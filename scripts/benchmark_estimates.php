#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Record real hop timings across sample apps and fit estimate_model.json.
 *
 * Usage:
 *   php scripts/benchmark_estimates.php [--apps=kitchen,locale,tdr,pacs,vcr,thcee]
 *                                       [--write]
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\AppComplexity;
use PowerSweeper\HopAdvisor;
use PowerSweeper\Pipeline;

$apps = [
    'kitchen' => dirname(__DIR__) . '/samples/dark_mode_kitchen_sink/dark_mode_kitchen_sink.msapp',
    'locale' => dirname(__DIR__) . '/samples/locale_german_corrupt/locale_german_corrupt.msapp',
    'tdr' => dirname(__DIR__) . '/samples/import_debug/TDR - THCEE Directory App.msapp',
    'pacs' => dirname(__DIR__) . '/samples/import_debug/VCDS ASC - Pass Accountability and Control System (PACS).msapp',
    'vcr' => dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App repair2.msapp',
    'thcee' => dirname(__DIR__) . '/samples/import_debug/VCDS — THCEE.msapp',
];

$selected = array_keys($apps);
$write = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--apps=')) {
        $selected = array_values(array_filter(explode(',', substr($arg, 7))));
    }
    if ($arg === '--write') {
        $write = true;
    }
}

$outDir = dirname(__DIR__) . '/storage/benchmarks';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

$pipeline = new Pipeline();
$advisor = new HopAdvisor();
$runs = [];
$stamp = date('Ymd_His');

foreach ($selected as $appId) {
    if (!isset($apps[$appId])) {
        fwrite(STDERR, "Unknown app: {$appId}\n");
        continue;
    }
    $path = $apps[$appId];
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file for {$appId}: {$path}\n");
        continue;
    }

    fwrite(STDERR, "=== {$appId} (" . round(filesize($path) / 1048576, 2) . " MB) — analyzing…\n");
    $plan = $advisor->recommend($path);
    $hops = $plan['hops'] ?? [];
    $complexity = $plan['complexity'] ?? [];
    $signals = $plan['signals'] ?? [];
    if ($hops === []) {
        // Still measure unpack/pack with a no-op configure hop if present, else skip.
        $hops = [['id' => 'set_zip_path_style', 'options' => []]];
        fwrite(STDERR, "  no actionable hops — measuring package overhead with set_zip_path_style\n");
    } else {
        fwrite(STDERR, '  plan: ' . implode(', ', array_map(static fn ($h) => $h['id'], $hops)) . "\n");
    }

    $outPath = $outDir . '/' . $appId . '_' . $stamp . '.msapp';
    $events = [];
    $t0 = microtime(true);
    try {
        $result = $pipeline->run($path, $hops, $outPath, static function (array $ev) use (&$events): void {
            $events[] = $ev;
            if (($ev['type'] ?? '') === 'hop_done' || (($ev['type'] ?? '') === 'phase' && in_array($ev['phase'] ?? '', ['unpack_done', 'pack_done'], true))) {
                $label = $ev['hop'] ?? ($ev['phase'] ?? 'phase');
                $ms = $ev['duration_ms'] ?? '?';
                $ch = $ev['changes'] ?? '';
                fwrite(STDERR, "  {$label}: {$ms}ms" . ($ch !== '' ? " ({$ch} changes)" : '') . "\n");
            }
        });
    } catch (Throwable $e) {
        fwrite(STDERR, "  FAILED: " . $e->getMessage() . "\n");
        @unlink($outPath);
        continue;
    }

    $unpack = null;
    $pack = null;
    $hopRows = [];
    $prevCount = 0;
    foreach ($events as $ev) {
        if (($ev['type'] ?? '') === 'phase' && ($ev['phase'] ?? '') === 'unpack_done') {
            $unpack = (int) ($ev['duration_ms'] ?? 0);
            if (isset($ev['complexity']) && is_array($ev['complexity'])) {
                $complexity = $ev['complexity'];
            }
        }
        if (($ev['type'] ?? '') === 'phase' && ($ev['phase'] ?? '') === 'pack_done') {
            $pack = (int) ($ev['duration_ms'] ?? 0);
        }
        if (($ev['type'] ?? '') === 'hop_done') {
            $count = (int) ($ev['count'] ?? 0);
            $changes = isset($ev['changes']) ? (int) $ev['changes'] : max(0, $count - $prevCount);
            $prevCount = $count;
            $hopRows[] = [
                'hop' => (string) ($ev['hop'] ?? ''),
                'duration_ms' => (int) ($ev['duration_ms'] ?? 0),
                'changes' => $changes,
            ];
        }
    }

    $runs[] = [
        'app' => $appId,
        'path' => basename($path),
        'file_bytes' => (int) filesize($path),
        'elapsed_ms' => (int) ($result['elapsed_ms'] ?? round((microtime(true) - $t0) * 1000)),
        'report_total' => (int) ($result['report']['total'] ?? 0),
        'complexity' => $complexity,
        'signals' => [
            'opaque_colors' => (int) ($signals['opaque_colors'] ?? 0),
            'modern_themeable_controls' => (int) ($signals['modern_themeable_controls'] ?? 0),
            'missing_accessible_label' => (int) ($signals['missing_accessible_label'] ?? 0),
            'missing_tooltip' => (int) ($signals['missing_tooltip'] ?? 0),
            'missing_tab_index' => (int) ($signals['missing_tab_index'] ?? 0),
            'missing_focus_border' => (int) ($signals['missing_focus_border'] ?? 0),
            'locale_hits' => (int) ($signals['locale_hits'] ?? 0),
            'formula_errors' => (int) ($signals['formula_errors'] ?? 0),
            'literal_texts' => (int) ($signals['literal_texts'] ?? 0),
            'generic_names' => (int) ($signals['generic_names'] ?? 0),
            'container_chrome' => (int) ($signals['container_chrome'] ?? 0),
            'white_container_fills' => (int) ($signals['white_container_fills'] ?? 0),
        ],
        'unpack_ms' => $unpack,
        'pack_ms' => $pack,
        'hops' => $hopRows,
    ];
    @unlink($outPath);
}

