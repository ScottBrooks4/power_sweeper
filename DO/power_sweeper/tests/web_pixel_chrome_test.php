<?php

declare(strict_types=1);

use PowerSweeper\ControlDocument;
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

$screen = ControlDocument::fromFile(__DIR__ . '/fixtures/pixel_chrome.pa.yaml', 'Src/Chrome.pa.yaml');
$app = ControlDocument::fromFile(__DIR__ . '/fixtures/pixel_chrome_app.pa.yaml', 'Src/App.pa.yaml');
assert_true($screen !== null && $app !== null, 'chrome fixtures load');

$extract = sys_get_temp_dir() . '/ps_chrome_' . bin2hex(random_bytes(3));
mkdir($extract, 0775, true);
file_put_contents($extract . '/Properties.json', json_encode([
    'DocumentAppType' => 'DesktopOrTablet',
    'DocumentLayoutWidth' => 1366,
    'DocumentLayoutHeight' => 768,
    'Name' => 'Chrome Demo',
], JSON_PRETTY_PRINT));

$ir = (new WebAppIrBuilder())->build([$screen, $app], $extract, [
    'runtime' => 'php',
    'storage' => 'sql',
    'layout' => 'pixel',
]);
assert_true(($ir['collections']['colFormSectionsWithoutEmergency'][0]['Label'] ?? '') === 'Destination', 'tab collection harvested');

$html = (new WebAppPixelRenderer())->renderIntermediate($ir);
assert_true(str_contains($html, 'Visit Clearance Request / Notification'), 'gold banner title is painted');
assert_true(str_contains($html, '1. TO:'), 'destination label is painted');
assert_true(str_contains($html, '(Country / international organisation name)'), 'destination legend is painted');
assert_true(str_contains($html, '2. REQUESTING GOVERNMENT AGENCY'), 'requesting header is painted');
assert_true(str_contains($html, 'Military') && str_contains($html, 'Government'), 'requesting checkboxes are painted');
assert_true(str_contains($html, 'ctrl-check-box'), 'checkbox squares use a visible box element');
assert_true(str_contains($html, 'Destination') && str_contains($html, 'Organization'), 'form tabs are painted');
assert_true(str_contains($html, 'Submit') && !str_contains($html, 'ctrl--ghost">Submit'), 'submit button is painted');
assert_true(preg_match('/data-control="TopSubmitButton"[^>]*ctrl--ghost/', $html) !== 1
    && preg_match('/data-control="topSubmit_1"[^>]*>[\s\S]{0,400}Submit/', $html) === 1, 'submit is a visible primary button');
assert_true(
    str_contains($html, 'background:#1f2937')
    || str_contains($html, 'background:rgba(31,41,55')
    || str_contains($html, '31, 41, 55'),
    'submit uses charcoal primary chrome'
);
assert_true(str_contains($html, 'data-screen="ChromeScreen"') && str_contains($html, 'screen--active'), 'chrome screen is the start screen');
assert_true(
    str_contains($html, 'ca8a04') || str_contains($html, '202,138,4') || str_contains($html, 'eab308')
    || str_contains($html, '234,179,8') || str_contains($html, '--theme-warning'),
    'warning gold is in the page'
);

$resolved = (new \PowerSweeper\WebApp\WebAppLayoutResolver())->resolve($ir);
$chrome = null;
foreach ($resolved['screens'] as $s) {
    if (($s['name'] ?? '') === 'ChromeScreen') {
        $chrome = $s;
        break;
    }
}
function chrome_find(array $node, string $want): ?array
{
    if (($node['name'] ?? '') === $want) {
        return $node;
    }
    foreach ($node['children'] ?? [] as $c) {
        if (is_array($c)) {
            $hit = chrome_find($c, $want);
            if ($hit !== null) {
                return $hit;
            }
        }
    }
    return null;
}
$military = $chrome ? chrome_find($chrome, 'RequestingAgencyMilitary') : null;
$gov = $chrome ? chrome_find($chrome, 'RequestingAgencyGovernment') : null;
$destCont = $chrome ? chrome_find($chrome, 'Destination_Container') : null;
assert_true($military !== null && (int) ($military['layout']['width'] ?? 0) >= 80, 'FillPortions gives Military a share of the row');
assert_true(
    $military !== null && $gov !== null
    && abs((int) ($military['layout']['width'] ?? 0) - (int) ($gov['layout']['width'] ?? 0)) <= 2,
    'equal FillPortions produce equal checkbox column widths'
);
assert_true($destCont !== null && (int) ($destCont['layout']['height'] ?? 0) >= 32, 'destination row keeps a usable height');

$sites = $chrome ? chrome_find($chrome, 'Sites') : null;
$sitesRow = $chrome ? chrome_find($chrome, 'SitesContainer') : null;
assert_true($sites !== null && (int) ($sites['layout']['height'] ?? 0) >= 32, 'sites number input has a usable height');
assert_true($sitesRow !== null && (int) ($sitesRow['layout']['height'] ?? 0) >= 32, 'sites summary row has a usable height');
assert_true(preg_match('/data-control="Sites"[^>]*value="1"/', $html) === 1, 'sites count defaults to 1');
assert_true(str_contains($html, '(above general or deputy minister)'), 'VIP helper text is painted');
assert_true(str_contains($html, 'Jump to Sites') && str_contains($html, 'Jump to Visitors'), 'summary jump links are painted');
assert_true(str_contains($html, 'data-scroll-to="AnchorSites"') || str_contains($html, 'data-scroll-to="AnchorVisitors"'), 'jump links target SetFocus-compatible anchors');
assert_true(str_contains((string) file_get_contents(dirname(__DIR__) . '/templates/web_package/assets/app.css'), '[data-control="5_Summary"]'), 'summary section border rule ships in package CSS');

$runtimeJs = (string) file_get_contents(dirname(__DIR__) . '/templates/web_package/assets/runtime.js');
assert_true(str_contains($runtimeJs, 'data-navigate'), 'runtime handles sidebar data-navigate');
assert_true(str_contains($runtimeJs, 'data-scroll-to'), 'runtime handles jump data-scroll-to');
assert_true(str_contains($runtimeJs, 'parseNavigateTarget'), 'runtime parses quoted Navigate targets');

$header = $chrome ? chrome_find($chrome, '5_Summary_Header') : null;
// Optional in fixture; when present, LayoutMinHeight must win over a smaller Height.
if ($header !== null && isset($header['properties']['LayoutMinHeight'])) {
    $minH = (int) ltrim((string) $header['properties']['LayoutMinHeight'], '=');
    assert_true((int) ($header['layout']['height'] ?? 0) >= $minH, 'LayoutMinHeight clamps summary header height');
}

$folded = (new \PowerSweeper\WebApp\SweeperStyles())->fallbackConvert($html);
assert_true((preg_match_all('/<[^>]*\sclass="[^"]*"\s[^>]*\sclass="/i', $folded['html']) ?: 0) === 0, 'geometry class is merged onto the existing class attribute');
assert_true(str_contains($folded['html'], 'ps_c'), 'folded HTML keeps generated geometry classes');

echo "web_pixel_chrome_test: all checks passed\n";
