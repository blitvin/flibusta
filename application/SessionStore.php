<?php
/**
 * Administrative view of the session store, independent of where sessions live.
 *
 * Sessions are read and written by the save handler PHP calls for us, but three
 * places in the app need to reach *other people's* sessions: the admin session
 * list, the "log this user out everywhere" that must follow a role change, a
 * password reset or a deletion, and the periodic cleanup. Those used to issue SQL
 * against php_sessions directly, which stops working the moment the session data
 * moves to Redis - hence this interface, with one implementation per backend.
 *
 * init.php picks the implementation and hands it to session_store_init().
 */
interface SessionStore
{
	/**
	 * Every live session, most recently used first.
	 *
	 * @return list<object> rows carrying id, last_accessed, username, user_agent,
	 *                      ip_address - the columns the admin tab renders.
	 */
	public function listSessions(): array;

	/** Drops one session by id. */
	public function deleteSession(string $id): void;

	/**
	 * Drops every session belonging to a user, optionally sparing one - used when a
	 * user changes their own password and should stay logged in here while being
	 * logged out everywhere else.
	 */
	public function deleteUserSessions(int $userId, ?string $exceptId = null): void;

	/** Housekeeping for sessions idle longer than $seconds. */
	public function deleteIdle(int $seconds): void;
}

/* ------------------------------------------------------------------ Postgres */

final class PostgresSessionStore implements SessionStore
{
	public function __construct(private PDO $pdo)
	{
	}

	public function listSessions(): array
	{
		$stmt = $this->pdo->query(
			"SELECT id, last_accessed, username, user_agent, ip_address
			 FROM php_sessions ORDER BY last_accessed DESC"
		);
		return $stmt->fetchAll(PDO::FETCH_OBJ);
	}

	public function deleteSession(string $id): void
	{
		$this->pdo->prepare("DELETE FROM php_sessions WHERE id = ?")->execute([$id]);
	}

	public function deleteUserSessions(int $userId, ?string $exceptId = null): void
	{
		if ($exceptId === null) {
			$this->pdo->prepare("DELETE FROM php_sessions WHERE user_id = ?")->execute([$userId]);
			return;
		}
		$this->pdo->prepare("DELETE FROM php_sessions WHERE user_id = ? AND id != ?")
			->execute([$userId, $exceptId]);
	}

	public function deleteIdle(int $seconds): void
	{
		$stmt = $this->pdo->prepare(
			"DELETE FROM php_sessions WHERE last_accessed < NOW() - INTERVAL '1 second' * ?"
		);
		$stmt->execute([$seconds]);
		// Kept with the delete it belongs to: this table churns and the planner
		// needs the row estimate to stay sane.
		$this->pdo->query("VACUUM ANALYZE php_sessions");
	}
}

/* --------------------------------------------------------------------- Redis */

/**
 * Key layout shared with RedisSessionHandler.
 *
 * sess:<id>        HASH  data, user_id, username, ip, ua, last_accessed - with TTL
 * sess:all         ZSET  id scored by last_accessed, for the admin list
 * sess:user:<uid>  SET   that user's session ids, for "log out everywhere"
 *
 * Membership of sess:all and sess:user:<uid> is allowed to lag behind: a session
 * hash can expire on its own, leaving the id behind in an index. Every read below
 * therefore checks that the hash still exists, and drops the members that vanished.
 */
function session_redis_key(string $id): string
{
	return 'sess:' . $id;
}

function session_redis_user_key(int $userId): string
{
	return 'sess:user:' . $userId;
}

const SESSION_REDIS_INDEX = 'sess:all';

/** Idle lifetime of a session, in seconds, for a client on / off the trusted network. */
function session_redis_ttl(bool $trusted): int
{
	if (!$trusted) {
		// Matches the 4h session cookie set in init.php. (PHP's built-in default of
		// 24 minutes, which the Postgres backend still uses, is far shorter than the
		// cookie it hands out.)
		return 4 * 3600;
	}
	$configured = (int)(getenv('FLIBUSTA_SESSION_TRUSTED_TTL') ?: 0);
	return $configured > 0 ? $configured : 30 * 86400;
}

final class RedisSessionStore implements SessionStore
{
	public function __construct(private Redis $redis)
	{
	}

