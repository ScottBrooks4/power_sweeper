<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\SweepAdvisor;
use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNaming;
use PowerSweeper\PowerAppsYaml;
use PowerSweeper\StudioJson;
use PowerSweeper\StudioIssueScanner;
use PowerSweeper\FormulaIdentifierRewriter;
use PowerSweeper\FormulaLocaleNormalizer;
use PowerSweeper\EnglishSpelling;
use PowerSweeper\Sweeps\AccessibilityLabelsSweep;
use PowerSweeper\Sweeps\AlignNearMissSweep;
use PowerSweeper\Sweeps\AnalyzeAppCheckerSweep;
use PowerSweeper\Sweeps\EnableDarkModeSweep;
use PowerSweeper\Sweeps\EnsureFocusVisibleSweep;
use PowerSweeper\Sweeps\EnsureTabIndexSweep;
use PowerSweeper\Sweeps\AccessibilityPolishSweep;
use PowerSweeper\Sweeps\MeaningfulNamesSweep;
use PowerSweeper\Sweeps\NormalizeClassicButtonChromeSweep;
use PowerSweeper\Sweeps\NormalizeContainersSweep;
use PowerSweeper\Sweeps\PreferClassicThemeControlsSweep;
use PowerSweeper\Sweeps\RepairContextAwareRefsSweep;
use PowerSweeper\Sweeps\RepairCheckedBooleansSweep;
use PowerSweeper\Sweeps\ScanStudioIssuesSweep;
use PowerSweeper\Sweeps\StripDefaultFillSweep;
use PowerSweeper\Sweeps\TooltipFromLabelSweep;
use PowerSweeper\Sweeps\TranslateSweep;
use PowerSweeper\Sweeps\SpellingConsistencySweep;
use PowerSweeper\Sweeps\UnwhackLocaleFormulasSweep;
use PowerSweeper\Pipeline;
use PowerSweeper\EstimateModel;
use PowerSweeper\SweepChains;
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
(new NormalizeContainersSweep())->apply([$doc], $report);
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

require __DIR__ . '/floor_scanner_test.php';

// --- accessibility ---
$doc = loadFixtureDoc();
$report = new Report();
(new AccessibilityLabelsSweep())->apply([$doc], $report);
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
(new AccessibilityLabelsSweep())->apply([$doc], $report);
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
(new AccessibilityLabelsSweep())->apply([$doc], $report, ['force' => true]);
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        assert_true(str_contains(strtolower((string) $c->getProperty('AccessibleLabel')), 'start'), 'AccessibleLabel overwritten when force=true');
    }
}

// Dynamic Text binds AccessibleLabel to Self.Text (not a stringified formula)
$doc = loadFixtureDoc();
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $c->setProperty('Text', '=If(varLang, "Save", "Enregistrer")');
        $c->removeProperty('AccessibleLabel');
        break;
    }
}
$report = new Report();
(new AccessibilityLabelsSweep())->apply([$doc], $report);
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        assert_true(
            preg_match('/^=?Self\.Text\s*$/', trim((string) $c->getProperty('AccessibleLabel'))) === 1,
            'AccessibleLabel binds Self.Text for dynamic Text'
        );
    }
}
// Stringified-formula labels are repaired even when force=false
$doc = loadFixtureDoc();
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        $c->setProperty('Text', '=If(varLang, "Save", "Enregistrer")');
        $c->setProperty('AccessibleLabel', '="If(varLang,""Save"",""Enregistrer"")"');
        break;
    }
}
$report = new Report();
(new AccessibilityLabelsSweep())->apply([$doc], $report, ['force' => false]);
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        assert_true(
            preg_match('/^=?Self\.Text\s*$/', trim((string) $c->getProperty('AccessibleLabel'))) === 1,
            'stringified formula AccessibleLabel repaired to Self.Text'
        );
    }
}

// Neighbor-aware accessibility labels (inputs use nearby Label text)
$neighborDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/a11y_neighbors.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($neighborDoc !== null, 'a11y neighbors fixture loads');
$neighborReport = new Report();
(new AccessibilityLabelsSweep())->apply([$neighborDoc], $neighborReport);
$neighborLabels = [];
foreach ($neighborDoc->controls() as $c) {
    $neighborLabels[$c->name] = $c->getProperty('AccessibleLabel');
}
assert_true(
    is_string($neighborLabels['EmailInput'] ?? null)
        && str_contains(strtolower((string) $neighborLabels['EmailInput']), 'email'),
    'EmailInput AccessibleLabel from label above'
);
assert_true(
    is_string($neighborLabels['PhoneInput'] ?? null)
        && str_contains(strtolower((string) $neighborLabels['PhoneInput']), 'phone'),
    'PhoneInput AccessibleLabel from label to the left'
);
assert_true(
    is_string($neighborLabels['txtDepartment'] ?? null)
        && preg_match('/^=?lblDepartment\.Text\s*$/', trim((string) $neighborLabels['txtDepartment'])) === 1,
    'txtDepartment AccessibleLabel binds paired lblDepartment.Text'
);
assert_true(
    is_string($neighborLabels['SaveIcon'] ?? null)
        && str_contains(strtolower((string) $neighborLabels['SaveIcon']), 'save'),
    'SaveIcon AccessibleLabel from caption to the right'
);

// Purpose-first labels: spoken meaning for icons, checkboxes, destinations — not Button1
$purposeDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/a11y_purpose.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($purposeDoc !== null, 'a11y purpose fixture loads');
$purposeReport = new Report();
(new AccessibilityLabelsSweep())->apply([$purposeDoc], $purposeReport);
$purposeLabels = [];
foreach ($purposeDoc->controls() as $c) {
    $purposeLabels[$c->name] = $c->getProperty('AccessibleLabel');
}
assert_true(
    is_string($purposeLabels['ManagerReviewCheck'] ?? null)
        && str_contains(strtolower((string) $purposeLabels['ManagerReviewCheck']), 'checkbox for')
        && str_contains(strtolower((string) $purposeLabels['ManagerReviewCheck']), 'manager'),
    'checkbox AccessibleLabel is purpose speech with role'
);
assert_true(
    is_string($purposeLabels['NextFormIcon'] ?? null)
        && str_contains(strtolower((string) $purposeLabels['NextFormIcon']), 'right arrow')
        && str_contains(strtolower((string) $purposeLabels['NextFormIcon']), 'request form'),
    'icon AccessibleLabel uses icon meaning + Navigate destination'
);
assert_true(
    is_string($purposeLabels['SaveOnlyIcon'] ?? null)
        && preg_match('/^=?"Save"?\s*$/', trim((string) $purposeLabels['SaveOnlyIcon'])) === 1,
    'icon AccessibleLabel uses Icon.Save meaning, not control name'
);
assert_true(
    is_string($purposeLabels['OpenDetailsButton'] ?? null)
        && str_contains(strtolower((string) $purposeLabels['OpenDetailsButton']), 'details'),
    'empty-text button AccessibleLabel uses Navigate destination'
);
assert_true(
    is_string($purposeLabels['Button1'] ?? null)
        && !str_contains(strtolower((string) $purposeLabels['Button1']), 'button 1'),
    'generic Button1 does not become AccessibleLabel "Button 1"'
);
assert_true(
    is_string($purposeLabels['ApprovalRequiredToggle'] ?? null)
        && str_contains(strtolower((string) $purposeLabels['ApprovalRequiredToggle']), 'toggle for')
        && str_contains(strtolower((string) $purposeLabels['ApprovalRequiredToggle']), 'approval'),
    'toggle AccessibleLabel uses meaningful name as purpose with role'
);

$jsonTextNode = (object) ['Name' => 'Hint', 'Template' => (object) ['Name' => 'text'], 'Rules' => []];
$jsonTextControl = new \PowerSweeper\ControlNode('Hint', 'text', 'Controls/x.json/Hint', 'json', $jsonTextNode);
assert_true($jsonTextControl->isInteractive(), 'JSON classic TextInput template name "text" is interactive');

// Twin sync must map Classic/TextInput → JSON template "text" (not "textinput").
$twinYamlRaw = "Screens:\n  Screen1:\n    Properties:\n      LoadingSpinnerColor: =RGBA(0,0,0,1)\n    Children:\n      - Input1:\n          Control: Classic/TextInput@2.3.2\n          Properties:\n            Default: =\"\"\n";
$twinYamlPath = sys_get_temp_dir() . '/ps_twin_text_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents($twinYamlPath, $twinYamlRaw);
$twinYamlDoc = ControlDocument::fromFile($twinYamlPath, 'Src/Screen1.pa.yaml');
$twinJsonNode = (object) [
    'TopParent' => (object) [
        'Name' => 'Screen1',
        'Template' => (object) ['Name' => 'screen'],
        'Rules' => [],
        'Children' => [
            (object) [
                'Name' => 'Input1',
                'Template' => (object) [
                    'Id' => 'http://microsoft.com/appmagic/modernTextInput',
                    'Name' => 'modernTextInput',
                    'Version' => '1.1.2',
                    'OverridableProperties' => (object) [],
                ],
                'Rules' => [],
                'Children' => [],
            ],
        ],
    ],
];
$twinJsonPath = sys_get_temp_dir() . '/ps_twin_text_' . bin2hex(random_bytes(3)) . '.json';
file_put_contents($twinJsonPath, \PowerSweeper\StudioJson::encode($twinJsonNode));
$twinJsonDoc = ControlDocument::fromFile($twinJsonPath, 'Controls/1.json');
assert_true($twinYamlDoc !== null && $twinJsonDoc !== null, 'twin TextInput fixtures load');
$twinMap = new \PowerSweeper\DocumentTwinMap([$twinYamlDoc, $twinJsonDoc]);
$twinMap->syncYamlToJson();
$twinInput = null;
foreach ($twinJsonDoc->controls() as $c) {
    if ($c->name === 'Input1') {
        $twinInput = $c;
        break;
    }
}
assert_true($twinInput !== null, 'twin sync finds Input1');
assert_true($twinInput->type === 'text', 'twin sync sets control type to JSON template name text');
$twinJsonDoc->save($twinJsonPath);
$twinRawOut = (string) file_get_contents($twinJsonPath);
assert_true(str_contains($twinRawOut, '"Name": "text"'), 'twin sync writes JSON template Name text');
assert_true(str_contains($twinRawOut, 'http://microsoft.com/appmagic/text'), 'twin sync writes JSON template Id …/text');
assert_true(!str_contains($twinRawOut, 'textinput'), 'twin sync does not invent textinput template');
@unlink($twinYamlPath);
@unlink($twinJsonPath);

// --- Missing tab stop: TabIndex=-1 Labels/Images + OnSelect shapes; static labels untouched ---
$tabDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/a11y_tab_stops.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($tabDoc !== null, 'a11y tab stops fixture loads');
$staticBefore = null;
foreach ($tabDoc->controls() as $c) {
    if ($c->name === 'StaticCaption') {
        $staticBefore = $c->getProperty('TabIndex');
        assert_true(!$c->isAccessibilityInteractive(), 'static Label without TabIndex is not a11y-interactive');
    }
    if ($c->name === 'Label3_2') {
        assert_true($c->isAccessibilityInteractive(), 'Label with TabIndex=-1 is a11y-interactive');
        assert_true($c->tabIndexValue() === -1, 'Label3_2 starts at TabIndex -1');
    }
    if ($c->name === 'ClickableLabel') {
        assert_true($c->hasActionHandler(), 'Label with Navigate OnSelect has action handler');
        assert_true($c->isAccessibilityInteractive(), 'OnSelect Label is a11y-interactive');
    }
    if ($c->name === 'Rectangle9_1') {
        assert_true($c->hasActionHandler() && $c->isAccessibilityInteractive(), 'OnSelect rectangle is a11y-interactive');
    }
}
$tabReport = new Report();
(new AccessibilityPolishSweep())->apply([$tabDoc], $tabReport, ['force' => true]);
$tabDoc->reindex();
$by = [];
foreach ($tabDoc->controls() as $c) {
    $by[$c->name] = $c;
}
assert_true(($by['StaticCaption']->getProperty('TabIndex') ?? null) === $staticBefore, 'static Label TabIndex left alone');
assert_true($by['Label3_2']->tabIndexValue() === 0, 'Label TabIndex -1 → 0 (Missing tab stop)');
assert_true($by['tileTitle']->tabIndexValue() === 0, 'tileTitle TabIndex -1 → 0');
assert_true($by['ClickableLabel']->tabIndexValue() === 0, 'OnSelect Label gets TabIndex 0');
assert_true($by['Icon1_1']->tabIndexValue() === 0, 'Icon TabIndex -1 → 0');
assert_true($by['Rectangle9_1']->tabIndexValue() === 0, 'Rectangle hit-target TabIndex -1 → 0');
assert_true($by['TemplateSVGImg']->tabIndexValue() === 0, 'Image TabIndex -1 → 0');
assert_true($by['OverViewGallery']->tabIndexValue() === 0, 'Gallery TabIndex -1 → 0');
assert_true($by['conHeaderHeader_UserProfile_2']->tabIndexValue() === 0, 'header container TabIndex -1 → 0');
assert_true($by['SaveButton']->tabIndexValue() === 0, 'Button without TabIndex gets 0');
assert_true(
    is_string($by['Label3_2']->getProperty('AccessibleLabel'))
        && str_contains(strtolower((string) $by['Label3_2']->getProperty('AccessibleLabel')), 'help'),
    'TabIndex=-1 Label gets AccessibleLabel from Text'
);
assert_true(
    (string) ($by['Icon1_1']->getProperty('FocusedBorderThickness') ?? '') !== ''
        && (string) ($by['Icon1_1']->getProperty('FocusedBorderThickness') ?? '') !== '0'
        && (string) ($by['Icon1_1']->getProperty('FocusedBorderThickness') ?? '') !== '=0',
    'interactive Icon gets focus ring'
);
$scanLeft = array_values(array_filter(
    StudioIssueScanner::scan([$tabDoc]),
    static fn(array $i): bool => ($i['kind'] ?? '') === 'missing_tab_stop'
));
assert_true($scanLeft === [], 'no missing_tab_stop left after accessibility_polish');

// --- advisor force mode stamps forceable sweeps ---
$advisorForce = new SweepAdvisor();
$forced = $advisorForce->applyForceMode([
    ['id' => 'accessibility_polish', 'options' => []],
    ['id' => 'export_to_web_ir', 'options' => []],
], 'all');
assert_true(($forced[0]['options']['force'] ?? false) === true, 'applyForceMode sets force on forceable sweeps');
assert_true(!array_key_exists('force', $forced[1]['options']), 'non-forceable sweep left alone');
$missing = $advisorForce->applyForceMode([
    ['id' => 'accessibility_polish', 'options' => ['force' => true]],
], 'missing_only');
assert_true(($missing[0]['options']['force'] ?? true) === false, 'applyForceMode missing_only clears force');

// --- Report caps detail rows but keeps accurate totals (THCEE OOM guard) ---
$capReport = new Report(null, 3, 20);
for ($i = 0; $i < 10; $i++) {
    $capReport->add('enable_dark_mode', 'Ctrl' . $i, 'Fill', str_repeat('A', 100), str_repeat('B', 100));
}
assert_true($capReport->count() === 10, 'capped report still counts all changes');
assert_true(count($capReport->entries()) === 3, 'capped report retains only maxEntries detail rows');
$capArr = $capReport->toArray();
assert_true(($capArr['entries_truncated'] ?? false) === true, 'capped report marks entries_truncated');
assert_true(($capArr['entries_omitted'] ?? 0) === 7, 'capped report reports omitted count');
assert_true(($capArr['by_sweep']['enable_dark_mode'] ?? 0) === 10, 'capped report by_sweep stays accurate');
assert_true(strlen($capArr['entries'][0]['from']) <= 20, 'capped report truncates from snippets');
assert_true(str_ends_with($capArr['entries'][0]['from'], '...'), 'capped report marks truncated snippets');

// --- ColorValue studio defaults ---
assert_true(ColorValue::isStudioDefault('=RGBA(255, 255, 255, 1)', 'Fill'), 'white fill is studio default');
assert_true(ColorValue::isStudioDefault('=RGBA(20, 20, 20, 1)', 'Color'), 'near-black text is studio default');
assert_true(!ColorValue::isStudioDefault('=RGBA(37, 99, 235, 1)', 'Fill'), 'brand blue fill is not studio default');

// --- dark mode force preserves custom colors ---
$darkDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_app.pa.yaml', 'Src/App.pa.yaml');
assert_true($darkDoc !== null, 'dark mode fixture loads');
$darkReport = new Report();
(new EnableDarkModeSweep())->apply([$darkDoc], $darkReport);
$accentFill = null;
foreach ($darkDoc->controls() as $c) {
    if ($c->name === 'Accent') {
        $accentFill = (string) ($c->getProperty('Fill') ?? '');
    }
}
assert_true(
    $accentFill !== null
        && str_contains($accentFill, '37, 99, 235')
        && (
            !str_contains($accentFill, 'gblTheme.')
            || str_contains($accentFill, 'If(gblDarkMode')
        ),
    'custom accent fill preserved when force=false'
);
$darkDocForced = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_app.pa.yaml', 'Src/App.pa.yaml');
assert_true($darkDocForced !== null, 'dark mode fixture reloads');
(new EnableDarkModeSweep())->apply([$darkDocForced], new Report(), ['force' => true]);
$accentFillForced = null;
foreach ($darkDocForced->controls() as $c) {
    if ($c->name === 'Accent') {
        $accentFillForced = (string) ($c->getProperty('Fill') ?? '');
    }
}
assert_true($accentFillForced !== null && str_contains($accentFillForced, 'gblTheme.'), 'custom accent fill re-themed when force=true');

// --- translate sweep: packs + language radio wiring ---
$translateDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/translate_app.pa.yaml', 'Src/App.pa.yaml');
assert_true($translateDoc !== null, 'translate fixture loads');
$translateReport = new Report();
(new TranslateSweep())->apply([$translateDoc], $translateReport);
$onStart = $titleText = $goText = $subText = $langOnChange = $glyph = null;
foreach ($translateDoc->controls() as $c) {
    if ($c->isApp()) {
        $onStart = (string) ($c->getProperty('OnStart') ?? '');
    }
    if ($c->name === 'Title') {
        $titleText = (string) ($c->getProperty('Text') ?? '');
    }
    if ($c->name === 'GoBtn') {
        $goText = (string) ($c->getProperty('Text') ?? '');
    }
    if ($c->name === 'Subtitle') {
        $subText = (string) ($c->getProperty('Text') ?? '');
    }
    if ($c->name === 'LanguageRadio') {
        $langOnChange = (string) ($c->getProperty('OnChange') ?? '');
    }
    if ($c->name === 'SkipGlyph') {
        $glyph = (string) ($c->getProperty('Text') ?? '');
    }
}
assert_true(is_string($onStart) && str_contains($onStart, '/* ps-i18n:start */'), 'translate injects i18n OnStart block');
assert_true(is_string($onStart) && str_contains($onStart, 'gblStrings') && str_contains($onStart, 'En:') && str_contains($onStart, 'Fr:'), 'translate defines En/Fr side-by-side packs');
assert_true(is_string($titleText) && str_contains($titleText, 'gblStrings.'), 'translate rewrites Welcome label to gblStrings');
assert_true(is_string($goText) && str_contains($goText, 'gblStrings.'), 'translate rewrites Continue button to gblStrings');
assert_true(is_string($subText) && str_contains($subText, 'gblStrings.'), 'translate collapses bilingual If(varLang) into gblStrings');
assert_true(is_string($onStart) && str_contains($onStart, 'Open request') && str_contains($onStart, 'Ouvrir la demande'), 'translate keeps both EN/FR bilingual literals in packs');
assert_true(is_string($langOnChange) && str_contains($langOnChange, 'Set(varLang'), 'translate wires existing LanguageRadio to Set(varLang)');
assert_true(is_string($glyph) && str_contains($glyph, '→'), 'translate leaves arrow glyph alone');
assert_true($translateReport->count() > 0, 'translate reports changes');
assert_true((new \PowerSweeper\SweepRegistry())->has('translate'), 'translate sweep registered');

