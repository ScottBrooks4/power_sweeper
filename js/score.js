(function (root) {
  const REACH_BANDS = [
    [/l1|org/, 5, "an L1 or the whole organization"],
    [/l2/, 3, "one or more L2s"],
    [/l3|single|less/, 1, "a single L3 or smaller"]
  ];

  function clip(value) {
    const number = Math.round(Number(value));
    if (!isFinite(number)) return 1;
    return Math.max(1, Math.min(5, number));
  }

  function blobOf(row) {
    return [row.title, row.overview, row.problem, row.outcome, row.category, row.notes].filter(Boolean).join(" ").toLowerCase();
  }

  function levelScore(level) {
    const key = String(level || "").toUpperCase();
    if (key === "L0") return 5;
    if (key === "L0.5" || key === "L1") return 4;
    if (key === "L2") return 3;
    if (key === "L3") return 2;
    return 1;
  }

  function strategicValue(level, blob) {
    if (/enterprise|standardiz|governance|defence-wide|defense-wide/.test(blob) && (level === "L0" || level === "L1" || /enterprise/.test(blob))) {
      return { value: 5, why: "The wording is enterprise-wide or about governance, so strategic value is 5." };
    }
    if (/cross-l1|shared service|multi-l2|multiple l2/.test(blob) || level === "L1" || level === "L0.5") {
      return { value: 4, why: "This sits at L1, or the wording is cross-organization, so strategic value is 4." };
    }
    if (level === "L2" || /service/.test(blob)) {
      return { value: 3, why: "Level L2, or a service used by one organization, so strategic value is 3." };
    }
    if (level === "L3" || /dashboard|internal/.test(blob)) {
      return { value: 2, why: "Level L3, or an internal dashboard, so strategic value is 2." };
    }
    return { value: 1, why: "No enterprise wording, and the level is below L3, so strategic value is 1." };
  }

  function businessValue(row, blob) {
    const money = { high: 5, medium: 3, low: 2 };
    const time = { high: 5, medium: 3, low: 1 };
    const financial = money[String(row.financial || "").toLowerCase()];
    const saving = time[String(row.timeSaving || "").toLowerCase()];
    if (financial || saving) {
      const parts = [financial, saving].filter(Boolean);
      const value = clip(parts.reduce((sum, part) => sum + part, 0) / parts.length);
      const bits = [];
      if (financial) bits.push("financial implication " + row.financial);
      if (saving) bits.push("time saving " + row.timeSaving);
      return { value: value, why: "Business value is " + value + " from " + bits.join(" and ") + "." };
    }
    if (/stewardship|compliance|readiness|operational effectiveness|decision-making|decision making/.test(blob)) {
      return { value: 5, why: "The wording is stewardship, compliance, readiness, or decision-making, so business value is 5." };
    }
    if (/efficien|metric|measur|reduced manual|faster decision|save time|accuracy/.test(blob)) {
      return { value: 4, why: "The wording is about efficiency, measurement, or less manual work, so business value is 4." };
    }
    if (/operational/.test(blob)) {
      return { value: 3, why: "The wording is operational, so business value is 3." };
    }
    if (blob.trim().length < 40) {
      return { value: 1, why: "The description is short, so business value stays at 1." };
    }
    return { value: 3, why: "No stronger business wording, so business value stays at 3." };
  }

  function reachValue(row, level, blob) {
    const users = String(row.users || "").trim();
    if (users) {
      const band = REACH_BANDS.find((entry) => entry[0].test(users.toLowerCase()));
      if (band) {
        return { value: band[1], why: "Number of users on the use case is “" + users + "”, read as " + band[2] + ", so reach is " + band[1] + "." };
      }
      const count = parseInt(users.replace(/[^\d]/g, ""), 10);
      if (count > 0) {
        const value = count >= 1000 ? 5 : count >= 200 ? 4 : count >= 50 ? 3 : count >= 10 ? 2 : 1;
        return { value: value, why: "Number of users on the use case is " + count + ", so reach is " + value + "." };
      }
    }
    if (/defence-wide|defense-wide|enterprise-wide|all l1s|dnd\/caf|caf-wide/.test(blob)) {
      return { value: 5, why: "No user count was recorded. The wording is enterprise-wide, so reach is 5." };
    }
    if (level === "L0" || level === "L1" || level === "L0.5") {
      return { value: 4, why: "No user count was recorded. Level " + level + " stands in for reach 4." };
    }
    if (level === "L2" || level === "L3") {
      return { value: 2, why: "No user count was recorded. Level " + level + " stands in for reach 2." };
    }
    return { value: 1, why: "No user count was recorded, and the level is L4 or below, so reach is 1." };
  }

  function firstReading(row) {
    const level = row.level || "";
    const blob = blobOf(row);
    const strategic = strategicValue(level, blob);
    const reach = reachValue(row, level, blob);
    const business = businessValue(row, blob);
    const fromLevel = levelScore(level);
    const score = Math.round((strategic.value + reach.value + business.value + fromLevel) * 10 / 4) / 10;
    return {
      level: level,
      strategic: strategic.value,
      reach: reach.value,
      business: business.value,
      levelScore: fromLevel,
      score: score,
      strategicWhy: strategic.why,
      reachWhy: reach.why,
      businessWhy: business.why
    };
  }

  function scoreCase(row, edit) {
    const first = firstReading(row || {});
    const saved = edit || {};
    const level = saved.level || first.level;
    const strategic = saved.strategic != null ? clip(saved.strategic) : first.strategic;
    const reach = saved.reach != null ? clip(saved.reach) : first.reach;
    const business = saved.business != null ? clip(saved.business) : first.business;
    const fromLevel = levelScore(level);
    return {
      level: level,
      strategic: strategic,
      reach: reach,
      business: business,
      levelScore: fromLevel,
      score: Math.round((strategic + reach + business + fromLevel) * 10 / 4) / 10,
      strategicWhy: saved.strategicWhy || first.strategicWhy,
      reachWhy: saved.reachWhy || first.reachWhy,
      businessWhy: first.businessWhy,
      first: first,
      edited: !!(saved.strategic != null || saved.reach != null || saved.business != null || saved.level || saved.strategicWhy || saved.reachWhy)
    };
  }

  function stageOf(row, work) {
    const state = String(work && work.state || "");
    if (row && row.implemented) return "production";
    if (/^(resolved|closed|done|completed|removed)$/i.test(state)) return "production";
    if (row && row.workId) return "development";
    return "planning";
  }

  function scoreItem(item, level) {
    const blob = [item.title, item.description, item.text, item.area, item.type].filter(Boolean).join(" ").toLowerCase();
    return { strategic: strategicValue(level || "", blob).value };
  }

  function formulaText(scored) {
    const level = scored.level || "—";
    return "(strategic + reach + business + level) / 4\n= (" + scored.strategic + " + " + scored.reach + " + " + scored.business + " + " + scored.levelScore + ") / 4\n= " + scored.score + "\nLevel " + level + " is read as " + scored.levelScore + ".";
  }

  // SOP-001 6.1.3 names three epic states. DO used to say Use case, In progress,
  // Completed; the SOP wording leads and the old word stays as a gloss.
  function stageLabel(stage) {
    if (stage === "production") return "In Production";
    if (stage === "development") return "In Development";
    return "Planning";
  }

  function stageGloss(stage) {
    if (stage === "production") return "completed";
    if (stage === "development") return "in progress";
    return "use case";
  }

  // The six features every project Epic carries, in the order they are worked.
  const FEATURES = [
    ["Project Architecture", /architect/i],
    ["Back-end development", /back[\s-]?end|backend/i],
    ["Front-end development", /front[\s-]?end|frontend/i],
    ["UAT Testing", /\buat\b/i],
    ["Production", /production|deploy/i],
    ["Maintenance", /maintenance/i]
  ];

  /** Which of the six features have a work item under this use case. */
  function featureProgress(row, items) {
    const bag = (row && row.workItems) || [];
    const rows = (items || []).filter((item) => bag.some((id) => String(id) === String(item.id)));
    return FEATURES.map(([name, test]) => ({
      name: name,
      done: rows.some((item) => test.test(String(item.title || "")) && /^(resolved|closed|done|completed)$/i.test(String(item.state || ""))),
      started: rows.some((item) => test.test(String(item.title || "")))
    }));
  }

  const edits = {};
  let loaded = null;

  function remember(id, row) {
    if (row && Object.keys(row).length) edits[id] = row;
    else delete edits[id];
    try { localStorage.setItem("vasc-score-edits", JSON.stringify(edits)); } catch (error) {}
  }

  function load() {
    if (loaded) return loaded;
    try {
      const local = JSON.parse(localStorage.getItem("vasc-score-edits") || "{}");
      if (local && typeof local === "object") Object.assign(edits, local);
    } catch (error) {}
    loaded = fetch("api/scores.php", { cache: "no-store" })
      .then((res) => res.json())
      .then((data) => {
        const saved = data && data.scores;
        if (saved && typeof saved === "object") Object.assign(edits, saved);
      })
      .catch(() => {});
    return loaded;
  }

  function save(id, patch) {
    const next = Object.assign({}, edits[id] || {});
    Object.keys(patch || {}).forEach((key) => {
      const value = patch[key];
      if (value == null || value === "") delete next[key];
      else next[key] = value;
    });
    remember(String(id), next);
    return fetch("api/scores.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(Object.assign({ id: String(id) }, next, {
        strategic: next.strategic != null ? next.strategic : "",
        reach: next.reach != null ? next.reach : "",
        business: next.business != null ? next.business : "",
        level: next.level || "",
        strategicWhy: next.strategicWhy || "",
        reachWhy: next.reachWhy || ""
      }))
    }).then(async (res) => {
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) {
        const error = new Error(data.error || "The score stayed on this browser.");
        error.login = res.status === 401;
        throw error;
      }
      return data;
    });
  }

  root.VASC_SCORE = {
    scoreCase: scoreCase,
    formulaText: formulaText,
    scoreItem: scoreItem,
    stageOf: stageOf,
    stageLabel: stageLabel,
    stageGloss: stageGloss,
    featureProgress: featureProgress,
    features: FEATURES,
    edits: edits,
    load: load,
    save: save
  };

  if (!root.document || root.document.body.dataset.page !== "score") return;

  root.VASC.ready.then(() => {
    const V = root.VASC;
    const stage = document.querySelector(".stage");
    if (!V.requireVasc(stage)) return;
    const ST = root.VASC_STATUS || { rank: () => 99, label: (v) => String(v || ""), statusOf: () => "", pill: () => "" };
    const columns = [
      ["title", "Project", "text"],
      ["status", "Status", "text"],
      ["stage", "Stage", "text"],
      ["level", "Level", "text"],
      ["strategic", "Strategic", "num"],
      ["reach", "Reach", "num"],
      ["business", "Business", "num"],
      ["score", "Score", "num"]
    ];
    let sortKey = "score";
    let sortDir = -1;

    function rows() {
      return V.cases.map((row) => {
        const scored = scoreCase(row, edits[row.id]);
        return {
          id: row.id,
          title: row.title || "",
          status: ST.label(ST.statusOf(row)),
          statusHtml: ST.pill(row),
          statusRank: ST.rank(ST.statusOf(row)),
          stage: stageLabel(stageOf(row)),
          level: scored.level || "—",
          strategic: scored.strategic,
          reach: scored.reach,
          business: scored.business,
          score: scored.score
        };
      });
    }

    function draw() {
      const sorted = rows().sort((a, b) => {
        const left = a[sortKey];
        const right = b[sortKey];
        if (sortKey === "status") return (a.statusRank - b.statusRank) * sortDir || a.title.localeCompare(b.title);
        if (typeof left === "number" && typeof right === "number") return (left - right) * sortDir || a.title.localeCompare(b.title);
        return String(left).localeCompare(String(right)) * sortDir || a.title.localeCompare(b.title);
      });
      const head = columns.map((col) => {
        const mark = col[0] === sortKey ? (sortDir < 0 ? " ↓" : " ↑") : "";
        return `<th class="${col[2] === "num" ? "num" : ""}"><button type="button" data-sort="${col[0]}">${col[1]}${mark}</button></th>`;
      }).join("");
      const body = sorted.map((row) => `<tr>
        <td><a href="case.html?id=${V.esc(row.id)}">${V.esc(row.title)}</a></td>
        <td>${row.statusHtml}</td>
        <td>${V.esc(row.stage)}</td>
        <td>${V.esc(row.level)}</td>
        <td class="num">${row.strategic}</td>
        <td class="num">${row.reach}</td>
        <td class="num">${row.business}</td>
        <td class="num">${row.score}</td>
      </tr>`).join("");
      stage.innerHTML = `
        <section class="lede"><h1>Scoring</h1>
          <p>This is the Digital Office quick read, not the portfolio score. Strategic value, reach, and business value start from the use case text and are editable on the project. Level is L0 through L4. Score is the average of those four, with level read as 5 for L0 down to 1 for L4. The Project Scores list holds the weighted and adjusted score the standing agenda uses. <a href="projects.html">Project list</a></p>
        </section>
        <p class="note">${sorted.length} project${sorted.length === 1 ? "" : "s"}</p>
        <div class="table-wrap"><table class="score-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
      stage.querySelectorAll("[data-sort]").forEach((button) => {
        button.addEventListener("click", () => {
          const key = button.dataset.sort;
          sortDir = key === sortKey ? -sortDir : (columns.find((col) => col[0] === key)[2] === "num" ? -1 : 1);
          sortKey = key;
          draw();
        });
      });
    }

    stage.innerHTML = `<section class="lede"><h1>Scoring</h1><p>Reading the use cases…</p></section>`;
    Promise.all([load(), V.caseLinks(), ST.load ? ST.load() : null]).then(draw);
  });
})(typeof window !== "undefined" ? window : globalThis);
