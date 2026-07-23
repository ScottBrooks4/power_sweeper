<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\FormulaIdentifierRewriter;
use PowerSweeper\FormulaLocaleNormalizer;
use PowerSweeper\Hops\AccessibilityLabelsHop;
use PowerSweeper\Hops\AlignNearMissHop;
use PowerSweeper\Hops\CorrelateSharePointHop;
use PowerSweeper\Hops\EnableDarkModeHop;
use PowerSweeper\Hops\NormalizeClassicButtonChromeHop;
use PowerSweeper\Hops\NormalizeContainersHop;
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
$screenFillThemed = false;
$titleColorThemed = false;
foreach ($doc->controls() as $c) {
    if ($c->name === 'App') {
        $onStartOk = str_contains((string) $c->getProperty('OnStart'), 'gblDarkMode');
    }
    if ($c->name === 'tglPowerSweeperDarkMode') {
        $hasToggle = true;
    }
    if ($c->name === 'Screen1') {
        $screenFillThemed = str_contains((string) $c->getProperty('Fill'), 'gblDarkMode');
    }
    if ($c->name === 'Title') {
        $titleColorThemed = str_contains((string) $c->getProperty('Color'), 'gblDarkMode');
    }
}
assert_true($onStartOk, 'App.OnStart initializes gblDarkMode');
assert_true($hasToggle, 'dark mode toggle injected');
assert_true($screenFillThemed, 'screen Fill wrapped for dark mode');
assert_true($titleColorThemed, 'label Color wrapped for dark mode');

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
