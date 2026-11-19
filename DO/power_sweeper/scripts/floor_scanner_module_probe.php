<?php

declare(strict_types=1);

/**
 * Diagnostic: measure plan-classification evidence and module geometry per fixture.
 *
 * The module comes from the modal gap between adjacent parallel partition lines,
 * which is far more stable than whole-sheet autocorrelation (that locks onto
 * harmonics because a sheet mixes several banks).
 *
 *   php scripts/floor_scanner_module_probe.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\FloorScanner\FloorplanImage;
use PowerSweeper\FloorScanner\RoomDetector;

/**
 * Collapse long runs in one direction into non-maximal-suppressed lines.
 *
 * @param list<int> $mask
 * @return list<array{pos: int, a: int, b: int, len: int}>
 */
function fs_lines(array $mask, int $w, int $h, bool $vertical, int $minLen): array
{
    $outer = $vertical ? $w : $h;
    $inner = $vertical ? $h : $w;
    $runs = [];
    for ($o = 0; $o < $outer; $o++) {
        $i = 0;
        while ($i < $inner) {
            $idx = $vertical ? ($i * $w + $o) : ($o * $w + $i);
            if ($mask[$idx] !== 1) {
                $i++;
                continue;
            }
            $start = $i;
            while ($i < $inner) {
                $idx = $vertical ? ($i * $w + $o) : ($o * $w + $i);
                if ($mask[$idx] !== 1) {
                    break;
                }
                $i++;
            }
            $len = $i - $start;
            if ($len >= $minLen) {
                $runs[] = ['pos' => $o, 'a' => $start, 'b' => $i - 1, 'len' => $len];
            }
        }
    }
    // Suppress duplicates from wall thickness: keep the longest run per cluster.
    usort($runs, static fn (array $x, array $y): int => $x['pos'] <=> $y['pos'] ?: $x['a'] <=> $y['a']);
    $lines = [];
    foreach ($runs as $run) {
        $merged = false;
        foreach ($lines as $k => $line) {
            if (abs($line['pos'] - $run['pos']) > 3) {
                continue;
            }
            $overlap = min($line['b'], $run['b']) - max($line['a'], $run['a']) + 1;
            if ($overlap < (int) (0.5 * min($line['len'], $run['len']))) {
                continue;
            }
            if ($run['len'] > $line['len']) {
                $lines[$k] = $run;
            }
            $merged = true;
            break;
        }
        if (!$merged) {
            $lines[] = $run;
        }
    }

    return $lines;
}

function fs_overlap(int $a0, int $a1, int $b0, int $b1): int
{
    return max(0, min($a1, $b1) - max($a0, $b0) + 1);
}

/**
 * Candidate cells: strips between adjacent horizontal lines, subdivided by the
 * vertical lines that run alongside that strip. A cell must be closed on at
 * least three sides, which is what separates a real space from a desk strip.
 *
 * @param list<array{pos: int, a: int, b: int, len: int}> $vLines
 * @param list<array{pos: int, a: int, b: int, len: int}> $hLines
 * @return array{module: array{0:int,1:int}, conf: float, cells: int, sizes: array<string, int>}
 */
function fs_modal_cell(array $vLines, array $hLines, int $minSide, int $maxW, int $maxH): array
{
    usort($vLines, static fn (array $x, array $y): int => $x['pos'] <=> $y['pos']);
    usort($hLines, static fn (array $x, array $y): int => $x['pos'] <=> $y['pos']);

    $sizes = [];
    $cells = 0;
    $nh = count($hLines);
    for ($k = 0; $k < $nh; $k++) {
        for ($l = $k + 1; $l < $nh; $l++) {
            $y0 = $hLines[$k]['pos'];
            $y1 = $hLines[$l]['pos'];
            $ch = $y1 - $y0 - 1;
            if ($ch < $minSide) {
                continue;
            }
            if ($ch > $maxH) {
                break;
            }
            // The two horizontals must overlap enough to bound a common strip.
            $span = fs_overlap($hLines[$k]['a'], $hLines[$k]['b'], $hLines[$l]['a'], $hLines[$l]['b']);
            if ($span < $minSide) {
                continue;
            }
            $sx0 = max($hLines[$k]['a'], $hLines[$l]['a']);
            $sx1 = min($hLines[$k]['b'], $hLines[$l]['b']);

            // Verticals that run alongside this strip for most of its height.
            $inStrip = [];
            foreach ($vLines as $v) {
                if ($v['pos'] < $sx0 - 2 || $v['pos'] > $sx1 + 2) {
                    continue;
                }
                if (fs_overlap($v['a'], $v['b'], $y0, $y1) < (int) (0.6 * ($ch + 2))) {
                    continue;
                }
                $inStrip[] = $v;
            }
            $nv = count($inStrip);
            for ($i = 0; $i + 1 < $nv; $i++) {
                $cw = $inStrip[$i + 1]['pos'] - $inStrip[$i]['pos'] - 1;
                if ($cw < $minSide || $cw > $maxW) {
                    continue;
                }
                $x0 = $inStrip[$i]['pos'];
                $x1 = $inStrip[$i + 1]['pos'];
                // Horizontal closure over this cell's own width, not the whole strip.
                $top = fs_overlap($hLines[$k]['a'], $hLines[$k]['b'], $x0, $x1) / ($x1 - $x0 + 1);
                $bot = fs_overlap($hLines[$l]['a'], $hLines[$l]['b'], $x0, $x1) / ($x1 - $x0 + 1);
                if ($top < 0.6 || $bot < 0.6) {
                    continue;
                }
                $cells++;
                $key = $cw . 'x' . $ch;
                $sizes[$key] = ($sizes[$key] ?? 0) + 1;
            }
            break; // nearest supported strip partner only
        }
    }
    if ($sizes === []) {
        return ['module' => [0, 0], 'conf' => 0.0, 'cells' => 0, 'sizes' => []];
    }
    // Vote with +/-2 tolerance so quantisation does not split the mode.
    $best = null;
    $bestVotes = 0;
    foreach ($sizes as $key => $_) {
        [$cw, $ch] = array_map('intval', explode('x', (string) $key));
        $votes = 0;
        foreach ($sizes as $other => $count) {
            [$ow, $oh] = array_map('intval', explode('x', (string) $other));
            if (abs($ow - $cw) <= 2 && abs($oh - $ch) <= 2) {
                $votes += $count;
            }
        }
        if ($votes > $bestVotes) {
            $bestVotes = $votes;
            $best = [$cw, $ch];
        }
    }
    arsort($sizes);

    return [
        'module' => $best ?? [0, 0],
        'conf' => round($bestVotes / max(1, $cells), 3),
        'cells' => $cells,
        'sizes' => array_slice($sizes, 0, 6, true),
    ];
}

