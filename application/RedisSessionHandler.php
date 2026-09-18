<?php
/**
 * Sessions in Redis.
 *
 * Used instead of PostgresSessionHandler when FLIBUSTA_REDIS_HOST is set. The
 * motivation is not that Postgres is slow at this, it is that every entry point
 * calls session_start() before it does anything else, so as long as sessions live
 * in Postgres *every* request opens a DB connection and runs at least a SELECT and
 * an UPSERT - a cover served from cache with a 304, a scroll-position beacon, a
 * rejected request, a download of a file already sitting in /cache/local. Moving
 * the session out is what lets those requests touch no database at all.
 *
 * Two differences from the Postgres handler, both deliberate:
 *
 * - It implements SessionUpdateTimestampHandlerInterface, so PHP's session.lazy_write
 *   applies: a request that only reads the session refreshes a timestamp instead of
 *   rewriting the whole row. The Postgres handler cannot do this, which is why it
 *   upserts on every single request today.
 * - A brand-new session that stays empty is not stored at all. PHP calls write()
 *   even for an untouched $_SESSION, so cookie-less OPDS readers on the trusted
 *   network currently create a php_sessions row per request; here they create
 *   nothing.
 *
 * Key layout and the index-lag rule are documented in SessionStore.php, which this
 * file relies on being loaded first.
 */
