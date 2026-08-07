#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Run repair / powered / power↔web profiles against the multi-app sample set.
 *
 * Usage:
 *   php scripts/matrix_profiles.php [--apps=vcr,thcee,tdr,pacs,template]
 *                                   [--skip-powered] [--skip-repair] [--web]
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
$skipRepair = false;
$runWeb = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--apps=')) {
        $selected = array_values(array_filter(explode(',', substr($arg, 7))));
    }
    if ($arg === '--skip-powered') {
        $skipPowered = true;
    }
    if ($arg === '--skip-repair') {
        $skipRepair = true;
    }
    if ($arg === '--web') {
        $runWeb = true;
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

$formulaErrCount = static function (array $live): int {
    $n = 0;
    foreach ($live['findings'] as $f) {
        $rule = (string) ($f['ruleId'] ?? '');
        if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref') {
            $n++;
        }
    }

    return $n;
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
    echo 'before live=' . $before['total'] . ' formulaErr=' . $formulaErrCount($before)
        . ' | ' . $fmtRules($before['by_rule']) . "\n";

    $repairedPath = $path;
    if (!$skipRepair) {
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
            echo "repair_studio_errors: {$secs}s live={$after['total']} formulaErr="
                . $formulaErrCount($after) . ' delta=' . ($after['total'] - $before['total'])
                . ' | ' . $fmtRules($after['by_rule']) . "\n";
            $summary[$appId]['repair'] = [
                'before' => $before['total'],
                'after' => $after['total'],
                'formula_err' => $formulaErrCount($after),
                'secs' => $secs,
                'rules' => $after['by_rule'],
            ];
            $repairedPath = $out1;
        } catch (Throwable $e) {
            echo 'repair FAIL: ' . $e->getMessage() . "\n";
            $summary[$appId]['repair'] = ['error' => $e->getMessage()];
        }
    }

    if ($runWeb) {
        $powerToWeb = include dirname(__DIR__) . '/profiles/power_to_web.php';
        $webToPower = include dirname(__DIR__) . '/profiles/web_to_power.php';
        $webOut = $outDir . '/' . $appId . '.web.msapp';
        $roundOut = $outDir . '/' . $appId . '.web_roundtrip.msapp';
        try {
            $t0 = microtime(true);
            (new Pipeline())->run($repairedPath, $powerToWeb['hops'], $webOut);
            $secsWeb = round(microtime(true) - $t0, 1);
            $aw = new MsappArchive($webOut);
            $aw->unpack();
            $irPath = $aw->extractDir() . '/WebApp/power_sweeper_ir.json';
            $irStats = ['screens' => 0, 'controls' => 0, 'nav' => 0, 'datasources' => 0];
            if (is_file($irPath)) {
                $ir = json_decode((string) file_get_contents($irPath), true);
                $irStats = [
                    'screens' => (int) ($ir['stats']['screens'] ?? 0),
                    'controls' => (int) ($ir['stats']['controls'] ?? 0),
                    'nav' => (int) ($ir['stats']['navigation_edges'] ?? 0),
                    'datasources' => count($ir['datasources'] ?? []),
                ];
            }
            $props = json_decode((string) file_get_contents($aw->extractDir() . '/Properties.json'), true);
            $scaleWeb = $props['DocumentLayoutScaleToFit'] ?? null;
            $liveWeb = StudioLiveChecker::check($aw->documents(), ['extract_dir' => $aw->extractDir()]);
            $aw->cleanup();

            $t0 = microtime(true);
            (new Pipeline())->run($webOut, $webToPower['hops'], $roundOut);
            $secsRound = round(microtime(true) - $t0, 1);
            $ar = new MsappArchive($roundOut);
            $ar->unpack();
            $props2 = json_decode((string) file_get_contents($ar->extractDir() . '/Properties.json'), true);
            $scaleBack = $props2['DocumentLayoutScaleToFit'] ?? null;
            $liveRound = StudioLiveChecker::check($ar->documents(), ['extract_dir' => $ar->extractDir()]);
            $ar->cleanup();

            echo "power_to_web: {$secsWeb}s live={$liveWeb['total']} formulaErr="
                . $formulaErrCount($liveWeb) . ' ir=' . json_encode($irStats)
                . ' scaleToFit=' . json_encode($scaleWeb) . "\n";
            echo "web_to_power: {$secsRound}s live={$liveRound['total']} formulaErr="
                . $formulaErrCount($liveRound) . ' scaleToFit=' . json_encode($scaleBack)
                . ' | ' . $fmtRules($liveRound['by_rule']) . "\n";

            $summary[$appId]['web'] = [
                'power_to_web' => [
                    'secs' => $secsWeb,
                    'live' => $liveWeb['total'],
                    'formula_err' => $formulaErrCount($liveWeb),
                    'ir' => $irStats,
                    'scale_to_fit' => $scaleWeb,
                ],
                'web_to_power' => [
                    'secs' => $secsRound,
                    'live' => $liveRound['total'],
                    'formula_err' => $formulaErrCount($liveRound),
                    'scale_to_fit' => $scaleBack,
                    'rules' => $liveRound['by_rule'],
                ],
            ];
        } catch (Throwable $e) {
            echo 'web FAIL: ' . $e->getMessage() . "\n";
            $summary[$appId]['web'] = ['error' => $e->getMessage()];
        }
    }

    if ($skipPowered) {
        continue;
    }

    $powered = $loader->resolvePoweredProfile($path);
    $profileName = basename((string) ($powered['id'] ?? 'repair_powered'));
    if ($profileName === 'repair_powered' || !isset($powered['id'])) {
        $profileName = 'repair_powered';
    }
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
        echo "powered ({$profileName}): {$secs}s live={$after['total']} theme="
            . ($hasTheme ? 'Y' : 'N') . ' | ' . $fmtRules($after['by_rule']) . "\n";
        $summary[$appId]['powered'] = [
            'before' => $before['total'],
            'after' => $after['total'],
            'secs' => $secs,
            'theme' => $hasTheme,
            'rules' => $after['by_rule'],
            'profile' => $profileName,
        ];
    } catch (Throwable $e) {
        echo 'powered FAIL: ' . $e->getMessage() . "\n";
        $summary[$appId]['powered'] = ['error' => $e->getMessage()];
    }
}

file_put_contents($outDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT));
echo "\nWrote {$outDir}/summary.json\nDONE\n";
