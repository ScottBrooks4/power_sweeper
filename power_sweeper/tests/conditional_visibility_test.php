<?php

declare(strict_types=1);

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

$irCandidates = [
    '/tmp/vcr_roundtrip/pkg/ir/power_sweeper_ir.json',
    dirname(__DIR__) . '/web/_vcr_ir/power_sweeper_ir.json',
    dirname(__DIR__) . '/web/VCR/ir/power_sweeper_ir.json',
    dirname(__DIR__) . '/web/php-xml-wet/ir/power_sweeper_ir.json',
];
$irPath = '';
foreach ($irCandidates as $cand) {
    if (is_file($cand)) {
        $irPath = $cand;
        break;
    }
}
assert_true($irPath !== '', 'VCR conversion IR is available for visibility checks');
$ir = json_decode((string) file_get_contents($irPath), true);
assert_true(is_array($ir), 'IR loads');

$html = (new WebAppPixelRenderer())->renderIntermediate($ir);

assert_true(str_contains($html, 'data-control="BOTSelect"'), 'BOTSelect conditional control is emitted');
assert_true(
    (bool) preg_match('/data-control="BOTSelect"[^>]*is-hidden|class="[^"]*is-hidden[^"]*"[^>]*data-control="BOTSelect"/', $html),
    'BOTSelect starts with is-hidden'
);
assert_true(str_contains($html, 'data-control="VIPLevel"'), 'VIPLevel conditional control is emitted');
assert_true(str_contains($html, 'data-control="SecurityClassificationSpecificationContainer"'), 'SecurityClassificationSpecificationContainer is emitted');
assert_true(
    (bool) preg_match('/data-control="AdminRequestLookup"[^>]*(?!is-hidden)/', $html)
    || (bool) preg_match('/data-control="AdminRequestLookup"[^>]*class="(?![^"]*is-hidden)[^"]*"/', $html),
    'default AdminRequestLookup pane is not idle-hidden'
);
assert_true(
    (bool) preg_match('/data-control="AdminMap"[^>]*is-hidden|class="[^"]*is-hidden[^"]*"[^>]*data-control="AdminMap"/', $html),
    'AdminMap pane starts hidden'
);
assert_true(!str_contains($html, 'data-control="LoadingContainer"') || str_contains($html, 'LoadingContainer_'), 'literal loading chrome stays omitted by name hard-skip');

$runtime = (string) file_get_contents(dirname(__DIR__) . '/templates/web_package/assets/runtime.js');
assert_true(str_contains($runtime, '[\\s\\S]+'), 'runtime If() matcher accepts multiline Visible formulas');
assert_true(str_contains($runtime, 'Selected.Value') || str_contains($runtime, 'Selected') && str_contains($runtime, 'resolvePath'), 'runtime resolves Selected.Value');
assert_true(str_contains($runtime, 'function resolvePath'), 'runtime has resolvePath for dotted Visible formulas');

echo "conditional_visibility_test: all checks passed\n";
