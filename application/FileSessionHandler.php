<?php
// File-backed session storage (replaces PostgresSessionHandler).
//
// Sessions were the reason every single request had to open a Postgres
// connection: session_start() did a SELECT and shutdown did an UPSERT. Moving
// them to the /cache volume is what makes DB-free cache hits possible.
//
// Compared to the Postgres handler this also fixes a real bug: there was no
// locking at all, so parallel requests for the same session (a book page fires
// cover and position requests concurrently) could clobber each other's
// $_SESSION changes. Here the session file is flock()ed for the whole request.
//
// Layout, one pair of files per session under CACHE_PATH/sessions:
//   sess_<id>       - the serialized session payload
//   sess_<id>.meta  - JSON {user_id, username, ip_address, user_agent, last_accessed}
// The sidecar carries exactly the columns the Postgres table denormalized for
// the admin "active sessions" view and for logging a user out everywhere.
class FileSessionHandler implements SessionHandlerInterface {
	private string $dir;
	private string $trustedNet;
	private $fh = null;          // handle of the currently locked session file
	private string $openId = '';

	public function __construct(string $dir, string $trustedNet = '') {
		$this->dir = rtrim($dir, '/');
		$this->trustedNet = $trustedNet;
	}

	// Session ids come from the client cookie, so never interpolate one into a
	// path without checking it. PHP's own id alphabet is a subset of this.
	private static function validId(string $id): bool {
		return (bool)preg_match('/^[A-Za-z0-9,\-]{22,256}$/', $id);
	}

	private function dataPath(string $id): string {
		return $this->dir . '/sess_' . $id;
	}

	private function metaPath(string $id): string {
		return $this->dir . '/sess_' . $id . '.meta';
	}

	public function open($savePath, $sessionName): bool {
		if (!is_dir($this->dir)) {
			@mkdir($this->dir, 0770, true);
		}
		return true;
	}

	public function close(): bool {
		$this->releaseLock();
		return true;
	}

	private function releaseLock(): void {
		if ($this->fh !== null) {
			flock($this->fh, LOCK_UN);
			fclose($this->fh);
			$this->fh = null;
			$this->openId = '';
		}
	}

	// Opens the session file and keeps an exclusive lock until close(), which is
	// what serializes concurrent requests sharing one session.
	public function read($id): string {
		if (!self::validId((string)$id)) {
			return '';
		}
		$this->releaseLock();
		$path = $this->dataPath($id);
		$fh = @fopen($path, 'c+');
		if ($fh === false) {
			return '';
		}
		if (!flock($fh, LOCK_EX)) {
			fclose($fh);
			return '';
		}
		$this->fh = $fh;
		$this->openId = (string)$id;
		$data = stream_get_contents($fh);
		return $data === false ? '' : $data;
	}

	public function write($id, $data): bool {
		if (!self::validId((string)$id)) {
			return false;
		}
		// Normally we still hold the lock taken in read(); a session created by
		// session_regenerate_id() may not have been read, so take one here.
		$ownLock = false;
		if ($this->fh === null || $this->openId !== (string)$id) {
			$this->releaseLock();
			$fh = @fopen($this->dataPath($id), 'c+');
			if ($fh === false) {
				return false;
			}
			flock($fh, LOCK_EX);
			$this->fh = $fh;
			$this->openId = (string)$id;
			$ownLock = true;
		}
		rewind($this->fh);
		ftruncate($this->fh, 0);
		fwrite($this->fh, (string)$data);
		fflush($this->fh);
		$this->writeMeta((string)$id);
		if ($ownLock) {
			$this->releaseLock();
		}
		return true;
	}

