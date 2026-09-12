<?php
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

if ($pos == 0) {
	$stmt = $dbh->prepare("DELETE FROM progress WHERE user_id=:uid AND bookid=:id");
	$stmt->bindParam(":uid", $user_id);
	$stmt->bindParam(":id", $bookid);
	$stmt->execute();
	die();
}

$stmt = $dbh->prepare("INSERT INTO progress (user_id, bookid, pos) VALUES (:uid, :id, :pos) ON CONFLICT(user_id, bookid) DO UPDATE set pos=:pos2");
$stmt->bindParam(":uid", $user_id);
$stmt->bindParam(":id", $bookid);
$stmt->bindParam(":pos", $pos);
$stmt->bindParam(":pos2", $pos);
$stmt->execute();
