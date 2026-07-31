#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build *.powered.msapp deliverables (repair_studio_errors + dark mode).
 *
 * Usage:
 *   php scripts/build_powered.php [input.msapp] [output.msapp]
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$input = $argv[1] ?? dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App repair2.msapp';
$output = $argv[2] ?? null;

if (!is_file($input)) {
    fwrite(STDERR, "Input not found: {$input}\n");
    exit(1);
}

if ($output === null) {
    $base = pathinfo($input, PATHINFO_FILENAME);
    $base = preg_replace('/\.(repaired|powered)$/i', '', $base) ?? $base;
    $output = dirname($input) . '/' . preg_replace('/[^A-Za-z0-9_]+/', '_', $base) . '.powered.msapp';
}

$profile = include dirname(__DIR__) . '/profiles/repair_powered.php';
(new PowerSweeper\Pipeline())->run($input, $profile['hops'], $output);

$arch = new PowerSweeper\MsappArchive($output);
$arch->unpack();
$live = PowerSweeper\StudioLiveChecker::check($arch->documents(), ['extract_dir' => $arch->extractDir()]);
$hasTheme = false;
$themeRadioWired = false;
foreach ($arch->documents() as $doc) {
    foreach ($doc->controls() as $c) {
        if ($c->isApp() && str_contains((string) $c->getProperty('OnStart'), 'gblThemeLight')) {
            $hasTheme = true;
        }
        if ($c->name === 'ThemeRadio' && str_contains((string) $c->getProperty('OnChange'), 'gblThemeDark')) {
            $themeRadioWired = true;
        }
    }
}
$arch->cleanup();

echo "Built: {$output}\n";
echo "Live checker total: {$live['total']}\n";
echo "Theme palettes in App.OnStart: " . ($hasTheme ? 'yes' : 'no') . "\n";
echo "ThemeRadio wired: " . ($themeRadioWired ? 'yes' : 'no') . "\n";
