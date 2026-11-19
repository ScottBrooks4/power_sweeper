<?php
declare(strict_types=1);

/**
 * Force sync between the scorecard and its SharePoint list, for the admin page.
 *
 *   POST api/scorecard-sync.php?action=status        {}                                  → enabled, mode, lastRun, openConflicts, locked, csrf
 *   POST api/scorecard-sync.php?action=plan          {"direction":"both|pull|push"}      → planId, hash, counts, operations (GET-only)
 *   POST api/scorecard-sync.php?action=apply         {"planId","hash","confirm":true}   → runId, applied, conflicts, skipped, backupDir
 *   POST api/scorecard-sync.php?action=restore-plan  {"runId"}                          → same shape as plan
 *
 * Every action but status needs the X-CSRF-Token header (csrf from status).
 * All need a Microsoft sign-in (api/microsoft.php); apply and restore-plan need
 * an address on the admin allowlist (api/lib/admin.php).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/lib/admin.php';
require __DIR__ . '/lib/microsoft-signin.php';
require __DIR__ . '/lib/graph-client.php';
require __DIR__ . '/lib/scorecard-sync.php';

function httpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
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
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$action = (string) ($_GET['action'] ?? ($body['action'] ?? ''));
$headers = ['X-CSRF-Token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')];

$signIn = null;
$deps = [
    'config' => $config,
    'signIn' => function () use ($config, &$signIn): TokenProvider {
        $signIn = new MicrosoftSignIn($config['microsoft'], $_SESSION);
        return $signIn;
    },
    'sync' => function (string $token, array $user) use ($config, &$signIn): ScorecardSync {
        $graph = new GraphClient(new CurlGraphHttp(), static fn (): string => $token, $signIn->graphBase());
        $sync = $config['scorecard_sync'];
        return new ScorecardSync($sync, $graph, new SyncStore($sync['storage_dir']), dirname(__DIR__) . '/data/scores.json', $user);
    },
    'isAdmin' => 'vascIsAdmin',
    'release' => static function (): void {
        session_write_close();
    },
];

try {
    [$status, $out] = scorecardSyncHandle($action, $method, $body, $headers, $_SESSION, $deps);
} catch (Throwable $error) {
    error_log('scorecard-sync: ' . $error->getMessage());
    [$status, $out] = [500, ['ok' => false, 'error' => 'The scorecard sync failed. Nothing further was written.']];
}

http_response_code($status);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
