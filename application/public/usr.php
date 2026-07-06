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

$stmt = $dbh->prepare("SELECT libbook.Title BookTitle, libfilename.filename, libbook.filetype,
	CONCAT(libavtorname.LastName, ' ', libavtorname.FirstName) author_name
		FROM libbook
		LEFT JOIN libavtor USING(BookId)
		LEFT JOIN libfilename USING(BookId)
		LEFT JOIN libavtorname USING(AvtorId)
		WHERE libbook.BookId=:id");
$stmt->bindParam(":id", $id);
$stmt->execute();
$book = $stmt->fetch();

$ext = strtolower(trim($book->filetype));

// If libfilename says the stored file is already a zip wrapper, skip the direct lookup.
// Otherwise use the libfilename name directly, falling back to {id}.{ext} if absent.
if (isset($book->filename) && strtolower(pathinfo($book->filename, PATHINFO_EXTENSION)) === 'zip') {
	$fname        = null;               // no direct entry to look for
	$innerZipName = $book->filename;    // e.g. 709533.pdf.zip
} elseif (isset($book->filename)) {
	$fname        = $book->filename;
	$innerZipName = $book->filename . '.zip';
} else {
	$fname        = $id . '.' . $ext;
	$innerZipName = $id . '.' . $ext . '.zip';
}

$downloadName = $book->author_name . " - " . $book->booktitle . " " . $id . "." . $book->filename . "." . trim($book->filetype);

function send_book_headers(string $name): void {
	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename=' . basename(rawurlencode($name)));
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
}

// 1. Check local cache (already extracted from a previous inner-zip request)
$localPath = LOCAL_LIBRARY_PATH . intval($id) . '.' . $ext;
if (file_exists($localPath)) {
	send_book_headers($downloadName);
	readfile($localPath);
	exit;
}

// 2. Look up outer zip in DB
$stmt = $dbh->prepare("SELECT * FROM book_zip WHERE :id BETWEEN start_id AND end_id AND usr=1");
$stmt->bindParam(":id", $id);
$stmt->execute();
$zipRow = $stmt->fetch();
if (!$zipRow) {
	$localPath = fetchMissingBook(intval($id), $ext);
	if ($localPath !== null) {
		send_book_headers($downloadName);
		readfile($localPath);
		exit;
	}
	echo "NO ZIP";
	exit;
}
$zip_name = $zipRow->filename;
$zip = new ZipArchive();

if (!$zip->open($zip_name)) {
	echo "NO ZIP";
	exit;
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
