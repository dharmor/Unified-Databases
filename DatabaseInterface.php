<?php
/**
 * @file   DatabaseInterface.php
 * @brief  Common contract for every database driver.
 *
 * All catalogue, data-retrieval, and CRUD methods accept an explicit
 * @p $database parameter so callers can target any database on the
 * server without switching connection state.  When @p $database is
 * @c null the driver uses its currently-selected database.
 *
 * @author DB Manager
 * @date   2026
 */

interface DatabaseInterface
{
    /*==================================================================*/
    /** @defgroup conn Construction & Connection
     *  @{ */
    /*==================================================================*/

    /**
     * @brief  Open a connection to the database server.
     *
     * @param  string      $host     Hostname, IP, or file path (SQLite).
     * @param  string      $username Login user  (ignored by SQLite).
     * @param  string      $password Login pass  (ignored by SQLite).
     * @param  string|null $database Optional default database / schema.
     * @param  int|null    $port     Optional port (driver default if null).
     *
     * @throws \PDOException On connection failure.
     */
    public function __construct(
        string  $host,
        string  $username,
        string  $password,
        ?string $database = null,
        ?int    $port     = null
    );

    /**
     * @brief  Select / switch the active database.
     *
     * @param  string $database Database name or file path.
     * @return bool   TRUE on success.
     */
    public function selectDatabase(string $database): bool;

    /**
     * @brief  Close the connection and free resources.
     * @return void
     */
    public function disconnect(): void;

    /**
     * @brief  Return the underlying PDO object.
     * @return \PDO|null
     */
    public function getConnection(): ?object;

    /** @} */

    /*==================================================================*/
    /** @defgroup cat Catalogue / Metadata
     *  @{ */
    /*==================================================================*/

    /**
     * @brief  List every database on the server.
     * @return string[]
     */
    public function getAllDatabases(): array;

    /**
     * @brief  List every user table in a database.
     *
     * @param  string|null $database Database to query (null = current).
     * @return string[]
     */
    public function getAllTables(?string $database = null): array;

    /**
     * @brief  Column schema for a table.
     *
     * Each element: @c ['name','type','nullable','key','default','extra']
     *
     * @param  string      $table    Table name.
     * @param  string|null $database Database to query (null = current).
     * @return array<int, array<string, mixed>>
     */
    public function getTableSchema(string $table, ?string $database = null): array;

    /**
     * @brief  Primary-key column(s) for a table.
     *
     * @param  string      $table    Table name.
     * @param  string|null $database Database to query (null = current).
     * @return string[]
     */
    public function getPrimaryKey(string $table, ?string $database = null): array;

    /** @} */

    /*==================================================================*/
    /** @defgroup data Data Retrieval
     *  @{ */
    /*==================================================================*/

    /**
     * @brief  Fetch rows via a memory-efficient Generator.
     *
     * Yields one associative-array row at a time, keeping memory
     * constant regardless of result-set size.
     *
     * @param  string      $table    Table name.
     * @param  string|null $database Database (null = current).
     * @param  int         $limit    Max rows (default 100).
     * @param  int         $offset   Starting offset (default 0).
     * @return \Generator<int, array<string, mixed>>
     */
    public function getTableData(
        string  $table,
        ?string $database = null,
        int     $limit    = 100,
        int     $offset   = 0
    ): \Generator;

    /**
     * @brief  Search all text columns in a table for a keyword.
     *
     * @param  string      $table    Table name.
     * @param  string|null $database Database (null = current).
     * @param  string      $keyword  Search term.
     * @param  int         $limit    Max rows.
     * @param  int         $offset   Starting offset.
     * @return \Generator<int, array<string, mixed>>
     */
    public function searchTable(
        string  $table,
        ?string $database = null,
        string  $keyword  = '',
        int     $limit    = 50,
        int     $offset   = 0
    ): \Generator;

    /**
     * @brief  Count rows in a table.
     *
     * @param  string      $table    Table name.
     * @param  string|null $database Database (null = current).
     * @return int
     */
    public function countRows(string $table, ?string $database = null): int;

    /** @} */

    /*==================================================================*/
    /** @defgroup crud CRUD Operations
     *  @{ */
    /*==================================================================*/

    /**
     * @brief  Insert a new row.
     *
     * @param  string               $table    Table name.
     * @param  array<string, mixed> $data     Column => value pairs.
     * @param  string|null          $database Database (null = current).
     * @return int|string  Last-insert ID.
     */
    public function insert(string $table, array $data, ?string $database = null);

    /**
     * @brief  Update rows matching WHERE.
     *
     * @param  string               $table    Table name.
     * @param  array<string, mixed> $data     Column => new value.
     * @param  array<string, mixed> $where    Column => value (AND-ed).
     * @param  string|null          $database Database (null = current).
     * @return int  Affected rows.
     */
    public function update(string $table, array $data, array $where, ?string $database = null): int;

    /**
     * @brief  Delete rows matching WHERE.
     *
     * @param  string               $table    Table name.
     * @param  array<string, mixed> $where    Column => value (AND-ed).
     * @param  string|null          $database Database (null = current).
     * @return int  Affected rows.
     */
    public function delete(string $table, array $where, ?string $database = null): int;

    /**
     * @brief  Run a raw SQL query, yielding rows via Generator.
     *
     * @param  string $sql    SQL (use ? placeholders).
     * @param  array  $params Positional bind values.
     * @return \Generator<int, array<string, mixed>>
     */
    public function rawQuery(string $sql, array $params = []): \Generator;

    /** @} */

    /*==================================================================*/
    /** @defgroup util Utility
     *  @{ */
    /*==================================================================*/

    /**
     * @brief  Last error message, or '' if none.
     * @return string
     */
    public function getLastError(): string;

    /** @} */
}
