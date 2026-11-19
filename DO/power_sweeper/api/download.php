<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['token'] ?? ''));
if ($token === '' || strlen($token) < 16) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

$path = POWER_SWEEPER_STORAGE . '/out/' . $token . '.msapp';
$metaPath = POWER_SWEEPER_STORAGE . '/out/' . $token . '.json';

if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'File not found or expired']);
    exit;
}

$filename = $token . '.cleaned.msapp';
if (is_file($metaPath)) {
    $meta = json_decode((string) file_get_contents($metaPath), true);
    if (is_array($meta) && !empty($meta['filename'])) {
        $filename = (string) $meta['filename'];
    }
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
exit;
