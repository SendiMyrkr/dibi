<?php

/**
 * This file is part of the "dibi" - smart database abstraction layer.
 * Copyright (c) 2005 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Dibi\Drivers;

use Dibi;


/**
 * The dibi driver for MySQL database via improved extension.
 *
 * Driver options:
 *   - host => the MySQL server host name
 *   - port (int) => the port number to attempt to connect to the MySQL server
 *   - socket => the socket or named pipe
 *   - username (or user)
 *   - password (or pass)
 *   - database => the database name to select
 *   - options (array) => array of driver specific constants (MYSQLI_*) and values {@see mysqli_options}
 *   - flags (int) => driver specific constants (MYSQLI_CLIENT_*) {@see mysqli_real_connect}
 *   - charset => character encoding to set (default is utf8)
 *   - persistent (bool) => try to find a persistent link?
 *   - unbuffered (bool) => sends query without fetching and buffering the result rows automatically?
 *   - sqlmode => see http://dev.mysql.com/doc/refman/5.0/en/server-sql-mode.html
 *   - resource (mysqli) => existing connection resource
 *   - lazy, profiler, result, substitutes, ... => see Dibi\Connection options
 */
class MySqliDriver implements Dibi\Driver, Dibi\ResultDriver
{
	use Dibi\Strict;

	public const ERROR_ACCESS_DENIED = 1045;
	public const ERROR_DUPLICATE_ENTRY = 1062;
	public const ERROR_DATA_TRUNCATED = 1265;

	/** @var \mysqli|null */
	private $connection;

	/** @var \mysqli_result|null */
	private $resultSet;

	/** @var bool */
	private $autoFree = true;

	/** @var bool  Is buffered (seekable and countable)? */
	private $buffered;


	/**
	 * @throws Dibi\NotSupportedException
	 */
	public function __construct()
	{
		if (!extension_loaded('mysqli')) {
			throw new Dibi\NotSupportedException("PHP extension 'mysqli' is not loaded.");
		}
	}


	/**
	 * Connects to a database.
	 * @throws Dibi\Exception
	 */
	public function connect(array &$config): void
	{
		mysqli_report(MYSQLI_REPORT_OFF);
		if (isset($config['resource'])) {
			$this->connection = $config['resource'];

		} else {
			// default values
			$config += [
				'charset' => 'utf8',
				'timezone' => date('P'),
				'username' => ini_get('mysqli.default_user'),
				'password' => ini_get('mysqli.default_pw'),
				'socket' => (string) ini_get('mysqli.default_socket'),
				'port' => null,
			];
			if (!isset($config['host'])) {
				$host = ini_get('mysqli.default_host');
				if ($host) {
					$config['host'] = $host;
					$config['port'] = ini_get('mysqli.default_port');
				} else {
					$config['host'] = null;
					$config['port'] = null;
				}
			}

			$foo = &$config['flags'];
			$foo = &$config['database'];

			$this->connection = mysqli_init();
			if (isset($config['options'])) {
				if (is_scalar($config['options'])) {
					$config['flags'] = $config['options']; // back compatibility
					trigger_error(__CLASS__ . ": configuration item 'options' must be array; for constants MYSQLI_CLIENT_* use 'flags'.", E_USER_NOTICE);
				} else {
					foreach ((array) $config['options'] as $key => $value) {
						mysqli_options($this->connection, $key, $value);
					}
				}
			}
			@mysqli_real_connect($this->connection, (empty($config['persistent']) ? '' : 'p:') . $config['host'], $config['username'], $config['password'], $config['database'] ?? '', $config['port'] ?? 0, $config['socket'], $config['flags'] ?? 0); // intentionally @

			if ($errno = mysqli_connect_errno()) {
				throw new Dibi\DriverException(mysqli_connect_error(), $errno);
			}
		}

		if (isset($config['charset'])) {
			if (!@mysqli_set_charset($this->connection, $config['charset'])) {
				$this->query("SET NAMES '$config[charset]'");
			}
		}

		if (isset($config['sqlmode'])) {
			$this->query("SET sql_mode='$config[sqlmode]'");
		}

		if (isset($config['timezone'])) {
			$this->query("SET time_zone='$config[timezone]'");
		}

		$this->buffered = empty($config['unbuffered']);
	}


