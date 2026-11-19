<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\FloorScanner\DetectionScore;
use PowerSweeper\FloorScanner\RoomDetector;

$gt = json_decode((string) file_get_contents('tests/fixtures/floor_scanner/corpus/bldg5_north_1f/gt.json'), true);
foreach ($gt['spaces'] as $i => $s) {
    $lab = (string) ($s['label'] ?? '');
    if (in_array($lab, ['B03.03', 'B05.50', 'A06.41', 'A08.03'], true)) {
        echo sprintf(
            "%d %s %.0fx%.0f @%.0f,%.0f type=%s\n",
            $i,
            $lab,
            $s['w'],
            $s['h'],
            $s['x'],
            $s['y'],
            $s['type'] ?? '?'
        );
    }
}

$path = 'tests/fixtures/floor_scanner/corpus/bldg5_north_1f_raster/empty_2000.png';
$r = (new RoomDetector())->detectFile($path);
$sx = 2000 / 5907;
$sy = 957 / 2824;
$gx = 1317 * $sx;
$gy = 263 * $sy;
$gw = 783 * $sx;
$gh = 565 * $sy;
echo 'GT B03.03 @2000: ' . round($gx) . ',' . round($gy) . ' ' . round($gw) . 'x' . round($gh) . "\n";
foreach ($r['rooms'] as $room) {
    $b = $room['bbox'];
    $cx = $b['x'] + $b['width'] / 2;
    $cy = $b['y'] + $b['height'] / 2;
    if ($cx > $gx && $cx < $gx + $gw && $cy > $gy && $cy < $gy + $gh) {
        echo "  inside: {$b['width']}x{$b['height']} @{$b['x']},{$b['y']} {$room['label']} {$room['type']}\n";
    }
    if ($b['height'] >= 400) {
        echo "  tall: {$b['width']}x{$b['height']} @{$b['x']},{$b['y']} {$room['label']}\n";
    }
}
echo 'n=' . count($r['rooms']) . "\n";
