#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build both Friday *.powered.msapp deliverables and run validate_powered.php.
 *
 * Usage:
 *   php scripts/build_friday_deliverables.php
 *
 * Expects source uploads (not committed by default):
 *   samples/import_debug/CDLS (L) VCR App Friday.msapp
 *   samples/import_debug/VCDS — THCEE Friday.msapp
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__) . '/samples/import_debug';
$jobs = [
    [
        'input' => $root . '/CDLS (L) VCR App Friday.msapp',
        'output' => $root . '/CDLS_VCR_App_Friday.powered.msapp',
        'label' => 'VCR Friday',
    ],
    [
        'input' => $root . '/VCDS — THCEE Friday.msapp',
        'output' => $root . '/VCDS_THCEE_Friday.powered.msapp',
        'label' => 'THCEE Friday',
    ],
];

$failed = 0;
foreach ($jobs as $job) {
    if (!is_file($job['input'])) {
        fwrite(STDERR, "SKIP {$job['label']}: source not found — {$job['input']}\n");
        $failed++;
        continue;
    }

    echo str_repeat('=', 60) . "\n";
    echo "Building {$job['label']}…\n";
    passthru(
        'php ' . escapeshellarg(dirname(__DIR__) . '/scripts/build_powered.php')
            . ' ' . escapeshellarg($job['input'])
            . ' ' . escapeshellarg($job['output']),
        $code
    );
    if ($code !== 0) {
        fwrite(STDERR, "FAIL build {$job['label']}\n");
        $failed++;
        continue;
    }

    echo "\nValidating {$job['label']}…\n";
    passthru(
        'php ' . escapeshellarg(dirname(__DIR__) . '/scripts/validate_powered.php')
            . ' ' . escapeshellarg($job['output']),
        $vcode
    );
    if ($vcode !== 0) {
        fwrite(STDERR, "FAIL validate {$job['label']}\n");
        $failed++;
    }
    echo "\n";
}

if ($failed > 0) {
    fwrite(STDERR, "Completed with {$failed} failure(s).\n");
    exit(1);
}

echo "Both Friday deliverables built and validated.\n";
exit(0);
