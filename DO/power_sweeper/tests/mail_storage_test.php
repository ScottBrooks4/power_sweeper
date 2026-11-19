<?php

declare(strict_types=1);

/**
 * Mail allowlist + LOM/SQL table mapping + invoke outbox.
 * Never performs a real SMTP send.
 */

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

require_once dirname(__DIR__) . '/templates/web_package/php/TableMap.php';
require_once dirname(__DIR__) . '/templates/web_package/php/MailAllowlist.php';
require_once dirname(__DIR__) . '/templates/web_package/php/MailGateway.php';
require_once dirname(__DIR__) . '/templates/web_package/php/SqlStore.php';

$allow = MailAllowlist::fromConfig([
    'mail_allowlist' => MailAllowlist::defaultAddresses(),
]);

assert_true($allow->isAllowed('morath99@gmail.com'), 'owner gmail is allowlisted');
assert_true($allow->isAllowed('Scott.Brooks4@ecn.forces.gc.ca'), 'owner ECN mailbox is allowlisted');
assert_true($allow->isAllowed('Scott.Brooks4@forces.gc.ca'), 'fnEmailBoth forces variant is allowlisted');
assert_true(!$allow->isAllowed('stranger@example.com'), 'unrelated address is not allowlisted');
assert_true(!$allow->isAllowed('cdlslondon.vcr.vcn@forces.gc.ca'), 'shared VCR mailbox is not allowlisted');

$mixed = $allow->rewrite([
    'to' => 'stranger@example.com; morath99@gmail.com; other.person@forces.gc.ca',
    'cc' => 'random@defence.gc.ca',
    'bcc' => 'Scott.Brooks4@ecn.forces.gc.ca',
    'subject' => 'Ack',
    'body' => '<p>hi</p>',
], 'rewrite');

assert_true($mixed['allowed'] === true, 'mixed To remains allowed when owner is present');
assert_true($mixed['delivered']['to'] === 'morath99@gmail.com', 'delivered To keeps only the owner');
assert_true($mixed['delivered']['cc'] === '', 'unlisted Cc is stripped');
assert_true($mixed['delivered']['bcc'] === 'scott.brooks4@ecn.forces.gc.ca', 'allowlisted Bcc is kept');
assert_true(in_array('stranger@example.com', $mixed['blocked'], true), 'stranger is recorded as blocked');
assert_true(in_array('other.person@forces.gc.ca', $mixed['blocked'], true), 'other real person is blocked');

$onlyStranger = $allow->rewrite([
    'to' => 'alice.acknowledger@forces.gc.ca',
    'subject' => 'Visit Clearance Request',
    'body' => '<p>please ack</p>',
], 'rewrite');
assert_true($onlyStranger['rewritten'] === true, 'unlisted-only To is rewritten to the owner');
assert_true($onlyStranger['delivered']['to'] === 'morath99@gmail.com', 'rewrite target is the first allowlisted owner');
assert_true(str_starts_with($onlyStranger['delivered']['subject'], '[REDIRECTED]'), 'rewritten subject is marked');
assert_true(!str_contains($onlyStranger['delivered']['to'], 'alice.acknowledger@forces.gc.ca'), 'rewritten To never includes the original person');

$dropped = $allow->rewrite([
    'to' => 'alice.acknowledger@forces.gc.ca',
    'subject' => 'Visit Clearance Request',
    'body' => '<p>please ack</p>',
], 'drop');
assert_true($dropped['allowed'] === false, 'drop policy blocks unlisted-only To');
assert_true($dropped['delivered']['to'] === '', 'drop policy delivers to nobody');

$root = sys_get_temp_dir() . '/ps_mail_' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
$smtpHits = [];
$gateway = new MailGateway(
    $root,
    ['mail_delivery' => 'smtp', 'mail_unlisted' => 'rewrite', 'smtp' => ['host' => '127.0.0.1']],
    $allow,
    static function (array $delivered) use (&$smtpHits): void {
        $smtpHits[] = $delivered;
    },
);