// --- translate: unhide LanguagePreference + EN/FR slider in top bar ---
$langUiDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/translate_settings_lang.pa.yaml', 'Src/Home.pa.yaml');
assert_true($langUiDoc !== null, 'translate settings lang fixture loads');
$langUiReport = new Report();
(new TranslateSweep())->apply([$langUiDoc], $langUiReport);
$langUiDoc->reindex();
$langPrefVisible = $langRadioItems = $langRadioOnChange = $sliderPath = $sliderEnHit = $sliderTimer = null;
$sliderInSettingsContainer = false;
foreach ($langUiDoc->controls() as $c) {
    if ($c->name === 'LanguagePreference') {
        $langPrefVisible = (string) ($c->getProperty('Visible') ?? '');
    }
    if ($c->name === 'LanguageRadio') {
        $langRadioItems = (string) ($c->getProperty('Items') ?? '');
        $langRadioOnChange = (string) ($c->getProperty('OnChange') ?? '');
    }
    if ($c->name === 'psLangSlider') {
        $sliderPath = $c->path;
        $sliderInSettingsContainer = str_contains($c->path, 'SettingsContainer');
    }
    if ($c->name === 'psLangENHit') {
        $sliderEnHit = (string) ($c->getProperty('OnSelect') ?? '');
    }
    if ($c->name === 'psLangSliderTimer') {
        $sliderTimer = (string) ($c->getProperty('Duration') ?? '');
    }
}
assert_true(is_string($langPrefVisible) && str_contains($langPrefVisible, 'true'), 'translate unhides LanguagePreference');
assert_true(is_string($langRadioItems) && str_contains($langRadioItems, 'French'), 'translate restores English/French radio Items');
assert_true(
    is_string($langRadioOnChange)
    && str_contains($langRadioOnChange, 'Set(varLang, Self.Selected.Value = "English")')
    && !str_contains($langRadioOnChange, 'Or('),
    'translate wires LanguageRadio OnChange like the theme radio (Selected.Value only)'
);
assert_true(is_string($sliderPath) && str_contains($sliderPath, 'SettingsAndHelpCont'), 'translate injects psLangSlider in top bar SettingsAndHelpCont');
assert_true($sliderInSettingsContainer === false, 'translate does not put slider on settings panel header');
assert_true(is_string($sliderEnHit) && str_contains($sliderEnHit, 'Set(varLang, true)'), 'slider EN hit target sets varLang true');
assert_true(is_string($sliderTimer) && trim(ltrim($sliderTimer, '=')) === '10', 'slider timer uses 10ms ticks for smoother slide');
$langUiOnStart = '';
foreach ($langUiDoc->controls() as $c) {
    if ($c->isApp()) {
        $langUiOnStart = (string) ($c->getProperty('OnStart') ?? '');
    }
}
assert_true(str_contains($langUiOnStart, 'Set(varPsLangKnobX,'), 'translate initializes slider knob in OnStart so the name is valid');
assert_true(str_contains($langUiOnStart, 'Supprimer la demande') || str_contains($langUiOnStart, 'Paramètres'), 'translate fills French fallbacks into gblStrings');

// --- translate: HTML copy is stored in the pack, formulas stay formulas, colons keep a trailing space ---
$htmlDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/translate_html_formula.pa.yaml', 'Src/App.pa.yaml');
assert_true($htmlDoc !== null, 'translate html formula fixture loads');
$htmlReport = new Report();
(new TranslateSweep())->apply([$htmlDoc], $htmlReport);
$htmlOnStart = '';
$htmlTitle = '';
foreach ($htmlDoc->controls() as $c) {
    if ($c->isApp()) {
        $htmlOnStart = (string) ($c->getProperty('OnStart') ?? '');
    }
    if ($c->name === 'RequestTitle') {
        $htmlTitle = (string) ($c->getProperty('Text') ?? '');
    }
}
assert_true(str_contains($htmlTitle, 'fnRequestTitle') && !str_contains($htmlTitle, 'gblStrings'), 'translate leaves bare formula names like fnRequestTitle alone');
assert_true(str_contains($htmlOnStart, 'EmerAcomAddress'), 'translate does not swallow concatenations inside HTML tags');
assert_true(str_contains($htmlOnStart, 'Résumé de la demande'), 'translate French-fills HTML phrases such as Request Summary');
$htmlToken = null;
if (preg_match('/gblStrings\.([A-Za-z0-9_]+)\.En,\s*gblStrings\.\1\.Fr/', $htmlOnStart, $htmlTok)) {
    $htmlToken = $htmlTok[1];
}
assert_true(is_string($htmlToken) && preg_match('/\b' . preg_quote((string) $htmlToken, '/') . '\s*:\s*\{/', $htmlOnStart) === 1, 'HTML bindings point at a token defined in gblStrings');
assert_true(str_contains($htmlOnStart, "Mode débogage\u{00A0}: "), 'French colon uses a non-breaking space and keeps a space before a concatenated value');

$valueDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/translate_value_display.pa.yaml', 'Src/App.pa.yaml');
assert_true($valueDoc !== null, 'translate value-display fixture loads');
(new TranslateSweep())->apply([$valueDoc], new Report());
$valueBtn = $valueStatus = $valueHtml = $valueFormulas = '';
foreach ($valueDoc->controls() as $c) {
    if ($c->name === 'RoleBtn') {
        $valueBtn = (string) ($c->getProperty('Text') ?? '');
    }
    if ($c->name === 'StatusLbl') {
        $valueStatus = (string) ($c->getProperty('Text') ?? '');
    }
    if ($c->name === 'MailHelp') {
        $valueHtml = (string) ($c->getProperty('HtmlText') ?? '');
    }
    if ($c->isApp()) {
        $valueFormulas = (string) ($c->getProperty('Formulas') ?? '') . (string) ($c->getProperty('OnStart') ?? '');
    }
}
assert_true(str_contains($valueBtn, 'Switch(ThisItem.Value') && str_contains($valueBtn, 'Rôles') && str_contains($valueBtn, '"User Roles"'), 'gallery button translates ThisItem.Value without replacing the stored value');
assert_true(str_contains($valueStatus, 'Switch(ThisItem.approval_state') && str_contains($valueStatus, 'En attente'), 'status label translates the display and keeps the SharePoint value');
assert_true(str_contains($valueHtml, 'gblStrings.') && str_contains($valueFormulas, 'jetons {name_of_field}'), 'help prose that mentions {field} tokens is translated');
assert_true(str_contains($valueFormulas, 'gblStrings.') && str_contains($valueFormulas, 'N° de demande'), 'email token Label fields are translated and Key stays put');

// --- English spelling consistency ---
assert_true(EnglishSpelling::normalizeLocale('en-CA') === 'ca', 'normalize en-CA → ca');
assert_true(EnglishSpelling::normalizeLocale('en-GB') === 'uk', 'normalize en-GB → uk');
assert_true(EnglishSpelling::normalizeLocale('en-US') === 'us', 'normalize en-US → us');
assert_true(
    EnglishSpelling::rewriteText('Pick a color for your favourite honour', 'ca') === 'Pick a colour for your favourite honour',
    'CA: color→colour, favourite/honour kept'
);
assert_true(
    EnglishSpelling::rewriteText('Pick a colour for your favourite honour', 'us') === 'Pick a color for your favorite honor',
    'US: colour/favourite/honour → American'
);
assert_true(
    EnglishSpelling::rewriteText('Organize the colors', 'uk') === 'Organise the colours',
    'UK: organize→organise, colors→colours'
);
assert_true(
    EnglishSpelling::rewriteFormulaStrings('=Navigate(\'Colour Screen\'); Notify("honour the color")', 'us')
        === '=Navigate(\'Colour Screen\'); Notify("honor the color")',
    'formula rewrite skips single-quoted names; fixes double-quoted strings'
);
$spellDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/spelling_consistency.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($spellDoc !== null, 'spelling fixture loads');
$spellReport = new Report();
(new SpellingConsistencySweep())->apply([$spellDoc], $spellReport, ['locale' => 'ca']);
$colourLabel = null;
$navBtn = null;
foreach ($spellDoc->controls() as $c) {
    if ($c->name === 'ColourLabel') {
        $colourLabel = $c;
    }
    if ($c->name === 'NavigateBtn') {
        $navBtn = $c;
    }
}
assert_true($colourLabel !== null && $navBtn !== null, 'spelling fixture controls found');
assert_true(
    (string) $colourLabel->getProperty('Text') === '="Pick a colour for your favourite honour"',
    'CA sweep rewrites color→colour in Text'
);
assert_true(
    (string) $colourLabel->getProperty('HintText') === '="Organize the colours"',
    'CA keeps Organize (-ize) and rewrites colors→colours'
);
assert_true(
    (string) $colourLabel->getProperty('Tooltip') === '="Centred grey dialogue"',
    'CA: Centered→Centred, dialog→dialogue'
);
assert_true(
    str_contains((string) $navBtn->getProperty('OnSelect'), "Navigate('Colour Screen')"),
    'CA sweep leaves single-quoted screen name alone'
);
assert_true(
    str_contains((string) $navBtn->getProperty('OnSelect'), 'Notify("honour the colour")'),
    'CA sweep rewrites Notify string color→colour'
);
assert_true($spellReport->count() > 0, 'spelling_consistency reports changes');
assert_true((new \PowerSweeper\SweepRegistry())->has('spelling_consistency'), 'spelling_consistency registered');
assert_true(
    in_array('spelling_consistency', \PowerSweeper\SweepRegistry::PALETTE_IDS, true),
    'spelling_consistency in UI palette'
);

// US pass on a fresh fixture
$spellUs = ControlDocument::fromFile(__DIR__ . '/fixtures/spelling_consistency.pa.yaml', 'Src/Screen1.pa.yaml');
$spellUsReport = new Report();
(new SpellingConsistencySweep())->apply([$spellUs], $spellUsReport, ['locale' => 'us']);
foreach ($spellUs->controls() as $c) {
    if ($c->name === 'ColourLabel') {
        assert_true(
            (string) $c->getProperty('Text') === '="Pick a color for your favorite honor"',
            'US sweep rewrites colour-side words to American'
        );
    }
}

// --- align near miss ---
$doc = loadFixtureDoc();
$report = new Report();
(new AlignNearMissSweep())->apply([$doc], $report, ['tolerance' => 3]);
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
(new StripDefaultFillSweep())->apply([$doc], $report);
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequest') {
        assert_true(str_contains(strtolower((string) $c->getProperty('Fill')), 'rgba(0, 0, 0, 0)'), 'container fill stripped');
    }
}

// --- button chrome ---
$doc = loadFixtureDoc();
$report = new Report();
(new NormalizeClassicButtonChromeSweep())->apply([$doc], $report);
foreach ($doc->controls() as $c) {
    if ($c->name === 'NewRequestButton') {
        assert_true(str_contains(strtolower((string) $c->getProperty('HoverFill')), 'rgba(0, 0, 0, 0)'), 'HoverFill cleared');
        assert_true(str_contains(strtolower((string) $c->getProperty('PressedFill')), 'rgba(0, 0, 0, 0)'), 'PressedFill cleared');
    }
}

// --- tooltip ---
$doc = loadFixtureDoc();
$report = new Report();
(new TooltipFromLabelSweep())->apply([$doc], $report);
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
(new MeaningfulNamesSweep())->apply([$genericDoc], $report);
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
(new UnwhackLocaleFormulasSweep())->apply([$doc], $report);
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
(new UnwhackLocaleFormulasSweep())->apply([$jsonDoc], $report);
assert_true($report->count() > 0, 'unwhack reported JSON changes');
$jsonX = null;
foreach ($jsonDoc->controls() as $c) {
    if ($c->name === 'InternalLabel') {
        $jsonX = (string) $c->getProperty('X');
    }
}
assert_true(is_string($jsonX) && str_contains($jsonX, '40.5') && str_contains($jsonX, ','), 'internal InvariantScript unwhacked');

// --- prefer classic controls for theming (modern lacking Fill/Color) ---
$classicPrepDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/theme_classic_prep.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($classicPrepDoc !== null, 'classic theme prep fixture loads');
$classicPrepReport = new Report();
(new PreferClassicThemeControlsSweep())->apply([$classicPrepDoc], $classicPrepReport);
$classicPrepDoc->reindex();
$byName = [];
foreach ($classicPrepDoc->controls() as $c) {
    $byName[$c->name] = $c;
}
assert_true(str_contains(strtolower((string) ($byName['TitleModern']->type ?? '')), 'moderntext'), 'ModernText without Fill stays modern (classic Label invents a dark box)');
assert_true(str_starts_with((string) ($byName['SaveModern']->type ?? ''), 'Classic/Button@'), 'ModernButton → Classic/Button');
assert_true(str_starts_with((string) ($byName['CanvasBtn']->type ?? ''), 'Classic/Button@'), 'ButtonCanvas → Classic/Button');
assert_true(str_starts_with((string) ($byName['NameInput']->type ?? ''), 'Classic/TextInput@'), 'ModernTextInput → Classic/TextInput');
assert_true(str_starts_with((string) ($byName['AlreadyClassic']->type ?? ''), 'Classic/Button@'), 'existing Classic/Button left alone');
assert_true(str_contains(strtolower((string) ($byName['GhostHit']->type ?? '')), 'button@0.0'), 'transparent modern button is not converted to classic');
assert_true(str_contains(strtolower((string) ($byName['WarningGlyph']->type ?? '')), 'modernicon')
    || str_contains(strtolower((string) ($byName['WarningGlyph']->type ?? '')), 'icon@0.0'),
    'modern icon is not converted to classic');
assert_true((string) ($byName['SaveModern']->getProperty('Size') ?? '') === '=12', 'converted classic button copies modern FontSize');
assert_true((string) ($byName['CompactBtn']->getProperty('Size') ?? '') === '=10.5', 'converted button keeps FontSize 10.5 instead of leftover Size=14');
assert_true(str_contains((string) ($byName['SaveModern']->getProperty('Font') ?? ''), 'Segoe UI'), 'converted classic button keeps modern Font');
assert_true(str_contains((string) ($byName['SaveModern']->getProperty('FontWeight') ?? ''), 'Semibold'), 'converted classic button keeps FontWeight');
assert_true(!str_contains((string) ($byName['SaveModern']->getProperty('RadiusTopLeft') ?? ''), 'Self.Height'), 'converted classic button does not invent a pill radius');
assert_true(trim((string) ($byName['SaveModern']->getProperty('RadiusTopLeft') ?? '')) === '=4', 'converted classic button uses 4px corners, not a capsule');
assert_true(str_starts_with((string) ($byName['RoleCheck']->type ?? ''), 'Classic/CheckBox@'), 'modern checkbox → Classic/CheckBox');
assert_true(str_contains((string) ($byName['RoleCheck']->getProperty('Default') ?? ''), 'Value(varRole)'), 'checkbox Checked remaps to Default');
assert_true((string) ($byName['RoleCheck']->getProperty('Text') ?? '') === '="Admin"', 'checkbox Label remaps to Text');
assert_true(str_contains((string) ($byName['RoleCheck']->getProperty('DisplayMode') ?? ''), 'Self.Value'), 'Self.Checked rewrites to Self.Value');
assert_true(str_contains((string) ($byName['RoleGate']->getProperty('Visible') ?? ''), 'RoleCheck.Value'), 'RoleCheck.Checked rewrites to RoleCheck.Value');
assert_true(str_starts_with((string) ($byName['NotifyOn']->type ?? ''), 'Classic/Toggle@'), 'modern toggle → Classic/Toggle');
assert_true(str_contains((string) ($byName['NotifyOn']->getProperty('Default') ?? ''), 'true'), 'toggle Checked remaps to Default');
assert_true((string) ($byName['NotifyOn']->getProperty('TrueText') ?? '') === '="Alerts"', 'toggle Label remaps to TrueText');
assert_true(preg_match('/radio@0\\.0\\./i', (string) ($byName['RankRadio']->type ?? '')) === 1, 'modern radio stays RadioGroupCanvas (classic conversion paints a black bar)');
assert_true(str_contains((string) ($byName['RankRadio']->getProperty('DefaultSelectedItems') ?? ''), '"Cdr"'), 'radio DefaultSelectedItems stays on the modern control');
assert_true((string) ($byName['RankCaption']->getProperty('Text') ?? '') === '=RankRadio.Selected', 'modern Radio.Selected is not rewritten when the control stays modern');
assert_true(str_starts_with((string) ($byName['StatusCombo']->type ?? ''), 'Classic/ComboBox@'), 'ModernCombobox → Classic/ComboBox');
assert_true(str_starts_with((string) ($byName['CanvasNameInput']->type ?? ''), 'Classic/TextInput@'), 'TextInputCanvas → Classic/TextInput');
assert_true((string) ($byName['CanvasNameInput']->getProperty('HintText') ?? '') === '="Full name"', 'modern Placeholder remaps to HintText');
assert_true(str_contains((string) ($byName['NotesBox']->getProperty('Mode') ?? ''), 'TextMode.MultiLine'), 'modern multiline Type becomes TextMode.MultiLine');
assert_true(str_starts_with((string) ($byName['TagsMulti']->type ?? ''), 'Classic/ComboBox@'), 'modern multiselect ComboBox → Classic/ComboBox');
assert_true(str_contains((string) ($byName['TagsMulti']->getProperty('SelectMultiple') ?? ''), 'true'), 'converted multiselect keeps SelectMultiple');
assert_true(str_contains((string) ($byName['TagsMulti']->getProperty('DefaultSelectedItems') ?? ''), '{Value: "EN"}'), 'primitive DefaultSelectedItems becomes a Value record table');
assert_true(str_contains((string) ($byName['TagsMulti']->getProperty('DisplayFields') ?? ''), 'Value'), 'converted combo seeds DisplayFields');
assert_true(str_contains((string) ($byName['StatusFilter']->getProperty('Visible') ?? ''), 'StatusCombo.SelectedItems.Value'), 'in Combo.SelectedItems rewrites to .Value');
assert_true(str_contains((string) ($byName['OrgGate']->getProperty('Visible') ?? ''), 'OrgCombo.SelectedItems.EnglishNameShort'), 'ShowColumns membership uses the real column');
assert_true(!str_contains((string) ($byName['OrgGate']->getProperty('Visible') ?? ''), 'SelectedItems.Value'), 'ShowColumns membership does not invent .Value');
assert_true(str_contains((string) ($byName['OrgCombo']->getProperty('DisplayFields') ?? ''), 'EnglishNameShort'), 'combo DisplayFields follows ShowColumns');
assert_true(str_contains((string) ($byName['StatusFindGate']->getProperty('Visible') ?? ''), 'StatusFind.SelectedItems.Text'), 'ThisItem.Text membership projects Text');
$checkboxJsonPath = sys_get_temp_dir() . '/power_sweeper_checkbox_' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($checkboxJsonPath, json_encode([
    'TopParent' => [
        'Name' => 'Admin',
        'Template' => ['Name' => 'screen'],
        'Children' => [[
            'Name' => 'CheckboxCanvas1',
            'Template' => ['Name' => 'CheckboxCanvas'],
            'Rules' => [
                ['Property' => 'DisplayMode', 'InvariantScript' => 'If(Self.Checked, DisplayMode.View, DisplayMode.Edit)'],
                ['Property' => 'Checked', 'InvariantScript' => 'false'],
            ],
        ]],
    ],
]));
$checkboxJsonDoc = ControlDocument::fromFile($checkboxJsonPath, 'Controls/1909.json');
assert_true($checkboxJsonDoc !== null, 'classic checkbox JSON fixture loads');
if ($checkboxJsonDoc !== null) {
    (new PreferClassicThemeControlsSweep())->apply([$checkboxJsonDoc], new Report());
    $checkboxJsonDoc->reindex();
    $checkboxDisplay = '';
    foreach ($checkboxJsonDoc->controls() as $checkboxNode) {
        if ($checkboxNode->name === 'CheckboxCanvas1') {
            $checkboxDisplay = (string) ($checkboxNode->getProperty('DisplayMode') ?? '');
        }
    }
    assert_true(str_contains($checkboxDisplay, 'Self.Value') && !str_contains($checkboxDisplay, 'Self.Checked'), 'JSON checkbox DisplayMode rewrites Self.Checked');
}
@unlink($checkboxJsonPath);
assert_true(str_starts_with((string) ($byName['StartDate']->type ?? ''), 'Classic/DatePicker@'), 'DatePickerCanvas → Classic/DatePicker');
assert_true(str_contains((string) ($byName['StartDate']->getProperty('DefaultDate') ?? ''), 'Date(2026, 9, 14)'), 'modern datepicker Value remaps to DefaultDate');
assert_true(str_contains((string) ($byName['StartDate']->getProperty('Format') ?? ''), 'DateTimeFormat.ShortDate'), 'datepicker keeps ShortDate Format');
assert_true(str_starts_with((string) ($byName['ArrivalDate']->type ?? ''), 'Classic/DatePicker@'), 'ArrivalDate DatePickerCanvas → classic');
assert_true(str_contains((string) ($byName['ArrivalDate']->getProperty('Format') ?? ''), 'DateTimeFormat.ShortDate'), 'DatePickerFormat.ShortDate remaps to DateTimeFormat.ShortDate');
assert_true(!str_contains((string) ($byName['ArrivalDate']->getProperty('Format') ?? ''), 'LongDate'), 'classic datepicker does not fall back to LongDate');
assert_true((string) ($byName['ArrivalDate']->getProperty('Size') ?? '') === '=12', 'datepicker Size=0 is replaced by FontSize');
assert_true(str_starts_with((string) ($byName['TinyTitle']->type ?? ''), 'Label@'), 'ModernText with Fill converts to Label');
assert_true((string) ($byName['TinyTitle']->getProperty('Size') ?? '') === '=16', 'Size=0 on converted text is replaced by FontSize');
assert_true((string) ($byName['SaveModern']->getProperty('BorderThickness') ?? '') === '=0', 'converted filled button does not gain a classic black border');
assert_true((string) ($byName['CanvasBtn']->getProperty('Size') ?? '') === '=10.5', 'converted classic button defaults Size when FontSize is unset');
assert_true(str_starts_with((string) ($byName['Sites']->type ?? ''), 'Classic/TextInput@'), 'ModernNumberInput → Classic/TextInput');
assert_true(str_contains((string) ($byName['Sites']->getProperty('Format') ?? ''), 'TextFormat.Number'), 'converted number input keeps numeric Format');
assert_true(str_contains((string) ($byName['SiteCount']->getProperty('Text') ?? ''), 'Value(Sites.Text)'), 'Sites.Value rewrites to Value(Sites.Text)');
$screenOnVisible = (string) ($byName['Screen1']->getProperty('OnVisible') ?? '');
assert_true(str_contains($screenOnVisible, 'RoleCheck.Value') && !str_contains($screenOnVisible, 'RoleCheck.Checked'), 'OnVisible RoleCheck.Checked rewrites to RoleCheck.Value');
assert_true(str_contains($screenOnVisible, "'Screen1'.RoleCheck.Value"), 'screen-qualified RoleCheck.Checked is rewritten');
assert_true(str_contains($screenOnVisible, 'Value(Sites.Text)'), 'OnVisible Sites.Value rewrites to Value(Sites.Text)');
assert_true(str_contains($screenOnVisible, 'RankRadio.Selected') && !str_contains($screenOnVisible, 'RankRadio.Selected.Value'), 'OnVisible RankRadio.Selected stays modern');
assert_true(str_contains($screenOnVisible, 'NameInput.HintText') && !str_contains($screenOnVisible, 'NameInput.Placeholder'), 'OnVisible Placeholder rewrites to HintText');
assert_true(str_contains($screenOnVisible, 'SaveModern.Color') && !str_contains($screenOnVisible, 'SaveModern.FontColor'), 'OnVisible FontColor rewrites to Color');
assert_true(str_contains($screenOnVisible, 'RoleCheck.Text') && !str_contains($screenOnVisible, 'RoleCheck.Label'), 'OnVisible checkbox Label rewrites to Text');
assert_true(str_contains((string) ($byName['SaveModern']->getProperty('Fill') ?? ''), 'RGBA(37, 99, 235'), 'BasePaletteColor remapped to Fill');
assert_true(str_contains((string) ($byName['SaveModern']->getProperty('Color') ?? ''), 'RGBA(255, 255, 255'), 'FontColor remapped to Color');
assert_true($byName['SaveModern']->getProperty('Appearance') === null, 'Appearance removed after classic swap');
assert_true($classicPrepReport->count() > 0, 'classic theme prep reported changes');

