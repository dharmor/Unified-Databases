<?php
/**
 * @file   MssqlDatabase.php
 * @brief  Microsoft SQL Server driver — fully self-contained CRUD.
 *
 * Memory-efficiency notes:
 *  - getTableData() and rawQuery() yield rows one at a time.
 *  - Every statement calls closeCursor() after consumption.
 *
 * @author DB Manager
 * @date   2026
 */

require_once __DIR__ . '/DatabaseInterface.php';

class MssqlDatabase implements DatabaseInterface
{
    /** @var \PDO|null */
    private ?PDO $pdo = null;

    /** @var string */
    private string $lastError = '';

    /** @var string|null */
    private ?string $currentDb = null;

    /*==================================================================*/
    /** @name Construction & Connection
     *  @{ */
    /*==================================================================*/

    /** {@inheritDoc} */
    public function __construct(
        string  $host,
        string  $username,
        string  $password,
        ?string $database = null,
        ?int    $port     = null
    ) {
        $port = $port ?? 1433;
        $dsn  = "sqlsrv:Server={$host},{$port}";
        if ($database !== null) {
            $dsn .= ";Database={$database}";
            $this->currentDb = $database;
        }
        try {
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            throw $e;
        }
    }

    /** {@inheritDoc} */
    public function selectDatabase(string $database): bool
    {
        try {
            $this->pdo->exec("USE " . $this->qi($database));
            $this->currentDb = $database;
            return true;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /** {@inheritDoc} */
    public function disconnect(): void { $this->pdo = null; }

    /** {@inheritDoc} */
    public function getConnection(): ?object { return $this->pdo; }

    /** @} */

    /*==================================================================*/
    /** @name Catalogue
     *  @{ */
    /*==================================================================*/

    /** {@inheritDoc} */
    public function getAllDatabases(): array
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sys.databases WHERE state_desc='ONLINE' ORDER BY name"
        );
        $list = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $stmt->closeCursor();
        return $list;
    }

