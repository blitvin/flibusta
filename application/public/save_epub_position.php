<?php
// Stores the EPUB reading location (CFI). Local store only, no database.
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

// Bound the stored value: it comes from the client and lands in a shared file.
position_set($user_id, 'epub', $bookid, substr($cfi, 0, 512));
