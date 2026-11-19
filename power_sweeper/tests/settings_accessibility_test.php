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
assert_true($irPath !== '', 'VCR conversion IR is available for settings checks');
$ir = json_decode((string) file_get_contents($irPath), true);
assert_true(is_array($ir), 'IR loads');

$html = (new WebAppPixelRenderer())->renderIntermediate($ir);

assert_true(str_contains($html, 'data-control="SettingsButton"'), 'SettingsButton is emitted');
assert_true(str_contains($html, 'data-control="TransparentButtonContainer"'), 'TransparentButtonContainer is emitted');
assert_true(str_contains($html, 'data-control="SettingsLeft"'), 'SettingsLeft panel is emitted');
assert_true(str_contains($html, 'data-control="SettingsMiddle"'), 'SettingsMiddle panel is emitted');
assert_true(str_contains($html, 'data-control="SettingsRight"'), 'SettingsRight panel is emitted');
assert_true(str_contains($html, 'data-control="ThemeRadio"'), 'ThemeRadio is emitted');
assert_true(str_contains($html, 'ctrl--radio'), 'ThemeRadio renders as a radio group');
assert_true(str_contains($html, 'value="Light"') && str_contains($html, 'value="Dark"'), 'ThemeRadio has Light/Dark options');
assert_true(
    str_contains($html, 'Show delete confirmation screen'),
    'Settings checkboxes prefer Label caption over AccessibleLabel control name'
);
assert_true(
    str_contains($html, 'Show notifcation confirmation screen')
        || str_contains($html, 'Show notification confirmation screen'),
    'NotfConfirmSettingsCheck uses Label caption'
);
assert_true(
    (bool) preg_match('/data-control="SettingsLeft"[^>]*is-hidden|class="[^"]*is-hidden[^"]*"[^>]*data-control="SettingsLeft"/', $html),
    'SettingsLeft starts hidden until varTopMenu'
);
assert_true(str_contains($html, 'ps-dark') || str_contains($html, 'data-theme'), 'dark theme CSS vars are present');

