<?php

declare(strict_types=1);

/**
 * Generate + pack the German-locale corruption sample, optionally run unwhack_locale.
 *
 * Usage:
 *   php samples/locale_german_corrupt/build.php
 *   php samples/locale_german_corrupt/build.php --with-unwhack
 *   php samples/locale_german_corrupt/build.php --screens=30 --controls=50 --with-unwhack
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use PowerSweeper\Pipeline;
use PowerSweeper\ZipTool;

$root = __DIR__;
$withUnwhack = in_array('--with-unwhack', $argv, true);

$genArgs = array_values(array_filter(
    $argv,
    static fn(string $a): bool => str_starts_with($a, '--screens=') || str_starts_with($a, '--controls=')
));
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/generate.php');
foreach ($genArgs as $arg) {
    $cmd .= ' ' . escapeshellarg($arg);
}
passthru($cmd, $code);
if ($code !== 0) {
    fwrite(STDERR, "generate.php failed\n");
    exit($code);
}

$stage = sys_get_temp_dir() . '/ps_locale_' . bin2hex(random_bytes(4));
mkdir($stage . '/Src', 0777, true);
mkdir($stage . '/Controls', 0777, true);
foreach (glob($root . '/Src/*') ?: [] as $file) {
    copy($file, $stage . '/Src/' . basename($file));
}
foreach (glob($root . '/Controls/*') ?: [] as $file) {
    copy($file, $stage . '/Controls/' . basename($file));
}

$outCorrupt = $root . '/locale_german_corrupt.msapp';
ZipTool::createFromDirectory($stage, $outCorrupt);
echo "Wrote {$outCorrupt}\n";

if ($withUnwhack) {
    $outFixed = $root . '/locale_german_corrupt.fixed.msapp';
    $result = (new Pipeline())->run($outCorrupt, [
        ['id' => 'unwhack_locale_formulas'],
    ], $outFixed);
    $total = (int) ($result['report']['total'] ?? 0);
    echo "Wrote {$outFixed}\n";
    echo "Unwhack changes: {$total}\n";
    $reportPath = $root . '/locale_german_corrupt.fixed.report.json';
    // Keep report summary compact — full entry list can be huge
    $summary = [
        'total' => $total,
        'by_hop' => $result['report']['by_hop'] ?? [],
        'sample_entries' => array_slice($result['report']['entries'] ?? [], 0, 40),
    ];
    file_put_contents($reportPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "Wrote {$reportPath} (summary + 40 sample entries)\n";

    // Spot-check: no EU separators left in a packed screen
    $yaml = ZipTool::readEntry($outFixed, 'Src/Screen1.pa.yaml') ?? '';
    $json = ZipTool::readEntry($outFixed, 'Controls/Screen1.json') ?? '';
    $yamlBad = preg_match('/RGBA\(\d+;\s*\d+/', $yaml) === 1;
    $jsonBad = preg_match('/RGBA\(\d+;\s*\d+/', $json) === 1;
    echo 'Screen1 YAML still has RGBA(;): ' . ($yamlBad ? 'YES (bad)' : 'no') . "\n";
    echo 'Screen1 JSON still has RGBA(;): ' . ($jsonBad ? 'YES (bad)' : 'no') . "\n";
    echo 'Screen1 YAML has invariant RGBA(,): ' . (str_contains($yaml, 'RGBA(255, 255, 255, 1)') || str_contains($yaml, 'RGBA(255,255,255,1)') || preg_match('/RGBA\(\d+, \d+, \d+/', $yaml) ? 'yes' : 'no') . "\n";
    echo 'Screen1 chaining fixed (; not ;;): ' . (!str_contains($yaml, ';;') && str_contains($yaml, 'Set(gblScreen,') ? 'yes' : 'check manually') . "\n";
}

// cleanup stage
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $file) {
    /** @var SplFileInfo $file */
    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
}
@rmdir($stage);
