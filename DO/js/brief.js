/**
 * DPP Executive Overview: the one-page leadership brief.
 *
 * Every number comes from the Project Scores list, with the status changes made
 * in Pivot on top (js/status.js), and the dated decisions from api/decisions.php.
 * Charts are WET-BOEW wb-charts tables, so each chart is also its data table.
 * Filters live in the address (?period=&l2=), so a filtered view can be shared.
 */
(function () {
  const V = window.VASC;

  // Leadership asked for a brief without money or hours. This one switch brings
  // back the committed-hours indicator and columns, read from DevOps for readers
  // signed in to Azure DevOps.
  const SHOW_HOURS = false;

  const PERIODS = [
    ["fy", "This fiscal year"],
    ["lastfy", "Last fiscal year"],
    ["90", "Last 90 days"],
    ["180", "Last 6 months"],
    ["365", "Last 12 months"],
    ["all", "All dates"]
  ];
  const DEFAULT_PERIOD = "365";
  const NO_L2 = "none";
  const TURNED_AWAY = /^(deferred|declined)$/i;
  const SOURCE_WORDS = {
    scoring: "scoring recommendation",
    list: "Project Scores list, dated by the scoring meeting",
    usecase: "never scored; dated by the use case's last edit",
    pivot: "status changed in Pivot",
    rescored: "scored again",
    observed: "first seen in a new export of the list"
  };

  function key(value) {
    return String(value || "").toLowerCase().replace(/[^a-z0-9]+/g, " ").trim();
  }

  function compact(value) {
    return String(value || "").toUpperCase().replace(/[^A-Z0-9]+/g, "");
  }

  function levelNumber(org) {
    const hit = /^L(\d+)$/.exec((org && org.level) || "");
    return hit ? Number(hit[1]) : 99;
  }

  function isoDay(date) {
    const pad = (n) => String(n).padStart(2, "0");
    return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate());
  }

  function longDay(day) {
    if (!day) return "—";
    const date = new Date(day.slice(0, 10) + "T12:00:00");
    if (isNaN(date)) return day;
    return date.toLocaleDateString("en-CA", { year: "numeric", month: "short", day: "numeric" });
  }

  function hoursText(value) {
    if (value == null) return "—";
    return (Math.round(Number(value) * 10) / 10).toLocaleString("en-CA");
  }

  /** The federal fiscal year starts on April 1. */
  function periodRange(period, today) {
    const end = isoDay(today);
    const fyStart = new Date(today.getMonth() >= 3 ? today.getFullYear() : today.getFullYear() - 1, 3, 1);
    if (period === "all") return { from: "", to: "", label: "All dates" };
    if (period === "fy") {
      return { from: isoDay(fyStart), to: end, label: "Fiscal year " + fyStart.getFullYear() + "–" + String(fyStart.getFullYear() + 1).slice(2) + " to date" };
    }
    if (period === "lastfy") {
      const start = new Date(fyStart.getFullYear() - 1, 3, 1);
      const stop = new Date(fyStart.getFullYear(), 2, 31);
      return { from: isoDay(start), to: isoDay(stop), label: "Fiscal year " + start.getFullYear() + "–" + String(fyStart.getFullYear()).slice(2) };
    }
    const days = Number(period) || 365;
    const start = new Date(today);
    start.setDate(start.getDate() - days + 1);
    return { from: isoDay(start), to: end, label: (PERIODS.find((row) => row[0] === period) || ["", days + " days"])[1] };
  }

  function inRange(day, range) {
    if (!day) return false;
    return (!range.from || day >= range.from) && (!range.to || day <= range.to);
  }

  /** The L2 an org sits under. An L0 or L1 org stands for itself. */
  function l2Above(org) {
    let at = org;
    const seen = new Set();
    while (at && levelNumber(at) > 2 && at.parent && !seen.has(at.name)) {
      seen.add(at.name);
      at = V.byName[at.parent] || null;
    }
    return at || org;
  }

  const l2Shorts = (V.orgs || [])
    .filter((org) => (org.level === "L2" || org.level === "L3") && compact(org.short).length >= 3)
    .map((org) => ({ org: org, word: " " + key(org.short) + " " }))
    .sort((a, b) => b.word.length - a.word.length);

  /**
   * Most use cases leave the L2 blank. In order: the org picked on the use case,
   * the L2 typed on it, then an L2 or L3 short name written as a whole word in
   * the title, such as "CDLS(W) Member Self-Service Portal".
   */
  function l2For(row) {
    const picked = V.findOrg(row.orgId);
    if (picked) return orgLabel(l2Above(picked), "the org on the use case");
    const typed = String(row.l2 || "").trim();
    if (typed) {
      const match = (V.orgs || []).find((org) => compact(org.short) === compact(typed));
      return match ? orgLabel(l2Above(match), "the L2 on the use case") : { key: "text:" + typed.toLowerCase(), name: typed, why: "the L2 on the use case" };
    }
    const title = " " + key(row.title) + " ";
    const hit = l2Shorts.find((entry) => title.includes(entry.word));
    if (hit) return orgLabel(l2Above(hit.org), "its title");
    return { key: NO_L2, name: "", why: "" };
  }

  function orgLabel(org, why) {
    return { key: "org:" + org.id, name: org.short, why: why };
  }

  function titleMatch(a, b) {
    const x = key(a);
    const y = key(b);
    if (!x || !y) return false;
    if (x === y) return true;
    const short = x.length < y.length ? x : y;
    const long = short === x ? y : x;
    return short.length >= 6 && (" " + long + " ").includes(" " + short + " ");
  }

  V.ready.then(() => {
    const stage = document.querySelector(".stage");
    const ST = window.VASC_STATUS;
    const params = new URLSearchParams(location.search);
    const state = {
      period: PERIODS.some((row) => row[0] === params.get("period")) ? params.get("period") : DEFAULT_PERIOD,
      l2: params.get("l2") || "",
      query: ""
    };
    const data = { projects: [], decisions: {}, board: null, boardSaved: 0, hours: null };

    function buildProjects() {
      const byId = new Map();
      (data.decisions.events || []).forEach((event) => {
        if (!byId.has(event.id)) byId.set(event.id, []);
        byId.get(event.id).push(event);
      });
      data.projects = (V.cases || []).map((row) => {
        const status = ST.statusRow(row) || {};
        return {
          id: String(row.id),
          title: row.title || "Untitled",
          status: ST.label(ST.statusOf(row)),
          recommendation: String(status.recommendation || ""),
          l2: l2For(row),
          events: byId.get(String(row.id)) || []
        };
      });
    }

    function visible(project) {
      if (state.l2 && project.l2.key !== state.l2) return false;
      const q = state.query.trim().toLowerCase();
      return !q || (project.title + " " + project.l2.name).toLowerCase().includes(q);
    }

    function committed(title) {
      if (!SHOW_HOURS || !data.hours) return null;
      const hit = data.hours.find((row) => titleMatch(row.title, title));
      return hit ? Number(hit.needed) || 0 : null;
    }

    function l2Cell(project) {
      if (!project.l2.name) return `<span class="brief-muted" title="No L2 recorded on the use case">—</span>`;
      const title = project.l2.why ? ` title="L2 from ${V.esc(project.l2.why)}"` : "";
      return `<span${title}>${V.esc(project.l2.name)}</span>`;
    }

    function l2Key(rows) {
      return rows.some((row) => !row.project.l2.name) ? `<p class="note">— means no L2 is recorded on the use case.</p>` : "";
    }

    function projectLink(project) {
      return `<a href="case.html?id=${encodeURIComponent(project.id)}">${V.esc(project.title)}</a>`;
    }

    function lastEvent(project, test) {
      const hits = project.events.filter((event) => test.test(event.outcome));
      return hits.length ? hits[hits.length - 1] : null;
    }

    function activeRows(list) {
      return list
        .filter((project) => /^(in dev|uat)$/i.test(project.status))
        .map((project) => ({ project: project, stage: /^uat$/i.test(project.status) ? "UAT" : "Development", hours: committed(project.title) }))
        .sort((a, b) => a.stage.localeCompare(b.stage) || a.project.title.localeCompare(b.project.title));
    }

    /** Prioritized now, or recommended for approval and not yet given a status. */
    function approvedRows(list, range) {
      const rows = [];
      list.forEach((project) => {
        let decision = null;
        let word = "";
        if (/^prioritized$/i.test(project.status)) {
          decision = lastEvent(project, /^prioritized$/i);
          word = "Prioritized";
        } else if (/^(new|)$/i.test(project.status)) {
          decision = lastEvent(project, /^approved$/i);
          word = "Approved";
        }
        if (!word) return;
        const day = decision ? decision.date : "";
        if (range.from || range.to) {
          if (!inRange(day, range)) return;
        }
        rows.push({ project: project, word: word, day: day, source: decision ? decision.source : "", hours: committed(project.title) });
      });
      return rows.sort((a, b) => (b.day || "").localeCompare(a.day || "") || a.project.title.localeCompare(b.project.title));
    }

    /** Deferred now, or new with a recommendation to defer (amber). */
    function inBacklog(project) {
      if (/^deferred$/i.test(project.status)) return true;
      return /^(new|)$/i.test(project.status) && /^defer$/i.test(project.recommendation);
    }

    /**
     * Each deferral or decline in the period, marked as a repeat when the same
     * project was already deferred or declined on an earlier date.
     */
    function turnedAway(list, range) {
      const rows = [];
      list.forEach((project) => {
        let before = 0;
        project.events.forEach((event) => {
          if (!TURNED_AWAY.test(event.outcome)) return;
          if (inRange(event.date, range)) {
            rows.push({ project: project, event: event, repeat: before > 0 });
          }
          before += 1;
        });
      });
      return rows;
    }

    function countBy(rows, pick) {
      const counts = new Map();
      rows.forEach((row) => {
        const name = pick(row);
        counts.set(name, (counts.get(name) || 0) + 1);
      });
      return counts;
    }

    function chartOrder(counts) {
      return [...counts.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]));
    }

    // HTML tick labels (canvas: false) take the page's CSS, so long L2 names can
    // shrink and wrap instead of overlapping.
    const FLOT_BASE = {
      canvas: false,
      grid: { color: "#e4f1fb", borderColor: "rgba(168, 214, 255, 0.35)", borderWidth: 1, tickColor: "rgba(168, 214, 255, 0.14)" },
      yaxis: { min: 0, tickDecimals: 0, minTickSize: 1 }
    };

    function flotAttr(extra) {
      return V.esc(JSON.stringify(Object.assign({}, FLOT_BASE, extra || {})));
    }

    // orderBars expects left-aligned bars and centres them itself; WET's bar
    // preset already centres them, so each series sets align back to left.
    function seriesAttr(extra) {
      return V.esc(JSON.stringify(Object.assign({ bars: { show: true, barWidth: 0.6, align: "left", fill: 0.85, lineWidth: 0 } }, extra || {})));
    }

    function backlogChart(rows) {
      const named = rows.filter((project) => project.l2.name);
      const unnamed = rows.length - named.length;
      const counts = chartOrder(countBy(named, (project) => project.l2.name));
      const note = unnamed
        ? `<p class="note">${unnamed} more deferred ${unnamed === 1 ? "project has" : "projects have"} no L2 recorded on the use case, so ${unnamed === 1 ? "it is" : "they are"} counted in the headline but not in the chart.</p>`
        : "";
      if (state.l2 === NO_L2) {
        return `<p>${rows.length} deferred ${rows.length === 1 ? "project has" : "projects have"} no L2 recorded, so there is nothing to chart by L2.</p>`;
      }
      if (!counts.length) {
        return `<p>No deferred project${state.l2 ? " for this L2" : " has an L2 recorded"}.</p>${note}`;
      }
      const head = counts.map(([name]) => `<th scope="col">${V.esc(name)}</th>`).join("");
      const cells = counts.map(([, count]) => `<td>${count}</td>`).join("");
      return `<table class="wb-charts wb-charts-bar brief-chart" data-wb-charts='{"height":240}' data-flot="${flotAttr({ colors: ["#e4c56a"] })}">
          <caption>Deferred projects by L2</caption>
          <thead><tr><td></td>${head}</tr></thead>
          <tbody><tr><th scope="row" data-flot="${seriesAttr()}">Deferred projects</th>${cells}</tr></tbody>
        </table>${note}`;
    }

    function repeatChart(rows) {
      const named = rows.filter((row) => row.project.l2.name);
      const unnamed = rows.length - named.length;
      const totals = chartOrder(countBy(named, (row) => row.project.l2.name));
      const first = countBy(named.filter((row) => !row.repeat), (row) => row.project.l2.name);
      const again = countBy(named.filter((row) => row.repeat), (row) => row.project.l2.name);
      const note = unnamed
        ? `<p class="note">${unnamed} more ${unnamed === 1 ? "decision is" : "decisions are"} on projects with no L2 recorded.</p>`
        : "";
      if (state.l2 === NO_L2) {
        const repeats = rows.filter((row) => row.repeat).length;
        return `<p>${rows.length} ${rows.length === 1 ? "deferral or decline" : "deferrals and declines"} in this period, ${repeats} of them repeat, on projects with no L2 recorded, so there is nothing to chart by L2.</p>`;
      }
      if (!totals.length) {
        return `<p>No deferral or decline${state.l2 ? " for this L2" : " with an L2 recorded"} falls in this period.</p>${note}`;
      }
      // Both rows share one bar position (same order) and stack. WET halves
      // barWidth for two series, so 1.2 draws bars 0.6 wide.
      const series = seriesAttr({ stack: true, bars: { show: true, barWidth: 1.2, order: 1, align: "left", fill: 0.85, lineWidth: 0 } });
      const head = totals.map(([name]) => `<th scope="col">${V.esc(name)}</th>`).join("");
      const row = (counts) => totals.map(([name]) => `<td>${counts.get(name) || 0}</td>`).join("");
      return `<table class="wb-charts wb-charts-bar wb-charts-stacked brief-chart" data-wb-charts='{"height":240}' data-flot="${flotAttr({ colors: ["#8fd4ff", "#e15d5d"] })}">
          <caption>Deferrals and declines by L2, first time and repeat</caption>
          <thead><tr><td></td>${head}</tr></thead>
          <tbody>
            <tr><th scope="row" data-flot="${series}">First time</th>${row(first)}</tr>
            <tr><th scope="row" data-flot="${series}">Repeat (same project again)</th>${row(again)}</tr>
          </tbody>
        </table>${note}`;
    }

    function repeatList(rows) {
      const ids = new Set(rows.filter((row) => row.repeat).map((row) => row.project.id));
      if (!ids.size) return "";
      const items = data.projects.filter((project) => ids.has(project.id)).map((project) => {
        const steps = project.events
          .filter((event) => TURNED_AWAY.test(event.outcome))
          .map((event) => `${V.esc(event.outcome.toLowerCase())} ${V.esc(longDay(event.date))}`)
          .join(", ");
        return `<li>${projectLink(project)} <span class="brief-muted">${l2Cell(project)} · ${steps}</span></li>`;
      }).join("");
      return `<h3>Projects turned away more than once</h3><ul class="brief-repeats">${items}</ul>`;
    }

    /** Only on the unfiltered view: it is about the data, not about one L2. */
    function boardCheck(list) {
      if (!data.board || state.l2 || state.query.trim()) return "";
      const missing = [];
      const differs = [];
      data.board.forEach((item) => {
        const stage = /uat/i.test(item.state) ? "UAT" : "Development";
        const match = list.find((project) => titleMatch(project.title, item.title));
        if (!match) {
          missing.push(`<a href="${V.esc(item.url || "#")}">${V.esc(item.title)}</a> (${stage})`);
          return;
        }
        const listed = /^uat$/i.test(match.status) ? "UAT" : (/^in dev$/i.test(match.status) ? "Development" : match.status || "no status");
        if (listed !== stage) differs.push(`${projectLink(match)} (list: ${V.esc(listed)}, board: ${stage})`);
      });
      if (!missing.length && !differs.length) {
        return `<p class="note">The DevOps board agrees with the status list.</p>`;
      }
      return `<details class="brief-board"><summary>DevOps board check: ${missing.length + differs.length} to reconcile</summary>
          ${missing.length ? `<p>On the board in development or UAT, with no matching use case: ${missing.join(", ")}.</p>` : ""}
          ${differs.length ? `<p>Board stage differs from the status list: ${differs.join(", ")}.</p>` : ""}
          <p class="note">The brief counts the status list. Update the list or the board so they agree.</p>
        </details>`;
    }

    function filterForm() {
      const periodOptions = PERIODS.map(([value, label]) => `<option value="${value}"${value === state.period ? " selected" : ""}>${V.esc(label)}</option>`).join("");
      const names = new Map();
      let none = 0;
      data.projects.forEach((project) => {
        if (project.l2.name) names.set(project.l2.key, project.l2.name);
        else none += 1;
      });
      const l2Options = [...names.entries()]
        .sort((a, b) => a[1].localeCompare(b[1]))
        .map(([value, label]) => `<option value="${V.esc(value)}"${value === state.l2 ? " selected" : ""}>${V.esc(label)}</option>`)
        .join("");
      return `<form class="brief-filters" aria-label="Filters" onsubmit="return false">
          <label>Reporting period <select id="brief-period">${periodOptions}</select></label>
          <label>L2 <select id="brief-l2"><option value="">All L2s</option>${l2Options}${none ? `<option value="${NO_L2}"${state.l2 === NO_L2 ? " selected" : ""}>No L2 recorded</option>` : ""}</select></label>
        </form>`;
    }

    function footer() {
      const d = data.decisions;
      const stamps = [];
      if (d.edited) stamps.push(d.edited.slice(0, 10));
      if (ST.updated()) stamps.push(ST.updated());
      if (data.boardSaved) stamps.push(isoDay(new Date(data.boardSaved * 1000)));
      const latest = stamps.sort().pop() || "";
      const parts = [];
      if (ST.updated()) parts.push(`Project Scores list rows edited up to ${V.esc(longDay(ST.updated()))}`);
      if (d.edited) parts.push(`last status change in Pivot ${V.esc(longDay(d.edited))}`);
      if (data.boardSaved) parts.push(`DevOps cache ${V.esc(new Date(data.boardSaved * 1000).toLocaleString("en-CA", { dateStyle: "medium", timeStyle: "short" }))}`);
      if (d.logStarted) parts.push(`decision log kept since ${V.esc(longDay(d.logStarted))}`);
      return `<footer class="brief-footer"><b>Last updated ${V.esc(longDay(latest))}</b>${parts.length ? " · " + parts.join(" · ") : ""}</footer>`;
    }

    function draw() {
      const range = periodRange(state.period, new Date());
      const list = data.projects.filter(visible);
      const active = activeRows(list);
      const approved = approvedRows(list, range);
      const backlog = list.filter(inBacklog);
      const away = turnedAway(list, range);
      const devCount = active.filter((row) => row.stage === "Development").length;
      const uatCount = active.length - devCount;
      const l2Name = state.l2 ? (state.l2 === NO_L2 ? "No L2 recorded" : ((data.projects.find((p) => p.l2.key === state.l2) || { l2: { name: state.l2 } }).l2.name)) : "All L2s";
      const hoursCol = SHOW_HOURS ? `<th scope="col" class="num">Committed hours</th>` : "";
      const hoursCell = (value) => SHOW_HOURS ? `<td class="num">${hoursText(value)}</td>` : "";
      const totalHours = SHOW_HOURS
        ? active.concat(approved).reduce((sum, row) => sum + (row.hours || 0), 0)
        : 0;
      const hoursNote = SHOW_HOURS && !data.hours
        ? `<p class="note">Committed hours come from Azure DevOps. <a href="connect.html?return=brief.html">Sign in</a> to show them.</p>`
        : "";

      const activeTable = active.length
        ? `<table class="brief-table"><thead><tr><th scope="col">Project</th><th scope="col">L2</th><th scope="col">Development or UAT</th>${hoursCol}</tr></thead><tbody>
            ${active.map((row) => `<tr><td>${projectLink(row.project)}</td><td>${l2Cell(row.project)}</td><td><span class="status-pill tone-${row.stage === "UAT" ? "build" : "work"}">${row.stage}</span></td>${hoursCell(row.hours)}</tr>`).join("")}
          </tbody></table>`
        : `<p>No project is in development or UAT${state.l2 ? " for this L2" : ""}.</p>`;

      const approvedTable = approved.length
        ? `<table class="brief-table"><thead><tr><th scope="col">Project</th><th scope="col">L2</th><th scope="col">Prioritized or approved</th><th scope="col">Decision date</th>${hoursCol}</tr></thead><tbody>
            ${approved.map((row) => `<tr><td>${projectLink(row.project)}</td><td>${l2Cell(row.project)}</td><td><span class="status-pill tone-go">${row.word}</span></td><td class="brief-day" title="${V.esc(SOURCE_WORDS[row.source] || "")}">${V.esc(longDay(row.day))}</td>${hoursCell(row.hours)}</tr>`).join("")}
          </tbody></table>`
        : `<p>No project was prioritized or approved in this period${state.l2 ? " for this L2" : ""}.</p>`;

      const logNote = data.decisions.logStarted
        ? `Pivot logs each decision change from ${V.esc(longDay(data.decisions.logStarted))}. Before that, the Project Scores list keeps one decision per project, so earlier repeats do not show.`
        : "The decision log could not be read, so only the Project Scores list's one decision per project is shown.";

      stage.innerHTML = `
        <article class="brief-page">
          <header class="brief-head">
            <div class="lede">
              <h1>DPP Executive Overview</h1>
              <p>${V.esc(range.label)} · ${V.esc(l2Name)}${state.query ? ` · matching “${V.esc(state.query)}”` : ""}</p>
            </div>
            ${filterForm()}
          </header>
          <section class="stats brief-stats${SHOW_HOURS ? "" : " brief-stats-3"}" aria-label="Summary indicators">
            <div class="stat"><b>${devCount}</b><span>Projects in active development</span></div>
            <div class="stat"><b>${uatCount}</b><span>Projects in active UAT</span></div>
            <div class="stat"><b>${backlog.length}</b><span>Total amber/deferred backlog</span></div>
            ${SHOW_HOURS ? `<div class="stat"><b>${data.hours ? hoursText(totalHours) : "—"}</b><span>Total committed project hours</span></div>` : ""}
          </section>
          ${hoursNote}
          <div class="brief-grid">
            <section class="panel">
              <h2>Active development and UAT</h2>
              <div class="table-wrap">${activeTable}</div>
              ${l2Key(active)}
              ${boardCheck(list)}
            </section>
            <section class="panel">
              <h2>Newly prioritized or approved</h2>
              <div class="table-wrap">${approvedTable}</div>
              <p class="note">Most recent decision first. Approved means the scoring recommended approval and no status has been set yet.${approved.some((row) => !row.project.l2.name) ? " — means no L2 is recorded on the use case." : ""}</p>
            </section>
            <section class="panel">
              <h2>Amber / deferred backlog</h2>
              ${backlogChart(backlog)}
              <p class="note">Currently deferred, plus new requests the scoring recommended deferring (amber). Not limited by the reporting period.</p>
            </section>
            <section class="panel">
              <h2>Repeated deferrals and declines by L2</h2>
              ${repeatChart(away)}
              ${repeatList(away)}
              <p class="note">${logNote}</p>
            </section>
          </div>
          ${footer()}
        </article>`;

      document.getElementById("brief-period").addEventListener("change", (event) => {
        state.period = event.target.value;
        remember();
        draw();
      });
      document.getElementById("brief-l2").addEventListener("change", (event) => {
        state.l2 = event.target.value;
        remember();
        draw();
      });
      paintCharts();
    }

    function paintCharts() {
      const $ = window.jQuery;
      if (!$ || !window.wb) return;
      $(".brief-chart").trigger("wb-init.wb-charts");
    }

    function remember() {
      const next = new URLSearchParams(location.search);
      if (state.period === DEFAULT_PERIOD) next.delete("period");
      else next.set("period", state.period);
      if (state.l2) next.set("l2", state.l2);
      else next.delete("l2");
      const query = next.toString();
      history.replaceState(null, "", location.pathname + (query ? "?" + query : "") + location.hash);
    }

    function load() {
      const decisions = fetch("api/decisions.php", { cache: "no-store" })
        .then((res) => res.json())
        .catch(() => ({ events: [] }));
      const signedIn = V.role() === "vasc";
      const board = signedIn
        ? V.devops("tree").then((reply) => {
          data.boardSaved = reply.age != null ? Math.round(Date.now() / 1000) - reply.age : 0;
          data.board = (reply.items || []).filter((item) => /^(active|uat) projects$/i.test(item.type || "") && /^(active|uat)$/i.test(item.state || ""));
        }).catch(() => {})
        : Promise.resolve();
      const hours = SHOW_HOURS && signedIn
        ? V.devops("metrics").then((reply) => { data.hours = (reply.report && reply.report.allProjects) || []; }).catch(() => {})
        : Promise.resolve();
      return Promise.all([ST.load(), decisions, board, hours]).then((result) => {
        data.decisions = result[1] || { events: [] };
        buildProjects();
        draw();
      });
    }

    // wb-charts folds each chart's data table into a closed <details>.
    window.addEventListener("beforeprint", () => {
      document.querySelectorAll(".brief-grid details:not([open])").forEach((box) => {
        box.open = true;
        box.dataset.printOpened = "1";
      });
    });
    window.addEventListener("afterprint", () => {
      document.querySelectorAll(".brief-grid details[data-print-opened]").forEach((box) => {
        box.open = false;
        delete box.dataset.printOpened;
      });
    });

    V.searchChart = (value) => {
      state.query = String(value || "");
      if (data.projects.length) draw();
    };
    stage.innerHTML = `<section class="lede"><h1>DPP Executive Overview</h1><p>Reading project decisions…</p></section>`;
    load().catch(() => {
      stage.innerHTML = `<section class="lede"><h1>DPP Executive Overview</h1><p>The project decisions could not be read. Reload to try again.</p></section>`;
    });
  });
})();
