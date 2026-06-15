<?php
if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
	$id = (int)$_GET['id'];
} else {
	die();
}
error_reporting(E_ALL);
include('../init.php');

$stmt = $dbh->prepare("SELECT libbook.Title BookTitle,
	libfilename.filename,
	CONCAT(libavtorname.LastName, ' ', libavtorname.FirstName) author_name
		FROM libbook
		LEFT JOIN libbannotations USING(BookId)
		LEFT JOIN libgenre USING(BookId)
		LEFT JOIN libgenrelist USING(GenreId)
		LEFT JOIN libseq USING(BookId)
		LEFT JOIN libavtor USING(BookId)
		LEFT JOIN libavtorname USING(AvtorId)
		LEFT JOIN libseqname USING(SeqId)
		LEFT JOIN libfilename USING(BookId)
		WHERE libbook.BookId=:id");
$stmt->bindParam(":id", $id);
$stmt->execute();
$book = $stmt->fetch();

// If libfilename says the stored file is already a zip wrapper, skip the direct lookup.
// Otherwise use the libfilename name directly, falling back to {id}.fb2 if absent.
if (isset($book->filename) && strtolower(pathinfo($book->filename, PATHINFO_EXTENSION)) === 'zip') {
	$fname        = null;
	$innerZipName = $book->filename;
} elseif (isset($book->filename)) {
	$fname        = $book->filename;
	$innerZipName = $book->filename . '.zip';
} else {
	$fname        = $id . '.fb2';
	$innerZipName = $id . '.fb2.zip';
}

$downloadName = $book->author_name . " - " . $book->booktitle . " " . $id . ".fb2";

function send_fb2_headers(string $name): void {
	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename=' . basename(rawurlencode($name)));
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
}

// 1. Check local cache (already extracted from a previous inner-zip request)
$localPath = LOCAL_LIBRARY_PATH . $id . '.fb2';
if (file_exists($localPath)) {
	send_fb2_headers($downloadName);
	readfile($localPath);
	exit;
}

// 2. Look up outer zip in DB
$stmt = $dbh->prepare("SELECT * FROM book_zip WHERE ? BETWEEN start_id AND end_id AND usr=0");
$stmt->execute([$id]);
$zipRow = $stmt->fetch();
if (!$zipRow) {
	$localPath = fetchMissingBook($id, 'fb2');
	if ($localPath !== null) {
		send_fb2_headers($downloadName);
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
	send_fb2_headers($downloadName);
	$src  = $zip->getStream($fname);
	$dest = fopen('php://output', 'w');
	stream_copy_to_stream($src, $dest);
	fclose($src);
	fclose($dest);
	$zip->close();
	exit;
}

$zip->close();

// 4. File missing from outer zip — try inner-zip extraction
$localPath = resolve_inner_zip_book($zip_name, $id, $innerZipName, 'fb2');
if ($localPath !== null) {
	send_fb2_headers($downloadName);
	readfile($localPath);
	exit;
}

// 5. Not in local zips — try downloading from Flibusta
$localPath = fetchMissingBook($id, 'fb2');
if ($localPath !== null) {
	send_fb2_headers($downloadName);
	readfile($localPath);
	exit;
}

http_response_code(404);
echo "Book file not found in archive";
