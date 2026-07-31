#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Validate a *.powered.msapp deliverable (repair + dark mode).
 *
 * Usage:
 *   php scripts/validate_powered.php [path/to/app.powered.msapp]
 *
 * Exit 0 when all checks pass; exit 1 otherwise.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\ColorValue;
use PowerSweeper\MsappArchive;
use PowerSweeper\StudioErrorDetector;
use PowerSweeper\StudioLiveChecker;

$msappPath = $argv[1] ?? dirname(__DIR__) . '/samples/import_debug/CDLS_L_VCR_App_repair2.powered.msapp';

if (!is_file($msappPath)) {
    fwrite(STDERR, "File not found: {$msappPath}\n");
    exit(1);
}

$failed = 0;
$check = static function (bool $ok, string $label): void {
    global $failed;
    echo ($ok ? 'OK  ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
};

$archive = new MsappArchive($msappPath);
$archive->unpack();

$live = StudioLiveChecker::check($archive->documents(), ['extract_dir' => $archive->extractDir()]);
$embedded = StudioErrorDetector::detectFromMsapp($msappPath, false);

echo 'Validate: ' . basename($msappPath) . "\n";
echo str_repeat('=', 60) . "\n";

$check($live['total'] === 0, 'Live checker total is 0 (got ' . $live['total'] . ')');
$check(($embedded['total'] ?? 0) === 0, 'Embedded SARIF total is 0 (got ' . ($embedded['total'] ?? '?') . ')');

$hasTheme = false;
$themeRadioWired = false;
$accessAppScope = false;
$screenDate = 0;
$localeSemi = 0;
$gblThemeDarkOnControls = 0;
$colorEnum = 0;
$opaqueRgba = 0;

foreach ($archive->documents() as $doc) {
    foreach ($doc->controls() as $control) {
        if ($control->isApp() && str_contains((string) $control->getProperty('OnStart'), 'gblThemeLight')) {
            $hasTheme = true;
        }
        if ($control->name === 'ThemeRadio' && str_contains((string) $control->getProperty('OnChange'), 'gblThemeDark')) {
            $themeRadioWired = true;
        }
        if (
            !$themeRadioWired
            && str_contains($control->path, 'TopbarHeader')
            && str_contains(strtolower($control->type), 'radio')
            && str_contains((string) $control->getProperty('OnChange'), 'gblThemeDark')
        ) {
            $themeRadioWired = true;
        }
        if ($control->name === 'TopbarHeader' && str_contains($control->path, 'ComponentDefinitions')) {
            $rootScope = $control->getYamlDefinitionField('AccessAppScope');
            if ($rootScope === true || $rootScope === 'true') {
                $accessAppScope = true;
            }
        }

        foreach ($control->propertyNames() as $prop) {
            $value = (string) ($control->getProperty($prop) ?? '');
            if ($value === '') {
                continue;
            }
            if (str_contains($value, 'gblThemeDark.')) {
                $gblThemeDarkOnControls++;
            }
            if (preg_match("/'[^']+'\.Date\s*\(/", $value)) {
                $screenDate++;
            }
            if (preg_match("/'[^']+'\s*;/", $value)) {
                $localeSemi++;
            }
            if (preg_match('/Color\.(Green|Yellow|Red|Blue)(?!Css)/i', $value) && !str_contains($value, 'gblTheme.')) {
                $colorEnum++;
            }
            if (!preg_match('/Fill|Color|Border|FontColor|BasePalette|Background|Chevron|ItemHover|DropTarget/i', $prop)) {
                continue;
            }
            if (!preg_match('/RGBA\s*\(/i', $value) || str_contains($value, 'gblTheme.')) {
                continue;
            }
            $parsed = ColorValue::parse($value);
            if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
                $opaqueRgba++;
            }
        }
    }
}

$archive->cleanup();

$check($hasTheme, 'App.OnStart defines gblThemeLight/gblThemeDark palettes');
$check($themeRadioWired, 'ThemeRadio OnChange swaps gblTheme via gblDarkMode');
$check($accessAppScope, 'TopbarHeader AccessAppScope enabled for theme toggle');
$topbarYaml = \PowerSweeper\ZipTool::readEntry($msappPath, 'Src/Components/TopbarHeader.pa.yaml');
if (is_string($topbarYaml)) {
    $rootScope = preg_match('/^\s*AccessAppScope:\s*true\s*$/m', $topbarYaml) === 1;
    $propScope = preg_match('/^\s*AccessAppScope:\s*=true\s*$/m', $topbarYaml) === 1;
    $check($rootScope && !$propScope, 'TopbarHeader AccessAppScope at component root only (not duplicated in Properties)');
}
$check($screenDate === 0, 'No screen-qualified Date() calls (got ' . $screenDate . ')');
$check($localeSemi === 0, 'No locale ; after quoted args (got ' . $localeSemi . ')');
$check($gblThemeDarkOnControls === 0, 'Controls use gblTheme.* not gblThemeDark.* (got ' . $gblThemeDarkOnControls . ')');
$check($colorEnum === 0, 'No bare Color.Green/Yellow/Red/Blue on controls (got ' . $colorEnum . ')');
$check($opaqueRgba === 0, 'No opaque hard-coded RGBA on color properties (got ' . $opaqueRgba . ')');

echo "\n";
if ($failed > 0) {
    echo "FAILED: {$failed} check(s)\n";
    exit(1);
}

echo "All powered validation checks passed.\n";
exit(0);
