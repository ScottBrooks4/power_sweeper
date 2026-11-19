#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Honest multi-app sweep statistics.
 *
 * Prints three views that must not be conflated:
 *   1) Source Studio SARIF  — what Studio last wrote into the .msapp
 *   2) Live checker         — what Power Sweeper can detect (partial)
 *   3) After powered        — live a11y/formula + embedded SARIF after merge-write
 *
 * Usage:
 *   php scripts/sweep_stats.php
 *   php scripts/sweep_stats.php --apps=vcr,thcee,tdr
 *   php scripts/sweep_stats.php --skip-run   # measure source only
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\MsappArchive;
use PowerSweeper\Pipeline;
use PowerSweeper\StudioErrorDetector;
use PowerSweeper\StudioLiveChecker;
use PowerSweeper\SweepChains;

$allApps = [
    'vcr' => dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App repair2.msapp',
    'thcee' => dirname(__DIR__) . '/samples/import_debug/VCDS — THCEE.msapp',
    'tdr' => dirname(__DIR__) . '/samples/import_debug/TDR - THCEE Directory App.msapp',
    'pacs' => dirname(__DIR__) . '/samples/import_debug/VCDS ASC - Pass Accountability and Control System (PACS).msapp',
    'template' => dirname(__DIR__) . '/samples/import_debug/VCDS ASC —Template with Approvals.msapp',
    'saint' => dirname(__DIR__) . '/samples/import_debug/VCDS ASC — The SAINT Catalog.msapp',
    'teampulse' => dirname(__DIR__) . '/samples/import_debug/Team Pulse.msapp',
];

$selected = array_keys($allApps);
$skipRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--apps=')) {
        $selected = array_values(array_filter(explode(',', substr($arg, 7))));
    }
    if ($arg === '--skip-run') {
        $skipRun = true;
    }
}

$outDir = dirname(__DIR__) . '/storage/benchmarks/sweep_stats_' . date('Ymd_His');
mkdir($outDir, 0775, true);

/**
 * @param array{by_rule?:array<string,int>,findings?:list<array<string,mixed>>,total?:int,by_category?:array<string,int>} $live
 * @return array{total:int,acc:int,tab:int,label:int,focus:int,formula_err:int,perf:int,maint:int}
 */
$liveStats = static function (array $live): array {
    $byRule = $live['by_rule'] ?? [];
    $ferr = 0;
    foreach ($live['findings'] ?? [] as $f) {
        $rule = (string) ($f['ruleId'] ?? '');
        if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref') {
            $ferr++;
        }
    }
    $cats = $live['by_category'] ?? [];

    return [
        'total' => (int) ($live['total'] ?? 0),
        'acc' => (int) ($cats['accessibility'] ?? 0),
        'tab' => (int) ($byRule['acc-TabIndexShouldBeDefinedForInteractiveControl'] ?? 0),
        'label' => (int) ($byRule['acc-AccessibleLabelNeeded'] ?? 0),
        'focus' => (int) ($byRule['acc-FocusBorderShouldBeVisible'] ?? 0),
        'formula_err' => $ferr,
        'perf' => (int) ($cats['performance'] ?? 0),
        'maint' => (int) ($cats['maintainability'] ?? 0),
    ];
};

/**
 * @return array{total:int,acc:int,formula:int,perf:int,maint:int,other:int,by_rule:array<string,int>}
 */
$studioStats = static function (string $msapp): array {
    $emb = StudioErrorDetector::detectFromMsapp($msapp, false);
    $acc = $frm = $prf = $mnt = $oth = 0;
    foreach ($emb['by_rule'] ?? [] as $rule => $n) {
        $n = (int) $n;
        if (str_starts_with($rule, 'acc-')) {
            $acc += $n;
        } elseif (str_starts_with($rule, 'app-Err') || str_starts_with($rule, 'app-Warn')) {
            $frm += $n;
        } elseif (
            str_contains($rule, 'Inefficient')
            || str_contains($rule, 'SuggestRemote')
            || str_contains($rule, 'MaxRows')
            || str_contains($rule, 'DelayOutput')
            || str_contains($rule, 'NumericProperty')
            || str_contains($rule, 'CountRows')
        ) {
            $prf += $n;
        } elseif (
            str_starts_with($rule, 'app-Unused')
            || str_contains($rule, 'CrossScreen')
            || str_contains($rule, 'Collecting')
            || str_contains($rule, 'ForAll')
            || str_contains($rule, 'ScreenHasMany')
        ) {
            $mnt += $n;
        } else {
            $oth += $n;
        }
    }

    return [
        'total' => (int) ($emb['total'] ?? 0),
        'acc' => $acc,
        'formula' => $frm,
        'perf' => $prf,
        'maint' => $mnt,
        'other' => $oth,
        'by_rule' => $emb['by_rule'] ?? [],
        'sarif_present' => (bool) ($emb['sarif_present'] ?? false),
    ];
};

/**
 * @param list<\PowerSweeper\ControlDocument> $docs
 * @return array{interactive:int,tab_gap:int,label_gap:int,focus_gap:int}
 */
