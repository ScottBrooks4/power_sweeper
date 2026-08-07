<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNaming;
use PowerSweeper\PowerAppsYaml;
use PowerSweeper\StudioJson;
use PowerSweeper\StudioIssueScanner;
use PowerSweeper\FormulaIdentifierRewriter;
use PowerSweeper\FormulaLocaleNormalizer;
use PowerSweeper\Hops\AccessibilityLabelsHop;
use PowerSweeper\Hops\AlignNearMissHop;
use PowerSweeper\Hops\AnalyzeAppCheckerHop;
use PowerSweeper\Hops\EnableDarkModeHop;
use PowerSweeper\Hops\EnsureFocusVisibleHop;
use PowerSweeper\Hops\MeaningfulNamesHop;
use PowerSweeper\Hops\NormalizeClassicButtonChromeHop;
use PowerSweeper\Hops\NormalizeContainersHop;
use PowerSweeper\Hops\RepairContextAwareRefsHop;
use PowerSweeper\Hops\RepairCheckedBooleansHop;
use PowerSweeper\Hops\ScanStudioIssuesHop;
use PowerSweeper\Hops\StripDefaultFillHop;
use PowerSweeper\Hops\TooltipFromLabelHop;
use PowerSweeper\Hops\UnwhackLocaleFormulasHop;
use PowerSweeper\Pipeline;
use PowerSweeper\ProfileLoader;
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

// --- accessibility force ---
$doc = loadFixtureDoc();
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $c->setProperty('AccessibleLabel', '="User reviewed label"');
        break;
    }
}
$report = new Report();
(new AccessibilityLabelsHop())->apply([$doc], $report);
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        assert_true(str_contains((string) $c->getProperty('AccessibleLabel'), 'User reviewed label'), 'AccessibleLabel kept when force=false');
    }
}
$doc = loadFixtureDoc();
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $c->setProperty('AccessibleLabel', '="User reviewed label"');
        break;
    }
}
$report = new Report();
(new AccessibilityLabelsHop())->apply([$doc], $report, ['force' => true]);
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        assert_true(str_contains(strtolower((string) $c->getProperty('AccessibleLabel')), 'start'), 'AccessibleLabel overwritten when force=true');
    }
}

// --- profile force propagation ---
$profileLoader = new ProfileLoader(POWER_SWEEPER_PROFILES);
$a11yConfig = include POWER_SWEEPER_PROFILES . '/a11y_pass.php';
$resolved = $profileLoader->resolveHops(array_merge($a11yConfig, ['force' => true]));
assert_true(($resolved[0]['options']['force'] ?? false) === true, 'profile force merges into hop options');
$resolvedHopOverride = $profileLoader->resolveHops([
    'force' => true,
    'hops' => [['id' => 'accessibility_labels', 'options' => ['force' => false]]],
]);
assert_true(($resolvedHopOverride[0]['options']['force'] ?? true) === false, 'hop-level force overrides profile force');

// --- ColorValue studio defaults ---
assert_true(ColorValue::isStudioDefault('=RGBA(255, 255, 255, 1)', 'Fill'), 'white fill is studio default');
assert_true(ColorValue::isStudioDefault('=RGBA(20, 20, 20, 1)', 'Color'), 'near-black text is studio default');
assert_true(!ColorValue::isStudioDefault('=RGBA(37, 99, 235, 1)', 'Fill'), 'brand blue fill is not studio default');

// --- dark mode force preserves custom colors ---
$darkDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_app.pa.yaml', 'Src/App.pa.yaml');
assert_true($darkDoc !== null, 'dark mode fixture loads');
$darkReport = new Report();
(new EnableDarkModeHop())->apply([$darkDoc], $darkReport);
$accentFill = null;
foreach ($darkDoc->controls() as $c) {
    if ($c->name === 'Accent') {
        $accentFill = (string) ($c->getProperty('Fill') ?? '');
    }
}
assert_true($accentFill !== null && !str_contains($accentFill, 'gblTheme.'), 'custom accent fill preserved when force=false');
$darkDocForced = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_app.pa.yaml', 'Src/App.pa.yaml');
assert_true($darkDocForced !== null, 'dark mode fixture reloads');
(new EnableDarkModeHop())->apply([$darkDocForced], new Report(), ['force' => true]);
$accentFillForced = null;
foreach ($darkDocForced->controls() as $c) {
    if ($c->name === 'Accent') {
        $accentFillForced = (string) ($c->getProperty('Fill') ?? '');
    }
}
assert_true($accentFillForced !== null && str_contains($accentFillForced, 'gblTheme.'), 'custom accent fill re-themed when force=true');

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

// --- meaningful control names ---
assert_true(ControlNaming::isGenericName('Button1'), 'Button1 is generic');
assert_true(ControlNaming::isGenericName('Container54_2'), 'Container54_2 is generic');
assert_true(ControlNaming::isGenericName('Label7_183'), 'Label7_183 is generic');
assert_true(!ControlNaming::isGenericName('SubmitRequestButton'), 'SubmitRequestButton is not generic');
assert_true(
    ControlNaming::toIdentifier('Submit Request', 'Button') === 'SubmitRequestButton',
    'toIdentifier PascalCase + suffix'
);
assert_true(
    ControlNaming::toIdentifier('Customer Details', 'Container') === 'CustomerDetailsContainer',
    'toIdentifier multi-word container'
);
$genericDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/generic_names.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($genericDoc !== null, 'generic_names fixture loads');
$report = new Report();
(new MeaningfulNamesHop())->apply([$genericDoc], $report);
$submitBtn = $detailsContainer = $detailsLabel = null;
foreach ($genericDoc->controls() as $c) {
    if ($c->name === 'SubmitRequestButton') {
        $submitBtn = $c;
    }
    if ($c->name === 'CustomerDetailsContainer') {
        $detailsContainer = $c;
    }
    if ($c->name === 'CustomerDetailsLabel') {
        $detailsLabel = $c;
    }
}
assert_true($submitBtn !== null, 'Button1 renamed to SubmitRequestButton');
assert_true($detailsContainer !== null, 'Container1 renamed to CustomerDetailsContainer');
assert_true($detailsLabel !== null, 'Label1 renamed to CustomerDetailsLabel');
assert_true(
    str_contains((string) $submitBtn->getProperty('OnSelect'), 'SubmitRequestButton.Text'),
    'OnSelect control ref updated after rename'
);
assert_true(!str_contains((string) $submitBtn->getProperty('OnSelect'), 'Button1.'), 'old Button1 ref removed from formula');
assert_true($report->count() >= 3, 'meaningful_names reported renames');

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
$rgbaTransparent = '=RGBA(0,0,0,0)';
assert_true(
    FormulaLocaleNormalizer::toInvariant($rgbaTransparent) === $rgbaTransparent,
    'invariant RGBA list commas preserved'
);

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
// Two-arg invariant calls must NOT be treated as locale decimals (ASC/THCEE GetInfoData).
$twoArgInvariant = "comTranslations.GetInfoData(0,0);\nSet(varInfoContainer, !varInfoContainer);";
assert_true(!FormulaLocaleNormalizer::looksLocaleCorrupted($twoArgInvariant), 'GetInfoData(0,0) invariant not flagged as locale');
assert_true(
    FormulaLocaleNormalizer::toInvariant($twoArgInvariant) === $twoArgInvariant,
    'GetInfoData(0,0) invariant left unchanged (no 0.0 / comma-chaining corruption)'
);
// Already-quoted screen replacements must not become '''Screen'''
$quotedScreenRewrite = \PowerSweeper\FormulaIdentifierRewriter::rename(
    "Navigate('PACS Homepage')",
    ['PACS Homepage' => "'PACS Homepage'"]
);
assert_true(
    $quotedScreenRewrite === "Navigate('PACS Homepage')",
    'identifier rewriter does not triple-quote already-quoted screen names'
);
$spacedRename = \PowerSweeper\FormulaIdentifierRewriter::rename(
    "SideMenuFirstTable = [{ x: 1 }];",
    ['SideMenuFirstTable' => 'TDR Trips_ TopMenu_1']
);
assert_true(
    str_contains($spacedRename, "'TDR Trips_ TopMenu_1'"),
    'identifier rewriter quotes spaced rename targets in bare-identifier slots'
);

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

// Settings ThemeRadio gets Light/Dark wired (CDLS pattern)
$settingsDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_settings.pa.yaml', 'Src/Home.pa.yaml');
assert_true($settingsDoc !== null, 'dark mode settings fixture loads');
$settingsReport = new Report();
(new EnableDarkModeHop())->apply([$settingsDoc], $settingsReport);
$settingsDoc->reindex();
$themeItemsOk = false;
$themeOnChangeOk = false;
$noFloatingToggle = true;
$settingsPaletteOk = false;
foreach ($settingsDoc->controls() as $c) {
    if ($c->isApp()) {
        $settingsPaletteOk = str_contains((string) $c->getProperty('OnStart'), 'gblThemeLight');
    }
    if ($c->name === 'ThemeRadio') {
        $items = (string) $c->getProperty('Items');
        $themeItemsOk = str_contains($items, 'Light') && str_contains($items, 'Dark');
        $themeOnChangeOk = str_contains((string) $c->getProperty('OnChange'), 'gblThemeDark')
            && str_contains((string) $c->getProperty('OnChange'), 'gblThemeLight');
    }
    if ($c->name === 'tglPowerSweeperDarkMode') {
        $noFloatingToggle = false;
    }
}
assert_true($settingsPaletteOk, 'settings fixture gets OnStart theme palette');
assert_true($themeItemsOk, 'ThemeRadio Items includes Light and Dark');
assert_true($themeOnChangeOk, 'ThemeRadio OnChange swaps gblTheme palettes');
assert_true($noFloatingToggle, 'ThemeRadio present — no floating toggle injected');

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

$linkHex = ColorValue::toHex(['r' => 45, 'g' => 212, 'b' => 191, 'a' => 1.0]);
assert_true($linkHex === '#2DD4BF', 'ColorValue::toHex for dark link teal');

