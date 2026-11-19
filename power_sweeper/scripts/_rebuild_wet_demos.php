<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\WebApp\WebAppIrBuilder;
use PowerSweeper\WebApp\WebAppPackageBuilder;

@ini_set('memory_limit', '2048M');
@set_time_limit(0);

$root = dirname(__DIR__);
$irPath = is_file($root . '/web/_vcr_ir/power_sweeper_ir.json')
    ? $root . '/web/_vcr_ir/power_sweeper_ir.json'
    : $root . '/web/php-xml-wet/ir/power_sweeper_ir.json';
$base = json_decode((string) file_get_contents($irPath), true);
if (!is_array($base)) {
    fwrite(STDERR, "IR missing/invalid\n");
    exit(1);
}

$builder = new WebAppPackageBuilder();
$work = sys_get_temp_dir() . '/ps_wet_' . bin2hex(random_bytes(3));
mkdir($work, 0775, true);

$targets = [
    ['php', 'xml', 'wet'],
    ['php', 'sql', 'wet'],
    ['php', 'xml', 'responsive'],
];

foreach ($targets as [$rt, $st, $ly]) {
    $slug = "{$rt}-{$st}-{$ly}";
    $ir = $base;
    $ir['export'] = [
        'runtime' => WebAppIrBuilder::normalizeRuntime($rt),
        'storage' => WebAppIrBuilder::normalizeStorage($st),
        'layout' => WebAppIrBuilder::normalizeLayout($ly),
    ];
    $zip = $work . "/{$slug}.zip";
    echo "building {$slug}…\n";
    $result = $builder->build($ir, $zip, null);
    if (empty($result['ok']) || !is_file($zip)) {
        fwrite(STDERR, 'Failed: ' . ($result['message'] ?? '') . "\n");
        exit(1);
    }
    $dest = $root . '/web/' . $slug;
    if (is_dir($dest)) {
        passthru('rm -rf ' . escapeshellarg($dest));
    }
    mkdir($dest, 0775, true);
    $za = new ZipArchive();
    if ($za->open($zip) !== true) {
        fwrite(STDERR, "Cannot open {$zip}\n");
        exit(1);
    }
    $za->extractTo($dest);
    $za->close();
    @unlink($zip);

    $idx = $dest . '/public/index.html';
    $html = (string) file_get_contents($idx);
    $html = preg_replace(
        '#(assets/(?:app\.css|layout\.css|runtime\.js|app-logic\.js))(?:\?v=[^"\']*)?#',
        '$1?v=20261012k',
        $html,
    ) ?? $html;
    file_put_contents($idx, $html);

    echo 'ok ' . $slug
        . ' app-logic=' . filesize($dest . '/public/assets/app-logic.js')
        . ' ' . ($result['message'] ?? '') . "\n";
}

passthru('rm -rf ' . escapeshellarg($work));
