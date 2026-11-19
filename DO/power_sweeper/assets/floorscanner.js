(() => {
  const cfg = window.FLOOR_SCANNER || {};
  const dropZone = document.getElementById('dropZone');
  const fileInput = document.getElementById('fileInput');
  const browseBtn = document.getElementById('browseBtn');
  const fileLabel = document.getElementById('fileLabel');
  const scanBtn = document.getElementById('scanBtn');
  const sampleBtn = document.getElementById('sampleBtn');
  const statusEl = document.getElementById('status');
  const resultPanel = document.getElementById('resultPanel');
  const resultSummary = document.getElementById('resultSummary');
  const beforeImg = document.getElementById('beforeImg');
  const afterImg = document.getElementById('afterImg');
  const roomList = document.getElementById('roomList');
  const roomHotspots = document.getElementById('roomHotspots');
  const roomHoverLabel = document.getElementById('roomHoverLabel');
  const overlayStage = document.getElementById('overlayStage');
  const mapLightbox = document.getElementById('mapLightbox');
  const mapLightboxClose = document.getElementById('mapLightboxClose');
  const mapLightboxStage = document.getElementById('mapLightboxStage');
  const mapLightboxImg = document.getElementById('mapLightboxImg');
  const mapLightboxHotspots = document.getElementById('mapLightboxHotspots');
  const mapLightboxHoverLabel = document.getElementById('mapLightboxHoverLabel');
  const downloadLink = document.getElementById('downloadLink');
  const scanProgress = document.getElementById('scanProgress');
  const scanProgressLabel = document.getElementById('scanProgressLabel');
  const scanProgressEta = document.getElementById('scanProgressEta');
  const scanProgressBar = document.getElementById('scanProgressBar');
  const scanProgressFill = document.getElementById('scanProgressFill');

  const MAX_PROCESS = Number(cfg.maxProcess) || 2000;

  let selectedFile = null;
  let beforeUrl = null;
  let overlayUrl = null;
  let activeRoomId = null;
  let lastRooms = [];
  let lastPlanW = 1;
  let lastPlanH = 1;
  let lightboxOpen = false;
  let lightboxScale = 1;
  let lightboxX = 0;
  let lightboxY = 0;
  let lightboxDragging = false;
  let lightboxDragStart = null;
  /** Fraction of the locked ETA reserved for overlay encode / download / parse / render. */
  const FINISH_FRAC = 0.14;

  let progressTimer = null;
  let progressStartedAt = 0;
  /** Locked at scan start — never stretches mid-run (avoids ETA flicker). */
  let progressEstimateMs = 0;
  let progressScanMs = 0;
  let progressFinishMs = 0;
  let progressLastShownSec = null;
  let progressPhase = 'scan'; // 'scan' | 'finish'
  let timingModel = {
    intercept_ms: Number(cfg.timing?.intercept_ms) || 2200,
    ms_per_mp: Number(cfg.timing?.ms_per_mp) || 12000,
  };

  const applyTiming = (timing) => {
    if (!timing || typeof timing !== 'object') {
      return;
    }
    const intercept = Number(timing.intercept_ms);
    const perMp = Number(timing.ms_per_mp);
    if (Number.isFinite(intercept) && intercept > 0) {
      timingModel.intercept_ms = intercept;
    }
    if (Number.isFinite(perMp) && perMp > 0) {
      timingModel.ms_per_mp = perMp;
    }
  };

  const clearRoomHover = () => {
    activeRoomId = null;
    roomList.querySelectorAll('.room-card.is-hover').forEach((el) => el.classList.remove('is-hover'));
    roomHotspots.querySelectorAll('.room-hotspot.is-active').forEach((el) => el.classList.remove('is-active'));
    mapLightboxHotspots.querySelectorAll('.room-hotspot.is-active').forEach((el) => el.classList.remove('is-active'));
    roomHoverLabel.classList.add('hidden');
    roomHoverLabel.textContent = '';
    mapLightboxHoverLabel.classList.add('hidden');
    mapLightboxHoverLabel.textContent = '';
  };

  const placeHoverLabel = (labelEl, stageEl, hotspotEl, text) => {
    labelEl.textContent = text;
    labelEl.classList.remove('hidden');
    const stageBox = stageEl.getBoundingClientRect();
    const hotBox = hotspotEl.getBoundingClientRect();
    const labelW = Math.min(260, stageBox.width * 0.55);
    let left = hotBox.left - stageBox.left + hotBox.width / 2 - labelW / 2;
    left = Math.max(8, Math.min(left, stageBox.width - labelW - 8));
    let top = hotBox.top - stageBox.top - 36;
    if (top < 8) {
      top = hotBox.bottom - stageBox.top + 8;
    }
    labelEl.style.width = `${labelW}px`;
    labelEl.style.left = `${left}px`;
    labelEl.style.top = `${top}px`;
  };

  const showRoomHover = (room, card, hotspot) => {
    if (!room || activeRoomId === room.id) {
      return;
    }
    clearRoomHover();
    activeRoomId = room.id;
    if (card) {
      card.classList.add('is-hover');
    }
    const typeLabel = room.type === 'cubicle' ? 'Cubicle' : 'Room';
    const text = `${typeLabel}: ${room.label}`;
    roomHotspots.querySelectorAll(`[data-room-id="${room.id}"]`).forEach((el) => el.classList.add('is-active'));
    mapLightboxHotspots.querySelectorAll(`[data-room-id="${room.id}"]`).forEach((el) => el.classList.add('is-active'));
    if (hotspot && hotspot.closest('#mapLightboxStage')) {
      placeHoverLabel(mapLightboxHoverLabel, mapLightboxStage, hotspot, text);
    } else if (hotspot) {
      placeHoverLabel(roomHoverLabel, roomHotspots.parentElement, hotspot, text);
    } else if (lightboxOpen) {
      const lbHot = mapLightboxHotspots.querySelector(`[data-room-id="${room.id}"]`);
      if (lbHot) {
        placeHoverLabel(mapLightboxHoverLabel, mapLightboxStage, lbHot, text);
      }
    } else {
      const mainHot = roomHotspots.querySelector(`[data-room-id="${room.id}"]`);
      if (mainHot) {
        placeHoverLabel(roomHoverLabel, roomHotspots.parentElement, mainHot, text);
      }
    }
  };

  const applyLightboxTransform = () => {
    const t = `translate(calc(-50% + ${lightboxX}px), calc(-50% + ${lightboxY}px)) scale(${lightboxScale})`;
    mapLightboxImg.style.transform = t;
    mapLightboxHotspots.style.transform = t;
    const nw = mapLightboxImg.naturalWidth || lastPlanW;
    const nh = mapLightboxImg.naturalHeight || lastPlanH;
    mapLightboxHotspots.style.width = `${nw}px`;
    mapLightboxHotspots.style.height = `${nh}px`;
  };

  const closeLightbox = () => {
    if (!lightboxOpen) {
      return;
    }
    lightboxOpen = false;
    mapLightbox.classList.add('hidden');
    document.body.style.overflow = '';
    clearRoomHover();
  };

  const openLightbox = () => {
    if (!afterImg.getAttribute('src')) {
      return;
    }
    lightboxOpen = true;
    mapLightboxImg.src = afterImg.currentSrc || afterImg.src;
    mapLightbox.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    const fit = () => {
      const sw = mapLightboxStage.clientWidth || 1;
      const sh = mapLightboxStage.clientHeight || 1;
      const nw = mapLightboxImg.naturalWidth || lastPlanW;
      const nh = mapLightboxImg.naturalHeight || lastPlanH;
      lightboxScale = Math.min(sw / nw, sh / nh) * 0.96;
      lightboxX = 0;
      lightboxY = 0;
      applyLightboxTransform();
    };
    if (mapLightboxImg.complete && mapLightboxImg.naturalWidth) {
      fit();
    } else {
      mapLightboxImg.onload = fit;
    }
  };

  const buildHotspot = (room, planW, planH, onActivate) => {
    const bbox = room.bbox || {};
    const hotspot = document.createElement('div');
    hotspot.className = 'room-hotspot';
    hotspot.dataset.roomId = String(room.id);
    hotspot.style.left = `${(Number(bbox.x) / planW) * 100}%`;
    hotspot.style.top = `${(Number(bbox.y) / planH) * 100}%`;
    hotspot.style.width = `${(Number(bbox.width) / planW) * 100}%`;
    hotspot.style.height = `${(Number(bbox.height) / planH) * 100}%`;
    hotspot.addEventListener('mouseenter', () => onActivate(hotspot));
    hotspot.addEventListener('mouseleave', clearRoomHover);
    return hotspot;
  };

  const setStatus = (text, isError = false) => {
    statusEl.textContent = text;
    statusEl.classList.toggle('is-error', isError);
  };

  const formatEta = (ms) => {
    const sec = Math.max(1, Math.ceil(ms / 1000));
    if (sec < 60) {
      return sec === 1 ? 'about 1 second left' : `about ${sec} seconds left`;
    }
    const min = Math.floor(sec / 60);
    const rem = sec % 60;
    if (rem === 0) {
      return min === 1 ? 'about 1 minute left' : `about ${min} minutes left`;
    }
    if (min === 1) {
      return `about 1 min ${rem}s left`;
    }
    return `about ${min} min ${rem}s left`;
  };

  const estimateScanMs = (width, height, fileBytes = 0) => {
    const maxSide = Math.max(width, height, 1);
    const scale = maxSide > MAX_PROCESS ? MAX_PROCESS / maxSide : 1;
    const workW = Math.max(80, Math.round(width * scale));
    const workH = Math.max(80, Math.round(height * scale));
    const megapixels = (workW * workH) / 1e6;
    let scanMs = timingModel.intercept_ms + megapixels * timingModel.ms_per_mp;
    // Large SVG embeds: OCR tiles + wall work scale with bytes as well as pixels.
    if (fileBytes > 0) {
      scanMs += fileBytes / 9000;
      if (fileBytes > 250000) {
        scanMs += 18000;
      }
      if (megapixels > 2) {
        scanMs += megapixels * 4000;
      }
    }
    // Finishing: overlay PNG/SVG encode, JSON transfer, parse, and UI render.
    const finishMs = Math.min(
      28000,
      Math.max(2500, 1800 + megapixels * 2800 + (fileBytes > 0 ? fileBytes / 35000 : 0))
    );
    return Math.round(Math.min(240000, Math.max(3500, scanMs + finishMs)));
  };

  const readImageSize = (src) => new Promise((resolve) => {
    const img = new Image();
    img.onload = () => resolve({ width: img.naturalWidth || img.width, height: img.naturalHeight || img.height });
    img.onerror = () => resolve({ width: 800, height: 600 });
    img.src = src;
  });

  /** Prefer SVG viewBox / logical size over tiny CSS display size for ETA. */
  const probeFileWorkSize = async (file, fallbackUrl) => {
    if (file && (/\.svgz?$/i.test(file.name || '') || file.type === 'image/svg+xml')) {
      try {
        const text = await file.text();
        const vb = text.match(/viewBox=["']\s*([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)/i);
        const cssW = Number((text.match(/\bwidth=["']([0-9.]+)/i) || [])[1] || 0);
        const cssH = Number((text.match(/\bheight=["']([0-9.]+)/i) || [])[1] || 0);
        if (vb) {
          let width = Math.max(1, Math.round(Number(vb[3])));
          let height = Math.max(1, Math.round(Number(vb[4])));
          // Mild CSS oversample uses CSS size server-side; mirror for ETA.
          if (cssW >= 80 && cssH >= 80) {
            const over = Math.max(width / cssW, height / cssH);
            if (over > 1.05 && over <= 2.25) {
              width = Math.round(cssW);
              height = Math.round(cssH);
            }
          }
          return { width, height };
        }
        if (cssW >= 80 && cssH >= 80) {
          return { width: Math.round(cssW), height: Math.round(cssH) };
        }
      } catch {
        // fall through
      }
    }
    if (fallbackUrl) {
      return readImageSize(fallbackUrl);
    }
    return { width: 800, height: 600 };
  };

  const setProgressUi = (pct, label, etaText) => {
    const clamped = Math.max(0, Math.min(100, pct));
    scanProgressFill.style.width = `${clamped}%`;
    scanProgressBar.setAttribute('aria-valuenow', String(Math.round(clamped)));
    scanProgressLabel.textContent = label;
    scanProgressEta.textContent = etaText;
  };

  const stopProgressTimer = () => {
    if (progressTimer !== null) {
      window.clearInterval(progressTimer);
      progressTimer = null;
    }
  };

  const tickProgress = () => {
    const elapsed = Date.now() - progressStartedAt;
    const estimate = progressEstimateMs;
    const remainingMs = estimate - elapsed;
    let pct;
    let label = progressPhase === 'finish' ? 'Finishing scan…' : 'Scanning floorplan…';
    if (elapsed <= progressScanMs) {
      pct = Math.min(86, Math.max(2, (elapsed / Math.max(1, progressScanMs)) * 86));
    } else {
      const finishElapsed = elapsed - progressScanMs;
      pct = Math.min(97, 86 + (finishElapsed / Math.max(1, progressFinishMs)) * 11);
      label = 'Finishing scan…';
    }
    let etaText;
    if (remainingMs <= 700) {
      etaText = 'Almost done…';
      progressLastShownSec = 0;
    } else {
      const secLeft = Math.max(1, Math.ceil(remainingMs / 1000));
      if (progressLastShownSec === null || secLeft < progressLastShownSec) {
        progressLastShownSec = secLeft;
      }
      etaText = formatEta(progressLastShownSec * 1000);
    }
    setProgressUi(pct, label, etaText);
  };

  const startProgress = (estimateMs) => {
    stopProgressTimer();
    progressStartedAt = Date.now();
    progressPhase = 'scan';
    // Lock once: trained estimate stays fixed for the whole scan + finish.
    progressEstimateMs = Math.max(3500, estimateMs);
    progressFinishMs = Math.max(2500, Math.round(progressEstimateMs * FINISH_FRAC));
    progressScanMs = Math.max(1000, progressEstimateMs - progressFinishMs);
    progressLastShownSec = Math.max(1, Math.ceil(progressEstimateMs / 1000));
    scanProgress.classList.remove('hidden');
    tickProgress();
    progressTimer = window.setInterval(tickProgress, 250);
  };

  const enterFinishPhase = () => {
    progressPhase = 'finish';
    // If the scan portion overran, keep the locked total — just relabel.
    tickProgress();
  };

  const finishProgress = (ok) => {
    stopProgressTimer();
    if (!ok) {
      scanProgress.classList.add('hidden');
      setProgressUi(0, 'Scanning…', '');
      return;
    }
    setProgressUi(100, 'Scan complete', 'Done');
    window.setTimeout(() => {
      scanProgress.classList.add('hidden');
      setProgressUi(0, 'Scanning…', '');
    }, 550);
  };

  const revokeBefore = () => {
    if (beforeUrl) {
      URL.revokeObjectURL(beforeUrl);
      beforeUrl = null;
    }
  };

  const revokeOverlay = () => {
    if (overlayUrl) {
      URL.revokeObjectURL(overlayUrl);
      overlayUrl = null;
    }
  };

  const clearResult = () => {
    revokeOverlay();
    closeLightbox();
    resultPanel.classList.add('hidden');
    resultSummary.textContent = '';
    afterImg.removeAttribute('src');
    mapLightboxImg.removeAttribute('src');
    downloadLink.href = '#';
    clearRoomHover();
    roomList.replaceChildren();
    roomHotspots.replaceChildren();
    mapLightboxHotspots.replaceChildren();
    lastRooms = [];
  };

  const setOverlayFromData = (data) => {
    revokeOverlay();
    const pngUrl = data.overlay_png_data_url || '';
    const dataUrl = data.overlay_data_url || '';
    const svgText = typeof data.overlay_svg === 'string' ? data.overlay_svg : '';
    if (pngUrl) {
      afterImg.src = pngUrl;
      downloadLink.href = dataUrl || pngUrl;
      return;
    }
    if (svgText) {
      const blob = new Blob([svgText], { type: 'image/svg+xml' });
      overlayUrl = URL.createObjectURL(blob);
      afterImg.src = overlayUrl;
      downloadLink.href = overlayUrl;
      return;
    }
    afterImg.src = dataUrl;
    downloadLink.href = dataUrl || '#';
  };

  const setFile = (file) => {
    if (!file) {
      return;
    }
    if (!file.type.startsWith('image/') && !/\.(png|jpe?g|webp|gif|svg)$/i.test(file.name || '')) {
      setStatus('Choose a floorplan (SVG, PNG, JPEG, or WebP).', true);
      return;
    }
    selectedFile = file;
    revokeBefore();
    clearResult();
    beforeUrl = URL.createObjectURL(file);
    beforeImg.src = beforeUrl;
    fileLabel.textContent = file.name || 'Floorplan ready';
    dropZone.classList.add('has-file');
    scanBtn.disabled = false;
    setStatus('Ready to scan');
  };

  const renderResult = (data, previewUrl) => {
    const rooms = data.rooms || [];
    const planW = Math.max(1, Number(data.width) || 1);
    const planH = Math.max(1, Number(data.height) || 1);
    lastRooms = rooms;
    lastPlanW = planW;
    lastPlanH = planH;
    resultSummary.textContent = `${data.room_count} room${data.room_count === 1 ? '' : 's'}, ${data.cubicle_count} cubicle${data.cubicle_count === 1 ? '' : 's'} · ${data.width}×${data.height}${data.ocr_label_count ? ` · ${data.ocr_label_count} labels read` : ''}`;
    beforeImg.src = previewUrl;
    setOverlayFromData(data);
    downloadLink.download = 'floor-scanner.svg';
    clearRoomHover();
    roomList.replaceChildren();
    roomHotspots.replaceChildren();
    mapLightboxHotspots.replaceChildren();
    for (const room of rooms) {
      const bbox = room.bbox || {};
      const li = document.createElement('li');
      li.className = 'room-card';
      li.dataset.type = room.type;
      li.dataset.roomId = String(room.id);
      li.tabIndex = 0;
      const typeLabel = room.type === 'cubicle' ? 'Cubicle' : 'Room';
      li.title = `${typeLabel}: ${room.label}`;
      li.setAttribute('aria-label', `${typeLabel} ${room.label}`);
      const title = document.createElement('strong');
      title.textContent = room.label;
      const meta = document.createElement('span');
      meta.textContent = `${room.area_pct}% · ${bbox.width}×${bbox.height}px`;
      li.append(title, meta);

      const activate = (hotspot) => showRoomHover(room, li, hotspot);
      li.addEventListener('mouseenter', () => activate(roomHotspots.querySelector(`[data-room-id="${room.id}"]`)));
      li.addEventListener('focus', () => activate(roomHotspots.querySelector(`[data-room-id="${room.id}"]`)));
      li.addEventListener('mouseleave', clearRoomHover);
      li.addEventListener('blur', clearRoomHover);

      roomList.append(li);
      roomHotspots.append(buildHotspot(room, planW, planH, activate));
      mapLightboxHotspots.append(buildHotspot(room, planW, planH, activate));
    }
    resultPanel.classList.remove('hidden');
  };

  const scan = async (file, { sample = false } = {}) => {
    scanBtn.disabled = true;
    sampleBtn.disabled = true;
    setStatus(sample ? 'Scanning sample…' : 'Scanning…');

    let sizeSrc = sample ? (cfg.sampleImage || '') : (beforeUrl || '');
    if (!sizeSrc && file) {
      sizeSrc = URL.createObjectURL(file);
    }
    const size = await probeFileWorkSize(file, sizeSrc || '');
    if (
      sizeSrc
      && sizeSrc !== beforeUrl
      && sizeSrc !== cfg.sampleImage
    ) {
      URL.revokeObjectURL(sizeSrc);
    }
    startProgress(estimateScanMs(size.width, size.height, file ? file.size : 0));

    const body = new FormData();
    if (sample && !file) {
      body.set('sample', '1');
    } else {
      body.set('floorplan', file, file.name || 'floorplan.png');
    }
    try {
      const res = await fetch(cfg.apiScan, { method: 'POST', body });
      enterFinishPhase();
      const raw = await res.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch {
        throw new Error(
          res.ok
            ? 'Scanner returned an invalid response (often a PHP memory error on huge plans).'
            : `Scan failed (HTTP ${res.status}).`
        );
      }
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Scan failed');
      }
      const preview = file && beforeUrl
        ? beforeUrl
        : (cfg.sampleImage || data.overlay_data_url);
      if (sample && preview) {
        beforeImg.src = preview;
      }
      renderResult(data, preview);
      applyTiming(data.timing);
      finishProgress(true);
      setStatus(`Found ${data.room_count + data.cubicle_count} spaces${data.ocr_label_count ? ` · ${data.ocr_label_count} map labels` : ''}`);
    } catch (err) {
      clearResult();
      finishProgress(false);
      setStatus(err instanceof Error ? err.message : 'Scan failed', true);
    } finally {
      scanBtn.disabled = !selectedFile;
      sampleBtn.disabled = false;
    }
  };

  dropZone.addEventListener('click', () => fileInput.click());
  browseBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    fileInput.click();
  });
  fileInput.addEventListener('change', () => {
    const file = fileInput.files && fileInput.files[0];
    if (file) {
      setFile(file);
    }
  });
  ;['dragenter', 'dragover'].forEach((type) => {
    dropZone.addEventListener(type, (e) => {
      e.preventDefault();
      dropZone.classList.add('dragover');
    });
  });
  ;['dragleave', 'drop'].forEach((type) => {
    dropZone.addEventListener(type, (e) => {
      e.preventDefault();
      dropZone.classList.remove('dragover');
    });
  });
  dropZone.addEventListener('drop', (e) => {
    const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (file) {
      setFile(file);
    }
  });
  dropZone.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      fileInput.click();
    }
  });
  scanBtn.addEventListener('click', () => {
    if (selectedFile) {
      scan(selectedFile);
    }
  });
  sampleBtn.addEventListener('click', () => scan(null, { sample: true }));

  overlayStage.addEventListener('click', (e) => {
    if (e.target.closest('.room-hotspot')) {
      return;
    }
    openLightbox();
  });
  mapLightboxClose.addEventListener('click', closeLightbox);
  mapLightbox.addEventListener('click', (e) => {
    if (e.target === mapLightbox) {
      closeLightbox();
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && lightboxOpen) {
      closeLightbox();
    }
  });
  mapLightboxStage.addEventListener('wheel', (e) => {
    if (!lightboxOpen) {
      return;
    }
    e.preventDefault();
    const factor = e.deltaY < 0 ? 1.12 : 1 / 1.12;
    lightboxScale = Math.min(8, Math.max(0.2, lightboxScale * factor));
    applyLightboxTransform();
  }, { passive: false });
  mapLightboxStage.addEventListener('pointerdown', (e) => {
    if (e.target.closest('.room-hotspot')) {
      return;
    }
    lightboxDragging = true;
    lightboxDragStart = { x: e.clientX, y: e.clientY, ox: lightboxX, oy: lightboxY };
    mapLightboxStage.classList.add('is-dragging');
    mapLightboxStage.setPointerCapture(e.pointerId);
  });
  mapLightboxStage.addEventListener('pointermove', (e) => {
    if (!lightboxDragging || !lightboxDragStart) {
      return;
    }
    lightboxX = lightboxDragStart.ox + (e.clientX - lightboxDragStart.x);
    lightboxY = lightboxDragStart.oy + (e.clientY - lightboxDragStart.y);
    applyLightboxTransform();
  });
  const endLightboxDrag = (e) => {
    if (!lightboxDragging) {
      return;
    }
    lightboxDragging = false;
    lightboxDragStart = null;
    mapLightboxStage.classList.remove('is-dragging');
    try {
      mapLightboxStage.releasePointerCapture(e.pointerId);
    } catch (_) {
      /* ignore */
    }
  };
  mapLightboxStage.addEventListener('pointerup', endLightboxDrag);
  mapLightboxStage.addEventListener('pointercancel', endLightboxDrag);
})();
