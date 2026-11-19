<?php

declare(strict_types=1);

use PowerSweeper\ControlDocument;
use PowerSweeper\Report;
use PowerSweeper\SweepRegistry;
use PowerSweeper\Sweeps\AcronymHtmlSweep;

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

assert_true((new SweepRegistry())->has('acronym_html'), 'acronym_html is registered');
assert_true(
    in_array('acronym_html', SweepRegistry::PALETTE_IDS, true),
    'acronym_html is in the palette'
);

$doc = [
    'TopParent' => [
        'Type' => 'ControlInfo',
        'Name' => 'Screen',
        'Template' => [
            'Id' => 'http://microsoft.com/appmagic/screen',
            'Version' => '1.0',
            'Name' => 'screen',
        ],
        'Rules' => [],
        'Children' => [
            ruleLabel('lblProgram', 'fxT("By OHS program", "Par programme SST")', 'false'),
            ruleLabel('lblData', 'ThisItem.L2', 'false'),
            ruleLabel('lblClick', '"WHMIS"', 'Select(Parent)'),
            [
                'Type' => 'ControlInfo',
                'Name' => 'lblOld',
                'Template' => [
                    'Id' => 'http://microsoft.com/appmagic/htmlViewer',
                    'Version' => '2.1.0',
                    'Name' => 'htmlViewer',
                ],
                'StyleName' => 'defaultHtmlViewerStyle',
                'Rules' => [
                    [
                        'Property' => 'HtmlText',
                        'Category' => 'Data',
                        'InvariantScript' => '"<abbr title=\'Level 1\'>L1</abbr>"',
                        'RuleProviderType' => 'User',
                    ],
                ],
                'Children' => [],
            ],
            [
                'Type' => 'ControlInfo',
                'Name' => 'lblPre',
                'Template' => [
                    'Id' => 'http://microsoft.com/appmagic/htmlViewer',
                    'Version' => '2.1.0',
                    'Name' => 'htmlViewer',
                ],
                'StyleName' => 'defaultHtmlViewerStyle',
                'Rules' => [
                    [
                        'Property' => 'HtmlText',
                        'Category' => 'Data',
                        'InvariantScript' => '"<pre>" & varInfoTitle & "</pre>"',
                        'RuleProviderType' => 'User',
                    ],
                ],
                'Children' => [],
            ],
        ],
    ],
];

$path = sys_get_temp_dir() . '/acronym_html_fixture.json';
file_put_contents($path, json_encode($doc));
$loaded = ControlDocument::fromFile($path, 'Controls/fixture.json');
assert_true($loaded !== null, 'fixture loads');

$report = new Report();
(new AcronymHtmlSweep())->apply([$loaded], $report, []);
$byName = [];
foreach ($loaded->controls() as $control) {
    $byName[$control->name] = $control;
}

$html = (string) $byName['lblProgram']->getProperty('HtmlText');
assert_true(str_contains($html, "<abbr title='Occupational Health and Safety' style='text-decoration:none'>OHS</abbr>"), 'English OHS is tagged without an underline');
assert_true(str_contains($html, "<abbr title='Santé et sécurité au travail' style='text-decoration:none'>SST</abbr>"), 'French SST is tagged without an underline');
assert_true(!str_contains($html, '>OHS</abbr>') || str_contains($html, 'Occupational'), 'OHS title is the English expansion');
assert_true($byName['lblProgram']->type === 'htmlViewer', 'label switched to htmlViewer');
assert_true($byName['lblProgram']->getProperty('Text') === null, 'Text property removed');
assert_true($byName['lblData']->type === 'label', 'ThisItem.L2 is not treated as the acronym L2');
assert_true($byName['lblClick']->type === 'label', 'clickable labels stay labels');
$old = (string) $byName['lblOld']->getProperty('HtmlText');
assert_true(str_contains($old, "<abbr title='Level 1' style='text-decoration:none'>L1</abbr>"), 'existing abbr tags lose the underline and keep the title');
assert_true($byName['lblPre']->getProperty('HtmlText') === '"<pre>" & varInfoTitle & "</pre>"', 'other HTML text is left alone');
assert_true($report->entries() !== [], 'sweep reports the conversion');

@unlink($path);
echo "acronym_html_test passed\n";

/**
 * @return array<string, mixed>
 */
function ruleLabel(string $name, string $text, string $onSelect): array
{
    return [
        'Type' => 'ControlInfo',
        'Name' => $name,
        'Template' => [
            'Id' => 'http://microsoft.com/appmagic/label',
            'Version' => '2.5.1',
            'Name' => 'label',
        ],
        'StyleName' => 'defaultLabelStyle',
        'Rules' => [
            [
                'Property' => 'Text',
                'Category' => 'Data',
                'InvariantScript' => $text,
                'RuleProviderType' => 'User',
            ],
            [
                'Property' => 'OnSelect',
                'Category' => 'Behavior',
                'InvariantScript' => $onSelect,
                'RuleProviderType' => 'User',
            ],
            [
                'Property' => 'Color',
                'Category' => 'Design',
                'InvariantScript' => 'gblTheme.Text',
                'RuleProviderType' => 'User',
            ],
        ],
        'Children' => [],
    ];
}
