<?php
include('../init.php');
session_start();

if (!isset($_SESSION['user_id'])) {
	http_response_code(401);
	die();
}

// M5: CSRF-protect this state-changing POST endpoint.
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
	http_response_code(403);
	die();
}

$user_id = intval($_SESSION['user_id']);
$bookid  = intval($_POST['bookid'] ?? 0);
$cfi     = trim($_POST['cfi'] ?? '');

if ($bookid <= 0 || $cfi === '') {
	die();
}

$stmt = $dbh->prepare("INSERT INTO epub_progress (user_id, bookid, cfi) VALUES (?, ?, ?)
	ON CONFLICT (user_id, bookid) DO UPDATE SET cfi = EXCLUDED.cfi");
$stmt->execute([$user_id, $bookid, $cfi]);
