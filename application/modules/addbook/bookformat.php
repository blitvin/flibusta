<?php
// Book file format detection & validation for the addbook module.
// Detection is content-based (magic bytes); the original file extension is
// only consulted to accept plain-text files (txt) that have no magic.
// The zip special case is handled here: epub and docx ARE zip containers and
// are recognized by their signature entries BEFORE a zip is treated as a
// wrapper archive; a wrapper zip must contain exactly one valid book file.

define('ADDBOOK_SUPPORTED_FORMATS', ['fb2', 'epub', 'pdf', 'djvu', 'docx', 'mobi', 'html', 'txt', 'rtf']);

// Detect non-container formats from the first bytes of the file.
function addbook_detect_simple(string $head, string $origExt): ?string {
	if (strncmp($head, '%PDF-', 5) === 0)
		return 'pdf';
	if (strncmp($head, 'AT&TFORM', 8) === 0 && substr($head, 12, 4) === 'DJVU')
		return 'djvu';
	if (strlen($head) > 68 && (substr($head, 60, 8) === 'BOOKMOBI' || substr($head, 60, 8) === 'TEXtREAd'))
		return 'mobi';
	if (strncmp($head, '{\\rtf', 5) === 0)
		return 'rtf';
	if (stripos($head, '<FictionBook') !== false)
		return 'fb2';
	if (stripos($head, '<html') !== false || stripos($head, '<!DOCTYPE html') !== false)
		return 'html';
	if (($origExt === 'txt' || $origExt === '') && strpos($head, "\0") === false && $head !== '')
		return 'txt';
	return null;
}

// Inspect an uploaded file; unwrap a plain zip containing exactly one book.
// Returns ['ok'=>true, 'ext'=>..., 'path'=>payload file, 'tmpdir'=>dir-to-remove-or-null]
// or      ['ok'=>false, 'error'=>message]
function addbook_inspect_upload(string $path, string $origName, int $depth = 0) {
	$origExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
	$head = (string)@file_get_contents($path, false, null, 0, 8192);
	if ($head === '') {
		return ['ok' => false, 'error' => 'Файл пуст или не читается'];
	}

	if (strncmp($head, "PK\x03\x04", 4) !== 0) {
		$ext = addbook_detect_simple($head, $origExt);
		if ($ext === null) {
			return ['ok' => false, 'error' => 'Не удалось распознать формат файла. Поддерживаются: ' . implode(', ', ADDBOOK_SUPPORTED_FORMATS)];
		}
		return ['ok' => true, 'ext' => $ext, 'path' => $path, 'tmpdir' => null];
	}

	// Zip container: epub / docx / wrapper-with-one-book
	$zip = new ZipArchive();
	if ($zip->open($path) !== true) {
		return ['ok' => false, 'error' => 'Повреждённый zip-архив'];
	}

	$mimetype = $zip->getFromName('mimetype');
	if ($mimetype !== false && strpos($mimetype, 'application/epub+zip') !== false) {
		$zip->close();
		return ['ok' => true, 'ext' => 'epub', 'path' => $path, 'tmpdir' => null];
	}
	if ($zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false) {
		$zip->close();
		return ['ok' => true, 'ext' => 'docx', 'path' => $path, 'tmpdir' => null];
	}

	if ($depth > 0) {
		$zip->close();
		return ['ok' => false, 'error' => 'Zip внутри zip не поддерживается'];
	}

	// Wrapper zip: require exactly one file entry
	$entries = [];
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$name = $zip->getNameIndex($i);
		if ($name === false || substr($name, -1) === '/')
			continue; // directory
		$entries[] = $name;
	}
	if (count($entries) !== 1) {
		$zip->close();
		return ['ok' => false, 'error' => 'Zip-архив должен содержать ровно один файл книги (найдено: ' . count($entries) . ')'];
	}

	$tmpdir = CACHE_PATH . 'tmp/addbook_' . bin2hex(random_bytes(8));
	if (!mkdir($tmpdir, 0770, true)) {
		$zip->close();
		return ['ok' => false, 'error' => 'Не удалось создать временный каталог'];
	}
	$innerName = $entries[0];
	$innerPath = $tmpdir . '/' . basename($innerName);
	$src = $zip->getStream($innerName);
	if ($src === false) {
		$zip->close();
		rmdir($tmpdir);
		return ['ok' => false, 'error' => 'Не удалось извлечь файл из zip-архива'];
	}
	$dst = fopen($innerPath, 'wb');
	stream_copy_to_stream($src, $dst);
	fclose($src);
	fclose($dst);
	$zip->close();

	$inner = addbook_inspect_upload($innerPath, $innerName, $depth + 1);
	if (!$inner['ok']) {
		@unlink($innerPath);
		@rmdir($tmpdir);
		return $inner;
	}
	$inner['tmpdir'] = $tmpdir;
	return $inner;
}

// Package the payload as the one-book zip in LOCAL_LIBRARY_PATH following the
// existing naming convention, so book_zip range lookup and update_zip_list.php
// pick it up with no changes. Inner entry name {id}.{ext} matches the
// {id}.{ext} fallback in fb2.php/usr.php (no libfilename row needed).
// Returns [zipPath, usrFlag] or throws RuntimeException.
function addbook_package(int $bookid, string $ext, string $payloadPath): array {
	if ($ext === 'fb2') {
		$zipPath = LOCAL_LIBRARY_PATH . "f.fb2.$bookid-$bookid.zip";
		$usr = 0;
	} else {
		$zipPath = LOCAL_LIBRARY_PATH . "f.usr-$bookid-$bookid.zip";
		$usr = 1;
	}
	$zip = new ZipArchive();
	if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		throw new RuntimeException("cannot create $zipPath");
	}
	if (!$zip->addFile($payloadPath, "$bookid.$ext")) {
		$zip->close();
		@unlink($zipPath);
		throw new RuntimeException("cannot add payload to $zipPath");
	}
	if (!$zip->close()) {
		@unlink($zipPath);
		throw new RuntimeException("cannot finalize $zipPath");
	}
	return [$zipPath, $usr];
}