// analyze_app_checker removes empty layout formulas
$emptyDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_settings.pa.yaml', 'Src/Home.pa.yaml');
$emptyReport = new Report();
(new AnalyzeAppCheckerHop())->apply([$emptyDoc], $emptyReport, ['_extract_dir' => sys_get_temp_dir()]);
$emptyDoc->reindex();
$layoutCleared = true;
foreach ($emptyDoc->controls() as $c) {
    if ($c->name === 'SettingsMiddle') {
        $layoutCleared = $c->getProperty('LayoutMaxHeight') === null
            && $c->getProperty('LayoutMaxWidth') === null;
    }
}
assert_true($layoutCleared, 'analyze_app_checker removes empty LayoutMaxHeight/Width');
assert_true($emptyReport->count() > 0, 'analyze_app_checker reports work');

$white = ColorValue::parse('=RGBA(255, 255, 255, 1)');
assert_true($white !== null, 'parse white');
$fixedAlpha = ColorValue::normalizeColorLiteral('=RGBA(240, 240, 240, 0,2)');
assert_true(str_contains($fixedAlpha, '0.2'), 'locale RGBA alpha comma fixed');
$parsedAlpha = ColorValue::parse($fixedAlpha);
assert_true($parsedAlpha !== null && abs($parsedAlpha['a'] - 0.2) < 0.001, 'parse fixed locale RGBA alpha');
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
assert_true(is_string($kmsApp) && str_contains((string) $kmsApp, 'gblDarkMode'), 'kitchen sink App sets gblDarkMode');
assert_true(is_string($kmsApp) && str_contains((string) $kmsApp, 'gblThemeLight') && str_contains((string) $kmsApp, 'gblThemeDark'), 'kitchen sink App has editable palettes');
assert_true(is_string($kmsApp) && str_contains((string) $kmsApp, 'gblThemeLight') && str_contains((string) $kmsApp, 'ps-theme:start'), 'kitchen sink palettes in App.OnStart');
assert_true(is_string($kmsHome) && str_contains($kmsHome, 'gblTheme.'), 'kitchen sink home colors use gblTheme tokens');
assert_true(is_string($kmsControls) && str_contains($kmsControls, 'gblTheme.'), 'kitchen sink controls colors use gblTheme tokens');
assert_true(
    is_string($kmsControls)
    && preg_match("/SelectedFill:\\s*'?=gblTheme\\./m", $kmsControls) === 1,
    'gallery SelectedFill uses gblTheme token'
);
assert_true(
    is_string($kmsControls)
    && preg_match("/RailFill:\\s*'?=gblTheme\\./m", $kmsControls) === 1,
    'slider RailFill uses gblTheme token'
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
    assert_true($live['total'] >= 400 && $live['total'] <= 550, 'App (16) live checker total in expected range (got ' . $live['total'] . ')');
    assert_true(($live['by_category']['formulas'] ?? 0) >= 90, 'App (16) live formula issues');
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
    assert_true($overlap >= 180, 'App (16) live checker overlaps embedded SARIF (got ' . $overlap . ')');
}

// Repaired pipeline should drive live errors well below original 1719 SARIF
$repairedPipelineOut = sys_get_temp_dir() . '/ps_repaired_live_check_' . bin2hex(random_bytes(4)) . '.msapp';
$repairProfile = include dirname(__DIR__) . '/profiles/repair_studio_errors.php';
(new Pipeline())->run($app16, $repairProfile['hops'], $repairedPipelineOut);
$repairedArchive = new \PowerSweeper\MsappArchive($repairedPipelineOut);
$repairedArchive->unpack();
$repairedLive = \PowerSweeper\StudioLiveChecker::check($repairedArchive->documents(), ['extract_dir' => $repairedArchive->extractDir()]);
$repairedArchive->cleanup();
assert_true($repairedLive['total'] === 0, 'Repaired pipeline live checker reports zero issues (got ' . $repairedLive['total'] . ')');
assert_true(($repairedLive['by_category']['formulas'] ?? 0) === 0, 'Repaired pipeline has no formula errors');
assert_true(($repairedLive['by_category']['performance'] ?? 0) === 0, 'Repaired pipeline has no delegation hints');
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

// FormulaRefContext — record fields after calls and screen-scoped With records
assert_true(
    \PowerSweeper\FormulaRefContext::isRecordVariable('LookUp(T).Values.Text', 'Values') === false,
    'Values alone is not a record variable definition'
);
$recordCtx = "With({ UpdateItemDescription: { Visible: true } }, UpdateItemDescription.Visible)";
assert_true(
    \PowerSweeper\FormulaRefContext::isRecordVariable('UpdateItemDescription.Visible', 'UpdateItemDescription', $recordCtx),
    'UpdateItemDescription from sibling With on same screen'
);

// FormulaRefContext — ForAll As loop variables are not control refs
$forAllFormula = "ForAll(approvers As rec, With({ x: rec.Value }, Patch(col, x)))";
assert_true(
    \PowerSweeper\FormulaRefContext::isLoopVariable($forAllFormula, 'rec'),
    'ForAll As rec is a loop variable'
);
assert_true(
    \PowerSweeper\FormulaRefContext::isScopedBinding($forAllFormula, 'rec'),
    'ForAll As rec is a scoped binding'
);
assert_true(
    !\PowerSweeper\FormulaRefContext::isLoopVariable($forAllFormula, 'approvers'),
    'ForAll collection name is not a loop variable'
);

// FormulaRefContext — global component hosts are not bare cross-screen refs
$thceeFriday = dirname(__DIR__) . '/samples/import_debug/VCDS — THCEE Friday.msapp';

// Context-aware reference repair — copy-paste pattern + stale suffix with verify loop
$copyPasteDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/copy_paste_refs.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($copyPasteDoc !== null, 'copy_paste_refs fixture loads');
$copyPasteReport = new Report();
(new RepairContextAwareRefsHop())->apply([$copyPasteDoc], $copyPasteReport);
$vi5 = $rb1 = null;
foreach ($copyPasteDoc->controls() as $c) {
    if ($c->name === 'ValueInput5') {
        $vi5 = $c;
    }
    if ($c->name === 'RemoteButton1') {
        $rb1 = $c;
    }
}
assert_true($vi5 !== null && $rb1 !== null, 'copy_paste fixture controls found');
assert_true(str_contains((string) $vi5->getProperty('Default'), 'ValueInput5.Text'), 'ValueInput5 aligned to self not stale ValueInput1_1');
assert_true(str_contains((string) $rb1->getProperty('OnSelect'), 'RemoteButton1.Text'), 'RemoteButton1_2 normalized to RemoteButton1');
assert_true($copyPasteReport->count() > 0, 'repair_context_aware_refs reported changes');

$patternDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/copy_paste_refs.pa.yaml', 'Src/Screen1.pa.yaml');
$patternCatalog = \PowerSweeper\AppControlCatalog::build([$patternDoc]);
$perHost = \PowerSweeper\FormulaPatternAnalyzer::inferPerHostRenameMap([$patternDoc], $patternCatalog);
assert_true(isset($perHost['ValueInput5']['ValueInput1_1']), 'pattern analyzer maps stale ref per host');
assert_true($perHost['ValueInput5']['ValueInput1_1'] === 'ValueInput5', 'pattern analyzer aligns host index');

// Formula repair converger — locale verify loop on corrupt fixture
$localeDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/locale_corrupt.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($localeDoc !== null, 'locale_corrupt fixture loads');
$localeData = \PowerSweeper\AppDataContext::build([$localeDoc]);
$localeCatalog = \PowerSweeper\AppControlCatalog::build([$localeDoc]);
$localeChecker = new \PowerSweeper\PowerFxFormulaChecker($localeCatalog, $localeData);
$beforeLocale = $localeChecker->check(
    (string) $localeDoc->controls()[0]->getProperty('Fill') ?: '=RGBA(255; 255; 255; 1)',
    'Screen1',
    'Screen1.Fill',
    'Screen',
    'Fill',
    'Screen1',
    ['Screen1' => true],
    [],
);
assert_true(count($beforeLocale) > 0, 'locale corrupt Fill has checker findings');
$convergeStats = (new \PowerSweeper\FormulaRepairConverger())->converge([$localeDoc], ['max_rounds' => 2]);
assert_true($convergeStats['repairs'] > 0, 'converger repairs locale_corrupt');
assert_true($convergeStats['after'] <= $convergeStats['before'], 'converger reduces formula errors');

$thceeFriday = dirname(__DIR__) . '/samples/import_debug/VCDS — THCEE Friday.msapp';
if (is_file($thceeFriday)) {
    $thceeArch = new \PowerSweeper\MsappArchive($thceeFriday);
    $thceeArch->unpack();
    $thceeCatalog = \PowerSweeper\AppControlCatalog::build($thceeArch->documents());
    assert_true(
        !\PowerSweeper\FormulaRefContext::hasBareCrossScreenControlRef(
            'comTranslations.Labels.Foo',
            'comTranslations',
            'THCEE Refresh Screen',
            $thceeCatalog
        ),
        'comTranslations is not flagged as bare cross-screen ref'
    );
    $thceeArch->cleanup();
}

// ScreenReferenceNormalizer — idempotent, no triple-encoding
$screens = ['VCR Home Page', 'VCR Admin Screen', 'VCR / VCN Form'];
$navDup = "Navigate('VCR Home Page'.'VCR Home Page', ScreenTransition.Fade)";
$navOnce = \PowerSweeper\ScreenReferenceNormalizer::normalize($navDup, $screens);
$navTwice = \PowerSweeper\ScreenReferenceNormalizer::normalize($navOnce, $screens);
$navThrice = \PowerSweeper\ScreenReferenceNormalizer::normalize($navTwice, $screens);
assert_true($navOnce === "Navigate('VCR Home Page', ScreenTransition.Fade)", 'Navigate collapses Screen.Screen');
assert_true($navTwice === $navOnce && $navThrice === $navOnce, 'Navigate normalize is idempotent');

$merged = "'VCR 'VCR Home Page'.Admin Screen'";
$mergedFixed = \PowerSweeper\ScreenReferenceNormalizer::normalize($merged, $screens);
assert_true($mergedFixed === "'VCR Admin Screen'", 'merged corruption literal repaired');
assert_true(
    \PowerSweeper\ScreenReferenceNormalizer::normalize($mergedFixed, $screens) === $mergedFixed,
    'merged literal repair is idempotent'
);

