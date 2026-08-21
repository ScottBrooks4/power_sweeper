<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Shared runtime estimate model — updated from every successful live run.
 *
 * Samples are keyed by sweep id and scaled by controls / file size / workload
 * so estimates improve across many apps (not per-browser localStorage).
 */
final class EstimateModel
{
    public const VERSION = 7;
    public const MAX_SAMPLES_PER_SWEEP = 24;
    public const MAX_PHASE_SAMPLES = 48;
    public const MAX_LIVE_RUNS = 250;

    /**
     * @return list<string>
     */
    public static function modelPaths(?string $root = null): array
    {
        $root = $root ?? (defined('POWER_SWEEPER_ROOT') ? POWER_SWEEPER_ROOT : dirname(__DIR__));
        // Prefer writable storage copy first for live learning on shared hosts.
        return [
            self::storageDir($root) . '/estimate_model.json',
            $root . '/config/estimate_model.json',
            $root . '/public/assets/estimate_model.json',
            $root . '/assets/estimate_model.json',
        ];
    }

    /**
     * Paths that should receive live updates (best-effort).
     *
     * @return list<string>
     */
    public static function writableModelPaths(?string $root = null): array
    {
        $root = $root ?? (defined('POWER_SWEEPER_ROOT') ? POWER_SWEEPER_ROOT : dirname(__DIR__));

        return [
            self::storageDir($root) . '/estimate_model.json',
            $root . '/config/estimate_model.json',
            $root . '/public/assets/estimate_model.json',
            $root . '/assets/estimate_model.json',
        ];
    }

