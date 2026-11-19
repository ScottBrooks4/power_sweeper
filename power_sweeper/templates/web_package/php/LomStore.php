<?php

declare(strict_types=1);

if (!class_exists('TableMap', false)) {
    require_once __DIR__ . '/TableMap.php';
}

/**
 * LOM-backed store using /srv/http/LOM (or LOM_HOME) O.php.
 */
final class LomStore
{
    private object $O;
    private string $xmlPath;

    /** @param array<string, mixed> $config */
    public function __construct(string $root, array $config)
    {
        $lomHome = (string) ($config['lom_home'] ?? getenv('LOM_HOME') ?: '/srv/http/LOM');
        $oPhp = rtrim($lomHome, '/\\') . '/O.php';
        if (!is_file($oPhp)) {
            throw new RuntimeException('LOM O.php not found at ' . $oPhp);
        }
        require_once $oPhp;
        $this->xmlPath = $root . '/data/app.xml';
        if (!is_file($this->xmlPath)) {
            file_put_contents($this->xmlPath, "<app></app>\n");
        }
        $this->O = new O($this->xmlPath);
    }

    private function safeTable(string $table): string
    {
        return TableMap::safeIdent(TableMap::canonical($table));
    }

    /** @return list<string> */
    private function tableSelectors(string $table): array
    {
        $out = [];
        foreach (TableMap::candidates($table) as $cand) {
            $ident = TableMap::safeIdent($cand);
            $rowTag = TableMap::rowTag($cand);
            $out[] = $ident . '_row';
            $out[] = $ident;
            if ($rowTag !== 'row') {
                $out[] = $rowTag;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<array<string, mixed>> */
    public function list(string $table): array
    {
        $rows = [];
        foreach ($this->tableSelectors($table) as $sel) {
            $matches = null;
            if (method_exists($this->O, '_')) {
                $matches = $this->O->_($sel);
            }
            if (!is_array($matches) && method_exists($this->O, 'get')) {
                $matches = $this->O->get($sel);
            }
            if (!is_array($matches) || $matches === []) {
                continue;
            }
            foreach ($matches as $match) {
                $rows[] = TableMap::aliasRow($this->rowFromMatch($match));
            }
            if ($rows !== []) {
                return $rows;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function get(string $table, mixed $id): ?array
    {
        foreach ($this->list($table) as $row) {
            if ((string) ($row['Id'] ?? '') === (string) $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function insert(string $table, array $row): array
    {
        $t = $this->safeTable($table);
        if (!isset($row['Id']) && !isset($row['lom_id'])) {
            $row['Id'] = (string) (count($this->list($table)) + 1);
        }
        $rowTag = TableMap::rowTag($t);
        $attrs = '';
        foreach ($row as $k => $v) {
            $key = TableMap::safeColumn((string) $k);
            $attrs .= ' ' . $key . '="' . htmlspecialchars(is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v), ENT_QUOTES) . '"';
        }
        $title = htmlspecialchars((string) ($row['Title'] ?? ''), ENT_QUOTES);
        $xml = '<' . $rowTag . $attrs . '>' . $title . '</' . $rowTag . '>';
        $this->O->new_($xml, $t);
        $this->save();

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function update(string $table, array $row): array
    {
        $id = $row['Id'] ?? $row['ID'] ?? $row['lom_id'] ?? null;
        if ($id === null) {
            foreach ($this->list($table) as $existing) {
                if (isset($row['Title']) && (string) ($existing['Title'] ?? '') === (string) $row['Title']) {
                    $id = $existing['Id'] ?? $existing['ID'] ?? $existing['lom_id'] ?? null;
                    $row = array_merge($existing, $row);
                    break;
                }
            }
        }
        if ($id === null) {
            return $this->insert($table, $row);
        }
        $row['Id'] = $id;
        $t = $this->safeTable($table);
        $existing = $this->get($table, $id);
        if ($existing === null) {
            return $this->insert($table, $row);
        }
        // Delete and re-insert for simplicity (keeps package self-contained).
        foreach (['Id', 'lom_id', 'ID'] as $key) {
            $this->O->delete($t . '_row@' . $key . '=' . $id);
            $rowTag = TableMap::rowTag($t);
            if ($rowTag !== 'row') {
                $this->O->delete($rowTag . '@' . $key . '=' . $id);
            }
        }
        return $this->insert($table, array_merge($existing, $row));
    }

    public function delete(string $table, mixed $id): bool
    {
        $t = $this->safeTable($table);
        foreach (['Id', 'lom_id', 'ID'] as $key) {
            $this->O->delete($t . '_row@' . $key . '=' . $id);
            $rowTag = TableMap::rowTag($t);
            if ($rowTag !== 'row') {
                $this->O->delete($rowTag . '@' . $key . '=' . $id);
            }
        }
        $this->save();

        return true;
    }

    /**
     * @param array<string, mixed> $match
     */
    public function deleteWhere(string $table, array $match): int
    {
        $n = 0;
        foreach ($this->list($table) as $row) {
            $ok = $match !== [];
            foreach ($match as $k => $v) {
                $have = $row[$k] ?? $row[TableMap::safeColumn((string) $k)] ?? null;
                if (strcasecmp((string) $have, (string) $v) !== 0) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
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

    /** @return array<string, mixed> */
    private function rowFromMatch(mixed $match): array
    {
        $row = [];
        if (is_array($match)) {
            foreach ($match as $k => $v) {
                if (is_string($k)) {
                    $row[$k] = is_scalar($v) ? $v : (string) json_encode($v);
                }
            }
            if ($row !== []) {
                return $row;
            }
        }
        // Fallback: ask LOM for tagged string and parse attributes.
        $tagged = null;
        if (method_exists($this->O, 'get_tagged_string')) {
            try {
                $tagged = $this->O->get_tagged_string($match);
            } catch (Throwable) {
            }
        }
        if (is_string($tagged) && preg_match_all('/(\w+)="([^"]*)"/', $tagged, $m, PREG_SET_ORDER)) {
            foreach ($m as $attr) {
                $row[$attr[1]] = html_entity_decode($attr[2]);
            }
        }
        if ($row === []) {
            $row = ['Id' => (string) count($row) + 1, 'Title' => is_scalar($match) ? (string) $match : ''];
        }

        return $row;
    }

    private function save(): void
    {
        if (method_exists($this->O, 'save_LOM_to_file')) {
            $this->O->save_LOM_to_file($this->xmlPath);
        } elseif (method_exists($this->O, 'save')) {
            $this->O->save($this->xmlPath);
        }
    }
}
