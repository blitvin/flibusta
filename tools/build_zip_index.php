<?php
/**
 * Builds /cache/zip_index.php from the book_zip table when it is missing.
 *
 * The index is normally written by tools/update_zip_list.php as part of an
 * archive rescan. This exists for the upgrade case: an installation that already
 * has a populated book_zip table gets its index at container start instead of
 * falling back to the table (and logging about it) until an admin happens to run
 * "Сканирование ZIP".
 *
 *     php /tools/build_zip_index.php [--force]
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	die("CLI only\n");
}

$appRoot = getenv('FLIBUSTA_APP_ROOT') ?: '/application/';
require_once $appRoot . 'dbinit.php';
require_once $appRoot . 'zipindex.php';

$force = in_array('--force', $argv, true);
if (!$force && is_file(ZIP_INDEX_FILE)) {
	echo "build_zip_index: " . ZIP_INDEX_FILE . " already exists, leaving it alone\n";
	exit(0);
}

try {
	$count = (int)$dbh->query("SELECT COUNT(*) FROM book_zip")->fetchColumn();
} catch (Throwable $e) {
	fwrite(STDERR, "build_zip_index: cannot read book_zip (" . $e->getMessage() . ")\n");
	exit(0);   // not fatal: the runtime falls back to the table
}
if ($count === 0) {
	echo "build_zip_index: book_zip is empty, nothing to index yet\n";
	exit(0);
}

if (zip_index_write_from_db($dbh)) {
	echo "build_zip_index: indexed $count archives\n";
} else {
	fwrite(STDERR, "build_zip_index: failed to write the index\n");
}
exit(0);
