#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fit estimate_model.json using sample-nearest scaling from benchmark runs.
 *
 * Usage:
 *   php scripts/fit_estimate_model.php [storage/benchmarks/estimate_runs_….json]
 */

$root = dirname(__DIR__);
$src = $argv[1] ?? null;
if ($src === null) {
    $files = glob($root . '/storage/benchmarks/estimate_runs_*.json') ?: [];
    rsort($files);
    $src = $files[0] ?? null;
}
if (!is_string($src) || !is_file($src)) {
    fwrite(STDERR, "No runs JSON found\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($src), true);
$runs = $data['runs'] ?? null;
if (!is_array($runs) || $runs === []) {
    fwrite(STDERR, "No runs in {$src}\n");
    exit(1);
}

$signalWorkload = static function (string $id, array $signals, int $changes, int $controls): int {
    $fromSignals = match ($id) {
        'enable_dark_mode' => (int) ($signals['opaque_colors'] ?? 0)
            + (int) ($signals['modern_themeable_controls'] ?? 0)
            + (int) ($signals['white_container_fills'] ?? 0),
        'accessibility_labels' => (int) ($signals['missing_accessible_label'] ?? 0),
        'tooltip_from_label' => (int) ($signals['missing_tooltip'] ?? 0),
        'ensure_tab_index' => (int) ($signals['missing_tab_index'] ?? 0),
        'ensure_focus_visible' => (int) ($signals['missing_focus_border'] ?? 0),
        'unwhack_locale_formulas' => (int) ($signals['locale_hits'] ?? 0),
        'meaningful_names' => (int) ($signals['generic_names'] ?? 0),
        'translate' => (int) round(((int) ($signals['literal_texts'] ?? 0)) * 0.35),
        'normalize_containers', 'strip_default_fill' => (int) ($signals['container_chrome'] ?? 0)
            + (int) ($signals['white_container_fills'] ?? 0),
        'prefer_classic_theme_controls' => (int) ($signals['modern_themeable_controls'] ?? 0),
        'repair_control_refs', 'repair_context_aware_refs', 'repair_double_qualified_refs',
        'repair_converge_formulas', 'repair_ghost_patch_fields', 'repair_sharepoint_fields',
        'repair_studio_syntax', 'repair_delegation', 'repair_maintainability' => max(
            $changes,
            (int) ($signals['formula_errors'] ?? 0),
            (int) round($controls * 0.08)
        ),
        'regenerate_sarif', 'analyze_app_checker', 'scan_studio_issues' => max(1, $controls),
        default => max($changes, (int) round($controls * 0.02), 1),
    };

    return max(1, $fromSignals);
};

$isHeavy = static function (string $id): bool {
    return str_starts_with($id, 'repair_')
        || in_array($id, ['regenerate_sarif', 'analyze_app_checker', 'scan_studio_issues', 'enable_dark_mode'], true);
};

$fitLine = static function (array $pts): array {
    $n = count($pts);
    if ($n === 0) {
        return ['a' => 60.0, 'b' => 100.0];
    }
    if ($n === 1) {
        $x = max(0.05, $pts[0]['x']);
        return ['a' => max(10.0, $pts[0]['y'] * 0.2), 'b' => max(5.0, $pts[0]['y'] * 0.8 / $x)];
    }
    $sx = $sy = $sxx = $sxy = 0.0;
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

$predictFromSamples = static function (array $samples, int $controls, float $mb, int $workload, bool $heavy): float {
    if ($samples === []) {
        return 900.0;
    }
    $preds = [];
    $weights = [];
    foreach ($samples as $s) {
        $sc = max(1, (int) $s['controls']);
        $sm = max(0.01, (float) $s['file_mb']);
        $sw = max(1, (int) $s['workload']);
        $ratio = 0.42 * ($controls / $sc) + 0.28 * ($mb / $sm) + 0.30 * ($workload / $sw);
        $ratio = max(0.06, min(5.5, $ratio));
        if ($heavy) {
            $ratio = pow($ratio, 1.2);
        }
        $pred = max(1.0, (float) $s['duration_ms']) * $ratio;
        $w = 1.0 / (0.12 + abs(log(max(0.05, $ratio))));
        $preds[] = $pred;
        $weights[] = $w;
    }
    $num = 0.0;
    $den = 0.0;
    foreach ($preds as $i => $p) {
        $num += $p * $weights[$i];
        $den += $weights[$i];
    }

    return $den > 0 ? $num / $den : $preds[0];
};

$unpackPts = [];
$packPts = [];
$byHop = [];
foreach ($runs as $run) {
    $mb = max(0.01, ((int) ($run['file_bytes'] ?? 0)) / 1048576);
    if (isset($run['unpack_ms'])) {
        $unpackPts[] = ['x' => $mb, 'y' => (float) $run['unpack_ms']];
    }
    if (isset($run['pack_ms'])) {
        $packPts[] = ['x' => $mb, 'y' => (float) $run['pack_ms']];
    }
    $controls = max(1, (int) ($run['complexity']['control_count'] ?? 1));
    $signals = is_array($run['signals'] ?? null) ? $run['signals'] : [];
    foreach ($run['hops'] as $hopRow) {
        $id = (string) ($hopRow['hop'] ?? '');
        if ($id === '') {
            continue;
        }
        $changes = (int) ($hopRow['changes'] ?? 0);
        $workload = $signalWorkload($id, $signals, $changes, $controls);
        $byHop[$id][] = [
            'duration_ms' => max(1, (int) ($hopRow['duration_ms'] ?? 0)),
            'changes' => $changes,
            'controls' => $controls,
            'file_mb' => round($mb, 4),
            'workload' => $workload,
            'app' => $run['app'],
        ];
    }
}

$unpackFit = $fitLine($unpackPts);
$packFit = $fitLine($packPts);

$hopModels = [];
foreach ($byHop as $id => $samples) {
    $hopModels[$id] = [
        'heavy' => $isHeavy($id),
        'samples' => $samples,
        'avg_ms' => (int) round(array_sum(array_column($samples, 'duration_ms')) / count($samples)),
    ];
}

$model = [
    'version' => 6,
    'generated_at' => gmdate('c'),
    'source_runs' => basename($src),
    'overhead_ms' => 280,
    'unpack' => [
        'fixed_ms' => (int) round($unpackFit['a']),
        'per_mb_ms' => round($unpackFit['b'], 3),
    ],
    'pack' => [
        'fixed_ms' => (int) round($packFit['a']),
        'per_mb_ms' => round($packFit['b'], 3),
    ],
    'default_hop' => [
        'heavy' => false,
        'samples' => [
            ['duration_ms' => 400, 'controls' => 500, 'file_mb' => 1.5, 'workload' => 200, 'changes' => 50, 'app' => 'default'],
            ['duration_ms' => 1800, 'controls' => 2000, 'file_mb' => 5.0, 'workload' => 1200, 'changes' => 400, 'app' => 'default'],
        ],
        'avg_ms' => 900,
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
        'prefer_classic_theme_controls' => ['modern_themeable_controls'],
    ],
    'force_multiplier' => 1.12,
];

$errors = [];
foreach ($runs as $run) {
    $mb = max(0.01, ((int) $run['file_bytes']) / 1048576);
    $controls = max(1, (int) ($run['complexity']['control_count'] ?? 1));
    $signals = $run['signals'] ?? [];
    $est = $model['overhead_ms']
        + $model['unpack']['fixed_ms'] + $model['unpack']['per_mb_ms'] * $mb
        + $model['pack']['fixed_ms'] + $model['pack']['per_mb_ms'] * $mb;
    foreach ($run['hops'] as $hopRow) {
        $id = $hopRow['hop'];
        $h = $model['hops'][$id] ?? $model['default_hop'];
        $workload = $signalWorkload($id, $signals, (int) $hopRow['changes'], $controls);
        // Exclude the same app sample for a leave-one-app-out style check when possible.
        $samples = array_values(array_filter(
            $h['samples'],
            static fn ($s) => ($s['app'] ?? '') !== $run['app']
        ));
        if ($samples === []) {
            $samples = $h['samples'];
        }
        $est += $predictFromSamples($samples, $controls, $mb, $workload, (bool) ($h['heavy'] ?? false));
    }
    $actual = max(1, (int) $run['elapsed_ms']);
    $errors[] = [
        'app' => $run['app'],
        'actual_ms' => (int) $run['elapsed_ms'],
        'est_ms' => (int) round($est),
        'ratio' => round($est / $actual, 2),
    ];
}

$json = json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
foreach ([
    $root . '/config/estimate_model.json',
    $root . '/public/assets/estimate_model.json',
    $root . '/assets/estimate_model.json',
] as $path) {
    file_put_contents($path, $json);
    fwrite(STDERR, "Wrote {$path}\n");
}

echo json_encode(['source' => basename($src), 'validation_leave_one_app_out' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
