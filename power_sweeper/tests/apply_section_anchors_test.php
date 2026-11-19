<?php

declare(strict_types=1);

use PowerSweeper\ControlDocument;
use PowerSweeper\Report;
use PowerSweeper\SweepChains;
use PowerSweeper\SweepRegistry;
use PowerSweeper\Sweeps\ApplySectionAnchorsSweep;
use PowerSweeper\WebApp\WebAppIrBuilder;
use PowerSweeper\WebApp\WebAppPixelRenderer;

require_once dirname(__DIR__) . '/bootstrap.php';

if (!function_exists('assert_true')) {
    function assert_true(bool $cond, string $msg): void
    {
        if (!$cond) {
            fwrite(STDERR, "FAIL: {$msg}\n");
            exit(1);
        }
        echo "ok - {$msg}\n";
    }
}

assert_true((new SweepRegistry())->has('apply_section_anchors'), 'apply_section_anchors is registered');
assert_true(
    array_column(SweepChains::applySectionAnchors(), 'id') === ['apply_section_anchors'],
    'SweepChains::applySectionAnchors exposes the chain'
);

$screen = ControlDocument::fromFile(__DIR__ . '/fixtures/pixel_chrome.pa.yaml', 'Src/Chrome.pa.yaml');
$app = ControlDocument::fromFile(__DIR__ . '/fixtures/pixel_chrome_app.pa.yaml', 'Src/App.pa.yaml');
assert_true($screen !== null && $app !== null, 'fixtures load');

$report = new Report();
(new ApplySectionAnchorsSweep())->apply([$screen, $app], $report, []);
$changes = $report->entries();
assert_true($changes !== [], 'section anchors sweep reports changes');

$tmpYaml = sys_get_temp_dir() . '/ps_anchors_' . bin2hex(random_bytes(3)) . '.pa.yaml';
$screen->save($tmpYaml);
$reloaded = ControlDocument::fromFile($tmpYaml, 'Src/Chrome.pa.yaml');
assert_true($reloaded !== null, 'mutated screen reloads');

$byName = [];
foreach ($reloaded->controls() as $c) {
    if (str_starts_with($c->name, 'Anchor')) {
        $byName[$c->name] = $c;
    }
}
assert_true(isset($byName['Anchor1']), 'sweep inserted Anchor1 for 1_Destination');
assert_true(isset($byName['Anchor5']), 'numbered 5_Summary_Contents maps to Anchor5');
assert_true(isset($byName['AnchorPark']), 'sweep inserted AnchorPark reset target');
assert_true(!isset($byName['Anchor2']) || !str_contains($byName['Anchor2']->path, '2_Checkboxes'), 'checkbox chrome rows are not anchored');

$y = (string) ($byName['Anchor1']->getProperty('Y') ?? '');
assert_true(str_contains($y, 'ScrollableForm') && str_contains($y, 'Height'), 'section anchor Y is mid-viewport Height/2');
$parkY = ltrim((string) ($byName['AnchorPark']->getProperty('Y') ?? ''), '=');
assert_true($parkY === '15' || str_contains((string) ($byName['AnchorPark']->getProperty('Y') ?? ''), '15'), 'AnchorPark stays near the top of its pad');
$w = ltrim((string) ($byName['Anchor1']->getProperty('Width') ?? ''), '=');
assert_true($w === '1', 'anchor width is 1');

// Second pass is idempotent for names (may still tune Y/chrome).
$report2 = new Report();
(new ApplySectionAnchorsSweep())->apply([$reloaded, $app], $report2, []);
$duplicateInserts = array_values(array_filter(
    $report2->entries(),
    static fn(array $e): bool => ($e['from'] ?? '') === '(missing)' && str_contains((string) ($e['to'] ?? ''), 'Classic/Button')
));
assert_true($duplicateInserts === [], 'second apply does not insert duplicate anchors');
$reloaded->reindex();

