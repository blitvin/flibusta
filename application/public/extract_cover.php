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
	$stamp = filemtime($marker);
	// Anything that can make a cover reachable invalidates the miss: a fresh cover
	// archive, a rescan that changed which zip holds the book (or repaired a broken
	// index), and a local copy of the book appearing in /cache/local. Without these
	// a miss recorded while something was misconfigured outlived the fix and could
	// only be cleared by wiping /cache/covers by hand.
	include_once(ROOT_PATH . 'zipindex.php');
	$newerThanMarker = [
		CACHE_PATH . 'lib.b.attached.zip',
		ZIP_INDEX_FILE,
		LOCAL_LIBRARY_PATH . intval($id) . '.fb2',
	];
	foreach ($newerThanMarker as $path) {
		if (file_exists($path) && filemtime($path) > $stamp) {
			return false;
		}
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
// Book metadata and the archive layout both come from the caches now, so a cold
// cover costs the libbpics lookup above and nothing else.
$meta = book_meta($dbh, (int)$id);
if ($meta !== null) {
	$type = trim((string)$meta->filetype);
	$u = ($type == 'fb2') ? 0 : 1;
} else {
	error_log("extract_cover: book $id: no libbook row");
	cover_placeholder($id);
}
$metaFilename = ($meta->filename !== null && $meta->filename !== '') ? $meta->filename : null;

if ($type == 'fb2') {
	$localFb2 = LOCAL_LIBRARY_PATH . $id . '.fb2';
	if (file_exists($localFb2)) {
		extractFb2CoverFromZip($localFb2, $id);
	} else {
		$zip_name = book_zip_filename($dbh, (int)$id, $u);
		if ($zip_name === '') {
			error_log("extract_cover: fb2 $id: no book_zip entry and no local file");
			cover_placeholder($id);
		}
		// Which entry inside the archive holds this book? In an fb2 archive it is
		// always "<id>.fb2" - the original file name belongs to usr archives, and
		// that is what libfilename stores. Preferring libfilename here named an
		// entry that does not exist, XMLReader could not open it, and every such
		// book silently got a placeholder plus a permanent miss marker. Ask the
		// archive instead of guessing; book_open_source() resolves it the same way.
		$entry = fb2_archive_entry($zip_name, (int)$id, $metaFilename);
		if ($entry === null && $metaFilename !== null
			&& strtolower(pathinfo($metaFilename, PATHINFO_EXTENSION)) === 'zip') {
			// The book is packed as a one-book zip inside the outer archive.
			resolve_inner_zip_book($zip_name, $id, $metaFilename, 'fb2');
			if (file_exists($localFb2)) {
				extractFb2CoverFromZip($localFb2, $id);
			} else {
				error_log("extract_cover: fb2 $id: inner zip '$metaFilename' could not be unpacked from $zip_name");
				cover_placeholder($id);
			}
		} elseif ($entry === null) {
			$tried = "$id.fb2" . ($metaFilename !== null ? ", '$metaFilename'" : '');
			error_log("extract_cover: fb2 $id: no matching entry in $zip_name (tried $tried)");
			cover_placeholder($id);
		} else {
			extractFb2CoverFromZip('zip://' . realpath($zip_name) . '#' . $entry, $id);
		}
	}
} elseif ($type == 'epub') {
	$zip_name = book_zip_filename($dbh, (int)$id, $u);
	if ($zip_name === '') {
		error_log("extract_cover: epub $id: no book_zip entry");
		cover_placeholder($id);
	}
	$filename = $metaFilename ?? trim("$id.epub");
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
	// A cover was just produced, so drop any miss recorded earlier rather than
	// leaving a marker that contradicts the file next to it. Only here, not on
	// the cached path at the top of the file: that one serves every cover on
	// every page and must not pay for a syscall that changes nothing.
	@unlink(cover_miss_marker($id));
	lastm($fname);
} else {
	// The extractors above have already logged why they came up empty. Record
	// the miss so the next request skips the queries and the XML parse: this is
	// the path a coverless fb2 takes, and it is the expensive one.
	cover_placeholder($id);
}
