<?php
// One-time migration: reading positions from Postgres into the local per-user
// files under /cache/positions (see application/positions.php).
//
// Run once by tools/flibusta_entrypoint.sh, guarded by a marker file. The source
// tables (progress, epub_progress, djvu_progress) and user_settings.last_book are
// left in place for this release so a rollback keeps working; they are no longer
// read or written by the application.
//
// Idempotent: an entry already present in a user's file wins and is not
// overwritten, so a re-run can never clobber newer local state.
error_reporting(E_ALL);
include('/application/dbinit.php');
include_once('/application/positions.php');

$kinds = [
	['table' => 'progress',      'column' => 'pos',  'kind' => 'pos'],
	['table' => 'epub_progress', 'column' => 'cfi',  'kind' => 'epub'],
	['table' => 'djvu_progress', 'column' => 'page', 'kind' => 'djvu'],
];

// Collect everything per user first, so each user's file is written once.
$byUser = [];

foreach ($kinds as $k) {
	try {
		$rows = $dbh->query("SELECT user_id, bookid, {$k['column']} AS value FROM {$k['table']}");
	} catch (Throwable $e) {
		// Table may already be gone in a future release - nothing to migrate.
		fwrite(STDERR, "migrate_positions: skipping {$k['table']}: " . $e->getMessage() . PHP_EOL);
		continue;
	}
	$n = 0;
	while ($row = $rows->fetch(PDO::FETCH_OBJ)) {
		$uid = (int)$row->user_id;
		$bid = (int)$row->bookid;
		if ($uid <= 0 || $bid <= 0 || $row->value === null) {
			continue;
		}
		$value = $k['kind'] === 'pos' ? (float)$row->value
			: ($k['kind'] === 'djvu' ? (int)$row->value : (string)$row->value);
		$byUser[$uid][$k['kind']][(string)$bid] = $value;
		$n++;
	}
	fwrite(STDERR, "migrate_positions: read $n rows from {$k['table']}" . PHP_EOL);
}

// The "continue reading" pointer used to be a user_settings column.
try {
	$rows = $dbh->query("SELECT user_id, last_book FROM user_settings WHERE last_book IS NOT NULL");
	while ($row = $rows->fetch(PDO::FETCH_OBJ)) {
		$uid = (int)$row->user_id;
		$bid = (int)$row->last_book;
		if ($uid > 0 && $bid > 0) {
			$byUser[$uid]['last'] = $bid;
		}
	}
} catch (Throwable $e) {
	fwrite(STDERR, 'migrate_positions: skipping user_settings.last_book: ' . $e->getMessage() . PHP_EOL);
}

$users = 0;
foreach ($byUser as $uid => $incoming) {
	positions_mutate($uid, function (array $data) use ($incoming) {
		foreach (['pos', 'epub', 'djvu'] as $kind) {
			foreach ($incoming[$kind] ?? [] as $bookid => $value) {
				// Existing local state is newer than the DB copy - keep it.
				if (!isset($data[$kind][$bookid])) {
					$data[$kind][$bookid] = $value;
				}
			}
		}
		if (isset($incoming['last']) && !isset($data['last'])) {
			$data['last'] = $incoming['last'];
		}
		return $data;
	});
	$users++;
}

fwrite(STDERR, "migrate_positions: wrote position files for $users users" . PHP_EOL);
echo "migrate_positions: done ($users users)" . PHP_EOL;
