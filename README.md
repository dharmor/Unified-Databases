# Unified-Databases

[![Website](https://img.shields.io/badge/Website-www.daves--corner.com-0b5cad?style=for-the-badge)](https://www.daves-corner.com)

If there is a feature that could be improved or added, or even just a comment, contact me at [dave@daves-corner.com](mailto:dave@daves-corner.com).

Unified-Databases is a small PHP database abstraction library that provides one common interface for working with multiple database engines. It is designed for tools that need to browse database servers, inspect schemas, read table data, search records, and perform simple CRUD operations without rewriting the same logic for every database type.

## What It Does

Unified-Databases wraps PDO-based database access behind a shared `DatabaseInterface`. Each database driver implements the same set of methods, so an application can switch between supported database engines while keeping the calling code mostly unchanged.

Typical uses include:

- Build database browser or admin tools.
- List available databases and tables.
- Inspect table columns and primary keys.
- Read table rows with memory-efficient generators.
- Search text columns for a keyword.
- Count rows in a table.
- Insert, update, and delete records.
- Run raw parameterized SQL queries.
- Support multiple database engines from one code path.

## Supported Databases

The included factory supports:

- MySQL and MariaDB
- Microsoft SQL Server
- PostgreSQL
- Firebird
- SQLite

## Files

```text
DatabaseInterface.php
DatabaseFactory.php
MysqlDatabase.php
MssqlDatabase.php
PostgresDatabase.php
FirebirdDatabase.php
SqliteDatabase.php
```

## Requirements

- PHP 8.1 or newer
- PDO
- The PDO extension for the database engine you want to use

Common extensions include:

- `pdo_mysql` for MySQL and MariaDB
- `pdo_sqlsrv` for Microsoft SQL Server
- `pdo_pgsql` for PostgreSQL
- `pdo_firebird` for Firebird
- `pdo_sqlite` for SQLite

## Quick Start

Include the factory and create a database connection:

```php
<?php

require_once __DIR__ . '/DatabaseFactory.php';

$db = DatabaseFactory::create(
    type: 'mysql',
    host: '127.0.0.1',
    username: 'root',
    password: '',
    database: 'example'
);

foreach ($db->getAllTables() as $table) {
    echo $table . PHP_EOL;
}
```

## Factory Types

Use one of these type strings with `DatabaseFactory::create()`:

```php
$mysql = DatabaseFactory::create('mysql', '127.0.0.1', 'root', '', 'app');
$mssql = DatabaseFactory::create('mssql', '127.0.0.1', 'sa', 'password', 'app');
$pgsql = DatabaseFactory::create('postgres', '127.0.0.1', 'postgres', 'password', 'app');
$fdb   = DatabaseFactory::create('firebird', '/path/to/app.fdb', 'sysdba', 'masterkey');
$lite  = DatabaseFactory::create('sqlite', '/path/to/app.sqlite');
```

## Common Operations

List databases:

```php
$databases = $db->getAllDatabases();
```

List tables:

```php
$tables = $db->getAllTables('example');
```

Inspect a table:

```php
$schema = $db->getTableSchema('users');
$primaryKey = $db->getPrimaryKey('users');
```

Read table rows:

```php
foreach ($db->getTableData('users', limit: 100, offset: 0) as $row) {
    print_r($row);
}
```

Search a table:

```php
foreach ($db->searchTable('users', keyword: 'admin') as $row) {
    print_r($row);
}
```

Insert a row:

```php
$id = $db->insert('users', [
    'name' => 'Jane Admin',
    'email' => 'jane@example.com',
]);
```

Update rows:

```php
$affected = $db->update(
    table: 'users',
    data: ['name' => 'Jane Manager'],
    where: ['id' => $id]
);
```

Delete rows:

```php
$deleted = $db->delete('users', ['id' => $id]);
```

Run raw SQL:

```php
foreach ($db->rawQuery('SELECT * FROM users WHERE email = ?', ['jane@example.com']) as $row) {
    print_r($row);
}
```

## Interface Summary

Every driver implements:

```php
selectDatabase(string $database): bool
disconnect(): void
getConnection(): ?object
getAllDatabases(): array
getAllTables(?string $database = null): array
getTableSchema(string $table, ?string $database = null): array
getPrimaryKey(string $table, ?string $database = null): array
getTableData(string $table, ?string $database = null, int $limit = 100, int $offset = 0): Generator
searchTable(string $table, ?string $database = null, string $keyword = '', int $limit = 50, int $offset = 0): Generator
countRows(string $table, ?string $database = null): int
insert(string $table, array $data, ?string $database = null)
update(string $table, array $data, array $where, ?string $database = null): int
delete(string $table, array $where, ?string $database = null): int
rawQuery(string $sql, array $params = []): Generator
getLastError(): string
```

## Notes

- `getTableData()` and `rawQuery()` return generators so large result sets can be streamed one row at a time.
- PostgreSQL database switching requires a new PDO connection, so the PostgreSQL driver reconnects when `selectDatabase()` is called.
- SQLite uses the host parameter as the database file path.
- Raw queries should use placeholders and bound parameters instead of string-concatenated values.

## Version

1.0.0 Initial release
