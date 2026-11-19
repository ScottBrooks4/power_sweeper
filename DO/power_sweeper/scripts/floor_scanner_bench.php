<?php

declare(strict_types=1);

/**
 * Floor scanner corpus bench.
 *
 * Iterates tests/fixtures/floor_scanner/corpus/ (each plan's meta.json) and
 * reports honest metrics, split by dev / holdout.
 *
 *   php scripts/floor_scanner_bench.php
 *   php scripts/floor_scanner_bench.php --holdout
 *   php scripts/floor_scanner_bench.php --full --write
 *   php scripts/floor_scanner_bench.php --only=bldg5_north_1f --probe
 *   php scripts/floor_scanner_bench.php --variants   # also score raster DPI variants
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\FloorScanner\DetectionScore;
use PowerSweeper\FloorScanner\RoomDetector;

const FS_BASELINE = __DIR__ . '/../tests/fixtures/floor_scanner/baseline_metrics.json';
const FS_CORPUS = __DIR__ . '/../tests/fixtures/floor_scanner/corpus';
const FS_PROBES = [1, 6, 18, 19, 32, 35, 37, 55, 57, 59, 62, 70, 76, 82];

/**
 * @return list<array<string, mixed>>
 */
function fs_corpus_cases(bool $includeHoldout, bool $includeHeavy, bool $variants, ?string $only): array
{
    $cases = [];
    foreach (glob(FS_CORPUS . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $metaPath = $dir . '/meta.json';
        if (!is_file($metaPath)) {
            continue;
        }
        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($meta)) {
            continue;
        }
        $id = (string) ($meta['id'] ?? basename($dir));
        if ($only !== null && $id !== $only && basename($dir) !== $only) {
            continue;
        }
        $split = (string) ($meta['split'] ?? 'dev');
        if ($split === 'holdout' && !$includeHoldout && $only === null) {
            continue;
        }
        $heavy = (bool) ($meta['heavy'] ?? false);
        if ($heavy && !$includeHeavy && $only === null) {
            continue;
        }
        if (!empty($meta['skip_detect']) && $only === null) {
            continue;
        }

        $empty = $dir . '/' . ($meta['empty'] ?? 'empty.svg');
        if (!is_file($empty)) {
            foreach (['empty.svg', 'empty.png'] as $cand) {
                if (is_file($dir . '/' . $cand)) {
                    $empty = $dir . '/' . $cand;
                    break;
                }
            }
        }
        $gt = $dir . '/' . ($meta['gt'] ?? 'gt.json');
        if (!is_file($gt)) {
            $gt = null;
        }

        $cases[] = [
            'name' => $id,
            'path' => $empty,
            'gt' => $gt,
            'heavy' => $heavy,
            'split' => $split,
            'class' => (string) ($meta['class'] ?? ''),
            'variant' => 'primary',
        ];

        if ($variants && isset($meta['raster_variants']) && is_array($meta['raster_variants'])) {
            foreach ($meta['raster_variants'] as $v) {
                $file = $dir . '/' . ($v['file'] ?? '');
                if (!is_file($file) || realpath($file) === realpath($empty)) {
                    continue;
                }
                $cases[] = [
                    'name' => $id . '@' . ($v['id'] ?? basename($file)),
                    'path' => $file,
                    'gt' => $gt,
                    'heavy' => $heavy,
                    'split' => $split,
                    'class' => (string) ($meta['class'] ?? ''),
                    'variant' => (string) ($v['id'] ?? 'raster'),
                ];
            }
        }
    }

    // Legacy fixtures still covered when corpus is thin.
    $root = dirname(__DIR__);
    $legacy = [
        [
            'name' => 'sample_png',
            'path' => $root . '/tests/fixtures/sample_floorplan.png',
            'gt' => null,
            'heavy' => false,
            'split' => 'dev',
            'class' => 'residential',
            'variant' => 'legacy',
        ],
    ];
    foreach ($legacy as $c) {
        if ($only !== null && $c['name'] !== $only) {
            continue;
        }
        if (is_file($c['path'])) {
            $cases[] = $c;
        }
    }

    return $cases;
}

/**
 * @param array<string, mixed> $case
 * @return array<string, mixed>
 */
