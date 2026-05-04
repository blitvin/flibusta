<?php
include('../init.php');
session_start();

if (!isset($_SESSION['user_id'])) {
	die();
}

$user_id = intval($_SESSION['user_id']);
$bookid  = intval($_GET['bookid'] ?? 0);
$page    = intval($_GET['page'] ?? 0);

if ($bookid <= 0 || $page <= 0) {
	die();
}

$stmt = $dbh->prepare("INSERT INTO djvu_progress (user_id, bookid, page) VALUES (?, ?, ?)
	ON CONFLICT (user_id, bookid) DO UPDATE SET page = EXCLUDED.page");
$stmt->execute([$user_id, $bookid, $page]);