$runtime = (string) file_get_contents(dirname(__DIR__) . '/templates/web_package/assets/runtime.js');
assert_true(str_contains($runtime, 'expandComponentsIntoScreens'), 'runtime expands component trees for Settings OnSelect');
assert_true(str_contains($runtime, 'applySettingsChrome'), 'runtime expands topbar when settings open');
assert_true(str_contains($runtime, 'function pinChrome'), 'runtime resets screen scroll so Settings stays in view');
assert_true(
    (bool) preg_match('/function pinChrome\s*\([^)]*\)\s*\{[\s\S]*?\n  \}/', $runtime, $pin)
        && !str_contains($pin[0], 'scrollIntoView'),
    'pinChrome does not scrollIntoView the screen and clip Settings'
);
assert_true(str_contains($runtime, 'applyDarkMode'), 'runtime applies dark mode class');
assert_true(str_contains($runtime, 'ThemeRadio'), 'runtime wires ThemeRadio changes');
assert_true(str_contains($runtime, 'case "fnDebugAllowed"'), 'runtime evaluates fnDebugAllowed for Settings debug toggle');
assert_true(str_contains($runtime, 'case "fnSubmitReady"'), 'runtime evaluates fnSubmitReady so debug mode skips required fields');
assert_true(str_contains($runtime, 'case "fnRequestTitle"'), 'runtime evaluates fnRequestTitle so submit can mint a new ID');
assert_true(str_contains($runtime, 'function isQuotedString'), 'runtime does not treat "a" & requestID & "b" as one string');
assert_true(str_contains($runtime, 'function bindDropdownValue'), 'runtime binds dropdown Selected.Value as a record');
assert_true(str_contains($runtime, 'function lookupDropdownRecord'), 'runtime looks up L1 Selected.ID from colL1Orgs');
assert_true(str_contains($runtime, 'function hydrateDatePickers'), 'runtime turns ModernDatePicker into type=date');
assert_true(str_contains($runtime, 'DisplayMode.View'), 'runtime locks Ireland L1 dropdowns in DisplayMode.View');
assert_true(str_contains($runtime, 'case "fnDestOK"'), 'runtime evaluates fnDestOK from Destination.Selected.Value');
assert_true(str_contains($runtime, 'function office365Profile'), 'runtime implements Office365Users.MyProfileV2 for Settings Organization');
assert_true(str_contains($runtime, 'MyProfileV2'), 'runtime matches MyProfileV2 so $select is not shown as Organization text');
assert_true(str_contains($runtime, 'hydrateFormGalleries'), 'runtime hydrates galSites / galVisitors from colSites / colVisitors');
assert_true(str_contains($runtime, 'AllItems'), 'runtime resolves gallery.AllItems from Items');
$renderer = (string) file_get_contents(dirname(__DIR__) . '/src/WebApp/WebAppPixelRenderer.php');
assert_true(str_contains($renderer, 'type="date"'), 'renderer emits native date inputs for ModernDatePicker');
assert_true(str_contains($renderer, 'dropdownOptionRecords'), 'renderer stamps collection option data-id');
assert_true(str_contains($runtime, 'hydrateLookupGallery'), 'runtime hydrates Admin LookupGallery from LOM tracking rows');
assert_true(str_contains($runtime, 'function mergePatchChangeRecords'), 'runtime merges every Patch { } change record, not only the last argument');
assert_true(str_contains($runtime, 'function recordPairColon'), 'runtime parses quoted Patch keys such as @odata.type');
assert_true(str_contains($runtime, 'function fxEquals'), 'runtime treats Blank() = 0 as true for Admin number filters');
assert_true(str_contains($runtime, 'function hydrateFilterControls'), 'runtime inserts a blank choice on Admin Filter dropdowns');
assert_true(str_contains($runtime, 'function enrichTrackingFields'), 'runtime writes request_user.Email and Posted on submit');
assert_true(str_contains($runtime, '/^Int\\s*\\('), 'runtime implements Int() for LookupGallery visitor/site filters');
assert_true(str_contains($renderer, 'Filter$/i'), 'renderer leaves Admin Filter dropdowns and dates blank');
assert_true(
    str_contains($runtime, '"RequestUID"') && str_contains($runtime, '"RequestStatus"') && str_contains($runtime, '"MoreOptionsBtn"'),
    'runtime orders LookupGallery cells to match IR title columns'
);
assert_true(str_contains($runtime, 'unwrapParens'), 'runtime unwraps grouped !(varDebugMode || fnSubmitReady)');
assert_true(
    str_contains($runtime, 'Set\\s*\\(\\s*([A-Za-z_][\\w]*)\\s*,\\s*([\\s\\S]+)\\)\\s*$'),
    'runtime Set() accepts multiline If/With values used by submit'
);
assert_true(str_contains($html, 'data-control="BtnToggleDebugMode"'), 'BtnToggleDebugMode is emitted in Settings');
assert_true(str_contains($html, 'data-control="LookupGallery"'), 'LookupGallery is emitted for request lookup');

$css = (string) file_get_contents(dirname(__DIR__) . '/templates/web_package/assets/app.css');
assert_true(str_contains($css, '.lookup-row'), 'CSS lays out cloned LookupGallery rows');
assert_true(str_contains($css, '[data-control="RequestsGallery"]'), 'CSS lays out Home Edit Request rows');

