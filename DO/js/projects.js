(function () {
  const V = window.VASC;
  V.ready.then(() => {
  const stage = document.querySelector(".stage");
  if (!V.requireVasc(stage)) return;
  const vasc = V.role() === "vasc";
  const S = window.VASC_SCORE;
  const ST = window.VASC_STATUS;
  const H = window.VASC_HOURS;
  let hoursByTitle = new Map();
  const columns = [
    ["title", "Project", "text"],
    ["status", "Status", "text"],
    ["org", "Organization", "text"],
    ["stage", "Stage", "text"],
    ["level", "Level", "text"],
    ["score", "Score", "num"],
    ["priority", "Priority", "text"],
    ["category", "Category", "text"]
  ];
  if (vasc) {
    columns.push(["hoursNeeded", "Hours needed", "num"], ["hoursLeft", "Hours left", "num"]);
  }
  let sortKey = "score";
  let sortDir = -1;
  let query = new URLSearchParams(location.search).get("q") || "";
  // The standing agenda reviews deferred and declined projects together, so the
  // picker carries one preset for that room instead of making people run twice.
  const REVIEW = "Deferred and Declined";
  let statusPick = new URLSearchParams(location.search).get("status") || "";
  if (/^review$/i.test(statusPick)) statusPick = REVIEW;
  V.searchChart = (value) => {
    query = String(value || "");
    if (document.getElementById("project-table")) draw();
  };

  function levelRank(value) {
    const rank = parseInt(String(value || "").replace(/\D/g, ""), 10);
    return rank || 99;
  }

  function hours(value) {
    return value == null || value === "" ? "—" : String(value);
  }

  function hoursCell(value, row) {
    const tag = row.hoursSource === "Estimate" ? ` <em class="hours-src">est.</em>` : row.hoursSource === "Recorded" ? ` <em class="hours-src">list</em>` : "";
    const tip = row.hoursWhy ? ` title="${V.esc(row.hoursWhy)}"` : "";
    return `<td class="num"${tip}>${hours(value)}${value == null || value === "" ? "" : tag}</td>`;
  }

  function draw() {
    const level = document.getElementById("level-filter").value;
    const q = query.trim().toLowerCase();
    const reviewing = statusPick === REVIEW;
    const rows = V.cases.filter((row) => {
      if (level && row.level !== level) return false;
      if (reviewing) {
        if (!/^(deferred|declined)$/i.test(ST.label(ST.statusOf(row)))) return false;
      } else if (statusPick && ST.label(ST.statusOf(row)) !== statusPick) return false;
      if (!q) return true;
      const scored = S.scoreCase(row, S.edits[row.id]);
      const blob = [row.title, row.l2, row.l3, row.category, row.priority, row.level, S.stageLabel(S.stageOf(row)), scored.level, ST.label(ST.statusOf(row)), (ST.statusRow(row) || {}).rag || ""].join(" ").toLowerCase();
      return blob.includes(q);
    }).map((row) => {
      const scored = S.scoreCase(row, S.edits[row.id]);
      const hit = ST.statusRow(row);
      const plan = H ? H.forCase(row, hoursByTitle) : null;
      const round = (value) => Math.round((Number(value) || 0) * 10) / 10;
      return {
        id: row.id,
        title: row.title || "",
        status: ST.label(ST.statusOf(row)),
        statusHtml: ST.select(row, null, "status-select-row") + ST.dot(row),
        statusRank: ST.rank(ST.statusOf(row)),
        rag: hit && hit.rag ? hit.rag : "",
        org: [row.l2, row.l3].filter(Boolean).join(" · ") || "—",
        stage: S.stageLabel(S.stageOf(row)),
        level: scored.level || "",
        score: scored.score,
        formula: S.formulaText(scored),
        priority: row.priority || "",
        category: row.category || "",
        hoursNeeded: plan ? round(plan.needed) : row.hoursNeeded,
        hoursLeft: plan ? round(plan.left) : row.hoursLeft,
        hoursSource: plan ? plan.source : "",
        hoursWhy: plan ? plan.why : ""
      };
    });
    rows.sort((a, b) => {
      const left = a[sortKey];
      const right = b[sortKey];
      if (sortKey === "level") return (levelRank(left) - levelRank(right)) * sortDir || a.title.localeCompare(b.title);
      if (sortKey === "status") return (a.statusRank - b.statusRank) * sortDir || a.title.localeCompare(b.title);
      const empty = left == null || left === "";
      const otherEmpty = right == null || right === "";
      if (empty && otherEmpty) return a.title.localeCompare(b.title);
      if (empty) return 1;
      if (otherEmpty) return -1;
      if (typeof left === "number" && typeof right === "number") return (left - right) * sortDir;
      return String(left).localeCompare(String(right)) * sortDir;
    });
    const head = columns.map((col) => {
      const mark = col[0] === sortKey ? (sortDir < 0 ? " ↓" : " ↑") : "";
      const align = col[2] === "num" ? " num" : "";
      return `<th class="${align.trim()}"><button type="button" data-sort="${col[0]}" aria-sort="${col[0] === sortKey ? (sortDir < 0 ? "descending" : "ascending") : "none"}">${col[1]}${mark}</button></th>`;
    }).join("");
    const body = rows.map((row) => `<tr>
      <td><a href="case.html?id=${V.esc(row.id)}">${V.esc(row.title)}</a></td>
      <td>${row.statusHtml}</td>
      <td>${V.esc(row.org)}</td>
      <td>${V.esc(row.stage)}</td>
      <td>${V.esc(row.level || "—")}</td>
      <td class="num score-cell" tabindex="0" data-formula="${V.esc(row.formula).replace(/\n/g, "&#10;")}">${row.score}</td>
      <td>${V.esc(row.priority || "—")}</td>
      <td>${V.esc(row.category || "—")}</td>
      ${vasc ? hoursCell(row.hoursNeeded, row) + hoursCell(row.hoursLeft, row) : ""}
    </tr>`).join("");
    const table = document.getElementById("project-table");
    table.innerHTML = `<thead><tr>${head}</tr></thead><tbody>${body}</tbody>`;
    const count = document.getElementById("project-count");
    if (count) {
      const total = V.cases.length;
      const shown = rows.length;
      const scope = reviewing ? " deferred or declined" : statusPick ? " with status " + statusPick : "";
      count.textContent = shown === total
        ? total + " project" + (total === 1 ? "" : "s")
        : shown + " of " + total + " projects" + scope;
    }
    const strip = document.getElementById("status-strip");
    if (strip) {
      strip.innerHTML = ST.buckets().map((bucket) => `<button type="button" class="status-chip tone-${bucket.tone}" data-status="${V.esc(bucket.name)}"${bucket.name === statusPick ? ' aria-current="true"' : ""}><b>${bucket.count}</b><span>${V.esc(bucket.name)}</span></button>`).join("");
    }
    table.querySelectorAll("[data-sort]").forEach((button) => {
      button.addEventListener("click", () => {
        const key = button.dataset.sort;
        sortDir = key === sortKey ? -sortDir : (columns.find((col) => col[0] === key)[2] === "num" ? -1 : 1);
        sortKey = key;
        draw();
      });
    });
  }

  const levels = [...new Set(V.cases.map((row) => row.level).filter(Boolean))];
  const statusNames = ST.buckets().map((bucket) => bucket.name);
  const statusOptions = statusNames.length ? statusNames : ST.order;
  stage.innerHTML = `
    <nav class="pm-tabs" aria-label="Project management views"><a href="pm.html">Business management</a><a href="projects.html" aria-current="page">List and scoring</a></nav>
    <section class="lede"><h1>Projects</h1>
      <p>One row per use case. <strong>Status</strong> is the decision recorded on the Project Scores list: Deferred, Declined, Prioritized, and so on, with the RAG rating beside it. Score is the average of strategic value, reach, business value, and level. Open a project to change those. <a href="score.html">How scoring works</a></p>
      <p class="status-strip" id="status-strip"></p>
      <p class="note"><button type="button" class="linkish" id="review-preset">Review deferred and declined</button> — the list the standing agenda works through to reintroduce, keep deferring, or close.</p>
      <p class="note" id="status-updated"></p>
    </section>
    <div class="filters">
      <select id="status-filter" aria-label="Status"><option value="">All statuses</option><option value="${V.esc(REVIEW)}"${statusPick === REVIEW ? " selected" : ""}>${V.esc(REVIEW)}</option>${statusOptions.map((name) => `<option${name === statusPick ? " selected" : ""}>${V.esc(name)}</option>`).join("")}</select>
      <select id="level-filter" aria-label="Level"><option value="">All levels</option>${levels.map((level) => `<option>${V.esc(level)}</option>`).join("")}</select>
    </div>
    <p class="note" id="project-count"></p>
    <div class="table-wrap"><table class="score-table" id="project-table"></table></div>`;
  function applyStatus(next) {
    statusPick = next || "";
    const filter = document.getElementById("status-filter");
    if (filter && filter.value !== statusPick) filter.value = statusPick;
    const url = new URL(location.href);
    if (statusPick === REVIEW) url.searchParams.set("status", "review");
    else if (statusPick) url.searchParams.set("status", statusPick);
    else url.searchParams.delete("status");
    history.replaceState(null, "", url.pathname + url.search);
    draw();
  }
  document.getElementById("level-filter").addEventListener("change", draw);
  document.getElementById("status-filter").addEventListener("change", (event) => {
    applyStatus(event.target.value);
  });
  document.getElementById("status-strip").addEventListener("click", (event) => {
    const button = event.target.closest("[data-status]");
    if (!button) return;
    const name = button.getAttribute("data-status");
    applyStatus(name === statusPick ? "" : name);
  });
  document.getElementById("review-preset").addEventListener("click", () => {
    applyStatus(statusPick === REVIEW ? "" : REVIEW);
  });
  document.getElementById("project-table").addEventListener("change", (event) => {
    const picker = event.target.closest("select.status-select");
    if (!picker) return;
    const id = picker.getAttribute("data-status-id");
    const want = picker.value;
    picker.disabled = true;
    picker.className = "status-select status-select-row tone-" + ST.tone(want);
    ST.save(id, { projectStatus: want }).then(() => {
      picker.disabled = false;
      draw();
    }).catch((error) => {
      picker.disabled = false;
      picker.className = "status-select status-select-row tone-" + ST.tone(ST.statusOf({ id: id }));
      if (error.login && V.promptLogin) V.promptLogin();
    });
  });
  const projectTable = document.getElementById("project-table");
  function scoreCell(target) {
    return target && target.closest ? target.closest("td[data-formula]") : null;
  }
  function showScore(event) {
    const cell = scoreCell(event.target);
    if (!cell || !projectTable.contains(cell)) return;
    const box = cell.getBoundingClientRect();
    V.showPlainTip(cell.getAttribute("data-formula"), box.left, box.bottom);
  }
  function hideScore(event) {
    const cell = scoreCell(event.target);
    if (!cell) return;
    const next = event.relatedTarget;
    if (next && cell.contains(next)) return;
    V.hideAbbrTip();
  }
  projectTable.addEventListener("mouseover", showScore);
  projectTable.addEventListener("focusin", showScore);
  projectTable.addEventListener("mouseout", hideScore);
  projectTable.addEventListener("focusout", hideScore);
  const hoursReady = vasc && H
    ? V.devops("metrics").then((data) => { hoursByTitle = H.titleIndex(data.report || {}); }).catch(() => {})
    : Promise.resolve();
  Promise.all([S.load(), V.caseLinks(), ST.load(), hoursReady]).then(() => {
    const stamp = document.getElementById("status-updated");
    if (stamp) {
      stamp.textContent = ST.count()
        ? ST.count() + " use cases carry a status from the Project Scores list, last changed " + (ST.updated() || "unknown date") + "."
        : (ST.note() || "The Project Scores list has not been published to this site yet.");
    }
    draw();
  });
  });
})();
