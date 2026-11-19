<?php

declare(strict_types=1);

/**
 * Convert a CubiCasa5K model.svg annotation into Floor Scanner gt.json.
 *
 *   php scripts/floor_scanner_cubicasa_convert.php \
 *     --svg=/path/to/model.svg \
 *     --out=tests/fixtures/floor_scanner/corpus/<id>/gt.json \
 *     --plan-id=cubicasa_001 \
 *     --split=dev
 *
 * Also copies/links the F1_scaled.png (or similar) as empty.png when --image= is given.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\FloorScanner\GroundTruth;

$svgPath = null;
$out = null;
$planId = null;
$split = 'dev';
$image = null;
$corpusRoot = dirname(__DIR__) . '/tests/fixtures/floor_scanner/corpus';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--svg=')) {
        $svgPath = substr($arg, 6);
    } elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    } elseif (str_starts_with($arg, '--plan-id=')) {
        $planId = substr($arg, 10);
    } elseif (str_starts_with($arg, '--split=')) {
        $split = substr($arg, 8);
    } elseif (str_starts_with($arg, '--image=')) {
        $image = substr($arg, 8);
    }
}

if ($svgPath === null || !is_file($svgPath)) {
    fwrite(STDERR, "Usage: php scripts/floor_scanner_cubicasa_convert.php --svg=model.svg [--plan-id=id] [--out=gt.json] [--image=F1.png] [--split=dev]\n");
    exit(1);
}

$gt = GroundTruth::extractFromCubiCasaSvg((string) file_get_contents($svgPath));
if ($gt['count'] < 1) {
    fwrite(STDERR, "No Space groups found in {$svgPath}\n");
    exit(1);
}

if ($planId !== null) {
    $dir = $corpusRoot . '/' . $planId;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $out = $out ?? ($dir . '/gt.json');
    copy($svgPath, $dir . '/filled.svg');
    if ($image !== null && is_file($image)) {
        copy($image, $dir . '/empty.png');
    }
    $meta = [
        'id' => $planId,
        'name' => $planId,
        'class' => 'residential',
        'split' => $split,
        'licence' => 'CC-BY-4.0-CubiCasa5K',
        'source' => 'cubicasa5k',
        'empty' => is_file($dir . '/empty.png') ? 'empty.png' : 'empty.svg',
        'filled' => 'filled.svg',
        'gt' => 'gt.json',
        'raster_variants' => [],
        'heavy' => false,
        'furniture_count' => count($gt['furniture'] ?? []),
        'notes' => 'Converted from CubiCasa5K Space polygons to labelled AABBs.',
    ];
    file_put_contents($dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    if (!empty($gt['furniture'])) {
        file_put_contents(
            $dir . '/furniture.json',
            json_encode(['furniture' => $gt['furniture']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}

if ($out === null) {
    fwrite(STDERR, "Provide --out= or --plan-id=\n");
    exit(1);
}
$dir = dirname($out);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
file_put_contents($out, json_encode($gt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
printf(
    "wrote %s  spaces=%d furniture=%d viewBox=[%s]\n",
    $out,
    $gt['count'],
    count($gt['furniture'] ?? []),
    implode(',', $gt['viewBox'])
);
