(function () {
  const V = window.VASC;
  V.ready.then(() => {
    const stage = document.querySelector(".stage");
    if (!V.requireVasc(stage)) return;

    function hours(value) {
      const rounded = Math.round((Number(value) || 0) * 100) / 100;
      return String(rounded);
    }

    function bars(rows) {
      const max = Math.max(...rows.map((row) => row.left), 1);
      return `<div class="bars metrics-bars">${rows.map((row) => {
        const label = row.id ? `<a href="pm.html?item=wit:${encodeURIComponent(row.id)}">${V.esc(row.title)}</a>` : V.esc(row.title);
        const open = row.url ? ` <a href="${V.esc(row.url)}" target="_blank" rel="noopener">Open</a>` : "";
        const count = row.count ? ` <em>(${row.count})</em>` : "";
        return `<div class="bar-row"><span title="${V.esc(row.title)}">${label}${open}${count}</span><div class="bar"><i style="width:${Math.round(row.left / max * 100)}%"></i></div><b>${hours(row.left)}</b></div>`;
      }).join("")}</div>`;
    }

    /**
     * Two SOP rules that are easy to break and cheap to see: UAT should run at
     * least two weeks (6.4.6), and time is logged daily (6.1.2).
     */
    function sopFlags(data) {
      const ST = window.VASC_STATUS;
      const cases = V.cases || [];
      const sop = (data.report && data.report.sop) || null;
      if (!ST || !cases.length) return "";

      const rows = [];
      const uat = cases.filter((row) => /^uat$/i.test(String(ST.statusOf(row) || "")));
      const shortUat = uat.filter((row) => {
        const hit = ST.statusRow(row);
        const stamp = Date.parse(String((hit && hit.modified) || row.modified || ""));
        if (!isFinite(stamp)) return false;
        return (Date.now() - stamp) / 86400000 < 14;
      });
      if (uat.length) {
        rows.push(`<div class="stat"><b>${shortUat.length}</b><span>UAT projects under the two-week minimum (of ${uat.length})</span></div>`);
      }
      if (sop && sop.openWork) {
        rows.push(`<div class="stat"><b>${sop.noTimeToday}</b><span>Open work items with no time logged on ${V.esc(sop.day || "the saved day")} (of ${sop.openWork})</span></div>`);
      }
      if (!rows.length) return "";
      return `<div class="stats">${rows.join("")}</div>
        <p class="note">SOP-001 6.4.6 sets a two-week minimum for UAT, and 6.1.2 asks for time to be entered daily on every task.</p>`;
    }

    function render(data) {
      const report = data.report || { totals: {}, projects: [], types: [], states: [] };
      const totals = report.totals || {};
      const flags = sopFlags(data);
      stage.innerHTML = `
        <section class="lede"><h1>Metrics</h1><p>Hours needed, left, and used are the estimates entered on VASC work items. When a parent and something under it both have a figure, the figure underneath is the one that counts.</p></section>
        ${flags}
        <div class="stats">
          <div class="stat"><b>${hours(totals.needed)}</b><span>Hours needed</span></div>
          <div class="stat"><b>${hours(totals.left)}</b><span>Hours left</span></div>
          <div class="stat"><b>${hours(totals.used)}</b><span>Hours used</span></div>
          <div class="stat"><b>${totals.open || 0}</b><span>Open work items</span></div>
        </div>
        <section class="panel"><h2>Hours left by project</h2>${report.projects && report.projects.length ? bars(report.projects) : "<p>No remaining hours are entered.</p>"}</section>
        <div class="cols">
          <section class="panel"><h2>Hours left by type</h2>${report.types && report.types.length ? bars(report.types) : "<p>No remaining hours are entered.</p>"}</section>
          <section class="panel"><h2>Hours left by state</h2>${report.states && report.states.length ? bars(report.states) : "<p>No remaining hours are entered.</p>"}</section>
        </div>
        <p><button class="btn" id="sync" type="button">Sync</button></p>
        <p id="sync-note" class="note">${V.esc(data.note || "")}</p>`;
      const button = document.getElementById("sync");
      if (button) {
        button.addEventListener("click", () => {
          button.disabled = true;
          load(true);
        });
      }
    }

    function load(fresh) {
      const note = document.getElementById("sync-note");
      if (note && fresh) note.textContent = "Reading Azure DevOps…";
      V.devops("metrics", fresh ? { fresh: "1" } : null).then(render).catch((error) => {
        if (error.login) {
          V.promptLogin();
          return;
        }
        stage.innerHTML = `<section class="panel"><h1>Metrics</h1><p>${V.esc(error.message || "The hours could not be loaded.")}</p></section>`;
      });
    }

    load(false);
  });
})();
