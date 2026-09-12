<?php
// Cache entry point: include this to get flib_cache() plus the invalidation
// helpers. Safe to include from CLI tools as well (config constants are resolved
// here if init.php has not defined them).

include_once __DIR__ . '/FlibustaCache.php';
include_once __DIR__ . '/FileCache.php';
include_once __DIR__ . '/RedisCache.php';
include_once __DIR__ . '/NullCache.php';

// Configuration. init.php defines these from the environment; the guards let
// tools/*.php include this file directly.
if (!defined('CACHE_BACKEND')) {
	$_b = strtolower(trim((string)(getenv('FLIBUSTA_CACHE_BACKEND') ?: 'files')));
	define('CACHE_BACKEND', in_array($_b, ['none', 'files', 'redis'], true) ? $_b : 'files');
	unset($_b);
}
if (!defined('CACHE_PATH')) {
	define('CACHE_PATH', '/cache/');
}
if (!defined('TIMESTAPS_PATH')) {
	define('TIMESTAPS_PATH', '/cache/timestamps/');
}
if (!defined('PAGE_CACHE_TTL')) {
	define('PAGE_CACHE_TTL', (int)(getenv('FLIBUSTA_PAGE_CACHE_TTL') ?: 21600));
}
if (!defined('DATA_CACHE_TTL')) {
	define('DATA_CACHE_TTL', (int)(getenv('FLIBUSTA_DATA_CACHE_TTL') ?: 86400));
}
// File under TIMESTAPS_PATH whose mtime is the global cache epoch. Touched by
// the import/reindex scripts and by the admin "clear cache" operation.
define('CACHE_EPOCH_FILE', TIMESTAPS_PATH . 'cache_epoch');

function flib_cache_build(string $backend): FlibustaCache {
	if ($backend === 'none') {
		return new NullCache();
	}
	if ($backend === 'redis') {
		if (!extension_loaded('redis')) {
			error_log('Flibusta cache: FLIBUSTA_CACHE_BACKEND=redis but the redis extension is missing - falling back to file cache.');
		} else {
			$password = '';
			$pwdFile = getenv('FLIBUSTA_REDIS_PASSWORD_FILE');
			if ($pwdFile && is_readable($pwdFile)) {
				$password = trim((string)file_get_contents($pwdFile));
			} elseif (getenv('FLIBUSTA_REDIS_PASSWORD')) {
				$password = (string)getenv('FLIBUSTA_REDIS_PASSWORD');
			}
			return new RedisCache(
				getenv('FLIBUSTA_REDIS_HOST') ?: 'redis',
				(int)(getenv('FLIBUSTA_REDIS_PORT') ?: 6379),
				(int)(getenv('FLIBUSTA_REDIS_DB') ?: 0),
				$password,
				getenv('FLIBUSTA_REDIS_PREFIX') ?: 'flib:'
			);
		}
	}
	return new FileCache(CACHE_PATH . 'appcache');
}

// Shared cache for fragments/metadata (see functions.php helpers).
function flib_cache(): FlibustaCache {
	static $cache = null;
	if ($cache === null) {
		$cache = flib_cache_build(CACHE_BACKEND);
	}
	return $cache;
}

// Separate instance for whole rendered pages: with the file backend it lives in
// its own directory so the admin "clear cache" op and page-only purges can drop
// pages without discarding the (more expensive to rebuild) fragment caches.
function flib_page_cache(): FlibustaCache {
	static $cache = null;
	if ($cache === null) {
		$cache = (CACHE_BACKEND === 'files') ? new FileCache(CACHE_PATH . 'pagecache') : flib_cache();
	}
	return $cache;
}

function flib_cache_enabled(): bool {
	return CACHE_BACKEND !== 'none';
}

// Global invalidation token. Every cache key embeds it, so bumping the epoch
// file logically drops the whole cache without deleting anything - which is what
// lets the shell scripts invalidate a redis-backed cache without redis-cli.
function cache_global_epoch(): string {
	static $epoch = null;
	if ($epoch === null) {
		$mtime = @filemtime(CACHE_EPOCH_FILE);
		$epoch = $mtime === false ? '0' : (string)$mtime;
	}
	return $epoch;
}

// Bumps the global epoch (admin "clear cache"). Also physically clears the
// backends so file-cache entries do not linger until their TTL.
function cache_clear_all(): void {
	flib_page_cache()->clear();
	flib_cache()->clear();
	@touch(CACHE_EPOCH_FILE);
}

// Request-local store shared by user_cache_epoch() and bump_user_cache_epoch(),
// so a bump is immediately visible to the rest of the request.
function &_flib_user_epochs(): array {
	static $epochs = [];
	return $epochs;
}

// Per-user invalidation counters, part of every user-scoped cache key. Bumped
// whenever something user-visible changes, so the very request that made the
// change already renders fresh data.
//
// Counters are kept per scope rather than one per user, because the things they
// guard are unrelated: favourites change constantly, preferences almost never.
// A single shared counter would make every heart click re-read user_settings.
//   'fav'   - the favourites id sets (user_state.php)
//   'prefs' - display preferences and the hidden-genre list
//
// Note user-scoped keys deliberately do NOT include the global cache epoch: a
// dump import TRUNCATEs only the lib* tables, leaving user data untouched, so
// there is nothing for an import to invalidate here.
function user_cache_epoch(int $userId, string $scope): int {
	if ($userId <= 0) {
		return 0;
	}
	$epochs = &_flib_user_epochs();
	$k = $scope . ':' . $userId;
	if (!isset($epochs[$k])) {
		$epochs[$k] = (int)(flib_cache()->get('uepoch:' . $k) ?? 0);
	}
	return $epochs[$k];
}

function bump_user_cache_epoch(int $userId, string $scope): void {
	if ($userId <= 0) {
		return;
	}
	$epochs = &_flib_user_epochs();
	$k = $scope . ':' . $userId;
	$epochs[$k] = flib_cache()->increment('uepoch:' . $k);
}