final class RedisSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
	/** Whether this client is on the trusted network, which buys a much longer TTL. */
	private bool $trusted;

	/** Per-id snapshot of what read()/validateId() saw, to avoid re-reading. */
	private array $seen = [];

	public function __construct(private Redis $redis, string $trustedNet)
	{
		$this->trusted = $trustedNet !== ''
			&& ipInNetwork($_SERVER['REMOTE_ADDR'] ?? '', $trustedNet);
	}

	private function ttl(): int
	{
		return session_redis_ttl($this->trusted);
	}

	/**
	 * The stored fields for an id, read at most once per request.
	 *
	 * @return array{exists: bool, data: string, user_id: ?int}
	 */
	private function fetch(string $id): array
	{
		if (isset($this->seen[$id])) {
			return $this->seen[$id];
		}
		$state = ['exists' => false, 'data' => '', 'user_id' => null];
		try {
			$row = $this->redis->hMGet(session_redis_key($id), ['data', 'user_id']);
			if (is_array($row) && isset($row['data']) && $row['data'] !== false && $row['data'] !== null) {
				$state['exists'] = true;
				$state['data']   = (string)$row['data'];
				// An anonymous session stores '' for user_id (hashes hold no nulls),
				// which must read back as "no user", not as user 0.
				$uid = $row['user_id'] ?? false;
				$state['user_id'] = ($uid === false || $uid === null || $uid === '' || (int)$uid <= 0)
					? null : (int)$uid;
			}
		} catch (Throwable $e) {
			// Fail closed rather than silently handing out a fresh empty session:
			// PHP turns this into a session start failure, which is the honest answer.
			error_log('Flibusta: session read failed: ' . $e->getMessage());
			throw $e;
		}
		$this->seen[$id] = $state;
		return $state;
	}

	public function open($savePath, $sessionName): bool
	{
		return true;   // init.php already established the connection
	}

	public function close(): bool
	{
		return true;
	}

	public function read($id): string
	{
		return $this->fetch((string)$id)['data'];
	}

	public function write($id, $data): bool
	{
		$id     = (string)$id;
		$state  = $this->seen[$id] ?? ['exists' => false, 'data' => '', 'user_id' => null];
		$userId = null;
		if (isset($_SESSION) && is_array($_SESSION)
				&& isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
			$userId = (int)$_SESSION['user_id'];
		}

		// Nothing worth remembering and nothing remembered yet: an anonymous
		// request that never touched $_SESSION. Storing it would mean a key per
		// request from clients that do not even keep cookies.
		if ($userId === null && !$state['exists'] && ($data === '' || $data === 'a:0:{}')) {
			return true;
		}

		$username = null;
		if (isset($_SESSION['username']) && is_scalar($_SESSION['username'])) {
			$username = substr((string)$_SESSION['username'], 0, 50);
		}
		$ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 64) : '';
		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 512) : '';
		$now = time();
		$ttl = $this->ttl();
		$key = session_redis_key($id);

		try {
			$tx = $this->redis->multi();
			$tx->hMSet($key, [
				'data'          => $data,
				'user_id'       => $userId === null ? '' : $userId,
				'username'      => $username === null ? '' : $username,
				'ip'            => $ip,
				'ua'            => $ua,
				'last_accessed' => $now,
			]);
			$tx->expire($key, $ttl);
			$tx->zAdd(SESSION_REDIS_INDEX, $now, $id);
			if ($userId !== null) {
				$userKey = session_redis_user_key($userId);
				$tx->sAdd($userKey, $id);
				// Outlive the longest session it can contain, refreshed on every add,
				// so the set cannot leak while a user keeps logging in.
				$tx->expire($userKey, session_redis_ttl(true));
			}
			// A session that changed hands (log in, then log in as someone else)
			// must not stay listed under the previous user.
			if ($state['user_id'] !== null && $state['user_id'] !== $userId) {
				$tx->sRem(session_redis_user_key($state['user_id']), $id);
			}
			$tx->exec();
		} catch (Throwable $e) {
			error_log('Flibusta: session write failed: ' . $e->getMessage());
			return false;
		}

		$this->seen[$id] = ['exists' => true, 'data' => $data, 'user_id' => $userId];
		return true;
	}

	/**
	 * Called by PHP instead of write() when the session data has not changed
	 * (session.lazy_write). This is the common case for a reader browsing the
	 * library, and it is why the session costs one cheap command per request.
	 */
	public function updateTimestamp($id, $data): bool
	{
		$id    = (string)$id;
		$state = $this->seen[$id] ?? null;
		if ($state === null || !$state['exists']) {
			// Never stored in the first place (an empty session): a plain write()
			// decides properly whether it is worth storing at all.
			return $this->write($id, $data);
		}

		$now = time();
		$key = session_redis_key($id);

		// Conditional on purpose, and atomic. A bare HSET would *create* the hash if
		// the session had expired or been deleted since read() - leaving a key with
		// a timestamp and no session data, and quietly resurrecting a session an
		// admin had just revoked. Touch it only while it is really there.
		$script = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
redis.call('HSET', KEYS[1], 'last_accessed', ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[2])
redis.call('ZADD', KEYS[2], ARGV[1], ARGV[3])
return 1
LUA;
		try {
			$this->redis->eval($script, [$key, SESSION_REDIS_INDEX, $now, $this->ttl(), $id], 2);
		} catch (Throwable $e) {
			error_log('Flibusta: session touch failed: ' . $e->getMessage());
			return false;
		}
		return true;
	}

	/**
	 * Both session_destroy() and the old id of session_regenerate_id(true) land
	 * here, so the indexes are kept straight on log-out and on log-in alike.
	 */
	public function destroy($id): bool
	{
		$id     = (string)$id;
		$userId = ($this->seen[$id]['user_id'] ?? null);
		if ($userId === null) {
			try {
				$stored = $this->redis->hGet(session_redis_key($id), 'user_id');
				if ($stored !== false && $stored !== null && (int)$stored > 0) {
					$userId = (int)$stored;
				}
			} catch (Throwable $e) {
				// Fall through: the session itself still gets deleted below.
			}
		}

		try {
			$tx = $this->redis->multi();
			$tx->del(session_redis_key($id));
			$tx->zRem(SESSION_REDIS_INDEX, $id);
			if ($userId !== null) {
				$tx->sRem(session_redis_user_key($userId), $id);
			}
			$tx->exec();
		} catch (Throwable $e) {
			error_log('Flibusta: session destroy failed: ' . $e->getMessage());
			return false;
		}
		unset($this->seen[$id]);
		return true;
	}

	/**
	 * Redis expires the sessions themselves; what is collected here are index
	 * members left behind by sessions that have already expired.
	 */
	public function gc($maxlifetime): int|false
	{
		return (new RedisSessionStore($this->redis))->pruneIndex();
	}

	/**
	 * With session.use_strict_mode on, PHP asks before adopting the id from the
	 * cookie, so a client cannot pick its own session id. The lookup is memoised,
	 * so the read() that follows for a valid id costs nothing extra.
	 */
	public function validateId($id): bool
	{
		$id = (string)$id;
		if (!preg_match('/^[A-Za-z0-9,\-]{22,256}$/', $id)) {
			return false;
		}
		try {
			return $this->fetch($id)['exists'];
		} catch (Throwable $e) {
			return false;
		}
	}
}
