#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build a smart-repaired .msapp (meaningful names + full repair + converge loop).
 *
 * Usage:
 *   php scripts/build_smart_repair.php [input.msapp] [output.msapp]
 */

require_once dirname(__DIR__) . '/bootstrap.php';

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

$profile = include dirname(__DIR__) . '/profiles/repair_smart.php';
(new PowerSweeper\Pipeline())->run($input, $profile['hops'], $output);

$arch = new PowerSweeper\MsappArchive($output);
$arch->unpack();
$live = PowerSweeper\StudioLiveChecker::check($arch->documents(), ['extract_dir' => $arch->extractDir()]);
$arch->cleanup();

echo "Built: {$output}\n";
echo "Live checker total: {$live['total']} (formula errors: " . self::formulaErrors($live) . ")\n";

/** @param array<string,mixed> $live */
function formulaErrors(array $live): int
{
    $n = 0;
    foreach ($live['findings'] as $finding) {
        $rule = (string) ($finding['ruleId'] ?? '');
        if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref') {
            $n++;
        }
    }

    return $n;
}