/**
 * Simple least-squares: y ≈ a + b*x for package phases.
 * @param list<array{x:float,y:float}> $pts
 * @return array{a:float,b:float}
 */
$fitLine = static function (array $pts): array {
    $n = count($pts);
    if ($n === 0) {
        return ['a' => 0.0, 'b' => 0.0];
    }
    if ($n === 1) {
        return ['a' => $pts[0]['y'], 'b' => 0.0];
    }
    $sx = 0.0;
    $sy = 0.0;
    $sxx = 0.0;
    $sxy = 0.0;
    foreach ($pts as $p) {
        $sx += $p['x'];
        $sy += $p['y'];
        $sxx += $p['x'] * $p['x'];
        $sxy += $p['x'] * $p['y'];
    }
    $den = ($n * $sxx) - ($sx * $sx);
    if (abs($den) < 1e-9) {
        return ['a' => $sy / $n, 'b' => 0.0];
    }
    $b = (($n * $sxy) - ($sx * $sy)) / $den;
    $a = ($sy - $b * $sx) / $n;

    return ['a' => max(0.0, $a), 'b' => max(0.0, $b)];
};

$unpackPts = [];
$packPts = [];
foreach ($runs as $run) {
    $mb = max(0.01, $run['file_bytes'] / 1048576);
    if ($run['unpack_ms'] !== null) {
        $unpackPts[] = ['x' => $mb, 'y' => (float) $run['unpack_ms']];
    }
    if ($run['pack_ms'] !== null) {
        $packPts[] = ['x' => $mb, 'y' => (float) $run['pack_ms']];
    }
}
$unpackFit = $fitLine($unpackPts);
$packFit = $fitLine($packPts);

/** @var array<string, list<array{duration_ms:int,changes:int,controls:int,file_mb:float,formula_chars:int,workload:int}>> $byHop */
$byHop = [];
foreach ($runs as $run) {
    $controls = (int) ($run['complexity']['control_count'] ?? 0);
    $formulaChars = (int) ($run['complexity']['formula_chars'] ?? 0);
    $mb = max(0.01, $run['file_bytes'] / 1048576);
    $signals = $run['signals'];
    foreach ($run['hops'] as $hopRow) {
        $id = $hopRow['hop'];
        if ($id === '') {
            continue;
        }
        $workload = match ($id) {
            'enable_dark_mode' => max(1, $signals['opaque_colors'] + $signals['modern_themeable_controls'] + $signals['white_container_fills']),
            'accessibility_labels' => max(1, $signals['missing_accessible_label']),
            'tooltip_from_label' => max(1, $signals['missing_tooltip']),
            'ensure_tab_index' => max(1, $signals['missing_tab_index']),
            'ensure_focus_visible' => max(1, $signals['missing_focus_border']),
            'unwhack_locale_formulas' => max(1, $signals['locale_hits']),
            'meaningful_names' => max(1, $signals['generic_names']),
            'translate' => max(1, (int) round($signals['literal_texts'] * 0.35)),
            'normalize_containers', 'strip_default_fill' => max(1, $signals['container_chrome'] + $signals['white_container_fills']),
            default => max(1, $hopRow['changes'] > 0 ? $hopRow['changes'] : max(1, (int) round($controls * 0.02))),
        };
        $byHop[$id][] = [
            'duration_ms' => $hopRow['duration_ms'],
            'changes' => $hopRow['changes'],
            'controls' => $controls,
            'file_mb' => $mb,
            'formula_chars' => $formulaChars,
            'workload' => $workload,
        ];
    }
}

