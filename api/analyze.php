<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\AppProfileAdvisor;
use PowerSweeper\ZipTool;

// Avoid HTML error pages leaking into the JSON/NDJSON client parser.
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['type' => 'error', 'ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

$emit = static function (array $event): void {
    echo json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
};

try {
    if (!isset($_FILES['msapp']) || !is_array($_FILES['msapp'])) {
        throw new RuntimeException('Missing msapp upload');
    }
    $file = $_FILES['msapp'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int) ($file['error'] ?? 0);
        $hint = match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => ' (file exceeds upload_max_filesize / post_max_size)',
            UPLOAD_ERR_PARTIAL => ' (partial upload)',
            UPLOAD_ERR_NO_TMP_DIR => ' (missing temp dir)',
            UPLOAD_ERR_CANT_WRITE => ' (disk write failed)',
            default => '',
        };
        throw new RuntimeException('Upload failed with error code ' . $code . $hint);
    }
    $originalName = (string) ($file['name'] ?? 'app.msapp');
    $lower = strtolower($originalName);
    if (!str_ends_with($lower, '.msapp') && !str_ends_with($lower, '.zip')) {
        throw new RuntimeException('File must be a .msapp');
    }

    if (!is_dir(POWER_SWEEPER_STORAGE . '/tmp') && !@mkdir(POWER_SWEEPER_STORAGE . '/tmp', 0775, true)) {
        throw new RuntimeException('storage/tmp is not writable');
    }

    $token = bin2hex(random_bytes(8));
    $tmp = POWER_SWEEPER_STORAGE . '/tmp/analyze_' . $token . '.msapp';
    if (!move_uploaded_file((string) $file['tmp_name'], $tmp)) {
        if (!rename((string) $file['tmp_name'], $tmp) && !copy((string) $file['tmp_name'], $tmp)) {
            throw new RuntimeException('Could not store upload for analysis');
        }
    }

    $emit([
        'type' => 'progress',
        'phase' => 'upload',
        'message' => 'Received ' . $originalName . ' — starting scan…',
    ]);

    try {
        $advisor = new AppProfileAdvisor();
        $result = $advisor->recommend($tmp, static function (array $event) use ($emit): void {
            $emit($event);
        });
        $result['type'] = 'result';
        $result['filename'] = $originalName;
        $result['ziptool'] = ZipTool::REV;
        $emit($result);
    } finally {
        @unlink($tmp);
    }
} catch (Throwable $e) {
    http_response_code(400);
    $emit([
        'type' => 'error',
        'ok' => false,
        'error' => $e->getMessage(),
        'forceable_hops' => AppProfileAdvisor::FORCEABLE_HOPS,
    ]);
}
