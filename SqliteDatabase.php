<?php
/**
 * @file   SqliteDatabase.php
 * @brief  SQLite driver — fully self-contained CRUD.
 *
 * The @p $host parameter is treated as the file path to the .db file.
 * Username / password are ignored (SQLite has no authentication).
 *
 * Memory-efficiency notes:
 *  - getTableData() and rawQuery() yield rows one at a time.
 *  - Every statement calls closeCursor() after consumption.
 *
 * @author DB Manager
 * @date   2026
 */

require_once __DIR__ . '/DatabaseInterface.php';

class SqliteDatabase implements DatabaseInterface
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

    /** @var string Full filesystem path to the .db file. */
    private string $filePath = '';

    /** {@inheritDoc} */
    public function __construct(
        string  $host,
        string  $username = '',
        string  $password = '',
        ?string $database = null,
        ?int    $port     = null
    ) {
        // $host is always the full file path entered on the login form.
        // $database may be just a basename from getAllDatabases() — ignore
        // it unless it looks like an absolute path.
        if ($database !== null && $database !== '' && $this->isAbsolutePath($database)) {
            $file = $database;
        } else {
            $file = $host;
        }

        $this->filePath  = $file;
        $this->currentDb = basename($file);

        try {
            $this->pdo = new PDO("sqlite:{$file}", null, null, [
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
     * @brief  Check if a path is absolute (Windows or Unix).
     * @param  string $path
     * @return bool
     */
    private function isAbsolutePath(string $path): bool
    {
        // Windows: C:\ or C:/ or \\server
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) return true;
        if (str_starts_with($path, '\\\\'))             return true;
        // Unix: /
        if (str_starts_with($path, '/'))                return true;
        return false;
    }

    /**
     * {@inheritDoc}
     * @note Opens a new SQLite file (one file = one database).
     */
    public function selectDatabase(string $database): bool
    {
        try {
            $this->pdo = new PDO("sqlite:{$database}", null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $this->filePath  = $database;
            $this->currentDb = basename($database);
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
        // Return the full file path so getDbConnection() can reopen it.
        return $this->filePath ? [$this->filePath] : [];
    }

    /** {@inheritDoc} */
    public function getAllTables(?string $database = null): array
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master "
          . "WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );
        $list = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $stmt->closeCursor();
        return $list;
    }

    /** {@inheritDoc} */
    public function getTableSchema(string $table, ?string $database = null): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info(" . $this->qi($table) . ")");
        $cols = [];
        while ($r = $stmt->fetch()) {
            $cols[] = [
                'name'     => $r['name'],
                'type'     => $r['type'] ?: 'TEXT',
                'nullable' => ($r['notnull'] == 0),
                'key'      => $r['pk'] ? 'PRI' : '',
                'default'  => $r['dflt_value'],
                'extra'    => '',
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

    /** @brief Quote identifier with double-quotes (ANSI SQL). */
    private function qi(string $id): string
    {
        return '"' . str_replace('"', '""', $id) . '"';
    }

    /** @} */
}