$cases = [
    'little_png' => dirname(__DIR__) . '/tests/fixtures/floor_scanner/little_empty.png',
    'little_svg' => dirname(__DIR__) . '/tests/fixtures/floor_scanner/little_empty.svg',
    'sample_png' => dirname(__DIR__) . '/tests/fixtures/sample_floorplan.png',
    'bldg5_png' => dirname(__DIR__) . '/tests/fixtures/floor_scanner/bldg5_empty.png',
    'bldg5_svg' => '/home/lulu/Downloads/Bldg 5W - North - 1st floor - Empty.svg',
];

$det = new RoomDetector();
$ref = new ReflectionClass($det);
$call = static function (string $name, array $args) use ($det, $ref) {
    $m = $ref->getMethod($name);
    $m->setAccessible(true);

    return $m->invokeArgs($det, $args);
};

foreach ($cases as $name => $path) {
    if (!is_file($path)) {
        echo "skip {$name}\n";
        continue;
    }
    $loaded = FloorplanImage::loadBlob((string) file_get_contents($path), basename($path));
    $src = $loaded['image'];
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $cap = FloorplanImage::maxEdge();
    $work = $src;
    $owns = false;
    if (max($srcW, $srcH) > $cap) {
        $s = $cap / max($srcW, $srcH);
        $w = (int) round($srcW * $s);
        $h = (int) round($srcH * $s);
        $work = imagecreatetruecolor($w, $h);
        imagecopyresampled($work, $src, 0, 0, 0, 0, $w, $h, $srcW, $srcH);
        $owns = true;
    }
    $w = imagesx($work);
    $h = imagesy($work);

    $walls = $call('wallMask', [$work, $w, $h]);
    $thickness = $call('estimateWallThickness', [$walls, $w, $h]);
    $office = $call('isOfficeCadPlan', [$thickness, $srcW, $srcH]);
    $partitions = $call('partitionWallMask', [$walls, $w, $h]);

    $ink = 0;
    foreach ($walls as $v) {
        $ink += $v;
    }

    $minLen = max(8, (int) round(min($w, $h) * 0.02));
    $vLines = fs_lines($partitions, $w, $h, true, $minLen);
    $hLines = fs_lines($partitions, $w, $h, false, $minLen);

    $minSide = max(5, (int) round(min($w, $h) * 0.008));
    $mod = fs_modal_cell($vLines, $hLines, $minSide, intdiv($w, 5), intdiv($h, 4));
    [$modW, $modH] = $mod['module'];

    printf(
        "%-11s work %dx%d thick=%d office=%s ink=%.3f vlines=%-4d hlines=%-4d module=%dx%d (c=%.2f over %d cells) cadS_now=%.2f cadS_mod=%.2f\n",
        $name,
        $w,
        $h,
        $thickness,
        $office ? 'yes' : 'NO ',
        $ink / ($w * $h),
        count($vLines),
        count($hLines),
        $modW,
        $modH,
        $mod['conf'],
        $mod['cells'],
        max(0.45, min(1.15, $w / 4000.0)),
        $modW > 0 ? round($modW / 110.0, 2) : 0.0
    );
    echo "    top sizes: ";
    foreach ($mod['sizes'] as $key => $count) {
        echo "{$key}x{$count}  ";
    }
    echo "\n";

    if ($owns) {
        imagedestroy($work);
    }
    imagedestroy($src);
}
