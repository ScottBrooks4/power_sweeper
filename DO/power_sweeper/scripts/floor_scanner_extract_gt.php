<?php

declare(strict_types=1);

/**
 * Extract labelled space ground truth from a Synoptic Designer Filled SVG
 * (or CubiCasa Space annotation SVG).
 *
 *   php scripts/floor_scanner_extract_gt.php \
 *     --filled="/path/to/Filled.svg" \
 *     --out=tests/fixtures/floor_scanner/bldg5_filled_rects.json
 *
 *   php scripts/floor_scanner_extract_gt.php --cubicasa --filled=model.svg --out=gt.json
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\FloorScanner\GroundTruth;

$filled = null;
$out = null;
$cubicasa = false;
$fill = GroundTruth::ANNOTATION_FILL;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--cubicasa') {
        $cubicasa = true;
        continue;
    }
    if (str_starts_with($arg, '--filled=')) {
        $filled = substr($arg, 9);
        continue;
    }
    if (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
        continue;
    }
    if (str_starts_with($arg, '--fill=')) {
        $fill = substr($arg, 7);
        continue;
    }
}

if ($filled === null || $out === null) {
    fwrite(STDERR, "Usage: php scripts/floor_scanner_extract_gt.php --filled=Filled.svg --out=gt.json [--cubicasa] [--fill=#0B95EE]\n");
    exit(1);
}
if (!is_file($filled)) {
    fwrite(STDERR, "Missing filled SVG: {$filled}\n");
    exit(1);
}

$svg = (string) file_get_contents($filled);
$gt = $cubicasa
    ? GroundTruth::extractFromCubiCasaSvg($svg)
    : GroundTruth::extractFromFilledSvg($svg, $fill === '' ? null : $fill);

$dir = dirname($out);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
file_put_contents(
    $out,
    json_encode($gt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

$labels = 0;
foreach ($gt['spaces'] as $s) {
    if (($s['label'] ?? '') !== '') {
        $labels++;
    }
}
$cubicles = 0;
$rooms = 0;
foreach ($gt['spaces'] as $s) {
    if (($s['type'] ?? '') === 'cubicle') {
        $cubicles++;
    } else {
        $rooms++;
    }
}

printf(
    "wrote %s  spaces=%d (%d cubicle / %d room) labelled=%d viewBox=[%s]\n",
    $out,
    $gt['count'],
    $cubicles,
    $rooms,
    $labels,
    implode(',', $gt['viewBox'])
);
