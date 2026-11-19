<?php

declare(strict_types=1);

namespace PowerSweeper\FloorScanner;

/**
 * Shared Floor Scanner runtime estimates — learns from every successful scan.
 *
 * Predicts elapsed_ms from work megapixels (after the soft MAX_PROCESS cap),
 * the same way Power Sweeper's EstimateModel learns from live runs.
 */
final class TimingModel
{
    public const VERSION = 1;
    public const MAX_SAMPLES = 48;
    public const MAX_PROCESS = 2000;

    /**
     * @return list<string>
     */
    public static function modelPaths(?string $root = null): array
    {
        $root = $root ?? (defined('POWER_SWEEPER_ROOT') ? POWER_SWEEPER_ROOT : dirname(__DIR__, 2));

        return [
            self::storageDir($root) . '/floorscanner_timing.json',
            $root . '/config/floorscanner_timing.json',
            $root . '/public/assets/floorscanner_timing.json',
            $root . '/assets/floorscanner_timing.json',
        ];
    }

    /**
     * @return list<string>
     */
    public static function writableModelPaths(?string $root = null): array
    {
        $root = $root ?? (defined('POWER_SWEEPER_ROOT') ? POWER_SWEEPER_ROOT : dirname(__DIR__, 2));

        return [
            self::storageDir($root) . '/floorscanner_timing.json',
            $root . '/config/floorscanner_timing.json',
            $root . '/public/assets/floorscanner_timing.json',
            $root . '/assets/floorscanner_timing.json',
        ];
    }

    public static function storageDir(?string $root = null): string
    {
        $root = $root ?? (defined('POWER_SWEEPER_ROOT') ? POWER_SWEEPER_ROOT : dirname(__DIR__, 2));
        $dir = $root . '/storage/benchmarks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir;
    }

    /**
     * @return array{
     *   version:int,
     *   intercept_ms:float,
     *   ms_per_mp:float,
     *   samples:list<array{mp:float,ms:int,w:int,h:int,bytes:int,at:string}>
     * }
     */
    public static function load(?string $root = null): array
    {
        foreach (self::modelPaths($root) as $path) {
            if (!is_file($path)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && isset($data['ms_per_mp'])) {
                return self::normalize($data);
            }
        }

        return self::seedModel();
    }