    public static function storageDir(?string $root = null): string
    {
        $root = $root ?? (defined('POWER_SWEEPER_ROOT') ? POWER_SWEEPER_ROOT : dirname(__DIR__));
        $dir = $root . '/storage/benchmarks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir;
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(?string $root = null): array
    {
        foreach (self::modelPaths($root) as $path) {
            if (!is_file($path)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return self::emptyModel();
    }

    /**
     * Build a run record from Pipeline NDJSON-style events.
     *
     * @param list<array<string, mixed>> $events
     * @param array{
     *   app?:string,
     *   file_bytes?:int,
     *   elapsed_ms?:int,
     *   signals?:array<string, mixed>,
     *   complexity?:array<string, mixed>
     * } $meta
     * @return array<string, mixed>|null
     */
    public static function buildRunFromEvents(array $events, array $meta = []): ?array
    {
        $unpackMs = null;
        $packMs = null;
        $complexity = is_array($meta['complexity'] ?? null) ? $meta['complexity'] : [];
        $sweepRows = [];
        $subRows = [];

        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $type = (string) ($ev['type'] ?? '');
            $phase = (string) ($ev['phase'] ?? '');
            if ($type === 'phase' && $phase === 'unpack_done') {
                $unpackMs = (int) ($ev['duration_ms'] ?? 0);
                if (isset($ev['complexity']) && is_array($ev['complexity'])) {
                    $complexity = $ev['complexity'];
                }
            }
            if ($type === 'phase' && $phase === 'pack_done') {
                $packMs = (int) ($ev['duration_ms'] ?? 0);
            }
            if ($type === 'sweep_done') {
                $id = (string) ($ev['sweep'] ?? '');
                if ($id === '') {
                    continue;
                }
                $duration = (int) ($ev['duration_ms'] ?? 0);
                if ($duration < 1) {
                    continue;
                }
                $sweepRows[] = [
                    'sweep' => $id,
                    'duration_ms' => $duration,
                    'changes' => max(0, (int) ($ev['changes'] ?? 0)),
                ];
            }
            if ($type === 'phase' && $phase === 'subsweep_done') {
                $id = (string) ($ev['subsweep'] ?? '');
                if ($id === '') {
                    continue;
                }
                $duration = (int) ($ev['duration_ms'] ?? 0);
                if ($duration < 1) {
                    continue;
                }
                $subRows[] = [
                    'sweep' => $id,
                    'duration_ms' => $duration,
                    'changes' => max(0, (int) ($ev['changes'] ?? 0)),
                ];
            }
        }

        if ($sweepRows === [] && $subRows === [] && $unpackMs === null && $packMs === null) {
            return null;
        }

        $fileBytes = (int) ($meta['file_bytes'] ?? ($complexity['file_bytes'] ?? 0));
        $signals = is_array($meta['signals'] ?? null) ? $meta['signals'] : [];

        return [
            'app' => self::sanitizeAppLabel((string) ($meta['app'] ?? 'live')),
            'file_bytes' => max(0, $fileBytes),
            'elapsed_ms' => max(0, (int) ($meta['elapsed_ms'] ?? 0)),
            'complexity' => $complexity,
            'signals' => self::normalizeSignals($signals),
            'unpack_ms' => $unpackMs,
            'pack_ms' => $packMs,
            'sweeps' => array_merge($sweepRows, $subRows),
            'recorded_at' => gmdate('c'),
        ];
    }

    /**
     * Append run history and merge samples into the shared estimate_model.json files.
     *
     * @param array<string, mixed> $run
     * @return array{ok:bool,reason?:string,model_version?:int,samples_added?:int,paths_written?:list<string>}
     */
    public static function recordRun(array $run, ?string $root = null): array
    {
        $root = $root ?? (defined('POWER_SWEEPER_ROOT') ? POWER_SWEEPER_ROOT : dirname(__DIR__));
        $dir = self::storageDir($root);
        if (!is_dir($dir) || !is_writable($dir)) {
            return ['ok' => false, 'reason' => 'benchmarks storage not writable'];
        }

        $historyPath = $dir . '/live_runs.jsonl';
        $line = json_encode($run, JSON_UNESCAPED_SLASHES);
        if (!is_string($line) || $line === '') {
            return ['ok' => false, 'reason' => 'could not encode run'];
        }

        $hf = @fopen($historyPath, 'ab');
        if ($hf === false) {
            return ['ok' => false, 'reason' => 'could not open live_runs.jsonl'];
        }
        try {
            flock($hf, LOCK_EX);
            fwrite($hf, $line . "\n");
        } finally {
            flock($hf, LOCK_UN);
            fclose($hf);
        }
        self::trimJsonl($historyPath, self::MAX_LIVE_RUNS);

        $lockPath = $dir . '/estimate_model.lock';
        $lf = @fopen($lockPath, 'c+');
        if ($lf === false) {
            return ['ok' => false, 'reason' => 'could not lock estimate model'];
        }

        try {
            flock($lf, LOCK_EX);
            $model = self::load($root);
            $added = self::mergeRunIntoModel($model, $run);
            self::refitPhases($model);
            $model['version'] = self::VERSION;
            $model['generated_at'] = gmdate('c');
            $model['source_runs'] = 'live_runs.jsonl';
            $model['live_run_count'] = self::countJsonl($historyPath);

            $json = json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            $written = [];
            foreach (self::writableModelPaths($root) as $path) {
                $parent = dirname($path);
                if (!is_dir($parent)) {
                    @mkdir($parent, 0777, true);
                }
                if (@file_put_contents($path, $json, LOCK_EX) !== false) {
                    $written[] = $path;
                }
            }
            if ($written === []) {
                return ['ok' => false, 'reason' => 'could not write estimate_model.json'];
            }

            return [
                'ok' => true,
                'model_version' => self::VERSION,
                'samples_added' => $added,
                'paths_written' => $written,
            ];
        } finally {
            flock($lf, LOCK_UN);
            fclose($lf);
        }
    }

    /**
     * @param array<string, mixed> $model
     * @param array<string, mixed> $run
     */
    public static function mergeRunIntoModel(array &$model, array $run): int
    {
        if (!isset($model['sweeps']) || !is_array($model['sweeps'])) {
            $model['sweeps'] = [];
        }
        if (!isset($model['phase_samples']) || !is_array($model['phase_samples'])) {
            $model['phase_samples'] = ['unpack' => [], 'pack' => []];
        }

        $mb = max(0.01, ((int) ($run['file_bytes'] ?? 0)) / 1048576.0);
        $controls = max(1, (int) ($run['complexity']['control_count'] ?? 1));
        $signals = is_array($run['signals'] ?? null) ? $run['signals'] : [];
        $app = self::sanitizeAppLabel((string) ($run['app'] ?? 'live'));
        $added = 0;

        if (isset($run['unpack_ms']) && is_numeric($run['unpack_ms'])) {
            $model['phase_samples']['unpack'][] = [
                'file_mb' => round($mb, 4),
                'duration_ms' => max(1, (int) $run['unpack_ms']),
                'app' => $app,
            ];
            $model['phase_samples']['unpack'] = array_slice(
                $model['phase_samples']['unpack'],
                -self::MAX_PHASE_SAMPLES
            );
        }
        if (isset($run['pack_ms']) && is_numeric($run['pack_ms'])) {
            $model['phase_samples']['pack'][] = [
                'file_mb' => round($mb, 4),
                'duration_ms' => max(1, (int) $run['pack_ms']),
                'app' => $app,
            ];
            $model['phase_samples']['pack'] = array_slice(
                $model['phase_samples']['pack'],
                -self::MAX_PHASE_SAMPLES
            );
        }

        $sweeps = is_array($run['sweeps'] ?? null) ? $run['sweeps'] : [];
        foreach ($sweeps as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['sweep'] ?? '');
            $duration = (int) ($row['duration_ms'] ?? 0);
            if ($id === '' || $duration < 40 || $duration >= 20 * 60 * 1000) {
                continue;
            }
            $changes = max(0, (int) ($row['changes'] ?? 0));
            $workload = self::workloadFor($id, $signals, $changes, $controls);
            if (!isset($model['sweeps'][$id]) || !is_array($model['sweeps'][$id])) {
                $model['sweeps'][$id] = [
                    'heavy' => self::isHeavy($id),
                    'samples' => [],
                    'avg_ms' => $duration,
                ];
            }
            $entry = &$model['sweeps'][$id];
            $entry['heavy'] = self::isHeavy($id) || !empty($entry['heavy']);
            if (!isset($entry['samples']) || !is_array($entry['samples'])) {
                $entry['samples'] = [];
            }
            $entry['samples'][] = [
                'duration_ms' => $duration,
                'changes' => $changes,
                'controls' => $controls,
                'file_mb' => round($mb, 4),
                'workload' => $workload,
                'app' => $app,
            ];
            $entry['samples'] = array_slice($entry['samples'], -self::MAX_SAMPLES_PER_SWEEP);
            $durs = array_map(static fn ($s) => (int) ($s['duration_ms'] ?? 0), $entry['samples']);
            $entry['avg_ms'] = (int) round(array_sum($durs) / max(1, count($durs)));
            unset($entry);
            $added++;
        }

        if (!isset($model['overhead_ms'])) {
            $model['overhead_ms'] = 280;
        }
        if (!isset($model['force_multiplier'])) {
            $model['force_multiplier'] = 1.12;
        }
        if (!isset($model['default_sweep']) || !is_array($model['default_sweep'])) {
            $model['default_sweep'] = [
                'heavy' => false,
                'samples' => [
                    ['duration_ms' => 400, 'controls' => 500, 'file_mb' => 1.5, 'workload' => 200, 'changes' => 50, 'app' => 'default'],
                    ['duration_ms' => 1800, 'controls' => 2000, 'file_mb' => 5.0, 'workload' => 1200, 'changes' => 400, 'app' => 'default'],
                ],
                'avg_ms' => 900,
            ];
        }
        if (!isset($model['workload_from_signals']) || !is_array($model['workload_from_signals'])) {
            $model['workload_from_signals'] = [
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
            ];
        }

        return $added;
    }

    /**
     * @param array<string, mixed> $model
     */
    public static function refitPhases(array &$model): void
    {
        $phases = is_array($model['phase_samples'] ?? null) ? $model['phase_samples'] : [];
        foreach (['unpack', 'pack'] as $kind) {
            $pts = [];
            foreach (($phases[$kind] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $pts[] = [
                    'x' => max(0.01, (float) ($row['file_mb'] ?? 0.01)),
                    'y' => max(1.0, (float) ($row['duration_ms'] ?? 1)),
                ];
            }
            // Fall back to existing coefficients when we have no live phase samples yet.
            if ($pts === [] && isset($model[$kind]) && is_array($model[$kind])) {
                continue;
            }
            $fit = self::fitLine($pts);
            $model[$kind] = [
                'fixed_ms' => (int) round($fit['a']),
                'per_mb_ms' => round($fit['b'], 3),
            ];
        }
    }

    /**
     * @param array<string, mixed> $signals
     */
    public static function workloadFor(string $id, array $signals, int $changes, int $controls): int
    {
        $fromSignals = match ($id) {
            'enable_dark_mode', 'enable_dark_theme' => (int) ($signals['opaque_colors'] ?? 0)
                + (int) ($signals['modern_themeable_controls'] ?? 0)
                + (int) ($signals['white_container_fills'] ?? 0),
            'accessibility_labels', 'accessibility_polish' => (int) ($signals['missing_accessible_label'] ?? 0),
            'tooltip_from_label' => (int) ($signals['missing_tooltip'] ?? 0),
            'ensure_tab_index' => (int) ($signals['missing_tab_index'] ?? 0),
            'ensure_focus_visible' => (int) ($signals['missing_focus_border'] ?? 0),
            'unwhack_locale_formulas' => (int) ($signals['locale_hits'] ?? 0),
            'meaningful_names', 'fix_control_names_and_refs' => (int) ($signals['generic_names'] ?? 0),
            'translate' => (int) round(((int) ($signals['literal_texts'] ?? 0)) * 0.35),
            'normalize_containers', 'strip_default_fill', 'clean_default_chrome' => (int) ($signals['container_chrome'] ?? 0)
                + (int) ($signals['white_container_fills'] ?? 0),
            'prefer_classic_theme_controls' => (int) ($signals['modern_themeable_controls'] ?? 0),
            'repair_control_refs', 'repair_context_aware_refs', 'repair_double_qualified_refs',
            'repair_converge_formulas', 'repair_ghost_patch_fields', 'repair_sharepoint_fields',
            'repair_studio_syntax', 'repair_delegation', 'repair_maintainability',
            'fix_formula_errors' => max(
                $changes,
                (int) ($signals['formula_errors'] ?? 0),
                (int) round($controls * 0.08)
            ),
            'regenerate_sarif', 'analyze_app_checker', 'scan_studio_issues' => max(1, $controls),
            default => max($changes, (int) round($controls * 0.02), 1),
        };

        return max(1, $fromSignals);
    }

    public static function isHeavy(string $id): bool
    {
        return str_starts_with($id, 'repair_')
            || in_array($id, [
                'regenerate_sarif',
                'analyze_app_checker',
                'scan_studio_issues',
                'enable_dark_mode',
                'enable_dark_theme',
                'fix_formula_errors',
                'fix_control_names_and_refs',
                'translate',
            ], true);
    }

    /**
     * @param list<array{x:float,y:float}> $pts
     * @return array{a:float,b:float}
     */
    public static function fitLine(array $pts): array
    {
        $n = count($pts);
        if ($n === 0) {
            return ['a' => 80.0, 'b' => 120.0];
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
    }

    public static function sanitizeAppLabel(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/\.(msapp|zip)$/i', '', $base) ?? $base;
        $base = preg_replace('/[^a-zA-Z0-9._+-]+/', '_', $base) ?? 'live';
        $base = trim($base, '._-');
        if ($base === '') {
            return 'live';
        }

        return substr($base, 0, 80);
    }

    /**
     * @param array<string, mixed> $signals
     * @return array<string, int>
     */
    public static function normalizeSignals(array $signals): array
    {
        $keys = [
            'opaque_colors',
            'modern_themeable_controls',
            'missing_accessible_label',
            'missing_tooltip',
            'missing_tab_index',
            'missing_focus_border',
            'locale_hits',
            'formula_errors',
            'literal_texts',
            'generic_names',
            'container_chrome',
            'white_container_fills',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = (int) ($signals[$k] ?? 0);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyModel(): array
    {
        return [
            'version' => self::VERSION,
            'generated_at' => gmdate('c'),
            'source_runs' => 'empty',
            'overhead_ms' => 280,
            'unpack' => ['fixed_ms' => 80, 'per_mb_ms' => 110.0],
            'pack' => ['fixed_ms' => 80, 'per_mb_ms' => 160.0],
            'phase_samples' => ['unpack' => [], 'pack' => []],
            'default_sweep' => [
                'heavy' => false,
                'samples' => [
                    ['duration_ms' => 400, 'controls' => 500, 'file_mb' => 1.5, 'workload' => 200, 'changes' => 50, 'app' => 'default'],
                    ['duration_ms' => 1800, 'controls' => 2000, 'file_mb' => 5.0, 'workload' => 1200, 'changes' => 400, 'app' => 'default'],
                ],
                'avg_ms' => 900,
            ],
            'sweeps' => [],
            'force_multiplier' => 1.12,
        ];
    }

    private static function trimJsonl(string $path, int $keep): void
    {
        if ($keep < 1 || !is_file($path)) {
            return;
        }
        $size = filesize($path);
        if ($size !== false && $size < 256 * 1024) {
            // Small enough — count lines cheaply.
            $lines = @file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines) || count($lines) <= $keep) {
                return;
            }
            $slice = array_slice($lines, -$keep);
            file_put_contents($path, implode("\n", $slice) . "\n", LOCK_EX);

            return;
        }
        // Large file: rewrite last N non-empty lines.
        $lines = [];
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return;
        }
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $lines[] = $line;
            if (count($lines) > $keep) {
                array_shift($lines);
            }
        }
        fclose($fh);
        file_put_contents($path, implode("\n", $lines) . (count($lines) ? "\n" : ''), LOCK_EX);
    }

    private static function countJsonl(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }
        $n = 0;
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return 0;
        }
        while (($line = fgets($fh)) !== false) {
            if (trim($line) !== '') {
                $n++;
            }
        }
        fclose($fh);

        return $n;
    }
}