$extract = sys_get_temp_dir() . '/ps_anchors_' . bin2hex(random_bytes(3));
mkdir($extract, 0775, true);
file_put_contents($extract . '/Properties.json', json_encode([
    'DocumentAppType' => 'DesktopOrTablet',
    'DocumentLayoutWidth' => 1366,
    'DocumentLayoutHeight' => 768,
    'Name' => 'Anchor Demo',
], JSON_PRETTY_PRINT));

$ir = (new WebAppIrBuilder())->build([$reloaded, $app], $extract, [
    'runtime' => 'php',
    'storage' => 'sql',
    'layout' => 'pixel',
]);
$html = (new WebAppPixelRenderer())->renderIntermediate($ir);
assert_true((bool) preg_match('/<a[^>]+id="Anchor1"[^>]*data-role="section-anchor"/', $html), 'pixel renderer emits top-of-section <a id="Anchor1">');
assert_true((bool) preg_match('/<a[^>]+id="AnchorPark"/', $html), 'pixel renderer emits <a id="AnchorPark">');
assert_true(str_contains($html, 'data-role="section-anchor"'), 'section anchors use data-role=section-anchor');

// Misplaced Anchor2 under classification must relocate to Organization section.
$misplaced = ControlDocument::fromFile(__DIR__ . '/fixtures/pixel_chrome.pa.yaml', 'Src/Chrome.pa.yaml');
assert_true($misplaced !== null, 'misplaced fixture reloads');
$scroll = null;
foreach ($misplaced->controls() as $c) {
    if ($c->name === 'ScrollableForm') {
        $scroll = $c;
        break;
    }
}
assert_true($scroll !== null, 'ScrollableForm present for relocate test');
$scroll->addYamlChild('2_Requesting', [
    'Control' => 'GroupContainer@1.5.0',
    'Variant' => 'AutoLayout',
    'Properties' => [
        'LayoutDirection' => '=LayoutDirection.Vertical',
        'Height' => '=80',
        'Width' => '=Parent.Width',
    ],
]);
$scroll->addYamlChild('4_SecurityClassification', [
    'Control' => 'GroupContainer@1.5.0',
    'Variant' => 'AutoLayout',
    'Properties' => [
        'LayoutDirection' => '=LayoutDirection.Vertical',
        'Height' => '=80',
        'Width' => '=Parent.Width',
    ],
    'Children' => [
        [
            'Anchor2' => [
                'Control' => 'Classic/Button@2.2.0',
                'Properties' => [
                    'X' => '=40',
                    'Y' => '=40',
                    'Width' => '=1',
                    'Height' => '=1',
                    'Text' => '="Anchor2"',
                ],
            ],
        ],
    ],
]);
$misplaced->reindex();
$report3 = new Report();
(new ApplySectionAnchorsSweep())->apply([$misplaced, $app], $report3, ['relocate' => true]);
$misplaced->reindex();
$anchor2 = null;
foreach ($misplaced->controls() as $c) {
    if ($c->name === 'Anchor2') {
        $anchor2 = $c;
        break;
    }
}
assert_true($anchor2 !== null, 'relocated Anchor2 still exists');
assert_true(str_contains($anchor2->path, '2_Requesting'), 'Anchor2 relocated into Organization (2_Requesting)');
assert_true(!str_contains($anchor2->path, '4_SecurityClassification'), 'Anchor2 no longer under Classification');

$chain = include dirname(__DIR__) . '/config/sweep_chains/apply_section_anchors.php';
assert_true(is_array($chain) && ($chain[0]['id'] ?? '') === 'apply_section_anchors', 'CLI chain apply_section_anchors exists');
$webToPower = include dirname(__DIR__) . '/config/sweep_chains/web_to_power.php';
assert_true(
    in_array('apply_section_anchors', array_column($webToPower, 'id'), true),
    'web_to_power chain includes apply_section_anchors for reverse mid-pane placement'
);

echo "apply_section_anchors_test: all checks passed\n";
