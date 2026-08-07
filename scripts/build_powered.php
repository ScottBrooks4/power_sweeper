#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build *.powered.msapp deliverables (repair + dark mode).
 *
 * Usage:
 *   php scripts/build_powered.php [input.msapp] [output.msapp] [profile.php]
 *
 * Profile auto-selection:
 *   - explicit profile.php when provided
 *   - repair_powered (shared full repair + dark mode) otherwise
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\ProfileLoader;

$input = $argv[1] ?? dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App repair2.msapp';
$output = $argv[2] ?? null;
$explicitProfile = $argv[3] ?? null;

if (!is_file($input)) {
    fwrite(STDERR, "Input not found: {$input}\n");
    exit(1);
}

if ($output === null) {
    $base = pathinfo($input, PATHINFO_FILENAME);
    $base = preg_replace('/\.(repaired|powered)$/i', '', $base) ?? $base;
    $output = dirname($input) . '/' . preg_replace('/[^A-Za-z0-9_]+/', '_', $base) . '.powered.msapp';
}

$profilesDir = dirname(__DIR__) . '/profiles';
$loader = new ProfileLoader($profilesDir);
$profile = $loader->resolvePoweredProfile($input, is_string($explicitProfile) && $explicitProfile !== '' ? $explicitProfile : null);
$profileLabel = is_string($explicitProfile) && $explicitProfile !== ''
    ? basename($explicitProfile)
    : 'repair_powered.php';
$appClass = (string) ($profile['app_class'] ?? 'shared');

(new PowerSweeper\Pipeline())->run($input, $loader->resolveHops($profile), $output);

$arch = new PowerSweeper\MsappArchive($output);
$arch->unpack();
$live = PowerSweeper\StudioLiveChecker::check($arch->documents(), ['extract_dir' => $arch->extractDir()]);
$hasTheme = false;
$themeRadioWired = false;
$formulaErr = 0;
foreach ($arch->documents() as $doc) {
    foreach ($doc->controls() as $c) {
        if ($c->isApp() && str_contains((string) $c->getProperty('OnStart'), 'gblThemeLight')) {
            $hasTheme = true;
        }
        $onChange = (string) $c->getProperty('OnChange');
        if (
            str_contains($onChange, 'gblThemeDark')
            && str_contains($onChange, 'gblDarkMode')
            && (
                $c->name === 'ThemeRadio'
                || str_contains(strtolower($c->type), 'radio')
            )
        ) {
            $themeRadioWired = true;
        }
    }
}
foreach ($live['findings'] as $f) {
    $rule = (string) ($f['ruleId'] ?? '');
    if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref') {
        $formulaErr++;
    }
}
$arch->cleanup();

echo "Built: {$output}\n";
echo "Profile: {$profileLabel} (app_class={$appClass})\n";
echo "Live checker total: {$live['total']} (formulaErr={$formulaErr})\n";
echo "Theme palettes in App.OnStart: " . ($hasTheme ? 'yes' : 'no') . "\n";
echo "Theme radio wired: " . ($themeRadioWired ? 'yes' : 'no') . "\n";