	/**
	 * Disconnects from a database.
	 */
	public function disconnect(): void
	{
		@mysqli_close($this->connection); // @ - connection can be already disconnected
	}


	/**
	 * Executes the SQL query.
	 * @throws Dibi\DriverException
	 */
	public function query(string $sql): ?Dibi\ResultDriver
	{
		$res = @mysqli_query($this->connection, $sql, $this->buffered ? MYSQLI_STORE_RESULT : MYSQLI_USE_RESULT); // intentionally @

		if ($code = mysqli_errno($this->connection)) {
			throw self::createException(mysqli_error($this->connection), $code, $sql);

		} elseif (is_object($res)) {
			return $this->createResultDriver($res);
		}
		return null;
	}


	public static function createException(string $message, $code, string $sql): Dibi\DriverException
	{
		if (in_array($code, [1216, 1217, 1451, 1452, 1701], true)) {
			return new Dibi\ForeignKeyConstraintViolationException($message, $code, $sql);

		} elseif (in_array($code, [1062, 1557, 1569, 1586], true)) {
			return new Dibi\UniqueConstraintViolationException($message, $code, $sql);

		} elseif (in_array($code, [1048, 1121, 1138, 1171, 1252, 1263, 1566], true)) {
			return new Dibi\NotNullConstraintViolationException($message, $code, $sql);

		} else {
			return new Dibi\DriverException($message, $code, $sql);
		}
	}


	/**
	 * Retrieves information about the most recently executed query.
	 */
	public function getInfo(): array
	{
		$res = [];
		preg_match_all('#(.+?): +(\d+) *#', mysqli_info($this->connection), $matches, PREG_SET_ORDER);
		if (preg_last_error()) {
			throw new Dibi\PcreException;
		}

		foreach ($matches as $m) {
			$res[$m[1]] = (int) $m[2];
		}
		return $res;
	}


	/**
	 * Gets the number of affected rows by the last INSERT, UPDATE or DELETE query.
	 */
	public function getAffectedRows(): ?int
	{
		return mysqli_affected_rows($this->connection) === -1 ? null : mysqli_affected_rows($this->connection);
	}


	/**
	 * Retrieves the ID generated for an AUTO_INCREMENT column by the previous INSERT query.
	 */
	public function getInsertId(?string $sequence): ?int
	{
		return mysqli_insert_id($this->connection);
	}


	/**
	 * Begins a transaction (if supported).
	 * @throws Dibi\DriverException
	 */
	public function begin(string $savepoint = null): void
	{
		$this->query($savepoint ? "SAVEPOINT $savepoint" : 'START TRANSACTION');
	}


	/**
	 * Commits statements in a transaction.
	 * @throws Dibi\DriverException
	 */
	public function commit(string $savepoint = null): void
	{
		$this->query($savepoint ? "RELEASE SAVEPOINT $savepoint" : 'COMMIT');
	}


	/**
	 * Rollback changes in a transaction.
	 * @throws Dibi\DriverException
	 */
	public function rollback(string $savepoint = null): void
	{
		$this->query($savepoint ? "ROLLBACK TO SAVEPOINT $savepoint" : 'ROLLBACK');
	}


	/**
	 * Returns the connection resource.
	 */
	public function getResource(): \mysqli
	{
		return @$this->connection->thread_id ? $this->connection : null;
	}


	/**
	 * Returns the connection reflector.
	 */
	public function getReflector(): Dibi\Reflector
	{
		return new MySqlReflector($this);
	}


	/**
	 * Result set driver factory.
	 */
	public function createResultDriver(\mysqli_result $resource): Dibi\ResultDriver
	{
		$res = clone $this;
		$res->resultSet = $resource;
		return $res;
	}


	/********************* SQL ****************d*g**/


	/**
	 * Encodes data for use in a SQL statement.
	 */
	public function escapeText(string $value): string
	{
		return "'" . mysqli_real_escape_string($this->connection, $value) . "'";
	}


	public function escapeBinary(string $value): string
	{
		return "_binary'" . mysqli_real_escape_string($this->connection, $value) . "'";
	}


	public function escapeIdentifier(string $value): string
	{
		return '`' . str_replace('`', '``', $value) . '`';
	}


	public function escapeBool(bool $value): string
	{
		return $value ? '1' : '0';
	}


