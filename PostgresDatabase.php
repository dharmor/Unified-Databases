<?php
/**
 * @file   PostgresDatabase.php
 * @brief  PostgreSQL driver — fully self-contained CRUD.
 *
 * Memory-efficiency notes:
 *  - getTableData() and rawQuery() yield rows one at a time.
 *  - Every statement calls closeCursor() after consumption.
 *
 * @note   PostgreSQL requires a new PDO connection to switch databases.
 *         selectDatabase() reconnects transparently using stored credentials.
 *
 * @author DB Manager
 * @date   2026
 */

require_once __DIR__ . '/DatabaseInterface.php';

class PostgresDatabase implements DatabaseInterface
{
    /** @var \PDO|null */
    private ?PDO $pdo = null;

    /** @var string */
    private string $lastError = '';

    /** @var string|null */
    private ?string $currentDb = null;

    /** @var string Stored for reconnection. */
    private string $sHost;
    /** @var int */
    private int $sPort;
    /** @var string */
    private string $sUser;
    /** @var string */
    private string $sPass;

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
        $this->sHost = $host;
        $this->sPort = $port ?? 5432;
        $this->sUser = $username;
        $this->sPass = $password;

        $db  = $database ?? 'postgres';
        $dsn = "pgsql:host={$host};port={$this->sPort};dbname={$db}";
        $this->currentDb = $db;

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

    /**
     * {@inheritDoc}
     * @note Creates a brand-new PDO connection (PostgreSQL limitation).
     */
    public function selectDatabase(string $database): bool
    {
        try {
            $dsn = "pgsql:host={$this->sHost};port={$this->sPort};dbname={$database}";
            $this->pdo = new PDO($dsn, $this->sUser, $this->sPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
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
            "SELECT datname FROM pg_database WHERE datistemplate=false ORDER BY datname"
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
            "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"
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
                c.column_name,
                c.data_type ||
                    CASE WHEN c.character_maximum_length IS NOT NULL
                         THEN '(' || c.character_maximum_length || ')'
                         ELSE '' END AS full_type,
                c.is_nullable,
                c.column_default,
                CASE WHEN tc.constraint_type = 'PRIMARY KEY' THEN 'PRI' ELSE '' END AS col_key
            FROM information_schema.columns c
            LEFT JOIN information_schema.key_column_usage kcu
                ON  kcu.table_name  = c.table_name
                AND kcu.column_name = c.column_name
                AND kcu.table_schema = 'public'
            LEFT JOIN information_schema.table_constraints tc
                ON  tc.constraint_name = kcu.constraint_name
                AND tc.table_schema    = 'public'
                AND tc.constraint_type = 'PRIMARY KEY'
            WHERE c.table_name = :tbl AND c.table_schema = 'public'
            ORDER BY c.ordinal_position";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tbl' => $table]);
        $cols = [];
        while ($r = $stmt->fetch()) {
            $extra = '';
            if ($r['column_default'] !== null && strpos($r['column_default'], 'nextval') !== false) {
                $extra = 'auto_increment';
            }
            $cols[] = [
                'name'     => $r['column_name'],
                'type'     => $r['full_type'],
                'nullable' => ($r['is_nullable'] === 'YES'),
                'key'      => $r['col_key'],
                'default'  => $r['column_default'],
                'extra'    => $extra,
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
        $sql  = "SELECT * FROM " . $this->qi($table) . " LIMIT :lim OFFSET :off";
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
            $likeParts[] = $this->qi($col['name']) . "::TEXT ILIKE $ph";
            $params[$ph] = "%$keyword%";
        }
        if (empty($likeParts)) return;
        $sql = "SELECT * FROM " . $this->qi($table) . " WHERE " . implode(' OR ', $likeParts)
             . " LIMIT :lim OFFSET :off";
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

    /** @brief Switch DB if needed. */
    private function useDb(?string $database): void
    {
        if ($database !== null && $database !== $this->currentDb) {
            $this->selectDatabase($database);
        }
    }

    /** @brief Quote identifier with double-quotes (ANSI SQL). */
    private function qi(string $id): string
    {
        return '"' . str_replace('"', '""', $id) . '"';
    }

    /** @} */
}
