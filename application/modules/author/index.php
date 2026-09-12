<?php
include_once(ROOT_PATH . "webroot.php");
$author_id = intval($url->var1);

$stmt = $dbh->prepare("SELECT * FROM libavtorname LEFT JOIN libapics USING(AvtorId) WHERE avtorid=:id");
$stmt->bindParam(":id", $author_id);
$stmt->execute();
$a = $stmt->fetch();

// Fetch annotations upfront to decide whether to show the "About" tab
$annStmt = $dbh->prepare("SELECT * FROM libaannotations WHERE AvtorId=:id");
$annStmt->bindParam(":id", $author_id);
$annStmt->execute();
$annotations = $annStmt->fetchAll();
$has_about = count($annotations) > 0;

// Determine default tab from user preference (guests always get 'alpha')
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$pref_tab = 'alpha';
if ($current_user_id > 0) {
	$prefStmt = $dbh->prepare("SELECT author_default_tab FROM user_settings WHERE user_id = ?");
	$prefStmt->execute([$current_user_id]);
	$pref_row = $prefStmt->fetch();
	if ($pref_row && $pref_row->author_default_tab) {
		$pref_tab = $pref_row->author_default_tab;
	}
}
// 'about' falls back to 'alpha' when the author has no annotation
if ($pref_tab === 'about' && !$has_about) {
	$pref_tab = 'alpha';
}

echo "<div class='card mb-3'>";
echo "<div class='card-header'><h3>" . htmlspecialchars(trim("$a->lastname $a->firstname $a->middlename $a->nickname"), ENT_QUOTES, 'UTF-8') . "</h3></div>";
echo "<div class='card-body'><div class='row'>";

echo "<div class='col-sm-2 mb-3'>";
if (isset($a->file) && $a->file != '') {
	echo "<img src='$webroot/extract_author.php?id=$author_id' style='width: 100%;' class='card-image' /><br />";
}
echo "<a class='btn btn-primary mt-2 w-100' href='$webroot/?aid=$author_id'>Книги автора</a>";

try {
	if ($current_user_id > 0) {
		$favStmt = $dbh->prepare("SELECT COUNT(*) cnt FROM fav WHERE user_id=:uid AND avtorid=:id");
		$favStmt->bindParam(":uid", $current_user_id);
		$favStmt->bindParam(":id", $author_id);
		$favStmt->execute();
		$is_fav = ($favStmt->fetch()->cnt > 0);
		$action       = $is_fav ? 'unfav_author' : 'fav_author';
		$button_text  = $is_fav ? 'Из избранного' : 'В избранное';
		$button_class = $is_fav ? 'btn-warning'   : 'btn-secondary';
		$csrf = isset($_SESSION['csrf_token']) ? htmlspecialchars($_SESSION['csrf_token']) : '';
		echo "<form method='POST' action='' style='display:contents;'>
			<input type='hidden' name='action' value='$action' />
			<input type='hidden' name='id' value='$author_id' />
			<input type='hidden' name='csrf_token' value='$csrf' />
			<button type='submit' class='btn $button_class mt-2 w-100'>$button_text</button>
		</form>";
	}
} catch (PDOException $e) {
	//
}

echo "</div>";

// Right side: tabbed content
// Helpers: active class based on preferred tab name
$nav_cls  = fn($t) => $pref_tab === $t ? ' active' : '';
$nav_sel  = fn($t) => $pref_tab === $t ? 'true' : 'false';
$pane_cls = fn($t) => $pref_tab === $t ? 'show active' : '';

echo "<div class='col-sm-10 mb-3'>";
echo "<ul class='nav nav-tabs mb-3' id='authorTabs' role='tablist'>";

if ($has_about) {
	echo "<li class='nav-item' role='presentation'><button class='nav-link" . $nav_cls('about') . "' id='about-tab' data-bs-toggle='tab' data-bs-target='#about' type='button' role='tab' aria-controls='about' aria-selected='" . $nav_sel('about') . "'>Об авторе</button></li>";
}
echo "<li class='nav-item' role='presentation'><button class='nav-link" . $nav_cls('alpha') . "' id='alpha-tab' data-bs-toggle='tab' data-bs-target='#alpha' type='button' role='tab' aria-controls='alpha' aria-selected='" . $nav_sel('alpha') . "'>По алфавиту</button></li>";
echo "<li class='nav-item' role='presentation'><button class='nav-link" . $nav_cls('series') . "' id='series-tab' data-bs-toggle='tab' data-bs-target='#series' type='button' role='tab' aria-controls='series' aria-selected='" . $nav_sel('series') . "'>По сериям</button></li>";
echo "<li class='nav-item' role='presentation'><button class='nav-link" . $nav_cls('year') . "' id='year-tab' data-bs-toggle='tab' data-bs-target='#year' type='button' role='tab' aria-controls='year' aria-selected='" . $nav_sel('year') . "'>По году</button></li>";

