(function () {
  const V = window.VASC;
  const T = window.VASC_TREE;
  const canvas = document.getElementById("org-canvas");
  const ctx = canvas.getContext("2d");
  const panel = document.getElementById("org-panel");
  const badgeImages = {};
  const portraits = {};
  const appImages = {};
  const PERSON = "M12 4a3 3 0 110 6 3 3 0 010-6zM5 18c0-3 3-5 7-5s7 2 7 5";
  Object.entries(window.VASC_LOGOS || {}).forEach(([id, src]) => {
    const img = new Image();
    img.src = src;
    badgeImages["org:" + id] = img;
  });
  const params = new URLSearchParams(location.search);
  let nodeId = params.get("node");
  if (!nodeId && params.get("id")) nodeId = "org:" + params.get("id");
  let pendingId = nodeId && !T.byId[nodeId] ? nodeId : "";
  if (!nodeId || !T.byId[nodeId]) nodeId = T.vcdsId;

  const DIMS = [
    ["left", "Hours left", "hoursLeft"],
    ["used", "Hours used", "hoursUsed"],
    ["needed", "Hours needed", "hoursNeeded"],
    ["tasks", "Tasks", "taskCount"]
  ];
  let dim = localStorage.getItem("vasc-pivot-dimension") || "left";
  if (!DIMS.some((row) => row[0] === dim)) dim = "left";

  const FILTERS = [
    ["level", "This level"],
    ["active", "Active"],
    ["done", "Completed"]
  ];
  let filter = localStorage.getItem("vasc-pivot-filter") || "level";
  if (!FILTERS.some((row) => row[0] === filter)) filter = "level";
  const STAFF = [
    ["recent", "Last month"],
    ["earlier", "Earlier"]
  ];
  let staff = localStorage.getItem("vasc-pivot-staff") || "recent";
  if (!STAFF.some((row) => row[0] === staff)) staff = "recent";

  let current = T.byId[nodeId];
  let hostChain = [];
  let viewRoot = null;
  let searchResult = null;
  let liveNote = "";
  let canWrite = false;
  let writeKnown = false;
  const detailCache = new Map();
  const WORK_STATES = ["New", "Active", "Resolved", "Closed", "Removed", "Done", "Completed"];
  let hoverId = "";
  let plusHot = false;
  let movingId = "";
  let copyId = "";
  let pasting = false;
  let targets = [];
  let drag = null;
  let pressing = false;
  let hotTargetId = "";
  let hold = null;
  let width = 0;
  let height = 0;
  const bodies = [];

  function dimField() {
    return DIMS.find((row) => row[0] === dim)[2];
  }

  function dimLabel() {
    return DIMS.find((row) => row[0] === dim)[1];
  }

  const caseBuckets = new Map();
  const projectMemo = new Map();
  const hourMemo = new Map();

  function caseBucket(row) {
    if (row && String(row.implemented || "").trim()) return "done";
    const work = row && row.workId && T.byId["wit:" + row.workId];
    if (work && DONE.has(work.state)) return "done";
    return "active";
  }

  function appBucket(node) {
    const status = String(node && (node.status || node.level || node.name) || "");
    if (/decommission|do not use/i.test(status)) return "done";
    return "active";
  }

  function bucketOn(bucket) {
    if (filter === "done") return bucket === "done";
    if (filter === "active") return bucket === "active";
    return true;
  }

  function rebuildCases() {
    caseBuckets.clear();
    projectMemo.clear();
    hourMemo.clear();
    (V.cases || []).forEach((row) => {
      if (!row.orgId) return;
      const id = "org:" + row.orgId;
      const hit = caseBuckets.get(id) || { active: 0, done: 0 };
      hit[caseBucket(row)] += 1;
      caseBuckets.set(id, hit);
    });
  }

  function casesAt(id) {
    const hit = caseBuckets.get(id);
    if (!hit) return 0;
    if (filter === "done") return hit.done;
    if (filter === "active") return hit.active;
    return hit.active + hit.done;
  }

  function projectsUnder(id, stack) {
    const memoKey = filter + ":" + id;
    if (projectMemo.has(memoKey)) return projectMemo.get(memoKey);
    if (stack.has(id)) return 0;
    stack.add(id);
    const node = T.byId[id];
    let total = casesAt(id);
    if (node && node.kind === "project" && bucketOn(node.state && DONE.has(node.state) ? "done" : "active")) total += 1;
    if (node && node.kind === "app" && bucketOn(appBucket(node))) total += 1;
    (T.children[id] || []).forEach((kid) => {
      total += projectsUnder(kid, stack);
    });
    stack.delete(id);
    projectMemo.set(memoKey, total);
    return total;
  }

  function hoursUnder(id, field, stack) {
    const memoKey = filter + ":" + field + ":" + id;
    if (hourMemo.has(memoKey)) return hourMemo.get(memoKey);
    if (stack.has(id)) return 0;
    stack.add(id);
    const node = T.byId[id];
    const kids = T.children[id] || [];
    let total = 0;
    if (!kids.length) {
      const open = node && isWork(node) && !DONE.has(node.state);
      const closed = node && isWork(node) && DONE.has(node.state);
      if (node && (filter === "done" ? closed : open)) total = Number(node[field]) || 0;
    } else {
      kids.forEach((kid) => {
        total += hoursUnder(kid, field, stack);
      });
    }
    stack.delete(id);
    hourMemo.set(memoKey, total);
    return total;
  }

  function metric(node) {
    if (!node) return 0;
    const live = T.byId[node.id] || node;
    if (!signedIn()) return projectsUnder(live.id, new Set());
    if (filter === "level") return live[dimField()] || 0;
    return hoursUnder(live.id, dimField(), new Set());
  }

  function hue(short) {
    let h = 0;
    const source = String(short || "");
    for (let i = 0; i < source.length; i++) h = (h * 33 + source.charCodeAt(i)) >>> 0;
    return h % 360;
  }

  function kindStroke(node) {
    if (node.kind === "epic") return "#8fd4ff";
    if (node.kind === "feature") return "#e4c56a";
    if (node.kind === "screen") return "#d7b4ff";
    if (node.kind === "story") return "#9be7c4";
    if (node.kind === "bug") return "#ff9b9b";
    if (node.kind === "task" || node.kind === "work") return "#f7fbff";
    return `hsl(${hue(node.title)} 70% 72%)`;
  }

  function chainOf(node) {
    const list = [];
    let guard = 0;
    while (node && guard < 24) {
      list.unshift(node);
      node = T.byId[node.parent];
      guard += 1;
    }
    return list;
  }

  function shownPath(node) {
    const target = node || current;
    if (!target) return [];
    if (target.kind === "person") return hostChain.concat([target]);
    return chainOf(target);
  }

  function shownAncestors() {
    return shownPath(current).slice(0, -1);
  }

  function href(id) {
    return "org.html?node=" + encodeURIComponent(id);
  }

  let layoutFit = 1;

  function portraitRadius(centerPerson) {
    const room = Math.min(width, height) || 640;
    if (centerPerson) return Math.min(48, Math.max(34, room * 0.075));
    return Math.min(32, Math.max(22, room * 0.05));
  }

  function metricRadius(node) {
    const value = metric(node);
    if (!(value > 0)) return 9;
    if (signedIn()) return Math.sqrt(value) * 4.5;
    return Math.sqrt(value) * 18;
  }

  function centerRadius() {
    if (viewRoot) return 36;
    if (current && current.kind === "app") return 72;
    if (!current || current.kind === "person") return portraitRadius(!!current);
    const radius = Math.max(9, metricRadius(current) * layoutFit);
    if (current.id === "org:vasc-do") return Math.max(radius, 36);
    return radius;
  }

  function ancestorRadius() {
    const room = Math.min(width, height) || 640;
    return Math.max(10, Math.min(14, room * 0.022));
  }

  function ancestorSlot(index, total) {
    const count = Math.max(total || 1, 1);
    const span = Math.max(28, Math.min(height * 0.42, height / 2 - centerRadius() - 28));
    const step = Math.min(40, span / count);
    return {
      x: Math.min(20 + index * 8, Math.max(20, width * 0.16)),
      y: Math.min(18 + index * step, Math.max(18, height / 2 - centerRadius() - 20))
    };
  }

  function childRadius(node) {
    if (node.kind === "person") return portraitRadius(false);
    const radius = Math.max(9, metricRadius(node) * layoutFit);
    if (node.id === "org:vasc-do") return Math.max(radius, 20);
    return radius;
  }

  const DONE = new Set(["Resolved", "Closed", "Done", "Completed", "Removed"]);
  rebuildCases();

  function isWork(node) {
    return node.kind !== "org";
  }

  function isActive(node) {
    return isWork(node) && !!node.state && !DONE.has(node.state);
  }

  function isDone(node) {
    return isWork(node) && DONE.has(node.state);
  }

  function matchesDown(id, pred) {
    const node = T.byId[id];
    if (node && pred(node)) return true;
    return (T.children[id] || []).some((childId) => matchesDown(childId, pred));
  }

  function directKids(root) {
    return (T.children[root.id] || []).map((id) => T.byId[id]).filter(Boolean);
  }

  function workRank(node) {
    return { project: 0, epic: 1, feature: 2, screen: 3, story: 4, bug: 5, task: 6, issue: 7, work: 8 }[node.kind] ?? 9;
  }

  function visibleEntries(root) {
    let shown = root.kind === "person" && T.projectsForPerson ? T.projectsForPerson(root.id) : directKids(root);
    if (filter !== "level") {
      shown = shown.filter((node) => {
        if (node.kind === "app") return bucketOn(appBucket(node));
        if (node.kind === "org") return metric(node) > 0;
        if (filter === "done") return isDone(node) || matchesDown(node.id, isDone);
        return isActive(node) || matchesDown(node.id, isActive);
      });
    }
    if (root.kind === "org") shown = shown.filter((node) => node.kind === "org" || node.kind === "project" || (node.kind === "app" && !signedIn()));
    shown = shown.slice().sort((a, b) => {
      const group = (node) => node.kind === "org" ? 0 : node.kind === "app" ? 2 : 1;
      if (group(a) !== group(b)) return group(a) - group(b);
      if (a.kind === "org") return a.title.localeCompare(b.title);
      return workRank(a) - workRank(b) || a.title.localeCompare(b.title);
    });
    const outer = shown.map((node) => ({ node: node, depth: 2, ring: "outer", parentId: root.id }));
    const people = signedIn() && root.kind !== "person" && T.peopleUnder ? T.peopleUnder(root.id, staff) : [];
    const inner = people.map((node) => ({ node: node, depth: 1, ring: "inner", parentId: root.id }));
    return inner.concat(outer);
  }

  function trailCircles(live) {
    const points = [];
    const knots = [];
    if (live) {
      shownAncestors().forEach((node) => {
        const body = bodies.find((item) => item.id === node.id && item.role === "ancestor");
        if (!body) return;
        const knot = { x: body.x, y: body.y, r: Math.max(16, body.r) };
        points.push(knot);
        knots.push(knot);
      });
      const center = bodies.find((body) => body.role === "center");
      if (center) knots.push({ x: center.x, y: center.y, r: center.r });
    } else {
      const ancestors = shownAncestors();
      ancestors.forEach((node, index) => {
        const slot = ancestorSlot(index, ancestors.length);
        const knot = { x: slot.x, y: slot.y, r: ancestorRadius() };
        points.push(knot);
        knots.push(knot);
      });
      knots.push({ x: width / 2, y: height / 2, r: centerRadius() });
    }
    for (let i = 0; i < knots.length - 1; i++) {
      const a = knots[i];
      const b = knots[i + 1];
      const len = Math.hypot(b.x - a.x, b.y - a.y);
      const steps = Math.max(1, Math.ceil(len / 28));
      for (let s = 1; s < steps; s++) {
        const t = s / steps;
        points.push({ x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t, r: 22 });
      }
    }
    return points;
  }

  function separate(x, y, r, obstacles) {
    for (let pass = 0; pass < 8; pass++) {
      let moved = false;
      obstacles.forEach((obs) => {
        let dx = x - obs.x;
        let dy = y - obs.y;
        let dist = Math.hypot(dx, dy);
        const need = r + obs.r + 8;
        if (dist < need) {
          if (dist < 0.5) { dx = 1; dy = 0.2; dist = Math.hypot(dx, dy); }
          const push = need - dist;
          x += dx / dist * push;
          y += dy / dist * push;
          moved = true;
        }
      });
      if (!moved) break;
    }
    return { x: x, y: y };
  }

  function trailSegments(live) {
    const points = [];
    if (live) {
      shownAncestors().forEach((node) => {
        const body = bodies.find((item) => item.id === node.id);
        if (body) points.push(body);
      });
    } else {
      const ancestors = shownAncestors();
      ancestors.forEach((node, index) => points.push(ancestorSlot(index, ancestors.length)));
    }
    const center = live ? bodies.find((item) => item.role === "center") : null;
    points.push(center || { x: width / 2, y: height / 2 });
    const segments = [];
    for (let i = 0; i < points.length - 1; i++) segments.push([points[i], points[i + 1]]);
    return segments;
  }

  function nearestTrail(x, y) {
    let best = null;
    trailSegments(true).forEach((pair) => {
      const a = pair[0];
      const b = pair[1];
      const vx = b.x - a.x;
      const vy = b.y - a.y;
      const len2 = vx * vx + vy * vy || 1;
      let t = ((x - a.x) * vx + (y - a.y) * vy) / len2;
      t = Math.max(0, Math.min(1, t));
      const qx = a.x + t * vx;
      const qy = a.y + t * vy;
      const dx = x - qx;
      const dy = y - qy;
      const dist = Math.hypot(dx, dy);
      if (!best || dist < best.dist) best = { dist: dist, dx: dx, dy: dy };
    });
    return best;
  }

  function labelClearOfTrail(node) {
    if (node.role === "center") return { x: node.x, y: node.y + node.r + 12, align: "center" };
    const hit = nearestTrail(node.x, node.y);
    if (!hit || hit.dist > 120) return { x: node.x, y: node.y + node.r + 12, align: "center" };
    let dx = hit.dx;
    let dy = hit.dy;
    let dist = hit.dist;
    if (dist < 0.5) { dx = 1; dy = 0.2; dist = Math.hypot(dx, dy); }
    const ux = dx / dist;
    const uy = dy / dist;
    return {
      x: node.x + ux * (node.r + 8),
      y: node.y + uy * (node.r + 8),
      align: ux >= 0 ? "left" : "right"
    };
  }

  function layoutEntries(entries) {
    const byParent = new Map();
    entries.forEach((entry) => {
      const list = byParent.get(entry.parentId) || [];
      list.push(entry);
      byParent.set(entry.parentId, list);
    });
    const mixed = entries.some((entry) => entry.node.kind === "org") && entries.some((entry) => entry.node.kind !== "org");
    const maxDepth = mixed ? 2 : entries.reduce((max, entry) => Math.max(max, entry.depth), 1);
    let biggest = 12;
    function consider(list, depth) {
      list.forEach((kid) => {
        biggest = Math.max(biggest, childRadius(kid.node));
      });
    }
    function preview(parentId) {
      const kids = byParent.get(parentId) || [];
      if (!kids.length) return;
      const inner = kids.filter((kid) => kid.ring === "inner");
      const outerKids = kids.filter((kid) => kid.ring !== "inner");
      if (inner.length && outerKids.length) {
        consider(inner, 1);
        consider(outerKids, 2);
        return;
      }
      consider(kids, kids[0].depth || 1);
    }
    const root = viewRoot || current;
    preview(root.id);
    const half = Math.min(width, height) / 2;
    const outer = Math.max(120, half - 28);
    layoutFit = 1;
    if (root && root.kind !== "person") {
      const outerKids = (byParent.get(root.id) || []).filter((kid) => kid.ring !== "inner" && kid.node.kind !== "person");
      const packRadius = (node) => metricRadius(node);
      const widest = outerKids.reduce((max, kid) => Math.max(max, packRadius(kid.node)), 1);
      const centerRaw = root.kind === "search" ? 36 : metricRadius(root);
      let scale = widest > outer * 0.42 ? (outer * 0.42) / widest : 1;
      if (centerRaw * scale > outer * 0.46) scale = (outer * 0.46) / centerRaw;
      const count = Math.max(outerKids.length, 1);
      const ancestors = root.kind === "search" ? [] : chainOf(root).slice(0, -1);
      const sweep = ancestors.length ? (Math.PI * 2) * count / (count + 1) : Math.PI * 2;
      const arc = outer * sweep;
      const sum2 = outerKids.reduce((sum, kid) => sum + packRadius(kid.node) * 2, 0);
      const gaps = outerKids.length * 4;
      const need = sum2 * scale + gaps;
      if (outerKids.length > 1 && need > arc && sum2 > 0 && arc > gaps) scale = Math.min(scale, (arc - gaps) / sum2);
      layoutFit = Math.max(0.05, scale);
    }
    function placeRing(list, start, end, depth, phase) {
      const total = list.length || 1;
      const sweep = Math.max(0.2, end - start);
      const isInner = list[0] && list[0].ring === "inner";
      const dist = isInner ? outer * 0.58 : outer;
      const gap = 4;
      list.forEach((kid) => {
        kid.depth = depth;
        kid.showLabel = list.length <= 40 || kid.node.kind === "app";
        kid.r = childRadius(kid.node);
      });
      if (isInner && list.length > 1) {
        const need = list.reduce((sum, kid) => sum + kid.r * 2 + gap, 0);
        const arc = dist * sweep;
        if (need > arc) {
          const peopleScale = arc / need;
          list.forEach((kid) => {
            kid.r *= peopleScale;
          });
        }
      }
      list.forEach((kid, index) => {
        let turn = (index + 0.5 + (phase || 0)) / total;
        if (turn >= 1) turn -= 1;
        const mid = start + turn * sweep;
        const span = sweep / total;
        kid.x = width / 2 + Math.cos(mid) * dist;
        kid.y = height / 2 + Math.sin(mid) * dist;
        const pad = list.length > 1 ? Math.min(0.06, span * 0.06) : 0;
        walk(kid.node.id, mid - span / 2 + pad, mid + span / 2 - pad);
      });
    }
    function circlesOverlap(nodes) {
      const pad = 2;
      const cx = width / 2;
      const cy = height / 2;
      const centerR = centerRadius();
      for (let i = 0; i < nodes.length; i++) {
        const a = nodes[i];
        if (Math.hypot(a.x - cx, a.y - cy) < centerR + a.r + pad - 0.2) return true;
        for (let j = i + 1; j < nodes.length; j++) {
          const b = nodes[j];
          if (Math.hypot(a.x - b.x, a.y - b.y) < a.r + b.r + pad - 0.2) return true;
        }
      }
      return false;
    }
    function keepCirclesApart(nodes) {
      if (!nodes.length) return;
      const pad = 2;
      const cx = width / 2;
      const cy = height / 2;
      const centerR = centerRadius();
      const saved = nodes.map((node) => ({ x: node.x, y: node.y, r: node.r }));
      const fixed = [];
      const ancestors = root.kind === "search" ? [] : chainOf(root).slice(0, -1);
      ancestors.forEach((node, index) => {
        const slot = ancestorSlot(index, ancestors.length);
        fixed.push({ x: slot.x, y: slot.y, r: ancestorRadius() });
      });
      for (let shrink = 0; shrink < 14; shrink++) {
        const factor = Math.pow(0.9, shrink);
        nodes.forEach((node, index) => {
          node.x = saved[index].x;
          node.y = saved[index].y;
          node.r = saved[index].r * factor;
        });
        for (let pass = 0; pass < 80; pass++) {
          let moved = false;
          nodes.forEach((node) => {
            let dx = node.x - cx;
            let dy = node.y - cy;
            let dist = Math.hypot(dx, dy) || 0.01;
            const need = centerR + node.r + pad;
            if (dist < need) {
              node.x = cx + dx / dist * need;
              node.y = cy + dy / dist * need;
              moved = true;
            }
            fixed.forEach((block) => {
              dx = node.x - block.x;
              dy = node.y - block.y;
              dist = Math.hypot(dx, dy) || 0.01;
              const room = block.r + node.r + pad;
              if (dist < room) {
                node.x = block.x + dx / dist * room;
                node.y = block.y + dy / dist * room;
                moved = true;
              }
            });
            const minX = node.r + 4;
            const minY = node.r + 4;
            const maxX = width - node.r - 4;
            const maxY = height - node.r - 8;
            if (node.x < minX || node.x > maxX || node.y < minY || node.y > maxY) {
              node.x = Math.max(minX, Math.min(maxX, node.x));
              node.y = Math.max(minY, Math.min(maxY, node.y));
              moved = true;
            }
          });
          for (let i = 0; i < nodes.length; i++) {
            for (let j = i + 1; j < nodes.length; j++) {
              const a = nodes[i];
              const b = nodes[j];
              let dx = b.x - a.x;
              let dy = b.y - a.y;
              let dist = Math.hypot(dx, dy);
              const need = a.r + b.r + pad;
              if (dist >= need) continue;
              if (dist < 0.4) {
                dx = Math.cos(i + 1);
                dy = Math.sin(j + 1);
                dist = Math.hypot(dx, dy) || 1;
              }
              const push = (need - dist) / 2;
              a.x -= dx / dist * push;
              a.y -= dy / dist * push;
              b.x += dx / dist * push;
              b.y += dy / dist * push;
              moved = true;
            }
          }
          if (!moved) break;
        }
        const hitsFixed = nodes.some((node) => fixed.some((block) => {
          return Math.hypot(node.x - block.x, node.y - block.y) < node.r + block.r + pad - 0.2;
        }));
        if (!circlesOverlap(nodes) && !hitsFixed) return;
      }
    }
    function walk(parentId, start, end) {
      const kids = byParent.get(parentId) || [];
      if (!kids.length) return;
      const inner = kids.filter((kid) => kid.ring === "inner");
      const outerKids = kids.filter((kid) => kid.ring !== "inner");
      if (inner.length && outerKids.length) {
        placeRing(outerKids, start, end, 2, 0);
        placeRing(inner, start, end, 1, 0.5);
        return;
      }
      placeRing(kids, start, end, kids[0].depth || 1, 0);
    }
    function centerRingBounds(count) {
      const ancestors = root.kind === "search" ? [] : chainOf(root).slice(0, -1);
      if (!ancestors.length || count < 1) return [-Math.PI / 2, Math.PI * 1.5];
      const slot = ancestorSlot(ancestors.length - 1, ancestors.length);
      const ray = Math.atan2(slot.y - height / 2, slot.x - width / 2);
      const step = (Math.PI * 2) / (count + 1);
      return [ray + step / 2, ray + Math.PI * 2 - step / 2];
    }
    const top = byParent.get(root.id) || [];
    const innerCount = top.filter((kid) => kid.ring === "inner").length;
    const outerCount = top.filter((kid) => kid.ring !== "inner").length;
    const ringCount = Math.max(innerCount, outerCount);
    const bounds = centerRingBounds(ringCount);
    walk(root.id, bounds[0], bounds[1]);
    keepCirclesApart(top);
  }

  const ICONS = {
    epic: { color: "#ff7b00", path: "M2 17 L6.2 8.5 L9.6 13 L12 4 L14.4 13 L17.8 8.5 L22 17 Z M3 18.2 H21 V22 H3 Z" },
    feature: { color: "#773b93", path: "M8 2 H16 V8 A4 4 0 0 1 8 8 Z M10.5 11.2 H13.5 V14.2 H17 V16.6 H7 V14.2 H10.5 Z" },
    project: { color: "#0078d4", path: "M8 8 V5 H16 V8 H21 V20 H3 V8 Z" },
    screen: { color: "#5c2d91", path: "M3 4 H21 V15 H3 Z M10 16.2 H14 V18.6 H10 Z M7 19.4 H17 V21 H7 Z" },
    story: { color: "#009ccc", path: "M2 5 H11 V20 H2 Z M13 5 H22 V20 H13 Z" },
    task: { color: "#f2cb1d", path: "M4 4 H20 V20 H4 Z", mark: "M7 12.2 L10.4 15.6 L17 8.2", markColor: "#3a2e00" },
    bug: { color: "#cc293d", path: "M12 8.2 C8.6 8.2 7 11 7 14 C7 18.2 9.1 21 12 21 C14.9 21 17 18.2 17 14 C17 11 15.4 8.2 12 8.2 Z M10.2 7.2 L8 3.4 H10.1 L11.2 6.6 Z M13.8 7.2 L16 3.4 H13.9 L12.8 6.6 Z", mark: "M7 13 H4 M7 16.2 H4 M17 13 H20 M17 16.2 H20", markColor: "#cc293d" },
    issue: { color: "#b4009e", path: "M4 4 H20 V15 H13 L9 20 V15 H4 Z" },
    work: { color: "#0078d4", path: "M6 3 H18 V21 H6 Z M8 8 H16 V10 H8 Z M8 12 H16 V14 H8 Z M8 16 H13 V18 H8 Z" }
  };

  function paintIcon(kind, x, y, box) {
    const icon = ICONS[kind] || ICONS.work;
    ctx.save();
    ctx.translate(x - box / 2, y - box / 2);
    ctx.scale(box / 24, box / 24);
    ctx.fillStyle = icon.color;
    ctx.fill(new Path2D(icon.path));
    if (icon.mark) {
      ctx.strokeStyle = icon.markColor || "#fff";
      ctx.lineWidth = 2.2;
      ctx.lineCap = "round";
      ctx.lineJoin = "round";
      ctx.stroke(new Path2D(icon.mark));
    }
    ctx.restore();
  }

  function iconSvg(kind, size) {
    const icon = ICONS[kind] || ICONS.work;
    const mark = icon.mark
      ? `<path d="${icon.mark}" fill="none" stroke="${icon.markColor || "#fff"}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>`
      : "";
    return `<svg class="logo" xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" aria-hidden="true"><path fill="${icon.color}" d="${icon.path}"/>${mark}</svg>`;
  }

  function logoFor(node, size) {
    if (node.kind === "org") {
      const id = String(node.id || "").replace(/^org:/, "");
      return V.logo({ id: id, short: node.title, level: node.level }, size);
    }
    if (node.kind === "app") {
      const src = node.thumb || "";
      return src
        ? `<img class="app-shot" alt="" width="96" height="54" src="${V.esc(src)}">`
        : `<span class="app-shot app-shot-empty" aria-hidden="true"></span>`;
    }
    if (node.kind === "person") {
      if (signedIn() && node.personKey) {
        const src = "api/devops.php?action=portrait&key=" + encodeURIComponent(node.personKey) + "&name=" + encodeURIComponent(node.title || "");
        return `<img class="logo" alt="" width="${size}" height="${size}" src="${src}" style="border-radius:50%;object-fit:cover;background:#16324d">`;
      }
      return `<svg class="logo" xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" aria-hidden="true"><path fill="#e4c56a" d="${PERSON}"/></svg>`;
    }
    return iconSvg(node.kind, size);
  }

  function ensure(data, x, y, r) {
    let body = bodies.find((item) => item.id === data.id);
    if (!body) {
      body = Object.assign({}, data, { x: x, y: y, vx: 0, vy: 0, r: r, opacity: 0 });
      bodies.push(body);
    }
    body.tx = x;
    body.ty = y;
    body.tr = r;
    body.to = 1;
    return body;
  }

  function collectMatches(raw) {
    const q = String(raw || "").trim().toLowerCase();
    const hits = [];
    const seen = new Set();
    if (!q) return { shown: [], total: 0 };
    function consider(node) {
      if (!node || !node.title || node.id === "search" || seen.has(node.id)) return;
      seen.add(node.id);
      if (node.kind === "person" && (!signedIn() || node.title.charAt(0) === "[")) return;
      const title = String(node.title).toLowerCase();
      const blob = [title, node.name || "", node.fr || "", node.level || ""].join(" ").toLowerCase();
      if (!blob.includes(q)) return;
      let rank = 2;
      if (title.startsWith(q)) rank = 0;
      else if (title.split(/[^a-z0-9]+/).some((word) => word.startsWith(q))) rank = 1;
      hits.push({ node: node, rank: rank });
    }
    Object.keys(T.byId).forEach((id) => consider(T.byId[id]));
    if (signedIn() && T.allPeople) T.allPeople().forEach(consider);
    hits.sort((a, b) => a.rank - b.rank || a.node.title.localeCompare(b.node.title));
    const shown = hits.slice(0, 28).map((hit) => hit.node);
    shown.sort((a, b) => {
      const group = (node) => node.kind === "org" ? 0 : node.kind === "project" ? 1 : node.kind === "person" ? 3 : 2;
      return group(a) - group(b) || a.title.localeCompare(b.title);
    });
    return { shown: shown, total: hits.length };
  }

  function showSearch(raw) {
    const q = String(raw || "").trim();
    if (!q) {
      if (!viewRoot) return;
      viewRoot = null;
      searchResult = null;
      focus(current.id, false, false);
      return;
    }
    if (!current || width < 40 || height < 40) return;
    const result = collectMatches(q);
    searchResult = result;
    const countNote = result.total > result.shown.length
      ? result.shown.length + " of " + result.total + " matches"
      : result.total + " match" + (result.total === 1 ? "" : "es");
    viewRoot = { id: "search", title: q, kind: "search", parent: "", name: countNote };
    const entries = result.shown.map((node) => ({
      node: node,
      depth: node.kind === "person" ? 1 : 2,
      ring: node.kind === "person" ? "inner" : "outer",
      parentId: "search"
    }));
    layoutEntries(entries);
    const keep = new Set(["search"].concat(entries.map((entry) => entry.node.id)));
    bodies.forEach((body) => {
      if (!keep.has(body.id)) body.role = "drop";
    });
    const centerBody = ensure(viewRoot, width / 2, height / 2, centerRadius());
    centerBody.role = "center";
    centerBody.title = q;
    centerBody.kind = "search";
    centerBody.name = countNote;
    entries.forEach((entry) => {
      if (entry.node.kind === "person") loadPortrait(entry.node);
      const existing = bodies.find((item) => item.id === entry.node.id && item.role !== "leaving" && item.role !== "drop");
      const body = ensure(entry.node, entry.x, entry.y, entry.r);
      body.role = "child";
      body.depth = entry.depth;
      body.anchor = "search";
      body.showLabel = entries.length <= 28;
      if (!existing) {
        body.x = width / 2;
        body.y = height / 2;
        body.r = 6;
        body.opacity = 0;
      }
    });
    bodies.forEach((body) => {
      if (body.role === "drop") {
        body.role = "leaving";
        body.to = 0;
        body.tr = 4;
      }
    });
    renderPanel();
  }

  function focus(id, instant, record) {
    if (viewRoot) {
      viewRoot = null;
      searchResult = null;
      const box = document.getElementById("start-search");
      if (box && box.value) box.value = "";
    }
    const next = T.byId[id];
    if (!next) return;
    if (movingId) {
      cancelMove();
      if (liveNote.indexOf("top right") >= 0) liveNote = "";
    }
    if (next.kind === "person") {
      if (current && current.kind !== "person") hostChain = chainOf(current);
    } else {
      hostChain = chainOf(next).slice(0, -1);
    }
    current = next;
    const path = shownPath(current);
    const ancestors = path.slice(0, -1);
    const entries = visibleEntries(current);
    layoutEntries(entries);
    const keep = new Set(ancestors.map((node) => node.id).concat(current.id, entries.map((entry) => entry.node.id)));
    bodies.forEach((body) => {
      if (!keep.has(body.id)) body.role = "drop";
    });

    ancestors.forEach((node, index) => {
      const slot = ancestorSlot(index, ancestors.length);
      const body = ensure(node, slot.x, slot.y, ancestorRadius());
      body.role = "ancestor";
      if (instant) place(body);
    });

    const centerBody = ensure(current, width / 2, height / 2, centerRadius());
    centerBody.role = "center";
    if (current.kind === "person") loadPortrait(current);
    if (instant) place(centerBody);

    const multi = entries.some((entry) => entry.depth > 1);
    entries.forEach((entry) => {
      if (entry.node.kind === "person") loadPortrait(entry.node);
      const existing = bodies.find((item) => item.id === entry.node.id);
      const body = ensure(entry.node, entry.x, entry.y, entry.r);
      body.role = "child";
      body.depth = entry.depth;
      body.anchor = entry.parentId;
      body.showLabel = entry.showLabel !== false;
      if (!existing) {
        const parent = bodies.find((item) => item.id === entry.parentId);
        body.x = parent ? parent.x : width / 2;
        body.y = parent ? parent.y : height / 2;
        body.r = 6;
        body.opacity = 0;
      }
      if (instant) place(body);
    });
    bodies.multi = multi;

    bodies.forEach((body) => {
      if (body.role === "drop") {
        body.role = "leaving";
        body.to = 0;
        body.tr = 4;
        body.tx = body.x;
        body.ty = body.y;
      }
    });

    if (record && next.workItemId) {
      const hit = detailCache.get(String(next.workItemId));
      if (hit && hit.status === "error") detailCache.delete(String(next.workItemId));
    }
    if (record) history.pushState({ node: current.id }, "", href(current.id));
    renderPanel();
  }

  function place(body) {
    body.x = body.tx;
    body.y = body.ty;
    body.r = body.tr;
    body.opacity = body.to;
    body.vx = 0;
    body.vy = 0;
  }

  function resize() {
    const rect = canvas.getBoundingClientRect();
    if (rect.width < 40 || rect.height < 40) return false;
    const nextW = rect.width;
    const nextH = rect.height;
    const same = Math.abs(nextW - width) < 0.5 && Math.abs(nextH - height) < 0.5;
    width = nextW;
    height = nextH;
    const dpr = window.devicePixelRatio || 1;
    const bw = Math.max(1, Math.floor(width * dpr));
    const bh = Math.max(1, Math.floor(height * dpr));
    if (canvas.width !== bw || canvas.height !== bh) {
      canvas.width = bw;
      canvas.height = bh;
    }
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return !same;
  }

  function layoutNow() {
    if (viewRoot) {
      showSearch(viewRoot.title);
      return;
    }
    if (!current || width < 40 || height < 40) return;
    const ancestors = shownAncestors();
    ancestors.forEach((node, index) => {
      const body = bodies.find((item) => item.id === node.id && item.role === "ancestor");
      if (!body) return;
      const slot = ancestorSlot(index, ancestors.length);
      body.tx = slot.x;
      body.ty = slot.y;
      body.tr = ancestorRadius();
    });
    const center = bodies.find((body) => body.role === "center");
    if (center) {
      center.tx = width / 2;
      center.ty = height / 2;
      center.tr = centerRadius();
    }
    const entries = visibleEntries(current);
    layoutEntries(entries);
    entries.forEach((entry) => {
      const body = bodies.find((item) => item.id === entry.node.id && item.role === "child");
      if (!body) return;
      body.tx = entry.x;
      body.ty = entry.y;
      body.tr = entry.r;
    });
    if (movingId) placeTargets();
  }

  function settle(body, rate) {
    const dx = body.tx - body.x;
    const dy = body.ty - body.y;
    if (dx * dx + dy * dy < 0.25) {
      body.x = body.tx;
      body.y = body.ty;
    } else {
      body.x += dx * rate;
      body.y += dy * rate;
    }
    if (Math.abs(body.tr - body.r) < 0.2) body.r = body.tr;
    else body.r += (body.tr - body.r) * rate;
    if (Math.abs(body.to - body.opacity) < 0.01) body.opacity = body.to;
    else body.opacity += (body.to - body.opacity) * rate;
    body.vx = 0;
    body.vy = 0;
  }

  function step() {
    bodies.filter((body) => body.role === "child").forEach((node) => settle(node, 0.12));
    for (let i = bodies.length - 1; i >= 0; i--) {
      const body = bodies[i];
      if (body.role !== "child") settle(body, 0.12);
      if (body.role === "leaving" && body.opacity < 0.04) bodies.splice(i, 1);
    }
  }

  function drawNode(node) {
    ctx.save();
    ctx.globalAlpha = Math.max(0, Math.min(1, node.opacity));
    ctx.beginPath();
    ctx.arc(node.x, node.y, Math.max(1, node.r), 0, Math.PI * 2);
    if (node.kind === "app") {
      const photo = appImages[node.id];
      ctx.fillStyle = "#10233c";
      ctx.fill();
      if (photo && photo.complete && photo.naturalWidth > 0) {
        ctx.save();
        ctx.clip();
        const scale = Math.max((node.r * 2) / photo.naturalWidth, (node.r * 2) / photo.naturalHeight);
        const dw = photo.naturalWidth * scale;
        const dh = photo.naturalHeight * scale;
        ctx.drawImage(photo, node.x - dw / 2, node.y - dh / 2, dw, dh);
        ctx.restore();
      }
      ctx.lineWidth = 2;
      ctx.strokeStyle = "rgba(245, 249, 255, 0.88)";
      ctx.stroke();
      ctx.restore();
      return;
    }
    if (node.kind === "search") {
      ctx.fillStyle = "hsl(205 46% 24%)";
      ctx.fill();
      ctx.lineWidth = 2;
      ctx.strokeStyle = "#e4c56a";
      ctx.stroke();
      let label = node.title || "";
      let size = 16;
      ctx.fillStyle = "#f5f9ff";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.font = `700 ${size}px Segoe UI, sans-serif`;
      while (label.length > 1 && ctx.measureText(label).width > node.r * 1.6 && size > 9) {
        size -= 1;
        ctx.font = `700 ${size}px Segoe UI, sans-serif`;
      }
      ctx.fillText(label, node.x, node.y);
      ctx.restore();
      return;
    }
    if (node.kind === "person") {
      const photo = portraits[node.personKey];
      if (photo && photo.complete && photo.naturalWidth > 0) {
        ctx.save();
        ctx.clip();
        const side = Math.min(photo.naturalWidth, photo.naturalHeight);
        const sx = (photo.naturalWidth - side) / 2;
        const sy = (photo.naturalHeight - side) / 2;
        ctx.drawImage(photo, sx, sy, side, side, node.x - node.r, node.y - node.r, node.r * 2, node.r * 2);
        ctx.restore();
      } else {
        ctx.fillStyle = "hsl(205 42% 28%)";
        ctx.fill();
        ctx.save();
        ctx.translate(node.x, node.y);
        ctx.scale(node.r / 16, node.r / 16);
        ctx.translate(-12, -12);
        ctx.fillStyle = "#f5f9ff";
        ctx.fill(new Path2D(PERSON));
        ctx.restore();
      }
      ctx.beginPath();
      ctx.arc(node.x, node.y, Math.max(1, node.r), 0, Math.PI * 2);
      ctx.lineWidth = 2;
      ctx.strokeStyle = "#e4c56a";
      ctx.stroke();
      ctx.restore();
      return;
    }
    const badge = badgeImages[node.id];
    if (badge && badge.complete && badge.naturalWidth > 0 && node.r > 8) {
      ctx.save();
      ctx.clip();
      const side = node.r * 1.86;
      ctx.drawImage(badge, node.x - side / 2, node.y - side / 2, side, side);
      ctx.restore();
      ctx.lineWidth = 2;
      ctx.strokeStyle = "rgba(245, 249, 255, 0.88)";
      ctx.stroke();
      paintValue(node);
      ctx.restore();
      return;
    }
    const h = hue(node.title);
    ctx.fillStyle = node.kind === "org" ? `hsl(${h} 46% 30%)` : "rgba(6, 22, 42, 0.92)";
    ctx.fill();
    ctx.lineWidth = node.kind === "org" ? 2 : 3;
    ctx.strokeStyle = kindStroke(node);
    ctx.stroke();
    if (node.kind !== "org") {
      paintIcon(node.kind, node.x, node.y, Math.max(8, node.r * 1.15));
      paintValue(node);
      ctx.restore();
      return;
    }
    if (node.role !== "ancestor") {
      const number = metric(node) > 0;
      let label = node.title || "";
      let size = Math.max(8, Math.min(node.role === "center" ? 22 : 14, node.r * (number ? 0.34 : 0.42)));
      ctx.fillStyle = "#f5f9ff";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.font = `700 ${size}px Segoe UI, sans-serif`;
      while (label.length > 1 && ctx.measureText(label).width > node.r * 1.6) {
        if (size > 8) {
          size -= 1;
          ctx.font = `700 ${size}px Segoe UI, sans-serif`;
        } else {
          label = label.slice(0, -1);
        }
      }
      ctx.fillText(label, node.x, number ? node.y - size * 0.7 : node.y);
    }
    paintValue(node);
    ctx.restore();
  }

  function formatMetric(value) {
    const rounded = Math.round(value * 100) / 100;
    return String(rounded);
  }

  function paintValue(node) {
    if (!node || node.kind === "person" || node.kind === "search" || node.role === "ancestor") return;
    const value = metric(node);
    if (!(value > 0)) return;
    const text = formatMetric(value);
    const crest = node.kind === "org" && badgeImages[node.id] && badgeImages[node.id].complete && badgeImages[node.id].naturalWidth > 0;
    const named = node.kind === "org" && !crest;
    let size = Math.max(8, Math.min(node.role === "center" ? 18 : 13, node.r * (named ? 0.32 : 0.42)));
    ctx.save();
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.font = `700 ${size}px Segoe UI, sans-serif`;
    while (size > 8 && ctx.measureText(text).width > node.r * 1.65) {
      size -= 1;
      ctx.font = `700 ${size}px Segoe UI, sans-serif`;
    }
    const y = named ? node.y + size * 0.85 : (crest ? node.y + node.r * 0.58 : node.y);
    ctx.lineWidth = 3;
    ctx.strokeStyle = "rgba(3, 12, 24, 0.92)";
    ctx.fillStyle = "#f7fbff";
    ctx.strokeText(text, node.x, y);
    ctx.fillText(text, node.x, y);
    ctx.restore();
  }

  const labelBoxes = [];

  function noteLabel(id, title, x, y, width, align) {
    const left = align === "right" ? x - width : align === "center" ? x - width / 2 : x;
    labelBoxes.push({ id: id, title: title, x: left, y: y - 10, w: width, h: 20 });
  }

  function hitLabel(x, y) {
    for (let i = labelBoxes.length - 1; i >= 0; i--) {
      const box = labelBoxes[i];
      if (x >= box.x && x <= box.x + box.w && y >= box.y && y <= box.y + box.h) return box;
    }
    return null;
  }

  function fitLabel(node, x, y, textWidth, align) {
    const pad = 6;
    const half = 8;
    if (y + half > height - pad) {
      const above = node.y - node.r - 14;
      y = above - half >= pad ? above : Math.max(pad + half, height - pad - half);
    } else if (y - half < pad) {
      const below = node.y + node.r + 14;
      y = below + half <= height - pad ? below : Math.min(height - pad - half, pad + half);
    }
    const leftOf = (px, how) => how === "right" ? px - textWidth : how === "center" ? px - textWidth / 2 : px;
    let left = leftOf(x, align);
    if (left < pad) x += pad - left;
    left = leftOf(x, align);
    const extra = left + textWidth - (width - pad);
    if (extra > 0) x -= extra;
    return { x: x, y: y, align: align };
  }

  function drawLabel(node, mode) {
    if (node.kind === "search") {
      if (!node.name) return;
      ctx.save();
      ctx.globalAlpha = Math.max(0, Math.min(1, node.opacity));
      ctx.font = "12px Segoe UI, sans-serif";
      ctx.textAlign = "center";
      ctx.textBaseline = "top";
      ctx.lineWidth = 3;
      ctx.strokeStyle = "rgba(3, 12, 24, 0.85)";
      ctx.fillStyle = "#e7f3fc";
      const nameWidth = ctx.measureText(node.name).width;
      let nameX = node.x;
      let nameY = node.y + node.r + 8;
      if (nameY + 14 > height - 6) nameY = Math.max(6, node.y - node.r - 20);
      if (nameX - nameWidth / 2 < 6) nameX = nameWidth / 2 + 6;
      if (nameX + nameWidth / 2 > width - 6) nameX = width - 6 - nameWidth / 2;
      ctx.strokeText(node.name, nameX, nameY);
      ctx.fillText(node.name, nameX, nameY);
      ctx.restore();
      return;
    }
    ctx.save();
    ctx.globalAlpha = Math.max(0, Math.min(1, node.opacity));
    ctx.font = "12px Segoe UI, sans-serif";
    ctx.textBaseline = "middle";
    ctx.lineWidth = 3;
    ctx.strokeStyle = "rgba(3, 12, 24, 0.85)";
    ctx.fillStyle = "#e7f3fc";
    const value = node.kind === "app" ? 0 : metric(node);
    const limit = node.kind === "app" ? 42 : 22;
    const text = node.title.length > limit ? node.title.slice(0, limit - 1) + "…" : node.title;
    const line = value > 0 ? text + " · " + value : text;
    const textWidth = ctx.measureText(line).width;
    const away = mode === "beside" ? null : labelClearOfTrail(node);
    const placed = mode === "beside"
      ? fitLabel(node, node.x + node.r + 8, node.y, textWidth, "left")
      : fitLabel(node, away.x, away.y, textWidth, away.align);
    ctx.textAlign = placed.align;
    ctx.strokeText(line, placed.x, placed.y);
    ctx.fillText(line, placed.x, placed.y);
    noteLabel(node.id, node.title, placed.x, placed.y, textWidth, placed.align);
    ctx.restore();
  }

  function frame() {
    step();
    const shifted = [];
    if (drag) {
      bodies.forEach((body) => {
        if (!drag.ids.has(body.id)) return;
        body.x += drag.dx;
        body.y += drag.dy;
        shifted.push(body);
      });
    }
    ctx.clearRect(0, 0, width, height);
    const center = bodies.find((body) => body.role === "center");
    const ancestors = bodies.filter((body) => body.role === "ancestor");
    const kids = bodies.filter((body) => body.role === "child");
    ctx.lineWidth = 1.5;
    ancestors.forEach((node, index) => {
      const next = ancestors[index + 1] || center;
      if (!next) return;
      ctx.beginPath();
      ctx.moveTo(node.x, node.y);
      ctx.lineTo(next.x, next.y);
      ctx.strokeStyle = "rgba(228, 197, 106, 0.55)";
      ctx.stroke();
    });
    if (center) {
      kids.forEach((node) => {
        const parent = node.anchor === center.id ? center : bodies.find((body) => body.id === node.anchor && body.role !== "leaving");
        if (!parent) return;
        ctx.beginPath();
        ctx.moveTo(parent.x, parent.y);
        ctx.lineTo(node.x, node.y);
        ctx.strokeStyle = node.depth > 1 ? "rgba(143, 212, 255, 0.16)" : "rgba(143, 212, 255, 0.28)";
        ctx.stroke();
      });
    }
    bodies.filter((body) => body.role === "leaving").forEach(drawNode);
    kids.forEach(drawNode);
    ancestors.forEach(drawNode);
    if (center) drawNode(center);
    labelBoxes.length = 0;
    ancestors.forEach((node) => drawLabel(node, "beside"));
    if (center) drawLabel(center, "below");
    kids.forEach((node) => {
      if (node.showLabel !== false || node.id === hoverId) drawLabel(node, "below");
    });
    drawEditors();
    if (shifted.length) {
      shifted.forEach((body) => {
        body.x -= drag.dx;
        body.y -= drag.dy;
      });
    }
    requestAnimationFrame(frame);
  }

  function pointerPos(event) {
    const rect = canvas.getBoundingClientRect();
    return { x: event.clientX - rect.left, y: event.clientY - rect.top };
  }

  function signedIn() {
    return V.role() === "vasc";
  }

  function hitBody(x, y) {
    const order = [];
    for (let i = bodies.length - 1; i >= 0; i--) {
      const body = bodies[i];
      if ((body.role === "ancestor" || body.role === "child") && body.opacity > 0.5) order.push(body);
    }
    const center = bodies.find((body) => body.role === "center" && body.opacity > 0.5);
    if (center) order.push(center);
    return order.find((body) => Math.hypot(body.x - x, body.y - y) <= body.r + 8) || null;
  }

  function plusAt(body) {
    return {
      x: body.x + Math.cos(-Math.PI / 3) * (body.r + 16),
      y: body.y + Math.sin(-Math.PI / 3) * (body.r + 16),
      r: 11
    };
  }

  function hitPlus(x, y) {
    if (!signedIn() || !hoverId || drag) return null;
    const body = bodies.find((item) => item.id === hoverId && item.opacity > 0.5);
    if (!body) return null;
    const plus = plusAt(body);
    return Math.hypot(plus.x - x, plus.y - y) <= plus.r + 3 ? body : null;
  }

  function placeTargets() {
    const banned = new Set(T.subtree(movingId));
    banned.delete(movingId);
    const list = [];
    const push = (id) => {
      if (!id || banned.has(id) || list.some((node) => node.id === id)) return;
      const node = T.byId[id];
      if (node) list.push(node);
    };
    const moving = T.byId[movingId];
    let chain = moving ? chainOf(moving) : chainOf(current);
    if (moving && chain.length < 2 && current && moving.id !== current.id) {
      chain = chainOf(current).filter((node) => node.id !== moving.id).concat([moving]);
    }
    chain.forEach((node) => {
      if (node.kind === "person") loadPortrait(node);
      push(node.id);
    });
    const count = Math.max(list.length, 1);
    const span = Math.max(28, Math.min(height * 0.55, height - 48));
    const step = Math.min(46, span / count);
    targets = list.map((node, index) => ({
      id: node.id,
      title: node.title,
      kind: node.kind,
      x: width - 28,
      y: 22 + index * step,
      r: 16
    }));
  }

  function dropTarget(x, y) {
    const crumb = hitTarget(x, y);
    if (crumb && crumb.id !== movingId) return crumb;
    const hit = hitBody(x, y);
    if (!hit || hit.id === movingId) return null;
    const node = T.byId[hit.id];
    if (!node || (node.kind !== "org" && node.kind !== "project")) return null;
    if (T.subtree(movingId).indexOf(hit.id) >= 0) return null;
    return hit;
  }

  function hitTarget(x, y) {
    return targets.find((target) => Math.hypot(target.x - x, target.y - y) <= target.r + 4) || null;
  }

  function cancelMove() {
    movingId = "";
    targets = [];
    drag = null;
    canvas.style.cursor = "";
  }

  function beginMove(id) {
    movingId = id;
    drag = null;
    placeTargets();
    canvas.style.cursor = "grab";
    liveNote = "This circle’s path is at the top right. Drag a circle onto one of those to place it there.";
    renderPanel();
  }

  function workKind(type) {
    const name = String(type || "").toLowerCase();
    if (name === "epic") return "epic";
    if (name === "feature") return "feature";
    if (name === "bug") return "bug";
    if (name === "user story") return "story";
    if (name === "issue") return "issue";
    if (name === "task") return "task";
    return "work";
  }

  function commitMove(id, parentId) {
    if (!id || !parentId || id === parentId) return;
    if (String(id).indexOf("person:") === 0) {
      cancelMove();
      liveNote = "People stay on the inner ring of the organization or item you have open.";
      renderPanel();
      return;
    }
    if (!canWrite) {
      V.promptWrite();
      return;
    }
    if (T.subtree(id).indexOf(parentId) >= 0) {
      liveNote = "That circle is already under the one you are moving.";
      renderPanel();
      return;
    }
    const from = id;
    cancelMove();
    liveNote = "Saving the new place…";
    renderPanel();
    V.devops("move", null, { id: from, parent: parentId }).then((data) => {
      if (data.unit) T.applyChart({ units: [data.unit] });
      else T.setParent(from, parentId);
      if (from.indexOf("wit:") === 0 && parentId.indexOf("org:") === 0) {
        T.applyChart({ places: [{ work: from.slice(4), under: parentId.slice(4) }] });
      }
      liveNote = "Moved.";
      focus(T.byId[from] ? current.id : current.id, true, false);
    }).catch((error) => {
      liveNote = error.message;
      renderPanel();
      if (error.login) V.promptWrite();
    });
  }

  const addForm = document.createElement("form");
  addForm.id = "add-work";
  addForm.hidden = true;
  addForm.innerHTML = `<h2>Add under <span id="add-under"></span></h2>
    <label>Title <input id="add-title" maxlength="200" required></label>
    <label>Type <select id="add-type">
      <option>Task</option><option>User Story</option><option>Bug</option><option>Feature</option><option>Epic</option><option>Issue</option>
    </select></label>
    <div class="actions"><button class="btn login-go" type="submit">Add</button><button class="btn" type="button" id="add-cancel">Cancel</button></div>
    <p id="add-note" class="note"></p>`;
  document.body.appendChild(addForm);
  addForm.addEventListener("submit", (event) => {
    event.preventDefault();
    const parent = addForm.dataset.parent || "";
    const title = document.getElementById("add-title").value.trim();
    const type = document.getElementById("add-type").value;
    const note = document.getElementById("add-note");
    if (!title) {
      note.textContent = "Give the work item a title.";
      return;
    }
    note.textContent = "Adding it in Azure DevOps…";
    V.devops("create", null, { title: title, type: type, parent: parent }).then((data) => {
      const item = data.item || {};
      T.add({
        id: "wit:" + item.id,
        parent: data.parent || parent,
        kind: workKind(item.type || type),
        title: item.title || title,
        name: [item.type || type, item.state || "New"].filter(Boolean).join(" · "),
        level: item.type || type,
        state: item.state || "New",
        workItemId: item.id,
        url: item.url || "",
        sample: false
      });
      if (String(data.parent || parent).indexOf("org:") === 0) {
        T.applyChart({ places: [{ work: String(item.id), under: String(data.parent || parent).slice(4) }] });
      }
      addForm.hidden = true;
      document.getElementById("add-title").value = "";
      note.textContent = "";
      liveNote = "Added " + (item.title || title) + ".";
      focus(parent, false, true);
    }).catch((error) => {
      note.textContent = error.message;
      if (error.login) V.promptWrite();
    });
  });
  document.getElementById("add-cancel").addEventListener("click", () => {
    addForm.hidden = true;
  });

  function openAdd(body) {
    if (!signedIn()) return;
    if (!canWrite) {
      V.promptWrite();
      return;
    }
    addForm.hidden = false;
    addForm.dataset.parent = body.id;
    document.getElementById("add-under").textContent = body.title;
    document.getElementById("add-note").textContent = "";
    document.getElementById("add-title").focus();
  }

  function drawPlus(body) {
    const plus = plusAt(body);
    ctx.save();
    ctx.beginPath();
    ctx.arc(plus.x, plus.y, plus.r, 0, Math.PI * 2);
    ctx.fillStyle = plusHot ? "#46e08a" : "#1f8a4c";
    ctx.fill();
    ctx.lineWidth = 2;
    ctx.strokeStyle = "#f5fff8";
    ctx.stroke();
    ctx.strokeStyle = "#f5fff8";
    ctx.lineWidth = 2;
    ctx.lineCap = "round";
    ctx.beginPath();
    ctx.moveTo(plus.x - 5, plus.y);
    ctx.lineTo(plus.x + 5, plus.y);
    ctx.moveTo(plus.x, plus.y - 5);
    ctx.lineTo(plus.x, plus.y + 5);
    ctx.stroke();
    ctx.restore();
  }

  function drawEditors() {
    if (copyId) {
      const copied = new Set(T.subtree(copyId));
      ctx.save();
      ctx.strokeStyle = "#8fd4ff";
      ctx.lineWidth = 3;
      bodies.forEach((body) => {
        if (!copied.has(body.id) || body.opacity < 0.35 || body.role === "leaving") return;
        ctx.beginPath();
        ctx.arc(body.x, body.y, body.r + 7, 0, Math.PI * 2);
        ctx.stroke();
      });
      ctx.restore();
    }
    if (movingId) {
      const moving = bodies.find((body) => body.id === movingId && body.opacity > 0.5);
      if (moving) {
        ctx.beginPath();
        ctx.arc(moving.x, moving.y, moving.r + 5, 0, Math.PI * 2);
        ctx.strokeStyle = "#3ddc84";
        ctx.lineWidth = 2;
        ctx.stroke();
      }
    }
    if (signedIn() && hoverId && !drag) {
      const body = bodies.find((item) => item.id === hoverId && item.opacity > 0.5 && item.role !== "leaving");
      if (body) drawPlus(body);
    }
    if (!targets.length) return;
    ctx.save();
    ctx.lineWidth = 1.5;
    ctx.strokeStyle = "rgba(61, 220, 132, 0.55)";
    for (let i = 0; i < targets.length - 1; i++) {
      ctx.beginPath();
      ctx.moveTo(targets[i].x, targets[i].y);
      ctx.lineTo(targets[i + 1].x, targets[i + 1].y);
      ctx.stroke();
    }
    targets.forEach((target) => {
      const node = T.byId[target.id];
      ctx.save();
      ctx.beginPath();
      ctx.arc(target.x, target.y, target.r, 0, Math.PI * 2);
      ctx.clip();
      const badge = node && badgeImages[node.id];
      const photo = node && node.kind === "person" ? portraits[node.personKey] : null;
      if (photo && photo.complete && photo.naturalWidth > 0) {
        ctx.drawImage(photo, target.x - target.r, target.y - target.r, target.r * 2, target.r * 2);
      } else if (badge && badge.complete && badge.naturalWidth > 0) {
        const side = target.r * 1.9;
        ctx.drawImage(badge, target.x - side / 2, target.y - side / 2, side, side);
      } else if (node && node.kind === "person") {
        ctx.fillStyle = "hsl(205 42% 28%)";
        ctx.fill();
        ctx.save();
        ctx.translate(target.x, target.y);
        ctx.scale(target.r / 16, target.r / 16);
        ctx.translate(-12, -12);
        ctx.fillStyle = "#f5f9ff";
        ctx.fill(new Path2D(PERSON));
        ctx.restore();
      } else if (node && node.kind !== "org") {
        ctx.fillStyle = "rgba(6, 22, 42, 0.94)";
        ctx.fill();
        paintIcon(node.kind, target.x, target.y, target.r * 1.15);
      } else {
        ctx.fillStyle = `hsl(${hue(target.title)} 46% 30%)`;
        ctx.fill();
      }
      ctx.restore();
      ctx.beginPath();
      ctx.arc(target.x, target.y, target.r, 0, Math.PI * 2);
      ctx.lineWidth = 2;
      ctx.strokeStyle = target.id === hotTargetId ? "#3ddc84" : "rgba(228, 197, 106, 0.95)";
      ctx.stroke();
      const label = target.title.length > 18 ? target.title.slice(0, 16) + "…" : target.title;
      ctx.font = "12px Segoe UI, sans-serif";
      ctx.textAlign = "right";
      ctx.textBaseline = "middle";
      ctx.lineWidth = 3;
      ctx.strokeStyle = "rgba(3, 12, 24, 0.85)";
      ctx.strokeText(label, target.x - target.r - 6, target.y);
      ctx.fillStyle = "#e7f3fc";
      ctx.fillText(label, target.x - target.r - 6, target.y);
    });
    ctx.restore();
  }

  function areaSentence() {
    if (signedIn()) {
      const scope = filter === "done" ? "completed work" : filter === "active" ? "open work" : "each piece";
      return `Circle area is ${dimLabel().toLowerCase()} for ${scope} under this one. `;
    }
    if (filter === "done") return "Circle area for an organization is the number of completed projects under it. ";
    if (filter === "active") return "Circle area for an organization is the number of open projects under it. ";
    return "Circle area for an organization is the number of projects under it. ";
  }

  function filterNote() {
    const scope = current.kind === "person"
      ? "Projects this person is assigned to sit on the outer ring."
      : current.kind === "app"
        ? "This is an app from the Apps section."
        : current.kind === "org"
          ? (signedIn()
            ? "People sit on the inner ring of their own organization. The digital office is VASC DO. Departments and projects sit on the outer ring."
            : "Departments and apps sit on the outer ring. Each app shows its name and thumbnail.")
          : "People on this item sit on the inner ring. The next DevOps level sits on the outer ring.";
    const staffNote = !signedIn() ? "" : staff === "recent"
      ? " People are those whose assigned DevOps work changed in the last month."
      : " People are those with no assigned DevOps change in the last month.";
    if (filter === "active") return scope + " A circle stays when it, or something under it, is still open." + staffNote;
    if (filter === "done") return scope + " A circle stays when it, or something under it, is completed." + staffNote;
    return scope + staffNote;
  }

  function renderSearchPanel() {
    const result = searchResult || { shown: [], total: 0 };
    const rowsHtml = result.shown.map((node) => {
      const parent = T.byId[node.parent];
      const where = parent ? parent.title : "";
      const kind = node.kind === "org" ? (node.level || "org") : (node.level || node.kind);
      return `<a class="child-link" data-node="${V.esc(node.id)}" href="${href(node.id)}">${logoFor(node, 28)}<span>${V.esc(node.title)} <em>${V.esc(kind)}${where ? " · " + V.esc(where) : ""}</em></span></a>`;
    }).join("");
    panel.innerHTML = `
      <div class="org-fixed">
      ${signedIn() ? `<div class="dims" role="group" aria-label="Circle size">
        ${DIMS.map(([key, label]) => `<button type="button" data-dim="${key}" aria-pressed="${key === dim}">${label}</button>`).join("")}
      </div>` : ""}
      <p class="crumb">Search</p>
      <h2>${V.esc(viewRoot.title)}</h2>
      <p class="note">${V.esc(viewRoot.name || "")}. Matches from every part of the chart, shown together.</p>
      </div>
      <div class="org-scroll">
      <h3>Matches</h3>
      ${rowsHtml || "<p>Nothing matches.</p>"}
      </div>`;
    panel.querySelectorAll("[data-dim]").forEach((button) => {
      button.addEventListener("click", () => {
        dim = button.dataset.dim;
        localStorage.setItem("vasc-pivot-dimension", dim);
        showSearch(viewRoot.title);
      });
    });
  }

  function hourText(value) {
    if (value == null || value === "") return "";
    return String(value);
  }

  function applyWork(node, item) {
    if (!node || !item) return;
    if (item.title) node.title = item.title;
    if (item.type) node.level = item.type;
    if (item.state) node.state = item.state;
    node.name = [node.level, node.state].filter(Boolean).join(" · ");
    if (item.url) node.url = item.url;
    if (item.assigned) node.assigned = item.assigned;
    node.description = item.description || "";
    if ((T.children[node.id] || []).length) return;
    if (item.hoursNeeded != null) node.hoursNeeded = Number(item.hoursNeeded) || 0;
    if (item.hoursLeft != null) node.hoursLeft = Number(item.hoursLeft) || 0;
    if (item.hoursUsed != null) node.hoursUsed = Number(item.hoursUsed) || 0;
  }

  function ensureDetail(node) {
    const id = String(node.workItemId || "");
    if (!id) return;
    const hit = detailCache.get(id);
    if (hit && (hit.status === "loading" || hit.status === "ready" || hit.status === "error")) return;
    detailCache.set(id, { status: "loading" });
    V.devops("workitem", { id: id, fresh: "1" }).then((data) => {
      const previous = detailCache.get(id);
      detailCache.set(id, { status: "ready", item: data.item || {}, draft: previous && previous.draft });
      if (current && String(current.workItemId) === id && !(previous && previous.draft)) applyWork(current, data.item || {});
      if (current && String(current.workItemId) === id) renderPanel();
    }).catch((error) => {
      detailCache.set(id, { status: "error", message: error.message || "The work item did not load." });
      if (current && String(current.workItemId) === id) renderPanel();
    });
  }

  function workValues(node, detail) {
    const item = detail && detail.item || {};
    const draft = detail && detail.draft || {};
    const pick = (key, fallback) => Object.prototype.hasOwnProperty.call(draft, key) ? draft[key] : fallback;
    return {
      title: pick("title", item.title || node.title || ""),
      state: pick("state", item.state || node.state || ""),
      description: pick("description", item.description || node.description || ""),
      assigned: item.assigned || node.assigned || "",
      type: item.type || node.level || node.kind || "",
      hoursNeeded: pick("hoursNeeded", hourText(item.hoursNeeded)),
      hoursLeft: pick("hoursLeft", hourText(item.hoursLeft)),
      hoursUsed: pick("hoursUsed", hourText(item.hoursUsed))
    };
  }

  function workDetail(node) {
    ensureDetail(node);
    const id = String(node.workItemId);
    const detail = detailCache.get(id) || { status: "loading" };
    const link = signedIn() && node.url ? `<p><a href="${V.esc(node.url)}">Open in Azure DevOps</a></p>` : "";
    const caseLink = node.caseId ? `<p><a href="case.html?id=${V.esc(node.caseId)}">Open the use case</a></p>` : "";
    if (detail.status === "error") {
      return `<p class="note">${V.esc(detail.message || "The work item did not load.")}</p>${link}${caseLink}`;
    }
    if (detail.status !== "ready") {
      return `<p class="note">Reading this work item…</p>${link}${caseLink}`;
    }
    const values = workValues(node, detail);
    const editing = writeKnown && canWrite;
    if (!editing) {
      const lines = [
        ["Type", values.type],
        ["State", values.state],
        ["Assigned", values.assigned || "—"],
        ["Hours needed", values.hoursNeeded === "" ? "—" : values.hoursNeeded],
        ["Hours left", values.hoursLeft === "" ? "—" : values.hoursLeft],
        ["Hours used", values.hoursUsed === "" ? "—" : values.hoursUsed]
      ].map(([label, value]) => `<dt>${V.esc(label)}</dt><dd>${V.esc(value)}</dd>`).join("");
      const text = values.description ? `<p class="work-text">${V.esc(values.description)}</p>` : `<p class="note">No description yet.</p>`;
      return `<dl class="kv">${lines}</dl>${text}${link}${caseLink}`;
    }
    const states = WORK_STATES.slice();
    if (values.state && states.indexOf(values.state) < 0) states.unshift(values.state);
    const options = states.map((state) => `<option${state === values.state ? " selected" : ""}>${V.esc(state)}</option>`).join("");
    return `<form id="work-detail" data-id="${V.esc(id)}">
      <label>Title <input name="title" maxlength="200" value="${V.esc(values.title)}" required></label>
      <p class="note">${V.esc(values.type)}${values.assigned ? " · " + V.esc(values.assigned) : ""}</p>
      <label>State <select name="state">${options}</select></label>
      <label>Description <textarea name="description" maxlength="8000">${V.esc(values.description)}</textarea></label>
      <label>Hours needed <input name="hoursNeeded" inputmode="decimal" value="${V.esc(values.hoursNeeded)}"></label>
      <label>Hours left <input name="hoursLeft" inputmode="decimal" value="${V.esc(values.hoursLeft)}"></label>
      <label>Hours used <input name="hoursUsed" inputmode="decimal" value="${V.esc(values.hoursUsed)}"></label>
      ${link}${caseLink}
      <div class="work-save"><button class="btn" id="work-save" type="submit">Save</button><p id="work-note" class="note"></p></div>
    </form>`;
  }

  function rememberDraft() {
    const form = panel.querySelector("#work-detail");
    if (!form) return;
    const hit = detailCache.get(form.dataset.id);
    if (!hit) return;
    hit.draft = {
      title: form.elements.title.value,
      state: form.elements.state.value,
      description: form.elements.description.value,
      hoursNeeded: form.elements.hoursNeeded.value,
      hoursLeft: form.elements.hoursLeft.value,
      hoursUsed: form.elements.hoursUsed.value
    };
  }

  function saveWork(event) {
    if (event) event.preventDefault();
    const form = panel.querySelector("#work-detail");
    if (!form || !current || !current.workItemId) return;
    rememberDraft();
    const note = panel.querySelector("#work-note");
    const button = panel.querySelector("#work-save");
    const title = form.elements.title.value.trim();
    if (!title) {
      note.textContent = "Give the work item a title.";
      return;
    }
    button.disabled = true;
    note.textContent = "Saving…";
    const id = String(current.workItemId);
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
      detailCache.set(id, { status: "ready", item: item });
      applyWork(current, item);
      liveNote = "Saved.";
      focus(current.id, false, false);
    }).catch((error) => {
      if (button) button.disabled = false;
      if (note) note.textContent = error.message;
      if (error.login) V.promptWrite();
    });
  }

  function renderPanel() {
    if (viewRoot) {
      renderSearchPanel();
      return;
    }
    const path = shownPath(current);
    const entries = visibleEntries(current);
    const listed = entries.slice();
    const crumb = path.map((node, index) => {
      const last = index === path.length - 1;
      return last ? V.esc(node.title) : `<a data-node="${V.esc(node.id)}" href="${href(node.id)}">${V.esc(node.title)}</a>`;
    }).join(" / ");
    const work = !!current.workItemId;
    const rows = [["Kind", current.kind === "app" ? "App" : current.kind]];
    if (!work && signedIn()) {
      rows.push(
        ["Hours needed", current.hoursNeeded || "—"],
        ["Hours left", current.hoursLeft || "—"],
        ["Hours used", current.hoursUsed || "—"],
        ["Tasks", current.taskCount || "—"]
      );
    }
    if (current.level && !work) rows.unshift(["Level", current.level]);
    if (current.state && !work) rows.push(["State", current.state]);
    const facts = work ? "" : rows.map(([label, value]) => `<dt>${V.esc(label)}</dt><dd>${V.esc(value)}</dd>`).join("");
    const detail = work ? workDetail(current) : "";
    const link = !work && signedIn() && current.url ? `<p><a href="${V.esc(current.url)}">${V.esc(current.url)}</a></p>` : "";
    const caseLink = !work && current.caseId ? `<p><a href="case.html?id=${V.esc(current.caseId)}">Open the use case</a></p>` : "";
    const sync = `<p><button class="btn" id="sync" type="button">Sync</button></p><p id="sync-note" class="note">${V.esc(liveNote)}</p>`;
    const heading = V.esc(current.title);
    function countWords(value) {
      if (signedIn()) {
        const scope = filter === "done" ? "completed " : filter === "active" ? "open " : "";
        return `${value} ${scope}${dimLabel().toLowerCase()}`;
      }
      const word = value === 1 ? "project" : "projects";
      if (filter === "done") return `${value} completed ${word}`;
      if (filter === "active") return `${value} open ${word}`;
      return `${value} ${word}`;
    }
    function childRow(node) {
      const value = node.kind === "app" ? 0 : metric(node);
      const result = value > 0 ? ` · ${countWords(value)}` : "";
      const kind = node.kind === "app" ? (node.level || "App") : node.kind === "org" ? (node.level || "org") : (node.level || node.kind);
      return `<a class="child-link" data-node="${V.esc(node.id)}" href="${href(node.id)}">${logoFor(node, 28)}<span>${V.esc(node.title)} <em>${V.esc(kind)}${result}</em></span></a>`;
    }
    const rowsHtml = listed.map((entry) => childRow(entry.node)).join("");
    panel.innerHTML = `
      <div class="org-fixed">
      ${signedIn() ? `<div class="dims" role="group" aria-label="Circle size">
        ${DIMS.map(([key, label]) => `<button type="button" data-dim="${key}" aria-pressed="${key === dim}">${label}</button>`).join("")}
      </div>` : ""}
      <p class="crumb">${crumb}</p>
      <h2>${current.kind === "app" ? "" : logoFor(current, 36) + " "}${heading}</h2>
      <p class="note">${V.esc(current.name || "")} ${current.fr ? "· " + V.esc(current.fr) : ""}</p>
      <div class="dims" role="group" aria-label="How far to open">
        ${FILTERS.map(([key, label]) => `<button type="button" data-filter="${key}" aria-pressed="${key === filter}">${label}</button>`).join("")}
      </div>
      ${signedIn() ? `<div class="dims" role="group" aria-label="People">
        ${STAFF.map(([key, label]) => `<button type="button" data-staff="${key}" aria-pressed="${key === staff}">${label}</button>`).join("")}
      </div>` : ""}
      <p>${current.kind === "app" ? "" : areaSentence()}${filterNote()}</p>
      ${facts ? `<dl class="kv">${facts}</dl>` : ""}
      ${link}${caseLink}${sync}
      </div>
      <div class="org-scroll">
      ${detail}
      ${current.kind === "app" ? `${current.thumb ? `<a class="app-hero-link" href="app.html?name=${encodeURIComponent(current.title)}"><img class="app-hero" alt="" src="${V.esc(current.thumb)}"></a>` : ""}${current.summary ? `<p>${V.esc(current.summary)}</p>` : ""}<p><a href="app.html?name=${encodeURIComponent(current.title)}">Open the description</a></p>` : `<h3>Under this</h3>
      ${rowsHtml || "<p>Nothing under this.</p>"}`}
      </div>`;
    panel.querySelectorAll("[data-dim]").forEach((button) => {
      button.addEventListener("click", () => {
        dim = button.dataset.dim;
        localStorage.setItem("vasc-pivot-dimension", dim);
        focus(current.id, false, false);
      });
    });
    panel.querySelectorAll("[data-filter]").forEach((button) => {
      button.addEventListener("click", () => {
        filter = button.dataset.filter;
        localStorage.setItem("vasc-pivot-filter", filter);
        focus(current.id, false, false);
      });
    });
    panel.querySelectorAll("[data-staff]").forEach((button) => {
      button.addEventListener("click", () => {
        staff = button.dataset.staff;
        localStorage.setItem("vasc-pivot-staff", staff);
        focus(current.id, false, false);
      });
    });
    const syncButton = panel.querySelector("#sync");
    if (syncButton) {
      syncButton.addEventListener("click", () => {
        if (V.role() !== "vasc") {
          V.promptLogin();
          return;
        }
        loadHierarchy(true);
      });
    }
    const workForm = panel.querySelector("#work-detail");
    if (workForm) {
      workForm.addEventListener("input", rememberDraft);
      workForm.addEventListener("submit", saveWork);
    }
  }

  panel.addEventListener("click", (event) => {
    const link = event.target.closest("[data-node]");
    if (!link) return;
    event.preventDefault();
    focus(link.dataset.node, false, true);
  });

  function openAppDescription(title) {
    const anchor = document.createElement("a");
    anchor.href = "app.html?name=" + encodeURIComponent(title);
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
  }

  canvas.addEventListener("click", (event) => {
    const point = pointerPos(event);
    const plus = hitPlus(point.x, point.y);
    if (plus) {
      openAdd(plus);
      return;
    }
    if (movingId) {
      const target = dropTarget(point.x, point.y);
      if (target) commitMove(movingId, target.id);
      return;
    }
    const labeled = hitLabel(point.x, point.y);
    const hit = labeled ? bodies.find((body) => body.id === labeled.id) : hitBody(point.x, point.y);
    if (hit && hit.role === "center" && hit.kind === "app") {
      openAppDescription(hit.title);
      return;
    }
    if (hit && hit.role !== "center") focus(hit.id, false, true);
  });

  canvas.addEventListener("contextmenu", (event) => {
    if (!signedIn()) return;
    const point = pointerPos(event);
    const hit = hitBody(point.x, point.y);
    if (!hit || hit.kind === "search" || hit.role === "center") return;
    event.preventDefault();
    beginMove(hit.id);
  });

  canvas.addEventListener("pointerdown", (event) => {
    pressing = true;
    const point = pointerPos(event);
    if (event.pointerType !== "mouse") {
      if (hold) clearTimeout(hold.timer);
      hold = {
        x: point.x,
        y: point.y,
        timer: setTimeout(() => {
          hold = null;
          const found = hitBody(point.x, point.y);
          if (!found || !signedIn()) return;
          beginMove(found.id);
          if (pressing) {
            drag = { x: point.x, y: point.y, dx: 0, dy: 0, ids: new Set(T.subtree(found.id)) };
          }
        }, 600)
      };
    }
    if (!movingId || !canWrite) return;
    const hit = hitBody(point.x, point.y);
    const ids = new Set(T.subtree(movingId));
    if (!hit || !ids.has(hit.id)) return;
    drag = { x: point.x, y: point.y, dx: 0, dy: 0, ids: ids };
    if (canvas.setPointerCapture) canvas.setPointerCapture(event.pointerId);
  });

  canvas.addEventListener("pointermove", (event) => {
    const point = pointerPos(event);
    if (hold && Math.hypot(point.x - hold.x, point.y - hold.y) > 8) {
      clearTimeout(hold.timer);
      hold = null;
    }
    if (drag) {
      drag.dx = point.x - drag.x;
      drag.dy = point.y - drag.y;
      const target = dropTarget(point.x, point.y);
      hotTargetId = target ? target.id : "";
      return;
    }
    const plus = hitPlus(point.x, point.y);
    plusHot = !!plus;
    hoverId = plus ? plus.id : "";
    const labeled = plus ? null : hitLabel(point.x, point.y);
    if (!plus) {
      const hit = labeled ? bodies.find((body) => body.id === labeled.id) : hitBody(point.x, point.y);
      hoverId = hit ? hit.id : "";
    }
    const tipTitle = labeled ? labeled.title : (hoverId && T.byId[hoverId] ? T.byId[hoverId].title : "");
    if (tipTitle && V.showAbbrTip) V.showAbbrTip(tipTitle, event.clientX, event.clientY);
    else if (V.hideAbbrTip) V.hideAbbrTip();
    canvas.style.cursor = plusHot || hoverId ? "pointer" : movingId ? "grab" : "";
  });

  canvas.addEventListener("pointerup", (event) => {
    pressing = false;
    hotTargetId = "";
    if (hold) {
      clearTimeout(hold.timer);
      hold = null;
    }
    if (!drag) return;
    const point = pointerPos(event);
    const target = dropTarget(point.x, point.y);
    const from = movingId;
    const moved = Math.hypot(drag.dx, drag.dy) > 6;
    drag = null;
    if (moved && target) commitMove(from, target.id);
  });

  canvas.addEventListener("pointerleave", () => {
    if (!drag) {
      hoverId = "";
      plusHot = false;
    }
    if (V.hideAbbrTip) V.hideAbbrTip();
  });

  function copyTree() {
    const id = hoverId || (current && current.id) || "";
    if (!id || !T.byId[id]) return;
    const count = T.subtree(id).length;
    if (count > 40) {
      liveNote = "That tree is too large to copy at once. Open a smaller branch and copy that.";
      renderPanel();
      return;
    }
    copyId = id;
    const extra = count - 1;
    liveNote = "Copied " + T.byId[id].title + (extra ? " and " + extra + " under it" : "") + ". Hover where it should go and press Ctrl+V.";
    renderPanel();
  }

  function placeCopied(node, under) {
    if (node.kind === "org") {
      V.orgs.push({
        id: node.orgId,
        parent: node.parentName,
        level: node.level,
        name: node.name,
        short: node.title,
        fr: node.fr || "",
        kids: 0,
        weight: 0
      });
      T.add({
        id: node.id,
        parent: under,
        kind: "org",
        title: node.title,
        name: node.name,
        fr: node.fr || "",
        level: node.level,
        sample: false
      });
      return;
    }
    T.add({
      id: node.id,
      parent: under,
      kind: node.kind || workKind(node.type),
      title: node.title,
      name: [node.type, node.state].filter(Boolean).join(" · "),
      level: node.type || node.kind,
      state: node.state || "New",
      workItemId: node.workId,
      url: node.url || "",
      sample: false
    });
  }

  function pasteTree() {
    if (!copyId || !T.byId[copyId] || pasting) return;
    if (!canWrite) {
      V.promptWrite();
      return;
    }
    const copied = new Set(T.subtree(copyId));
    let parentId = "";
    if (hoverId && !copied.has(hoverId)) parentId = hoverId;
    else if (current && !copied.has(current.id)) parentId = current.id;
    else if (T.byId[copyId] && T.byId[copyId].parent) parentId = T.byId[copyId].parent;
    if (!parentId || !T.byId[parentId]) return;
    const nodes = [];
    const walk = (id, parentKey) => {
      const node = T.byId[id];
      if (!node) return;
      nodes.push({
        key: id,
        parentKey: parentKey,
        kind: node.kind,
        title: node.title,
        type: node.level || "",
        fr: node.fr || ""
      });
      (T.children[id] || []).forEach((child) => walk(child, id));
    };
    walk(copyId, "");
    pasting = true;
    liveNote = "Placing it…";
    renderPanel();
    V.devops("copy", null, { parent: parentId, nodes: nodes }).then((data) => {
      const made = data.created || [];
      made.forEach((node) => placeCopied(node, node.under || parentId));
      const units = made.filter((node) => node.kind === "org").map((node) => ({
        id: node.orgId,
        parent: node.parentName,
        level: node.level,
        name: node.name,
        short: node.title,
        fr: node.fr || ""
      }));
      const places = made.filter((node) => node.kind !== "org" && String(node.under || "").indexOf("org:") === 0).map((node) => ({
        work: String(node.workId),
        under: String(node.under).slice(4)
      }));
      if (units.length || places.length) T.applyChart({ units: units, places: places });
      liveNote = data.warning || ("Pasted " + made.length + (made.length === 1 ? " circle" : " circles") + " under " + T.byId[parentId].title + ".");
      focus(parentId, false, false);
      if (data.warning && String(data.warning).indexOf("Read & write") >= 0) V.promptWrite();
    }).catch((error) => {
      liveNote = error.message;
      renderPanel();
      if (error.login) V.promptWrite();
    }).finally(() => {
      pasting = false;
    });
  }

  window.addEventListener("keydown", (event) => {
    const typing = event.target && event.target.closest && event.target.closest("input, textarea, select");
    if (event.key === "Escape") {
      cancelMove();
      copyId = "";
      addForm.hidden = true;
      liveNote = "";
      renderPanel();
      return;
    }
    if (typing || event.repeat || !(event.ctrlKey || event.metaKey)) return;
    const key = String(event.key || "").toLowerCase();
    if (key === "c") {
      if (!signedIn()) return;
      if (window.getSelection && String(window.getSelection())) return;
      event.preventDefault();
      copyTree();
    } else if (key === "v") {
      if (!signedIn() || !copyId) return;
      event.preventDefault();
      pasteTree();
    }
  });

  window.addEventListener("popstate", (event) => {
    const id = event.state && event.state.node;
    if (id && T.byId[id]) focus(id, false, false);
  });

  window.addEventListener("resize", () => {
    if (resize()) layoutNow();
  });
  if (window.ResizeObserver) {
    const observer = new ResizeObserver(() => {
      if (resize()) layoutNow();
    });
    observer.observe(canvas);
  }

  resize();
  focus(current.id, true);
  requestAnimationFrame(() => {
    if (resize()) layoutNow();
  });
  if (!pendingId) history.replaceState({ node: current.id }, "", href(current.id));
  requestAnimationFrame(frame);
  function savedTree() {
    try {
      const saved = JSON.parse(sessionStorage.getItem("vasc-tree-v2") || "null");
      if (saved && Array.isArray(saved.items) && Date.now() - saved.at < 24 * 60 * 60 * 1000) return saved;
    } catch (error) {}
    return null;
  }

  function rememberTree(items, people) {
    try {
      const raw = JSON.stringify({ at: Date.now(), items: items, people: people || [] });
      if (raw.length < 4500000) sessionStorage.setItem("vasc-tree-v2", raw);
    } catch (error) {}
  }

  function loadPortrait(node) {
    if (!node || !node.personKey || portraits[node.personKey]) return;
    const img = new Image();
    const name = encodeURIComponent(node.title || "");
    img.src = "api/devops.php?action=portrait&key=" + encodeURIComponent(node.personKey) + "&name=" + name;
    portraits[node.personKey] = img;
  }

  function applyTree(items, note) {
    const count = T.applyHierarchy(items || []);
    rebuildCases();
      const opened = pendingId && T.byId[pendingId] ? pendingId : "";
      pendingId = opened ? "" : pendingId;
      liveNote = opened
        ? count + " Azure DevOps items are under the organizations. " + note
        : pendingId
          ? "That work item is not in the current Azure DevOps hierarchy."
          : count
            ? count + " Azure DevOps items are under the organizations. " + note
            : (note || "Azure DevOps returned no work items.");
      if (pendingId) {
        history.replaceState({ node: current.id }, "", href(current.id));
        pendingId = "";
      }
      focus(opened || current.id, false, false);
  }

  function loadHierarchy(fresh) {
    if (!fresh) {
      const saved = savedTree();
      if (saved) {
        if (T.setPeople) T.setPeople(saved.people || []);
        applyTree(saved.items, "Saved on this browser. Sync reads Azure DevOps again.");
        return;
      }
    }
    liveNote = "Reading the Azure DevOps hierarchy…";
    renderPanel();
    V.devops("tree", fresh ? { fresh: "1" } : {}).then((data) => {
      if (T.setPeople) T.setPeople(data.people || []);
      rememberTree(data.items || [], data.people || []);
      applyTree(data.items || [], data.note || "Saved on the server.");
    }).catch((error) => {
      liveNote = error.message;
      renderPanel();
      if (error.login) V.promptLogin(() => loadHierarchy(true));
    });
  }

  function departmentId(app) {
    const homes = {
      "In&Out Clearance CFB (O-G)": "9652",
      "Taxi Chit": "9661",
      "In&Out Clearance VCDS HQ": "9516",
      "Golf App": "9516",
      "Golf Score Input App": "9516",
      "Curling App": "9516",
      "Softball App": "9536",
      "DIY Tech Library": "vasc-do",
      "Pulse App": "vasc-do",
      "VASC Use Case Submission App": "vasc-do",
      "Student Mentorship Program Tracking & Reporting": "vasc-do",
      "Booking 1on1": "vasc-do",
      "VCDS ASC - App Directory": "vasc-do",
      "VCDS L2 Network Directory": "9516",
      "VCDS Gp HQ - Library App": "9516",
      "Staffing App": "9516",
      "TDR": "9516",
      "TDR - THCEE Directory App": "9516",
      "H&R App": "9516",
      "MAP App": "9516",
      "VCDS Task Tracking": "9516",
      "PMed Dashboard": "7300",
      "CFMP Incident Intake": "7394",
      "Hermes": "9667",
      "CDLS (L) VCR App": "9667",
      "CRes Knowledge Management App": "9577",
      "Training Request & Approval": "9593",
      "DEPSEC SSR 1 Activity Tracker": "9593",
      "Business Case Analysis Digitalization App": "9561",
      "EC Request Form App": "9540",
      "VCDS - Virtual Hallway Chats Program": "9675",
      "VCDS - Virtual Hallway Chats Program UAT": "9675",
      "VCDS SITREP Reporting": "9682"
    };
    const id = homes[app.name] || "";
    return id && T.byId["org:" + id] ? id : "";
  }

  function placePublicApps() {
    const apps = window.VASC_APPS || [];
    const thumbs = window.VASC_APP_THUMBS || {};
    apps.forEach((app) => {
      if (!app || !app.name) return;
      const orgId = departmentId(app);
      if (!orgId || !T.byId["org:" + orgId]) return;
      const id = "app:" + String(app.name).toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
      if (T.byId[id]) return;
      const thumb = thumbs[app.name] || "";
      T.add({
        id: id,
        parent: "org:" + orgId,
        kind: "app",
        title: app.name,
        name: [app.client, app.lane || app.status].filter(Boolean).join(" · "),
        summary: app.summary || "",
        fr: app.fr || "",
        level: app.lane || app.status || "App",
        status: app.status || "",
        thumb: thumb,
        sample: false
      });
      if (!thumb) return;
      const img = new Image();
      img.src = thumb;
      appImages[id] = img;
    });
    projectMemo.clear();
  }

  V.searchChart = showSearch;

  V.ready.then(() => {
    V.devops("chart").then((data) => {
      T.applyChart(data, true);
      if (current) focus(current.id, true, false);
    }).catch(() => {});
    if (V.role() === "vasc") {
      V.devops("access").then((data) => {
        canWrite = !!data.canWrite;
        writeKnown = true;
        if (current && current.workItemId) renderPanel();
      }).catch(() => {
        canWrite = false;
        writeKnown = true;
      });
      loadHierarchy(false);
      return;
    }
    placePublicApps();
    const opened = pendingId && T.byId[pendingId] ? pendingId : current.id;
    pendingId = "";
    liveNote = "Sign in to show Azure DevOps work on this chart.";
    focus(opened, true, false);
  });
})();
