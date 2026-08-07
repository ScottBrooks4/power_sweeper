#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Run repair_studio_errors + powered profiles against the multi-app sample set.
 *
 * Usage: php scripts/matrix_profiles.php [--apps=vcr,thcee,tdr,pacs,template] [--skip-powered]
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\MsappArchive;
use PowerSweeper\Pipeline;
use PowerSweeper\ProfileLoader;
use PowerSweeper\StudioLiveChecker;

$allApps = [
    'vcr' => dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App repair2.msapp',
    'thcee' => dirname(__DIR__) . '/samples/import_debug/VCDS — THCEE.msapp',
    'tdr' => dirname(__DIR__) . '/samples/import_debug/TDR - THCEE Directory App.msapp',
    'pacs' => dirname(__DIR__) . '/samples/import_debug/VCDS ASC - Pass Accountability and Control System (PACS).msapp',
    'template' => dirname(__DIR__) . '/samples/import_debug/VCDS ASC —Template with Approvals.msapp',
];

$selected = array_keys($allApps);
$skipPowered = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--apps=')) {
        $selected = array_values(array_filter(explode(',', substr($arg, 7))));
    }
    if ($arg === '--skip-powered') {
        $skipPowered = true;
    }
}

$outDir = sys_get_temp_dir() . '/ps_matrix_' . date('Ymd_His');
mkdir($outDir, 0775, true);
$loader = new ProfileLoader(POWER_SWEEPER_PROFILES);
$summary = [];

$fmtRules = static function (array $byRule): string {
    arsort($byRule);
    $parts = [];
    foreach (array_slice($byRule, 0, 8, true) as $k => $v) {
        $parts[] = "{$k}={$v}";
    }

    return implode(', ', $parts);
};

foreach ($selected as $appId) {
    if (!isset($allApps[$appId])) {
        fwrite(STDERR, "Unknown app id: {$appId}\n");
        continue;
    }
    $path = $allApps[$appId];
    if (!is_file($path)) {
        fwrite(STDERR, "Missing: {$path}\n");
        continue;
    }

    echo "\n==== {$appId}: " . basename($path) . " ====\n";
    $arch = new MsappArchive($path);
    $arch->unpack();
    $before = StudioLiveChecker::check($arch->documents(), ['extract_dir' => $arch->extractDir()]);
    $arch->cleanup();
    echo 'before live=' . $before['total'] . ' | ' . $fmtRules($before['by_rule']) . "\n";

    $repairProf = include dirname(__DIR__) . '/profiles/repair_studio_errors.php';
    $out1 = $outDir . '/' . $appId . '.repaired.msapp';
    $t0 = microtime(true);
    try {
        (new Pipeline())->run($path, $repairProf['hops'], $out1);
        $secs = round(microtime(true) - $t0, 1);
        $a2 = new MsappArchive($out1);
        $a2->unpack();
        $after = StudioLiveChecker::check($a2->documents(), ['extract_dir' => $a2->extractDir()]);
        $a2->cleanup();
        echo "repair_studio_errors: {$secs}s live={$after['total']} delta="
            . ($after['total'] - $before['total']) . ' | ' . $fmtRules($after['by_rule']) . "\n";
        $summary[$appId]['repair'] = [
            'before' => $before['total'],
            'after' => $after['total'],
            'secs' => $secs,
            'rules' => $after['by_rule'],
        ];
    } catch (Throwable $e) {
        echo 'repair FAIL: ' . $e->getMessage() . "\n";
        $summary[$appId]['repair'] = ['error' => $e->getMessage()];
    }

    if ($skipPowered) {
        continue;
    }

    $powered = $loader->resolvePoweredProfile($path);
    $appClass = (string) ($powered['app_class'] ?? '?');
    $out2 = $outDir . '/' . $appId . '.powered.msapp';
    $t0 = microtime(true);
    try {
        (new Pipeline())->run($path, $powered['hops'], $out2);
        $secs = round(microtime(true) - $t0, 1);
        $a3 = new MsappArchive($out2);
        $a3->unpack();
        $after = StudioLiveChecker::check($a3->documents(), ['extract_dir' => $a3->extractDir()]);
        $hasTheme = false;
        foreach ($a3->documents() as $d) {
            foreach ($d->controls() as $c) {
                if ($c->isApp() && str_contains((string) $c->getProperty('OnStart'), 'gblThemeLight')) {
                    $hasTheme = true;
                }
            }
        }
        $a3->cleanup();
        echo "powered ({$appClass}): {$secs}s live={$after['total']} theme="
            . ($hasTheme ? 'Y' : 'N') . ' | ' . $fmtRules($after['by_rule']) . "\n";
        $summary[$appId]['powered'] = [
            'before' => $before['total'],
            'after' => $after['total'],
            'secs' => $secs,
            'theme' => $hasTheme,
            'rules' => $after['by_rule'],
            'profile' => $appClass,
        ];
    } catch (Throwable $e) {
        echo 'powered FAIL: ' . $e->getMessage() . "\n";
        $summary[$appId]['powered'] = ['error' => $e->getMessage()];
    }
}

file_put_contents($outDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT));
echo "\nWrote {$outDir}/summary.json\nDONE\n";
