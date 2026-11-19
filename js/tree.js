(function () {
  const orgs = window.VASC_ORGS || [];
  const cases = window.VASC_CASES || [];
  const byName = Object.fromEntries(orgs.map((org) => [org.name, org]));
  const vcds = orgs.find((org) => org.short === "VCDS" && org.level === "L1");
  const byId = {};
  const children = {};

  function add(node) {
    node.hoursNeeded = node.hoursNeeded || 0;
    node.hoursLeft = node.hoursLeft || 0;
    node.hoursUsed = node.hoursUsed || 0;
    node.taskCount = node.taskCount || 0;
    byId[node.id] = node;
    if (!children[node.parent]) children[node.parent] = [];
    if (children[node.parent].indexOf(node.id) < 0) children[node.parent].push(node.id);
  }

  function setParent(id, parentId) {
    const node = byId[id];
    if (!node || node.parent === parentId) return;
    const previous = children[node.parent] || [];
    children[node.parent] = previous.filter((kid) => kid !== id);
    node.parent = parentId || "";
    if (!children[node.parent]) children[node.parent] = [];
    if (children[node.parent].indexOf(id) < 0) children[node.parent].push(id);
  }

  function subtree(id) {
    const out = [];
    const walk = (current) => {
      out.push(current);
      (children[current] || []).forEach(walk);
    };
    walk(id);
    return out;
  }

  let savedChart = { units: [], places: [] };

  function applyUnits(units) {
    (units || []).forEach((unit) => {
      const node = byId["org:" + unit.id];
      const parentOrg = orgs.find((org) => org.name === unit.parent);
      if (!node || !parentOrg) return;
      setParent(node.id, "org:" + parentOrg.id);
      if (unit.level) node.level = unit.level;
      const row = orgs.find((org) => org.id === String(unit.id));
      if (row) row.parent = unit.parent;
    });
  }

  function applyPlaces(places) {
    (places || []).forEach((place) => {
      const id = "wit:" + place.work;
      const under = "org:" + place.under;
      if (byId[id] && byId[under]) setParent(id, under);
    });
  }

  function applyChart(data, replace) {
    const incoming = data || { units: [], places: [] };
    if (replace) {
      savedChart = { units: incoming.units || [], places: incoming.places || [] };
    } else {
      const units = (savedChart.units || []).slice();
      (incoming.units || []).forEach((unit) => {
        const index = units.findIndex((item) => String(item.id) === String(unit.id));
        if (index >= 0) units[index] = unit;
        else units.push(unit);
      });
      const places = (savedChart.places || []).slice();
      (incoming.places || []).forEach((place) => {
        const index = places.findIndex((item) => String(item.work) === String(place.work));
        if (index >= 0) places[index] = place;
        else places.push(place);
      });
      savedChart = { units: units, places: places };
    }
    applyUnits(savedChart.units || []);
    applyPlaces(savedChart.places || []);
  }

  orgs.forEach((org) => {
    const parentOrg = byName[org.parent];
    add({
      id: "org:" + org.id,
      parent: parentOrg ? "org:" + parentOrg.id : "",
      kind: "org",
      title: org.short,
      name: org.name,
      fr: org.fr,
      level: org.level,
      sample: false
    });
  });

  function plain(org) {
    return String(org.name || "").replace(/^L\d+\s*-\s*/i, "").trim().toUpperCase();
  }

  function ancestorsOf(org) {
    const names = [];
    let cursor = org;
    let guard = 0;
    while (cursor && guard < 16) {
      names.push(String(cursor.short || "").toUpperCase());
      cursor = byName[cursor.parent];
      guard += 1;
    }
    return names;
  }

  function matchOrg(segment, pathParts) {
    const key = String(segment || "").toUpperCase().replace(/\s+/g, " ").trim();
    if (!key || key === "VASC" || key === "VCDS-VCEMD") return "";
    const hits = orgs.filter((org) => String(org.short || "").toUpperCase() === key || plain(org) === key);
    if (!hits.length) return "";
    if (hits.length === 1) return "org:" + hits[0].id;
    const pathSet = new Set(pathParts.map((part) => String(part).toUpperCase()));
    hits.sort((a, b) => {
      const score = (org) => ancestorsOf(org).reduce((sum, name) => sum + (pathSet.has(name) ? 2 : 0), 0) + (parseInt(String(org.level).replace(/\D/g, ""), 10) || 0);
      return score(b) - score(a);
    });
    return "org:" + hits[0].id;
  }

  const caseOrg = {};
  cases.forEach((row) => {
    if (!row.orgId) return;
    const ids = [];
    if (row.workId) ids.push(row.workId);
    (row.workItems || []).forEach((id) => ids.push(id));
    const url = String(row.devops || "").match(/edit\/(\d+)/);
    if (url) ids.push(url[1]);
    ids.forEach((id) => {
      caseOrg[String(id)] = "org:" + row.orgId;
    });
  });

  const orgsByToken = new Map();
  orgs.forEach((org) => {
    String(org.short || "").toUpperCase().split(/[^A-Z0-9]+/).forEach((token) => {
      if (token.length < 3 || token === "VASC") return;
      const list = orgsByToken.get(token) || [];
      list.push(org);
      orgsByToken.set(token, list);
    });
  });

  function orgByEpicTitle(title) {
    const raw = String(title || "").toUpperCase().trim();
    const tokens = raw.split(/[^A-Z0-9]+/).filter((token) => token.length >= 3);
    const whole = raw.replace(/[^A-Z0-9]/g, "");
    if (whole.length >= 2 && whole.length < 3) tokens.unshift(whole);
    tokens.sort((a, b) => b.length - a.length);
    for (let i = 0; i < tokens.length; i++) {
      const hits = (orgsByToken.get(tokens[i]) || []).slice();
      if (!hits.length) continue;
      hits.sort((a, b) => {
        const level = (org) => parseInt(String(org.level).replace(/\D/g, ""), 10) || 99;
        return level(a) - level(b);
      });
      return "org:" + hits[0].id;
    }
    const plainTitle = raw.replace(/^L\d+\s*-\s*/i, "").trim();
    const named = orgs.find((org) => plain(org) === plainTitle && plainTitle.length >= 4);
    return named ? "org:" + named.id : "";
  }

  function orgFor(item) {
    const type = String(item.type || "").toLowerCase();
    if (type === "epic" || type === "projects" || type === "project") {
      const titled = orgByEpicTitle(item.title);
      if (titled && byId[titled]) return titled;
    }
    const parts = String(item.area || "").split("\\").map((part) => part.trim()).filter(Boolean);
    for (let i = parts.length - 1; i >= 0; i--) {
      const id = matchOrg(parts[i], parts);
      if (id && byId[id]) return id;
    }
    if (caseOrg[String(item.id)] && byId[caseOrg[String(item.id)]]) return caseOrg[String(item.id)];
    return "org:" + vcds.id;
  }

  function kindOf(type) {
    const name = String(type || "").toLowerCase();
    if (name === "epic") return "epic";
    if (name === "feature") return "feature";
    if (name === "projects" || name === "project" || name.indexOf("project") >= 0 || name === "program") return "project";
    if (name === "screen" || name === "screens") return "screen";
    if (name === "issue") return "issue";
    if (name === "user story" || name === "product backlog item" || name === "requirement") return "story";
    if (name === "bug") return "bug";
    if (name === "task") return "task";
    return "work";
  }

  function stripWork() {
    Object.keys(byId).forEach((id) => {
      if (id.indexOf("wit:") === 0) delete byId[id];
    });
    Object.keys(children).forEach((id) => {
      children[id] = (children[id] || []).filter((kid) => String(kid).indexOf("wit:") !== 0);
      if (!children[id].length) delete children[id];
    });
  }

  function rollup(id, seen) {
    if (seen.has(id)) return;
    seen.add(id);
    const node = byId[id];
    const kids = children[id] || [];
    kids.forEach((kid) => rollup(kid, seen));
    if (!kids.length) {
      if (!node.taskCount) node.taskCount = node.kind === "task" || node.kind === "bug" ? 1 : 0;
      if (node.hoursUsed == null) node.hoursUsed = 0;
      if (!node.hoursUsed && (node.hoursNeeded || node.hoursLeft)) node.hoursUsed = (node.hoursNeeded || 0) - (node.hoursLeft || 0);
      return;
    }
    const summed = {
      hoursNeeded: kids.reduce((sum, kid) => sum + (byId[kid].hoursNeeded || 0), 0),
      hoursLeft: kids.reduce((sum, kid) => sum + (byId[kid].hoursLeft || 0), 0),
      hoursUsed: kids.reduce((sum, kid) => sum + (byId[kid].hoursUsed || 0), 0),
      taskCount: kids.reduce((sum, kid) => sum + (byId[kid].taskCount || 0), 0)
    };
    node.hoursNeeded = summed.hoursNeeded || node.hoursNeeded || 0;
    node.hoursLeft = summed.hoursLeft || node.hoursLeft || 0;
    node.hoursUsed = summed.hoursUsed || node.hoursUsed || 0;
    node.taskCount = summed.taskCount || node.taskCount || 0;
  }

  function rollupAll() {
    const seen = new Set();
    Object.keys(byId).forEach((id) => {
      if (!byId[id].parent) rollup(id, seen);
    });
  }

  rollupAll();

  function applyHierarchy(items) {
    stripWork();
    const list = items || [];
    const ids = new Set(list.map((item) => String(item.id)));
    list.forEach((item) => {
      if (!item || !item.id) return;
      const kind = kindOf(item.type);
      const hinted = kind === "project" && item.orgHint && byId["org:" + item.orgHint];
      const parentInTree = item.parentId && ids.has(String(item.parentId));
      add({
        id: "wit:" + item.id,
        parent: hinted ? "org:" + item.orgHint : (parentInTree ? "wit:" + item.parentId : orgFor(item)),
        kind: kind,
        title: item.title || item.type || "Work item",
        name: [item.type, item.state].filter(Boolean).join(" · "),
        level: item.type || "",
        state: item.state || "",
        workItemId: item.id,
        url: item.url || "",
        assigned: item.assigned || "",
        changed: item.changed || "",
        changedBy: item.changedBy || "",
        sample: false,
        hoursNeeded: item.hoursNeeded || 0,
        hoursLeft: item.hoursLeft || 0,
        hoursUsed: item.hoursUsed || 0,
        taskCount: kind === "task" || kind === "bug" ? 1 : 0
      });
    });
    applyPlaces(savedChart.places || []);
    rollupAll();
    return list.length;
  }

  function applyLive(items) {
    const byWork = {};
    Object.keys(byId).forEach((id) => {
      const node = byId[id];
      if (node.workItemId) byWork[String(node.workItemId)] = node;
    });
    (items || []).forEach((item) => {
      const node = byWork[String(item.id)];
      if (!node) return;
      if (item.title) {
        node.title = item.title;
        node.name = [item.type || node.level, item.state || node.state].filter(Boolean).join(" · ");
      }
      if (item.state) node.state = item.state;
      if (item.url) node.url = item.url;
      node.sample = false;
      if (item.hoursNeeded != null) node.hoursNeeded = item.hoursNeeded;
      if (item.hoursLeft != null) node.hoursLeft = item.hoursLeft;
      if (item.hoursUsed != null) node.hoursUsed = item.hoursUsed;
    });
    rollupAll();
  }

  const peopleDirectory = {};

  function personKey(name) {
    const key = String(name || "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
    return key || "person";
  }

  function setPeople(list) {
    Object.keys(peopleDirectory).forEach((key) => delete peopleDirectory[key]);
    (list || []).forEach((person) => {
      if (!person || !person.name) return;
      peopleDirectory[String(person.name).toLowerCase()] = person;
    });
  }

  function personNode(name) {
    const row = peopleDirectory[String(name || "").toLowerCase()];
    const key = row && row.key ? row.key : personKey(name);
    const id = "person:" + key;
    if (!byId[id]) {
      byId[id] = {
        id: id,
        parent: "",
        kind: "person",
        title: row && row.name ? row.name : name,
        name: "",
        level: "",
        personKey: key,
        avatar: !!(row && row.avatar),
        hoursNeeded: 0,
        hoursLeft: 0,
        hoursUsed: 0,
        taskCount: 0
      };
    }
    return byId[id];
  }

  function recentWork(iso) {
    const then = Date.parse(iso || "");
    if (!then) return false;
    return Date.now() - then < 30 * 24 * 60 * 60 * 1000;
  }

  function peopleUnder(rootId, staff) {
    const root = byId[rootId];
    if (!root || root.kind === "person") return [];
    const names = new Map();
    function note(name, recent) {
      if (!name || name.charAt(0) === "[") return;
      const person = personNode(name);
      const row = names.get(person.id) || { person: person, recent: false };
      if (recent) row.recent = true;
      names.set(person.id, row);
    }
    function take(node) {
      if (!node) return;
      const recent = recentWork(node.changed);
      if (node.changedBy) note(node.changedBy, recent);
      const same = node.changedBy && String(node.changedBy).toLowerCase() === String(node.assigned || "").toLowerCase();
      note(node.assigned, recent && (!node.changedBy || same));
    }
    if (root.kind === "org") {
      if (root.id !== "org:vasc-do") return [];
      Object.keys(byId).forEach((id) => {
        const node = byId[id];
        if (!node || node.kind === "org" || node.kind === "person") return;
        take(node);
      });
    } else {
      subtree(root.id).forEach((childId) => take(byId[childId]));
    }
    const rows = Array.from(names.values()).filter((row) => {
      if (staff === "recent") return row.recent;
      if (staff === "earlier") return !row.recent;
      return true;
    });
    return rows.map((row) => row.person).sort((a, b) => a.title.localeCompare(b.title));
  }

  function allPeople() {
    const out = [];
    Object.keys(peopleDirectory).forEach((key) => {
      const row = peopleDirectory[key];
      if (!row || !row.name || String(row.name).charAt(0) === "[") return;
      out.push(personNode(row.name));
    });
    return out;
  }

  function projectsForPerson(personId) {
    const person = byId[personId];
    if (!person) return [];
    const name = String(person.title || "").toLowerCase();
    return Object.keys(byId).filter((id) => byId[id].kind === "project").map((id) => byId[id]).filter((project) => {
      return subtree(project.id).some((childId) => String((byId[childId] && byId[childId].assigned) || "").toLowerCase() === name);
    });
  }

  window.VASC_TREE = {
    byId: byId,
    children: children,
    vcdsId: "org:" + vcds.id,
    applyHierarchy: applyHierarchy,
    applyLive: applyLive,
    applyChart: applyChart,
    setParent: setParent,
    setPeople: setPeople,
    peopleUnder: peopleUnder,
    allPeople: allPeople,
    projectsForPerson: projectsForPerson,
    subtree: subtree,
    add: add
  };
})();
