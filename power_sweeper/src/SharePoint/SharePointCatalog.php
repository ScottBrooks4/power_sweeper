<?php

declare(strict_types=1);

namespace PowerSweeper\SharePoint;

/**
 * SharePoint-oriented view of Connections / DataSources / TableDefinitions inside an unpacked .msapp.
 */
final class SharePointCatalog
{
    /**
     * @param list<array{
     *   name:string,
     *   type:string,
     *   dataset:?string,
     *   table:?string,
     *   listId:?string,
     *   columns:array<string,string>,
     *   sourceFile:string,
     *   isSharePoint:bool,
     *   raw:array<string,mixed>
     * }> $sources
     * @param list<array{id?:string,name?:string,type?:string,sourceFile:string,raw:array<string,mixed>}> $connections
     */
    public function __construct(
        public array $sources,
        public array $connections,
    ) {
    }

    public static function loadFromExtractDir(string $extractDir): self
    {
        $sources = [];
        $connections = [];

        $paths = [
            $extractDir . '/References/DataSources.json',
            $extractDir . '/DataSources.json',
        ];
        foreach ($paths as $path) {
            if (is_file($path)) {
                self::ingestDataSourcesFile($path, $extractDir, $sources);
            }
        }

        foreach (['DataSources', 'References/DataSources'] as $dirRel) {
            $dir = $extractDir . '/' . $dirRel;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                self::ingestDataSourcesFile($file, $extractDir, $sources);
            }
        }

        // Enrich columns from pkgs/TableDefinitions when present
        foreach (['pkgs/TableDefinitions', 'Pkgs/TableDefinitions', 'References/TableDefinitions'] as $tdRel) {
            $tdDir = $extractDir . '/' . $tdRel;
            if (!is_dir($tdDir)) {
                continue;
            }
            foreach (glob($tdDir . '/*.json') ?: [] as $file) {
                self::enrichFromTableDefinition($file, $sources);
            }
        }

        foreach ([
            $extractDir . '/Connections/Connections.json',
            $extractDir . '/Connections.json',
            $extractDir . '/References/Connections.json',
        ] as $connPath) {
            if (is_file($connPath)) {
                self::ingestConnectionsFile($connPath, $extractDir, $connections);
            }
        }

        // Deduplicate sources by name+dataset
        $deduped = [];
        $seen = [];
        foreach ($sources as $src) {
            $key = strtolower($src['name'] . '|' . ($src['dataset'] ?? '') . '|' . $src['sourceFile']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $src;
        }

        return new self($deduped, $connections);
    }

    /** @return list<string> */
    public function sharePointListNames(): array
    {
        $names = [];
        foreach ($this->sources as $src) {
            if ($src['isSharePoint']) {
                $names[] = $src['name'];
            }
        }
        return array_values(array_unique($names));
    }

    public function findSource(string $name): ?array
    {
        foreach ($this->sources as $src) {
            if (strcasecmp($src['name'], $name) === 0) {
                return $src;
            }
            if ($src['table'] !== null && strcasecmp($src['table'], $name) === 0) {
                return $src;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function columnNamesFor(string $listName): array
    {
        $src = $this->findSource($listName);
        if ($src === null) {
            return [];
        }
        $names = array_values(array_unique(array_merge(
            array_keys($src['columns']),
            array_values($src['columns'])
        )));
        return array_values(array_filter($names, static fn(string $n): bool => $n !== ''));
    }

    /**
     * @param list<array<string,mixed>> $sources
     */
    private static function ingestDataSourcesFile(string $absolutePath, string $extractDir, array &$sources): void
    {
        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }

        $rel = substr($absolutePath, strlen(rtrim($extractDir, '/\\')) + 1);
        $rel = str_replace('\\', '/', $rel);

        $entries = [];
        if (isset($data['DataSources']) && is_array($data['DataSources'])) {
            $entries = $data['DataSources'];
        } elseif (self::looksLikeDataSource($data)) {
            $entries = [$data];
        } elseif (array_is_list($data)) {
            $entries = $data;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry) || !self::looksLikeDataSource($entry)) {
                continue;
            }
            $sources[] = self::normalizeSource($entry, $rel);
        }
    }

    /**
     * @param list<array<string,mixed>> $sources
     */
    private static function enrichFromTableDefinition(string $absolutePath, array &$sources): void
    {
        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }

        $name = (string) ($data['Name'] ?? $data['name'] ?? pathinfo($absolutePath, PATHINFO_FILENAME));
        $columns = [];

        if (isset($data['DataEntityMetadataJson']) && is_string($data['DataEntityMetadataJson'])) {
            $meta = json_decode($data['DataEntityMetadataJson'], true);
            if (is_array($meta)) {
                $props = $meta['schema']['items']['properties'] ?? $meta['schema']['properties'] ?? null;
                if (is_array($props)) {
                    foreach ($props as $colName => $colDef) {
                        if (is_string($colName)) {
                            $title = is_array($colDef) ? (string) ($colDef['title'] ?? $colName) : $colName;
                            $columns[$title] = $colName;
                        }
                    }
                }
            }
        }

        if ($columns === []) {
            return;
        }