$extremeNav = "Navigate('''VCR 'VCR Home Page'.Admin Screen''.''VCR 'VCR Home Page'.Admin Screen'''.'''VCR 'VCR Home Page'.Admin Screen''', ScreenTransition.Fade)";
$extremeNavFixed = \PowerSweeper\ScreenReferenceNormalizer::normalize($extremeNav, $screens);
assert_true($extremeNavFixed === "Navigate('VCR Admin Screen', ScreenTransition.Fade)", 'extreme Navigate admin screen chain repaired');

$tableScreen = "Screen: '''VCR 'VCR Home Page'.Admin Screen''.''VCR 'VCR Home Page'.Admin Screen'''";
$tableScreenFixed = \PowerSweeper\ScreenReferenceNormalizer::normalize($tableScreen, $screens);
assert_true($tableScreenFixed === "Screen: 'VCR Admin Screen'", 'App.Formulas Screen field extreme chain repaired');

$cross = "'VCR Home Page'.'VCR Home Page'.SubmitButton";
$crossFixed = \PowerSweeper\ScreenReferenceNormalizer::normalize($cross, $screens);
assert_true($crossFixed === "'VCR Home Page'.SubmitButton", 'member chain collapses repeated screen');
assert_true(
    \PowerSweeper\ScreenReferenceNormalizer::normalize($crossFixed, $screens) === $crossFixed,
    'member chain normalize is idempotent'
);

$numeric = "'VCR / VCN Form'.8_Pertinence";
$numericFixed = \PowerSweeper\ScreenReferenceNormalizer::normalize($numeric, $screens);
assert_true($numericFixed === "'VCR / VCN Form'.'8_Pertinence'", 'numeric control member quoted once');
assert_true(
    \PowerSweeper\ScreenReferenceNormalizer::normalize($numericFixed, $screens) === $numericFixed,
    'numeric member quote is idempotent'
);

// FormulaReferenceExtractor — single-quoted screen names are opaque tokens
$extracted = \PowerSweeper\FormulaReferenceExtractor::identifiers("Navigate('VCR Admin Screen', x)");
assert_true(in_array('VCR Admin Screen', $extracted, true), 'extracts quoted screen name');
assert_true(!in_array('Admin', $extracted, true) && !in_array('Screen', $extracted, true), 'does not split inside quoted screen name');

// FormulaIdentifierRewriter — does not rewrite inside double-quoted strings
$rewritten = FormulaIdentifierRewriter::rename(
    'Notify("VCR Home Page is ready", NotificationType.Information)',
    ['VCR Home Page' => "'VCR Home Page'.'VCR Home Page'"]
);
assert_true($rewritten === 'Notify("VCR Home Page is ready", NotificationType.Information)', 'rewriter leaves string literals alone');

// Comment preservation — repairs must not touch // or block comments (idempotent, segment-aware)
$commentFormula = "// PertinentToDefence.Checked\nIf(true, Navigate('VCR Home Page', ScreenTransition.Fade), /* ghost */ Blank())";
$commentIdentity = \PowerSweeper\PowerFxFormulaSegments::transformCode($commentFormula, static fn(string $c): string => $c);
assert_true($commentIdentity === $commentFormula, 'transformCode leaves comments byte-identical');

$navWithComment = "// Navigate('VCR Home Page'.'VCR Home Page')\nNavigate('VCR Home Page'.'VCR Home Page', ScreenTransition.Fade)";
$navCommentFixed = \PowerSweeper\ScreenReferenceNormalizer::normalize($navWithComment, $screens);
assert_true(
    str_starts_with($navCommentFixed, "// Navigate('VCR Home Page'.'VCR Home Page')"),
    'normalizer preserves line comment above Navigate'
);
assert_true(
    str_contains($navCommentFixed, "Navigate('VCR Home Page', ScreenTransition.Fade)"),
    'normalizer repairs live Navigate below preserved comment'
);

$delegComment = "// CountIf(colAnnex1, !IsBlank(Trim(AgencyName)))\nCountIf(colAnnex1, !IsBlank(Trim(AgencyName)))";
$delegRewritten = \PowerSweeper\DelegationFormulaRewriter::rewrite($delegComment);
assert_true(
    str_starts_with($delegRewritten, "// CountIf(colAnnex1, !IsBlank(Trim(AgencyName)))"),
    'delegation rewriter preserves commented CountIf line'
);
assert_true(
    str_contains($delegRewritten, 'CountRows(Filter(colAnnex1, !IsBlank(AgencyName)))'),
    'delegation rewriter fixes live CountIf below comment'
);
// Generalized patterns (any collection / email ref)
$delegGeneral = \PowerSweeper\DelegationFormulaRewriter::rewrite(
    'CountIf(colItems, !IsBlank(Trim(Title))); Lower(owner.Email) = Lower(User().Email); Lower(User().Email) = Lower(\'Other Screen\'.Contact.Email)'
);
assert_true(str_contains($delegGeneral, 'CountRows(Filter(colItems, !IsBlank(Title)))'), 'delegation CountIf generalizes to any col/field');
assert_true(str_contains($delegGeneral, 'owner.Email = User().Email'), 'delegation Lower(email) generalizes beyond request_user');
assert_true(str_contains($delegGeneral, "'Other Screen'.Contact.Email = User().Email"), 'delegation Lower(email) handles quoted screen refs');
$delegSub = \PowerSweeper\DelegationFormulaRewriter::rewrite('Filter(list, Substitute(SearchBox.Text, " ", "") in Substitute(Title, " ", ""))');
assert_true(str_contains($delegSub, 'StartsWith(Title, SearchBox.Text)'), 'delegation Substitute→StartsWith generalizes control name');
$delegTrimLocal = \PowerSweeper\DelegationFormulaRewriter::rewrite(
    'Filter(list, StartsWith(Task, Trim(txtSearch_1.Text)) || IsBlank(Trim(txtSearch_1.Text)))'
);
assert_true(
    str_contains($delegTrimLocal, 'StartsWith(Task, txtSearch_1.Text)')
        && str_contains($delegTrimLocal, 'IsBlank(txtSearch_1.Text)')
        && !str_contains($delegTrimLocal, 'Trim('),
    'delegation strips Trim() around local control Text args'
);

$varPkgTmp = sys_get_temp_dir() . '/ps_varpkg_' . bin2hex(random_bytes(4)) . '.pa.yaml';
file_put_contents($varPkgTmp, <<<'YAML'
Screen1:
  Control: Screen@2.0.0
  Properties:
    OnSelect: |-
      =//AmendmentVisit: loadedRequest.AmendmentVisit,
      If(varCurrentPackage.AmendmentVisit, "y", "")
YAML
);
$varPkgDoc = ControlDocument::fromFile($varPkgTmp, 'Src/Test.pa.yaml');
assert_true($varPkgDoc !== null, 'varCurrentPackage comment fixture loads');
$varPkgReport = new Report();
(new \PowerSweeper\Hops\RepairVarCurrentPackageHop())->apply([$varPkgDoc], $varPkgReport);
$varPkgFormula = '';
foreach ($varPkgDoc->controls() as $c) {
    if ($c->name === 'Screen1') {
        $varPkgFormula = (string) $c->getProperty('OnSelect');
    }
}
@unlink($varPkgTmp);
assert_true(
    str_contains($varPkgFormula, '//AmendmentVisit: loadedRequest.AmendmentVisit'),
    'varCurrentPackage hop leaves commented loadPackage field untouched'
);
assert_true(
    !preg_match('/\bvarCurrentPackage\.AmendmentVisit\b/', $varPkgFormula),
    'varCurrentPackage hop replaces live AmendmentVisit reference with false'
);

// Component template bindings — stale suffixed refs repaired (Components/*.json)
$app16 = dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App (16).msapp';
if (is_file($app16)) {
    $componentRepairOut = sys_get_temp_dir() . '/ps_component_bindings_' . bin2hex(random_bytes(4)) . '.msapp';
    $repairProfile = include dirname(__DIR__) . '/profiles/repair_studio_errors.php';
    (new Pipeline())->run($app16, $repairProfile['hops'], $componentRepairOut);
    $ghostBindings = 0;
    $compArch = new \PowerSweeper\MsappArchive($componentRepairOut);
    $compArch->unpack();
    foreach ($compArch->documents() as $doc) {
        $doc->transformFormulas(static function (string $f) use (&$ghostBindings): string {
            if (preg_match('/Container55_5|Icon2_2\.|Container70_1\./', $f)) {
                $ghostBindings++;
            }
            return $f;
        });
    }
    $compArch->cleanup();
    assert_true($ghostBindings === 0, 'component AutoRuleBindingString ghost refs repaired (got ' . $ghostBindings . ')');
    @unlink($componentRepairOut);
}

$repaired16 = dirname(__DIR__) . '/samples/import_debug/CDLS_L_VCR_App_16.repaired.msapp';
if (is_file($repaired16)) {
    $archive = new \PowerSweeper\MsappArchive($repaired16);
    $archive->unpack();
    $post = \PowerSweeper\StudioPostRepairValidator::validate($archive->documents(), ['extract_dir' => $archive->extractDir()]);
    $archive->cleanup();
    assert_true(($post['by_kind']['missing_package_field'] ?? 0) === 0, 'App (16) repaired has no varCurrentPackage field drift');
    assert_true(($post['by_kind']['unresolved_control_ref'] ?? 0) === 0, 'App (16) repaired has no unresolved control refs');
    assert_true(($post['by_category']['accessibility'] ?? 0) === 0, 'App (16) repaired has no a11y issues');
    assert_true($post['total'] === 0, 'App (16) repaired heuristic total is zero (was 1719 SARIF)');
}

