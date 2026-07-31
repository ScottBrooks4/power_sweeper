<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\PowerAppsYaml;
use PowerSweeper\StudioJson;
use PowerSweeper\StudioIssueScanner;
use PowerSweeper\FormulaIdentifierRewriter;
use PowerSweeper\FormulaLocaleNormalizer;
use PowerSweeper\Hops\AccessibilityLabelsHop;
use PowerSweeper\Hops\AlignNearMissHop;
use PowerSweeper\Hops\EnableDarkModeHop;
use PowerSweeper\Hops\EnsureFocusVisibleHop;
use PowerSweeper\Hops\NormalizeClassicButtonChromeHop;
use PowerSweeper\Hops\NormalizeContainersHop;
use PowerSweeper\Hops\RepairCheckedBooleansHop;
use PowerSweeper\Hops\ScanStudioIssuesHop;
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

// Compact invariant RGBA must NOT be treated as decimal commas (was mangling to RGBA(0.0,0.0)).
$compactRgba = 'RGBA(0,0,0,0)';
assert_true(!FormulaLocaleNormalizer::looksLocaleCorrupted($compactRgba), 'compact invariant RGBA not flagged as locale');
assert_true(FormulaLocaleNormalizer::toInvariant($compactRgba) === $compactRgba, 'compact invariant RGBA unchanged');
$spacedRgba = 'RGBA(0, 0, 0, 0)';
assert_true(!FormulaLocaleNormalizer::looksLocaleCorrupted($spacedRgba), 'spaced invariant RGBA not flagged');
$localeRgba = 'RGBA(0; 0; 0; 1)';
assert_true(FormulaLocaleNormalizer::looksLocaleCorrupted($localeRgba), 'locale RGBA(; ) flagged');
assert_true(FormulaLocaleNormalizer::toInvariant($localeRgba) === 'RGBA(0, 0, 0, 1)', 'locale RGBA converts without dropping args');
$localeRgbaTight = 'RGBA(0;0;0;1)';
assert_true(FormulaLocaleNormalizer::toInvariant($localeRgbaTight) === 'RGBA(0,0,0,1)', 'tight locale RGBA keeps four args');
$standaloneDec = '=Parent.Width * 0,5';
assert_true(FormulaLocaleNormalizer::toInvariant($standaloneDec) === '=Parent.Width * 0.5', 'standalone decimal comma still fixed');

// Half-converted color alphas (list commas + locale decimal) → BadArity in Studio
$halfAlpha = 'RGBA(240, 240, 240, 0,2)';
assert_true(FormulaLocaleNormalizer::looksLocaleCorrupted($halfAlpha), 'half-converted RGBA alpha flagged');
assert_true(FormulaLocaleNormalizer::toInvariant($halfAlpha) === 'RGBA(240, 240, 240, 0.2)', 'half-converted RGBA alpha repaired');
$emptyAlpha = 'RGBA(119, 119, 119, ,4)';
assert_true(FormulaLocaleNormalizer::looksLocaleCorrupted($emptyAlpha), 'empty+fragment RGBA alpha flagged');
assert_true(FormulaLocaleNormalizer::toInvariant($emptyAlpha) === 'RGBA(119, 119, 119, 0.4)', 'empty+fragment RGBA alpha repaired');
$countIfLocale = 'CountIf(App.SizeBreakpoints; Value >= Self.Width)';
assert_true(
    FormulaLocaleNormalizer::toInvariant($countIfLocale) === 'CountIf(App.SizeBreakpoints, Value >= Self.Width)',
    'Size breakpoint CountIf locale separators unwhacked'
);

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

// Cross-screen qualify must not double-apply on member access
$cross = FormulaIdentifierRewriter::rename(
    "=Set(x, 'VCR / VCN Form'.Date.SelectedDate)",
    ['Date' => "'VCR / VCN Form'.Date"]
);
assert_true($cross === "=Set(x, 'VCR / VCN Form'.Date.SelectedDate)", 'no double screen qualification');
$bare = FormulaIdentifierRewriter::rename(
    '=IsBlank(Date.SelectedDate)',
    ['Date' => "'VCR / VCN Form'.Date"]
);
assert_true($bare === "=IsBlank('VCR / VCN Form'.Date.SelectedDate)", 'bare cross-screen ref qualified once');

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
(new EnsureFocusVisibleHop())->apply([$vcrYaml, $vcrJson], $vcrReport);
assert_true($vcrReport->count() > 10, 'VCR repair reports multiple fixes');

