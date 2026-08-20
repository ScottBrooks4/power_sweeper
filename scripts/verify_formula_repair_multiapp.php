#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Verify fix_formula_errors across diverse source apps (not powered/repaired copies).
 *
 * For each app: live formula-error count before → run Fix formula errors → count after.
 * Fails if any app regresses (after > before). Prints a table for generality review.
 *
 * Usage:
 *   php scripts/verify_formula_repair_multiapp.php
 *   php scripts/verify_formula_repair_multiapp.php path/to/one.msapp
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\MsappArchive;
use PowerSweeper\Pipeline;
use PowerSweeper\StudioLiveChecker;

/**
 * Canonical source apps used to prove formula repair is general.
 * Ignore powered / repaired / step / numbered copies.
 *
 * @return list<string>
 */
function formula_repair_source_apps(): array
{
    $dir = dirname(__DIR__) . '/samples/import_debug';
    $names = [
        'CDLS (L) VCR App (16).msapp',
        'CDLS (L) VCR App Friday.msapp',
        'CDLS (L) VCR App repair2.msapp',
        'TDR - THCEE Directory App.msapp',
        'Team Pulse.msapp',
        'VCDS ASC - Pass Accountability and Control System (PACS).msapp',
        'VCDS ASC —Template with Approvals.msapp',
        'VCDS ASC — The SAINT Catalog.msapp',
        'VCDS — THCEE Friday.msapp',
        'VCDS — THCEE.msapp',
    ];
    $out = [];
    foreach ($names as $name) {
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            $out[] = $path;
        }
    }

    return $out;
}

/**
 * @return array{total:int,formulas:int,by_rule:array<string,int>}
 */
function live_formula_stats(string $msappPath): array
{
    $arch = new MsappArchive($msappPath);
    try {
        $arch->unpack();
        $live = StudioLiveChecker::check($arch->documents(), ['extract_dir' => $arch->extractDir()]);
        $formulas = 0;
        $byRule = [];
        foreach ($live['by_rule'] ?? [] as $rule => $count) {
            $rule = (string) $rule;
            $count = (int) $count;
            if (str_starts_with($rule, 'app-Err') || str_starts_with($rule, 'app-formula')) {
                $formulas += $count;
                $byRule[$rule] = $count;
            }
        }

        return [
            'total' => (int) ($live['total'] ?? 0),
            'formulas' => $formulas,
            'by_rule' => $byRule,
        ];
    } finally {
        $arch->cleanup();
    }
}

$argvPaths = array_slice($argv, 1);
$apps = $argvPaths !== []
    ? array_values(array_filter($argvPaths, 'is_file'))
    : formula_repair_source_apps();

if ($apps === []) {
    fwrite(STDERR, "No source apps found under samples/import_debug.\n");
    exit(2);
}

echo "Formula repair multi-app verification (" . count($apps) . " apps)\n";
echo str_repeat('=', 78) . "\n";
printf("%-42s %8s %8s %8s %s\n", 'App', 'Before', 'After', 'Delta', 'Result');
echo str_repeat('-', 78) . "\n";

$failed = 0;
$results = [];

foreach ($apps as $input) {
    $label = basename($input);
    $short = mb_strlen($label) > 42 ? (mb_substr($label, 0, 39) . '...') : $label;
    $before = live_formula_stats($input);
    $out = sys_get_temp_dir() . '/ps_ffe_' . bin2hex(random_bytes(4)) . '.msapp';

    try {
        (new Pipeline())->run(
            $input,
            [['id' => 'fix_formula_errors', 'options' => []]],
            $out
        );
        $after = live_formula_stats($out);
    } catch (Throwable $e) {
        printf("%-42s %8d %8s %8s FAIL (%s)\n", $short, $before['formulas'], '—', '—', $e->getMessage());
        $failed++;
        $results[] = [
            'app' => $label,
            'before' => $before['formulas'],
            'after' => null,
            'ok' => false,
            'error' => $e->getMessage(),
        ];
        @unlink($out);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        continue;
    }

    $delta = $after['formulas'] - $before['formulas'];
    $ok = $after['formulas'] <= $before['formulas'];
    $tag = $ok
        ? ($delta < 0 ? 'improved' : ($before['formulas'] === 0 ? 'clean' : 'stable'))
        : 'REGRESSED';
    if (!$ok) {
        $failed++;
    }

    printf(
        "%-42s %8d %8d %8s %s\n",
        $short,
        $before['formulas'],
        $after['formulas'],
        ($delta > 0 ? '+' : '') . $delta,
        $tag
    );

    $results[] = [
        'app' => $label,
        'before' => $before['formulas'],
        'after' => $after['formulas'],
        'delta' => $delta,
        'ok' => $ok,
        'before_rules' => $before['by_rule'],
        'after_rules' => $after['by_rule'],
    ];

    @unlink($out);
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
}

echo str_repeat('=', 78) . "\n";
$improved = count(array_filter($results, static fn(array $r): bool => ($r['delta'] ?? 0) < 0));
$clean = count(array_filter($results, static fn(array $r): bool => ($r['before'] ?? -1) === 0 && ($r['after'] ?? -1) === 0));
echo sprintf(
    "Summary: %d apps, %d improved, %d already clean, %d failed/regressed\n",
    count($results),
    $improved,
    $clean,
    $failed
);

$reportPath = sys_get_temp_dir() . '/ps_ffe_multiapp_' . date('Ymd_His') . '.json';
file_put_contents($reportPath, json_encode(['results' => $results], JSON_PRETTY_PRINT));
echo "JSON report: {$reportPath}\n";

exit($failed > 0 ? 1 : 0);
