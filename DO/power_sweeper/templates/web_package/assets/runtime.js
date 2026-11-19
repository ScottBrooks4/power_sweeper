/**
 * Power Sweeper web package runtime.
 * Evaluates Power Fx formulas from the conversion file and calls /api/data.
 */
(function () {
  const irEl = document.getElementById("ps-ir");
  let ir = irEl ? JSON.parse(irEl.textContent || "{}") : {};
  const screens = () => (Array.isArray(ir.screens) ? ir.screens : []);
  const appOf = () => ir.app || null;
  const tablesOf = () => (Array.isArray(ir.tables) ? ir.tables : []);

  const root = document.getElementById("app-root");
  const state = {
    globals: Object.create(null),
    context: Object.create(null),
    screen: (root && root.dataset.startScreen) || null,
    controlIndex: Object.create(null),
    values: Object.create(null),
    bound: false,
  };
  [
    "varSideMenu",
    "varTopMenu",
    "varBugReport",
    "varShowLoadingContainer",
    "varDebugMode",
    "gblDarkMode",
    "fnIsAcknowledged",
    "fnIsEmerStep",
    "fnSubmitReady",
    "varShowValidation",
    "varFormSubmitted",
    "varIsEditMode",
    "varIsEditingForm",
    "varNavLock",
    "varShowConfirmDeleteContainer",
  ].forEach((name) => {
    state.globals[name] = false;
  });
  // Preview defaults so Admin home card + first admin pane are reachable.
  state.globals.varVisibleAdminScreen = 1;
  state.context.varVisibleAdminScreen = 1;
  state.globals.varDetailScreenSelect = 1;
  state.context.varDetailScreenSelect = 1;
  state.globals.varCurrentUser = { role: "Admin", email: "preview@local", preferences: { theme: "light" } };
  state.globals.varRequestID = "";
  state.globals.varCurrentPackage = {
    ID: "",
    Time: "",
    Destination: "",
    VIP: "",
    User: { RequestingAgencyEmail: "" },
  };
  state.globals.varTempMessages = [];
  state.globals.colMessages = [];
  state.globals.varL1Approvers = [{ l1: "IRL", email: "" }];
  state.globals.varTempL1 = [{ l1: "IRL", email: "" }];
  // OnStart seeds varDevApprover; LoadAppSettings sets varDefaultApprover from
  // SharePoint or varDevApprover when fnIsDev. Preview keeps the OnStart value.
  state.globals.varDevApprover = "Scott.Brooks4@ecn.forces.gc.ca";
  state.globals.varDefaultApprover = "Scott.Brooks4@ecn.forces.gc.ca";
  // App.Formulas: varEnv = "dev" (unlocks Settings → Data debug buttons via fnDebugAllowed).
  state.globals.varEnv = "dev";
  state.globals.varTempUser = [
    {
      user: "preview@local",
      role: "Admin",
      preferences: {
        theme: "light",
        show_delete_confirm: "true",
        show_notif_confirm: "true",
      },
    },
  ];
  state.globals.varShowConfirmNotifContainer = false;
  state.context.varShowConfirmNotifContainer = false;
  // Preview stub for Admin LookUp('CDLS (L) App Settings', Title = "1").
  state.globals["CDLS (L) App Settings"] = [
    {
      Title: "1",
      "Default Approver": "Scott.Brooks4@ecn.forces.gc.ca",
      Users: "[]",
      "L1 Data": "[]",
      Messages: "[]",
    },
  ];
  // Details Acknowledgment list — idle empty (no invented SP rows).
  state.globals["CDLS (L) Approval Tracking"] = [];
  state.rteDefaults = Object.create(null);

  function indexControls(list) {
    const map = Object.create(null);
    // v2 app-logic: flat controls map (HTML owns structure).
    if (ir && ir.controls && typeof ir.controls === "object") {
      Object.keys(ir.controls).forEach((name) => {
        const c = ir.controls[name];
        if (!c || typeof c !== "object") return;
        map[name] = {
          node: {
            name: name,
            role: c.role || "",
            type: c.type || "",
            component: c.component || "",
            properties: c.properties || c,
          },
          screen: c.screen || null,
        };
      });
    }
    function walk(node, screen) {
      if (!node || typeof node !== "object") return;
      const name = node.name;
      if (name && !map[name]) {
        map[name] = { node, screen };
      } else if (name && map[name] && node.properties && (!map[name].node.properties || !Object.keys(map[name].node.properties).length)) {
        map[name] = { node, screen };
      }
      (node.children || []).forEach((c) => walk(c, screen));
    }
    (list || []).forEach((s) => walk(s, s.name));
    if (ir && ir.app) walk(ir.app, "App");
    if (ir && ir.components && typeof ir.components === "object") {
      Object.keys(ir.components).forEach((k) => walk(ir.components[k], k));
    }
    return map;
  }

  function expandComponentsIntoScreens(rawIr) {
    // v2 flat controls already include component-hosted formulas; nothing to expand.
    if (rawIr && Number(rawIr.version) >= 2 && rawIr.controls) {
      return rawIr;
    }
    const comps = rawIr && rawIr.components ? rawIr.components : {};
    const defs = Object.create(null);
    Object.keys(comps).forEach((k) => {
      const c = comps[k];
      if (c && typeof c === "object") {
        defs[c.name || k] = c;
      }
    });
    function expandNode(node) {
      if (!node || typeof node !== "object") return node;
      let kids = Array.isArray(node.children) ? node.children.slice() : [];
      const comp = node.component;
      if (comp && kids.length === 0 && defs[comp] && Array.isArray(defs[comp].children)) {
        try {
          kids = JSON.parse(JSON.stringify(defs[comp].children));
        } catch (_) {
          kids = defs[comp].children.slice();
        }
      }
      node.children = kids.map((c) => expandNode(c));
      return node;
    }
    const screens = Array.isArray(rawIr.screens) ? rawIr.screens : [];
    rawIr.screens = screens.map((s) => expandNode(JSON.parse(JSON.stringify(s))));
    return rawIr;
  }

  function stripEq(s) {
    s = String(s == null ? "" : s).trim();
    return s.startsWith("=") ? s.slice(1).trim() : s;
  }

  function stripComments(s) {
    let out = "";
    let i = 0;
    let quote = null;
    while (i < s.length) {
      const ch = s[i];
      const next = s[i + 1];
      if (quote) {
        out += ch;
        if (ch === quote) quote = null;
        i++;
        continue;
      }
      if (ch === '"' || ch === "'") {
        quote = ch;
        out += ch;
        i++;
        continue;
      }
      if (ch === "/" && next === "/") {
        i += 2;
        while (i < s.length && s[i] !== "\n") i++;
        continue;
      }
      if (ch === "/" && next === "*") {
        i += 2;
        while (i < s.length - 1 && !(s[i] === "*" && s[i + 1] === "/")) i++;
        i += 2;
        out += " ";
        continue;
      }
      out += ch;
      i++;
    }
    return out;
  }

  function unquote(s) {
    s = String(s).trim();
    if (isQuotedString(s)) {
      return s.slice(1, -1).replace(/""/g, '"').replace(/\\"/g, '"');
    }
    return s;
  }

  /** True only when the whole expr is one quoted string, not `"a" & varId & "b"`. */
  function isQuotedString(expr) {
    const s = String(expr == null ? "" : expr);
    if (s.length < 2) return false;
    const q = s[0];
    if (q !== '"' && q !== "'") return false;
    for (let i = 1; i < s.length; i++) {
      const ch = s[i];
      if (ch === "\\") {
        i++;
        continue;
      }
      if (ch === q && s[i + 1] === q) {
        i++;
        continue;
      }
      if (ch === q) return i === s.length - 1;
    }
    return false;
  }

  function packageBase() {
    if (typeof window !== "undefined" && window.PS_BASE != null) {
      return String(window.PS_BASE).replace(/\/$/, "");
    }
    try {
      const path = String(location.pathname || "");
      // Prefer the full published path: /web/VCR, /web/php-xml-canvas, /power_sweeper/web/...
      const m = path.match(/^(.*?\/web\/[^/]+)(?:\/|$)/i);
      if (m) return m[1];
      const m2 = path.match(/^(.*\/web)(?:\/|$)/i);
      if (m2) return m2[1];
    } catch (_) {}
    return "";
  }

  function packageUrl(path) {
    const base = packageBase();
    const p = path.startsWith("/") ? path : "/" + path;
    return base + p;
  }

  async function apiData(action, payload) {
    const res = await fetch(packageUrl("/api/data"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action, ...payload }),
    });
    if (!res.ok) {
      throw new Error("data api " + res.status);
    }
    return res.json();
  }

  const TABLE_ALIASES = {
    "CDLS (L) VCR Tracking List": ["VCRTrackingList", "Tracking", "CDLS_L_VCR_Tracking_List"],
    "CDLS (L) Approval Tracking": ["ApprovalTracking", "Approvals", "CDLS_L_Approval_Tracking"],
    "CDLS (L) Member Locations": ["MemberLocations", "Locations", "CDLS_L_Member_Locations"],
    "CDLS (L) App Users": ["AppUsers", "Users", "CDLS_L_App_Users"],
    "CDLS (L) App Settings": ["AppSettings", "Settings", "CDLS_L_App_Settings"],
    "CDLS (L) User Tracking": ["UserTracking", "CDLS_L_User_Tracking"],
  };

  const COMPONENT_PARAMS = {
    sendAcknowledgmentMail: ["email", "comments"],
    sendECFConfirmation: ["email"],
    sendRequestConfirmation: ["email"],
    sendIVCONumber: ["email", "number", "requestid"],
    notifyOriginator: ["email"],
    loadPackage: ["requestID"],
    loadTransmittals: ["requestID", "default", "l1"],
    deleteRequest: ["requestID"],
    updateUser: ["section", "updated_record"],
    loadExcelData: ["excel_data"],
    saveAppUsers: [],
    loadAppUsers: [],
    refreshAppsSettings: [],
  };

  function tableAliases(name) {
    const raw = String(name || "").replace(/^'|'$/g, "").replace(/^"|"$/g, "");
    const out = [raw];
    Object.keys(TABLE_ALIASES).forEach((sp) => {
      const als = TABLE_ALIASES[sp];
      if (sp === raw || als.indexOf(raw) >= 0) {
        out.push(sp);
        als.forEach((a) => out.push(a));
      }
    });
    return Array.from(new Set(out));
  }

  function parseJsonField(value) {
    if (value == null || value === "") return value;
    if (typeof value === "object") return value;
    const t = String(value).trim();
    if (!t.startsWith("{") && !t.startsWith("[")) return value;
    try {
      return JSON.parse(t);
    } catch (_) {
      return value;
    }
  }

  function normalizeTrackingRow(row) {
    if (!row || typeof row !== "object") return row;
    const out = Object.assign({}, row);
    out.request_user = parseJsonField(out.request_user);
    const person = out.request_user && typeof out.request_user === "object" ? out.request_user : {};
    const profile = office365Profile();
    const email =
      person.Email ||
      person.RequestingAgencyEmail ||
      person.mail ||
      out.requester_email ||
      out.Email ||
      profile.mail ||
      "";
    out.request_user = Object.assign({}, person, {
      Email: email,
      RequestingAgencyEmail: person.RequestingAgencyEmail || email,
      DisplayName: person.DisplayName || out.Name || profile.displayName || email,
    });
    if (out.Email == null || out.Email === "") out.Email = email;
    if (out.requester_email == null || out.requester_email === "") out.requester_email = email;
    if (out.Posted == null || out.Posted === "") out.Posted = "false";
    if (out.approval_state == null || out.approval_state === "") {
      out.approval_state = "Awaiting Acknowledgment";
    }
    if (out["Annex 1"] == null && out.Annex_1 != null) out["Annex 1"] = out.Annex_1;
    if (out["Annex 2"] == null && out.Annex_2 != null) out["Annex 2"] = out.Annex_2;
    return out;
  }

  function enrichTrackingFields(table, fields) {
    if (!/VCR Tracking/i.test(String(table || ""))) return fields;
    const next = Object.assign({}, fields || {});
    const profile = office365Profile();
    const dest = resolvePath("Destination", ["Selected", "Value"]);
    if (next.request_user == null || typeof next.request_user !== "object") {
      next.request_user = {
        Email: profile.mail,
        DisplayName: profile.displayName,
        RequestingAgencyEmail: profile.mail,
        Claims: "i:0#.f|membership|" + String(profile.mail || "").toLowerCase(),
      };
    }
    const email =
      next.request_user.Email ||
      next.request_user.RequestingAgencyEmail ||
      profile.mail;
    if (next.Email == null || next.Email === "") next.Email = email;
    if (next.requester_email == null || next.requester_email === "") next.requester_email = email;
    if (next.Posted == null || next.Posted === "") next.Posted = "false";
    if (next.approval_state == null || next.approval_state === "") {
      next.approval_state = "Awaiting Acknowledgment";
    }
    if (next.time_submitted == null || next.time_submitted === "") {
      next.time_submitted = new Date().toISOString();
    }
    if ((next.Destination == null || next.Destination === "") && dest) {
      next.Destination = dest;
    }
    if (next.VisitDateFrom == null || next.VisitDateFrom === "") {
      const from = state.values["VisitDateFrom.SelectedDate"];
      if (from) next.VisitDateFrom = from;
    }
    if (next.VisitDateTo == null || next.VisitDateTo === "") {
      const to = state.values["VisitDateTo.SelectedDate"];
      if (to) next.VisitDateTo = to;
    }
    return next;
  }

  function cacheTable(name, rows) {
    const list = Array.isArray(rows) ? rows : [];
    const mapped = /VCR Tracking/i.test(String(name || ""))
      ? list.map((row) => normalizeTrackingRow(row))
      : list;
    tableAliases(name).forEach((alias) => setVar(alias, mapped));
  }

  function isPlausibleTable(name) {
    const raw = String(name || "").trim();
    if (!raw || raw.length > 80) return false;
    if (/[\n(=]/.test(raw)) return false;
    return true;
  }

  async function hydrateTable(name) {
    const data = await apiData("list", { table: name });
    const rows = data.rows || [];
    cacheTable(name, rows);
    if (/VCR Tracking List|VCRTrackingList/i.test(String(name || ""))) {
      try {
        hydrateLookupGallery();
      } catch (_) {}
    }
    return rows;
  }

  async function invokeSendEmail(to, subject, body, extra) {
    const payload = {
      action: "invoke",
      connector: "Office365Outlook",
      operation: "SendEmailV2",
      to: to == null ? "" : String(to),
      subject: subject == null ? "" : String(subject),
      body: body == null ? "" : String(body),
      cc: extra && extra.cc != null ? String(extra.cc) : "",
      bcc: extra && extra.bcc != null ? String(extra.bcc) : "",
    };
    return apiData("invoke", payload);
  }

  function parseQuotedTable(expr) {
    const m = String(expr || "").match(/^'([^']+)'/);
    if (m) return m[1];
    const m2 = String(expr || "").match(/^"([^"]+)"/);
    return m2 ? m2[1] : "";
  }

  function pickField(row, name) {
    if (!row || typeof row !== "object") return undefined;
    const raw = String(name || "").replace(/^'|'$/g, "").replace(/^"|"$/g, "");
    if (Object.prototype.hasOwnProperty.call(row, raw)) return row[raw];
    const collapsed = raw.replace(/[\s_#]+/g, "");
    const underscored = raw.replace(/[\s#]+/g, "_");
    const keys = Object.keys(row);
    for (let i = 0; i < keys.length; i++) {
      const k = keys[i];
      if (k === raw || k.replace(/[\s_#]+/g, "") === collapsed || k.replace(/[\s#]+/g, "_") === underscored) {
        return row[k];
      }
    }
    return undefined;
  }

  function fnEmailLocal(addr) {
    const loc = String(addr == null ? "" : addr).trim().toLowerCase();
    if (loc.endsWith("@ecn.forces.gc.ca")) return loc.slice(0, -"@ecn.forces.gc.ca".length);
    if (loc.endsWith("@forces.gc.ca")) return loc.slice(0, -"@forces.gc.ca".length);
    return loc;
  }

  function fnEmailBoth(addr) {
    const loc = String(addr == null ? "" : addr).trim().toLowerCase();
    const local = fnEmailLocal(addr);
    if (!loc || loc.indexOf("@") < 0) return addr;
    const def = String(getVar("varDefaultApprover") || getVar("varDevApprover") || "");
    const firstDef = def.split(/[;,]/).map((s) => s.trim()).find((s) => s.indexOf("@") >= 0) || "";
    if (firstDef && local === fnEmailLocal(firstDef)) return addr;
    if (loc.endsWith("@ecn.forces.gc.ca") || loc.endsWith("@forces.gc.ca")) {
      return local + "@ecn.forces.gc.ca;" + local + "@forces.gc.ca";
    }
    return addr;
  }

  function fnEmailList(addrs) {
    return String(addrs == null ? "" : addrs)
      .split(/[;,]/)
      .map((s) => s.trim())
      .filter((s) => s && s.indexOf("@") >= 0)
      .map((s) => fnEmailBoth(s))
      .join(";");
  }

  // App.Formulas fnApplyEmailTokens — key order matches the nested Substitute.
  const EMAIL_TOKEN_KEYS = [
    "vcr_id",
    "link_to_app",
    "pdf_link",
    "ecf_form_link",
    "ivco_number",
    "summary",
    "comments",
    "link_to_request",
    "default_acknowledger",
  ];

  function fnIsDev() {
    return String(getVar("varEnv") || "prod").toLowerCase() === "dev";
  }

  function linkToApp() {
    return fnIsDev()
      ? "https://apps.powerapps.com/play/e/9ed70000-7006-e3b7-a4b4-c31cb53e5df0/a/fd6734ba-bee6-4f7e-a618-167467a008e7?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=cf025198-bc5c-4693-a452-2f7cd2a09a30"
      : "https://apps.powerapps.com/play/e/510961b1-a339-ed63-a313-2a1d6437d0f3/a/24ec1f53-588a-4729-acbe-e3159146922c?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=44f7afad-754a-4a9c-9bc7-0ac827b21470";
  }

  function sharePointSite() {
    return fnIsDev()
      ? "https://018gc.sharepoint.com/sites/ORG-9872-0002-Dev"
      : "https://018gc.sharepoint.com/sites/000-003-656";
  }

  function emailSourceTime() {
    return String(Math.floor(Date.now() / 1000));
  }

  function requestLinkName() {
    const pkg = getVar("varCurrentPackage") || {};
    const id = coalesceText(pkg.ID, getVar("varSubmittedRequestUID"), getVar("varRequestID"));
    const row = lookupTrackingByTitle(id);
    const user = row && row.request_user != null ? row.request_user : pkg.User;
    let display = "";
    if (user && typeof user === "object") {
      display = String(user.DisplayName || user.displayName || "");
    } else if (typeof user === "string" && user.trim().charAt(0) === "{") {
      try {
        const parsed = JSON.parse(user);
        display = String((parsed && (parsed.DisplayName || parsed.displayName)) || "");
      } catch (_) {
        display = "";
      }
    }
    const u = getVar("varCurrentUser");
    return (
      display.trim() ||
      String((u && (u.fullName || u.FullName)) || "").trim() ||
      String(pkg.RequestingAgencyName || "").trim()
    );
  }

  function requestLink() {
    const pkg = getVar("varCurrentPackage") || {};
    const id = coalesceText(pkg.ID, getVar("varSubmittedRequestUID"), "");
    let url = linkToApp();
    if (id) {
      url += "&requestid=" + encodeURIComponent(id);
      const name = requestLinkName();
      if (name) url += "&name=" + encodeURIComponent(name);
    }
    return url + "&sourcetime=" + emailSourceTime();
  }

  function lookupTrackingByTitle(title) {
    const want = String(title == null ? "" : title);
    if (!want) return null;
    const names = tableAliases("CDLS (L) VCR Tracking List");
    for (let n = 0; n < names.length; n++) {
      const rows = getVar(names[n]);
      if (!Array.isArray(rows)) continue;
      for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        if (row && String(row.Title || row.title || "") === want) return row;
      }
    }
    return null;
  }

  function toDate(value) {
    if (value == null || value === "") return null;
    if (value instanceof Date && !Number.isNaN(value.getTime())) return value;
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? null : d;
  }

  function evalMax(argsRaw) {
    const args = splitArgs(argsRaw);
    if (args.length === 0) return 0;
    const source = evalExpr(args[0]);
    if (Array.isArray(source)) {
      let max = 0;
      const field = args[1];
      source.forEach((row) => {
        const saved = Object.assign(Object.create(null), state.context);
        try {
          if (row && typeof row === "object") {
            state.context.ThisRecord = row;
            Object.keys(row).forEach((k) => {
              state.context[k] = row[k];
            });
          }
          const n = Number(field ? evalExpr(field) : row);
          if (Number.isFinite(n) && n > max) max = n;
        } finally {
          state.context = saved;
        }
      });
      return max;
    }
    let max = Number(source);
    if (!Number.isFinite(max)) max = 0;
    for (let i = 1; i < args.length; i++) {
      const n = Number(evalExpr(args[i]));
      if (Number.isFinite(n) && n > max) max = n;
    }
    return max;
  }

  function coalesceText() {
    for (let i = 0; i < arguments.length; i++) {
      const v = arguments[i];
      if (v != null && String(v) !== "") return String(v);
    }
    return "";
  }

  function themeCss(name) {
    const t = getVar("gblTheme");
    if (t && typeof t === "object" && t[name] != null) return String(t[name]);
    return "";
  }

  function createSummaryHtml() {
    const pkg = getVar("varCurrentPackage") || {};
    const cText = themeCss("TextCss");
    const cSurface = themeCss("SurfaceCss");
    const cSurfaceMuted = themeCss("SurfaceMutedCss");
    const cSectionHeadBg = themeCss("SurfaceAltCss");
    const cBorder = themeCss("BorderCss");
    const vip = pkg.VIP === true || pkg.VIP === "true" || pkg.VIP === "Yes" ? "Yes" : "No";
    const dates = coalesceText(pkg.VisitDateFrom, "") + " - " + coalesceText(pkg.VisitDateTo, "");
    const l1 = coalesceText(pkg.L1Organization, "") + (pkg.L1Acronym ? " (" + pkg.L1Acronym + ")" : "");
    const user = pkg.User && typeof pkg.User === "object" ? pkg.User : {};
    const proof = getVar("colProofFiles");
    const proofHtml = Array.isArray(proof) && proof.length
      ? proof.map((f) => String((f && (f.FileName || f.Name)) || "")).filter(Boolean).join("<br>")
      : "None";
    function cell(label, value, muted) {
      const bgLabel = muted ? cSurfaceMuted : cSurfaceMuted;
      return (
        "<tr>" +
        "<td style='background-color:" + bgLabel + ";color:" + cText + ";'><strong>" + label + "</strong></td>" +
        "<td style='background-color:" + cSurface + ";color:" + cText + ";'>" + (value == null ? "" : String(value)) + "</td>" +
        "</tr>"
      );
    }
    return (
      "<tr><td style='padding:0 30px 25px 30px; color:" + cText + ";'>" +
      "<table width='100%' cellpadding='10' cellspacing='0' border='0' style='border:1px solid " + cBorder +
      ";border-collapse:collapse;background-color:" + cSurface + ";color:" + cText + ";'>" +
      "<tr style='background-color:" + cSectionHeadBg + ";color:" + cText + ";'><td colspan='2'><strong>Request Summary</strong></td></tr>" +
      cell("Request ID", pkg.ID) +
      cell("Destination", pkg.Destination) +
      cell("VIP", vip) +
      cell("VIP Level", pkg.VIPLevel) +
      cell("Visit Dates", dates) +
      cell("L1", l1) +
      cell("Requesting Agency", pkg.RequestingAgencyName) +
      cell("Security Officer", pkg.SecurityOfficerName) +
      cell("Proof of Security Clearance", proofHtml) +
      cell("Visitors", pkg.Visitors) +
      cell("Sites", pkg.Sites) +
      cell("Submitted By", user.DisplayName || user.displayName || "") +
      cell("Submission Date", pkg.Time) +
      "</table></td></tr>"
    );
  }

  function emailValues() {
    const pkg = getVar("varCurrentPackage") || {};
    const id = coalesceText(pkg.ID, getVar("varSubmittedRequestUID"), "");
    const track = lookupTrackingByTitle(coalesceText(pkg.ID, getVar("varSubmittedRequestUID"), getVar("varRequestID")));
    const pdf =
      coalesceText(pkg.PDF, track && track.spo_pdf_link) ||
      (track && (track.ID != null || track.Id != null)
        ? sharePointSite() +
          "/Lists/CDLS%20L%20VCR%20Tracking%20List/DispForm.aspx?ID=" +
          coalesceText(track.ID, track.Id)
        : "");
    const name = requestLinkName();
    const ecf =
      linkToApp() +
      "&sourcetime=" +
      emailSourceTime() +
      "&requestid=" +
      encodeURIComponent(id) +
      (name ? "&name=" + encodeURIComponent(name) : "") +
      "&ecf=true";
    return {
      vcr_id: id,
      link_to_app: linkToApp(),
      pdf_link: pdf,
      ecf_form_link: ecf,
      ivco_number: coalesceText(getVar("varIvcoNumber"), ""),
      default_acknowledger: coalesceText(getVar("varDefaultApprover"), getVar("fnTransmittalFirst"), ""),
      summary: createSummaryHtml(),
      comments: coalesceText(getVar("varEmailComments"), ""),
      link_to_request: requestLink(),
      open_request_link: requestLink(),
    };
  }

  function fnEmailToken(key) {
    return "{" + String(key == null ? "" : key) + "}";
  }

  function fnEmailTokenOld(key) {
    return "<" + String(key == null ? "" : key) + ">";
  }

  function applyEmailTokens(message, values) {
    let raw = String(message == null ? "" : message);
    raw = raw.split("&lt;").join("<").split("&gt;").join(">");
    raw = raw.split(fnEmailToken("open_request_link")).join(fnEmailToken("link_to_request"));
    raw = raw.split(fnEmailTokenOld("open_request_link")).join(fnEmailTokenOld("link_to_request"));
    const vals = values && typeof values === "object" ? values : {};
    for (let i = 0; i < EMAIL_TOKEN_KEYS.length; i++) {
      const key = EMAIL_TOKEN_KEYS[i];
      const repl = vals[key] == null ? "" : String(vals[key]);
      raw = raw.split(fnEmailToken(key)).join(repl);
      raw = raw.split(fnEmailTokenOld(key)).join(repl);
    }
    return raw;
  }

  function fnApplyEmailTokens(message) {
    return applyEmailTokens(message, emailValues());
  }

  function namedFormula(name) {
    switch (name) {
      case "fnIsDev":
        return fnIsDev();
      case "fnIsProd":
        return !fnIsDev();
      case "LinkToApp":
        return linkToApp();
      case "fnSharePointSite":
        return sharePointSite();
      case "fnEmailSourceTime":
        return emailSourceTime();
      case "fnRequestLink":
        return requestLink();
      case "fnRequestLinkName":
        return requestLinkName();
      case "fnCreateSummaryHTML":
        return createSummaryHtml();
      case "fnEmailValues":
        return emailValues();
      case "fnDebugAllowed":
        return (
          fnIsDev() ||
          String((getVar("varCurrentUser") || {}).role || "").toLowerCase() === "admin"
        );
      case "fnIsEmerStep":
        return String(state.screen || "") === "Emergency Contact";
      case "fnRequestTitle": {
        const id = getVar("varRequestID");
        if (id == null || String(id).trim() === "" || String(id) === "-1") return null;
        return id;
      }
      case "fnIsAcknowledged": {
        const title = namedFormula("fnRequestTitle");
        if (title == null || String(title).trim() === "") return false;
        const row = lookupTrackingByTitle(title);
        return row != null && String(row.approval_state || "") === "Acknowledged";
      }
      case "fnDestOK": {
        const dest = resolvePath("Destination", ["Selected", "Value"]);
        if (dest == null || String(dest).trim() === "") return false;
        if (String(dest) === "British Overseas Territories (BOTs)") {
          const bot = resolvePath("BOTSelect", ["Selected", "Value"]);
          return bot != null && String(bot).trim() !== "";
        }
        return true;
      }
      case "fnL1Name": {
        const fromVar = getVar("varL1Name");
        if (fromVar != null && String(fromVar).trim() !== "") return String(fromVar).trim();
        const sel = resolvePath("L1", ["Selected", "Name"]);
        return sel != null ? String(sel).trim() : "";
      }
      case "fnL1Acro": {
        const fromVar = getVar("varL1Acronym");
        if (fromVar != null && String(fromVar).trim() !== "") return String(fromVar).trim();
        const sel = resolvePath("L1Acro", ["Selected", "Acronym"]);
        return sel != null ? String(sel).trim() : "";
      }
      case "fnL1Selected": {
        const name = String(namedFormula("fnL1Name") ?? "");
        const acro = String(namedFormula("fnL1Acro") ?? "");
        return (
          name.trim() !== "" &&
          name.indexOf("-----") < 0 &&
          acro.trim() !== "" &&
          acro.indexOf("-----") < 0
        );
      }
      case "fnL1OK":
        return namedFormula("fnL1Selected");
      case "fnSubmitReady":
        if (asBool(getVar("varDebugMode"))) return true;
        if (namedFormula("fnIsEmerStep")) return false;
        return false;
      case "fnEmailTokenGuide":
        if (ir.collections && Array.isArray(ir.collections.fnEmailTokens)) {
          return ir.collections.fnEmailTokens
            .map((r) => fnEmailToken(r.Key) + " — " + (r.Label || ""))
            .join("\n");
        }
        return "";
      default:
        return undefined;
    }
  }

  function findComponentFormula(host, method) {
    const comps = ir.components && typeof ir.components === "object" ? ir.components : {};
    if (comps[host] && comps[host].properties && comps[host].properties[method]) {
      return comps[host].properties[method];
    }
    const entry = state.controlIndex[host];
    const cname = entry && entry.node && entry.node.component;
    if (cname && comps[cname] && comps[cname].properties && comps[cname].properties[method]) {
      return comps[cname].properties[method];
    }
    const keys = Object.keys(comps);
    for (let i = 0; i < keys.length; i++) {
      const name = keys[i];
      if (
        host === name ||
        host.indexOf(name) === 0 ||
        String(host).toLowerCase().indexOf(String(name).toLowerCase()) >= 0
      ) {
        if (comps[name].properties && comps[name].properties[method]) {
          return comps[name].properties[method];
        }
      }
    }
    if (comps.ExternalFunctions && comps.ExternalFunctions.properties && comps.ExternalFunctions.properties[method]) {
      return comps.ExternalFunctions.properties[method];
    }
    if (comps.topSubmit && comps.topSubmit.properties && comps.topSubmit.properties[method]) {
      return comps.topSubmit.properties[method];
    }
    return null;
  }

  function bindComponentArgs(method, argExprs) {
    const names = COMPONENT_PARAMS[method] || [];
    const args = splitArgs(argExprs || "");
    names.forEach((name, i) => {
      const v = args[i] != null ? evalExpr(args[i]) : null;
      state.context[name] = v;
      state.globals[name] = v;
    });
  }

  function activeScreenEl() {
    return document.querySelector(".screen.screen--active, .screen.active");
  }

  function findByControl(name, root) {
    const want = String(name);
    const rowScope = state.context.__rowScope;
    if (rowScope && rowScope.querySelectorAll && !root) {
      const nodes = rowScope.querySelectorAll("[data-control]");
      for (let i = 0; i < nodes.length; i++) {
        if (nodes[i].getAttribute("data-control") === want) return nodes[i];
      }
      if (rowScope.getAttribute && rowScope.getAttribute("data-control") === want) {
        return rowScope;
      }
    }
    const active = activeScreenEl();
    const scopes = [];
    if (root) scopes.push(root);
    if (active && root !== active) scopes.push(active);
    scopes.push(document);
    for (const scope of scopes) {
      if (!scope || !scope.querySelectorAll) continue;
      const nodes = scope.querySelectorAll("[data-control]");
      for (let i = 0; i < nodes.length; i++) {
        if (nodes[i].getAttribute("data-control") === want) return nodes[i];
      }
    }
    return null;
  }

  function findAllByControl(name) {
    const want = String(name);
    const active = activeScreenEl();
    const scope = active || document;
    const out = [];
    const nodes = scope.querySelectorAll("[data-control]");
    for (let i = 0; i < nodes.length; i++) {
      if (nodes[i].getAttribute("data-control") === want) out.push(nodes[i]);
    }
    if (out.length === 0 && scope !== document) {
      const all = document.querySelectorAll("[data-control]");
      for (let i = 0; i < all.length; i++) {
        if (all[i].getAttribute("data-control") === want) out.push(all[i]);
      }
    }
    return out;
  }

  function findScreenEl(name) {
    const want = String(name);
    const nodes = document.querySelectorAll(".screen[data-screen]");
    for (let i = 0; i < nodes.length; i++) {
      if (nodes[i].getAttribute("data-screen") === want) return nodes[i];
    }
    return null;
  }

  function markSidebarActive(name) {
    document.querySelectorAll("[data-navigate]").forEach((el) => {
      el.classList.toggle(
        "ctrl-navicon--active",
        el.getAttribute("data-navigate") === name
      );
    });
  }

  function showScreen(name) {
    if (!name) return false;
    const target = String(name);
    const has = findScreenEl(target);
    if (!has) return false;
    state.screen = target;
    document.querySelectorAll("template[data-screen-template]").forEach((tpl) => {
      if (tpl.getAttribute("data-screen-template") === target && tpl.content) {
        const node = tpl.content.cloneNode(true);
        tpl.replaceWith(node);
      }
    });
    document.querySelectorAll(".screen").forEach((el) => {
      const on = el.getAttribute("data-screen") === target;
      el.classList.toggle("screen--active", on);
      el.classList.toggle("active", on);
    });
    markSidebarActive(target);
    runScreenOnVisible(target);
    pinFormTop();
    bindFormTabScrollSpy();
    if (/Form/i.test(target) || target === "VCR / VCN Form") {
      syncTabForAnchor("Anchor1");
    }
    if (/Home|Admin/i.test(target)) {
      try {
        hydrateLookupGallery();
      } catch (_) {}
    }
    return true;
  }

  function runScreenOnVisible(name) {
    const entry = state.controlIndex[name];
    const fx =
      entry && entry.node && entry.node.properties
        ? entry.node.properties.OnVisible
        : null;
    if (!fx) {
      applyVisibility();
      return;
    }
    try {
      // OnVisible is sync-friendly for UpdateContext/Set used by Admin tabs.
      const parts = splitStatements(stripEq(stripComments(String(fx))));
      parts.forEach((part) => {
        if (!part) return;
        if (/^(Filter|LookUp|Patch|Collect|ClearCollect|SubmitForm|Refresh)\s*\(/i.test(part)) {
          return;
        }
        evalExpr(part);
      });
    } catch (_) {}
    applyVisibility();
  }

  function setVar(name, value) {
    state.globals[name] = value;
  }

  function asBool(value) {
    if (value === true || value === 1 || value === "true") return true;
    if (value === false || value === 0 || value === "false" || value == null) {
      return false;
    }
    if (typeof value === "string" && /^(var|fn|gbl)[A-Z]\w*$/.test(value)) {
      return false;
    }
    return Boolean(value);
  }

  function looksLikeFormula(value) {
    const t = String(value == null ? "" : value);
    return /^(If|Switch|LookUp|Filter|Navigate|Set|User|Coalesce|ThisItem|Parent|Self)\s*[\.(]/i.test(
      t
    ) || /^(fn|var|gbl)[A-Z]\w*$/.test(t) || t.includes("/*") || t.startsWith("//");
  }

  function getVar(name) {
    if (Object.prototype.hasOwnProperty.call(state.context, name)) {
      return state.context[name];
    }
    if (Object.prototype.hasOwnProperty.call(state.globals, name)) {
      return state.globals[name];
    }
    if (Object.prototype.hasOwnProperty.call(state.values, name)) {
      return state.values[name];
    }
    return undefined;
  }

  function rgbaToCss(args) {
    const parts = String(args)
      .split(",")
      .map((p) => p.trim());
    if (parts.length >= 4) {
      return `rgba(${parts[0]},${parts[1]},${parts[2]},${parts[3]})`;
    }
    return "#000";
  }

  function parseNavigateTarget(expr) {
    const m = String(expr).match(
      /^Navigate\s*\(\s*(?:'([^']+)'|"([^"]+)"|([A-Za-z_][\w]*))/i
    );
    if (!m) return null;
    return m[1] || m[2] || m[3] || null;
  }

  function syncTabForAnchor(anchorName) {
    if (!anchorName) return;
    const want = String(anchorName);
    const screen = activeScreenEl() || document;
    const navs = screen.querySelectorAll
      ? screen.querySelectorAll('[data-control="FormNavTabs"]')
      : document.querySelectorAll('[data-control="FormNavTabs"]');
    navs.forEach((nav) => {
      nav.querySelectorAll(".ctrl-tab[data-anchor]").forEach((t) => {
        t.classList.toggle("ctrl-tab--active", t.getAttribute("data-anchor") === want);
      });
    });
  }

  /** Canonical Anchor* → section data-control names (mirrors SectionAnchors.php). */
  const SECTION_ANCHOR_TARGETS = {
    Anchor1: ["1_Destination"],
    Anchor2: ["2_Requesting"],
    Anchor3: ["3_TypeOfVCR"],
    Anchor4: ["4_SecurityClassification"],
    Anchor5: ["5_Summary"],
    Anchor6: ["6_Date", "6_Dates"],
    Anchor7: ["7_Initiative"],
    Anchor8: ["8_Pertinence"],
    Anchor9: ["9_SecurityOfficer"],
    Anchor10: ["10_Remarks"],
    AnchorSites: ["Annex1_Sites", "SitesCont"],
    AnchorVisitors: ["Annex2_Visitors", "VisitorsCont"],
    AnchorEmer: ["EmergencyContact", "EmerScrollableForm"],
  };

  function resolveScrollTarget(name, screen) {
    if (!name) return null;
    const want = String(name);
    const scope = screen && screen.querySelectorAll ? screen : document;

    // Prefer proper top-of-section HTML anchors from the web export.
    try {
      const top = scope.querySelector(
        'a[data-role="section-anchor"][id="' + want.replace(/"/g, '\\"') + '"],' +
          'a.ctrl--section-anchor[id="' + want.replace(/"/g, '\\"') + '"]'
      );
      if (top) return { el: top, name: want };
    } catch (_) {}

    const aliases = Object.assign(
      {
        AnchorSites: ["Annex1_Sites", "SitesHeaderCont", "Agency_Header_Text", "galSites", "AnchorSites"],
        AnchorVisitors: [
          "Annex2_Visitors",
          "VisitorsHeaderCont",
          "Particular_Header_Text",
          "galVisitors",
          "AnchorVisitors",
        ],
      },
      Object.keys(SECTION_ANCHOR_TARGETS).reduce((acc, key) => {
        acc[key] = (SECTION_ANCHOR_TARGETS[key] || []).concat([key]);
        return acc;
      }, {})
    );
    const names = aliases[want] || [want];
    for (const n of names) {
      let el = findByControl(n, screen);
      if (!el && scope.querySelector) {
        try {
          el = scope.querySelector('a[id="' + String(n).replace(/"/g, '\\"') + '"]');
        } catch (_) {}
      }
      if (!el) el = document.getElementById(String(n));
      // Skip mid-pane PA hacks when a section container is available.
      if (el && el.getAttribute && el.getAttribute("data-role") === "pa-anchor-mid") {
        continue;
      }
      if (el) return { el, name: n };
    }
    return null;
  }

  function isFlowLayout() {
    try {
      const layout = (document.documentElement.getAttribute("data-layout") || "").toLowerCase();
      return layout === "responsive" || layout === "wet";
    } catch (_) {
      return false;
    }
  }

  /** Zero-size SetFocus / section anchors — never hydrate Text into them (flex min-height:auto balloons gaps). */
  function isAnchorControlEl(el) {
    if (!el || !el.getAttribute) return false;
    const role = el.getAttribute("data-role") || "";
    if (
      role === "section-anchor" ||
      role === "pa-anchor-mid" ||
      role === "anchor"
    ) {
      return true;
    }
    return (
      el.classList.contains("ctrl--section-anchor") ||
      el.classList.contains("ctrl--pa-mid-anchor") ||
      el.classList.contains("ctrl--anchor")
    );
  }

  function scrollToControl(name) {
    if (!name) return false;
    const screen = activeScreenEl() || document;
    const resolved = resolveScrollTarget(name, screen);
    if (!resolved) return false;
    const el = resolved.el;
    const scroller =
      el.closest('[data-control="ScrollableForm"]') ||
      el.closest('[data-control="EmerScrollableForm"]') ||
      el.closest(".ctrl--scroll") ||
      null;
    const scrollerCanScroll =
      scroller && scroller.scrollHeight > scroller.clientHeight + 2;
    if (scrollerCanScroll) {
      const elTop =
        el.getBoundingClientRect().top -
        scroller.getBoundingClientRect().top +
        scroller.scrollTop;
      // Section-top anchors: pin just under the sticky tab bar.
      const pinned = elTop - 12;
      scroller.scrollTo({ top: Math.max(0, pinned), behavior: "smooth" });
    } else if (isFlowLayout() || !scroller) {
      // Flow / WET: form expands with the page — scroll the document.
      try {
        el.scrollIntoView({ behavior: "smooth", block: "start" });
      } catch (_) {
        const top = el.getBoundingClientRect().top + (window.scrollY || 0) - 72;
        window.scrollTo(0, Math.max(0, top));
      }
    }
    syncTabForAnchor(name);
    return true;
  }

  function activateTab(tabBtn) {
    const parent = tabBtn.parentElement;
    if (parent) {
      parent.querySelectorAll(".ctrl-tab").forEach((t) => {
        t.classList.toggle("ctrl-tab--active", t === tabBtn);
      });
    }
    const anchor = tabBtn.getAttribute("data-anchor");
    // Ignore scroll-spy while smooth scroll settles on the clicked tab.
    state.tabLockUntil = Date.now() + 750;
    if (anchor) scrollToControl(anchor);
    // Avoid GCWeb / hash navigation reloading the converted app.
    try {
      if (location.hash) {
        history.replaceState(null, "", location.pathname + location.search);
      }
    } catch (_) {}
  }

  function bindFormTabScrollSpy() {
    document.querySelectorAll('[data-control="ScrollableForm"]').forEach((scroller) => {
      if (scroller.dataset.psTabSpy === "1") return;
      scroller.dataset.psTabSpy = "1";
      let ticking = false;
      scroller.addEventListener(
        "scroll",
        () => {
          if (ticking) return;
          ticking = true;
          requestAnimationFrame(() => {
            ticking = false;
            if (state.tabLockUntil && Date.now() < state.tabLockUntil) return;
            const screen = activeScreenEl() || document;
            const nav = screen.querySelector
              ? screen.querySelector('[data-control="FormNavTabs"]')
              : document.querySelector('[data-control="FormNavTabs"]');
            if (!nav) return;
            const tabs = [...nav.querySelectorAll(".ctrl-tab[data-anchor]")];
            if (!tabs.length) return;
            const line = scroller.getBoundingClientRect().top + 96;
            let best = null;
            let bestTop = -Infinity;
            tabs.forEach((tab) => {
              const anchor = tab.getAttribute("data-anchor");
              const resolved = resolveScrollTarget(anchor, screen);
              if (!resolved) return;
              const top = resolved.el.getBoundingClientRect().top;
              if (top <= line && top > bestTop) {
                best = tab;
                bestTop = top;
              }
            });
            if (best) syncTabForAnchor(best.getAttribute("data-anchor"));
          });
        },
        { passive: true }
      );
    });
  }

  function unwrapParens(expr) {
    let cur = String(expr == null ? "" : expr).trim();
    while (cur.startsWith("(") && cur.endsWith(")")) {
      let depth = 0;
      let quote = null;
      let wraps = true;
      for (let i = 0; i < cur.length; i++) {
        const ch = cur[i];
        if (quote) {
          if (ch === quote) quote = null;
          continue;
        }
        if (ch === '"' || ch === "'") {
          quote = ch;
          continue;
        }
        if (ch === "(") depth++;
        else if (ch === ")") {
          depth--;
          if (depth === 0 && i !== cur.length - 1) {
            wraps = false;
            break;
          }
        }
      }
      if (!wraps || depth !== 0) break;
      cur = cur.slice(1, -1).trim();
    }
    return cur;
  }

  function office365Profile() {
    const u = getVar("varCurrentUser") || {};
    const prefs = u.preferences && typeof u.preferences === "object" ? u.preferences : {};
    return {
      department: u.department || prefs.department || "",
      companyName: u.companyName || u.organization || prefs.companyName || "",
      officeLocation: u.officeLocation || prefs.officeLocation || "",
      jobTitle: u.jobTitle || "",
      mail: u.email || u.user || "preview@local",
      displayName: u.fullName || "Preview User",
    };
  }

  function evalExpr(expr) {
    expr = unwrapParens(stripEq(stripComments(String(expr == null ? "" : expr))).trim());
    if (expr === "") return "";
    if (/^true$/i.test(expr)) return true;
    if (/^false$/i.test(expr)) return false;
    if (/^Blank\s*\(\s*\)$/i.test(expr)) return null;
    if (/^Today\s*\(\s*\)$/i.test(expr)) {
      return new Date().toISOString().slice(0, 10);
    }
    if (/^Now\s*\(\s*\)$/i.test(expr)) {
      return new Date().toISOString();
    }
    if (/^-?\d+(\.\d+)?$/.test(expr)) return Number(expr);
    if (isQuotedString(expr)) {
      return unquote(expr);
    }

    let m;
    if ((m = expr.match(/^RGBA\s*\((.+)\)\s*$/i))) {
      return rgbaToCss(m[1]);
    }
    const navTarget = parseNavigateTarget(expr);
    if (navTarget) {
      showScreen(navTarget);
      return true;
    }
    if ((m = expr.match(/^Set\s*\(\s*([A-Za-z_][\w]*)\s*,\s*([\s\S]+)\)\s*$/i))) {
      const v = evalExpr(m[2]);
      setVar(m[1], v);
      return v;
    }
    if ((m = expr.match(/^UpdateContext\s*\(\s*\{([\s\S]+)\}\s*\)\s*$/i))) {
      const body = m[1];
      body.split(",").forEach((pair) => {
        const idx = pair.indexOf(":");
        if (idx < 0) return;
        const k = pair.slice(0, idx).trim();
        const v = evalExpr(pair.slice(idx + 1).trim());
        state.context[k] = v;
        // Mirror var* context into globals so Visible formulas resolve either way.
        if (/^(var|fn|gbl)[A-Z]\w*$/.test(k)) {
          state.globals[k] = v;
        }
      });
      return true;
    }
    if ((m = expr.match(/^Select\s*\(\s*([A-Za-z_][\w]*)\s*\)\s*$/i))) {
      const props = controlProps(m[1]);
      if (props.OnSelect) {
        if (typeof props.OnSelect === "function") {
          return props.OnSelect();
        }
        // Fire synchronously for chained OnStart / LoadAppSettings.
        const parts = splitStatements(
          stripEq(stripComments(String(props.OnSelect)))
        );
        let last = null;
        parts.forEach((part) => {
          last = evalExpr(part);
        });
        return last;
      }
      return false;
    }
    if ((m = expr.match(/^SetFocus\s*\(\s*([A-Za-z_][\w]*)\s*\)\s*$/i))) {
      return scrollToControl(m[1]);
    }
    if (/^Select\s*\(\s*Parent\s*\)/i.test(expr)) {
      const scope = state.context.__rowScope;
      const gal =
        scope && scope.closest
          ? scope.closest('[data-role="gallery"], [data-control="RequestsGallery"], [data-control="LookupGallery"]')
          : null;
      const gname = gal && gal.getAttribute("data-control");
      const props = gname ? controlProps(gname) : {};
      if (props.OnSelect) {
        const item = thisItemFromRow(scope) || state.context.ThisItem;
        const prevItem = state.context.ThisItem;
        const prevSelf = state.context.__selfName;
        state.context.ThisItem = item;
        state.context.__selfName = gname;
        try {
          return invokeHandler(props.OnSelect);
        } finally {
          state.context.__selfName = prevSelf;
          if (prevItem === undefined) delete state.context.ThisItem;
          else state.context.ThisItem = prevItem;
        }
      }
      return true;
    }
    if (/^Select\s*\(/i.test(expr)) {
      return true;
    }
    if ((m = expr.match(/^Reset\s*\(\s*([A-Za-z_][\w]*)\s*\)\s*$/i))) {
      resetControl(m[1]);
      return true;
    }
    if ((m = expr.match(/^If\s*\(([\s\S]+)\)$/i))) {
      const args = splitArgs(m[1]);
      if (args.length >= 3) {
        return asBool(evalExpr(args[0])) ? evalExpr(args[1]) : evalExpr(args[2]);
      }
      if (args.length === 2) {
        return asBool(evalExpr(args[0])) ? evalExpr(args[1]) : false;
      }
    }
    if ((m = expr.match(/^Switch\s*\(([\s\S]+)\)$/i))) {
      const args = splitArgs(m[1]);
      if (args.length < 2) return null;
      const needle = String(evalExpr(args[0]) ?? "");
      for (let i = 1; i + 1 < args.length; i += 2) {
        if (String(evalExpr(args[i]) ?? "") === needle) return evalExpr(args[i + 1]);
      }
      if ((args.length - 1) % 2 === 1) return evalExpr(args[args.length - 1]);
      return null;
    }
    if ((m = expr.match(/^And\s*\(([\s\S]+)\)$/i))) {
      return splitArgs(m[1]).every((a) => asBool(evalExpr(a)));
    }
    if ((m = expr.match(/^Or\s*\(([\s\S]+)\)$/i))) {
      return splitArgs(m[1]).some((a) => asBool(evalExpr(a)));
    }
    if ((m = expr.match(/^Not\s*\(([\s\S]+)\)\s*$/i))) {
      return !asBool(evalExpr(m[1]));
    }
    if ((m = expr.match(/^!([A-Za-z_][\w]*)$/))) {
      return !asBool(evalExpr(m[1]));
    }
    if ((m = expr.match(/^IsBlank\s*\(([\s\S]+)\)\s*$/i))) {
      const v = evalExpr(m[1]);
      return v == null || v === "";
    }
    if ((m = expr.match(/^IsEmpty\s*\(([\s\S]+)\)\s*$/i))) {
      const v = evalExpr(m[1]);
      if (Array.isArray(v)) return v.length === 0;
      if (v == null || v === "") return true;
      return false;
    }
    if ((m = expr.match(/^CountRows\s*\(([\s\S]+)\)\s*$/i))) {
      const v = evalExpr(m[1]);
      return Array.isArray(v) ? v.length : 0;
    }
    if ((m = expr.match(/^Concat\s*\(([\s\S]+)\)\s*$/i))) {
      return evalConcat(m[1]);
    }
    if ((m = expr.match(/^Substitute\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      const src = String(evalExpr(args[0]) ?? "");
      const find = String(evalExpr(args[1]) ?? "");
      const repl = args[2] != null ? String(evalExpr(args[2]) ?? "") : "";
      if (!find) return src;
      return src.split(find).join(repl);
    }
    if ((m = expr.match(/^Trim\s*\(([\s\S]+)\)\s*$/i))) {
      return String(evalExpr(m[1]) ?? "").trim();
    }
    if ((m = expr.match(/^Lower\s*\(([\s\S]+)\)\s*$/i))) {
      return String(evalExpr(m[1]) ?? "").toLowerCase();
    }
    if ((m = expr.match(/^Text\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      const v = evalExpr(args[0]);
      const fmt = args[1] != null ? String(evalExpr(args[1]) ?? "").replace(/^"|"$/g, "") : "";
      if (fmt && /^0+$/.test(fmt)) {
        return String(Math.trunc(Number(v) || 0)).padStart(fmt.length, "0");
      }
      return v == null ? "" : String(v);
    }
    if ((m = expr.match(/^Defaults\s*\(/i))) {
      return {};
    }
    if ((m = expr.match(/^Split\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      const text = String(evalExpr(args[0]) ?? "");
      const sep = args[1] != null ? String(evalExpr(args[1]) ?? "") : ",";
      return text.split(sep).map((Value) => ({ Value }));
    }
    if ((m = expr.match(/^Char\s*\(([\s\S]+)\)\s*$/i))) {
      const n = Number(evalExpr(m[1]));
      return Number.isFinite(n) ? String.fromCharCode(n) : "";
    }
    if ((m = expr.match(/^ParseJSON\s*\(([\s\S]+)\)\s*$/i))) {
      const raw = evalExpr(m[1]);
      if (raw == null || raw === "") return null;
      try {
        return JSON.parse(String(raw));
      } catch (_) {
        return null;
      }
    }
    if ((m = expr.match(/^Table\s*\(([\s\S]+)\)\s*$/i))) {
      const v = evalExpr(m[1]);
      if (Array.isArray(v)) {
        return v.map((item) =>
          item && typeof item === "object" && Object.prototype.hasOwnProperty.call(item, "Value")
            ? item
            : { Value: item }
        );
      }
      return v == null ? [] : [{ Value: v }];
    }
    if ((m = expr.match(/^Concatenate\s*\(([\s\S]+)\)\s*$/i))) {
      return splitArgs(m[1]).map((a) => String(evalExpr(a) ?? "")).join("");
    }
    if ((m = expr.match(/^Boolean\s*\(([\s\S]+)\)\s*$/i))) {
      return asBool(evalExpr(m[1]));
    }
    if ((m = expr.match(/^ThisRecord(?:\.(.+))?$/i))) {
      const rec = state.context.ThisRecord;
      if (!m[1]) return rec;
      let cur = rec;
      const parts = String(m[1]).split(".");
      for (let i = 0; i < parts.length; i++) {
        if (cur == null || typeof cur !== "object") return undefined;
        const picked = pickField(cur, parts[i]);
        cur = picked !== undefined ? picked : cur[parts[i]];
      }
      return cur;
    }
    if ((m = expr.match(/^Find\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      const needle = String(evalExpr(args[0]) ?? "");
      const hay = String(evalExpr(args[1]) ?? "");
      const idx = hay.indexOf(needle);
      return idx < 0 ? 0 : idx + 1; // Power Apps is 1-based; 0 = not found
    }
    if ((m = expr.match(/^Coalesce\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      for (let i = 0; i < args.length; i++) {
        const v = evalExpr(args[i]);
        if (v != null && v !== "") return v;
      }
      return null;
    }
    if ((m = expr.match(/^ForAll\s*\(([\s\S]+)\)\s*$/i))) {
      return evalForAll(m[1]);
    }
    if ((m = expr.match(/^IfError\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      try {
        let result = null;
        splitStatements(args[0] || "").forEach((part) => {
          if (!part) return;
          result = evalExpr(part);
        });
        return result;
      } catch (_) {
        return args[1] != null ? evalExpr(args[1]) : null;
      }
    }
    if ((m = expr.match(/^(?:Office365Outlook\.)?SendEmailV2\s*\(([\s\S]*)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      const to = args[0] != null ? evalExpr(args[0]) : "";
      const subject = args[1] != null ? evalExpr(args[1]) : "";
      const body = args[2] != null ? evalExpr(args[2]) : "";
      invokeSendEmail(to, subject, body);
      return true;
    }
    if ((m = expr.match(/^fnEmailLocal\s*\(([\s\S]+)\)\s*$/i))) {
      return fnEmailLocal(evalExpr(m[1]));
    }
    if ((m = expr.match(/^fnEmailBoth\s*\(([\s\S]+)\)\s*$/i))) {
      return fnEmailBoth(evalExpr(m[1]));
    }
    if ((m = expr.match(/^fnEmailList\s*\(([\s\S]+)\)\s*$/i))) {
      return fnEmailList(evalExpr(m[1]));
    }
    if ((m = expr.match(/^fnEmailTokenOld\s*\(([\s\S]+)\)\s*$/i))) {
      return fnEmailTokenOld(evalExpr(m[1]));
    }
    if ((m = expr.match(/^fnEmailToken\s*\(([\s\S]+)\)\s*$/i))) {
      return fnEmailToken(evalExpr(m[1]));
    }
    if ((m = expr.match(/^fnApplyEmailTokens\s*\(([\s\S]+)\)\s*$/i))) {
      return fnApplyEmailTokens(evalExpr(m[1]));
    }
    if ((m = expr.match(/^EncodeUrl\s*\(([\s\S]+)\)\s*$/i))) {
      return encodeURIComponent(String(evalExpr(m[1]) ?? ""));
    }
    if ((m = expr.match(/^First\s*\(([\s\S]+)\)\s*$/i))) {
      const v = evalExpr(m[1]);
      if (Array.isArray(v)) return v.length ? v[0] : null;
      return v == null ? null : v;
    }
    if ((m = expr.match(/^StartsWith\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      return String(evalExpr(args[0]) ?? "").startsWith(String(evalExpr(args[1]) ?? ""));
    }
    if ((m = expr.match(/^EndsWith\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      return String(evalExpr(args[0]) ?? "").endsWith(String(evalExpr(args[1]) ?? ""));
    }
    if ((m = expr.match(/^Left\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      const s = String(evalExpr(args[0]) ?? "");
      const n = Number(evalExpr(args[1]));
      return s.slice(0, Number.isFinite(n) ? n : 0);
    }
    if ((m = expr.match(/^Right\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      const s = String(evalExpr(args[0]) ?? "");
      const n = Number(evalExpr(args[1]));
      return s.slice(Number.isFinite(n) ? -n : s.length);
    }
    if ((m = expr.match(/^Year\s*\(([\s\S]+)\)\s*$/i))) {
      const d = toDate(evalExpr(m[1]));
      return d ? d.getFullYear() : 0;
    }
    if ((m = expr.match(/^Month\s*\(([\s\S]+)\)\s*$/i))) {
      const d = toDate(evalExpr(m[1]));
      return d ? d.getMonth() + 1 : 0;
    }
    if ((m = expr.match(/^Mod\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      const a = Number(evalExpr(args[0]));
      const b = Number(evalExpr(args[1]));
      return b ? a % b : 0;
    }
    if ((m = expr.match(/^Value\s*\(([\s\S]+)\)\s*$/i))) {
      const n = Number(evalExpr(m[1]));
      return Number.isFinite(n) ? n : 0;
    }
    if ((m = expr.match(/^Int\s*\(([\s\S]+)\)\s*$/i))) {
      const n = Number(evalExpr(m[1]));
      return Number.isFinite(n) ? Math.trunc(n) : 0;
    }
    if ((m = expr.match(/^DateValue\s*\(([\s\S]+)\)\s*$/i))) {
      return toDate(evalExpr(m[1]));
    }
    if ((m = expr.match(/^Max\s*\(([\s\S]+)\)\s*$/i))) {
      return evalMax(m[1]);
    }
    // Component instance methods (topSubmit_1.sendAcknowledgmentMail(...)).
    if (
      (m = expr.match(
        /^([A-Za-z_][\w]*)\.([A-Za-z_][\w]*)\s*\(([\s\S]*)\)\s*$/
      ))
    ) {
      const host = m[1];
      const method = m[2];
      const fx = findComponentFormula(host, method);
      if (fx) {
        bindComponentArgs(method, m[3]);
        runFormula(fx);
        return true;
      }
      if (
        /^(topSubmit|comExternalFunctions|ExternalFunctions)/i.test(host) ||
        state.controlIndex[host]
      ) {
        return true;
      }
    }
    if ((m = expr.match(/^ClearCollect\s*\(\s*([A-Za-z_][\w]*)\s*(?:,\s*([\s\S]+))?\s*\)\s*$/i))) {
      const rows = m[2] ? parseCollectRows(m[2]) : [];
      setVar(m[1], rows);
      if (!ir.collections || typeof ir.collections !== "object") ir.collections = {};
      ir.collections[m[1]] = rows;
      refreshCollectionBoundDropdowns(m[1]);
      return rows;
    }
    if ((m = expr.match(/^Collect\s*\(\s*([A-Za-z_][\w]*)\s*,\s*([\s\S]+)\s*\)\s*$/i))) {
      const existing = getVar(m[1]);
      const list = Array.isArray(existing) ? existing.slice() : [];
      const add = parseCollectRows(m[2]);
      add.forEach((r) => list.push(r));
      setVar(m[1], list);
      return list;
    }
    if ((m = expr.match(/^Patch\s*\(\s*([A-Za-z_][\w]*)\s*,\s*([\s\S]+)\s*\)\s*$/i))) {
      return patchInMemory(m[1], m[2]);
    }
    if ((m = expr.match(/^Filter\s*\(([\s\S]+)\)\s*$/i))) {
      return evalFilter(m[1]);
    }
    if ((m = expr.match(/^SortByColumns\s*\(([\s\S]+)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      const rows = evalExpr(args[0]);
      if (!Array.isArray(rows)) return [];
      const col = args[1] != null ? String(evalExpr(args[1]) ?? "").replace(/^["']|["']$/g, "") : "";
      const desc = /Descending/i.test(String(args[2] || ""));
      return rows.slice().sort((a, b) => {
        const av = a && col ? a[col] : "";
        const bv = b && col ? b[col] : "";
        if (av == bv) return 0;
        const cmp = av > bv ? 1 : -1;
        return desc ? -cmp : cmp;
      });
    }
    if ((m = expr.match(/^Clear\s*\(\s*([A-Za-z_][\w]*)\s*\)\s*$/i))) {
      setVar(m[1], []);
      return [];
    }
    if (/^(ResetForm|SubmitForm)\s*\(/i.test(expr)) {
      return true;
    }
    if ((m = expr.match(/^Refresh\s*\(\s*'([^']+)'\s*\)\s*$/i))) {
      hydrateTable(m[1]);
      return true;
    }
    if ((m = expr.match(/^Refresh\s*\(\s*([A-Za-z_][\w]*)\s*\)\s*$/i))) {
      hydrateTable(m[1]);
      return true;
    }
    if ((m = expr.match(/^JSON\s*\(([\s\S]+)\)\s*$/i))) {
      try {
        const args = splitArgs(m[1]);
        return JSON.stringify(evalExpr(args[0]));
      } catch (_) {
        return "[]";
      }
    }
    if ((m = expr.match(/^Notify\s*\(\s*([\s\S]+)\s*\)\s*$/i))) {
      const args = splitArgs(m[1]);
      const msg = args[0] != null ? String(evalExpr(args[0]) ?? args[0]) : "";
      const kind = args[1] != null ? String(args[1]) : "";
      showNotify(msg.replace(/^"|"$/g, ""), kind);
      return true;
    }
    // LookUp(collectionOrTable, predicate).field — handled via trailing path below when dotted.
    if ((m = expr.match(/^LookUp\s*\(([\s\S]+)\)\s*(?:\.(.+))?$/i))) {
      let row = evalLookUp(m[1]);
      if (m[2]) {
        const parts = m[2].split(".");
        for (const p of parts) {
          if (row == null || typeof row !== "object") {
            row = undefined;
            break;
          }
          row = pickField(row, p);
        }
      }
      return row;
    }

    // Boolean && / || before unary ! so `!A && B` → (!A) && B
    if (expr.includes("&&") || expr.includes("||")) {
      const boolParts = splitTopLevelBool(expr);
      if (boolParts.length > 1) {
        let acc = asBool(evalExpr(boolParts[0].expr));
        for (let i = 1; i < boolParts.length; i++) {
          const next = asBool(evalExpr(boolParts[i].expr));
          if (boolParts[i].op === "&&") acc = acc && next;
          else acc = acc || next;
        }
        return acc;
      }
    }

    // Unary ! for function calls / dotted paths (!IsBlank(...), !varFlag)
    if (expr.startsWith("!") && expr.length > 1) {
      return !asBool(evalExpr(expr.slice(1).trim()));
    }

    // "needle in haystack" — substring for text, membership for tables.
    if ((m = expr.match(/^(.+?)\s+in\s+(.+)$/i))) {
      if (topLevelWord(expr, "in")) {
        const needle = evalExpr(m[1]);
        const hay = evalExpr(m[2]);
        if (Array.isArray(hay)) {
          return hay.some((row) => {
            if (row == null) return false;
            if (typeof row !== "object") return String(row) === String(needle);
            return Object.keys(row).some((k) => String(row[k]) === String(needle));
          });
        }
        return String(hay ?? "").indexOf(String(needle ?? "")) >= 0;
      }
    }

    // Numeric + / - (FY label, next request number).
    if ((m = expr.match(/^([\s\S]+?)\s*\+\s*([\s\S]+)$/))) {
      if (topLevelCompare(expr, "+")) {
        const a = evalExpr(m[1]);
        const b = evalExpr(m[2]);
        if (typeof a === "number" || typeof b === "number") {
          return (Number(a) || 0) + (Number(b) || 0);
        }
        return String(a ?? "") + String(b ?? "");
      }
    }
    if ((m = expr.match(/^([\s\S]+?)\s*-\s*([\s\S]+)$/))) {
      if (topLevelCompare(expr, "-")) {
        return (Number(evalExpr(m[1])) || 0) - (Number(evalExpr(m[2])) || 0);
      }
    }

    // Comparisons — multi-char ops before bare "=".
    if ((m = expr.match(/^(.+?)\s*<>\s*(.+)$/))) {
      if (topLevelCompare(expr, "<>")) {
        return String(evalExpr(m[1])) !== String(evalExpr(m[2]));
      }
    }
    if ((m = expr.match(/^(.+?)\s*<=\s*(.+)$/))) {
      if (topLevelCompare(expr, "<=")) {
        return Number(evalExpr(m[1])) <= Number(evalExpr(m[2]));
      }
    }
    if ((m = expr.match(/^(.+?)\s*>=\s*(.+)$/))) {
      if (topLevelCompare(expr, ">=")) {
        return Number(evalExpr(m[1])) >= Number(evalExpr(m[2]));
      }
    }
    if ((m = expr.match(/^(.+?)\s*<\s*(.+)$/))) {
      if (topLevelCompare(expr, "<")) {
        return Number(evalExpr(m[1])) < Number(evalExpr(m[2]));
      }
    }
    if ((m = expr.match(/^(.+?)\s*>\s*(.+)$/))) {
      if (topLevelCompare(expr, ">")) {
        return Number(evalExpr(m[1])) > Number(evalExpr(m[2]));
      }
    }
    if ((m = expr.match(/^(.+?)\s*=\s*(.+)$/)) && !/^If\s*\(/i.test(expr)) {
      // Prefer deepest comparison only when top-level (no open paren imbalance for function calls)
      if (topLevelCompare(expr, "=")) {
        return fxEquals(evalExpr(m[1]), evalExpr(m[2]));
      }
    }

    // Concatenation &
    if (expr.includes("&")) {
      const parts = splitTopLevel(expr, "&");
      if (parts.length > 1) {
        return parts.map((p) => String(evalExpr(p.trim()) ?? "")).join("");
      }
    }

    // User()
    if (/^User\s*\(\s*\)\s*\.Email$/i.test(expr)) {
      return office365Profile().mail;
    }
    if (/^User\s*\(\s*\)\s*\.FullName$/i.test(expr)) {
      return office365Profile().displayName;
    }
    if (/^User\s*\(\s*\)\s*\.Image$/i.test(expr)) {
      return "";
    }
    if (/^User\s*\(\s*\)$/i.test(expr)) {
      const p = office365Profile();
      return { Email: p.mail, FullName: p.displayName, Image: "" };
    }
    if (/^Office365Users\.MyProfileV2\s*\(/i.test(expr)) {
      return office365Profile();
    }

    // With({...}, body) — evaluate body with locals in context
    if ((m = expr.match(/^With\s*\(([\s\S]+)\)$/i))) {
      const args = splitArgs(m[1]);
      if (args.length >= 2) {
        const record = parseRecordLiteral(args[0]);
        const body = args.slice(1).join(",");
        const saved = Object.assign(Object.create(null), state.context);
        Object.keys(record).forEach((k) => {
          state.context[k] = record[k];
          state.globals[k] = record[k];
        });
        let result = null;
        try {
          splitStatements(body).forEach((part) => {
            result = evalExpr(part);
          });
        } finally {
          state.context = saved;
        }
        return result;
      }
    }

    // Record literals: { theme: Coalesce(...), ... } used by loadAppUsers.
    if (expr.startsWith("{") && expr.endsWith("}")) {
      return parseRecordLiteral(expr);
    }

    // ident.'Quoted Field' — App Settings 'Default Approver' / 'L1 Data'
    if ((m = expr.match(/^([A-Za-z_][\w]*)\.'([^']+)'$/))) {
      const rec = getVar(m[1]);
      return rec && typeof rec === "object" ? pickField(rec, m[2]) : undefined;
    }

    // Dotted paths: Control.Selected.Value, 'Destination'.Selected.Value, Parent.DisplayMode
    if (
      (m = expr.match(
        /^(?:'([^']+)'|"([^"]+)"|([A-Za-z_][\w]*))((?:\.[A-Za-z_][\w]*)+)$/
      ))
    ) {
      const rootName = m[1] || m[2] || m[3];
      const parts = m[4].slice(1).split(".");
      return resolvePath(rootName, parts);
    }

    // Bare identifier
    if (/^[A-Za-z_][\w]*$/.test(expr)) {
      if (expr === "DisplayMode" || /^DisplayMode\./.test(expr)) return expr;
      const named = namedFormula(expr);
      if (named !== undefined) return named;
      const v = getVar(expr);
      if (v !== undefined) return v;
      if (state.controlIndex[expr]) return expr;
      // Unevaluated App.Formulas must be blank, not the identifier string
      // (otherwise IsBlank(fnRequestTitle) is false and submit reuses "fnRequestTitle").
      if (/^fn[A-Z]/.test(expr)) return null;
      return expr;
    }

    // Chained statements with ;
    if (expr.includes(";")) {
      let last = null;
      splitStatements(expr).forEach((part) => {
        last = evalExpr(part);
      });
      return last;
    }

    return expr;
  }

  function splitTopLevel(expr, sep) {
    const out = [];
    let cur = "";
    let depth = 0;
    let quote = null;
    for (let i = 0; i < expr.length; i++) {
      const ch = expr[i];
      if (quote) {
        cur += ch;
        if (ch === quote) quote = null;
        continue;
      }
      if (ch === '"' || ch === "'") {
        quote = ch;
        cur += ch;
        continue;
      }
      if (ch === "(" || ch === "{") depth++;
      else if (ch === ")" || ch === "}") depth--;
      if (depth === 0 && ch === sep) {
        out.push(cur);
        cur = "";
        continue;
      }
      cur += ch;
    }
    if (cur !== "" || out.length) out.push(cur);
    return out;
  }

  /** Split on top-level && / || while respecting quotes and grouping. */
  function splitTopLevelBool(expr) {
    const parts = [];
    let cur = "";
    let depth = 0;
    let quote = null;
    let pendingOp = null;
    for (let i = 0; i < expr.length; i++) {
      const ch = expr[i];
      const next = expr[i + 1];
      if (quote) {
        cur += ch;
        if (ch === quote) quote = null;
        continue;
      }
      if (ch === '"' || ch === "'") {
        quote = ch;
        cur += ch;
        continue;
      }
      if (ch === "(" || ch === "{") depth++;
      else if (ch === ")" || ch === "}") depth--;
      if (depth === 0 && ((ch === "&" && next === "&") || (ch === "|" && next === "|"))) {
        parts.push({ expr: cur.trim(), op: pendingOp });
        pendingOp = ch + next;
        cur = "";
        i++; // skip second char
        continue;
      }
      cur += ch;
    }
    if (cur.trim() || parts.length) {
      parts.push({ expr: cur.trim(), op: pendingOp });
    }
    return parts;
  }

  function recordPairColon(pair) {
    let quote = null;
    let depth = 0;
    const s = String(pair || "");
    for (let i = 0; i < s.length; i++) {
      const ch = s[i];
      if (quote) {
        if (ch === quote) quote = null;
        continue;
      }
      if (ch === '"' || ch === "'") {
        quote = ch;
        continue;
      }
      if (ch === "{" || ch === "(") depth++;
      else if (ch === "}" || ch === ")") depth--;
      else if (ch === ":" && depth === 0) return i;
    }
    return -1;
  }

  function parseRecordLiteral(raw) {
    const out = Object.create(null);
    let body = String(raw || "").trim();
    if (body.startsWith("{") && body.endsWith("}")) {
      body = body.slice(1, -1);
    }
    splitArgs(body).forEach((pair) => {
      const idx = recordPairColon(pair);
      if (idx < 0) return;
      const k = pair.slice(0, idx).trim().replace(/^'|'$/g, "").replace(/^"|"$/g, "");
      try {
        out[k] = evalExpr(pair.slice(idx + 1).trim());
      } catch (_) {
        out[k] = null;
      }
    });
    return out;
  }

  function mergePatchChangeRecords(args) {
    const fields = {};
    let firstRec = -1;
    for (let i = 0; i < args.length; i++) {
      if (/^\s*\{/.test(args[i])) {
        if (firstRec < 0) firstRec = i;
        Object.assign(fields, parseRecordLiteral(args[i]));
      }
    }
    return { fields, firstRec };
  }

  function fxEquals(a, b) {
    if (a === b) return true;
    const blankA = a == null || a === "";
    const blankB = b == null || b === "";
    if (blankA && blankB) return true;
    if (blankA && (b === 0 || b === "0" || b === false)) return true;
    if (blankB && (a === 0 || a === "0" || a === false)) return true;
    if ((typeof a === "number" || typeof b === "number") && a !== "" && b !== "") {
      const na = Number(a);
      const nb = Number(b);
      if (Number.isFinite(na) && Number.isFinite(nb)) return na === nb;
    }
    return String(a) === String(b);
  }

  function topLevelCompare(expr, op) {
    let depth = 0;
    let quote = null;
    const token = op;
    for (let i = 0; i < expr.length; i++) {
      const ch = expr[i];
      if (quote) {
        if (ch === quote) quote = null;
        continue;
      }
      if (ch === '"' || ch === "'") {
        quote = ch;
        continue;
      }
      if (ch === "(") depth++;
      else if (ch === ")") depth--;
      else if (depth === 0 && expr.slice(i, i + token.length) === token) {
        // Avoid matching "=" inside "<=", ">=", "<>".
        if (token === "=") {
          const prev = i > 0 ? expr[i - 1] : "";
          const next = expr[i + 1] || "";
          if (prev === "<" || prev === ">" || next === "=") continue;
        }
        if (token === "<" || token === ">") {
          const next = expr[i + 1] || "";
          if (next === "=" || (token === "<" && next === ">")) continue;
        }
        return true;
      }
    }
    return false;
  }

  function topLevelWord(expr, word) {
    const token = " " + word + " ";
    let depth = 0;
    let quote = null;
    const hay = " " + expr + " ";
    for (let i = 0; i < hay.length; i++) {
      const ch = hay[i];
      if (quote) {
        if (ch === quote) quote = null;
        continue;
      }
      if (ch === '"' || ch === "'") {
        quote = ch;
        continue;
      }
      if (ch === "(" || ch === "{") depth++;
      else if (ch === ")" || ch === "}") depth--;
      else if (depth === 0 && hay.slice(i, i + token.length).toLowerCase() === token) {
        return true;
      }
    }
    return false;
  }

  function resolvePath(rootName, parts) {
    if (rootName === "Parent") {
      // Web preview: treat forms as editable; Required comes from the parent data card / form.
      if (parts[0] === "DisplayMode") return "DisplayMode.Edit";
      if (parts[0] === "Required") {
        const selfName = state.context.__selfName;
        const entry = selfName ? state.controlIndex[selfName] : null;
        const parentName =
          (entry && entry.parent) ||
          (entry && entry.node && entry.node.parent) ||
          null;
        if (parentName && state.controlIndex[parentName]) {
          const pref = state.controlIndex[parentName].node.properties || {};
          if (pref.Required != null) return asBool(evalExpr(pref.Required));
        }
        // Walk DOM for nearest data-card / form parent with Required in IR.
        const el = selfName ? findByControl(selfName) : null;
        let node = el ? el.parentElement : null;
        while (node) {
          const cn = node.getAttribute && node.getAttribute("data-control");
          if (cn && state.controlIndex[cn]) {
            const pref = state.controlIndex[cn].node.properties || {};
            if (pref.Required != null) return asBool(evalExpr(pref.Required));
          }
          node = node.parentElement;
        }
        return false;
      }
      return undefined;
    }
    if (rootName === "DisplayMode") {
      return "DisplayMode." + parts.join(".");
    }
    if (rootName === "Color") {
      return "Color." + parts.join(".");
    }
    if (rootName === "Self") {
      const selfName = state.context.__selfName;
      if (selfName) {
        return resolvePath(selfName, parts);
      }
      return undefined;
    }
    if (rootName === "App" && parts[0] === "ActiveScreen") {
      if (parts[1] === "Name" || parts.length === 1) {
        return parts[1] === "Name" ? state.screen : { Name: state.screen };
      }
    }
    if (rootName === "ThisItem") {
      const item = state.context.ThisItem;
      if (item && typeof item === "object") {
        let cur = item;
        for (let i = 0; i < parts.length; i++) {
          if (cur == null || typeof cur !== "object") return undefined;
          const picked = pickField(cur, parts[i]);
          cur = picked !== undefined ? picked : cur[parts[i]];
        }
        return cur;
      }
    }

    const dotted = [rootName].concat(parts).join(".");
    const keyed = getVar(dotted);
    if (keyed !== undefined) return keyed;

    let cur = getVar(rootName);
    if (cur === undefined) {
      cur = namedFormula(rootName);
    }
    if (cur && typeof cur === "object" && !Array.isArray(cur)) {
      for (const p of parts) {
        if (cur == null || typeof cur !== "object") return undefined;
        const picked = pickField(cur, p);
        cur = picked !== undefined ? picked : cur[p];
      }
      return cur;
    }

    if (parts[0] === "AllItems") {
      const props = controlProps(rootName);
      if (props && props.Items) {
        try {
          const rows = evalExpr(props.Items);
          if (Array.isArray(rows)) return rows;
        } catch (_) {}
      }
      return [];
    }

    const el = findByControl(rootName);
    if (!el) {
      const ctrl = state.controlIndex[rootName];
      if (ctrl && ctrl.node.properties) {
        let props = ctrl.node.properties;
        if (parts.length === 1 && props[parts[0]] != null) {
          return evalExpr(props[parts[0]]);
        }
      }
      return undefined;
    }

    if (parts[0] === "SelectedDate") {
      if (el.tagName === "INPUT" || el.tagName === "SELECT") return el.value || null;
      const nested = el.querySelector("input, select");
      return nested ? nested.value || null : state.values[rootName + ".SelectedDate"] || null;
    }
    if (parts[0] === "Selected" && (parts[1] === "Value" || parts[1] === "Name" || parts[1] === "ID" || parts[1] === "Acronym")) {
      const field = parts[1];
      const bound = state.values[rootName + ".Selected." + field];
      if (bound !== undefined && bound !== null && bound !== "") return bound;
      const rec = state.values[rootName + ".Selected"];
      if (rec && typeof rec === "object" && rec[field] != null && rec[field] !== "") {
        return rec[field];
      }
      if (el.tagName === "SELECT") {
        if (field === "ID") {
          const opt = el.selectedOptions && el.selectedOptions[0];
          const id = opt ? opt.getAttribute("data-id") : null;
          if (id != null && id !== "") {
            const n = Number(id);
            return Number.isFinite(n) ? n : id;
          }
        }
        return el.value;
      }
      if (el.getAttribute("data-role") === "gallery") {
        return (
          state.values[rootName + ".Selected.Value"] ||
          el.getAttribute("data-selected") ||
          ""
        );
      }
      const checked = el.querySelector('input[type="radio"]:checked');
      if (checked) return checked.value;
      const sel = el.querySelector("select");
      if (sel) {
        if (field === "ID") {
          const opt = sel.selectedOptions && sel.selectedOptions[0];
          const id = opt ? opt.getAttribute("data-id") : null;
          if (id != null && id !== "") {
            const n = Number(id);
            return Number.isFinite(n) ? n : id;
          }
        }
        return sel.value;
      }
      return state.values[rootName + ".Selected.Value"] || "";
    }
    if (parts[0] === "Value") {
      const keyed = state.values[rootName + ".Value"];
      if (typeof keyed === "boolean") return keyed;
      const input = el.matches('input[type="checkbox"]')
        ? el
        : el.querySelector('input[type="checkbox"]');
      if (input) return !!input.checked;
      if (el.tagName === "INPUT" || el.tagName === "SELECT") return el.value;
      const nested = el.querySelector("input, select");
      return nested ? nested.value : undefined;
    }
    if (parts[0] === "HtmlText") {
      const body = el.querySelector(".ctrl-rte-body");
      return body ? body.innerHTML : el.innerHTML || "";
    }
    if (parts[0] === "Text") {
      if (el.getAttribute("data-role") === "richtext") {
        const body = el.querySelector(".ctrl-rte-body");
        return body ? body.innerHTML : el.textContent;
      }
      return el.tagName === "INPUT" || el.tagName === "SELECT"
        ? el.value
        : el.textContent;
    }
    // Formula properties (BorderColor, etc.) — used by validation thickness If(Self.BorderColor = …).
    if (parts.length === 1) {
      const pref =
        state.controlIndex[rootName] &&
        state.controlIndex[rootName].node &&
        state.controlIndex[rootName].node.properties
          ? state.controlIndex[rootName].node.properties
          : null;
      if (pref && pref[parts[0]] != null && parts[0] !== "Items") {
        const prevSelf = state.context.__selfName;
        state.context.__selfName = rootName;
        try {
          return evalExpr(pref[parts[0]]);
        } finally {
          state.context.__selfName = prevSelf;
        }
      }
    }
    if (parts[0] === "AllItemsCount") {
      if (rootName === "UserGallery") {
        const rows = getVar("varTempUser");
        if (Array.isArray(rows)) {
          const pick = getVar("varUserSearchPick");
          const mail = pick && pick.Mail != null ? String(pick.Mail).trim() : "";
          if (mail) {
            const lower = mail.toLowerCase();
            return rows.filter(
              (r) => String((r && (r.user || r.User)) || "").toLowerCase() === lower
            ).length;
          }
          return rows.length;
        }
      }
      if (rootName === "L1ApproversGallery" || rootName === "AppSettingsGallery") {
        const src =
          rootName === "L1ApproversGallery"
            ? getVar("varTempL1") || getVar("varL1Approvers")
            : null;
        if (Array.isArray(src)) return src.length;
      }
      return el.querySelectorAll(".ctrl-gallery-row, [data-gallery-item]").length;
    }
    if (parts[0] === "Selected" && !parts[1]) {
      if (el.getAttribute("data-combo") === "user-search" || rootName === "UserSearchCombo" || rootName === "ReviewerComboboxInput") {
        const mail = el.value || state.values[rootName + ".Selected.Mail"] || "";
        return mail ? { Mail: mail, DisplayName: mail } : null;
      }
      return state.values[rootName + ".Selected"];
    }
    if (parts[0] === "SelectedItems") {
      if (el.getAttribute("data-combo") === "user-search" || rootName === "ReviewerComboboxInput" || rootName === "UserSearchCombo") {
        const mail = el.value || state.values[rootName + ".Selected.Mail"] || "";
        return mail ? [{ Mail: mail, DisplayName: mail }] : [];
      }
      const cached = state.values[rootName + ".SelectedItems"];
      return Array.isArray(cached) ? cached : [];
    }
    if (parts[0] === "SearchText") {
      if (el.tagName === "INPUT") return el.value;
      return state.values[rootName + ".SearchText"] || "";
    }
    return undefined;
  }

  function splitArgs(s) {
    const out = [];
    let cur = "";
    let depth = 0;
    let quote = null;
    for (let i = 0; i < s.length; i++) {
      const ch = s[i];
      if (quote) {
        cur += ch;
        if (ch === quote) quote = null;
        continue;
      }
      if (ch === '"' || ch === "'") {
        quote = ch;
        cur += ch;
        continue;
      }
      if (ch === "(" || ch === "{") {
        depth++;
        cur += ch;
        continue;
      }
      if (ch === ")" || ch === "}") {
        depth--;
        cur += ch;
        continue;
      }
      if (ch === "," && depth === 0) {
        out.push(cur.trim());
        cur = "";
        continue;
      }
      cur += ch;
    }
    if (cur.trim()) out.push(cur.trim());
    return out;
  }

  function splitStatements(s) {
    const out = [];
    let cur = "";
    let depth = 0;
    let quote = null;
    for (let i = 0; i < s.length; i++) {
      const ch = s[i];
      if (quote) {
        cur += ch;
        if (ch === quote) quote = null;
        continue;
      }
      if (ch === '"' || ch === "'") {
        quote = ch;
        cur += ch;
        continue;
      }
      if (ch === "(" || ch === "{") {
        depth++;
        cur += ch;
        continue;
      }
      if (ch === ")" || ch === "}") {
        depth--;
        cur += ch;
        continue;
      }
      if (ch === ";" && depth === 0) {
        if (cur.trim()) out.push(cur.trim());
        cur = "";
        continue;
      }
      cur += ch;
    }
    if (cur.trim()) out.push(cur.trim());
    return out;
  }

  async function evalDataExpr(expr) {
    expr = stripEq(stripComments(expr));
    let m;
    if ((m = expr.match(/^Set\s*\(\s*([A-Za-z_][\w]*)\s*,\s*([\s\S]+)\)\s*$/i))) {
      const v = await evalDataExpr(m[2]);
      setVar(m[1], v);
      return v;
    }
    // In-memory collections (varTempMessages, varTempUser, colMessages, …).
    if (/^(Collect|ClearCollect)\s*\(/i.test(expr)) {
      return evalExpr(expr);
    }
    if (/^LookUp\s*\(\s*(var|col)/i.test(expr)) {
      return evalExpr(expr);
    }
    // Patch(varTempMessages, id, { ... }) where id is a LookUp result / local.
    if (
      (m = expr.match(
        /^Patch\s*\(\s*([A-Za-z_][\w]*)\s*,\s*([\s\S]+)\s*\)\s*$/i
      ))
    ) {
      const name = m[1];
      const existing = getVar(name);
      if (Array.isArray(existing) || (existing && typeof existing === "object")) {
        return patchInMemory(name, m[2]);
      }
    }
    if ((m = expr.match(/^(?:Office365Outlook\.)?SendEmailV2\s*\(([\s\S]*)\)\s*$/i))) {
      const args = splitArgs(m[1]);
      const to = args[0] != null ? evalExpr(args[0]) : "";
      const subject = args[1] != null ? evalExpr(args[1]) : "";
      const body = args[2] != null ? evalExpr(args[2]) : "";
      return invokeSendEmail(to, subject, body);
    }
    if (
      (m = expr.match(
        /^([A-Za-z_][\w]*)\.([A-Za-z_][\w]*)\s*\(([\s\S]*)\)\s*$/
      ))
    ) {
      const fx = findComponentFormula(m[1], m[2]);
      if (fx) {
        bindComponentArgs(m[2], m[3]);
        return runFormula(fx);
      }
    }
    if ((m = expr.match(/^Refresh\s*\(\s*'([^']+)'\s*\)\s*$/i))) {
      return hydrateTable(m[1]);
    }
    if ((m = expr.match(/^Refresh\s*\(\s*([A-Za-z_][\w]*)\s*\)\s*$/i))) {
      return hydrateTable(m[1]);
    }
    const quotedTable = parseQuotedTable(expr.replace(/^(Filter|LookUp|Patch|RemoveIf|Remove|ForAll)\s*\(\s*/i, ""));
    if ((m = expr.match(/^ForAll\s*\(/i)) && quotedTable) {
      await hydrateTable(quotedTable);
      return evalExpr(expr);
    }
    if ((m = expr.match(/^RemoveIf\s*\(\s*'([^']+)'\s*,\s*([\s\S]+)\)\s*$/i))) {
      const table = m[1];
      const pred = m[2];
      await hydrateTable(table);
      const rows = evalFilter("'" + table + "', " + pred);
      let n = 0;
      for (let i = 0; i < rows.length; i++) {
        const id = rows[i] && (rows[i].Id != null ? rows[i].Id : rows[i].ID != null ? rows[i].ID : rows[i].lom_id);
        if (id == null) continue;
        await apiData("delete", { table, id });
        n++;
      }
      await hydrateTable(table);
      return n;
    }
    if ((m = expr.match(/^Remove\s*\(\s*'([^']+)'\s*,\s*([\s\S]+)\)\s*$/i))) {
      const table = m[1];
      const target = evalExpr(m[2]);
      const id = target && typeof target === "object"
        ? (target.Id != null ? target.Id : target.ID != null ? target.ID : target.lom_id)
        : null;
      if (id != null) {
        await apiData("delete", { table, id });
        await hydrateTable(table);
      }
      return true;
    }
    if ((m = expr.match(/^Filter\s*\(/i)) && quotedTable) {
      await hydrateTable(quotedTable);
      return evalExpr(expr);
    }
    if ((m = expr.match(/^LookUp\s*\(/i)) && quotedTable) {
      await hydrateTable(quotedTable);
      return evalExpr(expr);
    }
    if ((m = expr.match(/^Filter\s*\(\s*'?([^',\s]+)'?\s*,\s*(.+)\)\s*$/i))) {
      const table = m[1];
      if (/^(var|col)/i.test(table) || Array.isArray(getVar(table))) {
        return evalExpr(expr);
      }
      await hydrateTable(table);
      return evalExpr(expr);
    }
    if ((m = expr.match(/^LookUp\s*\(\s*'?([^',\s]+)'?/i))) {
      const table = m[1];
      if (/^(var|col|fn)/i.test(table) || Array.isArray(getVar(table))) {
        return evalExpr(expr);
      }
      await hydrateTable(table);
      return evalExpr(expr);
    }
    if ((m = expr.match(/^Patch\s*\(\s*'([^']+)'\s*,\s*([\s\S]+)\s*\)\s*$/i))) {
      const table = m[1];
      const patchArgs = splitArgs(m[2]);
      const merged = mergePatchChangeRecords(patchArgs);
      let fields = merged.fields;
      let target = merged.firstRec >= 0 ? patchArgs.slice(0, merged.firstRec).join(", ") : m[2];
      if (merged.firstRec < 0) {
        const rec = String(m[2] || "").match(/(\{[\s\S]*\})\s*(?:,\s*[^,{][\s\S]*)?$/);
        fields = rec ? parseRecordLiteral(rec[1]) : {};
        target = rec ? String(m[2]).slice(0, rec.index).replace(/,\s*$/, "") : m[2];
      }
      if (fields.Title == null || fields.Title === "") {
        const minted = getVar("varSubmittedRequestUID") || getVar("varRequestID");
        if (minted) fields.Title = minted;
      }
      fields = enrichTrackingFields(table, fields);
      let existing = null;
      if (target && !/^\s*Defaults\s*\(/i.test(target)) {
        try {
          existing = evalExpr(target);
        } catch (_) {
          existing = null;
        }
      }
      if (existing && typeof existing === "object") {
        if (existing.Id != null) fields.Id = existing.Id;
        else if (existing.ID != null) fields.Id = existing.ID;
        else if (existing.lom_id != null) fields.Id = existing.lom_id;
      }
      const isNew = fields.Id == null;
      const saved = await apiData(isNew ? "insert" : "update", { table, row: fields });
      await hydrateTable(table);
      return unwrapRow(saved);
    }
    if (
      (m = expr.match(
        /^Patch\s*\(\s*'?([^',\s]+)'?\s*,\s*(Defaults\([^)]*\)|[^,]+)\s*,\s*\{(.+)\}\s*\)\s*$/i
      ))
    ) {
      const table = m[1];
      if (/^(var|col)/i.test(table) || Array.isArray(getVar(table))) {
        return evalExpr(expr);
      }
      const fields = {};
      m[3].split(",").forEach((pair) => {
        const idx = pair.indexOf(":");
        if (idx < 0) return;
        fields[pair.slice(0, idx).trim()] = evalExpr(pair.slice(idx + 1).trim());
      });
      const isNew = /Defaults/i.test(m[2]);
      const saved = await apiData(isNew ? "insert" : "update", { table, row: fields });
      await hydrateTable(table);
      return unwrapRow(saved);
    }
    if ((m = expr.match(/^If\s*\(([\s\S]+)\)$/i))) {
      return evalDataIf(m[1]);
    }
    if ((m = expr.match(/^With\s*\(([\s\S]+)\)$/i))) {
      return evalDataWith(m[1]);
    }
    return evalExpr(expr);
  }

  function unwrapRow(saved) {
    if (saved && typeof saved === "object" && saved.row && typeof saved.row === "object") {
      return saved.row;
    }
    return saved;
  }

  async function evalDataIf(argsRaw) {
    const args = splitArgs(argsRaw);
    if (args.length === 0) return null;
    const cond = asBool(evalExpr(args[0]));
    const branch = cond ? args[1] : args.length >= 3 ? args[2] : "";
    if (branch == null || String(branch).trim() === "") return false;
    return runFormula(branch);
  }

  async function evalDataWith(argsRaw) {
    const args = splitArgs(argsRaw);
    if (args.length < 2) return null;
    const record = parseRecordLiteral(args[0]);
    const body = args.slice(1).join(",");
    const saved = Object.assign(Object.create(null), state.context);
    Object.keys(record).forEach((k) => {
      state.context[k] = record[k];
      state.globals[k] = record[k];
    });
    try {
      return await runFormula(body);
    } finally {
      state.context = saved;
    }
  }

  /**
   * Patch(varName, lookUpOrRecord, { fields }) or Patch(recordVar, { fields }).
   */
  function patchInMemory(name, restRaw) {
    const rest = String(restRaw || "").trim();
    const existing = getVar(name);
    // Two-arg record patch: Patch(varCurrentUser, { preferences: ... })
    if (rest.startsWith("{") && rest.endsWith("}") && !Array.isArray(existing)) {
      const record = parseRecordLiteral(rest);
      const next = Object.assign({}, existing && typeof existing === "object" ? existing : {}, record);
      setVar(name, next);
      return next;
    }
    const m = rest.match(/^([\s\S]+),\s*\{([\s\S]+)\}\s*$/);
    if (!m) return existing;
    const record = parseRecordLiteral("{" + m[2] + "}");
    if (!Array.isArray(existing)) {
      const next = Object.assign({}, existing && typeof existing === "object" ? existing : {}, record);
      setVar(name, next);
      return next;
    }
    const target = evalExpr(m[1].trim());
    if (target && typeof target === "object") {
      const idx = existing.findIndex((r) => rowsMatch(r, target));
      if (idx >= 0) {
        const next = existing.slice();
        next[idx] = Object.assign({}, next[idx], record);
        setVar(name, next);
        return next[idx];
      }
    }
    const next = existing.slice();
    next.push(record);
    setVar(name, next);
    return record;
  }

  function rowsMatch(a, b) {
    if (!a || !b || typeof a !== "object" || typeof b !== "object") return false;
    if (a === b) return true;
    if (a.email != null && b.email != null && String(a.email) === String(b.email)) return true;
    if (a.user != null && b.user != null && String(a.user) === String(b.user)) return true;
    if (a.l1 != null && b.l1 != null && String(a.l1) === String(b.l1)) return true;
    if (a.id != null && b.id != null && String(a.id) === String(b.id)) return true;
    return false;
  }

  function evalForAll(argsRaw) {
    const args = splitArgs(argsRaw);
    if (args.length < 2) return [];
    let sourceExpr = args[0].trim();
    let alias = null;
    const asMatch = sourceExpr.match(/^(.+?)\s+As\s+([A-Za-z_][\w]*)$/i);
    if (asMatch) {
      sourceExpr = asMatch[1].trim();
      alias = asMatch[2];
    }
    let source = evalExpr(sourceExpr);
    if (!Array.isArray(source)) {
      const named = String(sourceExpr || "").replace(/^'|'$/g, "").replace(/^"|"$/g, "");
      const fromVar = getVar(named);
      if (Array.isArray(fromVar)) source = fromVar;
    }
    const rows = Array.isArray(source) ? source : [];
    const template = args.slice(1).join(",").trim();
    const out = [];
    rows.forEach((row) => {
      if (!row || typeof row !== "object") return;
      const saved = Object.assign(Object.create(null), state.context);
      try {
        if (alias) state.context[alias] = row;
        state.context.ThisRecord = row;
        Object.keys(row).forEach((k) => {
          state.context[k] = row[k];
        });
        if (template.startsWith("{")) {
          out.push(parseRecordLiteral(template));
        } else {
          out.push(evalExpr(template));
        }
      } finally {
        state.context = saved;
      }
    });
    return out;
  }

  function evalFilter(argsRaw) {
    const args = splitArgs(argsRaw);
    if (args.length < 2) return [];
    const sourceName = stripEq(args[0]).replace(/^'|'$/g, "").replace(/^"|"$/g, "");
    let rows = getVar(sourceName);
    if (!Array.isArray(rows)) {
      const v = evalExpr(args[0]);
      rows = Array.isArray(v) ? v : [];
    }
    // Multi-predicate Filter(table, p1, p2, ...) — AND them.
    const preds = args.slice(1);
    const out = [];
    rows.forEach((row) => {
      if (!row || typeof row !== "object") return;
      const saved = Object.assign(Object.create(null), state.context);
      state.context.ThisRecord = row;
      Object.keys(row).forEach((k) => {
        state.context[k] = row[k];
      });
      let ok = true;
      try {
        for (let i = 0; i < preds.length; i++) {
          if (!asBool(evalExpr(preds[i]))) {
            ok = false;
            break;
          }
        }
      } finally {
        state.context = saved;
      }
      if (ok) out.push(row);
    });
    return out;
  }

  function evalConcat(argsRaw) {
    const args = splitArgs(argsRaw);
    if (args.length < 2) return "";
    const source = evalExpr(args[0]);
    const rows = Array.isArray(source) ? source : [];
    const fieldExpr = args[1];
    const sep = args[2] != null ? String(evalExpr(args[2]) ?? "") : "";
    const parts = [];
    rows.forEach((row) => {
      if (row == null) return;
      const saved = Object.assign(Object.create(null), state.context);
      try {
        if (row && typeof row === "object") {
          Object.keys(row).forEach((k) => {
            state.context[k] = row[k];
          });
        }
        const v = evalExpr(fieldExpr);
        if (v != null && v !== "") parts.push(String(v));
      } finally {
        state.context = saved;
      }
    });
    return parts.join(sep);
  }

  function showNotify(message, kind) {
    const text = String(message || "").trim();
    if (!text) return;
    let host = document.getElementById("ps-notify-host");
    if (!host) {
      host = document.createElement("div");
      host.id = "ps-notify-host";
      host.className = "ps-notify-host";
      document.body.appendChild(host);
    }
    const el = document.createElement("div");
    el.className = "ps-notify";
    if (/error/i.test(kind)) el.classList.add("ps-notify--error");
    else if (/warn/i.test(kind)) el.classList.add("ps-notify--warn");
    else if (/success/i.test(kind)) el.classList.add("ps-notify--success");
    else el.classList.add("ps-notify--info");
    el.textContent = text;
    host.appendChild(el);
    setTimeout(() => {
      el.classList.add("ps-notify--out");
      setTimeout(() => el.remove(), 280);
    }, 3200);
  }

  function applyDarkMode() {
    const dark = asBool(getVar("gblDarkMode"));
    const rootEl = document.getElementById("app-root");
    if (rootEl) rootEl.classList.toggle("ps-dark", dark);
    document.documentElement.classList.toggle("ps-dark", dark);
    document.documentElement.setAttribute("data-theme", dark ? "dark" : "light");
    // Sync ThemeRadio radios if present (every component clone).
    // Matches DefaultSelectedItems: If(gblDarkMode, ["Dark"], ["Light"])
    document.querySelectorAll('[data-control="ThemeRadio"] input[type="radio"]').forEach((r) => {
      r.checked = dark ? r.value === "Dark" : r.value === "Light";
    });
  }

  function persistThemePreference(theme) {
    const normalized = theme === "dark" || theme === "Dark" ? "dark" : "light";
    const user = getVar("varCurrentUser");
    const prefs =
      user && typeof user === "object" && user.preferences && typeof user.preferences === "object"
        ? Object.assign({}, user.preferences)
        : {};
    prefs.theme = normalized;
    const next =
      user && typeof user === "object"
        ? Object.assign({}, user, { preferences: prefs })
        : { preferences: prefs };
    setVar("varCurrentUser", next);
    // Mirror SharePoint-backed preference so reload keeps the checked ThemeRadio.
    try {
      localStorage.setItem("ps_vcr_theme", normalized);
    } catch (_) {}
    // Keep varTempUser row in sync (ThemeRadio OnChange Patches the same shape).
    try {
      const rows = getVar("varTempUser");
      if (Array.isArray(rows)) {
        const email = (user && (user.user || user.email)) || "preview@local";
        const idx = rows.findIndex(
          (r) => r && String(r.user || r.email || "").toLowerCase() === String(email).toLowerCase()
        );
        if (idx >= 0) {
          const row = Object.assign({}, rows[idx]);
          row.preferences = Object.assign({}, row.preferences || {}, { theme: normalized });
          const copy = rows.slice();
          copy[idx] = row;
          setVar("varTempUser", copy);
        }
      }
    } catch (_) {}
  }

  /** After OnStart defaults gblDarkMode=false, restore like LoadAppSettings. */
  function restoreThemePreference() {
    let theme = "";
    try {
      const user = getVar("varCurrentUser");
      if (user && user.preferences && user.preferences.theme) {
        theme = String(user.preferences.theme);
      }
    } catch (_) {}
    if (!theme) {
      try {
        theme = localStorage.getItem("ps_vcr_theme") || "";
      } catch (_) {}
    }
    if (/dark/i.test(theme)) {
      setVar("gblDarkMode", true);
      persistThemePreference("dark");
    } else if (/light/i.test(theme)) {
      setVar("gblDarkMode", false);
      const user = getVar("varCurrentUser");
      if (user && typeof user === "object") {
        const prefs = Object.assign({}, user.preferences || {}, { theme: "light" });
        setVar("varCurrentUser", Object.assign({}, user, { preferences: prefs }));
      }
    }
  }

  function applySettingsChrome() {
    pinChrome();
    const open = asBool(getVar("varTopMenu"));
    document.querySelectorAll('[data-control^="TopbarHeader_"]').forEach((el) => {
      el.classList.toggle("ps-settings-open", open);
      if (open) {
        el.style.height = "100%";
        // Re-sync ThemeRadio checked state when Settings opens.
        applyDarkMode();
        // Re-evaluate fnDebugAllowed so Data column debug buttons appear.
        [
          "BtnToggleDebugMode",
          "BtnRefreshData",
          "BtnResetLog",
          "BtnCopyDebugLog",
        ].forEach((name) => {
          findAllByControl(name).forEach((el) => {
            const show = asBool(namedFormula("fnDebugAllowed"));
            el.classList.toggle("is-hidden", !show);
            if (show) {
              el.style.display = "";
              el.style.visibility = "visible";
              // Keep the live Debug mode: true/false label.
              try {
                const props = controlProps(name) || {};
                if (props.Text != null) {
                  const t = evalExpr(props.Text);
                  if (typeof t === "string" && !looksLikeFormula(t)) el.textContent = t;
                }
              } catch (_) {}
            }
          });
        });
      } else {
        el.style.height = "65px";
      }
    });
    const tbc = document.querySelectorAll('[data-control="TransparentButtonContainer"]');
    tbc.forEach((el) => {
      el.style.minHeight = open ? "420px" : "0";
      el.style.height = open ? "auto" : "0";
      el.style.pointerEvents = open ? "auto" : "none";
    });
  }

  function applyAdminChrome() {
    const pane = Number(getVar("varVisibleAdminScreen") || 1);
    const map = {
      Button2_9: 1,
      Button2_10: 2,
      Button2_12: 3,
      AppAdminSettingsBtn: 4,
      OverviewBtn: 5,
    };
    Object.keys(map).forEach((name) => {
      findAllByControl(name).forEach((el) => {
        el.classList.toggle("is-admin-active", map[name] === pane);
      });
    });
  }

  function applyDetailChrome() {
    const pane = Number(getVar("varDetailScreenSelect") || 1);
    const map = {
      PDFOverviewBtn: 1,
      PDFOverviewBtn_1: 2,
      PDFOverviewBtn_2: 3,
    };
    Object.keys(map).forEach((name) => {
      findAllByControl(name).forEach((el) => {
        el.classList.toggle("is-detail-active", map[name] === pane);
      });
    });
  }

  function refreshCollectionBoundDropdowns(colName) {
    const want = String(colName || "");
    if (!want) return;
    const rows = getVar(want);
    if (!Array.isArray(rows)) return;
    Object.keys(state.controlIndex).forEach((name) => {
      const props = controlProps(name);
      const items = stripEq(String((props && props.Items) || ""));
      if (items !== want) return;
      findAllByControl(name).forEach((el) => {
        if (!el || el.tagName !== "SELECT") return;
        const prevId =
          el.selectedOptions && el.selectedOptions[0]
            ? el.selectedOptions[0].getAttribute("data-id")
            : null;
        const prev = el.value;
        el.innerHTML = "";
        rows.forEach((row) => {
          if (!row || typeof row !== "object") return;
          const lab =
            row.Name != null
              ? row.Name
              : row.Acronym != null
                ? row.Acronym
                : row.Value != null
                  ? row.Value
                  : row.Label != null
                    ? row.Label
                    : "";
          const opt = document.createElement("option");
          opt.value = String(lab ?? "");
          opt.textContent = String(lab ?? "");
          if (row.ID != null) opt.setAttribute("data-id", String(row.ID));
          else if (row.Id != null) opt.setAttribute("data-id", String(row.Id));
          el.appendChild(opt);
        });
        if (prevId != null && prevId !== "") {
          const match = Array.prototype.find.call(el.options, (o) => o.getAttribute("data-id") === prevId);
          if (match) el.value = match.value;
        } else if (prev != null) {
          el.value = prev;
        }
        bindDropdownValue(name, el.value, el);
      });
    });
  }

  function parseCollectRows(raw) {
    raw = String(raw || "").trim();
    if (!raw) return [];
    // Single record or comma-separated records.
    if (raw.startsWith("{")) {
      const rows = [];
      let depth = 0;
      let cur = "";
      let quote = null;
      for (let i = 0; i < raw.length; i++) {
        const ch = raw[i];
        if (quote) {
          cur += ch;
          if (ch === quote && raw[i - 1] !== "\\") quote = null;
          continue;
        }
        if (ch === '"' || ch === "'") {
          quote = ch;
          cur += ch;
          continue;
        }
        if (ch === "{") depth++;
        if (ch === "}") depth--;
        cur += ch;
        if (depth === 0 && cur.trim()) {
          rows.push(parseRecordLiteral(cur.trim()));
          cur = "";
          while (i + 1 < raw.length && /[\s,]/.test(raw[i + 1])) i++;
        }
      }
      if (cur.trim()) rows.push(parseRecordLiteral(cur.trim()));
      return rows;
    }
    const v = evalExpr(raw);
    return Array.isArray(v) ? v : v != null ? [v] : [];
  }

  function evalLookUp(argsRaw) {
    const args = splitArgs(argsRaw);
    if (args.length < 2) return null;
    const sourceName = stripEq(args[0]).replace(/^'|'$/g, "").replace(/^"|"$/g, "");
    let rows = getVar(sourceName);
    if (!Array.isArray(rows) && ir.collections && Array.isArray(ir.collections[sourceName])) {
      rows = ir.collections[sourceName];
    }
    if (!Array.isArray(rows)) return null;
    const pred = args[1];
    for (let i = 0; i < rows.length; i++) {
      const row = rows[i];
      if (!row || typeof row !== "object") continue;
      const saved = Object.assign(Object.create(null), state.context);
      Object.keys(row).forEach((k) => {
        state.context[k] = row[k];
      });
      let ok = false;
      try {
        ok = asBool(evalExpr(pred));
      } finally {
        state.context = saved;
      }
      // Also support email = "x" / Title = "1" / user = ... without context edge cases.
      if (!ok && /email\s*=\s*"([^"]+)"/i.test(pred)) {
        const want = pred.match(/email\s*=\s*"([^"]+)"/i)[1];
        ok = String(row.email || "") === want;
      }
      if (!ok && /Title\s*=\s*"([^"]+)"/i.test(pred)) {
        const want = pred.match(/Title\s*=\s*"([^"]+)"/i)[1];
        ok = String(row.Title || row.title || "") === want;
      }
      if (ok) return row;
    }
    return null;
  }

  function lookupDropdownRecord(name, text) {
    const rec = { Value: text, Name: text };
    if (!text) return rec;
    const props = controlProps(name);
    const itemsFx = stripEq(String((props && props.Items) || ""));
    const valueField = stripEq(String((props && (props["Items.Value"] || props.Value)) || ""));
    let rows = getVar(itemsFx);
    if (!Array.isArray(rows) && ir.collections && Array.isArray(ir.collections[itemsFx])) {
      rows = ir.collections[itemsFx];
    }
    if (!Array.isArray(rows)) return rec;
    const field = valueField && /^[A-Za-z_]\w*$/.test(valueField) ? valueField : "";
    for (let i = 0; i < rows.length; i++) {
      const row = rows[i];
      if (!row || typeof row !== "object") continue;
      const lab =
        (field && row[field] != null ? row[field] : null) ??
        row.Name ??
        row.Acronym ??
        row.Value ??
        row.Label;
      if (String(lab ?? "") === text || String(row.ID ?? row.Id ?? "") === text) {
        return Object.assign({ Value: lab != null ? lab : text, Name: row.Name != null ? row.Name : text }, row);
      }
    }
    return rec;
  }

  function bindDropdownValue(name, value, el) {
    const text = value == null ? "" : String(value);
    let rec = lookupDropdownRecord(name, text);
    if (el && el.tagName === "SELECT" && el.selectedOptions && el.selectedOptions[0]) {
      const id = el.selectedOptions[0].getAttribute("data-id");
      if (id != null && id !== "" && rec.ID == null && rec.Id == null) {
        rec = Object.assign({}, rec, { ID: Number(id) || id });
      }
    }
    state.values[name + ".Text"] = text;
    state.values[name + ".Selected"] = rec;
    state.values[name + ".Selected.Value"] = rec.Value != null ? rec.Value : text;
    state.values[name + ".Selected.Name"] = rec.Name != null ? rec.Name : text;
    if (rec.ID != null) state.values[name + ".Selected.ID"] = rec.ID;
    else if (rec.Id != null) state.values[name + ".Selected.ID"] = rec.Id;
    if (rec.Acronym != null) state.values[name + ".Selected.Acronym"] = rec.Acronym;
    if (name === "Destination") {
      setVar("varDestination", text);
      setVar("varIreland", text === "Ireland");
    }
    if (name === "BOTSelect") {
      setVar("varBOT", text);
    }
  }

  function syncDropdownValues(root) {
    const scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll("select[data-control]").forEach((el) => {
      const name = el.getAttribute("data-control");
      if (!name) return;
      bindDropdownValue(name, el.value, el);
    });
  }

  function hydrateFilterControls() {
    document.querySelectorAll("select[data-control]").forEach((el) => {
      const name = el.getAttribute("data-control") || "";
      if (!/Filter$/i.test(name)) return;
      const props = controlProps(name);
      if (props.Default || props.DefaultSelectedItems) return;
      const hasBlank = Array.prototype.some.call(el.options, (opt) => String(opt.value || "") === "" && String(opt.text || "") === "");
      if (!hasBlank) {
        const blank = document.createElement("option");
        blank.value = "";
        el.insertBefore(blank, el.firstChild);
      }
      el.selectedIndex = 0;
      bindDropdownValue(name, el.value, el);
    });
  }

  function hydrateDatePickers() {
    Object.keys(state.controlIndex).forEach((name) => {
      const entry = state.controlIndex[name];
      const type = String((entry && entry.node && entry.node.type) || "");
      if (!/datepicker/i.test(type)) return;
      const isFilter = /Filter$/i.test(name);
      findAllByControl(name).forEach((el) => {
        if (el.tagName !== "INPUT") return;
        try {
          el.type = "date";
        } catch (_) {}
        el.removeAttribute("placeholder");
        if (!el.value) {
          const fx = (entry.node.properties && (entry.node.properties.DefaultDate || entry.node.properties.Default)) || "";
          let v = "";
          if (fx) {
            const out = evalExpr(fx);
            const d = toDate(out);
            if (d) v = d.toISOString().slice(0, 10);
            else if (typeof out === "string" && /^\d{4}-\d{2}-\d{2}/.test(out)) v = out.slice(0, 10);
          }
          if (!v && !isFilter) v = new Date().toISOString().slice(0, 10);
          el.value = v;
        } else if (isFilter && !((entry.node.properties && (entry.node.properties.DefaultDate || entry.node.properties.Default)))) {
          el.value = "";
        }
        state.values[name + ".SelectedDate"] = el.value || null;
        state.values[name + ".Text"] = el.value || "";
      });
    });
  }

  /** Keep L1 / L1Acro selects aligned with varL1SelectedID (Power Apps Reset pair). */
  function syncL1PairFromVars() {
    const wantId = getVar("varL1SelectedID");
    if (wantId == null || wantId === "") return;
    const idStr = String(wantId);
    ["L1", "L1Acro"].forEach((name) => {
      findAllByControl(name).forEach((el) => {
        if (!el || el.tagName !== "SELECT") return;
        for (let i = 0; i < el.options.length; i++) {
          if (el.options[i].getAttribute("data-id") === idStr) {
            if (el.selectedIndex !== i) {
              el.selectedIndex = i;
            }
            bindDropdownValue(name, el.value, el);
            return;
          }
        }
        // Fallback: match by Default formula text (Name / Acronym).
        const fx = controlProps(name).Default;
        if (!fx) return;
        try {
          const label = evalExpr(fx);
          if (label == null || label === "") return;
          const want = String(label);
          for (let i = 0; i < el.options.length; i++) {
            if (el.options[i].value === want || el.options[i].textContent === want) {
              el.selectedIndex = i;
              bindDropdownValue(name, el.value, el);
              return;
            }
          }
        } catch (_) {}
      });
    });
  }

  function isIrelandDestination() {
    if (asBool(getVar("varIreland"))) return true;
    const dest = resolvePath("Destination", ["Selected", "Value"]);
    return String(dest ?? "") === "Ireland";
  }

  function resetControl(name) {
    const el = findByControl(name);
    if (!el) return;
    if (el.getAttribute("data-role") === "richtext") {
      const body = el.querySelector(".ctrl-rte-body");
      const original =
        state.rteDefaults[name] != null
          ? state.rteDefaults[name]
          : el.getAttribute("data-default-html") || "";
      if (body) body.innerHTML = original;
      return;
    }
    if (el.getAttribute("data-role") === "gallery") {
      el.querySelectorAll("input[data-role='input'], textarea").forEach((input) => {
        const def =
          input.getAttribute("data-default") != null
            ? input.getAttribute("data-default")
            : "";
        input.value = def;
      });
      return;
    }
    if (el.tagName === "INPUT" || el.tagName === "TEXTAREA" || el.tagName === "SELECT") {
      let def = "";
      const fx =
        el.getAttribute("data-default-fx") ||
        (controlProps(name).Default != null ? controlProps(name).Default : controlProps(name).DefaultDate);
      // Prefer live Default formula (LookUp by varL1SelectedID) over SSR snapshot.
      if (fx) {
        try {
          const out = evalExpr(fx);
          if (out != null && out !== "") def = String(out);
        } catch (_) {}
      }
      if (!def) def = el.getAttribute("data-default") || "";
      if (el.tagName === "SELECT") {
        const wantId = getVar("varL1SelectedID");
        let matched = false;
        if ((name === "L1" || name === "L1Acro") && wantId != null && wantId !== "") {
          const idStr = String(wantId);
          for (let i = 0; i < el.options.length; i++) {
            if (el.options[i].getAttribute("data-id") === idStr) {
              el.selectedIndex = i;
              matched = true;
              break;
            }
          }
        }
        if (!matched) {
          el.value = def;
          if (el.value !== def && def) {
            for (let i = 0; i < el.options.length; i++) {
              if (el.options[i].textContent === def || el.options[i].value === def) {
                el.selectedIndex = i;
                break;
              }
            }
          }
        }
        bindDropdownValue(name, el.value, el);
      } else {
        el.value = def;
      }
      if (name === "UserSearchCombo" || el.getAttribute("data-combo") === "user-search") {
        setVar("varUserSearchPick", null);
        state.values[name + ".Selected"] = { Mail: "" };
        state.values[name + ".Selected.Mail"] = "";
        state.values[name + ".Selected.Value"] = "";
      }
    }
  }

  /** Bind ThisItem from a gallery row (User Roles / Acknowledgers). */
  function thisItemFromRow(row) {
    if (!row) return null;
    if (row.classList.contains("ctrl-user-row")) {
      return {
        user: row.getAttribute("data-user") || "",
        role: row.getAttribute("data-role-value") || "",
      };
    }
    if (row.classList.contains("ctrl-acknowledger-row")) {
      return {
        l1: row.getAttribute("data-l1") || "",
        email: row.getAttribute("data-email") || "",
      };
    }
    if (row.classList.contains("lookup-row") || row.classList.contains("gallery-row")) {
      const title = row.getAttribute("data-request-title") || "";
      const list =
        getVar("CDLS (L) VCR Tracking List") ||
        getVar("VCRTrackingList") ||
        [];
      if (title && Array.isArray(list)) {
        const found = list.find((r) => r && String(r.Title || "") === title);
        if (found) return found;
      }
      return title ? { Title: title } : null;
    }
    return null;
  }

  function seedTempMessagesFromIr() {
    const existing = getVar("varTempMessages");
    const list = Array.isArray(existing) ? existing.slice() : [];
    const have = new Set(list.map((r) => (r && r.email) || ""));
    const blob = JSON.stringify(ir || {});
    // Formulas inside IR stringify with escaped quotes: email: \"ack_deny\", content: \"...\"
    const re =
      /email:\s*\\"([a-z_]+)\\"\s*,\s*\\n\s*content:\s*\\"((?:\\\\.|[^"\\])*)\\"/g;
    let m;
    while ((m = re.exec(blob)) !== null) {
      const email = m[1];
      if (have.has(email)) continue;
      let content = m[2];
      try {
        content = JSON.parse('"' + content + '"');
      } catch (_) {
        content = content
          .replace(/\\"/g, '"')
          .replace(/\\n/g, "\n")
          .replace(/\\\\/g, "\\");
      }
      // Power Fx sometimes doubles quotes inside href=""..."".
      content = content.replace(/href=""([^"]*)""/g, 'href="$1"');
      list.push({ email: email, content: content });
      have.add(email);
    }
    setVar("varTempMessages", list);
    setVar("colMessages", list.slice());
  }

  function hydrateRichTextEditors() {
    document.querySelectorAll('[data-role="richtext"]').forEach((el) => {
      const name = el.getAttribute("data-control") || "";
      const body = el.querySelector(".ctrl-rte-body");
      if (!body) return;
      const fx = el.getAttribute("data-default-fx") || "";
      let html = "";
      if (fx) {
        try {
          const v = evalExpr(fx);
          html = v == null ? "" : String(v);
        } catch (_) {
          html = "";
        }
      }
      body.innerHTML = html;
      state.rteDefaults[name] = html;
      el.setAttribute("data-default-html", html);
    });
  }

  /** Resolve input Defaults like LookUp(...).'Default Approver' / varDefaultApprover. */
  function hydrateDefaultInputs() {
    document.querySelectorAll("input[data-default-fx]").forEach((el) => {
      if (el.value) return;
      const fx = el.getAttribute("data-default-fx") || "";
      if (!fx) return;
      let v = "";
      try {
        // Common Admin path: LookUp('CDLS (L) App Settings', Title = "1").'Default Approver'
        if (/Default Approver/i.test(fx) || /varDefaultApprover/i.test(fx)) {
          v = String(getVar("varDefaultApprover") || getVar("varDevApprover") || "");
        } else {
          const out = evalExpr(fx);
          v = out == null ? "" : String(out);
        }
      } catch (_) {
        v = "";
      }
      if (v) {
        el.value = v;
        el.setAttribute("data-default", v);
        const name = el.getAttribute("data-control");
        if (name) state.values[name + ".Text"] = v;
      }
    });
  }

  function fillBoundText(scope, row) {
    const saved = Object.assign(Object.create(null), state.context);
    state.context.ThisItem = row;
    state.context.ThisRecord = row;
    if (row && typeof row === "object") {
      Object.keys(row).forEach((k) => {
        state.context[k] = row[k];
      });
    }
    try {
      scope.querySelectorAll("[data-control]").forEach((el) => {
        const name = el.getAttribute("data-control");
        const props = controlProps(name);
        if (!props || props.Text == null) return;
        if (el.tagName === "INPUT" || el.tagName === "SELECT" || el.tagName === "BUTTON") return;
        if (isAnchorControlEl(el)) return;
        try {
          const t = evalExpr(props.Text);
          if ((typeof t === "string" || typeof t === "number") && !looksLikeFormula(t)) {
            el.textContent = String(t);
          }
        } catch (_) {}
      });
    } finally {
      state.context = saved;
    }
  }

  function hydrateNamedGallery(name, fallbackText) {
    const gal = findByControl(name);
    if (!gal) return;
    if (!gal.getAttribute("data-row-template")) {
      gal.setAttribute("data-row-template", gal.innerHTML);
    }
    const tpl = gal.getAttribute("data-row-template") || "";
    const props = controlProps(name);
    let rows = [];
    if (props.Items) {
      try {
        const out = evalExpr(props.Items);
        rows = Array.isArray(out) ? out : [];
      } catch (_) {
        rows = [];
      }
    }
    if (rows.length === 0 && /VCR Tracking List|VCRTrackingList/i.test(String(props.Items || ""))) {
      const raw = getVar("CDLS (L) VCR Tracking List") || getVar("VCRTrackingList") || [];
      rows = Array.isArray(raw) ? raw : [];
    }
    gal.innerHTML = "";
    rows.forEach((row) => {
      if (!row || typeof row !== "object") return;
      const wrap = document.createElement("div");
      wrap.className = "lookup-row";
      wrap.setAttribute("data-request-title", String(row.Title || row.ID || ""));
      wrap.innerHTML = tpl;
      wrap.querySelectorAll("[data-control]").forEach((el) => {
        const orig = el.getAttribute("data-control");
        if (orig) el.setAttribute("data-row-control", orig);
        if (el.parentElement === wrap) {
          el.style.setProperty("position", "relative", "important");
          el.style.setProperty("left", "auto", "important");
          el.style.setProperty("top", "auto", "important");
          el.style.setProperty("width", "auto", "important");
          el.style.setProperty("height", "auto", "important");
        }
      });
      const more = wrap.querySelector('[data-control="MoreOptionsContainer"]');
      if (more) more.classList.add("is-hidden");
      const vipBg = wrap.querySelector('[data-control="VIPHighlightContainer"]');
      if (vipBg) vipBg.classList.add("is-hidden");
      fillBoundText(wrap, row);
      if (fallbackText) fallbackText(wrap, row);
      gal.appendChild(wrap);
    });
    gal.setAttribute("data-row-count", String(gal.querySelectorAll(".lookup-row").length));
  }

  function hydrateCollectionGallery(name, templateControl) {
    const gal = findByControl(name);
    if (!gal) return;
    const props = controlProps(name);
    let rows = [];
    if (props.Items) {
      try {
        const out = evalExpr(props.Items);
        rows = Array.isArray(out) ? out : [];
      } catch (_) {
        rows = [];
      }
    }
    if (!rows.length && props.Items) {
      const fallback = getVar(String(props.Items).replace(/^=/, "").trim());
      if (Array.isArray(fallback)) rows = fallback;
    }
    if (!gal.getAttribute("data-row-template")) {
      const tplEl =
        gal.querySelector('[data-control="' + templateControl + '"]') ||
        gal.firstElementChild;
      if (tplEl) gal.setAttribute("data-row-template", tplEl.outerHTML);
    }
    const tpl = gal.getAttribute("data-row-template") || "";
    if (!tpl) return;
    const existing = gal.querySelectorAll(".gallery-row").length;
    if (existing === rows.length && existing > 0) return;
    gal.innerHTML = "";
    rows.forEach((row) => {
      if (!row || typeof row !== "object") return;
      const wrap = document.createElement("div");
      wrap.className = "gallery-row";
      wrap.setAttribute("data-gallery-item", "1");
      if (row.ID != null) wrap.setAttribute("data-item-id", String(row.ID));
      wrap.innerHTML = tpl;
      fillBoundText(wrap, row);
      wrap.querySelectorAll("input[type='checkbox']").forEach((input) => {
        const host = input.closest("[data-control]");
        const field = host && host.getAttribute("data-control");
        if (field && row[field] != null) input.checked = asBool(row[field]);
      });
      gal.appendChild(wrap);
    });
    gal.setAttribute("data-row-count", String(gal.querySelectorAll(".gallery-row").length));
  }

  function hydrateFormGalleries() {
    hydrateCollectionGallery("galSites", "AgencyTemplate");
    hydrateCollectionGallery("galVisitors", "ParticularTemplate");
  }

  function hydrateLookupGallery() {
    hydrateNamedGallery("LookupGallery", (wrap, row) => {
      const uid = wrap.querySelector('[data-control="RequestUID"]');
      if (uid && !String(uid.textContent || "").trim()) {
        uid.textContent = String(row.Title || "");
      }
      const status = wrap.querySelector('[data-control="RequestStatus"]');
      if (status && !String(status.textContent || "").trim()) {
        status.textContent = String(row.approval_state || "");
      }
      // Match LookupGalleryTitleLLabelsContainer column order from the IR.
      const order = [
        "RequestUID",
        "MemberEmailLabel",
        "RequestVIP",
        "RequestStatus",
        "DateOfVisit",
        "CDLSRefNum",
        "RequestL1Label",
        "RequestSecurityClassification",
        "RequestVisitors",
        "RequestSites",
        "MoreOptionsBtn",
      ];
      order.forEach((name) => {
        const el = wrap.querySelector('[data-control="' + name + '"]');
        if (el) wrap.appendChild(el);
      });
    });
    hydrateNamedGallery("RequestsGallery", (wrap, row) => {
      const title = wrap.querySelector('[data-control="RequestTitle"]');
      if (title) {
        const dest = row.Destination != null ? String(row.Destination) : "";
        title.textContent = dest ? String(row.Title || "") + " — " + dest : String(row.Title || "");
      }
    });
  }

  function applyVisibility() {
    Object.keys(state.controlIndex).forEach((name) => {
      const entry = state.controlIndex[name];
      const props = entry.node.properties || {};
      // Component instances repeat across screens — update every match on the active screen.
      const els = findAllByControl(name);
      if (!els.length) return;
      els.forEach((el) => {
        if (el.closest && el.closest(".lookup-row")) return;
        if (props.Visible != null) {
          const vis = evalExpr(props.Visible);
          el.classList.toggle("is-hidden", !asBool(vis));
        }
        if (props.DisplayMode != null || name === "L1" || name === "L1Acro") {
          let mode = "";
          if (props.DisplayMode != null) {
            try {
              mode = String(evalExpr(props.DisplayMode) ?? "");
            } catch (_) {
              mode = "";
            }
          }
          // L1/L1Acro: DisplayMode.View when Destination is Ireland (also honor varIreland).
          let locked =
            mode === "DisplayMode.Disabled" ||
            mode === "DisplayMode.View" ||
            /^DisplayMode\.(Disabled|View)$/i.test(mode);
          if (!locked && mode && !/^DisplayMode\./i.test(mode) && !/^If\s*\(/i.test(mode)) {
            // Only use loose /View|Disabled/ when mode is a short enum-like string.
            locked = /^(Disabled|View)$/i.test(mode);
          }
          if ((name === "L1" || name === "L1Acro") && isIrelandDestination()) {
            locked = true;
          }
          el.classList.toggle("is-disabled", locked);
          el.classList.toggle("is-view-mode", locked);
          if (el.tagName === "SELECT" || el.tagName === "INPUT" || el.tagName === "TEXTAREA") {
            el.disabled = locked;
            if (locked) el.setAttribute("aria-readonly", "true");
            else el.removeAttribute("aria-readonly");
          }
        }
        if (props.HtmlText != null && (el.getAttribute("data-role") || "") === "html") {
          try {
            const html = evalExpr(props.HtmlText);
            if (typeof html === "string" && !looksLikeFormula(html)) {
              el.innerHTML = html;
            }
          } catch (_) {}
        }
        if (
          props.Text != null &&
          el.tagName !== "INPUT" &&
          el.tagName !== "SELECT" &&
          !isAnchorControlEl(el)
        ) {
          try {
            const role = el.getAttribute("data-role") || "";
            if (
              role === "choice" ||
              role === "html" ||
              role === "tabs" ||
              role === "gallery" ||
              el.classList.contains("ctrl--checkbox") ||
              el.classList.contains("ctrl--radio") ||
              el.classList.contains("ctrl-settings-pill") ||
              el.hasAttribute("data-gallery-item") ||
              el.querySelector("input, select, textarea, button, a, svg, img")
            ) {
              // Keep interactive / structured chrome intact.
              // Gallery template buttons (AppSettingsGallery pills) keep SSR labels.
            } else {
              const t = evalExpr(props.Text);
              if (
                (typeof t === "string" || typeof t === "number") &&
                !looksLikeFormula(t)
              ) {
                if (!el.querySelector("[data-control]")) {
                  el.textContent = String(t);
                }
              } else if (t == null && !el.querySelector("[data-control]")) {
                el.textContent = "";
              }
            }
          } catch (_) {}
        }
      });
    });
    applySettingsChrome();
    applyAdminChrome();
    applyDetailChrome();
    applyDarkMode();
    applyValidationBorders();
    try {
      hydrateLookupGallery();
    } catch (_) {}
    try {
      hydrateFormGalleries();
    } catch (_) {}
  }

  function cssColorFromFx(value) {
    if (value == null) return "";
    if (typeof value === "string") {
      const s = value.trim();
      if (/^rgba?\(/i.test(s) || /^#/.test(s) || /^var\(/i.test(s)) return s;
      if (/^gblTheme(?:Light|Dark)?\./i.test(s)) {
        const key = s.split(".").pop();
        return themeCss(key) || "";
      }
      if (/^Color\./i.test(s)) {
        const name = s.slice(6).toLowerCase();
        if (name === "red") return "rgba(184,0,0,1)";
        if (name === "transparent") return "transparent";
      }
      return s;
    }
    if (typeof value === "object") {
      if (value.r != null && value.g != null && value.b != null) {
        const a = value.a != null ? value.a : 1;
        return "rgba(" + value.r + "," + value.g + "," + value.b + "," + a + ")";
      }
    }
    return "";
  }

  /** Re-evaluate BorderColor / BorderThickness when varShowValidation flips. */
  function applyValidationBorders() {
    ensureThemeDefaults();
    Object.keys(state.controlIndex).forEach((name) => {
      const entry = state.controlIndex[name];
      const props = (entry && entry.node && entry.node.properties) || {};
      const bcFx = props.BorderColor != null ? stripEq(String(props.BorderColor)) : "";
      const btFx =
        props.BorderThickness != null ? stripEq(String(props.BorderThickness)) : "";
      if (!bcFx && !btFx) return;
      const hay = bcFx + "\n" + btFx;
      if (
        !/varShowValidation|gblTheme\.Danger|Parent\.Required|Color\.Red/i.test(hay)
      ) {
        return;
      }
      // Skip fully commented-out validation formulas left as gblTheme.Border only.
      const liveBc = bcFx
        .replace(/\/\*[\s\S]*?\*\//g, "")
        .replace(/\/\/[^\n]*/g, "")
        .trim();
      const liveBt = btFx
        .replace(/\/\*[\s\S]*?\*\//g, "")
        .replace(/\/\/[^\n]*/g, "")
        .trim();
      if (!liveBc && !liveBt) return;
      if (
        liveBc &&
        !/varShowValidation|Parent\.Required|Color\.Red|gblTheme\.Danger/i.test(liveBc) &&
        liveBt &&
        !/varShowValidation|Self\.BorderColor|gblTheme\.Danger/i.test(liveBt)
      ) {
        return;
      }
      const els = findAllByControl(name);
      if (!els.length) return;
      els.forEach((el) => {
        if (el.closest && el.closest(".lookup-row")) return;
        const prevSelf = state.context.__selfName;
        state.context.__selfName = name;
        try {
          let color = "";
          let thickness = null;
          if (liveBc && /varShowValidation|Parent\.Required|Color\.Red|IsBlank/i.test(liveBc)) {
            color = cssColorFromFx(evalExpr(liveBc));
          } else if (liveBc) {
            color = cssColorFromFx(evalExpr(liveBc));
          }
          if (liveBt) {
            const t = evalExpr(liveBt);
            if (typeof t === "number" && Number.isFinite(t)) thickness = t;
            else if (typeof t === "string" && /^-?\d+(\.\d+)?$/.test(t.trim())) {
              thickness = Number(t.trim());
            }
          }
          const transparent =
            !color ||
            /^transparent$/i.test(color) ||
            /^Color\.Transparent$/i.test(color) ||
            /rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*0\s*\)/i.test(color);
          if (color && !transparent) {
            const danger = /danger|184\s*,\s*0\s*,\s*0|249\s*,\s*83\s*,\s*109|185\s*,\s*28\s*,\s*28|rgb\(\s*184/i.test(
              color
            );
            const w =
              thickness != null && Number.isFinite(thickness)
                ? Math.max(0, thickness)
                : Math.max(1, parseFloat(getComputedStyle(el).borderWidth) || 1);
            // Inset outline so parent overflow:hidden does not clip the red ring.
            if (danger && w > 0) {
              el.classList.add("ps-validation-error");
              el.style.borderColor = color;
              el.style.borderWidth = "1px";
              el.style.borderStyle = "solid";
              el.style.boxShadow = "inset 0 0 0 2px " + color;
              el.style.outline = "none";
            } else if (w > 0) {
              el.classList.remove("ps-validation-error");
              el.style.border = w + "px solid " + color;
              el.style.boxShadow = "";
              el.style.outline = "";
            } else {
              el.classList.remove("ps-validation-error");
              el.style.border = "none";
              el.style.boxShadow = "";
              el.style.outline = "";
            }
          } else {
            // Color.Transparent / blank — drop SSR validation chrome.
            el.classList.remove("ps-validation-error");
            el.style.boxShadow = "";
            el.style.outline = "";
            if (thickness != null && thickness <= 0) {
              el.style.border = "none";
            } else {
              el.style.borderColor = "";
              el.style.borderWidth = "";
              el.style.borderStyle = "";
            }
          }
        } catch (_) {
          /* keep SSR border */
        } finally {
          state.context.__selfName = prevSelf;
        }
      });
    });
  }

  function ensureThemeDefaults() {
    let theme = getVar("gblTheme");
    if (!theme || typeof theme !== "object") {
      theme = {};
      setVar("gblTheme", theme);
    }
    const defaults = {
      Danger: "rgba(184,0,0,1)",
      DangerHover: "rgba(115,49,49,1)",
      DangerText: "rgba(185,28,28,1)",
      InputBorder: "rgba(148,163,184,1)",
      Border: "rgba(226,232,240,1)",
      BorderStrong: "rgba(0,0,0,1)",
      LinkCss: "rgba(29,78,216,1)",
    };
    Object.keys(defaults).forEach((k) => {
      if (theme[k] == null || theme[k] === "") theme[k] = defaults[k];
    });
  }

  async function runFormula(formula) {
    if (!formula) return;
    if (typeof formula === "function") {
      const last = await formula();
      applyVisibility();
      return last;
    }
    const parts = splitStatements(stripEq(stripComments(formula)));
    let last = null;
    for (const part of parts) {
      if (!part) continue;
      if (
        /^(Filter|LookUp|Patch|Collect|ClearCollect|SubmitForm|Refresh|RemoveIf|Remove|ForAll|If|With|Set)\s*\(/i.test(part) ||
        /(?:Office365Outlook\.)?SendEmailV2\s*\(/i.test(part) ||
        /^[A-Za-z_][\w]*\.[A-Za-z_][\w]*\s*\(/.test(part)
      ) {
        try {
          last = await evalDataExpr(part);
        } catch (_) {
          last = evalExpr(part);
        }
      } else {
        last = evalExpr(part);
      }
    }
    applyVisibility();
    return last;
  }

  /** Invoke a native handler function or residual Power Fx string. */
  async function invokeHandler(handler) {
    if (!handler) return;
    if (typeof handler === "function") {
      const last = await handler();
      applyVisibility();
      return last;
    }
    return runFormula(handler);
  }

  function psApiNavigate(name) {
    showScreen(String(name || ""));
    return true;
  }
  function psApiSet(name, value) {
    setVar(String(name || ""), value);
    return value;
  }
  function psApiGet(name) {
    return getVar(String(name || ""));
  }
  function psApiReset(name) {
    resetControl(String(name || ""));
    return true;
  }
  function psApiSelectControl(name) {
    return evalExpr("Select(" + String(name || "") + ")");
  }
  function psApiSelectParent() {
    return evalExpr("Select(Parent)");
  }

  // Native handler modules call window.PS.* instead of embedding Power Fx JSON.
  window.PS = Object.assign(window.PS || {}, {
    runFx: runFormula,
    navigate: psApiNavigate,
    set: psApiSet,
    get: psApiGet,
    reset: psApiReset,
    selectControl: psApiSelectControl,
    selectParent: psApiSelectParent,
  });

  function controlProps(name) {
    const entry = state.controlIndex[name];
    const base =
      entry && entry.node && entry.node.properties ? entry.node.properties : {};
    const fromHandlers =
      typeof window !== "undefined" &&
      window.PS_HANDLERS &&
      window.PS_HANDLERS[name] &&
      typeof window.PS_HANDLERS[name] === "object"
        ? window.PS_HANDLERS[name]
        : {};
    if (!Object.keys(fromHandlers).length) {
      return base;
    }
    return Object.assign({}, base, fromHandlers);
  }

  function bindEvents() {
    if (state.bound) return;
    state.bound = true;

    // Capture phase: stop in-app anchors / GCWeb chrome from navigating away
    // or doing a full hash reload before our handlers run.
    document.addEventListener(
      "click",
      (ev) => {
        const t = ev.target && ev.target.closest ? ev.target : null;
        if (!t || !t.closest) return;
        const tab = t.closest(".ctrl-tab[data-anchor]");
        if (tab) {
          ev.preventDefault();
          ev.stopPropagation();
          if (typeof ev.stopImmediatePropagation === "function") ev.stopImmediatePropagation();
          activateTab(tab);
          return;
        }
        const jump = t.closest("[data-scroll-to]");
        if (jump) {
          ev.preventDefault();
          ev.stopPropagation();
          if (typeof ev.stopImmediatePropagation === "function") ev.stopImmediatePropagation();
          scrollToControl(jump.getAttribute("data-scroll-to"));
          try {
            if (location.hash) history.replaceState(null, "", location.pathname + location.search);
          } catch (_) {}
          return;
        }
        const appAnchor = t.closest(
          "#app-root a[href^='#'], a.ctrl-link[href^='#'], a.ctrl--section-anchor[href^='#']"
        );
        if (appAnchor) {
          const href = appAnchor.getAttribute("href") || "";
          const id = href.replace(/^#/, "");
          if (id) {
            ev.preventDefault();
            ev.stopPropagation();
            if (typeof ev.stopImmediatePropagation === "function") ev.stopImmediatePropagation();
            if (!scrollToControl(id) && document.getElementById(id)) {
              document.getElementById(id).scrollIntoView({ behavior: "smooth", block: "start" });
            }
            try {
              history.replaceState(null, "", location.pathname + location.search);
            } catch (_) {}
          }
        }
      },
      true
    );

    // GCWeb search in the WET shell must not leave the converted app.
    document.querySelectorAll('#wb-srch form, form[name="cse-search-box"]').forEach((form) => {
      form.addEventListener("submit", (ev) => {
        ev.preventDefault();
      });
    });
    document.querySelectorAll("#wb-lng a[href='#'], #wb-lng a[href='']").forEach((a) => {
      a.addEventListener("click", (ev) => ev.preventDefault());
    });

    document.addEventListener("click", async (ev) => {
      // Never intercept native form controls — first click must open/focus them.
      if (
        ev.target &&
        ev.target.closest &&
        ev.target.closest(
          'select, option, input, textarea, label.ctrl--checkbox, label.ctrl-radio-option, .ctrl--radio, .ctrl-rte-body'
        )
      ) {
        return;
      }

      const rteBtn = ev.target.closest("[data-rte-cmd]");
      if (rteBtn) {
        ev.preventDefault();
        const cmd = rteBtn.getAttribute("data-rte-cmd");
        const host = rteBtn.closest('[data-role="richtext"]');
        const body = host && host.querySelector(".ctrl-rte-body");
        if (body && cmd) {
          body.focus();
          if (cmd === "createLink") {
            const url = window.prompt("URL", "https://");
            if (url) document.execCommand(cmd, false, url);
          } else {
            document.execCommand(cmd, false, null);
          }
        }
        return;
      }

      const infoBtn = ev.target.closest(".ctrl--info-btn, [data-info-fx]");
      if (infoBtn) {
        ev.preventDefault();
        const fx = infoBtn.getAttribute("data-info-fx") || "";
        let msg = "";
        if (/fnEmailTokenGuide/i.test(fx) && ir.collections && Array.isArray(ir.collections.fnEmailTokens)) {
          msg = ir.collections.fnEmailTokens
            .map((r) => "{" + (r.Key || "") + "} — " + (r.Label || ""))
            .join("\n");
        } else if (fx) {
          try {
            msg = String(evalExpr(fx) || "");
          } catch (_) {
            msg = "";
          }
        }
        if (msg) {
          window.alert(msg);
        }
        return;
      }

      const nav = ev.target.closest("[data-navigate]");
      if (nav) {
        ev.preventDefault();
        const dest = nav.getAttribute("data-navigate");
        if (dest) {
          showScreen(dest);
          applyVisibility();
        }
        return;
      }

      const jump = ev.target.closest("[data-scroll-to]");
      if (jump) {
        ev.preventDefault();
        scrollToControl(jump.getAttribute("data-scroll-to"));
        return;
      }

      const tab = ev.target.closest(".ctrl-tab[data-anchor]");
      if (tab) {
        ev.preventDefault();
        activateTab(tab);
        return;
      }

      const attachBtn = ev.target.closest(".ctrl-attach-btn");
      if (attachBtn) {
        ev.preventDefault();
        return;
      }

      const pill = ev.target.closest("[data-gallery-item]");
      if (pill) {
        ev.preventDefault();
        const galleryName = pill.getAttribute("data-gallery") || "AppSettingsGallery";
        const value = pill.getAttribute("data-gallery-item") || "";
        const gallery = pill.closest("[data-control='" + galleryName + "']") || pill.parentElement;
        if (gallery) {
          gallery.setAttribute("data-selected", value);
          gallery.querySelectorAll("[data-gallery-item]").forEach((btn) => {
            btn.classList.toggle("is-selected", btn === pill);
          });
        }
        state.values[galleryName + ".Selected"] = value;
        state.values[galleryName + ".Selected.Value"] = value;
        state.values[galleryName + ".Value"] = value;
        const props = controlProps(pill.getAttribute("data-control") || "Button11");
        if (props.OnSelect) {
          const prevSelf = state.context.__selfName;
          state.context.__selfName = pill.getAttribute("data-control") || "Button11";
          state.context.ThisItem = { Value: value, Name: value };
          try {
            await invokeHandler(props.OnSelect);
          } finally {
            state.context.__selfName = prevSelf;
            delete state.context.ThisItem;
          }
        } else {
          applyVisibility();
        }
        return;
      }

      const galleryRow = ev.target.closest(".lookup-row, .gallery-row");
      if (galleryRow) {
        const item = thisItemFromRow(galleryRow);
        const gal = galleryRow.closest('[data-role="gallery"], [data-control="RequestsGallery"], [data-control="LookupGallery"]');
        const gname = gal && gal.getAttribute("data-control");
        const gprops = gname ? controlProps(gname) : {};
        if (item && gprops.OnSelect) {
          const prevSelf = state.context.__selfName;
          const prevItem = state.context.ThisItem;
          const prevScope = state.context.__rowScope;
          state.context.__selfName = gname;
          state.context.ThisItem = item;
          state.context.__rowScope = galleryRow;
          try {
            ev.preventDefault();
            await invokeHandler(gprops.OnSelect);
          } finally {
            state.context.__selfName = prevSelf;
            state.context.__rowScope = prevScope;
            if (prevItem === undefined) delete state.context.ThisItem;
            else state.context.ThisItem = prevItem;
          }
          return;
        }
      }

      const el = ev.target.closest("[data-control]");
      if (!el) return;
      if (
        el.tagName === "INPUT" ||
        el.tagName === "SELECT" ||
        el.tagName === "TEXTAREA" ||
        el.classList.contains("ctrl--checkbox") ||
        el.classList.contains("ctrl--radio")
      ) {
        return;
      }
      let name = el.dataset.control;
      // Settings label sits under a transparent hit-target button; accept either.
      if (
        name === "SettingsLabel" ||
        name === "SettingsCont" ||
        name === "SettingsAndHelpCont"
      ) {
        name = "SettingsButton";
      }
      const props = controlProps(name);
      if (!props.OnSelect) return;
      ev.preventDefault();
      const row = el.closest(".ctrl-user-row, .ctrl-acknowledger-row");
      const item = thisItemFromRow(row);
      const prevSelf = state.context.__selfName;
      const prevItem = state.context.ThisItem;
      const prevRowScope = state.context.__rowScope;
      state.context.__selfName = name;
      if (item) state.context.ThisItem = item;
      if (row) {
        state.context.__rowScope = row;
        // Prefer the row's Acknowledger input for Trim(Acknowledger.Text).
        const ack = row.querySelector('[data-control="Acknowledger"]');
        if (ack) {
          state.values["Acknowledger.Text"] = ack.value;
          state.values["Acknowledger.Value"] = ack.value;
        }
      }
      try {
        await invokeHandler(props.OnSelect);
      } finally {
        state.context.__selfName = prevSelf;
        if (prevItem === undefined) delete state.context.ThisItem;
        else state.context.ThisItem = prevItem;
        if (prevRowScope === undefined) delete state.context.__rowScope;
        else state.context.__rowScope = prevRowScope;
      }
    });

    document.addEventListener("change", async (ev) => {
      const t = ev.target;
      if (!t || !t.closest) return;

      if (t.matches && t.matches('input[type="radio"]')) {
        const host = t.closest("[data-control]");
        const name = host && host.dataset.control;
        if (name) {
          state.values[name + ".Selected"] = t.value;
          state.values[name + ".Selected.Value"] = t.value;
          state.values[name + ".Value"] = t.value;
          if (name === "ThemeRadio") {
            const dark = t.value === "Dark";
            setVar("gblDarkMode", dark);
            persistThemePreference(dark ? "dark" : "light");
            applyDarkMode();
          }
          const props = controlProps(name);
          if (props.OnChange) {
            const prevSelf = state.context.__selfName;
            state.context.__selfName = name;
            try {
              await invokeHandler(props.OnChange);
            } finally {
              state.context.__selfName = prevSelf;
            }
          } else {
            applyVisibility();
          }
          if (name === "ThemeRadio") {
            applyDarkMode();
          }
        }
        return;
      }

      if (t.matches && t.matches('input[type="checkbox"]')) {
        const host = t.closest("[data-control]") || t;
        const name = host.dataset.control || t.dataset.control;
        if (name) {
          state.values[name + ".Value"] = !!t.checked;
          const props = controlProps(name);
          if (props.OnCheck || props.OnUncheck || props.OnChange || props.OnSelect) {
            const fx =
              (t.checked ? props.OnCheck : props.OnUncheck) ||
              props.OnChange ||
              props.OnSelect;
            if (fx) await invokeHandler(fx);
          }
          applyVisibility();
        }
        return;
      }

      const host = t.closest("[data-control]");
      if (!host) return;
      const name = host.dataset.control;
      if (!name) return;
      if (t.tagName === "INPUT" || t.tagName === "SELECT" || t.tagName === "TEXTAREA") {
        if (t.tagName === "SELECT") {
          bindDropdownValue(name, t.value, t);
        } else {
          state.values[name + ".Text"] = t.value;
          state.values[name + ".Selected"] = t.value;
          state.values[name + ".Selected.Value"] = t.value;
          if (t.type === "date") {
            state.values[name + ".SelectedDate"] = t.value;
          }
        }
        // UserSearchCombo: IR OnChange = Set(varUserSearchPick, Self.Selected)
        // Selected is a record with Mail (DisplayFields = ["Mail"]).
        if (name === "UserSearchCombo" || name === "ReviewerComboboxInput" || host.getAttribute("data-combo") === "user-search") {
          const selected = t.value ? { Mail: t.value, DisplayName: t.value } : null;
          state.values[name + ".Selected"] = selected;
          state.values[name + ".Selected.Mail"] = t.value;
          state.values[name + ".SelectedItems"] = selected ? [selected] : [];
          if (name === "UserSearchCombo") {
            setVar("varUserSearchPick", selected);
          }
        }
        const row = host.closest(".ctrl-user-row, .ctrl-acknowledger-row");
        const item = thisItemFromRow(row);
        if (row && name === "Dropdown3") {
          row.setAttribute("data-role-value", t.value);
        }
        const props = controlProps(name);
        if (props.OnChange) {
          const prevSelf = state.context.__selfName;
          const prevItem = state.context.ThisItem;
          state.context.__selfName = name;
          if (item) state.context.ThisItem = item;
          try {
            await invokeHandler(props.OnChange);
          } finally {
            state.context.__selfName = prevSelf;
            if (prevItem === undefined) delete state.context.ThisItem;
            else state.context.ThisItem = prevItem;
          }
        }
        // L1 ↔ L1Acro stay paired (Reset(L1Acro)/Reset(L1) is commented in IR but
        // varL1SelectedID is set; mirror that onto both dropdowns). Ireland OnChange
        // Sets vars + Reset — re-sync so both show Ireland/IRL before lock paint.
        if (name === "L1" || name === "L1Acro" || (name === "Destination" && isIrelandDestination())) {
          syncL1PairFromVars();
        }
        // Re-eval DisplayMode / Visible / validation after every change
        // (e.g. Destination=Ireland locks L1; checkbox clears red border).
        applyVisibility();
      }
    });

    // Live SearchText for UserSearchCombo / ReviewerComboboxInput.
    document.addEventListener("input", (ev) => {
      const t = ev.target;
      if (!t || !t.getAttribute) return;
      const filterHost = t.closest && t.closest("[data-control]");
      const filterName = filterHost && filterHost.dataset.control;
      if (filterName && /Filter$/i.test(filterName)) {
        state.values[filterName + ".Text"] = t.value;
        try {
          hydrateLookupGallery();
        } catch (_) {}
        return;
      }
      if (t.getAttribute("data-combo") !== "user-search") {
        return;
      }
      const name = t.getAttribute("data-control") || "UserSearchCombo";
      const selected = t.value ? { Mail: t.value, DisplayName: t.value } : null;
      state.values[name + ".Text"] = t.value;
      state.values[name + ".SearchText"] = t.value;
      state.values[name + ".Selected"] = selected;
      state.values[name + ".Selected.Mail"] = t.value || "";
      state.values[name + ".SelectedItems"] = selected ? [selected] : [];
      if (name === "UserSearchCombo") {
        setVar("varUserSearchPick", selected);
      }
      applyVisibility();
    });
  }

  async function loadCollections() {
    if (ir.collections && typeof ir.collections === "object" && Object.keys(ir.collections).length) {
      return;
    }
    try {
      const res = await fetch(packageUrl("/assets/collections.json"));
      if (res.ok) {
        const data = await res.json();
        if (data && typeof data === "object") {
          ir.collections = data;
        }
      }
    } catch (_) {}
  }

  async function loadIr() {
    if (ir && ir.screens && ir.screens.length) {
      await loadCollections();
      return;
    }
    // Prefer generated app-logic (maintainable package output) over the archive IR.
    if (typeof window !== "undefined" && window.PS_APP && (Array.isArray(window.PS_APP.screens) || window.PS_APP.controls)) {
      ir = window.PS_APP;
      await loadCollections();
      return;
    }
    try {
      const logic = await fetch(packageUrl("/assets/app-logic.js"));
      // app-logic.js assigns window.PS_APP when evaluated as a script tag; also
      // accept a JSON twin for hosts that strip .js execution.
    } catch (_) {}
    if (typeof window !== "undefined" && window.PS_APP && (Array.isArray(window.PS_APP.screens) || window.PS_APP.controls)) {
      ir = window.PS_APP;
      await loadCollections();
      return;
    }
    try {
      const res = await fetch(packageUrl("/assets/app-logic.json"));
      if (res.ok) {
        ir = await res.json();
        await loadCollections();
        return;
      }
    } catch (_) {}
    // Intentionally do not load import/power_sweeper_ir.json — that archive is
    // Import→.msapp only. Maintainers edit HTML/CSS, handlers/*.js, server/generated, data/.
  }

  function pinChrome() {
    document.querySelectorAll(".screen").forEach((el) => {
      el.scrollTop = 0;
      el.scrollLeft = 0;
    });
    const rootEl = document.getElementById("app-root");
    if (rootEl) {
      rootEl.scrollTop = 0;
      rootEl.scrollLeft = 0;
    }
    if (document.documentElement) document.documentElement.scrollTop = 0;
    if (document.body) document.body.scrollTop = 0;
    window.scrollTo(0, 0);
  }

  function pinFormTop() {
    document.querySelectorAll("[data-control='ScrollableForm']").forEach((el) => {
      el.scrollTop = 0;
      el.scrollLeft = 0;
    });
    pinChrome();
  }

  async function hydrateKnownTables() {
    const names = [];
    for (const t of tablesOf()) {
      const name = t && (t.name || t.safe_name);
      if (isPlausibleTable(name)) names.push(name);
    }
    Object.keys(TABLE_ALIASES).forEach((n) => names.push(n));
    const seen = new Set();
    for (let i = 0; i < names.length; i++) {
      const name = String(names[i]);
      if (seen.has(name)) continue;
      seen.add(name);
      try {
        await hydrateTable(name);
      } catch (_) {}
    }
  }

  function mergeHandlersIntoIndex() {
    const bag =
      typeof window !== "undefined" && window.PS_HANDLERS && typeof window.PS_HANDLERS === "object"
        ? window.PS_HANDLERS
        : null;
    if (!bag) return;
    Object.keys(bag).forEach((name) => {
      const events = bag[name];
      if (!events || typeof events !== "object") return;
      if (!state.controlIndex[name]) {
        state.controlIndex[name] = {
          node: { name: name, properties: {} },
          screen: null,
        };
      }
      const node = state.controlIndex[name].node || (state.controlIndex[name].node = { name: name, properties: {} });
      node.properties = Object.assign({}, node.properties || {}, events);
    });
  }

  function loadScript(src) {
    return new Promise((resolve) => {
      try {
        const existing = document.querySelector('script[src="' + src.replace(/"/g, '\\"') + '"]');
        if (existing) {
          resolve(true);
          return;
        }
        const s = document.createElement("script");
        s.src = src;
        s.async = false;
        s.onload = () => resolve(true);
        s.onerror = () => resolve(false);
        (document.head || document.documentElement).appendChild(s);
      } catch (_) {
        resolve(false);
      }
    });
  }

  function assetCacheBust() {
    try {
      const scripts = document.scripts || [];
      for (let i = 0; i < scripts.length; i++) {
        const src = scripts[i].src || "";
        if (!/app-logic\.js|handlers\.js|runtime\.js/.test(src)) continue;
        const m = src.match(/[?&]v=([^&]+)/);
        if (m) return m[1];
      }
    } catch (_) {}
    return "";
  }

  async function loadHandlerModules() {
    if (typeof window === "undefined") return;
    window.PS_HANDLERS = window.PS_HANDLERS || {};
    const screens = Array.isArray(window.PS_HANDLER_SCREENS) ? window.PS_HANDLER_SCREENS : [];
    // Already populated (legacy monolithic handlers.js) — skip fetches.
    if (!screens.length && Object.keys(window.PS_HANDLERS).length) {
      return;
    }
    const bust = assetCacheBust();
    const q = bust ? "?v=" + encodeURIComponent(bust) : "";
    for (let i = 0; i < screens.length; i++) {
      const stem = String(screens[i] || "").replace(/[^\w.-]+/g, "");
      if (!stem) continue;
      await loadScript(packageUrl("/assets/handlers/" + stem + ".js") + q);
    }
  }

  async function boot() {
    await loadIr();
    await loadHandlerModules();
    ir = expandComponentsIntoScreens(ir);
    const list = screens();
    state.controlIndex = indexControls(list);
    mergeHandlersIntoIndex();
    if (!state.screen) {
      const fromDom =
        (typeof document !== "undefined" &&
          document.getElementById("app-root") &&
          document.getElementById("app-root").dataset.startScreen) ||
        "";
      state.screen = fromDom || (list[0] ? list[0].name : null);
    }
    // Power Apps has connector rows cached before OnStart; hydrate first so
    // refreshAppsSettings / loadAppUsers LookUp App Settings + App Users.
    try {
      await hydrateKnownTables();
    } catch (_) {}
    const app = appOf();
    if (app && app.properties && app.properties.OnStart) {
      try {
        await runFormula(app.properties.OnStart);
      } catch (_) {}
    }
    // LoadAppSettings OnSelect (not App.OnStart) runs refreshAppsSettings so
    // colMessages / varTempUser come from App Settings + App Users.
    try {
      const settingsFx =
        findComponentFormula("ExternalFunctions", "refreshAppsSettings") ||
        findComponentFormula("comExternalFunctions", "refreshAppsSettings");
      if (settingsFx) {
        await runFormula(settingsFx);
      }
    } catch (_) {}
    // refreshAppsSettings With body can skip ClearCollect when a later
    // statement throws; apply the same IR ForAll/LookUp that LoadAppSettings uses.
    try {
      const fromStore = evalExpr(
        "ForAll(Table(ParseJSON(LookUp('CDLS (L) App Settings', Title = \"1\").Messages)), { email: Text(ThisRecord.Value.email), content: Text(ThisRecord.Value.content) })"
      );
      if (Array.isArray(fromStore) && fromStore.some((row) => row && row.email)) {
        const have = new Set();
        const merged = [];
        fromStore.forEach((row) => {
          const key = row && row.email ? String(row.email) : "";
          if (!key || have.has(key)) return;
          have.add(key);
          merged.push(row);
        });
        const existing = getVar("varTempMessages");
        if (Array.isArray(existing)) {
          existing.forEach((row) => {
            const key = row && row.email ? String(row.email) : "";
            if (!key || have.has(key)) return;
            have.add(key);
            merged.push(row);
          });
        }
        setVar("varTempMessages", merged);
        setVar("colMessages", merged.slice());
      }
      const me = evalExpr(
        "LookUp(varTempUser, fnEmailLocal(Text(user)) = fnEmailLocal(User().Email))"
      );
      if (me && typeof me === "object") {
        setVar("varCurrentUser", me);
      }
    } catch (_) {}
    if (state.screen) showScreen(state.screen);
    bindEvents();
    bindFormTabScrollSpy();
    // Idle App Admin Settings selection matches Studio: Help.
    if (!state.values["AppSettingsGallery.Selected.Value"]) {
      state.values["AppSettingsGallery.Selected"] = "Help";
      state.values["AppSettingsGallery.Selected.Value"] = "Help";
    }
    // Seed email templates from IR Collect(varTempMessages, ...) fallbacks (e.g. ack_deny),
    // then hydrate RichTextEditor Defaults via LookUp(varTempMessages, ...).content.
    seedTempMessagesFromIr();
    hydrateRichTextEditors();
    hydrateDefaultInputs();
    hydrateDatePickers();
    syncDropdownValues();
    hydrateFilterControls();
    applyVisibility();
    // After OnStart (which defaults gblDarkMode=false), restore theme from
    // varCurrentUser.preferences.theme / localStorage — same as LoadAppSettings.
    restoreThemePreference();
    // OnStart may overwrite varDevApprover; keep varDefaultApprover for Admin Acknowledgers.
    try {
      const dev = getVar("varDevApprover");
      if (dev && !getVar("varDefaultApprover")) {
        setVar("varDefaultApprover", dev);
      } else if (dev && String(getVar("varDefaultApprover") || "").trim() === "") {
        setVar("varDefaultApprover", dev);
      }
      hydrateDefaultInputs();
    } catch (_) {}
    applyDarkMode();
    pinFormTop();
    requestAnimationFrame(pinFormTop);
    setTimeout(pinFormTop, 0);
    setTimeout(pinFormTop, 50);
    setTimeout(bindFormTabScrollSpy, 60);
    // Re-assert ThemeRadio checked state after Settings chrome paints.
    setTimeout(applyDarkMode, 80);
    try {
      await hydrateKnownTables();
    } catch (_) {}
    try {
      hydrateLookupGallery();
    } catch (_) {}
    try {
      hydrateFormGalleries();
    } catch (_) {}
  }

  window.PowerSweeperRuntime = {
    evalExpr,
    runFormula,
    showScreen,
    scrollToControl,
    getState: () => state,
    apiData,
    namedFormula,
    hydrateLookupGallery,
    fnEmailToken,
    fnEmailTokenOld,
    fnApplyEmailTokens,
    applyEmailTokens,
    emailValues,
    EMAIL_TOKEN_KEYS,
    isQuotedString,
    bindDropdownValue,
    parseRecordLiteral,
    mergePatchChangeRecords,
    fxEquals,
    enrichTrackingFields,
    normalizeTrackingRow,
    hydrateFilterControls,
    applyDarkMode,
    applySettingsChrome,
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      boot();
    });
  } else {
    boot();
  }
})();
