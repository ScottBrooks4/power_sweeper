(function () {
  const V = window.VASC;
  const T = window.VASC_TREE;
  const canvas = document.getElementById("org-canvas");
  const ctx = canvas.getContext("2d");
  const panel = document.getElementById("org-panel");
  const badgeImages = {};
  const portraits = {};
  const presence = {};
  const directory = {};
  const directoryAsked = {};
  const schedules = {};
  let scheduleShift = 0;
  let teamsOn = null;
  let presenceWait = 0;
  let presenceError = "";
  const appImages = {};
  const PERSON = "M12 4a3 3 0 110 6 3 3 0 010-6zM5 18c0-3 3-5 7-5s7 2 7 5";
  Object.entries(window.VASC_LOGOS || {}).forEach(([id, src]) => {
    const img = new Image();
    img.src = src;
    badgeImages["org:" + id] = img;
  });
  const params = new URLSearchParams(location.search);
  let nodeId = params.get("node");
  // Work items live on the project management page now; old links still land on them.
  if (/^wit:\d+$/.test(String(nodeId || ""))) {
    location.replace("pm.html?item=" + encodeURIComponent(nodeId));
    return;
  }
  if (!nodeId && params.get("id")) nodeId = "org:" + params.get("id");
  let pendingId = nodeId && !T.byId[nodeId] ? nodeId : "";
  if (!nodeId || !T.byId[nodeId]) nodeId = T.vcdsId;

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
  let heldNote = "";
  let hoverId = "";
  let width = 0;
  let height = 0;
  const bodies = [];

  const unitMemo = new Map();

  function unitsUnder(id, stack) {
    if (unitMemo.has(id)) return unitMemo.get(id);
    if (stack.has(id)) return 0;
    stack.add(id);
    let total = 0;
    (T.children[id] || []).forEach((kid) => {
      const node = T.byId[kid];
      if (node && node.kind === "org") total += 1 + unitsUnder(kid, stack);
    });
    stack.delete(id);
    unitMemo.set(id, total);
    return total;
  }

  /** Circle area: the departments under an organization. */
  function metric(node) {
    if (!node || node.kind !== "org") return 0;
    return unitsUnder(node.id, new Set());
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
  let parentFloor = 0;

  function portraitRadius(centerPerson) {
    const room = Math.min(width, height) || 640;
    if (centerPerson) return Math.min(48, Math.max(34, room * 0.075));
    return Math.min(32, Math.max(22, room * 0.05));
  }

  function metricRadius(node) {
    const value = metric(node);
    if (!(value > 0)) return 9;
    return Math.sqrt(value) * 12;
  }

  function centerRadius() {
    if (viewRoot) return 36;
    if (current && current.kind === "app") return 72;
    if (!current || current.kind === "person") return portraitRadius(!!current);
    return Math.max(9, metricRadius(current) * layoutFit, parentFloor);
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
    return Math.max(9, metricRadius(node) * layoutFit);
  }

  function directKids(root) {
    return (T.children[root.id] || []).map((id) => T.byId[id]).filter(Boolean);
  }

  /** People and departments only; projects and their work are on the project management page. */
  function onChart(node) {
    return !!node && (node.kind === "org" || node.kind === "person" || node.kind === "app");
  }

  function visibleEntries(root) {
    let shown = root.kind === "person" ? [] : directKids(root);
    shown = shown.filter((node) => node.kind === "org" || (node.kind === "app" && !signedIn()));
    shown = shown.slice().sort((a, b) => {
      const group = (node) => node.kind === "org" ? 0 : 1;
      if (group(a) !== group(b)) return group(a) - group(b);
      return a.title.localeCompare(b.title);
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
    parentFloor = 0;
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
    let biggestChild = 0;
    top.forEach((kid) => {
      if (kid.r > biggestChild) biggestChild = kid.r;
    });
    if (biggestChild > 0 && root && root.kind !== "search" && root.kind !== "person") {
      parentFloor = biggestChild / 0.82;
      const limit = centerRadius() * 0.82;
      if (limit > 0) {
        top.forEach((kid) => {
          if (kid.r > limit) kid.r = limit;
        });
      }
    }
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
    if (data.kind) body.kind = data.kind;
    if (data.level) body.level = data.level;
    if (data.title) body.title = data.title;
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
      if (!node || !node.title || node.id === "search" || seen.has(node.id) || !onChart(node)) return;
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
      const group = (node) => node.kind === "org" ? 0 : node.kind === "person" ? 2 : 1;
      return group(a) - group(b) || a.title.localeCompare(b.title);
    });
    return { shown: shown, total: hits.length };
  }

  function chartSearchQuery() {
    if (viewRoot && viewRoot.kind === "search") return String(viewRoot.title || "").trim();
    const boxes = [];
    const local = document.getElementById("start-search");
    if (local) boxes.push(local);
    try {
      if (window.parent && window.parent !== window) {
        const parentBox = window.parent.document.getElementById("start-search");
        if (parentBox) boxes.push(parentBox);
      }
    } catch (error) {}
    for (let i = 0; i < boxes.length; i++) {
      const value = String(boxes[i].value || "").trim();
      if (value) return value;
    }
    return "";
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
    queuePresence();
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

    if (record) history.pushState({ node: current.id }, "", href(current.id));
    renderPanel();
    queuePresence();
    loadDirectory(current);
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

  function personStatus(node) {
    if (!node || !node.email) return null;
    return presence[String(node.email).toLowerCase()] || null;
  }

  function badgeSpots(node) {
    const size = Math.max(7, Math.min(13, node.r * 0.36));
    return {
      status: { x: node.x + node.r * 0.62, y: node.y + node.r * 0.62, r: size * 0.62 },
      place: { x: node.x - node.r * 0.62, y: node.y + node.r * 0.62, r: size * 0.62 },
      chat: { x: node.x + node.r * 0.62, y: node.y - node.r * 0.62, r: Math.max(8, size * 0.78) }
    };
  }

  function fillDisc(spot, color) {
    ctx.beginPath();
    ctx.arc(spot.x, spot.y, spot.r + 1.6, 0, Math.PI * 2);
    ctx.fillStyle = "#071422";
    ctx.fill();
    ctx.beginPath();
    ctx.arc(spot.x, spot.y, spot.r, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.fill();
  }

  function paintPersonBadges(node) {
    const row = personStatus(node);
    const spots = badgeSpots(node);
    if (row && row.tone && row.tone !== "unknown" && node.r >= 11) {
      const colors = { available: "#6bb700", busy: "#c4314b", away: "#f7b500", dnd: "#c4314b", offline: "#8b8b8b", oof: "#b4009e" };
      if (row.tone === "offline") {
        ctx.beginPath();
        ctx.arc(spots.status.x, spots.status.y, spots.status.r + 1.6, 0, Math.PI * 2);
        ctx.fillStyle = "#071422";
        ctx.fill();
        ctx.beginPath();
        ctx.arc(spots.status.x, spots.status.y, spots.status.r, 0, Math.PI * 2);
        ctx.strokeStyle = "#8b8b8b";
        ctx.lineWidth = 1.6;
        ctx.stroke();
      } else {
        fillDisc(spots.status, colors[row.tone] || "#8b8b8b");
      }
      ctx.strokeStyle = "#fff";
      ctx.fillStyle = "#fff";
      ctx.lineWidth = Math.max(1.2, spots.status.r * 0.28);
      ctx.lineCap = "round";
      if (row.tone === "available") {
        ctx.beginPath();
        ctx.moveTo(spots.status.x - spots.status.r * 0.45, spots.status.y);
        ctx.lineTo(spots.status.x - spots.status.r * 0.1, spots.status.y + spots.status.r * 0.38);
        ctx.lineTo(spots.status.x + spots.status.r * 0.48, spots.status.y - spots.status.r * 0.36);
        ctx.stroke();
      } else if (row.tone === "dnd") {
        ctx.beginPath();
        ctx.moveTo(spots.status.x - spots.status.r * 0.4, spots.status.y);
        ctx.lineTo(spots.status.x + spots.status.r * 0.4, spots.status.y);
        ctx.stroke();
      } else if (row.tone === "oof") {
        ctx.beginPath();
        ctx.moveTo(spots.status.x + spots.status.r * 0.35, spots.status.y - spots.status.r * 0.35);
        ctx.lineTo(spots.status.x - spots.status.r * 0.3, spots.status.y);
        ctx.lineTo(spots.status.x + spots.status.r * 0.35, spots.status.y + spots.status.r * 0.35);
        ctx.stroke();
      }
      if (row.place === "office" || row.place === "remote") {
        fillDisc(spots.place, row.planned ? "#16324d" : "#e8f2ff");
        ctx.save();
        ctx.translate(spots.place.x, spots.place.y);
        ctx.scale(spots.place.r / 8, spots.place.r / 8);
        ctx.translate(-12, -12);
        ctx.strokeStyle = row.planned ? "#e4c56a" : "#12304c";
        ctx.fillStyle = row.planned ? "transparent" : "#12304c";
        ctx.lineWidth = 1.8;
        const mark = row.place === "office"
          ? "M6 20 V10 L12 6 L18 10 V20 H14 V14 H10 V20 Z"
          : "M5 12 L12 6 L19 12 V19 H5 Z";
        const path = new Path2D(mark);
        if (!row.planned) ctx.fill(path);
        ctx.stroke(path);
        ctx.restore();
      }
    }
    if (node.email && node.r >= 16) {
      fillDisc(spots.chat, "#5b5fc7");
      ctx.strokeStyle = "#fff";
      ctx.lineWidth = 1.3;
      ctx.beginPath();
      ctx.arc(spots.chat.x, spots.chat.y - 0.4, spots.chat.r * 0.38, 0.4, Math.PI - 0.2);
      ctx.stroke();
    }
  }

  function hitChat(x, y) {
    const order = bodies.filter((body) => body.kind === "person" && body.email && body.opacity > 0.5 && body.role !== "leaving" && body.r >= 16);
    const center = order.find((body) => body.role === "center");
    const rest = order.filter((body) => body !== center);
    if (center) rest.push(center);
    return rest.find((body) => {
      const spot = badgeSpots(body).chat;
      return Math.hypot(spot.x - x, spot.y - y) <= spot.r + 5;
    }) || null;
  }

  function openTeamsChat(email) {
    const url = "https://teams.microsoft.com/l/chat/0/0?users=" + encodeURIComponent(email);
    window.open(url, "_blank", "noopener");
  }

  function visibleEmails() {
    const found = new Set();
    bodies.forEach((body) => {
      if (body.kind === "person" && body.email && body.opacity > 0.35 && body.role !== "leaving") found.add(String(body.email).toLowerCase());
    });
    if (current && current.kind === "person" && current.email) found.add(String(current.email).toLowerCase());
    return Array.from(found).slice(0, 40);
  }

  function refreshPresence() {
    if (teamsOn !== true) return;
    const emails = visibleEmails();
    if (!emails.length) return;
    fetch("api/teams.php?action=presence", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ emails: emails })
    }).then((res) => res.json().catch(() => ({})).then((data) => ({ ok: res.ok, data: data }))).then((result) => {
      if (!result.ok || result.data.ok === false) {
        if (result.data.login) teamsOn = false;
        presenceError = result.data.error || "Teams did not answer.";
        if (current && (current.kind === "person" || current.kind === "org")) renderPanel();
        return;
      }
      teamsOn = true;
      presenceError = "";
      Object.keys(result.data.people || {}).forEach((email) => {
        presence[email] = result.data.people[email];
      });
      if (current && (current.kind === "person" || current.kind === "org")) renderPanel();
    }).catch(() => {
      presenceError = "Teams did not answer.";
      if (current && (current.kind === "person" || current.kind === "org")) renderPanel();
    });
  }

  function queuePresence() {
    if (teamsOn !== true) return;
    clearTimeout(presenceWait);
    presenceWait = setTimeout(refreshPresence, 350);
  }

  function postTeams(action, body) {
    return fetch("api/teams.php?action=" + action, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    }).then((res) => res.json().then((data) => ({ ok: res.ok && data.ok !== false, data: data })));
  }

  function mailKey(node) {
    const key = String((node && node.email) || "").toLowerCase();
    return /^[^@\s/]+@[^@\s/]+\.[^@\s/]+$/.test(key) ? key : "";
  }

  function showingPerson(key) {
    return current && current.kind === "person" && String(current.email || "").toLowerCase() === key;
  }

  function loadDirectory(node) {
    const key = node && node.kind === "person" ? mailKey(node) : "";
    if (!key) return;
    if (Date.now() - (directoryAsked[key] || 0) < 5 * 60 * 1000) return;
    directoryAsked[key] = Date.now();
    postTeams("directory", { emails: [key] }).then((result) => {
      if (!result.ok) return;
      Object.keys(result.data.people || {}).forEach((email) => {
        const row = Object.assign({ source: result.data.source, reason: result.data.reason || "", profile: result.data.profile || "" }, result.data.people[email]);
        directory[email] = row;
        if (!row.sample && row.presence) presence[email] = row.presence;
      });
      if (showingPerson(key)) renderPanel();
    }).catch(() => {});
  }

  function scheduleDate() {
    const day = new Date();
    day.setDate(day.getDate() + scheduleShift);
    return day.getFullYear() + "-" + String(day.getMonth() + 1).padStart(2, "0") + "-" + String(day.getDate()).padStart(2, "0");
  }

  function loadSchedule(key) {
    const date = scheduleDate();
    const id = key + "|" + date;
    const hit = schedules[id];
    if (hit && (hit.status === "wait" || Date.now() - hit.at < 2 * 60 * 1000)) return hit;
    schedules[id] = { status: "wait", at: Date.now() };
    const settle = (row) => {
      schedules[id] = Object.assign({ at: Date.now() }, row);
      if (showingPerson(key) && scheduleDate() === date) renderPanel();
    };
    postTeams("schedule", { email: key, date: date }).then((result) => {
      if (result.ok) settle({ status: "ok", data: result.data });
      else settle({ status: "error", error: result.data.error || "The calendar did not answer.", calendar: !!result.data.calendar, login: !!result.data.login });
    }).catch(() => settle({ status: "error", error: "The calendar did not answer." }));
    return schedules[id];
  }

  function returnParam() {
    return encodeURIComponent((location.pathname.split("/").pop() || "org.html") + location.search);
  }

  function minutesOf(clock) {
    const parts = String(clock || "").split(":");
    return parts.length === 2 ? Number(parts[0]) * 60 + Number(parts[1]) : NaN;
  }

  function clockOf(minutes) {
    return String(Math.floor(minutes / 60)).padStart(2, "0") + ":" + String(minutes % 60).padStart(2, "0");
  }

  function freeBusyHtml(key) {
    const FB_WORDS = ["Free", "Tentative", "Busy", "Out of office", "Working elsewhere"];
    const hit = loadSchedule(key);
    const day = new Date(scheduleDate() + "T12:00:00").toLocaleDateString("en-CA", { weekday: "short", month: "short", day: "numeric" });
    const head = `<div class="fb-head"><button type="button" class="fb-step" data-fb-day="-1" aria-label="Previous day">‹</button><span>${V.esc(day)}</span><button type="button" class="fb-step" data-fb-day="1" aria-label="Next day">›</button></div>`;
    let body = "";
    if (hit.status === "wait") {
      body = `<p class="note">Reading free and busy times…</p>`;
    } else if (hit.status === "error") {
      const fix = hit.calendar
        ? ` <a href="connect.html?calendar=1&amp;return=${returnParam()}">Allow calendar</a>`
        : hit.login ? ` <a href="connect.html?return=${returnParam()}">Connect Teams</a>` : "";
      body = `<p class="note">${V.esc(hit.error)}${fix}</p>`;
    } else {
      const data = hit.data;
      const start = minutesOf(data.start);
      const step = Number(data.interval) || 30;
      const view = String(data.view || "");
      const workStart = minutesOf(data.workingHours && data.workingHours.start);
      const workEnd = minutesOf(data.workingHours && data.workingHours.end);
      const cells = view.split("").map((code, index) => {
        const from = start + index * step;
        const off = !isNaN(workStart) && !isNaN(workEnd) && (from < workStart || from >= workEnd) ? " fb-off" : "";
        return `<i class="fb-${V.esc(code)}${off}" title="${clockOf(from)}–${clockOf(from + step)} ${V.esc(FB_WORDS[Number(code)] || "")}"></i>`;
      }).join("");
      const ticks = view.split("").map((code, index) => {
        const from = start + index * step;
        return from % 120 === 0 ? `<span style="grid-column:${index + 1}">${clockOf(from).slice(0, 2)}</span>` : "";
      }).join("");
      const items = (data.items || []).filter((item) => item.status !== "free").slice(0, 6).map((item) => {
        const what = [FB_WORDS[["free", "tentative", "busy", "oof", "workingElsewhere"].indexOf(item.status)] || "Busy", item.subject, item.location].filter(Boolean).join(" · ");
        return `<li><b>${V.esc(item.start)}–${V.esc(item.end)}</b> ${V.esc(what)}</li>`;
      }).join("");
      const sample = data.source === "sample" ? `<p class="note sample-note">Sample free and busy times.</p>` : "";
      body = view
        ? `<div class="fb-strip" style="grid-template-columns:repeat(${view.length},1fr)">${cells}</div><div class="fb-ticks" style="grid-template-columns:repeat(${view.length},1fr)">${ticks}</div>${items ? `<ul class="fb-items">${items}</ul>` : ""}${sample}`
        : `<p class="note">No free and busy times for this day.</p>`;
    }
    return `<div class="freebusy"><h3>Free and busy</h3>${head}${body}</div>`;
  }

  function profileHtml(info) {
    if (!info || info.found === false) return "";
    const phones = (info.businessPhones || []).concat(info.mobilePhone ? [info.mobilePhone] : []);
    const rows = [
      ["Title", info.jobTitle],
      ["Department", info.department],
      ["Office", info.officeLocation],
      ["Phone", phones.join(", ")]
    ].filter((row) => row[1]);
    const facts = rows.length ? `<dl class="kv person-kv">${rows.map(([label, value]) => `<dt>${V.esc(label)}</dt><dd>${V.esc(value)}</dd>`).join("")}</dl>` : "";
    const basic = info.source === "graph" && info.profile === "basic" && !rows.length
      ? `<p class="note">Title, department and phone need User.Read.All, which an administrator has to allow.</p>`
      : "";
    return facts + basic;
  }

  function contactHtml(key, info) {
    const live = info && !info.sample;
    const mail = live ? (info.found === false ? key : info.mail || "") : key;
    const upn = live && info.upn ? info.upn : key;
    const subject = encodeURIComponent("VASC Pivot");
    const email = mail
      ? `<a class="btn" href="mailto:${V.esc(mail)}?subject=${subject}">Email</a>`
      : `<button class="btn" type="button" disabled title="The directory has no mail address for this person">Email</button>`;
    return `<p class="person-actions">${email}<a class="btn" href="https://teams.microsoft.com/l/chat/0/0?users=${encodeURIComponent(upn)}" target="_blank" rel="noopener">Chat in Teams</a></p>`;
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
      paintPersonBadges(node);
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
      paintIcon(node.kind, node.x, node.y, Math.max(8, node.r * 1.05));
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
    if (!named && !crest) {
      ctx.save();
      ctx.globalAlpha = Math.max(0, Math.min(1, node.opacity));
      let size = Math.max(8, Math.min(node.role === "center" ? 13 : 11, node.r * 0.4));
      ctx.font = `700 ${size}px Segoe UI, sans-serif`;
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      while (size > 8 && ctx.measureText(text).width > node.r * 0.9) {
        size -= 1;
        ctx.font = `700 ${size}px Segoe UI, sans-serif`;
      }
      const spot = Math.max(7, ctx.measureText(text).width / 2 + 3.5);
      const x = node.x + node.r * 0.62;
      const y = node.y + node.r * 0.62;
      ctx.beginPath();
      ctx.arc(x, y, spot, 0, Math.PI * 2);
      ctx.fillStyle = "#071422";
      ctx.fill();
      ctx.lineWidth = 1.5;
      ctx.strokeStyle = "rgba(245, 249, 255, 0.88)";
      ctx.stroke();
      ctx.fillStyle = "#f7fbff";
      ctx.fillText(text, x, y);
      ctx.restore();
      return;
    }
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
    ctx.clearRect(0, 0, width, height);
    const center = bodies.find((body) => body.role === "center");
    const ancestors = shownAncestors().map((node) => bodies.find((body) => body.id === node.id && body.role === "ancestor")).filter(Boolean);
    const kids = bodies.filter((body) => body.role === "child");
    function strokeBetween(from, to, color, width) {
      if (!from || !to) return;
      const dx = to.x - from.x;
      const dy = to.y - from.y;
      const dist = Math.hypot(dx, dy) || 1;
      const ux = dx / dist;
      const uy = dy / dist;
      const fromR = from.r || 0;
      const toR = to.r || 0;
      if (dist <= fromR + toR + 2) return;
      ctx.beginPath();
      ctx.moveTo(from.x + ux * (fromR + 1), from.y + uy * (fromR + 1));
      ctx.lineTo(to.x - ux * (toR + 1), to.y - uy * (toR + 1));
      ctx.strokeStyle = color;
      ctx.lineWidth = width;
      ctx.stroke();
    }
    ancestors.forEach((body, index) => {
      strokeBetween(body, ancestors[index + 1] || center, "rgba(228, 197, 106, 0.92)", 2);
    });
    kids.forEach((node) => {
      const tree = T.byId[node.id];
      const parentId = tree && tree.parent ? tree.parent : node.anchor;
      const parent = center && parentId === center.id
        ? center
        : bodies.find((body) => body.id === parentId && body.role !== "leaving");
      strokeBetween(parent, node, "rgba(143, 212, 255, 0.75)", 1.75);
    });
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

  function areaSentence() {
    return "Circle area for an organization is the number of departments under it. ";
  }

  function filterNote() {
    const scope = current.kind === "person"
      ? "Their projects are on the Project management page."
      : current.kind === "app"
        ? "This is an app from the Apps section."
        : signedIn()
          ? "People sit on the inner ring of their own organization. The digital office is VASC DO. Departments sit on the outer ring. Projects are on the Project management page."
          : "Departments and apps sit on the outer ring. Each app shows its name and thumbnail.";
    const staffNote = !signedIn() ? "" : staff === "recent"
      ? " People are those whose assigned DevOps work changed in the last month."
      : " People are those with no assigned DevOps change in the last month.";
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
      <p class="crumb">Search</p>
      <h2>${V.esc(viewRoot.title)}</h2>
      <p class="note">${V.esc(viewRoot.name || "")}. Matches among people and departments.</p>
      </div>
      <div class="org-scroll">
      <h3>Matches</h3>
      ${rowsHtml || "<p>Nothing matches.</p>"}
      </div>`;
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
    const rows = [["Kind", current.kind === "app" ? "App" : current.kind]];
    if (current.level) rows.unshift(["Level", current.level]);
    if (current.kind === "org") rows.push(["Departments under it", String(metric(current))]);
    const facts = rows.map(([label, value]) => `<dt>${V.esc(label)}</dt><dd>${V.esc(value)}</dd>`).join("");
    const projects = signedIn() && (current.kind === "org" || current.kind === "person")
      ? `<p><a href="pm.html?q=${encodeURIComponent(current.title)}">${current.kind === "person" ? "Projects for this person" : "Projects for " + V.esc(current.title)}</a> on the Project management page</p>`
      : "";
    const sync = signedIn() ? `<p><button class="btn" id="sync" type="button">Sync</button></p><p id="sync-note" class="note">${V.esc(liveNote)}</p>` : `<p id="sync-note" class="note">${V.esc(liveNote)}</p>`;
    const heading = V.esc(current.title);
    function countWords(value) {
      return `${value} ${value === 1 ? "department" : "departments"}`;
    }
    function childRow(node) {
      const value = node.kind === "app" ? 0 : metric(node);
      const result = value > 0 ? ` · ${countWords(value)}` : "";
      const kind = node.kind === "app" ? (node.level || "App") : node.kind === "org" ? (node.level || "org") : (node.level || node.kind);
      const row = personStatus(node);
      const dot = row && row.tone && row.tone !== "unknown" ? `<i class="presence-dot tone-${V.esc(row.tone)}" title="${V.esc(row.label)}"></i>` : "";
      return `<a class="child-link" data-node="${V.esc(node.id)}" href="${href(node.id)}">${logoFor(node, 28)}<span>${dot}${V.esc(node.title)} <em>${V.esc(kind)}${result}</em></span></a>`;
    }
    function personLine() {
      if (current.kind !== "person") return "";
      const key = mailKey(current);
      if (!key) {
        return `<p class="note">Sync the chart so this person’s address can be matched to Teams.</p>`;
      }
      const info = directory[key] || null;
      const back = returnParam();
      const line = (row, prefix) => {
        const dot = row.tone && row.tone !== "unknown" ? `<i class="presence-dot tone-${V.esc(row.tone)}"></i>` : "";
        return `<p class="presence-line">${dot}${V.esc(prefix + row.label)}</p>`;
      };
      let status = "";
      if (info && info.sample) {
        const why = info.reason === "config"
          ? "Pivot is set to sample people data."
          : info.reason === "graph-unreachable"
            ? "Microsoft Graph did not answer."
            : `<a href="connect.html?return=${back}">Connect Teams</a> for the directory, presence and calendar.`;
        status = (info.presence ? line(info.presence, "Sample: ") : "") + `<p class="note sample-note">Sample directory data. ${why}</p>`;
      } else if (teamsOn === false) {
        status = `<p class="note"><a href="connect.html?return=${back}">Connect Teams</a> to see whether this person is active and planned in the office.</p>`;
      } else {
        const row = personStatus(current);
        status = row ? line(row, "") : `<p class="note">${presenceError ? V.esc(presenceError) : "Reading Teams status…"}</p>`;
      }
      return status + profileHtml(info) + contactHtml(key, info);
    }
    const teamsLegend = signedIn() && current.kind !== "person" && teamsOn !== null
      ? (teamsOn
        ? (presenceError
          ? `<p class="note">${V.esc(presenceError)}</p>`
          : `<p class="note">Portrait colour matches Teams. A building is in the office, and an outline building is planned. The purple mark opens a chat.</p>`)
        : `<p class="note"><a href="connect.html?return=${encodeURIComponent((location.pathname.split("/").pop() || "org.html") + location.search)}">Connect Teams</a> to show who is active and planned in the office.</p>`)
      : "";
    const rowsHtml = listed.map((entry) => childRow(entry.node)).join("");
    panel.innerHTML = `
      <div class="org-fixed">
      <p class="crumb">${crumb}</p>
      <h2>${current.kind === "app" ? "" : logoFor(current, 36) + " "}${heading}</h2>
      ${personLine()}
      <p class="note">${V.esc(current.name || "")} ${current.fr ? "· " + V.esc(current.fr) : ""}</p>
      ${signedIn() ? `<div class="dims" role="group" aria-label="People">
        ${STAFF.map(([key, label]) => `<button type="button" data-staff="${key}" aria-pressed="${key === staff}">${label}</button>`).join("")}
      </div>${teamsLegend}` : ""}
      <p>${current.kind === "app" ? "" : areaSentence()}${filterNote()}</p>
      ${facts ? `<dl class="kv">${facts}</dl>` : ""}
      ${projects}${sync}
      </div>
      <div class="org-scroll">
      ${current.kind === "person" && mailKey(current) ? freeBusyHtml(mailKey(current)) : ""}
      ${current.kind === "app" ? `${current.thumb ? `<a class="app-hero-link" href="app.html?name=${encodeURIComponent(current.title)}"><img class="app-hero" alt="" src="${V.esc(current.thumb)}"></a>` : ""}${current.summary ? `<p>${V.esc(current.summary)}</p>` : ""}<p><a href="app.html?name=${encodeURIComponent(current.title)}">Open the description</a></p>` : `<h3>Under this</h3>
      ${rowsHtml || "<p>Nothing under this.</p>"}`}
      </div>`;
    panel.querySelectorAll("[data-fb-day]").forEach((button) => {
      button.addEventListener("click", () => {
        scheduleShift = Math.max(-7, Math.min(28, scheduleShift + Number(button.dataset.fbDay)));
        renderPanel();
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
        heldNote = "";
        loadHierarchy(true);
      });
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
    const labeled = hitLabel(point.x, point.y);
    const chatHit = hitChat(point.x, point.y);
    if (chatHit) {
      openTeamsChat(chatHit.email);
      return;
    }
    const hit = labeled ? bodies.find((body) => body.id === labeled.id) : hitBody(point.x, point.y);
    if (hit && hit.role === "center" && hit.kind === "app") {
      openAppDescription(hit.title);
      return;
    }
    if (hit && hit.role !== "center") focus(hit.id, false, true);
  });

  canvas.addEventListener("pointermove", (event) => {
    const point = pointerPos(event);
    const labeled = hitLabel(point.x, point.y);
    const hit = labeled ? bodies.find((body) => body.id === labeled.id) : hitBody(point.x, point.y);
    hoverId = hit ? hit.id : "";
    const chatHover = hitChat(point.x, point.y);
    const hovered = hoverId && T.byId[hoverId] ? T.byId[hoverId] : null;
    const status = hovered ? personStatus(hovered) : null;
    if (chatHover && V.showPlainTip) V.showPlainTip("Chat in Teams", event.clientX, event.clientY);
    else if (status && status.label && V.showPlainTip) V.showPlainTip(hovered.title + " — " + status.label, event.clientX, event.clientY);
    else {
      const tipTitle = labeled ? labeled.title : (hovered ? hovered.title : "");
      if (tipTitle && V.showAbbrTip) V.showAbbrTip(tipTitle, event.clientX, event.clientY);
      else if (V.hideAbbrTip) V.hideAbbrTip();
    }
    canvas.style.cursor = hoverId || chatHover ? "pointer" : "";
  });

  canvas.addEventListener("pointerleave", () => {
    hoverId = "";
    if (V.hideAbbrTip) V.hideAbbrTip();
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

  fetch("api/teams.php?action=session", { cache: "no-store" })
    .then((res) => res.json())
    .then((data) => {
      teamsOn = !!(data && data.signedIn);
      if (teamsOn) queuePresence();
      else if (current) renderPanel();
    })
    .catch(() => {
      teamsOn = false;
    });
  setInterval(() => {
    if (teamsOn) refreshPresence();
  }, 60000);

  resize();
  focus(current.id, true);
  requestAnimationFrame(() => {
    if (resize()) layoutNow();
  });
  if (!pendingId) history.replaceState({ node: current.id }, "", href(current.id));
  requestAnimationFrame(frame);
  // The VASC hierarchy is read only for who is assigned to what, so people can sit on their organization.
  const chartProject = "old";

  function treeStoreKey() {
    return "vasc-tree-v8-" + chartProject;
  }

  function savedTree() {
    try {
      const saved = JSON.parse(sessionStorage.getItem(treeStoreKey()) || "null");
      if (saved && saved.project === chartProject && Array.isArray(saved.items) && Date.now() - saved.at < 24 * 60 * 60 * 1000) return saved;
    } catch (error) {}
    return null;
  }

  function rememberTree(items, people) {
    try {
      const raw = JSON.stringify({ at: Date.now(), project: chartProject, items: items, people: people || [] });
      if (raw.length < 4500000) sessionStorage.setItem(treeStoreKey(), raw);
    } catch (error) {}
  }

  function loadPortrait(node) {
    if (!node || !node.personKey || portraits[node.personKey]) return;
    const img = new Image();
    const name = encodeURIComponent(node.title || "");
    img.src = "api/devops.php?action=portrait&key=" + encodeURIComponent(node.personKey) + "&name=" + name;
    portraits[node.personKey] = img;
  }

  function applyTree(items, note, resumeSearch) {
    const count = T.applyHierarchy(items || []);
      const query = String(resumeSearch || "").trim();
      const opened = pendingId && T.byId[pendingId] ? pendingId : "";
      pendingId = opened ? "" : pendingId;
      const statusNote = pendingId
        ? "That person or organization is not on the chart."
        : count
          ? "People are matched to their organization from Azure DevOps assignments. " + note
          : (note || "Azure DevOps returned no assignments.");
      liveNote = heldNote || statusNote;
      heldNote = "";
      if (pendingId) {
        history.replaceState({ node: current.id }, "", href(current.id));
        pendingId = "";
      }
      if (query) {
        if (!current || !T.byId[current.id]) current = T.byId[T.vcdsId] || current;
        showSearch(query);
        return;
      }
      const wanted = opened || (current && current.id) || "";
      if (wanted && T.byId[wanted]) {
        focus(wanted, false, false);
        return;
      }
      const home = T.vcdsId && T.byId[T.vcdsId] ? T.vcdsId : "";
      if (!home) return;
      focus(home, false, false);
      if (current) history.replaceState({ node: current.id }, "", href(current.id));
  }

  function loadHierarchy(fresh, resumeSearch) {
    if (!fresh) {
      const saved = savedTree();
      if (saved) {
        if (T.setPeople) T.setPeople(saved.people || []);
        applyTree(saved.items, "Saved on this browser. Sync reads Azure DevOps again.", resumeSearch);
        return;
      }
    }
    if (!heldNote) {
      liveNote = "Reading the Azure DevOps hierarchy…";
      renderPanel();
    }
    V.devops("tree", fresh ? { fresh: "1", project: chartProject } : { project: chartProject }).then((data) => {
      if (T.setPeople) T.setPeople(data.people || []);
      rememberTree(data.items || [], data.people || []);
      applyTree(data.items || [], data.note || "Saved on the server.", resumeSearch);
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
    unitMemo.clear();
  }

  V.searchChart = showSearch;

  V.ready.then(() => {
    V.devops("chart").then((data) => {
      T.applyChart(data, true);
      unitMemo.clear();
      if (current) focus(current.id, true, false);
    }).catch(() => {});
    if (V.role() === "vasc") {
      loadHierarchy(false);
      return;
    }
    placePublicApps();
    const opened = pendingId && T.byId[pendingId] ? pendingId : current.id;
    pendingId = "";
    liveNote = "Sign in to show people on this chart.";
    focus(opened, true, false);
  });
})();
