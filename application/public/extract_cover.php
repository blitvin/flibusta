<?php
include('../init.php');
$cover = '';
$q = 75;


function resizeCover($filename, $newwidth, $newheight){
	$i = imagecreatefromstring($filename);
	$width = imagesx($i);
       	$height = imagesy($i);
    if($width > $height && $newheight < $height){
        $newheight = (int)round($height / ($width / $newwidth));
    } else if ($width < $height && $newwidth < $width) {
        $newwidth = (int)round($width / ($height / $newheight));
    } else {
        $newwidth = (int)round($width);
        $newheight = (int)round($height);
    }
    $thumb = imagecreatetruecolor($newwidth, $newheight);
    imagecopyresized($thumb, $i, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
    return $thumb;
}

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

$small = isset($_GET['small']);

if (isset($_GET['id'])) {
	$id = $_GET['id'];
} else {
	if (isset($_GET['sid'])) {
		$id = $_GET['sid'];
		$small = true;
	}
}
$iid = $id;


// check whether DB is in the process of maintenance , return status 503 if yes
$filehandle = fopen(DBUPDATE_LOCK,"r");
if (flock($filehandle,LOCK_SH|LOCK_NB) === false) {
	http_response_code(503);
	die();
}
header("Content-type: image/jpeg");
header('Cache-Control: public, max-age=86400');
if ($small) {
	if (file_exists( CACHE_PATH . "covers/$id-small.jpg")) {
		lastm( CACHE_PATH . "covers/$id-small.jpg");
		die();
	}
} else {
	if (file_exists(CACHE_PATH . "covers/$id.jpg")) {
		lastm(CACHE_PATH . "covers/$id.jpg");
		die();
	}
}

$stmt = $dbh->prepare("SELECT file FROM libbpics WHERE BookId=:id");
$stmt->bindParam(":id",$id);
$stmt->execute();
$f = $stmt->fetch();
if ($f !== false) {

if (isset($f->file)) {
	$zip = new ZipArchive(); 
	if ($zip->open(CACHE_PATH . "lib.b.attached.zip")) {
		$fdata = $zip->getFromName($f->file);
		if (strlen($fdata) > 0) {
			file_put_contents(CACHE_PATH . "covers/$id.jpg", $f);
			$thm = resizeCover($fdata, 300, 400);
			imagejpeg($thm, CACHE_PATH . "covers/$id-small.jpg", 75);
			$thm = null;
			if ($small) {
				if (file_exists(CACHE_PATH . "covers/$id-small.jpg")) {
					lastm(CACHE_PATH . "covers/$id-small.jpg");
					die();
				}
			} else {
				echo $fdata;
				die();
			}
		}
	}
	$zip->close();
}
}
$stmt = false;
$stmt = $dbh->prepare("SELECT filetype FROM libbook WHERE bookid=:id LIMIT 1");
$stmt->bindParam(":id",$id);
$stmt->execute();
$result = $stmt->fetch();
if ($result !== false) {
	$type = trim($result->filetype);
	if ($type == 'fb2') {
		$u = '0';
	} else {
		$u = '1';
	}
} else {
	echo file_get_contents('/application/none.jpg');
	die();
}
$stmt = null;
$stmt = $dbh->prepare("SELECT filename FROM book_zip WHERE :id BETWEEN start_id AND end_id AND usr=$u");
$stmt->bindParam(":id",$id);
$stmt->execute();
$result = $stmt->fetch();
if (!$result){
	echo file_get_contents('/application/none.jpg');
	die();
}
$zip_name = $result->filename;
$stmt = null;

$zip = new ZipArchive(); 

$stmt = $dbh->prepare("SELECT filename FROM libfilename where BookId=:id");
$stmt->bindParam(":id",$id);

$result = $stmt->fetch();

if ($result) {
    $filename = $result->filename;
} else {
    $filename = null;
}
if ($filename == '') {
	$filename = trim("$id.$type");
}
$stmt = null;
if ($zip->open($zip_name)) {
	$f = $zip->getFromName("$filename");
	if ($f === false){
		echo file_get_contents('/application/none.jpg');
		die();
	}
} else {
	echo file_get_contents('/application/none.jpg');
	die();
}
$zip->close();

if ($type == 'fb2') {
	$fb2 = simplexml_load_string($f);
	$images = array();
	if (isset($fb2->binary)) {
		foreach ($fb2->binary as $binary) {
			$id = $binary->attributes()['id'];		
			if (
				(strpos($id, "cover") !==  false) ||
				(strpos($id, "jpg") !==  false) ||
				(strpos($id, "obloj") !==  false)
			) {
				$cover = base64_decode($binary);
			}
			$images["$id"] = $binary;
		}
	}
}

if ($type == 'epub') {
	file_put_contents(CACHE_PATH . "tmp/$iid.tmp", $f);
	include('/application/epub.php');
	$d = new EPub(CACHE_PATH . "tmp/$iid.tmp");
	$im = $d->Cover();
	if ($im['found'] != '') {
		$cover = $im['data'];
		unlink(CACHE_PATH . "tmp/$iid.tmp");
	} else {
		$cover = '';
	}
}
if (strlen($cover) < 100) {
	$cover = file_get_contents('/application/none.jpg');
	echo $cover;
	die();
} else {
	file_put_contents(CACHE_PATH . "covers/$iid.jpg", $cover);
	$thm = resizeCover($cover, 300, 400);
	imagejpeg($thm, CACHE_PATH . "covers/$iid-small.jpg", 75);
	$thm = null;
}
if ($small) {
	if (file_exists(CACHE_PATH . "covers/$iid-small.jpg")) {
		lastm(CACHE_PATH . "covers/$iid-small.jpg");
		die();
	}
} else {
	echo $cover;
}
