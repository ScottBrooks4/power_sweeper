(() => {
  const cfg = window.POWER_SWEEPER || {};
  const hopMeta = Object.fromEntries((cfg.hops || []).map((h) => [h.id, h]));
  const forceableHops = new Set(cfg.forceable_hops || [
    'accessibility_labels',
    'accessibility_polish',
    'tooltip_from_label',
    'enable_dark_mode',
    'enable_dark_theme',
    'translate',
    'unwhack_locale_formulas',
    'fix_formula_errors',
    'fix_control_names_and_refs',
    'normalize_containers',
    'clean_default_chrome',
    'repair_sharepoint_data',
  ]);

  /** Sub-hops for composites (keep in sync with *Hop::subHops). */
  const COMPOSITE_SUB_HOPS = {
    fix_formula_errors: [
      'unwhack_locale_formulas',
      'repair_double_qualified_refs',
      'repair_control_refs',
      'repair_context_aware_refs',
      'repair_double_qualified_refs',
      'repair_var_current_package',
      'repair_sharepoint_fields',
      'repair_ghost_patch_fields',
      'repair_studio_syntax',
      'repair_checked_booleans',
      'repair_maintainability',
      'repair_delegation',
      'repair_converge_formulas',
      'repair_double_qualified_refs',
      'regenerate_sarif',
    ],
    fix_control_names_and_refs: [
      'meaningful_names',
      'repair_double_qualified_refs',
      'repair_control_refs',
      'repair_context_aware_refs',
      'repair_double_qualified_refs',
      'regenerate_sarif',
    ],
    accessibility_polish: [
      'accessibility_labels',
      'ensure_focus_visible',
      'ensure_tab_index',
      'tooltip_from_label',
      'regenerate_sarif',
    ],
    enable_dark_theme: [
      'prefer_classic_theme_controls',
      'enable_dark_mode',
      'regenerate_sarif',
    ],
    clean_default_chrome: [
      'normalize_containers',
      'strip_default_fill',
      'normalize_classic_button_chrome',
      'regenerate_sarif',
    ],
    repair_sharepoint_data: [
      'correlate_sharepoint',
      'repair_sharepoint_fields',
      'repair_var_current_package',
      'repair_ghost_patch_fields',
      'regenerate_sarif',
    ],
    export_to_web_ir: [
      'meaningful_names',
      'repair_double_qualified_refs',
      'export_web_ir',
      'configure_power_document',
      'regenerate_sarif',
    ],
    import_from_web_ir: [
      'import_web_ir',
      'repair_double_qualified_refs',
      'configure_power_document',
      'accessibility_labels',
      'ensure_focus_visible',
      'ensure_tab_index',
      'tooltip_from_label',
      'regenerate_sarif',
    ],
  };

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
  const skipScanBtn = document.getElementById('skipScanBtn');
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
  /** Fallback hop bases used before estimate_model.json loads. */
  const HOP_COST_MS = {
    enable_dark_mode: 2200,
    translate: 40,
    meaningful_names: 50,
    unwhack_locale_formulas: 220,
    accessibility_labels: 40,
    tooltip_from_label: 20,
    normalize_containers: 40,
    strip_default_fill: 20,
    normalize_classic_button_chrome: 40,
    prefer_classic_theme_controls: 40,
    align_near_miss: 80,
    ensure_focus_visible: 20,
    ensure_tab_index: 20,
    repair_control_refs: 28000,
    repair_context_aware_refs: 3200,
    repair_converge_formulas: 4000,
    repair_double_qualified_refs: 3200,
    repair_studio_syntax: 400,
    repair_sharepoint_fields: 750,
    repair_ghost_patch_fields: 6500,
    repair_delegation: 420,
    repair_maintainability: 160,
    repair_checked_booleans: 200,
    repair_var_current_package: 200,
    // Approx sums of COMPOSITE_SUB_HOPS fallbacks.
    fix_formula_errors: 55850,
    fix_control_names_and_refs: 40050,
    accessibility_polish: 2520,
    enable_dark_theme: 4640,
    clean_default_chrome: 2500,
    repair_sharepoint_data: 10650,
    export_to_web_ir: 8350,
    import_from_web_ir: 8380,
    correlate_sharepoint: 800,
    regenerate_sarif: 2400,
    analyze_app_checker: 1800,
    scan_studio_issues: 1400,
    export_web_ir: 2500,
    import_web_ir: 2500,
    configure_power_document: 200,
    set_zip_path_style: 80,
  };
  const DEFAULT_HOP_MS = 900;
  const LEARNED_SAMPLES_KEY = 'ps_hop_samples_v2';
  const LEARNED_COSTS_KEY = 'ps_hop_costs_v1';

  /** @type {any} */
  let estimateModel = null;
  /** @type {Record<string, any>|null} */
  let appComplexity = null;
  /** @type {Record<string, any>|null} */
  let appSignals = null;

  let file = null;
  /** @type {{id:string,options:object,uid:string}[]} */
  let sequence = [];
  /** Snapshot of sequence for the active run (for weighted remaining ETA). */
  let runSequence = [];
  let dragUid = null;
  /** @type {'all'|'missing_only'} */
  let forceMode = 'missing_only';
  let analyzeAbort = null;
  let runAbort = null;
  /** Bumped whenever a new app is chosen so stale run/analyze UI updates are ignored. */
  let uiEpoch = 0;
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

  function fileMb(bytes = file?.size || appComplexity?.file_bytes || 0) {
    return Math.max(0.01, Number(bytes || 0) / (1024 * 1024));
  }

  function controlCount() {
    const n = Number(appComplexity?.control_count);
    return Number.isFinite(n) && n > 0 ? n : Math.max(80, Math.round(fileMb() * 280));
  }

  function formulaK() {
    const n = Number(appComplexity?.formula_chars);
    return Number.isFinite(n) && n > 0 ? n / 1000 : Math.max(1, controlCount() * 0.8);
  }

  function signalNumber(...keys) {
    let sum = 0;
    let hit = false;
    for (const key of keys) {
      const n = Number(appSignals?.[key]);
      if (Number.isFinite(n) && n > 0) {
        sum += n;
        hit = true;
      }
    }
    return hit ? sum : 0;
  }

  function hopWorkload(id, changes = 0) {
    const controls = controlCount();
    const mapped = estimateModel?.workload_from_signals?.[id];
    if (Array.isArray(mapped) && mapped.length) {
      const fromSignals = signalNumber(...mapped);
      if (fromSignals > 0) {
        return id === 'translate' ? Math.max(1, Math.round(fromSignals * 0.35)) : fromSignals;
      }
    }
    if (id === 'enable_dark_mode') {
      return Math.max(1, signalNumber('opaque_colors', 'modern_themeable_controls', 'white_container_fills') || Math.round(controls * 0.4));
    }
    if (id.startsWith('repair_') || id === 'regenerate_sarif' || id === 'analyze_app_checker' || id === 'scan_studio_issues') {
      return Math.max(
        1,
        changes,
        signalNumber('formula_errors'),
        Math.round(controls * (id === 'regenerate_sarif' ? 1 : 0.08))
      );
    }
    if (changes > 0) return changes;
    return Math.max(1, Math.round(controls * 0.02));
  }

  function learnedSamples() {
    try {
      const raw = localStorage.getItem(LEARNED_SAMPLES_KEY);
      const parsed = raw ? JSON.parse(raw) : {};
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
      return {};
    }
  }

  function rememberHopSample(id, durationMs, changes = 0) {
    if (!id || !(durationMs >= 40) || !(durationMs < 20 * 60 * 1000)) return;
    const sample = {
      duration_ms: Math.round(durationMs),
      changes: Math.max(0, Number(changes) || 0),
      controls: controlCount(),
      file_mb: Number(fileMb().toFixed(4)),
      workload: hopWorkload(id, Number(changes) || 0),
      app: 'live',
    };
    const all = learnedSamples();
    const list = Array.isArray(all[id]) ? all[id] : [];
    list.push(sample);
    all[id] = list.slice(-12);
    try {
      localStorage.setItem(LEARNED_SAMPLES_KEY, JSON.stringify(all));
    } catch {
      /* ignore quota / private mode */
    }
    // Keep legacy scalar cache as a quick blend for remaining-ETA mid-run.
    try {
      const costs = JSON.parse(localStorage.getItem(LEARNED_COSTS_KEY) || '{}') || {};
      const prev = Number(costs[id]);
      costs[id] = Number.isFinite(prev) && prev > 0
        ? Math.round(prev * 0.55 + durationMs * 0.45)
        : Math.round(durationMs);
      localStorage.setItem(LEARNED_COSTS_KEY, JSON.stringify(costs));
    } catch {
      /* ignore */
    }
  }

  function predictFromSamples(samples, controls, mb, workload, heavy) {
    if (!Array.isArray(samples) || !samples.length) return null;
    let num = 0;
    let den = 0;
    for (const s of samples) {
      const sc = Math.max(1, Number(s.controls) || 1);
      const sm = Math.max(0.01, Number(s.file_mb) || 0.01);
      const sw = Math.max(1, Number(s.workload) || 1);
      let ratio = 0.42 * (controls / sc) + 0.28 * (mb / sm) + 0.3 * (workload / sw);
      ratio = Math.max(0.06, Math.min(5.5, ratio));
      if (heavy) ratio = ratio ** 1.2;
      const pred = Math.max(1, Number(s.duration_ms) || 1) * ratio;
      const w = 1 / (0.12 + Math.abs(Math.log(Math.max(0.05, ratio))));
      num += pred * w;
      den += w;
    }
    return den > 0 ? num / den : null;
  }

  function packagePhaseMs(kind) {
    const mb = fileMb();
    const phase = estimateModel?.[kind];
    if (phase && typeof phase === 'object') {
      return Math.max(20, Number(phase.fixed_ms || 0) + Number(phase.per_mb_ms || 0) * mb);
    }
    return kind === 'unpack' ? 80 + 110 * mb : 80 + 160 * mb;
  }

  function hopCostMs(id, options = {}) {
    // Composite: sum child estimates until the model has dedicated samples.
    const compositeKids = COMPOSITE_SUB_HOPS[id];
    if (Array.isArray(compositeKids) && compositeKids.length) {
      const modelHop = estimateModel?.hops?.[id];
      const learned = learnedSamples()[id];
      const hasSamples = (Array.isArray(modelHop?.samples) && modelHop.samples.length)
        || (Array.isArray(learned) && learned.length);
      if (!hasSamples) {
        return Math.max(
          25,
          compositeKids.reduce((sum, sid) => sum + hopCostMs(sid, options), 0)
        );
      }
    }
    const controls = controlCount();
    const mb = fileMb();
    const workload = hopWorkload(id);
    const modelHop = estimateModel?.hops?.[id] || estimateModel?.default_hop;
    const learned = learnedSamples()[id];
    const samples = [
      ...(Array.isArray(modelHop?.samples) ? modelHop.samples : []),
      ...(Array.isArray(learned) ? learned : []),
    ];
    const heavy = Boolean(modelHop?.heavy)
      || id.startsWith('repair_')
      || id === 'fix_formula_errors'
      || id === 'fix_control_names_and_refs'
      || id === 'enable_dark_mode'
      || id === 'enable_dark_theme'
      || id === 'regenerate_sarif';
    let ms = predictFromSamples(samples, controls, mb, workload, heavy);
    if (!(ms > 0)) {
      try {
        const legacy = Number(JSON.parse(localStorage.getItem(LEARNED_COSTS_KEY) || '{}')?.[id]);
        if (Number.isFinite(legacy) && legacy > 0) ms = legacy;
      } catch {
        /* ignore */
      }
    }
    if (!(ms > 0)) {
      ms = HOP_COST_MS[id] ?? DEFAULT_HOP_MS;
      // Soft size scaling when model samples are missing.
      ms *= Math.max(0.35, Math.min(2.4, 0.45 + Math.sqrt(mb) * 0.35 + controls / 3500));
    }
    if (options?.force === true && forceableHops.has(id)) {
      ms *= Number(estimateModel?.force_multiplier) || 1.12;
    }
    return Math.max(25, ms);
  }

  function estimateSequenceMs(hops = sequence) {
    if (!hops.length) return 0;
    const hopsMs = hops.reduce((sum, step) => sum + hopCostMs(step.id, step.options), 0);
    const overhead = Number(estimateModel?.overhead_ms) || 280;
    return overhead + packagePhaseMs('unpack') + packagePhaseMs('pack') + hopsMs;
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
    const controlNote = appComplexity?.control_count
      ? ` · ${Number(appComplexity.control_count).toLocaleString()} controls`
      : '';
    runEstimate.textContent = `Est. ${formatEstimateFriendly(plannedTotalMs)} for ${sequence.length} ${hopWord}${sizeNote}${controlNote}`;
    runEstimate.classList.remove('is-empty');
  }

  async function loadEstimateModel() {
    try {
      const res = await fetch('assets/estimate_model.json', { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (data && typeof data === 'object') {
        estimateModel = data;
        updateRunEstimate();
      }
    } catch {
      /* keep fallbacks */
    }
  }
  loadEstimateModel();

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
    setSkipScanVisible(false);
    appComplexity = data.complexity && typeof data.complexity === 'object' ? data.complexity : appComplexity;
    appSignals = data.signals && typeof data.signals === 'object' ? data.signals : appSignals;
    planPanel?.classList.remove('hidden');
    planPanel?.classList.add('plan-ready');
    const hopCount = (data.hops || []).length;
    planHint.textContent = hopCount === 0
      ? 'No actionable hops detected. Add hops from the left if you still want to run something.'
      : `Detected ${hopCount} hop${hopCount === 1 ? '' : 's'}. You can still edit the sequence.`;
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
    loadHops(data.hops || []);
    updateRunEstimate();
    requestAnimationFrame(() => {
      planPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      hopsLayout?.classList.add('sequence-reveal');
    });
  }

  function setSkipScanVisible(visible) {
    if (!skipScanBtn) return;
    skipScanBtn.hidden = !visible;
    skipScanBtn.disabled = !visible;
    skipScanBtn.setAttribute('aria-hidden', visible ? 'false' : 'true');
  }

  function skipScan() {
    if (analyzeAbort) {
      analyzeAbort.abort();
      analyzeAbort = null;
    }
    setSkipScanVisible(false);
    planPanel?.classList.remove('hidden');
    planPanel?.classList.add('plan-ready');
    hopsLayout?.classList.remove('sequence-reveal');
    if (planHint) {
      planHint.textContent = 'Scan skipped — add hops from the left. Order matters.';
    }
    if (scanLive) scanLive.textContent = '';
    if (forceHint) forceHint.textContent = '';
    if (planReasons) planReasons.innerHTML = '';
    sequence = [];
    renderSequence();
    updateRunEnabled();
    updateRunEstimate();
    status.textContent = '';
    requestAnimationFrame(() => {
      hopsLayout?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  function setScanProgress(message) {
    if (scanLive) scanLive.textContent = message || '';
    if (planHint && message) planHint.textContent = 'Scanning…';
  }

  function analyzeParseError(status, sample) {
    const preview = String(sample || '').replace(/\s+/g, ' ').trim().slice(0, 180);
    if (!preview) {
      return new Error(`Analyze failed (HTTP ${status}) — empty response from ${cfg.apiAnalyze || '/api/analyze.php'}`);
    }
    if (preview.startsWith('<')) {
      return new Error(`Analyze returned HTML (HTTP ${status}) — PHP may not be executing at ${cfg.apiAnalyze || '/api/analyze.php'}`);
    }
    return new Error(`Analyze returned non-JSON (HTTP ${status}): ${preview}`);
  }

  function applyAnalyzeEvent(event, state) {
    if (event.type === 'progress') {
      setScanProgress(event.message || 'Scanning…');
    } else if (event.type === 'result' || event.ok === true) {
      state.result = event;
    } else if (event.type === 'error' || event.ok === false) {
      state.error = event;
    }
  }

  function parseAnalyzeLine(line, status) {
    const trimmed = String(line || '').replace(/^\uFEFF/, '').trim();
    if (!trimmed) return null;
    try {
      return JSON.parse(trimmed);
    } catch {
      throw analyzeParseError(status, trimmed);
    }
  }

  async function readAnalyzeStream(res) {
    const ctype = (res.headers.get('content-type') || '').toLowerCase();
    if (ctype.includes('text/html')) {
      const text = await res.text();
      throw analyzeParseError(res.status, text);
    }
    if (!res.body || typeof res.body.getReader !== 'function') {
      const text = await res.text();
      return parseAnalyzePayload(text, res.status);
    }
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    const state = { result: null, error: null };
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop() || '';
      for (const line of lines) {
        const event = parseAnalyzeLine(line, res.status);
        if (event) applyAnalyzeEvent(event, state);
      }
    }
    const tail = buffer.replace(/^\uFEFF/, '').trim();
    if (tail) {
      // Single JSON object body (non-streaming) or final NDJSON line
      try {
        applyAnalyzeEvent(JSON.parse(tail), state);
      } catch {
        if (!state.result && !state.error) {
          return parseAnalyzePayload(tail, res.status);
        }
        throw analyzeParseError(res.status, tail);
      }
    }
    if (state.error) {
      const err = new Error(state.error.error || `Analyze failed (HTTP ${res.status})`);
      err.status = res.status;
      throw err;
    }
    if (!state.result) {
      throw new Error(`Analyze failed (HTTP ${res.status}) — no result event from ${cfg.apiAnalyze || '/api/analyze.php'}`);
    }
    return state.result;
  }

  function parseAnalyzePayload(text, status) {
    const trimmed = String(text || '').replace(/^\uFEFF/, '').trim();
    if (!trimmed) {
      throw new Error(`Analyze failed (HTTP ${status})`);
    }
    if (trimmed.startsWith('<')) {
      throw analyzeParseError(status, trimmed);
    }
    // Legacy single-object JSON
    if (trimmed.startsWith('{') && !trimmed.includes('\n')) {
      try {
        const event = JSON.parse(trimmed);
        if (event.type === 'error' || event.ok === false) {
          throw new Error(event.error || `Analyze failed (HTTP ${status})`);
        }
        if (event.type === 'result' || event.ok === true) return event;
      } catch (err) {
        if (err instanceof Error && !err.message.startsWith('Analyze returned')) throw err;
      }
    }
    const lines = trimmed.split('\n').map((l) => l.trim()).filter(Boolean);
    const state = { result: null, error: null };
    for (const line of lines) {
      const event = parseAnalyzeLine(line, status);
      if (event) applyAnalyzeEvent(event, state);
    }
    if (state.error) throw new Error(state.error.error || `Analyze failed (HTTP ${status})`);
    if (!state.result) throw new Error(`Analyze failed (HTTP ${status})`);
    return state.result;
  }

  async function analyzeFile(selected) {
    if (!selected || !cfg.apiAnalyze) return;
    const epoch = uiEpoch;
    if (analyzeAbort) {
      analyzeAbort.abort();
    }
    analyzeAbort = new AbortController();
    planPanel?.classList.remove('hidden', 'plan-ready');
    hopsLayout?.classList.remove('sequence-reveal');
    setSkipScanVisible(true);
    if (planHint) planHint.textContent = 'Scanning…';
    setScanProgress('Uploading app for analysis…');
    if (forceHint) forceHint.textContent = '';
    if (planReasons) planReasons.innerHTML = '';
    // Keep the dock clear while the analyze upload is in flight.
    if (epoch === uiEpoch) {
      showProgress(false);
      if (resultPanel) resultPanel.classList.add('hidden');
      if (status) status.textContent = '';
    }
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
      if (epoch !== uiEpoch) return;
      if (res.status === 413) {
        throw new Error(
          `Upload too large for the web server (HTTP 413). File is ${humanBytes(selected.size)}. `
          + 'Nginx/PHP body limits need to be raised on the host (Power Sweeper targets 512M).'
        );
      }
      const data = await readAnalyzeStream(res);
      if (epoch !== uiEpoch) return;
      if (!data.ok) {
        throw new Error(data.error || `Analyze failed (HTTP ${res.status})`);
      }
      showPlan(data);
    } catch (err) {
      if (err?.name === 'AbortError' || epoch !== uiEpoch) return;
      setSkipScanVisible(false);
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

    // Immediate dock reset — before analyze upload — and cancel any in-flight sweep
    // so its NDJSON events cannot paint over the cleared bottom bar.
    uiEpoch += 1;
    if (runAbort) {
      runAbort.abort();
      runAbort = null;
    }
    sequence = [];
    appComplexity = null;
    appSignals = null;
    renderSequence();
    resetBottomUi();

    file = f;
    fileLabel.textContent = f.name;
    dropZone.classList.add('has-file');
    warnIfOverUploadLimit(f);
    updateRunEnabled();
    updateRunEstimate();
    analyzeFile(f);
  }

  browseBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    fileInput.click();
  });
  function onSkipScanClick(e) {
    e.preventDefault();
    e.stopPropagation();
    skipScan();
  }
  skipScanBtn?.addEventListener('click', onSkipScanClick);
  dropZone.addEventListener('click', () => {
    fileInput.click();
  });
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

  function resetBottomUi() {
    stopProgressClock();
    showProgress(false);
    runStartedAt = 0;
    runSequence = [];
    hopDurationsMs = [];
    hopDurationById = {};
    hopsRemaining = 0;
    packingPending = false;
    plannedTotalMs = 0;
    runProgressFraction = 0;
    setProgressFraction(0);
    if (progressPhase) progressPhase.textContent = 'Ready';
    if (progressCount) progressCount.textContent = '';
    if (progressLast) progressLast.textContent = '';
    if (progressElapsed) progressElapsed.textContent = 'Elapsed 0:00';
    if (progressEta) progressEta.textContent = 'Estimating…';
    if (progressBar) delete progressBar.dataset.indeterminate;
    if (resultPanel) resultPanel.classList.add('hidden');
    if (reportSummary) reportSummary.textContent = '';
    if (reportTable) reportTable.innerHTML = '';
    if (downloadLink) {
      downloadLink.removeAttribute('href');
      downloadLink.removeAttribute('download');
    }
    if (status) status.textContent = '';
    if (runEstimate) {
      runEstimate.textContent = 'Add hops to estimate runtime';
      runEstimate.classList.add('is-empty');
    }
    updateRunEstimate();
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
        weighted += packagePhaseMs('pack');
      }
      eta = weighted;
    }

    if (hopDurationsMs.length > 0 && (hopsRemaining > 0 || packingPending)) {
      const avg = hopDurationsMs.reduce((a, b) => a + b, 0) / hopDurationsMs.length;
      const fromAvg = avg * hopsRemaining + (packingPending ? Math.max(avg * 0.35, packagePhaseMs('pack') * 0.65) : 0);
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
      if (ev.phase === 'unpack_done' && ev.complexity && typeof ev.complexity === 'object') {
        appComplexity = { ...(appComplexity || {}), ...ev.complexity };
        updateRunEstimate();
      }
    } else if (ev.type === 'hop_done') {
      if (typeof ev.duration_ms === 'number' && ev.duration_ms >= 0) {
        hopDurationsMs.push(ev.duration_ms);
        if (ev.hop) {
          hopDurationById[ev.hop] = ev.duration_ms;
          rememberHopSample(ev.hop, ev.duration_ms, ev.changes);
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
    const parts = Object.entries(byHop).map(([k, v]) => `${hopMeta[k]?.label || k}: ${v}`);
    if (reportSummary) {
      const truncated = data.report?.entries_truncated
        ? ` (showing ${data.report.entries?.length || 0} of ${total})`
        : '';
      reportSummary.textContent = `${total} change${total === 1 ? '' : 's'}`
        + ` in ${formatDuration(elapsedMs)}`
        + (parts.length ? ` — ${parts.join(' · ')}` : '')
        + truncated;
    }
    if (reportTable) {
      reportTable.innerHTML = '';
      const rows = data.report?.entries || [];
      const maxRows = 500;
      rows.slice(0, maxRows).forEach((row) => {
        const hopLabel = hopMeta[row.hop]?.label || row.hop;
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${escapeHtml(hopLabel)}</td>
          <td>${escapeHtml(row.control)}</td>
          <td>${escapeHtml(row.property)}</td>
          <td><code class="report-from">${escapeHtml(row.from)}</code></td>
          <td><code class="report-to">${escapeHtml(row.to)}</code></td>
        `;
        reportTable.appendChild(tr);
      });
      if (rows.length > maxRows || data.report?.entries_truncated) {
        const tr = document.createElement('tr');
        const omitted = data.report?.entries_omitted
          ?? Math.max(0, total - Math.min(rows.length, maxRows));
        tr.innerHTML = `<td colspan="5" class="hint">…and ${omitted} more change${omitted === 1 ? '' : 's'} (preview capped to keep memory low — download the cleaned .msapp).</td>`;
        reportTable.appendChild(tr);
      }
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
    const epoch = uiEpoch;
    if (runAbort) {
      runAbort.abort();
    }
    runAbort = new AbortController();
    const signal = runAbort.signal;
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
        signal,
        headers: { Accept: 'application/x-ndjson, application/json' },
      });

      if (epoch !== uiEpoch) return;

      if (res.status === 413) {
        throw new Error(
          `Upload too large for the web server (HTTP 413). File is ${humanBytes(file.size)}. `
          + 'Nginx/PHP body limits need to be raised on the host (Power Sweeper targets 512M).'
        );
      }

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
          if (epoch !== uiEpoch) return;
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
        if (epoch !== uiEpoch) return;
        if (!data.ok) failed = data;
        else finished = { type: 'done', ok: true, ...data };
      } else {
        const text = await res.text();
        if (epoch !== uiEpoch) return;
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

      if (epoch !== uiEpoch) return;
      if (failed) throw new Error(failed.error || 'Run failed');
      if (!finished?.ok) {
        throw new Error(
          'Run failed — the server stopped before finishing (often out of memory on large apps like THCEE). '
          + 'Retry after deploy, or run Enable dark mode / Fix formula errors alone with fewer other hops.'
        );
      }
      applyResult(finished);
    } catch (err) {
      if (epoch !== uiEpoch || err?.name === 'AbortError') return;
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
      if (epoch === uiEpoch) {
        if (finished?.ok && progressPhase && /^starting/i.test(progressPhase.textContent || '')) {
          markRunFinished(finished);
        }
        updateRunEnabled();
      }
      if (runAbort && runAbort.signal === signal) {
        runAbort = null;
      }
    }
  });

  renderSequence();
})();
