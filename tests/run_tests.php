<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\ControlDocument;
use PowerSweeper\Hops\AccessibilityLabelsHop;
use PowerSweeper\Hops\AlignNearMissHop;
use PowerSweeper\Hops\NormalizeClassicButtonChromeHop;
use PowerSweeper\Hops\NormalizeContainersHop;
use PowerSweeper\Hops\StripDefaultFillHop;
use PowerSweeper\Hops\TooltipFromLabelHop;
use PowerSweeper\MsappArchive;
use PowerSweeper\Pipeline;
use PowerSweeper\Report;

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

// --- integration: zip fixture -> pipeline -> zip ---
$fixtureYaml = file_get_contents(__DIR__ . '/fixtures/screen.pa.yaml');
$tmpDir = sys_get_temp_dir() . '/ps_int_' . bin2hex(random_bytes(4));
mkdir($tmpDir . '/Src', 0777, true);
file_put_contents($tmpDir . '/Src/Screen1.pa.yaml', $fixtureYaml);
$inMsapp = $tmpDir . '/in.msapp';
$outMsapp = $tmpDir . '/out.msapp';
$zip = new ZipArchive();
$zip->open($inMsapp, ZipArchive::CREATE);
$zip->addFile($tmpDir . '/Src/Screen1.pa.yaml', 'Src/Screen1.pa.yaml');
$zip->close();

$result = (new Pipeline())->run($inMsapp, [
    ['id' => 'normalize_containers'],
    ['id' => 'align_near_miss', 'options' => ['tolerance' => 3]],
    ['id' => 'accessibility_labels'],
    ['id' => 'normalize_classic_button_chrome'],
], $outMsapp);

assert_true(is_file($outMsapp), 'output msapp created');
assert_true(($result['report']['total'] ?? 0) > 0, 'integration report has changes');

$verify = new ZipArchive();
assert_true($verify->open($outMsapp) === true, 'output opens as zip');
$yamlOut = $verify->getFromName('Src/Screen1.pa.yaml');
$verify->close();
assert_true(is_string($yamlOut) && str_contains($yamlOut, 'DropShadow.None'), 'packed YAML contains normalized DropShadow');

// cleanup tmp
@unlink($inMsapp);
@unlink($outMsapp);
@unlink($tmpDir . '/Src/Screen1.pa.yaml');
@rmdir($tmpDir . '/Src');
@rmdir($tmpDir);

echo "\n";
if ($failed > 0) {
    echo "FAILED: {$failed} assertion(s)\n";
    exit(1);
}
echo "All tests passed.\n";
exit(0);