echo "</ul>";
echo "<div class='tab-content' id='authorTabsContent'>";

// --- Tab 1: About (only when annotations exist) ---
if ($has_about) {
	echo "<div class='tab-pane fade " . $pane_cls('about') . "' id='about' role='tabpanel' aria-labelledby='about-tab'>";
	foreach ($annotations as $an) {
		echo "$an->title<br />";
		echo "<p>", bbc2html(nl2br($an->body)), "</p>";
	}
	echo "</div>";
}

// --- Tab 2: Books alphabetically ---
echo "<div class='tab-pane fade " . $pane_cls('alpha') . "' id='alpha' role='tabpanel' aria-labelledby='alpha-tab'>";
$alphaStmt = $dbh->prepare(
	"SELECT b.*, (SELECT body FROM libbannotations WHERE bookid=b.bookid LIMIT 1) body
	 FROM libbook b
	 JOIN libavtor la ON la.bookid = b.bookid
	 WHERE la.avtorid = :id AND b.deleted = '0'
	 ORDER BY b.title");
$alphaStmt->bindParam(":id", $author_id);
$alphaStmt->execute();
while ($book = $alphaStmt->fetch()) {
	book_info_pg($book, $webroot);
}
echo "</div>";

// --- Tab 3: Books by series ---
echo "<div class='tab-pane fade " . $pane_cls('series') . "' id='series' role='tabpanel' aria-labelledby='series-tab'>";

$seqStmt = $dbh->prepare(
	"SELECT b.*, ls.seqid, ls.seqnumb, sn.seqname,
	        (SELECT body FROM libbannotations WHERE bookid=b.bookid LIMIT 1) body
	 FROM libbook b
	 JOIN libavtor la ON la.bookid = b.bookid
	 JOIN libseq ls ON ls.bookid = b.bookid
	 JOIN libseqname sn ON sn.seqid = ls.seqid
	 WHERE la.avtorid = :id AND b.deleted = '0'
	 ORDER BY sn.seqname, ls.seqnumb, b.title");
$seqStmt->bindParam(":id", $author_id);
$seqStmt->execute();

$cur_seqid = null;
while ($book = $seqStmt->fetch()) {
	if ($book->seqid !== $cur_seqid) {
		if ($cur_seqid !== null) {
			echo "</div>";
		}
		$cur_seqid = $book->seqid;
		$seq_title = htmlspecialchars($book->seqname, ENT_QUOTES, 'UTF-8');
		echo "<h5 class='mt-3'><a class='badge bg-danger text-white text-decoration-none' href='$webroot/?sid=" . intval($book->seqid) . "'>$seq_title</a></h5>";
		echo "<div>";
	}
	book_info_pg($book, $webroot);
}
if ($cur_seqid !== null) {
	echo "</div>";
}

// Books without any sequence
$noseqStmt = $dbh->prepare(
	"SELECT b.*, (SELECT body FROM libbannotations WHERE bookid=b.bookid LIMIT 1) body
	 FROM libbook b
	 JOIN libavtor la ON la.bookid = b.bookid
	 WHERE la.avtorid = :id AND b.deleted = '0'
	   AND NOT EXISTS (SELECT 1 FROM libseq WHERE bookid = b.bookid)
	 ORDER BY b.title");
$noseqStmt->bindParam(":id", $author_id);
$noseqStmt->execute();

$has_noseq = false;
while ($book = $noseqStmt->fetch()) {
	if (!$has_noseq) {
		$has_noseq = true;
		echo "<h5 class='mt-3'>Вне серий</h5>";
	}
	book_info_pg($book, $webroot);
}

echo "</div>";

// --- Tab 4: Books by year ---
echo "<div class='tab-pane fade " . $pane_cls('year') . "' id='year' role='tabpanel' aria-labelledby='year-tab'>";
$yearStmt = $dbh->prepare(
	"SELECT b.*, (SELECT body FROM libbannotations WHERE bookid=b.bookid LIMIT 1) body
	 FROM libbook b
	 JOIN libavtor la ON la.bookid = b.bookid
	 WHERE la.avtorid = :id AND b.deleted = '0'
	 ORDER BY b.year DESC NULLS LAST, b.title");
$yearStmt->bindParam(":id", $author_id);
$yearStmt->execute();
while ($book = $yearStmt->fetch()) {
	book_info_pg($book, $webroot);
}
echo "</div>";

echo "</div>"; // tab-content
echo "</div>"; // col-sm-10

echo "</div></div></div>";
