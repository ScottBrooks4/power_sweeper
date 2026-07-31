#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\StudioErrorDetector;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/detect_studio_errors.php <path-to.msapp> [--no-heuristic] [--out <report.json>]\n");
    exit(1);
}

$msappPath = $argv[1];
$outPath = null;
$includeHeuristic = true;

for ($i = 2; $i < $argc; $i++) {
    if ($argv[$i] === '--no-heuristic') {
        $includeHeuristic = false;
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

$report = StudioErrorDetector::detectFromMsapp($msappPath, $includeHeuristic);
$summary = StudioErrorDetector::formatSummary($report);

echo $summary;

if ($outPath !== null) {
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "Failed to encode JSON report\n");
        exit(1);
    }
    file_put_contents($outPath, $json);
    $summaryPath = preg_replace('/\.json$/', '.txt', $outPath) ?? ($outPath . '.txt');
    file_put_contents($summaryPath, $summary);
    fwrite(STDERR, "Wrote {$outPath}\n");
    fwrite(STDERR, "Wrote {$summaryPath}\n");
}