$vcrSizeOk = false;
$vcrOrientOk = false;
$vcrCheckedOk = false;
$vcrFocusOk = false;
$vcrJsonSizeOk = false;
$vcrIfNumericBoolOk = false;
$vcrVisibleBoolOk = false;
$vcrAutoBindOk = false;
$vcrJsonVisibleOk = false;
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
    if ($c->name === 'BulkFlag') {
        $checked = (string) $c->getProperty('Checked');
        $visible = (string) $c->getProperty('Visible');
        $vcrIfNumericBoolOk = str_contains($checked, 'true') && str_contains($checked, 'false')
            && !preg_match('/,\s*1\s*,\s*0/', $checked);
        $vcrVisibleBoolOk = $visible === '=true' || str_ends_with($visible, 'true');
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
    if ($c->name === 'LegacyLabel') {
        $vis = (string) ($c->getProperty('Visible') ?? '');
        $vcrJsonVisibleOk = $vis === 'true';
    }
    if ($c->name === 'Button1_3') {
        $ft = (string) ($c->getProperty('FocusedBorderThickness') ?? '');
        if (str_contains($ft, '2')) {
            $vcrFocusOk = true;
        }
    }
}
// AutoRuleBindingString is not exposed via ControlNode properties — re-read via transform capture
$autoBindSample = '';
$vcrJson->transformFormulas(static function (string $f, string $label) use (&$autoBindSample): string {
    if (str_contains($label, 'AutoRuleBindingString')) {
        $autoBindSample = $f;
    }
    return $f;
});
// Re-load and check file after save
$vcrJsonTmp = sys_get_temp_dir() . '/vcr_json_' . bin2hex(random_bytes(3)) . '.json';
$vcrJson->markDirty();
$vcrJson->save($vcrJsonTmp);
$vcrJsonRaw = (string) file_get_contents($vcrJsonTmp);
$vcrAutoBindOk = str_contains($vcrJsonRaw, '"AutoRuleBindingString": "RGBA(0, 0, 0, 0)"')
    || str_contains($vcrJsonRaw, '"AutoRuleBindingString": "RGBA(0,0,0,0)"');
@unlink($vcrJsonTmp);

assert_true($vcrSizeOk, 'VCR screen Size decimal unwhacked (fixes received-2-expected-1 class)');
assert_true($vcrOrientOk, 'VCR Orientation separators unwhacked');
assert_true($vcrCheckedOk, 'VCR Checked/Default booleans repaired');
assert_true($vcrIfNumericBoolOk, 'VCR If(cond, 1, 0) Checked rewritten to true/false');
assert_true($vcrVisibleBoolOk, 'VCR Visible: 1 rewritten to true');
assert_true($vcrFocusOk, 'VCR interactive focus ring applied');
assert_true($vcrJsonSizeOk, 'VCR internal JSON Size unwhacked');
assert_true($vcrJsonVisibleOk, 'VCR JSON Visible boolean repaired');
assert_true($vcrAutoBindOk, 'VCR AutoRuleBindingString locale RGBA unwhacked');

$vcrIssues = StudioIssueScanner::scan([$vcrYaml, $vcrJson]);
$vcrLocaleLeft = array_values(array_filter($vcrIssues, static fn($i) => $i['kind'] === 'locale_separators'));
$vcrBoolLeft = array_values(array_filter(
    $vcrIssues,
    static fn($i) => str_starts_with($i['kind'], 'expecting_boolean')
));
assert_true($vcrLocaleLeft === [], 'scanner finds no remaining locale separators on VCR fixtures');
assert_true($vcrBoolLeft === [], 'scanner finds no remaining boolean literals on VCR fixtures');

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

