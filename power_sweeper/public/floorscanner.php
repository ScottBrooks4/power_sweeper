<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\FloorScanner\FloorplanImage;
use PowerSweeper\FloorScanner\TimingModel;

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$basePath = rtrim(dirname($scriptName), '/');
foreach (['/floorscanner', '/public'] as $suffix) {
    if (str_ends_with($basePath, $suffix)) {
        $basePath = substr($basePath, 0, -strlen($suffix));
    }
}
if ($basePath === '/' || $basePath === '.') {
    $basePath = '';
}
$basePrefix = $basePath;

$timing = TimingModel::load();
$logoPath = __DIR__ . '/assets/floorscanner-logo.svg';
$logoVer = is_file($logoPath) ? (string) filemtime($logoPath) : '1';
$logoHref = 'assets/floorscanner-logo.svg?v=' . rawurlencode($logoVer);
$cssPath = __DIR__ . '/assets/floorscanner.css';
$jsPath = __DIR__ . '/assets/floorscanner.js';
$cssHref = 'assets/floorscanner.css?v=' . rawurlencode(is_file($cssPath) ? (string) filemtime($cssPath) : '1');
$jsHref = 'assets/floorscanner.js?v=' . rawurlencode(is_file($jsPath) ? (string) filemtime($jsPath) : '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Floor Scanner</title>
  <base href="<?= htmlspecialchars(($basePrefix === '' ? '' : $basePrefix) . '/', ENT_QUOTES) ?>">
  <link rel="icon" href="<?= htmlspecialchars($logoHref, ENT_QUOTES) ?>" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= htmlspecialchars($cssHref, ENT_QUOTES) ?>">
</head>
<body>
  <div class="page">
    <header class="hero">
      <div class="brand-lockup">
        <img class="brand-icon" src="<?= htmlspecialchars($logoHref, ENT_QUOTES) ?>" width="56" height="56" alt="Floor Scanner">
        <div class="brand-copy">
          <p class="brand">Floor Scanner</p>
          <h1>Find rooms and cubicles on a floorplan</h1>
        </div>
      </div>
      <p class="lede">Drop a 2D floorplan. Floor Scanner finds rooms and cubicles and fills each one as a rectangle, ignoring furniture.</p>
    </header>

    <section class="panel drop-panel" id="dropZone" tabindex="0">
      <input type="file" id="fileInput" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml,.png,.jpg,.jpeg,.webp,.gif,.svg" hidden>
      <div class="drop-inner">
        <p class="drop-title" id="fileLabel">Drop a floorplan to begin</p>
        <p class="drop-sub">or <button type="button" class="linkish" id="browseBtn">browse</button> · SVG, PNG, JPEG, WebP</p>
      </div>
    </section>

    <section class="actions-row">
      <button type="button" class="primary" id="scanBtn" disabled>Scan floorplan</button>
      <button type="button" class="ghost" id="sampleBtn">Try sample</button>
      <p class="status" id="status" role="status"></p>
    </section>

    <section class="scan-progress hidden" id="scanProgress" aria-live="polite">
      <div class="scan-progress-meta">
        <span id="scanProgressLabel">Scanning…</span>
        <span id="scanProgressEta"></span>
      </div>
      <div class="scan-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="scanProgressBar">
        <div class="scan-progress-fill" id="scanProgressFill"></div>
      </div>
    </section>

    <section class="panel result hidden" id="resultPanel">
      <div class="row between">
        <h2>Scan result</h2>
        <a class="primary download" id="downloadLink" href="#" download="floor-scanner.svg">Download SVG</a>
      </div>
      <p class="summary" id="resultSummary"></p>
      <div class="compare" id="compare">
        <figure>
          <figcaption>Before</figcaption>
          <img id="beforeImg" alt="Original floorplan">
        </figure>
        <figure class="overlay-figure">
          <figcaption>After</figcaption>
          <div class="overlay-stage" id="overlayStage">
            <img id="afterImg" alt="Detected rooms overlay">
            <div class="room-hotspots" id="roomHotspots" aria-hidden="true"></div>
            <div class="room-hover-label hidden" id="roomHoverLabel" role="status"></div>
          </div>
        </figure>
      </div>
      <ul class="room-list" id="roomList"></ul>
    </section>
  </div>

  <div class="map-lightbox hidden" id="mapLightbox" role="dialog" aria-modal="true" aria-label="Enlarged floorplan">
    <button type="button" class="map-lightbox-close" id="mapLightboxClose" aria-label="Close enlarged map">Close</button>
    <div class="map-lightbox-stage" id="mapLightboxStage">
      <img id="mapLightboxImg" alt="Enlarged detected rooms overlay">
      <div class="room-hotspots" id="mapLightboxHotspots" aria-hidden="true"></div>
      <div class="room-hover-label hidden" id="mapLightboxHoverLabel" role="status"></div>
    </div>
    <p class="map-lightbox-hint">Scroll to zoom · drag to pan · Esc to close</p>
  </div>

  <script>
    window.FLOOR_SCANNER = {
      apiScan: <?= json_encode(($basePrefix === '' ? '' : $basePrefix) . '/api/floorscanner.php', JSON_UNESCAPED_SLASHES) ?>,
      sampleImage: <?= json_encode(($basePrefix === '' ? '' : $basePrefix) . '/assets/sample-floorplan.png', JSON_UNESCAPED_SLASHES) ?>,
      maxProcess: <?= (int) FloorplanImage::maxEdge() ?>,
      timing: <?= json_encode([
          'intercept_ms' => $timing['intercept_ms'],
          'ms_per_mp' => $timing['ms_per_mp'],
          'sample_count' => count($timing['samples']),
      ], JSON_UNESCAPED_SLASHES) ?>
    };
  </script>
  <script src="<?= htmlspecialchars($jsHref, ENT_QUOTES) ?>"></script>
</body>
</html>
