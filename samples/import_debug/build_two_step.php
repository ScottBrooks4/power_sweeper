<?php

declare(strict_types=1);

/**
 * Build the two-step CDLS VCR deliverables from the plain Studio export.
 *
 * Usage (repo root):
 *   php samples/import_debug/build_two_step.php
 *
 * Outputs (same folder):
 *   CDLS_L_VCR_step1_repair.msapp      — repair_studio_errors only
 *   CDLS_L_VCR_step2_dark_mode.msapp    — dark_mode only (from the same plain source)
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use PowerSweeper\Pipeline;

$outDir = __DIR__;
$plain = $outDir . '/CDLS (L) VCR App (4).msapp';
if (!is_file($plain)) {
    fwrite(STDERR, "Missing plain source: {$plain}\n");
    exit(1);
}

$profilesDir = dirname(__DIR__, 2) . '/profiles';
/** @var array{hops: list<array{id:string,options?:array<string,mixed>}>} $repair */
$repair = include $profilesDir . '/repair_studio_errors.php';
/** @var array{hops: list<array{id:string,options?:array<string,mixed>}>} $dark */
$dark = include $profilesDir . '/dark_mode.php';

$step1 = $outDir . '/CDLS_L_VCR_step1_repair.msapp';
$step2 = $outDir . '/CDLS_L_VCR_step2_dark_mode.msapp';

$pipeline = new Pipeline();

echo 'Source: ' . basename($plain) . "\n\n";

echo "Step 1 — repair_studio_errors → " . basename($step1) . "\n";
$r1 = $pipeline->run($plain, $repair['hops'], $step1);
echo '  changes: ' . ($r1['report']['total'] ?? 0) . "\n";
foreach ($r1['report']['by_hop'] ?? [] as $hop => $n) {
    echo "    {$hop}: {$n}\n";
}

echo "\nStep 2 — dark_mode → " . basename($step2) . "\n";
$r2 = $pipeline->run($plain, $dark['hops'], $step2);
echo '  changes: ' . ($r2['report']['total'] ?? 0) . "\n";
foreach ($r2['report']['by_hop'] ?? [] as $hop => $n) {
    echo "    {$hop}: {$n}\n";
}

echo "\nDone.\n";
echo "  1) Open " . basename($step1) . " in Studio — App Checker should be much quieter.\n";
echo "  2) Open " . basename($step2) . " in Studio — Settings → Theme → Dark (no repair pass).\n";
echo "  Or run step 1, save in Studio, then run dark_mode on that saved export.\n";
