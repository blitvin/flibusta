<?php
// File-backed cache on the /cache volume — the default backend.
//
// Needs no extra service and survives container replacement (in both deployment
// configs /cache is a host bind mount). Writes go through a temp file + rename()
// so a concurrent reader never sees a half-written entry; keys are hashed and
// sharded over 256 directories to keep directory sizes sane.
final class FileCache implements FlibustaCache {
	private string $baseDir;

	public function __construct(string $baseDir) {
		$this->baseDir = rtrim($baseDir, '/');
	}

	private function path(string $key): string {
		$h = sha1($key);
		return $this->baseDir . '/' . substr($h, 0, 2) . '/' . $h . '.cache';
	}

	public function get(string $key): mixed {
		$path = $this->path($key);
		$raw = @file_get_contents($path);
		if ($raw === false || $raw === '') {
			return null;
		}
		$entry = @unserialize($raw, ['allowed_classes' => [stdClass::class]]);
		if (!is_array($entry) || !array_key_exists('v', $entry)) {
			@unlink($path); // corrupt or truncated - treat as a miss
			return null;
		}
		if (!empty($entry['e']) && $entry['e'] < time()) {
			@unlink($path);
			return null;
		}
		return $entry['v'];
	}

	public function set(string $key, mixed $value, int $ttl = 0): bool {
		$path = $this->path($key);
		$dir = dirname($path);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			return false;
		}
		$payload = serialize(['e' => $ttl > 0 ? time() + $ttl : 0, 'v' => $value]);
		$tmp = $dir . '/.' . bin2hex(random_bytes(8)) . '.tmp';
		if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
			return false;
		}
		if (!@rename($tmp, $path)) { // atomic within the same directory
			@unlink($tmp);
			return false;
		}
		return true;
	}

	public function delete(string $key): bool {
		return @unlink($this->path($key)) || !file_exists($this->path($key));
	}

	public function increment(string $key): int {
		$path = $this->path($key);
		$dir = dirname($path);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			return 0;
		}
		// Counters must not be lost to a concurrent bump, so unlike set() this is
		// a locked read-modify-write in place rather than a rename.
		$fh = @fopen($path, 'c+');
		if ($fh === false) {
			return 0;
		}
		$value = 0;
		if (flock($fh, LOCK_EX)) {
			$raw = stream_get_contents($fh);
			$entry = $raw !== '' ? @unserialize($raw, ['allowed_classes' => false]) : null;
			$value = (is_array($entry) && isset($entry['v'])) ? (int)$entry['v'] : 0;
			$value++;
			$payload = serialize(['e' => 0, 'v' => $value]);
			rewind($fh);
			ftruncate($fh, 0);
			fwrite($fh, $payload);
			fflush($fh);
			flock($fh, LOCK_UN);
		}
		fclose($fh);
		return $value;
	}

	public function clear(): bool {
		if (!is_dir($this->baseDir)) {
			return true;
		}
		$ok = true;
		foreach (scandir($this->baseDir) ?: [] as $shard) {
			if ($shard === '.' || $shard === '..') {
				continue;
			}
			$shardPath = $this->baseDir . '/' . $shard;
			if (!is_dir($shardPath)) {
				$ok = @unlink($shardPath) && $ok;
				continue;
			}
			foreach (scandir($shardPath) ?: [] as $file) {
				if ($file === '.' || $file === '..') {
					continue;
				}
				$ok = @unlink($shardPath . '/' . $file) && $ok;
			}
			@rmdir($shardPath);
		}
		return $ok;
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
