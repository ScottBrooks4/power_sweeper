(function () {
  if (window.parent !== window) document.documentElement.classList.add("framed");
  else document.documentElement.classList.add("shell");
  const NAV = [
    ["index.html", "home", "Start", "M5 5h6v6H5V5zm8 0h6v6h-6V5zM5 13h6v6H5v-6zm8 0h6v6h-6v-6z", false],
    ["intake.html", "intake", "Intake", "+", false],
    ["org.html", "org", "Organization", "M12 10.15a1.85 1.85 0 1 1 0 3.7a1.85 1.85 0 1 1 0 -3.7M12 10L12 5.22M12 2.98a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M13.18 10.38L15.99 6.51M16.64 4.49a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M13.9 11.38L18.45 9.9M19.51 8.44a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M13.9 12.62L18.45 14.1M19.51 13.32a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M13.18 13.62L15.99 17.49M16.64 17.27a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M12 14L12 18.78M12 18.78a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M10.82 13.62L8.01 17.49M7.36 17.27a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M10.1 12.62L5.55 14.1M4.49 13.32a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M10.1 11.38L5.55 9.9M4.49 8.44a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M10.82 10.38L8.01 6.51M7.36 4.49a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24", false],
    ["timeline.html", "timeline", "Timeline", "gantt", true],
    ["projects.html", "projects", "Projects", "checklist", true],
    ["apps.html", "apps", "Apps", ">", false],
    ["links.html", "links", "Links", "M10 13a5 5 0 007.2.2l1.8-2a5 5 0 00-7-7.1L10.8 5.4M14 11a5 5 0 00-7.2-.2l-1.8 2a5 5 0 007 7.1l1.2-1.3", false],
    ["connect.html", "connect", "Connections", "plug", true],
    ["acronyms.html", "acronyms", "Abbreviations", '"', false]
  ];
  const SECTION = {
    case: "projects",
    score: "projects",
    app: "apps",
    pulse: "connect"
  };

  const orgs = window.VASC_ORGS || [];
  const cases = window.VASC_CASES || [];
  const byId = Object.fromEntries(orgs.map((org) => [org.id, org]));
  const byName = Object.fromEntries(orgs.map((org) => [org.name, org]));
  let markSeq = 0;

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  let acronymRows = null;
  let acronymIndex = null;

  function compactAbbr(value) {
    return String(value || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
  }

  function placementText(text) {
    return /^L\d\b/.test(String(text || "").trim());
  }

  function loadAcronyms() {
    if (acronymRows) return Promise.resolve(acronymRows);
    return fetch("data/abbreviations.xml?v=20261101d")
      .then((response) => {
        if (!response.ok) throw new Error("The abbreviation list did not load.");
        return response.text();
      })
      .then((text) => {
        const doc = new DOMParser().parseFromString(text, "application/xml");
        if (doc.querySelector("parsererror")) throw new Error("The abbreviation list could not be read.");
        acronymRows = [...doc.getElementsByTagName("entry")].map((node) => {
          const textOf = (name) => {
            const child = node.getElementsByTagName(name)[0];
            return child && child.textContent ? child.textContent.trim() : "";
          };
          return {
            abbr: node.getAttribute("abbr") || "",
            frAbbr: node.getAttribute("frAbbr") || "",
            source: node.getAttribute("source") || "",
            en: textOf("en"),
            fr: textOf("fr"),
            place: textOf("place")
          };
        });
        acronymIndex = buildAbbrIndex(acronymRows);
        return acronymRows;
      });
  }

  function buildAbbrIndex(rows) {
    const skipShort = new Set("OF TO IN OR ON AT BE AN AS BY IF IT NO SO UP WE DO GO IS AM ME MY HE RE UN OK".split(" "));
    const map = new Map();
    function rankRow(row) {
      let rank = 0;
      const ab = String(row.abbr || "");
      const en = String(row.en || "").trim();
      if (ab && ab === ab.toUpperCase() && /[A-Z]/.test(ab)) rank += 3;
      if (/TERMIUM/i.test(row.source || "")) rank += 2;
      if (/Organization/i.test(row.source || "")) rank += 2;
      if (/VASC/i.test(row.source || "")) rank += 2;
      if (placementText(en)) rank -= 6;
      if (en && !/\s/.test(en) && en.length < 14) rank -= 2;
      if (en.length > 24) rank += 1;
      return rank;
    }
    const best = new Map();
    rows.forEach((row) => {
      const key = compactAbbr(row.abbr);
      if (key.length < 2) return;
      const en = String(row.en || "").trim();
      if (!en || placementText(en)) return;
      const prior = best.get(key);
      if (!prior || rankRow(row) > rankRow(prior.row)) best.set(key, { row, meaning: en });
    });
    rows.forEach((row) => {
      const key = compactAbbr(row.frAbbr);
      if (key.length < 2 || best.has(key)) return;
      const fr = String(row.fr || "").trim();
      const en = String(row.en || "").trim();
      const meaning = fr || en;
      if (!meaning || placementText(meaning)) return;
      best.set(key, { row, meaning: meaning });
    });
    best.forEach((hit, key) => {
      map.set(key, { abbr: hit.row.abbr || hit.row.frAbbr, meaning: hit.meaning });
    });
    // Prefer CAF TG sense for TG when the bare Manual sense is only "telegram"
    const cafTg = rows.find((row) => compactAbbr(row.abbr) === "CAFTG" && row.en && !placementText(row.en));
    if (cafTg) {
      map.set("TG", { abbr: "CAF TG", meaning: "Transition Group (CAF Transition Group)" });
      map.set("GT", { abbr: "GT FAC", meaning: "Groupe de transition (GT FAC)" });
    }
    skipShort.forEach((word) => map.delete(word));
    // The manual's only OPI sense is a munition. On this site it is the project lead.
    map.set("OPI", { abbr: "OPI", meaning: "Office of Primary Interest" });
    // The manual's VCR is a vehicle. Here it is the clearance app.
    map.set("VCR", { abbr: "VCR", meaning: "Visitor Clearance Request" });
    return map;
  }

  const ABBR_SKIP = new Set("OF TO IN OR ON AT BE AN AS BY IF IT NO SO UP WE DO GO IS AM ME MY HE RE UN OK".split(" "));
  const ABBR_ORDINARY = new Set("AUX PAR EST POST LAW CAMP PORT VOL SON LONG PER OFF RED ARM FER CAS ARC MER SOL PAS QUE POUR DANS AVEC SUR SOUS CHEZ VERS ENTRE SANS TOUT CETTE MAIS DONC COMME PLUS MOINS BIEN FAIT SONT AUSSI ALORS AVANT APRES AUTRE MEME PEU TROP PHOTO MASS JAM BAD BAC THE AND FOR WITH FROM THAT THIS NOT BUT CAN HIS HER OUR OUT AIR SEA LAND END NEW OLD DES LES UNE APP".split(" "));
  const ABBR_TWO = new Set("HQ GP CA QC BC AB SK MB NB NL NS PE ON OG".split(" "));

  function proseWord(part) {
    const letters = String(part).replace(/[^A-Za-z\u00C0-\u024F]/g, "");
    if (!letters || letters === letters.toUpperCase()) return false;
    if (letters === letters.toLowerCase()) return true;
    return ABBR_ORDINARY.has(compactAbbr(part));
  }

  function meaningOf(bit, selfKey) {
    if (!acronymIndex || proseWord(bit)) return null;
    const key = compactAbbr(bit);
    if (key.length < 2 || key === selfKey || !acronymIndex.has(key)) return null;
    if (bit !== bit.toUpperCase() || ABBR_ORDINARY.has(key) || ABBR_SKIP.has(key)) return null;
    if (key.length < 3 && !ABBR_TWO.has(key)) return null;
    const hit = acronymIndex.get(key);
    const meaning = String(hit.meaning || "").trim();
    if (!meaning || meaning.toUpperCase() === bit.toUpperCase()) return null;
    return { abbr: hit.abbr, meaning: meaning };
  }

  function linkAbbrs(text, options) {
    const opts = options || {};
    const raw = text == null ? "" : String(text);
    if (!acronymIndex || !raw || raw === "—") return esc(raw);
    const selfKey = compactAbbr(opts.self || "");
    const inline = !!opts.inline;
    return raw.split(/([A-Za-z\u00C0-\u024F][A-Za-z\u00C0-\u024F0-9+&.-]*(?:\/[A-Za-z\u00C0-\u024F][A-Za-z\u00C0-\u024F0-9+&.-]*)*)/).map((part, indexNum) => {
      if (indexNum % 2 === 0) return esc(part);
      if (proseWord(part)) return esc(part);
      const bits = part.includes("/") ? part.split("/") : [part];
      return bits.map((bit) => {
        const hit = meaningOf(bit, selfKey);
        if (!hit) return esc(bit);
        if (inline) return `<span class="abbr" tabindex="0" data-full="${esc(hit.meaning)}">${esc(bit)}</span>`;
        return `<a class="abbr" href="acronyms.html?abbr=${encodeURIComponent(hit.abbr)}" data-full="${esc(hit.meaning)}">${esc(bit)}</a>`;
      }).join("/");
    }).join("");
  }

  function abbrTip(text) {
    const raw = text == null ? "" : String(text);
    if (!acronymIndex || !raw) return "";
    const seen = new Set();
    const lines = [];
    raw.split(/([A-Za-z\u00C0-\u024F][A-Za-z\u00C0-\u024F0-9+&.-]*(?:\/[A-Za-z\u00C0-\u024F][A-Za-z\u00C0-\u024F0-9+&.-]*)*)/).forEach((part, indexNum) => {
      if (indexNum % 2 === 0 || proseWord(part)) return;
      const bits = part.includes("/") ? part.split("/") : [part];
      bits.forEach((bit) => {
        const hit = meaningOf(bit, "");
        if (!hit || seen.has(bit)) return;
        seen.add(bit);
        lines.push(bit + " — " + hit.meaning);
      });
    });
    return lines.join("\n");
  }

  let abbrLinking = false;

  function ensureAbbrTip() {
    let tip = document.getElementById("abbr-tip");
    if (tip) return tip;
    tip = document.createElement("div");
    tip.id = "abbr-tip";
    tip.hidden = true;
    document.body.appendChild(tip);
    return tip;
  }

  function placeAbbrTip(x, y) {
    const tip = ensureAbbrTip();
    const width = tip.offsetWidth || 0;
    const height = tip.offsetHeight || 0;
    let left = x;
    let top = y + 14;
    if (left + width > window.innerWidth - 8) left = window.innerWidth - width - 8;
    if (top + height > window.innerHeight - 8) top = y - height - 10;
    tip.style.left = Math.max(8, left) + "px";
    tip.style.top = Math.max(8, top) + "px";
  }

  function showPlainTip(text, x, y) {
    const tip = ensureAbbrTip();
    tip.textContent = String(text || "");
    tip.hidden = !tip.textContent;
    if (!tip.hidden) placeAbbrTip(x, y);
  }

  function showAbbrTip(text, x, y) {
    const tip = ensureAbbrTip();
    const line = abbrTip(text);
    if (!line) {
      tip.hidden = true;
      return;
    }
    tip.textContent = line;
    tip.hidden = false;
    placeAbbrTip(x, y);
  }

  function hideAbbrTip() {
    const tip = document.getElementById("abbr-tip");
    if (tip) tip.hidden = true;
  }

  function linkAbbrsIn(root) {
    if (abbrLinking || !acronymIndex || !root || document.body.dataset.page === "acronyms") return;
    abbrLinking = true;
    try {
      const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
          const parent = node.parentElement;
          if (!parent) return NodeFilter.FILTER_REJECT;
          if (parent.closest("script, style, textarea, input, select, option, .abbr, .acronym, .acronyms, #abbr-tip, .status-pill, .status-picker, .status-select, .status-strip, .status-chip, .rag-dot")) return NodeFilter.FILTER_REJECT;
          if (!/[A-Z]{2}|[A-Z]-[A-Z]/.test(node.nodeValue || "")) return NodeFilter.FILTER_REJECT;
          return NodeFilter.FILTER_ACCEPT;
        }
      });
      const nodes = [];
      while (walker.nextNode()) nodes.push(walker.currentNode);
      nodes.forEach((node) => {
        const parent = node.parentElement;
        if (!parent) return;
        const inline = !!parent.closest("a, button");
        const html = linkAbbrs(node.nodeValue, inline ? { inline: true } : undefined);
        if (html === esc(node.nodeValue)) return;
        const holder = document.createElement("span");
        holder.innerHTML = html;
        parent.replaceChild(holder, node);
      });
    } finally {
      abbrLinking = false;
    }
  }

  function watchAbbrs() {
    linkAbbrsIn(document.body);
    const observer = new MutationObserver(() => {
      if (abbrLinking) return;
      linkAbbrsIn(document.body);
    });
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    document.addEventListener("mouseover", (event) => {
      const host = event.target.closest && event.target.closest(".abbr[data-full]");
      if (!host) return;
      const tip = ensureAbbrTip();
      tip.textContent = host.getAttribute("data-full") || "";
      if (!tip.textContent) {
        tip.hidden = true;
        return;
      }
      tip.hidden = false;
      const rect = host.getBoundingClientRect();
      placeAbbrTip(rect.left, rect.bottom);
    });
    document.addEventListener("mouseout", (event) => {
      const host = event.target.closest && event.target.closest(".abbr[data-full]");
      if (!host) return;
      const next = event.relatedTarget && event.relatedTarget.closest && event.relatedTarget.closest(".abbr[data-full]");
      if (next === host) return;
      hideAbbrTip();
    });
    document.addEventListener("focusin", (event) => {
      const host = event.target.closest && event.target.closest(".abbr[data-full]");
      if (!host) return;
      const tip = ensureAbbrTip();
      tip.textContent = host.getAttribute("data-full") || "";
      tip.hidden = !tip.textContent;
      if (tip.hidden) return;
      const rect = host.getBoundingClientRect();
      placeAbbrTip(rect.left, rect.bottom);
    });
    document.addEventListener("focusout", (event) => {
      if (event.target.closest && event.target.closest(".abbr[data-full]")) hideAbbrTip();
    });
  }

  let currentRole = "public";

  function role() {
    return currentRole;
  }

  function blank(value) {
    const text = value == null ? "" : String(value).trim();
    return text ? esc(text) : "—";
  }

  function hours(value) {
    return value == null || value === "" ? "—" : String(value);
  }

  function findOrg(id) {
    return byId[id] || null;
  }

  function findCase(id) {
    return cases.find((row) => row.id === String(id)) || null;
  }

  function rootOrg() {
    return orgs.find((org) => !org.parent) || orgs[0];
  }

  function childrenOf(org) {
    if (!org) return [];
    return orgs.filter((row) => row.parent === org.name);
  }

  function isVcds(org) {
    return !!(org && org.short === "VCDS" && org.level === "L1");
  }

  function navGlyph(path, size) {
    const box = size || 24;
    const common = `xmlns="http://www.w3.org/2000/svg" width="${box}" height="${box}" viewBox="0 0 24 24" aria-hidden="true"`;
    if (path === '"') {
      return `<svg ${common}><path fill="currentColor" d="M6 17h3l2-4V7H5v6h3zm8 0h3l2-4V7h-6v6h3z"/></svg>`;
    }
    if (path === "+") {
      return `<svg ${common}><path fill="currentColor" d="M10 4h4v6h6v4h-6v6h-4v-6H4v-4h6z"/></svg>`;
    }
    if (path === ">") {
      return `<svg ${common} fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="8.25"/><path fill="currentColor" stroke="none" d="M9.6 7.7l6.8 4.3-6.8 4.3z"/></svg>`;
    }
    if (path === "calendar") {
      return `<svg ${common} fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="5" width="16" height="15" rx="1.5"/><path d="M4 9h16M8 3v4M16 3v4"/></svg>`;
    }
    if (path === "gantt") {
      // Three staggered bars against an axis: work over time.
      return `<svg ${common} fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3.5 3.5v17M3.5 20.5h17" stroke-opacity=".55"/><rect x="6" y="5.4" width="11" height="3.2" rx="1.2" fill="currentColor" stroke="none"/><rect x="6" y="10.4" width="8" height="3.2" rx="1.2" fill="currentColor" stroke="none" fill-opacity=".72"/><rect x="9.5" y="15.4" width="9" height="3.2" rx="1.2" fill="currentColor" stroke="none" fill-opacity=".45"/></svg>`;
    }
    if (path === "checklist") {
      // Two items ticked and one still open, each with its line: a plan being worked.
      return `<svg ${common} fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3.6 6.2l1.6 1.7 3-3.5"/><path d="M11.2 6h9.2"/><path d="M3.6 12.2l1.6 1.7 3-3.5"/><path d="M11.2 12h7.4"/><rect x="3.9" y="16.1" width="4" height="4" rx="1"/><path d="M11.2 18h8.2"/></svg>`;
    }
    if (path === "plug") {
      return `<svg ${common} fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 8V4M16 8V4M7 8h10v5a5 5 0 01-10 0V8zM12 18v3"/></svg>`;
    }
    return `<svg ${common} fill="none" stroke="currentColor" stroke-width="1.6"><path d="${path}"/></svg>`;
  }

  function navIcon(id, size) {
    const item = NAV.find((row) => row[1] === id);
    return item ? navGlyph(item[3], size) : "";
  }

  function orgIcon(size) {
    const box = size || 16;
    const path = "M12 10.15a1.85 1.85 0 1 1 0 3.7a1.85 1.85 0 1 1 0 -3.7M12 10L12 5.22M12 2.98a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M13.18 10.38L15.99 6.51M16.64 4.49a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M13.9 11.38L18.45 9.9M19.51 8.44a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M13.9 12.62L18.45 14.1M19.51 13.32a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M13.18 13.62L15.99 17.49M16.64 17.27a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M12 14L12 18.78M12 18.78a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M10.82 13.62L8.01 17.49M7.36 17.27a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M10.1 12.62L5.55 14.1M4.49 13.32a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M10.1 11.38L5.55 9.9M4.49 8.44a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24M10.82 10.38L8.01 6.51M7.36 4.49a1.12 1.12 0 1 1 0 2.24a1.12 1.12 0 1 1 0 -2.24";
    return `<svg class="org-icon" xmlns="http://www.w3.org/2000/svg" width="${box}" height="${box}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="${path}"/></svg>`;
  }

  function mark(short, size) {
    const raw = String(short || "?");
    const quote = raw === '"';
    const plus = raw === "+";
    const label = (quote || plus) ? raw : (raw.replace(/[^A-Za-z0-9]/g, "").slice(0, 4).toUpperCase() || "•");
    let hue = 0;
    const source = quote ? "Abbreviations" : plus ? "Intake" : raw;
    for (let i = 0; i < source.length; i++) hue = (hue * 33 + source.charCodeAt(i)) >>> 0;
    hue %= 360;
    const fs = label.length > 3 ? 11 : label.length > 2 ? 14 : 18;
    const id = "m" + (markSeq++);
    const glyph = quote
      ? `<g transform="translate(-6.4 -6.4) scale(3.2)"><path fill="#f5f9ff" d="M6 17h3l2-4V7H5v6h3zm8 0h3l2-4V7h-6v6h3z"/></g>`
      : plus
      ? `<g transform="translate(8 8) scale(2)"><path fill="#f5f9ff" d="M10 4h4v6h6v4h-6v6h-4v-6H4v-4h6z"/></g>`
      : `<text x="32" y="39" text-anchor="middle" font-family="Segoe UI,sans-serif" font-size="${fs}" font-weight="700" fill="#f5f9ff">${esc(label)}</text>`;
    return `<svg class="logo" xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 64 64" aria-hidden="true"><defs><linearGradient id="${id}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="hsl(${hue} 58% 46%)"/><stop offset="1" stop-color="hsl(${(hue + 36) % 360} 42% 20%)"/></linearGradient></defs><path d="M32 3 58 14 V34 C58 49 45 59 32 62 19 59 6 49 6 34 V14 Z" fill="url(#${id})" stroke="hsl(${hue} 75% 76%)" stroke-width="1.6"/>${glyph}</svg>`;
  }

  function logo(org, size) {
    const src = org && window.VASC_LOGOS && window.VASC_LOGOS[org.id];
    if (src) return `<img class="logo" src="${src}" width="${size}" height="${size}" alt="">`;
    if (isVcds(org)) {
      return `<img class="logo" src="assets/vcds-crest.png" width="${size}" height="${size}" alt="">`;
    }
    return mark(org ? org.short : "?", size);
  }

  function casesFor(org) {
    if (!org) return [];
    return cases.filter((row) => row.orgId === org.id);
  }

  function hereFile() {
    const iframe = document.getElementById("page-frame");
    if (iframe && !iframe.hidden && iframe.getAttribute("src")) {
      try {
        const url = new URL(iframe.src, location.href);
        return (url.pathname.split("/").pop() || "index.html") + url.search;
      } catch (error) {}
    }
    return (location.pathname.split("/").pop() || "index.html") + location.search;
  }

  function accountMarkup() {
    const file = hereFile();
    const signHref = file.split("?")[0] === "connect.html" ? "connect.html" : "connect.html?return=" + encodeURIComponent(file);
    if (role() === "vasc") return `<span class="who">Signed in</span><button type="button" id="sign-out">Sign out</button>`;
    return `<a class="btn" href="${signHref}">Sign in</a>`;
  }

  function bindSignOut() {
    const signOut = document.getElementById("sign-out");
    if (!signOut) return;
    signOut.addEventListener("click", () => {
      signOut.disabled = true;
      fetch("api/devops.php?action=forget", { method: "POST" }).finally(() => location.reload());
    });
  }

  function fillAccount() {
    const roles = document.querySelector("#site-header .roles");
    if (!roles) return;
    roles.innerHTML = accountMarkup();
    bindSignOut();
  }

  function railPage() {
    const iframe = document.getElementById("page-frame");
    if (iframe && !iframe.hidden && iframe.getAttribute("src")) {
      try { return pageIdFromHref(new URL(iframe.src, location.href).pathname); } catch (error) {}
    }
    return document.body.dataset.page || "home";
  }

  function fillRail() {
    if (window.parent !== window) return;
    const frame = document.querySelector(".frame");
    if (!frame) return;
    let rail = frame.querySelector(".rail");
    if (!rail) {
      rail = document.createElement("nav");
      rail.className = "rail";
      rail.setAttribute("aria-label", "VASC");
      frame.insertBefore(rail, frame.querySelector(".stage"));
    }
    const here = role();
    const select = navPage(railPage());
    rail.innerHTML = NAV.filter((item) => here === "vasc" || !item[4]).map(([href, id, label, path]) => {
      const current = select === id ? ' aria-current="page"' : "";
      return `<a href="${href}" title="${label}" aria-label="${label}"${current}>${navGlyph(path)}</a>`;
    }).join("");
  }

  function mount() {
    const page = document.body.dataset.page || "";
    const header = document.getElementById("site-header");
    if (!header) return;
    header.innerHTML = `
      <div class="top">
        <a class="brand" href="index.html">
          <img src="assets/vcds-crest.png" alt="VCDS crest">
          <span><strong>VASC Pivot</strong><small>Digital Office</small></span>
        </a>
        <input class="search" id="start-search" type="search" placeholder="${(searchCopy(page) || ["Search", "Search"])[0]}" aria-label="${(searchCopy(page) || ["Search", "Search"])[1]}"${searchCopy(page) ? "" : " hidden"}>
        <div class="roles">${accountMarkup()}</div>
      </div>`;
    bindSignOut();
    const search = document.getElementById("start-search");
    if (search && window.parent === window) {
      tuneSearch(page, new URLSearchParams(location.search).get("q") || "");
      search.addEventListener("input", () => {
        const run = pageSearch();
        if (run) run(search.value);
      });
      search.addEventListener("keydown", (event) => {
        if (event.key !== "Enter") return;
        const run = pageSearch();
        if (run) {
          event.preventDefault();
          run(search.value);
          return;
        }
        if (searchPage !== "home") return;
        const q = search.value.trim();
        if (!q) return;
        event.preventDefault();
        showInFrame("index.html?q=" + encodeURIComponent(q), true);
      });
    }
    if (window.parent !== window) announceSearch();
    fillRail();
  }

  function pageIdFromHref(href) {
    const file = String(href || "").split("?")[0].split("/").pop() || "index.html";
    const hit = NAV.find((row) => row[0] === file);
    if (hit) return hit[1];
    return file.replace(/\.html$/, "") || "home";
  }

  function navPage(page) {
    if (NAV.some((row) => row[1] === page)) return page;
    return SECTION[page] || "";
  }

  function localPage(href) {
    let url;
    try { url = new URL(href, location.href); } catch (error) { return ""; }
    if (url.origin !== location.origin) return "";
    const file = url.pathname.split("/").pop() || "";
    if (!/^[A-Za-z0-9_-]+\.html$/i.test(file)) return "";
    if (url.pathname === location.pathname) return "";
    return url.pathname + url.search;
  }

  function markRail(page) {
    const top = NAV.some((row) => row[1] === page);
    if (!top && document.querySelector('.rail a[aria-current="page"]')) return;
    const select = top ? page : navPage(page);
    document.querySelectorAll(".rail a").forEach((link) => {
      const id = pageIdFromHref(link.getAttribute("href"));
      if (select && id === select) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    });
  }

  let searchPage = "";

  function searchCopy(page) {
    const id = page === "index" ? "home" : (page || "home");
    const copy = {
      home: ["Search organizations", "Search organizations"],
      org: ["Search the chart", "Search the chart"],
      links: ["Search links", "Search links"],
      apps: ["Search apps", "Search apps"],
      projects: ["Search projects", "Search projects"],
      acronyms: ["Search abbreviation or expansion", "Search abbreviations"]
    };
    return copy[id] || null;
  }

  function tuneSearch(page, query) {
    if (window.parent !== window) return;
    const search = document.getElementById("start-search");
    if (!search) return;
    const id = page === "index" ? "home" : (page || "home");
    const changed = id !== searchPage;
    searchPage = id;
    const text = searchCopy(id);
    if (!text) {
      search.hidden = true;
      search.value = "";
      return;
    }
    search.hidden = false;
    search.placeholder = text[0];
    search.setAttribute("aria-label", text[1]);
    if (typeof query === "string") search.value = query;
    else if (changed) search.value = "";
  }

  function pageSearch() {
    const iframe = document.getElementById("page-frame");
    if (iframe && !iframe.hidden) {
      try {
        const api = iframe.contentWindow && iframe.contentWindow.VASC;
        return api && api.searchChart ? api.searchChart : null;
      } catch (error) {
        return null;
      }
    }
    return window.VASC && window.VASC.searchChart ? window.VASC.searchChart : null;
  }

  function announceSearch() {
    if (window.parent === window) return;
    const page = document.body.dataset.page || "home";
    const q = new URLSearchParams(location.search).get("q") || "";
    window.parent.postMessage({ vascSearch: page, q: q }, location.origin);
  }

  function announceRole() {
    if (window.parent === window) return;
    window.parent.postMessage({ vascRole: role() }, location.origin);
  }

  /**
   * Pages inside the frame own their own <title>. Push it up to the shell so the
   * browser tab names the page you are actually on, and so a project or app page
   * can name itself once it knows which one it is.
   */
  function announceTitle() {
    if (window.parent === window) return;
    const text = String(document.title || "").trim();
    if (!text) return;
    window.parent.postMessage({ vascTitle: text }, location.origin);
  }

  function setTitle(text) {
    const clean = String(text || "").trim();
    if (!clean) return;
    if (document.title !== clean) document.title = clean;
    announceTitle();
  }

  /** "Case — VASC Pivot" becomes "Case — VASC Pivot" in the tab once the frame answers. */
  function titleFor(href) {
    const known = {
      "index.html": "Start",
      "intake.html": "Intake",
      "org.html": "Organization",
      "timeline.html": "Timeline",
      "projects.html": "Projects",
      "apps.html": "Apps",
      "app.html": "App",
      "links.html": "Links",
      "connect.html": "Connections",
      "acronyms.html": "Abbreviations",
      "case.html": "Project",
      "score.html": "Scoring"
    };
    const file = String(href || "").split("?")[0].split("/").pop() || "index.html";
    const name = known[file] || "";
    return name ? name + " — VASC Pivot" : "VASC Pivot";
  }

  function showInFrame(href, push) {
    const frame = document.querySelector(".frame");
    if (!frame) {
      location.href = href;
      return;
    }
    let iframe = document.getElementById("page-frame");
    if (!iframe) {
      iframe = document.createElement("iframe");
      iframe.id = "page-frame";
      iframe.title = "Page";
      frame.appendChild(iframe);
    }
    const stage = frame.querySelector(".stage");
    if (stage) stage.hidden = true;
    iframe.hidden = false;
    iframe.src = href;
    setTitle(titleFor(href));
    markRail(pageIdFromHref(href));
    let preset = "";
    try { preset = new URL(href, location.href).searchParams.get("q") || ""; } catch (error) {}
    tuneSearch(pageIdFromHref(href), preset);
    if (push) history.pushState({ vascFrame: href }, "", href);
  }

  document.addEventListener("click", (event) => {
    const link = event.target.closest && event.target.closest("a");
    if (!link || link.target === "_blank" || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const href = localPage(link.href);
    if (!href) return;
    event.preventDefault();
    if (window.parent !== window) {
      window.parent.postMessage({ vascNav: href }, location.origin);
      return;
    }
    showInFrame(href, true);
  });

  window.addEventListener("message", (event) => {
    if (window.parent !== window || event.origin !== location.origin) return;
    if (!event.data) return;
    if (event.data.vascRole === "vasc" || event.data.vascRole === "public") {
      const iframe = document.getElementById("page-frame");
      if (!iframe || iframe.hidden || event.source !== iframe.contentWindow) return;
      if (event.data.vascRole !== currentRole) {
        currentRole = event.data.vascRole;
        fillRail();
        fillAccount();
      }
      return;
    }
    if (event.data.vascSearch) {
      const iframe = document.getElementById("page-frame");
      if (!iframe || iframe.hidden || event.source !== iframe.contentWindow) return;
      const page = String(event.data.vascSearch);
      if (!/^[A-Za-z0-9-]+$/.test(page)) return;
      tuneSearch(page, typeof event.data.q === "string" ? event.data.q : "");
      return;
    }
    if (event.data.vascTitle) {
      const iframe = document.getElementById("page-frame");
      if (!iframe || iframe.hidden || event.source !== iframe.contentWindow) return;
      const text = String(event.data.vascTitle || "").trim().slice(0, 160);
      if (text && document.title !== text) document.title = text;
      return;
    }
    if (event.data.vascLane) {
      const lane = String(event.data.vascLane);
      if (!/^[A-Za-z0-9-]+$/.test(lane)) return;
      const href = "apps.html?lane=" + encodeURIComponent(lane);
      const state = history.state && history.state.vascFrame ? { vascFrame: href } : history.state;
      history.replaceState(state, "", href);
      return;
    }
    if (!event.data.vascNav) return;
    showInFrame(event.data.vascNav, true);
  });

  window.addEventListener("popstate", (event) => {
    if (window.parent !== window) return;
    const href = event.state && event.state.vascFrame;
    const iframe = document.getElementById("page-frame");
    const stage = document.querySelector(".frame .stage");
    if (!href) {
      if (iframe) iframe.hidden = true;
      if (stage) stage.hidden = false;
      document.title = titleFor((location.pathname.split("/").pop() || "index.html") + location.search);
      markRail(document.body.dataset.page || "home");
      tuneSearch(document.body.dataset.page || "home", "");
      const run = pageSearch();
      if (run) run("");
      return;
    }
    showInFrame(href, false);
  });


  let linksReady = null;

  function caseLinks() {
    if (linksReady) return linksReady;
    linksReady = fetch("api/devops.php?action=caselinks", { cache: "no-store" })
      .then((res) => res.json())
      .then((data) => {
        const links = (data && data.links) || {};
        cases.forEach((row) => {
          if (row.workId) return;
          const link = links[String(row.id)];
          if (!link || !link.id) return;
          row.workId = String(link.id);
          if (!row.devops) row.devops = link.url || "";
          const items = Array.isArray(row.workItems) ? row.workItems.slice() : [];
          if (!items.some((id) => String(id) === String(link.id))) items.push(Number(link.id));
          row.workItems = items;
        });
        return links;
      })
      .catch(() => ({}));
    return linksReady;
  }

  function devops(action, query, body) {
    const params = new URLSearchParams(Object.assign({ action: action }, query || {}));
    const opts = { cache: "no-store" };
    if (body) {
      opts.method = "POST";
      opts.headers = { "Content-Type": "application/json" };
      opts.body = JSON.stringify(body);
    }
    return fetch("api/devops.php?" + params.toString(), opts).then(async (res) => {
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) {
        const error = new Error(data.error || "Sync failed.");
        error.login = data.login === true || res.status === 401;
        throw error;
      }
      return data;
    });
  }

  function useDevopsToken(pat) {
    return fetch("api/devops.php?action=usepat", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ pat: pat })
    }).then(async (res) => {
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) {
        throw new Error(data.error || "Azure DevOps did not accept that token.");
      }
      return data;
    });
  }

  function promptLogin() {
    if (document.body.dataset.page === "connect") {
      const field = document.getElementById("connect-pat");
      if (field) {
        field.focus();
        field.scrollIntoView({ block: "center" });
      }
      return;
    }
    const here = (location.pathname.split("/").pop() || "index.html") + location.search;
    location.href = "connect.html?return=" + encodeURIComponent(here);
  }

  function promptWrite() {
    if (document.body.dataset.page === "connect") {
      const field = document.getElementById("connect-pat");
      if (field) {
        field.focus();
        field.scrollIntoView({ block: "center" });
      }
      return;
    }
    const here = (location.pathname.split("/").pop() || "index.html") + location.search;
    location.href = "connect.html?write=1&return=" + encodeURIComponent(here);
  }

  function requireVasc(stage) {
    if (role() === "vasc") return true;
    const here = (location.pathname.split("/").pop() || "index.html") + location.search;
    stage.innerHTML = `<section class="panel"><h1>VASC</h1><p>This page opens after an Azure DevOps sign-in.</p><p><a class="btn" href="connect.html?return=${encodeURIComponent(here)}">Sign in</a></p></section>`;
    return false;
  }

  const ready = fetch("api/devops.php?action=session", { cache: "no-store" })
    .then((res) => res.json())
    .then((data) => {
      currentRole = data && data.signedIn ? "vasc" : "public";
    })
    .catch(() => {
      currentRole = "public";
    })
    .then(() => {
      mount();
      announceRole();
      announceTitle();
      loadAcronyms().then(() => watchAbbrs()).catch(() => {});
      return currentRole;
    });

  window.VASC = {
    esc, role, blank, hours, findOrg, findCase, rootOrg, childrenOf, isVcds,
    mark, logo, orgIcon, navIcon, casesFor, mount, requireVasc, devops, caseLinks, promptLogin, promptWrite, useDevopsToken, loadAcronyms, linkAbbrs, showAbbrTip, showPlainTip, hideAbbrTip, setTitle, ready, orgs, cases, byName
  };
})();