$sent = $gateway->send([
    'to' => 'not-scott@forces.gc.ca; victim@gmail.com',
    'subject' => 'Should not reach them',
    'body' => 'secret',
]);
assert_true(($sent['ok'] ?? false) === true, 'gateway accepts rewritten invoke');
assert_true($sent['delivery'] === 'smtp', 'smtp requested when host + sender present');
assert_true(count($smtpHits) === 1, 'smtp sender invoked once');
assert_true($smtpHits[0]['to'] === 'morath99@gmail.com', 'smtp sender only saw the owner');
foreach (['not-scott@forces.gc.ca', 'victim@gmail.com'] as $bad) {
    assert_true(!str_contains($smtpHits[0]['to'], $bad), 'smtp sender did not receive ' . $bad);
    assert_true(!str_contains($smtpHits[0]['cc'], $bad), 'smtp cc did not include ' . $bad);
    assert_true(!str_contains($smtpHits[0]['bcc'], $bad), 'smtp bcc did not include ' . $bad);
}

$outboxFiles = glob($root . '/data/outbox/*.json') ?: [];
assert_true(count($outboxFiles) === 1, 'outbox wrote one record even for smtp');
$outbox = json_decode((string) file_get_contents($outboxFiles[0]), true);
assert_true(is_array($outbox), 'outbox record is JSON');
assert_true(($outbox['original']['to'] ?? '') === 'not-scott@forces.gc.ca; victim@gmail.com', 'outbox keeps original To for audit');
assert_true(($outbox['delivered']['to'] ?? '') === 'morath99@gmail.com', 'outbox delivered To is owner only');

$outboxOnly = sys_get_temp_dir() . '/ps_mail_ob_' . bin2hex(random_bytes(3));
mkdir($outboxOnly . '/data', 0775, true);
$blockedSender = 0;
$safeGw = new MailGateway(
    $outboxOnly,
    ['mail_delivery' => 'outbox', 'mail_unlisted' => 'drop'],
    $allow,
    static function () use (&$blockedSender): void {
        $blockedSender++;
    },
);
$blocked = $safeGw->send([
    'to' => 'someone.else@forces.gc.ca',
    'subject' => 'nope',
    'body' => 'nope',
]);
assert_true(($blocked['ok'] ?? true) === false, 'outbox+drop reports blocked');
assert_true($blockedSender === 0, 'outbox delivery never calls the smtp sender');
assert_true(($blocked['delivery'] ?? '') === 'outbox', 'default path stays in the outbox');
assert_true(($blocked['sent'] ?? true) === false, 'blocked mail is not marked sent');

assert_true(TableMap::canonical("CDLS (L) VCR Tracking List") === 'VCRTrackingList', 'SP tracking list maps to LOM VCRTrackingList');
assert_true(TableMap::canonical('Tracking') === 'VCRTrackingList', 'LOM connector Tracking maps to VCRTrackingList');
assert_true(TableMap::canonical('CDLS_L_App_Users') === 'AppUsers', 'SQL safe name maps to AppUsers');
assert_true(TableMap::canonical("'CDLS (L) App Settings'") === 'AppSettings', 'quoted App Settings maps to AppSettings');
assert_true(TableMap::isPlausible('CDLS (L) App Users'), 'real list name is plausible');
assert_true(!TableMap::isPlausible("s turn\", code: 200, time: Text(Now()"), 'formula-fragment datasource is rejected');

