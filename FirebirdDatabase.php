<?php
/**
 * @file   FirebirdDatabase.php
 * @brief  Firebird driver — fully self-contained CRUD.
 *
 * Memory-efficiency notes:
 *  - getTableData() and rawQuery() yield rows one at a time.
 *  - Every statement calls closeCursor() after consumption.
 *
 * @note   Firebird is single-database-per-connection.
 *         getAllDatabases() returns only the current alias.
 *
 * @author DB Manager
 * @date   2026
 */

require_once __DIR__ . '/DatabaseInterface.php';

class FirebirdDatabase implements DatabaseInterface
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
        $port   = $port ?? 3050;
        $dbPath = $database ?? '';
        $dsn    = "firebird:dbname={$host}/{$port}:{$dbPath};charset=UTF8";
        $this->currentDb = $database;

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
        $this->currentDb = $database;
        return true;
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
        return $this->currentDb ? [$this->currentDb] : [];
    }

    /** {@inheritDoc} */
    public function getAllTables(?string $database = null): array
    {
        $sql  = "SELECT RDB\$RELATION_NAME FROM RDB\$RELATIONS "
              . "WHERE RDB\$SYSTEM_FLAG=0 AND RDB\$VIEW_BLR IS NULL "
              . "ORDER BY RDB\$RELATION_NAME";
        $stmt = $this->pdo->query($sql);
        $list = [];
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) { $list[] = trim($r[0]); }
        $stmt->closeCursor();
        return $list;
    }

    /** {@inheritDoc} */
    public function getTableSchema(string $table, ?string $database = null): array
    {
        $sql = "
            SELECT
                rf.RDB\$FIELD_NAME     AS FNAME,
                f.RDB\$FIELD_TYPE      AS FTYPE,
                f.RDB\$FIELD_LENGTH    AS FLEN,
                rf.RDB\$NULL_FLAG      AS NFLAG,
                rf.RDB\$DEFAULT_SOURCE AS FDEFAULT
            FROM RDB\$RELATION_FIELDS rf
            JOIN RDB\$FIELDS f ON f.RDB\$FIELD_NAME = rf.RDB\$FIELD_SOURCE
            WHERE rf.RDB\$RELATION_NAME = :tbl
            ORDER BY rf.RDB\$FIELD_POSITION";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tbl' => strtoupper(trim($table))]);

        /** @var array<int,string> Firebird numeric type-code map. */
        $tm = [
            7=>'SMALLINT',8=>'INTEGER',10=>'FLOAT',12=>'DATE',13=>'TIME',
            14=>'CHAR',16=>'BIGINT',27=>'DOUBLE',35=>'TIMESTAMP',
            37=>'VARCHAR',261=>'BLOB',
        ];
        $pkCols = $this->getPrimaryKey($table, $database);

        $cols = [];
        while ($r = $stmt->fetch()) {
            $name = trim($r['FNAME']);
            $t    = (int) $r['FTYPE'];
            $cols[] = [
                'name'     => $name,
                'type'     => ($tm[$t] ?? "TYPE_{$t}") . '(' . $r['FLEN'] . ')',
                'nullable' => ($r['NFLAG'] !== 1),
                'key'      => in_array($name, $pkCols, true) ? 'PRI' : '',
                'default'  => $r['FDEFAULT'] ? trim($r['FDEFAULT']) : null,
                'extra'    => '',
            ];
        }
        $stmt->closeCursor();
        return $cols;
    }

    /** {@inheritDoc} */
    public function getPrimaryKey(string $table, ?string $database = null): array
    {
        $sql = "
            SELECT isg.RDB\$FIELD_NAME
            FROM RDB\$RELATION_CONSTRAINTS rc
            JOIN RDB\$INDEX_SEGMENTS isg ON isg.RDB\$INDEX_NAME = rc.RDB\$INDEX_NAME
            WHERE rc.RDB\$RELATION_NAME = :tbl
              AND rc.RDB\$CONSTRAINT_TYPE = 'PRIMARY KEY'
            ORDER BY isg.RDB\$FIELD_POSITION";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tbl' => strtoupper(trim($table))]);
        $pks = [];
        while ($r = $stmt->fetch()) { $pks[] = trim($r['RDB$FIELD_NAME']); }
        $stmt->closeCursor();
        return $pks;
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
        $tbl  = $this->qi(strtoupper(trim($table)));
        $sql  = "SELECT FIRST {$limit} SKIP {$offset} * FROM {$tbl}";
        $stmt = $this->pdo->query($sql);
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
            $likeParts[] = $this->qi(trim($col['name'])) . " CONTAINING $ph";
            $params[$ph] = $keyword;
        }
        if (empty($likeParts)) return;
        $sql = "SELECT * FROM " . $this->qi($table) . " WHERE " . implode(' OR ', $likeParts)
             . " OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->execute();
        while ($row = $stmt->fetch()) { yield $row; }
        $stmt->closeCursor();
    }

    /** {@inheritDoc} */
    public function countRows(string $table, ?string $database = null): int
    {
        $tbl  = $this->qi(strtoupper(trim($table)));
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$tbl}");
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
        $tbl    = $this->qi(strtoupper(trim($table)));
        $cols   = array_keys($data);
        $colStr = implode(', ', array_map([$this, 'qi'], $cols));
        $phStr  = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        $sql    = "INSERT INTO {$tbl} ({$colStr}) VALUES ({$phStr})";
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
        $tbl = $this->qi(strtoupper(trim($table)));
        $sets = []; $conds = [];
        foreach (array_keys($data)  as $c) { $sets[]  = $this->qi($c) . " = :set_{$c}"; }
        foreach (array_keys($where) as $c) { $conds[] = $this->qi($c) . " = :wh_{$c}"; }
        $sql  = "UPDATE {$tbl} SET " . implode(', ', $sets) . " WHERE " . implode(' AND ', $conds);
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
        $tbl   = $this->qi(strtoupper(trim($table)));
        $conds = [];
        foreach (array_keys($where) as $c) { $conds[] = $this->qi($c) . " = :wh_{$c}"; }
        $sql  = "DELETE FROM {$tbl} WHERE " . implode(' AND ', $conds);
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

    /** @brief Quote identifier with double-quotes (Firebird uppercase). */
    private function qi(string $id): string
    {
        return '"' . str_replace('"', '""', strtoupper(trim($id))) . '"';
    }

    /** @} */
}
