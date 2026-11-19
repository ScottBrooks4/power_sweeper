(function () {
  const V = window.VASC;
  const stage = document.querySelector(".stage");
  const params = new URLSearchParams(location.search);
  let lang = "en";
  try {
    const asked = (params.get("lang") || "").toLowerCase();
    const saved = sessionStorage.getItem("vasc-abbr-lang") || "";
    lang = asked === "fr" || asked === "en" ? asked : (saved === "fr" ? "fr" : "en");
  } catch (error) {}
  stage.innerHTML = `
    <section class="lede"><h1>Abbreviations</h1>
      <p>From the full public <a href="https://open.canada.ca/data/en/dataset/976bb4f8-2b63-4150-910c-1f8e094cc83a">DND/CAF Manual of Abbreviations</a> and <a href="https://open.canada.ca/data/en/dataset/94fc74d6-9b9a-4c2e-9c6c-45a5092453aa">TERMIUM Plus</a>.</p>
    </section>
    <div class="lang-toggle" role="group" aria-label="Language shown on the left">
      <button type="button" class="btn" id="lang-en" aria-pressed="${lang === "en"}">EN</button>
      <button type="button" class="btn" id="lang-fr" aria-pressed="${lang === "fr"}">FR</button>
    </div>
    <p class="note" id="count"></p>
    <ul class="acronyms" id="list"></ul>`;
  V.ready.then(() => {
  const box = document.getElementById("start-search");
  let queryText = params.get("abbr") || params.get("q") || "";
  if (box && window.parent === window) {
    box.placeholder = "Search abbreviation or expansion";
    box.setAttribute("aria-label", "Search abbreviations");
    if (queryText) box.value = queryText;
  }
  V.loadAcronyms().then((rows) => {
      function compact(value) {
        return String(value || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
      }
      function placement(text) {
        return /^L\d\b/.test(String(text || "").trim());
      }
      const skipShort = new Set("OF TO IN OR ON AT BE AN AS BY IF IT NO SO UP WE DO GO IS AM ME MY HE RE UN OK".split(" "));
      const exact = new Map();
      function rankRow(row) {
        let rank = 0;
        const ab = String(row.abbr || "");
        const en = String(row.en || "").trim();
        if (ab && ab === ab.toUpperCase() && /[A-Z]/.test(ab)) rank += 3;
        if (/TERMIUM/i.test(row.source || "")) rank += 2;
        if (/Organization/i.test(row.source || "")) rank += 2;
        if (/VASC/i.test(row.source || "")) rank += 2;
        if (placement(en)) rank -= 6;
        if (en && !/\s/.test(en) && en.length < 14) rank -= 2;
        if (en.length > 24) rank += 1;
        return rank;
      }
      rows.forEach((row) => {
        const key = compact(row.abbr);
        if (key.length < 2) return;
        const prior = exact.get(key);
        if (!prior || rankRow(row) > rankRow(prior)) exact.set(key, row);
      });
      function expandName(name) {
        return String(name || "").split(/([A-Za-z\u00C0-\u024F][A-Za-z\u00C0-\u024F0-9+&.-]*)/).map((part, index) => {
          if (index % 2 === 0) return part;
          const row = exact.get(compact(part));
          const words = row && !placement(row.en) ? String(row.en).split(",")[0].trim() : "";
          if (!words || words.length > 48 || compact(words) === compact(part)) return part;
          return words.charAt(0).toUpperCase() + words.slice(1);
        }).join("").replace(/\s+/g, " ").trim();
      }
      const known = new Map();
      exact.forEach((row, key) => {
        const en = String(row.en || "").trim();
        const fr = String(row.fr || "").trim();
        const meaning = placement(en) ? expandName(row.abbr) : [en, fr].filter(Boolean).join(" · ");
        known.set(key, { abbr: row.abbr, meaning: meaning });
      });
      rows.forEach((row) => {
        const bits = String(row.abbr + " " + (row.frAbbr || "")).match(/[A-Za-z\u00C0-\u024F][A-Za-z\u00C0-\u024F0-9/+&.-]*/g) || [];
        bits.forEach((part) => {
          if (part !== part.toUpperCase()) return;
          const key = compact(part);
          if (key.length < 2 || key.length > 6 || skipShort.has(key)) return;
          const current = known.get(key);
          if (current && !current.fragment) return;
          const meaning = placement(row.en) ? expandName(row.abbr) : String(row.en || row.abbr).trim();
          const safe = part.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
          if (new RegExp("(?:^|[^A-Za-z])" + safe + "(?:[^A-Za-z]|$)").test(meaning)) return;
          if (!current || String(row.abbr).length < current.from) {
            known.set(key, { abbr: part, meaning: meaning, from: String(row.abbr).length, fragment: true });
          }
        });
      });
      function orgFor(abbr) {
        const key = compact(abbr);
        if (key.length < 2) return null;
        const hits = (V.orgs || []).filter((org) => compact(org.short) === key);
        hits.sort((a, b) => {
          const level = (org) => parseInt(String(org.level).replace(/\D/g, ""), 10) || 99;
          return level(a) - level(b);
        });
        return hits[0] || null;
      }
      function orgLink(abbr) {
        const org = orgFor(abbr);
        if (!org) return "";
        return `<a class="org-jump" href="org.html?node=${encodeURIComponent("org:" + org.id)}">${V.orgIcon(14)}</a>`;
      }
      function linkedAbbr(part, selfKey, tip, spell) {
        const key = compact(part);
        const shortKey = key.length < 3;
        if (key.length < 2 || key === selfKey || !known.has(key)) return V.esc(part);
        if (shortKey && (part !== part.toUpperCase() || skipShort.has(key))) return V.esc(part);
        const hit = known.get(key);
        if (hit.fragment && part !== part.toUpperCase()) return V.esc(part);
        const meaning = String(tip || hit.meaning || "").trim();
        if (!meaning || meaning.toUpperCase() === part.toUpperCase()) return V.esc(part);
        const gloss = meaning.split(" · ")[0];
        const note = spell && gloss && !placement(gloss) && gloss.length <= 80 && gloss.toUpperCase() !== part.toUpperCase()
          ? ` <span class="gloss">(${V.esc(gloss)})</span>` : "";
        return `${orgLink(hit.abbr)}<a href="acronyms.html?abbr=${encodeURIComponent(hit.abbr)}" data-full="${V.esc(meaning)}">${V.esc(part)}</a>${note}`;
      }
      const ordinary = new Set("AUX PAR EST POST LAW CAMP PORT VOL SON LONG PER OFF RED ARM FER CAS ARC MER SOL PAS QUE POUR DANS AVEC SUR SOUS CHEZ VERS ENTRE SANS TOUT CETTE MAIS DONC COMME PLUS MOINS BIEN FAIT SONT AUSSI ALORS AVANT APRES AUTRE MEME PEU TROP PHOTO MASS JAM BAD BAC THE AND FOR WITH FROM THAT THIS NOT BUT CAN HIS HER OUR OUT AIR SEA LAND END NEW OLD DES LES UNE".split(" "));
      function proseWord(part) {
        const letters = String(part).replace(/[^A-Za-z\u00C0-\u024F]/g, "");
        if (!letters || letters === letters.toUpperCase()) return false;
        if (letters === letters.toLowerCase()) return true;
        return ordinary.has(compact(part));
      }
      function linkToken(part, selfKey, spell) {
        if (!part.includes("/")) return linkedAbbr(part, selfKey, "", spell);
        const body = part.split("/").map((bit) => linkedAbbr(bit, selfKey, "", spell)).join(" / ");
        return orgLink(part) + body;
      }
      function linkText(text, selfKey, spell) {
        return String(text || "").split(/([A-Za-z\u00C0-\u024F][A-Za-z\u00C0-\u024F0-9+&.-]*(?:\/[A-Za-z\u00C0-\u024F][A-Za-z\u00C0-\u024F0-9+&.-]*)*)/).map((part, index) => {
          if (index % 2 === 0) return V.esc(part);
          if (proseWord(part)) return V.esc(part);
          return linkToken(part, selfKey, spell);
        }).join("");
      }
      const FR_ONLY = new Set("santé escadron bataillon quartier général générale détachement hôpital campagne régiment aérienne aérien génie transmissions appui unité canadienne canadien canadiennes adjoint commandant peloton compagnie escadre intérimaire dentaire renseignement sous obligatoire militaire".split(" "));
      const EN_ONLY = new Set("headquarters detachment health medical battalion squadron regiment hospital combat acting branch company platoon intelligence wing canadian field battery independent enhanced reliability transformation engineering second command service services group unit".split(" "));
      function wordsOf(text) {
        return new Set(String(text || "").toLowerCase().match(/[a-zà-ÿ0-9']+/g) || []);
      }
      function langOf(abbr, text) {
        const gloss = placement(text) ? "" : String(text || "");
        let fr = 0;
        let en = 0;
        const blob = abbr + " " + gloss;
        if (/[àâäéèêëïîôùûüçœæ]/i.test(blob)) fr += 5;
        if (/\b(de|des|du|la|le|les|aux|au|une|un|et|pour|dans|avec|sous|sur|par)\b/.test(gloss.toLowerCase())) fr += 3;
        if (/\b(d'|l')/.test(gloss.toLowerCase())) fr += 3;
        if (/\b(\d+er|\d+re|\d+e)\b/.test(gloss.toLowerCase())) fr += 3;
        const words = wordsOf(gloss);
        words.forEach((word) => {
          if (FR_ONLY.has(word)) fr += 2;
          if (EN_ONLY.has(word)) en += 2;
        });
        if (/\b(the|of|and|for|with)\b/.test(gloss.toLowerCase())) en += 2;
        const ab = String(abbr || "");
        const mark = (pattern) => new RegExp("\\b(?:" + pattern + ")\\b", "i").test(ab);
        if (mark("Svc\\s+S")) fr += 4;
        if (mark("QG")) fr += 3;
        if (mark("Dét")) fr += 4;
        if (mark("Bon")) fr += 2;
        if (mark("Cie")) fr += 2;
        if (mark("Pon")) fr += 2;
        if (mark("Bie")) fr += 3;
        if (mark("Esc")) fr += 2;
        if (mark("Unité")) fr += 3;
        if (mark("Aut")) fr += 2;
        if (mark("HQ")) en += 3;
        if (mark("Bn")) en += 2;
        if (mark("Sqn")) en += 2;
        if (mark("Svcs")) en += 3;
        if (mark("Med")) en += 3;
        if (mark("Det") && !mark("Dét")) en += 2;
        if (mark("Coy")) en += 2;
        if (mark("Pl")) en += 2;
        if (mark("Bty")) en += 2;
        if (mark("Flt")) en += 2;
        if (mark("Wg")) en += 2;
        if (mark("Fd")) en += 2;
        if (mark("Ind")) en += 2;
        if (mark("Regt")) en += 2;
        if (fr > en) return "fr";
        if (en > fr) return "en";
        return "";
      }
      function makeSide(abbr, text, place) {
        const raw = String(text || "").trim();
        const gloss = placement(raw) ? "" : raw;
        const loc = placement(raw) ? raw : String(place || "").trim();
        const name = String(abbr || "").trim();
        return { abbr: name, text: gloss, place: loc, lang: langOf(name, gloss) };
      }
      function richer(current, next) {
        if (!current) return next;
        if (!next) return current;
        const pick = next.text.length > current.text.length ? next : current;
        const other = pick === next ? current : next;
        return { abbr: pick.abbr || other.abbr, text: pick.text || other.text, place: pick.place || other.place, lang: pick.lang };
      }
      const entries = [];
      const byKey = new Map();
      rows.forEach((row) => {
        let primary = makeSide(row.abbr, row.en, row.place);
        let other = makeSide(row.frAbbr, row.fr, "");
        const sameAbbr = compact(primary.abbr) === compact(other.abbr);
        const sameText = !other.text || other.text.toLowerCase() === primary.text.toLowerCase();
        if (sameAbbr && sameText) other = null;
        if (other && primary.abbr && other.abbr && compact(primary.abbr) !== compact(other.abbr)) {
          if (primary.lang && !other.lang) other.lang = primary.lang === "en" ? "fr" : "en";
          else if (other.lang && !primary.lang) primary.lang = other.lang === "en" ? "fr" : "en";
        }
        const entry = { a: primary.abbr ? primary : null, b: other && other.abbr ? other : null, source: row.source || "" };
        const key = [compact(entry.a && entry.a.abbr), compact(entry.b && entry.b.abbr)].filter(Boolean).sort().join("~");
        const prior = byKey.get(key);
        if (!prior) {
          byKey.set(key, entry);
          entries.push(entry);
          return;
        }
        [entry.a, entry.b].forEach((side) => {
          if (!side || !side.abbr) return;
          const id = compact(side.abbr);
          if (prior.a && compact(prior.a.abbr) === id) prior.a = richer(prior.a, side);
          else if (prior.b && compact(prior.b.abbr) === id) prior.b = richer(prior.b, side);
          else if (!prior.b) prior.b = side;
        });
        const rank = (source) => /manual|TERMIUM/i.test(source) ? 2 : /work item/i.test(source) ? 1 : 0;
        if (rank(entry.source) > rank(prior.source)) prior.source = entry.source;
      });
      function sideHtml(side, tag) {
        if (!side || !side.abbr) return "—";
        const selfKey = compact(side.abbr);
        const gloss = side.text && !placement(side.text) ? side.text : "";
        const head = `<b${gloss ? ` data-full="${V.esc(gloss)}"` : ""}>${V.esc(side.abbr)}</b>`;
        const named = gloss ? " " + linkText(gloss, selfKey, false) : "";
        const place = side.place ? " " + linkText(side.place, selfKey, true) : "";
        const badge = tag ? `<span class="lang-tag">${tag}</span>` : "";
        return `${badge}${orgLink(side.abbr)}${head}${named}${place}`;
      }
      function sidesOf(entry) {
        return [entry.a, entry.b].filter((side) => side && side.abbr);
      }
      function render(list) {
        document.getElementById("list").innerHTML = list.map((entry) => {
          const source = /organization/i.test(entry.source || "") ? "" : V.esc(entry.source || "");
          const sides = sidesOf(entry);
          const known = sides.filter((side) => side.lang === "en" || side.lang === "fr");
          const unknown = sides.filter((side) => side.lang !== "en" && side.lang !== "fr");
          let main = null;
          let alt = null;
          if (known.length) {
            main = known.find((side) => side.lang === lang) || null;
            alt = known.find((side) => side !== main) || unknown[0] || null;
          } else {
            main = unknown[0] || null;
            alt = unknown[1] || null;
          }
          const altTag = alt && alt.lang === "fr" ? "FR" : alt && alt.lang === "en" ? "EN" : "";
          return `<li class="abbr-row"><span class="abbr-main">${sideHtml(main, "")}</span><span class="abbr-other">${sideHtml(alt, altTag)}</span><span class="abbr-src">${source}</span></li>`;
        }).join("");
      }
      let pinned = compact(params.get("abbr") || "");
      function entryHit(entry, key) {
        return sidesOf(entry).some((side) => compact(side.abbr) === key);
      }
      function entryText(entry) {
        return sidesOf(entry).map((side) => side.abbr + " " + side.text + " " + side.place).join(" ");
      }
      function show(query) {
        const key = compact(query);
        if (pinned && key !== pinned) pinned = "";
        const exact = pinned && key === pinned;
        const q = query.trim().toLowerCase();
        let hits = exact
          ? entries.filter((entry) => entryHit(entry, key))
          : q
            ? entries.filter((entry) => entryText(entry).toLowerCase().includes(q))
            : entries;
        if (exact && !hits.length) {
          hits = entries.filter((entry) => sidesOf(entry).some((side) => {
            const abbr = compact(side.abbr);
            return abbr.length > key.length && abbr.includes(key);
          })).sort((a, b) => {
            const len = (entry) => Math.min(...sidesOf(entry).map((side) => compact(side.abbr).length));
            return len(a) - len(b);
          });
        }
        const slice = hits.slice(0, 80);
        document.getElementById("count").textContent = slice.length + " shown of " + hits.length + " · " + entries.length + " in the list";
        render(slice);
      }
      function setLang(next) {
        lang = next === "fr" ? "fr" : "en";
        try { sessionStorage.setItem("vasc-abbr-lang", lang); } catch (error) {}
        document.getElementById("lang-en").setAttribute("aria-pressed", String(lang === "en"));
        document.getElementById("lang-fr").setAttribute("aria-pressed", String(lang === "fr"));
        const url = new URL(location.href);
        url.searchParams.set("lang", lang);
        history.replaceState(history.state, "", url.pathname + url.search);
        show(queryText);
      }
      document.getElementById("lang-en").addEventListener("click", () => setLang("en"));
      document.getElementById("lang-fr").addEventListener("click", () => setLang("fr"));
      V.searchChart = (value) => {
        queryText = String(value || "");
        show(queryText);
      };
      show(queryText);
    })
    .catch(() => {
      document.getElementById("count").textContent = "The abbreviation list did not load.";
    });
  });
})();
