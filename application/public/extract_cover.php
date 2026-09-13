<?php
include('../init.php');
session_start();
// Covers are library content too — same access rule as the book endpoints.
checkFileAccess($dbh, $webroot);
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
 * Negative cache for books that have no cover anywhere.
 *
 * Without it every cold request for a coverless book repeats the whole lookup —
 * two queries plus, for fb2, a full XML parse of the book straight out of its
 * zip — and a listing page does that once per book per visitor.
 */
function cover_miss_marker($id) {
	return CACHE_PATH . "covers/" . intval($id) . ".none";
}

/**
 * True when a recorded miss still stands.
 *
 * A freshly downloaded cover archive may hold a cover the book lacked before,
 * so a marker older than lib.b.attached.zip is ignored and the lookup runs
 * again. "Очистить кэш" wipes /cache/covers/* and so drops the markers outright.
 */
function cover_miss_is_fresh($id) {
	$marker = cover_miss_marker($id);
	if (!file_exists($marker)) {
		return false;
	}
	$archive = CACHE_PATH . 'lib.b.attached.zip';
	if (file_exists($archive) && filemtime($archive) > filemtime($marker)) {
		return false;
	}
	return true;
}

/** Record that this book has no cover, serve the placeholder and stop. */
function cover_placeholder($id) {
	if (!@touch(cover_miss_marker($id))) {
		error_log('extract_cover: cannot write miss marker for book ' . intval($id)
			. ' — is ' . CACHE_PATH . 'covers/ writable?');
	}
	echo file_get_contents('/application/none.jpg');
	exit;
}

/**
 * Extracts the cover image from an FB2 file stored inside a ZIP archive.
 *
 * @param string $zipPath    Path to the .zip archive.
 * @param string $fb2Name    Name of the .fb2 file inside the archive.
 * @param int    $id         bookId. cover images are saved to /cache/covers/$id.jpg and $id-small.jpg
 * @return bool              True on success, false on failure.
 */
function extractFb2CoverFromZip($streamPath, $id) {
    $reader = new XMLReader();
    if (!$reader->open($streamPath)) {
        error_log("extract_cover: fb2 $id: XMLReader failed to open $streamPath");
        return false;
    }

    $coverId = null;

    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName === 'coverpage') {
            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName === 'image') {
                    $href = $reader->getAttribute('l:href')
                         ?: $reader->getAttribute('xlink:href')
                         ?: $reader->getAttributeNs('href', 'http://www.w3.org/1999/xlink');
                    if ($href) {
                        $coverId = ltrim($href, '#');
                        break 2;
                    }
                }
                if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName === 'coverpage') {
                    break;
                }
            }
        }
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName === 'body') {
            break;
        }
    }

    if (!$coverId) {
        error_log("extract_cover: fb2 $id: no coverpage image found in $streamPath");
        $reader->close();
        return false;
    }

    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName === 'binary') {
            if ($reader->getAttribute('id') === $coverId) {
                $base64Data = $reader->readString();
                $decoded = base64_decode($base64Data);
                if ($decoded) {
                    $img = imagecreatefromstring($decoded);
                    if ($img !== false) {
                        imagejpeg($img, CACHE_PATH . "covers/$id.jpg", 90);
                        $thm = resizeCover($decoded, 300, 400);
                        imagejpeg($thm, CACHE_PATH . "covers/$id-small.jpg", 75);
                        $thm = null;
                        $reader->close();
                        return true;
                    }
                    error_log("extract_cover: fb2 $id: imagecreatefromstring failed for cover '$coverId'");
                } else {
                    error_log("extract_cover: fb2 $id: base64_decode failed for cover '$coverId'");
                }
                break;
            }
        }
    }

    error_log("extract_cover: fb2 $id: binary '$coverId' not found or decode failed");
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
	if ($zip->open($zipPath) !== true)
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

$small = isset($_GET['small']);

