(function () {
  const V = window.VASC;
  const ready = window.VASC_STATUS ? window.VASC_STATUS.load() : Promise.resolve();
  V.ready.then(() => ready).then(() => {
    const stage = document.querySelector(".stage");
    if (!stage || document.body.dataset.page !== "apps" || !/app\.html$/i.test(location.pathname.split("/").pop() || "")) return;
    const params = new URLSearchParams(location.search);
    const name = params.get("name") || "";
    let lane = params.get("lane") || "";
    if (!lane) {
      try { lane = sessionStorage.getItem("vasc-apps-lane") || ""; } catch (error) { lane = ""; }
    }
    const back = lane ? "apps.html?lane=" + encodeURIComponent(lane) : "apps.html";
    const apps = window.VASC_APPS || [];
    const app = apps.find((item) => item.name.toLowerCase() === name.toLowerCase());
    if (!app) {
      stage.innerHTML = `<section class="lede"><h1>App</h1><p>That app is not in the directory.</p><p><a href="${back}">Back to apps</a></p></section>`;
      return;
    }
    const shot = (window.VASC_APP_THUMBS || {})[app.name] || "";
    if (V.setTitle) V.setTitle(app.name + " — VASC Pivot");
    const play = typeof window.VASC_PLAYABLE === "function" && window.VASC_PLAYABLE(app.url);
    const cases = (app.cases || []).map((title) => {
      const row = (V.cases || []).find((item) => item.title === title);
      return row ? `<a href="case.html?id=${encodeURIComponent(row.id)}">${V.esc(title)}</a>` : V.esc(title);
    }).join("<br>");
    const where = V.role() === "vasc" ? `<dt>Where</dt><dd>${V.esc(app.lane || app.status || "—")}</dd>` : "";
    const statusRow = window.VASC_STATUS ? window.VASC_STATUS : null;
    const linked = (app.cases || []).map((title) => (V.cases || []).find((item) => item.title === title)).filter(Boolean);
    const decided = statusRow ? linked.map((row) => statusRow.statusOf(row)).filter(Boolean)[0] || "" : "";
    const banner = decided
      ? `<p class="status-line">${statusRow.pill(linked.find((row) => statusRow.statusOf(row)))} <span class="note">The use case behind this app</span></p>`
      : "";
    stage.innerHTML = `
      <section class="lede">
        <p><a href="${back}">Apps</a></p>
        ${banner}
        ${shot ? (play ? `<p><a class="app-hero-link" href="${V.esc(app.url)}" target="_blank" rel="noopener"><img class="app-hero" src="${V.esc(shot)}" alt="Open ${V.esc(app.name)}"></a></p>` : `<p><img class="app-hero" src="${V.esc(shot)}" alt=""></p>`) : ""}
        <h1>${V.esc(app.name)}</h1>
        ${app.fr ? `<p>${V.esc(app.fr)}</p>` : ""}
      </section>
      <section class="panel">
        <p>${V.esc(app.summary || "No description yet.")}</p>
        <dl class="kv">
          <dt>Client</dt><dd>${V.esc(app.client || "—")}</dd>
          <dt>Updated</dt><dd>${V.esc(app.updated || "—")}</dd>
          ${where}
          <dt>Intake</dt><dd>${cases || "—"}</dd>
        </dl>
        ${play ? `<p><a class="btn" href="${V.esc(app.url)}" target="_blank" rel="noopener">Open</a></p>` : `<p class="note">No address yet.</p>`}
      </section>`;
  });
})();
