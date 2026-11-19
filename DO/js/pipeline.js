/**
 * The project outline from Projects and Team Management in Azure DevOps.
 *
 * An Epic there moves Planning Stage → In Development → In Production, and
 * carries the six SOP-001 6.1.3 features in the order they are worked. The
 * Project Scores list says the same thing in its own words (Prioritized, In Dev,
 * UAT, Completed), so both are read onto one set of stages here.
 *
 * The six features are sequential, so they are shown and set as one track:
 * choosing a stage marks everything before it done.
 */
(function (root) {
  const V = root.VASC || {};

  const STAGES = [
    { id: "intake", name: "Intake", devops: "New", gloss: "Use case received, not yet decided" },
    { id: "planning", name: "Planning Stage", devops: "Planning Stage", gloss: "Prioritized, being planned" },
    { id: "development", name: "In Development", devops: "In Development", gloss: "Being built or in UAT" },
    { id: "production", name: "In Production", devops: "In Production", gloss: "Live, in maintenance" },
    { id: "continuous", name: "Continuous", devops: "Continuous", gloss: "Ongoing work with no end state" },
    { id: "off", name: "Deferred, declined, or retired", devops: "On Hold / Removed", gloss: "Off the track until the standing agenda reopens it" }
  ];

  const BY_STATUS = {
    "": "intake",
    new: "intake",
    prioritized: "planning",
    "in dev": "development",
    uat: "development",
    completed: "production",
    deferred: "off",
    declined: "off",
    decommissioned: "off"
  };

  const BY_STATE = {
    new: "intake",
    proposed: "intake",
    "planning stage": "planning",
    upcomming: "planning",
    upcoming: "planning",
    "in development": "development",
    active: "development",
    committed: "development",
    "in progress": "development",
    uat: "development",
    "in production": "production",
    resolved: "production",
    closed: "production",
    done: "production",
    completed: "production",
    continuous: "continuous",
    "on hold": "off",
    blocked: "off",
    removed: "off"
  };

  function stageOfStatus(status) {
    const key = String(status || "").toLowerCase().trim().replace(/decom{1,2}issioned/, "decommissioned");
    return BY_STATUS[key] || "intake";
  }

  function stageOfState(state) {
    return BY_STATE[String(state || "").toLowerCase().trim()] || "development";
  }

  function stage(id) {
    return STAGES.find((row) => row.id === id) || STAGES[0];
  }

  const FEATURE_TESTS = [
    ["architecture", /architect/i],
    ["backend", /back[\s-]?end/i],
    ["frontend", /front[\s-]?end/i],
    ["uat", /\buat\b/i],
    ["production", /production|deploy/i],
    ["maintenance", /maintenance/i]
  ];

  const SHORT = {
    architecture: "Architecture",
    backend: "Back-end",
    frontend: "Front-end",
    uat: "UAT",
    production: "Production",
    maintenance: "Maintenance"
  };

  const DONE = /^(resolved|closed|done|completed|in production)$/i;
  const STARTED = /^(active|uat|in development|committed|in progress|blocked|on hold)$/i;

  /** Which DevOps feature under an epic is which of the six stages. */
  function featureOf(title) {
    const hit = FEATURE_TESTS.find((row) => row[1].test(String(title || "")));
    return hit ? hit[0] : "";
  }

  /**
   * steps: [{ id, name, on, devops: { state, workId } | null }]
   * options.mode: "edit" (signed-in reader sets the track), "drill" (each stage
   * opens its DevOps feature), or "read".
   */
  function track(steps, options) {
    const opts = options || {};
    const mode = opts.mode || "read";
    const compact = !!opts.compact;
    const esc = V.esc || ((value) => String(value == null ? "" : value));
    const firstOpen = steps.findIndex((step) => !step.on);
    const doneCount = steps.filter((step) => step.on).length;
    const items = steps.map((step, index) => {
      const devState = step.devops && step.devops.state ? step.devops.state : "";
      const working = !step.on && devState && STARTED.test(devState);
      const state = step.on ? "done" : (index === firstOpen ? "current" : "waiting");
      const classes = ["pipe-step", "is-" + state];
      if (working) classes.push("is-working");
      if (step.devops === null && opts.devopsKnown) classes.push("is-missing");
      const sub = compact ? "" : `<span class="pipe-sub">${devState ? esc(devState) + " in DevOps" : (opts.devopsKnown ? "Not in DevOps" : (step.on ? "Done" : index === firstOpen ? "Next" : "Waiting"))}</span>`;
      const mark = step.on ? "✓" : String(index + 1);
      const label = mode === "edit"
        ? (step.on && (index === steps.length - 1 || !steps[index + 1].on)
          ? "Step back: mark " + step.name + " not done"
          : "Mark done through " + step.name)
        : mode === "drill" ? "Open " + step.name + (devState ? ", " + devState : "") : step.name + (step.on ? ", done" : "");
      const inner = `<span class="pipe-num" aria-hidden="true">${mark}</span><span class="pipe-name" title="${esc(step.name)}">${esc(SHORT[step.id] || step.name)}</span>${sub}`;
      const live = mode === "edit" || (mode === "drill" && step.devops && step.devops.workId);
      const body = live
        ? `<button type="button" class="pipe-hit" data-step="${index}"${step.devops && step.devops.workId ? ` data-work="${esc(step.devops.workId)}"` : ""} aria-label="${esc(label)}"${state === "current" ? ' aria-current="step"' : ""}>${inner}</button>`
        : `<span class="pipe-hit" title="${esc(label)}"${state === "current" ? ' aria-current="step"' : ""}>${inner}</span>`;
      return `<li class="${classes.join(" ")}">${body}</li>`;
    }).join("");
    const next = firstOpen >= 0 ? steps[firstOpen].name : "";
    const summary = compact ? "" : `<p class="pipe-summary">${doneCount} of ${steps.length} done${next ? " · next: " + esc(next) : " · all six done"}</p>`;
    return `<div class="pipe${compact ? " pipe-compact" : ""} pipe-${mode}" role="group" aria-label="${esc(opts.label || "SOP-001 project features")}"${mode === "edit" ? ' tabindex="0"' : ""}>
      <ol class="pipe-track">${items}</ol>
      ${summary}
    </div>`;
  }

  /** The ticks after choosing step `index`: done through it, or one back if it was the last done. */
  function ticksFor(steps, index) {
    let through = index;
    const lead = steps.findIndex((step) => !step.on);
    const lastLead = (lead < 0 ? steps.length : lead) - 1;
    if (index === lastLead) through = index - 1;
    const ticks = {};
    steps.forEach((step, place) => {
      ticks[step.id] = place <= through;
    });
    return ticks;
  }

  /**
   * Arrow keys move the track one stage; the result goes through onPick like a
   * click. `steps` may be a function when the track is re-drawn in place.
   */
  function bind(container, stepsOrRead, onPick) {
    if (!container) return;
    const read = typeof stepsOrRead === "function" ? stepsOrRead : () => stepsOrRead;
    container.addEventListener("click", (event) => {
      const hit = event.target.closest && event.target.closest("button.pipe-hit");
      if (!hit || !container.contains(hit)) return;
      event.preventDefault();
      event.stopPropagation();
      onPick(Number(hit.getAttribute("data-step")), hit.getAttribute("data-work") || "");
    });
    container.addEventListener("keydown", (event) => {
      if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") return;
      const pipe = event.target.closest && event.target.closest(".pipe-edit");
      if (!pipe) return;
      event.preventDefault();
      const steps = read();
      const lead = steps.findIndex((step) => !step.on);
      const done = lead < 0 ? steps.length : lead;
      if (event.key === "ArrowRight" && done < steps.length) onPick(done, "");
      if (event.key === "ArrowLeft" && done > 0) onPick(done - 1, "");
    });
  }

  /** Re-draw inside `container` and put keyboard focus back on the same stage. */
  function keepFocus(container, redraw) {
    const doc = root.document;
    const active = doc && doc.activeElement;
    const inside = active && container && container.contains(active);
    const step = inside && active.getAttribute ? active.getAttribute("data-step") : null;
    const onTrack = inside && active.classList && active.classList.contains("pipe");
    redraw();
    if (!inside || !container) return;
    const back = step != null
      ? container.querySelector(`.pipe button[data-step="${step}"]`)
      : onTrack ? container.querySelector(".pipe-edit") : null;
    if (back) back.focus({ preventScroll: true });
  }

  root.VASC_PIPELINE = {
    keepFocus: keepFocus,
    stages: STAGES,
    stage: stage,
    stageOfStatus: stageOfStatus,
    stageOfState: stageOfState,
    featureOf: featureOf,
    done: (state) => DONE.test(String(state || "")),
    started: (state) => STARTED.test(String(state || "")),
    track: track,
    ticksFor: ticksFor,
    bind: bind
  };
})(typeof window !== "undefined" ? window : globalThis);