// DOS/Windows zip metadata (create_system=0) — Unix stamps break Studio open
$packInfo = new ZipArchive();
assert_true($packInfo->open($outMsapp) === true, 'reopen packed msapp for metadata check');
$metaOk = true;
for ($i = 0; $i < $packInfo->numFiles; $i++) {
    $stat = $packInfo->statIndex($i);
    if (!is_array($stat)) {
        continue;
    }
    // ZipArchive::statIndex does not expose create_system; use Python-less check via raw if needed.
}
$packInfo->close();
// Probe with ZipTool round-trip detect + a direct binary sniff of central-dir host OS byte.
$rawPack = file_get_contents($outMsapp);
assert_true(is_string($rawPack) && $rawPack !== '', 'packed msapp readable for DOS metadata sniff');
// Central directory header signature 0x02014b50; byte at +5 is host OS (0=DOS, 3=Unix)
$dosHosts = 0;
$unixHosts = 0;
$offset = 0;
while (($pos = strpos($rawPack, "PK\x01\x02", $offset)) !== false) {
    $host = ord($rawPack[$pos + 5]);
    if ($host === 0) {
        $dosHosts++;
    } elseif ($host === 3) {
        $unixHosts++;
    }
    $offset = $pos + 4;
}
assert_true($dosHosts > 0 && $unixHosts === 0, 'packed .msapp uses DOS/Windows zip host (not Unix) for Studio import');

// --- Power Apps YAML dump (Studio import shape) ---
$paYaml = PowerAppsYaml::dump([
    'Screens' => [
        'Home' => [
            'Properties' => [
                'Fill' => '=RGBA(1, 2, 3, 1)',
                'Y' => '=10',
                'Font' => "=Font.'Open Sans'",
            ],
            'Children' => [
                ['Box' => [
                    'Control' => 'Rectangle@2.3.0',
                    'Properties' => ['BorderColor' => '=gblTheme.Border'],
                ]],
            ],
        ],
    ],
], "# banner\n# line");
assert_true(str_starts_with($paYaml, "# banner\n"), 'PowerAppsYaml keeps header comment');
assert_true(str_contains($paYaml, 'Fill: =RGBA(1, 2, 3, 1)'), 'PowerAppsYaml leaves Power Fx unquoted');
assert_true(str_contains($paYaml, 'Y: =10') && !str_contains($paYaml, "'Y':"), 'PowerAppsYaml does not quote Y key');
assert_true(str_contains($paYaml, "Font: =Font.'Open Sans'"), 'PowerAppsYaml keeps Font.\'…\' unquoted as formula');
assert_true(str_contains($paYaml, '- Box:'), 'PowerAppsYaml uses inline Children list items');
assert_true(!str_contains($paYaml, "Fill: '="), 'PowerAppsYaml does not emit Fill: \'=…\'');
$paYamlScreens = PowerAppsYaml::dump([
    'Screens' => [
        'VCR Home Page' => ['Properties' => ['Fill' => '=RGBA(1, 1, 1, 1)']],
        'VCR / VCN Form' => ['Properties' => ['Fill' => '=RGBA(2, 2, 2, 1)']],
    ],
]);
assert_true(str_contains($paYamlScreens, 'VCR Home Page:') && !str_contains($paYamlScreens, "'VCR Home Page':"), 'PowerAppsYaml leaves spaced screen names unquoted');
assert_true(str_contains($paYamlScreens, 'VCR / VCN Form:') && !str_contains($paYamlScreens, "'VCR / VCN Form':"), 'PowerAppsYaml leaves slashed screen names unquoted');

// Formulas with ": " (UpdateContext records) must use block scalars so YAML round-trips.
$colonFx = PowerAppsYaml::dump([
    'Screen1' => [
        'Properties' => [
            'OnSelect' => '=UpdateContext({varDetailScreenSelect: 1})',
            'Fill' => '=RGBA(1, 2, 3, 1)',
        ],
    ],
]);
assert_true(str_contains($colonFx, "OnSelect: |-") && str_contains($colonFx, '=UpdateContext({varDetailScreenSelect: 1})'), 'PowerAppsYaml uses block scalar for UpdateContext colon formulas');
assert_true(str_contains($colonFx, 'Fill: =RGBA(1, 2, 3, 1)'), 'PowerAppsYaml keeps simple Power Fx unquoted');
$colonParsed = \Symfony\Component\Yaml\Yaml::parse($colonFx);
assert_true(
    is_array($colonParsed)
    && ($colonParsed['Screen1']['Properties']['OnSelect'] ?? null) === '=UpdateContext({varDetailScreenSelect: 1})',
    'colon-bearing Power Fx YAML round-trips through Symfony parse'
);

