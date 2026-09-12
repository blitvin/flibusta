<?php
// Caching disabled (FLIBUSTA_CACHE_BACKEND=none) and the fallback a backend
// degrades to when it is unreachable: every get() misses, every write is a no-op,
// so callers transparently fall back to Postgres.
final class NullCache implements FlibustaCache {
	public function get(string $key): mixed { return null; }
	public function set(string $key, mixed $value, int $ttl = 0): bool { return false; }
	public function delete(string $key): bool { return true; }
	public function increment(string $key): int { return 0; }
	public function clear(): bool { return true; }
	public function getOrCompute(string $key, int $ttl, callable $fn): mixed { return $fn(); }
}