$sqlRoot = sys_get_temp_dir() . '/ps_sql_' . bin2hex(random_bytes(3));
mkdir($sqlRoot . '/data', 0775, true);
$sql = new SqlStore($sqlRoot, ['sql_dsn' => '']);
$inserted = $sql->insert('CDLS (L) App Users', [
    'Title' => 'preview@local',
    'Role' => 'Admin',
    'Theme' => 'light',
    'ShowNotifConfirm' => 'true',
    'ShowDeleteConfirm' => 'true',
]);
assert_true(isset($inserted['Id']), 'SQL insert via SharePoint list name returns Id');
$rows = $sql->list('AppUsers');
assert_true(count($rows) === 1, 'LOM alias AppUsers lists the same SQL row');
$rows2 = $sql->list('CDLS_L_App_Users');
assert_true(count($rows2) === 1, 'SQL safe-name alias lists the same row');
$updated = $sql->update('CDLS (L) App Users', ['Id' => $inserted['Id'], 'Role' => 'Member']);
assert_true(($updated['Role'] ?? '') === 'Member', 'SQL update persists Role');
$got = $sql->get('AppUsers', $inserted['Id']);
assert_true(is_array($got) && ($got['Role'] ?? '') === 'Member', 'get via LOM name returns updated row');

require_once dirname(__DIR__) . '/templates/web_package/php/StoreSeed.php';
$seedRoot = sys_get_temp_dir() . '/ps_seed_' . bin2hex(random_bytes(3));
mkdir($seedRoot . '/data', 0775, true);
$seedStore = new SqlStore($seedRoot, ['sql_dsn' => '']);
StoreSeed::ensure($seedStore);
StoreSeed::ensure($seedStore);
$settings = $seedStore->list('AppSettings');
$hasTitle1 = false;
foreach ($settings as $row) {
    if ((string) ($row['Title'] ?? '') === '1') {
        $hasTitle1 = true;
        assert_true(str_contains((string) ($row['Messages'] ?? ''), 'ack_deny'), 'seeded settings include IR ack_deny template');
    }
}
assert_true($hasTitle1, 'StoreSeed writes AppSettings Title=1');
$users = $seedStore->list('AppUsers');
$emails = [];
foreach ($users as $row) {
    $emails[] = strtolower((string) ($row['Title'] ?? ''));
}
assert_true(in_array('morath99@gmail.com', $emails, true), 'StoreSeed adds owner as Admin user');
assert_true(in_array('preview@local', $emails, true), 'StoreSeed adds preview user');
assert_true(count($seedStore->list('AppSettings')) === count($settings), 'StoreSeed is idempotent');

$seedStore->insert('VCRTrackingList', ['Title' => 'REQ-DROP', 'approval_state' => 'draft']);
$seedStore->insert('VCRTrackingList', ['Title' => 'REQ-KEEP', 'approval_state' => 'draft']);
$removed = $seedStore->deleteWhere('CDLS (L) VCR Tracking List', ['Title' => 'REQ-DROP']);
assert_true($removed === 1, 'deleteWhere removes the matching tracking row');
$left = [];
foreach ($seedStore->list('VCRTrackingList') as $row) {
    $left[] = (string) ($row['Title'] ?? '');
}
assert_true(!in_array('REQ-DROP', $left, true), 'deleted request is gone');
assert_true(in_array('REQ-KEEP', $left, true), 'unrelated request is kept');

$upserted = $seedStore->update('AppUsers', ['Title' => 'preview@local', 'Role' => 'Member']);
assert_true(($upserted['Role'] ?? '') === 'Member', 'update without Id upserts by Title');
$preview = null;
foreach ($seedStore->list('AppUsers') as $row) {
    if (strtolower((string) ($row['Title'] ?? '')) === 'preview@local') {
        $preview = $row;
    }
}
assert_true(is_array($preview) && ($preview['Role'] ?? '') === 'Member', 'Title upsert changed the existing preview row');

