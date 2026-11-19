<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$file = dirname(__DIR__) . '/data/scores.json';

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

function signedIn(): bool
{
    if (trim((string) ($_SESSION['ado_pat'] ?? '')) !== '') {
        return true;
    }
    $ado = $_SESSION['ado'] ?? null;
    return is_array($ado) && trim((string) ($ado['access'] ?? '')) !== '';
}

function readScores(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return [];
    }
    // Shared, because the SharePoint sync (api/scorecard-sync.php) also writes this file.
    flock($handle, LOCK_SH);
    $raw = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function fail(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'scores' => readScores($file)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Use GET or POST.');
}

if (!signedIn()) {
    fail(401, 'Sign in to save a score.');
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    fail(400, 'Send the score as JSON.');
}

$id = (string) ($payload['id'] ?? '');
if (!preg_match('/^\d{1,6}$/', $id)) {
    fail(400, 'Unknown use case.');
}

$level = trim((string) ($payload['level'] ?? ''));
if ($level !== '' && !preg_match('/^L\d(?:\.5)?$/', $level)) {
    fail(400, 'Level must look like L1 or L0.5.');
}

$numbers = [];
foreach (['strategic', 'reach', 'business'] as $key) {
    if (!array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
        $numbers[$key] = null;
        continue;
    }
    $value = (int) $payload[$key];
    if ($value < 1 || $value > 5) {
        fail(400, 'Strategic, reach, and business are 1 to 5.');
    }
    $numbers[$key] = $value;
}

$why = [];
foreach (['strategicWhy', 'reachWhy'] as $key) {
    $text = trim((string) ($payload[$key] ?? ''));
    if (strlen($text) > 2000) {
        fail(400, 'Justification is too long.');
    }
    $why[$key] = $text;
}

$handle = fopen($file, 'c+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    fail(500, 'The score could not be saved.');
}
$scores = json_decode((string) stream_get_contents($handle), true);
$scores = is_array($scores) ? $scores : [];
$row = [];
foreach ($numbers as $key => $value) {
    if ($value !== null) {
        $row[$key] = $value;
    }
}
if ($level !== '') {
    $row['level'] = $level;
}
foreach ($why as $key => $text) {
    if ($text !== '') {
        $row[$key] = $text;
    }
}

if ($row) {
    $scores[$id] = $row;
} else {
    unset($scores[$id]);
}

$encoded = json_encode($scores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$saved = $encoded !== false && ftruncate($handle, 0) && rewind($handle) && fwrite($handle, $encoded . "\n") !== false && fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);
if (!$saved) {
    fail(500, 'The score could not be saved.');
}

echo json_encode(['ok' => true, 'id' => $id, 'score' => $row]);
