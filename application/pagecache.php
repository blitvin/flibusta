<?php
// Whole-page cache for the front controller.
//
// A hit is answered from the cache before any module runs, so nothing queries
// Postgres - combined with the file-backed sessions and the local book_zip map,
// such a request opens no database connection at all (see LazyPDO).
//
// What may be cached (see page_cache_key):
//   - Read-only modules only. Anything that writes (fav, settings, users,
//     service, addbook) is excluded, as is any non-GET request.
//   - The primary module is skipped while it is processing filter parameters,
//     because those mutate $_SESSION; the resulting filter state is part of the
//     key instead.
//
// Cache keys carry two invalidation tokens so nothing has to be actively purged:
//   - the global epoch, bumped by a dump import/reindex and by the admin
//     "clear cache" operation;
//   - the user's own epoch, bumped when they change favourites or settings, so
//     the request that made the change already renders fresh output.
//
// CSRF tokens are never stored. A page is stored with the token replaced by a
// placeholder and the current session's token is substituted back in on the way
// out (see csrf_cache_encode/decode). That keeps live tokens off the disk and
// means pages that differ only by their token are one cache entry, so entries
// can be shared by all sessions of a user instead of one copy per session.

// Placeholder standing in for a CSRF token inside a stored page. Deliberately
// distinctive so it cannot collide with book/author text from the dump.
define('CSRF_CACHE_PLACEHOLDER', '@@FLIBUSTA_CSRF_TOKEN_PLACEHOLDER@@');

// Modules whose output never depends on request state we do not model. The
// remaining allowed modules are deliberately absent: fav/settings/users/service/
// addbook write state, favlist and 404 are not worth caching.
function page_cache_cacheable_modules(): array {
	return ['primary', 'book', 'author', 'authors', 'series', 'genres', 'help', 'opds'];
}

// Modules that keep browsing filters in the session: a query parameter sets the
// filter and later plain URLs render from it. For those, the request carrying
// the parameter is not cached (it mutates the session) and the resulting filter
// state becomes part of the key - otherwise a filtered listing would be stored
// under the unfiltered URL and served to everybody.
function page_cache_session_filters(): array {
	return [
		'primary' => [
			'params'  => ['fb2', 'ru', 'q', 'aid', 'gid', 'sid', 'xgid', 'xgl'],
			'session' => ['fb2', 'ru', 'search', 'filter_author', 'filter_genre',
			              'filter_xgenre', 'filter_series', 'xgenres_off'],
		],
		'authors' => [
			'params'  => ['q', 'letter'],
			'session' => ['authors_q', 'authors_letter'],
		],
		'series' => [
			'params'  => ['q', 'letter'],
			'session' => ['series_q', 'series_letter'],
		],
	];
}

// Replaces this session's CSRF token with the placeholder, so no live token is
// ever written to the cache.
function csrf_cache_encode(string $body): string {
	$token = $_SESSION['csrf_token'] ?? '';
	if ($token === '' || strpos($body, (string)$token) === false) {
		return $body;
	}
	return str_replace((string)$token, CSRF_CACHE_PLACEHOLDER, $body);
}

// Puts the current session's token back into a page coming out of the cache.
// Also covers token rotation: a page stored before the token changed still gets
// the current one.
function csrf_cache_decode(string $body): string {
	if (strpos($body, CSRF_CACHE_PLACEHOLDER) === false) {
		return $body;   // nothing to fill - never creates a token needlessly
	}
	return str_replace(CSRF_CACHE_PLACEHOLDER, get_csrf_token(), $body);
}

// Query parameters that only select a view of the same content and so belong in
// the key. Anything else present in the query string makes the request uncached
// rather than risking a wrong hit.
function page_cache_known_params(): array {
	return ['sort', 'page'];
}

// The preferences that change what a module renders. Anything not listed here
// must not differ between two visitors looking at the same URL - if you add a
// preference that changes output, add it here too, or users will see each
// other's rendering.
//
// Both lookups are served from the fragment cache and are invalidated by the
// user's cache epoch, so a preference change produces a different facet (and
// therefore a different key) on the very next request.
function page_cache_facets(string $mod, int $currentUserId): array {
	global $dbh;
	$facets = [];
	if ($mod === 'book') {
		// Which of the two book layouts this visitor gets by default.
		$facets['view_mode'] = $currentUserId > 0
			? get_user_prefs($dbh, $currentUserId)->book_view_mode
			: 'contentonly';
	}
	if ($mod === 'primary') {
		// Hidden genres are filtered out of the listing itself.
		$facets['xgenres'] = $currentUserId > 0 ? get_excluded_genres($dbh, $currentUserId) : [];
	}
	return $facets;
}

