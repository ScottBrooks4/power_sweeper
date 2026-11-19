<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\FormulaIdentifierRewriter;
use PowerSweeper\Report;
use PowerSweeper\SharePoint\SharePointCatalog;
use PowerSweeper\SharePoint\SharePointSchema;
use PowerSweeper\StringSimilarity;

/**
 * Correlate .msapp SharePoint connections/datasources with an expected list schema
 * (or the app's own table definitions), report bad links, and repair near-miss typos.
 */
final class CorrelateSharePointHop implements HopInterface
{
    public static function id(): string
    {
        return 'correlate_sharepoint';
    }

    public static function label(): string
    {
        return 'Correlate SharePoint lists';
    }

    public static function description(): string
    {
        return 'Validate SharePoint list connections against a schema (or patterns in the app data), flag bad connections, and repair list/column typos — every fix is reported.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = isset($options['_extract_dir']) && is_string($options['_extract_dir'])
            ? $options['_extract_dir']
            : '';
        if ($extractDir === '' || !is_dir($extractDir)) {
            $report->add(self::id(), '(pipeline)', 'extract_dir', '(missing)', 'skipped — no unpack directory');
            return;
        }

        $repair = !array_key_exists('repair', $options) || (bool) $options['repair'];
        $maxDistance = isset($options['max_distance']) ? max(1, (int) $options['max_distance']) : 2;
        $repairSite = !empty($options['repair_site_url']);

        $schema = $this->loadSchema($options);
        $catalog = SharePointCatalog::loadFromExtractDir($extractDir);

        if ($catalog->sources === [] && $schema->isEmpty()) {
            $report->add(self::id(), '(catalog)', 'datasources', '(none found)', 'no SharePoint datasources or schema to correlate');
            return;
        }

        // Canonical list/column names: prefer operator schema, else learn from package metadata
        $canonicalLists = $schema->isEmpty() ? $catalog->sharePointListNames() : $schema->listNames();
        if ($canonicalLists === []) {
            $canonicalLists = $catalog->sharePointListNames();
        }

        $listRenameMap = [];
        $columnRenameMapByList = [];

        // --- Validate / repair datasource metadata ---
        foreach ($catalog->sources as &$src) {
            if (!$src['isSharePoint'] && !$schema->isEmpty()) {
                // Still try to match non-typed sources when schema provided
                if ($src['dataset'] === null && $src['table'] === null && $src['columns'] === []) {
                    continue;
                }
            }

            $path = $src['sourceFile'] . '/' . $src['name'];

            // Broken connection signals
            if ($src['isSharePoint'] || str_contains(strtolower($src['type']), 'connected')) {
                if (($src['dataset'] === null || trim((string) $src['dataset']) === '')
                    && ($src['table'] === null || trim((string) $src['table']) === '')
                ) {
                    $report->add(self::id(), $path, 'connection', '(empty dataset/table)', 'bad connection — missing SharePoint dataset/table');
                }
            }

            // List name vs schema / canonical
            $match = StringSimilarity::bestMatch($src['name'], $canonicalLists, $maxDistance);
            if ($match !== null && $match['distance'] > 0) {
                $report->add(
                    self::id(),
                    $path,
                    'list_name',
                    $src['name'],
                    $repair
                        ? $match['match'] . ' (typo repair, distance ' . $match['distance'] . ')'
                        : 'suggested: ' . $match['match'] . ' (distance ' . $match['distance'] . ')'
                );
                if ($repair) {
                    $listRenameMap[$src['name']] = $match['match'];
                    $src['raw']['Name'] = $match['match'];
                    if (isset($src['raw']['TableName'])) {
                        $src['raw']['TableName'] = $match['match'];
                    }
                    $src['name'] = $match['match'];
                    $src['table'] = $match['match'];
                    $src['dirty'] = true;
                }
            } elseif ($match === null && !$schema->isEmpty() && $src['isSharePoint']) {
                $report->add(self::id(), $path, 'list_name', $src['name'], 'not found in provided SharePoint schema');
            }

            // Site URL correlation
            $expected = $schema->findList($src['name']);
            if ($expected !== null && !empty($expected['siteUrl']) && is_string($src['dataset'])) {
                $want = rtrim((string) $expected['siteUrl'], '/');
                $have = rtrim($src['dataset'], '/');
                if (strcasecmp($want, $have) !== 0) {
                    $report->add(self::id(), $path, 'siteUrl', $have, $repairSite ? $want : 'expected: ' . $want);
                    if ($repairSite) {
                        $src['raw']['DatasetName'] = $want;
                        $src['dataset'] = $want;
                        $src['dirty'] = true;
                    }
                }
            }

            // Column mapping typos against schema or learned columns
            $canonicalCols = !$schema->isEmpty()
                ? $schema->columnNames($src['name'])
                : $catalog->columnNamesFor($src['name']);
            // Prefer schema columns; if empty fall back to this source's own columns as pattern set
            if ($canonicalCols === []) {
                $canonicalCols = array_values(array_unique(array_merge(
                    array_keys($src['columns']),
                    array_values($src['columns'])
                )));
            }

            if ($canonicalCols !== [] && $src['columns'] !== []) {
                $newMapping = [];
                $changedMapping = false;
                foreach ($src['columns'] as $display => $logical) {
                    $displayMatch = StringSimilarity::bestMatch((string) $display, $canonicalCols, $maxDistance);
                    $logicalMatch = StringSimilarity::bestMatch((string) $logical, $canonicalCols, $maxDistance);

                    $newDisplay = (string) $display;
                    $newLogical = (string) $logical;

                    if ($displayMatch !== null && $displayMatch['distance'] > 0) {
                        $report->add(
                            self::id(),
                            $path,
                            'column_display',
                            (string) $display,
                            $repair ? $displayMatch['match'] : 'suggested: ' . $displayMatch['match']
                        );
                        if ($repair) {
                            $newDisplay = $displayMatch['match'];
                            $columnRenameMapByList[$src['name']][(string) $display] = $displayMatch['match'];
                            $changedMapping = true;
                        }
                    }
                    if ($logicalMatch !== null && $logicalMatch['distance'] > 0) {
                        $report->add(
                            self::id(),
                            $path,
                            'column_logical',
                            (string) $logical,
                            $repair ? $logicalMatch['match'] : 'suggested: ' . $logicalMatch['match']
                        );
                        if ($repair) {
                            $newLogical = $logicalMatch['match'];
                            $columnRenameMapByList[$src['name']][(string) $logical] = $logicalMatch['match'];
                            $changedMapping = true;
                        }
                    }
                    $newMapping[$newDisplay] = $newLogical;
                }
                if ($changedMapping && $repair) {
                    $src['columns'] = $newMapping;
                    $src['raw']['ConnectedDataSourceInfoNameMapping'] = $newMapping;
                    $src['dirty'] = true;
                }
            }

            // Schema columns missing from datasource mapping (report only)
            if ($expected !== null) {
                $haveCols = array_map('strtolower', array_merge(array_keys($src['columns']), array_values($src['columns'])));
                foreach ($expected['columns'] as $col) {
                    $colName = $col['name'];
                    if (!in_array(strtolower($colName), $haveCols, true)) {
                        $display = $col['displayName'] ?? null;
                        if (is_string($display) && in_array(strtolower($display), $haveCols, true)) {
                            continue;
                        }
                        $report->add(self::id(), $path, 'column_missing', $colName, 'present in schema, absent from app datasource mapping');
                    }
                }
            }
        }
        unset($src);

        // Schema lists missing from app
        if (!$schema->isEmpty()) {
            foreach ($schema->listNames() as $wantList) {
                if ($catalog->findSource($wantList) === null) {
                    // Might have been renamed already in map
                    $found = false;
                    foreach ($listRenameMap as $from => $to) {
                        if (strcasecmp($to, $wantList) === 0) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $report->add(self::id(), '(schema)', 'list_missing_in_app', $wantList, 'expected list not present in .msapp datasources');
                    }
                }
            }
        }

        // Connection stubs that look SharePoint-related but unused / unnamed
        foreach ($catalog->connections as $conn) {
            $type = strtolower((string) ($conn['type'] ?? ''));
            if ($type !== '' && !str_contains($type, 'sharepoint')) {
                continue;
            }
            $name = trim((string) ($conn['name'] ?? ''));
            if ($name === '' && empty($conn['id'])) {
                $report->add(self::id(), $conn['sourceFile'], 'connection', '(unnamed)', 'bad connection stub');
            }
        }

        if ($repair) {
            $catalog->persist($extractDir, function (string $path, string $property, string $from, string $to) use ($report): void {
                $report->add(self::id(), $path, $property, $from, $to);
            });
        }

        // --- Formula correlation / repair ---
        $formulaMap = $listRenameMap;
        foreach ($columnRenameMapByList as $cols) {
            foreach ($cols as $from => $to) {
                $formulaMap[$from] = $to;
            }
        }

        // Also scan formulas for near-miss references against canonical names (patterns in data)
        $allCanonicalCols = [];
        foreach ($canonicalLists as $listName) {
            $cols = !$schema->isEmpty() ? $schema->columnNames($listName) : $catalog->columnNamesFor($listName);
            foreach ($cols as $c) {
                $allCanonicalCols[$c] = true;
            }
        }
        $canonicalColList = array_keys($allCanonicalCols);

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use (
                $repair,
                $report,
                $canonicalLists,
                $canonicalColList,
                $maxDistance,
                &$formulaMap
            ): string {
                $original = $formula;
                $localMap = $formulaMap;

                // Detect near-miss list references in this formula
                foreach ($this->extractReferencedNames($formula) as $ref) {
                    if (isset($localMap[$ref])) {
                        continue;
                    }
                    // Exact canonical hit — fine
                    foreach ($canonicalLists as $listName) {
                        if (strcasecmp($ref, $listName) === 0) {
                            continue 2;
                        }
                    }
                    foreach ($canonicalColList as $colName) {
                        if (strcasecmp($ref, $colName) === 0) {
                            continue 2;
                        }
                    }

                    $listHit = StringSimilarity::bestMatch($ref, $canonicalLists, $maxDistance);
                    if ($listHit !== null && $listHit['distance'] > 0) {
                        $report->add(
                            self::id(),
                            $path,
                            'formula_list',
                            $ref,
                            $repair ? $listHit['match'] : 'suggested: ' . $listHit['match']
                        );
                        if ($repair) {
                            $localMap[$ref] = $listHit['match'];
                            $formulaMap[$ref] = $listHit['match'];
                        }
                        continue;
                    }

                    $colHit = StringSimilarity::bestMatch($ref, $canonicalColList, $maxDistance);
                    if ($colHit !== null && $colHit['distance'] > 0 && strlen($ref) >= 4) {
                        $report->add(
                            self::id(),
                            $path,
                            'formula_column',
                            $ref,
                            $repair ? $colHit['match'] : 'suggested: ' . $colHit['match']
                        );
                        if ($repair) {
                            $localMap[$ref] = $colHit['match'];
                            $formulaMap[$ref] = $colHit['match'];
                        }
                    }
                }

                if ($localMap === []) {
                    return $formula;
                }
                $next = FormulaIdentifierRewriter::rename($formula, $localMap);
                if ($next !== $original && $repair) {
                    // Detailed per-key reporting already done for discoveries; note rewrite if map pre-existed
                    if ($next !== $formula) {
                        $report->add(self::id(), $path, 'formula', self::preview($original), self::preview($next));
                    }
                    return $next;
                }
                return $repair ? $next : $original;
            });
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function loadSchema(array $options): SharePointSchema
    {
        if (isset($options['schema_file']) && is_string($options['schema_file']) && is_file($options['schema_file'])) {
            return SharePointSchema::fromJsonFile($options['schema_file']);
        }
        if (isset($options['schema']) && is_array($options['schema'])) {
            return SharePointSchema::fromArray($options['schema']);
        }
        if (isset($options['lists']) && is_array($options['lists'])) {
            return SharePointSchema::fromArray(['lists' => $options['lists']]);
        }
        return new SharePointSchema([]);
    }