$nodeBin = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($nodeBin !== '') {
    $js = <<<'JS'
function asBool(value) {
  if (value === true || value === 1 || value === "true") return true;
  if (value === false || value === 0 || value === "false" || value == null) return false;
  if (typeof value === "string" && /^(var|fn|gbl)[A-Z]\w*$/.test(value)) return false;
  return Boolean(value);
}
function namedFormula(name, env) {
  switch (name) {
    case "fnIsDev":
      return String(env.varEnv || "prod").toLowerCase() === "dev";
    case "fnDebugAllowed":
      return namedFormula("fnIsDev", env) || String((env.varCurrentUser || {}).role || "").toLowerCase() === "admin";
    case "fnRequestTitle": {
      const id = env.varRequestID;
      if (id == null || String(id).trim() === "" || String(id) === "-1") return null;
      return id;
    }
    case "fnSubmitReady":
      if (asBool(env.varDebugMode)) return true;
      return false;
    default:
      return undefined;
  }
}
function evalIdent(expr, env) {
  const named = namedFormula(expr, env);
  if (named !== undefined) return named;
  if (Object.prototype.hasOwnProperty.call(env, expr)) return env[expr];
  if (/^fn[A-Z]/.test(expr)) return null;
  return expr;
}
function notIdent(expr, env) {
  return !asBool(evalIdent(expr, env));
}
const admin = { varEnv: "prod", varCurrentUser: { role: "Admin" }, varDebugMode: false, varRequestID: "" };
if (!namedFormula("fnDebugAllowed", admin)) { console.error("admin should be allowed"); process.exit(1); }
if (notIdent("fnDebugAllowed", admin)) { console.error("!fnDebugAllowed should be false for admin"); process.exit(1); }
if (namedFormula("fnSubmitReady", admin)) { console.error("submit should be blocked until debug"); process.exit(1); }
admin.varDebugMode = true;
if (!namedFormula("fnSubmitReady", admin)) { console.error("debug mode must make fnSubmitReady"); process.exit(1); }
if (namedFormula("fnRequestTitle", admin) != null) { console.error("blank request id must be blank title"); process.exit(1); }
admin.varRequestID = "-1";
if (namedFormula("fnRequestTitle", admin) != null) { console.error("-1 request id must be blank title"); process.exit(1); }
console.log("ok");
JS;
    $tmpJs = sys_get_temp_dir() . '/ps_debug_' . bin2hex(random_bytes(3)) . '.js';
    file_put_contents($tmpJs, $js);
    $nodeOut = [];
    $nodeCode = 0;
    exec(escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmpJs) . ' 2>&1', $nodeOut, $nodeCode);
    @unlink($tmpJs);
    assert_true($nodeCode === 0 && implode("\n", $nodeOut) === 'ok', 'fnDebugAllowed / fnSubmitReady / fnRequestTitle contracts');

    $jsPatch = <<<'JS'
function recordPairColon(pair) {
  let quote = null;
  let depth = 0;
  const s = String(pair || "");
  for (let i = 0; i < s.length; i++) {
    const ch = s[i];
    if (quote) {
      if (ch === quote) quote = null;
      continue;
    }
    if (ch === '"' || ch === "'") { quote = ch; continue; }
    if (ch === "{" || ch === "(") depth++;
    else if (ch === "}" || ch === ")") depth--;
    else if (ch === ":" && depth === 0) return i;
  }
  return -1;
}
function fxEquals(a, b) {
  if (a === b) return true;
  const blankA = a == null || a === "";
  const blankB = b == null || b === "";
  if (blankA && blankB) return true;
  if (blankA && (b === 0 || b === "0" || b === false)) return true;
  if (blankB && (a === 0 || a === "0" || a === false)) return true;
  if ((typeof a === "number" || typeof b === "number") && a !== "" && b !== "") {
    const na = Number(a); const nb = Number(b);
    if (Number.isFinite(na) && Number.isFinite(nb)) return na === nb;
  }
  return String(a) === String(b);
}
const pair = "'@odata.type': \"#Microsoft.Azure.Connectors.SharePoint.SPListExpandedUser\"";
const idx = recordPairColon(pair);
if (idx < 0 || pair.slice(0, idx).trim() !== "'@odata.type'") { console.error("odata key"); process.exit(1); }
if (!fxEquals("", 0) || !fxEquals(null, 0) || fxEquals("Yes", 0)) { console.error("blank equals"); process.exit(1); }
const args = [
  "Coalesce(LookUp('CDLS (L) VCR Tracking List', Title = varSubmittedRequestUID), Defaults('CDLS (L) VCR Tracking List'))",
  "{ Title: varSubmittedRequestUID, Posted: \"false\" }",
  "frmAttach.Updates"
];
let firstRec = -1;
for (let i = 0; i < args.length; i++) {
  if (/^\s*\{/.test(args[i]) && firstRec < 0) firstRec = i;
}
if (firstRec !== 1) { console.error("last brace record"); process.exit(1); }
if (/Defaults\s*\(/i.test(args.slice(0, firstRec).join(", ")) && firstRec === 1) {
  // target still mentions Defaults, but insert vs update is decided by LookUp Id
}
console.log("ok");
JS;
    $tmpPatch = sys_get_temp_dir() . '/ps_patch_' . bin2hex(random_bytes(3)) . '.js';
    file_put_contents($tmpPatch, $jsPatch);
    $patchOut = [];
    $patchCode = 0;
    exec(escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmpPatch) . ' 2>&1', $patchOut, $patchCode);
    @unlink($tmpPatch);
    assert_true($patchCode === 0 && implode("\n", $patchOut) === 'ok', 'Patch record key / blank filter / trailing Updates contracts');
}

