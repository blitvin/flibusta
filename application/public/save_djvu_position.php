<?php
// Stores the current DJVU page. Local store only, no database.
include('../init.php');
session_start();

if (!isset($_SESSION['user_id'])) {
	http_response_code(401);
	die();
}

// M5: state-changing endpoint must be POST + CSRF-protected.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validate_csrf_token($_POST['csrf_token'] ?? '')) {
	http_response_code(403);
	die();
}

$user_id = intval($_SESSION['user_id']);
$bookid  = intval($_POST['bookid'] ?? 0);
$page    = intval($_POST['page'] ?? 0);

if ($bookid <= 0 || $page <= 0) {
	http_response_code(400);
	die();
}

position_set($user_id, 'djvu', $bookid, $page);
