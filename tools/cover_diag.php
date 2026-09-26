<?php
/**
 * Explains why a book shows a cover, or does not.
 *
 * extract_cover.php logs one line per failure, which tells you what broke but not
 * the state around it. This prints everything that decision is made from - the
 * libbook/libfilename/libbpics rows, the archive the index and the table each
 * resolve to, which entries actually exist inside that archive, a dry run of the
 * XML parse, and the cover/marker files in /cache/covers.
 *
 * Read-only: nothing is written, no cover is generated, no marker is touched.
 *
 *     docker exec -u www-data flibusta-fpm php /tools/cover_diag.php 173909 173910
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	die("CLI only\n");
}

$appRoot = getenv('FLIBUSTA_APP_ROOT') ?: '/application/';
if (substr($appRoot, -1) !== '/') {
	$appRoot .= '/';
}
if (!defined('ROOT_PATH')) {
	define('ROOT_PATH', $appRoot);
}
if (!defined('CACHE_PATH')) {
	define('CACHE_PATH', '/cache/');
}
if (!defined('LIBRARY_PATH')) {
	define('LIBRARY_PATH', '/flibusta/');
}
if (!defined('LOCAL_LIBRARY_PATH')) {
	define('LOCAL_LIBRARY_PATH', '/cache/local/');
}

require_once $appRoot . 'functions.php';
require_once $appRoot . 'dbinit.php';
require_once $appRoot . 'zipindex.php';

$ids = array_slice($argv, 1);
if (!$ids) {
	fwrite(STDERR, "usage: php /tools/cover_diag.php <bookid> [<bookid> ...]\n");
	exit(1);
}

/** file existence plus mtime, in one line */
function stamp(string $path): string {
	if (!file_exists($path)) {
		return 'absent';
	}
	return 'present, ' . date('Y-m-d H:i:s', filemtime($path)) . ', ' . filesize($path) . ' bytes';
}

