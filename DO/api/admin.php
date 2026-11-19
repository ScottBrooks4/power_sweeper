<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/devops_identity.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = (string) ($_GET['action'] ?? '');
$data = dirname(__DIR__) . '/data';

function cacheFile(string $path, callable $describe): array
{
    if (!is_readable($path)) {
        return ['present' => false, 'saved' => null, 'bytes' => 0];
    }
    $row = ['present' => true, 'saved' => filemtime($path) ?: null, 'bytes' => filesize($path) ?: 0];
    $json = json_decode((string) file_get_contents($path), true);
    if (is_array($json)) {
        if (!empty($json['saved'])) {
            $row['saved'] = (int) $json['saved'];
        }
        $row = array_merge($row, $describe($json));
    }
    return $row;
}

function countOf(array $json, string $key): int
{
    return is_array($json[$key] ?? null) ? count($json[$key]) : 0;
}

function flockFree(string $path): bool
{
    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return true;
    }
    $free = flock($handle, LOCK_EX | LOCK_NB);
    if ($free) {
        flock($handle, LOCK_UN);
    }
    fclose($handle);
    return $free;
}

if ($action === 'whoami') {
    $who = vascDevopsIdentity();
    session_write_close();
    echo json_encode([
        'ok' => true,
        'signedIn' => $who['signedIn'],
        'admin' => $who['admin'],
        'name' => $who['admin'] ? $who['name'] : '',
        'email' => $who['signedIn'] ? $who['email'] : '',
        'configured' => vascAdminEmails() !== [],
    ]);
    exit;
}

if ($action === 'overview') {
    $who = vascRequireAdmin();
    $teams = $_SESSION['teams'] ?? null;
    $presence = $_SESSION['teams_presence'] ?? null;
    session_write_close();
    $portraits = glob($data . '/portraits/*') ?: [];
    $added = 0;
    if (is_readable($data . '/abbreviations-added.xml')) {
        $doc = new DOMDocument();
        if (@$doc->load($data . '/abbreviations-added.xml')) {
            $added = $doc->getElementsByTagName('entry')->length;
        }
    }
    echo json_encode([
        'ok' => true,
        'who' => ['name' => $who['name'], 'email' => $who['email']],
        'now' => time(),
        'devops' => [
            'daily' => cacheFile($data . '/daily.json', static fn (array $j): array => [
                'items' => countOf($j, 'items'),
                'people' => countOf($j, 'people'),
                'apps' => countOf($j, 'apps'),
                'links' => countOf($j, 'links'),
                'groups' => countOf($j, 'groups'),
            ]),
            'new' => cacheFile($data . '/daily-new.json', static fn (array $j): array => ['items' => countOf($j, 'items')]),
            'portraits' => count($portraits),
            'building' => is_file($data . '/daily.json.lock') && !flockFree($data . '/daily.json.lock'),
        ],
        'teams' => [
            'signedIn' => is_array($teams) && (((string) ($teams['access'] ?? '')) !== '' || ((string) ($teams['refresh'] ?? '')) !== ''),
            'presenceAt' => is_array($presence) ? (int) ($presence['at'] ?? 0) : null,
            'presenceRows' => is_array($presence['rows'] ?? null) ? count($presence['rows']) : 0,
        ],
        'abbreviations' => ['added' => $added],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'teams-reset') {
    vascRequireJsonPost();
    vascRequireAdmin();
    // Presence is cached per browser session for 45 s; dropping it makes the next chart view ask Graph again.
    unset($_SESSION['teams_presence']);
    echo json_encode(['ok' => true]);
    exit;
}

vascAdminFail(400, 'Unknown admin action.');