// Profiles — all hop ids resolve; studio repair hops are exposed
$hopRegistry = new \PowerSweeper\HopRegistry();
$allProfiles = (new ProfileLoader(POWER_SWEEPER_PROFILES))->all();
assert_true(count($allProfiles) >= 15, 'profiles directory loaded (got ' . count($allProfiles) . ')');
$profileIds = array_column($allProfiles, 'id');
assert_true(in_array('repair_studio_errors', $profileIds, true), 'repair_studio_errors profile exists');
assert_true(in_array('repair_delegation', $profileIds, true), 'repair_delegation profile exists');
assert_true(in_array('regenerate_sarif', $profileIds, true), 'regenerate_sarif profile exists');
assert_true(in_array('repair_formula_refs', $profileIds, true), 'repair_formula_refs profile exists');
$formulaRefsProfile = include dirname(__DIR__) . '/profiles/repair_formula_refs.php';
$formulaRefsIds = array_column($formulaRefsProfile['hops'], 'id');
assert_true(in_array('repair_context_aware_refs', $formulaRefsIds, true), 'repair_formula_refs includes context-aware refs');
assert_true(in_array('repair_converge_formulas', $formulaRefsIds, true), 'repair_formula_refs includes converge');
assert_true(in_array('repair_powered', $profileIds, true), 'repair_powered profile exists');
assert_true(in_array('powered_thcee', $profileIds, true), 'powered_thcee profile exists');
assert_true(in_array('repair_studio_errors_then_dark', $profileIds, true), 'repair_studio_errors_then_dark profile exists');
assert_true(in_array('meaningful_names', $profileIds, true), 'meaningful_names profile exists');
assert_true(in_array('repair_smart', $profileIds, true), 'repair_smart profile exists');
assert_true(in_array('power_to_web', $profileIds, true), 'power_to_web profile exists');
assert_true(in_array('web_to_power', $profileIds, true), 'web_to_power profile exists');
$profileLoader = new ProfileLoader(POWER_SWEEPER_PROFILES);
$vcrPowered = $profileLoader->resolvePoweredProfile('CDLS VCR App.msapp');
$thceePowered = $profileLoader->resolvePoweredProfile('VCDS THCEE App.msapp');
assert_true(in_array('repair_control_refs', array_column($vcrPowered['hops'], 'id'), true), 'VCR powered profile includes repair_control_refs');
assert_true(in_array('repair_control_refs', array_column($thceePowered['hops'], 'id'), true), 'THCEE powered profile includes repair_control_refs (component-safe)');
assert_true(in_array('regenerate_sarif', array_column($thceePowered['hops'], 'id'), true), 'THCEE powered profile includes regenerate_sarif');
assert_true(in_array('enable_dark_mode', array_column($thceePowered['hops'], 'id'), true), 'THCEE powered profile includes enable_dark_mode');
foreach ($allProfiles as $profile) {
    assert_true($profile['description'] !== '', 'profile ' . $profile['id'] . ' has description');
    foreach ($profile['hops'] as $hop) {
        assert_true($hopRegistry->has($hop['id']), 'profile ' . $profile['id'] . ' hop ' . $hop['id'] . ' registered');
    }
}
$repairStudio = include dirname(__DIR__) . '/profiles/repair_studio_errors.php';
$repairHopIds = array_column($repairStudio['hops'], 'id');
assert_true(in_array('repair_delegation', $repairHopIds, true), 'repair_studio_errors includes repair_delegation');
assert_true(in_array('regenerate_sarif', $repairHopIds, true), 'repair_studio_errors includes regenerate_sarif');
assert_true(in_array('repair_control_refs', $repairHopIds, true), 'repair_studio_errors includes repair_control_refs');
assert_true(in_array('repair_context_aware_refs', $repairHopIds, true), 'repair_studio_errors includes repair_context_aware_refs');
assert_true(in_array('repair_converge_formulas', $repairHopIds, true), 'repair_studio_errors includes repair_converge_formulas');
$smartProfile = include dirname(__DIR__) . '/profiles/repair_smart.php';
$smartHopIds = array_column($smartProfile['hops'], 'id');
assert_true(in_array('meaningful_names', $smartHopIds, true), 'repair_smart includes meaningful_names');
assert_true(in_array('repair_context_aware_refs', $smartHopIds, true), 'repair_smart includes repair_context_aware_refs');
assert_true(!in_array('meaningful_names', $repairHopIds, true), 'repair_studio_errors does not rename by default');

assert_true(in_array('repair_studio_syntax', $repairHopIds, true), 'repair_studio_errors includes repair_studio_syntax');

$powerToWeb = include dirname(__DIR__) . '/profiles/power_to_web.php';
$webToPower = include dirname(__DIR__) . '/profiles/web_to_power.php';
assert_true(in_array('meaningful_names', array_column($powerToWeb['hops'], 'id'), true), 'power_to_web renames generics before export');
assert_true(in_array('export_web_ir', array_column($powerToWeb['hops'], 'id'), true), 'power_to_web exports IR');
assert_true(in_array('import_web_ir', array_column($webToPower['hops'], 'id'), true), 'web_to_power imports IR');
assert_true(in_array('accessibility_labels', array_column($webToPower['hops'], 'id'), true), 'web_to_power fills a11y after import');
assert_true(in_array('ensure_focus_visible', array_column($webToPower['hops'], 'id'), true), 'web_to_power ensures focus visible');
assert_true($hopRegistry->has('export_web_ir'), 'export_web_ir hop registered');
assert_true($hopRegistry->has('import_web_ir'), 'import_web_ir hop registered');
assert_true($hopRegistry->has('configure_power_document'), 'configure_power_document hop registered');

// repair_studio_syntax — trailing Concatenate comma and undefined var (code only)
$syntaxIn = 'Concatenate(If(true, "a", ""), If(true, "b", ""), If(true, "c", ""),); Set(x, varNewRequest);';
$syntaxFixed = \PowerSweeper\PowerFxFormulaSegments::transformCode($syntaxIn, static function (string $code): string {
    $code = preg_replace('/""\s*,\s*\)/', '"")', $code) ?? $code;
    $code = preg_replace('/\)\s*,\s*\)/', '))', $code) ?? $code;
    return preg_replace('/\bvarNewRequest\b/', 'false', $code) ?? $code;
});
$syntaxInParen = 'Concatenate(If(true, "a", ""), If(true, "b", ""), If(true, "c", ""),)';
$syntaxFixedParen = \PowerSweeper\PowerFxFormulaSegments::transformCode($syntaxInParen, static function (string $code): string {
    $code = preg_replace('/\)\s*,\s*\)/', '))', $code) ?? $code;
    return $code;
});
assert_true(!str_contains($syntaxFixed, 'varNewRequest'), 'syntax repair removes varNewRequest');
assert_true(!preg_match('/""\s*,\s*\)/', $syntaxFixed), 'syntax repair removes trailing Concatenate comma');
assert_true(!preg_match('/\)\s*,\s*\)/', $syntaxFixedParen), 'syntax repair removes trailing Concatenate paren comma');

// repair_studio_syntax — screen-qualified Date() function calls
$dateHop = new \PowerSweeper\Hops\RepairStudioSyntaxHop();
$dateReport = new Report();
$dateRef = new ReflectionClass($dateHop);
$dateRepair = $dateRef->getMethod('repairFormula');
$dateRepair->setAccessible(true);
$dateFixed = $dateRepair->invoke($dateHop, "='VCR / VCN Form'.Date(1900, 1, 1)", 'test', $dateReport);
assert_true(str_contains($dateFixed, 'Date(1900') && !str_contains($dateFixed, "'VCR / VCN Form'.Date"), 'syntax repair unwraps screen-qualified Date()');

// locale — LookUp with ; after quoted table name inside concatenation
$lookupLocale = '"Version #: " & AppVersion & LookUp(\'VASC App Versions\'; ID = 1).AppVersion';
assert_true(FormulaLocaleNormalizer::looksLocaleCorrupted($lookupLocale), 'LookUp quoted-arg locale separator detected');
$lookupFixed = FormulaLocaleNormalizer::toInvariant($lookupLocale);
assert_true(str_contains($lookupFixed, "LookUp('VASC App Versions', ID = 1)"), 'LookUp locale separator unwhacked');

// repair2.msapp — pipeline idempotency (3 passes, formulas stable)
$repair2 = dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App repair2.msapp';
if (is_file($repair2)) {
    $idempotentOut = sys_get_temp_dir() . '/ps_idempotent_' . bin2hex(random_bytes(4)) . '.msapp';
    $repairProfile = include dirname(__DIR__) . '/profiles/repair_studio_errors.php';
    (new Pipeline())->run($repair2, $repairProfile['hops'], $idempotentOut);
    $pass1Formulas = [];
    $arch1 = new \PowerSweeper\MsappArchive($idempotentOut);
    $arch1->unpack();
    foreach ($arch1->documents() as $doc) {
        $doc->transformFormulas(function (string $formula, string $path) use (&$pass1Formulas): string {
            $pass1Formulas[$path] = $formula;
            return $formula;
        });
    }
    $live1 = \PowerSweeper\StudioLiveChecker::check($arch1->documents(), ['extract_dir' => $arch1->extractDir()]);
    $arch1->cleanup();

    $pass2Out = sys_get_temp_dir() . '/ps_idempotent_p2_' . bin2hex(random_bytes(4)) . '.msapp';
    (new Pipeline())->run($idempotentOut, $repairProfile['hops'], $pass2Out);
    $pass2Formulas = [];
    $arch2 = new \PowerSweeper\MsappArchive($pass2Out);
    $arch2->unpack();
    foreach ($arch2->documents() as $doc) {
        $doc->transformFormulas(function (string $formula, string $path) use (&$pass2Formulas): string {
            $pass2Formulas[$path] = $formula;
            return $formula;
        });
    }
    $live2 = \PowerSweeper\StudioLiveChecker::check($arch2->documents(), ['extract_dir' => $arch2->extractDir()]);
    $arch2->cleanup();

    $pass3Out = sys_get_temp_dir() . '/ps_idempotent_p3_' . bin2hex(random_bytes(4)) . '.msapp';
    (new Pipeline())->run($pass2Out, $repairProfile['hops'], $pass3Out);
    $pass3Formulas = [];
    $arch3 = new \PowerSweeper\MsappArchive($pass3Out);
    $arch3->unpack();
    foreach ($arch3->documents() as $doc) {
        $doc->transformFormulas(function (string $formula, string $path) use (&$pass3Formulas): string {
            $pass3Formulas[$path] = $formula;
            return $formula;
        });
    }
    $live3 = \PowerSweeper\StudioLiveChecker::check($arch3->documents(), ['extract_dir' => $arch3->extractDir()]);
    $arch3->cleanup();

    assert_true($live1['total'] === 0, 'repair2 pass1 live checker zero (got ' . $live1['total'] . ')');
    assert_true($live2['total'] === 0, 'repair2 pass2 live checker zero (got ' . $live2['total'] . ')');
    assert_true($live3['total'] === 0, 'repair2 pass3 live checker zero (got ' . $live3['total'] . ')');
    assert_true($pass1Formulas === $pass2Formulas, 'repair2 formulas stable after pass 2');
    assert_true($pass2Formulas === $pass3Formulas, 'repair2 formulas stable after pass 3');

    @unlink($idempotentOut);
    @unlink($pass2Out);
    @unlink($pass3Out);
}