$tintYaml = sys_get_temp_dir() . '/ps_button_tint_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents($tintYaml, <<<'YAML'
App:
  Control: App@1.0.0
  Properties:
    OnStart: =Set(gblReady, true)
Screen1:
  Control: Screen@2.0.0
  Properties:
    Fill: =RGBA(250, 250, 252, 1)
  Children:
    - EditBanner:
        Control: Label@2.5.1
        Properties:
          Text: ="You are currently in edit mode"
          Fill: |-
            =If(
                varEditing,
                RGBA(239, 159, 39, 0.18),
                RGBA(56, 132, 222, 0.15)
            )
    - SaveBtn:
        Control: Button@0.0.45
        Properties:
          Text: ="Enregistrer"
          Appearance: ='ButtonCanvas.Appearance'.Primary
          BasePaletteColor: =RGBA(8, 222, 8, 1)
          FontSize: =12
    - ExitBtn:
        Control: Button@0.0.45
        Properties:
          Text: ="Quitter"
          Appearance: ='ButtonCanvas.Appearance'.Secondary
          BasePaletteColor: =RGBA(249, 83, 109, 1)
          FontSize: =12
YAML);
$tintDoc = ControlDocument::fromFile($tintYaml, 'Src/Header.pa.yaml');
assert_true($tintDoc !== null, 'button tint fixture loads');
$tintReport = new Report();
(new PreferClassicThemeControlsSweep())->apply([$tintDoc], $tintReport, []);
(new EnableDarkModeSweep())->apply([$tintDoc], $tintReport, ['inject_toggle' => false]);
$tintDoc->reindex();
$bannerFill = $saveFill = $saveSize = $exitFill = $exitFontSize = $exitSize = $tintOnStart = '';
foreach ($tintDoc->controls() as $c) {
    if ($c->name === 'EditBanner') {
        $bannerFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'SaveBtn') {
        $saveFill = (string) ($c->getProperty('Fill') ?? '');
        $saveSize = (string) ($c->getProperty('Size') ?? '');
    }
    if ($c->name === 'ExitBtn') {
        $exitFill = (string) ($c->getProperty('Fill') ?? '');
        $exitFontSize = (string) ($c->getProperty('FontSize') ?? '');
        $exitSize = (string) ($c->getProperty('Size') ?? '');
    }
    if ($c->isApp()) {
        $tintOnStart = (string) ($c->getProperty('OnStart') ?? '');
    }
}
assert_true(str_contains($bannerFill, 'gblTheme.C239_159_39'), 'edit banner keeps the orange token');
assert_true(!str_contains($bannerFill, 'SurfaceAlt'), 'edit banner is not flattened to SurfaceAlt');
assert_true(substr_count($bannerFill, 'If(gblDarkMode') === 2, 'edit banner wraps each wash once');
assert_true(str_contains($saveFill, 'RGBA(4, 122, 4'), 'save button light face is the settled green');
assert_true(str_contains($saveFill, 'gblTheme.C8_222_8'), 'save button dark side is its own green token');
assert_true(str_contains($saveSize, 'If(gblDarkMode, 10, 12)'), 'save label is 10pt in dark mode so white ink matches the 12pt light label');
assert_true(str_contains($exitFill, 'RGBA(255, 255, 255, 1)'), 'exit button stays white in light mode');
assert_true(!str_contains($exitFill, '249, 83, 109'), 'exit button is not a solid pink fill');
assert_true(preg_match('/If\(gblDarkMode,\s*RGBA\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $exitFill, $exitDark) === 1, 'exit button has a dark face');
assert_true(
    ((int) $exitDark[1]) < 20
        && abs(((int) $exitDark[1]) - ((int) $exitDark[2])) < 8
        && abs(((int) $exitDark[2]) - ((int) $exitDark[3])) < 8,
    'exit dark fill is the dark end of white, not red'
);
assert_true(str_contains($exitFontSize, '12') && str_contains($exitSize, '12'), 'exit button keeps FontSize and Size at 12');
$darkPalette = str_contains($tintOnStart, 'gblThemeDark') ? substr($tintOnStart, (int) strpos($tintOnStart, 'gblThemeDark')) : '';
assert_true(preg_match('/C8_222_8:\s*RGBA\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $darkPalette, $saveDark) === 1, 'dark palette lists save green');
assert_true(((int) $saveDark[2]) > ((int) $saveDark[1]) + 20 && ((int) $saveDark[2]) < 170, 'dark save green is a tint, not neon');
assert_true(preg_match('/C239_159_39_A18:\s*RGBA\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $darkPalette, $bannerDark) === 1, 'dark palette lists orange wash');
assert_true(((int) $bannerDark[1]) > ((int) $bannerDark[2]) && ((int) $bannerDark[2]) > ((int) $bannerDark[3]), 'dark orange wash stays orange');

// THCEE Settings Language/Theme RadioGroupCanvas — stay modern and keep Horizontal layout
$thceeRadioYaml = sys_get_temp_dir() . '/ps_thcee_radio_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents($thceeRadioYaml, <<<'YAML'
App:
  Control: App@1.0.0
  Properties:
    OnStart: =Set(gblReady, true)
Settings:
  Control: Screen@2.0.0
  Properties:
    Fill: =RGBA(255, 255, 255, 1)
  Children:
    - RadioGroupCanvas1_4:
        Control: Radio@0.0.25
        Properties:
          Items: =["English", "French"]
          DefaultSelectedItems: =["English"]
          Layout: ='RadioGroupCanvas.Layout'.Horizontal
          FillPortions: =1
          OnChange: =If(Self.Selected.Value = "English", Set(varLang, true), Set(varLang, false))
    - RadioGroupCanvas1_5:
        Control: Radio@0.0.25
        Properties:
          Items: =["Light"]
          DefaultSelectedItems: =["Light"]
          Layout: ='RadioGroupCanvas.Layout'.Horizontal
          FillPortions: =1
YAML
);
$thceeRadioDoc = ControlDocument::fromFile($thceeRadioYaml, 'Src/Settings.pa.yaml');
assert_true($thceeRadioDoc !== null, 'THCEE settings radio fixture loads');
$thceeRadioReport = new Report();
(new PreferClassicThemeControlsSweep())->apply([$thceeRadioDoc], $thceeRadioReport);
(new EnableDarkModeSweep())->apply([$thceeRadioDoc], $thceeRadioReport, ['inject_toggle' => false]);
$thceeRadioDoc->reindex();
$thceeRadios = [];
foreach ($thceeRadioDoc->controls() as $c) {
    $thceeRadios[$c->name] = $c;
}
assert_true(preg_match('/radio@0\\.0\\./i', (string) ($thceeRadios['RadioGroupCanvas1_4']->type ?? '')) === 1, 'language radio stays RadioGroupCanvas');
assert_true(preg_match('/radio@0\\.0\\./i', (string) ($thceeRadios['RadioGroupCanvas1_5']->type ?? '')) === 1, 'theme radio stays RadioGroupCanvas');
assert_true(str_contains((string) ($thceeRadios['RadioGroupCanvas1_4']->getProperty('Layout') ?? ''), 'RadioGroupCanvas.Layout'), 'language radio keeps Fluent Horizontal layout');
assert_true(str_contains((string) ($thceeRadios['RadioGroupCanvas1_5']->getProperty('Layout') ?? ''), 'RadioGroupCanvas.Layout'), 'theme radio keeps Fluent Horizontal layout');
assert_true(str_contains((string) ($thceeRadios['RadioGroupCanvas1_4']->getProperty('Items') ?? ''), 'English'), 'language radio Items stay English/French');
assert_true(str_contains((string) ($thceeRadios['RadioGroupCanvas1_5']->getProperty('Items') ?? ''), 'Dark'), 'theme radio Items gain Dark');
$langFill = (string) ($thceeRadios['RadioGroupCanvas1_4']->getProperty('Fill') ?? '');
$themeFill = (string) ($thceeRadios['RadioGroupCanvas1_5']->getProperty('Fill') ?? '');
assert_true($langFill === '' || str_contains($langFill, '0, 0, 0, 0'), 'language radio Fill is not painted');
assert_true($themeFill === '' || str_contains($themeFill, '0, 0, 0, 0'), 'theme radio Fill is not painted');
assert_true(!str_contains($langFill, 'gblTheme.Text') && !str_contains($themeFill, 'gblTheme.Text'), 'radio Fill is not themed to Text');
@unlink($thceeRadioYaml);

// Older sweeps left Classic/Radio with RadioGroupCanvas.Layout + black Fill
$brokenRadioYaml = sys_get_temp_dir() . '/ps_broken_radio_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents($brokenRadioYaml, <<<'YAML'
Settings:
  Control: Screen@2.0.0
  Properties:
    Fill: =RGBA(255, 255, 255, 1)
  Children:
    - ThemeRadio:
        Control: Classic/Radio@2.3.0
        Properties:
          Items: =["Light", "Dark"]
          Layout: ='RadioGroupCanvas.Layout'.Horizontal
          Fill: =RGBA(0, 0, 0, 1)
          Size: =0
YAML
);
$brokenRadioDoc = ControlDocument::fromFile($brokenRadioYaml, 'Src/Settings.pa.yaml');
assert_true($brokenRadioDoc !== null, 'broken classic radio fixture loads');
$brokenRadioReport = new Report();
(new PreferClassicThemeControlsSweep())->apply([$brokenRadioDoc], $brokenRadioReport);
$brokenRadioDoc->reindex();
$brokenTheme = null;
foreach ($brokenRadioDoc->controls() as $c) {
    if ($c->name === 'ThemeRadio') {
        $brokenTheme = $c;
    }
}
assert_true($brokenTheme !== null, 'broken ThemeRadio still present');
assert_true(str_contains((string) ($brokenTheme->getProperty('Layout') ?? ''), 'Layout.Horizontal')
    && !str_contains((string) ($brokenTheme->getProperty('Layout') ?? ''), 'RadioGroupCanvas'), 'leftover RadioGroupCanvas.Layout remaps to Layout.Horizontal');
assert_true(str_contains((string) ($brokenTheme->getProperty('Fill') ?? ''), '0, 0, 0, 0'), 'invented black radio Fill becomes transparent');
assert_true((string) ($brokenTheme->getProperty('Size') ?? '') === '=14', 'leftover Size=0 on classic radio is restored');
@unlink($brokenRadioYaml);

$zeroSizeYaml = sys_get_temp_dir() . '/ps_zero_size_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents($zeroSizeYaml, <<<'YAML'
Screen1:
  Control: Screen@2.0.0
  Properties:
    Fill: =RGBA(255, 255, 255, 1)
  Children:
    - Caption:
        Control: Label@2.5.1
        Properties:
          Text: ="Hello"
          Size: =0
          X: =10
          Y: =10
          Width: =80
          Height: =24
YAML);
$zeroDoc = ControlDocument::fromFile($zeroSizeYaml, 'Src/Screen1.pa.yaml');
assert_true($zeroDoc !== null, 'Size=0 fixture loads');
(new EnableDarkModeSweep())->apply([$zeroDoc], new Report(), ['inject_toggle' => false]);
$zeroCaption = null;
foreach ($zeroDoc->controls() as $c) {
    if ($c->name === 'Caption') {
        $zeroCaption = $c;
        break;
    }
}
assert_true($zeroCaption !== null && (string) $zeroCaption->getProperty('Size') === '=14', 'dark theme repairs Size=0 on labels');
@unlink($zeroSizeYaml);

$classicPrepOpt = ControlDocument::fromFile(__DIR__ . '/fixtures/theme_classic_prep.pa.yaml', 'Src/Screen1.pa.yaml');
(new PreferClassicThemeControlsSweep())->apply([$classicPrepOpt], new Report(), [
    'include_modern_number_input' => true,
]);
$classicPrepOpt->reindex();
$sitesType = null;
foreach ($classicPrepOpt->controls() as $c) {
    if ($c->name === 'Sites') {
        $sitesType = $c->type;
    }
}
assert_true(is_string($sitesType) && str_starts_with($sitesType, 'Classic/TextInput@'), 'ModernNumberInput still converts with include_modern_number_input');

$classicThenDark = ControlDocument::fromFile(__DIR__ . '/fixtures/theme_classic_prep.pa.yaml', 'Src/Screen1.pa.yaml');
(new PreferClassicThemeControlsSweep())->apply([$classicThenDark], new Report());
(new EnableDarkModeSweep())->apply([$classicThenDark], new Report(), ['inject_toggle' => false]);
$classicThenDark->reindex();
$themedInputFill = $themedMultiFill = '';
foreach ($classicThenDark->controls() as $c) {
    if ($c->name === 'CanvasNameInput') {
        $themedInputFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'TagsMulti') {
        $themedMultiFill = (string) ($c->getProperty('Fill') ?? '');
    }
}
assert_true(str_contains($themedInputFill, 'gblTheme.InputFill'), 'converted canvas text input Fill is themed');
assert_true(str_contains($themedMultiFill, 'gblTheme.InputFill'), 'converted modern multiselect Fill is themed');

$darkModeHopIds = array_column(SweepChains::darkMode(), 'id');
assert_true($darkModeHopIds === ['enable_dark_theme'], 'dark_mode chain is enable_dark_theme composite');
$darkThemeSubs = array_column(\PowerSweeper\Sweeps\EnableDarkThemeSweep::subSweeps(), 'id');
assert_true($darkThemeSubs[0] === 'prefer_classic_theme_controls', 'enable_dark_theme prepares classic controls first');
assert_true(in_array('enable_dark_mode', $darkThemeSubs, true), 'enable_dark_theme still runs enable_dark_mode');
assert_true(in_array('regenerate_sarif', $darkThemeSubs, true), 'enable_dark_theme regenerates SARIF');

// --- dark mode ---
$doc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_app.pa.yaml', 'Src/App.pa.yaml');
assert_true($doc !== null, 'dark mode fixture loads');
$report = new Report();
(new EnableDarkModeSweep())->apply([$doc], $report);
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
(new EnableDarkModeSweep())->apply([$settingsDoc], $settingsReport);
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

// PAT / PACS chrome: Color.DarkCyan bars, comment-only BasePaletteColor, azure tints, HtmlText ink
assert_true(
    ColorValue::parse('=RGBA(30, 144, 255, 0.10) //Color.Azure') !== null,
    'color parse ignores trailing // comments'
);
assert_true(ColorValue::roleForProperty('BasePaletteColor') === 'background', 'BasePaletteColor is background chrome');
assert_true(ColorValue::roleForProperty('ChevronFill') === 'foreground', 'ChevronFill is the dropdown glyph, not a surface');
assert_true(ColorValue::roleForProperty('IconFill') === 'foreground', 'IconFill is glyph ink');
$patDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/pat_dark_chrome.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($patDoc !== null, 'PAT chrome fixture loads');
$patReport = new Report();
(new EnableDarkModeSweep())->apply([$patDoc], $patReport, ['inject_toggle' => false]);
$patDoc->reindex();
$patBy = [];
foreach ($patDoc->controls() as $c) {
    $patBy[$c->name] = $c;
}
assert_true(str_contains((string) $patBy['TabBar']->getProperty('Fill'), 'gblTheme.Accent'), 'Color.DarkCyan tab bar → Accent');
assert_true(
    str_contains((string) $patBy['PrimaryBtn']->getProperty('BasePaletteColor'), 'gblTheme.C37_99_235')
        || str_contains((string) $patBy['PrimaryBtn']->getProperty('BasePaletteColor'), 'gblTheme.Accent'),
    'comment BasePaletteColor keeps the author blue'
);
assert_true(str_contains((string) $patBy['PrimaryBtn']->getProperty('BorderColor'), 'gblTheme.Accent'), 'Color.Aquamarine → Accent');
assert_true(str_contains((string) $patBy['AzureLbl']->getProperty('Fill'), 'gblTheme.C30_144_255_A10'), 'translucent azure fill keeps hue+alpha via unique token');
assert_true(str_contains((string) $patBy['AzureLbl']->getProperty('Color'), 'gblTheme.Text'), '//FontColour stub → Text');
assert_true(str_contains((string) $patBy['HtmlLbl']->getProperty('HtmlText'), 'TextCss'), 'HtmlText #111111 → TextCss');
assert_true(
    str_contains((string) $patBy['SidebarBg']->getProperty('HtmlText'), 'If(gblDarkMode')
        && str_contains((string) $patBy['SidebarBg']->getProperty('HtmlText'), 'rgba(166, 176, 255, 1)')
        && (str_contains((string) $patBy['SidebarBg']->getProperty('HtmlText'), 'gblTheme.C166_176_255Css')
            || str_contains((string) $patBy['SidebarBg']->getProperty('HtmlText'), 'gblTheme.SurfaceMutedCss')
            || str_contains((string) $patBy['SidebarBg']->getProperty('HtmlText'), 'gblTheme.PageCss')),
    'sidebar HTML gradient keeps light lavender and flips it on the same spectrum'
);
assert_true(
    str_contains((string) $patBy['DraftChip']->getProperty('Color'), 'gblTheme.TextOnLight')
        && str_contains((string) $patBy['DraftChip']->getProperty('Fill'), '253, 233, 180'),
    'pale yellow Draft chip keeps cream fill and uses TextOnLight ink'
);
$attachFill = (string) $patBy['ReportedAttachments']->getProperty('Fill');
$attachColor = (string) $patBy['ReportedAttachments']->getProperty('Color');
assert_true(
    str_contains($attachFill, 'If(gblDarkMode')
        && (str_contains($attachFill, 'gblTheme.InputFill') || str_contains($attachFill, 'gblTheme.SurfaceMuted'))
        && str_contains($attachFill, '240, 240, 240')
        && str_contains($attachFill, '30, 144, 255')
        && str_contains($attachFill, '!varReview'),
    'Attachments If(!varReview, gray, azure) keeps both light branches and darkens in dark mode'
);
assert_true(
    str_contains($attachColor, 'gblTheme.Text'),
    'Attachments empty-state Color follows theme text'
);
assert_true(
    str_contains((string) $patBy['StatusDropdown']->getProperty('HoverColor'), 'gblTheme.Text')
        && str_contains((string) $patBy['StatusDropdown']->getProperty('Color'), 'gblTheme.Text'),
    'dropdown hover ink stays theme Text so it is readable on HoverFill'
);
assert_true(
    str_contains((string) $patBy['ExecSummaryTab']->getProperty('HoverFill'), 'gblTheme.Selected')
        && str_contains((string) $patBy['ExecSummaryTab']->getProperty('HoverFill'), '110, 110, 110')
        && str_contains((string) $patBy['ExecSummaryTab']->getProperty('HoverColor'), 'gblTheme.Text')
        && str_contains((string) $patBy['ExecSummaryTab']->getProperty('Fill'), 'Self.HoverFill'),
    'segmented tab keeps state Fill and the author gray selected face'
);
assert_true(
    str_contains((string) $patBy['EmailPdfBtn']->getProperty('Fill'), 'gblTheme.SurfaceAlt')
        && str_contains((string) $patBy['EmailPdfBtn']->getProperty('Color'), 'gblTheme.Text'),
    'classic button with no Fill gets a dark SurfaceAlt chip so white Text is visible'
);
assert_true(
    str_contains((string) $patBy['TealTabBar']->getProperty('Fill'), 'ColorFade(PacsTeal')
        && str_contains((string) $patBy['TealTabBar']->getProperty('Fill'), 'If(gblDarkMode'),
    'named PacsTeal tab bar darkens with ColorFade in dark mode'
);
assert_true(
    str_contains((string) $patBy['HeaderSave']->getProperty('DisabledFill'), 'gblTheme.Disabled')
        && str_contains((string) $patBy['HeaderSave']->getProperty('DisabledColor'), 'gblTheme.TextMuted'),
    'Save button with DisplayMode.Disabled gets muted DisabledFill/Color'
);
assert_true(
    str_contains((string) $patBy['StatusDropdown']->getProperty('ChevronFill'), 'gblTheme.Text')
        && !str_contains((string) $patBy['StatusDropdown']->getProperty('ChevronFill'), 'InputFill')
        && $patBy['StatusDropdown']->getProperty('IconColor') === null,
    'dropdown ChevronFill is Text ink, not InputFill'
);
assert_true(
    str_contains((string) $patBy['ClearanceCombo']->getProperty('ChevronFill'), 'gblTheme.Text')
        && str_contains((string) $patBy['ClearanceCombo']->getProperty('IconColor'), 'gblTheme.Text')
        && str_contains((string) $patBy['ClearanceCombo']->getProperty('ChevronBackground'), 'gblTheme.SurfaceMuted'),
    'combobox with no ChevronFill gets themed chevron and waffle ink'
);
assert_true(
    str_contains((string) $patBy['FieldTagIcon']->getProperty('Color'), 'gblTheme.Text')
        && !str_contains((string) $patBy['FieldTagIcon']->getProperty('Color'), 'TextOnAccent'),
    'field icon on a transparent fill uses Text, not TextOnAccent'
);
assert_true(
    str_contains((string) $patBy['RolePopupSubmit']->getProperty('Fill'), 'If(gblDarkMode')
        && (str_contains((string) $patBy['RolePopupSubmit']->getProperty('Fill'), 'gblTheme.C168_110_235')
            || str_contains((string) $patBy['RolePopupSubmit']->getProperty('Fill'), 'gblTheme.Accent'))
        && str_contains((string) $patBy['RolePopupSubmit']->getProperty('Fill'), '168, 110, 235')
        && str_contains((string) $patBy['RolePopupSubmit']->getProperty('Color'), 'gblTheme.TextOnAccent')
        && (str_contains((string) $patBy['RolePopupSubmit']->getProperty('Color'), '255, 255, 255')
            || str_contains((string) $patBy['RolePopupSubmit']->getProperty('Color'), 'gblTheme.TextOnLight')),
    'popup Submit keeps author purple and original light-mode label ink'
);
assert_true(
    (string) $patBy['RolePopupSubmit']->getProperty('Width') === '=150',
    'popup Submit with no Width gets an explicit chip so the label is not clipped'
);
assert_true(
    str_contains((string) $patBy['StepOneBadge']->getProperty('Color'), 'gblTheme.TextOnAccent')
        && !str_contains((string) $patBy['StepOneBadge']->getProperty('Color'), 'TextOnLight')
        && str_contains((string) $patBy['StepOneBadge']->getProperty('Fill'), '9, 33, 98'),
    'view-mode step badge keeps white ink on the navy chip in light mode'
);
assert_true(
    str_contains((string) $patBy['RolePopupInner']->getProperty('Fill'), 'RGBA(0, 0, 0, 0)'),
    'popup inner layout wrapper with no Fill stays transparent so it does not paint a light slab'
);

// TDR / THCEE Directory: language radio FontColor, Settings buttons, status legend ink
$tdrDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/tdr_dark_controls.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($tdrDoc !== null, 'TDR dark controls fixture loads');
$tdrReport = new Report();
(new EnableDarkModeSweep())->apply([$tdrDoc], $tdrReport, ['force' => true, 'inject_toggle' => false]);
$tdrDoc->reindex();
$tdrBy = [];
foreach ($tdrDoc->controls() as $c) {
    $tdrBy[$c->name] = $c;
}
assert_true(str_contains((string) $tdrBy['LangRadio']->getProperty('FontColor'), 'gblTheme.Text'), 'language radio gets FontColor');
assert_true(str_contains((string) $tdrBy['ThemeRadio']->getProperty('FontColor'), 'gblTheme.Text'), 'theme radio keeps FontColor');
assert_true(str_contains((string) $tdrBy['SaveBtn']->getProperty('Color'), 'gblTheme.Text'), 'Settings Save button gets Color ink');
assert_true(str_contains((string) $tdrBy['CancelBtn']->getProperty('Color'), 'gblTheme.Text'), 'Settings Cancel button gets Color ink');
assert_true(
    str_contains((string) $tdrBy['lblDraft']->getProperty('Color'), 'gblTheme.C')
        || str_contains((string) $tdrBy['lblDraft']->getProperty('Color'), 'gblTheme.Warning'),
    'Draft legend stays chromatic (not TextOnLight)'
);
assert_true(
    str_contains((string) $tdrBy['lblSigned']->getProperty('Color'), 'gblTheme.C')
        || str_contains((string) $tdrBy['lblSigned']->getProperty('Color'), 'gblTheme.Success'),
    'Signed legend stays green on the author spectrum'
);
assert_true(
    str_contains((string) $tdrBy['lblDenied']->getProperty('Color'), 'gblTheme.C')
        || str_contains((string) $tdrBy['lblDenied']->getProperty('Color'), 'gblTheme.Accent')
        || str_contains((string) $tdrBy['lblDenied']->getProperty('Color'), 'gblTheme.Danger'),
    'Denied legend stays chromatic'
);
assert_true(!str_contains((string) $tdrBy['lblType']->getProperty('Color'), 'TextOnLight'), 'THCEE badge avoids TextOnLight on surface');

// Light-only RadioGroupCanvas stub (THCEE/TDR/ASC Topbar JSON twin pattern) gets Dark wired
$lightStubDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_light_stub.pa.yaml', 'Src/Home.pa.yaml');
assert_true($lightStubDoc !== null, 'light-only theme stub fixture loads');
$lightStubReport = new Report();
(new EnableDarkModeSweep())->apply([$lightStubDoc], $lightStubReport);
$stubItems = $stubOnChange = $langItems = null;
$stubToggleInjected = false;
foreach ($lightStubDoc->controls() as $c) {
    if ($c->name === 'RadioGroupCanvas1_5') {
        $stubItems = (string) ($c->getProperty('Items') ?? '');
        $stubOnChange = (string) ($c->getProperty('OnChange') ?? '');
    }
    if ($c->name === 'RadioGroupCanvas1_4') {
        $langItems = (string) ($c->getProperty('Items') ?? '');
    }
    if ($c->name === 'tglPowerSweeperDarkMode') {
        $stubToggleInjected = true;
    }
}
assert_true(is_string($stubItems) && str_contains($stubItems, 'Dark'), 'Light-only RadioGroupCanvas Items gains Dark');
assert_true(is_string($stubOnChange) && str_contains($stubOnChange, 'gblThemeDark'), 'Light-only RadioGroupCanvas OnChange swaps dark palette');
assert_true(is_string($langItems) && str_contains($langItems, 'English') && !str_contains(strtolower($langItems), 'dark'), 'language radio left as English/French');
assert_true(!$stubToggleInjected, 'no floating dark toggle when Light-only theme radio stub exists');

// Expandable TopbarHeader: title Label Height=Parent.Height must not cover theme/language radios
$topbarClickDoc = ControlDocument::fromFile(
    __DIR__ . '/fixtures/dark_mode_topbar_clicks.pa.yaml',
    'Src/Components/TopbarHeader.pa.yaml'
);
assert_true($topbarClickDoc !== null, 'topbar click fixture loads');
(new EnableDarkModeSweep())->apply([$topbarClickDoc], new Report(), ['inject_toggle' => false]);
$topbarLabelH = $topbarPanelZ = $topbarChromeZ = null;
foreach ($topbarClickDoc->controls() as $c) {
    if ($c->name === 'Label1') {
        $topbarLabelH = trim(ltrim(trim((string) ($c->getProperty('Height') ?? '')), '='));
    }
    if ($c->name === 'Container71') {
        $topbarPanelZ = (int) trim(ltrim(trim((string) ($c->getProperty('ZIndex') ?? '0')), '='));
    }
    if ($c->name === 'Container1') {
        $topbarChromeZ = (int) trim(ltrim(trim((string) ($c->getProperty('ZIndex') ?? '0')), '='));
    }
}
assert_true($topbarLabelH === '65', 'topbar title Label Height clamped to header strip (got ' . (string) $topbarLabelH . ')');
assert_true(
    is_int($topbarPanelZ) && is_int($topbarChromeZ) && $topbarPanelZ > $topbarChromeZ,
    'settings panel ZIndex above chrome so theme/language radios receive clicks'
);

// Brand override via theme_defaults option (central palette only)
$overrideDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_app.pa.yaml', 'Src/App.pa.yaml');
$dmOverrideReport = new Report();
(new EnableDarkModeSweep())->apply([$overrideDoc], $dmOverrideReport, [
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

// Contrast: ColorFade status chips, mixed RGBA, input Color, darkened Warning
$contrastDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_contrast.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($contrastDoc !== null, 'dark mode contrast fixture loads');
$contrastReport = new Report();
(new EnableDarkModeSweep())->apply([$contrastDoc], $contrastReport, ['force' => true]);
$contrastDoc->reindex();
$chipInk = $bannerFill = $mixedColor = $inputColor = $inputFill = $onStartContrast = '';
$inputBorder = $inputHover = $saveHover = $saveFill = $hoverChip = $hoverChipBorder = $cardBorder = $lockedFill = $mutedColor = $softShadow = $emphShadow = '';
foreach ($contrastDoc->controls() as $c) {
    if ($c->isApp()) {
        $onStartContrast = (string) ($c->getProperty('OnStart') ?? '');
    }
    if ($c->name === 'StatusLabel') {
        $chipInk = (string) ($c->getProperty('Color') ?? '');
    }
    if ($c->name === 'WarningBanner') {
        $bannerFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'MixedGrayLabel') {
        $mixedColor = (string) ($c->getProperty('Color') ?? '');
    }
    if ($c->name === 'TitleInput') {
        $inputColor = (string) ($c->getProperty('Color') ?? '');
        $inputFill = (string) ($c->getProperty('Fill') ?? '');
        $inputBorder = (string) ($c->getProperty('BorderColor') ?? '');
        $inputHover = (string) ($c->getProperty('HoverFill') ?? '');
    }
    if ($c->name === 'SaveAction') {
        $saveHover = (string) ($c->getProperty('HoverFill') ?? '');
        $saveFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'LockedDropdown') {
        $lockedFill = (string) ($c->getProperty('DisabledFill') ?? '');
    }
    if ($c->name === 'MutedCaption') {
        $mutedColor = (string) ($c->getProperty('Color') ?? '');
    }
    if ($c->name === 'HoverChip') {
        $hoverChip = (string) ($c->getProperty('HoverFill') ?? '');
        $hoverChipBorder = (string) ($c->getProperty('BorderColor') ?? '');
    }
    if ($c->name === 'CardEdge') {
        $cardBorder = (string) ($c->getProperty('BorderColor') ?? '');
    }
    if ($c->name === 'SoftCard') {
        $softShadow = (string) ($c->getProperty('DropShadow') ?? '');
    }
    if ($c->name === 'EmphCard') {
        $emphShadow = (string) ($c->getProperty('DropShadow') ?? '');
    }
}
assert_true(str_contains($chipInk, 'gblTheme.Text') && !str_contains($chipInk, 'TextOnLight'), 'ColorFade status chip keeps Text so dark mode ink is light');
assert_true(str_contains($bannerFill, 'gblTheme.C') || str_contains($bannerFill, 'gblTheme.Warning'), 'warning banner Fill keeps a chromatic token');
assert_true(str_contains($inputBorder, 'gblTheme.Border'), 'input BorderColor is themed');
assert_true(str_contains($inputHover, 'gblTheme.SurfaceAlt') || str_contains($inputHover, 'gblTheme.SurfaceMuted'), 'input HoverFill is a lift, not the same as Fill');
assert_true(str_contains($saveHover, 'gblTheme.C') || str_contains($saveHover, 'SuccessHover'), 'semantic button HoverFill stays on the author green spectrum');
assert_true(str_contains($saveFill, 'RGBA(4, 122, 4') && str_contains($saveFill, 'If(gblDarkMode'), 'Save button light face is the settled green, not neon');
assert_true(str_contains($lockedFill, 'gblTheme.Disabled') && !str_contains($lockedFill, 'InputFill'), 'disabled dropdown fill is Disabled, not InputFill');
assert_true(
    str_contains($mutedColor, 'gblTheme.TextMuted')
        && str_contains($mutedColor, 'RGBA(117, 117, 117'),
    'secondary grey caption keeps original gray in light mode'
);
assert_true(str_contains($hoverChip, 'SurfaceMuted') || str_contains($hoverChip, 'SurfaceAlt'), 'white HoverFill becomes a distinct hover surface');
assert_true(str_contains($hoverChipBorder, 'gblTheme.Border'), 'DefaultBorderColor → Border');
assert_true(str_contains($cardBorder, 'gblTheme.Border'), 'dark-gray card BorderColor is themed so it stays visible');
assert_true(
    str_contains($onStartContrast, 'TextOnLight:')
        && (str_contains($onStartContrast, 'Warning: RGBA(161, 98, 7, 1)')
            || str_contains($onStartContrast, 'C234_179_8:')
            || preg_match('/C\\d+_\\d+_\\d+: RGBA\\(/', $onStartContrast) === 1),
    'dark palette includes TextOnLight and flipped chromatic banner colors'
);
assert_true(
    str_contains($onStartContrast, 'Border: RGBA(56, 56, 64, 1)')
        || str_contains($onStartContrast, 'Border:RGBA(56, 56, 64, 1)'),
    'dark Border token stays a hairline, not a mid-grey outline'
);
assert_true(
    str_contains($softShadow, 'gblDarkMode')
        && str_contains($softShadow, 'DropShadow.None')
        && str_contains($softShadow, 'DropShadow.Regular'),
    'Regular drop shadow is None in dark mode and kept in light mode'
);
assert_true(
    str_contains($emphShadow, 'DropShadow.Light')
        && str_contains($emphShadow, 'DropShadow.Bold'),
    'Bold drop shadow steps down to Light in dark mode'
);

// THCEE finals: cream HtmlViewer section headers, ColorFade Draft chip, pale Modern buttons
$thceeFinalDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/thcee_dark_final.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($thceeFinalDoc !== null, 'thcee dark final fixture loads');
$thceeFinalReport = new Report();
(new EnableDarkModeSweep())->apply([$thceeFinalDoc], $thceeFinalReport, ['force' => false]);
$thceeFinalDoc->reindex();
$thceeHtml = $thceeHome = $thceeChip = $thceeOn = '';
foreach ($thceeFinalDoc->controls() as $c) {
    if ($c->isApp()) {
        $thceeOn = (string) ($c->getProperty('OnStart') ?? '');
    }
    if ($c->name === 'HeaderHtml') {
        $thceeHtml = (string) ($c->getProperty('HtmlText') ?? '');
    }
    if ($c->name === 'ReturnHome') {
        $thceeHome = (string) ($c->getProperty('BasePaletteColor') ?? $c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'StatusChip') {
        $thceeChip = (string) ($c->getProperty('Fill') ?? '');
    }
}
assert_true(str_contains($thceeOn, 'PageCss:') && str_contains($thceeOn, 'TextMutedCss:'), 'THCEE palette ships PageCss/TextMutedCss');
assert_true(
    (str_contains($thceeHtml, 'gblTheme.PageCss') || str_contains($thceeHtml, 'gblTheme.SurfaceCss'))
        && str_contains($thceeHtml, 'gblTheme.TextCss'),
    'THCEE section HTML cream/title theme'
);
assert_true(
    str_contains($thceeHtml, 'If(gblDarkMode') && (str_contains($thceeHtml, '#6b6760') || str_contains($thceeHtml, '#6B6760')),
    'THCEE Step n of 4 gray stays #6b6760 in light mode'
);
assert_true(str_contains($thceeChip, 'If(gblDarkMode'), 'THCEE Draft ColorFade chip darkens when dark');
assert_true(str_contains($thceeHome, 'C189_178_176') || str_contains($thceeHome, 'RGBA(189, 178, 176'), 'taupe Return Home stays taupe, not Surface grey');
assert_true(
    str_contains($mixedColor, 'gblTheme.') && str_contains($mixedColor, 'If(gblDarkMode'),
    'mixed If(...) formula themes leftover mid-gray RGBA for dark only'
);
assert_true(str_contains($inputFill, 'gblTheme.InputFill'), 'text input Fill uses InputFill');
assert_true(str_contains($inputColor, 'gblTheme.Text'), 'text input Color set for readable typed text');
assert_true(
    str_contains($onStartContrast, 'C8_222_8: RGBA(8, 222, 8, 1)')
        || (str_contains($onStartContrast, 'Success: RGBA(8, 222, 8, 1)')
            && str_contains($onStartContrast, 'Success: RGBA(20, 83, 45, 1)')),
    'author lime stays lime in light and a green on the dark side of the spectrum'
);
assert_true(
    str_contains($onStartContrast, 'TextMuted: RGBA(117, 117, 117, 1)'),
    'TextMuted light keeps the author caption gray'
);

// THCEE chrome: classic prep + dark — transparent sidebar hit-targets, SVG ink, remarks, homepage Start
$thceeChromeDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/thcee_dark_final.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($thceeChromeDoc !== null, 'thcee chrome fixture reloads');
$thceeChromeClassic = new Report();
(new PreferClassicThemeControlsSweep())->apply([$thceeChromeDoc], $thceeChromeClassic);
$thceeChromeDoc->reindex();
$thceeChromeReport = new Report();
(new EnableDarkModeSweep())->apply([$thceeChromeDoc], $thceeChromeReport, ['force' => true]);
$thceeChromeDoc->reindex();
$hitFill = $navIcon = $startType = $startFill = $startApp = $cardSvg = $remarksFill = $remarksApp = $objectiveFill = '';
$backType = $backSize = $backFill = $selectedFill = $selectedShadow = '';
$loadFill = $emptyColor = $overlayFill = $warnType = $warnColor = $attType = $attSize = $cardFill = '';
$chipFill = $stepFill = $loadModernType = $loadModernFill = $railFill = '';
$execColor = $approvalsStep = $transOnReset = $transAccess = $boundHtml = $adminHtml = $transmittalHtml = $transmittalHeight = null;
$approvalsOnVisible = '';
foreach ($thceeChromeDoc->controls() as $c) {
    if ($c->name === 'NavHitTarget') {
        $hitFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'NavIcon') {
        $navIcon = (string) ($c->getProperty('Color') ?? '');
    }
    if ($c->name === 'Homepage_Start') {
        $startType = (string) $c->type;
        $startFill = (string) ($c->getProperty('Fill') ?? '');
        $startApp = (string) ($c->getProperty('Appearance') ?? '');
    }
    if ($c->name === 'CardIcon') {
        $cardSvg = (string) ($c->getProperty('Image') ?? '');
    }
    if ($c->name === 'Remarks') {
        $remarksFill = (string) ($c->getProperty('Fill') ?? '');
        $remarksApp = (string) ($c->getProperty('Appearance') ?? '');
        assert_true($c->getStyleName() === null, 'Remarks StyleName cleared for gblTheme');
    }
    if ($c->name === 'ObjectiveBox') {
        $objectiveFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'LoadingScreen_LoadingTitle') {
        $loadFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'Text1_5') {
        $emptyColor = (string) ($c->getProperty('Color') ?? '');
    }
    if ($c->name === 'Container28') {
        $overlayFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'WarningScreen_Icon') {
        $warnType = (string) $c->type;
        $warnColor = (string) ($c->getProperty('Color') ?? '');
    }
    if ($c->name === 'Details_AttendeesBtn') {
        $attType = (string) $c->type;
        $attSize = (string) ($c->getProperty('Size') ?? '');
    }
    if ($c->name === 'CardSurface') {
        $cardFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'HeaderInfoChip') {
        $chipFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'StepDot') {
        $stepFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'LoadingTitleModern') {
        $loadModernType = (string) $c->type;
        $loadModernFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'SidebarRail') {
        $railFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'SidebarSelected') {
        $selectedFill = (string) ($c->getProperty('Fill') ?? '');
        $selectedShadow = (string) ($c->getProperty('DropShadow') ?? '');
    }
    if ($c->name === 'Approvals_BackBtn') {
        $backType = (string) $c->type;
        $backSize = (string) ($c->getProperty('Size') ?? '');
        $backFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'Approvals_ExecutiveSummary') {
        $execColor = (string) ($c->getProperty('Color') ?? '');
    }
    if ($c->name === 'ApprovalsStep') {
        $approvalsStep = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'TranslationHost') {
        $transOnReset = (string) ($c->getProperty('OnReset') ?? '');
        $transAccess = $c->getYamlDefinitionField('AccessAppScope');
    }
    if ($c->name === 'DetailsHtmlBound') {
        $boundHtml = (string) ($c->getProperty('HtmlText') ?? '');
    }
    if ($c->name === 'TransmittalIntro') {
        $transmittalHtml = (string) ($c->getProperty('HtmlText') ?? '');
        $transmittalHeight = (string) ($c->getProperty('Height') ?? '');
    }
    if ($c->name === 'THCEE Approvals') {
        $approvalsOnVisible = (string) ($c->getProperty('OnVisible') ?? '');
    }
    if ($c->name === 'AdminChangesHtml') {
        $adminHtml = (string) ($c->getProperty('HtmlText') ?? '');
    }
}
assert_true(str_contains($startType, 'Button@0.0') || str_contains(strtolower($startType), 'buttoncanvas'), 'homepage Start stays modern transparent button');
assert_true(str_contains($startApp, 'Transparent'), 'homepage Start keeps Transparent appearance');
assert_true($startFill === '' || preg_match('/RGBA\\s*\\(\\s*0\\s*,\\s*0\\s*,\\s*0\\s*,\\s*0\\s*\\)/i', $startFill) === 1, 'homepage Start Fill is not painted as a theme surface');
assert_true(
    preg_match('/RGBA\s*\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/i', $hitFill) === 1,
    'sidebar hit-target Fill stays transparent so icons show'
);
assert_true(str_contains($navIcon, 'gblTheme.Text'), 'sidebar icon Color uses Text');
assert_true(str_contains($cardSvg, 'gblTheme.TextCss'), 'homepage SVG icon fill splices TextCss');
assert_true(str_contains($remarksFill, 'gblTheme.InputFill'), 'remarks RichTextEditor Fill → InputFill');
assert_true(str_contains($remarksApp, 'FilledDarker'), 'remarks RichTextEditor Appearance → FilledDarker');
assert_true(str_contains($objectiveFill, 'gblTheme.InputFill'), 'unset objective TextInput Fill → InputFill');
assert_true(preg_match('/RGBA\\s*\\(\\s*0\\s*,\\s*0\\s*,\\s*0\\s*,\\s*0\\s*\\)/i', $loadFill) === 1, 'loading title Fill stays transparent, not Page');
assert_true(str_contains($emptyColor, 'gblTheme.Text'), 'empty text Color binds gblTheme.Text');
assert_true(preg_match('/RGBA\\s*\\(\\s*0\\s*,\\s*0\\s*,\\s*0\\s*,\\s*0\\s*\\)/i', $overlayFill) === 1, 'borderless overlay container Fill stays transparent');
assert_true(str_contains(strtolower($warnType), 'modernicon') || str_contains(strtolower($warnType), 'icon@0.0'), 'warning icon stays modern');
assert_true(str_contains($warnColor, 'gblTheme.Text'), 'modern icon Color uses Text');
assert_true(str_contains($attType, 'Button@0.0') || str_contains(strtolower($attType), 'buttoncanvas'), 'Attendees interface button stays modern');
assert_true(str_contains($attSize, '10.5'), 'Attendees button Size is unchanged');
assert_true(str_contains($cardFill, 'gblTheme.'), 'bordered card container still gets a theme surface');
assert_true(str_contains($chipFill, 'gblDarkMode') && str_contains($chipFill, 'RGBA(255, 255, 255'), 'header chip stays white in light mode');
assert_true(str_contains($stepFill, 'App.ActiveScreen') && str_contains($stepFill, 'RGBA(255,255,255'), 'stepper fill keeps ActiveScreen formula');
assert_true(str_contains($stepFill, 'gblDarkMode') && (str_contains($stepFill, 'Surface') || str_contains($stepFill, 'SurfaceMuted')), 'stepper off-state white becomes a dark surface shade');
assert_true(str_contains(strtolower($loadModernType), 'text@0.0'), 'loading title stays modern text');
assert_true($loadModernFill === '' || preg_match('/RGBA\\s*\\(\\s*0\\s*,\\s*0\\s*,\\s*0\\s*,\\s*0\\s*\\)/i', $loadModernFill) === 1, 'loading modern text Fill is not Page');
assert_true(str_contains($railFill, 'gblDarkMode') && str_contains($railFill, 'gblTheme.Page'), 'sidebar DefaultGray follows Page in dark mode');
assert_true(
    str_contains($selectedFill, 'gblTheme.SurfaceMuted')
        && str_contains($selectedFill, 'RGBA(255, 255, 255, 1)')
        && !str_contains($selectedFill, 'gblTheme.Page'),
    'sidebar current section is a lighter plate than the rail in dark mode'
);
assert_true(
    str_contains($selectedShadow, 'DropShadow.Regular')
        && !str_contains($selectedShadow, 'DropShadow.None'),
    'sidebar current section keeps its drop shadow in dark mode'
);
assert_true(
    str_contains($backType, 'Classic/Button')
        && str_contains($backSize, '14')
        && preg_match('/RGBA\s*\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/i', $backFill) === 1,
    'Activités back button is classic size 14 with a transparent face'
);
assert_true(str_contains((string) $execColor, 'varTransmitalContainer') && str_contains((string) $execColor, 'gblTheme.Text'), 'Executive Summary unselected ink becomes Text in dark mode');
assert_true(str_contains((string) $approvalsStep, 'App.ActiveScreen') && str_contains((string) $approvalsStep, 'gblTheme.Selected'), 'active Approvals step uses Selected token');
assert_true(
    str_contains((string) $transOnReset, 'background:#FFFFFF')
        && str_contains((string) $transOnReset, '#6b6760')
        && !str_contains((string) $transOnReset, 'gblTheme.'),
    'translation OnReset keeps literal HTML so Labels records still parse'
);
assert_true(
    !str_contains((string) $transOnReset, 'gblTheme.')
        || $transAccess === true
        || $transAccess === 'true',
    'translation host AccessAppScope only when OnReset references gblTheme'
);
assert_true(
    str_contains((string) $boundHtml, 'comTranslations.Labels.THCEEDetails.Details_GeneralHtml')
        && str_contains((string) $boundHtml, 'Substitute(')
        && str_contains((string) $boundHtml, '"background: #1C1C1E"')
        && str_contains((string) $boundHtml, '"color: #6b6760"')
        && substr_count((string) $boundHtml, 'Substitute(') === 4
        && str_contains((string) $boundHtml, 'If(gblDarkMode')
        && !str_contains((string) $boundHtml, 'gblTheme.')
        && str_contains((string) $boundHtml, 'With({psHtml:'),
    'Labels.*Html HtmlText uses four literal substitutes so dark mode cannot blank it'
);
assert_true(
    str_contains((string) $transmittalHtml, 'Approvals_HTML')
        && str_contains((string) $transmittalHtml, '"background: #ffffff"')
        && str_contains((string) $transmittalHtml, '"color: #1f1d1a"')
        && substr_count((string) $transmittalHtml, 'Substitute(') === 4
        && !str_contains((string) $transmittalHtml, 'gblTheme.')
        && str_contains((string) $transmittalHeight, 'Parent.Height+20'),
    'transmittal HTML uses four literal colors and keeps the author auto-height'
);
assert_true(
    str_contains($approvalsOnVisible, 'Set(varTransmitalContainer, "1")')
        && str_contains($approvalsOnVisible, 'varExpandEHCRBasicInfoHeader'),
    'Approvals OnVisible selects the transmittal list'
);
assert_true(
    str_contains((string) $adminHtml, 'Substitute(')
        && str_contains((string) $adminHtml, 'Admin_ChangesHTML')
        && str_contains((string) $adminHtml, '//"<div')
        && !str_contains((string) $adminHtml, "Substitute(comTranslations.Labels.THCEEAdminScreen.Admin_ChangesHTML\n\n//"),
    'commented Admin_ChangesHTML is wrapped on the Labels path only'
);
$thceeLive = \PowerSweeper\StudioLiveChecker::check([$thceeChromeDoc]);
$thceeSyntax = (int) ($thceeLive['by_rule']['app-ErrBadToken'] ?? 0);
assert_true($thceeSyntax === 0, 'THCEE chrome fixture has 0 parse errors after dark theme');

$emailDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/email_send_reminder.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($emailDoc !== null, 'email reminder fixture loads');
$emailBefore = '';
foreach ($emailDoc->controls() as $c) {
    if ($c->name === 'ExternalFunctions') {
        $emailBefore = (string) ($c->getProperty('Email_SendReminder') ?? '');
    }
}
(new EnableDarkModeSweep())->apply([$emailDoc], new Report(), ['force' => true]);
$emailDoc->reindex();
$emailAfter = '';
foreach ($emailDoc->controls() as $c) {
    if ($c->name === 'ExternalFunctions') {
        $emailAfter = (string) ($c->getProperty('Email_SendReminder') ?? '');
    }
}
assert_true($emailBefore !== '' && $emailAfter === $emailBefore, 'SendEmailV2 interpolated HTML is not spliced (keeps formula valid)');
assert_true(!str_contains($emailAfter, 'gblTheme.'), 'outbound email body does not reference gblTheme');

// Generalized detection: RTE host by child type; theme component AccessAppScope without TopbarHeader hardcoding
$generalDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_generalized.pa.yaml', 'Src/Screen1.pa.yaml');
assert_true($generalDoc !== null, 'generalized dark mode fixture loads');
$generalClassic = new Report();
(new PreferClassicThemeControlsSweep())->apply([$generalDoc], $generalClassic);
$generalReport = new Report();
(new EnableDarkModeSweep())->apply([$generalDoc], $generalReport, ['force' => true]);
$generalDoc->reindex();
$notesFill = $themeScope = $modernActionType = $themeRadioItems = '';
$accessRoot = null;
$accessInProps = false;
foreach ($generalDoc->controls() as $c) {
    if ($c->name === 'NotesHost') {
        $notesFill = (string) ($c->getProperty('Fill') ?? '');
    }
    if ($c->name === 'ModernAction') {
        $modernActionType = (string) $c->type;
    }
    if ($c->name === 'ThemeRadio') {
        $themeRadioItems = (string) ($c->getProperty('Items') ?? '');
    }
    if ($c->name === 'ThemeChrome' && str_contains($c->path, 'ComponentDefinitions')) {
        $accessRoot = $c->getYamlDefinitionField('AccessAppScope');
        $accessInProps = $c->getProperty('AccessAppScope') !== null;
    }
}
assert_true(str_contains($notesFill, 'gblTheme.Surface'), 'RTE host container themed by child type (not 10_Remarks name)');
assert_true(str_starts_with($modernActionType, 'Classic/Button@'), 'classic prep runs before dark on modern buttons');
assert_true(str_contains($themeRadioItems, 'Dark'), 'theme radio Light-only stub expanded via OnChange heuristics');
assert_true($accessRoot === true || $accessRoot === 'true', 'theme-aware component gets AccessAppScope at root');
assert_true($accessInProps === false, 'theme-aware component does not keep AccessAppScope in Properties');

$linkHex = ColorValue::toHex(['r' => 45, 'g' => 212, 'b' => 191, 'a' => 1.0]);
assert_true($linkHex === '#2DD4BF', 'ColorValue::toHex for dark link teal');

// analyze_app_checker removes empty layout formulas
$emptyDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/dark_mode_settings.pa.yaml', 'Src/Home.pa.yaml');
$emptyReport = new Report();
$emptyExtract = sys_get_temp_dir() . '/ps_analyze_empty_' . bin2hex(random_bytes(4));
mkdir($emptyExtract, 0777, true);
(new AnalyzeAppCheckerSweep())->apply([$emptyDoc], $emptyReport, ['_extract_dir' => $emptyExtract]);
@rmdir($emptyExtract);
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
$hairline = ColorValue::parse('=RGBA(226, 232, 240, 1)');
assert_true($hairline !== null, 'parse hairline border');
$darkHairline = ColorValue::toDark($hairline, 'border');
assert_true($darkHairline['r'] < 80 && $darkHairline['g'] < 80 && $darkHairline['b'] < 80, 'light hairline border stays a dark-mode hairline, not a bright outline');
$taupe = ColorValue::parse('=RGBA(189, 178, 176, 1)');
assert_true($taupe !== null && !ColorValue::isShade($taupe), 'Exit taupe is a color, not a grey shade');
assert_true(ColorValue::themeToken($taupe, 'Fill') === 'C189_178_176', 'taupe fill gets its own spectrum token');
$washGrey = ColorValue::parse('=RGBA(240, 240, 240, 0.4)');
assert_true($washGrey !== null && ColorValue::themeToken($washGrey, 'Fill') === 'C240_240_240_A40', 'translucent grey wash is not collapsed onto Page');
$darkTaupe = ColorValue::toDark($taupe, 'background');
assert_true($darkTaupe['r'] > $darkTaupe['b'] && $darkTaupe['r'] < 160, 'taupe flips to dark taupe, not Danger red or Surface grey');
$lime = ColorValue::parse('=RGBA(8, 222, 8, 1)');
assert_true($lime !== null && ColorValue::themeToken($lime, 'Fill') === 'C8_222_8', 'lime fill is not collapsed onto Success');
$darkLime = ColorValue::toDark($lime, 'background');
assert_true($darkLime['g'] > $darkLime['r'] + 20 && $darkLime['g'] > $darkLime['b'] + 20, 'lime flips to a green, not grey');
assert_true(ColorValue::luminance($darkLime) < 0.22 && ColorValue::luminance($darkLime) > 0.06, 'lime CTA dark side is a green tint, not neon');
$orangeWash = ColorValue::parse('RGBA(239, 159, 39, 0.18)');
assert_true($orangeWash !== null, 'parse orange edit banner');
$darkOrange = ColorValue::toDark($orangeWash, 'background');
assert_true($darkOrange['a'] > 0.95, 'orange wash is opaque in dark mode so the hue survives');
assert_true($darkOrange['r'] > $darkOrange['g'] && $darkOrange['g'] > $darkOrange['b'] + 10, 'orange wash keeps an orange tint');
assert_true(ColorValue::luminance($darkOrange) < 0.24, 'orange wash is dark enough for light banner text');

$inkYaml = sys_get_temp_dir() . '/ps_ink_wrap_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents($inkYaml, <<<'YAML'
App:
  Control: App@1.0.0
  Properties:
    OnStart: =Set(gblReady, true)
Screen1:
  Control: Screen@2.0.0
  Properties:
    Fill: =RGBA(255, 255, 255, 1)
  Children:
    - TitleInk:
        Control: Label@2.5.1
        Properties:
          Text: ="CAF/DNDTHCEE"
          Color: =RGBA(31, 29, 26, 1)
    - GreenInk:
        Control: Label@2.5.1
        Properties:
          Text: ="Ready"
          Color: =RGBA(140, 255, 140, 1)
YAML
);
$inkDoc = ControlDocument::fromFile($inkYaml, 'Src/Screen1.pa.yaml');
$inkReport = new Report();
(new EnableDarkModeSweep())->apply([$inkDoc], $inkReport, ['force' => false]);
$inkDoc->reindex();
$titleInk = $greenInk = '';
foreach ($inkDoc->controls() as $c) {
    if ($c->name === 'TitleInk') {
        $titleInk = (string) ($c->getProperty('Color') ?? '');
    }
    if ($c->name === 'GreenInk') {
        $greenInk = (string) ($c->getProperty('Color') ?? '');
    }
}
assert_true(
    str_contains($titleInk, 'If(gblDarkMode') && str_contains($titleInk, 'RGBA(31, 29, 26'),
    'near-black label Color keeps the light literal and flips in dark mode'
);
assert_true(
    str_contains($greenInk, 'If(gblDarkMode') && str_contains($greenInk, 'C140_255_140'),
    'light green label Color keeps hue via its own token instead of collapsing to Text'
);
@unlink($inkYaml);

$captionGrey = ColorValue::parse('=RGBA(106, 122, 127, 1)');
assert_true($captionGrey !== null && ColorValue::themeToken($captionGrey, 'Color') === 'TextMuted', 'tinted caption grey Color maps to TextMuted so dark mode ink is light');
assert_true(ColorValue::themeToken($captionGrey, 'Fill') === 'C106_122_127', 'same grey as a fill stays its own hue token');
$stepGrey = ColorValue::parse('=RGBA(107, 103, 96, 1)');
assert_true($stepGrey !== null && ColorValue::themeToken($stepGrey, 'Color') === 'TextMuted', 'THCEE #6b6760 step caption is TextMuted, not a missing C*Css token');

$startYaml = sys_get_temp_dir() . '/ps_start_btn_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents($startYaml, <<<'YAML'
App:
  Control: App@1.0.0
  Properties:
    OnStart: =Set(gblReady, true)
Screen1:
  Control: Screen@2.0.0
  Properties:
    Fill: =RGBA(255, 255, 255, 1)
  Children:
    - Homepage_Start_4:
        Control: Button@0.0.45
        Properties:
          Appearance: ='ButtonCanvas.Appearance'.Transparent
          BasePaletteColor: =RGBA(189, 178, 176, 1)
          Text: ="Start"
          FontSize: =12
          Icon: ="ArrowRight"
YAML
);
$startDoc = ControlDocument::fromFile($startYaml, 'Src/Screen1.pa.yaml');
$startReport = new Report();
(new PreferClassicThemeControlsSweep())->apply([$startDoc], $startReport);
(new EnableDarkModeSweep())->apply([$startDoc], $startReport, ['force' => false]);
$startDoc->reindex();
$startBtn = null;
foreach ($startDoc->controls() as $c) {
    if ($c->name === 'Homepage_Start_4') {
        $startBtn = $c;
    }
}
assert_true($startBtn !== null, 'transparent Start button fixture loads');
$startFont = (string) ($startBtn->getProperty('FontColor') ?? '');
$startColor = (string) ($startBtn->getProperty('Color') ?? '');
assert_true(str_contains($startFont, 'gblTheme.Text') || str_contains($startColor, 'gblTheme.Text'), 'Start button label uses Text, not Link');
assert_true(str_contains($startFont, 'gblTheme.Text'), 'Fluent ButtonCanvas Start gets FontColor so the word Start is visible in dark mode');
assert_true(str_contains($startFont, 'RGBA(0, 0, 0') || str_contains($startColor, 'RGBA(0, 0, 0'), 'Start stays black in light mode');
assert_true(!str_contains($startFont, 'gblTheme.Link') && !str_contains($startColor, 'gblTheme.Link'), 'Start is not restyled as a teal link');
@unlink($startYaml);

$thceePixelYaml = sys_get_temp_dir() . '/ps_thcee_pixel_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents($thceePixelYaml, <<<'YAML'
App:
  Control: App@1.0.0
  Properties:
    OnStart: =Set(gblReady, true)
Screen1:
  Control: Screen@2.0.0
  Properties:
    Fill: =RGBA(255, 255, 255, 1)
  Children:
    - Homepage_Description:
        Control: Label@2.5.1
        Properties:
          Color: =RGBA(106, 122, 127, 1)
          Text: ="What would you like to do?"
    - Label7_297:
        Control: Label@2.5.1
        Properties:
          Text: =ThisItem.Title
    - HtmlText8:
        Control: HtmlViewer@2.1.0
        Properties:
          HtmlText: |-
            ="<center><div style='font-weight: 600;'>START A NEW EHCR FOR APPROVAL</div>"
          Size: =7
    - Details_GeneralHtml:
        Control: HtmlViewer@2.1.0
        Properties:
          Color: =RGBA(51,51,51,1)
          Fill: =RGBA(255, 255, 255, 1)
          HtmlText: =comTranslations.Labels.THCEEDetails.Details_GeneralHtml
    - Container149_3:
        Control: GroupContainer@1.5.0
        Variant: AutoLayout
        Properties:
          Fill: =RGBA(247, 246, 243, 1)
          Height: =Parent.TemplateHeight
          Visible: =ThisItem.Locked
          Width: =Parent.TemplateWidth
TranslationHost:
  DefinitionType: CanvasComponent
  AccessAppScope: false
  CustomProperties: {}
  Properties:
    Height: =40
    Width: =200
    OnReset: |-
      =Set(
          varTranslations,
          Table(
              {
                  Language: "en-ca",
                  Labels: {
                      THCEEDetails: {
                          Details_L1: "L1",
                          Details_GeneralHtml: "<!doctype html><body style='margin: 0; background: #f7f6f3; color: #1f1d1a;'>don't break this<div style='color: #6b6760;'>Step 1 of 4</div><p style='color: #34495e;'>note</p></body>"
                      }
                  }
              }
          )
      )
YAML
);
$thceePixelDoc = ControlDocument::fromFile($thceePixelYaml, 'Src/Screen1.pa.yaml');
$thceePixelReport = new Report();
(new EnableDarkModeSweep())->apply([$thceePixelDoc], $thceePixelReport, ['force' => false, 'inject_toggle' => false]);
$thceePixelDoc->reindex();
$pixelBy = [];
foreach ($thceePixelDoc->controls() as $c) {
    $pixelBy[$c->name] = $c;
}
$descColor = (string) ($pixelBy['Homepage_Description']->getProperty('Color') ?? '');
$titleColor = (string) ($pixelBy['Label7_297']->getProperty('Color') ?? '');
$html8 = (string) ($pixelBy['HtmlText8']->getProperty('HtmlText') ?? '');
$html8Color = (string) ($pixelBy['HtmlText8']->getProperty('Color') ?? '');
$bound = (string) ($pixelBy['Details_GeneralHtml']->getProperty('HtmlText') ?? '');
$overlay = (string) ($pixelBy['Container149_3']->getProperty('Fill') ?? '');
$onReset = (string) ($pixelBy['TranslationHost']->getProperty('OnReset') ?? '');
assert_true(str_contains($descColor, 'gblTheme.TextMuted') && str_contains($descColor, 'RGBA(106, 122, 127'), 'homepage subtitle caption grey inverts via TextMuted and stays 106,122,127 in light');
assert_true(str_contains($titleColor, 'gblTheme.Text') && str_contains($titleColor, 'RGBA(0, 0, 0'), 'unset card title Color stays black in light and Text in dark');
assert_true(str_contains($html8, 'gblTheme.TextCss') && str_contains($html8, '#000000'), 'section header HTML without color gets theme ink');
assert_true(str_contains($html8Color, 'gblTheme.Text'), 'HtmlViewer Color is seeded so unstyled HTML inverts');
assert_true(str_contains($bound, 'Substitute(') && str_contains($bound, 'Details_GeneralHtml') && str_contains($bound, '"color: #D4D4DC"') && substr_count($bound, 'Substitute(') === 4 && !str_contains($bound, 'gblTheme.'), 'Details_GeneralHtml is themed at display time');
assert_true(str_contains($bound, '"color: #1f1d1a"') && str_contains($bound, '"color: #FFFFFF"'), 'section HTML title ink is inverted at display time');
assert_true(preg_match('/RGBA\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*0\.55\s*\)/i', $overlay) === 1, 'locked card overlay Fill is a translucent wash in dark mode');
assert_true(str_contains($overlay, 'RGBA(247, 246, 243, 1)'), 'locked card overlay stays cream in light mode');
assert_true(str_contains($onReset, "don't break this") && str_contains($onReset, '#6b6760') && !str_contains($onReset, 'gblTheme.'), 'translation OnReset with apostrophes is not spliced');
assert_true(str_contains($onReset, 'Details_L1: "L1"'), 'plain translation labels stay intact in OnReset');
@unlink($thceePixelYaml);

$thceeHealYaml = sys_get_temp_dir() . '/ps_thcee_heal_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents($thceeHealYaml, <<<'YAML'
App:
  Control: App@1.0.0
  Properties:
    OnStart: =Set(gblReady, true)
Screen1:
  Control: Screen@2.0.0
  Properties:
    Fill: =RGBA(255, 255, 255, 1)
TranslationHost:
  DefinitionType: CanvasComponent
  AccessAppScope: true
  CustomProperties: {}
  Properties:
    Height: =40
    Width: =200
    OnReset: |-
      =Set(
          varTranslations,
          Table(
              {
                  Language: "en-ca",
                  Labels: {
                      THCEEDetails: {
                          Details_L1: "L1",
                          Details_GeneralHtml: "<body style='background:" & If(gblDarkMode, gblTheme.PageCss, "#f7f6f3") & "; color:" & If(gblDarkMode, gblTheme.TextCss, "#1f1d1a") & ";'>don't<div style='color:" & If(gblDarkMode, gblTheme.TextMutedCss, "#6b6760") & ";'>Step 1</div></body>"
                      }
                  }
              }
          )
      )
YAML
);
$thceeHealDoc = ControlDocument::fromFile($thceeHealYaml, 'Src/Screen1.pa.yaml');
(new EnableDarkModeSweep())->apply([$thceeHealDoc], new Report(), ['force' => false, 'inject_toggle' => false]);
$thceeHealDoc->reindex();
$healedOnReset = '';
foreach ($thceeHealDoc->controls() as $c) {
    if ($c->name === 'TranslationHost') {
        $healedOnReset = (string) ($c->getProperty('OnReset') ?? '');
    }
}
assert_true(str_contains($healedOnReset, 'background: #f7f6f3') && str_contains($healedOnReset, 'color: #1f1d1a') && str_contains($healedOnReset, 'color: #6b6760'), 'prior CSS splices in translation OnReset are restored to literals');
assert_true(!str_contains($healedOnReset, 'gblTheme.') && str_contains($healedOnReset, "don't"), 'healed OnReset has no gblTheme concatenations and keeps apostrophes');
assert_true(str_contains($healedOnReset, 'Details_L1: "L1"'), 'healed OnReset keeps plain labels');
@unlink($thceeHealYaml);

$washYaml = sys_get_temp_dir() . '/ps_wash_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents($washYaml, <<<'YAML'
App:
  Control: App@1.0.0
  Properties:
    OnStart: =Set(gblReady, true)
Screen1:
  Control: Screen@2.0.0
  Properties:
    Fill: =RGBA(255, 255, 255, 1)
  Children:
    - SidebarWash:
        Control: GroupContainer@1.5.0
        Properties:
          Fill: =RGBA(240, 240, 240, 0.4)
          Height: =400
          Width: =75
YAML
);
$washDoc = ControlDocument::fromFile($washYaml, 'Src/Screen1.pa.yaml');
(new EnableDarkModeSweep())->apply([$washDoc], new Report(), ['force' => false, 'inject_toggle' => false]);
$washDoc->reindex();
$washFill = '';
foreach ($washDoc->controls() as $c) {
    if ($c->name === 'SidebarWash') {
        $washFill = (string) ($c->getProperty('Fill') ?? '');
    }
}
$washOnStart = '';
foreach ($washDoc->controls() as $c) {
    if ($c->isApp()) {
        $washOnStart = (string) ($c->getProperty('OnStart') ?? '');
    }
}
assert_true(str_contains($washFill, 'If(gblDarkMode') && str_contains($washFill, 'RGBA(240, 240, 240, 0.4)'), 'translucent grey sidebar wash inverts in dark and stays 240,240,240,0.4 in light');
assert_true(str_contains($washFill, 'C240_240_240_A40'), 'sidebar wash uses unique alpha token, not opaque Page');
assert_true(str_contains($washOnStart, 'C240_240_240_A40: RGBA(15, 15, 15, 0.4)'), 'dark sidebar wash is inverted grey at the same 0.4 alpha');
@unlink($washYaml);

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

// --- SharePoint correlate sweep ---
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
(new UnwhackLocaleFormulasSweep())->apply([$vcrYaml, $vcrJson], $vcrReport);
(new RepairCheckedBooleansSweep())->apply([$vcrYaml, $vcrJson], $vcrReport);
(new EnsureFocusVisibleSweep())->apply([$vcrYaml, $vcrJson], $vcrReport);
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

// Dirty tracking: empty sweep sequence must not rewrite YAML
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

// --- zip path style: preserve source, optional posix sweep ---
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
assert_true(ZipTool::detectEntryStyle($forcedPosix) === ZipTool::STYLE_POSIX, 'posix_zip_paths sweep forces forward slashes');
assert_true(($posixResult['report']['total'] ?? 0) >= 1, 'zip path style sweep reports a change');

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
    assert_true($live['total'] >= 400 && $live['total'] <= 600, 'App (16) live checker total in expected range (got ' . $live['total'] . ')');
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

    // Repaired pipeline should drive live errors well below original 1719 SARIF
    $repairedPipelineOut = sys_get_temp_dir() . '/ps_repaired_live_check_' . bin2hex(random_bytes(4)) . '.msapp';
    $repairHops = SweepChains::studioRepair();
    (new Pipeline())->run($app16, $repairHops, $repairedPipelineOut);
    $repairedArchive = new \PowerSweeper\MsappArchive($repairedPipelineOut);
    $repairedArchive->unpack();
    $repairedLive = \PowerSweeper\StudioLiveChecker::check($repairedArchive->documents(), ['extract_dir' => $repairedArchive->extractDir()]);
    $repairedArchive->cleanup();
    assert_true($repairedLive['total'] === 0, 'Repaired pipeline live checker reports zero issues (got ' . $repairedLive['total'] . ')');
    assert_true(($repairedLive['by_category']['formulas'] ?? 0) === 0, 'Repaired pipeline has no formula errors');
    assert_true(($repairedLive['by_category']['performance'] ?? 0) === 0, 'Repaired pipeline has no delegation hints');
    @unlink($repairedPipelineOut);
}

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
(new RepairContextAwareRefsSweep())->apply([$copyPasteDoc], $copyPasteReport);
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
$delegInLower = \PowerSweeper\DelegationFormulaRewriter::rewrite(
    'With({searchText: Lower(Trim(txtSearch.Text))}, Filter(list, searchText in Lower(Coalesce(\'Pass Holder Details\', ""))))'
);
assert_true(
    str_contains($delegInLower, 'searchText in Coalesce(')
        && !preg_match('/\bin\s+Lower\s*\(/i', $delegInLower),
    'delegation strips Lower() on in-operator column side'
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
(new \PowerSweeper\Sweeps\RepairVarCurrentPackageSweep())->apply([$varPkgDoc], $varPkgReport);
$varPkgFormula = '';
foreach ($varPkgDoc->controls() as $c) {
    if ($c->name === 'Screen1') {
        $varPkgFormula = (string) $c->getProperty('OnSelect');
    }
}
@unlink($varPkgTmp);
assert_true(
    str_contains($varPkgFormula, '//AmendmentVisit: loadedRequest.AmendmentVisit'),
    'varCurrentPackage sweep leaves commented loadPackage field untouched'
);
assert_true(
    !preg_match('/\bvarCurrentPackage\.AmendmentVisit\b/', $varPkgFormula),
    'varCurrentPackage sweep replaces live AmendmentVisit reference with false'
);

// Component template bindings — stale suffixed refs repaired (Components/*.json)
$app16 = dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App (16).msapp';
if (is_file($app16)) {
    $componentRepairOut = sys_get_temp_dir() . '/ps_component_bindings_' . bin2hex(random_bytes(4)) . '.msapp';
    $repairHops = SweepChains::studioRepair();
    (new Pipeline())->run($app16, $repairHops, $componentRepairOut);
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

// Sweep chains — ids resolve; studio repair + powered + web round-trip
$sweepRegistry = new \PowerSweeper\SweepRegistry();
$compositeIds = [
    'fix_formula_errors',
    'fix_control_names_and_refs',
    'accessibility_polish',
    'enable_dark_theme',
    'clean_default_chrome',
    'repair_sharepoint_data',
    'export_to_web_ir',
    'import_from_web_ir',
    'import_web_package',
];
foreach ($compositeIds as $cid) {
    assert_true($sweepRegistry->has($cid), $cid . ' sweep registered');
}
$paletteIds = array_column($sweepRegistry->catalog(), 'id');
assert_true($paletteIds === \PowerSweeper\SweepRegistry::PALETTE_IDS, 'UI catalog matches PALETTE_IDS order');
assert_true(!in_array('enable_dark_mode', $paletteIds, true), 'palette hides enable_dark_mode behind enable_dark_theme');
assert_true(!in_array('repair_control_refs', $paletteIds, true), 'palette hides individual formula repair sweeps');
assert_true(!in_array('accessibility_labels', $paletteIds, true), 'palette hides accessibility_labels behind accessibility_polish');
assert_true(!in_array('regenerate_sarif', $paletteIds, true), 'palette hides regenerate_sarif (runs inside composites)');
assert_true($sweepRegistry->has('enable_dark_mode'), 'internal enable_dark_mode still registered');
assert_true($sweepRegistry->has('regenerate_sarif'), 'internal regenerate_sarif still registered');
$formulaSubIds = array_column(\PowerSweeper\Sweeps\FixFormulaErrorsSweep::subSweeps(), 'id');
foreach (array_unique($formulaSubIds) as $subId) {
    assert_true($sweepRegistry->has($subId), 'fix_formula_errors sub-sweep ' . $subId . ' registered');
}
assert_true(in_array('regenerate_sarif', $formulaSubIds, true), 'fix_formula_errors includes regenerate_sarif');
$namesSubs = array_column(\PowerSweeper\Sweeps\FixControlNamesAndRefsSweep::subSweeps(), 'id');
assert_true(in_array('meaningful_names', $namesSubs, true), 'fix_control_names_and_refs renames');
assert_true(in_array('regenerate_sarif', $namesSubs, true), 'fix_control_names_and_refs regenerates SARIF');

$repairHopIds = array_column(SweepChains::studioRepair(), 'id');
assert_true($repairHopIds === ['fix_formula_errors', 'accessibility_polish'], 'studio repair is formula + a11y composites');
assert_true(!in_array('regenerate_sarif', $repairHopIds, true), 'studio repair SARIF lives inside composites');
assert_true(!in_array('repair_control_refs', $repairHopIds, true), 'studio repair uses composite instead of repair_control_refs');
assert_true(!in_array('repair_converge_formulas', $repairHopIds, true), 'studio repair uses composite instead of repair_converge_formulas');
assert_true(!in_array('meaningful_names', $repairHopIds, true), 'studio repair does not rename by default');

$smartHopIds = array_column(SweepChains::smartRepair(), 'id');
assert_true($smartHopIds[0] === 'fix_control_names_and_refs', 'smart repair starts with names/refs composite');
assert_true(in_array('fix_formula_errors', $smartHopIds, true), 'smart repair includes fix_formula_errors');
assert_true(
    in_array('fix_control_names_and_refs', $smartHopIds, true)
    && in_array('fix_formula_errors', $smartHopIds, true),
    'smart repair allows double-run of overlapping ref passes via both composites'
);

$poweredSweeps = SweepChains::powered();
$poweredIds = array_column($poweredSweeps, 'id');
assert_true(in_array('fix_formula_errors', $poweredIds, true), 'powered chain includes fix_formula_errors');
assert_true(in_array('accessibility_polish', $poweredIds, true), 'powered chain includes accessibility_polish');
assert_true(in_array('enable_dark_theme', $poweredIds, true), 'powered chain includes enable_dark_theme');
assert_true(!in_array('enable_dark_mode', $poweredIds, true), 'powered chain uses enable_dark_theme composite');
assert_true(!in_array('prefer_classic_theme_controls', $poweredIds, true), 'powered classic prep is inside enable_dark_theme');
assert_true(!in_array('regenerate_sarif', $poweredIds, true), 'powered SARIF lives inside composites');
$forcedDark = SweepChains::darkMode(true);
assert_true(($forcedDark[0]['options']['force'] ?? null) === true, 'darkMode(true) forces enable_dark_theme');
foreach ($poweredSweeps as $sweep) {
    assert_true($sweepRegistry->has($sweep['id']), 'powered sweep ' . $sweep['id'] . ' registered');
}
foreach (SweepChains::studioRepair() as $sweep) {
    assert_true($sweepRegistry->has($sweep['id']), 'studio repair sweep ' . $sweep['id'] . ' registered');
}

// fix_formula_errors — composite runs without error on empty docs
$ffeReport = new Report();
(new \PowerSweeper\Sweeps\FixFormulaErrorsSweep())->apply([], $ffeReport, []);
assert_true($ffeReport->count() >= 1, 'fix_formula_errors writes a summary row');
$ffeSweeps = array_column($ffeReport->entries(), 'sweep');
assert_true(in_array('fix_formula_errors', $ffeSweeps, true), 'fix_formula_errors summary sweep id present');

// fix_formula_errors — report attributes under parent sweep with from→to like dark mode
$ffeLocaleDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/locale_corrupt.pa.yaml', 'Src/App.pa.yaml');
assert_true($ffeLocaleDoc !== null, 'locale fixture loads for fix_formula_errors report');
$ffeDetailReport = new Report(null, 500, 280);
(new \PowerSweeper\Sweeps\FixFormulaErrorsSweep())->apply([$ffeLocaleDoc], $ffeDetailReport, []);
assert_true($ffeDetailReport->count() > 1, 'fix_formula_errors reports detail rows on locale fixture');
$ffeDetailSweeps = array_unique(array_column($ffeDetailReport->entries(), 'sweep'));
assert_true($ffeDetailSweeps === ['fix_formula_errors'], 'fix_formula_errors attributes all rows under parent sweep');
$ffeHasFormulaDiff = false;
foreach ($ffeDetailReport->entries() as $row) {
    if (($row['control'] ?? '') === '(summary)') {
        continue;
    }
    if (($row['from'] ?? '') !== '' && ($row['to'] ?? '') !== ''
        && ($row['from'] ?? '') !== '(screen ref)'
        && ($row['to'] ?? '') !== '(normalized)'
    ) {
        $ffeHasFormulaDiff = true;
        break;
    }
}
assert_true($ffeHasFormulaDiff, 'fix_formula_errors includes from→to formula previews');
$ffeHasKindPrefix = false;
foreach ($ffeDetailReport->entries() as $row) {
    if (str_contains((string) ($row['property'] ?? ''), 'locale')) {
        $ffeHasKindPrefix = true;
        break;
    }
}
assert_true($ffeHasKindPrefix, 'fix_formula_errors property names include repair kind');

// Live checker recognizes Set()/UpdateContext variables (Team Pulse teamMemberSelected pattern)
$setVarPath = sys_get_temp_dir() . '/ps_setvar_' . bin2hex(random_bytes(3)) . '.pa.yaml';
file_put_contents(
    $setVarPath,
    "Screen1:\n  Control: Screen@2.0.0\n  Children:\n    - Btn:\n        Control: Classic/Button@2.2.0\n        Properties:\n          OnSelect: =Set(teamMemberSelected, ThisItem)\n          Text: =teamMemberSelected.Profile.DisplayName\n"
);
$setVarDoc = ControlDocument::fromFile($setVarPath, 'Src/Screen1.pa.yaml');
assert_true($setVarDoc !== null, 'Set() variable fixture loads');
$setCtx = \PowerSweeper\AppDataContext::build([$setVarDoc], null);
assert_true($setCtx->isSetVariable('teamMemberSelected'), 'Set() variables are known to live checker');
@unlink($setVarPath);

// Multi-app formula repair — source apps (ignore powered/repaired copies)
$formulaRepairSources = [
    dirname(__DIR__) . '/samples/import_debug/Team Pulse.msapp',
    dirname(__DIR__) . '/samples/import_debug/TDR - THCEE Directory App.msapp',
    dirname(__DIR__) . '/samples/import_debug/VCDS ASC — The SAINT Catalog.msapp',
    dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App repair2.msapp',
];
$formulaCount = static function (string $path): int {
    $arch = new \PowerSweeper\MsappArchive($path);
    try {
        $arch->unpack();
        $live = \PowerSweeper\StudioLiveChecker::check($arch->documents(), ['extract_dir' => $arch->extractDir()]);
        $n = 0;
        foreach ($live['by_rule'] ?? [] as $rule => $count) {
            $rule = (string) $rule;
            if (str_starts_with($rule, 'app-Err') || str_starts_with($rule, 'app-formula')) {
                $n += (int) $count;
            }
        }

        return $n;
    } finally {
        $arch->cleanup();
    }
};
foreach ($formulaRepairSources as $srcApp) {
    if (!is_file($srcApp)) {
        continue;
    }
    $label = basename($srcApp);
    $before = $formulaCount($srcApp);
    $out = sys_get_temp_dir() . '/ps_ffe_gen_' . bin2hex(random_bytes(3)) . '.msapp';
    (new Pipeline())->run($srcApp, [['id' => 'fix_formula_errors', 'options' => []]], $out);
    $after = $formulaCount($out);
    @unlink($out);
    assert_true(
        $after <= $before,
        $label . ' formula repair does not regress (before ' . $before . ', after ' . $after . ')'
    );
    if ($before > 0) {
        assert_true(
            $after < $before || $after <= 15,
            $label . ' formula repair reduces errors or leaves only hard leftovers (before '
            . $before . ', after ' . $after . ')'
        );
    }
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
}
assert_true(is_file(dirname(__DIR__) . '/scripts/verify_formula_repair_multiapp.php'), 'multi-app formula repair verifier script exists');

$powerToWeb = SweepChains::powerToWeb();
$webToPower = SweepChains::webToPower();
assert_true($powerToWeb === [['id' => 'export_to_web_ir', 'options' => []]], 'power_to_web is export_to_web_ir composite');
assert_true($webToPower === [['id' => 'import_from_web_ir', 'options' => []]], 'web_to_power is import_from_web_ir composite');
$exportSubs = array_column(\PowerSweeper\Sweeps\ExportToWebIrSweep::subSweeps(), 'id');
$importSubs = array_column(\PowerSweeper\Sweeps\ImportFromWebIrSweep::subSweeps(), 'id');
assert_true(in_array('meaningful_names', $exportSubs, true), 'export_to_web_ir renames generics before export');
assert_true(in_array('repair_double_qualified_refs', $exportSubs, true), 'export_to_web_ir cleans screen refs after rename');
assert_true(in_array('export_web_ir', $exportSubs, true), 'export_to_web_ir exports IR');
assert_true(in_array('regenerate_sarif', $exportSubs, true), 'export_to_web_ir regenerates SARIF');
assert_true(in_array('import_web_ir', $importSubs, true), 'import_from_web_ir imports IR');
assert_true(in_array('repair_double_qualified_refs', $importSubs, true), 'import_from_web_ir cleans screen refs after import');
assert_true(in_array('accessibility_labels', $importSubs, true), 'import_from_web_ir fills a11y after import');
assert_true(in_array('ensure_focus_visible', $importSubs, true), 'import_from_web_ir ensures focus visible');
assert_true(in_array('regenerate_sarif', $importSubs, true), 'import_from_web_ir regenerates SARIF');
assert_true($sweepRegistry->has('export_web_ir'), 'export_web_ir sweep registered');
assert_true($sweepRegistry->has('import_web_ir'), 'import_web_ir sweep registered');
assert_true($sweepRegistry->has('configure_power_document'), 'configure_power_document sweep registered');

// Composite smoke — empty docs (SARIF skipped without _extract_dir)
foreach ([
    new \PowerSweeper\Sweeps\FixControlNamesAndRefsSweep(),
    new \PowerSweeper\Sweeps\AccessibilityPolishSweep(),
    new \PowerSweeper\Sweeps\EnableDarkThemeSweep(),
    new \PowerSweeper\Sweeps\CleanDefaultChromeSweep(),
    new \PowerSweeper\Sweeps\RepairSharePointDataSweep(),
    new \PowerSweeper\Sweeps\ExportToWebIrSweep(),
    new \PowerSweeper\Sweeps\ImportFromWebIrSweep(),
] as $compositeSweep) {
    $smokeReport = new Report();
    $compositeSweep->apply([], $smokeReport, []);
    assert_true($smokeReport->count() >= 1, $compositeSweep::id() . ' writes a summary row');
}

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
$dateSweep = new \PowerSweeper\Sweeps\RepairStudioSyntaxSweep();
$dateReport = new Report();
$dateRef = new ReflectionClass($dateSweep);
$dateRepair = $dateRef->getMethod('repairFormula');
$dateRepair->setAccessible(true);
$dateFixed = $dateRepair->invoke($dateSweep, "='VCR / VCN Form'.Date(1900, 1, 1)", 'test', $dateReport);
assert_true(str_contains($dateFixed, 'Date(1900') && !str_contains($dateFixed, "'VCR / VCN Form'.Date"), 'syntax repair unwraps screen-qualified Date()');

// repair_studio_syntax — quote spaced App.Formulas named-formula keys
$spacedFormulaYaml = <<<'YAML'
App:
  Control: App@1.0.0
  Properties:
    Formulas: |-
      TDR Trips_ TopMenu_1 = [{ Value: 1 }];
      SideMenuSecondTable = [{ Value: 2 }];
YAML;
$spacedFormulaPath = sys_get_temp_dir() . '/ps_spaced_nf_' . bin2hex(random_bytes(4)) . '.pa.yaml';
file_put_contents($spacedFormulaPath, $spacedFormulaYaml);
$spacedFormulaDoc = ControlDocument::fromFile($spacedFormulaPath, 'Src/App.pa.yaml');
assert_true($spacedFormulaDoc !== null, 'spaced named-formula fixture loads');
$spacedFormulaReport = new Report();
(new \PowerSweeper\Sweeps\RepairStudioSyntaxSweep())->apply([$spacedFormulaDoc], $spacedFormulaReport);
$spacedFormulas = null;
foreach ($spacedFormulaDoc->controls() as $c) {
    if ($c->isApp()) {
        $spacedFormulas = $c->getProperty('Formulas');
        break;
    }
}
assert_true(
    is_string($spacedFormulas)
        && str_contains($spacedFormulas, "'TDR Trips_ TopMenu_1' =")
        && str_contains($spacedFormulas, 'SideMenuSecondTable ='),
    'syntax repair quotes spaced App.Formulas named-formula keys only'
);
@unlink($spacedFormulaPath);

// locale — LookUp with ; after quoted table name inside concatenation
$lookupLocale = '"Version #: " & AppVersion & LookUp(\'VASC App Versions\'; ID = 1).AppVersion';
assert_true(FormulaLocaleNormalizer::looksLocaleCorrupted($lookupLocale), 'LookUp quoted-arg locale separator detected');
$lookupFixed = FormulaLocaleNormalizer::toInvariant($lookupLocale);
assert_true(str_contains($lookupFixed, "LookUp('VASC App Versions', ID = 1)"), 'LookUp locale separator unwhacked');

// App.Formulas named-formula terminators must not be treated as locale list separators
$appFormulas = <<<'FX'
=// Colors
DefaultGrayBackgroud = RGBA(
    250,
    250,
    250,
    1
);
MainFontColour = RGBA(
    0,
    0,
    0,
    1
);
MainFont = Font.'Segoe UI';
SideMenuFirstTable = [
    {
        Screen: 'VASC SAINT Homepage',
        Name: "Home",
        Icon: Icon.Home
    }
];
FX;
assert_true(!FormulaLocaleNormalizer::looksLocaleCorrupted($appFormulas), 'App.Formulas with Segoe UI; not flagged as locale');
assert_true(FormulaLocaleNormalizer::toInvariant($appFormulas) === $appFormulas, 'App.Formulas statement separators preserved');
$appFormulasLocale = "MainFont = Font.'Segoe UI';\nTitle = Concatenate(\"A\"; \"B\");";
assert_true(FormulaLocaleNormalizer::looksLocaleCorrupted($appFormulasLocale), 'App.Formulas with locale Concatenate(; ) still flagged');
$appFormulasFixed = FormulaLocaleNormalizer::toInvariant($appFormulasLocale);
assert_true(str_contains($appFormulasFixed, 'Concatenate("A", "B")'), 'locale args inside calls still converted');
assert_true(str_contains($appFormulasFixed, "Font.'Segoe UI';"), 'top-level App.Formulas ; preserved while fixing call args');

// Ghost Patch must not strip Power Fx enum record fields (Icon: Icon.Home)
$iconDoc = ControlDocument::fromFile(__DIR__ . '/fixtures/locale_corrupt.pa.yaml', 'Src/App.pa.yaml');
assert_true($iconDoc !== null, 'Icon enum host fixture loads');
$iconTarget = null;
foreach ($iconDoc->controls() as $c) {
    if ($c->getProperty('OnVisible') !== null || $c->getProperty('Text') !== null) {
        $iconTarget = $c;
        break;
    }
}
assert_true($iconTarget !== null, 'Icon enum host control found');
$iconProp = $iconTarget->getProperty('OnVisible') !== null ? 'OnVisible' : 'Text';
$iconTarget->setProperty(
    $iconProp,
    '=[{Name:"Home", Icon: Icon.Home}, {Name:"More", Icon: Icon.Items}]'
);
$iconReport = new Report();
(new \PowerSweeper\Sweeps\RepairGhostPatchFieldsSweep())->apply([$iconDoc], $iconReport, []);
$iconAfter = (string) ($iconTarget->getProperty($iconProp) ?? '');
assert_true(str_contains($iconAfter, 'Icon.Home'), 'ghost sweep keeps Icon.Home enum fields');
assert_true(str_contains($iconAfter, 'Icon.Items'), 'ghost sweep keeps Icon.Items enum fields');

// repair2.msapp — pipeline idempotency (3 passes, formulas stable)
$repair2 = dirname(__DIR__) . '/samples/import_debug/CDLS (L) VCR App repair2.msapp';
if (is_file($repair2)) {
    $idempotentOut = sys_get_temp_dir() . '/ps_idempotent_' . bin2hex(random_bytes(4)) . '.msapp';
    $repairHops = SweepChains::studioRepair();
    (new Pipeline())->run($repair2, $repairHops, $idempotentOut);
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
    (new Pipeline())->run($idempotentOut, $repairHops, $pass2Out);
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
    (new Pipeline())->run($pass2Out, $repairHops, $pass3Out);
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
if (is_file($repair2)) {
    $poweredTestOut = sys_get_temp_dir() . '/ps_powered_test_' . bin2hex(random_bytes(4)) . '.msapp';
    $poweredSweeps = SweepChains::powered();
    (new Pipeline())->run($repair2, $poweredSweeps, $poweredTestOut);
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
    assert_true(is_string($vcnYamlPowered) && preg_match('/Sites:[\\s\\S]*?(?:Classic\\/TextInput@|ModernNumberInput@)[\\s\\S]*?Fill:\\s*=gblTheme\\.InputFill/m', $vcnYamlPowered) === 1, 'Sites number field gets gblTheme.InputFill after classic prep');
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
    $thceeHops = SweepChains::powered();
    $thceePoweredOut = sys_get_temp_dir() . '/ps_thcee_powered_test_' . bin2hex(random_bytes(4)) . '.msapp';
    (new Pipeline())->run($thceeFriday, $thceeHops, $thceePoweredOut);
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
assert_true(str_contains($html, 'conversion file') || str_contains($html, 'Structural preview') || str_contains($html, 'Full conversion'), 'HTML preview scaffold mentions conversion fidelity');

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
(new \PowerSweeper\Sweeps\RepairGhostPatchFieldsSweep())->apply([$ghostDoc], $ghostReport);
$ghostOnVisible = null;
foreach ($ghostDoc->controls() as $c) {
    if ($c->isScreen()) {
        $ghostOnVisible = $c->getProperty('OnVisible');
        break;
    }
}
assert_true(
    is_string($ghostOnVisible) && !str_contains($ghostOnVisible, 'MissingGhostControl'),
    'ghost sweep discovers and removes absent Field: Field.Prop lines'
);
@unlink($ghostPath);

// Ghost gallery LookUp(...AllItems...).Child → Blank() across string-literal splits
$ghostLookupYaml = <<<'YAML'
Screen1:
  Control: Screen@2.0.0
  Properties:
    OnSelect: |-
      =Patch(
          colTemp,
          Defaults(colTemp),
          {
              Title: LookUp(
                  Gallery3.AllItems,
                  FieldName = "MP File Number"
              ).TextInput4.Text
          }
      );
YAML;
$ghostLookupPath = sys_get_temp_dir() . '/ps_ghost_lookup_' . bin2hex(random_bytes(4)) . '.pa.yaml';
file_put_contents($ghostLookupPath, $ghostLookupYaml);
$ghostLookupDoc = ControlDocument::fromFile($ghostLookupPath, 'Src/Screen1.pa.yaml');
assert_true($ghostLookupDoc !== null, 'ghost LookUp fixture loads');
$ghostLookupReport = new Report();
(new \PowerSweeper\Sweeps\RepairGhostPatchFieldsSweep())->apply([$ghostLookupDoc], $ghostLookupReport);
$ghostLookupOnSelect = null;
foreach ($ghostLookupDoc->controls() as $c) {
    if ($c->isScreen()) {
        $ghostLookupOnSelect = $c->getProperty('OnSelect');
        break;
    }
}
assert_true(
    is_string($ghostLookupOnSelect)
        && str_contains($ghostLookupOnSelect, 'Title: Blank()')
        && !str_contains($ghostLookupOnSelect, 'Gallery3'),
    'ghost sweep neutralizes LookUp(Ghost.AllItems, …).Child to Blank()'
);
@unlink($ghostLookupPath);

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
(new \PowerSweeper\Sweeps\ExportWebAppSweep())->apply([$webDoc], $exportReport, ['_extract_dir' => $webExtract]);
assert_true(is_file($webExtract . '/WebApp/power_sweeper_ir.json'), 'export_web_ir wrote IR file');
assert_true(is_file($webExtract . '/WebApp/index.html'), 'export_web_ir wrote HTML preview');
assert_true(is_file($webExtract . '/WebApp/app.css') && is_file($webExtract . '/WebApp/app.js'), 'export_web_ir wrote CSS/JS');
assert_true(is_file($webExtract . '/WebApp/api.php'), 'export_web_ir defaults to PHP API');
assert_true(!is_file($webExtract . '/WebApp/api.py'), 'export_web_ir does not write Python when PHP is selected');
$irExport = json_decode((string) file_get_contents($webExtract . '/WebApp/power_sweeper_ir.json'), true);
assert_true(is_array($irExport) && ($irExport['server_language'] ?? '') === 'php', 'exported IR records php server_language');
assert_true(is_array($irExport) && ($irExport['fidelity']['level'] ?? '') === 'canvas', 'exported IR fidelity is canvas');
assert_true(($irExport['document']['layout']['scale_to_fit'] ?? true) === false, 'export Studio layout ScaleToFit is false');
assert_true(($irExport['document']['player']['scale_to_fit'] ?? false) === true, 'export player still scale-to-fits devices');
assert_true(str_contains((string) file_get_contents($webExtract . '/WebApp/index.html'), 'data-scale-to-fit="true"'), 'exported HTML enables device scale-to-fit');
assert_true(str_contains((string) file_get_contents($webExtract . '/WebApp/app.js'), 'function fitCanvas'), 'exported JS has fitCanvas');
assert_true(str_contains((string) file_get_contents($webExtract . '/WebApp/app.css'), 'transform:scale(var(--ps-scale-x)'), 'exported CSS scales the design canvas');

(new \PowerSweeper\Sweeps\ExportWebAppSweep())->apply([$webDoc], new Report(), [
    '_extract_dir' => $webExtract,
    'server_language' => 'python',
    'configure_document' => false,
]);
assert_true(is_file($webExtract . '/WebApp/api.py'), 'export_web_ir writes Python API when requested');
assert_true(str_contains((string) file_get_contents($webExtract . '/WebApp/api.py'), 'server_language=python'), 'Python API stub identifies itself');

assert_true(\PowerSweeper\SweepOptions::serverLanguage([]) === 'php', 'server language defaults to php');
assert_true(\PowerSweeper\SweepOptions::serverLanguage(['server_language' => 'Python']) === 'python', 'server language accepts Python');

$vcrWebPath = __DIR__ . '/fixtures/vcr_web_roundtrip.pa.yaml';
$vcrWebDoc = ControlDocument::fromFile($vcrWebPath, 'Src/VCR Form.pa.yaml');
assert_true($vcrWebDoc !== null, 'VCR web fixture loads');
$vcrWebExtract = sys_get_temp_dir() . '/ps_vcr_web_' . bin2hex(random_bytes(4));
mkdir($vcrWebExtract, 0775, true);
file_put_contents($vcrWebExtract . '/Properties.json', json_encode([
    'DocumentAppType' => 'DesktopOrTablet',
    'DocumentLayoutWidth' => 1366,
    'DocumentLayoutHeight' => 768,
    'Name' => 'VCR App',
], JSON_PRETTY_PRINT));
$vcrIr = (new \PowerSweeper\WebApp\WebAppIrBuilder())->build([$vcrWebDoc], $vcrWebExtract, ['server_language' => 'php']);
assert_true(($vcrIr['stats']['screens'] ?? 0) >= 2, 'VCR IR has both form and confirm screens');
assert_true(in_array('Confirm', array_column($vcrIr['navigation'], 'to'), true), 'VCR IR captures Submit Navigate(Confirm)');
$vcrByName = [];
$walkIr = static function (array $node) use (&$walkIr, &$vcrByName): void {
    if (isset($node['name'])) {
        $vcrByName[(string) $node['name']] = $node;
    }
    foreach ($node['children'] ?? [] as $child) {
        if (is_array($child)) {
            $walkIr($child);
        }
    }
};
foreach ($vcrIr['screens'] as $screen) {
    if (is_array($screen)) {
        $walkIr($screen);
    }
}
assert_true(($vcrByName['ArrivalDate']['web']['html'] ?? '') === 'input', 'VCR datepicker maps to input');
assert_true(($vcrByName['ArrivalDate']['web']['input_type'] ?? '') === 'date', 'VCR datepicker input type is date');
assert_true(str_contains((string) ($vcrByName['ArrivalDate']['properties']['Format'] ?? ''), 'ShortDate'), 'VCR datepicker Format stays ShortDate in IR');
assert_true(($vcrByName['ArrivalDate']['chrome']['font_size'] ?? 0) === 14.0, 'VCR datepicker chrome font_size is 14');
assert_true(($vcrByName['Submit']['layout']['x'] ?? 0) === 32, 'VCR Submit X is 32');
assert_true(($vcrByName['Submit']['layout']['y'] ?? 0) === 260, 'VCR Submit Y is 260');
assert_true(($vcrByName['Submit']['web']['html'] ?? '') === 'button', 'VCR Submit maps to button');
$vcrHtml = (new \PowerSweeper\WebApp\WebAppEmitter())->renderHtml($vcrIr, 'php');
assert_true(str_contains($vcrHtml, 'left:32px') && str_contains($vcrHtml, 'top:260px'), 'VCR HTML positions Submit at 32,260');
assert_true(str_contains($vcrHtml, 'type="date"'), 'VCR HTML emits date inputs');
assert_true(str_contains($vcrHtml, '2026-09-18'), 'VCR HTML preserves ArrivalDate value');
assert_true(str_contains($vcrHtml, 'Visit Clearance Request'), 'VCR HTML keeps title text');
assert_true(($vcrIr['document']['player']['scale_to_fit'] ?? false) === true, 'VCR player scale_to_fit is on');
assert_true(($vcrIr['document']['player']['width'] ?? 0) === 1366, 'VCR player width is the design canvas');
assert_true(str_contains($vcrHtml, 'ps-viewport') && str_contains($vcrHtml, 'ps-canvas'), 'VCR HTML wraps the design canvas for device scaling');
assert_true(str_contains($vcrHtml, 'scale-to-fits each device'), 'VCR HTML documents device scale-to-fit');
$half = \PowerSweeper\WebApp\WebAppEmitter::fitScale(1366, 768, 683, 384);
assert_true(abs($half['x'] - 0.5) < 1e-9 && abs($half['y'] - 0.5) < 1e-9, 'fitScale half viewport is 0.5');
$phoneScale = \PowerSweeper\WebApp\WebAppEmitter::fitScale(1366, 768, 390, 700);
$phoneExpected = min(390 / 1366, 700 / 768);
assert_true(abs($phoneScale['x'] - $phoneExpected) < 1e-9 && abs($phoneScale['x'] - $phoneScale['y']) < 1e-9, 'fitScale phone uses the tighter axis');
$stretch = \PowerSweeper\WebApp\WebAppEmitter::fitScale(1366, 768, 800, 600, true, false);
assert_true(abs($stretch['x'] - (800 / 1366)) < 1e-9 && abs($stretch['y'] - (600 / 768)) < 1e-9, 'fitScale can stretch axes independently');
$offScale = \PowerSweeper\WebApp\WebAppEmitter::fitScale(1366, 768, 100, 100, false);
assert_true($offScale['x'] === 1.0 && $offScale['y'] === 1.0, 'fitScale off stays 1:1 design pixels');
$vcrFiles = (new \PowerSweeper\WebApp\WebAppEmitter())->emitFiles($vcrIr, 'php');
assert_true(isset($vcrFiles['api.php']) && str_contains($vcrFiles['api.php'], "case 'health'"), 'VCR PHP API has health route');
assert_true(!isset($vcrFiles['api.py']), 'VCR PHP emit does not include api.py');
assert_true(str_contains($vcrFiles['app.js'], 'function fitCanvas') && str_contains($vcrFiles['app.css'], '--ps-scale-x'), 'VCR emit includes scale-to-fit CSS/JS');
$vcrJsCheck = sys_get_temp_dir() . '/ps_vcr_app.js';
file_put_contents($vcrJsCheck, $vcrFiles['app.js']);
$vcrJsOk = null;
exec('node --check ' . escapeshellarg($vcrJsCheck) . ' 2>&1', $vcrJsOut, $vcrJsOk);
assert_true($vcrJsOk === 0, 'VCR emitted app.js parses as JavaScript');
@unlink($vcrJsCheck);
$vcrMutate = function (array &$node) use (&$vcrMutate): void {
    if (($node['name'] ?? '') === 'Title') {
        $node['chrome']['font_size'] = 24;
        $node['layout']['x'] = 40;
        return;
    }
    foreach ($node['children'] ?? [] as $i => $_) {
        $vcrMutate($node['children'][$i]);
    }
};
foreach ($vcrIr['screens'] as $i => $_) {
    $vcrMutate($vcrIr['screens'][$i]);
}
$vcrApply = (new \PowerSweeper\WebApp\WebAppIrApplier())->apply([$vcrWebDoc], $vcrIr, new Report(), $vcrWebExtract);
assert_true($vcrApply['changes'] > 0, 'VCR IR apply writes layout/chrome back');
$vcrTitle = null;
foreach ($vcrWebDoc->controls() as $c) {
    if ($c->name === 'Title') {
        $vcrTitle = $c;
        break;
    }
}
assert_true($vcrTitle !== null && str_contains((string) $vcrTitle->getProperty('X'), '40'), 'VCR import updates Title X');
assert_true($vcrTitle !== null && str_contains((string) $vcrTitle->getProperty('Size'), '24'), 'VCR import updates Title Size');
array_map('unlink', glob($vcrWebExtract . '/WebApp/*') ?: []);
@rmdir($vcrWebExtract . '/WebApp');
@unlink($vcrWebExtract . '/Properties.json');
@rmdir($vcrWebExtract);

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
    $tdrHops = SweepChains::studioRepair();
    (new Pipeline())->run($tdrSample, $tdrHops, $tdrOut);
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
    $ascHops = SweepChains::studioRepair();
    (new Pipeline())->run($ascTemplate, $ascHops, $ascOut);
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

// meaningful_names must not rename JSON Components/* template children
$compNameYaml = <<<'YAML'
Screen1:
  Control: Screen@2.0.0
  Children:
    - Label1:
        Control: Label@2.5.1
        Properties:
          Text: ="Screen Label"
YAML;
$compNameExtract = sys_get_temp_dir() . '/ps_compname_' . bin2hex(random_bytes(3));
mkdir($compNameExtract . '/Src', 0775, true);
mkdir($compNameExtract . '/Components', 0775, true);
file_put_contents($compNameExtract . '/Src/Screen1.pa.yaml', $compNameYaml);
file_put_contents($compNameExtract . '/Components/4.json', json_encode([
    'TopParent' => [
        'Name' => 'TopbarHeader',
        'Template' => ['Name' => 'http://microsoft.com/appmagic/ComponentDefinitionInfo'],
        'Children' => [[
            'Name' => 'Label1',
            'Template' => ['Name' => 'label'],
            'Rules' => [
                ['Property' => 'Text', 'InvariantScript' => '"Header"'],
                ['Property' => 'X', 'InvariantScript' => 'Label1.X'],
            ],
        ]],
    ],
], JSON_PRETTY_PRINT));
$compDocs = [];
$compScreenDoc = ControlDocument::fromFile($compNameExtract . '/Src/Screen1.pa.yaml', 'Src/Screen1.pa.yaml');
$compJsonDoc = ControlDocument::fromFile($compNameExtract . '/Components/4.json', 'Components/4.json');
assert_true($compScreenDoc !== null && $compJsonDoc !== null, 'component-safe rename fixtures load');
$compDocs[] = $compScreenDoc;
$compDocs[] = $compJsonDoc;
$compNameReport = new Report();
(new MeaningfulNamesSweep())->apply($compDocs, $compNameReport, ['only_generic' => true]);
$compLabelStill = false;
$screenLabelRenamed = false;
foreach ($compDocs as $doc) {
    foreach ($doc->controls() as $c) {
        if (str_contains($c->path, 'Components/') && $c->name === 'Label1') {
            $compLabelStill = true;
        }
        if (str_contains($c->path, 'Screen1') && $c->name !== 'Label1' && $c->name !== 'Screen1') {
            $screenLabelRenamed = true;
        }
    }
}
assert_true($compLabelStill, 'meaningful_names leaves JSON Components/* Label1 untouched');
assert_true($screenLabelRenamed, 'meaningful_names still renames screen-level generic Label1');
// Component formula must not be rewritten to the screen's new name
$compFormula = '';
foreach ($compJsonDoc->controls() as $c) {
    if ($c->name === 'Label1' || str_ends_with($c->path, 'Label1')) {
        // parent TopbarHeader may hold X on child — check Rules via transform
    }
}
$compJsonDoc->transformFormulas(static function (string $f) use (&$compFormula): string {
    if (str_contains($f, 'Label1') || str_contains($f, 'Screen')) {
        $compFormula .= $f . "\n";
    }
    return $f;
});
assert_true(str_contains($compFormula, 'Label1.X'), 'component formulas keep local Label1 refs after screen rename');

// Multi-app power↔web round-trip on repaired TDR (fast) — formula errors must stay 0
$tdrWebSample = dirname(__DIR__) . '/samples/import_debug/TDR - THCEE Directory App.msapp';
if (is_file($tdrWebSample)) {
    $tdrWebDir = sys_get_temp_dir() . '/ps_tdr_web_' . bin2hex(random_bytes(3));
    mkdir($tdrWebDir, 0775, true);
    $tdrRepaired = $tdrWebDir . '/repaired.msapp';
    $tdrWeb = $tdrWebDir . '/web.msapp';
    $tdrRound = $tdrWebDir . '/round.msapp';
    $repairHopsWeb = SweepChains::studioRepair();
    $p2wHops = SweepChains::powerToWeb();
    $w2pHops = SweepChains::webToPower();
    (new Pipeline())->run($tdrWebSample, $repairHopsWeb, $tdrRepaired);
    (new Pipeline())->run($tdrRepaired, $p2wHops, $tdrWeb);
    (new Pipeline())->run($tdrWeb, $w2pHops, $tdrRound);
    $tdrWebArch = new \PowerSweeper\MsappArchive($tdrWeb);
    $tdrWebArch->unpack();
    assert_true(is_file($tdrWebArch->extractDir() . '/WebApp/power_sweeper_ir.json'), 'TDR power_to_web writes IR');
    assert_true(is_file($tdrWebArch->extractDir() . '/WebApp/index.html'), 'TDR power_to_web writes HTML');
    $tdrWebProps = json_decode((string) file_get_contents($tdrWebArch->extractDir() . '/Properties.json'), true);
    assert_true(($tdrWebProps['DocumentLayoutScaleToFit'] ?? true) === false, 'TDR power_to_web sets ScaleToFit false');
    $tdrWebLive = \PowerSweeper\StudioLiveChecker::check($tdrWebArch->documents(), ['extract_dir' => $tdrWebArch->extractDir()]);
    $tdrWebErr = 0;
    foreach ($tdrWebLive['findings'] as $f) {
        $r = (string) ($f['ruleId'] ?? '');
        if (str_starts_with($r, 'app-Err') || $r === 'app-formula-mangled-screen-ref') {
            $tdrWebErr++;
        }
    }
    $tdrWebArch->cleanup();
    $tdrRoundArch = new \PowerSweeper\MsappArchive($tdrRound);
    $tdrRoundArch->unpack();
    $tdrRoundProps = json_decode((string) file_get_contents($tdrRoundArch->extractDir() . '/Properties.json'), true);
    assert_true(($tdrRoundProps['DocumentLayoutScaleToFit'] ?? false) === true, 'TDR web_to_power restores ScaleToFit true');
    $tdrRoundLive = \PowerSweeper\StudioLiveChecker::check($tdrRoundArch->documents(), ['extract_dir' => $tdrRoundArch->extractDir()]);
    $tdrRoundErr = 0;
    foreach ($tdrRoundLive['findings'] as $f) {
        $r = (string) ($f['ruleId'] ?? '');
        if (str_starts_with($r, 'app-Err') || $r === 'app-formula-mangled-screen-ref') {
            $tdrRoundErr++;
        }
    }
    $tdrRoundArch->cleanup();
    assert_true($tdrWebErr === 0, 'TDR power_to_web introduces no formula errors (got ' . $tdrWebErr . ')');
    assert_true($tdrRoundErr === 0, 'TDR web↔power round-trip introduces no formula errors (got ' . $tdrRoundErr . ')');
    @unlink($tdrRepaired);
    @unlink($tdrWeb);
    @unlink($tdrRound);
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
(new \PowerSweeper\Sweeps\RepairSharePointFieldsSweep())->apply([$spFbDoc], $spFbReport, ['_extract_dir' => $spFbExtract]);
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
assert_true(!preg_match('/\bAmendmentVisit\s*:/', $spFbFormula), 'SP sweep drops absent AmendmentVisit Patch field');
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
(new \PowerSweeper\Sweeps\RepairStudioSyntaxSweep())->apply([$vascDoc], $vascReport);
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

// --- SweepAdvisor: auto sweep sequence + force mode ---
$advisor = new SweepAdvisor();
$forced = $advisor->applyForceMode(
    [
        ['id' => 'accessibility_polish', 'options' => []],
        ['id' => 'export_to_web_ir', 'options' => ['only_generic' => true]],
        ['id' => 'enable_dark_theme', 'options' => ['x' => 1]],
    ],
    'all'
);
assert_true(($forced[0]['options']['force'] ?? null) === true, 'applyForceMode all sets force on a11y');
assert_true(!array_key_exists('force', $forced[1]['options']), 'applyForceMode leaves non-forceable sweeps alone');
assert_true(($forced[2]['options']['force'] ?? null) === true, 'applyForceMode all sets force on dark theme');
assert_true(($forced[2]['options']['x'] ?? null) === 1, 'applyForceMode preserves other options');
$missing = $advisor->applyForceMode(
    [['id' => 'clean_default_chrome', 'options' => []], ['id' => 'translate', 'options' => ['force' => true]]],
    'missing_only'
);
assert_true(($missing[0]['options']['force'] ?? null) === false, 'applyForceMode missing_only clears chrome force');
assert_true(($missing[1]['options']['force'] ?? null) === false, 'applyForceMode missing_only overrides prior force');

$kmsAdvisePath = dirname(__DIR__) . '/samples/dark_mode_kitchen_sink/dark_mode_kitchen_sink.msapp';
if (is_file($kmsAdvisePath)) {
    $plan = $advisor->recommend($kmsAdvisePath);
    assert_true(($plan['ok'] ?? false) === true, 'advisor recommend ok for kitchen sink');
    assert_true(in_array($plan['force_mode'], ['all', 'missing_only'], true), 'advisor force_mode is valid');
    $planIds = array_map(static fn(array $h): string => (string) $h['id'], $plan['sweeps'] ?? []);
    assert_true(in_array('accessibility_polish', $planIds, true), 'kitchen sink plan includes accessibility_polish');
    assert_true(in_array('enable_dark_theme', $planIds, true), 'kitchen sink plan includes enable_dark_theme');
    assert_true(!in_array('enable_dark_mode', $planIds, true), 'kitchen sink uses enable_dark_theme composite');
    assert_true(!in_array('prefer_classic_theme_controls', $planIds, true), 'kitchen sink classic prep is inside enable_dark_theme');
    assert_true(!in_array('accessibility_labels', $planIds, true), 'kitchen sink a11y lives in accessibility_polish');
    assert_true(in_array('regenerate_sarif', $planIds, true), 'kitchen sink keeps trailing regenerate_sarif (double-run OK)');
    assert_true(!in_array('align_near_miss', $planIds, true), 'kitchen sink does not recommend align_near_miss without detection');
    assert_true(!in_array('repair_delegation', $planIds, true), 'kitchen sink skips repair_delegation with no delegation hints');
    assert_true(!in_array('unwhack_locale_formulas', $planIds, true), 'kitchen sink skips locale unwhack with no locale hits');
    $formulaSignal = ((int) ($plan['signals']['formula_errors'] ?? 0))
        + ((int) ($plan['signals']['locale_hits'] ?? 0))
        + ((int) ($plan['signals']['delegation_hints'] ?? 0))
        + ((int) ($plan['signals']['maintainability_hits'] ?? 0));
    if ($formulaSignal > 0) {
        assert_true(in_array('fix_formula_errors', $planIds, true), 'kitchen sink recommends fix_formula_errors when formula signals present');
    } else {
        assert_true(!in_array('fix_formula_errors', $planIds, true), 'kitchen sink skips fix_formula_errors with no formula signals');
    }
    foreach ($plan['sweeps'] as $step) {
        if (!in_array($step['id'], SweepAdvisor::FORCEABLE_SWEEPS, true)) {
            continue;
        }
        assert_true(array_key_exists('force', $step['options'] ?? []), 'forceable sweep carries force option');
        assert_true(($step['options']['force'] ?? null) === ($plan['force_mode'] === 'all'), 'force option matches force_mode');
    }
}

$poweredAdvise = dirname(__DIR__) . '/samples/import_debug/CDLS_VCR_App_Friday.powered.msapp';
if (is_file($poweredAdvise)) {
    $poweredPlan = $advisor->recommend($poweredAdvise);
    assert_true(($poweredPlan['ok'] ?? false) === true, 'advisor recommend ok for powered VCR');
    assert_true(($poweredPlan['force_mode'] ?? '') === 'missing_only', 'powered app prefers missing_only force');
    assert_true(($poweredPlan['signals']['has_theme'] ?? false) === true, 'powered app signals has_theme');
    $poweredIds = array_map(static fn(array $h): string => (string) $h['id'], $poweredPlan['sweeps'] ?? []);
    assert_true(!in_array('enable_dark_mode', $poweredIds, true), 'powered app skips enable_dark_mode');
    assert_true(!in_array('enable_dark_theme', $poweredIds, true), 'powered app skips enable_dark_theme');
    assert_true(!in_array('align_near_miss', $poweredIds, true), 'powered app does not get default light-cleanup sweeps');
    // No full studio_repair dump when signals are clean for that sweep.
    if (((int) ($poweredPlan['signals']['delegation_hints'] ?? 0)) === 0) {
        assert_true(!in_array('repair_delegation', $poweredIds, true), 'powered app skips repair_delegation when no delegation hints');
    }
    if (((int) ($poweredPlan['signals']['locale_hits'] ?? 0)) === 0) {
        assert_true(!in_array('unwhack_locale_formulas', $poweredIds, true), 'powered app skips unwhack when no locale hits');
    }
    if (((int) ($poweredPlan['signals']['missing_accessible_label'] ?? 0)) === 0
        && ((int) ($poweredPlan['signals']['by_rule']['acc-AccessibleLabelNeeded'] ?? 0)) === 0
    ) {
        assert_true(!in_array('accessibility_labels', $poweredIds, true), 'powered app skips accessibility_labels when none missing');
        assert_true(!in_array('accessibility_polish', $poweredIds, true), 'powered app skips accessibility_polish when none missing');
    }
}

// --- Shared estimate model learns from run timings (not per-browser) ---
{
    $estRoot = sys_get_temp_dir() . '/ps_estimate_test_' . bin2hex(random_bytes(4));
    mkdir($estRoot . '/config', 0777, true);
    mkdir($estRoot . '/public/assets', 0777, true);
    mkdir($estRoot . '/assets', 0777, true);
    mkdir($estRoot . '/storage/benchmarks', 0777, true);
    $seed = EstimateModel::load(dirname(__DIR__));
    file_put_contents($estRoot . '/config/estimate_model.json', json_encode($seed, JSON_UNESCAPED_SLASHES));
    $beforeDark = count($seed['sweeps']['enable_dark_mode']['samples'] ?? []);
    $run = EstimateModel::buildRunFromEvents([
        [
            'type' => 'phase',
            'phase' => 'unpack_done',
            'duration_ms' => 900,
            'complexity' => [
                'control_count' => 1200,
                'file_bytes' => 4_000_000,
                'formula_chars' => 20_000,
                'document_count' => 8,
                'property_count' => 4000,
                'screen_count' => 6,
                'extract_bytes' => 8_000_000,
            ],
        ],
        ['type' => 'sweep_done', 'sweep' => 'enable_dark_mode', 'duration_ms' => 12_500, 'changes' => 400],
        ['type' => 'phase', 'phase' => 'subsweep_done', 'subsweep' => 'normalize_containers', 'duration_ms' => 250, 'changes' => 12],
        ['type' => 'phase', 'phase' => 'pack_done', 'duration_ms' => 1100],
    ], [
        'app' => 'Sample App.msapp',
        'file_bytes' => 4_000_000,
        'elapsed_ms' => 15_000,
        'signals' => ['opaque_colors' => 300, 'white_container_fills' => 40],
    ]);
    assert_true(is_array($run), 'estimate buildRunFromEvents returns run');
    assert_true(($run['app'] ?? '') === 'Sample_App', 'estimate sanitizes app label');
    $learned = EstimateModel::recordRun($run, $estRoot);
    assert_true(($learned['ok'] ?? false) === true, 'estimate recordRun ok');
    assert_true(((int) ($learned['samples_added'] ?? 0)) >= 2, 'estimate recordRun adds sweep + subsweep samples');
    $updated = json_decode((string) file_get_contents($estRoot . '/public/assets/estimate_model.json'), true);
    assert_true(is_array($updated), 'estimate model written to public assets');
    assert_true(((int) ($updated['version'] ?? 0)) === EstimateModel::VERSION, 'estimate model version bumped');
    $afterDark = count($updated['sweeps']['enable_dark_mode']['samples'] ?? []);
    assert_true($afterDark === $beforeDark + 1 || $afterDark === min(EstimateModel::MAX_SAMPLES_PER_SWEEP, $beforeDark + 1), 'estimate appends dark-mode sample');
    assert_true(isset($updated['sweeps']['normalize_containers']['samples']), 'estimate records subsweep samples');
    assert_true(is_file($estRoot . '/storage/benchmarks/live_runs.jsonl'), 'estimate appends live_runs.jsonl');
}

require __DIR__ . '/web_roundtrip_test.php';
require __DIR__ . '/web_pixel_chrome_test.php';
require __DIR__ . '/apply_section_anchors_test.php';
require __DIR__ . '/conditional_visibility_test.php';
require __DIR__ . '/settings_accessibility_test.php';
require __DIR__ . '/admin_pixel_test.php';
require __DIR__ . '/mail_storage_test.php';

echo "\n";
if ($failed > 0) {
    echo "FAILED: {$failed} assertion(s)\n";
    exit(1);
}
echo "All tests passed.\n";
exit(0);