    /**
     * @return array{
     *   version:int,
     *   intercept_ms:float,
     *   ms_per_mp:float,
     *   samples:list<array{mp:float,ms:int,w:int,h:int,bytes:int,at:string}>
     * }
     */
    public static function seedModel(): array
    {
        // Seeded from local fixture timings (native-res detector).
        return [
            'version' => self::VERSION,
            'intercept_ms' => 2200.0,
            'ms_per_mp' => 12000.0,
            'samples' => [
                ['mp' => 0.457, 'ms' => 6800, 'w' => 676, 'h' => 676, 'bytes' => 150454, 'at' => 'seed'],
                ['mp' => 0.375, 'ms' => 2600, 'w' => 886, 'h' => 424, 'bytes' => 192983, 'at' => 'seed'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   version:int,
     *   intercept_ms:float,
     *   ms_per_mp:float,
     *   samples:list<array{mp:float,ms:int,w:int,h:int,bytes:int,at:string}>
     * }
     */
    private static function normalize(array $data): array
    {
        $seed = self::seedModel();
        $samples = [];
        if (isset($data['samples']) && is_array($data['samples'])) {
            foreach ($data['samples'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $samples[] = [
                    'mp' => max(0.01, (float) ($row['mp'] ?? 0.01)),
                    'ms' => max(1, (int) ($row['ms'] ?? 1)),
                    'w' => max(1, (int) ($row['w'] ?? 1)),
                    'h' => max(1, (int) ($row['h'] ?? 1)),
                    'bytes' => max(0, (int) ($row['bytes'] ?? 0)),
                    'at' => (string) ($row['at'] ?? ''),
                ];
            }
        }

        return [
            'version' => self::VERSION,
            'intercept_ms' => (float) ($data['intercept_ms'] ?? $seed['intercept_ms']),
            'ms_per_mp' => (float) ($data['ms_per_mp'] ?? $seed['ms_per_mp']),
            'samples' => $samples !== [] ? $samples : $seed['samples'],
        ];
    }

    public static function workMegapixels(int $width, int $height): float
    {
        $maxSide = max($width, $height, 1);
        $cap = FloorplanImage::maxEdge();
        $scale = $maxSide > $cap ? $cap / $maxSide : 1.0;
        $workW = max(80, (int) round($width * $scale));
        $workH = max(80, (int) round($height * $scale));

        return ($workW * $workH) / 1_000_000.0;
    }

    /**
     * @param array<string, mixed>|null $model
     */
    public static function predictMs(int $width, int $height, int $fileBytes = 0, ?array $model = null): int
    {
        $model = $model ?? self::load();
        $mp = self::workMegapixels($width, $height);
        $ms = (float) $model['intercept_ms'] + $mp * (float) $model['ms_per_mp'];
        // Mild byte term for huge uploads (decode/OCR headroom).
        if ($fileBytes > 0) {
            $ms += $fileBytes / 9_000.0;
            if ($fileBytes > 250_000) {
                $ms += 18_000.0;
            }
            if ($mp > 2.0) {
                $ms += $mp * 4_000.0;
            }
        }
        // Finishing overhead: overlay encode + JSON transfer + client parse/render.
        $ms += min(28_000.0, max(2_500.0, 1_800.0 + $mp * 2_800.0 + ($fileBytes > 0 ? $fileBytes / 35_000.0 : 0.0)));

        return (int) round(min(240_000, max(3_500, $ms)));
    }

    /**
     * Record a completed scan and refit the linear model.
     *
     * @return array{ok:bool,reason?:string,model?:array<string,mixed>}
     */
    public static function recordSample(
        int $width,
        int $height,
        int $elapsedMs,
        int $fileBytes = 0,
        ?string $root = null
    ): array {
        if ($width < 16 || $height < 16 || $elapsedMs < 200) {
            return ['ok' => false, 'reason' => 'sample too small'];
        }
        $root = $root ?? (defined('POWER_SWEEPER_ROOT') ? POWER_SWEEPER_ROOT : dirname(__DIR__, 2));
        $model = self::load($root);
        $sample = [
            'mp' => self::workMegapixels($width, $height),
            'ms' => $elapsedMs,
            'w' => $width,
            'h' => $height,
            'bytes' => max(0, $fileBytes),
            'at' => gmdate('c'),
        ];
        $model['samples'][] = $sample;
        if (count($model['samples']) > self::MAX_SAMPLES) {
            $model['samples'] = array_slice($model['samples'], -self::MAX_SAMPLES);
        }
        self::refit($model);

        $json = json_encode($model, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return ['ok' => false, 'reason' => 'encode failed'];
        }
        $wrote = false;
        foreach (self::writableModelPaths($root) as $path) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            if (@file_put_contents($path, $json . "\n") !== false) {
                $wrote = true;
            }
        }
        $log = self::storageDir($root) . '/floorscanner_runs.jsonl';
        @file_put_contents($log, json_encode($sample, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

        return $wrote
            ? ['ok' => true, 'model' => $model]
            : ['ok' => false, 'reason' => 'could not write floorscanner_timing.json', 'model' => $model];
    }

    /**
     * @param array{
     *   intercept_ms:float,
     *   ms_per_mp:float,
     *   samples:list<array{mp:float,ms:int}>
     * } $model
     */
    private static function refit(array &$model): void
    {
        $samples = $model['samples'];
        $n = count($samples);
        if ($n < 2) {
            return;
        }
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXX = 0.0;
        $sumXY = 0.0;
        foreach ($samples as $row) {
            $x = (float) $row['mp'];
            $y = (float) $row['ms'];
            $sumX += $x;
            $sumY += $y;
            $sumXX += $x * $x;
            $sumXY += $x * $y;
        }
        $den = ($n * $sumXX) - ($sumX * $sumX);
        if (abs($den) < 1e-9) {
            return;
        }
        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $den;
        $intercept = ($sumY - $slope * $sumX) / $n;
        // Keep coefficients in a sane band so one outlier cannot break ETAs.
        $model['ms_per_mp'] = max(2_000.0, min(80_000.0, $slope));
        $model['intercept_ms'] = max(500.0, min(30_000.0, $intercept));
    }
}
