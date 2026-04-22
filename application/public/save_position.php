<?php
include('../init.php');
session_start();

if (!isset($_SESSION['user_id'])) {
	die();
}

$user_id = intval($_SESSION['user_id']);
$bookid = intval($_GET['bookid']);
$pos = floatval($_GET['pos']);

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
