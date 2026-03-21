<?php
include('../init.php');
$cover = '';
header('Cache-Control: public, max-age=86400');

function lastm($path) {
	$fmtimestamp = filemtime($path);
	if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $fmtimestamp <= strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
		die();
	} else {
		header("Expires: " . gmdate("D, d M Y H:i:s", filemtime($path) + 60*60*24) . " GMT");
		header("Last-Modified: " . gmdate("D, d M Y H:i:s", filemtime($path)) . " GMT");

		echo file_get_contents($path);
	}
}

if (isset($_GET['id'])) {
	$id = $_GET['id'];
} else {
	$id = 0;
}
$iid = $id;

header("Content-type: image/jpeg");
// check whether DB is in the process of maintenance , return status 503 if yes
$filehandle = fopen(DBUPDATE_LOCK,"r");
if (flock($filehandle,LOCK_SH|LOCK_NB) === false) {
	http_response_code(503);
	echo file_get_contents('/application/none.jpg');
	die();
}
if (file_exists(CACHE_PATH . "authors/$id.jpg")) {
	lastm(CACHE_PATH . "authors/$id.jpg");
	die();
}

$stmt = $dbh->prepare("SELECT file FROM libapics WHERE AvtorId=:id");
$stmt->bindParam(":id",$id);
$stmt->execute();
$f = $stmt->fetch();

if (isset($f->file)) {
	$zip = new ZipArchive(); 
	if ($zip->open(CACHE_PATH . "lib.a.attached.zip")) {
		$f = $zip->getFromName($f->file);
		if (strlen($f) > 0) {
			file_put_contents(CACHE_PATH . "authors/$id.jpg", $f);
			echo $f;
			die();
		}
	}
	$zip->close();
}

