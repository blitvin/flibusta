<?php
// Redis-backed cache (phpredis extension), for deployments that already run a
// redis service on a shared Docker network.
//
// Fails open: if redis is unreachable or an operation errors, we log once per
// request and behave like NullCache for the rest of it. A missing cache must
// never turn into an error page - the site just runs uncached.
final class RedisCache implements FlibustaCache {
	private ?Redis $redis = null;
	private bool $failed = false;
	private string $host;
	private int $port;
	private int $db;
	private string $password;
	private string $prefix;

	public function __construct(string $host, int $port, int $db, #[\SensitiveParameter] string $password, string $prefix) {
		$this->host = $host;
		$this->port = $port;
		$this->db = $db;
		$this->password = $password;
		$this->prefix = $prefix;
	}

	private function conn(): ?Redis {
		if ($this->failed) {
			return null;
		}
		if ($this->redis === null) {
			try {
				$r = new Redis();
				// Short timeouts: a hung redis must not hold a php-fpm worker.
				if (!$r->connect($this->host, $this->port, 1.0, null, 0, 1.0)) {
					throw new RedisException('connect() returned false');
				}
				if ($this->password !== '') {
					$r->auth($this->password);
				}
				if ($this->db !== 0) {
					$r->select($this->db);
				}
				$this->redis = $r;
			} catch (Throwable $e) {
				$this->fail($e);
				return null;
			}
		}
		return $this->redis;
	}

	private function fail(Throwable $e): void {
		if (!$this->failed) {
			error_log('Flibusta cache: redis unavailable, continuing uncached: ' . $e->getMessage());
		}
		$this->failed = true;
		$this->redis = null;
	}

	private function k(string $key): string {
		return $this->prefix . $key;
	}

	public function get(string $key): mixed {
		$r = $this->conn();
		if ($r === null) {
			return null;
		}
		try {
			$raw = $r->get($this->k($key));
			if ($raw === false || $raw === null) {
				return null;
			}
			$value = @unserialize($raw, ['allowed_classes' => [stdClass::class]]);
			return $value === false && $raw !== serialize(false) ? null : $value;
		} catch (Throwable $e) {
			$this->fail($e);
			return null;
		}
	}

	public function set(string $key, mixed $value, int $ttl = 0): bool {
		$r = $this->conn();
		if ($r === null) {
			return false;
		}
		try {
			$payload = serialize($value);
			return $ttl > 0
				? (bool)$r->setex($this->k($key), $ttl, $payload)
				: (bool)$r->set($this->k($key), $payload);
		} catch (Throwable $e) {
			$this->fail($e);
			return false;
		}
	}

	public function delete(string $key): bool {
		$r = $this->conn();
		if ($r === null) {
			return false;
		}
		try {
			$r->del($this->k($key));
			return true;
		} catch (Throwable $e) {
			$this->fail($e);
			return false;
		}
	}

	public function increment(string $key): int {
		$r = $this->conn();
		if ($r === null) {
			return 0;
		}
		try {
			return (int)$r->incr($this->k($key));
		} catch (Throwable $e) {
			$this->fail($e);
			return 0;
		}
	}

	// Only keys carrying our prefix are removed, so a shared redis instance keeps
	// whatever else lives in the same database.
	public function clear(): bool {
		$r = $this->conn();
		if ($r === null) {
			return false;
		}
		try {
			$it = null;
			do {
				$keys = $r->scan($it, $this->prefix . '*', 500);
				if ($keys) {
					$r->del($keys);
				}
			} while ($it > 0);
			return true;
		} catch (Throwable $e) {
			$this->fail($e);
			return false;
		}
	}

	public function getOrCompute(string $key, int $ttl, callable $fn): mixed {
		$hit = $this->get($key);
		if ($hit !== null) {
			return $hit;
		}
		$value = $fn();
		if ($value !== null) {
			$this->set($key, $value, $ttl);
		}
		return $value;
	}
}