$propGaps = static function (array $docs): array {
    $interactive = $tab = $lab = $focus = 0;
    foreach ($docs as $d) {
        foreach ($d->controls() as $c) {
            if (!$c->isAccessibilityInteractive()) {
                continue;
            }
            $interactive++;
            $tv = $c->tabIndexValue();
            $tp = $c->getProperty('TabIndex');
            if ($tp === null || trim($tp) === '' || ($tv !== null && $tv < 0)) {
                $tab++;
            }
            $al = $c->getProperty('AccessibleLabel');
            $t = $al === null ? '' : trim(ltrim(trim($al), '='));
            if ($t === '' || $t === '""') {
                $lab++;
            }
            $ft = $c->getProperty('FocusedBorderThickness');
            $f = $ft === null ? '' : strtolower(trim(ltrim(trim($ft), '=')));
            if ($f === '' || $f === '0' || $f === 'false') {
                $focus++;
            }
        }
    }

    return [
        'interactive' => $interactive,
        'tab_gap' => $tab,
        'label_gap' => $lab,
        'focus_gap' => $focus,
    ];
};

$summary = [];

echo "HONEST SWEEP STATS\n";
echo "Live checker ≠ Studio. Embedded SARIF after sweeps merges preserved Studio findings.\n\n";

printf(
    "%-10s %-8s %7s %7s %7s %7s %7s | %7s %7s %7s %7s\n",
    'app',
    'view',
    'total',
    'acc',
    'formula',
    'perf',
    'maint',
    'tab',
    'label',
    'focus',
    'ferr'
);
echo str_repeat('-', 110) . "\n";

foreach ($selected as $appId) {
    if (!isset($allApps[$appId])) {
        fwrite(STDERR, "Unknown app: {$appId}\n");
        continue;
    }
    $path = $allApps[$appId];
    if (!is_file($path)) {
        fwrite(STDERR, "Missing: {$path}\n");
        continue;
    }

    $studio = $studioStats($path);
    $arch = new MsappArchive($path);
    $arch->unpack();
    $liveBefore = StudioLiveChecker::check($arch->documents(), ['extract_dir' => $arch->extractDir()]);
    $gapsBefore = $propGaps($arch->documents());
    $arch->cleanup();
    $lb = $liveStats($liveBefore);

    printf(
        "%-10s %-8s %7d %7d %7d %7d %7d | %7d %7d %7d %7d\n",
        $appId,
        'studio',
        $studio['total'],
        $studio['acc'],
        $studio['formula'],
        $studio['perf'],
        $studio['maint'],
        (int) ($studio['by_rule']['acc-TabIndexShouldBeDefinedForInteractiveControl'] ?? 0),
        (int) ($studio['by_rule']['acc-AccessibleLabelNeeded'] ?? 0),
        (int) ($studio['by_rule']['acc-FocusBorderShouldBeVisible'] ?? 0),
        $studio['formula']
    );
    printf(
        "%-10s %-8s %7d %7d %7d %7d %7d | %7d %7d %7d %7d\n",
        $appId,
        'live',
        $lb['total'],
        $lb['acc'],
        $lb['formula_err'],
        $lb['perf'],
        $lb['maint'],
        $lb['tab'],
        $lb['label'],
        $lb['focus'],
        $lb['formula_err']
    );

    $summary[$appId] = [
        'source' => $path,
        'studio_sarif' => $studio,
        'live_before' => $lb,
        'property_gaps_before' => $gapsBefore,
        'coverage_gap_formulas' => max(0, $studio['formula'] - $lb['formula_err']),
    ];

    if ($skipRun) {
        continue;
    }

    $poweredPath = $outDir . '/' . $appId . '.powered.msapp';
    $t0 = microtime(true);
    try {
        (new Pipeline())->run($path, SweepChains::powered(), $poweredPath);
        $secs = round(microtime(true) - $t0, 1);
    } catch (Throwable $e) {
        echo "{$appId} powered FAIL: " . $e->getMessage() . "\n";
        $summary[$appId]['powered'] = ['error' => $e->getMessage()];
        continue;
    }

    $a2 = new MsappArchive($poweredPath);
    $a2->unpack();
    $liveAfter = StudioLiveChecker::check($a2->documents(), ['extract_dir' => $a2->extractDir()]);
    $gapsAfter = $propGaps($a2->documents());
    $a2->cleanup();
    $la = $liveStats($liveAfter);
    $studioAfter = $studioStats($poweredPath);

    printf(
        "%-10s %-8s %7d %7d %7d %7d %7d | %7d %7d %7d %7d  (%ss live)\n",
        $appId,
        'aftLive',
        $la['total'],
        $la['acc'],
        $la['formula_err'],
        $la['perf'],
        $la['maint'],
        $la['tab'],
        $la['label'],
        $la['focus'],
        $la['formula_err'],
        $secs
    );
    printf(
        "%-10s %-8s %7d %7d %7d %7d %7d | %7s %7s %7s %7s  (embedded after merge)\n",
        $appId,
        'aftSARIF',
        $studioAfter['total'],
        $studioAfter['acc'],
        $studioAfter['formula'],
        $studioAfter['perf'],
        $studioAfter['maint'],
        '-',
        '-',
        '-',
        $studioAfter['formula']
    );

    $falseZero = $la['total'] === 0 && $studioAfter['total'] > 0;
    if ($falseZero) {
        echo "{$appId} NOTE: live checker is 0 but embedded SARIF still has {$studioAfter['total']} (preserved Studio findings) — do not tell users the app is clean.\n";
    }

    $summary[$appId]['powered'] = [
        'secs' => $secs,
        'msapp' => $poweredPath,
        'live_after' => $la,
        'embedded_sarif_after' => $studioAfter,
        'property_gaps_after' => $gapsAfter,
        'do_not_claim_studio_clean' => $studioAfter['total'] > 0,
    ];
    file_put_contents($outDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT));
}

file_put_contents($outDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT));
echo "\nWrote {$outDir}/summary.json\n";
