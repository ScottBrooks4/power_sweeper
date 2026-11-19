<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\FloorScanner\ElementLayers;
use PowerSweeper\FloorScanner\FloorplanImage;
use PowerSweeper\FloorScanner\RoomDetector;
use PowerSweeper\FloorScanner\TimingModel;

header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
@ignore_user_abort(true);
@set_time_limit(180);
@ini_set('max_execution_time', '600');
@ini_set('memory_limit', '2048M');
@ini_set('max_input_time', '300');
// Catch fatals / OOMs as JSON so the UI never parses an HTML error page.
ob_start();
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) $err['type'], $fatal, true)) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $msg = (string) ($err['message'] ?? 'Fatal error');
    if (str_contains($msg, 'Allowed memory size')) {
        $msg = 'Out of memory while scanning this floorplan. Try a smaller export or PNG.';
    }
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
});

/** Flush leading whitespace so LiteSpeed/proxies see activity during long scans. */
$keepAlive = static function (): void {
    static $armed = false;
    if (!$armed) {
        // Headers must be sent before the first keepalive byte.
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $armed = true;
    }
    echo "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $timing = TimingModel::load();
    $json = json_encode([
        'ok' => true,
        'name' => 'Floor Scanner',
        'accept' => ['png', 'jpeg', 'webp', 'gif', 'svg'],
        'upload_limits' => [
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
        ],
        'max_process' => FloorplanImage::maxEdge(),
        'timing' => [
            'version' => $timing['version'],
            'intercept_ms' => $timing['intercept_ms'],
            'ms_per_mp' => $timing['ms_per_mp'],
            'sample_count' => count($timing['samples']),
        ],
    ], JSON_UNESCAPED_SLASHES);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo $json;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $blob = '';
    $name = 'floorplan.png';
    if (isset($_FILES['floorplan']) && is_array($_FILES['floorplan'])) {
        $file = $_FILES['floorplan'];
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $hint = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds upload limit',
                UPLOAD_ERR_PARTIAL => 'Partial upload',
                UPLOAD_ERR_NO_FILE => 'No floorplan uploaded',
                default => 'Upload failed (code ' . $err . ')',
            };
            throw new RuntimeException($hint);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 20 * 1024 * 1024) {
            throw new RuntimeException('Floorplan must be under 20 MB');
        }
        $blob = (string) file_get_contents($tmp);
        $name = (string) ($file['name'] ?? $name);
    } elseif (isset($_POST['sample']) && (string) $_POST['sample'] !== '') {
        // Public sample only — corpus / test maps stay local fixtures, not API keys.
        $sample = dirname(__DIR__) . '/public/assets/sample-floorplan.png';
        if (!is_file($sample)) {
            $sample = dirname(__DIR__) . '/tests/fixtures/sample_floorplan.png';
        }
        if (!is_file($sample)) {
            throw new RuntimeException('Sample floorplan is not available');
        }
        // Serve a precomputed result when possible. Shared hosts (LiteSpeed) often
        // return HTTP 503 when concurrent sample scans push past ~30s.
        // Match by content hash — FTP deploy rewrites mtimes, so filemtime is useless.
        $cachePaths = [
            dirname(__DIR__) . '/storage/benchmarks/floorscanner_sample_cache.json',
            dirname(__DIR__) . '/public/assets/floorscanner_sample_cache.json',
        ];
        $srcHash = (string) md5_file($sample);
        foreach ($cachePaths as $cachePath) {
            if (!is_file($cachePath)) {
                continue;
            }
            $cached = json_decode((string) file_get_contents($cachePath), true);
            if (!is_array($cached) || empty($cached['ok']) || empty($cached['rooms'])) {
                continue;
            }
            $cacheHash = (string) ($cached['source_md5'] ?? '');
            if ($cacheHash !== '' && $cacheHash !== $srcHash) {
                continue;
            }
            $cached['cached'] = true;
            $cached['elapsed_ms'] = 0;
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo json_encode($cached, JSON_UNESCAPED_SLASHES);
            exit;
        }
        $blob = (string) file_get_contents($sample);
        $name = basename($sample);
    } else {
        throw new RuntimeException('Drop a floorplan image to scan');
    }

    if ($blob === '') {
        throw new RuntimeException('Floorplan is empty');
    }

    $isSvg = FloorplanImage::looksLikeSvg($blob, $name);
    if (!$isSvg) {
        $info = @getimagesizefromstring($blob);
        if ($info === false) {
            throw new RuntimeException('File is not a recognized image or SVG');
        }
    }

    $probe = FloorplanImage::probeWorkSize($blob, $name);
    $estimateMs = TimingModel::predictMs($probe['width'], $probe['height'], strlen($blob));

    // Prove the connection is alive before the heavy scan (LiteSpeed ~30s idle kill).
    $keepAlive();

    $t0 = microtime(true);
    $detector = new RoomDetector();
    $detector->progressPing = $keepAlive;
    // Optional recognition layers: multipart field "layers" or FLOORSCANNER_LAYERS env.
    if (isset($_FILES['layers']) && is_uploaded_file((string) ($_FILES['layers']['tmp_name'] ?? ''))) {
        $layerJson = json_decode((string) file_get_contents($_FILES['layers']['tmp_name']), true);
        if (is_array($layerJson)) {
            $detector->withElementLayers(ElementLayers::fromArray($layerJson));
        }
    } elseif (isset($_POST['layers_json']) && is_string($_POST['layers_json']) && $_POST['layers_json'] !== '') {
        $layerJson = json_decode($_POST['layers_json'], true);
        if (is_array($layerJson)) {
            $detector->withElementLayers(ElementLayers::fromArray($layerJson));
        }
    }
    $result = $detector->detectBlob($blob, $name);
    $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
    $rooms = $result['rooms'];
    $roomCount = 0;
    $cubicleCount = 0;
    foreach ($rooms as $room) {
        if (($room['type'] ?? '') === 'cubicle') {
            $cubicleCount++;
        } else {
            $roomCount++;
        }
    }

    $width = (int) $result['width'];
    $height = (int) $result['height'];
    $recorded = TimingModel::recordSample($width, $height, $elapsedMs, strlen($blob));
    $timing = is_array($recorded['model'] ?? null) ? $recorded['model'] : TimingModel::load();

    $payload = [
        'ok' => true,
        'filename' => $name,
        'width' => $width,
        'height' => $height,
        'room_count' => $roomCount,
        'cubicle_count' => $cubicleCount,
        'ocr_label_count' => (int) ($result['ocr_label_count'] ?? 0),
        'elapsed_ms' => $elapsedMs,
        'estimate_ms' => $estimateMs,
        'timing' => [
            'version' => $timing['version'],
            'intercept_ms' => $timing['intercept_ms'],
            'ms_per_mp' => $timing['ms_per_mp'],
            'sample_count' => count($timing['samples']),
        ],
        'rooms' => $rooms,
        'overlay_svg' => (string) ($result['overlay_svg'] ?? ''),
        'overlay_data_url' => (string) ($result['overlay_data_url'] ?? ''),
        'overlay_png_data_url' => ($result['overlay_png'] ?? '') !== ''
            ? 'data:image/png;base64,' . base64_encode($result['overlay_png'])
            : '',
        'format' => 'svg',
    ];
    // Refresh sample cache after a live sample scan so Try sample stays cheap.
    if (isset($_POST['sample']) && (string) $_POST['sample'] !== '' && isset($sample) && is_file($sample)) {
        $payload['source_md5'] = (string) md5_file($sample);
        $payload['source_mtime'] = (int) filemtime($sample);
        $payload['cached'] = false;
        $cacheJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($cacheJson !== false) {
            foreach ([
                dirname(__DIR__) . '/storage/benchmarks/floorscanner_sample_cache.json',
                dirname(__DIR__) . '/public/assets/floorscanner_sample_cache.json',
            ] as $cachePath) {
                $dir = dirname($cachePath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
                @file_put_contents($cachePath, $cacheJson);
            }
        }
    }
    // Drop heavy binary from memory before JSON encode.
    unset($result, $blob);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode scan result');
    }
    unset($payload);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo $json;
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
