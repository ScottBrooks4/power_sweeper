#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build *.repaired.msapp deliverables (repair_studio_errors only, fresh SARIF).
 *
 * Usage:
 *   php scripts/build_repaired.php [input.msapp] [output.msapp]
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\ProfileLoader;

$input = $argv[1] ?? dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App repair2.msapp';
$output = $argv[2] ?? null;

if (!is_file($input)) {
    fwrite(STDERR, "Input not found: {$input}\n");
    exit(1);
}

if ($output === null) {
    $base = pathinfo($input, PATHINFO_FILENAME);
    $base = preg_replace('/\.(repaired|powered)$/i', '', $base) ?? $base;
    $output = dirname($input) . '/' . preg_replace('/[^A-Za-z0-9_]+/', '_', $base) . '.repaired.msapp';
}

$profile = include dirname(__DIR__) . '/profiles/repair_studio_errors.php';
$loader = new ProfileLoader(dirname(__DIR__) . '/profiles');
(new PowerSweeper\Pipeline())->run($input, $loader->resolveHops($profile), $output);

$arch = new PowerSweeper\MsappArchive($output);
$arch->unpack();
$live = PowerSweeper\StudioLiveChecker::check($arch->documents(), ['extract_dir' => $arch->extractDir()]);
$embedded = PowerSweeper\StudioErrorDetector::detectFromMsapp($output, false);
$arch->cleanup();

echo "Built: {$output}\n";
echo "Live checker total: {$live['total']} (formulas: " . ($live['by_category']['formulas'] ?? 0) . ")\n";
echo "Embedded SARIF total: " . ($embedded['total'] ?? 0) . " (formulas: " . ($embedded['by_category']['formulas'] ?? 0) . ")\n";