    /**
     * Pull candidate list/column identifiers from a formula (quoted names + Pascal/camel tokens).
     *
     * @return list<string>
     */
    private function extractReferencedNames(string $formula): array
    {
        $names = [];
        // Quoted identifiers: 'My List'
        if (preg_match_all("/'((?:[^']|'')+)'/", $formula, $m)) {
            foreach ($m[1] as $q) {
                $names[] = str_replace("''", "'", $q);
            }
        }
        // ThisItem.Field / [@Field] style
        if (preg_match_all('/\bThisItem\.([A-Za-z_][\w]*)/', $formula, $m2)) {
            foreach ($m2[1] as $f) {
                $names[] = $f;
            }
        }
        // LookUp/Filter/Search/Patch/Collect first-arg identifier
        if (preg_match_all('/\b(?:LookUp|Filter|Search|Patch|Collect|ClearCollect|Remove|UpdateIf|ShowColumns|AddColumns)\s*\(\s*([A-Za-z_][\w]*)/', $formula, $m3)) {
            foreach ($m3[1] as $f) {
                $names[] = $f;
            }
        }
        // .Column after a name
        if (preg_match_all('/\.[A-Za-z_][\w]{2,}/', $formula, $m4)) {
            foreach ($m4[0] as $f) {
                $names[] = substr($f, 1);
            }
        }

        $names = array_values(array_unique(array_filter($names, static function (string $n): bool {
            $skip = ['Color', 'RGBA', 'RGBA', 'Self', 'Parent', 'App', 'Param', 'User', 'true', 'false', 'Blank', 'If', 'Switch', 'With', 'Set', 'Navigate', 'Text', 'Value', 'DateTime'];
            return $n !== '' && !in_array($n, $skip, true);
        })));
        return $names;
    }

    private static function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        return strlen($s) > 160 ? substr($s, 0, 157) . '...' : $s;
    }
}
