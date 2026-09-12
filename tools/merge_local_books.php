<?php
// Replay locally added books (local_* tables) into the freshly imported lib*
// tables. Runs as a step of app_import_sql.sh, AFTER the dump tables are
// reloaded and BEFORE update_vectors.sql / update_zip_list.php — so FTS
// vectors and book_zip entries for the replayed books are rebuilt by those
// existing steps and are NOT touched here.
//
// Duplicate handling when a locally added book also appears in the new dump:
//   FLIBUSTA_LOCAL_DUPLICATE_POLICY = keep_both   (default) both copies stay
//                                   = prefer_dump local copy is removed and
//                                     fav/progress rows are remapped to the
//                                     dump book id.
// Duplicate detection: strong match by md5, weak match by identical
// normalized title + a shared author name. Author duplicates are only
// reported, never auto-merged.

error_reporting(E_ALL);
include('/application/dbinit.php');
include_once('/application/positions.php');

// Runs standalone under the CLI (dbinit.php only, no init.php), so define the
// shared constant here too. Keep in sync with application/init.php.
if (!defined('LOCAL_ID_BASE')) {
	define('LOCAL_ID_BASE', 10000000);
}
define('LOCAL_FILES_DIR', '/cache/local/');

$policy = getenv('FLIBUSTA_LOCAL_DUPLICATE_POLICY') ?: 'keep_both';
if (!in_array($policy, ['keep_both', 'prefer_dump'], true)) {
	fwrite(STDERR, "merge_local_books: unknown policy '$policy', falling back to keep_both\n");
	$policy = 'keep_both';
}

function logline(string $msg): void {
	echo $msg . PHP_EOL;
	fwrite(STDERR, "merge_local_books: " . $msg . PHP_EOL);
}

function local_zip_path(int $bookid, string $ext): string {
	return ($ext === 'fb2')
		? LOCAL_FILES_DIR . "f.fb2.$bookid-$bookid.zip"
		: LOCAL_FILES_DIR . "f.usr-$bookid-$bookid.zip";
}

