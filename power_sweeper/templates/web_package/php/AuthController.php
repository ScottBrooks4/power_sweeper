<?php

declare(strict_types=1);

/**
 * MVC AuthController: Azure AD / OpenID Connect (authorization code + PKCE).
 *
 * Set auth.client_id (and optionally auth.tenant) in server/config.json, or
 * save them via the /auth/login setup form into data/aad_config.json.
 * With a client id, Sign in redirects to login.microsoftonline.com.
 */
$root = dirname(__DIR__);
require_once $root . '/server/GraphGateway.php';

$configPath = $root . '/server/config.json';
$config = is_file($configPath)
    ? (json_decode((string) file_get_contents($configPath), true) ?: [])
    : [];
if (!is_array($config)) {
    $config = [];
}
$localAad = $root . '/data/aad_config.json';
if (is_file($localAad)) {
    $overlay = json_decode((string) file_get_contents($localAad), true);
    if (is_array($overlay)) {
        $config['auth'] = array_merge(
            is_array($config['auth'] ?? null) ? $config['auth'] : [],
            $overlay,
        );
    }
}

$graph = new GraphGateway($root, $config);
$auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
$action = (string) ($_GET['action'] ?? basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'login'));

$base = '';
$script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if (preg_match('#^(.*?/web/[^/]+)#', $script, $m) === 1) {
    $base = $m[1];
} elseif (preg_match('#^(.*?/web/[^/]+)#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $m) === 1) {
    $base = $m[1];
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$redirectPath = ($base !== '' ? $base : '') . '/auth/callback';
$redirectUri = $scheme . '://' . $host . $redirectPath;

if ($action === 'logout' || str_ends_with($action, 'logout')) {
    $graph->signOut();
    header('Location: ' . ($base !== '' ? $base . '/' : '/'));
    exit;
}

if ($action === 'save' || str_ends_with($action, 'save')) {
    $clientId = trim((string) ($_POST['client_id'] ?? ''));
    $tenant = trim((string) ($_POST['tenant'] ?? 'common')) ?: 'common';
    if ($clientId === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $clientId)) {
        auth_setup_page($base, $redirectUri, 'Enter a valid Application (client) ID (GUID).', $clientId, $tenant);
        exit;
    }
    $dir = $root . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents(
        $localAad,
        json_encode([
            'mode' => 'live',
            'client_id' => $clientId,
            'tenant' => $tenant,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    header('Location: ' . ($base !== '' ? $base . '/auth/login' : '/auth/login'));
    exit;
}

if ($action === 'callback' || str_ends_with($action, 'callback')) {
    $err = (string) ($_GET['error_description'] ?? $_GET['error'] ?? '');
    if ($err !== '') {
        auth_setup_page($base, $redirectUri, 'Microsoft sign-in failed: ' . $err, (string) ($auth['client_id'] ?? ''), (string) ($auth['tenant'] ?? 'common'));
        exit;
    }
    $code = (string) ($_GET['code'] ?? '');
    $state = (string) ($_GET['state'] ?? '');
    $result = $graph->completeOidcLogin($code, $state, $redirectUri);
    if (empty($result['ok'])) {
        auth_setup_page(
            $base,
            $redirectUri,
            'Token exchange failed: ' . (string) ($result['error'] ?? 'unknown'),
            (string) ($auth['client_id'] ?? ''),
            (string) ($auth['tenant'] ?? 'common'),
        );
        exit;
    }
    header('Location: ' . ($base !== '' ? $base . '/' : '/'));
    exit;
}

if ($action === 'demo' || str_ends_with($action, 'demo')) {
    $graph->signIn([
        'name' => 'Demo M365 User',
        'preferred_username' => 'demo.user@forces.gc.ca',
        'oid' => 'demo-user',
        'tid' => (string) ($auth['tenant'] ?? 'common'),
        'auth' => 'oidc_demo',
    ]);
    header('Location: ' . ($base !== '' ? $base . '/' : '/'));
    exit;
}

// login
$clientId = trim((string) ($auth['client_id'] ?? getenv('AAD_CLIENT_ID') ?: ''));
$tenant = trim((string) ($auth['tenant'] ?? getenv('AAD_TENANT') ?: 'common')) ?: 'common';
$mode = strtolower((string) ($auth['mode'] ?? ($clientId !== '' ? 'live' : 'demo')));

if ($clientId === '' || $mode === 'setup') {
    auth_setup_page($base, $redirectUri, '', $clientId, $tenant);
    exit;
}

$start = $graph->beginOidcLogin($redirectUri);
if (empty($start['ok'])) {
    auth_setup_page($base, $redirectUri, (string) ($start['error'] ?? 'Could not start login'), $clientId, $tenant);
    exit;
}
header('Location: ' . (string) $start['url']);
exit;

/**
 * @param string $base
 * @param string $redirectUri
 * @param string $error
 * @param string $clientId
 * @param string $tenant
 */
function auth_setup_page(string $base, string $redirectUri, string $error, string $clientId, string $tenant): void
{
    header('Content-Type: text/html; charset=utf-8');
    $home = htmlspecialchars($base !== '' ? $base . '/' : '/', ENT_QUOTES);
    $save = htmlspecialchars($base !== '' ? $base . '/auth/save' : '/auth/save', ENT_QUOTES);
    $demo = htmlspecialchars($base !== '' ? $base . '/auth/demo' : '/auth/demo', ENT_QUOTES);
    $redir = htmlspecialchars($redirectUri, ENT_QUOTES);
    $cid = htmlspecialchars($clientId, ENT_QUOTES);
    $ten = htmlspecialchars($tenant !== '' ? $tenant : 'common', ENT_QUOTES);
    $errHtml = $error !== ''
        ? '<p class="err">' . htmlspecialchars($error, ENT_QUOTES) . '</p>'
        : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in with Microsoft</title>
  <style>
    body { font: 15px/1.45 system-ui, sans-serif; margin: 0; background: #eef1f6; color: #0f172a; }
    main { max-width: 34rem; margin: 3rem auto; background: #fff; padding: 1.75rem 1.5rem; border-radius: 12px; box-shadow: 0 8px 30px rgba(15,23,42,.08); }
    h1 { font-size: 1.35rem; margin: 0 0 .5rem; }
    p, li { color: #334155; }
    code, .uri { font: 12px/1.4 ui-monospace, monospace; background: #f1f5f9; padding: .15rem .35rem; border-radius: 4px; word-break: break-all; }
    label { display: block; font-weight: 600; margin: 1rem 0 .35rem; }
    input { width: 100%; box-sizing: border-box; padding: .55rem .65rem; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; }
    button, .btn { display: inline-block; margin-top: 1rem; margin-right: .5rem; padding: .55rem 1rem; border-radius: 8px; border: 0; background: #0f3d2e; color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
    .btn.secondary { background: #64748b; }
    .err { background: #fef2f2; color: #991b1b; padding: .65rem .8rem; border-radius: 8px; }
    ol { padding-left: 1.2rem; }
  </style>
</head>
<body>
<main>
  <h1>Sign in with Microsoft</h1>
  <p>Use your normal work or school Microsoft account (Azure AD / Entra ID). Register a public client app once, then sign in here.</p>
  {$errHtml}
  <ol>
    <li>Open <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">Azure App registrations</a> → <strong>New registration</strong>.</li>
    <li>Supported account types: single tenant or “accounts in any org directory”.</li>
    <li>Redirect URI → platform <strong>Mobile and desktop applications</strong>, value:<br><span class="uri">{$redir}</span></li>
    <li>Authentication → <strong>Allow public client flows</strong> = Yes (no client secret; we use PKCE).</li>
    <li>Copy the <strong>Application (client) ID</strong>.</li>
    <li>API permissions → Microsoft Graph delegated <code>User.Read</code> (add <code>Mail.Send</code> for live mail) → Grant admin consent if required.</li>
  </ol>
  <form method="post" action="{$save}">
    <label for="client_id">Application (client) ID</label>
    <input id="client_id" name="client_id" value="{$cid}" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required>
    <label for="tenant">Tenant</label>
    <input id="tenant" name="tenant" value="{$ten}" placeholder="common  or  your-tenant.onmicrosoft.com  or  directory GUID">
    <button type="submit">Save &amp; continue to Microsoft</button>
  </form>
  <p><a class="btn secondary" href="{$demo}">Use demo user instead</a>
  <a class="btn secondary" href="{$home}">Cancel</a></p>
</main>
</body>
</html>
HTML;
}
