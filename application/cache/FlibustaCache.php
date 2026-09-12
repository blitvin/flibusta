<?php
// Backend-independent cache contract. Implementations: FileCache (default,
// /cache volume), RedisCache (external redis service), NullCache (caching off).
interface FlibustaCache {
	// Returns null on a miss or an expired entry.
	public function get(string $key): mixed;

	// $ttl in seconds; 0 = no expiry (entries then die only with the cache epoch).
	public function set(string $key, mixed $value, int $ttl = 0): bool;

	public function delete(string $key): bool;

	// Atomic +1, returns the new value. Used for per-user invalidation epochs.
	public function increment(string $key): int;

	// Drops everything this instance owns.
	public function clear(): bool;

	// get(), falling back to $fn() and caching its result.
	public function getOrCompute(string $key, int $ttl, callable $fn): mixed;
}
