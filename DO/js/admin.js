(function () {
  const V = window.VASC;
  const $ = (id) => document.getElementById(id);
  const esc = V.esc;

  function json(url, opts) {
    return fetch(url, Object.assign({ cache: "no-store" }, opts || {})).then(async (res) => {
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) {
        const error = new Error(data.error || "The request did not finish (HTTP " + res.status + ").");
        error.status = res.status;
        error.data = data;
        throw error;
      }
      return data;
    });
  }

  function post(url, body, headers) {
    return json(url, {
      method: "POST",
      headers: Object.assign({ "Content-Type": "application/json" }, headers || {}),
      body: JSON.stringify(body || {})
    });
  }

  function ago(seconds) {
    if (seconds == null || !isFinite(seconds)) return "never";
    const s = Math.max(0, Math.round(seconds));
    if (s < 90) return s + " s ago";
    if (s < 5400) return Math.round(s / 60) + " min ago";
    if (s < 129600) return Math.round(s / 3600) + " h ago";
    return Math.round(s / 86400) + " days ago";
  }

  function savedText(row, now) {
    if (!row || !row.present || !row.saved) return "Not saved yet";
    return ago(now - row.saved);
  }

  /* ---------- Gate ---------- */

  function gate() {
    const note = $("admin-gate-note");
    // devops.php?action=access refreshes an expired Microsoft sign-in before the identity check.
    return fetch("api/devops.php?action=access", { cache: "no-store" }).catch(() => null)
      .then(() => json("api/admin.php?action=whoami"))
      .then((who) => {
        if (who.admin) return who;
        const back = "connect.html?return=admin.html";
        if (!who.signedIn) {
          note.innerHTML = `Admin tools need your own Azure DevOps sign-in. <a href="${back}">Sign in</a>`;
        } else if (!who.configured) {
          note.textContent = "Sign in with a VASC Azure DevOps account to use admin tools.";
        } else {
          note.textContent = `${who.email || "This account"} is not a VASC admin.`;
        }
        return null;
      })
      .catch((error) => {
        note.textContent = error.message;
        return null;
      });
  }

  /* ---------- Data source sync ---------- */

  const SOURCES = [
    {
      id: "daily",
      label: "Azure DevOps: VASC",
      detail: "Work items, people, hours, links, apps and machines",
      url: "api/devops.php?action=tree&fresh=1",
      contents: (o) => {
        const d = o.devops.daily;
        return d.present ? `${d.items} items, ${d.people} people, ${d.links} links, ${d.apps} apps` : "";
      },
      saved: (o) => savedText(o.devops.daily, o.now)
    },
    {
      id: "new",
      label: "Azure DevOps: Projects and Team Management",
      detail: "The new DevOps project",
      url: "api/devops.php?action=tree&project=new&fresh=1",
      contents: (o) => (o.devops.new.present ? `${o.devops.new.items} items` : ""),
      saved: (o) => savedText(o.devops.new, o.now)
    },
    {
      id: "apps",
      label: "Power Apps list",
      detail: "App addresses from DevOps and Power Platform",
      url: "api/devops.php?action=apps&fresh=1",
      contents: (o) => (o.devops.daily.present ? `${o.devops.daily.apps} apps` : ""),
      saved: (o) => savedText(o.devops.daily, o.now)
    },
    {
      id: "teams",
      label: "Teams presence",
      detail: "Cached for 45 s in this browser; Sync asks Teams again on the next chart view",
      post: "api/admin.php?action=teams-reset",
      contents: (o) => (o.teams.signedIn ? `Connected, ${o.teams.presenceRows} people cached` : "Not connected in this browser"),
      saved: (o) => (o.teams.presenceAt ? ago(o.now - o.teams.presenceAt) : "Nothing cached"),
      extra: (o) => (o.teams.signedIn ? "" : ' <a href="connect.html?return=admin.html">Connect Teams</a>')
    }
  ];

  let overview = null;

  function drawSources() {
    const body = $("admin-sync-rows");
    if (!overview) return;
    body.innerHTML = SOURCES.map((src) => `
      <tr data-source="${src.id}">
        <td><strong>${esc(src.label)}</strong><br><small>${esc(src.detail)}</small></td>
        <td>${esc(src.saved(overview))}${overview.devops.building && src.url ? "<br><small>A rebuild is running</small>" : ""}</td>
        <td>${esc(src.contents(overview))}${src.extra ? src.extra(overview) : ""}</td>
        <td><button class="btn admin-btn" type="button" data-sync="${src.id}">Sync now</button></td>
      </tr>`).join("");
  }

  function loadOverview() {
    return json("api/admin.php?action=overview").then((data) => {
      overview = data;
      $("admin-who").textContent = `Signed in as ${data.who.name || data.who.email} (${data.who.email}).`;
      drawSources();
    }).catch((error) => {
      $("admin-sync-note").textContent = error.message;
    });
  }

  $("admin-sync-rows").addEventListener("click", (event) => {
    const button = event.target.closest("button[data-sync]");
    if (!button) return;
    const src = SOURCES.find((row) => row.id === button.dataset.sync);
    if (!src) return;
    const note = $("admin-sync-note");
    button.disabled = true;
    button.textContent = "Syncing…";
    note.textContent = `${src.label}: reading the source again. This can take a few minutes.`;
    const run = src.post ? post(src.post, {}) : json(src.url);
    run.then((data) => {
      note.textContent = `${src.label}: done. ${data.note || ""}`.trim();
    }).catch((error) => {
      note.innerHTML = `${esc(src.label)}: ${esc(error.message)}${error.status === 401 ? ' <a href="connect.html?return=admin.html">Sign in</a>' : ""}`;
    }).finally(() => {
      loadOverview();
    });
  });

  /* ---------- Scorecard and SharePoint ---------- */

  const SP = "api/scorecard-sync.php";
  let spCsrf = "";
  let spPlan = null;

  function spCall(action, body) {
    const headers = spCsrf ? { "X-CSRF-Token": spCsrf } : {};
    return post(SP + "?action=" + encodeURIComponent(action), Object.assign({ action: action }, body || {}), headers);
  }

  function spError(error) {
    const why = {
      401: "Sign in with Microsoft to sync SharePoint.",
      403: "Your Microsoft account does not have the Pivot admin role for SharePoint sync.",
      409: "A sync is already running. Try again when it finishes.",
      423: "SharePoint sync is in dry-run mode, so nothing was written. The preview above is what would change."
    };
    return why[error.status] || error.message;
  }

  function spStatus() {
    const note = $("admin-sp-status");
    const plan = $("admin-sp-plan");
    return spCall("status").then((data) => {
      spCsrf = data.csrf || data.csrfToken || spCsrf;
      const bits = [];
      bits.push(data.enabled ? "Sync is on" : "Sync is off on this server");
      if (data.mode) bits.push(data.mode === "dry-run" ? "dry-run mode (nothing is written)" : "mode: " + data.mode);
      if (data.lastRun) bits.push("last run " + (data.lastRun.at || data.lastRun.finished || data.lastRun.runId || ""));
      if (data.openConflicts) bits.push(data.openConflicts + " open conflicts");
      if (data.locked) bits.push("a sync is running now");
      note.textContent = bits.join(", ") + ".";
      plan.disabled = !data.enabled || !!data.locked;
    }).catch((error) => {
      if (error.status === 404 || (error.status === 400 && !error.data.error)) {
        note.textContent = "SharePoint sync is not installed on this server yet.";
      } else {
        note.textContent = spError(error);
      }
      plan.disabled = true;
    });
  }

  function drawPlan(plan) {
    const view = $("admin-sp-plan-view");
    const counts = plan.counts || {};
    const ops = Array.isArray(plan.operations) ? plan.operations : [];
    const writes = (counts.push || 0) + (counts.create || 0);
    const total = writes + (counts.pull || 0);
    const countLine = ["pull", "push", "create", "conflict", "skip"].map((key) => `${counts[key] || 0} ${key}`).join(", ");
    view.innerHTML = `
      <p><strong>Preview:</strong> ${esc(countLine)}.</p>
      ${ops.length ? `<table class="admin-table"><thead><tr><th>Item</th><th>Action</th><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>${ops.slice(0, 200).map((op) => `
        <tr class="${op.type === "conflict" || op.action === "conflict" ? "admin-conflict" : ""}">
          <td>${esc(op.title || op.itemId || op.id || "")}</td>
          <td>${esc(op.action || op.type || "")}</td>
          <td>${esc(op.field || (op.fields ? Object.keys(op.fields).join(", ") : ""))}</td>
          <td>${esc(op.before == null ? "" : typeof op.before === "object" ? JSON.stringify(op.before) : op.before)}</td>
          <td>${esc(op.after == null ? "" : typeof op.after === "object" ? JSON.stringify(op.after) : op.after)}</td>
        </tr>`).join("")}</tbody></table>${ops.length > 200 ? `<p>${ops.length - 200} more not shown.</p>` : ""}` : "<p>No changes.</p>"}`;
    const apply = $("admin-sp-apply");
    apply.hidden = total === 0;
    apply.textContent = `Apply ${total} change${total === 1 ? "" : "s"}`;
    $("admin-sp-cancel").hidden = false;
  }

  function clearPlan() {
    spPlan = null;
    $("admin-sp-plan-view").innerHTML = "";
    $("admin-sp-apply").hidden = true;
    $("admin-sp-cancel").hidden = true;
  }

  $("admin-sp-plan").addEventListener("click", () => {
    const button = $("admin-sp-plan");
    const note = $("admin-sp-status");
    clearPlan();
    button.disabled = true;
    note.textContent = "Reading SharePoint and the scorecard. Nothing is written yet…";
    spCall("plan", { lists: ["scorecard"], direction: "both" }).then((plan) => {
      spPlan = plan;
      note.textContent = "Check the preview, then apply it or discard it.";
      drawPlan(plan);
    }).catch((error) => {
      note.textContent = spError(error);
    }).finally(() => {
      button.disabled = false;
    });
  });

  $("admin-sp-apply").addEventListener("click", () => {
    if (!spPlan) return;
    const apply = $("admin-sp-apply");
    const note = $("admin-sp-status");
    if (!window.confirm(apply.textContent + " to SharePoint and the scorecard? A backup is kept before each change.")) return;
    apply.disabled = true;
    note.textContent = "Applying the previewed changes…";
    spCall("apply", { planId: spPlan.planId, hash: spPlan.hash, confirm: true }).then((result) => {
      note.textContent = `Applied ${result.applied || 0}. ${result.conflicts || 0} conflicts, ${result.skipped || 0} skipped.${result.runId ? " Run " + result.runId + "." : ""}`;
      clearPlan();
      spStatus();
    }).catch((error) => {
      note.textContent = spError(error);
    }).finally(() => {
      apply.disabled = false;
    });
  });

  $("admin-sp-cancel").addEventListener("click", () => {
    clearPlan();
    spStatus();
  });

  /* ---------- Abbreviations ---------- */

  const form = $("admin-abbr-form");
  let added = [];
  let editing = null;

  function abbrNote(text) {
    $("admin-abbr-note").textContent = text || "";
  }

  function drawAdded() {
    const q = $("admin-abbr-filter").value.trim().toLowerCase();
    const rows = added.filter((row) => !q || [row.abbr, row.frAbbr, row.en, row.fr].some((v) => String(v || "").toLowerCase().includes(q)));
    $("admin-abbr-rows").innerHTML = rows.length ? rows.map((row) => `
      <tr${editing && editing.index === row.index ? ' class="admin-editing"' : ""}>
        <td><strong>${esc(row.abbr)}</strong></td>
        <td>${esc(row.en)}</td>
        <td lang="fr"><strong>${esc(row.frAbbr)}</strong></td>
        <td lang="fr">${esc(row.fr)}</td>
        <td><small>${esc(row.source)}</small></td>
        <td class="admin-row-actions">
          <button class="btn" type="button" data-edit="${row.index}">Edit</button>
          <button class="btn danger" type="button" data-delete="${row.index}">Delete</button>
        </td>
      </tr>`).join("") : `<tr><td colspan="6">${added.length ? "No added entry matches." : "Nothing has been added on this site yet."}</td></tr>`;
  }

  function loadAdded() {
    return json("api/abbreviations.php?action=list").then((data) => {
      added = data.entries || [];
      drawAdded();
    }).catch((error) => abbrNote(error.message));
  }

  function fillForm(row, isEdit) {
    form.abbr.value = row.abbr || "";
    form.en.value = row.en || "";
    form.frAbbr.value = row.frAbbr || "";
    form.fr.value = row.fr || "";
    editing = isEdit ? row : null;
    $("admin-abbr-save").textContent = isEdit ? "Save" : "Add";
    $("admin-abbr-clear").hidden = !isEdit && !row.abbr && !row.frAbbr;
    drawAdded();
    form.abbr.focus();
  }

  function resetForm() {
    form.reset();
    editing = null;
    $("admin-abbr-save").textContent = "Add";
    $("admin-abbr-clear").hidden = true;
    drawAdded();
  }

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    const body = {
      abbr: form.abbr.value,
      en: form.en.value,
      frAbbr: form.frAbbr.value,
      fr: form.fr.value
    };
    if (editing) {
      body.index = editing.index;
      body.was = { abbr: editing.abbr, frAbbr: editing.frAbbr };
    }
    const save = $("admin-abbr-save");
    save.disabled = true;
    post("api/abbreviations.php?action=save", body).then((data) => {
      abbrNote(`Saved ${data.entry.abbr || data.entry.frAbbr}.`);
      V.forgetAcronyms();
      resetForm();
      return loadAdded();
    }).catch((error) => {
      abbrNote(error.message);
    }).finally(() => {
      save.disabled = false;
    });
  });

  $("admin-abbr-clear").addEventListener("click", () => {
    resetForm();
    abbrNote("");
  });

  $("admin-abbr-filter").addEventListener("input", drawAdded);

  $("admin-abbr-rows").addEventListener("click", (event) => {
    const edit = event.target.closest("button[data-edit]");
    const del = event.target.closest("button[data-delete]");
    if (edit) {
      const row = added.find((r) => r.index === Number(edit.dataset.edit));
      if (row) fillForm(row, true);
      return;
    }
    if (!del) return;
    const row = added.find((r) => r.index === Number(del.dataset.delete));
    if (!row || !window.confirm(`Delete ${row.abbr || row.frAbbr} from the added list?`)) return;
    del.disabled = true;
    post("api/abbreviations.php?action=delete", { index: row.index, was: { abbr: row.abbr, frAbbr: row.frAbbr } }).then(() => {
      abbrNote(`Deleted ${row.abbr || row.frAbbr}.`);
      V.forgetAcronyms();
      if (editing && editing.index === row.index) resetForm();
      return loadAdded();
    }).catch((error) => {
      abbrNote(error.message);
      del.disabled = false;
    });
  });

  let manual = [];
  let manualTimer = 0;

  function drawManual() {
    const q = $("admin-manual-q").value.trim().toLowerCase();
    const body = $("admin-manual-rows");
    if (q.length < 2) {
      body.innerHTML = "";
      return;
    }
    const hits = manual.filter((row) => [row.abbr, row.frAbbr, row.en, row.fr].some((v) => String(v || "").toLowerCase().includes(q))).slice(0, 40);
    body.innerHTML = hits.length ? hits.map((row, i) => `
      <tr>
        <td><strong>${esc(row.abbr)}</strong></td>
        <td>${esc(row.en)}</td>
        <td lang="fr"><strong>${esc(row.frAbbr)}</strong></td>
        <td lang="fr">${esc(row.fr)}</td>
        <td><small>${esc(row.source)}</small></td>
        <td><button class="btn" type="button" data-correct="${i}">Correct</button></td>
      </tr>`).join("") : '<tr><td colspan="6">No manual entry matches.</td></tr>';
    body.hits = hits;
  }

  $("admin-manual-q").addEventListener("input", () => {
    clearTimeout(manualTimer);
    manualTimer = setTimeout(drawManual, 150);
  });

  $("admin-manual-rows").addEventListener("click", (event) => {
    const button = event.target.closest("button[data-correct]");
    const hits = $("admin-manual-rows").hits || [];
    if (!button || !hits[Number(button.dataset.correct)]) return;
    const row = hits[Number(button.dataset.correct)];
    const have = added.find((r) => (row.abbr && r.abbr === row.abbr) || (row.frAbbr && r.frAbbr === row.frAbbr));
    fillForm(have || row, !!have);
    abbrNote(have ? "This entry already has a site copy; edit it here." : "Fix the fields, then Add saves a corrected copy.");
    form.scrollIntoView({ block: "center" });
  });

  /* ---------- Floor scanner ---------- */

  $("admin-floor-open").addEventListener("click", () => {
    const frame = $("admin-floor-frame");
    if (!frame.getAttribute("src")) frame.src = "power_sweeper/public/floorscanner.php";
    frame.hidden = !frame.hidden;
    $("admin-floor-open").textContent = frame.hidden ? "Open here" : "Hide";
  });

  /* ---------- Start ---------- */

  V.ready.then(gate).then((who) => {
    if (!who) return;
    $("admin-gate").hidden = true;
    $("admin-body").hidden = false;
    loadOverview();
    spStatus();
    Promise.all([loadAdded(), V.loadAcronyms()]).then(([, rows]) => {
      const same = (a, b) => a.abbr === b.abbr && a.frAbbr === b.frAbbr && a.en === b.en && a.fr === b.fr;
      manual = rows.filter((row) => !added.some((a) => same(a, row)));
    }).catch(() => {});
  });
})();
