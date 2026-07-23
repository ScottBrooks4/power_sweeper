<?php

declare(strict_types=1);

namespace PowerSweeper\SharePoint;

/**
 * Expected SharePoint list catalogue supplied by the operator (upload / hop options).
 */
final class SharePointSchema
{
    /**
     * @param list<array{
     *   name:string,
     *   displayName?:string,
     *   siteUrl?:string,
     *   listId?:string,
     *   columns:list<array{name:string,displayName?:string,type?:string}>
     * }> $lists
     */
    public function __construct(public readonly array $lists)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $listsIn = $data['lists'] ?? $data;
        if (!is_array($listsIn)) {
            return new self([]);
        }

        $lists = [];
        foreach ($listsIn as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? $item['list'] ?? $item['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $columns = [];
            $colsIn = $item['columns'] ?? $item['fields'] ?? [];
            if (is_array($colsIn)) {
                foreach ($colsIn as $col) {
                    if (is_string($col) && trim($col) !== '') {
                        $columns[] = ['name' => trim($col)];
                        continue;
                    }
                    if (!is_array($col)) {
                        continue;
                    }
                    $colName = trim((string) ($col['name'] ?? $col['internalName'] ?? $col['Name'] ?? ''));
                    if ($colName === '') {
                        continue;
                    }
                    $entry = ['name' => $colName];
                    if (!empty($col['displayName']) || !empty($col['title'])) {
                        $entry['displayName'] = (string) ($col['displayName'] ?? $col['title']);
                    }
                    if (!empty($col['type'])) {
                        $entry['type'] = (string) $col['type'];
                    }
                    $columns[] = $entry;
                }
            }
            $lists[] = [
                'name' => $name,
                'displayName' => isset($item['displayName']) ? (string) $item['displayName'] : $name,
                'siteUrl' => isset($item['siteUrl']) ? (string) $item['siteUrl'] : (isset($item['dataset']) ? (string) $item['dataset'] : null),
                'listId' => isset($item['listId']) ? (string) $item['listId'] : null,
                'columns' => $columns,
            ];
        }

        return new self($lists);
    }

    public static function fromJsonFile(string $path): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Could not read SharePoint schema: ' . $path);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('SharePoint schema must be JSON object/array');
        }
        return self::fromArray($data);
    }

    public function isEmpty(): bool
    {
        return $this->lists === [];
    }

    /** @return list<string> */
    public function listNames(): array
    {
        return array_values(array_map(static fn(array $l): string => $l['name'], $this->lists));
    }

    public function findList(string $name): ?array
    {
        foreach ($this->lists as $list) {
            if (strcasecmp($list['name'], $name) === 0) {
                return $list;
            }
            if (strcasecmp((string) ($list['displayName'] ?? ''), $name) === 0) {
                return $list;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function columnNames(string $listName): array
    {
        $list = $this->findList($listName);
        if ($list === null) {
            return [];
        }
        $names = [];
        foreach ($list['columns'] as $col) {
            $names[] = $col['name'];
            if (!empty($col['displayName'])) {
                $names[] = (string) $col['displayName'];
            }
        }
        return array_values(array_unique($names));
    }
}
