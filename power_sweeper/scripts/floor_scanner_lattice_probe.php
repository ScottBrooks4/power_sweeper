<?php

declare(strict_types=1);

/**
 * Diagnostic: how much of the ground truth can a lattice of closed cells reach?
 *
 * Scores lattice candidates straight against the ground truth, bypassing the
 * detection pipeline, so the candidate source can be judged in seconds instead
 * of the ~80s a full office run costs.
 *
 *   php scripts/floor_scanner_lattice_probe.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\FloorScanner\DetectionScore;
use PowerSweeper\FloorScanner\FloorplanImage;
use PowerSweeper\FloorScanner\RoomDetector;

/**
 * @param list<int> $mask
 * @return list<array{pos: int, a: int, b: int, len: int}>
 */
function fsl_lines(array $mask, int $w, int $h, bool $vertical, int $minLen): array
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
            if ($i - $start >= $minLen) {
                $runs[] = ['pos' => $o, 'a' => $start, 'b' => $i - 1, 'len' => $i - $start];
            }
        }
    }
    usort($runs, static fn (array $x, array $y): int => $x['pos'] <=> $y['pos'] ?: $x['a'] <=> $y['a']);

    // Collapse the two faces of one wall into a single line.
    $lines = [];
    foreach ($runs as $run) {
        $merged = false;
        foreach ($lines as $k => $line) {
            if (abs($line['pos'] - $run['pos']) > 4) {
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

function fsl_overlap(int $a0, int $a1, int $b0, int $b1): int
{
    return max(0, min($a1, $b1) - max($a0, $b0) + 1);
}

/**
 * Cells closed by two horizontals and two verticals that run alongside them.
 *
 * @param list<array{pos: int, a: int, b: int, len: int}> $vLines
 * @param list<array{pos: int, a: int, b: int, len: int}> $hLines
 * @return list<array{0:int,1:int,2:int,3:int}>
 */
function fsl_cells(array $vLines, array $hLines, int $minSide, int $maxW, int $maxH): array
{
    usort($vLines, static fn (array $x, array $y): int => $x['pos'] <=> $y['pos']);
    usort($hLines, static fn (array $x, array $y): int => $x['pos'] <=> $y['pos']);

    $cells = [];
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
            if (fsl_overlap($hLines[$k]['a'], $hLines[$k]['b'], $hLines[$l]['a'], $hLines[$l]['b']) < $minSide) {
                continue;
            }
            $sx0 = max($hLines[$k]['a'], $hLines[$l]['a']);
            $sx1 = min($hLines[$k]['b'], $hLines[$l]['b']);

            $inStrip = [];
            foreach ($vLines as $v) {
                if ($v['pos'] < $sx0 - 2 || $v['pos'] > $sx1 + 2) {
                    continue;
                }
                if (fsl_overlap($v['a'], $v['b'], $y0, $y1) < (int) (0.6 * ($ch + 2))) {
                    continue;
                }
                $inStrip[] = $v;
            }
            $nv = count($inStrip);
            for ($i = 0; $i + 1 < $nv; $i++) {
                $x0 = $inStrip[$i]['pos'];
                $x1 = $inStrip[$i + 1]['pos'];
                $cw = $x1 - $x0 - 1;
                if ($cw < $minSide || $cw > $maxW) {
                    continue;
                }
                $top = fsl_overlap($hLines[$k]['a'], $hLines[$k]['b'], $x0, $x1) / ($x1 - $x0 + 1);
                $bot = fsl_overlap($hLines[$l]['a'], $hLines[$l]['b'], $x0, $x1) / ($x1 - $x0 + 1);
                if ($top < 0.6 || $bot < 0.6) {
                    continue;
                }
                $cells[] = [$x0 + 1, $y0 + 1, $x1 - 1, $y1 - 1];
            }
            break;
        }
    }

    return $cells;
}

/**
 * @param list<array{0:int,1:int,2:int,3:int}> $cells
 * @return array{0:int,1:int}
 */
function fsl_modal_size(array $cells): array
{
    $sizes = [];
    foreach ($cells as $c) {
        $key = ($c[2] - $c[0] + 1) . 'x' . ($c[3] - $c[1] + 1);
        $sizes[$key] = ($sizes[$key] ?? 0) + 1;
    }
    $best = [0, 0];
    $bestVotes = 0;
    foreach ($sizes as $key => $_) {
        [$cw, $ch] = array_map('intval', explode('x', (string) $key));
        $votes = 0;
        foreach ($sizes as $other => $count) {
            [$ow, $oh] = array_map('intval', explode('x', (string) $other));
            if (abs($ow - $cw) <= 3 && abs($oh - $ch) <= 3) {
                $votes += $count;
            }
        }
        if ($votes > $bestVotes) {
            $bestVotes = $votes;
            $best = [$cw, $ch];
        }
    }

    return $best;
}

$cases = [
    'bldg5_svg' => '/home/lulu/Downloads/Bldg 5W - North - 1st floor - Empty.svg',
    'bldg5_png' => dirname(__DIR__) . '/tests/fixtures/floor_scanner/bldg5_empty.png',
];

$gtData = json_decode(
    (string) file_get_contents(dirname(__DIR__) . '/tests/fixtures/floor_scanner/bldg5_filled_rects.json'),
    true
);

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
    $cap = FloorplanImage::maxEdge();
    $sw = imagesx($src);
    $sh = imagesy($src);
    // Mirror detectImage: cap large sheets, lift under-resolved CAD to the cap.
    $s = $cap / max($sw, $sh);
    $w = (int) round($sw * $s);
    $h = (int) round($sh * $s);
    $work = imagecreatetruecolor($w, $h);
    imagecopyresampled($work, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
    imagedestroy($src);

    $walls = $call('wallMask', [$work, $w, $h]);
    $closed = $call('closeWallGaps', [$walls, $w, $h, 2]);
    $partitions = $call('partitionWallMask', [$walls, $w, $h]);
    imagedestroy($work);

    $gts = DetectionScore::scaleGroundTruth($gtData['rects'], $gtData['viewBox'], $w, $h);
    echo "{$name} work {$w}x{$h}\n";

    // The office growth mask drops cubicle partitions that openings break into
    // short runs, so compare it against raw closed ink at several run floors.
    $sources = ['partition' => $partitions, 'closed' => $closed];
    foreach ($sources as $srcName => $mask) {
    foreach ([8, 12, 16, 22, 30, 45] as $minLen) {
        $vLines = fsl_lines($mask, $w, $h, true, $minLen);
        $hLines = fsl_lines($mask, $w, $h, false, $minLen);
        $cells = fsl_cells($vLines, $hLines, max(5, (int) round(min($w, $h) * 0.012)), intdiv($w, 5), intdiv($h, 4));
        [$mw, $mh] = fsl_modal_size($cells);
        $m = DetectionScore::evaluate($cells, $gts);
        printf(
            "  %-9s minLen=%-3d v=%-4d h=%-4d cells=%-4d module=%dx%d  rec25=%-3d cells25=%-3d prec=%.3f holes=%d\n",
            $srcName,
            $minLen,
            count($vLines),
            count($hLines),
            count($cells),
            $mw,
            $mh,
            $m['recall25'],
            $m['cell_recall25'],
            $m['precision'],
            $m['empty_cell_centers']
        );
    }
    }
}