function fs_run_case(array $case, bool $probe): array
{
    $started = microtime(true);
    $detector = new RoomDetector();
    $result = $detector->detectFile($case['path']);
    $elapsed = (int) round((microtime(true) - $started) * 1000);

    $rooms = 0;
    $cubicles = 0;
    foreach ($result['rooms'] as $room) {
        if (($room['type'] ?? '') === 'cubicle') {
            $cubicles++;
        } else {
            $rooms++;
        }
    }

    $metrics = [
        'width' => $result['width'],
        'height' => $result['height'],
        'rooms' => $rooms,
        'cubicles' => $cubicles,
        'total' => count($result['rooms']),
        'elapsed_ms' => $elapsed,
        'split' => $case['split'] ?? 'dev',
        'class' => $case['class'] ?? '',
        'variant' => $case['variant'] ?? 'primary',
    ];

    if (!empty($case['gt']) && is_file($case['gt'])) {
        $gt = json_decode((string) file_get_contents($case['gt']), true);
        $rects = DetectionScore::rectsFromGtDocument($gt);
        $preds = DetectionScore::predsFromRooms($result['rooms']);
        $gts = DetectionScore::scaleGroundTruth(
            $rects,
            $gt['viewBox'],
            (int) $result['width'],
            (int) $result['height']
        );
        $metrics += DetectionScore::evaluate($preds, $gts);
        if ($probe) {
            $metrics['probes'] = DetectionScore::probe($preds, $gts, FS_PROBES);
        }
    }

    return $metrics;
}

$args = array_slice($argv, 1);
$full = in_array('--full', $args, true);
$write = in_array('--write', $args, true);
$probe = in_array('--probe', $args, true);
$holdout = in_array('--holdout', $args, true) || $full;
$variants = in_array('--variants', $args, true);
$only = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, 7);
    }
}

$report = [];
foreach (fs_corpus_cases($holdout, $full, $variants, $only) as $case) {
    if (!is_file($case['path'])) {
        echo "skip {$case['name']} (missing {$case['path']})\n";
        continue;
    }
    if (($case['heavy'] ?? false) && !$full && $only === null) {
        echo "skip {$case['name']} (heavy; pass --full)\n";
        continue;
    }

    $metrics = fs_run_case($case, $probe);
    $probes = $metrics['probes'] ?? null;
    unset($metrics['probes']);
    $report[$case['name']] = $metrics;

    $extra = '';
    if (isset($metrics['recall25'])) {
        $extra = sprintf(
            "rec25=%d/%d rec40=%d cells=%d/%d prec=%.3f FP=%d holes=%d lbl=%.2f typ=%.2f  ",
            $metrics['recall25'],
            $metrics['gt'],
            $metrics['recall40'],
            $metrics['cell_recall25'],
            $metrics['gt_cells'],
            $metrics['precision'],
            $metrics['false_positives'],
            $metrics['empty_cell_centers'],
            $metrics['label_norm_acc'] ?? 0,
            $metrics['type_acc'] ?? 0
        );
    }
    printf(
        "%-28s [%s] %dx%d  %dr/%dc total=%-4d %s%dms\n",
        $case['name'],
        $metrics['split'],
        $metrics['width'],
        $metrics['height'],
        $metrics['rooms'],
        $metrics['cubicles'],
        $metrics['total'],
        $extra,
        $metrics['elapsed_ms']
    );

    if (is_array($probes)) {
        foreach ($probes as $gi => $info) {
            $pred = $info['pred'];
            printf(
                "    #%-3d %-5s gt=%-8s pred=%s %s\n",
                $gi,
                number_format($info['iou'], 3),
                $info['gt_label'] ?? '',
                $info['label'] ?? '',
                $pred === null
                    ? 'none'
                    : sprintf('%dx%d @%d,%d', $pred[2] - $pred[0] + 1, $pred[3] - $pred[1] + 1, $pred[0], $pred[1])
            );
        }
    }
}

if ($write) {
    $existing = is_file(FS_BASELINE)
        ? (json_decode((string) file_get_contents(FS_BASELINE), true) ?: [])
        : [];
    $cases = array_merge($existing['cases'] ?? [], $report);
    ksort($cases);
    file_put_contents(
        FS_BASELINE,
        json_encode(
            ['recorded' => date('c'), 'cases' => $cases],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n"
    );
    echo "\nbaseline written: " . realpath(FS_BASELINE) . "\n";
}