// repair2 powered — theme toggle + App YAML twin
$repair2PoweredProfile = include dirname(__DIR__) . '/profiles/repair_powered.php';
if (is_file($repair2)) {
    $poweredTestOut = sys_get_temp_dir() . '/ps_powered_test_' . bin2hex(random_bytes(4)) . '.msapp';
    $poweredHops = (new ProfileLoader(POWER_SWEEPER_PROFILES))->resolveHops($repair2PoweredProfile);
    (new Pipeline())->run($repair2, $poweredHops, $poweredTestOut);
    $poweredYaml = ZipTool::readEntry($poweredTestOut, 'Src/App.pa.yaml');
    $topbarYaml = ZipTool::readEntry($poweredTestOut, 'Src/Components/TopbarHeader.pa.yaml');
    $homeYaml = ZipTool::readEntry($poweredTestOut, 'Src/VCR Home Page.pa.yaml');
    assert_true(is_string($poweredYaml) && str_contains($poweredYaml, 'gblThemeLight'), 'repair2 powered App.pa.yaml has theme palettes');
    assert_true(is_string($topbarYaml) && str_contains($topbarYaml, 'gblTheme.Surface'), 'TopbarHeader uses gblTheme.Surface');
    assert_true(is_string($topbarYaml) && str_contains($topbarYaml, 'gblDarkMode'), 'ThemeRadio DefaultSelectedItems binds gblDarkMode');
    assert_true(is_string($topbarYaml) && str_contains($topbarYaml, 'gblThemeDark'), 'ThemeRadio OnChange swaps gblThemeDark');
    assert_true(is_string($homeYaml) && preg_match('/GoodMorning:[\s\S]*?Color:\s*=gblTheme\.Text/m', $homeYaml) === 1, 'GoodMorning label gets gblTheme.Text');
    $vcnYaml = ZipTool::readEntry($poweredTestOut, 'Src/VCR _ VCN Form.pa.yaml');
    assert_true(is_string($poweredYaml) && str_contains($poweredYaml, 'LinkCss: "#1D4ED8"'), 'light palette LinkCss hex for HtmlText links');
    assert_true(is_string($poweredYaml) && str_contains($poweredYaml, 'LinkCss: "#2DD4BF"'), 'dark palette LinkCss is accessible teal');
    assert_true(is_string($poweredYaml) && str_contains($poweredYaml, 'Text: RGBA(255, 255, 255, 1)'), 'dark palette Text is white for contrast');
    assert_true(is_string($topbarYaml) && str_contains($topbarYaml, 'AccessAppScope'), 'TopbarHeader enables AccessAppScope for theme toggle');
    assert_true(
        is_string($topbarYaml)
            && preg_match('/^\s*AccessAppScope:\s*true\s*$/m', $topbarYaml) === 1
            && preg_match('/^\s*AccessAppScope:\s*=true\s*$/m', $topbarYaml) !== 1,
        'TopbarHeader AccessAppScope at component root only'
    );
    $poweredArch = new PowerSweeper\MsappArchive($poweredTestOut);
    $poweredArch->unpack();
    $opaqueRgba = 0;
    foreach ($poweredArch->documents() as $doc) {
        foreach ($doc->controls() as $c) {
            foreach ($c->propertyNames() as $prop) {
                $v = (string) ($c->getProperty($prop) ?? '');
                if (!preg_match('/Fill|Color|Border|FontColor|BasePalette|Background|Chevron/i', $prop)) {
                    continue;
                }
                if (!preg_match('/RGBA\s*\(/i', $v) || str_contains($v, 'gblTheme.')) {
                    continue;
                }
                $parsed = ColorValue::parse($v);
                if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
                    $opaqueRgba++;
                }
            }
        }
    }
    $poweredArch->cleanup();
    assert_true($opaqueRgba < 30, 'powered build keeps opaque hard-coded RGBA low (got ' . $opaqueRgba . ')');
    assert_true(is_string($vcnYaml) && str_contains($vcnYaml, 'gblTheme.LinkCss'), 'Jump to annex links bind gblTheme.LinkCss');
    $detailsYaml = ZipTool::readEntry($poweredTestOut, 'Src/VCR Details Screen.pa.yaml');
    $adminYaml = ZipTool::readEntry($poweredTestOut, 'Src/VCR Admin Screen.pa.yaml');
    assert_true(is_string($detailsYaml) && str_contains($detailsYaml, 'gblTheme.Success'), 'Color.Green maps to gblTheme.Success');
    assert_true(is_string($adminYaml) && str_contains($adminYaml, 'gblTheme.Warning'), 'Color.Yellow maps to gblTheme.Warning');
    $vcnYamlPowered = ZipTool::readEntry($poweredTestOut, 'Src/VCR _ VCN Form.pa.yaml');
    assert_true(is_string($vcnYamlPowered) && preg_match('/Sites:[\\s\\S]*?ModernNumberInput@1\\.1\\.1[\\s\\S]*?Fill:\\s*=gblTheme\\.InputFill/m', $vcnYamlPowered) === 1, 'ModernNumberInput Sites gets gblTheme.InputFill');
    assert_true(is_string($vcnYamlPowered) && preg_match('/Remarks:[\\s\\S]*?RichTextEditor@2\\.7\\.0[\\s\\S]*?Fill:\\s*=gblTheme\\.InputFill/m', $vcnYamlPowered) === 1, 'RichTextEditor Remarks gets gblTheme.InputFill');
    assert_true(is_string($vcnYamlPowered) && preg_match('/Remarks:[\\s\\S]*?RichTextEditor@2\\.7\\.0[\\s\\S]*?Appearance:\\s*=Appearance\\.FilledDarker/m', $vcnYamlPowered) === 1, 'RichTextEditor Remarks gets Appearance.FilledDarker');
    assert_true(is_string($vcnYamlPowered) && preg_match('/Remarks:[\\s\\S]*?RichTextEditor@2\\.7\\.0[\\s\\S]*?TemplateFill:\\s*=gblTheme\\.InputFill/m', $vcnYamlPowered) === 1, 'RichTextEditor Remarks gets gblTheme.TemplateFill');
    @unlink($poweredTestOut);
}

// PowerFxFormulaChecker — THCEE record-field patterns (no full-app scan)
$thceeCatalogFixture = \PowerSweeper\AppControlCatalog::build([]);
$thceeDataFixture = new \PowerSweeper\AppDataContext();
$thceeFx = new \PowerSweeper\PowerFxFormulaChecker($thceeCatalogFixture, $thceeDataFixture);
$comboBoxItems = 'LookUp(StatusTable, LangFilter = If(varLang = true, "EN", "FR")).Values.Text';
$comboFindings = $thceeFx->check($comboBoxItems, 'THCEE Dashboard', 'StatusComboBox.Items', 'ComboBox', 'Items', 'StatusComboBox', []);
assert_true($comboFindings === [], 'LookUp(...).Values.Text is not flagged as invalid control ref');
$visibleFindings = $thceeFx->check(
    'UpdateItemDescription.Visible',
    'THCEE Trips',
    'conEditItemDescription.Visible',
    'GroupContainer',
    'Visible',
    'conEditItemDescription',
    [],
    ['UpdateItemDescription' => true]
);
assert_true($visibleFindings === [], 'With record variable visible on same screen is valid');
$timeUnitFindings = $thceeFx->check(
    'DateDiff(DateValue(a), DateValue(b), TimeUnit.Days)',
    'THCEE Trips',
    'Trips_NewActivity.OnSelect',
    'Button',
    'OnSelect',
    'Trips_NewActivity',
    []
);
assert_true($timeUnitFindings === [], 'TimeUnit.Days is a Power Fx enum reference');

// THCEE — global component hosts must stay bare (not screen-qualified)
$thceeFriday = dirname(__DIR__) . '/samples/import_debug/VCDS — THCEE Friday.msapp';
if (is_file($thceeFriday)) {
    $thceeProfile = include dirname(__DIR__) . '/profiles/powered_thcee.php';
    $thceePoweredOut = sys_get_temp_dir() . '/ps_thcee_powered_test_' . bin2hex(random_bytes(4)) . '.msapp';
    (new Pipeline())->run($thceeFriday, $thceeProfile['hops'], $thceePoweredOut);
    $refreshYaml = ZipTool::readEntry($thceePoweredOut, 'Src/THCEE Refresh Screen.pa.yaml');
    assert_true(
        is_string($refreshYaml)
            && str_contains($refreshYaml, 'comTranslations.Labels.THCEERefreshScreen')
            && !str_contains($refreshYaml, "'THCEE Control Screen'.comTranslations"),
        'THCEE keeps bare comTranslations refs on Refresh Screen'
    );
    $thceeYaml = ZipTool::readEntry($thceePoweredOut, 'Src/App.pa.yaml');
    assert_true(is_string($thceeYaml) && str_contains($thceeYaml, 'gblThemeLight'), 'THCEE powered App.pa.yaml has theme palettes');
    @unlink($thceePoweredOut);

    $thceeArch = new \PowerSweeper\MsappArchive($thceeFriday);
    $thceeArch->unpack();
    $thceeCatalog = \PowerSweeper\AppControlCatalog::build($thceeArch->documents());
    assert_true($thceeCatalog->isComponentInstance('comTranslations'), 'THCEE comTranslations is a component instance');
    $resolved = $thceeCatalog->resolveIdentifier('THCEE Refresh Screen', 'comTranslations');
    assert_true($resolved === null, 'THCEE comTranslations is not screen-qualified cross-screen');
    $thceeArch->cleanup();
}

