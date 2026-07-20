(() => {
  const cfg = window.POWER_SWEEPER || {};
  const hopMeta = Object.fromEntries((cfg.hops || []).map((h) => [h.id, h]));

  const dropZone = document.getElementById('dropZone');
  const fileInput = document.getElementById('fileInput');
  const browseBtn = document.getElementById('browseBtn');
  const fileLabel = document.getElementById('fileLabel');
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
  const resultPanel = document.getElementById('resultPanel');
  const reportSummary = document.getElementById('reportSummary');
  const reportTable = document.querySelector('#reportTable tbody');
  const downloadLink = document.getElementById('downloadLink');

  let file = null;
  /** @type {{id:string,options:object,uid:string}[]} */
  let sequence = [];
  let dragUid = null;

  function uid() {
    return Math.random().toString(36).slice(2, 10);
  }

  function updateRunEnabled() {
    runBtn.disabled = !(file && sequence.length);
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
    profileHint.textContent = profile?.description || '';
    loadProfileHops(hops);
  });

  function showProgress(show) {
    runProgress.classList.toggle('hidden', !show);
  }

  function formatChangeLine(ev) {
    const hop = hopMeta[ev.hop]?.label || ev.hop || 'change';
    const control = ev.control || '?';
    const property = ev.property || '';
    const to = ev.to === undefined || ev.to === null || ev.to === '' ? '(empty)' : String(ev.to);
    return `${hop}: ${control}.${property} → ${to}`;
  }

  function applyResult(data) {
    const total = data.report?.total ?? 0;
    const byHop = data.report?.by_hop || {};
    const parts = Object.entries(byHop).map(([k, v]) => `${k}: ${v}`);
    reportSummary.textContent = `${total} change${total === 1 ? '' : 's'}` + (parts.length ? ` — ${parts.join(' · ')}` : '');

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

    downloadLink.href = `${cfg.apiDownload}?token=${encodeURIComponent(data.download_token)}`;
    downloadLink.download = data.filename || 'cleaned.msapp';
    resultPanel.classList.remove('hidden');
    status.textContent = 'Done.';
    progressPhase.textContent = 'Finished';
    progressCount.textContent = `${total} change${total === 1 ? '' : 's'}`;
    resultPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
        onEvent(JSON.parse(trimmed));
      }
    }

    const tail = buffer.trim();
    if (tail) {
      onEvent(JSON.parse(tail));
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

    const body = new FormData();
    body.append('msapp', file);
    body.append('hops', JSON.stringify(sequence.map(({ id, options }) => ({ id, options }))));
    body.append('stream', '1');

    try {
      const res = await fetch(cfg.apiRun, {
        method: 'POST',
        body,
        headers: { Accept: 'application/x-ndjson, application/json' },
      });

      const contentType = res.headers.get('content-type') || '';
      let finished = null;
      let failed = null;

      if (contentType.includes('ndjson')) {
        await readNdjsonStream(res, (ev) => {
          if (ev.type === 'phase') {
            progressPhase.textContent = ev.message || ev.label || ev.phase || 'Working…';
            if (typeof ev.count === 'number') {
              progressCount.textContent = `${ev.count} change${ev.count === 1 ? '' : 's'}`;
            }
          } else if (ev.type === 'change') {
            progressCount.textContent = `${ev.count} change${ev.count === 1 ? '' : 's'}`;
            progressLast.textContent = formatChangeLine(ev);
          } else if (ev.type === 'done') {
            finished = ev;
          } else if (ev.type === 'error') {
            failed = ev;
          }
        });
      } else {
        const data = await res.json();
        if (!data.ok) {
          failed = data;
        } else {
          finished = { type: 'done', ...data };
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
      progressPhase.textContent = 'Failed';
      progressLast.textContent = err.message || String(err);
    } finally {
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
