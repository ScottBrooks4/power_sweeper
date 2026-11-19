<?php

declare(strict_types=1);

/**
 * Admin / Details pixel roundtrip checks against the VCR IR.
 */

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

$candidates = [
    dirname(__DIR__) . '/web/VCR/ir/power_sweeper_ir.json',
    '/tmp/vcr_roundtrip/pkg/ir/power_sweeper_ir.json',
];
$irPath = '';
foreach ($candidates as $path) {
    if (is_file($path)) {
        $irPath = $path;
        break;
    }
}
assert_true($irPath !== '', 'VCR IR is available for admin pixel checks');
$ir = json_decode((string) file_get_contents($irPath), true);
assert_true(is_array($ir), 'IR loads');

$html = (new WebAppPixelRenderer())->renderIntermediate($ir);

assert_true(str_contains($html, 'ctrl--settings-pills'), 'AppSettingsGallery expands to settings pills');
assert_true(str_contains($html, 'data-gallery-item="Help"'), 'Help pill is emitted');
assert_true(str_contains($html, 'data-gallery-item="IVCO Message"'), 'IVCO Message pill is emitted');
assert_true(str_contains($html, 'data-role="richtext"'), 'RichTextEditor controls render as richtext');
assert_true(str_contains($html, 'data-control="IVCOMessage"'), 'IVCOMessage RichTextEditor is emitted');
assert_true(
    str_contains($html, 'data-default-fx="LookUp(varTempMessages, email = &quot;ivco&quot;).content"')
        || str_contains($html, "LookUp(varTempMessages, email = \"ivco\").content"),
    'IVCOMessage Default LookUp is preserved on the RTE'
);
assert_true(str_contains($html, 'M9.8 2.2H7.2'), 'IconAdminSidebar document+gear SVG is used');
assert_true(
    str_contains($html, 'data-control="AdminImage"') && !str_contains(
        (string) (preg_match('/data-control="AdminImage"[\s\S]*?(?=data-control="AdminWholeButton|data-control="AdminDescription)/', $html, $am) ? $am[0] : ''),
        'admin-icon.svg'
    ),
    'Home AdminImage does not use legacy admin-icon.svg media'
);
assert_true(
    (bool) preg_match('/data-control="AdminImage"[^>]*>\s*<svg class="ctrl-icon ctrl-icon--card"/', $html),
    'Home AdminImage renders IconAdminImage formula SVG'
);
assert_true(str_contains($html, "m3 9 9-7"), 'Sidebar Home icon comes from SideMenuSecondTable formula SVG');
assert_true(str_contains($html, 'm12 2 10 5'), 'Sidebar VCR Form icon comes from SideMenuSecondTable formula SVG');
assert_true(!str_contains($html, 'Icon1 67'), 'SearchIcon does not paint AccessibleLabel placeholder text');
assert_true(
    (bool) preg_match('/data-control="SearchIcon"[^>]*>\s*<svg/', $html),
    'SearchIcon renders Icon.Search SVG'
);
assert_true(!str_contains($html, 'title="Emergency Contact"'), 'Emergency Contact sidebar icon gated until acknowledged');
assert_true(str_contains($html, 'name="ThemeRadio__'), 'ThemeRadio radio groups are uniquified per clone');
assert_true(str_contains($html, '{vcr_id} — Request ID'), 'Help HtmlText expands fnEmailTokens');
assert_true(str_contains($html, 'Request Summary'), 'fnCreateSummaryHTML expands into Summary chrome');
assert_true(str_contains($html, '<option>Acknowledge</option>'), 'ApprovalChoices formula options render');
assert_true(str_contains($html, 'placeholder="VIP_Tracking"'), 'Import Data Placeholder comes from IR');
assert_true(str_contains($html, 'There is nothing attached.'), 'Attachments control chrome is emitted');
assert_true(str_contains($html, 'ctrl--powerbi'), 'Power BI placeholders are emitted');
assert_true(str_contains($html, 'ctrl-acknowledger-row'), 'L1ApproversGallery expands IRL seed row');
assert_true(str_contains($html, 'ctrl-user-row'), 'UserGallery expands varTempUser preview row');
assert_true(
    str_contains($html, 'data-user="preview@local"'),
    'UserGallery rows expose data-user for ThisItem binding'
);
assert_true(
    str_contains($html, 'placeholder="search users by email"'),
    'UserSearchCombo InputTextPlaceholder comes from IR'
);
assert_true(
    str_contains($html, 'data-combo="user-search"'),
    'UserSearchCombo renders as searchable text input'
);
assert_true(
    str_contains($html, 'data-default="') && str_contains($html, 'ctrl-acknowledger-row'),
    'Acknowledger inputs carry data-default for Reset(L1ApproversGallery)'
);
assert_true(
    str_contains($html, 'Show notification confirmation:'),
    'UserGallery preferences label includes show_notif_confirm from IR Text formula'
);
assert_true(
    str_contains($html, 'data-control="NotifyConfirmContainer"'),
    'NotifyConfirmContainer stays in DOM for confirm overlay'
);
assert_true(
    (bool) preg_match('/data-control="NotifyConfirmContainer"[^>]*class="[^"]*is-hidden/', $html)
        || (bool) preg_match('/class="[^"]*is-hidden[^"]*"[^>]*data-control="NotifyConfirmContainer"/', $html)
        || (bool) preg_match('/<div class="[^"]*is-hidden[^"]*" data-control="NotifyConfirmContainer"/', $html),
    'NotifyConfirmContainer starts hidden'
);
assert_true(
    str_contains($html, 'data-control="NoApprovalsContainer"')
        && !preg_match('/<div class="[^"]*is-hidden[^"]*" data-control="NoApprovalsContainer"/', $html),
    'NoApprovalsContainer is idle-visible when Approval Tracking is empty'
);
assert_true(
    (bool) preg_match('/<div class="[^"]*is-hidden[^"]*" data-control="ApprovalGallery"/', $html),
    'ApprovalGallery starts hidden when Approval Tracking is empty'
);
assert_true(
    str_contains($html, 'data-control="ReviewerComboboxInput"')
        && str_contains($html, 'data-combo="user-search"'),
    'ReviewerComboboxInput renders as searchable text input'
);

