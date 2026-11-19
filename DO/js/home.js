(function () {
  const V = window.VASC;
  V.ready.then(() => {
  const stage = document.querySelector(".stage");
  const root = V.rootOrg();
  const vcds = V.orgs.find((org) => org.short === "VCDS" && org.level === "L1");
  const departments = V.childrenOf(root);
  const apps = [
    ["intake.html", "Intake", "Use case intake", false, "intake"],
    ["org.html?node=org:" + vcds.id, "Organization", "VCDS", false, "org"],
    ["apps.html", "Apps", "App directory", false, "apps"],
    ["brief.html", "Brief", "Executive overview", false, "brief"],
    ["timeline.html", "Timeline", "Hours over time", true, "timeline"],
    ["pm.html", "Project management", "Stages, list and scoring", true, "pm"],
    ["connect.html", "Connections", "Sign-in", true, "connect"],
    ["links.html", "Links", "Public sites", false, "links"],
    ["feedback.html", "Comments", "Bug report", false, "feedback"],
    ["acronyms.html", "Abbreviations", "Manuals and VASC notes", false, "acronyms"]
  ];
  const shown = apps.filter((item) => V.role() === "vasc" || !item[3]);
  const ST = window.VASC_STATUS;

  function tile(href, title, sub, logoHtml, kind) {
    const tag = kind ? ` data-kind="${V.esc(kind)}"` : "";
    return `<a class="tile" href="${href}"${tag}>${logoHtml}<span>${V.esc(title)}</span><em>${V.esc(sub)}</em></a>`;
  }

  /**
   * Portfolio counts, the way the SharePoint list opens: one coloured chip per
   * status, decisions first. Each chip opens the project list filtered to it.
   */
  function statusStrip() {
    if (!ST) return "";
    const buckets = ST.buckets();
    if (!buckets.length) return "";
    const total = buckets.reduce((sum, bucket) => sum + bucket.count, 0);
    return `<p class="group-label">Portfolio status</p>
      <div class="status-strip">${buckets.map((bucket) => `<a class="status-chip tone-${bucket.tone}" href="projects.html?status=${encodeURIComponent(bucket.name)}"><b>${bucket.count}</b><span>${V.esc(bucket.name)}</span></a>`).join("")}</div>
      <p class="note">${total} use cases on the Project Scores list${ST.updated() ? ", last changed " + V.esc(ST.updated()) : ""}.</p>`;
  }

  function render(query) {
    const q = query.trim().toLowerCase();
    if (!q) {
      stage.innerHTML = `
        ${statusStrip()}
        <p class="group-label">Start</p>
        <div class="tiles">${shown.map(([href, title, sub, , id]) => tile(href, title, sub, V.navIcon(id, 48), V.featureKind ? V.featureKind(id) : "")).join("")}</div>
        <p class="feature-legend"><span data-kind="public">Public</span><span data-kind="aux">Auxiliary</span>${V.role() === "vasc" ? `<span data-kind="admin">Admin</span>` : ""}</p>
        <p class="group-label">Departments</p>
        <div class="dept-tiles">${departments.map((org) => tile("org.html?id=" + org.id, org.short, org.level, V.logo(org, 48))).join("")}</div>`;
      return;
    }
    const hits = V.orgs.filter((org) => (org.short + " " + org.name + " " + org.fr).toLowerCase().includes(q)).slice(0, 30);
    stage.innerHTML = `
      <p class="note">${hits.length} organization${hits.length === 1 ? "" : "s"} · <a href="acronyms.html?q=${encodeURIComponent(query.trim())}">Search abbreviations for ${V.esc(query.trim())}</a></p>
      <div class="hits">${hits.map((org) => `<a class="hit" href="org.html?id=${org.id}">${V.logo(org, 36)}<span><strong>${V.esc(org.short)}</strong><br><em>${V.esc(org.level)} · ${V.esc(org.name)}</em></span></a>`).join("") || "<p>No organization with that name.</p>"}</div>`;
  }

  const params = new URLSearchParams(location.search);
  const initial = params.get("q") || "";
  V.searchChart = render;
  render(initial);
  const box = document.getElementById("start-search");
  if (box && window.parent === window && initial) box.value = initial;
  if (ST) {
    ST.load().then(() => {
      const q = (document.getElementById("start-search") || {}).value || initial;
      if (!String(q || "").trim()) render(initial);
    });
  }
  });
})();