	private function writeMeta(string $id): void {
		$userId = null;
		$username = null;
		if (isset($_SESSION) && is_array($_SESSION)) {
			if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
				$userId = (int)$_SESSION['user_id'];
			}
			if (isset($_SESSION['username']) && is_scalar($_SESSION['username'])) {
				$username = substr((string)$_SESSION['username'], 0, 50);
			}
		}
		$meta = [
			'user_id' => $userId,
			'username' => $username,
			'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 64) : null,
			'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 512) : null,
			'last_accessed' => time(),
		];
		$path = $this->metaPath($id);
		$tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
		if (@file_put_contents($tmp, json_encode($meta, JSON_UNESCAPED_UNICODE)) !== false) {
			@rename($tmp, $path);
		} else {
			@unlink($tmp);
		}
	}

	public function destroy($id): bool {
		if (!self::validId((string)$id)) {
			return false;
		}
		if ($this->openId === (string)$id) {
			$this->releaseLock();
		}
		@unlink($this->dataPath($id));
		@unlink($this->metaPath($id));
		return true;
	}

	// Same policy the Postgres handler had: sessions whose last request came from
	// the trusted network are never expired (they pair with the one-year cookie
	// set in init.php).
	public function gc($maxlifetime): int|false {
		if (!is_dir($this->dir)) {
			return 0;
		}
		$deleted = 0;
		$cutoff = time() - (int)$maxlifetime;
		foreach (scandir($this->dir) ?: [] as $entry) {
			if (!str_starts_with($entry, 'sess_') || str_ends_with($entry, '.meta') || str_ends_with($entry, '.tmp')) {
				continue;
			}
			$id = substr($entry, 5);
			if (!self::validId($id)) {
				continue;
			}
			$path = $this->dataPath($id);
			$meta = session_store_read_meta($this->metaPath($id));
			$last = $meta['last_accessed'] ?? @filemtime($path);
			if ($last === false || $last >= $cutoff) {
				continue;
			}
			if ($this->trustedNet !== '' && !empty($meta['ip_address'])
			    && function_exists('ipInNetwork') && ipInNetwork($meta['ip_address'], $this->trustedNet)) {
				continue;
			}
			@unlink($path);
			@unlink($this->metaPath($id));
			$deleted++;
		}
		return $deleted;
	}
}

function session_store_dir(): string {
	return rtrim(CACHE_PATH, '/') . '/sessions';
}

function session_store_read_meta(string $path): array {
	$raw = @file_get_contents($path);
	if ($raw === false || $raw === '') {
		return [];
	}
	$meta = json_decode($raw, true);
	return is_array($meta) ? $meta : [];
}

// Every stored session, newest first - backs the admin "active sessions" tab.
// Returns objects with the same field names the php_sessions query produced.
function session_store_list(): array {
	$dir = session_store_dir();
	if (!is_dir($dir)) {
		return [];
	}
	$out = [];
	foreach (scandir($dir) ?: [] as $entry) {
		if (!str_starts_with($entry, 'sess_') || str_ends_with($entry, '.meta') || str_ends_with($entry, '.tmp')) {
			continue;
		}
		$id = substr($entry, 5);
		$meta = session_store_read_meta($dir . '/' . $entry . '.meta');
		$last = $meta['last_accessed'] ?? @filemtime($dir . '/' . $entry);
		$row = new stdClass();
		$row->id = $id;
		$row->user_id = isset($meta['user_id']) ? (int)$meta['user_id'] : null;
		$row->username = $meta['username'] ?? null;
		$row->ip_address = $meta['ip_address'] ?? null;
		$row->user_agent = $meta['user_agent'] ?? null;
		$row->last_accessed = $last ? date('Y-m-d H:i:s', (int)$last) : '';
		$row->last_accessed_ts = (int)($last ?: 0);
		$out[] = $row;
	}
	usort($out, fn($a, $b) => $b->last_accessed_ts <=> $a->last_accessed_ts);
	return $out;
}

function session_store_destroy(string $id): void {
	if (!preg_match('/^[A-Za-z0-9,\-]{22,256}$/', $id)) {
		return;
	}
	$dir = session_store_dir();
	@unlink($dir . '/sess_' . $id);
	@unlink($dir . '/sess_' . $id . '.meta');
}

// Logs a user out everywhere (optionally keeping the current session): used on
// password change and when an admin deletes an account.
function session_store_destroy_user(int $userId, ?string $exceptId = null): void {
	if ($userId <= 0) {
		return;
	}
	foreach (session_store_list() as $row) {
		if ((int)($row->user_id ?? 0) !== $userId) {
			continue;
		}
		if ($exceptId !== null && $row->id === $exceptId) {
			continue;
		}
		session_store_destroy($row->id);
	}
}