$runtime = (string) file_get_contents(dirname(__DIR__) . '/templates/web_package/assets/runtime.js');
assert_true(str_contains($runtime, 'persistThemePreference'), 'runtime persists ThemeRadio to preferences.theme');
assert_true(str_contains($runtime, 'restoreThemePreference'), 'runtime restores theme from preferences/localStorage');
assert_true(str_contains($runtime, 'ps_vcr_theme'), 'runtime mirrors theme preference to localStorage');
assert_true(str_contains($runtime, 'hydrateDefaultInputs'), 'runtime hydrates input Defaults from formulas');
assert_true(str_contains($runtime, 'varDefaultApprover'), 'runtime seeds varDefaultApprover from OnStart varDevApprover');
assert_true(str_contains($runtime, 'showNotify'), 'runtime surfaces Power Apps Notify() toasts');
assert_true(str_contains($runtime, 'Patch(varTempMessages, id') || str_contains($runtime, 'where id is a LookUp'), 'runtime Patches in-memory varTempMessages on Admin Submit');
assert_true(str_contains($runtime, 'applyAdminChrome'), 'runtime syncs Admin top-tab active state');
assert_true(str_contains($runtime, 'applyDetailChrome'), 'runtime syncs Details nav active state');
assert_true(str_contains($runtime, 'seedTempMessagesFromIr'), 'runtime seeds varTempMessages from IR Collects');
assert_true(str_contains($runtime, 'hydrateRichTextEditors'), 'runtime hydrates RichTextEditor Defaults');
assert_true(str_contains($runtime, 'bindFormTabScrollSpy'), 'FormNavTabs scroll spy is preserved');
assert_true(str_contains($runtime, 'AllItemsCount'), 'runtime resolves gallery AllItemsCount');
assert_true(str_contains($runtime, 'thisItemFromRow'), 'runtime binds ThisItem from gallery rows');
assert_true(str_contains($runtime, 'invokeSendEmail'), 'runtime sends mail through allowlisted invoke');
assert_true(str_contains($runtime, 'SendEmailV2'), 'runtime evaluates Office365Outlook.SendEmailV2');
assert_true(str_contains($runtime, 'evalDataIf'), 'runtime persists Patch inside If/With submit formulas');
assert_true(str_contains($runtime, 'evalForAll'), 'runtime evaluates ForAll for Acknowledgers ClearCollect');
assert_true(str_contains($runtime, 'patchInMemory'), 'runtime Patches in-memory varTempUser / varTempL1');
assert_true(str_contains($runtime, 'data-role') && str_contains($runtime, 'gallery'), 'runtime Reset supports gallery targets');
assert_true(str_contains($runtime, 'IsEmpty'), 'runtime evaluates IsEmpty for Approval gallery Visible');
assert_true(str_contains($runtime, 'SelectedItems'), 'runtime resolves ComboBox SelectedItems');
assert_true(str_contains($runtime, 'varShowConfirmNotifContainer'), 'runtime seeds notification confirm flag');

$css = (string) file_get_contents(dirname(__DIR__) . '/templates/web_package/assets/app.css');
assert_true(str_contains($css, 'ctrl-settings-pill'), 'Admin settings pill styles exist');
assert_true(str_contains($css, 'ctrl--richtext'), 'RichTextEditor chrome styles exist');
assert_true(str_contains($css, 'is-detail-active'), 'Details nav active styles exist');

echo "admin_pixel_test: all checks passed\n";
