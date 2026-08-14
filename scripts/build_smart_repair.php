#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build a smart-repaired .msapp (meaningful names + full repair).
 *
 * Usage:
 *   php scripts/build_smart_repair.php [input.msapp] [output.msapp]
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\HopChains;

$input = $argv[1] ?? null;
$output = $argv[2] ?? null;

if ($input === null || !is_file($input)) {
    fwrite(STDERR, "Usage: php scripts/build_smart_repair.php input.msapp [output.msapp]\n");
    exit(1);
}

if ($output === null) {
    $base = pathinfo($input, PATHINFO_FILENAME);
    $base = preg_replace('/\.(repaired|powered|smart)$/i', '', $base) ?? $base;
    $output = dirname($input) . '/' . preg_replace('/[^A-Za-z0-9_]+/', '_', $base) . '.smart.msapp';
}

(new PowerSweeper\Pipeline())->run($input, HopChains::smartRepair(), $output);

$arch = new PowerSweeper\MsappArchive($output);
$arch->unpack();
$live = PowerSweeper\StudioLiveChecker::check($arch->documents(), ['extract_dir' => $arch->extractDir()]);
$arch->cleanup();

$formulaErr = 0;
foreach ($live['findings'] as $f) {
    $rule = (string) ($f['ruleId'] ?? '');
    if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref') {
        $formulaErr++;
    }
}

echo "Built: {$output}\n";
echo "Live checker total: {$live['total']} (formula errors: {$formulaErr})\n";
