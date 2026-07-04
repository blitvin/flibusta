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

$downloadBaseName = $book->author_name . ' - ' . $book->booktitle . ' ' . $id;

// Validate final_format parameter
$final_format = '';
if (isset($_GET['final_format'])) {
	$_fmt = strtolower(trim($_GET['final_format']));
	if (in_array($_fmt, ['zip', 'epub2', 'epub3', 'kepub', 'kfx', 'azw8', 'pdf', 'txt', 'md'], true)) {
		$final_format = $_fmt;
	}
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function send_download_headers(string $name, string $mime, int $size): void {
	header('Content-Description: File Transfer');
	header('Content-Type: ' . $mime);
	header('Content-Disposition: attachment; filename="' . rawurlencode(basename($name)) . '"');
	header('Content-Transfer-Encoding: binary');
	header('Content-Length: ' . $size);
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
}

// Returns the path to a local .fb2 file, extracting/downloading if needed.
function ensure_local_fb2(int $id, string $localPath, $dbh, $fname, string $innerZipName): ?string {
	if (file_exists($localPath)) return $localPath;

	$stmt = $dbh->prepare("SELECT * FROM book_zip WHERE ? BETWEEN start_id AND end_id AND usr=0");
	$stmt->execute([$id]);
	$zipRow = $stmt->fetch();

	if ($zipRow) {
		// Try inner-zip extraction (writes to LOCAL_LIBRARY_PATH and returns path)
		$resolved = resolve_inner_zip_book($zipRow->filename, $id, $innerZipName, 'fb2');
		if ($resolved !== null) return $resolved;

		// Fallback: file sits directly in the outer zip
		if ($fname !== null && strtolower(pathinfo($fname, PATHINFO_EXTENSION)) !== 'zip') {
			$zip = new ZipArchive();
			if ($zip->open($zipRow->filename) === true) {
				if ($zip->locateName($fname) !== false) {
					$data = $zip->getFromName($fname);
					$zip->close();
					if ($data !== false) {
						file_put_contents($localPath, $data);
						return $localPath;
					}
				} else {
					$zip->close();
				}
			}
		}
	}

	return fetchMissingBook($id, 'fb2');
}

// ── Default: send as .fb2 (existing behaviour) ───────────────────────────────

if ($final_format === '') {
	$downloadName = $downloadBaseName . '.fb2';

	function send_fb2_headers(string $name): void {
		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename=' . basename(rawurlencode($name)));
		header('Content-Transfer-Encoding: binary');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
	}

	$localPath = LOCAL_LIBRARY_PATH . $id . '.fb2';
	if (file_exists($localPath)) {
		send_fb2_headers($downloadName);
		readfile($localPath);
		exit;
	}

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
	$localPath = resolve_inner_zip_book($zip_name, $id, $innerZipName, 'fb2');
	if ($localPath !== null) {
		send_fb2_headers($downloadName);
		readfile($localPath);
		exit;
	}
	$localPath = fetchMissingBook($id, 'fb2');
	if ($localPath !== null) {
		send_fb2_headers($downloadName);
		readfile($localPath);
		exit;
	}
	http_response_code(404);
	echo "Book file not found in archive";
	exit;
}

// ── Conversion formats ────────────────────────────────────────────────────────

$fmt_ext = [
	'zip'   => '.fb2.zip',
	'epub2' => '.epub',
	'epub3' => '.epub',
	'kepub' => '.kepub.epub',
	'kfx'   => '.kfx',
	'azw8'  => '.azw8',
	'pdf'   => '.pdf',
	'txt'   => '.txt',
	'md'    => '.md',
];
$fmt_mime = [
	'zip'   => 'application/zip',
	'epub2' => 'application/epub+zip',
	'epub3' => 'application/epub+zip',
	'kepub' => 'application/epub+zip',
	'kfx'   => 'application/vnd.amazon.ebook',
	'azw8'  => 'application/vnd.amazon.ebook',
	'pdf'   => 'application/pdf',
	'txt'   => 'text/plain; charset=utf-8',
	'md'    => 'text/markdown; charset=utf-8',
];
$kindle_formats = ['kfx', 'azw8'];

$ext          = $fmt_ext[$final_format];
$mime         = $fmt_mime[$final_format];
$downloadName = $downloadBaseName . $ext;

// ── .fb2.zip via PHP ZipArchive (no external tool needed) ────────────────────

if ($final_format === 'zip') {
	$localFb2 = ensure_local_fb2($id, LOCAL_LIBRARY_PATH . $id . '.fb2', $dbh, $fname, $innerZipName);
	if ($localFb2 === null || !file_exists($localFb2)) {
		http_response_code(404);
		echo "Book file not found";
		exit;
	}
	$tmpZip = tempnam(sys_get_temp_dir(), 'fb2zip_');
	$za = new ZipArchive();
	if ($za->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
		$za->addFile($localFb2, $id . '.fb2');
		$za->close();
		send_download_headers($downloadName, $mime, filesize($tmpZip));
		readfile($tmpZip);
		unlink($tmpZip);
	} else {
		http_response_code(500);
		echo "Failed to create zip";
	}
	exit;
}

// ── All other formats via fbc convert ────────────────────────────────────────

$localFb2 = ensure_local_fb2($id, LOCAL_LIBRARY_PATH . $id . '.fb2', $dbh, $fname, $innerZipName);
if ($localFb2 === null || !file_exists($localFb2)) {
	http_response_code(404);
	echo "Book file not found";
	exit;
}

$cacheFile = LOCAL_LIBRARY_PATH . $id . '.' . $final_format;

// Purge fbc log files older than one week on each conversion request.
$_fbcLogExpiry = time() - 7 * 86400;
foreach (glob(sys_get_temp_dir() . '/fbc*.log') as $_fbcLog) {
	if (filemtime($_fbcLog) < $_fbcLogExpiry) {
		@unlink($_fbcLog);
	}
}

$lockFile = sys_get_temp_dir() . '/fbc_' . $id . '_' . $final_format . '.lock';
$lockFp = fopen($lockFile, 'c');
flock($lockFp, LOCK_EX);
try {
	if (!file_exists($cacheFile)) {
		$tmpDir = sys_get_temp_dir() . '/fbc_' . uniqid('', true);
		mkdir($tmpDir, 0700, true);
		register_shutdown_function(function() use ($tmpDir) {
			if (is_dir($tmpDir)) exec('rm -rf ' . escapeshellarg($tmpDir));
		});

		$tmpOut = $tmpDir . '/' . $id . $ext;
		$cmd = '/usr/local/bin/fbc convert'
		     . ' --to ' . $final_format
		     . (in_array($final_format, $kindle_formats, true) ? ' --eb' : '')
		     . ' -o ' . escapeshellarg($tmpOut)
		     . ' ' . escapeshellarg($localFb2)
		     . ' 2>&1';

		error_log("fb2.php executing: $cmd");
		exec($cmd, $cmdOut, $retcode);

		if ($retcode !== 0 || !file_exists($tmpOut)) {
			error_log("fb2.php fbc failed for book $id (format $final_format): " . implode("\n", $cmdOut));
			http_response_code(500);
			echo "Conversion failed";
			exit;
		}

		rename($tmpOut, $cacheFile);
	}
} finally {
	flock($lockFp, LOCK_UN);
	fclose($lockFp);
	@unlink($lockFile);
}

send_download_headers($downloadName, $mime, filesize($cacheFile));
readfile($cacheFile);
exit;