assert_true(!preg_match('/\[data-control="TransparentButtonContainer"\]\s*\{\s*display:\s*none/', $css), 'CSS no longer hard-hides settings host');
assert_true(str_contains($css, 'ps-settings-open'), 'CSS has settings-open topbar chrome');
assert_true(
    (bool) preg_match('/\[data-control="SettingsAndHelpCont"\][^}]*right:\s*0/', $css),
    'SettingsAndHelpCont is pinned to the visible top-right'
);
assert_true(
    (bool) preg_match('/\[data-control\^="TopbarHeader_"\][^}]*max-width:\s*100vw/', $css),
    'topbar cannot overflow the viewport and hide Settings'
);
assert_true(
    (bool) preg_match('/\[data-control\^="TopbarHeader_"\][^}]*top:\s*0/', $css),
    'topbar is pinned to the top of the screen'
);
assert_true(
    (bool) preg_match('/\[data-control="VCRHoldingContainer"\][^}]*calc\(100%\s*-\s*65px\)/', $css),
    'form holding container stays below the 65px topbar'
);
assert_true(
    (bool) preg_match('/\[data-control="Destination"\][^}]*pointer-events:\s*auto/', $css),
    'Destination dropdown stays clickable above the yellow section'
);
assert_true(
    (bool) preg_match('/\[data-control="TransparentButtonContainer"\][^}]*pointer-events:\s*none/', $css),
    'closed Settings host does not intercept Destination clicks'
);
assert_true(
    (bool) preg_match('/ps-settings-open \[data-control="TransparentButtonContainer"\][^}]*pointer-events:\s*auto/', $css),
    'open Settings host is interactive'
);

