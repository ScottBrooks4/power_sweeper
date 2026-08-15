<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\HopAdvisor;
use PowerSweeper\HopRegistry;

$hops = (new HopRegistry())->catalog();
$forceable = HopAdvisor::FORCEABLE_HOPS;

// Base URL for /power_sweeper/ under Apache, or / under php -S router.php
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
// When served via public/index.php directly, step up to app root
if (str_ends_with($basePath, '/public')) {
    $basePath = substr($basePath, 0, -strlen('/public'));
}
$basePath = $basePath === '' ? '' : $basePath;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Power Sweeper</title>
  <base href="<?= htmlspecialchars(($basePath === '' ? '' : $basePath) . '/', ENT_QUOTES) ?>">
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="page">
    <header class="hero">
      <div class="brand-lockup">
        <img class="brand-icon" src="assets/favicon.svg" width="56" height="56" alt="">
        <div class="brand-copy">
          <p class="brand">Power Sweeper</p>
          <h1>Clean a canvas app in hops</h1>
        </div>
      </div>
      <p class="lede">Drop an <code>.msapp</code> — Power Sweeper scans it, picks the hop sequence and write mode, then you can tweak and run.</p>
    </header>

    <section class="panel drop-panel" id="dropZone" tabindex="0">
      <input type="file" id="fileInput" accept=".msapp,application/zip" hidden>
      <div class="drop-inner">
        <p class="drop-title" id="fileLabel">Drop your .msapp here</p>
        <p class="drop-sub">or <button type="button" class="linkish" id="browseBtn">browse</button></p>
        <div class="drop-scan-actions" id="dropScanActions" hidden>
          <p class="drop-scan-hint" id="dropScanHint">Scanning for useful hops…</p>
          <button type="button" class="btn-skip-scan" id="skipScanBtnDrop">Skip scan — pick hops myself</button>
        </div>
      </div>
    </section>

    <section class="panel plan-panel hidden" id="planPanel">
      <div class="row between plan-toolbar">
        <h2 id="planHeading">Recommended plan</h2>
        <label class="write-mode" for="forceModeSelect">
          <span class="write-mode-label">Write mode</span>
          <select id="forceModeSelect" aria-label="Write mode for selected hops">
            <option value="missing_only">Missing only</option>
            <option value="all">All</option>
          </select>
        </label>
      </div>
      <p class="hint" id="planHint">Scanning…</p>
      <p class="hint scan-live" id="scanLive" aria-live="polite"></p>
      <div class="scan-actions" id="scanActions" hidden>
        <button type="button" class="btn-skip-scan" id="skipScanBtn">Skip scan — pick hops myself</button>
      </div>
      <p class="hint force-hint" id="forceHint"></p>
      <ul class="plan-reasons" id="planReasons"></ul>
    </section>

    <section class="panel hops-layout">
      <div>
        <h2>Available hops</h2>
        <ul class="palette" id="palette">
          <?php foreach ($hops as $hop): ?>
            <li>
              <button type="button" class="hop-card" data-hop-id="<?= htmlspecialchars($hop['id'], ENT_QUOTES) ?>">
                <span class="hop-label"><?= htmlspecialchars($hop['label']) ?></span>
                <span class="hop-desc"><?= htmlspecialchars($hop['description']) ?></span>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <div class="row between">
          <h2>Sequence</h2>
          <button type="button" class="ghost" id="clearSequence">Clear</button>
        </div>
        <ol class="sequence" id="sequence" aria-label="Hop sequence"></ol>
        <p class="hint empty-seq" id="emptySeq">Drop an app to auto-fill hops, or add them from the left. Order matters.</p>
      </div>
    </section>

    <section class="actions-dock" id="actionsDock" aria-label="Run controls">
      <div class="actions-dock-inner">
        <div class="actions-row">
          <button type="button" class="primary" id="runBtn" disabled>Run sweeper</button>
          <p class="run-estimate" id="runEstimate" aria-live="polite">Add hops to estimate runtime</p>
          <p class="status" id="status" role="status"></p>
        </div>
        <div class="run-progress hidden" id="runProgress" aria-live="polite" aria-atomic="true">
          <div class="progress-meta">
            <span class="progress-phase" id="progressPhase">Ready</span>
            <span class="progress-count" id="progressCount"></span>
          </div>
          <div class="progress-bar" id="progressBar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Run progress">
            <div class="progress-bar-fill" id="progressBarFill"></div>
          </div>
          <div class="progress-times">
            <span class="progress-elapsed" id="progressElapsed">Elapsed 0:00</span>
            <span class="progress-eta" id="progressEta">Estimating…</span>
          </div>
          <p class="progress-last" id="progressLast"></p>
        </div>
      </div>
    </section>

    <section class="panel result hidden" id="resultPanel">
      <div class="row between">
        <h2>Report</h2>
        <a class="primary download" id="downloadLink" href="#">Download cleaned .msapp</a>
      </div>
      <p class="summary" id="reportSummary"></p>
      <div class="table-wrap">
        <table id="reportTable">
          <thead>
            <tr>
              <th>Hop</th>
              <th>Control</th>
              <th>Property</th>
              <th>From</th>
              <th>To</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </section>
  </div>

  <script>
    window.POWER_SWEEPER = {
      hops: <?= json_encode($hops, JSON_UNESCAPED_SLASHES) ?>,
      forceable_hops: <?= json_encode(array_values($forceable), JSON_UNESCAPED_SLASHES) ?>,
      apiRun: <?= json_encode(($basePath === '' ? '' : $basePath) . '/api/run.php', JSON_UNESCAPED_SLASHES) ?>,
      apiAnalyze: <?= json_encode(($basePath === '' ? '' : $basePath) . '/api/analyze.php', JSON_UNESCAPED_SLASHES) ?>,
      apiDownload: <?= json_encode(($basePath === '' ? '' : $basePath) . '/api/download.php', JSON_UNESCAPED_SLASHES) ?>,
      upload_limits: {
        upload_max_filesize: <?= json_encode((string) ini_get('upload_max_filesize'), JSON_UNESCAPED_SLASHES) ?>,
        post_max_size: <?= json_encode((string) ini_get('post_max_size'), JSON_UNESCAPED_SLASHES) ?>,
        upload_max_filesize_bytes: <?= (int) ps_ini_bytes((string) ini_get('upload_max_filesize')) ?>,
        post_max_size_bytes: <?= (int) ps_ini_bytes((string) ini_get('post_max_size')) ?>
      }
    };
  </script>
  <script src="assets/app.js"></script>
</body>
</html>
