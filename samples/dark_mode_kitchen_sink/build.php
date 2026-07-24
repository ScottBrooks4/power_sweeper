<?php

declare(strict_types=1);

/**
 * Pack this kitchen-sink sample into a .msapp for Power Sweeper testing.
 *
 * Usage:
 *   php samples/dark_mode_kitchen_sink/build.php
 *   php samples/dark_mode_kitchen_sink/build.php --with-dark-mode
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use PowerSweeper\Pipeline;
use PowerSweeper\ZipTool;

$root = __DIR__;
$srcDir = $root . '/Src';
$outPlain = $root . '/dark_mode_kitchen_sink.msapp';
$outDark = $root . '/dark_mode_kitchen_sink.dark.msapp';
$withDark = in_array('--with-dark-mode', $argv, true);

if (!is_dir($srcDir)) {
    fwrite(STDERR, "Missing Src directory\n");
    exit(1);
}

$stage = sys_get_temp_dir() . '/ps_kms_' . bin2hex(random_bytes(4));
mkdir($stage . '/Src', 0777, true);
foreach (glob($srcDir . '/*.pa.yaml') ?: [] as $file) {
    copy($file, $stage . '/Src/' . basename($file));
}

ZipTool::createFromDirectory($stage, $outPlain);
echo "Wrote {$outPlain}\n";

if ($withDark) {
    $result = (new Pipeline())->run($outPlain, [
        ['id' => 'enable_dark_mode'],
    ], $outDark);
    echo "Wrote {$outDark}\n";
    echo 'Dark-mode changes: ' . ($result['report']['total'] ?? 0) . "\n";
    $reportPath = $root . '/dark_mode_kitchen_sink.dark.report.json';
    file_put_contents($reportPath, json_encode($result['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "Wrote {$reportPath}\n";
}

// cleanup stage
foreach (glob($stage . '/Src/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($stage . '/Src');
@rmdir($stage);
