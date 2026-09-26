<?php
/**
 * One page of a cbr/cbz comic, as an image.
 *
 * Addressed by page NUMBER, never by file name: the names inside a comic archive
 * come from whoever packed it, so letting a request name a file would hand it a
 * path. The number indexes the list comic_pages() built, and only files that are
 * already in the book's own unpacked directory can be reached.
 *
 * Same access rule as usr.php and extract_cover.php - comic pages are library
 * content, not public assets.
 */

if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
	$id = (int)$_GET['id'];
} else {
	http_response_code(400);
	die();
}
$page = (isset($_GET['page']) && ctype_digit((string)$_GET['page'])) ? (int)$_GET['page'] : 1;

error_reporting(E_ALL);
include('../init.php');
session_start();
checkFileAccess($dbh, $webroot);

$book = book_meta($dbh, $id);
if (!$book) {
	http_response_code(404);
	die();
}
$ext = strtolower(trim((string)$book->filetype));
if (!in_array($ext, ['cbr', 'cbz'], true)) {
	http_response_code(404);
	die();
}

$pages = comic_pages($dbh, $id, $ext);
if ($pages === null || $page < 1 || $page > count($pages)) {
	http_response_code(404);
	die();
}

$file = comic_dir($id) . $pages[$page - 1];
if (!is_file($file)) {
	http_response_code(404);
	die();
}

$types = [
	'jpg'  => 'image/jpeg',
	'jpeg' => 'image/jpeg',
	'png'  => 'image/png',
	'gif'  => 'image/gif',
	'webp' => 'image/webp',
	'avif' => 'image/avif',
];
$type = $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

$mtime = filemtime($file);
header('Content-Type: ' . $type);
// "private": the response depends on who is asking, so no shared cache may keep it.
header('Cache-Control: private, max-age=86400');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $mtime <= strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
	http_response_code(304);
	die();
}
header('Content-Length: ' . filesize($file));
readfile($file);