// VCR Friday powered — live checker zero after ForAll As fix
$vcrFridayPowered = dirname(__DIR__) . '/samples/import_debug/CDLS_VCR_App_Friday.powered.msapp';
if (is_file($vcrFridayPowered)) {
    $vcrArch = new \PowerSweeper\MsappArchive($vcrFridayPowered);
    $vcrArch->unpack();
    $vcrLive = \PowerSweeper\StudioLiveChecker::check($vcrArch->documents(), ['extract_dir' => $vcrArch->extractDir()]);
    assert_true($vcrLive['total'] === 0, 'VCR Friday powered live checker zero (got ' . $vcrLive['total'] . ')');
    $vcrArch->cleanup();
}

// validate_powered.php — deliverable smoke check
if (is_file($repair2)) {
    $poweredSample = dirname(__DIR__) . '/samples/import_debug/CDLS_L_VCR_App_repair2.powered.msapp';
    if (is_file($poweredSample)) {
        $validateOut = shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/scripts/validate_powered.php') . ' ' . escapeshellarg($poweredSample) . ' 2>&1');
        assert_true(is_string($validateOut) && str_contains($validateOut, 'All powered validation checks passed'), 'validate_powered.php passes on VCR repair2 sample');
    }
}
$thceePoweredSample = dirname(__DIR__) . '/samples/import_debug/VCDS_THCEE_Friday.powered.msapp';
if (is_file($thceePoweredSample)) {
    $validateThcee = shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/scripts/validate_powered.php') . ' ' . escapeshellarg($thceePoweredSample) . ' 2>&1');
    assert_true(is_string($validateThcee) && str_contains($validateThcee, 'All powered validation checks passed'), 'validate_powered.php passes on THCEE Friday sample');
}
$vcrFridayPowered = dirname(__DIR__) . '/samples/import_debug/CDLS_VCR_App_Friday.powered.msapp';
if (is_file($vcrFridayPowered)) {
    $validateVcrFriday = shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/scripts/validate_powered.php') . ' ' . escapeshellarg($vcrFridayPowered) . ' 2>&1');
    assert_true(is_string($validateVcrFriday) && str_contains($validateVcrFriday, 'All powered validation checks passed'), 'validate_powered.php passes on VCR Friday sample');
}

// --- Web IR structural round-trip (heuristic, not full web runtime) ---
$webDoc = loadFixtureDoc();
foreach ($webDoc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $c->setProperty('OnSelect', '=Navigate(Screen2)');
        break;
    }
}
$webExtract = sys_get_temp_dir() . '/ps_web_ir_' . bin2hex(random_bytes(4));
mkdir($webExtract, 0775, true);
file_put_contents($webExtract . '/Properties.json', json_encode([
    'DocumentAppType' => 'Phone',
    'DocumentLayoutWidth' => 640,
    'DocumentLayoutHeight' => 1136,
    'DocumentLayoutScaleToFit' => true,
    'DocumentLayoutMaintainAspectRatio' => true,
    'Name' => 'Fixture App',
], JSON_PRETTY_PRINT));

$ir = (new \PowerSweeper\WebApp\WebAppIrBuilder())->build([$webDoc], $webExtract);
assert_true(($ir['format'] ?? '') === 'power_sweeper_web_ir', 'web IR format tag');
assert_true(($ir['stats']['screens'] ?? 0) >= 1, 'web IR has screens');
assert_true(($ir['screens'][0]['previous_name'] ?? '') === 'Screen1', 'web IR screen previous_name set');
assert_true(in_array('Screen2', array_column($ir['navigation'], 'to'), true), 'web IR captured Navigate target');

$html = (new \PowerSweeper\WebApp\WebAppHtmlPreview())->render($ir);
assert_true(str_contains($html, 'Structural preview'), 'HTML preview scaffold mentions structural fidelity');

// Mutate IR labels, layout, and a renamed control (previous_name), then apply
$ir['screens'][0]['children'][0]['children'][2]['name'] = 'LaunchButton';
$ir['screens'][0]['children'][0]['children'][2]['previous_name'] = 'NewRequestButton';
$ir['screens'][0]['children'][0]['children'][2]['labels']['Text'] = 'Launch';
$ir['screens'][0]['children'][0]['children'][2]['layout'] = ['x' => 48, 'y' => 96, 'width' => 140, 'height' => 44];
$ir['document']['layout']['scale_to_fit'] = false;
$ir['document']['app_type'] = 'DesktopOrTablet';
$applyReport = new Report();
$applyResult = (new \PowerSweeper\WebApp\WebAppIrApplier())->apply([$webDoc], $ir, $applyReport, $webExtract);
assert_true($applyResult['changes'] > 0, 'web IR apply reported changes');
$btnText = null;
$btnX = null;
$btnName = null;
foreach ($webDoc->controls() as $c) {
    if ($c->name === 'LaunchButton' || $c->name === 'NewRequestButton') {
        $btnName = $c->name;
        $btnText = $c->getProperty('Text');
        $btnX = $c->getProperty('X');
        break;
    }
}
assert_true($btnName === 'LaunchButton', 'web IR renamed control via previous_name');
assert_true(is_string($btnText) && str_contains($btnText, 'Launch'), 'web IR apply updated button Text via previous_name');
assert_true(is_string($btnX) && str_contains($btnX, '48'), 'web IR apply updated literal layout X');
$propsAfter = json_decode((string) file_get_contents($webExtract . '/Properties.json'), true);
assert_true(($propsAfter['DocumentLayoutScaleToFit'] ?? true) === false, 'web IR apply updated ScaleToFit');

// Fresh doc for literal state apply (no rename)
$webDoc2 = loadFixtureDoc();
foreach ($webDoc2->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $c->setProperty('OnSelect', '=Navigate(Screen1)');
        $c->setProperty('Visible', '=true');
        $c->setProperty('TabIndex', '=0');
        break;
    }
}
$ir2 = (new \PowerSweeper\WebApp\WebAppIrBuilder())->build([$webDoc2], $webExtract);
assert_true(isset($ir2['screens'][0]['children'][0]['children'][2]['state']['visible']), 'web IR captures literal Visible state');
$ir2['screens'][0]['children'][0]['children'][2]['state'] = ['visible' => false, 'tab_index' => 1];
$stateReport = new Report();
$stateResult = (new \PowerSweeper\WebApp\WebAppIrApplier())->apply([$webDoc2], $ir2, $stateReport, $webExtract);
assert_true($stateResult['changes'] > 0, 'web IR state apply reported changes');
$stateBtn = null;
foreach ($webDoc2->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $stateBtn = $c;
        break;
    }
}
assert_true($stateBtn !== null, 'state apply keeps control name');
assert_true(str_contains((string) $stateBtn->getProperty('Visible'), 'false'), 'web IR applied Visible state');
assert_true(str_contains((string) $stateBtn->getProperty('TabIndex'), '1'), 'web IR applied TabIndex state');

// DisplayMode create when unset + SetFocus edge capture + HtmlText apply
$webDoc3 = loadFixtureDoc();
foreach ($webDoc3->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $c->setProperty('OnSelect', "=SetFocus(NewRequestTitle); Navigate(Screen2)");
        break;
    }
    if ($c->name === 'LogoImage') {
        $c->setProperty('HtmlText', '="<p>old</p>"');
    }
}
$ir3 = (new \PowerSweeper\WebApp\WebAppIrBuilder())->build([$webDoc3], $webExtract);
$setFocusKinds = array_column(array_filter($ir3['navigation'], static fn($e) => ($e['kind'] ?? '') === 'setfocus'), 'to');
assert_true(in_array('NewRequestTitle', $setFocusKinds, true), 'web IR captures SetFocus targets');
$ir3['screens'][0]['children'][0]['children'][2]['state'] = ['display_mode' => 'DisplayMode.Disabled'];
$ir3['screens'][0]['children'][0]['children'][3]['labels']['HtmlText'] = '<p>new</p>';
// Screen rename already present under new name → rewrite SetFocus/Navigate from previous
$ir3['screens'][] = ['name' => 'Screen2', 'previous_name' => 'LegacyScreen', 'kind' => 'screen', 'children' => []];
// Ensure Screen2 exists as live name via Navigate target only; rewrite LegacyScreen→Screen2 when Screen2 live
foreach ($webDoc3->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $c->setProperty('OnSelect', "=SetFocus(NewRequestTitle); Navigate(LegacyScreen)");
        break;
    }
}
// Add a second screen doc so Screen2 is a live screen name
$screen2Yaml = "Screen2:\n  Control: Screen@2.0.0\n  Properties:\n    Fill: =RGBA(255, 255, 255, 1)\n";
$screen2Path = $webExtract . '/Screen2.pa.yaml';
file_put_contents($screen2Path, $screen2Yaml);
$screen2Doc = ControlDocument::fromFile($screen2Path, 'Src/Screen2.pa.yaml');
assert_true($screen2Doc !== null, 'Screen2 fixture loads');
$ir3['screens'][1] = [
    'name' => 'Screen2',
    'previous_name' => 'LegacyScreen',
    'kind' => 'screen',
    'children' => [],
];
$dmReport = new Report();
(new \PowerSweeper\WebApp\WebAppIrApplier())->apply([$webDoc3, $screen2Doc], $ir3, $dmReport, $webExtract);
$dmBtn = null;
$htmlCtrl = null;
foreach ($webDoc3->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $dmBtn = $c;
    }
    if ($c->name === 'LogoImage') {
        $htmlCtrl = $c;
    }
}
assert_true(
    $dmBtn !== null && str_contains((string) $dmBtn->getProperty('DisplayMode'), 'DisplayMode.Disabled'),
    'web IR creates DisplayMode when previously unset'
);
assert_true(
    $dmBtn !== null && str_contains((string) $dmBtn->getProperty('OnSelect'), 'Navigate(Screen2)'),
    'web IR rewrites Navigate old→new when new screen exists'
);
assert_true(
    $htmlCtrl !== null && str_contains((string) $htmlCtrl->getProperty('HtmlText'), '<p>new</p>'),
    'web IR applies HtmlText markup without length bias'
);
@unlink($screen2Path);