// Dirty tracking: empty hop sequence must not rewrite YAML
$dirtyDir = sys_get_temp_dir() . '/ps_dirty_' . bin2hex(random_bytes(3));
mkdir($dirtyDir . '/Src', 0777, true);
$origYaml = "# keep\nScreens:\n  Home:\n    Properties:\n      Fill: =RGBA(9, 9, 9, 1)\n";
file_put_contents($dirtyDir . '/Src/Home.pa.yaml', $origYaml);
file_put_contents($dirtyDir . '/Header.json', '{}');
$dirtyIn = $dirtyDir . '/in.msapp';
$dirtyOut = $dirtyDir . '/out.msapp';
ZipTool::createFromDirectory($dirtyDir, $dirtyIn, ZipTool::STYLE_WINDOWS);
(new Pipeline())->run($dirtyIn, [], $dirtyOut);
$after = ZipTool::readEntry($dirtyOut, 'Src/Home.pa.yaml');
assert_true($after === $origYaml, 'empty pipeline preserves original YAML bytes (dirty tracking)');

// Studio JSON style (CRLF + 2-space)
$studioJson = StudioJson::encode(['TopParent' => ['Name' => 'Screen1', 'Rules' => []]], "\r\n", 2);
assert_true(str_starts_with($studioJson, "{\r\n  \""), 'StudioJson uses CRLF and 2-space indent');
assert_true(!str_contains($studioJson, "{\n    \""), 'StudioJson does not use PHP default LF/4-space');
$style = StudioJson::detectStyle("{\r\n  \"A\": 1\r\n}\r\n");
assert_true($style['newline'] === "\r\n" && $style['indent'] === 2, 'StudioJson detects Studio export style');

// Empty objects must stay `{}` (assoc json_decode turns them into `[]` and Studio rejects the file).
$emptyObjFixture = sys_get_temp_dir() . '/ps_empty_obj_' . bin2hex(random_bytes(3)) . '.json';
file_put_contents($emptyObjFixture, "{\r\n  \"TopParent\": {\r\n    \"Name\": \"Screen1\",\r\n    \"Template\": {\r\n      \"Name\": \"screen\",\r\n      \"OverridableProperties\": {}\r\n    },\r\n    \"Rules\": [],\r\n    \"Children\": []\r\n  }\r\n}");
$emptyDoc = ControlDocument::fromFile($emptyObjFixture, 'Controls/EmptyObj.json');
assert_true($emptyDoc !== null, 'empty-object JSON fixture loads');
$emptyDoc->markDirty();
$emptyDoc->save($emptyObjFixture);
$emptyRound = file_get_contents($emptyObjFixture);
assert_true(is_string($emptyRound) && str_contains($emptyRound, '"OverridableProperties": {}'), 'JSON roundtrip preserves empty object {}');
assert_true(is_string($emptyRound) && !str_contains($emptyRound, '"OverridableProperties": []'), 'JSON roundtrip does not emit empty array for OverridableProperties');
@unlink($emptyObjFixture);

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

// StudioLiveChecker — live App checker on App (16)
$app16 = dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App (16).msapp';
if (is_file($app16)) {
    $archive = new \PowerSweeper\MsappArchive($app16);
    $archive->unpack();
    $live = \PowerSweeper\StudioLiveChecker::check($archive->documents(), ['extract_dir' => $archive->extractDir()]);
    $archive->cleanup();
    assert_true($live['total'] >= 650 && $live['total'] <= 750, 'App (16) live checker total in expected range (got ' . $live['total'] . ')');
    assert_true(($live['by_category']['formulas'] ?? 0) >= 250, 'App (16) live formula issues');
    assert_true(($live['by_category']['accessibility'] ?? 0) >= 200, 'App (16) live a11y issues');
    // Compare overlap with embedded SARIF
    $det = \PowerSweeper\StudioErrorDetector::detectFromMsapp($app16, false);
    $embLoc = [];
    foreach ($det['issues'] as $issue) {
        $embLoc[$issue['ruleId'] . '|' . $issue['location']] = true;
    }
    $overlap = 0;
    foreach ($live['findings'] as $f) {
        if (isset($embLoc[$f['ruleId'] . '|' . $f['location']])) {
            $overlap++;
        }
    }
    assert_true($overlap >= 200, 'App (16) live checker overlaps embedded SARIF (got ' . $overlap . ')');
}