	/**
	 * @param  \DateTimeInterface|string|int
	 */
	public function escapeDate($value): string
	{
		if (!$value instanceof \DateTimeInterface) {
			$value = new Dibi\DateTime($value);
		}
		return $value->format("'Y-m-d'");
	}


	/**
	 * @param  \DateTimeInterface|string|int
	 */
	public function escapeDateTime($value): string
	{
		if (!$value instanceof \DateTimeInterface) {
			$value = new Dibi\DateTime($value);
		}
		return $value->format("'Y-m-d H:i:s.u'");
	}


	/**
	 * Encodes string for use in a LIKE statement.
	 */
	public function escapeLike(string $value, int $pos): string
	{
		$value = addcslashes(str_replace('\\', '\\\\', $value), "\x00\n\r\\'%_");
		return ($pos <= 0 ? "'%" : "'") . $value . ($pos >= 0 ? "%'" : "'");
	}


	/**
	 * Decodes data from result set.
	 */
	public function unescapeBinary(string $value): string
	{
		return $value;
	}


	/**
	 * Injects LIMIT/OFFSET to the SQL query.
	 */
	public function applyLimit(string &$sql, ?int $limit, ?int $offset): void
	{
		if ($limit < 0 || $offset < 0) {
			throw new Dibi\NotSupportedException('Negative offset or limit.');

		} elseif ($limit !== null || $offset) {
			// see http://dev.mysql.com/doc/refman/5.0/en/select.html
			$sql .= ' LIMIT ' . ($limit === null ? '18446744073709551615' : $limit)
				. ($offset ? ' OFFSET ' . $offset : '');
		}
	}


	/********************* result set ****************d*g**/


	/**
	 * Automatically frees the resources allocated for this result set.
	 */
	public function __destruct()
	{
		$this->autoFree && $this->getResultResource() && @$this->free();
	}


	/**
	 * Returns the number of rows in a result set.
	 */
	public function getRowCount(): int
	{
		if (!$this->buffered) {
			throw new Dibi\NotSupportedException('Row count is not available for unbuffered queries.');
		}
		return mysqli_num_rows($this->resultSet);
	}


	/**
	 * Fetches the row at current position and moves the internal cursor to the next position.
	 * @param  bool     true for associative array, false for numeric
	 */
	public function fetch(bool $assoc): ?array
	{
		return mysqli_fetch_array($this->resultSet, $assoc ? MYSQLI_ASSOC : MYSQLI_NUM);
	}


	/**
	 * Moves cursor position without fetching row.
	 * @throws Dibi\Exception
	 */
	public function seek(int $row): bool
	{
		if (!$this->buffered) {
			throw new Dibi\NotSupportedException('Cannot seek an unbuffered result set.');
		}
		return mysqli_data_seek($this->resultSet, $row);
	}


	/**
	 * Frees the resources allocated for this result set.
	 */
	public function free(): void
	{
		mysqli_free_result($this->resultSet);
		$this->resultSet = null;
	}


	/**
	 * Returns metadata for all columns in a result set.
	 */
	public function getResultColumns(): array
	{
		static $types;
		if ($types === null) {
			$consts = get_defined_constants(true);
			$types = [];
			foreach ($consts['mysqli'] ?? [] as $key => $value) {
				if (strncmp($key, 'MYSQLI_TYPE_', 12) === 0) {
					$types[$value] = substr($key, 12);
				}
			}
			$types[MYSQLI_TYPE_TINY] = $types[MYSQLI_TYPE_SHORT] = $types[MYSQLI_TYPE_LONG] = 'INT';
		}

		$count = mysqli_num_fields($this->resultSet);
		$columns = [];
		for ($i = 0; $i < $count; $i++) {
			$row = (array) mysqli_fetch_field_direct($this->resultSet, $i);
			$columns[] = [
				'name' => $row['name'],
				'table' => $row['orgtable'],
				'fullname' => $row['table'] ? $row['table'] . '.' . $row['name'] : $row['name'],
				'nativetype' => $types[$row['type']] ?? $row['type'],
				'type' => $row['type'] === MYSQLI_TYPE_TIME ? Dibi\Type::TIME_INTERVAL : null,
				'vendor' => $row,
			];
		}
		return $columns;
	}


	/**
	 * Returns the result set resource.
	 */
	public function getResultResource(): ?\mysqli_result
	{
		$this->autoFree = false;
		return $this->resultSet;
	}
}
