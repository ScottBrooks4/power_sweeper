/**
 * Project status, from the Digital Office Project Scores list.
 *
 * The list is the record of what a use case was decided: Deferred, Declined,
 * Prioritized, in UAT, and so on, with the RAG rating and the portfolio score.
 * This file joins it onto window.VASC_CASES by UseCaseID so every page shows the
 * same words in the same colour.
 *
 * A signed-in reader can change a status or tick a feature from the project page.
 * Those decisions are saved to the site and win over the export until the export
 * itself is updated.
 */
(function (root) {
  const V = root.VASC || {};

  const ORDER = [
    "New",
    "Prioritized",
    "In Dev",
    "UAT",
    "Completed",
    "Deferred",
    "Declined",
    "Decommissioned"
  ];

  const TONE = {
    prioritized: "go",
    "in dev": "work",
    uat: "build",
    new: "unknown",
    deferred: "hold",
    declined: "stop",
    completed: "done",
    decommissioned: "gone"
  };

  // The six features every project Epic carries, from SOP-001 6.1.3.
  const FEATURES = [
    ["architecture", "Project Architecture"],
    ["backend", "Back-end development"],
    ["frontend", "Front-end development"],
    ["uat", "UAT Testing"],
    ["production", "Production"],
    ["maintenance", "Maintenance"]
  ];

  // The list spells decommissioned with one m, so both spellings land together.
  function key(value) {
    return String(value || "")
      .toLowerCase()
      .replace(/\s+/g, " ")
      .replace(/decom{1,2}issioned/, "decommissioned")
      .replace(/needs more info/, "needs more info")
      .trim();
  }

  function label(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";
    if (/^decom{1,2}issioned$/i.test(raw)) return "Decommissioned";
    if (/^dev$/i.test(raw)) return "In Dev";
    return raw;
  }

  const state = {
    rows: {},
    updated: "",
    count: 0,
    missing: false,
    note: "",
    statuses: ORDER,
    loaded: null
  };

  function tone(value) {
    return TONE[key(value)] || "unknown";
  }

  function statusOf(row) {
    if (!row) return "";
    if (row.status && row.status.projectStatus) return row.status.projectStatus;
    const hit = state.rows[String(row.id)];
    return hit ? hit.projectStatus : "";
  }

  function statusRow(row) {
    if (!row) return null;
    if (row.status) return row.status;
    return state.rows[String(row.id)] || null;
  }

  function pill(row, extra) {
    const raw = statusOf(row);
    const shown = label(raw);
    const add = extra ? " " + extra : "";
    if (!shown) {
      return `<span class="status-pill tone-unknown${add}">Status not published</span>`;
    }
    return `<span class="status-pill tone-${tone(raw)}${add}">${V.esc ? V.esc(shown) : shown}</span>`;
  }

  function esc(value) {
    return V.esc ? V.esc(value) : value;
  }

  /**
   * A status picker. The closed control and every choice are the same pill:
   * rounded, with that status's text colour on its tint.
   */
  function select(row, options, extra) {
    const raw = statusOf(row);
    const shown = label(raw);
    const names = options && options.length ? options : state.statuses;
    const list = names.concat(shown && names.indexOf(shown) < 0 ? [shown] : []);
    const shade = tone(shown);
    const add = extra ? " " + extra : "";
    const who = String(row && row.id).replace(/[^\d]/g, "");
    const choices = [{ name: "No status", value: "" }].concat(list.map((name) => ({ name: name, value: name })));
    const hidden = choices.map((choice) => {
      const picked = choice.value === shown ? " selected" : "";
      return `<option value="${esc(choice.value)}"${picked}>${esc(choice.name)}</option>`;
    }).join("");
    const menu = choices.map((choice) => {
      const on = choice.value === shown;
      return `<button type="button" class="status-option tone-${tone(choice.value)}" role="option" data-value="${esc(choice.value)}" aria-selected="${on ? "true" : "false"}">${esc(choice.name)}</button>`;
    }).join("");
    return `<span class="status-picker tone-${shade}${add}">
      <select class="status-select tone-${shade}${add}" data-status-id="${who}" tabindex="-1" aria-hidden="true">${hidden}</select>
      <button type="button" class="status-face status-select tone-${shade}${add}" aria-haspopup="listbox" aria-expanded="false" aria-label="Status for this use case">${esc(shown || "No status")}</button>
      <span class="status-menu" role="listbox" hidden>${menu}</span>
    </span>`;
  }

  function closeMenus() {
    if (!root.document) return;
    root.document.querySelectorAll(".status-picker.is-open").forEach((picker) => {
      picker.classList.remove("is-open");
      const menu = picker.querySelector(".status-menu");
      const face = picker.querySelector(".status-face");
      if (menu) menu.hidden = true;
      if (face) face.setAttribute("aria-expanded", "false");
    });
  }

  function paintPicker(picker, value) {
    const shade = tone(value);
    const rowClass = picker.classList.contains("status-select-row") ? " status-select-row" : "";
    const open = picker.classList.contains("is-open") ? " is-open" : "";
    picker.className = "status-picker tone-" + shade + rowClass + open;
    const select = picker.querySelector("select.status-select");
    const face = picker.querySelector(".status-face");
    if (select) {
      select.className = "status-select tone-" + shade + rowClass;
      select.value = value || "";
    }
    if (face) {
      face.className = "status-face status-select tone-" + shade + rowClass;
      face.textContent = value ? label(value) : "No status";
    }
    picker.querySelectorAll(".status-option").forEach((opt) => {
      opt.setAttribute("aria-selected", opt.getAttribute("data-value") === (value || "") ? "true" : "false");
    });
  }

  function openMenu(picker) {
    closeMenus();
    const menu = picker.querySelector(".status-menu");
    const face = picker.querySelector(".status-face");
    if (!menu || !face) return;
    picker.classList.add("is-open");
    menu.hidden = false;
    face.setAttribute("aria-expanded", "true");
    menu.style.top = "";
    menu.style.bottom = "";
    const faceBox = face.getBoundingClientRect();
    const menuBox = menu.getBoundingClientRect();
    if (faceBox.bottom + menuBox.height + 8 > root.innerHeight && faceBox.top > menuBox.height + 8) {
      menu.style.top = "auto";
      menu.style.bottom = "calc(100% + 6px)";
    }
  }

  function choose(picker, value) {
    const select = picker.querySelector("select.status-select");
    if (!select || select.disabled) return;
    paintPicker(picker, value);
    closeMenus();
    select.dispatchEvent(new Event("change", { bubbles: true }));
  }

  if (root.document) {
    root.document.addEventListener("click", (event) => {
      const option = event.target.closest && event.target.closest(".status-option");
      if (option) {
        event.preventDefault();
        choose(option.closest(".status-picker"), option.getAttribute("data-value") || "");
        return;
      }
      const face = event.target.closest && event.target.closest(".status-face");
      if (face) {
        event.preventDefault();
        const picker = face.closest(".status-picker");
        const select = picker.querySelector("select.status-select");
        if (select && select.disabled) return;
        if (picker.classList.contains("is-open")) closeMenus();
        else openMenu(picker);
        return;
      }
      if (!event.target.closest || !event.target.closest(".status-picker")) closeMenus();
    });
    root.document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeMenus();
    });
  }

  function dot(row) {
    const hit = statusRow(row);
    const rag = hit && hit.rag ? hit.rag : "";
    if (!rag) return "";
    const shade = key(rag);
    return `<span class="rag-dot rag-${V.esc ? V.esc(shade) : shade}" title="${V.esc ? V.esc(rag) : rag} rating" aria-label="${V.esc ? V.esc(rag) : rag} rating"></span>`;
  }

  /** Sort weight: what needs a decision first, what is finished last. */
  function rank(value) {
    const keyed = key(label(value));
    const place = ORDER.map(key).indexOf(keyed);
    return place < 0 ? ORDER.length + 1 : place;
  }

  function buckets() {
    const counts = new Map();
    Object.keys(state.rows).forEach((id) => {
      const shown = label(state.rows[id].projectStatus) || "No status";
      counts.set(shown, (counts.get(shown) || 0) + 1);
    });
    return [...counts.entries()]
      .map(([name, count]) => ({ name: name, count: count, tone: tone(name) }))
      .sort((a, b) => rank(a.name) - rank(b.name) || b.count - a.count);
  }

  function load() {
    if (state.loaded) return state.loaded;
    state.loaded = fetch("api/status.php", { cache: "no-store" })
      .then((res) => res.json())
      .catch(() => ({ ok: false, rows: {}, missing: true, note: "" }))
      .then((data) => {
        const rows = (data && data.rows) || {};
        state.rows = rows;
        state.updated = (data && data.updated) || "";
        state.count = (data && data.count) || 0;
        state.missing = !!(data && data.missing);
        state.note = (data && data.note) || "";
        if (data && Array.isArray(data.statuses) && data.statuses.length) state.statuses = data.statuses;
        (V.cases || []).forEach((row) => {
          const hit = rows[String(row.id)];
          if (hit) row.status = hit;
        });
        return state;
      });
    return state.loaded;
  }

  /** Re-read after a change, so counts and pills elsewhere agree with what was saved. */
  function reload() {
    state.loaded = null;
    return load();
  }

  function save(id, patch) {
    return fetch("api/status.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(Object.assign({ id: String(id) }, patch || {}))
    }).then(async (res) => {
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) {
        const error = new Error(data.error || "The change was not saved.");
        error.login = data.login === true || res.status === 401;
        throw error;
      }
      if (data.status) {
        state.rows[String(id)] = Object.assign({}, state.rows[String(id)] || {}, data.status);
        (V.cases || []).forEach((row) => {
          if (String(row.id) === String(id)) row.status = state.rows[String(id)];
        });
      }
      return data;
    });
  }

  function featuresOf(row) {
    const hit = statusRow(row);
    const ticks = (hit && hit.features) || null;
    return FEATURES.map(([id, name]) => ({
      id: id,
      name: name,
      on: !!(ticks && ticks[id])
    }));
  }

  root.VASC_STATUS = {
    load: load,
    reload: reload,
    save: save,
    rows: function () {
      return state.rows;
    },
    updated: function () {
      return state.updated;
    },
    count: function () {
      return state.count;
    },
    missing: function () {
      return state.missing;
    },
    note: function () {
      return state.note;
    },
    statuses: function () {
      return state.statuses;
    },
    features: function () {
      return FEATURES;
    },
    featuresOf: featuresOf,
    label: label,
    tone: tone,
    rank: rank,
    statusOf: statusOf,
    statusRow: statusRow,
    pill: pill,
    select: select,
    dot: dot,
    buckets: buckets,
    order: ORDER
  };
})(typeof window !== "undefined" ? window : globalThis);
