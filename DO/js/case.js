(function () {
  const V = window.VASC;
  const S = window.VASC_SCORE;
  const stage = document.querySelector(".stage");
  const params = new URLSearchParams(location.search);
  const row = V.findCase(params.get("id"));
  if (!row) {
    stage.innerHTML = `<section class="panel"><h1>Project not found</h1></section>`;
    return;
  }
  const org = V.findOrg(row.orgId);
  const levels = ["L0", "L0.5", "L1", "L2", "L3", "L4", "L5"];

  function chartHref(id) {
    return "org.html?node=" + encodeURIComponent(id);
  }

  function field(label, value) {
    return `<dt>${V.linkAbbrs(label)}</dt><dd>${V.blank(value)}</dd>`;
  }

  /** Production date plus the six-month maintenance cycle from SOP 6.4.9. */
  function nextReview(implemented) {
    const stamp = Date.parse(String(implemented || ""));
    if (!isFinite(stamp)) return "";
    const due = new Date(stamp);
    due.setMonth(due.getMonth() + 6);
    return due.toISOString().slice(0, 10);
  }

  /**
   * The six features every project Epic carries under SOP-001 6.1.3, worked in
   * order, so they are set as one track: choosing a stage marks it and the ones
   * before it done. The marks are saved on this site; a linked work item that
   * names a stage shows its DevOps state under it.
   */
  function featureSteps() {
    const steps = ST ? ST.featuresOf(row) : [];
    const derived = {};
    if (S.featureProgress) {
      S.featureProgress(row, workItems).forEach((step) => {
        const hit = steps.find((item) => item.name === step.name);
        if (hit) derived[hit.id] = step;
      });
    }
    return steps.map((step) => {
      const fromDevops = derived[step.id];
      const state = fromDevops && fromDevops.started ? (fromDevops.done ? "Closed" : "Active") : "";
      return Object.assign({}, step, { devops: state ? { state: state } : undefined });
    });
  }

  function featureTrack() {
    const steps = featureSteps();
    if (!steps.length || !window.VASC_PIPELINE) return "";
    const canTick = V.role() === "vasc";
    return `<h2>Features</h2>
      <p class="note">SOP-001 gives every project Epic these six features, in this order. ${canTick
        ? "Choose a stage to mark it and the ones before it done; choose the last done stage again to step back. Saved on this site."
        : "Sign in to set the stage."}</p>
      <div id="feature-track">${window.VASC_PIPELINE.track(steps, { mode: canTick ? "edit" : "read", label: "SOP-001 features" })}</div>
      <p id="feature-note" class="note" aria-live="polite"></p>`;
  }

  /**
   * The linked work items, so the feature track knows which stage a task belongs
   * to. Read from the locally saved copy; nothing here needs to sign in.
   */
  let workItems = [];

  function loadWorkItems() {
    const ids = (row.workItems || []).filter(Boolean).slice(0, 50);
    if (!ids.length || V.role() !== "vasc") return Promise.resolve([]);
    return V.devops("workitems", { ids: ids.join(",") })
      .then((data) => {
        workItems = data.items || [];
        return workItems;
      })
      .catch(() => []);
  }

  const ST = window.VASC_STATUS;

  function fact(label, value) {
    return value ? `<span>${V.linkAbbrs(label)} <b>${V.esc(value)}</b></span>` : "";
  }

  /** Say what happened, without re-painting the page and losing the reason. */
  function statusNote(text) {
    const box = document.getElementById("status-note");
    if (box) box.textContent = text || "";
  }

  /** The banner keeps its colour while the dropdown changes. */
  function paintStatus(scope) {
    const hit = ST.statusRow(row);
    const shade = ST.tone(hit && hit.projectStatus);
    const banner = document.getElementById("status-banner");
    if (banner) {
      banner.className = "status-banner tone-" + shade;
    }
    if (scope) {
      scope.className = "status-select tone-" + shade;
    }
  }

  function bindStatus() {
    const picker = document.querySelector(".status-select[data-status-id]");
    if (!picker) return;
    picker.addEventListener("change", () => {
      const want = picker.value;
      picker.disabled = true;
      statusNote("Saving…");
      paintStatus(picker);
      ST.save(row.id, { projectStatus: want })
        .then(() => {
          picker.disabled = false;
          paintStatus(picker);
          statusNote(want ? "Status set to " + want + "." : "Status cleared.");
        })
        .catch((error) => {
          picker.disabled = false;
          statusNote(error.login ? "Sign in to change a status." : error.message);
          if (error.login) V.promptLogin();
        });
    });
  }

  function bindFeatures() {
    const box = document.getElementById("feature-track");
    const P = window.VASC_PIPELINE;
    if (!box || !P || V.role() !== "vasc") return;
    let saving = false;
    const paintTrack = () => P.keepFocus(box, () => {
      box.innerHTML = P.track(featureSteps(), { mode: "edit", label: "SOP-001 features" });
    });
    const pick = (index) => {
      if (saving) return;
      const line = document.getElementById("feature-note");
      if (!row.status) {
        if (line) line.textContent = "This use case is not on the Project Scores list yet, so its stages cannot be saved.";
        return;
      }
      const before = Object.assign({}, row.status.features || {});
      const ticks = P.ticksFor(featureSteps(), index);
      row.status.features = ticks;
      paintTrack();
      saving = true;
      if (line) line.textContent = "Saving…";
      ST.save(row.id, { features: ticks })
        .then(() => {
          saving = false;
          paintTrack();
          if (line) line.textContent = "Saved.";
        })
        .catch((error) => {
          saving = false;
          row.status.features = before;
          paintTrack();
          if (line) line.textContent = error.login ? "Sign in to set the stage." : error.message;
          if (error.login) V.promptLogin();
        });
    };
    P.bind(box, featureSteps, pick);
  }

  /**
   * The decision, first thing on the page. Colour and wording come from the
   * Project Scores list so a Deferred or Declined project cannot be mistaken for
   * an open one.
   */
  function statusBanner() {
    const hit = ST ? ST.statusRow(row) : null;
    if (!hit) {
      return `<div class="status-banner tone-unknown" id="status-banner">
        <span class="status-pill status-hero tone-unknown">Status not published</span>
        <p class="status-facts"><span>This use case is not on the Project Scores list yet.</span></p>
      </div>`;
    }
    const tone = ST.tone(hit.projectStatus);
    const score = [hit.weighted, hit.adjusted, hit.effort].filter(Boolean);
    const portfolio = hit.weighted || hit.adjusted
      ? `<span>Portfolio score <b>${V.esc(hit.adjusted || hit.weighted)}</b></span>`
      : "";
    const facts = [
      hit.recommendation ? fact("Recommendation", hit.recommendation) : "",
      portfolio,
      hit.effort ? fact("Effort", hit.effort) : "",
      hit.confidence ? fact("Confidence", hit.confidence) : "",
      hit.scoreStatus ? fact("Scoring", hit.scoreStatus) : "",
      hit.lastScored ? fact("Last scored", hit.lastScored) : "",
      hit.securityReview ? fact("Security/IM review", "Required") : "",
      hit.lead ? fact("OPI", hit.lead) : "",
      hit.dataLead ? fact("Data lead", hit.dataLead) : ""
    ].filter(Boolean).join("");
    const showsWhy = /deferred|declined|decommissioned/i.test(hit.projectStatus) || !!hit.roadblocks;
    const why = showsWhy && hit.roadblocks
      ? `<p class="status-why"><span>Roadblock</span> ${V.esc(hit.roadblocks)}</p>`
      : "";
    return `<div class="status-banner tone-${tone}" id="status-banner">
      ${ST.select(row, ST.statuses())}
      ${ST.dot(row)}
      <p class="status-facts">${facts || "<span>No recommendation or score recorded.</span>"}</p>
      ${why}
      <p id="status-note" class="note"></p>
    </div>`;
  }

  function paint(note) {
    const scored = S.scoreCase(row, S.edits[row.id]);
    const stageName = S.stageLabel(S.stageOf(row));
    const levelOptions = levels.map((level) => `<option${level === scored.level ? " selected" : ""}>${V.esc(level)}</option>`).join("");
    if (V.setTitle) V.setTitle((row.title || "Project") + " — VASC Pivot");
    const decision = ST ? ST.statusRow(row) : null;
    const opi = decision && decision.lead ? decision.lead : "";
    const dataLead = decision && decision.dataLead ? decision.dataLead : "";
    const roadblocks = decision && decision.roadblocks ? decision.roadblocks : "";
    const security = decision && decision.securityReview ? "Required" : "";
    const review = nextReview(row.implemented);
    const orgLine = org
      ? `<a href="${chartHref("org:" + org.id)}">${V.orgIcon(16)} ${V.esc(org.short)}</a>`
      : V.blank(row.l2);
    const workLink = row.devops
      ? `<p><a href="${V.esc(row.devops)}">${V.esc(row.devops)}</a></p>`
      : (V.role() === "vasc"
        ? `<p>No work item is linked on this use case yet. <button type="button" class="text-link" id="create-work">Create the work item</button></p>`
        : `<p>No work item is linked on this use case yet.</p>`);
    stage.innerHTML = `
      ${statusBanner()}
      <section class="lede">
        <p>#${V.esc(row.id)} · ${V.esc(stageName)}</p>
        <h1>${V.esc(row.title)}</h1>
        <p class="score-now">DO quick read ${scored.score}</p>
        <p id="score-break" class="score-formula">${V.esc(S.formulaText(scored))}</p>
        <p><a href="score.html">How scoring works</a></p>
      </section>
      <form class="panel score-form" id="score-form">
        <h2>Score</h2>
        <div class="score-field">
          <label><span>Strategic value</span><input name="strategic" type="number" min="1" max="5" step="1" value="${scored.strategic}"></label>
          ${savedTick()}
        </div>
        <p class="first-read">First reading: ${scored.first.strategic}. ${V.esc(scored.first.strategicWhy)}</p>
        <div class="score-field">
          <label><span>Strategic justification</span><textarea name="strategicWhy">${V.esc(S.edits[row.id] && S.edits[row.id].strategicWhy || "")}</textarea></label>
          ${savedTick()}
        </div>
        <div class="score-field">
          <label><span>Reach</span><input name="reach" type="number" min="1" max="5" step="1" value="${scored.reach}"></label>
          ${savedTick()}
        </div>
        <p class="first-read">First reading: ${scored.first.reach}. ${V.esc(scored.first.reachWhy)}</p>
        <p class="note">Number of users recorded on the use case: ${V.blank(row.users)}</p>
        <div class="score-field">
          <label><span>Reach justification</span><textarea name="reachWhy">${V.esc(S.edits[row.id] && S.edits[row.id].reachWhy || "")}</textarea></label>
          ${savedTick()}
        </div>
        <div class="score-field">
          <label><span>Business value</span><input name="business" type="number" min="1" max="5" step="1" value="${scored.business}"></label>
          ${savedTick()}
        </div>
        <p class="first-read">First reading: ${scored.first.business}. ${V.esc(scored.first.businessWhy)}</p>
        <div class="score-field">
          <label><span>Level</span><select name="level">${levelOptions}</select></label>
          ${savedTick()}
        </div>
        <p class="first-read">First reading: ${V.esc(scored.first.level || "—")}, read as ${scored.first.levelScore}.</p>
        <p class="actions"><button class="btn" type="button" id="score-reset">Use the first reading</button></p>
        <p id="score-note" class="note">${V.esc(note || "")}</p>
      </form>
      <section class="panel">
        <h2>Use case</h2>
        <dl class="kv">
          <dt>Organization</dt><dd>${orgLine}</dd>
          ${field("L3", row.l3)}
          ${field("Stakeholder", row.stakeholder)}
          ${field("Project lead / OPI", opi)}
          ${field("Data lead", dataLead)}
          ${field("Category", row.category)}
          ${field("Priority", row.priority)}
          ${field("Classification", row.classification)}
          ${field("Security/IM review", security)}
          ${field("Roadblocks", roadblocks)}
          ${field("Requested", row.requested)}
          ${field("Created", row.created)}
          ${field("Next review", review)}
        </dl>
        <h2>Overview</h2><p>${V.blank(row.overview)}</p>
        <h2>Business problem</h2><p>${V.blank(row.problem)}</p>
        <h2>Desired outcome</h2><p>${V.blank(row.outcome)}</p>
      </section>
      <section class="panel" id="work-panel">
        <h2>Work</h2>
        ${featureTrack()}
        <dl class="kv">
          ${field("Next step", row.nextStep)}
          ${field("Estimate", row.estimate)}
          ${window.VASC_HOURS ? (() => {
            const plan = window.VASC_HOURS.forCase(row, null);
            return field("Hours estimate", plan.needed + " h · " + plan.why);
          })() : ""}
          ${field("Financial implication", row.financial)}
          ${field("Time saving", row.timeSaving)}
          ${field("Datasets", row.datasets)}
          ${field("Implemented", row.implemented)}
        </dl>
        <h2>Intake notes</h2><p>${V.blank(row.notes)}</p>
        ${workLink}
        <p id="devops-state"></p>
        <p id="devops-hours"></p>
        <p id="devops-text"></p>
        ${row.workId ? `<button class="btn" id="sync" type="button">Sync</button>` : ""}
        <p id="sync-note" class="note"></p>
      </section>`;
    const form = document.getElementById("score-form");
    form.addEventListener("input", (event) => {
      if (event.target && event.target.name) markDirty(event.target);
    });
    form.addEventListener("change", (event) => {
      if (event.target && event.target.tagName === "SELECT") markDirty(event.target);
    });
    document.getElementById("score-reset").addEventListener("click", () => {
      S.save(row.id, {
        strategic: "",
        reach: "",
        business: "",
        level: "",
        strategicWhy: "",
        reachWhy: ""
      }).then(() => paint("Back to the first reading.")).catch((error) => {
        paint(error.login ? "Saved on this browser. Sign in to keep it for everyone." : error.message);
      });
    });
    const createLink = document.getElementById("create-work");
    if (createLink) createLink.addEventListener("click", createWork);
    bindStatus();
    bindFeatures();
    const syncButton = document.getElementById("sync");
    if (syncButton) {
      syncButton.addEventListener("click", () => sync(true));
      if (V.role() === "vasc") sync(false);
      else {
        const syncNote = document.getElementById("sync-note");
        if (syncNote) syncNote.textContent = "Sign in to read the linked work item.";
      }
    }
  }

  function savedTick() {
    return `<p class="save-tick" hidden><svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M6.1 10.9 3.2 8 2.1 9.1l4 4L14 5.2 12.9 4.1z"/></svg><span>Saved</span></p>`;
  }

  let saveTimer = 0;
  let saveGen = 0;

  function readForm() {
    const data = new FormData(document.getElementById("score-form"));
    return {
      strategic: data.get("strategic"),
      reach: data.get("reach"),
      business: data.get("business"),
      level: data.get("level"),
      strategicWhy: String(data.get("strategicWhy") || "").trim(),
      reachWhy: String(data.get("reachWhy") || "").trim()
    };
  }

  function previewPatch(raw) {
    const patch = {};
    ["strategic", "reach", "business"].forEach((key) => {
      const n = Number(raw[key]);
      if (raw[key] !== "" && raw[key] != null && n >= 1 && n <= 5) patch[key] = n;
    });
    if (raw.level) patch.level = raw.level;
    if (raw.strategicWhy) patch.strategicWhy = raw.strategicWhy;
    if (raw.reachWhy) patch.reachWhy = raw.reachWhy;
    return patch;
  }

  function savePatch(raw) {
    const patch = {
      strategic: raw.strategic,
      reach: raw.reach,
      business: raw.business,
      level: raw.level,
      strategicWhy: raw.strategicWhy,
      reachWhy: raw.reachWhy
    };
    const first = S.scoreCase(row, {}).first;
    if (Number(patch.strategic) === first.strategic) patch.strategic = "";
    if (Number(patch.reach) === first.reach) patch.reach = "";
    if (Number(patch.business) === first.business) patch.business = "";
    if (patch.level === first.level) patch.level = "";
    return patch;
  }

  function showScore(message) {
    const scored = S.scoreCase(row, previewPatch(readForm()));
    const now = document.querySelector(".score-now");
    if (now) now.textContent = "Score " + scored.score;
    const detail = document.getElementById("score-break");
    if (detail) detail.textContent = S.formulaText(scored);
    const note = document.getElementById("score-note");
    if (note && message != null) note.textContent = message;
    return scored;
  }

  function markDirty(field) {
    const tick = field.closest(".score-field") && field.closest(".score-field").querySelector(".save-tick");
    if (tick) tick.hidden = true;
    const note = document.getElementById("score-note");
    if (note) note.textContent = "";
    showScore(null);
    clearTimeout(saveTimer);
    const wait = field.tagName === "TEXTAREA" ? 450 : 200;
    saveTimer = setTimeout(() => store(field), wait);
  }

  function store(field) {
    const gen = ++saveGen;
    const patch = savePatch(readForm());
    S.save(row.id, patch).then(() => {
      if (gen !== saveGen) return;
      showScore("");
      const tick = field && field.closest(".score-field") && field.closest(".score-field").querySelector(".save-tick");
      if (tick) tick.hidden = false;
    }).catch((error) => {
      if (gen !== saveGen) return;
      showScore(error.login ? "Saved on this browser. Sign in to keep it for everyone." : error.message);
    });
  }

  let creating = false;

  function workDescription() {
    const parts = [];
    if (row.overview) parts.push(row.overview);
    if (row.problem) parts.push(row.problem);
    if (row.outcome) parts.push(row.outcome);
    if (row.stakeholder) parts.push("Stakeholder: " + row.stakeholder);
    if (row.nextStep) parts.push("Next step: " + row.nextStep);
    return parts.join("\n\n");
  }

  function createWork() {
    if (creating || row.workId) return;
    creating = true;
    const note = document.getElementById("sync-note");
    if (note) note.textContent = "Checking Azure DevOps write access…";
    V.devops("access").then((data) => {
      if (!data.canWrite) {
        creating = false;
        V.promptWrite();
        return null;
      }
      if (note) note.textContent = "Creating the work item…";
      return V.devops("create", null, {
        title: row.title,
        type: "User Story",
        description: workDescription(),
        case: row.id,
        parent: row.orgId ? "org:" + row.orgId : ""
      });
    }).then((data) => {
      if (!data) return;
      const item = data.item || {};
      if (item.id) {
        row.workId = String(item.id);
        row.devops = item.url || "";
        row.workItems = [Number(item.id)];
      }
      creating = false;
      paint("");
    }).catch((error) => {
      creating = false;
      const current = document.getElementById("sync-note");
      if (current) current.textContent = error.message;
      if (error.login) V.promptWrite();
    });
  }

  function sync(fresh) {
    const note = document.getElementById("sync-note");
    if (note) note.textContent = fresh ? "Reading this work item from Azure DevOps…" : "Opening what was saved…";
    V.devops("workitem", { id: String(row.workId), fresh: fresh ? "1" : "0" }).then((data) => {
      const item = data.item || {};
      const state = document.getElementById("devops-state");
      const hours = document.getElementById("devops-hours");
      const text = document.getElementById("devops-text");
      if (state) state.textContent = (item.type || "Work item") + " · " + (item.state || "—");
      if (hours) {
        hours.textContent = "Hours needed " + (item.hoursNeeded == null ? "—" : item.hoursNeeded)
          + " · left " + (item.hoursLeft == null ? "—" : item.hoursLeft)
          + " · used " + (item.hoursUsed == null ? "—" : item.hoursUsed);
      }
      if (text) text.textContent = item.description || item.title || "";
      if (note) note.textContent = data.cached ? "From what was saved today." : "Read from Azure DevOps.";
    }).catch((error) => {
      if (note) note.textContent = error.message;
      if (error.login) V.promptLogin(() => sync(true));
    });
  }

  V.ready.then(() => Promise.all([S.load(), V.caseLinks(), ST.load()])
    .then(() => loadWorkItems())
    .then(() => paint("")));
})();
