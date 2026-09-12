<?php
include("../init.php");
// The buffer callback resolves the per-visitor placeholders (nav chrome, CSRF
// token) that cached pages are stored with. Registering it as the buffer's own
// callback means it runs on every exit path - cache hit, normal render, or a
// module calling die() half way through the page.
ob_start('flib_output_filter');
session_start();
decode_gurl($webroot);
// It is important that no DB access to any table that can be modified by service module phps
// doesn't happen here as lock that guards consistency of the DB is checked in the renderer.php/usr.php/fb2.php
$user_name = 'Книжные полки';
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$is_admin = !empty($_SESSION['is_admin']);

// Generate CSRF token for forms
generate_csrf_token();

// Handle POST favorite actions with CSRF validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $current_user_id > 0) {
	// Validate CSRF token
	$token = $_POST['csrf_token'] ?? '';
	if (!validate_csrf_token($token)) {
		// Invalid CSRF token, silently ignore
	} else {
		$action = $_POST['action'] ?? '';
		$id = intval($_POST['id'] ?? 0);
		
		if ($action === 'fav_book' && $id > 0) {
			$st = $dbh->prepare("INSERT INTO fav (user_id, bookid) VALUES(:uid, :id) ON CONFLICT DO NOTHING");
			$st->bindParam(":uid", $current_user_id);
			$st->bindParam(":id", $id);
			$st->execute();
		}
		if ($action === 'unfav_book' && $id > 0) {
			$st = $dbh->prepare("DELETE FROM fav WHERE user_id=:uid AND bookid=:id");
			$st->bindParam(":uid", $current_user_id);
			$st->bindParam(":id", $id);
			$st->execute();
		}
		if ($action === 'fav_author' && $id > 0) {
			$st = $dbh->prepare("INSERT INTO fav (user_id, avtorid) VALUES(:uid, :id) ON CONFLICT DO NOTHING");
			$st->bindParam(":uid", $current_user_id);
			$st->bindParam(":id", $id);
			$st->execute();
		}
		if ($action === 'unfav_author' && $id > 0) {
			$st = $dbh->prepare("DELETE FROM fav WHERE user_id=:uid AND avtorid=:id");
			$st->bindParam(":uid", $current_user_id);
			$st->bindParam(":id", $id);
			$st->execute();
		}
		if ($action === 'fav_seq' && $id > 0) {
			$st = $dbh->prepare("DELETE FROM fav WHERE user_id=:uid AND seqid=:id");
			$st->bindParam(":uid", $current_user_id);
			$st->bindParam(":id", $id);
			$st->execute();
			$st = $dbh->prepare("INSERT INTO fav (user_id, seqid) VALUES(:uid, :id) ON CONFLICT DO NOTHING");
			$st->bindParam(":uid", $current_user_id);
			$st->bindParam(":id", $id);
			$st->execute();
		}
		if ($action === 'unfav_seq' && $id > 0) {
			$st = $dbh->prepare("DELETE FROM fav WHERE user_id=:uid AND seqid=:id");
			$st->bindParam(":uid", $current_user_id);
			$st->bindParam(":id", $id);
			$st->execute();
		}
		// Favourite stars appear on almost every page: invalidate this user's
		// cached pages and fragments so the page rendered by *this* request
		// already shows the new state.
		bump_user_cache_epoch($current_user_id, 'fav');
	}
}

if (isset($_GET['sort'])) {
	$sort_mode = $_GET['sort'];
} else {
	$sort_mode = 'abc';
	if ($url->action == '') {
		$sort_mode = 'date';
	}
}

if (isset($_GET['page'])) {
	$page = intval($_GET['page']);
} else {
	$page = 0;
}

$start = $page * RECORDS_PAGE;
$lang = 'ru';
$filter = "";

switch ($sort_mode) {
	case 'abc':
		$order = 'b.Title';
		break;

	case 'author':
		$order = 'b.Title';
		break;

	case 'date':
		$order = 'b.Time DESC';
		break;

	case 'rating':
		$order = 'b.Title';
		break;
}

// Page cache is consulted only after the access check, so authentication and
// login redirects are never bypassed. On a hit page_cache_serve() exits.
$page_cache_key = page_cache_key($url, $current_user_id);

if ($url->mod == 'opds') {
	checkOPDSLogin($dbh);
	page_cache_serve($page_cache_key);
	include(ROOT_PATH . "/opds/index.php");
} else {
	checkLogin($dbh, isAdminPath($url),$webroot);
	page_cache_serve($page_cache_key);
	include(ROOT_PATH . 'modules/' . $url->mod . '/module.conf');
	include(ROOT_PATH . "renderer.php");
}

page_cache_store($page_cache_key);

