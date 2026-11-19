#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\MsappArchive;
use PowerSweeper\StudioErrorDetector;
use PowerSweeper\StudioLiveChecker;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/run_live_checker.php <path-to.msapp> [--write] [--compare] [--out <report.json>]\n");
    fwrite(STDERR, "  --write   Regenerate AppCheckerResult.sarif inside the .msapp (creates <name>.checked.msapp)\n");
    fwrite(STDERR, "  --compare Compare live findings to embedded SARIF (if present)\n");
    exit(1);
}

$msappPath = $argv[1];
$write = false;
$compare = false;
$outPath = null;

for ($i = 2; $i < $argc; $i++) {
    if ($argv[$i] === '--write') {
        $write = true;
        continue;
    }
    if ($argv[$i] === '--compare') {
        $compare = true;
        continue;
    }
    if ($argv[$i] === '--out' && isset($argv[$i + 1])) {
        $outPath = $argv[++$i];
        continue;
    }
    fwrite(STDERR, "Unknown option: {$argv[$i]}\n");
    exit(1);
}

if (!is_file($msappPath)) {
    fwrite(STDERR, "File not found: {$msappPath}\n");
    exit(1);
}

$archive = new MsappArchive($msappPath);
try {
    $archive->unpack();
    $report = StudioLiveChecker::check($archive->documents(), ['extract_dir' => $archive->extractDir()]);

    echo "Live App checker — " . basename($msappPath) . "\n";
    echo str_repeat('=', 60) . "\n";
    echo 'TOTAL ISSUES: ' . $report['total'] . "\n\n";

    echo "BY CATEGORY:\n";
    foreach ($report['by_category'] as $cat => $count) {
        echo sprintf("  %-18s %4d\n", ucfirst($cat) . ':', $count);
    }
    echo "\nBY RULE:\n";
    foreach ($report['by_rule'] as $rule => $count) {
        echo sprintf("  %4d  %s\n", $count, $rule);
    }

    if ($compare) {
        $embedded = StudioErrorDetector::detectFromMsapp($msappPath, false);
        echo "\nCOMPARE TO EMBEDDED SARIF:\n";
        echo '  Embedded total: ' . ($embedded['total'] ?? 0) . ' (sarif_present=' . (($embedded['sarif_present'] ?? false) ? 'yes' : 'no') . ")\n";
        echo '  Live total:     ' . $report['total'] . "\n";
        if (($embedded['sarif_present'] ?? false) && ($embedded['total'] ?? 0) > 0) {
            $embLoc = [];
            foreach ($embedded['issues'] as $issue) {
                $embLoc[$issue['ruleId'] . '|' . $issue['location']] = true;
            }
            $overlap = 0;
            foreach ($report['findings'] as $f) {
                if (isset($embLoc[$f['ruleId'] . '|' . $f['location']])) {
                    $overlap++;
                }
            }
            $pct = round(100 * $overlap / max(1, $report['total']), 1);
            echo "  Rule+location overlap: {$overlap} ({$pct}% of live findings)\n";
        }
    }

    if ($write) {
        StudioLiveChecker::writeSarifToExtractDir($archive->documents(), $archive->extractDir());
        $outMsapp = preg_replace('/\.msapp$/', '.checked.msapp', $msappPath) ?? ($msappPath . '.checked.msapp');
        $archive->pack($outMsapp);
        echo "\nWrote fresh SARIF and packed: {$outMsapp}\n";
    }

    if ($outPath !== null) {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            fwrite(STDERR, "Failed to encode JSON report\n");
            exit(1);
        }
        file_put_contents($outPath, $json);
        fwrite(STDERR, "Wrote {$outPath}\n");
    }
} finally {
    $archive->cleanup();
}
