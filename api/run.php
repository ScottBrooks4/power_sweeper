<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\HopRegistry;
use PowerSweeper\Pipeline;
use PowerSweeper\ProfileLoader;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $registry = new HopRegistry();
    $profiles = (new ProfileLoader(POWER_SWEEPER_PROFILES))->all();
    echo json_encode([
        'ok' => true,
        'hops' => $registry->catalog(),
        'profiles' => $profiles,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    if (!isset($_FILES['msapp']) || !is_array($_FILES['msapp'])) {
        throw new RuntimeException('Missing msapp upload');
    }

    $file = $_FILES['msapp'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed with error code ' . ($file['error'] ?? 'unknown'));
    }

    $originalName = (string) ($file['name'] ?? 'app.msapp');
    if (!str_ends_with(strtolower($originalName), '.msapp')) {
        // Allow .zip for testing
        if (!str_ends_with(strtolower($originalName), '.zip')) {
            throw new RuntimeException('File must be a .msapp');
        }
    }

    $hopsJson = $_POST['hops'] ?? '[]';
    $hops = json_decode((string) $hopsJson, true);
    if (!is_array($hops) || $hops === []) {
        throw new RuntimeException('Select at least one hop');
    }

    $normalized = [];
    foreach ($hops as $step) {
        if (!is_array($step) || empty($step['id'])) {
            continue;
        }
        $normalized[] = [
            'id' => (string) $step['id'],
            'options' => is_array($step['options'] ?? null) ? $step['options'] : [],
        ];
    }
    if ($normalized === []) {
        throw new RuntimeException('Select at least one hop');
    }

    $token = bin2hex(random_bytes(16));
    $tmpInput = POWER_SWEEPER_STORAGE . '/tmp/' . $token . '_in.msapp';
    $tmpOutput = POWER_SWEEPER_STORAGE . '/out/' . $token . '.msapp';

    if (!move_uploaded_file((string) $file['tmp_name'], $tmpInput)) {
        // CLI / non-upload fallback
        if (!rename((string) $file['tmp_name'], $tmpInput) && !copy((string) $file['tmp_name'], $tmpInput)) {
            throw new RuntimeException('Could not store uploaded file');
        }
    }

    $pipeline = new Pipeline();
    $result = $pipeline->run($tmpInput, $normalized, $tmpOutput);
    @unlink($tmpInput);

    $downloadName = preg_replace('/\.msapp$/i', '', $originalName) . '.cleaned.msapp';
    $meta = [
        'token' => $token,
        'filename' => $downloadName,
        'created' => time(),
        'report' => $result['report'],
    ];
    file_put_contents(
        POWER_SWEEPER_STORAGE . '/out/' . $token . '.json',
        json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    echo json_encode([
        'ok' => true,
        'download_token' => $token,
        'filename' => $downloadName,
        'report' => $result['report'],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