/** The cover id inside an fb2 stream, or the reason it could not be found. */
function probe_fb2_cover(string $streamPath): string {
	$reader = new XMLReader();
	if (!@$reader->open($streamPath)) {
		return 'XMLReader cannot open it';
	}
	$coverId = null;
	while (@$reader->read()) {
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
	$reader->close();
	return $coverId === null ? 'opens, but carries no <coverpage> image' : "coverpage image '$coverId'";
}

echo 'zip index: ' . ZIP_INDEX_FILE . ' - ' . stamp(ZIP_INDEX_FILE) . "\n";
echo 'covers dir: ' . CACHE_PATH . 'covers/ - '
	. (is_writable(CACHE_PATH . 'covers') ? 'writable' : 'NOT WRITABLE by ' . get_current_user()) . "\n";
echo 'cover archive: ' . CACHE_PATH . 'lib.b.attached.zip - ' . stamp(CACHE_PATH . 'lib.b.attached.zip') . "\n";

foreach ($ids as $arg) {
	$id = (int)$arg;
	echo "\n=== book $id ===\n";

	$stmt = $dbh->prepare("SELECT filetype, title FROM libbook WHERE bookid = ? LIMIT 1");
	$stmt->execute([$id]);
	$book = $stmt->fetch(PDO::FETCH_OBJ);
	if (!$book) {
		echo "  libbook: no row - extract_cover.php answers with the placeholder\n";
		continue;
	}
	$type = trim((string)$book->filetype);
	$usr  = ($type === 'fb2') ? 0 : 1;
	echo "  libbook: filetype='$type' usr=$usr title='" . trim((string)$book->title) . "'\n";

	$stmt = $dbh->prepare("SELECT filename FROM libfilename WHERE BookId = ? LIMIT 1");
	$stmt->execute([$id]);
	$fn = $stmt->fetch(PDO::FETCH_OBJ);
	$dbFilename = ($fn && $fn->filename !== null && $fn->filename !== '') ? $fn->filename : null;
	echo '  libfilename: ' . ($dbFilename === null ? 'no row' : "'$dbFilename'") . "\n";

	$stmt = $dbh->prepare("SELECT file FROM libbpics WHERE BookId = ? LIMIT 1");
	$stmt->execute([$id]);
	$pic = $stmt->fetch(PDO::FETCH_OBJ);
	echo '  libbpics: ' . ($pic && $pic->file !== '' ? "'{$pic->file}' (cover comes from lib.b.attached.zip)" : 'no row') . "\n";

	// The two sources of the archive name, so a disagreement is visible.
	$fromIndex = zip_index_lookup($id, $usr);
	$stmt = $dbh->prepare("SELECT filename FROM book_zip WHERE ? BETWEEN start_id AND end_id AND usr = ?");
	$stmt->execute([$id, $usr]);
	$row = $stmt->fetch(PDO::FETCH_OBJ);
	$fromTable = $row ? (string)$row->filename : '';
	echo '  archive from index: ' . ($fromIndex === null ? 'no index file' : ($fromIndex === '' ? 'no range covers this id' : $fromIndex)) . "\n";
	echo '  archive from book_zip: ' . ($fromTable === '' ? 'no row' : $fromTable) . "\n";
	if ($fromIndex !== null && $fromIndex !== '' && $fromTable !== '' && $fromIndex !== $fromTable) {
		echo "  NOTE: index and table disagree - the index wins at runtime\n";
	}

	$zipName = book_zip_filename($dbh, $id, $usr);
	if ($zipName === '') {
		echo "  resolved archive: none - extract_cover.php answers with the placeholder\n";
	} else {
		echo "  resolved archive: $zipName - " . (is_file($zipName) ? 'exists' : 'MISSING ON DISK') . "\n";
		if (is_file($zipName)) {
			$zip = new ZipArchive();
			if ($zip->open($zipName) !== true) {
				echo "  archive cannot be opened\n";
			} else {
				foreach (array_filter(["$id.fb2", $dbFilename]) as $candidate) {
					echo "  entry '$candidate': " . ($zip->locateName($candidate) !== false ? 'present' : 'absent') . "\n";
				}
				$zip->close();
			}
		}
	}

	$localFb2 = LOCAL_LIBRARY_PATH . $id . '.fb2';
	echo "  local copy $localFb2: " . stamp($localFb2) . "\n";

	if ($type === 'fb2') {
		if (file_exists($localFb2)) {
			echo '  fb2 parse (local): ' . probe_fb2_cover($localFb2) . "\n";
		} elseif ($zipName !== '' && is_file($zipName)) {
			$entry = fb2_archive_entry($zipName, $id, $dbFilename);
			if ($entry === null) {
				echo "  fb2 parse: no usable entry in the archive\n";
			} else {
				echo "  fb2 parse (entry '$entry'): " . probe_fb2_cover('zip://' . realpath($zipName) . '#' . $entry) . "\n";
			}
		}
	} else {
		echo "  $type: no cover is extracted from this format - only libbpics can supply one\n";
	}

	echo '  covers/' . $id . '.jpg: ' . stamp(CACHE_PATH . "covers/$id.jpg") . "\n";
	echo '  covers/' . $id . '-small.jpg: ' . stamp(CACHE_PATH . "covers/$id-small.jpg") . "\n";
	$marker = CACHE_PATH . "covers/$id.none";
	echo '  covers/' . $id . '.none: ' . stamp($marker);
	if (file_exists($marker)) {
		$fresh = true;
		foreach ([CACHE_PATH . 'lib.b.attached.zip', ZIP_INDEX_FILE, $localFb2] as $p) {
			if (file_exists($p) && filemtime($p) > filemtime($marker)) {
				$fresh = false;
				break;
			}
		}
		echo $fresh
			? " - STILL SUPPRESSING the lookup (placeholder served without trying)"
			: " - stale, the lookup runs again";
	}
	echo "\n";
}
