<?php

declare(strict_types=1);

/**
 * Build the CDLS VCR deliverables from the plain Studio export.
 *
 * Usage (repo root):
 *   php samples/import_debug/build_two_step.php
 *
 * Outputs (same folder):
 *   CDLS_L_VCR_step1_repair.msapp           — studio repair only
 *   CDLS_L_VCR_step2_dark_mode.msapp        — dark mode only (same plain source)
 *   CDLS_L_VCR_step3_repair_then_dark.msapp — recommended production (repair → dark)
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use PowerSweeper\HopChains;
use PowerSweeper\Pipeline;

$outDir = __DIR__;
$plain = $outDir . '/CDLS (L) VCR App (4).msapp';
if (!is_file($plain)) {
    fwrite(STDERR, "Missing plain source: {$plain}\n");
    exit(1);
}

$repair = HopChains::studioRepair();
$dark = HopChains::darkMode();

$step1 = $outDir . '/CDLS_L_VCR_step1_repair.msapp';
$step2 = $outDir . '/CDLS_L_VCR_step2_dark_mode.msapp';
$step3 = $outDir . '/CDLS_L_VCR_step3_repair_then_dark.msapp';

$pipeline = new Pipeline();

echo 'Source: ' . basename($plain) . "\n\n";

echo "Step 1 — studio_repair → " . basename($step1) . "\n";
$r1 = $pipeline->run($plain, $repair, $step1);
echo '  changes: ' . ($r1['report']['total'] ?? 0) . "\n";

echo "\nStep 2 — dark_mode → " . basename($step2) . "\n";
$r2 = $pipeline->run($plain, $dark, $step2);
echo '  changes: ' . ($r2['report']['total'] ?? 0) . "\n";

echo "\nStep 3 — repair then dark (recommended) → " . basename($step3) . "\n";
$r3 = $pipeline->run($plain, array_merge($repair, $dark), $step3);
echo '  changes: ' . ($r3['report']['total'] ?? 0) . "\n";

echo "\nDone.\n";
echo "  Test repair only:     " . basename($step1) . "\n";
echo "  Test dark only:       " . basename($step2) . " (Settings → Theme → Dark)\n";
echo "  Recommended import:   " . basename($step3) . "\n";
