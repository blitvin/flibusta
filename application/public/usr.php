<?php
// H2: validate id exactly like fb2.php / extract_author.php do.
if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
	$id = (int)$_GET['id'];
} else {
	http_response_code(400);
	die();
}
error_reporting(E_ALL);
include('../init.php');
session_start();
// Book files are behind the same log-in as the catalog pages: this endpoint is
// reached directly (and from OPDS acquisition links), not only through index.php.
checkFileAccess($dbh, $webroot);

// Cached, and a single row - see book_meta() in functions.php.
$book = book_meta($dbh, $id);
if (!$book) {
	// Without the row there is no way to know the file's extension, so there is
	// nothing to look for in the archives. (This used to be a fatal error on
	// trim(null) instead of an answer.)
	http_response_code(404);
	echo "Book file not found in archive";
	exit;
}

$ext = strtolower(trim((string)$book->filetype));
$dbFilename = ($book->filename !== null && $book->filename !== '') ? $book->filename : null;

// If libfilename says the stored file is already a zip wrapper, skip the direct lookup.
// Otherwise use the libfilename name directly, falling back to {id}.{ext} if absent.
if ($dbFilename !== null && strtolower(pathinfo($dbFilename, PATHINFO_EXTENSION)) === 'zip') {
	$fname        = null;            // no direct entry to look for
	$innerZipName = $dbFilename;     // e.g. 709533.pdf.zip
} elseif ($dbFilename !== null) {
	$fname        = $dbFilename;
	$innerZipName = $dbFilename . '.zip';
} else {
	$fname        = $id . '.' . $ext;
	$innerZipName = $id . '.' . $ext . '.zip';
}

$downloadName = $book->author_name . " - " . $book->title . " " . $id . "." . $dbFilename . "." . $ext;

function send_book_headers(string $name): void {
	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename=' . basename(rawurlencode($name)));
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
}

/**
 * The book's file cannot be produced.
 *
 * This endpoint is followed directly by a browser (the download button on the book
 * page), so it used to answer a click with a 6-byte "NO ZIP" page and HTTP 200 -
 * indistinguishable from a successful download as far as the browser is concerned.
 */
function book_not_available(int $id): never {
	http_response_code(404);
	header('Content-Type: text/html; charset=UTF-8');
	echo "<!doctype html><meta charset='utf-8'><title>Книга недоступна</title>";
	echo "<p>Файл книги № " . intval($id) . " недоступен: его нет в локальных архивах"
		. ", и скачать его с зеркала Флибусты не удалось.</p>";
	exit;
}

// 1. Check local cache (already extracted from a previous inner-zip request)
$localPath = LOCAL_LIBRARY_PATH . intval($id) . '.' . $ext;
if (file_exists($localPath)) {
	send_book_headers($downloadName);
	readfile($localPath);
	exit;
}

// 2. Look up the outer zip in the generated archive index (book_zip as fallback)
$zip_name = book_zip_filename($dbh, $id, 1);
if ($zip_name === '') {
	$localPath = fetchMissingBook(intval($id), $ext);
	if ($localPath !== null) {
		send_book_headers($downloadName);
		readfile($localPath);
		exit;
	}
	book_not_available($id);
}
$zip = new ZipArchive();

if (!$zip->open($zip_name)) {
	error_log("usr.php: cannot open archive $zip_name for book $id");
	book_not_available($id);
}

// 3. File directly in outer zip — serve as normal.
// Skip if fname is null or is itself a zip (libfilename already pointed at an inner zip).
if ($fname !== null && strtolower(pathinfo($fname, PATHINFO_EXTENSION)) !== 'zip' && $zip->locateName($fname) !== false) {
	send_book_headers($downloadName);
	$dest = fopen('php://output', 'w');
	$src = $zip->getStream($fname);
	stream_copy_to_stream($src, $dest);
	fclose($src);
	fclose($dest);
	$zip->close();
	exit;
}

$zip->close();

// 4. File missing from outer zip — try inner-zip extraction
$localPath = resolve_inner_zip_book($zip_name, intval($id), $innerZipName, $ext);
if ($localPath !== null) {
	send_book_headers($downloadName);
	readfile($localPath);
	exit;
}

// 5. Not in local zips — try downloading from Flibusta
$localPath = fetchMissingBook(intval($id), $ext);
if ($localPath !== null) {
	send_book_headers($downloadName);
	readfile($localPath);
	exit;
}

http_response_code(404);
echo "Book file not found in archive";
