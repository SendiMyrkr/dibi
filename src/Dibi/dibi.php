<?php

/**
 * This file is part of the "dibi" - smart database abstraction layer.
 * Copyright (c) 2005 David Grudl (https://davidgrudl.com)
 */

use Dibi\Type;


/**
 * This class is static container class for creating DB objects and
 * store connections info.
 *
 * @method void disconnect()
 * @method Dibi\Result|int query(...$args)
 * @method Dibi\Result|int nativeQuery(...$args)
 * @method bool test(...$args)
 * @method Dibi\DataSource dataSource(...$args)
 * @method Dibi\Row|bool fetch(...$args)
 * @method array fetchAll(...$args)
 * @method mixed fetchSingle(...$args)
 * @method array fetchPairs(...$args)
 * @method int getAffectedRows()
 * @method int getInsertId(string $sequence = null)
 * @method void begin(string $savepoint = null)
 * @method void commit(string $savepoint = null)
 * @method void rollback(string $savepoint = null)
 * @method Dibi\Reflection\Database getDatabaseInfo()
 * @method Dibi\Fluent command()
 * @method Dibi\Fluent select(...$args)
 * @method Dibi\Fluent update(string $table, array $args)
 * @method Dibi\Fluent insert(string $table, array $args)
 * @method Dibi\Fluent delete(string $table)
 * @method Dibi\HashMap getSubstitutes()
 * @method int loadFile(string $file)
 */
class dibi
{
	use Dibi\Strict;

	const
		AFFECTED_ROWS = 'a',
		IDENTIFIER = 'n';

	/** version */
	const
		VERSION = '3.2.0',
		REVISION = 'released on 2018-03-09';

	/** sorting order */
	const
		ASC = 'ASC',
		DESC = 'DESC';

	/** @deprecated */
	const
		TEXT = Type::TEXT,
		BINARY = Type::BINARY,
		BOOL = Type::BOOL,
		INTEGER = Type::INTEGER,
		FLOAT = Type::FLOAT,
		DATE = Type::DATE,
		DATETIME = Type::DATETIME,
		TIME = Type::TIME,
		FIELD_TEXT = Type::TEXT,
		FIELD_BINARY = Type::BINARY,
		FIELD_BOOL = Type::BOOL,
		FIELD_INTEGER = Type::INTEGER,
		FIELD_FLOAT = Type::FLOAT,
		FIELD_DATE = Type::DATE,
		FIELD_DATETIME = Type::DATETIME,
		FIELD_TIME = Type::TIME;

	/** @var string  Last SQL command @see dibi::query() */
	public static $sql;

	/** @var int  Elapsed time for last query */
	public static $elapsedTime;

	/** @var int  Elapsed time for all queries */
	public static $totalTime;

	/** @var int  Number or queries */
	public static $numOfQueries = 0;

	/** @var string  Default dibi driver */
	public static $defaultDriver = 'mysqli';

	/** @var Dibi\Connection[]  Connection registry storage for Dibi\Connection objects */
	private static $registry = [];

	/** @var Dibi\Connection  Current connection */
	private static $connection;


	/**
	 * Static class - cannot be instantiated.
	 */
	final public function __construct()
	{
		throw new LogicException('Cannot instantiate static class ' . get_class($this));
	}


	/********************* connections handling ****************d*g**/


	/**
	 * Creates a new Connection object and connects it to specified database.
	 * @param  mixed   connection parameters
	 * @param  string  connection name
	 * @return Dibi\Connection
	 * @throws Dibi\Exception
	 */
	public static function connect($config = [], $name = '0')
	{
		return self::$connection = self::$registry[$name] = new Dibi\Connection($config, $name);
	}


	/**
	 * Returns true when connection was established.
	 * @return bool
	 */
	public static function isConnected()
	{
		return (self::$connection !== null) && self::$connection->isConnected();
	}


	/**
	 * Retrieve active connection.
	 * @param  string   connection registy name
	 * @return Dibi\Connection
	 * @throws Dibi\Exception
	 */
	public static function getConnection($name = null)
	{
		if ($name === null) {
			if (self::$connection === null) {
				throw new Dibi\Exception('Dibi is not connected to database.');
			}

			return self::$connection;
		}

		if (!isset(self::$registry[$name])) {
			throw new Dibi\Exception("There is no connection named '$name'.");
		}

		return self::$registry[$name];
	}


	/**
	 * Sets connection.
	 * @param  Dibi\Connection
	 * @return Dibi\Connection
	 */
	public static function setConnection(Dibi\Connection $connection)
	{
		return self::$connection = $connection;
	}


	/**
	 * @deprecated
	 */
	public static function activate($name)
	{
		trigger_error(__METHOD__ . '() is deprecated.', E_USER_DEPRECATED);
		self::$connection = self::getConnection($name);
	}


	/********************* monostate for active connection ****************d*g**/


	/**
	 * Monostate for Dibi\Connection.
	 */
	public static function __callStatic($name, $args)
	{
		return call_user_func_array([self::getConnection(), $name], $args);
	}


	/**
	 * @deprecated
	 */
	public static function affectedRows()
	{
		trigger_error(__METHOD__ . '() is deprecated, use getAffectedRows()', E_USER_DEPRECATED);
		return self::getConnection()->getAffectedRows();
	}


	/**
	 * @deprecated
	 */
	public static function insertId($sequence = null)
	{
		trigger_error(__METHOD__ . '() is deprecated, use getInsertId()', E_USER_DEPRECATED);
		return self::getConnection()->getInsertId($sequence);
	}


	/********************* misc tools ****************d*g**/


	/**
	 * Prints out a syntax highlighted version of the SQL command or Result.
	 * @param  string|Result
	 * @param  bool  return output instead of printing it?
	 * @return string
	 */
	public static function dump($sql = null, $return = false)
	{
		return Dibi\Helpers::dump($sql, $return);
	}
}
