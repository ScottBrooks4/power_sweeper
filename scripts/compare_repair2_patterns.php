#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\AppControlCatalog;
use PowerSweeper\FormulaLocaleNormalizer;
use PowerSweeper\FormulaReferenceExtractor;
use PowerSweeper\MsappArchive;
use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\StudioErrorDetector;
use PowerSweeper\StudioLiveChecker;
use PowerSweeper\StudioPostRepairValidator;

$beforePath = $argv[1] ?? 'samples/import_debug/CDLS (L) VCR App repair2.msapp';
$afterPath = $argv[2] ?? 'samples/import_debug/CDLS_L_VCR_App_repair2.powered.msapp';

function loadFormulas(string $msappPath): array
{
    $archive = new MsappArchive($msappPath);
    $archive->unpack();
    $formulas = [];
    foreach ($archive->documents() as $doc) {
        $doc->transformFormulas(static function (string $formula, string $path) use (&$formulas): string {
            $formulas[$path] = $formula;
            return $formula;
        });
    }
    $docs = $archive->documents();
    $extractDir = $archive->extractDir();
    $archive->cleanup();
    return [$formulas, $docs, $extractDir];
}

echo "=== SUMMARY COUNTS ===\n\n";
foreach ([$beforePath, $afterPath] as $path) {
    echo basename($path) . ":\n";
    $embedded = StudioErrorDetector::detectFromMsapp($path, false);
    $archive = new MsappArchive($path);
    $archive->unpack();
    $live = StudioLiveChecker::check($archive->documents(), ['extract_dir' => $archive->extractDir()]);
    $post = StudioPostRepairValidator::validate($archive->documents(), ['extract_dir' => $archive->extractDir()]);
    $archive->cleanup();
    echo "  Embedded SARIF total: {$embedded['total']} (formulas: " . ($embedded['by_category']['formulas'] ?? 0) . ")\n";
    echo "  Live checker total:   {$live['total']} (formulas: " . ($live['by_category']['formulas'] ?? 0) . ")\n";
    echo "  Post-repair validator: {$post['total']} (formulas: " . ($post['by_category']['formulas'] ?? 0) . ")\n";
    if (($live['by_rule'] ?? []) !== []) {
        echo "  Live by rule:\n";
        foreach ($live['by_rule'] as $rule => $count) {
            echo "    $count  $rule\n";
        }
    }
    echo "\n";
}

[$beforeFormulas, $beforeDocs] = array_slice(loadFormulas($beforePath), 0, 2);
[$afterFormulas] = loadFormulas($afterPath);

$changed = 0;
$unchangedProblematic = [];

$patterns = [
    'triple_quote' => static fn(string $c): bool => str_contains($c, "'''"),
    'double_qualified_screen' => static fn(string $c): bool => (bool) preg_match("/'[^']+'\.'[^']+'\./", str_replace("''", "'", $c)),
    'locale_semicolon' => static fn(string $c): bool => FormulaLocaleNormalizer::looksLocaleCorrupted($c),
    'stale_suffix_ref' => static fn(string $c): bool => (bool) preg_match('/\b[A-Za-z_][\w]*_\d+\b/', $c),
    'typo_initiave' => static fn(string $c): bool => str_contains($c, 'Initiave'),
    'typo_leveLTopSecret' => static fn(string $c): bool => str_contains($c, 'LeveLTopSecret'),
    'restricted0' => static fn(string $c): bool => str_contains($c, 'Restricted0'),
    'screen_date_call' => static fn(string $c): bool => (bool) preg_match("/'[^']+'\.Date\s*\(/", $c),
    'varNewRequest' => static fn(string $c): bool => str_contains($c, 'varNewRequest'),
    'loadedRequest_ghost' => static fn(string $c): bool => (bool) preg_match('/\bloadedRequest\.(OneTimeVisit|AmendmentVisit|LevelSecret|PertinentTo)/', $c),
    'cross_screen_navigate' => static fn(string $c): bool => (bool) preg_match("/Navigate\s*\(\s*'[^']+'\.'[^']+'/", $c),
];

