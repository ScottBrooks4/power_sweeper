<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\AppProfileAdvisor;
use PowerSweeper\ZipTool;

header('Content-Type: application/json; charset=utf-8');

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
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed with error code ' . (int) ($file['error'] ?? 0));
    }
    $originalName = (string) ($file['name'] ?? 'app.msapp');
    $lower = strtolower($originalName);
    if (!str_ends_with($lower, '.msapp') && !str_ends_with($lower, '.zip')) {
        throw new RuntimeException('File must be a .msapp');
    }

    $token = bin2hex(random_bytes(8));
    $tmp = POWER_SWEEPER_STORAGE . '/tmp/analyze_' . $token . '.msapp';
    if (!move_uploaded_file((string) $file['tmp_name'], $tmp)) {
        if (!rename((string) $file['tmp_name'], $tmp) && !copy((string) $file['tmp_name'], $tmp)) {
            throw new RuntimeException('Could not store upload for analysis');
        }
    }

    try {
        $advisor = new AppProfileAdvisor();
        $result = $advisor->recommend($tmp);
        $result['filename'] = $originalName;
        $result['ziptool'] = ZipTool::REV;
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
    } finally {
        @unlink($tmp);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'forceable_hops' => AppProfileAdvisor::FORCEABLE_HOPS,
    ], JSON_UNESCAPED_SLASHES);
}
