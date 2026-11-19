<?php

declare(strict_types=1);

if (!class_exists('TableMap', false)) {
    require_once __DIR__ . '/TableMap.php';
}

/**
 * SQL-backed store (local SQLite by default; SQL_DSN for other engines).
 */
final class SqlStore
{
    private PDO $pdo;

    /** @param array<string, mixed> $config */
    public function __construct(string $root, array $config)
    {
        $dsn = (string) ($config['sql_dsn'] ?? getenv('SQL_DSN') ?: '');
        if ($dsn === '') {
            $db = $root . '/data/app.sqlite';
            $schema = $root . '/data/schema.sql';
            $pdo = new PDO('sqlite:' . $db);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if (is_file($schema) && !$this->hasUserTables($pdo)) {
                $sql = (string) file_get_contents($schema);
                // SharePoint lists expose both Id and ID; SQLite column names are case-insensitive.
                $sql = preg_replace('/,\s*ID TEXT/i', '', $sql) ?? $sql;
                try {
                    $pdo->exec($sql);
                } catch (\Throwable) {
                    foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
                        $stmt = trim($stmt);
                        if ($stmt === '') {
                            continue;
                        }
                        try {
                            $pdo->exec($stmt);
                        } catch (\Throwable) {
                        }
                    }
                }
            }
            $this->pdo = $pdo;
            return;
        }
        $this->pdo = new PDO($dsn);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function safeTable(string $table): string
    {
        return $this->resolveExisting($table);
    }

    private function hasUserTables(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            return $stmt !== false && (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveExisting(string $table): string
    {
        foreach (TableMap::candidates($table) as $cand) {
            $ident = TableMap::safeIdent($cand);
            if ($this->tableExists($ident)) {
                return $ident;
            }
        }

        return TableMap::safeIdent(TableMap::canonical($table));
    }

    private function tableExists(string $ident): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $this->pdo->quote($ident));
            if ($stmt && $stmt->fetchColumn()) {
                return true;
            }
        } catch (\Throwable) {
        }
        try {
            $this->pdo->query('SELECT 1 FROM ' . $ident . ' LIMIT 0');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $row */
    private function ensureTable(string $ident, array $row = []): void
    {
        if (!$this->tableExists($ident)) {
            $cols = ['Id INTEGER PRIMARY KEY'];
            foreach (array_keys($row) as $k) {
                $c = TableMap::safeColumn((string) $k);
                if (strcasecmp($c, 'Id') === 0) {
                    continue;
                }
                $cols[] = $c . ' TEXT';
            }
            if (count($cols) === 1) {
                $cols[] = 'Title TEXT';
                $cols[] = 'Value TEXT';
            }
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $ident . ' (' . implode(', ', $cols) . ')');
        }
        $this->ensureColumns($ident, $row);
    }

    /** @param array<string, mixed> $row */
    private function ensureColumns(string $ident, array $row): void
    {
        if ($row === []) {
            return;
        }
        $existing = [];
        try {
            $stmt = $this->pdo->query('PRAGMA table_info(' . $ident . ')');
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    $existing[strtolower((string) ($col['name'] ?? ''))] = true;
                }
            }
        } catch (\Throwable) {
            return;
        }
        foreach (array_keys($row) as $k) {
            $c = TableMap::safeColumn((string) $k);
            if (isset($existing[strtolower($c)])) {
                continue;
            }
            try {
                $this->pdo->exec('ALTER TABLE ' . $ident . ' ADD COLUMN ' . $c . ' TEXT');
                $existing[strtolower($c)] = true;
            } catch (\Throwable) {
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function persistable(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $c = TableMap::safeColumn((string) $k);
            $out[$c] = is_scalar($v) || $v === null ? $v : json_encode($v);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function list(string $table): array
    {
        $t = $this->safeTable($table);
        try {
            $stmt = $this->pdo->query('SELECT * FROM ' . $t);
        } catch (\Throwable) {
            return [];
        }
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn(array $row): array => TableMap::aliasRow($row), $rows);
    }

    /** @return array<string, mixed>|null */
    public function get(string $table, mixed $id): ?array
    {
        $t = $this->safeTable($table);
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . $t . ' WHERE Id = :id OR ID = :id2 OR lom_id = :id3');
            $stmt->execute([':id' => $id, ':id2' => $id, ':id3' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . $t . ' WHERE Id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return is_array($row) ? TableMap::aliasRow($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function insert(string $table, array $row): array
    {
        $t = $this->safeTable($table);
        $persist = $this->persistable($row);
        if (!isset($persist['Id'])) {
            $persist['Id'] = (string) (count($this->list($table)) + 1);
            $row['Id'] = $persist['Id'];
        }
        $this->ensureTable($t, $persist);
        $cols = array_keys($persist);
        $placeholders = array_map(static fn($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO ' . $t . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $this->pdo->prepare($sql);
        $params = [];
        foreach ($persist as $k => $v) {
            $params[':' . $k] = $v;
        }
        $stmt->execute($params);

        return TableMap::aliasRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function update(string $table, array $row): array
    {
        $persist = $this->persistable($row);
        if (!isset($persist['Id'])) {
            $found = $this->findByFields($table, $row);
            if ($found !== null && isset($found['Id'])) {
                $persist['Id'] = $found['Id'];
                $row['Id'] = $found['Id'];
            } else {
                return $this->insert($table, $row);
            }
        }
        $t = $this->safeTable($table);
        $this->ensureTable($t, $persist);
        $sets = [];
        $params = [':Id' => $persist['Id']];
        foreach ($persist as $k => $v) {
            if ($k === 'Id') {
                continue;
            }
            $sets[] = $k . ' = :' . $k;
            $params[':' . $k] = $v;
        }
        if ($sets === []) {
            return TableMap::aliasRow($row);
        }
        $sql = 'UPDATE ' . $t . ' SET ' . implode(', ', $sets) . ' WHERE Id = :Id';
        $this->pdo->prepare($sql)->execute($params);

        return TableMap::aliasRow($row);
    }

    public function delete(string $table, mixed $id): bool
    {
        $t = $this->safeTable($table);
        $stmt = $this->pdo->prepare('DELETE FROM ' . $t . ' WHERE Id = :id');
        $stmt->execute([':id' => $id]);

        return true;
    }

    /**
     * @param array<string, mixed> $match
     */
    public function deleteWhere(string $table, array $match): int
    {
        $n = 0;
        foreach ($this->list($table) as $row) {
            if (!$this->rowMatches($row, $match)) {
                continue;
            }
            $id = $row['Id'] ?? $row['ID'] ?? $row['lom_id'] ?? null;
            if ($id === null) {
                continue;
            }
            $this->delete($table, $id);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function findByFields(string $table, array $row): ?array
    {
        $title = $row['Title'] ?? $row['title'] ?? null;
        $request = $row['RequestID'] ?? $row['RequestId'] ?? null;
        foreach ($this->list($table) as $existing) {
            if ($title !== null && (string) ($existing['Title'] ?? '') === (string) $title) {
                return $existing;
            }
            if ($request !== null && (string) ($existing['RequestID'] ?? '') === (string) $request) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $match
     */
    private function rowMatches(array $row, array $match): bool
    {
        foreach ($match as $k => $v) {
            $have = $row[$k] ?? $row[TableMap::safeColumn((string) $k)] ?? null;
            if ($have === null && isset($row[preg_replace('/\s+/', '', (string) $k)])) {
                $have = $row[preg_replace('/\s+/', '', (string) $k)];
            }
            if (strcasecmp((string) $have, (string) $v) !== 0) {
                return false;
            }
        }

        return $match !== [];
    }
}
