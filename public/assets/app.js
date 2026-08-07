(() => {
  const cfg = window.POWER_SWEEPER || {};
  const hopMeta = Object.fromEntries((cfg.hops || []).map((h) => [h.id, h]));

  const dropZone = document.getElementById('dropZone');
  const fileInput = document.getElementById('fileInput');
  const browseBtn = document.getElementById('browseBtn');
  const fileLabel = document.getElementById('fileLabel');
  const schemaInput = document.getElementById('schemaInput');
  const schemaBrowseBtn = document.getElementById('schemaBrowseBtn');
  const schemaLabel = document.getElementById('schemaLabel');
  const profileSelect = document.getElementById('profileSelect');
  const profileHint = document.getElementById('profileHint');
  const palette = document.getElementById('palette');
  const sequenceEl = document.getElementById('sequence');
  const emptySeq = document.getElementById('emptySeq');
  const clearSequence = document.getElementById('clearSequence');
  const runBtn = document.getElementById('runBtn');
  const status = document.getElementById('status');
  const runProgress = document.getElementById('runProgress');
  const progressPhase = document.getElementById('progressPhase');
  const progressCount = document.getElementById('progressCount');
  const progressLast = document.getElementById('progressLast');
  const progressBar = document.getElementById('progressBar');
  const progressBarFill = document.getElementById('progressBarFill');
  const progressElapsed = document.getElementById('progressElapsed');
  const progressEta = document.getElementById('progressEta');
  const resultPanel = document.getElementById('resultPanel');
  const reportSummary = document.getElementById('reportSummary');
  const reportTable = document.querySelector('#reportTable tbody');
  const downloadLink = document.getElementById('downloadLink');

  let file = null;
  let schemaFile = null;
  /** @type {{id:string,options:object,uid:string}[]} */
  let sequence = [];
  let dragUid = null;
  /** @type {ReturnType<typeof setInterval>|null} */
  let progressTimer = null;
  let runStartedAt = 0;
  let runProgressFraction = 0;
  /** @type {number[]} */
  let hopDurationsMs = [];
  let hopsRemaining = 0;
  let packingPending = false;

  function uid() {
    return Math.random().toString(36).slice(2, 10);
  }

  function updateRunEnabled() {
    runBtn.disabled = !(file && sequence.length);
  }

  function humanBytes(n) {
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  }

  function warnIfOverUploadLimit(selected) {
    const limits = cfg.upload_limits || {};
    const max = Number(limits.upload_max_filesize_bytes || 0);
    const post = Number(limits.post_max_size_bytes || 0);
    const cap = Math.min(max || Infinity, post || Infinity);
    if (!selected || !Number.isFinite(cap) || cap === Infinity) return;
    if (selected.size > cap) {
      status.textContent = `This file is ${humanBytes(selected.size)} but PHP only allows ${humanBytes(cap)} (upload_max_filesize=${limits.upload_max_filesize}, post_max_size=${limits.post_max_size}). Raise those limits (.htaccess / .user.ini / php.ini) and reload.`;
    }
  }

  function renderSequence() {
    sequenceEl.innerHTML = '';
    emptySeq.classList.toggle('hidden', sequence.length > 0);
    sequence.forEach((step, index) => {
      const meta = hopMeta[step.id] || { label: step.id, description: '' };
      const li = document.createElement('li');
      li.className = 'seq-item';
      li.draggable = true;
      li.dataset.uid = step.uid;
      li.innerHTML = `
        <span class="seq-num">${index + 1}</span>
        <span>
          <span class="seq-label">${escapeHtml(meta.label)}</span>
          <span class="seq-desc">${escapeHtml(meta.description)}</span>
        </span>
        <button type="button" class="seq-remove" aria-label="Remove hop">&times;</button>
      `;
      li.querySelector('.seq-remove').addEventListener('click', () => {
        sequence = sequence.filter((s) => s.uid !== step.uid);
        profileSelect.value = '';
        renderSequence();
        updateRunEnabled();
      });
      li.addEventListener('dragstart', () => {
        dragUid = step.uid;
        li.classList.add('dragging');
      });
      li.addEventListener('dragend', () => {
        dragUid = null;
        li.classList.remove('dragging');
      });
      li.addEventListener('dragover', (e) => {
        e.preventDefault();
        const overUid = li.dataset.uid;
        if (!dragUid || dragUid === overUid) return;
        const from = sequence.findIndex((s) => s.uid === dragUid);
        const to = sequence.findIndex((s) => s.uid === overUid);
        if (from < 0 || to < 0 || from === to) return;
        const [item] = sequence.splice(from, 1);
        sequence.splice(to, 0, item);
        renderSequence();
      });
      sequenceEl.appendChild(li);
    });
  }

  function addHop(id, options = {}) {
    if (!hopMeta[id]) return;
    sequence.push({ id, options: options || {}, uid: uid() });
    renderSequence();
    updateRunEnabled();
  }

  function loadProfileHops(hops) {
    sequence = (hops || []).map((h) => ({
      id: h.id,
      options: h.options || {},
      uid: uid(),
    }));
    renderSequence();
    updateRunEnabled();
  }

  function escapeHtml(str) {
    return String(str)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;');
  }

  function setFile(f) {
    if (!f) return;
    const name = f.name.toLowerCase();
    if (!name.endsWith('.msapp') && !name.endsWith('.zip')) {
      status.textContent = 'Please choose a .msapp file.';
      return;
    }
    file = f;
    fileLabel.textContent = f.name;
    dropZone.classList.add('has-file');
    status.textContent = '';
    warnIfOverUploadLimit(f);
    updateRunEnabled();
  }

  browseBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    fileInput.click();
  });
  dropZone.addEventListener('click', () => fileInput.click());
  dropZone.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      fileInput.click();
    }
  });
  fileInput.addEventListener('change', () => setFile(fileInput.files?.[0]));

  function setSchemaFile(f) {
    if (!f) {
      schemaFile = null;
      schemaLabel.innerHTML = 'Optional. Used by <code>correlate_sharepoint</code> to validate lists/columns and repair typos against your real SharePoint lists.';
      return;
    }
    if (!f.name.toLowerCase().endsWith('.json')) {
      status.textContent = 'SharePoint schema must be a .json file.';
      return;
    }
    schemaFile = f;
    schemaLabel.textContent = `Schema: ${f.name}`;
    status.textContent = '';
  }

  schemaBrowseBtn?.addEventListener('click', () => schemaInput?.click());
  schemaInput?.addEventListener('change', () => setSchemaFile(schemaInput.files?.[0]));

  ['dragenter', 'dragover'].forEach((evt) => {
    dropZone.addEventListener(evt, (e) => {
      e.preventDefault();
      dropZone.classList.add('dragover');
    });
  });
  ['dragleave', 'drop'].forEach((evt) => {
    dropZone.addEventListener(evt, (e) => {
      e.preventDefault();
      dropZone.classList.remove('dragover');
    });
  });
  dropZone.addEventListener('drop', (e) => {
    const f = e.dataTransfer?.files?.[0];
    setFile(f);
  });

  palette.querySelectorAll('.hop-card').forEach((btn) => {
    btn.addEventListener('click', () => {
      profileSelect.value = '';
      profileHint.textContent = 'Custom sequence';
      addHop(btn.dataset.hopId);
    });
  });

  clearSequence.addEventListener('click', () => {
    sequence = [];
    profileSelect.value = '';
    profileHint.textContent = 'Profiles fill the hop sequence; you can still edit afterward.';
    renderSequence();
    updateRunEnabled();
  });

  profileSelect.addEventListener('change', () => {
    const opt = profileSelect.selectedOptions[0];
    if (!opt || !opt.value) {
      profileHint.textContent = 'Custom sequence';
      return;
    }
    const hops = JSON.parse(opt.dataset.hops || '[]');
    const profile = (cfg.profiles || []).find((p) => p.id === opt.value);
    const forceNote = profile?.force ? ' Force mode: overwrites existing hop values.' : '';
    profileHint.textContent = (profile?.description || '') + forceNote;
    loadProfileHops(hops);
  });

  function showProgress(show) {
    runProgress.classList.toggle('hidden', !show);
  }

  function formatDuration(ms) {
    const totalSec = Math.max(0, Math.round(Number(ms) / 1000));
    const m = Math.floor(totalSec / 60);
    const s = totalSec % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
  }

  function setProgressFraction(fraction, { indeterminate = false } = {}) {
    const clamped = Math.max(0, Math.min(1, Number(fraction) || 0));
    runProgressFraction = clamped;
    if (!progressBar || !progressBarFill) return;
    if (indeterminate) {
      progressBar.dataset.indeterminate = '1';
      progressBar.setAttribute('aria-valuenow', '0');
      progressBarFill.style.width = '28%';
      return;
    }
    delete progressBar.dataset.indeterminate;
    const pct = Math.round(clamped * 100);
    progressBar.setAttribute('aria-valuenow', String(pct));
    progressBarFill.style.width = `${pct}%`;
  }

  function estimateRemainingMs() {
    if (runProgressFraction >= 0.999) return 0;
    const elapsed = Date.now() - runStartedAt;
    let eta = null;
    if (hopDurationsMs.length > 0 && (hopsRemaining > 0 || packingPending)) {
      const avg = hopDurationsMs.reduce((a, b) => a + b, 0) / hopDurationsMs.length;
      eta = avg * hopsRemaining + (packingPending ? Math.max(avg * 0.35, 800) : 0);
    }
    if (runProgressFraction >= 0.04) {
      const fromFraction = (elapsed / runProgressFraction) * (1 - runProgressFraction);
      eta = eta == null ? fromFraction : (eta * 0.55 + fromFraction * 0.45);
    }
    return eta;
  }

  function refreshProgressTimes({ finalMs = null, failed = false } = {}) {
    if (!progressElapsed || !progressEta) return;
    const elapsed = finalMs != null ? finalMs : (runStartedAt ? Date.now() - runStartedAt : 0);
    progressElapsed.textContent = `Elapsed ${formatDuration(elapsed)}`;
    if (failed) {
      progressEta.textContent = 'Stopped';
      return;
    }
    if (finalMs != null || runProgressFraction >= 0.999) {
      progressEta.textContent = `Total ${formatDuration(elapsed)}`;
      return;
    }
    const eta = estimateRemainingMs();
    if (eta == null) {
      progressEta.textContent = 'Estimating…';
      return;
    }
    progressEta.textContent = eta < 1500 ? 'Almost done…' : `~${formatDuration(eta)} left`;
  }

  function startProgressClock() {
    stopProgressClock();
    runStartedAt = Date.now();
    hopDurationsMs = [];
    hopsRemaining = sequence.length;
    packingPending = true;
    runProgressFraction = 0;
    setProgressFraction(0, { indeterminate: true });
    refreshProgressTimes();
    progressTimer = setInterval(() => refreshProgressTimes(), 250);
  }

  function stopProgressClock() {
    if (progressTimer != null) {
      clearInterval(progressTimer);
      progressTimer = null;
    }
  }

  function formatChangeLine(ev) {
    const hop = hopMeta[ev.hop]?.label || ev.hop || 'change';
    const control = ev.control || '?';
    const property = ev.property || '';
    const to = ev.to === undefined || ev.to === null || ev.to === '' ? '(empty)' : String(ev.to);
    return `${hop}: ${control}.${property} → ${to}`;
  }

  function applyProgressEvent(ev) {
    if (typeof ev.progress === 'number') {
      setProgressFraction(ev.progress);
    }
    if (ev.type === 'phase') {
      progressPhase.textContent = ev.message || ev.label || ev.phase || 'Working…';
      if (typeof ev.count === 'number') {
        progressCount.textContent = `${ev.count} change${ev.count === 1 ? '' : 's'}`;
      }
      if (ev.phase === 'hop' && typeof ev.total === 'number' && typeof ev.index === 'number') {
        hopsRemaining = Math.max(0, ev.total - ev.index + 1);
        packingPending = true;
        if (typeof ev.progress !== 'number' && ev.total > 0) {
          setProgressFraction((ev.index - 1) / (ev.total + 2));
        }
      }
      if (ev.phase === 'pack') {
        hopsRemaining = 0;
        packingPending = true;
      }
      if (ev.phase === 'pack_done' || ev.phase === 'unpack_done') {
        packingPending = ev.phase !== 'pack_done';
      }
    } else if (ev.type === 'hop_done') {
      if (typeof ev.duration_ms === 'number' && ev.duration_ms >= 0) {
        hopDurationsMs.push(ev.duration_ms);
      }
      if (typeof ev.total === 'number' && typeof ev.index === 'number') {
        hopsRemaining = Math.max(0, ev.total - ev.index);
      }
      progressPhase.textContent = ev.message || `Finished ${ev.label || ev.hop || 'hop'}`;
      if (typeof ev.count === 'number') {
        progressCount.textContent = `${ev.count} change${ev.count === 1 ? '' : 's'}`;
      }
    } else if (ev.type === 'change') {
      progressCount.textContent = `${ev.count} change${ev.count === 1 ? '' : 's'}`;
      progressLast.textContent = formatChangeLine(ev);
    }
    refreshProgressTimes();
  }

  function markRunFinished(data = {}) {
    const total = data.report?.total ?? 0;
    const elapsedMs = typeof data.elapsed_ms === 'number'
      ? data.elapsed_ms
      : (runStartedAt ? Date.now() - runStartedAt : 0);
    if (progressPhase) {
      progressPhase.textContent = 'Finished';
    }
    if (progressCount) {
      progressCount.textContent = `${total} change${total === 1 ? '' : 's'}`;
    }
    if (progressLast && (!progressLast.textContent || /waiting for first/i.test(progressLast.textContent))) {
      progressLast.textContent = 'Sweep complete';
    }
    setProgressFraction(1);
    hopsRemaining = 0;
    packingPending = false;
    stopProgressClock();
    refreshProgressTimes({ finalMs: elapsedMs });
    return elapsedMs;
  }

  function applyResult(data) {
    // Update progress chrome first so we never remain on "Starting…" after success.
    const elapsedMs = markRunFinished(data);
    const total = data.report?.total ?? 0;
    const byHop = data.report?.by_hop || {};
    const parts = Object.entries(byHop).map(([k, v]) => `${k}: ${v}`);
    if (reportSummary) {
      reportSummary.textContent = `${total} change${total === 1 ? '' : 's'}`
        + ` in ${formatDuration(elapsedMs)}`
        + (parts.length ? ` — ${parts.join(' · ')}` : '');
    }

    if (reportTable) {
      reportTable.innerHTML = '';
      (data.report?.entries || []).forEach((row) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${escapeHtml(row.hop)}</td>
          <td>${escapeHtml(row.control)}</td>
          <td>${escapeHtml(row.property)}</td>
          <td>${escapeHtml(row.from)}</td>
          <td>${escapeHtml(row.to)}</td>
        `;
        reportTable.appendChild(tr);
      });
    }

    if (downloadLink && data.download_token) {
      downloadLink.href = `${cfg.apiDownload}?token=${encodeURIComponent(data.download_token)}`;
      downloadLink.download = data.filename || 'cleaned.msapp';
    }
    resultPanel?.classList.remove('hidden');
    if (status) {
      status.textContent = 'Done.';
    }
    resultPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function parseEventLine(line) {
    try {
      return JSON.parse(line);
    } catch {
      return null;
    }
  }

  async function readNdjsonStream(res, onEvent) {
    if (!res.body || typeof res.body.getReader !== 'function') {
      const data = await res.json();
      onEvent(data.type ? data : { type: data.ok ? 'done' : 'error', ...data });
      return;
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let sawEvent = false;

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop() || '';
      for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed) continue;
        const ev = parseEventLine(trimmed);
        if (!ev) continue;
        sawEvent = true;
        onEvent(ev);
      }
    }

    const tail = buffer.trim();
    if (tail) {
      const ev = parseEventLine(tail);
      if (ev) {
        sawEvent = true;
        onEvent(ev);
        return;
      }
      // Proxies sometimes deliver one JSON object with no newlines.
      const whole = parseEventLine(tail);
      if (whole) {
        onEvent(whole.type ? whole : { type: whole.ok ? 'done' : 'error', ...whole });
        return;
      }
    }

    if (!sawEvent && tail === '') {
      // Empty body — caller handles missing done.
    }
  }

  runBtn.addEventListener('click', async () => {
    if (!file || !sequence.length) return;
    status.textContent = '';
    resultPanel.classList.add('hidden');
    runBtn.disabled = true;
    showProgress(true);
    progressPhase.textContent = 'Starting…';
    progressCount.textContent = '';
    progressLast.textContent = 'Waiting for first change…';
    startProgressClock();

    const body = new FormData();
    body.append('msapp', file);
    body.append('hops', JSON.stringify(sequence.map(({ id, options }) => ({ id, options }))));
    body.append('stream', '1');
    if (schemaFile) {
      body.append('sharepoint_schema', schemaFile);
    }

    let finished = null;
    try {
      const res = await fetch(cfg.apiRun, {
        method: 'POST',
        body,
        headers: { Accept: 'application/x-ndjson, application/json' },
      });

      // Leave "Starting…" as soon as the server responds (even if the body is buffered).
      if (progressPhase && /^starting/i.test(progressPhase.textContent || '')) {
        progressPhase.textContent = res.ok ? 'Running…' : 'Failed';
      }
      if (progressLast && /waiting for first/i.test(progressLast.textContent || '')) {
        progressLast.textContent = res.ok ? 'Processing hops…' : (res.statusText || 'Request failed');
      }
      setProgressFraction(Math.max(runProgressFraction, 0.02));

      if (!res.ok) {
        let errMsg = `HTTP ${res.status}`;
        try {
          const errBody = await res.text();
          const parsed = parseEventLine(errBody.trim().split('\n').pop() || '');
          if (parsed?.error) errMsg = parsed.error;
        } catch {
          /* keep status text */
        }
        throw new Error(errMsg);
      }

      const contentType = res.headers.get('content-type') || '';
      let failed = null;

      if (contentType.includes('ndjson') || contentType.includes('json')) {
        // Prefer line-stream when ndjson; also works if a proxy buffers the whole body.
        if (contentType.includes('ndjson')) {
          await readNdjsonStream(res, (ev) => {
            if (ev.type === 'done' || (ev.ok === true && ev.download_token)) {
              finished = { type: 'done', ok: true, ...ev };
              return;
            }
            if (ev.type === 'error' || ev.ok === false) {
              failed = ev;
              return;
            }
            applyProgressEvent(ev);
          });
        } else {
          const data = await res.json();
          if (!data.ok) {
            failed = data;
          } else {
            finished = { type: 'done', ok: true, ...data };
          }
        }
      } else {
        // Unexpected content-type: try NDJSON lines, then plain JSON.
        const text = await res.text();
        const lines = text.split('\n').map((l) => l.trim()).filter(Boolean);
        for (const line of lines) {
          const ev = parseEventLine(line);
          if (!ev) continue;
          if (ev.type === 'done' || (ev.ok === true && ev.download_token)) {
            finished = { type: 'done', ok: true, ...ev };
          } else if (ev.type === 'error' || ev.ok === false) {
            failed = ev;
          } else {
            applyProgressEvent(ev);
          }
        }
        if (!finished && !failed && lines.length === 1) {
          const ev = parseEventLine(lines[0]);
          if (ev?.ok) finished = { type: 'done', ok: true, ...ev };
          else if (ev) failed = ev;
        }
      }

      if (failed) {
        throw new Error(failed.error || 'Run failed');
      }
      if (!finished?.ok) {
        throw new Error('Run failed');
      }
      applyResult(finished);
    } catch (err) {
      status.textContent = err.message || String(err);
      if (finished?.ok) {
        // Result landed but UI follow-up threw — still show finished state.
        markRunFinished(finished);
      } else {
        progressPhase.textContent = 'Failed';
        progressLast.textContent = err.message || String(err);
        stopProgressClock();
        refreshProgressTimes({ failed: true });
      }
    } finally {
      if (finished?.ok && progressPhase && /^starting/i.test(progressPhase.textContent || '')) {
        markRunFinished(finished);
      }
      updateRunEnabled();
    }
  });

  // Default profile
  const defaultOpt = [...profileSelect.options].find((o) => o.value === 'default');
  if (defaultOpt) {
    profileSelect.value = 'default';
    profileSelect.dispatchEvent(new Event('change'));
  } else {
    renderSequence();
  }
})();
