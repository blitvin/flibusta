<?php
// Deferred database connection.
//
// The page/data caches let many requests be served without touching Postgres at
// all (cache hits, cover files already extracted, book downloads resolved from
// the local book_zip map). Connecting eagerly in dbinit.php would throw that
// away, so the web SAPI gets this forwarding wrapper instead of a real PDO and
// the connection is opened on the first query.
//
// Every call site uses $dbh->prepare()/query()/... without a PDO type hint, so
// __call forwarding is transparent. CLI tools keep getting a real PDO (see
// dbinit.php) because they connect immediately anyway and some of them type-hint
// PDO explicitly.
final class LazyPDO {
	private ?PDO $pdo = null;
	private string $dsn;
	private string $user;
	private string $password;

	public function __construct(string $dsn, string $user, #[\SensitiveParameter] string $password) {
		$this->dsn = $dsn;
		$this->user = $user;
		$this->password = $password;
	}

	// Opens the connection on first use. Error handling mirrors what dbinit.php
	// used to do eagerly.
	public function connect(): PDO {
		if ($this->pdo === null) {
			try {
				$pdo = new PDO($this->dsn, $this->user, $this->password);
				$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
				$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
				$this->pdo = $pdo;
			} catch (Exception $e) {
				// L3: never leak connection details (DSN/host) to output.
				error_log('Flibusta DB connection failed: ' . $e->getMessage());
				http_response_code(500);
				die('Database temporarily unavailable.');
			}
		}
		return $this->pdo;
	}

	// True when a query has already forced the connection open. Used by
	// diagnostics (X-Db debug header) to verify cache hits stay DB-free.
	public function isConnected(): bool {
		return $this->pdo !== null;
	}

	public function __call(string $name, array $args): mixed {
		return $this->connect()->$name(...$args);
	}
}