$patternCountsBefore = array_fill_keys(array_keys($patterns), 0);
$patternCountsAfter = array_fill_keys(array_keys($patterns), 0);
$patternExamplesBefore = [];
$patternExamplesAfter = [];

foreach ($beforeFormulas as $path => $formula) {
    $parts = PowerFxFormulaSegments::splitForStructure($formula);
    foreach ($parts as [$type, $text]) {
        if ($type !== 'code') {
            continue;
        }
        foreach ($patterns as $name => $fn) {
            if ($fn($text)) {
                $patternCountsBefore[$name]++;
                if (!isset($patternExamplesBefore[$name])) {
                    $patternExamplesBefore[$name] = ['path' => $path, 'snippet' => substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 140)];
                }
            }
        }
    }
}

foreach ($afterFormulas as $path => $formula) {
    $parts = PowerFxFormulaSegments::splitForStructure($formula);
    foreach ($parts as [$type, $text]) {
        if ($type !== 'code') {
            continue;
        }
        foreach ($patterns as $name => $fn) {
            if ($fn($text)) {
                $patternCountsAfter[$name]++;
                if (!isset($patternExamplesAfter[$name])) {
                    $patternExamplesAfter[$name] = ['path' => $path, 'snippet' => substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 140)];
                }
            }
        }
    }
}

echo "=== PATTERN COUNTS (active code segments) ===\n\n";
printf("%-28s %8s %8s\n", 'Pattern', 'Before', 'After');
foreach ($patternCountsBefore as $name => $beforeCount) {
    $afterCount = $patternCountsAfter[$name];
    if ($beforeCount > 0 || $afterCount > 0) {
        printf("%-28s %8d %8d\n", $name, $beforeCount, $afterCount);
    }
}

echo "\n=== REMAINING AFTER POWERED (examples) ===\n\n";
foreach ($patternCountsAfter as $name => $count) {
    if ($count === 0) {
        continue;
    }
    $ex = $patternExamplesAfter[$name] ?? null;
    echo "$name ($count segments)\n";
    if ($ex) {
        echo "  {$ex['path']}\n";
        echo "  {$ex['snippet']}\n";
    }
    echo "\n";
}

echo "=== STILL IN BEFORE BUT FIXED IN AFTER (top examples) ===\n\n";
foreach ($patternCountsBefore as $name => $beforeCount) {
    $afterCount = $patternCountsAfter[$name];
    if ($beforeCount > 0 && $afterCount === 0) {
        $ex = $patternExamplesBefore[$name] ?? null;
        echo "$name: $beforeCount -> 0\n";
        if ($ex) {
            echo "  was: {$ex['path']}\n";
            echo "  {$ex['snippet']}\n";
        }
        echo "\n";
    }
}

// Embedded SARIF formula issues on BEFORE only
echo "=== EMBEDDED SARIF FORMULA ISSUES (before, grouped by rule+snippet) ===\n\n";
$embedded = StudioErrorDetector::detectFromMsapp($beforePath, false);
$groups = [];
foreach ($embedded['issues'] as $issue) {
    if ($issue['category'] !== 'formulas') {
        continue;
    }
    $key = $issue['ruleId'] . '|' . ($issue['snippet'] ?: $issue['root_cause']);
    $groups[$key] = ($groups[$key] ?? 0) + 1;
}
arsort($groups);
$i = 0;
foreach ($groups as $key => $count) {
    echo "$count  $key\n";
    if (++$i >= 25) {
        break;
    }
}

// Unresolved control refs in BEFORE via post-repair validator
echo "\n=== UNRESOLVED CONTROL REFS (before, top) ===\n\n";
$postBefore = StudioPostRepairValidator::validate($beforeDocs);
$refGroups = [];
foreach ($postBefore['issues'] as $issue) {
    if ($issue['kind'] !== 'unresolved_control_ref') {
        continue;
    }
    $refGroups[$issue['detail']] = ($refGroups[$issue['detail']] ?? 0) + 1;
}
arsort($refGroups);
$i = 0;
foreach ($refGroups as $ref => $count) {
    echo "$count  $ref\n";
    if (++$i >= 20) {
        break;
    }
}
