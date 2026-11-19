/**
 * Project management: business management board.
 *
 * Every project is a rectangle in the stage it has reached. Opening one grows it
 * in place to most of the board, with the rectangles above and below still in
 * view and still live, so moving between projects never reloads the page or
 * loses where you were. The open rectangle carries what the organization chart's
 * right pane used to: the Azure DevOps work item, its children, and the edits,
 * moves, and deletions that go with it.
 */
(function () {
  const V = window.VASC;
  V.ready.then(() => {
  const stage = document.querySelector(".stage");
  if (!V.requireVasc(stage)) return;
  const S = window.VASC_SCORE;
  const ST = window.VASC_STATUS;
  const P = window.VASC_PIPELINE;

  const SOURCES = [
    ["case", "Use cases"],
    ["new", "Team Management (new)"],
    ["old", "VASC (old)"]
  ];
  const PROJECT_NAME = { old: "VASC", new: "Projects and Team Management" };
  const PROJECT_TYPES = /^(epic|active projects|uat projects|completed projects)$/i;
  const TEAM_TYPES = /^(program|client service)$/i;
  const WORK_STATES = ["New", "Active", "Resolved", "Closed", "Removed", "Done", "Completed"];
  const STAGE_TONE = { intake: "unknown", planning: "go", development: "work", production: "done", continuous: "build", off: "hold" };
  const STATUS_WORDS = {
    intake: "New",
    planning: "Prioritized",
    development: "In Dev · UAT",
    production: "Completed",
    continuous: "",
    off: "Deferred · Declined · Decommissioned"
  };

  const params = new URLSearchParams(location.search);
  let groupBy = params.get("group") === "org" ? "org" : "stage";
  let sources = new Set((params.get("src") || "case,new").split(",").filter((key) => SOURCES.some((row) => row[0] === key)));
  if (!sources.size) sources = new Set(["case", "new"]);
  let stageFilter = params.get("stage") || "";
  let query = params.get("q") || "";
  let openId = "";
  let wantedId = params.get("item") || "";
  let canWrite = false;
  const drill = {};
  const detailCache = new Map();
  const trees = { old: null, new: null };
  const treeNotes = { old: "", new: "" };
  let compose = null;
  let toolNote = "";

  function esc(value) {
    return V.esc(value);
  }

  /* ---------- Azure DevOps hierarchy ---------- */

  function storeKey(key) {
    return "vasc-tree-v8-" + key;
  }

  function indexTree(key, items) {
    const byId = new Map();
    const kids = new Map();
    (items || []).forEach((item) => {
      if (!item || !item.id) return;
      byId.set(String(item.id), item);
    });
    byId.forEach((item) => {
      const parent = item.parentId && byId.has(String(item.parentId)) ? String(item.parentId) : "";
      if (!kids.has(parent)) kids.set(parent, []);
      kids.get(parent).push(String(item.id));
    });
    trees[key] = { key: key, items: items || [], byId: byId, kids: kids };
    return trees[key];
  }

  function rememberTree(key, items, people) {
    try {
      const raw = JSON.stringify({ at: Date.now(), project: key, items: items, people: people || [] });
      if (raw.length < 4500000) sessionStorage.setItem(storeKey(key), raw);
    } catch (error) {}
  }

  function forgetTree(key) {
    try { sessionStorage.removeItem(storeKey(key)); } catch (error) {}
  }

  function loadTree(key, fresh) {
    if (!fresh) {
      try {
        const saved = JSON.parse(sessionStorage.getItem(storeKey(key)) || "null");
        if (saved && saved.project === key && Array.isArray(saved.items) && Date.now() - saved.at < 24 * 60 * 60 * 1000) {
          treeNotes[key] = "Saved on this browser.";
          return Promise.resolve(indexTree(key, saved.items));
        }
      } catch (error) {}
    }
    const query = { project: key };
    if (fresh) query.fresh = "1";
    return V.devops("tree", query).then((data) => {
      rememberTree(key, data.items || [], data.people || []);
      treeNotes[key] = data.note || "";
      return indexTree(key, data.items || []);
    }).catch((error) => {
      treeNotes[key] = error.message || "Azure DevOps did not answer.";
      return trees[key];
    });
  }

  function treeOf(workId) {
    const id = String(workId || "");
    if (trees.new && trees.new.byId.has(id)) return trees.new;
    if (trees.old && trees.old.byId.has(id)) return trees.old;
    return null;
  }

  function nodeOf(workId) {
    const tree = treeOf(workId);
    return tree ? tree.byId.get(String(workId)) : null;
  }

  function childrenOf(workId) {
    const tree = treeOf(workId);
    if (!tree) return [];
    return (tree.kids.get(String(workId)) || []).map((id) => tree.byId.get(id)).filter(Boolean);
  }

  function chainOf(workId) {
    const tree = treeOf(workId);
    const list = [];
    let node = tree ? tree.byId.get(String(workId)) : null;
    let guard = 0;
    while (node && guard < 24) {
      list.unshift(node);
      node = node.parentId ? tree.byId.get(String(node.parentId)) : null;
      guard += 1;
    }
    return list;
  }

  function isProject(tree, item) {
    if (!PROJECT_TYPES.test(String(item.type || ""))) return false;
    let parent = item.parentId ? tree.byId.get(String(item.parentId)) : null;
    let guard = 0;
    while (parent && guard < 24) {
      if (PROJECT_TYPES.test(String(parent.type || "")) || TEAM_TYPES.test(String(parent.type || ""))) return false;
      parent = parent.parentId ? tree.byId.get(String(parent.parentId)) : null;
      guard += 1;
    }
    return true;
  }

  function childType(item) {
    const type = String(item && item.type || "").toLowerCase();
    if (type === "projects" || type === "program" || type.indexOf("project") >= 0) return "Epic";
    if (type === "epic") return "Feature";
    if (type === "feature") return "User Story";
    if (type === "user story" || type === "story" || type === "bug" || type === "issue") return "Task";
    return "";
  }

  function hourText(value) {
    return value == null || value === "" ? "" : String(value);
  }

  /* ---------- Board items ---------- */

  function caseItem(row) {
    const status = ST.label(ST.statusOf(row));
    const org = V.findOrg(row.orgId);
    const stageId = P.stageOfStatus(status);
    return {
      id: "case:" + row.id,
      kind: "case",
      row: row,
      title: row.title || "Use case " + row.id,
      stage: stageId,
      status: status,
      tone: status ? ST.tone(status) : "unknown",
      orgName: row.l2 || (org ? org.short : "") || "No organization",
      org: org,
      blob: [row.title, row.l2, row.l3, row.category, row.priority, status, (ST.statusRow(row) || {}).lead || ""].join(" ").toLowerCase()
    };
  }

  function workItem(key, item) {
    const stageId = P.stageOfState(item.state);
    const area = String(item.area || "").split("\\").map((part) => part.trim()).filter(Boolean);
    const last = area.length > 1 ? area[area.length - 1] : "";
    return {
      id: "wit:" + item.id,
      kind: "work",
      project: key,
      work: item,
      title: item.title || item.type || "Work item " + item.id,
      stage: stageId,
      status: item.state || "",
      tone: STAGE_TONE[stageId] || "unknown",
      orgName: last || "Digital Office",
      blob: [item.title, item.type, item.state, item.assigned, item.area].join(" ").toLowerCase()
    };
  }

  let extras = [];

  function allItems() {
    const list = [];
    if (sources.has("case")) V.cases.forEach((row) => list.push(caseItem(row)));
    ["new", "old"].forEach((key) => {
      if (!sources.has(key) || !trees[key]) return;
      trees[key].items.forEach((item) => {
        if (isProject(trees[key], item)) list.push(workItem(key, item));
      });
    });
    extras.forEach((id) => {
      if (list.some((item) => item.id === id)) return;
      const node = nodeOf(id.slice(4));
      if (node) list.push(workItem(treeOf(node.id).key, node));
    });
    return list;
  }

  function shownItems() {
    const q = query.trim().toLowerCase();
    return allItems().filter((item) => {
      if (stageFilter && item.stage !== stageFilter) return false;
      return !q || item.blob.includes(q);
    });
  }

  function findItem(id) {
    return allItems().find((item) => item.id === id) || null;
  }

  /* ---------- The six features ---------- */

  function caseSteps(row) {
    const steps = ST.featuresOf(row).map((step) => Object.assign({ devops: undefined }, step));
    const workId = row.workId ? String(row.workId) : "";
    if (workId && nodeOf(workId)) {
      childrenOf(workId).forEach((kid) => {
        const id = P.featureOf(kid.title);
        const step = steps.find((item) => item.id === id);
        if (step && !step.devops) step.devops = { state: kid.state || "", workId: String(kid.id) };
      });
    }
    return steps;
  }

  function workSteps(node) {
    const kids = childrenOf(node.id);
    return ST.features().map(([id, name]) => {
      const kid = kids.find((item) => P.featureOf(item.title) === id);
      return {
        id: id,
        name: name,
        on: !!(kid && P.done(kid.state)),
        devops: kid ? { state: kid.state || "", workId: String(kid.id) } : null
      };
    });
  }

  function stepsOf(item) {
    return item.kind === "case" ? caseSteps(item.row) : workSteps(item.work);
  }

  /* ---------- Rendering ---------- */

  function summary(item) {
    const steps = stepsOf(item);
    const hasDevops = item.kind === "work" && steps.some((step) => step.devops);
    const kind = item.kind === "case"
      ? `Use case #${esc(item.row.id)}`
      : `${esc(item.work.type || "Work item")} ${esc(item.work.id)} · ${item.project === "new" ? "Team Management" : "VASC"}`;
    const status = item.kind === "case"
      ? `${ST.pill(item.row)}${ST.dot(item.row)}`
      : `<span class="status-pill tone-${item.tone}">${esc(item.work.state || "No state")}</span>`;
    const who = item.kind === "case"
      ? ((ST.statusRow(item.row) || {}).lead || "")
      : String(item.work.assigned || "").split(" VCDS")[0];
    const left = item.kind === "work" && Number(item.work.hoursLeft) > 0 ? `<span class="pm-hours">${esc(item.work.hoursLeft)} h left</span>` : "";
    return `<div class="pm-sum">
      <p class="pm-kind"><span>${kind}</span>${item.orgName !== "No organization" ? `<span class="pm-org">${esc(item.orgName)}</span>` : ""}</p>
      <h3>${esc(item.title)}</h3>
      <p class="pm-meta">${status}${who ? `<span class="pm-who">${esc(who)}</span>` : ""}${left}</p>
      ${item.kind === "case" || hasDevops ? P.track(steps, { compact: true, mode: "read", label: "SOP-001 features for " + item.title }) : ""}
    </div>`;
  }

  function rect(item) {
    const open = item.id === openId;
    return `<article class="pm-item tone-${item.tone} kind-${item.kind}${open ? " is-open" : ""}" data-id="${esc(item.id)}" tabindex="0" aria-expanded="${open}" aria-label="${esc(item.title)}">${open ? detail(item) : summary(item)}</article>`;
  }

  function groups(items) {
    if (groupBy === "org") {
      const map = new Map();
      items.forEach((item) => {
        if (!map.has(item.orgName)) map.set(item.orgName, []);
        map.get(item.orgName).push(item);
      });
      return [...map.entries()]
        .sort((a, b) => b[1].length - a[1].length || a[0].localeCompare(b[0]))
        .map(([name, list]) => ({ id: "org-" + name, name: name, gloss: name === "No organization" ? "The use case names no L2" : "", items: list }));
    }
    return P.stages.map((row) => ({
      id: row.id,
      name: row.name,
      gloss: row.gloss + (STATUS_WORDS[row.id] ? " · Project Scores: " + STATUS_WORDS[row.id] : "") + " · DevOps: " + row.devops,
      items: items.filter((item) => item.stage === row.id)
    })).filter((group) => group.items.length);
  }

  function sortItems(list) {
    return list.slice().sort((a, b) => {
      if (a.kind !== b.kind) return a.kind === "case" ? -1 : 1;
      if (a.kind === "case") {
        const rank = ST.rank(a.status) - ST.rank(b.status);
        if (rank) return rank;
        const sa = S.scoreCase(a.row, S.edits[a.row.id]).score;
        const sb = S.scoreCase(b.row, S.edits[b.row.id]).score;
        return sb - sa || a.title.localeCompare(b.title);
      }
      return a.title.localeCompare(b.title);
    });
  }

  function outline() {
    const all = allItems().filter((item) => !query.trim() || item.blob.includes(query.trim().toLowerCase()));
    const counts = {};
    all.forEach((item) => { counts[item.stage] = (counts[item.stage] || 0) + 1; });
    const main = ["intake", "planning", "development", "production"];
    const step = (id) => {
      const row = P.stage(id);
      const on = stageFilter === id;
      return `<button type="button" class="pm-stage tone-${STAGE_TONE[id]}" data-stage="${id}" aria-pressed="${on}">
        <b>${counts[id] || 0}</b><span>${esc(row.name)}</span><em>${esc(STATUS_WORDS[id] || row.devops)}</em>
      </button>`;
    };
    return `<div class="pm-outline-main" role="group" aria-label="Project stages">${main.map(step).join("")}</div>
      <div class="pm-outline-side" role="group" aria-label="Off the main track">${["continuous", "off"].filter((id) => counts[id]).map(step).join("")}</div>`;
  }

  function tools() {
    const group = [["stage", "By stage"], ["org", "By organization"]].map(([key, label]) => `<button type="button" data-group="${key}" aria-pressed="${groupBy === key}">${label}</button>`).join("");
    const src = SOURCES.map(([key, label]) => `<button type="button" data-source="${key}" aria-pressed="${sources.has(key)}">${label}</button>`).join("");
    return `<div class="dims" role="group" aria-label="Group">${group}</div>
      <div class="dims" role="group" aria-label="Show">${src}</div>
      <details class="pm-devtools"><summary>DevOps tools</summary><div class="pm-devtools-box">
        <p><button type="button" class="btn" data-act="sync">Sync from Azure DevOps</button>
        ${canWrite ? `<button type="button" class="btn" data-act="dummy">Create test epic in VASC</button>` : ""}</p>
        <p class="note">${esc([treeNotes.new && "Team Management: " + treeNotes.new, treeNotes.old && "VASC: " + treeNotes.old].filter(Boolean).join(" "))}</p>
      </div></details>
      <p class="note pm-tool-note" id="pm-tool-note" aria-live="polite">${esc(toolNote)}</p>`;
  }

  function renderBoard() {
    const items = shownItems();
    const board = document.getElementById("pm-board");
    const lanes = groups(items).map((group) => `<section class="pm-lane" data-lane="${esc(group.id)}">
      <header><h2>${esc(group.name)} <span>${group.items.length}</span></h2><p>${esc(group.gloss)}</p></header>
      <div class="pm-grid">${sortItems(group.items).map(rect).join("")}</div>
    </section>`).join("");
    board.innerHTML = lanes || `<p class="pm-empty">Nothing matches. Clear the search or choose another stage.</p>`;
    board.classList.toggle("has-open", !!openId);
    document.getElementById("pm-outline").innerHTML = outline();
    document.getElementById("pm-tools").innerHTML = tools();
    if (openId) {
      const article = articleOf(openId);
      if (article) {
        bindOpen(article);
        placeOpen(article, false);
      } else {
        openId = "";
      }
    }
    rememberUrl();
  }

  function articleOf(id) {
    return document.querySelector(`.pm-item[data-id="${CSS.escape(id)}"]`);
  }

  function rememberUrl() {
    const url = new URL(location.href);
    const set = (key, value) => (value ? url.searchParams.set(key, value) : url.searchParams.delete(key));
    set("item", openId);
    set("group", groupBy === "org" ? "org" : "");
    const src = SOURCES.map((row) => row[0]).filter((key) => sources.has(key)).join(",");
    set("src", src === "case,new" ? "" : src);
    set("stage", stageFilter);
    set("q", query.trim());
    history.replaceState(history.state, "", url.pathname + url.search);
  }

  /* ---------- An open rectangle ---------- */

  function fact(label, value) {
    const text = value == null ? "" : String(value).trim();
    return text ? `<dt>${V.linkAbbrs ? V.linkAbbrs(label) : esc(label)}</dt><dd>${esc(text)}</dd>` : "";
  }

  function caseHead(item) {
    const row = item.row;
    const orgLine = item.org
      ? ` · <a href="org.html?node=${encodeURIComponent("org:" + item.org.id)}">${V.orgIcon(14)} ${esc(item.org.short)}</a>${row.l3 ? " · " + esc(row.l3) : ""}`
      : (row.l2 ? " · " + esc(row.l2) : "");
    return `<p class="pm-kicker">Use case #${esc(row.id)} · ${esc(P.stage(item.stage).name)}${orgLine}</p>
      <h2>${esc(item.title)}</h2>
      <div class="pm-status">${ST.select(row, ST.statuses())}${ST.dot(row)}<span class="note" id="pm-status-note" aria-live="polite"></span></div>
      <div class="pm-steps" data-steps="case">${P.track(caseSteps(row), { mode: "edit", devopsKnown: false, label: "SOP-001 features" })}</div>
      <p class="note pm-step-note" id="pm-step-note" aria-live="polite">Choose a stage to mark it and the ones before it done. Choose the last done stage again to step back.</p>`;
  }

  function caseMain(item) {
    const row = item.row;
    const hit = ST.statusRow(row) || {};
    const scored = S.scoreCase(row, S.edits[row.id]);
    const facts = [
      fact("Recommendation", hit.recommendation),
      fact("Portfolio score", hit.adjusted || hit.weighted),
      fact("DO quick read", scored.score),
      fact("Effort", hit.effort),
      fact("Confidence", hit.confidence),
      fact("Priority", row.priority),
      fact("Category", row.category),
      fact("Project lead / OPI", hit.lead),
      fact("Data lead", hit.dataLead),
      fact("Last scored", hit.lastScored),
      fact("Security/IM review", hit.securityReview ? "Required" : ""),
      fact("Next step", row.nextStep),
      fact("Requested", row.requested)
    ].join("");
    const text = (label, value) => value ? `<h4>${esc(label)}</h4><p>${esc(value)}</p>` : "";
    return `<h3>Project Scores</h3>
      ${facts ? `<dl class="kv pm-facts">${facts}</dl>` : ""}
      ${hit.roadblocks ? `<p class="status-why"><span>Roadblock</span> ${esc(hit.roadblocks)}</p>` : ""}
      ${text("Overview", row.overview)}${text("Business problem", row.problem)}${text("Desired outcome", row.outcome)}
      <p><a href="case.html?id=${encodeURIComponent(row.id)}">Open the full project page</a> · <a href="score.html">How scoring works</a></p>`;
  }

  function workHead(item) {
    const node = item.work;
    const steps = workSteps(node);
    const known = steps.some((step) => step.devops);
    return `<p class="pm-kicker">${esc(node.type || "Work item")} ${esc(node.id)} · ${esc(PROJECT_NAME[item.project])} · ${esc(P.stage(item.stage).name)}</p>
      <h2>${esc(item.title)}</h2>
      <div class="pm-status"><span class="status-pill tone-${item.tone}">${esc(node.state || "No state")}</span>${node.assigned ? `<span class="pm-who">${esc(node.assigned)}</span>` : ""}</div>
      ${known
        ? `<div class="pm-steps" data-steps="work">${P.track(steps, { mode: "drill", devopsKnown: true, label: "SOP-001 features in Azure DevOps" })}</div>
          <p class="note pm-step-note">The six SOP-001 features, as Features under this ${esc(node.type || "item")} in Azure DevOps. Choose one to open it on the right.</p>`
        : `<p class="note pm-step-note">None of the six SOP-001 features are under this ${esc(node.type || "item")} in Azure DevOps yet.${canWrite && childType(node) === "Feature" ? " Add them as Features on the right." : ""}</p>`}`;
  }

  function workMain(item) {
    const node = item.work;
    const facts = [
      fact("Type", node.type),
      fact("State", node.state),
      fact("Assigned", node.assigned),
      fact("Hours needed", hourText(node.hoursNeeded)),
      fact("Hours left", hourText(node.hoursLeft)),
      fact("Hours used", hourText(node.hoursUsed)),
      fact("Changed", String(node.changed || "").slice(0, 10)),
      fact("Changed by", node.changedBy),
      fact("Area", node.area)
    ].join("");
    return `<h3>Summary</h3>
      <dl class="kv pm-facts">${facts}</dl>
      ${node.description ? `<h4>Description</h4><p class="work-text">${esc(node.description)}</p>` : `<p class="note">No description yet.</p>`}`;
  }

  function workValues(node) {
    const hit = detailCache.get(String(node.id));
    const item = hit && hit.item ? hit.item : {};
    const draft = hit && hit.draft ? hit.draft : {};
    const pick = (key, fallback) => Object.prototype.hasOwnProperty.call(draft, key) ? draft[key] : fallback;
    return {
      title: pick("title", item.title || node.title || ""),
      state: pick("state", item.state || node.state || ""),
      description: pick("description", item.description != null ? item.description : node.description || ""),
      assigned: item.assigned || node.assigned || "",
      type: item.type || node.type || "",
      hoursNeeded: pick("hoursNeeded", hourText(item.hoursNeeded != null ? item.hoursNeeded : node.hoursNeeded)),
      hoursLeft: pick("hoursLeft", hourText(item.hoursLeft != null ? item.hoursLeft : node.hoursLeft)),
      hoursUsed: pick("hoursUsed", hourText(item.hoursUsed != null ? item.hoursUsed : node.hoursUsed))
    };
  }

  function ensureDetail(node) {
    const id = String(node.id);
    const hit = detailCache.get(id);
    if (hit && hit.status) return;
    detailCache.set(id, { status: "loading" });
    V.devops("workitem", { id: id, fresh: "1" }).then((data) => {
      const previous = detailCache.get(id) || {};
      const item = data.item || {};
      detailCache.set(id, { status: "ready", item: item, draft: previous.draft });
      ["title", "state", "assigned", "description", "url"].forEach((key) => {
        if (item[key] != null && item[key] !== "") node[key] = item[key];
      });
      ["hoursNeeded", "hoursLeft", "hoursUsed"].forEach((key) => {
        if (item[key] != null) node[key] = item[key];
      });
      refreshOpen();
    }).catch((error) => {
      const previous = detailCache.get(id) || {};
      detailCache.set(id, { status: "error", message: error.message || "Azure DevOps did not answer.", draft: previous.draft });
      refreshOpen();
    });
  }

  function crumbs(rootId, focusId) {
    const chain = chainOf(focusId);
    const start = chain.findIndex((node) => String(node.id) === String(rootId));
    const shown = start >= 0 ? chain.slice(start) : chain.slice(-1);
    return shown.map((node, index) => index === shown.length - 1
      ? `<span>${esc(node.title)}</span>`
      : `<button type="button" class="linkish" data-drill="${esc(node.id)}">${esc(node.title)}</button>`).join(" / ");
  }

  function kidRect(node) {
    const tone = STAGE_TONE[P.stageOfState(node.state)] || "unknown";
    const count = childrenOf(node.id).length;
    return `<button type="button" class="pm-kid tone-${tone}" data-drill="${esc(node.id)}">
      <span class="pm-kid-type">${esc(node.type || "Item")} ${esc(node.id)}</span>
      <strong>${esc(node.title)}</strong>
      <span class="pm-kid-meta">${esc(node.state || "")}${node.assigned ? " · " + esc(String(node.assigned).split(" VCDS")[0]) : ""}${count ? " · " + count + " under it" : ""}</span>
    </button>`;
  }

  function composeForm() {
    const states = WORK_STATES.map((state) => `<option${state === compose.state ? " selected" : ""}>${esc(state)}</option>`).join("");
    return `<form class="pm-work-form" id="pm-compose">
      <h4>New ${esc(compose.type)} under ${esc(compose.parentTitle)}</h4>
      <label>Title <input name="title" maxlength="200" value="${esc(compose.title)}" required></label>
      <label>State <select name="state">${states}</select></label>
      <label>Description <textarea name="description" maxlength="8000">${esc(compose.description)}</textarea></label>
      <div class="pm-hours-row">
        <label>Hours needed <input name="hoursNeeded" inputmode="decimal" value="${esc(compose.hoursNeeded)}"></label>
        <label>Hours left <input name="hoursLeft" inputmode="decimal" value="${esc(compose.hoursLeft)}"></label>
        <label>Hours used <input name="hoursUsed" inputmode="decimal" value="${esc(compose.hoursUsed)}"></label>
      </div>
      <div class="work-save"><button class="btn" type="submit">Create ${esc(compose.type)}</button><button class="btn" type="button" data-act="compose-cancel">Cancel</button></div>
    </form>`;
  }

  function workPanel(node) {
    const values = workValues(node);
    const hit = detailCache.get(String(node.id)) || {};
    const url = node.url || "";
    const freshness = hit.status === "loading"
      ? "Reading this work item from Azure DevOps…"
      : hit.status === "error"
        ? "Showing what was saved. Azure DevOps said: " + hit.message
        : hit.status === "ready" ? "Read from Azure DevOps just now." : "";
    const facts = [
      fact("Type", values.type),
      fact("State", values.state),
      fact("Assigned", values.assigned || "—"),
      fact("Hours needed", values.hoursNeeded || "—"),
      fact("Hours left", values.hoursLeft || "—"),
      fact("Hours used", values.hoursUsed || "—"),
      fact("Changed", String(node.changed || "").slice(0, 10)),
      fact("Area", node.area)
    ].join("");
    let body;
    if (canWrite) {
      const states = WORK_STATES.slice();
      if (values.state && states.indexOf(values.state) < 0) states.unshift(values.state);
      body = `<form class="pm-work-form" id="pm-work" data-id="${esc(node.id)}">
        <label>Title <input name="title" maxlength="200" value="${esc(values.title)}" required></label>
        <p class="note">${esc(values.type)}${values.assigned ? " · " + esc(values.assigned) : ""}</p>
        <label>State <select name="state">${states.map((state) => `<option${state === values.state ? " selected" : ""}>${esc(state)}</option>`).join("")}</select></label>
        <label>Description <textarea name="description" maxlength="8000">${esc(values.description)}</textarea></label>
        <div class="pm-hours-row">
          <label>Hours needed <input name="hoursNeeded" inputmode="decimal" value="${esc(values.hoursNeeded)}"></label>
          <label>Hours left <input name="hoursLeft" inputmode="decimal" value="${esc(values.hoursLeft)}"></label>
          <label>Hours used <input name="hoursUsed" inputmode="decimal" value="${esc(values.hoursUsed)}"></label>
        </div>
        <div class="work-save"><button class="btn" type="submit">Save</button></div>
      </form>`;
    } else {
      body = `<dl class="kv">${facts}</dl>${values.description ? `<p class="work-text">${esc(values.description)}</p>` : `<p class="note">No description yet.</p>`}`;
    }
    const kids = childrenOf(node.id);
    const type = childType(node);
    const tree = treeOf(node.id);
    const other = tree && tree.key === "new" ? "old" : "new";
    const actions = canWrite ? `<p class="pm-actions">
      ${type ? `<button type="button" class="btn" data-act="add">Add ${esc(type)}</button>` : ""}
      <button type="button" class="btn" data-act="transfer" data-to="${other}">Move to ${esc(PROJECT_NAME[other])}</button>
      <button type="button" class="btn danger" data-act="delete">Delete</button>
    </p>` : `<p class="note">Adding, moving, and deleting work items needs Work Items, Read &amp; write. <button type="button" class="linkish" data-act="write">Grant write access</button></p>`;
    return `${url ? `<p><a href="${esc(url)}" target="_blank" rel="noopener">Open ${esc(node.id)} in Azure DevOps</a></p>` : ""}
      ${freshness ? `<p class="note">${esc(freshness)}</p>` : ""}
      ${compose && compose.parentId === String(node.id) ? composeForm() : body}
      ${actions}
      <h4>Under this <span class="note">${kids.length}</span></h4>
      ${kids.length ? `<div class="pm-kids">${kids.map(kidRect).join("")}</div>` : `<p class="note">Nothing under this yet.</p>`}`;
  }

  function devopsSide(item) {
    let rootId = item.kind === "work" ? String(item.work.id) : (item.row.workId ? String(item.row.workId) : "");
    if (!rootId) {
      return `<h3>Azure DevOps</h3>
        <p>No work item is linked to this use case yet.</p>
        ${canWrite ? `<p><button type="button" class="btn" data-act="create-case">Create the work item</button></p>` : `<p class="note">Creating one needs Work Items, Read &amp; write.</p>`}
        <p class="note" id="pm-work-note" aria-live="polite"></p>`;
    }
    const rootNode = nodeOf(rootId);
    if (!rootNode) {
      const loading = !trees.old || !trees.new;
      return `<h3>Azure DevOps</h3>
        <p class="note">${loading ? "Reading the Azure DevOps hierarchy…" : "Work item " + esc(rootId) + " is not in the saved hierarchy. Sync from Azure DevOps under DevOps tools to read it again."}</p>
        ${item.kind === "case" && item.row.devops ? `<p><a href="${esc(item.row.devops)}" target="_blank" rel="noopener">Open ${esc(rootId)} in Azure DevOps</a></p>` : ""}`;
    }
    const focusId = drill[item.id] && nodeOf(drill[item.id]) ? String(drill[item.id]) : rootId;
    const node = nodeOf(focusId);
    ensureDetail(node);
    return `<h3>Azure DevOps</h3>
      ${focusId !== rootId ? `<p class="crumb pm-crumb">${crumbs(rootId, focusId)}</p>` : ""}
      <h4 class="pm-focus-title">${esc(node.type || "Work item")} ${esc(node.id)} · ${esc(node.title)}</h4>
      ${workPanel(node)}
      <p class="note" id="pm-work-note" aria-live="polite"></p>`;
  }

  function detail(item) {
    return `<div class="pm-open">
      <button type="button" class="pm-close" data-act="close" aria-label="Close ${esc(item.title)}">×</button>
      <header class="pm-head">${item.kind === "case" ? caseHead(item) : workHead(item)}</header>
      <section class="pm-main">${item.kind === "case" ? caseMain(item) : workMain(item)}</section>
      <section class="pm-devops">${devopsSide(item)}</section>
    </div>`;
  }

  /* ---------- Opening and placing ---------- */

  const PEEK = 72;
  const PEEK_MAX = 150;

  /**
   * Size the open rectangle to the board, less room for the rectangle just
   * before it and the one just after it, then scroll so both stay in view.
   */
  function placeOpen(article, smooth) {
    const board = document.getElementById("pm-board");
    board.classList.add("has-open");
    // Narrow screens scroll the whole page instead of the board.
    const own = getComputedStyle(document.body).overflowY === "hidden";
    const scroller = own ? board : document.scrollingElement;
    const all = [...board.querySelectorAll(".pm-item")];
    const at = all.indexOf(article);
    const prev = at > 0 ? all[at - 1] : null;
    const next = at >= 0 && at < all.length - 1 ? all[at + 1] : null;
    const viewTop = own ? board.getBoundingClientRect().top : 0;
    const offset = (el) => el.getBoundingClientRect().top - viewTop + scroller.scrollTop;
    const room = own ? board.clientHeight : window.innerHeight;
    const top = offset(article);
    let above = prev ? Math.min(PEEK_MAX, top - offset(prev) + 2) : 0;
    if (prev && top - offset(prev) + 2 > PEEK_MAX) above = PEEK;
    article.style.height = Math.max(360, room - above - PEEK) + "px";
    if (next) {
      const below = next.getBoundingClientRect().bottom - article.getBoundingClientRect().top + above;
      if (below > room) article.style.height = Math.max(360, parseFloat(article.style.height) - (below - room) - 4) + "px";
    }
    scroller.scrollTo({ top: Math.max(0, top - above), behavior: smooth ? "smooth" : "auto" });
  }

  function closeArticle(article) {
    const item = findItem(article.dataset.id);
    article.classList.remove("is-open");
    article.setAttribute("aria-expanded", "false");
    article.style.height = "";
    article.innerHTML = item ? summary(item) : "";
  }

  function open(id, smooth) {
    if (openId === id) return;
    compose = null;
    const previous = openId ? articleOf(openId) : null;
    if (previous) closeArticle(previous);
    const item = findItem(id);
    const article = articleOf(id);
    if (!item || !article) {
      openId = "";
      rememberUrl();
      return;
    }
    openId = id;
    article.classList.add("is-open");
    article.setAttribute("aria-expanded", "true");
    article.innerHTML = detail(item);
    bindOpen(article);
    placeOpen(article, smooth !== false);
    rememberUrl();
    if (V.setTitle) V.setTitle(item.title + " — Project management — VASC Pivot");
  }

  function close() {
    if (!openId) return;
    const article = articleOf(openId);
    openId = "";
    compose = null;
    document.getElementById("pm-board").classList.remove("has-open");
    if (article) {
      closeArticle(article);
      article.focus({ preventScroll: true });
      article.scrollIntoView({ block: "nearest" });
    }
    rememberUrl();
    if (V.setTitle) V.setTitle("Project management — VASC Pivot");
  }

  /** Re-draw the open rectangle without moving the board. */
  function refreshOpen() {
    if (!openId) return;
    const article = articleOf(openId);
    const item = findItem(openId);
    if (!article || !item) return;
    const scrolls = [...article.querySelectorAll(".pm-main, .pm-devops")].map((el) => el.scrollTop);
    rememberDraft();
    P.keepFocus(article, () => {
      article.innerHTML = detail(item);
    });
    [...article.querySelectorAll(".pm-main, .pm-devops")].forEach((el, index) => { el.scrollTop = scrolls[index] || 0; });
    bindOpen(article);
  }

  function workNote(text) {
    const box = document.getElementById("pm-work-note");
    if (box) box.textContent = text || "";
  }

  function bindOpen(article) {
    const item = findItem(article.dataset.id);
    if (!item) return;
    const steps = article.querySelector(".pm-steps");
    if (steps) {
      const list = stepsOf(item);
      P.bind(steps, list, (index, workId) => {
        if (item.kind === "work") {
          if (workId) {
            drill[item.id] = workId;
            refreshOpen();
          }
          return;
        }
        saveSteps(item, list, index);
      });
    }
    const workForm = article.querySelector("#pm-work");
    if (workForm) {
      workForm.addEventListener("input", rememberDraft);
      workForm.addEventListener("submit", (event) => {
        event.preventDefault();
        saveWork(workForm);
      });
    }
    const composeEl = article.querySelector("#pm-compose");
    if (composeEl) {
      composeEl.addEventListener("input", rememberCompose);
      composeEl.addEventListener("submit", (event) => {
        event.preventDefault();
        saveCompose(composeEl);
      });
    }
  }

  /* ---------- Saving ---------- */

  function saveSteps(item, list, index) {
    const row = item.row;
    const ticks = P.ticksFor(list, index);
    const before = Object.assign({}, (ST.statusRow(row) || {}).features || {});
    const note = () => document.getElementById("pm-step-note");
    if (!row.status) {
      const line = note();
      if (line) line.textContent = "This use case is not on the Project Scores list yet, so its stages cannot be saved here.";
      return;
    }
    row.status.features = ticks;
    refreshOpen();
    let line = note();
    if (line) line.textContent = "Saving…";
    ST.save(row.id, { features: ticks }).then(() => {
      refreshOpen();
      line = note();
      const done = Object.values(ticks).filter(Boolean).length;
      if (line) line.textContent = "Saved. " + done + " of 6 done.";
    }).catch((error) => {
      row.status.features = before;
      refreshOpen();
      line = note();
      if (line) line.textContent = error.login ? "Sign in to set the stages." : error.message;
      if (error.login) V.promptLogin();
    });
  }

  function saveStatus(picker) {
    const id = picker.getAttribute("data-status-id");
    const want = picker.value;
    const note = document.getElementById("pm-status-note");
    picker.disabled = true;
    if (note) note.textContent = "Saving…";
    ST.save(id, { projectStatus: want }).then(() => {
      picker.disabled = false;
      renderBoard();
      const again = document.getElementById("pm-status-note");
      if (again) again.textContent = want ? "Status set to " + want + "." : "Status cleared.";
    }).catch((error) => {
      picker.disabled = false;
      if (note) note.textContent = error.login ? "Sign in to change a status." : error.message;
      if (error.login) V.promptLogin();
    });
  }

  function rememberDraft() {
    const form = document.getElementById("pm-work");
    if (!form) return;
    const hit = detailCache.get(form.dataset.id) || {};
    hit.draft = {
      title: form.elements.title.value,
      state: form.elements.state.value,
      description: form.elements.description.value,
      hoursNeeded: form.elements.hoursNeeded.value,
      hoursLeft: form.elements.hoursLeft.value,
      hoursUsed: form.elements.hoursUsed.value
    };
    detailCache.set(form.dataset.id, hit);
  }

  function saveWork(form) {
    rememberDraft();
    const id = form.dataset.id;
    const node = nodeOf(id);
    const title = form.elements.title.value.trim();
    if (!title) {
      workNote("Give the work item a title.");
      return;
    }
    const button = form.querySelector("[type=submit]");
    button.disabled = true;
    workNote("Saving…");
    V.devops("update", null, {
      id: id,
      title: title,
      state: form.elements.state.value,
      description: form.elements.description.value,
      hoursNeeded: form.elements.hoursNeeded.value.trim(),
      hoursLeft: form.elements.hoursLeft.value.trim(),
      hoursUsed: form.elements.hoursUsed.value.trim()
    }).then((data) => {
      const item = data.item || {};
      detailCache.set(String(id), { status: "ready", item: item });
      if (node) Object.assign(node, {
        title: item.title || title,
        state: item.state || node.state,
        description: item.description != null ? item.description : node.description,
        hoursNeeded: item.hoursNeeded != null ? item.hoursNeeded : node.hoursNeeded,
        hoursLeft: item.hoursLeft != null ? item.hoursLeft : node.hoursLeft,
        hoursUsed: item.hoursUsed != null ? item.hoursUsed : node.hoursUsed
      });
      forgetTree(treeOf(id) ? treeOf(id).key : "old");
      refreshSummaryOf(openId);
      refreshOpen();
      workNote("Saved.");
    }).catch((error) => {
      button.disabled = false;
      workNote(error.message);
      if (error.login) V.promptWrite();
    });
  }

  function refreshSummaryOf(id) {
    const board = document.getElementById("pm-board");
    if (!board) return;
    const outlineBox = document.getElementById("pm-outline");
    if (outlineBox) outlineBox.innerHTML = outline();
    const item = findItem(id);
    if (item && item.stage !== (articleOf(id) && articleOf(id).closest(".pm-lane") && articleOf(id).closest(".pm-lane").dataset.lane) && groupBy === "stage") {
      renderBoard();
    }
  }

  function rememberCompose() {
    const form = document.getElementById("pm-compose");
    if (!form || !compose) return;
    ["title", "state", "description", "hoursNeeded", "hoursLeft", "hoursUsed"].forEach((key) => {
      compose[key] = form.elements[key].value;
    });
  }

  function startCompose(node) {
    compose = {
      parentId: String(node.id),
      parentTitle: node.title || "",
      type: childType(node),
      title: "",
      state: "New",
      description: "",
      hoursNeeded: "",
      hoursLeft: "",
      hoursUsed: ""
    };
    refreshOpen();
    const field = document.querySelector("#pm-compose [name=title]");
    if (field) field.focus();
  }

  function saveCompose(form) {
    rememberCompose();
    const title = compose.title.trim();
    if (!title) {
      workNote("Give the work item a title.");
      return;
    }
    const parent = nodeOf(compose.parentId);
    const tree = treeOf(compose.parentId);
    const button = form.querySelector("[type=submit]");
    button.disabled = true;
    workNote("Creating it in Azure DevOps…");
    V.devops("create", null, {
      title: title,
      type: compose.type,
      parent: "wit:" + compose.parentId,
      project: tree ? tree.key : "old",
      state: compose.state,
      description: compose.description,
      hoursNeeded: compose.hoursNeeded.trim(),
      hoursLeft: compose.hoursLeft.trim(),
      hoursUsed: compose.hoursUsed.trim()
    }).then((data) => {
      const item = data.item || {};
      if (tree && item.id) {
        tree.items.push({
          id: item.id,
          title: item.title || title,
          type: item.type || compose.type,
          state: item.state || compose.state,
          parentId: parent ? parent.id : null,
          url: item.url || "",
          description: item.description || compose.description,
          hoursNeeded: item.hoursNeeded,
          hoursLeft: item.hoursLeft,
          hoursUsed: item.hoursUsed
        });
        indexTree(tree.key, tree.items);
        rememberTree(tree.key, tree.items, []);
      }
      compose = null;
      refreshOpen();
      workNote("Added " + (item.title || title) + ".");
    }).catch((error) => {
      button.disabled = false;
      workNote(error.message);
      if (error.login) V.promptWrite();
    });
  }

  function createForCase(item) {
    const row = item.row;
    const parts = [row.overview, row.problem, row.outcome, row.stakeholder ? "Stakeholder: " + row.stakeholder : "", row.nextStep ? "Next step: " + row.nextStep : ""].filter(Boolean);
    workNote("Creating the work item…");
    V.devops("create", null, {
      title: row.title,
      type: "User Story",
      description: parts.join("\n\n"),
      case: row.id,
      parent: row.orgId ? "org:" + row.orgId : ""
    }).then((data) => {
      const made = data.item || {};
      if (made.id) {
        row.workId = String(made.id);
        row.devops = made.url || "";
        row.workItems = [Number(made.id)];
        if (trees.old) {
          trees.old.items.push({ id: made.id, title: made.title || row.title, type: made.type || "User Story", state: made.state || "New", parentId: null, url: made.url || "" });
          indexTree("old", trees.old.items);
        }
      }
      refreshOpen();
      workNote("Created " + (made.id || "the work item") + ".");
    }).catch((error) => {
      workNote(error.message);
      if (error.login) V.promptWrite();
    });
  }

  function subtreeIds(workId) {
    const out = [];
    const walk = (id) => {
      out.push(String(id));
      childrenOf(id).forEach((kid) => walk(kid.id));
    };
    walk(workId);
    return out;
  }

  function askBox(options) {
    return new Promise((resolve) => {
      const phrase = options.phrase || "";
      const kids = options.children || 0;
      const root = document.createElement("div");
      root.className = "vasc-ask";
      root.innerHTML = `<form class="vasc-ask-card" role="dialog" aria-modal="true">
        <h2>${esc(options.title || "Confirm")}</h2>
        <p>${esc(options.body || "")}</p>
        ${phrase ? `<label>Type ${esc(phrase)} <input name="phrase" type="text" autocomplete="off"></label>` : ""}
        ${kids > 0 ? `<label class="vasc-ask-check"><input name="children" type="checkbox"> ${esc(options.childLabel || "")}</label>` : ""}
        <p class="note vasc-ask-error" hidden></p>
        <div class="vasc-ask-actions">
          <button type="button" class="btn" data-ask="cancel">Cancel</button>
          <button type="submit" class="btn${options.danger ? " danger" : ""}">${esc(options.ok || "Confirm")}</button>
        </div>
      </form>`;
      document.body.appendChild(root);
      const form = root.querySelector("form");
      const finish = (value) => {
        if (root.parentNode) root.remove();
        resolve(value);
      };
      form.addEventListener("submit", (event) => {
        event.preventDefault();
        if (phrase && (form.elements.phrase.value || "") !== phrase) {
          const error = form.querySelector(".vasc-ask-error");
          error.hidden = false;
          error.textContent = "Type the phrase to continue.";
          form.elements.phrase.focus();
          return;
        }
        finish({ children: !!(form.elements.children && form.elements.children.checked) });
      });
      form.querySelector("[data-ask=cancel]").addEventListener("click", () => finish(null));
      root.addEventListener("click", (event) => {
        if (event.target === root) finish(null);
      });
      form.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
          event.preventDefault();
          event.stopPropagation();
          finish(null);
        }
      });
      const focusEl = form.elements.phrase || form.querySelector("[type=submit]");
      if (focusEl) focusEl.focus();
    });
  }

  function transfer(node, toKey) {
    const label = PROJECT_NAME[toKey];
    const kids = subtreeIds(node.id).length - 1;
    const scoring = /scoring refactor/i.test(node.title || "");
    askBox({
      title: "Move work item",
      body: "Move \"" + node.title + "\" (" + node.id + ") into " + label + "? It keeps its number, title, and description. Nothing is deleted.",
      phrase: scoring ? "move scoring refactor" : "",
      children: kids,
      childLabel: "Also move the " + kids + " item" + (kids === 1 ? "" : "s") + " under it. They move with it and are not deleted.",
      ok: "Move"
    }).then((answer) => {
      if (!answer) {
        workNote("Left where it is.");
        return;
      }
      workNote("Moving into " + label + "…");
      V.devops("transfer", null, {
        id: String(node.id),
        to: toKey,
        confirm: String(node.id),
        acknowledge: scoring ? "move scoring refactor" : "",
        includeChildren: !!answer.children
      }).then((data) => {
        toolNote = data.note || "Moved.";
        forgetTree("old");
        forgetTree("new");
        sources.add(toKey);
        const keep = "wit:" + node.id;
        return Promise.all([loadTree("old", false), loadTree("new", false)]).then(() => {
          if (openId && openId !== keep) openId = "";
          if (!extras.includes(keep)) extras.push(keep);
          renderBoard();
        });
      }).catch((error) => {
        workNote(error.message || "The move did not happen.");
        if (error.login) V.promptWrite();
      });
    });
  }

  function remove(item, node) {
    const kids = subtreeIds(node.id).length - 1;
    const scoring = /scoring refactor/i.test(node.title || "");
    askBox({
      title: "Delete work item",
      body: "Delete \"" + node.title + "\" (" + node.id + ")? This permanently destroys it in Azure DevOps. It cannot be restored.",
      phrase: scoring ? "remove scoring refactor" : "",
      children: kids,
      childLabel: "Also destroy the " + kids + " item" + (kids === 1 ? "" : "s") + " under it.",
      ok: "Delete",
      danger: true
    }).then((answer) => {
      if (!answer) {
        workNote("Left in Azure DevOps.");
        return;
      }
      workNote("Deleting " + node.title + "…");
      V.devops("remove", null, {
        id: String(node.id),
        confirm: String(node.id),
        acknowledge: scoring ? "remove scoring refactor" : "",
        includeChildren: !!answer.children
      }).then((data) => {
        const gone = new Set((data.removed || [node.id]).map(String));
        const tree = treeOf(node.id);
        if (tree) {
          tree.items = tree.items.filter((entry) => !gone.has(String(entry.id)));
          indexTree(tree.key, tree.items);
          rememberTree(tree.key, tree.items, []);
        }
        toolNote = data.note || "Deleted.";
        if (item.kind === "work" && gone.has(String(item.work.id))) {
          openId = "";
          renderBoard();
          return;
        }
        if (drill[item.id] && gone.has(String(drill[item.id]))) drill[item.id] = node.parentId ? String(node.parentId) : "";
        refreshOpen();
        workNote(toolNote);
      }).catch((error) => {
        workNote(error.message);
        if (error.login) V.promptWrite();
      });
    });
  }

  function makeDummy() {
    askBox({
      title: "Create test epic",
      body: "Create a test epic, feature, and task in the old VASC project? If that test epic already exists, it is left as it is. Nothing else is changed.",
      ok: "Create"
    }).then((answer) => {
      if (!answer) return;
      toolNote = "Creating the test epic in VASC…";
      renderBoard();
      V.devops("dummy", null, {}).then((data) => {
        toolNote = data.note || "Test epic is in VASC.";
        forgetTree("old");
        sources.add("old");
        if (data.id) extras.push("wit:" + data.id);
        return loadTree("old", true).then(renderBoard);
      }).catch((error) => {
        toolNote = error.message;
        renderBoard();
      });
    });
  }

  function sync() {
    toolNote = "Reading Azure DevOps…";
    renderBoard();
    detailCache.clear();
    Promise.all([loadTree("new", true), loadTree("old", true)]).then(() => {
      toolNote = "Read from Azure DevOps.";
      renderBoard();
    });
  }

  /* ---------- Events ---------- */

  function currentFocus(item) {
    const rootId = item.kind === "work" ? String(item.work.id) : String(item.row.workId || "");
    return drill[item.id] && nodeOf(drill[item.id]) ? nodeOf(drill[item.id]) : nodeOf(rootId);
  }

  function handleAct(act, button, item) {
    if (act === "close") return close();
    if (act === "sync") return sync();
    if (act === "dummy") return makeDummy();
    if (act === "write") return V.promptWrite();
    if (!item) return;
    const node = currentFocus(item);
    if (act === "create-case") return createForCase(item);
    if (act === "compose-cancel") {
      compose = null;
      return refreshOpen();
    }
    if (!node) return;
    if (act === "add") return startCompose(node);
    if (act === "transfer") return transfer(node, button.getAttribute("data-to") === "old" ? "old" : "new");
    if (act === "delete") return remove(item, node);
  }

  stage.innerHTML = `
    <section class="lede pm-lede">
      <h1>Project management</h1>
      <p>Each rectangle is a project at the stage it has reached in the Projects and Team Management outline. Open one to work on it here: it grows in place, and the projects above and below stay one click away.</p>
      <nav class="pm-tabs" aria-label="Project management views"><a href="pm.html" aria-current="page">Business management</a><a href="projects.html">List and scoring</a></nav>
    </section>
    <div class="pm-outline" id="pm-outline"></div>
    <div class="pm-tools" id="pm-tools"></div>
    <div class="pm-board" id="pm-board"></div>`;

  const board = document.getElementById("pm-board");

  board.addEventListener("click", (event) => {
    const article = event.target.closest(".pm-item");
    if (!article) return;
    if (!article.classList.contains("is-open")) {
      if (event.target.closest("a")) return;
      event.preventDefault();
      open(article.dataset.id, true);
      return;
    }
    const item = findItem(article.dataset.id);
    const drillTo = event.target.closest("[data-drill]");
    if (drillTo && item) {
      drill[item.id] = drillTo.getAttribute("data-drill");
      compose = null;
      refreshOpen();
      return;
    }
    const act = event.target.closest("[data-act]");
    if (act) handleAct(act.getAttribute("data-act"), act, item);
  });

  board.addEventListener("keydown", (event) => {
    const article = event.target.closest && event.target.closest(".pm-item");
    if (!article) return;
    if (event.target === article && !article.classList.contains("is-open") && (event.key === "Enter" || event.key === " ")) {
      event.preventDefault();
      open(article.dataset.id, true);
    }
  });

  board.addEventListener("change", (event) => {
    const picker = event.target.closest("select.status-select[data-status-id]");
    if (picker) saveStatus(picker);
  });

  document.getElementById("pm-tools").addEventListener("click", (event) => {
    const group = event.target.closest("[data-group]");
    if (group) {
      groupBy = group.getAttribute("data-group") === "org" ? "org" : "stage";
      renderBoard();
      return;
    }
    const source = event.target.closest("[data-source]");
    if (source) {
      const key = source.getAttribute("data-source");
      if (sources.has(key) && sources.size > 1) sources.delete(key);
      else sources.add(key);
      renderBoard();
      return;
    }
    const act = event.target.closest("[data-act]");
    if (act) handleAct(act.getAttribute("data-act"), act, null);
  });

  document.getElementById("pm-outline").addEventListener("click", (event) => {
    const button = event.target.closest("[data-stage]");
    if (!button) return;
    const id = button.getAttribute("data-stage");
    stageFilter = stageFilter === id ? "" : id;
    renderBoard();
    board.scrollTop = 0;
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape" || !openId || document.querySelector(".vasc-ask")) return;
    if (event.target.closest && event.target.closest(".status-picker.is-open, input, textarea, select")) return;
    close();
  });

  window.addEventListener("resize", () => {
    const article = openId ? articleOf(openId) : null;
    if (article) placeOpen(article, false);
  });

  V.searchChart = (value) => {
    query = String(value || "");
    renderBoard();
  };

  /** A work item named in the address opens inside the project it belongs to. */
  function openWanted() {
    if (!wantedId) return false;
    if (wantedId.indexOf("case:") === 0) {
      if (!findItem(wantedId)) {
        sources.add("case");
        stageFilter = "";
        renderBoard();
      }
      open(wantedId, false);
      wantedId = "";
      return true;
    }
    const workId = wantedId.replace(/^wit:/, "");
    const tree = treeOf(workId);
    if (!tree) return false;
    const chain = chainOf(workId);
    const owner = chain.find((node) => isProject(tree, node));
    const host = owner || chain[0];
    sources.add(tree.key);
    stageFilter = "";
    const hostId = "wit:" + host.id;
    if (!owner && !extras.includes(hostId)) extras.push(hostId);
    if (String(host.id) !== String(workId)) drill[hostId] = String(workId);
    renderBoard();
    open(hostId, false);
    wantedId = "";
    return true;
  }

  renderBoard();
  Promise.all([S.load(), V.caseLinks(), ST.load()]).then(() => {
    renderBoard();
    if (wantedId.indexOf("case:") === 0) openWanted();
  });
  V.devops("access").then((data) => {
    canWrite = !!data.canWrite;
  }).catch(() => {
    canWrite = false;
  }).then(() => {
    if (openId) refreshOpen();
    const box = document.getElementById("pm-tools");
    if (box) box.innerHTML = tools();
  });
  loadTree("new", false).then(() => {
    renderBoard();
    return loadTree("old", false);
  }).then(() => {
    renderBoard();
    if (wantedId && !openWanted()) {
      toolNote = "Work item " + wantedId.replace(/^wit:/, "") + " is not in the saved Azure DevOps hierarchy. Sync under DevOps tools to read it again.";
      wantedId = "";
      renderBoard();
    }
  });
  });
})();
