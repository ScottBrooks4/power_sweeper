(function () {
  const V = window.VASC;
  const DIRECTORY = "https://018gc.sharepoint.com.mcas.ms/sites/ORG-9872-0002/SitePages/VCDS-ASC-App-Directory.aspx";
  const MAKER = "https://make.powerapps.com/environments/9ed70000-7006-e3b7-a4b4-c31cb53e5df0/apps";
  const MAKER_2 = "https://make.powerapps.com/environments/4f7cb393-36fb-efba-94fb-def26e69c3f5/apps";
  const APPS = [
    { group: "directory", name: "In&Out Clearance CFB (O-G)", fr: "BFC (O-G) Autorisation d'entrée et de sortie", status: "ND-DN", client: "CFB (O-G)", support: "", updated: "2025-04-04", summary: "An application that enables the users to digitally complete the In and Out clearance process.", cases: ["In/Out Clearance App"] },
    { group: "directory", name: "In&Out Clearance VCDS HQ", fr: "QG VCEMD Autorisation d'entrée et de sortie", status: "ND-DN", client: "VCDS HQ", support: "", updated: "", summary: "A digital version of the In and Out Clearance process.", cases: ["In/Out Clearance App"] },
    { group: "directory", name: "Golf App", fr: "Application Golf", status: "ND-DN", client: "VCDS HQ", support: "VCDS HQ", updated: "2025-07-21", summary: "Created for a golf tournament, to collect which teams would be participating and any necessary information.", cases: ["Golf Management App"] },
    { group: "directory", name: "Softball App", fr: "Application Softball", status: "ND-DN", client: "VCDS HQ", support: "VCDS ASC", updated: "2025-06-25", summary: "Supports a softball tournament. Users see scores and overall standings. Admins set the score for each game.", cases: ["Softball Tournament"] },
    { group: "directory", name: "Curling App", fr: "Application Curling", status: "ND-DN", client: "VCDS HQ", support: "VCDS HQ VASC", updated: "2025-02-26", summary: "Created for the Curling Funspiel. Users view team stats, individual games, and the tournament. Admins enter scores.", cases: [] },
    { group: "directory", name: "DIY Tech Library", fr: "Bibliothèque techno bricolage", status: "ND-DN", client: "VCDS HQ VASC", support: "VCDS HQ VASC", updated: "2024-04-15", summary: "Check books in and out of the library, and see the list of books currently in it.", cases: ["Library Loan App"] },
    { group: "directory", name: "VCDS L2 Network Directory", fr: "VCEMD L2 Annuaire des Réseaux", status: "ND-DN", client: "VCDS Gp", support: "VCDS HQ", updated: "2025-08-12", summary: "A contact list across the VCDS Group. Find colleagues by L2 and community of practice, or add a contact.", cases: [] },
    { group: "directory", name: "Pulse App", fr: "Application Pulse", status: "UAT", client: "VCDS HQ VASC", support: "VCDS HQ VASC", updated: "2025-11-24", summary: "Tracks leave requests, work-from-home schedules, and upcoming events, with a calendar of the month.", cases: [] },
    { group: "directory", name: "PMed Dashboard", fr: "", status: "ND-DN", client: "", support: "", updated: "", summary: "Intake form for CMP forms related to workplace safety assessments.", cases: ["PMed Dashboard"] },
    { group: "directory", name: "Staffing App", fr: "Portail de dotation du VCEMD", status: "Decommissioned", client: "VCDS HQ", support: "VCDS HQ", updated: "2025-06-04", summary: "Create, submit, and approve staffing requests, and look up old and new requests.", cases: ["VCDS Position Staffing Request Tracker"] },
    { group: "directory", name: "VASC Use Case Submission App", fr: "", status: "Prod", client: "VCDS HQ VASC", support: "VCDS HQ VASC", updated: "2025-04-08", summary: "Lets clients submit their use cases.", cases: [] },
    { group: "directory", name: "TDR", fr: "DST", status: "Prod", client: "VCDS HQ", support: "VCDS HQ", updated: "", summary: "The TDR process in Power Apps, for request processing and tracking.", cases: ["Automating / autofilled of TDR process", "DSG TDR"] },
    { group: "directory", name: "Taxi Chit", fr: "", status: "UAT", client: "CFB (O-G)", support: "", updated: "2025-12-05", summary: "The Taxi Chit process in Power Apps, covering the same steps as the previous version.", cases: [] },
    { group: "maker", name: "Taxi Chit (Premium)", fr: "", status: "Canvas", client: "CFB (O-G)", support: "", updated: "", summary: "The premium build of the Taxi Chit process. Environment ending 5df0.", cases: [] },
    { group: "directory", name: "PKPA Parking App", fr: "Application de stationnement PKPA", status: "UAT", client: "VCDS HQ", support: "VCDS HQ VASC", updated: "", summary: "Parking permit requests and allocation for NDHQ parking.", cases: ["PKPA NDHQ Parking App Enhancements"] },
    { group: "directory", name: "Hermes", fr: "", status: "UAT", client: "CDLS (London)", support: "", updated: "2025-12-02", summary: "Upload, approve, and digitally sign documents.", cases: [] },
    { group: "directory", name: "H&R App", fr: "", status: "UAT", client: "VCDS HQ", support: "VCDS HQ", updated: "2025-10-28", summary: "Create and submit a nomination for honours and recognition.", cases: ["VCDS Honours and Recognition"] },
    { group: "directory", name: "CRes Knowledge Management App", fr: "", status: "Dev", client: "C Res", support: "", updated: "2025-09-19", summary: "Enter research documents so they can be summarized, including a summary of the summaries already entered.", cases: [] },
    { group: "directory", name: "MAP App", fr: "", status: "ND-DN", client: "VCDS HQ", support: "VCDS HQ", updated: "2025-09-02", summary: "Create and update MAP items.", cases: ["MAP Reporting App"] },
    { group: "directory", name: "CFMP Incident Intake", fr: "", status: "UAT", client: "CFMP", support: "", updated: "2025-08-12", summary: "A digital interface for the Criminal Code sexual offences tracker.", cases: ["Development of a Security Incident Reporting Platform"] },
    { group: "directory", name: "Training Request & Approval", fr: "", status: "UAT", client: "DGDS", support: "", updated: "", summary: "Streamlines getting approval for training.", cases: ["Training Request Automation"] },
    { group: "directory", name: "Business Case Analysis Digitalization App", fr: "", status: "UAT", client: "CFD", support: "", updated: "2025-07-17", summary: "A digital BCA template. Create the business case in the app and save it as a PDF.", cases: ["BCA Digitalization"] },
    { group: "directory", name: "EC Request Form App", fr: "", status: "UAT", client: "CPROG (DDSPI)", support: "", updated: "", summary: "Fill out the EC request in the app. Approvals go to the people required by the way the form is filled out.", cases: ["EC Request Form"] },

    { group: "maker", name: "Nights Watch In - Clearance App", fr: "", status: "Canvas", client: "", support: "", updated: "", summary: "In the Power Apps maker list. Related to in-clearance. Environment ending 5df0.", cases: ["In/Out Clearance App"] },
    { group: "maker", name: "TDR - THCEE Directory App", fr: "", status: "Canvas", client: "", support: "", updated: "", summary: "In the Power Apps maker list. A directory app, separate from the TDR process app. Environment ending 5df0.", cases: [] },
    { group: "maker", name: "VCDS ASC — The SATE Catalog", fr: "", status: "Canvas", client: "", support: "", updated: "", summary: "In both Power Apps maker lists.", cases: ["SATE Report"] },
    { group: "maker", name: "Student Mentorship Program Tracking & Reporting", fr: "", status: "Canvas", client: "", support: "", updated: "", summary: "In the Power Apps maker list. Environment ending 5df0.", cases: ["Student Mentorship Program Tracking & Reporting"] },
    { group: "maker", name: "VCDS SITREP Reporting", fr: "", status: "Canvas", client: "", support: "", updated: "", summary: "In the Power Apps maker list. Environment ending 5df0.", cases: ["Digital Executive Reporting and SITREP Management Platform"] },
    { group: "maker", name: "VCDS - Virtual Hallway Chats Program", fr: "", status: "Canvas", client: "", support: "", updated: "", summary: "In the Power Apps maker list, and a separate one in UAT. Environment ending 5df0.", cases: ["Formation Europe Virtual Hallway Chats Program"] },
    { group: "maker", name: "VCDS ASC — PAT - Procurement Authorization & Tracking", fr: "", status: "Canvas", client: "", support: "", updated: "", summary: "In both Power Apps maker lists.", cases: [] },
    { group: "maker", name: "DND — EHCR", fr: "", status: "Canvas", client: "", support: "", updated: "", summary: "In the Power Apps maker list. Environment ending 5df0.", cases: [] },
    { group: "maker", name: "DND Student Talent Hub", fr: "", status: "Canvas", client: "", support: "", updated: "", summary: "In the Power Apps maker list. Environment ending 5df0.", cases: [] },
    { group: "maker", name: "European Assessment Test App", fr: "", status: "Model-driven", client: "", support: "", updated: "", summary: "In the Power Apps maker list. Environment ending 5df0.", cases: [] },
    { group: "maker", name: "CDLS (L) VCR App", fr: "", status: "Canvas", client: "CDLS (London)", support: "", updated: "", summary: "In the Power Apps maker list. Environment ending 5df0. The other environment has dated ones from 2026-08-10 and 2026-08-18.", cases: [] },
    { group: "maker", name: "Document Automation Application", fr: "", status: "Canvas", client: "", support: "", updated: "", summary: "In the Power Apps maker list. Environment ending 5df0.", cases: [] },
    { group: "maker", name: "VCDS Task Tracking", fr: "", status: "Canvas", client: "", support: "", updated: "", summary: "In the Power Apps maker list. Environment ending c3f5.", cases: ["VCDS Task Tracking"], url: MAKER_2 }
  ];

  function titlesMatch(a, b) {
    a = String(a || "").trim();
    b = String(b || "").trim();
    if (!a || !b) return false;
    if (a.toLowerCase() === b.toLowerCase()) return true;
    const short = a.length <= b.length ? a : b;
    const long = short === a ? b : a;
    return new RegExp("\\b" + short.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "\\b", "i").test(long);
  }

  function microsoftTool(name) {
    return /power platform|solution health|power pages management|copilot studio|power cat|creator kit|center of excellence|coe starter|innovation backlog|alm accelerator|business process manager|environment settings/i.test(String(name || ""));
  }

  function playable(url) {
    return /^https:\/\/apps\.powerapps\.com\/play\/e\/[^/]+\/a\/[0-9a-f-]{36}/i.test(String(url || ""));
  }

  function laneFromStatus(status) {
    return /^(Prod|UAT|ND-DN|Dev)$/.test(String(status || "")) ? String(status) : "";
  }

  function cleanAddress(url) {
    try {
      const parsed = new URL(url);
      parsed.searchParams.delete("sourcetime");
      parsed.searchParams.delete("source");
      return parsed.toString();
    } catch (error) {
      return url;
    }
  }

  const ENV_LINKS = [
    ["EDP_VCDS_Main_Dev", "https://make.powerapps.com/environments/9ed70000-7006-e3b7-a4b4-c31cb53e5df0"],
    ["EDP_VCDS_Main_UAT", "https://make.powerapps.com/environments/4f7cb393-36fb-efba-94fb-def26e69c3f5"],
    ["VCDS_TeamProductivity", "https://make.powerapps.com/environments/510961b1-a339-ed63-a313-2a1d6437d0f3"],
    ["ND-DN (default)", "https://make.powerapps.com/environments/Default-325b4494-1587-40d5-bb31-8b660b7f1038"]
  ];
  const KNOWN = [
    ["CDLS (L) VCR App", "https://apps.powerapps.com/play/e/510961b1-a339-ed63-a313-2a1d6437d0f3/a/24ec1f53-588a-4729-acbe-e3159146922c?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=44f7afad-754a-4a9c-9bc7-0ac827b21470"],
    ["VCDS ASC - App Directory", "https://apps.powerapps.com/play/e/510961b1-a339-ed63-a313-2a1d6437d0f3/a/0fbf4a4e-bd30-4af0-acf1-3ec79a8a3841?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=a8ec1d9b-f8d5-4de8-8e3e-ffd13a345a4f"],
    ["VCDS - Virtual Hallway Chats Program", "https://apps.powerapps.com/play/e/510961b1-a339-ed63-a313-2a1d6437d0f3/a/3c7a2148-2de1-49f2-a61e-8df963676af5?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=047844b2-35be-4742-896a-ad58b3d73731"],
    ["TDR", "https://apps.powerapps.com/play/e/510961b1-a339-ed63-a313-2a1d6437d0f3/a/a91980b1-09d7-4868-a234-107ceb3b27c1?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=02fb340c-9cd2-43ab-90a1-90b81597dd86"],
    ["Pulse App", "https://apps.powerapps.com/play/e/4f7cb393-36fb-efba-94fb-def26e69c3f5/a/bd81d09b-1454-48bd-a08f-ffb0f34f13a3?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=cd17b815-aa40-452c-a181-d7e863f209a7"],
    ["VCDS Task Tracking", "https://apps.powerapps.com/play/e/4f7cb393-36fb-efba-94fb-def26e69c3f5/a/2d5966d1-b6c7-4364-b614-2534c13da117?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=aa68e3ba-feac-44f2-8dd4-799674cb1104"],
    ["VCDS ASC — PAT - Procurement Authorization & Tracking", "https://apps.powerapps.com/play/e/4f7cb393-36fb-efba-94fb-def26e69c3f5/a/31edd544-aecb-4465-bb81-79ff27b30ef4?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=b3e090eb-86df-45df-b706-9d0c6ae70c3c", "PAT - Procurement Authorization & Tracking"],
    ["VCDS ASC — The SATE Catalog", "https://apps.powerapps.com/play/e/4f7cb393-36fb-efba-94fb-def26e69c3f5/a/c27a4665-8cf5-4ec3-bc6f-a4f998b7849b?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=56b92a68-b9b8-4ee3-8c3e-784a847df2a0", "The SATE Catalog"],
    ["Service Dog App", ""],
    ["VCDS L2 Network Directory", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/cf112ffa-51c8-4999-a5ee-4dd8b9389ce1?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=75dfee15-78c4-4c1a-bbdb-c3479944a2cf"],
    ["In&Out Clearance CFB (O-G)", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/1142b92b-bc5b-4780-ab6d-a00f951c83a8?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=4dcbeda4-78c4-4ad2-be45-3e3bb5cada7e"],
    ["DEPSEC Activity Tracker", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/930fa82e-ad6e-4624-83bf-da5a4e7726a7?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=9af5e303-6527-4ae9-ad1e-7075846b2671"],
    ["Golf Score Input App", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/838757bf-2284-4926-8dc3-67a5e33e6257?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=6468f737-cfdb-4406-97f5-85f5ad95e695"],
    ["VASC Use Case Submission App", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/34b7c7c0-0dbe-4b17-aa45-513ecf1fe108?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038"],
    ["DIY Tech Library", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/9e072420-fe07-4607-bbce-6667900054f3?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038"],
    ["Curling App", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/3aa767b1-e160-4521-ad9f-6fd2620364ac?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038"],
    ["DEPSEC SSR 1 Activity Tracker", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/e01ca03b-0ada-4fc8-b50e-bcf5bd233b6e?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=40b91651-7170-4e0c-b756-9e4de836a7b6"],
    ["VCDS Gp HQ - Library App", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/15c7bf37-f4e4-47e6-aac7-cc233b01123d?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=88698589-9b91-4dba-97f3-b0dad333fe11"],
    ["Booking 1on1", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/83b69764-74b3-48c7-9d4e-757172364a14?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=390651ff-d81d-4c89-9ee1-f52192aef25c"],
    ["MobileInventory", "https://make.powerapps.com/e/Default-325b4494-1587-40d5-bb31-8b660b7f1038/canvas/?action=edit&app-id=%2Fproviders%2FMicrosoft.PowerApps%2Fapps%2F359e768e-540d-4a78-9b75-327dadeedf96"],
    ["Softball App", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/8573c343-b666-4bd3-a0fb-6767409dd667?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=44bad6ae-5f60-46fd-8dd5-d076e8f606c5"],
    ["Golf App", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/f3140fe5-55ae-4558-9254-cc31a22ed9dc?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=521a974d-df72-4bbf-8755-52399b71e96b"],
    ["Hermes", "https://apps.powerapps.com/play/e/4f7cb393-36fb-efba-94fb-def26e69c3f5/a/227f7b8e-82f9-4ac7-9c84-3503f80428c6?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=78eb154e-1004-48d5-92ce-ed1e913ecdb8"],
    ["Taxi Chit", "https://apps.powerapps.com/play/e/4f7cb393-36fb-efba-94fb-def26e69c3f5/a/4977a99f-e5e7-4a09-b687-b24988b48455?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=452e85b3-9335-42f2-b20d-dba6ac2e6304"],
    ["Taxi Chit (Premium)", "https://apps.powerapps.com/play/e/9ed70000-7006-e3b7-a4b4-c31cb53e5df0/a/e50892d3-e756-4618-997f-114df67d12ec?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=36e6b691-35b4-449a-908e-c61b3d7c78cb"],
    ["PKPA Parking App", "https://apps.powerapps.com/play/e/9ed70000-7006-e3b7-a4b4-c31cb53e5df0/a/64d2757c-3e83-4e79-babb-0d6ef5f9f40e?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=02ebcf1c-a341-4e53-a562-15c248ae92ef"],
    ["Business Case Analysis Digitalization App", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/94b93fae-4875-43ae-be62-71d71029eb36?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=69be3794-70f8-4c44-9a1d-e6978850d7ae"],
    ["H&R App", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/e2dbb5ee-85ab-4970-8e3d-0c3cfbb7f8f5?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038"],
    ["MAP App", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/8584f94d-e7e9-4c2b-bcbc-1c77c745cfbb?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=6173a9df-6c4d-4096-9bb1-4daf401bd4b0"],
    ["CFMP Incident Intake", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/af145760-d2c1-48b7-ae3b-12edccb419de?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=eed15db8-0673-437c-9a5f-44d4d1e65915"],
    ["Staffing App", "https://apps.powerapps.com/play/e/default-325b4494-1587-40d5-bb31-8b660b7f1038/a/6490cc4a-2dfa-47ef-a403-6d231af8dcbc?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038"],
    ["CRes Knowledge Management App", "https://apps.powerapps.com/play/e/9ed70000-7006-e3b7-a4b4-c31cb53e5df0/a/bb1dc606-6cdb-438e-aa62-0143c6c04e17?tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&hint=0445db7e-1039-4eff-8bac-64070034cd50"],
    ["PMed Dashboard", ""]
  ];

  const THUMBS = {
    "In&Out Clearance CFB (O-G)": "assets/app-thumbs/inout-cfb.jpg?v=20261031q",
    "Golf App": "assets/app-thumbs/golf.jpg?v=20261031q",
    "Softball App": "assets/app-thumbs/softball.jpg?v=20261031q",
    "Curling App": "assets/app-thumbs/curling.jpg?v=20261031q",
    "DIY Tech Library": "assets/app-thumbs/diy-library.jpg?v=20261031q",
    "VCDS L2 Network Directory": "assets/app-thumbs/l2-directory.jpg?v=20261031q",
    "Pulse App": "assets/app-thumbs/pulse.jpg?v=20261031q",
    "Staffing App": "assets/app-thumbs/staffing.jpg?v=20261031q",
    "VASC Use Case Submission App": "assets/app-thumbs/use-case.jpg?v=20261031q",
    "TDR": "assets/app-thumbs/tdr.jpg?v=20261031q",
    "PKPA Parking App": "assets/app-thumbs/pkpa.jpg?v=20261103a",
    "Taxi Chit (Premium)": "assets/app-thumbs/taxi-chit-premium.jpg?v=20261103a",
    "H&R App": "assets/app-thumbs/hr.jpg?v=20261103a",
    "VCDS ASC — The SATE Catalog": "assets/app-thumbs/sate.jpg?v=20261031q",
    "VCDS ASC — PAT - Procurement Authorization & Tracking": "assets/app-thumbs/pat.jpg?v=20261031q",
    "CDLS (L) VCR App": "assets/app-thumbs/vcr.jpg?v=20261031q",
    "VCDS Task Tracking": "assets/app-thumbs/task-tracking.jpg?v=20261031q",
    "VCDS ASC - App Directory": "assets/app-thumbs/app-directory.jpg?v=20261031q",
    "DEPSEC Activity Tracker": "assets/app-thumbs/depsec.jpg?v=20261031q",
    "Golf Score Input App": "assets/app-thumbs/golf-score.jpg?v=20261031q",
    "DEPSEC SSR 1 Activity Tracker": "assets/app-thumbs/depsec-ssr1.jpg?v=20261031q",
    "VCDS Gp HQ - Library App": "assets/app-thumbs/hq-library.jpg?v=20261031q",
    "Booking 1on1": "assets/app-thumbs/booking.jpg?v=20261031q",
    "VCDS - Virtual Hallway Chats Program": "assets/app-thumbs/hallway-home.jpg?v=20261031e"
  };
  window.VASC_APP_THUMBS = THUMBS;
  window.VASC_PLAYABLE = playable;
  const LANES = ["all", "Prod", "UAT", "ND-DN", "Dev"];

  function storedLane() {
    const fromUrl = new URLSearchParams(location.search).get("lane") || "";
    if (LANES.indexOf(fromUrl) >= 0) return fromUrl;
    try {
      const saved = sessionStorage.getItem("vasc-apps-lane") || "";
      if (LANES.indexOf(saved) >= 0) return saved;
    } catch (error) {}
    return "all";
  }

  function laneOf(url) {
    const match = String(url).match(/\/e\/([^/]+)\//i) || String(url).match(/environments\/(?:Default-)?([^/]+)/i) || String(url).match(/\/e\/(Default-[^/]+)\//i);
    const key = match ? match[1].toLowerCase() : "";
    if (key.indexOf("510961b1") === 0) return "Prod";
    if (key.indexOf("4f7cb393") === 0) return "UAT";
    if (key.indexOf("9ed70000") === 0) return "Dev";
    if (key.indexOf("default-325b") === 0 || key.indexOf("325b4494") === 0) return "ND-DN";
    return "";
  }

  function pinKnown() {
    KNOWN.forEach((row) => {
      const name = row[0];
      const alias = row[2] || "";
      const url = row[1] ? cleanAddress(row[1]) : "";
      let app = APPS.find((item) => item.name.toLowerCase() === name.toLowerCase() || (alias && item.name.toLowerCase() === alias.toLowerCase()));
      if (!app) {
        app = { group: "directory", name: alias || name, fr: "", status: "", client: "", support: "", updated: "", summary: "Address supplied for this app.", cases: [] };
        APPS.push(app);
      }
      app.pinned = true;
      if (url && playable(url)) {
        app.url = url;
        app.lane = laneOf(url) || app.lane;
      } else {
        delete app.url;
        if (!app.lane) app.lane = (url && laneOf(url)) || laneFromStatus(app.status);
        if (app.summary === "Address supplied for this app.") app.summary = "";
      }
      if (!url && /service dog/i.test(name)) {
        app.lane = "ND-DN";
        app.summary = "Listed with the ND-DN apps. No play address was provided.";
      }
    });
  }

  V.ready.then(() => {
    const stage = document.querySelector(".stage");
    let group = storedLane();

    function rememberLane() {
      try { sessionStorage.setItem("vasc-apps-lane", group); } catch (error) {}
      const url = "apps.html?lane=" + encodeURIComponent(group);
      if (window.parent !== window) {
        window.parent.postMessage({ vascLane: group }, location.origin);
        return;
      }
      if ((location.pathname.split("/").pop() || "") === "apps.html") {
        history.replaceState(history.state, "", url);
      }
    }

    function appHref(name) {
      return "app.html?name=" + encodeURIComponent(name) + "&lane=" + encodeURIComponent(group);
    }
    let query = new URLSearchParams(location.search).get("q") || "";
    let note = "";
    const byTitle = new Map((V.cases || []).map((row) => [row.title, row]));

    function applyLinks(links) {
      pinKnown();
      for (let i = APPS.length - 1; i >= 0; i -= 1) {
        if (APPS[i].group === "synced") APPS.splice(i, 1);
        else if (APPS[i].fromSync) delete APPS[i].url;
      }
      const claimed = new Set();
      APPS.forEach((app) => {
        if (app.pinned) return;
        const matches = (links || []).filter((link) => playable(link.url) && titlesMatch(app.name, link.title));
        if (!matches.length) return;
        matches.sort((a, b) => (Number(b.rank) || 0) - (Number(a.rank) || 0));
        app.url = matches[0].url;
        app.fromSync = true;
        matches.forEach((link) => claimed.add(link.url));
      });
      APPS.forEach((app) => {
        if (app.url) claimed.add(app.url);
      });
      (links || []).forEach((link) => {
        if (!playable(link.url) || claimed.has(link.url) || microsoftTool(link.title)) return;
        if (APPS.some((app) => titlesMatch(app.name, link.title))) {
          claimed.add(link.url);
          return;
        }
        APPS.push({
          group: "synced",
          name: link.title || "App",
          fr: "",
          status: "Canvas",
          client: "",
          support: "",
          updated: "",
          summary: "Address read from the app list or from a VASC work item.",
          cases: [],
          url: link.url,
          fromSync: true
        });
        claimed.add(link.url);
      });
    }

    function draw() {
      const q = query.trim().toLowerCase();
      const shown = APPS.filter((app) => {
        if (microsoftTool(app.name) || app.group === "platform") return false;
        if (group !== "all" && app.lane !== group) return false;
        if (!q) return true;
        return [app.name, app.fr, app.status, app.client, app.summary, ...(app.cases || [])].join(" ").toLowerCase().includes(q);
      });
      const buttons = [
        ["all", "All"],
        ["Prod", "Prod"],
        ["UAT", "UAT"],
        ["ND-DN", "ND-DN"],
        ["Dev", "Dev"]
      ].map(([id, label]) => {
        const count = id === "all"
          ? APPS.filter((app) => !microsoftTool(app.name) && app.group !== "platform").length
          : APPS.filter((app) => app.lane === id).length;
        return `<button type="button" class="btn" data-group="${id}" aria-pressed="${group === id}">${label} ${count}</button>`;
      }).join("");
      const body = shown.map((app) => {
        const page = appHref(app.name);
        const shot = THUMBS[app.name];
        const thumb = shot
          ? `<a class="app-thumb" href="${page}"><img src="${V.esc(shot)}" alt=""></a>`
          : `<a class="app-thumb app-thumb-empty" href="${page}" aria-label="${V.esc(app.name)}"></a>`;
        const link = (value, inline) => V.linkAbbrs(value == null || value === "" ? "—" : value, inline ? { inline: true } : undefined);
        return `<tr>
          <td>${thumb}</td>
          <td><a href="${page}">${link(app.name, true)}</a></td>
          <td>${link(app.fr || "—")}</td>
          <td>${link(app.lane || app.status || "—")}</td>
          <td>${link(app.client || "—")}</td>
          <td>${V.esc(app.updated || "—")}</td>
          <td class="about">${link(app.summary)}</td>
        </tr>`;
      }).join("");
      const maker = V.role() === "vasc"
        ? `<p>${ENV_LINKS.map(([label, href]) => `<a href="${V.esc(href)}" target="_blank" rel="noopener">${V.esc(label)}</a>`).join(" · ")}. The <a href="${DIRECTORY}" target="_blank" rel="noopener">VASC App Directory</a> is one page, not each app.</p>`
        : "";
      stage.innerHTML = `
        <section class="lede"><h1>Apps</h1>
          <p>Choose a thumbnail to open that app’s description.</p>
          ${maker}
          <div class="link-filters">${buttons}</div>
          ${V.role() === "vasc" ? `<p><button class="btn" id="sync" type="button">Sync</button></p>` : ""}
          <p id="sync-note" class="note">${V.esc(note)}</p>
        </section>
        <div class="table-wrap"><table class="score-table">
          <thead><tr><th></th><th>App</th><th>French name</th><th>Status</th><th>Client</th><th>Updated</th><th>What it does</th></tr></thead>
          <tbody>${body || "<tr><td colspan=\"7\">No apps in this set.</td></tr>"}</tbody>
        </table></div>`;
      stage.querySelectorAll("[data-group]").forEach((button) => {
        button.addEventListener("click", () => {
          group = button.getAttribute("data-group") || "directory";
          rememberLane();
          draw();
        });
      });
      const sync = document.getElementById("sync");
      if (sync) {
        sync.addEventListener("click", () => load(true));
      }
    }

    function load(fresh) {
      const sync = document.getElementById("sync");
      if (sync) sync.disabled = true;
      V.devops("apps", fresh ? { fresh: "1" } : null).then((data) => {
        note = data.note || "";
        applyLinks(data.apps || []);
        draw();
      }).catch((error) => {
        note = error && error.message ? error.message : "The app list could not be read.";
        draw();
      });
    }

    pinKnown();
    window.VASC_APPS = APPS;
    if (document.body.dataset.page !== "apps" || /app\.html$/i.test(location.pathname)) return;
    V.searchChart = (value) => {
      query = String(value || "");
      draw();
    };
    rememberLane();
    V.loadAcronyms().catch(() => []).finally(() => {
      draw();
      load(false);
    });
  });
})();