if (isset($_GET['id'])) {
	$id = intval($_GET['id']);
} else {
	if (isset($_GET['sid'])) {
		$id = intval($_GET['sid']);
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
// "private": the response now depends on who is asking, so only the requesting
// browser may cache it — a shared cache would hand covers to anonymous clients.
header('Cache-Control: private, max-age=86400');
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

// Nothing was found last time and nothing has changed since: skip the lookup
// and the two queries it needs.
if (cover_miss_is_fresh($id)) {
	echo file_get_contents('/application/none.jpg');
	die();
}

// Most fb2 books carry no cover of their own; Flibusta keeps those images in
// lib.b.attached.zip, which the "Скачать обложки" operation downloads. Every
// failure below used to fall through silently, which made a missing archive
// indistinguishable from a book that simply has no cover.
$stmt = $dbh->prepare("SELECT file FROM libbpics WHERE BookId=:id");
$stmt->bindParam(":id",$id);
$stmt->execute();
$f = $stmt->fetch();
if ($f !== false && isset($f->file) && $f->file !== '') {
	$archive = CACHE_PATH . "lib.b.attached.zip";
	$zip = new ZipArchive();
	if ($zip->open($archive) !== true) {
		error_log("extract_cover: book $id: libbpics names cover '$f->file' but $archive"
			. (file_exists($archive) ? " cannot be opened" : " does not exist")
			. " — run \"Скачать обложки\"");
	} else {
		$fdata = $zip->getFromName($f->file);
		$zip->close();
		if ($fdata === false || strlen($fdata) === 0) {
			error_log("extract_cover: book $id: entry '$f->file' is missing or empty inside $archive");
		} else {
			$img = imagecreatefromstring($fdata);
			if ($img === false) {
				error_log("extract_cover: book $id: entry '$f->file' is not a decodable image");
			} else {
				imagejpeg($img, CACHE_PATH . "covers/$id.jpg", 90);
				$thm = resizeCover($fdata, 300, 400);
				imagejpeg($thm, CACHE_PATH . "covers/$id-small.jpg", 75);
				$thm = null;
				if ($small) {
					if (file_exists(CACHE_PATH . "covers/$id-small.jpg")) {
						lastm(CACHE_PATH . "covers/$id-small.jpg");
						die();
					}
				} else {
					if (file_exists(CACHE_PATH . "covers/$id.jpg")) {
						lastm(CACHE_PATH . "covers/$id.jpg");
						die();
					}
				}
				error_log("extract_cover: book $id: cover decoded but "
					. CACHE_PATH . "covers/ did not receive the file — check permissions");
			}
		}
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
	error_log("extract_cover: book $id: no libbook row");
	cover_placeholder($id);
}
$stmt = null;

if ($type == 'fb2') {
	$localFb2 = LOCAL_LIBRARY_PATH . $id . '.fb2';
	if (file_exists($localFb2)) {
		extractFb2CoverFromZip($localFb2, $id);
	} else {
		$stmt = $dbh->prepare("SELECT filename FROM book_zip WHERE :id BETWEEN start_id AND end_id AND usr=:u");
		$stmt->bindParam(":id",$id);
		$stmt->bindParam(":u",$u);
		$stmt->execute();
		$result = $stmt->fetch();
		if (!$result) {
			error_log("extract_cover: fb2 $id: no book_zip entry and no local file");
			cover_placeholder($id);
		}
		$zip_name = $result->filename;
		$stmt = null;
		$stmt = $dbh->prepare("SELECT filename FROM libfilename where BookId=:id");
		$stmt->bindParam(":id",$id);
		$stmt->execute();
		$result = $stmt->fetch();
		$filename = $result ? $result->filename : null;
		if ($filename == '') {
			$filename = trim("$id.fb2");
		}
		$stmt = null;
		if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'zip') {
			resolve_inner_zip_book($zip_name, $id, $filename, 'fb2');
			if (file_exists($localFb2)) {
				extractFb2CoverFromZip($localFb2, $id);
			} else {
				extractFb2CoverFromZip('zip://' . realpath($zip_name) . '#' . $filename, $id);
			}
		} else {
			extractFb2CoverFromZip('zip://' . realpath($zip_name) . '#' . $filename, $id);
		}
	}
} elseif ($type == 'epub') {
	$stmt = $dbh->prepare("SELECT filename FROM book_zip WHERE :id BETWEEN start_id AND end_id AND usr=:u");
	$stmt->bindParam(":id",$id);
	$stmt->bindParam(":u",$u);
	$stmt->execute();
	$result = $stmt->fetch();
	if (!$result) {
		error_log("extract_cover: epub $id: no book_zip entry");
		cover_placeholder($id);
	}
	$zip_name = $result->filename;
	$stmt = null;
	$stmt = $dbh->prepare("SELECT filename FROM libfilename where BookId=:id");
	$stmt->bindParam(":id",$id);
	$stmt->execute();
	$result = $stmt->fetch();
	$filename = $result ? $result->filename : null;
	if ($filename == '') {
		$filename = trim("$id.epub");
	}
	$stmt = null;
	try {
		extractEpubCoverFromZip($zip_name, $filename, $id);
	} catch (Exception $e) {
		error_log("extract_cover: epub $id: " . $e->getMessage());
	}
} else {
	// pdf, djvu, docx, ... — no cover is extracted from these at all, so the
	// marker stops the two queries above from running again for every request.
	cover_placeholder($id);
}

if ($small) {
	$fname = CACHE_PATH . "covers/$id-small.jpg";
} else {
	$fname = CACHE_PATH . "covers/$id.jpg";
}
if (file_exists($fname)) {
	lastm($fname);
} else {
	// The extractors above have already logged why they came up empty. Record
	// the miss so the next request skips the queries and the XML parse: this is
	// the path a coverless fb2 takes, and it is the expensive one.
	cover_placeholder($id);
}
