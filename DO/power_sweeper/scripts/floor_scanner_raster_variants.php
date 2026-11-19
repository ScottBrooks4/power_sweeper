<?php

declare(strict_types=1);

/**
 * Render deliberate DPI raster variants for a corpus plan.
 *
 *   php scripts/floor_scanner_raster_variants.php --plan=tests/fixtures/floor_scanner/corpus/bldg5_north_1f
 *   php scripts/floor_scanner_raster_variants.php --all
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\FloorScanner\FloorplanImage;

/**
 * @return list<string>
 */
function fsrv_plan_dirs(?string $only): array
{
    $root = dirname(__DIR__) . '/tests/fixtures/floor_scanner/corpus';
    if ($only !== null) {
        $p = str_starts_with($only, '/') ? $only : dirname(__DIR__) . '/' . ltrim($only, '/');
        if (!is_dir($p)) {
            $p = $root . '/' . basename($only);
        }

        return is_dir($p) ? [$p] : [];
    }
    $dirs = [];
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (is_file($d . '/meta.json')) {
            $dirs[] = $d;
        }
    }

    return $dirs;
}

/**
 * @param array<string, mixed> $meta
 */
function fsrv_render_variants(string $planDir, array $meta): void
{
    $empty = $planDir . '/' . ($meta['empty'] ?? 'empty.svg');
    if (!is_file($empty)) {
        // Fall back to any empty.* 
        foreach (['empty.svg', 'empty.png'] as $cand) {
            if (is_file($planDir . '/' . $cand)) {
                $empty = $planDir . '/' . $cand;
                break;
            }
        }
    }
    if (!is_file($empty)) {
        echo "skip {$planDir}: no empty drawing\n";

        return;
    }

    $blob = (string) file_get_contents($empty);
    $loaded = FloorplanImage::loadBlob($blob, basename($empty));
    $im = $loaded['image'];
    $srcW = imagesx($im);
    $srcH = imagesy($im);

    $class = (string) ($meta['class'] ?? 'office');
    $targets = $class === 'residential'
        ? [400, 900, max($srcW, $srcH)]
        : [1200, 2000, max($srcW, $srcH)];
    $targets = array_values(array_unique(array_filter($targets, static fn (int $t): bool => $t >= 200)));

    $variants = [];
    foreach ($targets as $edge) {
        $scale = $edge / max($srcW, $srcH);
        $w = max(80, (int) round($srcW * $scale));
        $h = max(80, (int) round($srcH * $scale));
        $name = "empty_{$edge}.png";
        $nativePng = $planDir . '/empty.png';
        if (
            is_file($nativePng)
            && ($info = @getimagesize($nativePng))
            && (int) $info[0] === $w
            && (int) $info[1] === $h
        ) {
            $variants[] = [
                'id' => "native_{$w}",
                'file' => 'empty.png',
                'note' => "{$w}x{$h} native",
            ];
            continue;
        }
        $out = imagecreatetruecolor($w, $h);
        if (!$out instanceof GdImage) {
            continue;
        }
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $srcW, $srcH);
        $path = $planDir . '/' . $name;
        imagepng($out, $path);
        imagedestroy($out);
        $variants[] = [
            'id' => "work_{$edge}",
            'file' => $name,
            'note' => "{$w}x{$h} render",
        ];
        echo "  wrote {$name} ({$w}x{$h})\n";
    }
    imagedestroy($im);

    if ($variants !== []) {
        $meta['raster_variants'] = $variants;
        file_put_contents(
            $planDir . '/meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}

$args = array_slice($argv, 1);
$all = in_array('--all', $args, true);
$only = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--plan=')) {
        $only = substr($arg, 7);
    }
}
if (!$all && $only === null) {
    fwrite(STDERR, "Usage: php scripts/floor_scanner_raster_variants.php --all | --plan=<dir>\n");
    exit(1);
}

foreach (fsrv_plan_dirs($all ? null : $only) as $dir) {
    $metaPath = $dir . '/meta.json';
    $meta = json_decode((string) file_get_contents($metaPath), true);
    if (!is_array($meta)) {
        echo "skip {$dir}: bad meta\n";
        continue;
    }
    echo basename($dir) . ":\n";
    fsrv_render_variants($dir, $meta);
}
