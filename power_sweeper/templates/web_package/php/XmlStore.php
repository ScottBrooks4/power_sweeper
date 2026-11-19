<?php

declare(strict_types=1);

if (!class_exists('TableMap', false)) {
    require_once __DIR__ . '/TableMap.php';
}

/**
 * XML document store (`data/app.xml`). Rows are attributes on `<row>` elements.
 */
final class XmlStore
{
    private string $path;

    /** @param array<string, mixed> $config */
    public function __construct(string $root, array $config)
    {
        unset($config);
        $dir = $root . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->path = $dir . '/app.xml';
        if (!is_file($this->path)) {
            file_put_contents($this->path, "<app>\n</app>\n");
        }
    }

    /** @return list<array<string, string>> */
    public function list(string $table): array
    {
        $dom = $this->load();
        $parent = $this->tableElement($dom, $table, false);
        if ($parent === null) {
            return [];
        }
        $rows = [];
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->tagName !== 'row') {
                continue;
            }
            $rows[] = $this->rowFromElement($child);
        }

        return $rows;
    }

    /** @return array<string, string>|null */
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
     * @return array<string, string>
     */
    public function insert(string $table, array $row): array
    {
        $stored = [];
        foreach ($row as $key => $value) {
            $stored[(string) $key] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        }
        if (!isset($stored['Id']) || $stored['Id'] === '') {
            $stored['Id'] = (string) (count($this->list($table)) + 1);
        }
        $dom = $this->load();
        $parent = $this->tableElement($dom, $table, true);
        if ($parent === null) {
            return $stored;
        }
        $el = $dom->createElement('row');
        foreach ($stored as $key => $value) {
            $attr = preg_replace('/\W+/', '_', $key) ?: 'f';
            $el->setAttribute($attr, $value);
        }
        if (isset($stored['Title'])) {
            $el->textContent = $stored['Title'];
        }
        $parent->appendChild($el);
        $this->save($dom);

        return $stored;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public function update(string $table, array $row): array
    {
        if (!isset($row['Id'])) {
            return $this->insert($table, $row);
        }
        $existing = $this->get($table, $row['Id']);
        if ($existing === null) {
            return $this->insert($table, $row);
        }
        $this->delete($table, $row['Id']);

        return $this->insert($table, array_merge($existing, $row));
    }

    public function delete(string $table, mixed $id): bool
    {
        $dom = $this->load();
        $parent = $this->tableElement($dom, $table, false);
        if ($parent === null) {
            return false;
        }
        $removed = false;
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (!$child instanceof DOMElement || $child->tagName !== 'row') {
                continue;
            }
            if ($child->getAttribute('Id') === (string) $id) {
                $parent->removeChild($child);
                $removed = true;
            }
        }
        if ($removed) {
            $this->save($dom);
        }

        return $removed;
    }

    /**
     * @param array<string, mixed> $match
     */
    public function deleteWhere(string $table, array $match): int
    {
        $n = 0;
        foreach ($this->list($table) as $row) {
            $ok = true;
            foreach ($match as $key => $value) {
                if ((string) ($row[(string) $key] ?? '') !== (string) $value) {
                    $ok = false;
                    break;
                }
            }
            if ($ok && isset($row['Id']) && $this->delete($table, $row['Id'])) {
                $n++;
            }
        }

        return $n;
    }

    private function load(): DOMDocument
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->load($this->path);

        return $dom;
    }

    private function save(DOMDocument $dom): void
    {
        $dom->save($this->path);
    }

    private function tableElement(DOMDocument $dom, string $table, bool $create): ?DOMElement
    {
        $name = TableMap::safeIdent(TableMap::canonical($table));
        $root = $dom->documentElement;
        if ($root === null) {
            return null;
        }
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === $name) {
                return $child;
            }
        }
        if (!$create) {
            return null;
        }
        $el = $dom->createElement($name);
        $root->appendChild($el);

        return $el;
    }

    /** @return array<string, string> */
    private function rowFromElement(DOMElement $el): array
    {
        $row = [];
        if ($el->hasAttributes()) {
            foreach ($el->attributes as $attr) {
                $row[$attr->name] = $attr->value;
            }
        }
        $text = trim($el->textContent);
        if ($text !== '' && !isset($row['Title'])) {
            $row['Title'] = $text;
        }

        return $row;
    }
}