// Repaired pipeline should drive live errors well below original 1719 SARIF
$repairedPipelineOut = sys_get_temp_dir() . '/ps_repaired_live_check_' . bin2hex(random_bytes(4)) . '.msapp';
$repairProfile = include dirname(__DIR__) . '/profiles/repair_studio_errors.php';
(new Pipeline())->run($app16, $repairProfile['hops'], $repairedPipelineOut);
$repairedArchive = new \PowerSweeper\MsappArchive($repairedPipelineOut);
$repairedArchive->unpack();
$repairedLive = \PowerSweeper\StudioLiveChecker::check($repairedArchive->documents(), ['extract_dir' => $repairedArchive->extractDir()]);
$repairedArchive->cleanup();
assert_true($repairedLive['total'] < 55, 'Repaired pipeline live checker under 55 (got ' . $repairedLive['total'] . ')');
assert_true(($repairedLive['by_category']['formulas'] ?? 0) === 0, 'Repaired pipeline has no formula errors');
assert_true(($repairedLive['by_category']['performance'] ?? 0) > 0, 'Repaired pipeline retains delegation hints');
@unlink($repairedPipelineOut);

// StudioErrorDetector — App (16) SARIF inventory
if (is_file($app16)) {
    $det = \PowerSweeper\StudioErrorDetector::detectFromMsapp($app16, false);
    assert_true($det['sarif_present'], 'App (16) has AppCheckerResult.sarif');
    assert_true($det['total'] === 1719, 'App (16) SARIF total is 1719');
    assert_true(($det['by_category']['formulas'] ?? 0) === 1112, 'App (16) formula count');
    assert_true(($det['by_category']['accessibility'] ?? 0) === 476, 'App (16) a11y count');
    assert_true($det['auto_fixable'] === 581, 'App (16) auto-fixable count');
}

// AppControlCatalog — suffix strip and cross-screen qualify
$cat = \PowerSweeper\AppControlCatalog::build([]);
// minimal synthetic catalog via reflection is heavy; test resolve paths with a tiny mock doc set
$fixtureYaml = __DIR__ . '/fixtures/screen.pa.yaml';
if (is_file($fixtureYaml)) {
    $doc = ControlDocument::fromFile($fixtureYaml, 'Src/Screen1.pa.yaml');
    if ($doc !== null) {
        $miniCat = \PowerSweeper\AppControlCatalog::build([$doc]);
        assert_true($miniCat->quoteScreen('VCR / VCN Form') === "'VCR / VCN Form'", 'quoteScreen wraps spaced name');
        assert_true($miniCat->qualify('VCR / VCN Form', '2_Requesting') === "'VCR / VCN Form'.'2_Requesting'", 'qualify numeric control');
    }
}

$repaired16 = dirname(__DIR__) . '/samples/import_debug/CDLS_L_VCR_App_16.repaired.msapp';
if (is_file($repaired16)) {
    $archive = new \PowerSweeper\MsappArchive($repaired16);
    $archive->unpack();
    $post = \PowerSweeper\StudioPostRepairValidator::validate($archive->documents());
    $archive->cleanup();
    assert_true(($post['by_kind']['missing_package_field'] ?? 0) === 0, 'App (16) repaired has no varCurrentPackage field drift');
    assert_true(($post['by_kind']['unresolved_control_ref'] ?? 0) === 0, 'App (16) repaired has no unresolved control refs');
    assert_true(($post['by_category']['accessibility'] ?? 0) === 0, 'App (16) repaired has no a11y issues');
    // Delegation warnings are expected; formula heuristics may still report locale edge cases.
    assert_true($post['total'] < 60, 'App (16) repaired heuristic total under 60 (was 1719 SARIF)');
}

echo "\n";
if ($failed > 0) {
    echo "FAILED: {$failed} assertion(s)\n";
    exit(1);
}
echo "All tests passed.\n";
exit(0);
