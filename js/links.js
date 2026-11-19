(function () {
  const V = window.VASC;
  const KINDS = [
    ["all", "All"],
    ["devops", "DevOps"],
    ["sharepoint", "SharePoint"],
    ["powerbi", "Power BI"],
    ["azure", "Azure"],
    ["teams", "Teams"],
    ["outlook", "Outlook"],
    ["onedrive", "OneDrive"],
    ["other", "Other"]
  ];
  const SITES = [
    // Old and new side by side while the move is in progress. The new project is
    // where work goes from now on; the old one still holds the history.
    ["DevOps — VCDS ASC Projects and Team Management (new)", "https://dev.azure.com/VCDS-VCEMD/VCDS%20ASC%20%E2%80%94%20Projects%20and%20Team%20Management", "devops", false],
    ["DevOps — VASC (old)", "https://dev.azure.com/VCDS-VCEMD/VASC", "devops", false],
    ["DevOps — Digital Office WebApp source", "https://dev.azure.com/VCDS-VCEMD/Digital%20Office%20WebApp", "devops", false],
    ["Outlook (webmail)", "https://mail-dnd.forces.mil.ca/owa", "outlook", false],
    ["Power BI", "https://app.powerbi.com", "powerbi", false],
    ["Power Automate", "https://make.powerautomate.com", "automate", false],
    ["Canvas", "https://make.powerapps.com", "canvas", false],
    ["Power Sweeper", "power_sweeper/", "sweeper", false],
    ["VASC homepage", "https://018gc.sharepoint.com.mcas.ms/sites/ORG-9872-0002/SitePages/EventPlanHome.aspx", "sharepoint", false],
    ["OneDrive", "https://018gc-my.sharepoint.com", "onedrive", true],
    ["VCR and others (dev)", "https://018gc.sharepoint.com/sites/ORG-9872-0002-Dev", "sharepoint", true],
    ["VCR (prod)", "https://018gc.sharepoint.com/sites/000-003-656/", "sharepoint", true],
    ["OHS", "https://018gc.sharepoint.com/sites/000-001-145/", "sharepoint", true],
    ["SharePoint 000-002-405", "https://018gc.sharepoint.com/sites/000-002-405/", "sharepoint", true],
    ["ORG-0150-0001", "https://018gc.sharepoint.com/sites/ORG-0150-0001", "sharepoint", true],
    ["Defence 365", "https://018gc.sharepoint.com/sites/D365", "sharepoint", true]
  ];
  const LABELS = Object.fromEntries(KINDS);

  V.ready.then(() => {
    const stage = document.querySelector(".stage");
    let rows = [];
    let note = "";
    let open = false;
    let kind = "all";
    let query = new URLSearchParams(location.search).get("q") || "";
    let drawn = false;
    V.searchChart = (value) => {
      query = String(value || "");
      if (drawn) draw();
    };

    const ICONS = {
      powerbi: "assets/link-icons/powerbi.svg",
      automate: "assets/link-icons/powerautomate.svg",
      canvas: "assets/link-icons/powerapps.svg",
      sweeper: "assets/link-icons/power-sweeper.svg",
      onedrive: "assets/link-icons/onedrive.svg",
      sharepoint: "assets/link-icons/sharepoint.svg",
      devops: "assets/link-icons/devops.svg",
      teams: "assets/link-icons/teams.svg",
      outlook: "assets/link-icons/outlook.svg",
      azure: "assets/link-icons/azure.svg"
    };

    function kindIcon(kind) {
      const src = ICONS[kind];
      if (src) return `<img class="link-icon" alt="" src="${src}">`;
      return `<svg class="link-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#f7fbff" stroke-width="1.7" aria-hidden="true"><path d="M10 13a5 5 0 007.2.2l1.8-2a5 5 0 00-7-7.1L10.8 5.4M14 11a5 5 0 00-7.2-.2l-1.8 2a5 5 0 007 7.1l1.2-1.3"/></svg>`;
    }

    function iconFor(row) {
      const url = String(row.url || "").toLowerCase();
      if (url.includes("powerbi")) return kindIcon("powerbi");
      if (url.includes("powerautomate") || url.includes("flow.microsoft")) return kindIcon("automate");
      if (row.kind === "canvas" || url.includes("make.powerapps")) return kindIcon("canvas");
      if (url.includes("power_sweeper")) return kindIcon("sweeper");
      if (url.includes("-my.sharepoint") || url.includes("onedrive")) return kindIcon("onedrive");
      if (row.kind === "devops" || url.includes("dev.azure")) return kindIcon("devops");
      if (row.kind === "teams" || url.includes("teams.microsoft")) return kindIcon("teams");
      if (row.kind === "outlook" || url.includes("mail-dnd") || url.includes("outlook")) return kindIcon("outlook");
      if (row.kind === "sharepoint" || url.includes("sharepoint")) return kindIcon("sharepoint");
      if (row.kind === "azure") return kindIcon("azure");
      return kindIcon(row.kind || "other");
    }

    function matches(row) {
      if (kind !== "all" && row.kind !== kind) return false;
      const q = query.trim().toLowerCase();
      if (!q) return true;
      const blob = [row.title, row.url, row.project, row.caseTitle || "", ...(row.items || []).map((item) => item.title)].join(" ").toLowerCase();
      return blob.includes(q);
    }

    function draw() {
      const q = query.trim().toLowerCase();
      const sites = SITES.filter((row) => {
        if (row[3] && open) return false;
        if (kind !== "all" && row[2] !== kind) return false;
        if (!q) return true;
        return (row[0] + " " + row[1]).toLowerCase().includes(q);
      });
      const shown = rows.filter(matches);
      const groups = new Map();
      shown.forEach((row) => {
        const name = row.project || "VASC";
        if (!groups.has(name)) groups.set(name, []);
        groups.get(name).push(row);
      });
      const filters = KINDS.filter(([id]) => !open || id === "all" || rows.some((row) => row.kind === id)).map(([id, label]) => {
        const count = id === "all" ? rows.length : rows.filter((row) => row.kind === id).length;
        return `<button type="button" class="btn" data-kind="${id}" aria-pressed="${kind === id}">${label} ${count}</button>`;
      }).join("");
      const sections = [...groups.entries()].map(([project, items]) => {
        const list = items.map((row) => {
          const work = (row.items || []).map((item) => `<a href="org.html?node=wit:${item.id}">${V.esc(item.type)} ${item.id}</a>`).join(", ");
          const cases = (row.cases || []).map((id) => `<a href="case.html?id=${encodeURIComponent(id)}">Case ${V.esc(id)}</a>`).join(", ");
          const tied = [work, cases].filter(Boolean).join(" · ");
          return `<li>${iconFor(row)}<a href="${V.esc(row.url)}" target="_blank" rel="noopener">${V.esc(row.title)}</a>${tied ? `<div class="note">${tied}</div>` : ""}</li>`;
        }).join("");
        return `<article class="pulse-card link-project"><h2>${V.esc(project)}</h2><ul>${list}</ul></article>`;
      }).join("");
      const devopsSites = sites.filter((row) => row[2] === "devops");
      const otherSites = sites.filter((row) => row[2] !== "devops");
      const siteRow = ([title, url, kind]) => {
        const external = /^https?:/i.test(url);
        const target = external ? ` target="_blank" rel="noopener"` : ` target="_top"`;
        const now = /\(new\)/i.test(title) ? ` <span class="badge">Current</span>` : "";
        return `<li>${iconFor({ url: url, kind: kind })}<a href="${V.esc(url)}"${target}>${V.esc(title)}</a>${now}</li>`;
      };
      const devopsList = devopsSites.map(siteRow).join("");
      const siteList = otherSites.map(siteRow).join("");
      stage.innerHTML = `
        <section class="lede"><h1>Links</h1>
          <p>${open ? "Tools and landing pages. Sites that need an account appear after sign-in. Apps stay on the Apps page." : "Tools, landing pages, and the SharePoint sites that need an account. Apps stay on the Apps page."}</p>
          ${note ? `<p class="note">${V.esc(note)}</p>` : ""}
          ${open ? "" : `<div class="link-filters">${filters}</div>`}
        </section>
        <div class="pulse-list">
          ${devopsList ? `<article class="pulse-card link-project"><h2>DevOps</h2><ul>${devopsList}</ul><p class="note">Work moves to Projects and Team Management. The old VASC project keeps the history, and no new items belong there.</p></article>` : ""}
          ${siteList ? `<article class="pulse-card link-project"><h2>Sites</h2><ul>${siteList}</ul></article>` : ""}
          ${sections || (siteList ? "" : "<p>No links in this set.</p>")}
        </div>`;
      stage.querySelectorAll("[data-kind]").forEach((button) => {
        button.addEventListener("click", () => {
          kind = button.getAttribute("data-kind") || "all";
          draw();
        });
      });
    }

    V.devops("links").then((data) => {
      note = data.note || "";
      open = data.public === true;
      drawn = true;
      rows = open ? [] : (data.links || []).filter((row) => {
        const url = String(row.url || "");
        if (row.kind === "powerapps" || /powerapps\.com/i.test(url)) return false;
        return !SITES.some((site) => site[1].replace(/\/$/, "").toLowerCase() === url.replace(/\/$/, "").toLowerCase());
      });
      draw();
    }).catch((error) => {
      if (error.login) {
        V.promptLogin();
        return;
      }
      stage.innerHTML = `<section class="panel"><h1>Links</h1><p>${V.esc(error.message)}</p></section>`;
    });
  });
})();
