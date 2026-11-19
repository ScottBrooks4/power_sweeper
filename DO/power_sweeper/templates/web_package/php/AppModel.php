<?php

declare(strict_types=1);

/**
 * MVC model: SharePoint-list shaped rows in the XML document or SQL database.
 * Patch / list / get used by the canvas runtime and by Controllers.
 */
final class AppModel
{
    private object $store;

    public function __construct(private string $root)
    {
        $configPath = $this->root . '/server/config.json';
        $config = is_file($configPath)
            ? (json_decode((string) file_get_contents($configPath), true) ?: [])
            : [];
        $storage = strtolower((string) ($config['storage'] ?? 'xml'));
        if ($storage === 'lom') {
            $storage = 'xml';
        }
        require_once $this->root . '/server/TableMap.php';
        try {
            if ($storage === 'sql') {
                require_once $this->root . '/server/SqlStore.php';
                $this->store = new SqlStore($this->root, is_array($config) ? $config : []);
            } elseif ($storage === 'lom') {
                require_once $this->root . '/server/LomStore.php';
                $this->store = new LomStore($this->root, is_array($config) ? $config : []);
            } else {
                require_once $this->root . '/server/XmlStore.php';
                $this->store = new XmlStore($this->root, is_array($config) ? $config : []);
            }
        } catch (Throwable $e) {
            if (
                $storage === 'sql'
                && str_contains($e->getMessage(), 'could not find driver')
                && is_file($this->root . '/data/app.xml')
            ) {
                require_once $this->root . '/server/XmlStore.php';
                $this->store = new XmlStore($this->root, is_array($config) ? $config : []);
            } else {
                throw $e;
            }
        }
        if (is_file($this->root . '/server/StoreSeed.php')) {
            require_once $this->root . '/server/StoreSeed.php';
            StoreSeed::ensure($this->store);
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(string $table): array
    {
        return $this->store->list($table);
    }

    /** @return array<string, mixed>|null */
    public function get(string $table, mixed $id): ?array
    {
        return $this->store->get($table, $id);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function insert(string $table, array $row): array
    {
        return $this->store->insert($table, $row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function update(string $table, array $row): array
    {
        return $this->store->update($table, $row);
    }

    public function delete(string $table, mixed $id): bool
    {
        return $this->store->delete($table, $id);
    }
}