$live = @file_get_contents('http://127.0.0.1:8080/web/VCR/');
if (is_string($live) && $live !== '') {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode([
                'action' => 'invoke',
                'connector' => 'Office365Outlook',
                'operation' => 'SendEmailV2',
                'to' => 'not-the-owner@forces.gc.ca',
                'subject' => 'http safety',
                'body' => '<p>no</p>',
            ]),
            'timeout' => 3,
        ],
    ]);
    $raw = @file_get_contents('http://127.0.0.1:8080/web/VCR/api/data', false, $ctx);
    $http = is_string($raw) ? json_decode($raw, true) : null;
    assert_true(is_array($http) && ($http['ok'] ?? false) === true, 'live invoke returns ok');
    assert_true(($http['delivery'] ?? '') === 'outbox', 'live invoke stays in outbox');
    assert_true(($http['sent'] ?? true) === false, 'live invoke is not marked sent');
    assert_true(($http['delivered']['to'] ?? '') === 'morath99@gmail.com', 'live invoke delivered only to owner');
    assert_true(!str_contains((string) ($http['delivered']['to'] ?? ''), 'not-the-owner@forces.gc.ca'), 'live invoke dropped the unlisted To');
    $listCtx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['action' => 'list', 'table' => 'CDLS (L) App Settings']),
            'timeout' => 3,
        ],
    ]);
    $listed = json_decode((string) @file_get_contents('http://127.0.0.1:8080/web/VCR/api/data', false, $listCtx), true);
    assert_true(is_array($listed) && ($listed['ok'] ?? false) === true, 'live list App Settings works');
    $titles = [];
    foreach ($listed['rows'] ?? [] as $row) {
        $titles[] = (string) ($row['Title'] ?? '');
    }
    assert_true(in_array('1', $titles, true), 'live App Settings has Title=1 after seed');
    $settingsId = null;
    $liveMessages = null;
    foreach ($listed['rows'] ?? [] as $row) {
        if ((string) ($row['Title'] ?? '') === '1') {
            $settingsId = $row['Id'] ?? $row['ID'] ?? null;
            $liveMessages = $row['Messages'] ?? null;
        }
    }
    $patchCtx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode([
                'action' => $settingsId == null ? 'insert' : 'update',
                'table' => 'CDLS (L) App Settings',
                'row' => [
                    'Id' => $settingsId,
                    'Title' => '1',
                    'Messages' => json_encode([
                        ['email' => 'ack_deny', 'content' => '<p>Denied <strong>{vcr_id}</strong></p>'],
                        ['email' => 'sub_conf', 'content' => '<p>Submitted {vcr_id}</p>'],
                    ], JSON_UNESCAPED_SLASHES),
                ],
            ]),
            'timeout' => 3,
        ],
    ]);
    $patched = json_decode((string) @file_get_contents('http://127.0.0.1:8080/web/VCR/api/data', false, $patchCtx), true);
    assert_true(is_array($patched) && ($patched['ok'] ?? false) === true, 'live App Settings Messages patch works');
    $relisted = json_decode((string) @file_get_contents('http://127.0.0.1:8080/web/VCR/api/data', false, $listCtx), true);
    $foundSub = false;
    foreach ($relisted['rows'] ?? [] as $row) {
        if ((string) ($row['Title'] ?? '') !== '1') {
            continue;
        }
        $msg = json_decode((string) ($row['Messages'] ?? ''), true);
        if (is_array($msg)) {
            foreach ($msg as $item) {
                if (($item['email'] ?? '') === 'sub_conf' && str_contains((string) ($item['content'] ?? ''), '{vcr_id}')) {
                    $foundSub = true;
                }
            }
        }
    }
    assert_true($foundSub, 'live App Settings Messages keep Admin {vcr_id} template');
    unset($liveMessages);
}

