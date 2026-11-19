(function () {
  // A working week, for estimates recorded on the list in weeks or days.
  const WEEK = 37.5;
  const DAY = 7.5;

  function key(value) {
    return String(value || "").toLowerCase().replace(/[^a-z0-9]+/g, " ").trim();
  }

  /**
   * A first hour budget read from the use case itself, for work DevOps has not
   * started. The number is a staffing sketch, not a quote: a small tool is 40
   * hours, and the description adds hours when it asks for more.
   */
  function estimate(row) {
    const blob = [row.title, row.overview, row.problem, row.outcome, row.notes, row.category].filter(Boolean).join(" ").toLowerCase();
    let total = 40;
    const why = ["A small tool starts at 40 hours."];
    function add(amount, reason, hit) {
      if (!hit) return;
      total += amount;
      why.push(reason);
    }
    add(40, "A report or dashboard adds 40.", /power\s*bi|dashboard|report|scorecard/.test(blob));
    add(60, "A workflow or automation adds 60.", /automat|workflow|approval|process/.test(blob));
    add(80, "Connecting another system adds 80.", /integrat|dataverse|sharepoint|drmis|\bsap\b|interface|\bapi\b/.test(blob));
    add(80, "An organization-wide reach adds 80.", /enterprise|defence-wide|defense-wide|all l1s|l1\/org/.test(blob) || /^(l0|l0\.5|l1)$/i.test(row.level || ""));
    add(40, "An L2 reach adds 40.", String(row.level || "").toUpperCase() === "L2");
    const words = blob.split(/\s+/).filter(Boolean).length;
    if (words > 120) {
      const extra = Math.min(80, Math.round((words - 120) / 80) * 20);
      if (extra) {
        total += extra;
        why.push("A longer description adds " + extra + ".");
      }
    }
    total = Math.max(24, Math.min(640, total));
    return { hours: total, why: why.join(" ") };
  }

  /** The Estimate column of the intake list ("6 weeks", "120 h", "3 days"), in hours. */
  function recorded(text) {
    const match = String(text || "").toLowerCase().match(/(\d+(?:\.\d+)?)\s*(h|hr|hrs|hours?|d|days?|w|wks?|weeks?|m|months?)\b/);
    if (!match) return 0;
    const value = Number(match[1]) || 0;
    const unit = match[2].charAt(0);
    if (unit === "h") return value;
    if (unit === "d") return value * DAY;
    if (unit === "w") return value * WEEK;
    return value * WEEK * 4;
  }

  function devopsMatch(row, byTitle) {
    const title = key(row.title);
    if (title && byTitle.has(title)) return byTitle.get(title);
    if (title.length < 12) return null;
    for (const [name, project] of byTitle) {
      if (name.length >= 12 && (title.includes(name) || name.includes(title))) return project;
    }
    return null;
  }

  function titleIndex(report) {
    const projects = (report && (report.allProjects || report.projects)) || [];
    return new Map(projects.map((row) => [key(row.title), row]));
  }

  /**
   * Hours for one use case: DevOps once work is logged, else the estimate
   * recorded on the list, else one read from the description.
   */
  function forCase(row, byTitle) {
    const live = byTitle ? devopsMatch(row, byTitle) : null;
    if (live && (Number(live.needed) > 0 || Number(live.used) > 0)) {
      const needed = Number(live.needed) || 0;
      const used = Number(live.used) || 0;
      const left = live.left != null ? Number(live.left) || 0 : Math.max(0, needed - used);
      return { needed: needed, used: used, left: left, source: "DevOps", why: "Hours logged on the DevOps work for this project." };
    }
    const listed = recorded(row.estimate);
    if (listed > 0) {
      return { needed: listed, used: 0, left: listed, source: "Recorded", why: "The estimate on the intake list: " + row.estimate + "." };
    }
    const guess = estimate(row);
    return { needed: guess.hours, used: 0, left: guess.hours, source: "Estimate", why: guess.why };
  }

  window.VASC_HOURS = { estimate: estimate, recorded: recorded, devopsMatch: devopsMatch, titleIndex: titleIndex, forCase: forCase };
})();
