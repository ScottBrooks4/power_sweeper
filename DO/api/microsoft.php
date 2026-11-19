<?php
declare(strict_types=1);

/**
 * Microsoft sign-in for Pivot features that act as the signed-in person
 * (scorecard writes to SharePoint, the org chart calendar). Device code flow,
 * the same way api/teams.php connects Teams.
 *
 *   ?action=session    → {signedIn, user, features}
 *   ?action=login      → {user_code, url, complete, interval}; open complete, enter the code
 *   ?action=loginpoll  → {pending} until done, then {signedIn, user}
 *   ?action=forget     → signs this browser out of the Pivot Microsoft session
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/lib/microsoft-signin.php';

function httpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function fail(int $code, string $message, bool $login = false): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message, 'login' => $login]);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/DO/',
        'secure' => httpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$config = require __DIR__ . '/lib/microsoft-config.php';
try {
    $signIn = new MicrosoftSignIn($config['microsoft'], $_SESSION);
} catch (InvalidArgumentException $error) {
    fail(500, 'Microsoft sign-in is not configured: ' . $error->getMessage());
}

$action = (string) ($_GET['action'] ?? '');

if ($action === 'session') {
    $user = $signIn->user();
    echo json_encode(['ok' => true, 'signedIn' => $user !== null, 'user' => $user, 'features' => array_keys($config['microsoft']['scopes'])]);
    exit;
}

if ($action === 'forget') {
    $signIn->forget();
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'login') {
    try {
        echo json_encode(['ok' => true] + $signIn->startLogin());
    } catch (RuntimeException $error) {
        fail(502, $error->getMessage());
    }
    exit;
}

if ($action === 'loginpoll') {
    try {
        echo json_encode(['ok' => true] + $signIn->pollLogin());
    } catch (NotSignedIn $error) {
        fail(401, $error->getMessage(), true);
    }
    exit;
}

fail(404, 'Unknown Microsoft sign-in action.');
