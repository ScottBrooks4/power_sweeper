<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\HopRegistry;
use PowerSweeper\Pipeline;
use PowerSweeper\ProfileLoader;
use PowerSweeper\ZipTool;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $registry = new HopRegistry();
    $profiles = (new ProfileLoader(POWER_SWEEPER_PROFILES))->all();
    echo json_encode([
        'ok' => true,
        'ziptool' => ZipTool::REV,
        'zip_archive' => ZipTool::hasZipArchive(),
        'hops' => $registry->catalog(),
        'profiles' => $profiles,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$wantsStream = isset($_POST['stream']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'ndjson');

/**
 * @param array<string, mixed> $event
 */
function ps_emit(array $event, bool $stream): void
{
    if (!$stream) {
        return;
    }
    echo json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

if ($wantsStream) {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
} else {
    header('Content-Type: application/json; charset=utf-8');
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
    $schemaPath = null;

    // Optional SharePoint list schema JSON for correlate_sharepoint hop
    if (isset($_FILES['sharepoint_schema']) && is_array($_FILES['sharepoint_schema'])) {
        $schemaFile = $_FILES['sharepoint_schema'];
        if (($schemaFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $schemaName = strtolower((string) ($schemaFile['name'] ?? ''));
            if (!str_ends_with($schemaName, '.json')) {
                throw new RuntimeException('SharePoint schema must be a .json file');
            }
            $schemaPath = POWER_SWEEPER_STORAGE . '/tmp/' . $token . '_sharepoint_schema.json';
            if (!move_uploaded_file((string) $schemaFile['tmp_name'], $schemaPath)) {
                if (!rename((string) $schemaFile['tmp_name'], $schemaPath) && !copy((string) $schemaFile['tmp_name'], $schemaPath)) {
                    throw new RuntimeException('Could not store SharePoint schema upload');
                }
            }
            foreach ($normalized as $i => $step) {
                if (($step['id'] ?? '') === 'correlate_sharepoint') {
                    $normalized[$i]['options']['schema_file'] = $schemaPath;
                }
            }
        }
    }

    if (!move_uploaded_file((string) $file['tmp_name'], $tmpInput)) {
        // CLI / non-upload fallback
        if (!rename((string) $file['tmp_name'], $tmpInput) && !copy((string) $file['tmp_name'], $tmpInput)) {
            throw new RuntimeException('Could not store uploaded file');
        }
    }

    ps_emit([
        'type' => 'phase',
        'phase' => 'upload',
        'message' => 'Starting sweep…',
    ], $wantsStream);

    // Throttle change events so LiteSpeed/browsers stay responsive without flooding.
    $lastFlush = microtime(true);
    $pendingChange = null;
    $flushChange = static function (bool $force = false) use (&$lastFlush, &$pendingChange, $wantsStream): void {
        if ($pendingChange === null) {
            return;
        }
        $now = microtime(true);
        if (!$force && ($now - $lastFlush) < 0.05) {
            return;
        }
        ps_emit($pendingChange, $wantsStream);
        $pendingChange = null;
        $lastFlush = $now;
    };

    $pipeline = new Pipeline();
    $result = $pipeline->run(
        $tmpInput,
        $normalized,
        $tmpOutput,
        static function (array $event) use ($wantsStream, &$pendingChange, $flushChange): void {
            if (($event['type'] ?? '') === 'change') {
                $pendingChange = $event;
                $flushChange(false);
                return;
            }
            $flushChange(true);
            ps_emit($event, $wantsStream);
        }
    );
    $flushChange(true);
    @unlink($tmpInput);
    if (is_string($schemaPath) && is_file($schemaPath)) {
        @unlink($schemaPath);
    }

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

    $done = [
        'type' => 'done',
        'ok' => true,
        'download_token' => $token,
        'filename' => $downloadName,
        'report' => $result['report'],
    ];

    if ($wantsStream) {
        ps_emit($done, true);
    } else {
        echo json_encode([
            'ok' => true,
            'download_token' => $token,
            'filename' => $downloadName,
            'report' => $result['report'],
        ], JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    if ($wantsStream) {
        ps_emit([
            'type' => 'error',
            'ok' => false,
            'error' => $e->getMessage(),
        ], true);
    } else {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES);
    }
}
