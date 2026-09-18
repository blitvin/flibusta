<?php
/**
 * Optional Redis cache.
 *
 * Enabled by setting FLIBUSTA_REDIS_HOST. When it is unset the library behaves
 * exactly as it did before: sessions stay in Postgres (see init.php) and every
 * cache_*() call below is a no-op that reports a miss, so callers always need a
 * working path without Redis.
 *
 * Deliberately self-contained - no ROOT_PATH, no functions.php, no $dbh - so the
 * CLI tools under /tools can `require` it on their own.
 *
 * Key layout (all under FLIBUSTA_REDIS_PREFIX, default "flibusta:"):
 *
 *   sess:<id>             session data              RedisSessionHandler
 *   sess:all              ZSET of ids by last use   RedisSessionStore (admin list)
 *   sess:user:<uid>       SET of a user's ids       per-user invalidation
 *   auth:<hmac>           verified Basic credential functions.php basic_auth_ok()
 *   auth:user:<uid>       SET of that user's auth keys
 *   book:<gen>:<id>       book metadata             functions.php book_meta()
 *   book:<gen>:hdr:<id>   authors/genres/series
 *   book:<gen>:rev:<id>   reviews
 *   user:<uid>:prefs      user settings + hidden genres
 *   user:<uid>:fav        favorite ids
 *   user:<uid>:last_book  write-dedup marker, not a read cache
 *   lib:gen               library generation counter, NO TTL
 *   cache:secret          per-boot HMAC key, NO TTL
 *
 * Eviction: run the instance with `--maxmemory <n> --maxmemory-policy volatile-lru`.
 * Everything above except lib:gen and cache:secret carries a TTL and is therefore
 * an eviction candidate; those two must survive, because resetting the generation
 * counter would make it collide with stale book keys. Sessions are touched on every
 * request, so cold book entries are evicted long before any session is.
 */

/** True when the deployment has opted into Redis. */
function flibusta_redis_enabled(): bool
{
	$host = getenv('FLIBUSTA_REDIS_HOST');
	return $host !== false && trim($host) !== '';
}

/**
 * The shared connection, or null when Redis is disabled, unusable or has failed
 * earlier in this request. Never throws: callers treat null as "no cache".
 */
function flibusta_redis(): ?Redis
{
	static $redis = null;
	static $tried = false;

	if ($tried) {
		return $redis;
	}
	$tried = true;

	if (!flibusta_redis_enabled()) {
		return null;
	}
	if (!class_exists('Redis')) {
		error_log('Flibusta: FLIBUSTA_REDIS_HOST is set but the phpredis extension is missing - running without cache.');
		return null;
	}

	$host = trim((string)getenv('FLIBUSTA_REDIS_HOST'));
	$port = (int)(getenv('FLIBUSTA_REDIS_PORT') ?: 6379);

	$password = '';
	if (getenv('FLIBUSTA_REDIS_PASSWORD_FILE')) {
		$fromFile = @file_get_contents((string)getenv('FLIBUSTA_REDIS_PASSWORD_FILE'));
		if ($fromFile !== false) {
			$password = trim($fromFile);
		}
	}
	if ($password === '' && getenv('FLIBUSTA_REDIS_PASSWORD')) {
		$password = (string)getenv('FLIBUSTA_REDIS_PASSWORD');
	}

	try {
		$r = new Redis();
		// Short timeouts on purpose: the cache must never be the reason a page hangs.
		if (!$r->connect($host, $port, 0.5)) {
			error_log("Flibusta: cannot connect to Redis at $host:$port - running without cache.");
			return null;
		}
		$r->setOption(Redis::OPT_READ_TIMEOUT, 1.0);
		if ($password !== '') {
			$r->auth($password);
		}
		$db = (int)(getenv('FLIBUSTA_REDIS_DB') ?: 0);
		if ($db !== 0) {
			$r->select($db);
		}
		$prefix = getenv('FLIBUSTA_REDIS_PREFIX');
		$r->setOption(Redis::OPT_PREFIX, ($prefix === false || $prefix === '') ? 'flibusta:' : $prefix);
	} catch (Throwable $e) {
		error_log('Flibusta: Redis unavailable (' . $e->getMessage() . ') - running without cache.');
		return null;
	}

	$redis = $r;
	return $redis;
}

/**
 * Like flibusta_redis(), but for the session store, which cannot silently degrade:
 * falling back to Postgres mid-flight would split session state across two stores,
 * so an operator who asked for Redis sessions gets a 503 instead of a confusing
 * half-logged-out library.
 */
function flibusta_redis_or_die(): Redis
{
	$r = flibusta_redis();
	if ($r === null) {
		error_log('Flibusta: session store (Redis) is unavailable, refusing the request.');
		if (!headers_sent()) {
			http_response_code(503);
			header('Retry-After: 30');
		}
		die('Session store temporarily unavailable.');
	}
	return $r;
}

/**
 * Marks the connection unusable for the rest of this request after an error, so a
 * Redis that has gone away costs one failed command instead of one per call.
 */
