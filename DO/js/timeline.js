(function () {
  const V = window.VASC;
  const DONE = new Set(["Resolved", "Closed", "Done", "Completed", "Removed"]);
  const STEPS = 12;

  function monthKey(iso) {
    return String(iso || "").slice(0, 7);
  }

  function amount(item, mode) {
    if (mode === "strategic" && window.VASC_SCORE) {
      return window.VASC_SCORE.scoreItem({
        title: item.title,
        description: item.text,
        type: item.type,
        area: ""
      }, "").strategic;
    }
    return Number(item.hours) || 0;
  }

  function partsOf(item) {
    const used = Math.max(0, Number(item.used != null ? item.used : item.hours) || 0);
    const needed = Math.max(0, Number(item.needed) || 0);
    const left = item.left == null || item.left === "" ? null : Math.max(0, Number(item.left) || 0);
    let remain = 0;
    if (needed > used) remain = needed - used;
    else if (!needed && left) remain = left;
    return { done: used, need: remain };
  }

  function itemHref(id) {
    const num = Number(id) || 0;
    if (!num) return "";
    const hit = (V.cases || []).find((row) => String(row.workId) === String(num));
    if (hit) return "case.html?id=" + encodeURIComponent(hit.id);
    return "pm.html?item=" + encodeURIComponent("wit:" + num);
  }

  function monthFromUs(text) {
    const match = String(text || "").match(/(\d{1,2})\/(\d{1,2})\/(\d{4})/);
    if (!match) return "";
    return match[3] + "-" + String(match[1]).padStart(2, "0");
  }

  function projectsAt(month, source) {
    const byId = new Map((source || []).map((item) => [String(item.id), item]));
    return (V.cases || []).map((row) => {
      const created = monthFromUs(row.created);
      if (month && created && created > month) return null;
      const work = row.workId ? byId.get(String(row.workId)) : null;
      const changed = work ? monthKey(work.changed) : "";
      let stage = "planning";
      if (row.implemented && monthFromUs(row.implemented) && (!month || monthFromUs(row.implemented) <= month)) stage = "production";
      else if (work && DONE.has(work.state) && (!month || !changed || changed <= month)) stage = "production";
      else if (row.workId && (!work || !month || !changed || changed <= month)) stage = "development";
      const scored = window.VASC_SCORE.scoreCase(row, window.VASC_SCORE.edits[row.id]);
      const hours = work ? partsOf(work) : { done: 0, need: 0 };
      return {
        id: row.id,
        title: row.title || "Untitled",
        type: window.VASC_SCORE.stageLabel(stage),
        stage: stage,
        value: scored.score,
        done: hours.done,
        need: hours.need,
        href: "case.html?id=" + encodeURIComponent(row.id)
      };
    }).filter(Boolean);
  }

  function labelFor(value, mode) {
    if (mode === "strategic") return String(value);
    return (Math.round(value * 10) / 10) + " h";
  }

  function axisScale(max) {
    const safe = max > 0 ? max : 1;
    const rough = safe / 4;
    const pow = Math.pow(10, Math.floor(Math.log10(rough)));
    const err = rough / pow;
    const nice = err <= 1 ? 1 : err <= 2 ? 2 : err <= 2.5 ? 2.5 : err <= 5 ? 5 : 10;
    const step = Math.round(nice * pow * 1000) / 1000;
    const top = Math.round(Math.max(step, Math.ceil((safe - step * 1e-9) / step) * step) * 1000) / 1000;
    const values = [];
    for (let i = 0; i * step <= top + step * 1e-6 && values.length < 9; i += 1) {
      values.push(Math.round(i * step * 1000) / 1000);
    }
    return { top: top, step: step, values: values };
  }

  function axisText(value, step, unit, isTop) {
    const places = Math.min(3, (String(step).split(".")[1] || "").length);
    const factor = 10 ** places;
    const shown = String(Math.round(value * factor) / factor);
    if (isTop && unit === "hours") return shown + " h";
    return shown;
  }

  V.ready.then(() => {
    const stage = document.querySelector(".stage");
    if (!V.requireVasc(stage)) return;
    let items = [];
    let months = [];
    let index = 0;
    let frac = 1;
    let mode = "hours";
    let timer = 0;
    let note = "";
    let monthOf = new Map();
    let weekOffset = 0;

    function mondayOf(date) {
      const day = new Date(date.getFullYear(), date.getMonth(), date.getDate());
      const shift = (day.getDay() + 6) % 7;
      day.setDate(day.getDate() - shift);
      return day;
    }

    function isoDay(date) {
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, "0");
      const d = String(date.getDate()).padStart(2, "0");
      return y + "-" + m + "-" + d;
    }

    function addDays(date, count) {
      const next = new Date(date.getFullYear(), date.getMonth(), date.getDate());
      next.setDate(next.getDate() + count);
      return next;
    }

    function rollingCalendar() {
      const start = addDays(mondayOf(new Date()), weekOffset * 7);
      const today = isoDay(new Date());
      const weeks = [];
      for (let w = 0; w < 6; w += 1) {
        const from = addDays(start, w * 7);
        const to = addDays(from, 6);
        const fromKey = isoDay(from);
        const toKey = isoDay(to);
        const rows = items.filter((item) => item.changed && item.changed >= fromKey && item.changed <= toKey)
          .sort((a, b) => (b.hours || 0) - (a.hours || 0) || String(a.title).localeCompare(String(b.title)))
          .slice(0, 5);
        const current = today >= fromKey && today <= toKey;
        const label = from.toLocaleDateString("en-CA", { month: "short", day: "numeric" });
        const list = rows.length
          ? `<ul>${rows.map((item) => {
            const href = itemHref(item.id);
            const title = V.esc(item.title || "Work item");
            const link = href ? `<a href="${href}">${title}</a>` : title;
            return `<li>${link}</li>`;
          }).join("")}</ul>`
          : `<p class="note">No recorded change.</p>`;
        weeks.push(`<section class="roll-week${current ? " is-now" : ""}"><h3>${label}${current ? " · this week" : ""}</h3>${list}</section>`);
      }
      const end = addDays(start, 41);
      return `<div class="roll-nav">
          <button class="btn" type="button" id="roll-prev">Earlier</button>
          <button class="btn" type="button" id="roll-next">Later</button>
          <span class="note">${isoDay(start)} to ${isoDay(end)}. Six weeks, rolling from this week.</span>
        </div>
        <div class="roll-cal">${weeks.join("")}</div>`;
    }

    function partsAt(item) {
      const at = monthOf.get(item.id);
      if (at === undefined || at > index) return { done: 0, need: 0 };
      const scale = at < index ? 1 : frac;
      if (mode === "strategic") {
        const value = Math.max(0, amount(item, mode)) * scale;
        if (!(value > 0)) return { done: 0, need: 0 };
        return DONE.has(item.state) ? { done: value, need: 0 } : { done: 0, need: value };
      }
      const parts = partsOf(item);
      return { done: parts.done * scale, need: parts.need * scale };
    }

    function rowFrom(item, parts) {
      return {
        id: item.id,
        title: item.title || "Untitled",
        type: item.type || "Other",
        done: parts.done,
        need: parts.need,
        value: parts.done + parts.need,
        href: itemHref(item.id)
      };
    }

    function frameNow() {
      const visible = [];
      items.forEach((item) => {
        const parts = partsAt(item);
        if (parts.done + parts.need > 0) visible.push(rowFrom(item, parts));
      });
      visible.sort((a, b) => b.value - a.value || a.title.localeCompare(b.title));
      const sky = visible.slice(0, 64).sort((a, b) => a.id - b.id);
      function leaders(pred) {
        return items.filter((item) => {
          const parts = partsAt(item);
          return parts.done + parts.need > 0 && pred(item);
        })
          .map((item) => rowFrom(item, partsAt(item)))
          .sort((a, b) => b.value - a.value)
          .slice(0, 6);
      }
      const projects = projectsAt(months[index] || "", items);
      function stageLeaders(stage) {
        return projects.filter((row) => row.stage === stage).sort((a, b) => b.value - a.value || a.title.localeCompare(b.title)).slice(0, 8);
      }
      return {
        sky: sky,
        lead: visible[0] || null,
        done: stageLeaders("production"),
        work: stageLeaders("development"),
        cases: stageLeaders("planning")
      };
    }

    function portionTip(row, score) {
      const bits = [row.title];
      if (row.stage === "planning") bits.push("Planning");
      if (row.done > 0) bits.push("Work done " + labelFor(row.done, "hours"));
      if (row.need > 0) bits.push("Work needed " + labelFor(row.need, "hours"));
      if (score != null) bits.push("score " + score);
      return bits.join(" · ");
    }

    function fillSpans(kind, row) {
      const hours = (row.done || 0) + (row.need || 0);
      if (kind === "need" && hours > 0) {
        const doneW = (row.done / hours) * 100;
        const needW = 100 - doneW;
        return `${row.done > 0 ? `<i class="seg seg-done" style="width:${doneW}%"></i>` : ""}${row.need > 0 ? `<i class="seg seg-need" style="width:${needW}%"></i>` : ""}`;
      }
      const seg = kind === "done" ? "done" : kind === "need" ? "need" : "case";
      return `<i class="seg seg-${seg}" style="width:100%"></i>`;
    }

    function bars(rows, kind) {
      const max = rows.reduce((best, row) => Math.max(best, row.value), 0) || 1;
      return rows.map((row) => {
        const width = Math.max(2, Math.round((row.value / max) * 100));
        const name = row.href
          ? `<a class="race-name" href="${row.href}">${V.esc(row.title)}</a>`
          : `<span class="race-name">${V.esc(row.title)}</span>`;
        const shown = String(Math.round(row.value * 10) / 10);
        const tip = portionTip(row, shown);
        return `<div class="race-bar" title="${V.esc(tip)}">${name}<div class="race-track"><span class="race-fill" style="width:${width}%">${fillSpans(kind, row)}</span></div><em>${V.esc(shown)}</em></div>`;
      }).join("");
    }

    function whenText() {
      const month = months[index] || "";
      if (!month) return "—";
      if (frac >= 0.999) return month;
      const day = Math.min(28, Math.max(1, Math.round(frac * 27) + 1));
      return month + "-" + String(day).padStart(2, "0");
    }

    function paint() {
      const frame = frameNow();
      const sky = document.getElementById("race-sky");
      const max = frame.sky.reduce((best, row) => Math.max(best, row.value), 0) || 1;
      const scale = axisScale(max);
      if (sky) {
        const lead = frame.lead;
        const leadHtml = lead
          ? (lead.href ? `<a class="sky-lead" href="${lead.href}">${V.esc(lead.title)}</a>` : `<span class="sky-lead">${V.esc(lead.title)}</span>`)
          : "";
        const ticks = scale.values.map((value, place, all) => {
          const y = (value / scale.top) * 100;
          const end = place === 0 ? "base" : place === all.length - 1 ? "top" : "";
          const mark = end ? ` data-end="${end}"` : "";
          return { y: y, mark: mark, text: axisText(value, scale.step, mode, place === all.length - 1) };
        });
        const axis = ticks.map((tick) => `<span style="--y:${tick.y}%"${tick.mark}>${V.esc(tick.text)}</span>`).join("");
        const grid = ticks.map((tick) => `<i style="--y:${tick.y}%"${tick.mark}></i>`).join("");
        const widest = ticks.reduce((best, tick) => Math.max(best, tick.text.length), 1);
        const cols = frame.sky.map((row) => {
          const height = (row.value / scale.top) * 100;
          const bits = [row.title];
          if (row.done > 0) bits.push("Work done " + labelFor(row.done, mode));
          if (row.need > 0) bits.push("Work needed " + labelFor(row.need, mode));
          const tip = bits.join(" · ");
          const segs = [
            row.done > 0 ? `<span class="seg seg-done" style="flex-grow:${row.done}"></span>` : "",
            row.need > 0 ? `<span class="seg seg-need" style="flex-grow:${row.need}"></span>` : ""
          ].join("");
          const tag = row.href ? "a" : "span";
          const href = row.href ? ` href="${row.href}"` : "";
          return `<${tag} class="sky-col"${href} title="${V.esc(tip)}" style="--h:${height}%"><i class="sky-stack">${segs}</i><b class="sky-name">${V.esc(row.title)}</b></${tag}>`;
        }).join("");
        const plot = cols
          ? `<div class="sky-grid">${grid}</div>${cols}`
          : `<p class="note">Nothing with a value yet.</p>`;
        sky.innerHTML = `<div class="sky-head">${leadHtml}<span class="sky-when">${V.esc(whenText())}</span></div><div class="sky-body"><div class="sky-axis" style="--w:${widest}" aria-hidden="true">${axis}</div><div class="sky-plot">${plot}</div></div>`;
      }
      const done = document.getElementById("race-done");
      const work = document.getElementById("race-work");
      const cases = document.getElementById("race-cases");
      if (done) done.innerHTML = bars(frame.done, "done") || "<p class=\"note\">None yet.</p>";
      if (work) work.innerHTML = bars(frame.work, "need") || "<p class=\"note\">None yet.</p>";
      if (cases) cases.innerHTML = bars(frame.cases, "case") || "<p class=\"note\">None yet.</p>";
      const when = document.getElementById("race-when");
      if (when) when.textContent = whenText();
    }

    function setPlayLabel() {
      const play = document.getElementById("race-play");
      if (!play) return;
      play.textContent = timer ? "Pause" : "Play";
      play.setAttribute("aria-pressed", timer ? "true" : "false");
    }

    function stop() {
      if (!timer) return;
      clearInterval(timer);
      timer = 0;
      setPlayLabel();
    }

    function mount() {
      const unit = mode === "strategic" ? "strategic value" : "hours";
      stage.innerHTML = `
        <section class="lede"><h1>Timeline</h1>
          <p>Each project follows the SOP epic states: Planning, then In Development, then In Production. Green is work done and gold is work still needed, stacked the way a Gantt bar shows progress. Blue is a project still in planning. The main bars are ordered by ${unit}. The three lists are the stages, ordered by score.</p>
          <p class="sky-legend"><span><i class="seg seg-case"></i>Planning</span><span><i class="seg seg-need"></i>Work needed</span><span><i class="seg seg-done"></i>Work done</span></p>
          <p><label>Order <select id="race-sort"><option value="hours"${mode === "hours" ? " selected" : ""}>Hours</option><option value="strategic"${mode === "strategic" ? " selected" : ""}>Strategic</option></select></label>
          ${note ? `<span class="note">${V.esc(note)}</span>` : ""}</p>
        </section>
        <section class="panel"><h2>Rolling calendar</h2>
          <p>Work items that changed in each week. Earlier and Later move the window by one week.</p>
          ${rollingCalendar()}
        </section>
        <div class="race">
          <section class="race-main" aria-label="Work and intake">
            <div class="sky" id="race-sky"></div>
          </section>
          <aside class="race-side">
            <section><h2><i class="seg seg-done"></i>In Production</h2><div id="race-done"></div></section>
            <section><h2><i class="seg seg-need"></i>In Development</h2><div id="race-work"></div></section>
            <section><h2><i class="seg seg-case"></i>Planning</h2><div id="race-cases"></div></section>
          </aside>
        </div>
        <div class="race-time">
          <button class="btn" id="race-play" type="button" aria-pressed="false">Play</button>
          <input id="race-range" type="range" min="0" max="${Math.max(months.length - 1, 0)}" value="${index}" aria-label="Month">
          <span id="race-when"></span>
        </div>`;
      document.getElementById("roll-prev").addEventListener("click", () => {
        weekOffset -= 1;
        mount();
      });
      document.getElementById("roll-next").addEventListener("click", () => {
        weekOffset += 1;
        mount();
      });
      document.getElementById("race-sort").addEventListener("change", (event) => {
        mode = event.target.value;
        mount();
      });
      document.getElementById("race-range").addEventListener("input", (event) => {
        stop();
        index = Number(event.target.value) || 0;
        frac = 1;
        paint();
      });
      document.getElementById("race-play").addEventListener("click", () => {
        if (timer) {
          stop();
          return;
        }
        if (index >= months.length - 1 && frac >= 1) {
          index = 0;
          frac = 0;
          const range = document.getElementById("race-range");
          if (range) range.value = "0";
        }
        timer = setInterval(() => {
          if (index >= months.length - 1 && frac >= 1) {
            stop();
            return;
          }
          frac += 1 / STEPS;
          if (frac > 1) {
            if (index >= months.length - 1) {
              frac = 1;
              stop();
              paint();
              return;
            }
            index += 1;
            frac = 1 / STEPS;
            const range = document.getElementById("race-range");
            if (range) range.value = String(index);
          }
          paint();
        }, 80);
        setPlayLabel();
        paint();
      });
      paint();
    }

    stage.innerHTML = `<section class="lede"><h1>Timeline</h1><p>Reading the saved work items…</p></section>`;
    Promise.all([V.devops("timeline"), window.VASC_SCORE.load(), V.caseLinks()]).then(([data]) => {
      items = data.items || [];
      note = data.note || "";
      const keys = new Set();
      items.forEach((item) => {
        const key = monthKey(item.changed);
        if (/^\d{4}-\d{2}$/.test(key)) keys.add(key);
      });
      months = [...keys].sort();
      monthOf = new Map();
      const at = new Map(months.map((month, place) => [month, place]));
      items.forEach((item) => {
        const place = at.get(monthKey(item.changed));
        if (place !== undefined) monthOf.set(item.id, place);
      });
      index = Math.max(months.length - 1, 0);
      frac = 1;
      mount();
    }).catch((error) => {
      if (error.login) {
        V.promptLogin();
        return;
      }
      stage.innerHTML = `<section class="panel"><h1>Timeline</h1><p>${V.esc(error.message)}</p></section>`;
    });
  });
})();
