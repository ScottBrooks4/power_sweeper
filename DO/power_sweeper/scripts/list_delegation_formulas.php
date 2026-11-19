#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\AppControlCatalog;
use PowerSweeper\AppDataContext;
use PowerSweeper\ControlNode;
use PowerSweeper\MsappArchive;
use PowerSweeper\StudioLiveChecker;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/list_delegation_formulas.php <path-to.msapp> [--json]\n");
    exit(1);
}

$msappPath = $argv[1];
$asJson = in_array('--json', $argv, true);

if (!is_file($msappPath)) {
    fwrite(STDERR, "File not found: {$msappPath}\n");
    exit(1);
}

$archive = new MsappArchive($msappPath);
try {
    $archive->unpack();
    $documents = $archive->documents();
    $extractDir = $archive->extractDir();
    $catalog = AppControlCatalog::build($documents);
    $dataContext = AppDataContext::build($documents, $extractDir);
    $scanDocs = $dataContext->documentsToScan($documents);
    $report = StudioLiveChecker::check($documents, ['extract_dir' => $extractDir]);

    $dataSources = loadDataSourceNames($extractDir);
    $collections = detectCollections($scanDocs, $dataSources);

    $formulaIndex = buildFormulaIndex($scanDocs, $catalog);

    $enriched = [];
    foreach ($report['findings'] as $finding) {
        if (!str_starts_with($finding['ruleId'], 'app-SuggestRemoteExecutionHint')) {
            continue;
        }
        $formula = $formulaIndex[$finding['location']] ?? null;
        if ($formula === null) {
            $formula = resolveFormula($finding, $scanDocs, $catalog);
        }
        $body = $formula !== null ? ltrim(trim($formula), '=') : '';
        $offset = (int) $finding['charOffset'];
        $length = (int) $finding['charLength'];
        $context = extractContext($body, $offset, $length);
        $inComment = isInComment($body, $offset);
        $pattern = classifyPattern($finding, $body, $offset, $dataSources, $collections);
        $sourceKind = classifySourceKind($body, $offset, $dataSources, $collections);
        $autoFix = suggestAutoFix($pattern, $context, $body, $sourceKind, $inComment);

        $enriched[] = [
            'ruleId' => $finding['ruleId'],
            'location' => $finding['location'],
            'screen' => $finding['screen'],
            'property' => $finding['property'],
            'triggerFunc' => $finding['triggerFunc'] ?? ($finding['messageArgs'][0] ?? ''),
            'charOffset' => $offset,
            'charLength' => $length,
            'formula' => $formula,
            'context' => $context,
            'pattern' => $pattern,
            'sourceKind' => $sourceKind,
            'autoFix' => $autoFix,
            'inComment' => $inComment,
        ];
    }

    $groups = groupFindings($enriched);
    $uniqueTemplates = dedupeTemplates($enriched);

    if ($asJson) {
        echo json_encode([
            'msapp' => basename($msappPath),
            'total' => count($enriched),
            'uniqueTemplates' => count($uniqueTemplates),
            'groups' => $groups,
            'templates' => $uniqueTemplates,
            'findings' => $enriched,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }

    echo "Delegation formula analysis — " . basename($msappPath) . "\n";
    echo str_repeat('=', 72) . "\n";
    echo 'TOTAL FINDINGS: ' . count($enriched) . "\n";
    echo 'UNIQUE TEMPLATES: ' . count($uniqueTemplates) . "\n\n";

    foreach ($groups as $groupKey => $group) {
        echo str_repeat('-', 72) . "\n";
        echo "PATTERN: {$groupKey}\n";
        echo "COUNT: {$group['count']}\n";
        echo "SOURCE: {$group['sourceKind']}\n";
        echo "AUTO-FIX: {$group['autoFix']}\n";
        echo "EXAMPLE SNIPPET:\n  {$group['exampleContext']}\n";
        if (!empty($group['exampleFix'])) {
            echo "EXAMPLE FIX:\n  {$group['exampleFix']}\n";
        }
        echo "LOCATIONS:\n";
        foreach ($group['locations'] as $loc) {
            echo "  - {$loc}\n";
        }
        echo "\n";
    }
} finally {
    $archive->cleanup();
}

/** @return array<string, true> */
function loadDataSourceNames(string $extractDir): array
{
    $names = [];
    foreach ([$extractDir . '/References/DataSources.json'] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }
        $list = $data['DataSources'] ?? ($data[0]['DataSources'] ?? []);
        if (!is_array($list)) {
            continue;
        }
        foreach ($list as $ds) {
            if (!is_array($ds)) {
                continue;
            }
            $name = (string) ($ds['Name'] ?? '');
            if ($name !== '') {
                $names[$name] = true;
            }
        }
    }
    return $names;
}

/**
 * @param list<\PowerSweeper\ControlDocument> $documents
 * @param array<string, true> $dataSources
 * @return array<string, true>
 */
function detectCollections(array $documents, array $dataSources): array
{
    $collections = [];
    foreach ($documents as $doc) {
        $doc->transformFormulas(static function (string $formula) use (&$collections, $dataSources): string {
            if (preg_match_all('/\b(?:ClearCollect|Collect)\s*\(\s*([A-Za-z_][\w]*)/i', $formula, $m)) {
                foreach ($m[1] as $name) {
                    if (!isset($dataSources[$name])) {
                        $collections[$name] = true;
                    }
                }
            }
            if (preg_match_all('/\bCountIf\s*\(\s*([A-Za-z_][\w]*)/i', $formula, $m)) {
                foreach ($m[1] as $name) {
                    if (!isset($dataSources[$name]) && !in_array(strtolower($name), ['app', 'true', 'false'], true)) {
                        $collections[$name] = true;
                    }
                }
            }
            return $formula;
        });
    }
    // Known local collections in this app
    foreach (['colSites', 'colVisitors', 'colRequests', 'colUsers', 'colAdmins', 'colRoles', 'colLookup'] as $c) {
        $collections[$c] = true;
    }
    return $collections;
}

/**
 * @param list<\PowerSweeper\ControlDocument> $documents
 * @return array<string, string>
 */
function buildFormulaIndex(array $documents, AppControlCatalog $catalog): array
{
    $index = [];
    foreach ($documents as $doc) {
        $screen = $catalog->screenForDocument($doc) ?? $doc->screenName() ?? '(unknown)';
        foreach ($doc->controls() as $control) {
            indexControl($index, $screen, $control);
        }
    }
    return $index;
}

/** @param array<string, string> $index */
function indexControl(array &$index, string $screen, ControlNode $control): void
{
    foreach ($control->propertyNames() as $prop) {
        $value = $control->getProperty($prop);
        if ($value === null || trim($value) === '') {
            continue;
        }
        $location = qualifiedLocation($screen, $control->path, $prop);
        $index[$location] = $value;
    }
    foreach ($control->children as $child) {
        indexControl($index, $screen, $child);
    }
}

function qualifiedLocation(string $screen, string $controlPath, string $property): string
{
    $controlFqn = pathToControlFqn($controlPath, $screen);
    if ($property === '') {
        return $controlFqn;
    }
    return $controlFqn . '.' . $property;
}

function pathToControlFqn(string $path, string $screen): string
{
    if ($screen !== '' && $screen !== '(unknown)' && str_contains($path, $screen)) {
        $pos = strpos($path, $screen);
        $rest = ltrim(substr($path, $pos + strlen($screen)), '/');
        $segments = $rest === '' ? [] : array_values(array_filter(explode('/', $rest), static fn(string $s): bool => $s !== ''));
        $parts = array_merge([$screen], $segments);
        return implode('.', array_map('quoteName', $parts));
    }
    $parts = array_values(array_filter(explode('/', $path)));
    $meaningful = [];
    foreach ($parts as $part) {
        if (str_ends_with($part, '.json') || str_ends_with($part, '.pa.yaml')) {
            continue;
        }
        if (in_array($part, ['Src', 'Controls', 'Components', 'Screens', 'ComponentDefinitions'], true)) {
            continue;
        }
        $meaningful[] = $part;
    }
    return implode('.', array_map('quoteName', $meaningful));
}

function quoteName(string $name): string
{
    if (preg_match('/^[A-Za-z_][\w]*$/', $name) && !ctype_digit($name[0])) {
        return $name;
    }
    return "'" . str_replace("'", "''", $name) . "'";
}

/**
 * @param array<string, mixed> $finding
 * @param list<\PowerSweeper\ControlDocument> $documents
 */
function resolveFormula(array $finding, array $documents, AppControlCatalog $catalog): ?string
{
    $location = $finding['location'];
    $property = $finding['property'];
    $dot = strrpos($location, '.' . $property);
    if ($dot === false) {
        return null;
    }
    $controlFqn = substr($location, 0, $dot);
    foreach ($documents as $doc) {
        $screen = $catalog->screenForDocument($doc) ?? $doc->screenName() ?? '(unknown)';
        foreach ($doc->controls() as $control) {
            $found = findByFqn($control, $screen, $controlFqn);
            if ($found !== null) {
                return $found->getProperty($property);
            }
        }
    }
    return null;
}

function findByFqn(ControlNode $control, string $screen, string $fqn): ?ControlNode
{
    if (qualifiedLocation($screen, $control->path, '') === $fqn) {
        return $control;
    }
    foreach ($control->children as $child) {
        $found = findByFqn($child, $screen, $fqn);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

function isInComment(string $body, int $offset): bool
{
    $lineStart = strrpos(substr($body, 0, $offset), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $linePrefix = substr($body, $lineStart, $offset - $lineStart);
    if (str_contains($linePrefix, '//')) {
        return true;
    }
    $blockStart = strrpos(substr($body, 0, $offset), '/*');
    if ($blockStart !== false) {
        $blockEnd = strpos($body, '*/', $blockStart);
        if ($blockEnd === false || $blockEnd >= $offset) {
            return true;
        }
    }
    return false;
}

function extractContext(string $body, int $offset, int $length, int $window = 120): string
{
    if ($body === '') {
        return '(formula not found)';
    }
    $start = max(0, $offset - $window);
    $end = min(strlen($body), $offset + $length + $window);
    $prefix = $start > 0 ? '…' : '';
    $suffix = $end < strlen($body) ? '…' : '';
    $slice = substr($body, $start, $end - $start);
    $rel = $offset - $start;
    $marked = substr($slice, 0, $rel) . '>>>' . substr($slice, $rel, $length) . '<<<' . substr($slice, $rel + $length);
    return $prefix . $marked . $suffix;
}

/**
 * @param array<string, true> $dataSources
 * @param array<string, true> $collections
 */
function classifyPattern(array $finding, string $body, int $offset, array $dataSources, array $collections): string
{
    $fn = (string) ($finding['messageArgs'][0] ?? '');
    $rule = $finding['ruleId'];
    $prop = $finding['property'];

    if ($rule === 'app-SuggestRemoteExecutionHint-InOpRhs') {
        return 'in operator (non-delegable RHS)';
    }

    $local = extractLocalExpression($body, $offset, strlen((string) $finding['snippet']));

    if ($fn === 'CountIf') {
        if (preg_match('/CountIf\s*\(\s*([^\s,]+)/i', $local, $m)) {
            $target = trim($m[1], "'\"");
            if (isset($collections[$target]) || str_starts_with(strtolower($target), 'col')) {
                return 'CountIf on local collection';
            }
            if (isset($dataSources[$target]) || preg_match("/^'[^']+'$/", $m[1])) {
                return 'CountIf on SharePoint/datasource';
            }
        }
        return 'CountIf (unknown target)';
    }

    if (in_array($fn, ['Lower', 'Upper', 'Trim'], true)) {
        if (preg_match('/Filter\s*\(/i', $local)) {
            return "Lower/Trim in Filter predicate";
        }
        if (preg_match('/LookUp\s*\(/i', $local)) {
            return "Lower/Trim in LookUp predicate";
        }
        if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\([^)]*\)\s*=\s*\b' . preg_quote($fn, '/') . '\s*\(/i', $local)) {
            return "Lower(x)=Lower(y) case-insensitive compare";
        }
        if (preg_match('/Gallery|\.Items|\.Default/i', $finding['location'])) {
            return "Lower/Trim on control Items/Default";
        }
        if (preg_match('/OnVisible|OnSelect|OnChange/i', $finding['location'])) {
            return "Lower/Trim in event handler (validation/patch)";
        }
        return "Lower/Trim (other)";
    }

    if (in_array($fn, ['StartsWith', 'EndsWith', 'Right', 'Left', 'Mid', 'Substitute', 'Find', 'Search'], true)) {
        if (preg_match('/Filter\s*\(/i', $local)) {
            return "{$fn} in Filter predicate";
        }
        if (preg_match('/LookUp\s*\(/i', $local)) {
            return "{$fn} in LookUp predicate";
        }
        if (preg_match('/\.Items\b/i', $finding['location'])) {
            return "{$fn} on gallery Items";
        }
        if (preg_match('/Patch|SubmitForm|PDF|savePDF/i', $local)) {
            return "{$fn} in PDF/export or Patch helper";
        }
        return "{$fn} (other)";
    }

    return "{$fn} (unclassified)";
}

/**
 * @param array<string, true> $dataSources
 * @param array<string, true> $collections
 */
function classifySourceKind(string $body, int $offset, array $dataSources, array $collections): string
{
    $local = extractLocalExpression($body, $offset, 5);
    foreach (array_keys($dataSources) as $ds) {
        $quoted = "'" . str_replace("'", "''", $ds) . "'";
        if (str_contains($local, $quoted) || str_contains($local, $ds)) {
            return 'SharePoint datasource';
        }
    }
    foreach (array_keys($collections) as $col) {
        if (preg_match('/\b' . preg_quote($col, '/') . '\b/', $local)) {
            return 'local collection';
        }
    }
    if (preg_match("/'[^']+'/", $local)) {
        return 'SharePoint datasource (quoted list name)';
    }
    if (preg_match('/\bcol[A-Z]\w*/', $local)) {
        return 'local collection';
    }
    if (preg_match('/\.(Text|Selected|Value|Default)\b/', $local)) {
        return 'control property';
    }
    return 'mixed/unknown';
}

function extractLocalExpression(string $body, int $offset, int $length): string
{
    // Walk outward to nearest statement boundary or semicolon
    $start = $offset;
    $end = $offset + $length;
    $depth = 0;
    for ($i = $offset; $i >= 0; $i--) {
        $ch = $body[$i] ?? '';
        if ($ch === ')') {
            $depth++;
        } elseif ($ch === '(') {
            if ($depth === 0) {
                $start = $i;
                break;
            }
            $depth--;
        } elseif ($ch === ';' && $depth === 0) {
            $start = $i + 1;
            break;
        }
    }
    $depth = 0;
    $len = strlen($body);
    for ($i = $offset + $length; $i < $len; $i++) {
        $ch = $body[$i];
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            if ($depth === 0) {
                $end = $i + 1;
                break;
            }
            $depth--;
        } elseif ($ch === ';' && $depth === 0) {
            $end = $i;
            break;
        }
    }
    return trim(substr($body, $start, $end - $start));
}

/**
 * @return array{suggestion:string, safe:bool, note:string}
 */
function suggestAutoFix(string $pattern, string $context, string $body, string $sourceKind, bool $inComment): array
{
    if ($inComment) {
        return [
            'suggestion' => 'Remove dead commented code containing non-delegable functions',
            'safe' => true,
            'note' => 'Comment-only; not executed at runtime',
        ];
    }
    if ($pattern === 'CountIf on local collection') {
        return [
            'suggestion' => 'Remove CountIf wrapper — collection is in-memory; use direct comparison or CountRows',
            'safe' => true,
            'note' => 'CountIf on col* never delegates; checker fires on function name only',
        ];
    }
    if ($pattern === 'Lower/Trim in Filter predicate' && $sourceKind === 'SharePoint datasource') {
        return [
            'suggestion' => 'Cannot auto-fix Filter+Lower on SharePoint — needs indexed column or client-side Collect',
            'safe' => false,
            'note' => 'Only safe if datasource is actually a local collection',
        ];
    }
    if ($pattern === 'Lower(x)=Lower(y) case-insensitive compare') {
        return [
            'suggestion' => 'Replace Lower(a)=Lower(b) with a=b for email fields (emails are case-insensitive by RFC)',
            'safe' => true,
            'note' => 'Safe when both sides are email addresses',
        ];
    }
    if (str_contains($pattern, 'Lower/Trim on control Items/Default') && $sourceKind === 'local collection') {
        return [
            'suggestion' => 'Remove unnecessary Lower/Trim on collection field in Items/Default',
            'safe' => true,
            'note' => 'Collection data is already normalized or case-sensitive match not needed',
        ];
    }
    if (str_contains($pattern, 'Lower/Trim in event handler')) {
        if (preg_match('/Lower\s*\(\s*Trim\s*\(/i', $context) && str_contains($sourceKind, 'control')) {
            return [
                'suggestion' => 'Keep Trim/Lower for user input normalization before Patch — not a delegation issue in practice if target is single record',
                'safe' => false,
                'note' => 'Warning is heuristic; formula may not hit datasource Filter',
            ];
        }
        if (preg_match('/Lower\s*\([^)]+\)\s*=\s*Lower\s*\(/i', $context)) {
            return [
                'suggestion' => 'Replace Lower(x)=Lower(y) with x=y for email comparison',
                'safe' => true,
                'note' => '',
            ];
        }
    }
    if ($pattern === 'in operator (non-delegable RHS)') {
        return [
            'suggestion' => 'Rewrite `x in Table` to `!IsBlank(LookUp(Table, ...))` or use delegable Or/And',
            'safe' => false,
            'note' => 'Depends on Table size and whether LookUp can delegate',
        ];
    }
    if (str_contains($pattern, 'PDF/export')) {
        return [
            'suggestion' => 'No fix needed — Substitute/Right used for string formatting, not delegation',
            'safe' => false,
            'note' => 'False positive from checker scanning entire OnSelect',
        ];
    }
    if (str_contains($pattern, 'on gallery Items') && $sourceKind === 'SharePoint datasource') {
        if (preg_match('/StartsWith|Substitute/i', $pattern)) {
            return [
                'suggestion' => 'Move search to delegable Search() or pre-filter with StartsWith on indexed column',
                'safe' => false,
                'note' => 'Substitute on Items may be for display formatting only',
            ];
        }
    }
    return [
        'suggestion' => 'Manual review required',
        'safe' => false,
        'note' => '',
    ];
}

/**
 * @param list<array<string, mixed>> $findings
 * @return array<string, array<string, mixed>>
 */
function groupFindings(array $findings): array
{
    $groups = [];
    foreach ($findings as $f) {
        $key = $f['pattern'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'count' => 0,
                'sourceKind' => $f['sourceKind'],
                'autoFix' => formatAutoFix($f['autoFix']),
                'exampleContext' => $f['context'],
                'exampleFix' => $f['autoFix']['suggestion'] ?? '',
                'locations' => [],
                'triggerFuncs' => [],
            ];
        }
        $groups[$key]['count']++;
        $groups[$key]['locations'][] = $f['location'] . ' [' . $f['triggerFunc'] . ']';
        $groups[$key]['triggerFuncs'][$f['triggerFunc']] = ($groups[$key]['triggerFuncs'][$f['triggerFunc']] ?? 0) + 1;
        // Prefer SharePoint example if we only had unknown before
        if ($f['sourceKind'] === 'SharePoint datasource' && $groups[$key]['sourceKind'] === 'mixed/unknown') {
            $groups[$key]['sourceKind'] = $f['sourceKind'];
            $groups[$key]['exampleContext'] = $f['context'];
        }
    }
    uasort($groups, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
    return $groups;
}

/** @param array{suggestion:string, safe:bool, note:string} $fix */
function formatAutoFix(array $fix): string
{
    $safe = $fix['safe'] ? 'YES' : 'NO';
    $note = $fix['note'] !== '' ? " ({$fix['note']})" : '';
    return "[{$safe}] {$fix['suggestion']}{$note}";
}

/**
 * @param list<array<string, mixed>> $findings
 * @return list<array<string, mixed>>
 */
function dedupeTemplates(array $findings): array
{
    $templates = [];
    foreach ($findings as $f) {
        $expr = extractLocalExpression(
            ltrim(trim((string) ($f['formula'] ?? '')), '='),
            (int) $f['charOffset'],
            (int) $f['charLength']
        );
        $expr = preg_replace('/\s+/', ' ', $expr) ?? $expr;
        $key = ($f['pattern'] ?? '') . '|' . $expr;
        if (!isset($templates[$key])) {
            $templates[$key] = [
                'pattern' => $f['pattern'],
                'expression' => $expr,
                'sourceKind' => $f['sourceKind'],
                'autoFix' => $f['autoFix'],
                'inComment' => $f['inComment'],
                'count' => 0,
                'locations' => [],
            ];
        }
        $templates[$key]['count']++;
        $templates[$key]['locations'][] = $f['location'];
    }
    uasort($templates, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
    return array_values($templates);
}
