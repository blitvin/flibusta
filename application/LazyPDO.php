<?php
/**
 * A PDO that does not connect until it is actually used.
 *
 * dbinit.php is included by init.php on every single request, so the library used
 * to open a Postgres connection even for responses that never read a row: a cover
 * served from /cache with a 304, a scroll-position beacon, a 401 from the file
 * endpoints, a download whose file is already extracted under /cache/local. With
 * the session store moved off Postgres (see RedisSessionHandler) those requests
 * touch no table at all, and the connect is the only cost left.
 *
 * It has to be a real PDO subclass rather than a wrapper object, because $dbh is
 * passed to code that type-hints PDO (PostgresSessionHandler::__construct,
 * sanitize_annotations.php, tools/merge_local_books.php) and to an `instanceof PDO`
 * check. The parent constructor is deliberately never called: PDO keeps its handle
 * in internal state, and every method that would touch that state is overridden
 * here to delegate to $inner instead. That also means any PDO method NOT overridden
 * below would fail with "PDO object is not initialized, constructor was not called"
 * - so when moving to a newer PHP, compare this class against
 * `php -r 'print_r(get_class_methods("PDO"));'` and add whatever is new.
 */
final class LazyPDO extends PDO
{
	private ?PDO $inner = null;

	/** Statements issued through this handle; a debug aid, see FLIBUSTA_DEBUG_DB. */
	public static int $statements = 0;

	/**
	 * @param array<int,mixed> $attrs PDO::ATTR_* applied once the connection opens.
	 */
	public function __construct(
		private string $dsn,
		private ?string $username = null,
		private ?string $password = null,
		private array $attrs = []
	) {
		// No parent::__construct() on purpose - that is the whole point of the class.
	}

	/** True once a connection has actually been opened. */
	public function isConnected(): bool
	{
		return $this->inner !== null;
	}

	/** The real connection, opened on first use. */
	private function conn(): PDO
	{
		if ($this->inner === null) {
			try {
				$pdo = new PDO($this->dsn, $this->username, $this->password);
				foreach ($this->attrs as $attribute => $value) {
					$pdo->setAttribute($attribute, $value);
				}
				$this->inner = $pdo;
			} catch (Throwable $e) {
				self::fail($e);
			}
		}
		return $this->inner;
	}

	/**
	 * Reproduces the behaviour dbinit.php had when the connect happened at include
	 * time: log the driver message (it can carry the DSN), tell the client nothing
	 * beyond "unavailable", stop.
	 *
	 * The connection now opens mid-request, so a page may already have written
	 * markup into the output buffer. Discard it, otherwise the 500 body would be
	 * half a rendered page.
	 */
	private static function fail(Throwable $e): never
	{
		error_log('Flibusta DB connection failed: ' . $e->getMessage());
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		if (!headers_sent()) {
			http_response_code(500);
		}
		die('Database temporarily unavailable.');
	}

	public function prepare(string $query, array $options = []): PDOStatement|false
	{
		self::$statements++;
		return $this->conn()->prepare($query, $options);
	}

	public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
	{
		self::$statements++;
		return $this->conn()->query($query, $fetchMode, ...$fetchModeArgs);
	}

	public function exec(string $statement): int|false
	{
		self::$statements++;
		return $this->conn()->exec($statement);
	}

	public function beginTransaction(): bool
	{
		return $this->conn()->beginTransaction();
	}

	public function commit(): bool
	{
		return $this->conn()->commit();
	}

	public function rollBack(): bool
	{
		return $this->conn()->rollBack();
	}

	/**
	 * Answered without connecting: a handle that never opened cannot be inside a
	 * transaction, and this is called in error paths (`if ($dbh->inTransaction())
	 * $dbh->rollBack()`) where connecting just to say "no" would be perverse.
	 */
	public function inTransaction(): bool
	{
		return $this->inner !== null && $this->inner->inTransaction();
	}

	public function lastInsertId(?string $name = null): string|false
	{
		return $this->conn()->lastInsertId($name);
	}

	public function quote(string $string, int $type = PDO::PARAM_STR): string|false
	{
		// Quoting rules are the driver's, so this one does need the connection.
		return $this->conn()->quote($string, $type);
	}

	public function setAttribute(int $attribute, mixed $value): bool
	{
		$this->attrs[$attribute] = $value;
		if ($this->inner !== null) {
			return $this->inner->setAttribute($attribute, $value);
		}
		return true;   // remembered, and applied when the connection opens
	}

	public function getAttribute(int $attribute): mixed
	{
		if ($this->inner === null && array_key_exists($attribute, $this->attrs)) {
			return $this->attrs[$attribute];
		}
		return $this->conn()->getAttribute($attribute);
	}

	public function errorCode(): ?string
	{
		return $this->inner === null ? null : $this->inner->errorCode();
	}

	public function errorInfo(): array
	{
		return $this->inner === null ? ['', null, null] : $this->inner->errorInfo();
	}
}
