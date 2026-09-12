<?php
// Stores the scroll position for fb2/txt/pdf/rtf/docx/mobi/html readers.
// Called on every scroll tick (66 ms debounce), so it deliberately touches no
// database: the position lives in the local per-user store.
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
$pos     = floatval($_POST['pos'] ?? 0);

if ($bookid <= 0) {
	http_response_code(400);
	die();
}

// Position 0 means "back at the top" - drop the entry rather than store it.
position_set($user_id, 'pos', $bookid, $pos == 0 ? null : $pos);