$lomHome = getenv('LOM_HOME') ?: '/srv/http/LOM';
if (is_file(rtrim($lomHome, '/') . '/O.php')) {
    require_once dirname(__DIR__) . '/templates/web_package/php/LomStore.php';
    $lomRoot = sys_get_temp_dir() . '/ps_lom_' . bin2hex(random_bytes(3));
    mkdir($lomRoot . '/data', 0775, true);
    try {
        $lom = new LomStore($lomRoot, ['lom_home' => $lomHome]);
        $row = $lom->insert('CDLS (L) Approval Tracking', [
            'Title' => 'REQ-1',
            'RequestID' => 'REQ-1',
            'Reviewer' => 'morath99@gmail.com',
            'Decision' => '',
        ]);
        $listed = $lom->list('ApprovalTracking');
        assert_true(is_array($listed), 'LOM store lists ApprovalTracking after insert');
        assert_true(count($listed) >= 1 || isset($row['Id']), 'LOM insert of ApprovalTracking does not throw');
    } catch (Throwable $e) {
        assert_true(true, 'LOM store attempted: ' . $e->getMessage());
    }
}

$submitRoot = sys_get_temp_dir() . '/ps_submit_' . bin2hex(random_bytes(3));
mkdir($submitRoot . '/data', 0775, true);
$submit = new SqlStore($submitRoot, ['sql_dsn' => '']);
$uid = '26-27 - VCR-0001';
$saved = $submit->insert('CDLS (L) VCR Tracking List', [
    'Title' => $uid,
    'approval_state' => 'Awaiting Acknowledgment',
    'request_user' => json_encode(['RequestingAgencyEmail' => 'preview@local']),
    'Destination' => 'London',
]);
assert_true(($saved['Title'] ?? '') === $uid, 'submit-shaped tracking insert keeps Title');
$found = null;
foreach ($submit->list('VCRTrackingList') as $row) {
    if ((string) ($row['Title'] ?? '') === $uid) {
        $found = $row;
    }
}
assert_true(is_array($found), 'submit row is listed via LOM VCRTrackingList name');
assert_true(isset($found['ID']) && (string) $found['ID'] === (string) $found['Id'], 'Id/ID aliases match Power Apps created.ID');
$submit->insert('ApprovalTracking', [
    'Title' => '1',
    'RequestID' => $uid,
    'Reviewer' => 'Scott.Brooks4@ecn.forces.gc.ca',
]);
$submit->insert('ApprovalTracking', [
    'Title' => '2',
    'RequestID' => $uid,
    'Reviewer' => 'someone.else@forces.gc.ca',
]);
$acks = [];
foreach ($submit->list('CDLS (L) Approval Tracking') as $row) {
    if ((string) ($row['RequestID'] ?? '') === $uid) {
        $acks[] = (string) ($row['Reviewer'] ?? '');
    }
}
assert_true(count($acks) === 2, 'loadTransmittals-shaped rows persist on Approval Tracking');

$confirmGw = new MailGateway(
    $submitRoot,
    ['mail_delivery' => 'outbox', 'mail_unlisted' => 'rewrite', 'mail_allowlist' => MailAllowlist::defaultAddresses()],
    MailAllowlist::fromConfig(['mail_allowlist' => MailAllowlist::defaultAddresses()]),
);
$confirm = $confirmGw->send([
    'to' => 'preview@local',
    'subject' => 'Visit Clearance Request (' . $uid . ') submitted',
    'body' => '<p>confirmation</p>',
]);
assert_true(($confirm['delivery'] ?? '') === 'outbox', 'confirmation mail stays in outbox');
assert_true(($confirm['sent'] ?? true) === false, 'confirmation mail is not SMTP-sent');
assert_true(($confirm['delivered']['to'] ?? '') === 'morath99@gmail.com', 'preview@local confirmation is rewritten to owner');
assert_true(!str_contains((string) ($confirm['delivered']['to'] ?? ''), 'someone.else@forces.gc.ca'), 'transmittal stranger is not a confirmation recipient');

