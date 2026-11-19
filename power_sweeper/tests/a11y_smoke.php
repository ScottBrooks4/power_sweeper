<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\ControlDocument;
use PowerSweeper\Report;
use PowerSweeper\Sweeps\AccessibilityLabelsSweep;
use PowerSweeper\Sweeps\AccessibilityPolishSweep;

function assert_true(bool $c, string $m): void
{
    if (!$c) {
        fwrite(STDERR, "FAIL: $m\n");
        exit(1);
    }
    echo "ok: $m\n";
}

$neighborDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/a11y_neighbors.pa.yaml', 'Src/Screen1.pa.yaml');
$neighborReport = new Report();
(new AccessibilityLabelsSweep())->apply([$neighborDoc], $neighborReport);
$neighborLabels = [];
foreach ($neighborDoc->controls() as $c) {
    $neighborLabels[$c->name] = $c->getProperty('AccessibleLabel');
}
assert_true(preg_match('/EmailLabel\.Text/', (string) $neighborLabels['EmailInput']) === 1, 'EmailInput binds EmailLabel.Text');
assert_true(preg_match('/PhoneLabel\.Text/', (string) $neighborLabels['PhoneInput']) === 1, 'PhoneInput binds PhoneLabel.Text');
assert_true(preg_match('/^=?lblDepartment\.Text\s*$/', trim((string) $neighborLabels['txtDepartment'])) === 1, 'txtDepartment');
assert_true(preg_match('/SaveCaption\.Text/', (string) $neighborLabels['SaveIcon']) === 1, 'SaveIcon');

$purposeDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/a11y_purpose.pa.yaml', 'Src/Screen1.pa.yaml');
$purposeReport = new Report();
(new AccessibilityLabelsSweep())->apply([$purposeDoc], $purposeReport);
$purposeLabels = [];
foreach ($purposeDoc->controls() as $c) {
    $purposeLabels[$c->name] = $c->getProperty('AccessibleLabel');
}
assert_true(
    str_contains(strtolower((string) $purposeLabels['ManagerReviewCheck']), 'checkbox for')
        && str_contains(strtolower((string) $purposeLabels['ManagerReviewCheck']), 'manager'),
    'checkbox'
);
assert_true(
    str_contains(strtolower((string) $purposeLabels['NextFormIcon']), 'right arrow')
        && str_contains(strtolower((string) $purposeLabels['NextFormIcon']), 'request form'),
    'icon nav'
);
assert_true(preg_match('/^=?"Save"?\s*$/', trim((string) $purposeLabels['SaveOnlyIcon'])) === 1, 'Save icon');
assert_true(str_contains(strtolower((string) $purposeLabels['OpenDetailsButton']), 'details'), 'button dest');
assert_true(!str_contains(strtolower((string) $purposeLabels['Button1']), 'button 1'), 'no Button 1');
assert_true(
    str_contains(strtolower((string) $purposeLabels['ApprovalRequiredToggle']), 'toggle for')
        && str_contains(strtolower((string) $purposeLabels['ApprovalRequiredToggle']), 'approval'),
    'toggle name'
);

$tabDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/a11y_tab_stops.pa.yaml', 'Src/Screen1.pa.yaml');
$staticBefore = null;
foreach ($tabDoc->controls() as $c) {
    if ($c->name === 'StaticCaption') {
        $staticBefore = $c->getProperty('TabIndex');
        assert_true(!$c->isAccessibilityInteractive(), 'static not a11y');
    }
}
$tabReport = new Report();
(new AccessibilityPolishSweep())->apply([$tabDoc], $tabReport, ['force' => true]);
$tabDoc->reindex();
$by = [];
foreach ($tabDoc->controls() as $c) {
    $by[$c->name] = $c;
}
assert_true(($by['StaticCaption']->getProperty('TabIndex') ?? null) === $staticBefore, 'static left alone');
assert_true($by['Label3_2']->hasConditionalA11yTabIndex(), 'Label conditional');
assert_true($by['tileTitle']->hasConditionalA11yTabIndex(), 'tileTitle conditional');
assert_true($by['ClickableLabel']->tabIndexValue() === 0, 'clickable 0');
assert_true($by['Icon1_1']->tabIndexValue() === 0, 'icon 0');
assert_true($by['Rectangle9_1']->tabIndexValue() === 0, 'rect 0');
assert_true($by['TemplateSVGImg']->hasConditionalA11yTabIndex(), 'img conditional');
assert_true($by['OverViewGallery']->tabIndexValue() === 0, 'gallery 0');
assert_true($by['conHeaderHeader_UserProfile_2']->hasConditionalA11yTabIndex(), 'header conditional');
assert_true($by['ProfileLabel']->hasConditionalA11yTabIndex(), 'profile conditional');
assert_true($by['SaveButton']->tabIndexValue() === 0, 'button 0');
assert_true(str_contains((string) $by['App']->getProperty('OnStart'), 'gblA11yTabStops'), 'onstart var');
assert_true(isset($by['tglPowerSweeperA11yTabStops']), 'toggle injected');
assert_true($by['Label3_2']->tabIndexSatisfiesChecker(), 'satisfies checker');
assert_true(str_contains(strtolower((string) $by['Label3_2']->getProperty('AccessibleLabel')), 'help'), 'label text');
$ft = (string) ($by['Icon1_1']->getProperty('FocusedBorderThickness') ?? '');
assert_true($ft !== '' && $ft !== '0' && $ft !== '=0', 'focus');

echo "ALL A11Y OK\n";
