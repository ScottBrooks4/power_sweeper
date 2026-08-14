(() => {
  const cfg = window.POWER_SWEEPER || {};
  const hopMeta = Object.fromEntries((cfg.hops || []).map((h) => [h.id, h]));
  const forceableHops = new Set(cfg.forceable_hops || [
    'accessibility_labels',
    'tooltip_from_label',
    'enable_dark_mode',
    'unwhack_locale_formulas',
    'normalize_containers',
  ]);

  const dropZone = document.getElementById('dropZone');
  const fileInput = document.getElementById('fileInput');
  const browseBtn = document.getElementById('browseBtn');
  const fileLabel = document.getElementById('fileLabel');
  const planPanel = document.getElementById('planPanel');
  const planHint = document.getElementById('planHint');
  const forceHint = document.getElementById('forceHint');
  const planReasons = document.getElementById('planReasons');
  const forceModeSelect = document.getElementById('forceModeSelect');
  const scanLive = document.getElementById('scanLive');
  const detectedHops = document.getElementById('detectedHops');
  const detectedHopsList = document.getElementById('detectedHopsList');
  const hopsLayout = document.querySelector('.hops-layout');
  const palette = document.getElementById('palette');
  const sequenceEl = document.getElementById('sequence');
  const emptySeq = document.getElementById('emptySeq');
  const clearSequence = document.getElementById('clearSequence');
  const runBtn = document.getElementById('runBtn');
  const status = document.getElementById('status');
  const runEstimate = document.getElementById('runEstimate');
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

  /** Baseline ms per hop before file-size scaling (tuned from typical runs). */
  const HOP_COST_MS = {
    enable_dark_mode: 4500,
    meaningful_names: 3500,
    unwhack_locale_formulas: 2800,
    accessibility_labels: 2200,
    tooltip_from_label: 1400,
    normalize_containers: 1800,
    strip_default_fill: 900,
    normalize_classic_button_chrome: 1100,
    align_near_miss: 1000,
    ensure_focus_visible: 1100,
    ensure_tab_index: 900,
    repair_control_refs: 2600,
    repair_context_aware_refs: 2400,
    repair_converge_formulas: 2200,
    repair_double_qualified_refs: 2000,
    repair_studio_syntax: 2100,
    repair_sharepoint_fields: 1700,
    repair_ghost_patch_fields: 1600,
    repair_delegation: 1500,
    repair_maintainability: 1400,
    repair_checked_booleans: 1200,
    repair_var_current_package: 1300,
    correlate_sharepoint: 2000,
    regenerate_sarif: 1300,
    analyze_app_checker: 1600,
    scan_studio_issues: 1400,
    export_web_ir: 3200,
    import_web_ir: 3200,
    configure_power_document: 900,
    set_zip_path_style: 700,
  };
  const DEFAULT_HOP_MS = 900;
  const OVERHEAD_MS = 700;
  const UNPACK_MS = 1100;
  const PACK_MS = 1400;
  const LEARNED_COSTS_KEY = 'ps_hop_costs_v1';

  let file = null;
  /** @type {{id:string,options:object,uid:string}[]} */
  let sequence = [];
  /** Snapshot of sequence for the active run (for weighted remaining ETA). */
  let runSequence = [];
  let dragUid = null;
  /** @type {'all'|'missing_only'} */
  let forceMode = 'missing_only';
  let analyzeAbort = null;
  /** @type {ReturnType<typeof setInterval>|null} */
  let progressTimer = null;
  let runStartedAt = 0;
  let runProgressFraction = 0;
  /** @type {number[]} */
  let hopDurationsMs = [];
  /** @type {Record<string, number>} */
  let hopDurationById = {};
  let hopsRemaining = 0;
  let packingPending = false;
  let plannedTotalMs = 0;

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

  function fileSizeFactor(bytes) {
    if (!bytes || bytes <= 0) return 1;
    const mb = bytes / (1024 * 1024);
    return Math.max(0.75, Math.min(2.6, 0.7 + Math.sqrt(mb) * 0.42));
  }

  function learnedHopCosts() {
    try {
      const raw = localStorage.getItem(LEARNED_COSTS_KEY);
      const parsed = raw ? JSON.parse(raw) : {};
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
      return {};
    }
  }

  function rememberHopCost(id, ms) {
    if (!id || !(ms >= 80) || !(ms < 15 * 60 * 1000)) return;
    const costs = learnedHopCosts();
    const prev = Number(costs[id]);
    costs[id] = Number.isFinite(prev) && prev > 0
      ? Math.round(prev * 0.62 + ms * 0.38)
      : Math.round(ms);
    try {
      localStorage.setItem(LEARNED_COSTS_KEY, JSON.stringify(costs));
    } catch {
      /* ignore quota / private mode */
    }
  }

  function hopBaseCostMs(id, options = {}) {
    const learned = Number(learnedHopCosts()[id]);
    let base = Number.isFinite(learned) && learned > 0
      ? learned
      : (HOP_COST_MS[id] ?? DEFAULT_HOP_MS);
    if (options?.force === true && forceableHops.has(id)) {
      base *= 1.28;
    }
    return base;
  }

  function hopCostMs(id, options = {}) {
    return hopBaseCostMs(id, options) * fileSizeFactor(file?.size || 0);
  }

  function estimateSequenceMs(hops = sequence) {
    if (!hops.length) return 0;
    const scale = fileSizeFactor(file?.size || 0);
    const hopsMs = hops.reduce((sum, step) => sum + hopCostMs(step.id, step.options), 0);
    return OVERHEAD_MS + (UNPACK_MS + PACK_MS) * scale + hopsMs;
  }

  function formatEstimateFriendly(ms) {
    if (!(ms > 0)) return '—';
    if (ms < 2500) return 'a few seconds';
    if (ms < 55_000) return `~${Math.max(1, Math.round(ms / 1000))}s`;
    return `~${formatDuration(ms)}`;
  }

  function updateRunEstimate() {
    if (!runEstimate) return;
    if (!sequence.length) {
      runEstimate.textContent = 'Add hops to estimate runtime';
      runEstimate.classList.add('is-empty');
      plannedTotalMs = 0;
      return;
    }
    plannedTotalMs = estimateSequenceMs();
    const hopWord = sequence.length === 1 ? 'hop' : 'hops';
    const sizeNote = file ? ` · ${humanBytes(file.size)}` : '';
    runEstimate.textContent = `Est. ${formatEstimateFriendly(plannedTotalMs)} for ${sequence.length} ${hopWord}${sizeNote}`;
    runEstimate.classList.remove('is-empty');
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

  function escapeHtml(str) {
    return String(str)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;');
  }

  function withForceOptions(id, options = {}) {
    const opts = { ...(options || {}) };
    if (forceableHops.has(id)) {
      opts.force = forceMode === 'all';
    }
    return opts;
  }

  function applyForceModeToSequence() {
    sequence = sequence.map((step) => ({
      ...step,
      options: withForceOptions(step.id, step.options),
    }));
  }

  function renderSequence() {
    sequenceEl.innerHTML = '';
    emptySeq.classList.toggle('hidden', sequence.length > 0);
    sequence.forEach((step, index) => {
      const meta = hopMeta[step.id] || { label: step.id, description: '' };
      const forced = forceableHops.has(step.id)
        ? (step.options?.force ? ' · all' : ' · missing only')
        : '';
      const li = document.createElement('li');
      li.className = 'seq-item';
      li.draggable = true;
      li.dataset.uid = step.uid;
      li.innerHTML = `
        <span class="seq-num">${index + 1}</span>
        <span>
          <span class="seq-label">${escapeHtml(meta.label)}${escapeHtml(forced)}</span>
          <span class="seq-desc">${escapeHtml(meta.description)}</span>
        </span>
        <button type="button" class="seq-remove" aria-label="Remove hop">&times;</button>
      `;
      li.querySelector('.seq-remove').addEventListener('click', () => {
        sequence = sequence.filter((s) => s.uid !== step.uid);
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
    updateRunEstimate();
  }

  function addHop(id, options = {}) {
    if (!hopMeta[id]) return;
    sequence.push({ id, options: withForceOptions(id, options), uid: uid() });
    renderSequence();
    updateRunEnabled();
  }

  function loadHops(hops) {
    sequence = (hops || []).map((h) => ({
      id: h.id,
      options: withForceOptions(h.id, h.options || {}),
      uid: uid(),
    }));
    renderSequence();
    updateRunEnabled();
  }

  function showPlan(data) {
    planPanel?.classList.remove('hidden');
    planPanel?.classList.add('plan-ready');
    const profile = data.recommended_profile || 'custom';
    const hopCount = (data.hops || []).length;
    planHint.textContent = `Auto-selected “${profile}” (${hopCount} hop${hopCount === 1 ? '' : 's'}). You can still edit the sequence.`;
    if (scanLive) scanLive.textContent = '';
    forceHint.textContent = data.force_mode_reason || '';
    forceMode = data.force_mode === 'all' ? 'all' : 'missing_only';
    if (forceModeSelect) {
      forceModeSelect.value = forceMode;
    }
    if (planReasons) {
      planReasons.innerHTML = '';
      (data.reasons || []).forEach((reason) => {
        const li = document.createElement('li');
        li.textContent = reason;
        planReasons.appendChild(li);
      });
    }
    (data.forceable_hops || []).forEach((id) => forceableHops.add(id));
    renderDetectedHops(data.hops || []);
    loadHops(data.hops || []);
    requestAnimationFrame(() => {
      planPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      hopsLayout?.classList.add('sequence-reveal');
    });
  }

  function renderDetectedHops(hops) {
    if (!detectedHops || !detectedHopsList) return;
    detectedHopsList.innerHTML = '';
    const list = hops || [];
    if (list.length === 0) {
      detectedHops.classList.add('hidden');
      return;
    }
    detectedHops.classList.remove('hidden');
    detectedHops.classList.remove('slide-in');
    // Retrigger animation
    void detectedHops.offsetWidth;
    detectedHops.classList.add('slide-in');
    list.forEach((step, index) => {
      const id = step.id || step;
      const meta = hopMeta[id];
      const li = document.createElement('li');
      li.style.animationDelay = `${0.05 + index * 0.045}s`;
      li.innerHTML = `<span class="detected-hop-name">${escapeHtml(meta?.label || id)}</span>`
        + (meta?.description ? `<span class="detected-hop-desc">${escapeHtml(meta.description)}</span>` : '');
      detectedHopsList.appendChild(li);
    });
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;');
  }

  function setScanProgress(message) {
    if (scanLive) scanLive.textContent = message || '';
    if (planHint && message) planHint.textContent = 'Scanning…';
  }

  async function readAnalyzeStream(res) {
    if (!res.body || typeof res.body.getReader !== 'function') {
      const text = await res.text();
      return parseAnalyzePayload(text, res.status);
    }
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let result = null;
    let error = null;
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop() || '';
      for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed) continue;
        let event;
        try {
          event = JSON.parse(trimmed);
        } catch {
          throw new Error('Analyze returned non-JSON. Is /api/analyze.php reachable on this host?');
        }
        if (event.type === 'progress') {
          setScanProgress(event.message || 'Scanning…');
        } else if (event.type === 'result' || event.ok === true) {
          result = event;
        } else if (event.type === 'error' || event.ok === false) {
          error = event;
        }
      }
    }
    const tail = buffer.trim();
    if (tail) {
      try {
        const event = JSON.parse(tail);
        if (event.type === 'progress') setScanProgress(event.message || 'Scanning…');
        else if (event.type === 'result' || event.ok === true) result = event;
        else if (event.type === 'error' || event.ok === false) error = event;
      } catch {
        throw new Error('Analyze returned non-JSON. Is /api/analyze.php reachable on this host?');
      }
    }
    if (error) {
      const err = new Error(error.error || `Analyze failed (HTTP ${res.status})`);
      err.status = res.status;
      throw err;
    }
    if (!result) {
      throw new Error(`Analyze failed (HTTP ${res.status})`);
    }
    return result;
  }

  function parseAnalyzePayload(text, status) {
    const trimmed = (text || '').trim();
    if (!trimmed) {
      throw new Error(`Analyze failed (HTTP ${status})`);
    }
    if (trimmed.startsWith('<')) {
      throw new Error('Analyze endpoint returned HTML instead of JSON — /api/analyze.php is not executing PHP on this host.');
    }
    const lines = trimmed.split('\n').map((l) => l.trim()).filter(Boolean);
    let result = null;
    let error = null;
    for (const line of lines) {
      let event;
      try {
        event = JSON.parse(line);
      } catch {
        throw new Error('Analyze returned non-JSON. Is /api/analyze.php reachable on this host?');
      }
      if (event.type === 'progress') setScanProgress(event.message || 'Scanning…');
      else if (event.type === 'result' || event.ok === true) result = event;
      else if (event.type === 'error' || event.ok === false) error = event;
    }
    if (error) throw new Error(error.error || `Analyze failed (HTTP ${status})`);
    if (!result) throw new Error(`Analyze failed (HTTP ${status})`);
    return result;
  }

  async function analyzeFile(selected) {
    if (!selected || !cfg.apiAnalyze) return;
    if (analyzeAbort) {
      analyzeAbort.abort();
    }
    analyzeAbort = new AbortController();
    planPanel?.classList.remove('hidden', 'plan-ready');
    hopsLayout?.classList.remove('sequence-reveal');
    detectedHops?.classList.add('hidden');
    if (planHint) planHint.textContent = 'Scanning…';
    setScanProgress('Uploading app for analysis…');
    if (forceHint) forceHint.textContent = '';
    if (planReasons) planReasons.innerHTML = '';
    status.textContent = '';
    sequence = [];
    renderSequence();
    updateRunEnabled();

    const body = new FormData();
    body.append('msapp', selected);
    try {
      const res = await fetch(cfg.apiAnalyze, {
        method: 'POST',
        body,
        signal: analyzeAbort.signal,
        headers: { Accept: 'application/x-ndjson, application/json' },
      });
      const data = await readAnalyzeStream(res);
      if (!data.ok) {
        throw new Error(data.error || `Analyze failed (HTTP ${res.status})`);
      }
      showPlan(data);
    } catch (err) {
      if (err?.name === 'AbortError') return;
      if (planHint) {
        planHint.textContent = 'Could not auto-select hops — add them manually from the left.';
      }
      if (scanLive) scanLive.textContent = '';
      if (forceHint) {
        forceHint.textContent = err.message || String(err);
      }
      status.textContent = err.message || String(err);
    }
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
    updateRunEstimate();
    analyzeFile(f);
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
      addHop(btn.dataset.hopId);
    });
  });

  clearSequence.addEventListener('click', () => {
    sequence = [];
    renderSequence();
    updateRunEnabled();
  });

  forceModeSelect?.addEventListener('change', () => {
    forceMode = forceModeSelect.value === 'all' ? 'all' : 'missing_only';
    applyForceModeToSequence();
    renderSequence();
    if (forceHint) {
      forceHint.textContent = forceMode === 'all'
        ? 'All: write over existing labels, theme colors, and container chrome where those hops apply.'
        : 'Missing only: fill gaps and preserve existing values on write-aware hops.';
    }
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

  function remainingHopSteps() {
    if (!runSequence.length || hopsRemaining <= 0) return [];
    const start = Math.max(0, runSequence.length - hopsRemaining);
    return runSequence.slice(start);
  }

  function estimateRemainingMs() {
    if (runProgressFraction >= 0.999) return 0;
    const elapsed = Date.now() - runStartedAt;
    const scale = fileSizeFactor(file?.size || 0);
    let eta = null;

    const remaining = remainingHopSteps();
    if (remaining.length || packingPending) {
      let weighted = remaining.reduce((sum, step) => {
        const learned = hopDurationById[step.id];
        if (Number.isFinite(learned) && learned > 0) {
          return sum + learned;
        }
        return sum + hopCostMs(step.id, step.options);
      }, 0);
      if (packingPending) {
        weighted += PACK_MS * scale;
      }
      eta = weighted;
    }

    if (hopDurationsMs.length > 0 && (hopsRemaining > 0 || packingPending)) {
      const avg = hopDurationsMs.reduce((a, b) => a + b, 0) / hopDurationsMs.length;
      const fromAvg = avg * hopsRemaining + (packingPending ? Math.max(avg * 0.35, 800) : 0);
      eta = eta == null ? fromAvg : (eta * 0.7 + fromAvg * 0.3);
    }

    if (runProgressFraction >= 0.04) {
      const fromFraction = (elapsed / runProgressFraction) * (1 - runProgressFraction);
      eta = eta == null ? fromFraction : (eta * 0.6 + fromFraction * 0.4);
    } else if (eta == null && plannedTotalMs > 0 && runStartedAt) {
      eta = Math.max(0, plannedTotalMs - elapsed);
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
    runSequence = sequence.map((step) => ({ ...step, options: { ...(step.options || {}) } }));
    hopDurationsMs = [];
    hopDurationById = {};
    hopsRemaining = runSequence.length;
    packingPending = true;
    plannedTotalMs = estimateSequenceMs(runSequence);
    runProgressFraction = 0;
    setProgressFraction(0, { indeterminate: true });
    if (runEstimate && plannedTotalMs > 0) {
      runEstimate.textContent = `Plan ${formatEstimateFriendly(plannedTotalMs)} · running…`;
      runEstimate.classList.remove('is-empty');
    }
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
        if (ev.hop) {
          hopDurationById[ev.hop] = ev.duration_ms;
          rememberHopCost(ev.hop, ev.duration_ms);
        }
      }
      if (typeof ev.total === 'number' && typeof ev.index === 'number') {
        hopsRemaining = Math.max(0, ev.total - ev.index);
      } else if (hopsRemaining > 0) {
        hopsRemaining -= 1;
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
    if (progressPhase) progressPhase.textContent = 'Finished';
    if (progressCount) progressCount.textContent = `${total} change${total === 1 ? '' : 's'}`;
    if (progressLast && (!progressLast.textContent || /waiting for first/i.test(progressLast.textContent))) {
      progressLast.textContent = 'Sweep complete';
    }
    setProgressFraction(1);
    hopsRemaining = 0;
    packingPending = false;
    stopProgressClock();
    refreshProgressTimes({ finalMs: elapsedMs });
    updateRunEstimate();
    return elapsedMs;
  }

  function applyResult(data) {
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
    if (status) status.textContent = 'Done.';
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
        if (ev) onEvent(ev);
      }
    }
    const tail = buffer.trim();
    if (tail) {
      const ev = parseEventLine(tail);
      if (ev) onEvent(ev);
    }
  }

  runBtn.addEventListener('click', async () => {
    if (!file || !sequence.length) return;
    applyForceModeToSequence();
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

    let finished = null;
    try {
      const res = await fetch(cfg.apiRun, {
        method: 'POST',
        body,
        headers: { Accept: 'application/x-ndjson, application/json' },
      });

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
          /* keep */
        }
        throw new Error(errMsg);
      }

      const contentType = res.headers.get('content-type') || '';
      let failed = null;

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
      } else if (contentType.includes('json')) {
        const data = await res.json();
        if (!data.ok) failed = data;
        else finished = { type: 'done', ok: true, ...data };
      } else {
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
      }

      if (failed) throw new Error(failed.error || 'Run failed');
      if (!finished?.ok) throw new Error('Run failed');
      applyResult(finished);
    } catch (err) {
      status.textContent = err.message || String(err);
      if (finished?.ok) {
        markRunFinished(finished);
      } else {
        progressPhase.textContent = 'Failed';
        progressLast.textContent = err.message || String(err);
        stopProgressClock();
        refreshProgressTimes({ failed: true });
        updateRunEstimate();
      }
    } finally {
      if (finished?.ok && progressPhase && /^starting/i.test(progressPhase.textContent || '')) {
        markRunFinished(finished);
      }
      updateRunEnabled();
    }
  });

  renderSequence();
})();