	public function listSessions(): array
	{
		try {
			$ids = $this->redis->zRevRange(SESSION_REDIS_INDEX, 0, -1);
		} catch (Throwable $e) {
			error_log('Flibusta: cannot list sessions: ' . $e->getMessage());
			return [];
		}
		if (!$ids) {
			return [];
		}

		try {
			$pipe = $this->redis->multi(Redis::PIPELINE);
			foreach ($ids as $id) {
				$pipe->hMGet(session_redis_key((string)$id),
					['user_id', 'username', 'ip', 'ua', 'last_accessed']);
			}
			$rows = $pipe->exec();
		} catch (Throwable $e) {
			error_log('Flibusta: cannot read sessions: ' . $e->getMessage());
			return [];
		}

		$out     = [];
		$vanished = [];
		foreach ($ids as $i => $id) {
			$row = $rows[$i] ?? false;
			// An expired session leaves its id in the index; hMGet then answers with
			// nulls for every field.
			if (!is_array($row) || ($row['last_accessed'] ?? null) === false
					|| ($row['last_accessed'] ?? null) === null) {
				$vanished[] = (string)$id;
				continue;
			}
			$session                = new stdClass();
			$session->id            = (string)$id;
			$session->last_accessed = date('Y-m-d H:i:s', (int)$row['last_accessed']);
			$session->username      = $row['username'] !== false ? $row['username'] : null;
			$session->user_agent    = $row['ua'] !== false ? $row['ua'] : null;
			$session->ip_address    = $row['ip'] !== false ? $row['ip'] : null;
			$out[] = $session;
		}
		if ($vanished) {
			try {
				$this->redis->zRem(SESSION_REDIS_INDEX, ...$vanished);
			} catch (Throwable $e) {
				// Cosmetic only - the entries are already gone from the listing.
			}
		}
		return $out;
	}

	public function deleteSession(string $id): void
	{
		try {
			$userId = $this->redis->hGet(session_redis_key($id), 'user_id');
			$pipe = $this->redis->multi(Redis::PIPELINE);
			$pipe->del(session_redis_key($id));
			$pipe->zRem(SESSION_REDIS_INDEX, $id);
			if ($userId !== false && $userId !== null && (int)$userId > 0) {
				$pipe->sRem(session_redis_user_key((int)$userId), $id);
			}
			$pipe->exec();
		} catch (Throwable $e) {
			error_log('Flibusta: cannot delete session: ' . $e->getMessage());
		}
	}

	public function deleteUserSessions(int $userId, ?string $exceptId = null): void
	{
		try {
			$ids = $this->redis->sMembers(session_redis_user_key($userId));
			if (!$ids) {
				return;
			}
			$pipe = $this->redis->multi(Redis::PIPELINE);
			foreach ($ids as $id) {
				$id = (string)$id;
				if ($exceptId !== null && $id === $exceptId) {
					continue;
				}
				$pipe->del(session_redis_key($id));
				$pipe->zRem(SESSION_REDIS_INDEX, $id);
				$pipe->sRem(session_redis_user_key($userId), $id);
			}
			$pipe->exec();
		} catch (Throwable $e) {
			error_log('Flibusta: cannot delete user sessions: ' . $e->getMessage());
		}
	}

	/**
	 * Redis expires the session data itself, so there is nothing here to delete on a
	 * schedule - and unlike the Postgres backend this does NOT cut trusted-network
	 * sessions off after a couple of days, which was never intended there either.
	 * What is left is trimming index members whose session has already expired.
	 */
	public function deleteIdle(int $seconds): void
	{
		$this->pruneIndex();
	}

	/**
	 * Drops ids from sess:all whose session hash is gone. Only entries older than the
	 * shortest possible session lifetime are even considered, and at most a few
	 * hundred per pass, so this stays cheap on a hot path.
	 */
	public function pruneIndex(int $limit = 500): int
	{
		try {
			$candidates = $this->redis->zRangeByScore(
				SESSION_REDIS_INDEX,
				'-inf',
				(string)(time() - session_redis_ttl(false)),
				['limit' => [0, $limit]]
			);
			if (!$candidates) {
				return 0;
			}
			$pipe = $this->redis->multi(Redis::PIPELINE);
			foreach ($candidates as $id) {
				$pipe->exists(session_redis_key((string)$id));
			}
			$exists = $pipe->exec();

			$gone = [];
			foreach ($candidates as $i => $id) {
				if (empty($exists[$i])) {
					$gone[] = (string)$id;
				}
			}
			if ($gone) {
				$this->redis->zRem(SESSION_REDIS_INDEX, ...$gone);
			}
			return count($gone);
		} catch (Throwable $e) {
			error_log('Flibusta: session index prune failed: ' . $e->getMessage());
			return 0;
		}
	}
}

/* ------------------------------------------------------------------ registry */

/** Installs the store chosen in init.php. */
function session_store_init(SessionStore $store): void
{
	$GLOBALS['__flibusta_session_store'] = $store;
}

/** The store for this request. */
function session_store(): SessionStore
{
	if (!isset($GLOBALS['__flibusta_session_store'])) {
		// Only reachable if a caller skipped init.php; better a clear error than a
		// silent no-op that leaves stale sessions alive after a password change.
		throw new RuntimeException('Session store is not initialised - init.php must run first.');
	}
	return $GLOBALS['__flibusta_session_store'];
}
