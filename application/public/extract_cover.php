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

		$src = fopen($path,"r");
		$dest = fopen('php://output', 'w'); // Best for Web + CLI compatibility

		stream_copy_to_stream($src, $dest);

		fclose($src);
		fclose($dest);
	}
}

/**
 * Extracts the cover image from an FB2 file stored inside a ZIP archive.
 *
 * @param string $zipPath    Path to the .zip archive.
 * @param string $fb2Name    Name of the .fb2 file inside the archive.
 * @param int    $id         bookId. cover images are saved to /cache/covers/$id.jpg and $id-small.jpg
 * @return bool              True on success, false on failure.
 */
function extractFb2CoverFromZip($zipPath, $fb2Name, $id) {
    // 1. Create a stream URI for the file inside the ZIP
    // format: zip://path/to/archive.zip#inside_file.fb2
    $streamPath = 'zip://' . realpath($zipPath) . '#' . $fb2Name;

    $reader = new XMLReader();
    if (!$reader->open($streamPath)) {
        return false;
    }

    $coverId = null;

    // 2. First Pass: Find the coverpage image ID in the <description>
    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName === 'coverpage') {
            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName === 'image') {
                    // Look for the XLink href attribute (usually starts with #)
                    $href = $reader->getAttribute('l:href') ?: $reader->getAttribute('xlink:href');
                    if ($href) {
                        $coverId = ltrim($href, '#');
                        break 2; // Found it, exit outer loop
                    }
                }
                if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName === 'coverpage') {
                    break;
                }
            }
        }
        // Stop searching description once we hit the body to save time
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName === 'body') {
            break;
        }
    }

    if (!$coverId) {
        $reader->close();
        return false;
    }

    // 3. Second Pass: Find the <binary> tag with the matching ID
    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName === 'binary') {
            if ($reader->getAttribute('id') === $coverId) {
                // Get the base64 content. readInnerXml handles large text nodes efficiently
                // as a stream in modern PHP versions.
                $base64Data = $reader->readInnerXml();
                
                // Decode and save
                $decoded = base64_decode($base64Data);
                if ($decoded) {
                    file_put_contents(CACHE_PATH . "covers/$id.jpg", $decoded);
					$thm = resizeCover($decoded, 300, 400);
					imagejpeg($thm, CACHE_PATH . "covers/$id-small.jpg", 75);
					$thm = null;
                    return true;
                }
                break;
            }
        }
    }

    $reader->close();
    return false;
}

/**
 * Extracts the cover image from an EPUB file stored inside a ZIP archive.
 *
 * @param string $zipPath    Path to the .zip archive.
 * @param string $epubName   Name of the .epub file inside the archive.
 * @param int $id         bookId. cover images are saved to /cache/covers/$id.jpg and $id-small.jpg
 * @return bool              True on success, false on failure.
 */
function extractEpubCoverFromZip($zipPath, $epubName, $id) {
	$zip = new ZipArchive(); 
	if (!$zip->open($zipPath))
		return false;
	try {
		$src = $zip->getStream($epubName);
		if ($src === false) {
			return false;
		}

		$dest = fopen(CACHE_PATH . "tmp/$id.tmp", 'w');

		stream_copy_to_stream($src, $dest);

		fclose($src);
		fclose($dest);
		include('/application/epub.php');
		$d = new EPub(CACHE_PATH . "tmp/$id.tmp");
		$im = $d->Cover();

		unlink(CACHE_PATH . "tmp/$id.tmp");
		if ($im['found'] != '') {
			$cover = $im['data'];
		} else {
			return false;
		}
		file_put_contents(CACHE_PATH . "covers/$id.jpg", $cover);
		$thm = resizeCover($cover, 300, 400);
		imagejpeg($thm, CACHE_PATH . "covers/$id-small.jpg", 75);
		$thm = null;
		return true;
	}finally {
		$zip->close();
	}
}

// WARNING ! For some reason when the script is called as extract_cover.php?id=..&small php crashes,
// Not exception  - the tread itself fails. Really. Get 500 HTTP responce and nothing in logs, even no mention
// of the call in docker log or errors log of NGinix... so use extract_cover.php?sid=... instead
$small = isset($_GET['small']);

if (isset($_GET['id'])) {
	$id = $_GET['id'];
} else {
	if (isset($_GET['sid'])) {
		$id = $_GET['sid'];
		$small = true;
	}
}


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

if ($type == 'fb2') {
	extractFb2CoverFromZip($zip_name,$filename,$id);
} elseif ($type == 'epub') {
	extractEpubCoverFromZip($zip_name,$filename,$id);
} else {
	echo file_get_contents('/application/none.jpg');
	die();
}

if ($small) {
	$fname = CACHE_PATH . "covers/$id-small.jpg";
} else {
	$fname = CACHE_PATH . "covers/$id.jpg";
}
if (file_exists($fname)) {
	lastm($fname);
} else {
	echo file_get_contents('/application/none.jpg');
}
