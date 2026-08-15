<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\HopRegistry;
use PowerSweeper\Pipeline;
use PowerSweeper\ZipTool;

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

function ps_ini_size(string $key): string
{
    $v = ini_get($key);
    return is_string($v) && $v !== '' ? $v : '(unknown)';
}

function ps_bytes(string $val): int
{
    $val = trim($val);
    if ($val === '' || $val === '-1') {
        return PHP_INT_MAX;
    }
    if (!preg_match('/^(\d+)([KMGT]?)B?$/i', $val, $m)) {
        return (int) $val;
    }
    $n = (int) $m[1];
    return match (strtoupper($m[2])) {
        'K' => $n * 1024,
        'M' => $n * 1024 * 1024,
        'G' => $n * 1024 * 1024 * 1024,
        'T' => $n * 1024 * 1024 * 1024 * 1024,
        default => $n,
    };
}

function ps_human_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

/**
 * Keep totals accurate but avoid multi-MB NDJSON "done" payloads that can stall
 * proxies/browsers after large dark-mode sweeps (THCEE ≈ 9k–30k rows).
 *
 * @param array{total?:int,by_hop?:array<string,int>,entries?:list<array<string,mixed>>} $report
 * @return array{total:int,by_hop:array<string,int>,entries:list<array<string,mixed>>,entries_truncated?:bool,entries_omitted?:int}
 */
function ps_slim_report(array $report, int $maxEntries = 250): array
{
    $entries = $report['entries'] ?? [];
    if (!is_array($entries)) {
        $entries = [];
    }
    $total = isset($report['total']) ? (int) $report['total'] : count($entries);
    $byHop = is_array($report['by_hop'] ?? null) ? $report['by_hop'] : [];
    if (count($entries) <= $maxEntries) {
        return [
            'total' => $total,
            'by_hop' => $byHop,
            'entries' => array_values($entries),
        ];
    }

    return [
        'total' => $total,
        'by_hop' => $byHop,
        'entries' => array_slice(array_values($entries), 0, $maxEntries),
        'entries_truncated' => true,
        'entries_omitted' => max(0, $total - $maxEntries),
    ];
}

function ps_upload_error_message(int $code, int $uploadMax, int $postMax): string
{
    $limits = 'upload_max_filesize=' . ps_ini_size('upload_max_filesize')
        . ', post_max_size=' . ps_ini_size('post_max_size');

    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'Upload exceeds PHP upload_max_filesize (' . ps_ini_size('upload_max_filesize')
            . '). Raise it (and post_max_size) in .htaccess / .user.ini / php.ini, then retry. (' . $limits . ')',
        UPLOAD_ERR_FORM_SIZE => 'Upload exceeds the form MAX_FILE_SIZE limit.',
        UPLOAD_ERR_PARTIAL => 'Upload was only partially received — try again.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'PHP has no temporary folder for uploads (upload_tmp_dir).',
        UPLOAD_ERR_CANT_WRITE => 'PHP could not write the upload to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
        default => 'Upload failed with error code ' . $code . ' (' . $limits . ')',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $registry = new HopRegistry();
    echo json_encode([
        'ok' => true,
        'ziptool' => ZipTool::REV,
        'zip_archive' => ZipTool::hasZipArchive(),
        'upload_limits' => [
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize_bytes' => ps_bytes((string) ini_get('upload_max_filesize')),
            'post_max_size_bytes' => ps_bytes((string) ini_get('post_max_size')),
        ],
        'hops' => $registry->catalog(),
        'forceable_hops' => \PowerSweeper\HopAdvisor::FORCEABLE_HOPS,
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

$runFinished = false;
register_shutdown_function(static function () use (&$runFinished, &$wantsStream): void {
    if ($runFinished) {
        return;
    }
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    $message = trim((string) ($err['message'] ?? 'Fatal error'));
    if ($message === '') {
        $message = 'Fatal error';
    }
    if (str_contains($message, 'Allowed memory size')) {
        $message = 'Out of memory while sweeping this app (' . ps_ini_size('memory_limit')
            . '). Try fewer hops, or raise memory_limit to 1024M on the host.';
    } else {
        $message = 'Server stopped mid-run: ' . $message;
    }
    // Best-effort NDJSON/JSON error so the UI does not show a blank "Run failed".
    if (!headers_sent()) {
        header('Content-Type: ' . ($wantsStream ? 'application/x-ndjson' : 'application/json') . '; charset=utf-8');
    }
    $payload = [
        'type' => 'error',
        'ok' => false,
        'error' => $message,
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . ($wantsStream ? "\n" : '');
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
});

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
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    $postMax = ps_bytes((string) ini_get('post_max_size'));
    $uploadMax = ps_bytes((string) ini_get('upload_max_filesize'));

    if (!isset($_FILES['msapp']) || !is_array($_FILES['msapp'])) {
        // When the body exceeds post_max_size, PHP empties $_POST and $_FILES.
        if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
            throw new RuntimeException(
                'Upload too large for PHP post_max_size (' . ps_ini_size('post_max_size')
                . '). Request was ' . ps_human_bytes($contentLength)
                . '. Raise post_max_size / upload_max_filesize (see .htaccess / .user.ini) and retry.'
            );
        }
        throw new RuntimeException(
            'Missing msapp upload. If the file is large, raise PHP post_max_size (currently '
            . ps_ini_size('post_max_size') . ') and upload_max_filesize (currently '
            . ps_ini_size('upload_max_filesize') . ').'
        );
    }

    $file = $_FILES['msapp'];
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(ps_upload_error_message($uploadError, $uploadMax, $postMax));
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

    $storageTmp = POWER_SWEEPER_STORAGE . '/tmp';
    $storageOut = POWER_SWEEPER_STORAGE . '/out';
    if (!is_writable($storageTmp) || !is_writable($storageOut)) {
        throw new RuntimeException(
            'Storage is not writable by the web server (tmp/out). Run: sh scripts/fix_permissions.sh'
        );
    }

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
            throw new RuntimeException(
                'Could not store uploaded file into storage/tmp (check permissions; run scripts/fix_permissions.sh)'
            );
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
    $fullReport = $result['report'];
    $slimReport = ps_slim_report(is_array($fullReport) ? $fullReport : []);
    $meta = [
        'token' => $token,
        'filename' => $downloadName,
        'created' => time(),
        // Compact JSON: full entry list can be 10MB+ after dark-mode on large apps.
        'report' => $fullReport,
    ];
    file_put_contents(
        POWER_SWEEPER_STORAGE . '/out/' . $token . '.json',
        json_encode($meta, JSON_UNESCAPED_SLASHES)
    );

    $done = [
        'type' => 'done',
        'ok' => true,
        'download_token' => $token,
        'filename' => $downloadName,
        'report' => $slimReport,
        'elapsed_ms' => (int) ($result['elapsed_ms'] ?? 0),
        'progress' => 1.0,
    ];

    if ($wantsStream) {
        ps_emit($done, true);
    } else {
        echo json_encode([
            'ok' => true,
            'download_token' => $token,
            'filename' => $downloadName,
            'report' => $slimReport,
            'elapsed_ms' => (int) ($result['elapsed_ms'] ?? 0),
        ], JSON_UNESCAPED_SLASHES);
    }
    $runFinished = true;
} catch (Throwable $e) {
    $runFinished = true;
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
