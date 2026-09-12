<?php
// Everything about the current visitor that a cached page cannot contain.
//
// Pages are shared between users, so per-user marks (which books/authors/series
// are in this visitor's favourites) cannot be baked into the HTML - the set of
// items on a page is not even known until the page has been rendered. The page
// therefore ships neutral placeholders and this endpoint supplies the state,
// exactly as reading positions are supplied.
//
// Optional bookid/kind parameters fold the reading-position lookup into the same
// request, so a reader page makes one call rather than two.
//
// Reads the session, the cached favourites set and the local positions store;
// normally no database access at all.
include('../init.php');
session_start();

header('Content-Type: application/json; charset=utf-8');
// Per-visitor and constantly changing: must never be stored by any cache.
header('Cache-Control: no-store, private');

$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

if ($user_id <= 0) {
	// Anonymous visitors get a well-formed "nothing here" answer rather than an
	// error: the same page markup runs for them, the marks simply stay neutral.
	echo json_encode(['logged_in' => false, 'csrf' => '', 'fav' => ['books' => [], 'authors' => [], 'series' => []], 'pos' => null]);
	die();
}

$state = [
	'logged_in' => true,
	// Lets JavaScript-built favourite forms post a valid token without any page
	// ever embedding one.
	'csrf' => get_csrf_token(),
	'fav' => user_fav_ids($dbh, $user_id),
];

// Reading position, when the caller is a book reader.
$bookid = intval($_GET['bookid'] ?? 0);
$kind   = (string)($_GET['kind'] ?? '');
if ($bookid > 0 && positions_valid_kind($kind)) {
	$state['pos'] = position_get($user_id, $kind, $bookid);
	// Opening a book for reading is what "last book" means for the
	// continue-reading login redirect.
	position_touch_last_book($user_id, $bookid);
} else {
	$state['pos'] = null;
}

echo json_encode($state);
