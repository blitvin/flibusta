<?php
echo "<script>var url = '$webroot/usr.php?id=$url->var1';</script>";

function nl2p($string) {
    $paragraphs = '';

    foreach (explode("\n", $string) as $line) {
        if (trim($line)) {
            $paragraphs .= '<p>' . $line . '</p>';
        }
    }

    return $paragraphs;
}
book_info_pg($book, $webroot, true);

echo "<div class='card card-body p-3'><ul>";

$stmt = $dbh->prepare("SELECT name, text FROM libreviews WHERE bookid=:id ORDER BY time");
$stmt->bindParam(":id", $url->var1);
$stmt->execute();

while ($r = $stmt->fetch()) {
	echo "<li><span class='badge bg-secondary'>" . htmlspecialchars($r->name, ENT_QUOTES, 'UTF-8') . "</span> "
   . htmlspecialchars($r->text, ENT_QUOTES, 'UTF-8') . "</li>";
}

echo "</ul></div>";
	

function str_replace_first($from, $to, $content) { 
    $from = '/'.preg_quote($from, '/').'/';
    return preg_replace($from, $to, $content, 1);
}


$ext = strtolower(trim($book->filetype));

if ($ext == 'fb2') {
	$stmt = $dbh->prepare("SELECT * FROM book_zip WHERE ? BETWEEN start_id AND end_id AND usr=0");
} else {
	$stmt = $dbh->prepare("SELECT * FROM book_zip WHERE ? BETWEEN start_id AND end_id AND usr=1");
}
$stmt->execute([$url->var1]);
if ($stmt->rowCount() >0 ){
	$zip_name = $stmt->fetch()->filename;
	$zip = new ZipArchive(); 

	echo "<div id='reader' class='reader'>";
	if ($zip->open($zip_name) === TRUE) {
		if ($ext == 'fb2') {
			include('fb.php');
		}

		if ($ext == 'txt') {
			include('txt.php');
		}

		if ($ext == 'epub') {
			include('epub.php');
		}

		if ($ext == 'pdf') {
			include('pdf.php');
		}

		if ($ext == 'mobi') {
			include('mobi.php');
		}

		if (($ext == 'djvu') || ($ext == 'djv')) {
			include('djvu.php');
		}

		if ($ext == 'rtf') {
			include('rtf.php');
		}

		if ($ext == 'docx') {
			include('docx.php');
		}

		if (($ext == 'html') || ($ext == 'htm')) {
			include('html.php');
		}

		$zip->close();
	} else {
		echo "<p><b><center> Не удалось открыть архив $zip_name ,Ошибка ".$zip->getStatusString()."</center></b></p>\n";
	}
} else {
	echo "<p><b><center>Не удалось открыть книгу № ". $url->var1 . " , вероятно zip файл с книгой отсутсвует</center></b></p>\n";
}


?>
</div>