        foreach ($sources as &$src) {
            if (strcasecmp($src['name'], $name) === 0 || strcasecmp((string) ($src['table'] ?? ''), $name) === 0) {
                $src['columns'] = array_merge($src['columns'], $columns);
            }
        }
        unset($src);
    }

    /**
     * @param list<array<string,mixed>> $connections
     */
    private static function ingestConnectionsFile(string $absolutePath, string $extractDir, array &$connections): void
    {
        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }
        $rel = substr($absolutePath, strlen(rtrim($extractDir, '/\\')) + 1);
        $rel = str_replace('\\', '/', $rel);

        $entries = array_is_list($data) ? $data : ($data['Connections'] ?? [$data]);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $connections[] = [
                'id' => isset($entry['id']) ? (string) $entry['id'] : (isset($entry['Id']) ? (string) $entry['Id'] : null),
                'name' => isset($entry['displayName']) ? (string) $entry['displayName'] : (isset($entry['Name']) ? (string) $entry['Name'] : null),
                'type' => isset($entry['connectionRef']['apiName']) ? (string) $entry['connectionRef']['apiName'] : (isset($entry['Type']) ? (string) $entry['Type'] : null),
                'sourceFile' => $rel,
                'raw' => $entry,
            ];
        }
    }

    /** @param array<string, mixed> $entry */
    private static function looksLikeDataSource(array $entry): bool
    {
        return isset($entry['Name']) || isset($entry['Type']) || isset($entry['ConnectedDataSourceInfoNameMapping']);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{
     *   name:string,type:string,dataset:?string,table:?string,listId:?string,
     *   columns:array<string,string>,sourceFile:string,isSharePoint:bool,raw:array<string,mixed>
     * }
     */
    private static function normalizeSource(array $entry, string $rel): array
    {
        $name = (string) ($entry['Name'] ?? $entry['name'] ?? 'Unknown');
        $type = (string) ($entry['Type'] ?? $entry['type'] ?? '');
        $dataset = isset($entry['DatasetName']) ? (string) $entry['DatasetName'] : (isset($entry['dataset']) ? (string) $entry['dataset'] : null);
        $table = isset($entry['TableName']) ? (string) $entry['TableName'] : (isset($entry['tableName']) ? (string) $entry['tableName'] : null);
        $listId = isset($entry['SharePointListId']) ? (string) $entry['SharePointListId'] : (isset($entry['ListId']) ? (string) $entry['ListId'] : null);

        $columns = [];
        if (isset($entry['ConnectedDataSourceInfoNameMapping']) && is_array($entry['ConnectedDataSourceInfoNameMapping'])) {
            foreach ($entry['ConnectedDataSourceInfoNameMapping'] as $display => $logical) {
                $columns[(string) $display] = (string) $logical;
            }
        }

        $isSp = str_contains(strtolower($type), 'sharepoint')
            || (is_string($dataset) && str_contains(strtolower($dataset), 'sharepoint.com'))
            || isset($entry['SharePointListId'])
            || isset($entry['ApiId']) && is_string($entry['ApiId']) && str_contains(strtolower($entry['ApiId']), 'sharepoint');

        return [
            'name' => $name,
            'type' => $type,
            'dataset' => $dataset,
            'table' => $table,
            'listId' => $listId,
            'columns' => $columns,
            'sourceFile' => $rel,
            'isSharePoint' => $isSp,
            'raw' => $entry,
            'dirty' => false,
            'originalName' => $name,
        ];
    }

    /**
     * Write mutated raw entries back into their JSON files under $extractDir.
     *
     * @param callable(string,string,string,string):void $report path, property, from, to
     */
    public function persist(string $extractDir, callable $report): void
    {
        $fileEntries = [];
        foreach ($this->sources as $src) {
            if (empty($src['dirty'])) {
                continue;
            }
            $fileEntries[$src['sourceFile']][] = $src;
        }
        if ($fileEntries === []) {
            return;
        }

        foreach ($fileEntries as $rel => $srcs) {
            $abs = rtrim($extractDir, '/\\') . '/' . $rel;
            if (!is_file($abs)) {
                continue;
            }
            $original = file_get_contents($abs);
            if ($original === false) {
                continue;
            }
            $data = json_decode($original, true);
            if (!is_array($data)) {
                continue;
            }

            $replaced = false;
            if (isset($data['DataSources']) && is_array($data['DataSources'])) {
                foreach ($data['DataSources'] as $i => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $name = (string) ($entry['Name'] ?? '');
                    foreach ($srcs as $src) {
                        $origName = (string) ($src['originalName'] ?? $src['name']);
                        if (strcasecmp($origName, $name) === 0 || strcasecmp($src['name'], $name) === 0) {
                            $data['DataSources'][$i] = $src['raw'];
                            $replaced = true;
                            break;
                        }
                    }
                }
            } elseif (self::looksLikeDataSource($data) && count($srcs) === 1) {
                $data = $srcs[0]['raw'];
                $replaced = true;
            } elseif (array_is_list($data)) {
                foreach ($data as $i => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $name = (string) ($entry['Name'] ?? '');
                    foreach ($srcs as $src) {
                        $origName = (string) ($src['originalName'] ?? $src['name']);
                        if (strcasecmp($origName, $name) === 0 || strcasecmp($src['name'], $name) === 0) {
                            $data[$i] = $src['raw'];
                            $replaced = true;
                            break;
                        }
                    }
                }
            }

            if (!$replaced) {
                continue;
            }

            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            file_put_contents($abs, $encoded);
            $report($rel, 'file', '(datasource metadata)', 'updated');
        }
    }
}
