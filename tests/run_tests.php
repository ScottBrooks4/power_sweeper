<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\FormulaIdentifierRewriter;
use PowerSweeper\FormulaLocaleNormalizer;
use PowerSweeper\Hops\AccessibilityLabelsHop;
use PowerSweeper\Hops\AlignNearMissHop;
use PowerSweeper\Hops\EnableDarkModeHop;
use PowerSweeper\Hops\EnsureFocusVisibleHop;
use PowerSweeper\Hops\NormalizeClassicButtonChromeHop;
use PowerSweeper\Hops\NormalizeContainersHop;
use PowerSweeper\Hops\RepairCheckedBooleansHop;
use PowerSweeper\Hops\StripDefaultFillHop;
use PowerSweeper\Hops\TooltipFromLabelHop;
use PowerSweeper\Hops\UnwhackLocaleFormulasHop;
use PowerSweeper\Pipeline;
use PowerSweeper\Report;
use PowerSweeper\SharePoint\SharePointCatalog;
use PowerSweeper\StringSimilarity;
use PowerSweeper\ZipTool;

$failed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failed;
    if ($cond) {
        echo "OK  {$msg}\n";
        return;
    }
    echo "FAIL {$msg}\n";
    $failed++;
}

function loadFixtureDoc(): ControlDocument
{
    $path = __DIR__ . '/fixtures/screen.pa.yaml';
    $doc = ControlDocument::fromFile($path, 'Src/Screen1.pa.yaml');
    assert_true($doc !== null, 'fixture loads');
    return $doc;
}

// --- normalize containers ---
$doc = loadFixtureDoc();
$report = new Report();
(new NormalizeContainersHop())->apply([$doc], $report);
$container = null;
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequest') {
        $container = $c;
        break;
    }
}
assert_true($container !== null, 'found NewRequest container');
assert_true(str_contains((string) $container->getProperty('DropShadow'), 'None'), 'DropShadow cleared');
assert_true(str_contains((string) $container->getProperty('PaddingTop'), '0'), 'PaddingTop cleared');
assert_true($report->count() > 0, 'normalize_containers reported changes');

// --- accessibility ---
$doc = loadFixtureDoc();
$report = new Report();
(new AccessibilityLabelsHop())->apply([$doc], $report);
$btn = null;
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $btn = $c;
        break;
    }
}
assert_true($btn !== null, 'found button');
assert_true($btn->getProperty('AccessibleLabel') !== null, 'AccessibleLabel set');
assert_true(str_contains(strtolower((string) $btn->getProperty('AccessibleLabel')), 'start'), 'AccessibleLabel from Text');

// --- align near miss ---
$doc = loadFixtureDoc();
$report = new Report();
(new AlignNearMissHop())->apply([$doc], $report, ['tolerance' => 3]);
$t1 = $t2 = null;
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestTitle') {
        $t1 = $c;
    }
    if ($c->name === 'NewRequestTitle2') {
        $t2 = $c;
    }
}
assert_true($t1 && $t2, 'found near-miss labels');
assert_true($t1->getProperty('X') === $t2->getProperty('X'), 'X snapped equal');
assert_true($t1->getProperty('Y') === $t2->getProperty('Y'), 'Y snapped equal');
assert_true($report->count() > 0, 'align_near_miss reported changes');

// --- strip default fill ---
$doc = loadFixtureDoc();
$report = new Report();
(new StripDefaultFillHop())->apply([$doc], $report);
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequest') {
        assert_true(str_contains(strtolower((string) $c->getProperty('Fill')), 'rgba(0, 0, 0, 0)'), 'container fill stripped');
    }
}

// --- button chrome ---
$doc = loadFixtureDoc();
$report = new Report();
(new NormalizeClassicButtonChromeHop())->apply([$doc], $report);
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        assert_true(str_contains(strtolower((string) $c->getProperty('HoverFill')), 'rgba(0, 0, 0, 0)'), 'HoverFill cleared');
        assert_true(str_contains(strtolower((string) $c->getProperty('PressedFill')), 'rgba(0, 0, 0, 0)'), 'PressedFill cleared');
    }
}

// --- tooltip ---
$doc = loadFixtureDoc();
$report = new Report();
(new TooltipFromLabelHop())->apply([$doc], $report);
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        assert_true($c->getProperty('Tooltip') !== null, 'Tooltip set on button');
    }
}

