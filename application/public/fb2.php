<?php
if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
	$id = (int)$_GET['id'];
} else {
	die();
}
error_reporting(E_ALL);
include('../init.php');


$stmt = $dbh->prepare("SELECT libbook.Title BookTitle, 
	CONCAT(libavtorname.LastName, ' ', libavtorname.FirstName) author_name
		FROM libbook 
		LEFT JOIN libbannotations USING(BookId) 
		LEFT JOIN libgenre USING(BookId) 
		LEFT JOIN libgenrelist USING(GenreId) 
		LEFT JOIN libseq USING(BookId) 
		LEFT JOIN libavtor USING(BookId) 
		LEFT JOIN libavtorname USING(AvtorId) 
		LEFT JOIN libseqname USING(SeqId) WHERE libbook.BookId=:id");
$stmt->bindParam(":id", $id);
$stmt->execute();
$book = $stmt->fetch();


$stmt = $dbh->prepare("SELECT * FROM book_zip WHERE ? BETWEEN start_id AND end_id AND usr=0");
$stmt->execute([$id]);
$zip_name = $stmt->fetch()->filename;
$zip = new ZipArchive();

if ($zip->open($zip_name)) {
	$filename = $book->author_name . " - " . $book->booktitle . " " . $id . ".fb2";
	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename=' . basename(rawurlencode($filename)));
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');

	$src = $zip->getStream("$id.fb2");
	$dest = fopen('php://output', 'w');

	stream_copy_to_stream($src, $dest);

	fclose($src);
	fclose($dest);
	$zip->close();
} else {
	echo "NO ZIP";
}