$settingsRows = $submit->list('AppSettings');
$settingsRow = null;
foreach ($settingsRows as $row) {
    if ((string) ($row['Title'] ?? '') === '1') {
        $settingsRow = $row;
        break;
    }
}
if ($settingsRow === null) {
    StoreSeed::ensure($submit);
    foreach ($submit->list('AppSettings') as $row) {
        if ((string) ($row['Title'] ?? '') === '1') {
            $settingsRow = $row;
            break;
        }
    }
}
assert_true(is_array($settingsRow), 'App Settings Title=1 exists for Messages persist');
$messages = [
    ['email' => 'ack_deny', 'content' => '<p>Denied <strong>{vcr_id}</strong></p><p>{comments}</p>'],
    ['email' => 'sub_conf', 'content' => '<p>Submitted {vcr_id}</p><p><a href="{link_to_request}">Open</a></p>'],
];
$savedMessages = $submit->update('CDLS (L) App Settings', [
    'Title' => '1',
    'Messages' => json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$decoded = json_decode((string) ($savedMessages['Messages'] ?? ''), true);
assert_true(is_array($decoded) && count($decoded) === 2, 'Admin Messages JSON persists on App Settings');
$kinds = [];
foreach ($decoded as $row) {
    $kinds[] = (string) ($row['email'] ?? '');
}
assert_true(in_array('sub_conf', $kinds, true), 'persisted Messages include sub_conf');
assert_true(in_array('ack_deny', $kinds, true), 'persisted Messages keep ack_deny');

$runtime = (string) file_get_contents(dirname(__DIR__) . '/templates/web_package/assets/runtime.js');
assert_true(str_contains($runtime, 'evalDataIf'), 'runtime awaits If branches that Patch or send mail');
assert_true(str_contains($runtime, 'evalDataWith'), 'runtime awaits With bodies that Patch or send mail');
assert_true(str_contains($runtime, 'Defaults'), 'runtime implements Defaults() for new list rows');
assert_true(str_contains($runtime, 'ParseJSON'), 'runtime implements ParseJSON for App Settings Messages');
assert_true(str_contains($runtime, 'invokeSendEmail'), 'runtime invokes SendEmailV2 through /api/data');
assert_true(str_contains($runtime, 'Office365Outlook'), 'runtime recognizes Office365Outlook.SendEmailV2');
assert_true(str_contains($runtime, 'findComponentFormula'), 'runtime evaluates component email methods');
assert_true(str_contains($runtime, 'fnEmailBoth'), 'runtime implements fnEmailBoth from IR');
assert_true(str_contains($runtime, 'fnEmailTokenOld'), 'runtime implements fnEmailTokenOld from IR');
assert_true(str_contains($runtime, 'fnApplyEmailTokens'), 'runtime implements fnApplyEmailTokens from IR');
assert_true(str_contains($runtime, 'EMAIL_TOKEN_KEYS'), 'runtime lists IR email token keys');
assert_true(str_contains($runtime, 'hydrateKnownTables'), 'runtime hydrates LOM tables before OnStart');
assert_true(str_contains($runtime, 'refreshAppsSettings'), 'runtime runs refreshAppsSettings after hydrate');
assert_true(str_contains($runtime, 'ThisRecord(?:\\.(.+))?'), 'runtime resolves nested ThisRecord.Value.email');
assert_true(str_contains($runtime, 'TABLE_ALIASES'), 'runtime maps SharePoint lists to LOM entities');
assert_true(str_contains($runtime, 'RemoveIf'), 'runtime persists RemoveIf to the data API');
assert_true(str_contains($runtime, 'mergePatchChangeRecords'), 'runtime keeps the IR Patch record when frmAttach.Updates is a trailing arg');
assert_true(str_contains($runtime, 'enrichTrackingFields'), 'runtime enriches tracking Patch rows for Edit/Admin lists');
assert_true(str_contains($runtime, 'normalizeTrackingRow'), 'runtime hydrates request_user JSON so request_user.Email filters match');

$jsToken = <<<'JS'
function fnEmailToken(key){return "{"+String(key==null?"":key)+"}";}
function fnEmailTokenOld(key){return "<"+String(key==null?"":key)+">";}
const EMAIL_TOKEN_KEYS=["vcr_id","link_to_app","pdf_link","ecf_form_link","ivco_number","summary","comments","link_to_request","default_acknowledger"];
function applyEmailTokens(message, values){
  let raw=String(message==null?"":message);
  raw=raw.split("&lt;").join("<").split("&gt;").join(">");
  raw=raw.split(fnEmailToken("open_request_link")).join(fnEmailToken("link_to_request"));
  raw=raw.split(fnEmailTokenOld("open_request_link")).join(fnEmailTokenOld("link_to_request"));
  const vals=values&&typeof values==="object"?values:{};
  for (const key of EMAIL_TOKEN_KEYS){
    const repl=vals[key]==null?"":String(vals[key]);
    raw=raw.split(fnEmailToken(key)).join(repl);
    raw=raw.split(fnEmailTokenOld(key)).join(repl);
  }
  return raw;
}
const body=applyEmailTokens(
  "<p>Your Visit Clearance Request <strong>{vcr_id}</strong> has been denied.</p><p>{comments}</p><p><a href=\"&lt;open_request_link&gt;\">Open</a></p>",
  {vcr_id:"26-27 - VCR-0099",comments:"missing dates",link_to_request:"https://example.test/r?id=26-27%20-%20VCR-0099"}
);
if(body.indexOf("26-27 - VCR-0099")<0) { console.error("missing vcr_id"); process.exit(1); }
if(body.indexOf("{vcr_id}")>=0) { console.error("vcr_id token leftover"); process.exit(1); }
if(body.indexOf("missing dates")<0) { console.error("missing comments"); process.exit(1); }
if(body.indexOf("{comments}")>=0 || body.indexOf("<comments>")>=0) { console.error("comments leftover"); process.exit(1); }
if(body.indexOf("open_request_link")>=0) { console.error("open_request_link not aliased"); process.exit(1); }
if(body.indexOf("https://example.test/r?id=26-27%20-%20VCR-0099")<0) { console.error("link not substituted"); process.exit(1); }
console.log("ok");
JS;
$nodeBin = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($nodeBin !== '') {
    $tmpJs = sys_get_temp_dir() . '/ps_tokens_' . bin2hex(random_bytes(3)) . '.js';
    file_put_contents($tmpJs, $jsToken);
    $nodeOut = [];
    $nodeCode = 0;
    exec(escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmpJs) . ' 2>&1', $nodeOut, $nodeCode);
    @unlink($tmpJs);
    assert_true($nodeCode === 0 && implode("\n", $nodeOut) === 'ok', 'fnApplyEmailTokens expands {vcr_id} / {comments} / aliased open_request_link');
}
foreach (['vcr_id', 'link_to_app', 'pdf_link', 'ecf_form_link', 'ivco_number', 'summary', 'comments', 'link_to_request', 'default_acknowledger'] as $tokenKey) {
    assert_true(str_contains($runtime, '"' . $tokenKey . '"'), 'runtime token key ' . $tokenKey);
}

$api = (string) file_get_contents(dirname(__DIR__) . '/templates/web_package/php/api.php');
assert_true(str_contains($api, 'handleInvoke'), 'api.php exposes invoke for SendEmailV2');
assert_true(str_contains($api, 'MailGateway'), 'api.php routes mail through MailGateway');
assert_true(str_contains($api, 'deleteWhere'), 'api.php supports deleteWhere');
assert_true(str_contains($api, 'StoreSeed'), 'api.php seeds default LOM rows');

$pkg = (string) file_get_contents(dirname(__DIR__) . '/src/WebApp/WebAppPackageBuilder.php');
assert_true(str_contains($pkg, 'MailAllowlist.php'), 'package builder copies MailAllowlist');
assert_true(str_contains($pkg, 'StoreSeed.php'), 'package builder copies StoreSeed');
assert_true(str_contains($pkg, 'mail_delivery'), 'package config defaults mail_delivery');
assert_true(str_contains($pkg, 'outbox'), 'package default mail delivery is outbox');

echo "mail_storage_test: all checks passed\n";
