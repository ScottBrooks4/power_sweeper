#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Validate a *.powered.msapp deliverable (repair + dark mode) for any app class.
 *
 * Soft Studio advisories (delegation hints, delay-loading, cross-screen deps, a11y)
 * are reported as WARN and do not fail the script. Formula errors (app-Err*,
 * mangled screen refs) fail.
 *
 * Usage:
 *   php scripts/validate_powered.php [path/to/app.powered.msapp]
 *
 * Exit 0 when all hard checks pass; exit 1 otherwise.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\ColorValue;
use PowerSweeper\MsappArchive;
use PowerSweeper\StudioErrorDetector;
use PowerSweeper\StudioLiveChecker;
use PowerSweeper\ZipTool;

$msappPath = $argv[1] ?? dirname(__DIR__) . '/samples/import_debug/CDLS_L_VCR_App_repair2.powered.msapp';

if (!is_file($msappPath)) {
    fwrite(STDERR, "File not found: {$msappPath}\n");
    exit(1);
}

$failed = 0;
$warned = 0;
$check = static function (bool $ok, string $label): void {
    global $failed;
    echo ($ok ? 'OK  ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
};
$warn = static function (bool $ok, string $label): void {
    global $warned;
    if ($ok) {
        echo "OK  {$label}\n";

        return;
    }
    echo "WARN {$label}\n";
    $warned++;
};

$archive = new MsappArchive($msappPath);
$archive->unpack();

$live = StudioLiveChecker::check($archive->documents(), ['extract_dir' => $archive->extractDir()]);
$embedded = StudioErrorDetector::detectFromMsapp($msappPath, false);

$formulaErr = 0;
$softByRule = [];
foreach ($live['findings'] as $f) {
    $rule = (string) ($f['ruleId'] ?? '');
    if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref') {
        $formulaErr++;
        continue;
    }
    if ($rule !== '') {
        $softByRule[$rule] = ($softByRule[$rule] ?? 0) + 1;
    }
}

echo 'Validate: ' . basename($msappPath) . "\n";
echo str_repeat('=', 60) . "\n";

$check($formulaErr === 0, 'No formula errors (app-Err*/mangled-screen-ref; got ' . $formulaErr . ')');
$check(($embedded['total'] ?? 0) === 0, 'Embedded SARIF total is 0 (got ' . ($embedded['total'] ?? '?') . ')');
if ($softByRule !== []) {
    arsort($softByRule);
    $softParts = [];
    foreach (array_slice($softByRule, 0, 6, true) as $k => $v) {
        $softParts[] = "{$k}={$v}";
    }
    echo 'WARN Soft live advisories present (total=' . $live['total']
        . '; ' . implode(', ', $softParts) . ") — allowed\n";
    $warned++;
} else {
    echo "OK  Live checker total is 0\n";
}

$hasTheme = false;
$themeRadioWired = false;
$accessAppScope = false;
$screenDate = 0;
$localeSemi = 0;
$gblThemeDarkOnControls = 0;
$colorEnum = 0;
$opaqueRgba = 0;
$topbarNames = [];

foreach ($archive->documents() as $doc) {
    foreach ($doc->controls() as $control) {
        if ($control->isApp() && str_contains((string) $control->getProperty('OnStart'), 'gblThemeLight')) {
            $hasTheme = true;
        }

        $isThemeRadio = $control->name === 'ThemeRadio'
            || (
                str_contains(strtolower($control->type), 'radio')
                && str_contains((string) $control->getProperty('OnChange'), 'gblDarkMode')
                && str_contains((string) $control->getProperty('OnChange'), 'gblTheme')
            );
        if ($isThemeRadio && str_contains((string) $control->getProperty('OnChange'), 'gblThemeDark')) {
            $themeRadioWired = true;
        }

        if (preg_match('/^TopbarHeader(_\d+)?$/', $control->name) === 1) {
            $topbarNames[$control->name] = true;
            if (str_contains($control->path, 'ComponentDefinitions')) {
                $rootScope = $control->getYamlDefinitionField('AccessAppScope');
                if ($rootScope === true || $rootScope === 'true') {
                    $accessAppScope = true;
                }
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
$check($themeRadioWired, 'Theme radio OnChange swaps gblTheme via gblDarkMode');

if ($topbarNames !== []) {
    $check($accessAppScope, 'TopbarHeader* AccessAppScope enabled for theme toggle');
    foreach (array_keys($topbarNames) as $topbarName) {
        $entry = 'Src/Components/' . $topbarName . '.pa.yaml';
        $topbarYaml = ZipTool::readEntry($msappPath, $entry);
        if (!is_string($topbarYaml)) {
            continue;
        }
        $rootScope = preg_match('/^\s*AccessAppScope:\s*true\s*$/m', $topbarYaml) === 1;
        $propScope = preg_match('/^\s*AccessAppScope:\s*=true\s*$/m', $topbarYaml) === 1;
        $check(
            $rootScope && !$propScope,
            $topbarName . ' AccessAppScope at component root only (not duplicated in Properties)'
        );
    }
} else {
    echo "OK  No TopbarHeader* component (theme radio may live on a screen)\n";
}

$check($screenDate === 0, 'No screen-qualified Date() calls (got ' . $screenDate . ')');
$check($localeSemi === 0, 'No locale ; after quoted args (got ' . $localeSemi . ')');
$check($gblThemeDarkOnControls === 0, 'Controls use gblTheme.* not gblThemeDark.* (got ' . $gblThemeDarkOnControls . ')');
$check($colorEnum === 0, 'No bare Color.Green/Yellow/Red/Blue on controls (got ' . $colorEnum . ')');
$check($opaqueRgba === 0, 'No opaque hard-coded RGBA on color properties (got ' . $opaqueRgba . ')');

if (preg_match('/THCEE/i', basename($msappPath)) && !preg_match('/TDR/i', basename($msappPath))) {
    $refreshYaml = ZipTool::readEntry($msappPath, 'Src/THCEE Refresh Screen.pa.yaml');
    $check(
        is_string($refreshYaml)
            && str_contains($refreshYaml, 'comTranslations.Labels')
            && !str_contains($refreshYaml, "'THCEE Control Screen'.comTranslations"),
        'THCEE keeps bare comTranslations refs (not screen-qualified)'
    );
}

echo "\n";
if ($failed > 0) {
    echo "FAILED: {$failed} check(s)" . ($warned > 0 ? " ({$warned} warning(s))" : '') . "\n";
    exit(1);
}

echo 'All powered validation checks passed.' . ($warned > 0 ? " ({$warned} warning(s))" : '') . "\n";
exit(0);