function cache_failed(Throwable $e): void
{
	static $logged = false;
	if (!$logged) {
		error_log('Flibusta: cache operation failed (' . $e->getMessage() . ') - serving this request uncached.');
		$logged = true;
	}
	$GLOBALS['__flibusta_cache_dead'] = true;
}

/** The connection to use for a cache operation, or null when there is none. */
function cache_handle(): ?Redis
{
	if (!empty($GLOBALS['__flibusta_cache_dead'])) {
		return null;
	}
	return flibusta_redis();
}

/**
 * The cached value, or null when absent. A stored value survives round-trip
 * exactly, false included - so `false` works as a "known to be missing" sentinel
 * and is distinguishable from a miss.
 */
function cache_get(string $key): mixed
{
	$r = cache_handle();
	if ($r === null) {
		return null;
	}
	try {
		$raw = $r->get($key);
	} catch (Throwable $e) {
		cache_failed($e);
		return null;
	}
	if ($raw === false || $raw === null) {
		return null;
	}
	// Rows come back from PDO as stdClass under ATTR_DEFAULT_FETCH_MODE; nothing
	// else may be revived from the cache.
	$value = @unserialize($raw, ['allowed_classes' => ['stdClass']]);
	if ($value === false && $raw !== serialize(false)) {
		return null;   // corrupt entry: treat as a miss
	}
	return $value;
}

function cache_set(string $key, mixed $value, int $ttl): void
{
	$r = cache_handle();
	if ($r === null || $ttl <= 0) {
		return;
	}
	try {
		$r->setex($key, $ttl, serialize($value));
	} catch (Throwable $e) {
		cache_failed($e);
	}
}

function cache_del(string ...$keys): void
{
	$r = cache_handle();
	if ($r === null || $keys === []) {
		return;
	}
	try {
		$r->del($keys);
	} catch (Throwable $e) {
		cache_failed($e);
	}
}

/**
 * Read-through: the cached value, otherwise whatever $produce returns, stored for
 * $ttl. A null result is not cached, so a failed lookup is retried next time.
 */
function cache_remember(string $key, int $ttl, callable $produce): mixed
{
	$cached = cache_get($key);
	if ($cached !== null) {
		return $cached;
	}
	$value = $produce();
	if ($value !== null) {
		cache_set($key, $value, $ttl);
	}
	return $value;
}

/**
 * A secret shared by every worker, used to key the Basic-auth cache so that the
 * credentials themselves are never derivable from a key. It lives only in Redis and
 * carries no TTL: a Redis restart rotates it, which simply orphans the auth entries
 * that were keyed with the old one (they expire on their own).
 *
 * Without Redis this returns per-request randomness, which is correct by accident:
 * there is no cache to key, and nothing can match a previous request's key.
 */
function cache_secret(): string
{
	static $secret = null;
	if ($secret !== null) {
		return $secret;
	}

	$r = cache_handle();
	if ($r === null) {
		$secret = bin2hex(random_bytes(32));
		return $secret;
	}
	try {
		$fresh = bin2hex(random_bytes(32));
		$r->set('cache:secret', $fresh, ['nx']);   // first worker to get here wins
		$stored = $r->get('cache:secret');
		$secret = ($stored === false || $stored === null || $stored === '') ? $fresh : (string)$stored;
	} catch (Throwable $e) {
		cache_failed($e);
		$secret = bin2hex(random_bytes(32));
	}
	return $secret;
}

/**
 * The library generation, part of every book cache key.
 *
 * Instead of hunting down individual book entries when a dump is imported or the
 * archives are rescanned - which would mean scanning the keyspace - the generation
 * is bumped and every old key becomes unreachable, ageing out by TTL.
 */
function lib_generation(): int
{
	// Held in a global rather than a function static so that a bump later in the
	// same request (the addbook module) is visible to everything after it.
	if (isset($GLOBALS['__flibusta_lib_gen'])) {
		return $GLOBALS['__flibusta_lib_gen'];
	}
	$r = cache_handle();
	if ($r === null) {
		return $GLOBALS['__flibusta_lib_gen'] = 1;
	}
	try {
		$value = $r->get('lib:gen');
		if ($value === false || $value === null) {
			$r->set('lib:gen', 1, ['nx']);
			$value = $r->get('lib:gen');
		}
		return $GLOBALS['__flibusta_lib_gen'] = max(1, (int)$value);
	} catch (Throwable $e) {
		cache_failed($e);
		return $GLOBALS['__flibusta_lib_gen'] = 1;
	}
}

/** Invalidates every book cache entry at once. Returns the new generation. */
function lib_generation_bump(): int
{
	$r = cache_handle();
	if ($r === null) {
		return 1;
	}
	try {
		return $GLOBALS['__flibusta_lib_gen'] = (int)$r->incr('lib:gen');
	} catch (Throwable $e) {
		cache_failed($e);
		return 1;
	}
}

/** Key for anything derived from library (dump) content. */
function book_cache_key(string $suffix): string
{
	return 'book:' . lib_generation() . ':' . $suffix;
}
