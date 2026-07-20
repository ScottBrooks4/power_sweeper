<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\HopRegistry;
use PowerSweeper\ProfileLoader;

$hops = (new HopRegistry())->catalog();
$profiles = (new ProfileLoader(POWER_SWEEPER_PROFILES))->all();

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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="page">
    <header class="hero">
      <p class="brand">Power Sweeper</p>
      <h1>Clean a canvas app in hops</h1>
      <p class="lede">Drop an <code>.msapp</code>, choose operations, reorder the sequence, and download a cleaned app with a change report.</p>
    </header>

    <section class="panel drop-panel" id="dropZone" tabindex="0">
      <input type="file" id="fileInput" accept=".msapp,application/zip" hidden>
      <div class="drop-inner">
        <p class="drop-title" id="fileLabel">Drop your .msapp here</p>
        <p class="drop-sub">or <button type="button" class="linkish" id="browseBtn">browse</button></p>
      </div>
    </section>

    <section class="panel">
      <div class="row between">
        <h2>Profile</h2>
        <select id="profileSelect" aria-label="Profile preset">
          <option value="">Custom sequence</option>
          <?php foreach ($profiles as $profile): ?>
            <option value="<?= htmlspecialchars($profile['id'], ENT_QUOTES) ?>"
              data-hops="<?= htmlspecialchars(json_encode($profile['hops'], JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">
              <?= htmlspecialchars($profile['id']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="hint" id="profileHint">Profiles fill the hop sequence; you can still edit afterward.</p>
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
        <p class="hint empty-seq" id="emptySeq">Add hops from the left. Order matters.</p>
      </div>
    </section>

    <section class="actions">
      <button type="button" class="primary" id="runBtn" disabled>Run sweeper</button>
      <p class="status" id="status" role="status"></p>
      <div class="run-progress hidden" id="runProgress" aria-live="polite" aria-atomic="true">
        <div class="progress-meta">
          <span class="progress-phase" id="progressPhase">Starting…</span>
          <span class="progress-count" id="progressCount"></span>
        </div>
        <p class="progress-last" id="progressLast"></p>
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
      profiles: <?= json_encode($profiles, JSON_UNESCAPED_SLASHES) ?>,
      apiRun: <?= json_encode(($basePath === '' ? '' : $basePath) . '/api/run.php', JSON_UNESCAPED_SLASHES) ?>,
      apiDownload: <?= json_encode(($basePath === '' ? '' : $basePath) . '/api/download.php', JSON_UNESCAPED_SLASHES) ?>
    };
  </script>
  <script src="assets/app.js"></script>
</body>
</html>