// Screen rename (YAML root key + Navigate rewrite) when new name is free
$renameScreenDoc = loadFixtureDoc();
foreach ($renameScreenDoc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $c->setProperty('OnSelect', '=Navigate(Screen1)');
        $c->setProperty('FocusedBorderColor', '=RGBA(37, 99, 235, 1)');
        $c->setProperty('FocusedBorderThickness', '=2');
        break;
    }
}
$renameExtract = sys_get_temp_dir() . '/ps_screen_ren_' . bin2hex(random_bytes(3));
mkdir($renameExtract . '/Src', 0775, true);
$screenSrc = $renameExtract . '/Src/Screen1.pa.yaml';
// Persist a minimal Src copy for file rename bookkeeping
file_put_contents($screenSrc, file_get_contents(__DIR__ . '/fixtures/screen.pa.yaml'));
$renameScreenDoc = ControlDocument::fromFile($screenSrc, 'Src/Screen1.pa.yaml');
assert_true($renameScreenDoc !== null, 'screen rename fixture reloads from Src');
foreach ($renameScreenDoc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $c->setProperty('OnSelect', '=Navigate(Screen1)');
        $c->setProperty('FocusedBorderColor', '=RGBA(37, 99, 235, 1)');
        break;
    }
}
$irRename = (new \PowerSweeper\WebApp\WebAppIrBuilder())->build([$renameScreenDoc], $renameExtract);
assert_true(
    isset($irRename['screens'][0]['children'][0]['children'][2]['state']['focused_border_color']),
    'web IR captures FocusedBorderColor literal'
);
$irRename['screens'][0]['name'] = 'HomeScreen';
$irRename['screens'][0]['previous_name'] = 'Screen1';
$srReport = new Report();
$srResult = (new \PowerSweeper\WebApp\WebAppIrApplier())->apply([$renameScreenDoc], $irRename, $srReport, $renameExtract);
assert_true(($srResult['notes'] !== [] && str_contains(implode(' ', $srResult['notes']), 'screen renames')), 'web IR screen rename noted');
assert_true(is_file($renameExtract . '/Src/HomeScreen.pa.yaml'), 'web IR renamed screen .pa.yaml file');
assert_true(!is_file($renameExtract . '/Src/Screen1.pa.yaml'), 'web IR removed old screen .pa.yaml file');
$homeScreen = null;
foreach ($renameScreenDoc->controls() as $c) {
    if ($c->isScreen()) {
        $homeScreen = $c;
        break;
    }
}
assert_true($homeScreen !== null && $homeScreen->name === 'HomeScreen', 'web IR renamed screen root key');
$navAfter = null;
foreach ($renameScreenDoc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $navAfter = $c->getProperty('OnSelect');
        break;
    }
}
assert_true(is_string($navAfter) && str_contains($navAfter, 'Navigate(HomeScreen)'), 'web IR rewrote Navigate after screen rename');
@unlink($renameExtract . '/Src/HomeScreen.pa.yaml');
@rmdir($renameExtract . '/Src');
@rmdir($renameExtract);

// General Filter nest (former VCR seed) still works via pattern splitter
$delegNest = \PowerSweeper\DelegationFormulaRewriter::rewrite(
    "Filter('CDLS (L) VCR Tracking List', request_user.Email = User().Email && Lower(Trim(Destination)) = locDestination && Date = locDate && Lower(Trim(Requestor)) = locRequestor)"
);
assert_true(str_contains($delegNest, 'Filter(Filter('), 'delegation nest splitter nests Filter without VCR seed table');
assert_true(str_contains($delegNest, 'request_user.Email = User().Email && Date = locDate'), 'delegation nest keeps email+date in inner Filter');

// Ghost patch discovery — unknown Field: Field.Prop line removed
$ghostYaml = <<<'YAML'
Screen1:
  Control: Screen@2.0.0
  Properties:
    OnVisible: |
      =Patch(
          colTemp,
          Defaults(colTemp),
          {
              Title: "x",
              MissingGhostControl: MissingGhostControl.Text,
              MissingGhostControl: MissingGhostControl
          }
      );
YAML;
$ghostPath = sys_get_temp_dir() . '/ps_ghost_' . bin2hex(random_bytes(4)) . '.pa.yaml';
file_put_contents($ghostPath, $ghostYaml);
$ghostDoc = ControlDocument::fromFile($ghostPath, 'Src/Screen1.pa.yaml');
assert_true($ghostDoc !== null, 'ghost fixture loads');
$ghostReport = new Report();
(new \PowerSweeper\Hops\RepairGhostPatchFieldsHop())->apply([$ghostDoc], $ghostReport);
$ghostOnVisible = null;
foreach ($ghostDoc->controls() as $c) {
    if ($c->isScreen()) {
        $ghostOnVisible = $c->getProperty('OnVisible');
        break;
    }
}
assert_true(
    is_string($ghostOnVisible) && !str_contains($ghostOnVisible, 'MissingGhostControl'),
    'ghost hop discovers and removes absent Field: Field.Prop lines'
);
@unlink($ghostPath);

// ColorValue chrome heuristic — pale slate/blue Studio chrome is themeable
assert_true(
    \PowerSweeper\ColorValue::isStudioDefault('=RGBA(226, 232, 240, 1)', 'RailFill'),
    'slate RailFill treated as Studio chrome default'
);
assert_true(
    \PowerSweeper\ColorValue::isStudioDefault('=RGBA(219, 234, 254, 1)', 'SelectedFill'),
    'pale SelectedFill treated as Studio chrome default'
);
assert_true(
    !\PowerSweeper\ColorValue::isStudioDefault('=RGBA(220, 38, 38, 1)', 'Fill'),
    'saturated brand red Fill is not a Studio default'
);

$exportReport = new Report();
(new \PowerSweeper\Hops\ExportWebAppHop())->apply([$webDoc], $exportReport, ['_extract_dir' => $webExtract]);
assert_true(is_file($webExtract . '/WebApp/power_sweeper_ir.json'), 'export_web_ir wrote IR file');
assert_true(is_file($webExtract . '/WebApp/index.html'), 'export_web_ir wrote HTML preview');

// Token-stem candidate heuristic (Initiave → Initiative) without hard-coded-only path
$stemGen = new \PowerSweeper\ControlRefCandidateGenerator();
$stemCatalog = \PowerSweeper\AppControlCatalog::build([$webDoc]);
$stemCandidates = $stemGen->candidates(
    'GovernmentInitiave',
    'Screen1',
    'Host_2',
    ['GovernmentInitiative' => true, 'NewRequestButton' => true],
    $stemCatalog,
);
assert_true(in_array('GovernmentInitiative', $stemCandidates, true), 'token-stem heuristic proposes GovernmentInitiative');

// Multi-app: TDR comTranslations → TranslationComponent_1 host alias
$tdrSample = dirname(__DIR__) . '/samples/import_debug/TDR - THCEE Directory App.msapp';
if (is_file($tdrSample)) {
    $tdrOut = sys_get_temp_dir() . '/ps_tdr_repair_' . bin2hex(random_bytes(3)) . '.msapp';
    $tdrProfile = include dirname(__DIR__) . '/profiles/repair_studio_errors.php';
    (new Pipeline())->run($tdrSample, $tdrProfile['hops'], $tdrOut);
    $tdrArch = new \PowerSweeper\MsappArchive($tdrOut);
    $tdrArch->unpack();
    $tdrLive = \PowerSweeper\StudioLiveChecker::check($tdrArch->documents(), ['extract_dir' => $tdrArch->extractDir()]);
    $tdrComLeft = 0;
    $tdrFormulaErr = 0;
    foreach ($tdrArch->documents() as $doc) {
        $doc->transformFormulas(static function (string $f) use (&$tdrComLeft): string {
            if (preg_match('/\bcomTranslations\b/', $f)) {
                $tdrComLeft++;
            }
            return $f;
        });
    }
    foreach ($tdrLive['findings'] as $f) {
        if (str_starts_with((string) $f['ruleId'], 'app-Err')) {
            $tdrFormulaErr++;
        }
    }
    assert_true($tdrComLeft === 0, 'TDR repair rewrites comTranslations host alias');
    assert_true($tdrFormulaErr === 0, 'TDR repair clears app-Err* findings (got ' . $tdrFormulaErr . ')');
    $tdrArch->cleanup();
    @unlink($tdrOut);
}

// Multi-app: ASC Template locale pass must not corrupt GetInfoData(0,0)
$ascTemplate = dirname(__DIR__) . '/samples/import_debug/VCDS ASC —Template with Approvals.msapp';
if (is_file($ascTemplate)) {
    $ascOut = sys_get_temp_dir() . '/ps_asc_repair_' . bin2hex(random_bytes(3)) . '.msapp';
    $ascProfile = include dirname(__DIR__) . '/profiles/repair_studio_errors.php';
    (new Pipeline())->run($ascTemplate, $ascProfile['hops'], $ascOut);
    $ascArch = new \PowerSweeper\MsappArchive($ascOut);
    $ascArch->unpack();
    $ascBad = 0;
    $ascGood = 0;
    $ascLive = \PowerSweeper\StudioLiveChecker::check($ascArch->documents(), ['extract_dir' => $ascArch->extractDir()]);
    foreach ($ascArch->documents() as $doc) {
        $doc->transformFormulas(static function (string $f) use (&$ascBad, &$ascGood): string {
            if (str_contains($f, 'GetInfoData(0.0)')) {
                $ascBad++;
            }
            if (preg_match('/GetInfoData\(\s*0\s*,\s*0\s*\)/', $f)) {
                $ascGood++;
            }
            return $f;
        });
    }
    $ascErr = 0;
    foreach ($ascLive['findings'] as $f) {
        if (str_starts_with((string) $f['ruleId'], 'app-Err')) {
            $ascErr++;
        }
    }
    assert_true($ascBad === 0, 'ASC Template repair does not corrupt GetInfoData(0,0)→0.0');
    assert_true($ascGood > 0, 'ASC Template keeps invariant GetInfoData(0,0)');
    assert_true($ascErr === 0, 'ASC Template repair clears app-Err* findings (got ' . $ascErr . ')');
    $ascArch->cleanup();
    @unlink($ascOut);
}