// Remap user data (favorites, reading positions) from one book id to another,
// then delete leftovers that would collide with existing rows.
function remap_user_data(PDO $dbh, int $fromId, int $toId): void {
	// Reading positions live in per-user files on the /cache volume, not in the DB.
	positions_remap_book($fromId, $toId);
	$dbh->prepare("UPDATE fav SET bookid = :to WHERE bookid = :from
		AND NOT EXISTS (SELECT 1 FROM fav f2 WHERE f2.bookid = :to2
			AND f2.list_uuid IS NOT DISTINCT FROM fav.list_uuid
			AND f2.user_id  IS NOT DISTINCT FROM fav.user_id)")
		->execute([':to' => $toId, ':from' => $fromId, ':to2' => $toId]);
	$dbh->prepare("DELETE FROM fav WHERE bookid = :from")->execute([':from' => $fromId]);
}

$books = $dbh->query("SELECT bookid, title, lang, year, trim(filetype) AS filetype, filesize,
		encode(md5, 'hex') AS md5hex, added_at FROM local_books ORDER BY bookid")->fetchAll();
logline("Локальных книг для восстановления: " . count($books));

$restored = 0;
$dropped = 0;
$failed = 0;

foreach ($books as $b) {
	$bookid = (int)$b->bookid;
	try {
		// ---- duplicate detection against the freshly imported dump ----
		$dupId = null;
		$dupKind = '';
		$st = $dbh->prepare("SELECT bookid FROM libbook WHERE bookid < " . LOCAL_ID_BASE . "
			AND (md5 = decode(:h1, 'hex') OR md5 = convert_to(:h2, 'UTF8')) LIMIT 1");
		$st->execute([':h1' => $b->md5hex, ':h2' => $b->md5hex]);
		if ($r = $st->fetch()) {
			$dupId = (int)$r->bookid;
			$dupKind = 'md5';
		} else {
			$st = $dbh->prepare("SELECT b2.bookid FROM libbook b2
				WHERE b2.bookid < " . LOCAL_ID_BASE . " AND b2.deleted = '0'
				  AND lower(b2.title) = lower(:t)
				  AND EXISTS (SELECT 1 FROM libavtor la JOIN libavtorname an ON an.avtorid = la.avtorid
					WHERE la.bookid = b2.bookid AND lower(trim(an.lastname || ' ' || an.firstname)) IN (
						SELECT lower(trim(coalesce(an2.lastname, la2_a.lastname) || ' ' || coalesce(an2.firstname, la2_a.firstname)))
						FROM local_book_authors lba
						LEFT JOIN local_authors la2_a ON la2_a.avtorid = lba.avtorid
						LEFT JOIN libavtorname an2 ON an2.avtorid = lba.avtorid
						WHERE lba.bookid = :b))
				LIMIT 1");
			$st->execute([':t' => $b->title, ':b' => $bookid]);
			if ($r = $st->fetch()) {
				$dupId = (int)$r->bookid;
				$dupKind = 'title+author';
			}
		}

		if ($dupId !== null && $policy === 'prefer_dump') {
			$dbh->beginTransaction();
			remap_user_data($dbh, $bookid, $dupId);
			$dbh->prepare("DELETE FROM local_books WHERE bookid = ?")->execute([$bookid]); // cascades authors/genres links
			$dbh->commit();
			$zip = local_zip_path($bookid, $b->filetype);
			if (file_exists($zip)) unlink($zip);
			logline("Книга «{$b->title}» (id $bookid) есть в новом дампе (id $dupId, совпадение: $dupKind) — локальная копия удалена, избранное/позиции перенесены");
			$dropped++;
			continue;
		}
		if ($dupId !== null) {
			logline("Книга «{$b->title}» (id $bookid) есть и в дампе (id $dupId, совпадение: $dupKind) — оставлены обе копии (политика keep_both)");
		}

		// ---- replay into lib* tables ----
		$zip = local_zip_path($bookid, $b->filetype);
		if (!file_exists($zip)) {
			logline("ВНИМАНИЕ: архив $zip не найден — книга «{$b->title}» (id $bookid) будет в каталоге, но файл недоступен");
		}

		$dbh->beginTransaction();
		// NB: the dump-shaped lib* tables have no primary keys, so ON CONFLICT
		// is unusable — use NOT EXISTS guards instead (safe on re-run).
		// "time" is the library's "date added" (used by the date sort). Take it
		// from local_books.added_at truncated to whole seconds: dump rows carry
		// second precision and the display code parses that exact shape, and
		// reusing added_at keeps the book from jumping to the top of "recently
		// added" on every import.
		$st = $dbh->prepare("INSERT INTO libbook (bookid, filesize, title, title1, lang, filetype, year, fileauthor, keywords, md5, \"time\")
			SELECT :id, :fs, :t, '', :l, :ft, :y, '', '', decode(:md5, 'hex'), date_trunc('second', CAST(:added AS timestamptz))
			WHERE NOT EXISTS (SELECT 1 FROM libbook WHERE bookid = :id2)");
		$st->execute([':id' => $bookid, ':fs' => $b->filesize, ':t' => $b->title,
			':l' => $b->lang, ':ft' => $b->filetype, ':y' => $b->year, ':md5' => $b->md5hex,
			':added' => $b->added_at, ':id2' => $bookid]);

		$as = $dbh->prepare("SELECT lba.avtorid, lba.pos, la.lastname, la.firstname, la.middlename, la.nickname
			FROM local_book_authors lba LEFT JOIN local_authors la ON la.avtorid = lba.avtorid
			WHERE lba.bookid = ? ORDER BY lba.pos");
		$as->execute([$bookid]);
		while ($a = $as->fetch()) {
			$aid = (int)$a->avtorid;
			if ($aid >= LOCAL_ID_BASE) {
				// local author: make sure the libavtorname row exists again
				$dbh->prepare("INSERT INTO libavtorname (avtorid, lastname, firstname, middlename, nickname, email, homepage)
					SELECT :id, :l, :f, :m, :n, '', ''
					WHERE NOT EXISTS (SELECT 1 FROM libavtorname WHERE avtorid = :id2)")
					->execute([':id' => $aid, ':l' => (string)$a->lastname, ':f' => (string)$a->firstname,
						':m' => (string)$a->middlename, ':n' => (string)$a->nickname, ':id2' => $aid]);
			} else {
				// dump author: may have vanished from the new dump — recreate a stub then
				$chk = $dbh->prepare("SELECT 1 FROM libavtorname WHERE avtorid = ?");
				$chk->execute([$aid]);
				if (!$chk->fetch()) {
					logline("ВНИМАНИЕ: автор id $aid книги «{$b->title}» исчез из дампа — создана пустая запись");
					$dbh->prepare("INSERT INTO libavtorname (avtorid, lastname, firstname, middlename, nickname, email, homepage)
						VALUES (:id, '(автор удалён из дампа)', '', '', '', '', '')")->execute([':id' => $aid]);
				}
			}
			$dbh->prepare("INSERT INTO libavtor (bookid, avtorid, pos) SELECT :b, :a, :p
				WHERE NOT EXISTS (SELECT 1 FROM libavtor WHERE bookid = :b2 AND avtorid = :a2)")
				->execute([':b' => $bookid, ':a' => $aid, ':p' => (int)$a->pos, ':b2' => $bookid, ':a2' => $aid]);
		}

		$gs = $dbh->prepare("SELECT genreid FROM local_book_genres WHERE bookid = ?");
		$gs->execute([$bookid]);
		while ($g = $gs->fetch()) {
			$dbh->prepare("INSERT INTO libgenre (bookid, genreid) SELECT :b, :g
				WHERE NOT EXISTS (SELECT 1 FROM libgenre WHERE bookid = :b2 AND genreid = :g2)")
				->execute([':b' => $bookid, ':g' => (int)$g->genreid, ':b2' => $bookid, ':g2' => (int)$g->genreid]);
		}
		$dbh->commit();
		$restored++;
	} catch (Throwable $e) {
		if ($dbh->inTransaction()) $dbh->rollBack();
		logline("ОШИБКА при восстановлении книги id $bookid: " . $e->getMessage());
		$failed++;
	}
}

// report (not merge) local authors that now have a namesake in the dump
$rep = $dbh->query("SELECT la.avtorid, la.lastname, la.firstname, an.avtorid AS dumpid
	FROM local_authors la JOIN libavtorname an
	  ON an.avtorid < " . LOCAL_ID_BASE . "
	 AND lower(an.lastname) = lower(la.lastname) AND lower(an.firstname) = lower(la.firstname)");
while ($r = $rep->fetch()) {
	logline("К сведению: локальный автор «{$r->lastname} {$r->firstname}» (id {$r->avtorid}) совпадает по имени с автором дампа id {$r->dumpid}");
}

logline("Восстановление локальных книг завершено: восстановлено $restored, удалено как дубликаты $dropped, ошибок $failed");
exit($failed > 0 ? 1 : 0);
