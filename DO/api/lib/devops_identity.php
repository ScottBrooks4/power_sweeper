<?php
declare(strict_types=1);

require_once __DIR__ . '/admin.php';

/**
 * The person signed in to Azure DevOps in this browser, and whether they are a Pivot admin.
 * Only the browser's own token or Microsoft sign-in counts: the server's shared DevOps token
 * would make every visitor look signed in, so it never identifies an admin.
 */

const VASC_IDENTITY_ORG = 'VCDS-VCEMD';
const VASC_IDENTITY_TTL = 3600;

function vascSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/DO/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** @return array{0:string,1:string} [bearer, pat] from this browser only */
function vascBrowserCredential(): array
{
    $ado = $_SESSION['ado'] ?? null;
    if (is_array($ado) && (string) ($ado['access'] ?? '') !== '' && time() < (int) ($ado['expires'] ?? 0)) {
        return [(string) $ado['access'], ''];
    }
    $pat = $_SESSION['ado_pat'] ?? '';
    if (is_string($pat) && trim($pat) !== '') {
        return ['', trim($pat)];
    }
    $saved = $_COOKIE['vasc_ado'] ?? '';
    if (is_string($saved) && preg_match('/^[A-Za-z0-9+\/_.=-]{20,256}$/', $saved)) {
        return ['', $saved];
    }
    return ['', ''];
}

/** @return array{signedIn:bool,email:string,name:string,admin:bool} */
function vascDevopsIdentity(): array
{
    vascSessionStart();
    [$bearer, $pat] = vascBrowserCredential();
    $none = ['signedIn' => false, 'email' => '', 'name' => '', 'admin' => false];
    if ($bearer === '' && $pat === '') {
        unset($_SESSION['vasc_identity']);
        return $none;
    }
    $key = hash('sha256', $bearer . '|' . $pat);
    $cached = $_SESSION['vasc_identity'] ?? null;
    if (is_array($cached) && ($cached['key'] ?? '') === $key && time() - (int) ($cached['at'] ?? 0) < VASC_IDENTITY_TTL) {
        $email = (string) $cached['email'];
        return ['signedIn' => true, 'email' => $email, 'name' => (string) $cached['name'], 'admin' => vascIsAdmin($email)];
    }
    $ch = curl_init('https://dev.azure.com/' . VASC_IDENTITY_ORG . '/_apis/connectionData');
    $headers = ['Accept: application/json'];
    if ($bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($pat !== '') {
        $options[CURLOPT_USERPWD] = ':' . $pat;
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $raw, true);
    $user = is_array($data) ? ($data['authenticatedUser'] ?? null) : null;
    $email = is_array($user) ? strtolower(trim((string) ($user['properties']['Account']['$value'] ?? ''))) : '';
    if ($status !== 200 || $email === '' || !str_contains($email, '@')) {
        unset($_SESSION['vasc_identity']);
        return $none;
    }
    $name = (string) ($user['providerDisplayName'] ?? $user['customDisplayName'] ?? '');
    $_SESSION['vasc_identity'] = ['key' => $key, 'email' => $email, 'name' => $name, 'at' => time()];
    return ['signedIn' => true, 'email' => $email, 'name' => $name, 'admin' => vascIsAdmin($email)];
}

function vascAdminFail(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message, 'login' => $code === 401]);
    exit;
}

/** Stops the request unless this browser's DevOps sign-in belongs to a listed admin. */
function vascRequireAdmin(): array
{
    $who = vascDevopsIdentity();
    if (!$who['signedIn']) {
        vascAdminFail(401, 'Sign in to Azure DevOps with your own token to use admin tools.');
    }
    if (!$who['admin']) {
        vascAdminFail(403, 'This account is not a VASC admin.');
    }
    return $who;
}

/** Admin writes must be same-site JSON, which a cross-site form cannot send. */
function vascRequireJsonPost(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        vascAdminFail(405, 'Send this as a POST.');
    }
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        vascAdminFail(415, 'Send JSON.');
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}