$hopModels = [];
foreach ($byHop as $id => $samples) {
    // duration ≈ fixed + per_control*controls + per_change*changes + per_mb*file_mb + per_workload*workload
    // Fit with averages / ratios (robust, small-n friendly).
    $n = count($samples);
    $avgMs = array_sum(array_column($samples, 'duration_ms')) / $n;
    $avgControls = max(1.0, array_sum(array_column($samples, 'controls')) / $n);
    $avgChanges = max(0.0, array_sum(array_column($samples, 'changes')) / $n);
    $avgMb = max(0.01, array_sum(array_column($samples, 'file_mb')) / $n);
    $avgWorkload = max(1.0, array_sum(array_column($samples, 'workload')) / $n);

    // Attribute shares of average duration.
    $perControl = $avgMs * 0.35 / $avgControls;
    $perChange = $avgChanges > 0 ? ($avgMs * 0.25 / $avgChanges) : ($avgMs * 0.05);
    $perMb = $avgMs * 0.15 / $avgMb;
    $perWorkload = $avgMs * 0.20 / $avgWorkload;
    $fixed = max(80.0, $avgMs * 0.05);

    // Re-scale so predicted mean ≈ observed mean.
    $pred = $fixed + $perControl * $avgControls + $perChange * $avgChanges + $perMb * $avgMb + $perWorkload * $avgWorkload;
    if ($pred > 1) {
        $scale = $avgMs / $pred;
        $fixed *= $scale;
        $perControl *= $scale;
        $perChange *= $scale;
        $perMb *= $scale;
        $perWorkload *= $scale;
    }

    $hopModels[$id] = [
        'fixed_ms' => (int) round($fixed),
        'per_control_ms' => round($perControl, 4),
        'per_change_ms' => round($perChange, 4),
        'per_mb_ms' => round($perMb, 4),
        'per_workload_ms' => round($perWorkload, 4),
        'samples' => $n,
        'avg_ms' => (int) round($avgMs),
    ];
}

$model = [
    'version' => 2,
    'generated_at' => gmdate('c'),
    'overhead_ms' => 500,
    'unpack' => [
        'fixed_ms' => (int) round($unpackFit['a']),
        'per_mb_ms' => round($unpackFit['b'], 3),
    ],
    'pack' => [
        'fixed_ms' => (int) round($packFit['a']),
        'per_mb_ms' => round($packFit['b'], 3),
    ],
    'default_hop' => [
        'fixed_ms' => 400,
        'per_control_ms' => 0.8,
        'per_change_ms' => 4.0,
        'per_mb_ms' => 40.0,
        'per_workload_ms' => 2.5,
    ],
    'hops' => $hopModels,
    'workload_from_signals' => [
        'enable_dark_mode' => ['opaque_colors', 'modern_themeable_controls', 'white_container_fills'],
        'accessibility_labels' => ['missing_accessible_label'],
        'tooltip_from_label' => ['missing_tooltip'],
        'ensure_tab_index' => ['missing_tab_index'],
        'ensure_focus_visible' => ['missing_focus_border'],
        'unwhack_locale_formulas' => ['locale_hits'],
        'meaningful_names' => ['generic_names'],
        'translate' => ['literal_texts'],
        'normalize_containers' => ['container_chrome', 'white_container_fills'],
        'strip_default_fill' => ['white_container_fills'],
    ],
    'force_multiplier' => 1.22,
    'runs' => $runs,
];

$rawPath = $outDir . '/estimate_runs_' . $stamp . '.json';
file_put_contents($rawPath, json_encode(['runs' => $runs, 'model' => $model], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDERR, "Wrote {$rawPath}\n");

if ($write) {
    $configPath = dirname(__DIR__) . '/config/estimate_model.json';
    $publicPath = dirname(__DIR__) . '/public/assets/estimate_model.json';
    $assetsPath = dirname(__DIR__) . '/assets/estimate_model.json';
    // Drop bulky runs from shipped model.
    $ship = $model;
    unset($ship['runs']);
    $json = json_encode($ship, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    foreach ([$configPath, $publicPath, $assetsPath] as $p) {
        $dir = dirname($p);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($p, $json);
        fwrite(STDERR, "Wrote {$p}\n");
    }
}

echo json_encode([
    'apps' => count($runs),
    'unpack' => $model['unpack'],
    'pack' => $model['pack'],
    'hops' => array_map(static fn ($h) => ['avg_ms' => $h['avg_ms'], 'samples' => $h['samples']], $hopModels),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