    /** {@inheritDoc} */
    public function getAllTables(?string $database = null): array
    {
        $this->useDb($database);
        $stmt = $this->pdo->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES "
          . "WHERE TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
        );
        $list = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $stmt->closeCursor();
        return $list;
    }

    /** {@inheritDoc} */
    public function getTableSchema(string $table, ?string $database = null): array
    {
        $this->useDb($database);
        $sql = "
            SELECT
                c.COLUMN_NAME,
                c.DATA_TYPE + CASE
                    WHEN c.CHARACTER_MAXIMUM_LENGTH IS NOT NULL
                         THEN '(' + CAST(c.CHARACTER_MAXIMUM_LENGTH AS VARCHAR) + ')'
                    WHEN c.NUMERIC_PRECISION IS NOT NULL
                         THEN '(' + CAST(c.NUMERIC_PRECISION AS VARCHAR)
                            + ',' + CAST(c.NUMERIC_SCALE AS VARCHAR) + ')'
                    ELSE '' END AS FULL_TYPE,
                c.IS_NULLABLE,
                c.COLUMN_DEFAULT,
                COLUMNPROPERTY(OBJECT_ID(:t1), c.COLUMN_NAME, 'IsIdentity') AS IS_IDENTITY,
                CASE WHEN kcu.COLUMN_NAME IS NOT NULL THEN 'PRI' ELSE '' END AS COL_KEY
            FROM INFORMATION_SCHEMA.COLUMNS c
            LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                ON  kcu.TABLE_NAME  = c.TABLE_NAME
                AND kcu.COLUMN_NAME = c.COLUMN_NAME
                AND kcu.CONSTRAINT_NAME IN (
                    SELECT CONSTRAINT_NAME
                    FROM   INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                    WHERE  TABLE_NAME = c.TABLE_NAME
                    AND    CONSTRAINT_TYPE = 'PRIMARY KEY')
            WHERE c.TABLE_NAME = :t2
            ORDER BY c.ORDINAL_POSITION";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':t1' => $table, ':t2' => $table]);
        $cols = [];
        while ($r = $stmt->fetch()) {
            $cols[] = [
                'name'     => $r['COLUMN_NAME'],
                'type'     => $r['FULL_TYPE'],
                'nullable' => ($r['IS_NULLABLE'] === 'YES'),
                'key'      => $r['COL_KEY'],
                'default'  => $r['COLUMN_DEFAULT'],
                'extra'    => $r['IS_IDENTITY'] ? 'auto_increment' : '',
            ];
        }
        $stmt->closeCursor();
        return $cols;
    }

    /** {@inheritDoc} */
    public function getPrimaryKey(string $table, ?string $database = null): array
    {
        return array_values(array_map(
            fn($c) => $c['name'],
            array_filter($this->getTableSchema($table, $database), fn($c) => $c['key'] === 'PRI')
        ));
    }

    /** @} */

    /*==================================================================*/
    /** @name Data Retrieval
     *  @{ */
    /*==================================================================*/

    /** {@inheritDoc} */
    public function getTableData(
        string  $table,
        ?string $database = null,
        int     $limit    = 100,
        int     $offset   = 0
    ): \Generator {
        $this->useDb($database);
        $sql  = "SELECT * FROM " . $this->qi($table)
              . " ORDER BY (SELECT NULL) OFFSET :off ROWS FETCH NEXT :lim ROWS ONLY";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        while ($row = $stmt->fetch()) { yield $row; }
        $stmt->closeCursor();
    }

    /** {@inheritDoc} */
    public function searchTable(
        string  $table,
        ?string $database = null,
        string  $keyword  = '',
        int     $limit    = 50,
        int     $offset   = 0
    ): \Generator {
        $this->useDb($database);
        $schema = $this->getTableSchema($table, $database);
        $likeParts = [];
        $params = [];
        foreach ($schema as $i => $col) {
            $ph = ':kw' . $i;
            $likeParts[] = "CAST(" . $this->qi($col['name']) . " AS NVARCHAR(MAX)) LIKE $ph";
            $params[$ph] = "%$keyword%";
        }
        if (empty($likeParts)) return;
        $sql = "SELECT * FROM " . $this->qi($table) . " WHERE " . implode(' OR ', $likeParts)
             . " ORDER BY (SELECT NULL) OFFSET :off ROWS FETCH NEXT :lim ROWS ONLY";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        while ($row = $stmt->fetch()) { yield $row; }
        $stmt->closeCursor();
    }

    /** {@inheritDoc} */
    public function countRows(string $table, ?string $database = null): int
    {
        $this->useDb($database);
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM " . $this->qi($table));
        $n = (int) $stmt->fetchColumn();
        $stmt->closeCursor();
        return $n;
    }

    /** @} */

    /*==================================================================*/
    /** @name CRUD
     *  @{ */
    /*==================================================================*/

    /** {@inheritDoc} */
    public function insert(string $table, array $data, ?string $database = null)
    {
        $this->useDb($database);
        $cols   = array_keys($data);
        $colStr = implode(', ', array_map([$this, 'qi'], $cols));
        $phStr  = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        $sql    = "INSERT INTO " . $this->qi($table) . " ({$colStr}) VALUES ({$phStr})";
        $stmt   = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) { $stmt->bindValue(':' . $k, $v === '' ? null : $v); }
        $stmt->execute();
        $id = $this->pdo->lastInsertId();
        $stmt->closeCursor();
        return $id;
    }

    /** {@inheritDoc} */
    public function update(string $table, array $data, array $where, ?string $database = null): int
    {
        $this->useDb($database);
        $sets = []; $conds = [];
        foreach (array_keys($data)  as $c) { $sets[]  = $this->qi($c) . " = :set_{$c}"; }
        foreach (array_keys($where) as $c) { $conds[] = $this->qi($c) . " = :wh_{$c}"; }
        $sql  = "UPDATE " . $this->qi($table) . " SET " . implode(', ', $sets)
              . " WHERE " . implode(' AND ', $conds);
        $stmt = $this->pdo->prepare($sql);
        foreach ($data  as $k => $v) { $stmt->bindValue(':set_' . $k, $v === '' ? null : $v); }
        foreach ($where as $k => $v) { $stmt->bindValue(':wh_'  . $k, $v); }
        $stmt->execute();
        $n = $stmt->rowCount();
        $stmt->closeCursor();
        return $n;
    }

    /** {@inheritDoc} */
    public function delete(string $table, array $where, ?string $database = null): int
    {
        $this->useDb($database);
        $conds = [];
        foreach (array_keys($where) as $c) { $conds[] = $this->qi($c) . " = :wh_{$c}"; }
        $sql  = "DELETE FROM " . $this->qi($table) . " WHERE " . implode(' AND ', $conds);
        $stmt = $this->pdo->prepare($sql);
        foreach ($where as $k => $v) { $stmt->bindValue(':wh_' . $k, $v); }
        $stmt->execute();
        $n = $stmt->rowCount();
        $stmt->closeCursor();
        return $n;
    }

    /** {@inheritDoc} */
    public function rawQuery(string $sql, array $params = []): \Generator
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) { yield $row; }
        $stmt->closeCursor();
    }

    /** @} */

    /*==================================================================*/
    /** @name Utility
     *  @{ */
    /*==================================================================*/

    /** {@inheritDoc} */
    public function getLastError(): string { return $this->lastError; }

    /**
     * @brief Switch to the given database if needed.
     * @param string|null $database Target (null = no-op).
     */
    private function useDb(?string $database): void
    {
        if ($database !== null && $database !== $this->currentDb) {
            $this->selectDatabase($database);
        }
    }

    /**
     * @brief  Quote an identifier with square brackets (T-SQL style).
     * @param  string $id Raw identifier.
     * @return string
     */
    private function qi(string $id): string
    {
        return '[' . str_replace(']', ']]', $id) . ']';
    }

    /** @} */
}
