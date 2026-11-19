<?php

declare(strict_types=1);

/**
 * MVC ScreenController: serve the painted app (same layout as canvas).
 * Optional ?screen=Name activates that screen on load.
 */
$root = dirname(__DIR__);
require_once $root . '/models/AppModel.php';
require_once $root . '/models/GraphModel.php';

$appModel = new AppModel($root);
$graphModel = new GraphModel($root);
unset($appModel);

$index = $root . '/public/index.html';
if (!is_file($index)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing public/index.html\n";
    return;
}

$html = (string) file_get_contents($index);
$screen = (string) ($_GET['screen'] ?? '');
if ($screen !== '') {
    $safe = htmlspecialchars($screen, ENT_QUOTES);
    $html = preg_replace(
        '/data-start-screen="[^"]*"/',
        'data-start-screen="' . $safe . '"',
        $html,
        1,
    ) ?? $html;
    $html = preg_replace('/\sscreen--active/', '', $html) ?? $html;
    $html = preg_replace(
        '/(<section\b[^>]*\bdata-screen="' . preg_quote($safe, '/') . '"[^>]*)\bclass="([^"]*)"/',
        '$1 class="$2 screen--active"',
        $html,
        1,
    ) ?? $html;
}

$user = $graphModel->currentUser();
$status = $graphModel->status();
$clientId = trim((string) ($status['client_id'] ?? ''));
$aadMode = strtolower((string) ($status['auth_mode'] ?? ($clientId !== '' ? 'live' : 'demo')));
if ($user === null) {
    $label = $clientId !== '' || $aadMode === 'live'
        ? 'Sign in with Microsoft'
        : 'Sign in with Microsoft';
    $chip = '<a class="mvc-auth-chip" href="auth/login" title="Azure AD / OpenID Connect">'
        . htmlspecialchars($label, ENT_QUOTES) . '</a>';
} else {
    $who = (string) ($user['displayName'] ?? $user['mail'] ?? 'Signed in');
    $via = (string) ($user['auth'] ?? $aadMode);
    $chip = '<span class="mvc-auth-chip mvc-auth-chip--in" title="auth '
        . htmlspecialchars($via, ENT_QUOTES) . '">'
        . htmlspecialchars($who, ENT_QUOTES)
        . ' · <a href="api/graph/status">Graph</a>'
        . ' · <a href="auth/logout">Sign out</a></span>';
}
if (!str_contains($html, 'mvc-auth-chip')) {
    $html = str_replace('<body>', '<body>' . "\n  " . $chip, $html);
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