$nodeBin = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($nodeBin !== '') {
    $js = <<<'JS'
const fs = require("fs");
const vm = require("vm");
const code = fs.readFileSync(process.argv[2], "utf8");
const document = {
  readyState: "loading",
  addEventListener() {},
  getElementById() { return null; },
  querySelector() { return null; },
  querySelectorAll() { return []; },
  createElement() {
    return { style: {}, classList: { add() {}, toggle() {}, contains() { return false; } }, appendChild() {}, setAttribute() {} };
  },
  body: { appendChild() {} },
  documentElement: { classList: { toggle() {} }, setAttribute() {} },
};
const window = { document, PS_BASE: "" };
const ctx = { window, document, console, setTimeout, clearTimeout };
ctx.globalThis = ctx;
ctx.self = ctx;
vm.createContext(ctx);
vm.runInContext(code, ctx);
const rt = ctx.window.PowerSweeperRuntime;
if (!rt || !rt.isQuotedString) { console.error("missing isQuotedString"); process.exit(1); }
if (rt.isQuotedString('"hello"') !== true) { console.error("plain string"); process.exit(1); }
if (rt.isQuotedString('"Request " & varSubmittedRequestUID & " x"') !== false) { console.error("concat treated as string"); process.exit(1); }
rt.getState().globals.varSubmittedRequestUID = "FY26/27 - VCR-0003";
rt.getState().globals.varCurrentPackage = { ID: "FY26/27 - VCR-0003" };
rt.getState().globals.varRequestID = "FY26/27 - VCR-0003";
const toast = rt.evalExpr('"Request " & varSubmittedRequestUID & " has been successfully submitted."');
if (toast !== "Request FY26/27 - VCR-0003 has been successfully submitted.") {
  console.error("toast", toast);
  process.exit(1);
}
const subject = rt.evalExpr('"Visit Clearance Request (" & varCurrentPackage.ID & ") Submission Receipt"');
if (subject !== "Visit Clearance Request (FY26/27 - VCR-0003) Submission Receipt") {
  console.error("subject", subject);
  process.exit(1);
}
rt.bindDropdownValue("Destination", "United Kingdom");
if (rt.evalExpr("Destination.Selected.Value") !== "United Kingdom") {
  console.error("dest", rt.evalExpr("Destination.Selected.Value"));
  process.exit(1);
}
rt.getState().controlIndex.L1 = { node: { properties: { Items: "colL1Orgs", "Items.Value": "Name" } } };
rt.getState().globals.colL1Orgs = [
  { ID: 0, Name: "" },
  { ID: 1, Name: "Royal Canadian Navy" },
  { ID: 23, Name: "Ireland" }
];
rt.bindDropdownValue("L1", "Royal Canadian Navy");
if (String(rt.evalExpr("L1.Selected.ID")) !== "1" || rt.evalExpr("L1.Selected.Name") !== "Royal Canadian Navy") {
  console.error("l1", rt.evalExpr("L1.Selected.ID"), rt.evalExpr("L1.Selected.Name"));
  process.exit(1);
}
rt.getState().globals.varCurrentUser = { user: "preview@local", role: "Admin", fullName: "Preview User" };
const orgFx = 'With({ locMe: Office365Users.MyProfileV2({ \'$select\': "department,companyName,officeLocation,jobTitle" }) }, Coalesce(locMe.department, locMe.companyName, locMe.officeLocation, locMe.jobTitle))';
const org = rt.evalExpr(orgFx);
if (org != null && String(org).includes("department,companyName")) {
  console.error("org leaked $select", org);
  process.exit(1);
}
if (rt.evalExpr("User().Email") !== "preview@local") {
  console.error("user email", rt.evalExpr("User().Email"));
  process.exit(1);
}
rt.getState().globals.colSites = [{ ID: 1 }, { ID: 2 }];
rt.getState().controlIndex.galSites = { node: { properties: { Items: "colSites" } } };
const all = rt.evalExpr("galSites.AllItems");
if (!Array.isArray(all) || all.length !== 2) {
  console.error("allitems", all);
  process.exit(1);
}
console.log("ok");
JS;
    $tmpJs = sys_get_temp_dir() . '/ps_notify_' . bin2hex(random_bytes(3)) . '.js';
    file_put_contents($tmpJs, $js);
    $nodeOut = [];
    $nodeCode = 0;
    exec(
        escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmpJs) . ' ' . escapeshellarg(dirname(__DIR__) . '/templates/web_package/assets/runtime.js') . ' 2>&1',
        $nodeOut,
        $nodeCode
    );
    @unlink($tmpJs);
    assert_true(
        $nodeCode === 0 && implode("\n", $nodeOut) === 'ok',
        'Notify concat expands request IDs and Destination.Selected.Value binds: ' . implode("\n", $nodeOut)
    );
}

echo "settings_accessibility_test: all checks passed\n";
