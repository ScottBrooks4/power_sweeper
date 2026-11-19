(function () {
  const D = window.COST_DATA;
  const app = document.getElementById("app");
  const state = { licensing: "guide", horizon: "annual", scale: "vcds_2026", stack: "m365", tab: "effectiveness" };

  const money = (n) =>
    n == null || Number.isNaN(n)
      ? "—"
      : Number(n).toLocaleString("en-CA", { style: "currency", currency: "CAD", maximumFractionDigits: 0 });

  const stackById = Object.fromEntries(D.stacks.map((s) => [s.id, s]));
  const scaleById = Object.fromEntries(D.scales.map((s) => [s.id, s]));

  function rows() {
    return D.rows.filter((r) => r.licensing === state.licensing && r.scale === state.scale);
  }

  function current() {
    return rows().find((r) => r.stack === state.stack) || rows()[0];
  }

  function heightField() {
    return state.horizon === "five" ? "five_year_cad" : "annual_cad";
  }

  function sweetField() {
    return state.horizon === "five" ? "sweet_five" : "sweet_annual";
  }

  function render() {
    const scale = scaleById[state.scale];
    const row = current();
    const field = heightField();
    const max = Math.max(...rows().map((r) => r[field]));
    app.innerHTML = `
      <header>
        <h1>Cost-effectiveness of a ten-person approval-app team for the Vice Chief of the Defence Staff</h1>
        <p class="lede">${escapeHtml(D.effectiveness.headline)}</p>
        <p class="lede">${escapeHtml(D.headline)}</p>
      </header>
      <div class="tabs" id="tabs">
        <button type="button" data-tab="effectiveness" class="${state.tab === "effectiveness" ? "active" : ""}">2030 effectiveness</button>
        <button type="button" data-tab="cost" class="${state.tab === "cost" ? "active" : ""}">Stack cost</button>
        <button type="button" data-tab="build" class="${state.tab === "build" ? "active" : ""}">Build payback</button>
      </div>
      <section class="panel ${state.tab === "effectiveness" ? "active" : ""}" id="panel-effectiveness">
        <div class="card">
          <h2 style="margin-top:0">Projects to 2030 vs mid backlog</h2>
          <p class="note">${escapeHtml(D.effectiveness.backlog.mandate)} ${YEARS_NOTE()} The vertical line is 1 January 2030, ${D.effectiveness.backlog.january_2030_year} years from 30 September 2026. Remaining mid backlog ${D.effectiveness.backlog.remaining.mid} (band ${D.effectiveness.backlog.remaining.low}–${D.effectiveness.backlog.remaining.high}). Legacy apps are converted with Power sweeper, not rebuilt by hand. The bending line is tools getting better through 2030. ${D.effectiveness.plan.hire_people} builders at today's pace and ${D.effectiveness.plan.improve_people} builders if tools improve are both drawn to the mid line. ${escapeHtml(D.effectiveness.backlog.method)}</p>
          <div id="chartPath" class="chart-panel"></div>
          <p class="note">${escapeHtml(D.effectiveness.assistant_key)}</p>
        </div>
        <div class="card" style="margin-top:0.8rem">
          <h2 style="margin-top:0">Cost per project delivered</h2>
          <p class="note">Annual loaded labour plus the coding-assistant list price, divided by the pace after the shell year. Platform licence towers from the stack chart are separate. ${escapeHtml(D.effectiveness.assistant_key)} Citations <a href="#c28">28</a>, <a href="#c29">29</a>, <a href="#c23">23</a>, <a href="#c24">24</a>.</p>
          <div id="chartEff" class="chart-panel"></div>
          <h2>What the VCR files show</h2>
          <p>${escapeHtml(D.effectiveness.vcr.note)}</p>
          <p>You and a student, ${D.effectiveness.vcr.solo_weekdays} weekdays: +${D.effectiveness.vcr.solo_controls} controls, ${Math.round(D.effectiveness.vcr.solo_chars/1000)}k YAML characters. Assisted sessions, ${D.effectiveness.vcr.ai_hours} hours: +${D.effectiveness.vcr.ai_controls} controls, ${Math.round(D.effectiveness.vcr.ai_chars/1000)}k characters. Per hour that is ${D.effectiveness.vcr.char_ratio}× the YAML and ${D.effectiveness.vcr.control_ratio}× the new controls, if those weekdays were ${D.effectiveness.vcr.weekday_hours} hours on the app. Coding is ${Math.round(D.effectiveness.compressible*100)}% of a delivery, so the team multiplier is ${D.effectiveness.vcr.throughput_multiplier}, not ${D.effectiveness.vcr.char_ratio}.</p>
          <p>Headcount you gave, held until the next point: ${D.effectiveness.staffing.steps.map((s) => `${s.years} year${s.years===1?"":"s"} at ${s.people} (${s.label})`).join("; ")}. Now about ${D.effectiveness.staffing.staff_now} staff. This summer, ${D.effectiveness.staffing.summer_students} students for ${D.effectiveness.staffing.summer_months} months, counted as ${D.effectiveness.staffing.person_years} person-years over five years, average ${D.effectiveness.staffing.average_headcount}. Historical cost per project uses that average times today's loaded rate.</p>
          <h2>Technical debt on the 30 already built</h2>
          <p>${escapeHtml(D.effectiveness.debt.note)}</p>
          <p>Replatform with Power sweeper: ${D.effectiveness.debt.replatform_weeks} person-weeks, ${money(D.effectiveness.debt.replatform_cad)}, about ${D.effectiveness.debt.replatform_team_months} months of this team. A hand port would have been ${D.effectiveness.debt.manual_weeks} person-weeks (${money(D.effectiveness.debt.manual_cad)}). That is not the plan. Ongoing care: ${D.effectiveness.debt.care_weeks_per_year} person-weeks a year, about ${Math.round(D.effectiveness.debt.drag * 100)}% of the team, which is already taken off the plan rate below.</p>
          <h2>Plan to reach 2030</h2>
          <ol>${D.effectiveness.plan.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join("")}</ol>
          <p class="note">Ten people if tools improve: about ${D.effectiveness.plan.improve_by_2030} projects by 2030, ending near ${D.effectiveness.plan.improve_end_rate} a year. Tool list price at that date about ${money(D.effectiveness.plan.ai_tools_2030_cad)} a year for ten builders. Builders to clear the mid line at today's pace: ${D.effectiveness.plan.hire_people}. If tools improve: ${D.effectiveness.plan.improve_people}. Half-time people at today's pace: ${D.effectiveness.plan.borrow_people}. Builders if L2 staff take the non-coding quarter: ${D.effectiveness.plan.l2_builders}. Sweeper conversion is ${D.effectiveness.plan.sweeper_weeks} person-weeks, against ${D.effectiveness.plan.manual_weeks} by hand.</p>
          <table class="eff-table"><thead><tr><th>Scenario</th><th>Coding assistant</th><th class="num">/ year</th><th class="num">By 2030</th><th class="num">Gap</th><th class="num">Assistant list price</th><th class="num">$/project</th></tr></thead>
          <tbody>${D.effectiveness.scenarios.map((s) => `<tr>
            <td><strong>${escapeHtml(s.label)}</strong><br><span class="note">${escapeHtml(s.note)}</span></td>
            <td>${escapeHtml(s.assistant)}</td>
            <td class="num">${s.projects_per_year}</td>
            <td class="num">${s.projects_by_2030}</td>
            <td class="num">${s.gap_to_backlog}</td>
            <td class="num">${money(s.ai_tools_cad)}</td>
            <td class="num">${money(s.cost_per_project_cad)}</td>
          </tr>`).join("")}</tbody></table>
          <p class="note">AI tools mid ${money(D.effectiveness.ai_tools_cad.mid)} / year for ten builders (band ${money(D.effectiveness.ai_tools_cad.low)}–${money(D.effectiveness.ai_tools_cad.high)}). Compressible share of build work: ${Math.round(D.effectiveness.compressible * 100)}%.</p>
        </div>
      </section>
      <section class="panel ${state.tab === "cost" ? "active" : ""}" id="panel-cost">
      <div class="controls">
        <label>Audience
          <select id="scale">${D.scales.map((s) => `<option value="${s.id}" ${s.id === state.scale ? "selected" : ""}>${escapeHtml(s.label)}</option>`).join("")}</select>
        </label>
        <label>Height
          <select id="horizon">
            <option value="annual" ${state.horizon === "annual" ? "selected" : ""}>Annual steady state</option>
            <option value="five" ${state.horizon === "five" ? "selected" : ""}>Five years, including the build</option>
          </select>
        </label>
        <label>Premium seats
          <select id="licensing">
            <option value="guide" ${state.licensing === "guide" ? "selected" : ""}>Licensing guide: every opener</option>
            <option value="maker" ${state.licensing === "maker" ? "selected" : ""}>Counterfactual: ten makers only</option>
          </select>
        </label>
      </div>
      <p class="note">${escapeHtml(scale.note)} Labour on every bar is ${money(D.team.loaded_cad)} a year. None of these stacks includes a coding-assistant line. That cost is on the 2030 tab. ${seatScaleNote()}</p>
      <div class="card"><div id="chart3d"></div></div>
      <div class="layout" style="margin-top:0.8rem">
        <div class="card">
          <h2 style="margin-top:0">Same numbers, one audience</h2>
          <div class="bars" id="bars"></div>
        </div>
        <aside class="card" id="detail"></aside>
      </div>
      <h2>Unpriced on purpose</h2>
      <div class="unpriced" id="unpriced"></div>
      </section>
      <section class="panel ${state.tab === "build" ? "active" : ""}" id="panel-build">
      <h2>Build the pieces that are Microsoft 365 today</h2>
      <div id="pieces"></div>
      </section>
      ${D.internal ? internalHtml() : ""}
      <h2>Other places an approval can live</h2>
      <p class="note">These are platform prices. None of them is a coding assistant. The labour line is the same ten people.</p>
      <ul>${D.other_options.map((o) => `<li><strong>${escapeHtml(o.name)}.</strong> Platform ${money(o.platform_cad)} a year at about ${o.openers.toLocaleString("en-CA")} openers. With this team's labour, ${money(o.with_labour_cad)}. ${escapeHtml(o.note)}</li>`).join("")}</ul>
      <h2>Citations</h2>
      <ol class="citations" id="cites">${D.citations.map((c) => `<li id="c${c.id}">${escapeHtml(c.text)}${c.url ? ` <a href="${c.url}">Source</a>` : ""}</li>`).join("")}</ol>
    `;
    if (state.tab === "cost") {
      drawBars(max);
      drawDetail(row);
      drawUnpriced();
      draw3d();
    }
    if (state.tab === "build") drawPieces();
    if (state.tab === "effectiveness") {
      drawPath();
      drawEff();
    }
    bind();
  }

  function YEARS_NOTE() {
    return `About ${D.effectiveness.backlog.years_to_2030} years from September 2026 to the end of 2030.`;
  }

  function drawBars(max) {
    const field = heightField();
    const sweet = sweetField();
    document.getElementById("bars").innerHTML = rows()
      .map((r) => {
        const s = stackById[r.stack];
        const pct = Math.max(1.5, (r[field] / max) * 100);
        const cls = [r.feasible ? "" : "off", r[sweet] ? "sweet" : "", r.you_are_here ? "here" : ""].filter(Boolean).join(" ");
        const pins = [
          r[sweet] ? `<span class="pin sweet">Sweet spot</span>` : "",
          r.you_are_here ? `<span class="pin here">You are here</span>` : "",
          D.internal && r.you_are_here_ai ? `<span class="pin here">AI pin</span>` : "",
        ].join(" ");
        return `<div class="bar-row">
          <button type="button" data-stack="${r.stack}">${escapeHtml(s.short)}</button>
          <div class="track ${cls}"><span style="width:${pct}%"></span></div>
          <div class="amt">${money(r[field])} ${pins}</div>
        </div>`;
      })
      .join("");
  }

  function drawDetail(r) {
    const s = stackById[r.stack];
    const cites = citationIds(r, s);
    const pay =
      r.payback_months == null
        ? "Staying on this stack does not avoid a premium seat. A build pays on licences only when the screen leaves Power Apps, and only if the guide’s per-opener rule is the bill you would otherwise pay."
        : `Payback about ${r.payback_months} months, of which ${r.delay_months} are calendar before any saving starts. That calendar is an author planning range, not a measured DND median.`;
    document.getElementById("detail").innerHTML = `
      <h3>${escapeHtml(s.name)}</h3>
      <p>${r.you_are_here ? "<strong>You are here.</strong> " : ""}${r[sweetField()] ? "<strong>Sweet spot at this audience and this height.</strong> " : ""}${r.feasible ? "" : "<strong>Does not clear the ceiling, so it cannot win.</strong> "}</p>
      <div class="kvs">
        <div><span>Annual, labour plus platform</span>${money(r.annual_cad)}</div>
        <div><span>Labour (same on every priced stack)</span>${money(r.labour_cad)}</div>
        <div><span>Host / capacity (SQL, Fabric, App Service, EC2…)</span>${money(r.host_cad)}</div>
        <div><span>Power Apps Premium seats</span>${money(r.seat_cad)}</div>
        <div><span>Five years, plus the one-time build</span>${money(r.five_year_cad)}</div>
        <div><span>One-time build</span>${money(r.one_time_cad)} (${r.one_time_weeks} person-weeks)</div>
        <div><span>Per app in maintenance (${D.team.apps})</span>${money(r.cost_per_app_cad)}</div>
        <div><span>Per monthly-active opener</span>${money(r.cost_per_active_opener_cad)}</div>
        <div><span>Licence avoided vs premium SQL</span>${money(r.licence_avoided_cad)}</div>
      </div>
      ${seatExplain(r, s)}
      <p>${escapeHtml(r.feasibility_note)}</p>
      <p>${escapeHtml(pay)}</p>
      <h2>Where the bytes sit</h2>
      <p>${escapeHtml(s.compliance.residency)}</p>
      <p>${escapeHtml(s.compliance.inherited)}</p>
      <p>${escapeHtml(s.compliance.still)}</p>
      <p>${escapeHtml(s.compliance.aia)}</p>
      <p class="note">Citations ${cites.map((id) => `<a href="#c${id}">${id}</a>`).join(", ")}</p>
    `;
    document.querySelectorAll(".citations li").forEach((li) => li.classList.remove("hot"));
    cites.forEach((id) => {
      const el = document.getElementById("c" + id);
      if (el) el.classList.add("hot");
    });
  }

  function seatScaleNote() {
    const big = D.rows.find((r) => r.licensing === "guide" && r.stack === "dataverse" && r.scale === "vcds_2020");
    const mid = D.rows.find((r) => r.licensing === "guide" && r.stack === "dataverse" && r.scale === "vcds_2026");
    if (!big || !mid) return "";
    return (
      `A bar near ${money(big.annual_cad)} is not a hosting bill and not an error. It is the 14,241-person row of a premium app (Dataverse, SQL, Fabric, or LOM still inside Power Apps). ` +
      `${money(big.labour_cad)} is the ten people. ${money(big.seat_cad)} is Power Apps Premium seats: US$20 × 14,241 × 12 × ${D.fx.usd_cad}. ` +
      `At 10,500 openers that seat line is ${money(mid.seat_cad)}, so the bar is about ${money(mid.annual_cad)}. The database or Fabric capacity is a few thousand dollars.`
    );
  }

  function seatExplain(r, s) {
    if (!s.premium) {
      return `<p class="note">No Power Apps Premium seat on this stack. Labour is about ${money(r.labour_cad)}. The host line is ${money(r.host_cad)}.</p>`;
    }
    const openers = r.openers.toLocaleString("en-CA");
    return `<p><strong>Where the extra ~${money(r.seat_cad)} comes from.</strong> Dataverse, SQL, Fabric, and a LOM custom connector are premium (or custom) connectors. Under the June 2026 licensing guide that makes the <em>app</em> Premium, so each person who opens it needs a seat — not only the ten builders. Guide figure: US$20 × ${openers} openers × 12 months × ${D.fx.usd_cad} USD/CAD = ${money(r.guide_seat_cad)}. The Azure SQL or Fabric host itself is only ${money(r.host_cad)}. Maker-only counterfactual for the same stack: ${money(r.maker_seat_cad)}. Citations <a href="#c4">4</a>, <a href="#c5">5</a>.</p>`;
  }

  function citationIds(r, s) {
    const ids = new Set([1, 2, 3, 10, 11, 12, 27]);
    if (s.premium || state.licensing === "maker") ids.add(4).add(5);
    if (["sql", "fabric", "web_azure", "web_lom", "lom_connector"].includes(r.stack)) ids.add(6);
    if (r.stack === "web_aws") ids.add(7).add(16).add(18);
    if (s.id === "m365" || r.stack === "m365") ids.add(8).add(9).add(17);
    if (r.stack === "lom_connector" || r.stack === "web_lom") ids.add(26);
    if (r.stack === "adm" || r.stack === "ssc") ids.add(22).add(25);
    ids.add(14).add(15).add(19).add(21);
    return [...ids].sort((a, b) => a - b);
  }

  function drawUnpriced() {
    document.getElementById("unpriced").innerHTML = D.stacks
      .filter((s) => !s.priced)
      .map(
        (s) =>
          `<button type="button" class="ghost" data-unpriced="${s.id}">${escapeHtml(s.short)}</button>`
      )
      .join("");
  }

  function drawPieces() {
    document.getElementById("pieces").innerHTML = D.pieces
      .map((p) => {
        const pay =
          p.payback_months == null
            ? "Does not pay back on licences."
            : `Payback about ${p.payback_months} months, including ${p.delay_months} months before savings start.`;
        const adj = D.internal ? ` Unadjusted mid was ${p.weeks_unadjusted} person-weeks.` : "";
        return `<div class="piece"><strong>${escapeHtml(p.title)}</strong> Person-weeks ${p.weeks_low} / ${p.weeks_mid} / ${p.weeks_high}. One-time ${money(p.one_time_cad)}. Avoided per year at ${p.openers.toLocaleString("en-CA")} openers: ${money(p.avoided_cad)}. ${pay}${adj} ${escapeHtml(p.why)}</div>`;
      })
      .join("");
  }

  function internalHtml() {
    const rows = D.tornado
      .map(
        (t) =>
          `<tr><td>${escapeHtml(t.name)}</td><td>${money(t.swing_cad)}</td><td>${money(t.low_gap_cad)}</td><td>${money(t.high_gap_cad)}</td></tr>`
      )
      .join("");
    return `
      <h2>What actually moves the five-year gap</h2>
      <p class="note">Gap between M365 screens on Azure SQL and a custom web app on Azure, at about 10,500 openers, licensing guide, five years. Mid gap ${money(D.tornado[0].mid_gap_cad)}. A swing near zero means that input hits both sides the same way.</p>
      <table class="tornado"><thead><tr><th>Input</th><th>Swing</th><th>Low gap</th><th>High gap</th></tr></thead><tbody>${rows}</tbody></table>
      <h2>Productivity annex</h2>
      <p>${escapeHtml(D.ai.text)}</p>
      <p>Compressible share ${D.ai.compressible}. VCR measured ${D.ai.priors.vcr} on the coding share. Upper sensitivity ${D.ai.priors.upper}. Citation <a href="#c29">29</a>.</p>
    `;
  }

  function bind() {
    document.querySelectorAll("[data-tab]").forEach((btn) => {
      btn.onclick = () => {
        state.tab = btn.dataset.tab;
        render();
      };
    });
    const scaleEl = document.getElementById("scale");
    if (scaleEl) {
      scaleEl.onchange = (e) => {
        state.scale = e.target.value;
        render();
      };
    }
    const horizonEl = document.getElementById("horizon");
    if (horizonEl) {
      horizonEl.onchange = (e) => {
        state.horizon = e.target.value;
        render();
      };
    }
    const licensingEl = document.getElementById("licensing");
    if (licensingEl) {
      licensingEl.onchange = (e) => {
        state.licensing = e.target.value;
        render();
      };
    }
    document.querySelectorAll("[data-stack]").forEach((btn) => {
      btn.onclick = () => selectStack(btn.dataset.stack);
    });
    document.querySelectorAll("[data-unpriced]").forEach((btn) => {
      btn.onclick = () => showUnpriced(btn.dataset.unpriced);
    });
  }

  function drawPath() {
    const el = document.getElementById("chartPath");
    if (!window.Plotly || !el) return;
    const backlog = D.effectiveness.backlog.remaining.mid;
    const low = D.effectiveness.backlog.remaining.low;
    const colors = {
      vcr: "#8a6a22",
      hire: "#1f6b4a",
      borrow: "#2c4a6e",
      l2: "#6b4a8a",
      l2ten: "#3d6b8a",
      improve: "#0e7490",
      improve_hire: "#155e75",
    };
    const traces = D.effectiveness.paths.map((p) => ({
      x: p.points.map((pt) => pt.year),
      y: p.points.map((pt) => pt.projects),
      mode: "lines",
      name: p.label,
      line: {
        width: p.id === "improve" || p.meets ? 2.5 : 2,
        color: colors[p.id] || "#5c564c",
        dash: p.id === "vcr" ? "dot" : "solid",
      },
    }));
    traces.push({
      x: [0, D.effectiveness.backlog.years_to_2030],
      y: [backlog, backlog],
      mode: "lines",
      name: "Mid backlog",
      line: { dash: "dash", color: "#8c3d2a", width: 2 },
    });
    traces.push({
      x: [0, D.effectiveness.backlog.years_to_2030],
      y: [low, low],
      mode: "lines",
      name: "Low backlog",
      line: { dash: "dash", color: "#b08968", width: 1 },
    });
    const jan = D.effectiveness.backlog.january_2030_year;
    const yTop = Math.max(
      backlog,
      ...D.effectiveness.paths.flatMap((p) => p.points.map((pt) => pt.projects))
    );
    traces.push({
      x: [jan, jan],
      y: [0, yTop],
      mode: "lines",
      name: "1 January 2030",
      line: { color: "#1c1915", width: 1.5, dash: "dot" },
      hovertemplate: "1 January 2030<extra></extra>",
    });
    Plotly.newPlot(
      el,
      traces,
      {
        margin: { l: 72, r: 16, t: 108, b: 48 },
        paper_bgcolor: "#fffdf8",
        plot_bgcolor: "#fffdf8",
        xaxis: { title: "Years from 30 September 2026" },
        yaxis: { title: "Projects delivered" },
        legend: { orientation: "h", y: 1.18, x: 0 },
      },
      { displayModeBar: false, responsive: true }
    );
  }

  function drawEff() {
    const el = document.getElementById("chartEff");
    if (!window.Plotly || !el) return;
    const scenarios = D.effectiveness.scenarios;
    Plotly.newPlot(
      el,
      [
        {
          type: "bar",
          orientation: "h",
          y: scenarios.map((s) => s.label),
          x: scenarios.map((s) => s.cost_per_project_cad),
          text: scenarios.map((s) => money(s.cost_per_project_cad)),
          textposition: "auto",
          marker: {
            color: scenarios.map((s) =>
              s.meets ? "#1f6b4a" : s.id === "vcr" ? "#8a6a22" : s.id === "historical" ? "#5c564c" : "#2c4a6e"
            ),
          },
          hovertext: scenarios.map(
            (s) =>
              `${s.projects_per_year}/year · AI ${money(s.ai_tools_cad)} · gap ${s.gap_to_backlog}`
          ),
        },
      ],
      {
        margin: { l: 280, r: 30, t: 10, b: 40 },
        paper_bgcolor: "#fffdf8",
        plot_bgcolor: "#fffdf8",
        xaxis: { title: "CAD per project delivered" },
      },
      { displayModeBar: false, responsive: true }
    );
  }

  function selectStack(id) {
    state.stack = id;
    drawDetail(current());
    document.querySelectorAll(".bar-row button").forEach((b) => {
      b.style.fontWeight = b.dataset.stack === id ? "700" : "400";
    });
  }

  function showUnpriced(id) {
    const s = stackById[id];
    document.getElementById("detail").innerHTML = `
      <h3>${escapeHtml(s.name)}</h3>
      <p>No dollar bar. Publishing a unit price would be an invention.</p>
      <p>${escapeHtml(s.compliance.residency)}</p>
      <p>${escapeHtml(s.compliance.inherited)}</p>
      <p>${escapeHtml(s.compliance.still)}</p>
      <p>${escapeHtml(s.compliance.aia)}</p>
    `;
    citationIds({ stack: id }, s).forEach((cid) => {
      const el = document.getElementById("c" + cid);
      if (el) el.classList.add("hot");
    });
  }

  function draw3d() {
    const el = document.getElementById("chart3d");
    if (!window.Plotly) {
      el.innerHTML = "<p class=\"note\">The 3D chart needs Plotly. The bars beside this note are the same numbers.</p>";
      return;
    }
    const field = heightField();
    const sweet = sweetField();
    // Depth: small audience closest to the camera so tall VCDS bars do not hide it.
    const nScales = D.scales.length;
    const scaleIndex = Object.fromEntries(D.scales.map((s, i) => [s.id, nScales - 1 - i]));
    const priced = D.stacks.filter((s) => s.priced);
    const xIndex = Object.fromEntries(priced.map((s, i) => [s.id, i]));
    const traces = [];
    D.rows
      .filter((r) => r.licensing === state.licensing)
      .forEach((r) => {
        const xi = xIndex[r.stack];
        const yi = scaleIndex[r.scale];
        if (xi == null) return;
        const z = r[field] / 1e6;
        let color = "#2c4a6e";
        if (!r.feasible) color = "#b7b1a6";
        if (r[sweet]) color = "#8a6a22";
        if (r.you_are_here) color = "#8c3d2a";
        traces.push(cube(xi, yi, Math.max(z, 0.02), color, r));
      });
    const yTicks = D.scales
      .map((s, i) => ({ y: nScales - 1 - i + 0.35, label: s.openers.toLocaleString("en-CA") }))
      .sort((a, b) => a.y - b.y);
    const layout = {
      margin: { l: 0, r: 0, t: 8, b: 0 },
      paper_bgcolor: "#fffdf8",
      scene: {
        aspectmode: "manual",
        aspectratio: { x: 2.4, y: 0.72, z: 0.7 },
        camera: { eye: { x: 0.15, y: 2.15, z: 0.72 }, center: { x: 0, y: 0, z: -0.08 } },
        xaxis: {
          title: "Stack",
          tickmode: "array",
          tickvals: priced.map((_, i) => i + 0.35),
          ticktext: priced.map((s) => s.short),
          tickfont: { size: 11, color: "#1c1915" },
          range: [-0.25, priced.length - 0.05],
        },
        yaxis: {
          title: "Audience (200 nearest)",
          tickvals: yTicks.map((t) => t.y),
          ticktext: yTicks.map((t) => t.label),
          range: [-0.2, nScales - 0.15],
        },
        zaxis: { title: state.horizon === "five" ? "Five-year CAD, millions" : "Annual CAD, millions" },
      },
      showlegend: false,
    };
    Plotly.newPlot(el, traces, layout, {
      displayModeBar: false,
      responsive: true,
      scrollZoom: false,
    });
    el.on("plotly_click", (ev) => {
      const pt = ev.points && ev.points[0];
      if (!pt || !pt.data || !pt.data.meta) return;
      const meta = pt.data.meta;
      if (meta.scale !== state.scale) {
        state.scale = meta.scale;
        state.stack = meta.stack;
        render();
        return;
      }
      selectStack(meta.stack);
    });
  }

  function cube(x, y, z, color, row) {
    const dx = 0.7;
    const dy = 0.7;
    // Eight corners: bottom 0–3, top 4–7.
    const X = [x, x + dx, x + dx, x, x, x + dx, x + dx, x];
    const Y = [y, y, y + dy, y + dy, y, y, y + dy, y + dy];
    const Z = [0, 0, 0, 0, z, z, z, z];
    // Twelve triangles = six solid faces (no internal diagonals that read as a wire cage).
    const i = [0, 0, 4, 4, 0, 0, 1, 1, 2, 2, 3, 3];
    const j = [1, 2, 5, 6, 1, 5, 2, 6, 3, 7, 0, 4];
    const k = [2, 3, 6, 7, 5, 4, 6, 5, 7, 6, 4, 7];
    return {
      type: "mesh3d",
      x: X,
      y: Y,
      z: Z,
      i,
      j,
      k,
      color,
      opacity: 1,
      flatshading: true,
      lighting: {
        ambient: 0.92,
        diffuse: 0.55,
        specular: 0.05,
        roughness: 0.95,
        fresnel: 0.02,
      },
      lightposition: { x: 1200, y: 1600, z: 2200 },
      hoverinfo: "text",
      text: `${stackById[row.stack].short}<br>${row.openers.toLocaleString("en-CA")} openers<br>${money(row[heightField()])}`,
      meta: { stack: row.stack, scale: row.scale },
    };
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  render();
})();