// Returns the cache key for this request, or null if it must not be cached.
function page_cache_key(stdClass $url, int $currentUserId): ?string {
	if (!flib_cache_enabled()) {
		return null;
	}
	$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	if ($method !== 'GET' && $method !== 'HEAD') {
		return null;
	}
	$mod = $url->mod;
	if (!in_array($mod, page_cache_cacheable_modules(), true)) {
		return null;
	}

	$extra = '';
	$filterSpec = page_cache_session_filters()[$mod] ?? null;
	if ($filterSpec !== null) {
		// These parameters rewrite the session filter state; let the module do
		// that and cache the plain URLs that follow.
		foreach ($filterSpec['params'] as $p) {
			if (isset($_GET[$p])) {
				return null;
			}
		}
		// The listing depends on the filters currently held in the session.
		$filters = [];
		foreach ($filterSpec['session'] as $k) {
			$filters[$k] = $_SESSION[$k] ?? null;
		}
		$extra = sha1(serialize($filters));
	}
	if ($mod === 'opds') {
		// Personal feeds must never be shared.
		if ($url->action === 'fav' || $url->action === 'favs') {
			return null;
		}
		// OPDS search carries arbitrary query parameters; include them verbatim.
		$q = $_GET;
		ksort($q);
		$extra = sha1(serialize($q));
	}

	if ($mod !== 'opds') {
		foreach (array_keys($_GET) as $p) {
			if (!in_array($p, page_cache_known_params(), true)) {
				return null;
			}
		}
	}

	// Keyed by what actually changes the rendered bytes, not by who is asking.
	// Everything genuinely per-visitor has been lifted out of the page body:
	// the nav chrome and CSRF token are placeholders filled on the way out, and
	// favourite marks are drawn by the browser from user_state.php. What remains
	// are display preferences, so two visitors whose preferences agree - and an
	// anonymous visitor, who gets the defaults - share one entry.
	$variant = 'v' . sha1(serialize(page_cache_facets($mod, $currentUserId)));

	$parts = [
		$mod,
		$url->action ?? '',
		$url->var1 ?? '',
		$url->var2_str ?? '',
		$url->var3 ?? '',
		$_GET['sort'] ?? '',
		$_GET['page'] ?? '',
		$extra,
	];
	return 'page:v1:g' . cache_global_epoch() . ':' . $variant . ':' . sha1(implode('|', $parts));
}

// Serves a cached page and exits, or returns so the request renders normally.
function page_cache_serve(?string $key): void {
	if ($key === null) {
		return;
	}
	$entry = flib_page_cache()->get($key);
	if (!is_array($entry) || !isset($entry['b'])) {
		return;
	}

	// The maintenance page must still win over a cache hit: while an import
	// holds the lock the library is inconsistent. Same check renderer.php does,
	// and it costs no database access.
	$lock = @fopen(DBUPDATE_LOCK, 'r');
	if ($lock !== false) {
		if (flock($lock, LOCK_SH | LOCK_NB) === false) {
			fclose($lock);
			return;   // let renderer.php render the 503 maintenance page
		}
		flock($lock, LOCK_UN);
		fclose($lock);
	}

	foreach ($entry['h'] ?? [] as $header) {
		header($header);
	}
	header('X-Cache: hit');
	// Emitted with its placeholders intact; flib_output_filter() substitutes this
	// visitor's nav chrome and CSRF token as the buffer flushes.
	echo (string)$entry['b'];
	exit;
}

// Stores the buffered response. Called after the page has been rendered.
// The buffer still holds the placeholder form of the page (flib_output_filter
// only runs on flush), so what gets written is already visitor-neutral.
function page_cache_store(?string $key): void {
	if ($key === null || headers_sent()) {
		return;
	}
	if (http_response_code() !== 200) {
		return;
	}
	$body = ob_get_contents();
	if ($body === false || $body === '') {
		return;
	}

	// Only replay headers that describe the body. Anything else (cookies,
	// redirects) is per-request and must not be resurrected for another visitor.
	$headers = [];
	foreach (headers_list() as $header) {
		if (stripos($header, 'Content-Type:') === 0) {
			$headers[] = $header;
		}
		if (stripos($header, 'Location:') === 0) {
			return;   // a redirect is not a page
		}
	}

	// Swap the live CSRF token for a placeholder before anything is written.
	$body = csrf_cache_encode($body);

	// Belt and braces: nothing recognisable as this session's token may survive
	// into storage. If it somehow does, skip caching rather than write it out.
	if (!empty($_SESSION['csrf_token']) && strpos($body, (string)$_SESSION['csrf_token']) !== false) {
		error_log('Flibusta cache: refusing to store a page still containing a CSRF token');
		return;
	}

	flib_page_cache()->set($key, ['h' => $headers, 'b' => $body], PAGE_CACHE_TTL);
}