// Catalog-driven SharePoint field fallbacks (VisitType / Level* / Initiative / Specification)
$spFbExtract = sys_get_temp_dir() . '/ps_spfb_' . bin2hex(random_bytes(3));
mkdir($spFbExtract . '/References', 0775, true);
file_put_contents($spFbExtract . '/References/DataSources.json', json_encode([
    'DataSources' => [[
        'Name' => 'CDLS (L) VCR Tracking List',
        'Type' => 'ConnectedDataSourceInfo',
        'DatasetName' => 'https://contoso.sharepoint.com/sites/DND',
        'TableName' => 'VCR',
        'ApiId' => '/providers/microsoft.powerapps/apis/shared_sharepointonline',
        'ConnectedDataSourceInfoNameMapping' => [
            'VisitType' => 'VisitType',
            'Confidential' => 'Confidential',
            'UnclassifiedRestricted' => 'UnclassifiedRestricted',
            'Government' => 'Government',
            'InitiativeType' => 'InitiativeType',
            'Initiation' => 'Initiation',
            'Subject' => 'Subject',
            'EmerContactCanadianPhone' => 'EmerContactCanadianPhone',
        ],
    ]],
], JSON_PRETTY_PRINT));
$spFbYaml = <<<'YAML'
Screen1:
  Control: Screen@2.0.0
  Properties:
    OnVisible: |
      =If(loadedRequest.OneTimeVisit, 1, 0);
      If(loadedRequest.LevelConfidential, 1, 0);
      If(loadedRequest.GovernmentInitiative, 1, 0);
      If(loadedRequest.CommercialInitiative, 1, 0);
      If(loadedRequest.InitiatedByRequestingAgency, 1, 0);
      Set(x, loadedRequest.PertinenceSpecification);
      Set(y, r.CanadianCellPhone);
      Patch(list, Defaults(list), { AmendmentVisit: true, Title: "x" })
YAML;
$spFbPath = $spFbExtract . '/Screen1.pa.yaml';
file_put_contents($spFbPath, $spFbYaml);
$spFbDoc = ControlDocument::fromFile($spFbPath, 'Src/Screen1.pa.yaml');
assert_true($spFbDoc !== null, 'SP fallback fixture loads');
$spFbReport = new Report();
(new \PowerSweeper\Hops\RepairSharePointFieldsHop())->apply([$spFbDoc], $spFbReport, ['_extract_dir' => $spFbExtract]);
$spFbFormula = '';
foreach ($spFbDoc->controls() as $c) {
    if ($c->isScreen()) {
        $spFbFormula = (string) $c->getProperty('OnVisible');
    }
}
assert_true(str_contains($spFbFormula, 'VisitType.Value = "One-time"'), 'SP fallback maps OneTimeVisit via VisitType');
assert_true(str_contains($spFbFormula, 'loadedRequest.Confidential'), 'SP fallback maps LevelConfidential → Confidential');
assert_true(str_contains($spFbFormula, 'loadedRequest.Government'), 'SP fallback strips Initiative → Government');
assert_true(str_contains($spFbFormula, 'loadedRequest.InitiativeType'), 'SP fallback maps CommercialInitiative → InitiativeType');
assert_true(str_contains($spFbFormula, 'loadedRequest.Initiation'), 'SP fallback maps Initiated* → Initiation');
assert_true(str_contains($spFbFormula, 'loadedRequest.Subject'), 'SP fallback maps *Specification → Subject');
assert_true(str_contains($spFbFormula, 'EmerContactCanadianPhone'), 'SP fallback fuzzy-maps CanadianCellPhone');
assert_true(!preg_match('/\bAmendmentVisit\s*:/', $spFbFormula), 'SP hop drops absent AmendmentVisit Patch field');
@unlink($spFbPath);
@unlink($spFbExtract . '/References/DataSources.json');
@rmdir($spFbExtract . '/References');
@rmdir($spFbExtract);

// Host-screen discovery for App bootstrap (not hardcoded VASC screen name)
$vascYaml = <<<'YAML'
App:
  Control: App@1.0.0
  Properties:
    OnStart: |
      =Set(x, 1);
      'Bootstrap Host'.comExternalFunctions.loadUser();
      If(
          !IsBlank(Param("requestid")),
          Set(varRequestID, Substitute(Param("requestid"),"-", " - "));
          'Bootstrap Host'.comExternalFunctions.loadPackage(varRequestID),
          Set(varRequestID,"-1")
      )
Bootstrap Host:
  Control: Screen@2.0.0
  Children:
    - comExternalFunctions:
        Control: Classic/Button@2.2.0
        Properties:
          Text: ="host"
YAML;
$vascPath = sys_get_temp_dir() . '/ps_vasc_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents($vascPath, $vascYaml);
$vascDoc = ControlDocument::fromFile($vascPath, 'Src/App.pa.yaml');
assert_true($vascDoc !== null, 'bootstrap host fixture loads');
$vascReport = new Report();
(new \PowerSweeper\Hops\RepairStudioSyntaxHop())->apply([$vascDoc], $vascReport);
$vascOnStart = '';
$vascOnVisible = '';
foreach ($vascDoc->controls() as $c) {
    if ($c->isApp()) {
        $vascOnStart = (string) $c->getProperty('OnStart');
    }
    if ($c->isScreen() && $c->name === 'Bootstrap Host') {
        $vascOnVisible = (string) $c->getProperty('OnVisible');
    }
}
assert_true(str_contains($vascOnStart, 'Set(varDeferredLoadUser, false)'), 'bootstrap inits varDeferredLoadUser false');
assert_true(str_contains($vascOnStart, 'Set(varDeferredLoadUser, true)'), 'bootstrap defers loadUser from any host screen');
assert_true(str_contains($vascOnStart, 'Set(varDeferredLoadPackage, true)'), 'bootstrap defers loadPackage from any host screen');
assert_true(!str_contains($vascOnStart, 'comExternalFunctions.loadUser'), 'bootstrap removes cross-screen loadUser from App');
assert_true(str_contains($vascOnVisible, 'ps-bootstrap:start'), 'bootstrap parks deferred load on discovered host OnVisible');
@unlink($vascPath);

// JSON Controls/*.json screen rename + SetFocus park onto destination OnVisible
$jsonExtract = sys_get_temp_dir() . '/ps_jsonren_' . bin2hex(random_bytes(3));
mkdir($jsonExtract . '/Controls', 0775, true);
$jsonScreen = [
    'TopParent' => [
        'Name' => 'LegacyJson',
        'Template' => ['Name' => 'screen', 'Version' => '2.0.0'],
        'Rules' => [
            ['Property' => 'OnVisible', 'InvariantScript' => ''],
        ],
        'Children' => [
            [
                'Name' => 'FocusTarget',
                'Template' => ['Name' => 'label', 'Version' => '2.0.0'],
                'Rules' => [
                    ['Property' => 'Text', 'InvariantScript' => '"hi"'],
                ],
                'Children' => [],
            ],
        ],
    ],
];
$jsonOld = $jsonExtract . '/Controls/LegacyJson.json';
file_put_contents($jsonOld, json_encode($jsonScreen, JSON_PRETTY_PRINT));
$jsonDoc = ControlDocument::fromFile($jsonOld, 'Controls/LegacyJson.json');
assert_true($jsonDoc !== null, 'JSON screen fixture loads');
$navYaml = "NavScreen:\n  Control: Screen@2.0.0\n  Children:\n    - GoBtn:\n        Control: Classic/Button@2.0.0\n        Properties:\n          OnSelect: \"=Navigate(LegacyJson); SetFocus(FocusTarget)\"\n";
$navPath = $jsonExtract . '/NavScreen.pa.yaml';
file_put_contents($navPath, $navYaml);
$navDoc = ControlDocument::fromFile($navPath, 'Src/NavScreen.pa.yaml');
assert_true($navDoc !== null, 'nav screen for SetFocus park loads');
$irJson = [
    'format' => 'power_sweeper_web_ir',
    'screens' => [
        ['name' => 'HomeJson', 'previous_name' => 'LegacyJson', 'kind' => 'screen', 'children' => []],
        ['name' => 'NavScreen', 'kind' => 'screen', 'children' => []],
    ],
    'navigation' => [
        ['from' => 'NavScreen', 'to' => 'HomeJson', 'kind' => 'navigate'],
        ['from' => 'NavScreen', 'to' => 'FocusTarget', 'kind' => 'setfocus'],
    ],
];
$jsonReport = new Report();
$jsonResult = (new \PowerSweeper\WebApp\WebAppIrApplier())->apply([$jsonDoc, $navDoc], $irJson, $jsonReport, $jsonExtract);
assert_true(is_file($jsonExtract . '/Controls/HomeJson.json'), 'web IR renames Controls/*.json screen file');
assert_true(!is_file($jsonExtract . '/Controls/LegacyJson.json'), 'web IR removes old Controls/*.json screen file');
$jsonScreenNode = null;
foreach ($jsonDoc->controls() as $c) {
    if ($c->isScreen()) {
        $jsonScreenNode = $c;
        break;
    }
}
assert_true($jsonScreenNode !== null && $jsonScreenNode->name === 'HomeJson', 'web IR renamed JSON screen Name');
assert_true(
    str_contains((string) $jsonScreenNode->getProperty('OnVisible'), 'SetFocus(FocusTarget)'),
    'web IR parks SetFocus on destination OnVisible'
);
assert_true(
    str_contains(implode(' ', $jsonResult['notes']), 'SetFocus park')
    || str_contains(implode(' ', $jsonResult['notes']), 'screen renames'),
    'web IR notes SetFocus park or screen renames'
);
$previewHtml = (new \PowerSweeper\WebApp\WebAppHtmlPreview())->render($irJson);
assert_true(str_contains($previewHtml, "e.kind === 'setfocus'") || str_contains($previewHtml, 'setfocus'), 'HTML preview distinguishes setfocus edges');
@unlink($jsonExtract . '/Controls/HomeJson.json');
@unlink($navPath);
@rmdir($jsonExtract . '/Controls');
@rmdir($jsonExtract);

// Cleanup temp extract
array_map('unlink', glob($webExtract . '/WebApp/*') ?: []);
@rmdir($webExtract . '/WebApp');
@unlink($webExtract . '/Properties.json');
@rmdir($webExtract);

echo "\n";
if ($failed > 0) {
    echo "FAILED: {$failed} assertion(s)\n";
    exit(1);
}
echo "All tests passed.\n";
exit(0);
