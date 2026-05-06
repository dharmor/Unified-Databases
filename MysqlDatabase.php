<?php
/**
 * @file   MysqlDatabase.php
 * @brief  MySQL / MariaDB driver — fully self-contained CRUD.
 *
 * Memory-efficiency notes:
 *  - Unbuffered queries via PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false.
 *  - getTableData() and rawQuery() yield rows one at a time.
 *  - Every statement calls closeCursor() after consumption.
 *
 * @author DB Manager
 * @date   2026
 */

require_once __DIR__ . '/DatabaseInterface.php';

class MysqlDatabase implements DatabaseInterface
{
    /** @var \PDO|null Active connection. */
    private ?PDO $pdo = null;

    /** @var string Last error text. */
    private string $lastError = '';

    /** @var string|null Currently selected database. */
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
        $port = $port ?? 3306;
        $dsn  = "mysql:host={$host};port={$port};charset=utf8mb4";
        if ($database !== null) {
            $dsn .= ";dbname={$database}";
            $this->currentDb = $database;
        }
        try {
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => false,
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
        $stmt = $this->pdo->query("SHOW DATABASES");
        $list = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $stmt->closeCursor();
        return $list;
    }

    /** {@inheritDoc} */
    public function getAllTables(?string $database = null): array
    {
        $db = $database ?? $this->currentDb;
        if ($db !== null && $db !== $this->currentDb) {
            $this->selectDatabase($db);
        }
        $stmt = $this->pdo->query("SHOW TABLES");
        $list = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $stmt->closeCursor();
        return $list;
    }

    /** {@inheritDoc} */
    public function getTableSchema(string $table, ?string $database = null): array
    {
        $this->useDb($database);
        $stmt = $this->pdo->query("DESCRIBE " . $this->qi($table));
        $cols = [];
        while ($r = $stmt->fetch()) {
            $cols[] = [
                'name'     => $r['Field'],
                'type'     => $r['Type'],
                'nullable' => ($r['Null'] === 'YES'),
                'key'      => $r['Key'],
                'default'  => $r['Default'],
                'extra'    => $r['Extra'],
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
            $likeParts[] = $this->qi($col['name']) . " LIKE $ph";
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
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v === '' ? null : $v);
        }
        $stmt->execute();
        $id = $this->pdo->lastInsertId();
        $stmt->closeCursor();
        return $id;
    }

    /** {@inheritDoc} */
    public function update(string $table, array $data, array $where, ?string $database = null): int
    {
        $this->useDb($database);
        $sets  = [];
        foreach (array_keys($data) as $c)  { $sets[]  = $this->qi($c) . " = :set_{$c}"; }
        $conds = [];
        foreach (array_keys($where) as $c) { $conds[] = $this->qi($c) . " = :wh_{$c}"; }

        $sql  = "UPDATE " . $this->qi($table)
              . " SET "   . implode(', ', $sets)
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
     * @brief  Switch to the given database if it differs from current.
     * @param  string|null $database Target database (null = no-op).
     */
    private function useDb(?string $database): void
    {
        if ($database !== null && $database !== $this->currentDb) {
            $this->selectDatabase($database);
        }
    }

    /**
     * @brief  Quote an identifier with backticks (MySQL style).
     * @param  string $id Raw identifier.
     * @return string Quoted identifier.
     */
    private function qi(string $id): string
    {
        return '`' . str_replace('`', '``', $id) . '`';
    }

    /** @} */
}
