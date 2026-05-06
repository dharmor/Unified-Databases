<?php
/**
 * @file   DatabaseFactory.php
 * @brief  Factory — instantiates the correct driver from a type string.
 *
 * @code
 *   $db = DatabaseFactory::create('mysql', '127.0.0.1', 'root', '', 'mydb');
 * @endcode
 *
 * @author DB Manager
 * @date   2026
 */

require_once __DIR__ . '/MysqlDatabase.php';
require_once __DIR__ . '/MssqlDatabase.php';
require_once __DIR__ . '/PostgresDatabase.php';
require_once __DIR__ . '/FirebirdDatabase.php';
require_once __DIR__ . '/SqliteDatabase.php';

class DatabaseFactory
{
    /** @var array<string,string> Human-readable labels. */
    public const DRIVERS = [
        'mysql'    => 'MySQL',
        'mssql'    => 'Microsoft SQL Server',
        'postgres' => 'PostgreSQL',
        'firebird' => 'Firebird',
        'sqlite'   => 'SQLite',
    ];

    /**
     * @brief  Create a DatabaseInterface for the given type.
     *
     * @param  string      $type     Key from DRIVERS.
     * @param  string      $host     Hostname or file path.
     * @param  string      $username Database user.
     * @param  string      $password Database password.
     * @param  string|null $database Optional default database.
     * @param  int|null    $port     Optional port.
     * @return DatabaseInterface
     * @throws \InvalidArgumentException If $type is unsupported.
     */
    public static function create(
        string  $type,
        string  $host,
        string  $username = '',
        string  $password = '',
        ?string $database = null,
        ?int    $port     = null
    ): DatabaseInterface {
        return match ($type) {
            'mysql'    => new MysqlDatabase($host, $username, $password, $database, $port),
            'mssql'    => new MssqlDatabase($host, $username, $password, $database, $port),
            'postgres' => new PostgresDatabase($host, $username, $password, $database, $port),
            'firebird' => new FirebirdDatabase($host, $username, $password, $database, $port),
            'sqlite'   => new SqliteDatabase($host, $username, $password, $database, $port),
            default    => throw new \InvalidArgumentException("Unsupported type: {$type}"),
        };
    }
}
