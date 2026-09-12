<?php
// Per-user reading state on the /cache volume: scroll positions, EPUB CFIs,
// DJVU page numbers and the "last book opened" pointer.
//
// This used to be three Postgres tables written by an XHR on every scroll tick
// (66 ms debounce in modules/book/fb.php) - by far the heaviest write path in the
// app, one UPSERT plus a connection per tick. It is low-value, high-frequency,
// strictly per-user data, so a small JSON file per user serves it better.
//
// Layout: CACHE_PATH/positions/<user_id>.json
//   {"v":1,"last":<bookid>,"pos":{"<bookid>":42.5},"epub":{...},"djvu":{...}}
// Writes are a locked read-modify-write of that one file, so parallel tabs of the
// same user cannot lose each other's updates.
//
// Guarded define: also included standalone from CLI tools.
if (!defined('CACHE_PATH')) {
	define('CACHE_PATH', '/cache/');
}
define('POSITIONS_PATH', CACHE_PATH . 'positions/');

// The three kinds map 1:1 onto the old progress / epub_progress / djvu_progress.
function positions_valid_kind(string $kind): bool {
	return $kind === 'pos' || $kind === 'epub' || $kind === 'djvu';
}

function positions_file(int $userId): string {
	return POSITIONS_PATH . $userId . '.json';
}

function positions_decode(string $raw): array {
	if ($raw === '') {
		return [];
	}
	$data = json_decode($raw, true);
	// A truncated or hand-edited file must not break reading - start over instead.
	return is_array($data) ? $data : [];
}

function positions_load(int $userId): array {
	if ($userId <= 0) {
		return [];
	}
	$raw = @file_get_contents(positions_file($userId));
	return $raw === false ? [] : positions_decode($raw);
}

function position_get(int $userId, string $kind, int $bookid): mixed {
	if ($userId <= 0 || $bookid <= 0 || !positions_valid_kind($kind)) {
		return null;
	}
	$data = positions_load($userId);
	return $data[$kind][(string)$bookid] ?? null;
}

function position_get_last_book(int $userId): ?int {
	$data = positions_load($userId);
	$last = $data['last'] ?? null;
	return $last ? (int)$last : null;
}

// Applies $mutator to the user's decoded data under an exclusive lock and writes
// the result back. All mutations go through here so concurrent savers of
// different kinds (a scroll tick and a "last book" touch) cannot clobber.
function positions_mutate(int $userId, callable $mutator): bool {
	if ($userId <= 0) {
		return false;
	}
	if (!is_dir(POSITIONS_PATH) && !@mkdir(POSITIONS_PATH, 0775, true) && !is_dir(POSITIONS_PATH)) {
		return false;
	}
	$fh = @fopen(positions_file($userId), 'c+');
	if ($fh === false) {
		return false;
	}
	if (!flock($fh, LOCK_EX)) {
		fclose($fh);
		return false;
	}
	$raw = stream_get_contents($fh);
	$data = positions_decode($raw === false ? '' : $raw);
	$data = $mutator($data);
	$data['v'] = 1;
	rewind($fh);
	ftruncate($fh, 0);
	fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE));
	fflush($fh);
	flock($fh, LOCK_UN);
	fclose($fh);
	return true;
}

// $value === null removes the entry (used when the reader scrolls back to 0).
function position_set(int $userId, string $kind, int $bookid, mixed $value): bool {
	if ($bookid <= 0 || !positions_valid_kind($kind)) {
		return false;
	}
	return positions_mutate($userId, function (array $data) use ($kind, $bookid, $value) {
		if ($value === null) {
			unset($data[$kind][(string)$bookid]);
		} else {
			$data[$kind][(string)$bookid] = $value;
		}
		return $data;
	});
}

// Replaces user_settings.last_book: records the book the user is currently
// reading, for the "continue reading" login redirect.
function position_touch_last_book(int $userId, int $bookid): bool {
	if ($bookid <= 0) {
		return false;
	}
	return positions_mutate($userId, function (array $data) use ($bookid) {
		$data['last'] = $bookid;
		return $data;
	});
}

function positions_delete_user(int $userId): void {
	if ($userId > 0) {
		@unlink(positions_file($userId));
	}
}

// Called by tools/merge_local_books.php when a locally added book is superseded
// by the same book from a dump: reading state follows the surviving id. An
// existing entry for the target id wins (it is the newer read).
function positions_remap_book(int $fromId, int $toId): void {
	if ($fromId <= 0 || $toId <= 0 || $fromId === $toId || !is_dir(POSITIONS_PATH)) {
		return;
	}
	foreach (glob(POSITIONS_PATH . '*.json') ?: [] as $path) {
		$userId = (int)basename($path, '.json');
		if ($userId <= 0) {
			continue;
		}
		positions_mutate($userId, function (array $data) use ($fromId, $toId) {
			foreach (['pos', 'epub', 'djvu'] as $kind) {
				if (isset($data[$kind][(string)$fromId])) {
					if (!isset($data[$kind][(string)$toId])) {
						$data[$kind][(string)$toId] = $data[$kind][(string)$fromId];
					}
					unset($data[$kind][(string)$fromId]);
				}
			}
			if (isset($data['last']) && (int)$data['last'] === $fromId) {
				$data['last'] = $toId;
			}
			return $data;
		});
	}
}