// --- locale normalizer unit checks ---
$eu = '=If(Slider1.Value > 10,5; Notify("Hi"; NotificationType.Information); Color.Red);; Set(x; 12,5)';
$inv = FormulaLocaleNormalizer::toInvariant($eu);
assert_true(FormulaLocaleNormalizer::looksLocaleCorrupted($eu), 'detects EU locale formula');
assert_true(str_contains($inv, '10.5'), 'decimal comma → dot');
assert_true(str_contains($inv, '12.5'), 'Set decimal fixed');
assert_true(str_contains($inv, 'Notify("Hi", NotificationType.Information)'), 'list ; → , inside Notify');
assert_true(str_contains($inv, '); Set(x,'), 'chaining ;; → ;');
assert_true(!str_contains($inv, ';;'), 'no leftover double-semicolon');
$safe = '=Set(x, 1); Set(y, 2)';
assert_true($safe === FormulaLocaleNormalizer::toInvariant($safe), 'leaves invariant chaining alone');
assert_true(!FormulaLocaleNormalizer::looksLocaleCorrupted($safe), 'invariant not flagged');

// --- unwhack locale on YAML ---
$doc = ControlDocument::fromFile(__DIR__ . '/fixtures/locale_corrupt.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($doc !== null, 'locale fixture loads');
$report = new Report();
(new UnwhackLocaleFormulasHop())->apply([$doc], $report);
assert_true($report->count() > 0, 'unwhack reported YAML changes');
$fillFixed = false;
$onVisFixed = false;
foreach ($doc->controls() as $c) {
    if ($c->name === 'Screen1') {
        $fill = (string) $c->getProperty('Fill');
        $fillFixed = str_contains($fill, 'RGBA(255, 255, 255, 1)') || str_contains($fill, 'RGBA(255,255,255,1)');
        $onVis = (string) $c->getProperty('OnVisible');
        $onVisFixed = str_contains($onVis, '12.5') && str_contains($onVis, 'Set(x,') && !str_contains($onVis, ';;');
    }
}
assert_true($fillFixed, 'Screen Fill separators unwhacked');
assert_true($onVisFixed, 'OnVisible chaining/decimals unwhacked');

// --- unwhack internal JSON InvariantScript ---
$jsonDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/internal_locale.json', 'Controls/Screen1.json');
assert_true($jsonDoc !== null, 'internal JSON fixture loads');
$report = new Report();
(new UnwhackLocaleFormulasHop())->apply([$jsonDoc], $report);
assert_true($report->count() > 0, 'unwhack reported JSON changes');
$jsonX = null;
foreach ($jsonDoc->controls() as $c) {
    if ($c->name === 'InternalLabel') {
        $jsonX = (string) $c->getProperty('X');
    }
}
assert_true(is_string($jsonX) && str_contains($jsonX, '40.5') && str_contains($jsonX, ','), 'internal InvariantScript unwhacked');

// --- dark mode ---
$doc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_app.pa.yaml', 'Src/App.pa.yaml');
assert_true($doc !== null, 'dark mode fixture loads');
$report = new Report();
(new EnableDarkModeHop())->apply([$doc], $report);
assert_true($report->count() > 0, 'dark mode reported changes');
$doc->reindex();
$hasToggle = false;
$onStartOk = false;
$paletteOk = false;
$screenFillThemed = false;
$titleColorThemed = false;
$toggleSwapsTheme = false;
foreach ($doc->controls() as $c) {
    if ($c->name === 'App') {
        $onStart = (string) $c->getProperty('OnStart');
        $onStartOk = str_contains($onStart, 'gblDarkMode');
        $paletteOk = str_contains($onStart, 'gblThemeLight')
            && str_contains($onStart, 'gblThemeDark')
            && str_contains($onStart, 'gblTheme')
            && str_contains($onStart, 'ps-theme:start');
    }
    if ($c->name === 'tglPowerSweeperDarkMode') {
        $hasToggle = true;
        $toggleSwapsTheme = str_contains((string) $c->getProperty('OnCheck'), 'gblThemeDark')
            && str_contains((string) $c->getProperty('OnUncheck'), 'gblThemeLight');
    }
    if ($c->name === 'Screen1') {
        $screenFillThemed = str_contains((string) $c->getProperty('Fill'), 'gblTheme.');
    }
    if ($c->name === 'Title') {
        $titleColorThemed = str_contains((string) $c->getProperty('Color'), 'gblTheme.');
    }
}
assert_true($onStartOk, 'App.OnStart initializes gblDarkMode');
assert_true($paletteOk, 'App.OnStart defines editable gblThemeLight/Dark palette');
assert_true($hasToggle, 'dark mode toggle injected');
assert_true($toggleSwapsTheme, 'toggle swaps gblTheme between light/dark palettes');
assert_true($screenFillThemed, 'screen Fill uses gblTheme token');
assert_true($titleColorThemed, 'label Color uses gblTheme token');

// Brand override via theme_defaults option (central palette only)
$overrideDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_app.pa.yaml', 'Src/App.pa.yaml');
$dmOverrideReport = new Report();
(new EnableDarkModeHop())->apply([$overrideDoc], $dmOverrideReport, [
    'theme_defaults' => [
        'Accent' => [
            'light' => ['r' => 220, 'g' => 38, 'b' => 38, 'a' => 1.0],
            'dark' => ['r' => 248, 'g' => 113, 'b' => 113, 'a' => 1.0],
        ],
    ],
]);
$overrideOnStart = '';
foreach ($overrideDoc->controls() as $c) {
    if ($c->isApp()) {
        $overrideOnStart = (string) ($c->getProperty('OnStart') ?? '');
    }
}
assert_true(
    str_contains($overrideOnStart, 'Accent: RGBA(220, 38, 38, 1)')
        && str_contains($overrideOnStart, 'Accent: RGBA(248, 113, 113, 1)'),
    'theme_defaults option edits Accent in central palette'
);

$white = ColorValue::parse('=RGBA(255, 255, 255, 1)');
assert_true($white !== null, 'parse white');
$darkBg = ColorValue::toDark($white, 'background');
assert_true($darkBg['r'] < 40 && $darkBg['g'] < 40 && $darkBg['b'] < 40, 'white maps to dark background');

// --- SharePoint similarity / rewriter ---
$best = StringSimilarity::bestMatch('Reqeusts', ['Requests', 'Employees'], 2);
assert_true($best !== null && $best['match'] === 'Requests', 'fuzzy list typo match');
$rewritten = FormulaIdentifierRewriter::rename(
    '=Filter(Reqeusts, Statu = "Open")',
    ['Reqeusts' => 'Requests', 'Statu' => 'Status']
);
assert_true(str_contains($rewritten, 'Filter(Requests,') && str_contains($rewritten, 'Status ='), 'formula identifier rewrite');

// --- SharePoint correlate hop ---
$spTmp = sys_get_temp_dir() . '/ps_sp_' . bin2hex(random_bytes(4));
$spStage = $spTmp . '/stage';
mkdir($spStage . '/Src', 0777, true);
mkdir($spStage . '/References', 0777, true);
file_put_contents($spStage . '/Src/Screen1.pa.yaml', file_get_contents(__DIR__ . '/fixtures/sharepoint_screen.pa.yaml'));
file_put_contents($spStage . '/References/DataSources.json', file_get_contents(__DIR__ . '/fixtures/sharepoint_datasources.json'));
$spIn = $spTmp . '/in.msapp';
$spOut = $spTmp . '/out.msapp';
ZipTool::createFromDirectory($spStage, $spIn);

$spResult = (new Pipeline())->run($spIn, [
    [
        'id' => 'correlate_sharepoint',
        'options' => [
            'repair' => true,
            'schema_file' => __DIR__ . '/fixtures/sharepoint_schema.json',
        ],
    ],
], $spOut);

assert_true(is_file($spOut), 'sharepoint output msapp created');
assert_true(($spResult['report']['total'] ?? 0) > 0, 'sharepoint report has findings/fixes');
$spDs = ZipTool::readEntry($spOut, 'References/DataSources.json');
assert_true(is_string($spDs) && str_contains($spDs, '"Name": "Requests"'), 'list typo Reqeusts repaired in datasources');
assert_true(is_string($spDs) && str_contains($spDs, '"Status"'), 'column typo Statu repaired in mapping');
$spYaml = ZipTool::readEntry($spOut, 'Src/Screen1.pa.yaml');
assert_true(is_string($spYaml) && str_contains($spYaml, 'Filter(Requests,'), 'formula list typo repaired');
assert_true(is_string($spYaml) && str_contains($spYaml, 'Status ='), 'formula column typo repaired');
$spReportJson = json_encode($spResult['report']);
assert_true(is_string($spReportJson) && str_contains($spReportJson, 'bad connection'), 'empty SharePoint connection reported');

$catalog = SharePointCatalog::loadFromExtractDir($spStage);
assert_true(count($catalog->sharePointListNames()) >= 1, 'catalog loads sharepoint lists from fixture dir');

@unlink($spIn);
@unlink($spOut);
@unlink($spStage . '/Src/Screen1.pa.yaml');
@unlink($spStage . '/References/DataSources.json');
@rmdir($spStage . '/Src');
@rmdir($spStage . '/References');
@rmdir($spStage);
@rmdir($spTmp);

// --- VCR-like Studio errors (Size / Orientation / ParseJSON / Checked / focus) ---
$vcrYaml = ControlDocument::fromFile(__DIR__ . '/fixtures/vcr_locale_errors.pa.yaml', 'Src/VCR.pa.yaml');
$vcrJson = ControlDocument::fromFile(__DIR__ . '/fixtures/vcr_locale_errors.json', 'Controls/VASC.json');
assert_true($vcrYaml !== null && $vcrJson !== null, 'VCR locale fixtures load');
$vcrReport = new Report();
(new UnwhackLocaleFormulasHop())->apply([$vcrYaml, $vcrJson], $vcrReport);
(new RepairCheckedBooleansHop())->apply([$vcrYaml, $vcrJson], $vcrReport);
(new EnsureFocusVisibleHop())->apply([$vcrYaml], $vcrReport);
assert_true($vcrReport->count() > 10, 'VCR repair reports multiple fixes');

$vcrSizeOk = false;
$vcrOrientOk = false;
$vcrCheckedOk = false;
$vcrFocusOk = false;
$vcrJsonSizeOk = false;
foreach ($vcrYaml->controls() as $c) {
    if ($c->name === 'VCRHomePage') {
        $size = (string) $c->getProperty('Size');
        $orient = (string) $c->getProperty('Orientation');
        $vcrSizeOk = str_contains($size, '0.5') && !str_contains($size, '0,5');
        $vcrOrientOk = str_contains($orient, ',') && !str_contains($orient, ';') && str_contains($orient, '1.5');
    }
    if ($c->name === 'VIPCheckbox') {
        $def = (string) $c->getProperty('Default');
        $vcrCheckedOk = $def === '=true' || str_ends_with($def, 'true');
    }
    if ($c->name === 'NewRequestButton') {
        $ft = (string) $c->getProperty('FocusedBorderThickness');
        $vcrFocusOk = str_contains($ft, '2');
    }
}
foreach ($vcrJson->controls() as $c) {
    if ($c->name === 'VASCTemplateControlScreen' || $c->name === 'Button1_3') {
        $sz = (string) ($c->getProperty('Size') ?? '');
        if (str_contains($sz, '0.85') || $sz === '12.5') {
            $vcrJsonSizeOk = true;
        }
    }
    if ($c->name === 'OneTimeVisit') {
        $d = (string) ($c->getProperty('Default') ?? '');
        if ($d === 'true') {
            $vcrCheckedOk = true;
        }
    }
}
assert_true($vcrSizeOk, 'VCR screen Size decimal unwhacked (fixes received-2-expected-1 class)');
assert_true($vcrOrientOk, 'VCR Orientation separators unwhacked');
assert_true($vcrCheckedOk, 'VCR Checked/Default booleans repaired');
assert_true($vcrFocusOk, 'VCR interactive focus ring applied');
assert_true($vcrJsonSizeOk, 'VCR internal JSON Size unwhacked');

$parseProbe = FormulaLocaleNormalizer::toInvariant(
    '=Set(gblJson; ParseJSON(gblPayload));; Set(x; Value(Text(gblJson.Amount); "en-US"))'
);
assert_true(
    str_contains($parseProbe, 'ParseJSON(gblPayload)')
    && str_contains($parseProbe, 'Value(Text(gblJson.Amount), "en-US")')
    && !str_contains($parseProbe, ';;'),
    'ParseJSON / nested Value separators unwhacked'
);

// --- German locale corruption sample (thousands of errors) ---
$localePath = dirname(__DIR__) . '/samples/locale_german_corrupt/locale_german_corrupt.msapp';
if (!is_file($localePath)) {
    passthru('php ' . escapeshellarg(dirname(__DIR__) . '/samples/locale_german_corrupt/build.php'), $localeBuild);
    assert_true(($localeBuild === 0) && is_file($localePath), 'locale corrupt sample builds');
}
$localeOut = sys_get_temp_dir() . '/ps_locale_out_' . bin2hex(random_bytes(4)) . '.msapp';
$localeResult = (new Pipeline())->run($localePath, [['id' => 'unwhack_locale_formulas']], $localeOut);
$localeTotal = (int) ($localeResult['report']['total'] ?? 0);
assert_true($localeTotal >= 1000, 'locale sample repairs thousands of formulas (got ' . $localeTotal . ')');
$localeApp = ZipTool::readEntry($localeOut, 'Src/App.pa.yaml');
$localeJson = ZipTool::readEntry($localeOut, 'Controls/Screen1.json');
assert_true(is_string($localeApp) && str_contains($localeApp, '12.5') && !str_contains($localeApp, ';;'), 'App.OnStart unwhacked');
assert_true(is_string($localeJson) && !preg_match('/RGBA\(\d+;\s*\d+/', $localeJson), 'internal JSON InvariantScript unwhacked');
assert_true(is_string($localeJson) && preg_match('/RGBA\(\d+,\s*\d+,\s*\d+/', $localeJson) === 1, 'internal JSON has invariant RGBA commas');
@unlink($localeOut);

// --- dark mode kitchen sink sample ---
$kmsPath = dirname(__DIR__) . '/samples/dark_mode_kitchen_sink/dark_mode_kitchen_sink.msapp';
if (!is_file($kmsPath)) {
    // Build on the fly if sample archive missing
    passthru('php ' . escapeshellarg(dirname(__DIR__) . '/samples/dark_mode_kitchen_sink/build.php'), $buildCode);
    assert_true($buildCode === 0 && is_file($kmsPath), 'kitchen sink sample builds');
}
$kmsOut = sys_get_temp_dir() . '/ps_kms_out_' . bin2hex(random_bytes(4)) . '.msapp';
$kmsResult = (new Pipeline())->run($kmsPath, [['id' => 'enable_dark_mode']], $kmsOut);
assert_true(($kmsResult['report']['total'] ?? 0) >= 100, 'kitchen sink produces many dark-mode changes');
$kmsHome = ZipTool::readEntry($kmsOut, 'Src/HomeScreen.pa.yaml');
$kmsControls = ZipTool::readEntry($kmsOut, 'Src/ControlsScreen.pa.yaml');
$kmsApp = ZipTool::readEntry($kmsOut, 'Src/App.pa.yaml');
assert_true(is_string($kmsHome) && str_contains($kmsHome, 'tglPowerSweeperDarkMode'), 'kitchen sink home gets dark toggle');
assert_true(is_string($kmsApp) && str_contains((string) $kmsApp, 'gblDarkMode'), 'kitchen sink App.OnStart sets gblDarkMode');
assert_true(is_string($kmsApp) && str_contains((string) $kmsApp, 'gblThemeLight') && str_contains((string) $kmsApp, 'gblThemeDark'), 'kitchen sink App.OnStart has editable palettes');
assert_true(is_string($kmsHome) && str_contains($kmsHome, 'gblTheme.'), 'kitchen sink home colors use gblTheme tokens');
assert_true(is_string($kmsControls) && str_contains($kmsControls, 'gblTheme.'), 'kitchen sink controls colors use gblTheme tokens');
assert_true(
    is_string($kmsControls)
    && preg_match("/SelectedFill:\\s*'?=gblTheme\\./m", $kmsControls) === 1,
    'gallery SelectedFill uses theme token'
);
assert_true(
    is_string($kmsControls)
    && preg_match("/RailFill:\\s*'?=gblTheme\\./m", $kmsControls) === 1,
    'slider RailFill uses theme token'
);
@unlink($kmsOut);

// --- integration: zip fixture -> pipeline -> zip ---
$fixtureYaml = file_get_contents(__DIR__ . '/fixtures/screen.pa.yaml');
$tmpDir = sys_get_temp_dir() . '/ps_int_' . bin2hex(random_bytes(4));
$stageDir = $tmpDir . '/stage';
mkdir($stageDir . '/Src', 0777, true);
mkdir($stageDir . '/Controls', 0777, true);
file_put_contents($stageDir . '/Src/Screen1.pa.yaml', $fixtureYaml);
file_put_contents($stageDir . '/Controls/Screen1.json', file_get_contents(__DIR__ . '/fixtures/internal_locale.json'));
$inMsapp = $tmpDir . '/in.msapp';
$outMsapp = $tmpDir . '/out.msapp';
ZipTool::createFromDirectory($stageDir, $inMsapp);

$result = (new Pipeline())->run($inMsapp, [
    ['id' => 'normalize_containers'],
    ['id' => 'align_near_miss', 'options' => ['tolerance' => 3]],
    ['id' => 'accessibility_labels'],
    ['id' => 'normalize_classic_button_chrome'],
    ['id' => 'unwhack_locale_formulas'],
], $outMsapp);

assert_true(is_file($outMsapp), 'output msapp created');
assert_true(($result['report']['total'] ?? 0) > 0, 'integration report has changes');

$yamlOut = ZipTool::readEntry($outMsapp, 'Src/Screen1.pa.yaml');
assert_true(is_string($yamlOut) && str_contains($yamlOut, 'DropShadow.None'), 'packed YAML contains normalized DropShadow');
$jsonOut = ZipTool::readEntry($outMsapp, 'Controls/Screen1.json');
assert_true(is_string($jsonOut) && str_contains($jsonOut, '40.5'), 'packed JSON has unwhacked internal formula');
$packNames = [];
$packZip = new ZipArchive();
assert_true($packZip->open($outMsapp) === true, 'packed msapp opens');
for ($i = 0; $i < $packZip->numFiles; $i++) {
    $packNames[] = (string) $packZip->getNameIndex($i);
}
$packZip->close();
$hasBs = false;
foreach ($packNames as $n) {
    if (str_contains($n, '\\')) {
        $hasBs = true;
        break;
    }
}
assert_true($hasBs, 'packed .msapp uses Windows backslash entry names for Studio import');
assert_true(in_array('Src\\Screen1.pa.yaml', $packNames, true) || in_array('Controls\\Screen1.json', $packNames, true), 'packed entries include Src\\ or Controls\\ paths');

// --- zip path style: preserve source, optional posix hop ---
$styleDir = sys_get_temp_dir() . '/ps_style_' . bin2hex(random_bytes(3));
mkdir($styleDir . '/win/Src', 0777, true);
mkdir($styleDir . '/posix/Src', 0777, true);
file_put_contents($styleDir . '/win/Src/A.pa.yaml', "Screen:\n  Properties:\n    X: =1\n");
file_put_contents($styleDir . '/posix/Src/A.pa.yaml', "Screen:\n  Properties:\n    X: =1\n");
ZipTool::createFromDirectory($styleDir . '/win', $styleDir . '/win.msapp', ZipTool::STYLE_WINDOWS);
ZipTool::createFromDirectory($styleDir . '/posix', $styleDir . '/posix.msapp', ZipTool::STYLE_POSIX);
assert_true(ZipTool::detectEntryStyle($styleDir . '/win.msapp') === ZipTool::STYLE_WINDOWS, 'detect windows zip style');
assert_true(ZipTool::detectEntryStyle($styleDir . '/posix.msapp') === ZipTool::STYLE_POSIX, 'detect posix zip style');

$preserved = $styleDir . '/preserved.msapp';
(new Pipeline())->run($styleDir . '/win.msapp', [['id' => 'normalize_containers']], $preserved);
assert_true(ZipTool::detectEntryStyle($preserved) === ZipTool::STYLE_WINDOWS, 'pipeline preserves windows zip style');

$forcedPosix = $styleDir . '/forced_posix.msapp';
$posixResult = (new Pipeline())->run($styleDir . '/win.msapp', [
    ['id' => 'set_zip_path_style', 'options' => ['style' => 'posix']],
], $forcedPosix);
assert_true(ZipTool::detectEntryStyle($forcedPosix) === ZipTool::STYLE_POSIX, 'posix_zip_paths hop forces forward slashes');
assert_true(($posixResult['report']['total'] ?? 0) >= 1, 'zip path style hop reports a change');

@unlink($styleDir . '/win/Src/A.pa.yaml');
@rmdir($styleDir . '/win/Src');
@rmdir($styleDir . '/win');
@unlink($styleDir . '/posix/Src/A.pa.yaml');
@rmdir($styleDir . '/posix/Src');
@rmdir($styleDir . '/posix');
@unlink($styleDir . '/win.msapp');
@unlink($styleDir . '/posix.msapp');
@unlink($preserved);
@unlink($forcedPosix);
@rmdir($styleDir);

// cleanup tmp
@unlink($inMsapp);
@unlink($outMsapp);
@unlink($stageDir . '/Src/Screen1.pa.yaml');
@unlink($stageDir . '/Controls/Screen1.json');
@rmdir($stageDir . '/Src');
@rmdir($stageDir . '/Controls');
@rmdir($stageDir);
@rmdir($tmpDir);

echo "\n";
if ($failed > 0) {
    echo "FAILED: {$failed} assertion(s)\n";
    exit(1);
}
echo "All tests passed.\n";
exit(0);
