#!/usr/bin/env python3
"""Annual cost of a 10-person VCDS approval-app team.

Every figure below is either a dated public price or an author planning
assumption. The script writes the CSV, the page data, and the print copies.
Run: python3 docs/cost/model.py
"""

from __future__ import annotations

import csv
import json
import shutil
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"
DISCLAIMER = "These are estimates with citations."

# Treasury Board IT agreement, appendix in force 22 December 2024.
# https://www.canada.ca/en/treasury-board-secretariat/topics/pay/collective-agreements/it.html
# Weekly rate = annual / 52.176 (same agreement).
RATES_2024_12_22 = {
    "IT-02": [85854, 88603, 91348, 94095, 96844, 99589, 102335, 105080],
    "IT-03": [101343, 104833, 108324, 111817, 115306, 118791, 122281, 125914],
    "IT-04": [116037, 120038, 124039, 128039, 132039, 136041, 140039, 144434],
}
WEEKS_PER_YEAR = 52.176
TEAM_MIX = (("IT-04", 1), ("IT-03", 3), ("IT-02", 6))  # step index 3 = step 4
# Guide to Costing: apply the ARLU employee-benefit factor; include
# accommodation when it is relevant. The current ARLU percentage and the
# PSPC accommodation charge are not published as one national rate.
# 1.20 is the salary-to-operating factor departments use when that ARLU
# rate is the 20% planning figure. 1.27 adds seven points for accommodation
# and overhead. 1.40 is the high planning case. All three are planning
# factors, not an observed VCDS invoice.
LOAD = {"low": 1.20, "mid": 1.27, "high": 1.40}

# Bank of Canada Valet, FXUSDCAD daily average, 25 September 2026.
FX_USD_CAD = 1.4145
FX_DATE = "2026-09-25"
FX_BAND = 0.03  # short-run planning band, not a forecast

# June 2026 Power Platform Licensing Guide (list prices, USD).
# Premium $20/user/month; $12 with 2,000+ new per-user licences;
# pay-as-you-go $10/active user/app/month; per flow $100/month, minimum 5.
PREMIUM_LIST = 20.0
PREMIUM_VOLUME = 12.0
PAYG = 10.0
FLOW_USD = 100.0
FLOW_MIN = 5
MAKERS = 10
# Power Pages, same June 2026 guide. One authenticated pack is 100 users a month.
# Tier 1 is US$200 a pack. Tier 2 (US$75) starts at 100 packs, 10,000 users.
# Pay-as-you-go is US$4 per active authenticated user per website per month.
PAGES_PACK = 100
PAGES_TIER1_USD = 200.0
PAGES_TIER2_USD = 75.0
PAGES_TIER2_MIN_PACKS = 100
PAGES_PAYG_USD = 4.0
# ServiceNow publishes no ITSM list price. Third-party 2026 estimates put the
# Foundation tier at about US$70 to US$100 per fulfiller per month. Requesters
# who only submit are described as unpaid. The low end is used.
SNOW_FULFILLER_USD = 70.0
# Appian’s price page has no dollar. Third-party writeups put Standard near
# US$75 per user per month per app, and an infrequent user near US$2.
APPIAN_STD_USD = 75.0
APPIAN_INFREQUENT_USD = 2.0
# Pega publishes no named-user dollar. Third-party writeups start at US$150 a month.
PEGA_NAMED_USD = 150.0
# GCDocs cost-recovery, said in committee for the 2016-17 year. SSC partners,
# Canadian dollars per user per year. Not a 2026 invoice.
GCDOCS_CAD_PER_USER_YEAR = 125.0

# Azure Retail Prices API, armRegionName canadacentral, USD.
# Fabric capacity CU consumption $0.20/hour (meter present since 2023-11-01).
# Reservation rows in that extract were not used: the unit did not read as a
# monthly F-SKU. SQL GP 2 vCore $0.36532/hour from 2025-06-01.
# GP storage $0.1265/GB-month. App Service P1v3 Linux $0.168/hour from
# 2020-10-01. Basic B1 Linux $0.018/hour.
FABRIC_CU_PER_HOUR = 0.20
FABRIC_CU = 2  # F2
SQL_VCORE_PER_HOUR = 0.36532
SQL_GB_MONTH = 0.1265
SQL_GB = 32  # author: a small approval database
APP_P1V3_PER_HOUR = 0.168
APP_B1_PER_HOUR = 0.018
HOURS_MONTH = 730
HOURS_YEAR = HOURS_MONTH * 12

# AWS price list. EC2 Linux on-demand, Canada (Central), publication
# 2026-09-25. t3.medium $0.0464/hour. RDS PostgreSQL on-demand, same region,
# publication 2026-09-24. db.t3.medium has two unlabelled rates, $0.08 and
# $0.16 per hour. The model uses $0.08 and treats $0.16 as the high case.
EC2_T3_MEDIUM = 0.0464
RDS_T3_MEDIUM_LOW = 0.080
RDS_T3_MEDIUM_HIGH = 0.160

SUBMISSIONS_PER_YEAR = 15  # author: low tens, midpoint 15
SHAREPOINT_VIEW_THRESHOLD = 5000
LOM_SINGLE_DOC_OPENERS = 2000  # author ceiling for one living document
APPS_IN_MAINTENANCE = 12
ACTIVE_FRACTION = 0.40
APPS_TOUCHED = 1.5  # author: apps a person opens in an active month
HORIZON_YEARS = 5
# Steady deliveries for a 10-person team without an AI coding assistant.
# History: about 30 projects in 7 years with a smaller team and a slower start
# (author observation). Mid 6 is a current-team planning rate, not measured.
DELIVERIES_PER_YEAR = 6
DELIVERIES_BAND = (4, 6, 8)
HISTORICAL_PROJECTS = 30
HISTORICAL_YEARS = 7
# Of those 30, how many are SharePoint-list apps that break if the whole L1 opens them.
SHAREPOINT_LEGACY = 20
REPLATFORM_WEEKS = 10  # lists-only mid, per legacy app
CARE_WEEKS_PER_APP = 1.5  # ongoing care, per app per year. Owner care, not a sweep.
SHELL_LIFT = 1.35  # later apps once a shared converted shape exists. Author.
# A hand port of each legacy app. Not the plan.
# Power sweeper already exports structure (power_to_web / web_to_power). It is
# not a full compiler yet. Finishing conversion, then checking each app, replaces the hand port.
SWEEPER_FINISH_WEEKS = 8
SWEEPER_CHECK_WEEKS = 0.5
# Projection, not a measurement. Coding-hour ratio by end-2030, about twice the
# VCR figure. The share that still does not compress falls because drafts of
# bilingual text, acceptance notes, and records move to the assistant. DGDS stays human.
AI_PRIOR_2030 = 10.0
STUCK_2030 = 0.20
AI_USD_2030 = 40  # top of the list-price band already in the model
# Year 0 is 30 September 2026. End of calendar 2030 is 4.25 years later.
PLAN_ORIGIN = date(2026, 9, 30)
JANUARY_2030 = date(2030, 1, 1)
YEARS_TO_2030 = 4.25
# VCDS Group: about 16 organizations in the January 2026 welcome package.
VCDS_L2_COUNT = 16
# Digitizable approval / admin processes per L2 that this kind of team would own
# (not DRMIS, not classified C2). Author planning range.
PROCESSES_PER_L2 = (6, 10, 14)
PAN_VCDS_PROCESSES = (12, 20, 30)  # cross-L2 HQ workflows
REFRESH_FRACTION = 0.5  # share of the 30 already built that need a 2030 refresh

# Coding-assistant list prices, USD per builder per month. Not a VCDS invoice.
# GitHub Copilot Business is often cited near US$19; M365 Copilot near US$30;
# Cursor Business near US$40. Mid uses US$25.
AI_USD_PER_BUILDER_MONTH = {"low": 19.0, "mid": 25.0, "high": 40.0}

# Person-weeks, author planning ranges. Not a vendor study.
WEEKS = {
    "m365_gate": (1, 2, 4),
    "new_boundary": (8, 16, 30),
    "lists_only": (6, 10, 16),
    "approvals": (8, 12, 20),
    "shell_sql": (24, 40, 80),
    "shell_lom": (16, 28, 48),
    "m365_app": (4, 8, 12),
    "later_app": (4, 8, 16),
    "fabric_stand": (2, 4, 8),
    "dwan_procure": (12, 20, 36),
    "aia": (8, 16, 24),
}
# Share of build weeks that are formula/connector/UI work. The rest
# (security packages, bilingual text, acceptance, records) does not compress.
COMPRESSIBLE = 0.60
# Priors on that share. "vcr" is YAML characters per hour on the VCR app.
# you and a student, weekdays 27 Jul–11 Sep 2026, versus the two Cursor
# sessions that edited the saved .msapp (21 Sep and 25 Sep). Upper stays internal.
VCR_SOLO_PEOPLE = 2
VCR_SOLO_WEEKDAYS = 35  # 27 Jul 2026 through 11 Sep 2026, inclusive
VCR_SOLO_CONTROLS = 150  # 690 on the 27 Jul remit file, 840 on the 11 Sep file
VCR_SOLO_CHARS = 667856
VCR_AI_HOURS = 6 + 50 / 60 + 1 + 37 / 60  # 25 Sep 09:25–16:15, and 21 Sep 16:49–18:26
VCR_AI_CONTROLS = 19  # +7 on 21 Sep, +12 on 25 Sep
VCR_AI_CHARS = 55451  # 8,284 + 47,167
VCR_WEEKDAY_HOURS = 7.5  # assumption: a weekday on the app. If shorter, the ratio shrinks.
# Headcount the user stated, held until the next point. Summer students are
# four months in the most recent year, not a fifth permanent team.
STAFF_STEPS = [
    (2.0, 2, "Five years ago: two members"),
    (1.0, 3, "Three years ago: those two, plus the first student"),
    (1.0, 5, "Two years ago: team of five"),
    (1.0, 8, "One year ago: team of eight"),
]
STAFF_NOW = 10
SUMMER_STUDENTS = 8
SUMMER_MONTHS = 4
# Other employees already on strength. Half their time on approval apps, the rest of their job stays.
BORROW_FRACTION = 0.5
# Of the 40% that is not coding, this much can move to people who already run the process
# (bilingual text, acceptance, how the shop actually works). DGDS packages and records stay.
OTHER_EMPLOYEE_SHARE = 0.25


def vcr_rates() -> dict:
    solo_hours = VCR_SOLO_PEOPLE * VCR_SOLO_WEEKDAYS * VCR_WEEKDAY_HOURS
    solo_chars_per_hour = VCR_SOLO_CHARS / solo_hours
    ai_chars_per_hour = VCR_AI_CHARS / VCR_AI_HOURS
    solo_controls_per_hour = VCR_SOLO_CONTROLS / solo_hours
    ai_controls_per_hour = VCR_AI_CONTROLS / VCR_AI_HOURS
    return {
        "solo_hours": solo_hours,
        "solo_chars_per_hour": solo_chars_per_hour,
        "ai_chars_per_hour": ai_chars_per_hour,
        "char_ratio": ai_chars_per_hour / solo_chars_per_hour,
        "control_ratio": ai_controls_per_hour / solo_controls_per_hour,
    }


def staff_person_years() -> dict:
    years = sum(span for span, _, _ in STAFF_STEPS)
    person_years = sum(span * n for span, n, _ in STAFF_STEPS)
    person_years += SUMMER_STUDENTS * (SUMMER_MONTHS / 12)
    return {
        "years": years,
        "person_years": person_years,
        "average_headcount": person_years / years,
        "summer_person_years": SUMMER_STUDENTS * (SUMMER_MONTHS / 12),
    }


_VCR = vcr_rates()
PRIORS = {"vcr": round(_VCR["char_ratio"], 2), "upper": 4.0}

SCALES = [
    {
        "id": "directorate",
        "openers": 200,
        "label": "Directorate, about 200",
        "note": "One process, one shop. Not a published headcount.",
    },
    {
        "id": "vcds_2026",
        "openers": 10500,
        "label": "VCDS, about 10,500",
        "note": "January 2026 DM welcome package, Personnel about 10,500. Planning population.",
    },
    {
        "id": "vcds_2020",
        "openers": 14241,
        "label": "VCDS 2020 total, 14,241",
        "note": "Defence 101, March 2020. Includes 9,668 reserves supporting cadets.",
    },
]


def cad(usd: float, fx: float = FX_USD_CAD) -> float:
    return usd * fx


def always_on(per_hour: float) -> float:
    return per_hour * HOURS_YEAR


def salary(step_index: int) -> float:
    total = 0.0
    for level, count in TEAM_MIX:
        total += RATES_2024_12_22[level][step_index] * count
    return total


def money(n: float) -> str:
    return f"${n:,.0f}"


def citations() -> list[dict]:
    return [
        {
            "id": 1,
            "text": "Treasury Board IT collective agreement, rates in force 22 December 2024. IT-02 step 4 is $94,095, IT-03 step 4 is $111,817, IT-04 step 4 is $128,039. Weekly rate is annual divided by 52.176.",
            "url": "https://www.canada.ca/en/treasury-board-secretariat/topics/pay/collective-agreements/it.html",
        },
        {
            "id": 2,
            "text": "Treasury Board Guide to Costing. Employee benefits use the Annual Reference Level Update factor. Accommodation is included when the department is charged for it. Neither figure is one published national rate, so the 1.20 / 1.27 / 1.40 multipliers are planning factors.",
            "url": "https://www.tbs-sct.canada.ca/pol/doc-eng.aspx?id=32600",
        },
        {
            "id": 3,
            "text": f"Bank of Canada Valet, FXUSDCAD daily average {FX_USD_CAD} on {FX_DATE}.",
            "url": "https://www.bankofcanada.ca/valet/observations/FXUSDCAD/json?recent=5",
        },
        {
            "id": 4,
            "text": "Microsoft Power Platform Licensing Guide, June 2026. Power Apps Premium US$20 per user per month, or US$12 with 2,000 or more new per-user licences. Pay-as-you-go US$10 per active user per app per month. Power Automate per flow US$100 per flow per month, minimum five. Power Pages authenticated capacity is US$200 per 100-user pack per month, or US$75 a pack once 100 packs (10,000 users) are bought; pay-as-you-go is US$4 per active authenticated user per website per month. Multiplexing does not remove user licences. This is the vendor’s rule, not a VCDS invoice.",
            "url": "https://cdn-dynmedia-1.microsoft.com/is/content/microsoftcorp/microsoft/bade/documents/products-and-services/en-us/bizapps/Power-Platform-Licensing-Guide-June-2026-PUB.pdf",
        },
        {
            "id": 5,
            "text": "Power Apps licence designation. Premium means a premium connector, a custom connector, or an on-premises gateway. The person who opens the app needs a per-app or per-user plan.",
            "url": "https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/license-designation",
        },
        {
            "id": 6,
            "text": "Azure Retail Prices API, Canada Central. Fabric capacity CU consumption US$0.20 per CU-hour. SQL Database general purpose 2 vCore US$0.36532 per hour (from 2025-06-01) and storage US$0.1265 per GB-month. App Service Premium v3 Linux P1v3 US$0.168 per hour. Basic B1 Linux US$0.018 per hour. F-SKU reservation rows were not used.",
            "url": "https://prices.azure.com/api/retail/prices",
        },
        {
            "id": 7,
            "text": "AWS public price list, Canada (Central). EC2 Linux t3.medium US$0.0464 per hour, file dated 2026-09-25. RDS for PostgreSQL db.t3.medium, file dated 2026-09-24, two on-demand rates US$0.08 and US$0.16 per hour with no deployment label. The model uses US$0.08.",
            "url": "https://aws.amazon.com/compliance/canada-data-privacy/",
        },
        {
            "id": 8,
            "text": "SharePoint list view threshold is 5,000 items. A list can store more. A view or query that would return more than 5,000 items without a supporting index fails. This is a SharePoint limit, not the Power Apps 500 / 2,000 delegation limit.",
            "url": "https://support.microsoft.com/office/manage-large-lists-and-libraries-in-sharepoint-b8588dae-9387-48c2-9248-c24122f07c59",
        },
        {
            "id": 9,
            "text": "Power Apps delegation is a different limit. Non-delegable filters run on the first 500 rows of a connected list, or 2,000 if the app limit is raised. They do not see the rest. Indexed SharePoint views can still hit the 5,000-item threshold separately.",
            "url": "https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/delegation-overview",
        },
        {
            "id": 10,
            "text": "Vice Chief of the Defence Staff, January 2026 deputy minister welcome package. Personnel about 10,500. Budget $447 million. Sixteen organizations.",
            "url": "https://www.canada.ca/en/department-national-defence/corporate/reports-publications/transition-materials/welcome-package-deputy-minister-nationa-defence-january-2026.html",
        },
        {
            "id": 11,
            "text": "Defence 101, March 2020. VCDS total employees about 14,241: 3,693 Regular Force (890 outside Canada), 200 Primary Reservists, 700 public service, 9,668 reserves supporting cadets.",
            "url": "https://www.canada.ca/en/department-national-defence/corporate/reports-publications/transition-materials/defence-101/2020/03/defence-101/vcds.html",
        },
        {
            "id": 12,
            "text": "DAOD 2006-0. Director General Defence Security is the Chief Security Officer and issues the National Defence Security Orders and Directives. DGDS is the authorization gate, not a hosting service.",
            "url": "https://www.canada.ca/en/department-national-defence/corporate/policies-standards/defence-administrative-orders-directives/2000-series/2006/2006-0-defence-security.html",
        },
        {
            "id": 13,
            "text": "TERMIUM Plus. DGDS is Director General Defence Security, the title to be used by National Defence and the Canadian Forces. An abolished dental title used the same letters. That sense is not used here.",
            "url": "https://www.btb.termiumplus.gc.ca/tpv2alpha/alpha-eng.html?lang=eng&index=enw&srchtxt=DGDS",
        },
        {
            "id": 14,
            "text": "Directive on Service and Digital. Storing Protected B data in Canada is the principal option. Storage outside Canada is a chief information officer decision against published criteria.",
            "url": "https://www.tbs-sct.canada.ca/pol/doc-eng.aspx?id=32601",
        },
        {
            "id": 15,
            "text": "Government of Canada Cloud Guardrails. Departments implement the guardrails before using Protected B cloud services. Shared Services Canada brokers the account.",
            "url": "https://www.tbs-sct.canada.ca/pol/doc-eng.aspx?id=32787",
        },
        {
            "id": 16,
            "text": "AWS CCCS Medium assessment. As of 12 September 2025, 162 services in Canada (Central) and Canada West. A service outside those regions is the department’s own compliance problem. The assessment is not the department’s authorization.",
            "url": "https://aws.amazon.com/compliance/cccs/",
        },
        {
            "id": 17,
            "text": "Microsoft CCCS Medium page. Azure, Microsoft 365, Dynamics 365, and Power Platform are in scope as listed there. Data at rest for many services can sit in Canada Central (Toronto) or Canada East (Quebec City). Microsoft says processing and support may still occur outside Canada.",
            "url": "https://learn.microsoft.com/en-us/compliance/regulatory/offering-cccs-medium",
        },
        {
            "id": 18,
            "text": "AWS Canada (Central) is the Montreal region. Canada West is Calgary. AWS says it does not move customer content out of the chosen region except to comply with law or a binding order. A backup in a US region is still a copy outside Canada.",
            "url": "https://aws.amazon.com/compliance/canada-data-privacy/",
        },
        {
            "id": 19,
            "text": "Government of Canada white paper on data sovereignty and the public cloud. A Canadian region of a US company’s cloud does not remove foreign legal process. Commercial public cloud is contemplated up to Protected B when the policy conditions hold. Classified data is outside this comparison.",
            "url": "https://www.canada.ca/en/government/system/digital-government/digital-government-innovations/cloud-services/digital-sovereignty/gc-white-paper-data-sovereignty-public-cloud.html",
        },
        {
            "id": 20,
            "text": "Treasury Board guide on the use of generative AI. Do not put personal or sensitive information into a public tool. Use a government-managed instance when the data is sensitive. Contract terms should cover residency and not training on the department’s prompts.",
            "url": "https://www.canada.ca/en/government/system/digital-government/digital-government-innovations/responsible-use-ai/guide-use-generative-ai.html",
        },
        {
            "id": 21,
            "text": "Directive on Automated Decision-Making. An Algorithmic Impact Assessment is required when a system makes or informs an administrative decision. Summarizing a file or recommending an approval can be that system. The host does not remove the directive.",
            "url": "https://www.tbs-sct.canada.ca/pol/doc-eng.aspx?id=32592",
        },
        {
            "id": 22,
            "text": "Digital Services Group, January 2026 deputy minister welcome package and the Level 1 mandate note. ADM(DS) owns the defence digital foundation. The mandate note records a stated need of $100 million a year for foundations including cloud from Designated to Top Secret. That is not a chargeback rate for this team.",
            "url": "https://www.canada.ca/en/department-national-defence/corporate/reports-publications/proactive-disclosure/welcome-package-minister-national-defence/overview-level-1-organizational-mandates-priorities-assistant-deputy-minister.html",
        },
        {
            "id": 23,
            "text": "Headcount paths that clear the 2030 backlog. They extend the measured VCR pace. They are not a second measurement. Legacy apps are converted with Power sweeper (structural export already runs; formula conversion is the part still being finished), not hand-rebuilt. The assistant is projected to improve from that measured pace to about twice the YAML-hour ratio by 2030, and tool list price is projected up to the top of the present band. Both projections are planning choices.",
            "url": "",
        },
        {
            "id": 24,
            "text": "Borrowed staff are costed at half a loaded salary. The other half of their job stays where they are. One quarter of each project (bilingual text, acceptance, process knowledge) can sit with those people. Security packages and records stay with the builders. Both fractions are author planning choices.",
            "url": "",
        },
        {
            "id": 25,
            "text": "GC Cloud One. Shared Services Canada brokers public cloud and a GC Private Cloud. The private-cloud price is not public. Sandbox environments are unclassified and short-term.",
            "url": "https://sharedservicescanada.my.site.com/ssccbs/s/gc-cloud-one?language=en_US",
        },
        {
            "id": 26,
            "text": "Living Object Model paper in the LOM tree. LOM is one in-process document, not a multi-document database. A custom connector still counts as premium under the licensing guide while the screen is a Power App.",
            "url": "https://github.com/freementls/LOM",
        },
        {
            "id": 27,
            "text": "Author planning assumptions, marked as such wherever they are used: team mix, 15 submissions a person a year, 40% monthly active, 1.5 apps touched in an active month, 12 apps in maintenance, person-week ranges, DGDS calendar of 1 month inside the tenant and 12 months (range 6 to 18) for a new boundary, LOM single-document ceiling of 2,000 openers, 32 GB SQL database, maintenance drawn from the same ten people, current-team delivery rate of about 6 apps a year (band 4–8), VCDS process counts per L2, AI tool price band.",
            "url": "",
        },
        {
            "id": 28,
            "text": "Canadian Armed Forces Digital Campaign Plan. Vision: digitally transformed CAF by 2030. VCDS has served as CAF Digital Champion. Corporate-space stewardship is part of that vision, which is the lane for approval and admin apps.",
            "url": "https://www.canada.ca/en/department-national-defence/corporate/reports-publications/canadian-armed-forces-digital-campaign-plan.html",
        },
        {
            "id": 29,
            "text": "Author observation: about 30 approval-style projects in about 7 years. Headcount stated for the last five of those years: two members, then the first student, then five, then eight, then about ten staff, with eight students for a summer. Not a published departmental statistic. The VCR pace is from saved CDLS (L) VCR .msapp copies (27 July and 11 September 2026) and the Cursor sessions on 21 and 25 September 2026 that edited them.",
            "url": "",
        },
        {
            "id": 30,
            "text": "AI coding-assistant list prices used as a band: about US$19 to US$40 per builder per month (Copilot Business–class to Cursor Business–class). Converted at the dated Bank of Canada rate. Not a VCDS invoice. Tiny next to loaded labour.",
            "url": "",
        },
        {
            "id": 31,
            "text": "ServiceNow’s ITSM pricing page lists packages and asks for a quote. It does not publish a per-user price. Third-party 2026 estimates put the Foundation tier at about US$70 to US$100 per fulfiller per month, and describe requesters who only submit as unpaid. This model uses US$70. Not a ServiceNow list price and not a GC contract.",
            "url": "https://www.servicenow.com/products/itsm/pricing.html",
        },
        {
            "id": 32,
            "text": "House of Commons committee evidence, 2016. GCDocs cost-recovery for 2016-17 was stated as $125 per user for Shared Services Canada partners and $95 for others. That is the last public per-user figure used here. It is not a 2026 rate.",
            "url": "https://publications.gc.ca/collections/collection_2016/parl/xc73-1/XC73-1-2-421-13-eng.pdf",
        },
        {
            "id": 33,
            "text": "Appian’s pricing page says the platform is priced per user, per month, per app, and does not print a dollar. Third-party 2026 writeups put a standard business user near US$75 a month and an infrequent user near US$2. Those are not Appian list prices.",
            "url": "https://appian.com/products/pricing",
        },
        {
            "id": 34,
            "text": "Pega does not publish a named-user list price. Third-party 2026 writeups put the platform near US$150 to US$300 per named user per month. This model uses US$150. Not a Pega list price.",
            "url": "https://www.pega.com/",
        },
        {
            "id": 35,
            "text": "Camunda’s 2026 pricing page offers a free trial and an enterprise quote. It does not print a production price. Camunda 7 is Apache-2.0. The host dollar on this row is the same Canada Central App Service P1v3 already used for a custom web app, not a Camunda invoice.",
            "url": "https://camunda.com/pricing/",
        },
    ]


def throughput_multiplier(prior: float | None) -> float:
    if not prior:
        return 1.0
    return 1.0 / ((1.0 - COMPRESSIBLE) + COMPRESSIBLE / prior)


def backlog() -> dict:
    low_l2 = VCDS_L2_COUNT * PROCESSES_PER_L2[0] + PAN_VCDS_PROCESSES[0]
    mid_l2 = VCDS_L2_COUNT * PROCESSES_PER_L2[1] + PAN_VCDS_PROCESSES[1]
    high_l2 = VCDS_L2_COUNT * PROCESSES_PER_L2[2] + PAN_VCDS_PROCESSES[2]
    refresh = int(round(HISTORICAL_PROJECTS * REFRESH_FRACTION))
    remaining = {
        "low": max(0, low_l2 - HISTORICAL_PROJECTS + int(round(HISTORICAL_PROJECTS * 0.3))),
        "mid": max(0, mid_l2 - HISTORICAL_PROJECTS + refresh),
        "high": max(0, high_l2 - HISTORICAL_PROJECTS + HISTORICAL_PROJECTS),
    }
    return {
        "l2_count": VCDS_L2_COUNT,
        "processes_per_l2": {"low": PROCESSES_PER_L2[0], "mid": PROCESSES_PER_L2[1], "high": PROCESSES_PER_L2[2]},
        "pan_vcds": {"low": PAN_VCDS_PROCESSES[0], "mid": PAN_VCDS_PROCESSES[1], "high": PAN_VCDS_PROCESSES[2]},
        "estate": {"low": low_l2, "mid": mid_l2, "high": high_l2},
        "done": HISTORICAL_PROJECTS,
        "refresh": refresh,
        "remaining": remaining,
        "method": (
            f"Sixteen VCDS organizations (January 2026 welcome package) times "
            f"{PROCESSES_PER_L2[0]}–{PROCESSES_PER_L2[2]} digitizable approval or admin processes each, "
            f"plus {PAN_VCDS_PROCESSES[0]}–{PAN_VCDS_PROCESSES[2]} pan-VCDS workflows. Subtract the "
            f"{HISTORICAL_PROJECTS} already done. Add a refresh of about half of those for 2030. "
            "DRMIS, classified C2, and pure records systems are out of scope. Author planning ranges, not a census."
        ),
        "years_to_2030": YEARS_TO_2030,
        "origin": PLAN_ORIGIN.isoformat(),
        "january_2030": JANUARY_2030.isoformat(),
        "january_2030_year": round((JANUARY_2030 - PLAN_ORIGIN).days / 365.25, 3),
        "mandate": "CAF Digital Campaign Plan: digitally transformed by 2030.",
    }


def effectiveness(loaded_cad: float, fx: float = FX_USD_CAD, include_author: bool = False) -> dict:
    hist_rate = HISTORICAL_PROJECTS / HISTORICAL_YEARS
    bl = backlog()
    remaining = bl["remaining"]["mid"]
    ai_mid = cad(MAKERS * AI_USD_PER_BUILDER_MONTH["mid"] * 12, fx)
    ai_low = cad(MAKERS * AI_USD_PER_BUILDER_MONTH["low"] * 12, fx)
    ai_high = cad(MAKERS * AI_USD_PER_BUILDER_MONTH["high"] * 12, fx)

    def scenario(sid, label, rate, ai_cost, prior, note, public=True, team_cost=None, by_2030=None, meets=False, people=None, assistant=""):
        mult = throughput_multiplier(prior)
        delivery_rate = rate * mult
        landed = delivery_rate * YEARS_TO_2030 if by_2030 is None else by_2030
        annual = (loaded_cad if team_cost is None else team_cost) + ai_cost
        return {
            "id": sid,
            "label": label,
            "public": public,
            "base_rate": round(rate, 2),
            "prior": prior,
            "throughput_multiplier": round(mult, 3),
            "projects_per_year": round(delivery_rate, 2),
            "projects_by_2030": round(landed, 1),
            "backlog_mid": remaining,
            "gap_to_backlog": round(remaining - landed, 1),
            "clears_mid_backlog": landed >= remaining,
            "meets": meets,
            "people": people,
            "ai_tools_cad": round(ai_cost, 2),
            "annual_team_cad": round(annual, 2),
            "cost_per_project_cad": round(annual / delivery_rate, 2) if delivery_rate else None,
            "assistant": assistant,
            "note": note,
        }

    staff = staff_person_years()
    per_person = loaded_cad / MAKERS
    historical_team_cost = per_person * staff["average_headcount"]
    vcr = vcr_rates()
    scenarios = [
        scenario(
            "historical",
            "Historical pace",
            hist_rate,
            0.0,
            None,
            (
                f"{HISTORICAL_PROJECTS} projects in {HISTORICAL_YEARS} years, about {hist_rate:.1f} a year. "
                f"Labour in this row is today's loaded rate times the average headcount over the last "
                f"{staff['years']:.0f} years you described ({staff['average_headcount']:.1f} people), "
                f"not today's ten. The two years before that headcount are not in the figure."
            ),
            team_cost=historical_team_cost,
            assistant="No coding assistant",
        ),
        scenario(
            "upper",
            "Upper sensitivity",
            DELIVERIES_PER_YEAR,
            ai_high,
            PRIORS["upper"],
            "Upper case: coding work four times as fast, and the high AI-tool price. Not a measured team output.",
            public=False,
            assistant="Coding assistant, upper sensitivity",
        ),
    ]

    vcr_rate = DELIVERIES_PER_YEAR * throughput_multiplier(PRIORS["vcr"])
    person_weeks_year = MAKERS * WEEKS_PER_YEAR
    week_cost = loaded_cad / person_weeks_year
    sweeper_weeks = SWEEPER_FINISH_WEEKS + SHAREPOINT_LEGACY * SWEEPER_CHECK_WEEKS
    manual_weeks = SHAREPOINT_LEGACY * REPLATFORM_WEEKS
    care_weeks = HISTORICAL_PROJECTS * CARE_WEEKS_PER_APP
    drag = care_weeks / person_weeks_year
    steady = vcr_rate * (1 - drag) * SHELL_LIFT
    year1_factor = 1 - min(0.95, sweeper_weeks / person_weeks_year)
    after_drag = steady * year1_factor
    after_shell = steady
    need_mid = remaining / YEARS_TO_2030
    need_low = bl["remaining"]["low"] / YEARS_TO_2030
    plain_after_shell = DELIVERIES_PER_YEAR * (1 - drag) * SHELL_LIFT

    def teams_for(rate: float, per_year: float) -> int:
        if rate <= 0:
            return 0
        return max(1, int((per_year + rate - 1e-9) // rate))

    debt = {
        "sharepoint_apps": SHAREPOINT_LEGACY,
        "replatform_weeks": sweeper_weeks,
        "replatform_cad": round(sweeper_weeks * week_cost, 2),
        "replatform_team_months": round(sweeper_weeks / person_weeks_year * 12, 1),
        "manual_weeks": manual_weeks,
        "manual_cad": round(manual_weeks * week_cost, 2),
        "care_weeks_per_year": care_weeks,
        "drag": round(drag, 3),
        "note": (
            f"Of the {HISTORICAL_PROJECTS} apps already built, {SHAREPOINT_LEGACY} are SharePoint-list "
            f"apps that fail the 5,000-item view if the whole L1 opens them. They are not rebuilt by hand. "
            f"Power sweeper already moves screens, layout, labels, navigation, and theme between a Power App "
            f"and a web app. Formulas are the part still being finished. Finishing that is "
            f"{SWEEPER_FINISH_WEEKS} person-weeks, then {SWEEPER_CHECK_WEEKS} person-week to check each app: "
            f"{sweeper_weeks:g} person-weeks in total. A hand port at {REPLATFORM_WEEKS} person-weeks an app "
            f"would have been {manual_weeks:g} person-weeks. That figure is not the plan. "
            f"Care of all {HISTORICAL_PROJECTS} stays {CARE_WEEKS_PER_APP} person-weeks each per year, "
            f"because a sweep does not sit with the owner. Author planning choices for the unfinished converter."
        ),
    }
    plan = {
        "need_per_year_mid": round(need_mid, 1),
        "need_per_year_low": round(need_low, 1),
        "one_team_after_shell": round(after_shell, 2),
        "teams_mid_with_ai": teams_for(after_shell, need_mid),
        "teams_low_with_ai": teams_for(after_shell, need_low),
        "teams_mid_no_ai": teams_for(plain_after_shell, need_mid),
        "shell_lift": SHELL_LIFT,
        "steps": [
            "Year 1: this team builds one shared web shell (sign-in, form, queue, approval, attachments, audit) and starts replatforming the SharePoint apps that cannot serve 10,500 people. New L2 work is slow that year.",
            "Do not put a premium connector in front of 10,500 openers. That is the multi-million bar, and it does not add projects.",
            f"After the shell, one team at the measured VCR pace is about {after_shell:.0f} projects a year, including care of the old apps.",
            f"The mid backlog needs about {need_mid:.0f} projects a year. That is about {teams_for(after_shell, need_mid)} teams on the shared shell, not one.",
            f"The low backlog ({bl['remaining']['low']} projects) needs about {need_low:.0f} a year, about {teams_for(after_shell, need_low)} teams.",
            "Keep a human on any approval the app recommends. That is the automated-decision rule, and it is the same on every stack.",
        ],
    }

    def ceil_n(need: float, each: float) -> int:
        if each <= 0:
            return 0
        return max(1, int((need + each - 1e-9) // each))

    def landed(year1: float, later: float) -> float:
        if YEARS_TO_2030 <= 1:
            return year1 * YEARS_TO_2030
        return year1 + later * (YEARS_TO_2030 - 1)

    per_year1 = after_drag / MAKERS
    per_later = after_shell / MAKERS
    each_by_2030 = landed(per_year1, per_later)
    ai_each = ai_mid / MAKERS
    hire_n = ceil_n(remaining, each_by_2030)
    hire_year1 = hire_n * per_year1
    hire_later = hire_n * per_later
    hire_by = landed(hire_year1, hire_later)

    core_by = landed(after_drag, after_shell)
    # Borrowed people join after the shell year. The ten build it.
    rest_years = YEARS_TO_2030 - 1
    borrow_later_need = (remaining - after_drag) / rest_years
    borrow_gap = borrow_later_need - after_shell
    borrow_n = ceil_n(borrow_gap, per_later * BORROW_FRACTION)
    borrow_year1 = after_drag
    borrow_later = after_shell + borrow_n * per_later * BORROW_FRACTION
    borrow_by = landed(borrow_year1, borrow_later)

    stuck = 1 - COMPRESSIBLE - OTHER_EMPLOYEE_SHARE
    team_frac = stuck + COMPRESSIBLE / PRIORS["vcr"]
    l2_drag_rate = DELIVERIES_PER_YEAR / team_frac * (1 - drag)
    l2_later_rate = l2_drag_rate * SHELL_LIFT
    l2_ten_by = landed(l2_drag_rate, l2_later_rate)
    l2_per_year1 = l2_drag_rate / MAKERS
    l2_per_later = l2_later_rate / MAKERS
    l2_n = ceil_n(remaining, landed(l2_per_year1, l2_per_later))
    l2_year1 = l2_n * l2_per_year1
    l2_later = l2_n * l2_per_later
    l2_by = landed(l2_year1, l2_later)

    coding_free = 1 / (1 - COMPRESSIBLE)
    ai_ceiling_by = landed(
        DELIVERIES_PER_YEAR * coding_free * (1 - drag) * year1_factor,
        DELIVERIES_PER_YEAR * coding_free * (1 - drag) * SHELL_LIFT,
    )

    stuck_now = 1 - COMPRESSIBLE

    def prior_at(t: float, improving: bool) -> float:
        if not improving:
            return PRIORS["vcr"]
        u = min(1.0, max(0.0, t / YEARS_TO_2030))
        return PRIORS["vcr"] + (AI_PRIOR_2030 - PRIORS["vcr"]) * u

    def stuck_at(t: float, improving: bool) -> float:
        if not improving:
            return stuck_now
        u = min(1.0, max(0.0, t / YEARS_TO_2030))
        return stuck_now + (STUCK_2030 - stuck_now) * u

    def annual_rate(t: float, people: int, improving: bool) -> float:
        prior = prior_at(t, improving)
        stuck = stuck_at(t, improving)
        comp = 1 - stuck
        mult = 1 / (stuck + comp / prior)
        rate = (people / MAKERS) * DELIVERIES_PER_YEAR * mult * (1 - drag) * SHELL_LIFT
        if t < 1:
            team_weeks = people * WEEKS_PER_YEAR
            rate *= 1 - min(0.95, sweeper_weeks / team_weeks)
        return rate

    def integrate(people: int, improving: bool, year_marks: list[float]) -> tuple[float, list[dict]]:
        total = 0.0
        points = []
        prev = 0.0
        for y in year_marks:
            if y <= prev:
                points.append({"year": y, "projects": round(total, 2)})
                continue
            steps = max(1, int(round((y - prev) / 0.25)))
            dt = (y - prev) / steps
            for i in range(steps):
                total += annual_rate(prev + (i + 0.5) * dt, people, improving) * dt
            points.append({"year": y, "projects": round(total, 2)})
            prev = y
        return total, points

    improve_people = MAKERS
    while True:
        landed_n, _ = integrate(improve_people, True, [YEARS_TO_2030])
        if landed_n >= remaining or improve_people >= 80:
            break
        improve_people += 1
    improve_ten_by, _ = integrate(MAKERS, True, [YEARS_TO_2030])
    improve_end = annual_rate(YEARS_TO_2030, MAKERS, True)
    ai_usd_avg = (AI_USD_PER_BUILDER_MONTH["mid"] + AI_USD_2030) / 2
    ai_improve_ten = cad(MAKERS * ai_usd_avg * 12, fx)
    ai_improve_end = cad(MAKERS * AI_USD_2030 * 12, fx)

    def paced(sid, label, year1, later, by, people, team_cost, ai_cost, note, assistant):
        scenarios.append(
            scenario(
                sid,
                label,
                later,
                ai_cost,
                None,
                note,
                by_2030=by,
                meets=by >= remaining,
                people=people,
                team_cost=team_cost,
                assistant=assistant,
            )
        )

    paced(
        "vcr",
        "Ten people, VCR pace",
        after_drag,
        after_shell,
        core_by,
        MAKERS,
        loaded_cad,
        ai_mid,
        (
            f"Does not clear the mid backlog at today's tool level. YAML on VCR moved about {vcr['char_ratio']:.1f}× faster per assisted hour "
            f"than per person-hour in the 35 weekdays you and a student built the app. That applies only to the coding share. "
            f"About {after_shell:.0f} projects a year after the converter's shared shape is in use. "
            f"Year 1 loses {sweeper_weeks:g} person-weeks to finish conversion and check the legacy apps, not to a hand rebuild."
        ),
        "Coding assistant, measured pace",
    )
    paced(
        "hire",
        f"{hire_n} builders",
        hire_year1,
        hire_later,
        hire_by,
        hire_n,
        per_person * hire_n,
        ai_each * hire_n,
        (
            f"Clears the mid backlog. Same pace per person as the VCR measurement, with the shell year. "
            f"{hire_n - MAKERS} people added to today's ten. Citation 23."
        ),
        "Coding assistant, measured pace",
    )
    paced(
        "borrow",
        f"Ten, then {borrow_n} people at half time",
        borrow_year1,
        borrow_later,
        borrow_by,
        MAKERS + borrow_n,
        loaded_cad + borrow_n * BORROW_FRACTION * per_person,
        ai_each * (MAKERS + borrow_n),
        (
            f"Clears the mid backlog. The ten build the shell in year 1. Then {borrow_n} people who already work elsewhere "
            f"give half their time, at the same pace per hour. Costed at half a loaded salary each. Citation 24."
        ),
        "Coding assistant, measured pace",
    )
    paced(
        "l2ten",
        "Ten builders, L2 help (clears the low line)",
        l2_drag_rate,
        l2_later_rate,
        l2_ten_by,
        MAKERS,
        loaded_cad,
        ai_mid,
        (
            f"Clears the low backlog ({bl['remaining']['low']}) and not the mid one. "
            f"People who already run the process take bilingual text, acceptance, and process detail "
            f"({int(OTHER_EMPLOYEE_SHARE * 100)}% of a project). No new builders. Citation 24."
        ),
        "Coding assistant, measured pace",
    )
    paced(
        "l2",
        f"{l2_n} builders, L2 staff carry acceptance",
        l2_year1,
        l2_later,
        l2_by,
        l2_n,
        per_person * l2_n,
        ai_each * l2_n,
        (
            f"Clears the mid backlog. Same split of the work, with {l2_n} builders instead of {hire_n}, "
            f"because the non-coding quarter is no longer on the builders' clock. Citation 24."
        ),
        "Coding assistant, measured pace",
    )
    paced(
        "improve",
        "Ten people, tools improve to 2030",
        improve_ten_by / YEARS_TO_2030,
        improve_ten_by / YEARS_TO_2030,
        improve_ten_by,
        MAKERS,
        loaded_cad,
        ai_improve_ten,
        (
            f"Projection, not a measurement. The coding-hour ratio moves from {PRIORS['vcr']} now to {AI_PRIOR_2030:g} "
            f"by end-2030, and the share that does not compress falls from {int(stuck_now*100)}% to {int(STUCK_2030*100)}% "
            f"(DGDS stays human). Tool list price moves from US${AI_USD_PER_BUILDER_MONTH['mid']:g} to US${AI_USD_2030:g} "
            f"per builder a month. Average tool cost {ai_improve_ten:,.0f} CAD a year; the 2030 run rate is {ai_improve_end:,.0f}. "
            f"Ending pace about {improve_end:.0f} projects a year. Lands about {improve_ten_by:.0f} by 2030. Citation 23."
        ),
        "Coding assistant, projected to improve",
    )
    improve_hire_cost_ai = cad(improve_people * ai_usd_avg * 12, fx)
    improve_by, _ = integrate(improve_people, True, [YEARS_TO_2030])
    paced(
        "improve_hire",
        f"{improve_people} builders, tools improve",
        improve_by / YEARS_TO_2030,
        improve_by / YEARS_TO_2030,
        improve_by,
        improve_people,
        per_person * improve_people,
        improve_hire_cost_ai,
        (
            f"Clears the mid backlog if the same projection holds and these people are in place the whole run. "
            f"{improve_people} builders instead of {hire_n} at today's tool level. "
            f"Tool cost uses the average list price over the run. Citation 23."
        ),
        "Coding assistant, projected to improve",
    )

    years = [round(x * 0.25, 2) for x in range(0, int(YEARS_TO_2030 * 4) + 1)]
    _, improve_points = integrate(MAKERS, True, years)
    _, improve_hire_points = integrate(improve_people, True, years)

    def curve(year1: float, later: float) -> list[dict]:
        points = []
        for y in years:
            projects = year1 * y if y <= 1 else year1 + later * (y - 1)
            points.append({"year": y, "projects": round(projects, 2)})
        return points

    path_specs = [
        ("vcr", "Ten people", after_drag, after_shell, False, "Coding assistant, measured pace"),
        ("l2ten", "L2 help, ten builders", l2_drag_rate, l2_later_rate, True, "Coding assistant, measured pace"),
        ("hire", f"{hire_n} builders", hire_year1, hire_later, True, "Coding assistant, measured pace"),
        ("borrow", "Half-time staff after year 1", borrow_year1, borrow_later, True, "Coding assistant, measured pace"),
        ("l2", f"L2 help, {l2_n} builders", l2_year1, l2_later, True, "Coding assistant, measured pace"),
    ]
    paths = [
        {
            "id": sid,
            "label": f"{label} — {assistant}",
            "assistant": assistant,
            "meets": meets,
            "public": True,
            "points": curve(y1, later),
        }
        for sid, label, y1, later, meets, assistant in path_specs
    ]
    paths.append(
        {
            "id": "improve",
            "label": "Tools improve — Coding assistant, projected to improve",
            "assistant": "Coding assistant, projected to improve",
            "meets": improve_ten_by >= remaining,
            "public": True,
            "points": improve_points,
        }
    )
    paths.append(
        {
            "id": "improve_hire",
            "label": f"{improve_people} builders, tools improve — Coding assistant, projected to improve",
            "assistant": "Coding assistant, projected to improve",
            "meets": True,
            "public": True,
            "points": improve_hire_points,
        }
    )
    plan["ai_ceiling_by_2030"] = round(ai_ceiling_by, 1)
    plan["ai_cannot_alone"] = improve_ten_by < remaining
    plan["hire_people"] = hire_n
    plan["borrow_people"] = borrow_n
    plan["l2_builders"] = l2_n
    plan["improve_by_2030"] = round(improve_ten_by, 1)
    plan["improve_end_rate"] = round(improve_end, 1)
    plan["improve_people"] = improve_people
    plan["ai_tools_2030_cad"] = round(ai_improve_end, 2)
    plan["sweeper_weeks"] = sweeper_weeks
    plan["manual_weeks"] = manual_weeks
    plan["steps"] = [
        f"Legacy apps are converted with Power sweeper, not rebuilt. {sweeper_weeks:g} person-weeks to finish conversion and check {SHAREPOINT_LEGACY} apps, against {manual_weeks:g} if each were ported by hand.",
        (
            f"Tools are projected to improve: coding-hour ratio {PRIORS['vcr']} now to {AI_PRIOR_2030:g} by 2030, "
            f"and the human-only share from {int(stuck_now*100)}% to {int(STUCK_2030*100)}%. "
            f"Ten people then land about {improve_ten_by:.0f} projects. The ending pace is about {improve_end:.0f} a year. "
            f"Tool list price rises from US${AI_USD_PER_BUILDER_MONTH['mid']:g} to US${AI_USD_2030:g} per builder a month, "
            f"about {ai_improve_end:,.0f} CAD a year at the end, still small next to salary."
        ),
        f"At today's tool level, {hire_n} builders clear the mid backlog. If tools improve as projected, {improve_people} builders do.",
        f"Other employees: after year 1, {borrow_n} people at half time clear the mid backlog at today's pace. "
        f"If they instead take bilingual text, acceptance, and process detail, ten builders clear the low backlog "
        f"and {l2_n} builders clear the mid one.",
        "Do not put a premium connector in front of 10,500 openers. That spend does not add projects.",
        "Keep a human on any approval the app recommends.",
    ]
    return {
        "historical": {
            "projects": HISTORICAL_PROJECTS,
            "years": HISTORICAL_YEARS,
            "rate": round(hist_rate, 2),
        },
        "backlog": bl,
        "ai_tools_cad": {"low": round(ai_low, 2), "mid": round(ai_mid, 2), "high": round(ai_high, 2)},
        "compressible": COMPRESSIBLE,
        "scenarios": [s for s in scenarios if include_author or s["public"]],
        "paths": paths,
        "assistant_key": (
            "No coding assistant: historical pace only. "
            "Coding assistant, measured pace: builders use a commercial coding tool at the VCR hour ratio. "
            "That is the ten-person line, the extra builders, the half-time staff, and both L2-help lines. "
            "Coding assistant, projected to improve: the same kind of tool, with the coding-hour ratio and the human-only share moving through 2030. "
            "None of these is the app recommending an approval. That use is the automated-decision rule and applies on every stack."
        ),
        "debt": debt,
        "plan": plan,
        "vcr": {
            "solo_people": VCR_SOLO_PEOPLE,
            "solo_weekdays": VCR_SOLO_WEEKDAYS,
            "solo_controls": VCR_SOLO_CONTROLS,
            "solo_chars": VCR_SOLO_CHARS,
            "weekday_hours": VCR_WEEKDAY_HOURS,
            "ai_hours": round(VCR_AI_HOURS, 2),
            "ai_controls": VCR_AI_CONTROLS,
            "ai_chars": VCR_AI_CHARS,
            "char_ratio": round(vcr["char_ratio"], 2),
            "control_ratio": round(vcr["control_ratio"], 2),
            "throughput_multiplier": round(throughput_multiplier(PRIORS["vcr"]), 2),
            "note": (
                "27 Jul remit file to the 11 Sep file: +150 controls and about 668,000 YAML characters, "
                "you and one student, no Cursor session editing that canvas. "
                "21 Sep (16:49–18:26) added the overview and +7 controls. "
                "25 Sep (09:25–16:15) added fiscal-year ids, the missing-request screen, acknowledgement mail rules, and +12 controls. "
                "Those two sessions are the assisted hours. Aug 21 was the dark-theme sweeper, not this app's screens."
            ),
        },
        "staffing": {
            "steps": [{"years": span, "people": n, "label": label} for span, n, label in STAFF_STEPS],
            "staff_now": STAFF_NOW,
            "summer_students": SUMMER_STUDENTS,
            "summer_months": SUMMER_MONTHS,
            "person_years": round(staff["person_years"], 1),
            "average_headcount": round(staff["average_headcount"], 2),
            "historical_team_cad": round(historical_team_cost, 2),
        },
        "headline": (
            f"Addressable remaining mid backlog about {remaining} VCDS approval-style projects by 2030 "
            f"({bl['remaining']['low']}–{bl['remaining']['high']}). Historical pace is {hist_rate:.1f} a year "
            f"from {HISTORICAL_PROJECTS} projects in {HISTORICAL_YEARS} years, costed at an average "
            f"{staff['average_headcount']:.1f} people over the last five years, not at today's ten. "
            f"On VCR, assisted hours changed YAML about {vcr['char_ratio']:.1f}× faster than a person-hour "
            f"in the six weeks you and a student built the body of the app. Ten people at that pace do not "
            f"clear the mid backlog. If tools improve as projected, those ten land about {improve_ten_by:.0f} "
            f"projects by 2030, and {improve_people} builders clear the mid line. "
            f"Legacy apps are converted with Power sweeper ({sweeper_weeks:g} person-weeks), not hand-ported "
            f"({manual_weeks:g} person-weeks)."
        ),
    }


def stacks() -> list[dict]:
    common_aia = (
        "If the app summarizes a file or recommends an approval, the Directive on "
        "Automated Decision-Making applies here too. The host does not remove it."
    )
    return [
        {
            "id": "m365",
            "name": "Full M365, SharePoint lists",
            "short": "M365 lists",
            "priced": True,
            "premium": False,
            "sharepoint": True,
            "lom_single": False,
            "boundary": "tenant",
            "weeks_key": "m365_gate",
            "compliance": {
                "residency": "Data at rest can be in Canada. Microsoft says processing and support may still leave Canada.",
                "inherited": "Reuses the tenant’s residency decision and its existing authorization.",
                "still": "DGDS still sees a change inside that tenant. A personal-information bank still needs a privacy assessment.",
                "aia": common_aia,
            },
        },
        {
            "id": "dataverse",
            "name": "M365 screens, Dataverse",
            "short": "Dataverse",
            "priced": True,
            "premium": True,
            "sharepoint": False,
            "lom_single": False,
            "boundary": "tenant",
            "weeks_key": "lists_only",
            "compliance": {
                "residency": "Dataverse in the Canadian Microsoft 365 tenant follows the same at-rest story as the tenant, with the same out-of-Canada processing caveat.",
                "inherited": "Still inside the tenant. Premium licensing is the new bill, not a new cloud account.",
                "still": "Privacy assessment. DGDS change inside the tenant.",
                "aia": common_aia,
            },
        },
        {
            "id": "sql",
            "name": "M365 screens, Azure SQL",
            "short": "SQL connector",
            "priced": True,
            "premium": True,
            "sharepoint": False,
            "lom_single": False,
            "boundary": "tenant",
            "weeks_key": "lists_only",
            "compliance": {
                "residency": "Azure SQL can be pinned to Canada Central or Canada East, including processing, if the SKU is deployed there and backups stay in Canada.",
                "inherited": "The screen stays in the tenant. The database is a new store and a premium connector.",
                "still": "Gateway or private link, backup region, privacy assessment, DGDS.",
                "aia": common_aia,
            },
        },
        {
            "id": "fabric",
            "name": "M365 screens, Fabric F2",
            "short": "Fabric",
            "priced": True,
            "premium": True,
            "sharepoint": False,
            "lom_single": False,
            "boundary": "tenant",
            "weeks_key": "fabric_stand",
            "compliance": {
                "residency": "Fabric capacity in Canada Central keeps that capacity’s data in-region only for items you do not replicate out. OneLake shortcuts and backups need a look.",
                "inherited": "Screen stays in the tenant.",
                "still": "Capacity is a new Azure bill plus premium seats. An F2 is a small analytics SKU, not a cheaper list.",
                "aia": common_aia,
            },
        },
        {
            "id": "lom_connector",
            "name": "M365 screens, XML LOM",
            "short": "LOM connector",
            "priced": True,
            "premium": True,
            "sharepoint": False,
            "lom_single": True,
            "boundary": "tenant",
            "weeks_key": "lists_only",
            "compliance": {
                "residency": "The document sits where you host lomd. A Canada Central App Service pin keeps that file in Canada if backups do too.",
                "inherited": "Screen stays in the tenant, so the custom connector is premium under the licensing guide.",
                "still": "LOM is one living document, not a multi-user database. Partitioning is extra work past the author ceiling.",
                "aia": common_aia,
            },
        },
        {
            "id": "web_azure",
            "name": "Custom web, SQL, Azure Canada",
            "short": "Web and SQL",
            "priced": True,
            "premium": False,
            "sharepoint": False,
            "lom_single": False,
            "boundary": "new",
            "weeks_key": "shell_sql",
            "compliance": {
                "residency": "App Service and Azure SQL in Canada Central can keep storage and processing in Canada. Failover to Canada East, not to a US region.",
                "inherited": "Nothing about a new subscription is inherited except Entra ID, if you choose to use it.",
                "still": "Cloud guardrails, a new DGDS authorization, privacy assessment. No per-opener Power Apps seat.",
                "aia": common_aia,
            },
        },
        {
            "id": "web_aws",
            "name": "Custom web, PostgreSQL, AWS Canada (Central)",
            "short": "Web on AWS Montreal",
            "priced": True,
            "premium": False,
            "sharepoint": False,
            "lom_single": False,
            "boundary": "new",
            "weeks_key": "shell_sql",
            "compliance": {
                "residency": "ca-central-1 is Montreal. AWS says content stays in the region you choose, except for law or a binding order. Canada West (Calgary) is the Canadian failover. A US replica fails the residency test.",
                "inherited": "Only the services on the CCCS Medium list, and only in the Canadian regions. As of 12 September 2025 that list had 162 services.",
                "still": "SSC broker, guardrails, new DGDS authorization. US legal process still reaches a US company.",
                "aia": common_aia,
            },
        },
        {
            "id": "web_lom",
            "name": "Custom web, XML LOM, Azure Canada",
            "short": "Web and LOM",
            "priced": True,
            "premium": False,
            "sharepoint": False,
            "lom_single": False,
            "boundary": "new",
            "weeks_key": "shell_lom",
            "compliance": {
                "residency": "Same pin as any other Canada Central app. The file is not a SharePoint list and not a Power App, so the premium seat does not apply.",
                "inherited": "The engine is already written (Apache-2.0). The authorization of a new host is not.",
                "still": "Single-document concurrency. Past the author ceiling, partition the documents or use SQL. New DGDS gate.",
                "aia": common_aia,
            },
        },
        {
            "id": "dwan",
            "name": "DWAN virtual machine",
            "short": "DWAN VM",
            "priced": True,
            "premium": False,
            "sharepoint": False,
            "lom_single": False,
            "boundary": "new",
            "weeks_key": "dwan_procure",
            "compliance": {
                "residency": "The machine is in a defence or SSC data centre. That is the row whose operator is not a US public-cloud company.",
                "inherited": "No per-person cloud licence. Data-centre chargeback is not public, so the platform line is zero marginal to the team, not zero to the Crown.",
                "still": "Procurement and a DGDS authorization. Slower calendar than a tenant change.",
                "aia": common_aia,
            },
        },
        {
            "id": "ssc",
            "name": "SSC cloud computing (GC Cloud One)",
            "short": "SSC cloud",
            "priced": False,
            "premium": False,
            "sharepoint": False,
            "lom_single": False,
            "boundary": "new",
            "weeks_key": "new_boundary",
            "compliance": {
                "residency": "Same regions as Azure or AWS, behind the SSC broker. GC Private Cloud is the sovereign option and its price is not public.",
                "inherited": "Guardrails are the broker’s baseline. The department still authorizes the workload.",
                "still": "No published unit price, so this row is not on the dollar chart.",
                "aia": common_aia,
            },
        },
        {
            "id": "adm",
            "name": "ADM(DS) digital foundation",
            "short": "ADM(DS)",
            "priced": False,
            "premium": False,
            "sharepoint": False,
            "lom_single": False,
            "boundary": "new",
            "weeks_key": "new_boundary",
            "compliance": {
                "residency": "The Level 1 that owns cloud, the data layer, identity, and application platforms for Defence. Intake is the cost you can see. The $100 million foundations figure is not a rate for this team.",
                "inherited": "Policy direction is that other Level 1s sit on this foundation rather than stand up their own.",
                "still": "Chargeback is not published. Not drawn as a dollar bar.",
                "aia": common_aia,
            },
        },
    ]


def hosts(fx: float) -> dict[str, float]:
    sql = cad(always_on(SQL_VCORE_PER_HOUR) + SQL_GB * SQL_GB_MONTH * 12, fx)
    fabric = cad(FABRIC_CU_PER_HOUR * FABRIC_CU * HOURS_YEAR, fx)
    p1 = cad(always_on(APP_P1V3_PER_HOUR), fx)
    b1 = cad(always_on(APP_B1_PER_HOUR), fx)
    aws = cad(always_on(EC2_T3_MEDIUM + RDS_T3_MEDIUM_LOW), fx)
    return {"sql": sql, "fabric": fabric, "p1": p1, "b1": b1, "aws": aws}


def seats(openers: int, fx: float, maker_only: bool) -> float:
    people = MAKERS if maker_only else openers
    return cad(people * PREMIUM_LIST * 12, fx)


def host_for(stack_id: str, fx: float) -> float:
    h = hosts(fx)
    return {
        "m365": 0.0,
        "dataverse": 0.0,
        "sql": h["sql"],
        "fabric": h["fabric"],
        "lom_connector": h["b1"],
        "web_azure": h["p1"] + h["sql"],
        "web_aws": h["aws"],
        "web_lom": h["p1"],
        "dwan": 0.0,
    }[stack_id]


def feasible(stack: dict, openers: int) -> tuple[bool, str]:
    rows = openers * SUBMISSIONS_PER_YEAR
    if stack["sharepoint"] and rows > SHAREPOINT_VIEW_THRESHOLD:
        return False, (
            f"A year of submissions is {rows:,.0f} rows. The list can store them. "
            f"The SharePoint list view threshold is {SHAREPOINT_VIEW_THRESHOLD:,} items: a view that would return more "
            f"than that without an index fails. That is not the Power Apps 500 / 2,000 delegation limit, which is separate."
        )
    if stack["lom_single"] and openers > LOM_SINGLE_DOC_OPENERS:
        return False, (
            f"{openers:,} openers is past the author ceiling of {LOM_SINGLE_DOC_OPENERS:,} "
            "for one living document. Partition the documents or use SQL. That ceiling is not a measured limit."
        )
    if stack["sharepoint"]:
        return True, (
            f"A year of submissions is {rows:,.0f} rows, under the SharePoint {SHAREPOINT_VIEW_THRESHOLD:,}-item "
            "view threshold. Separately, non-delegable Power Apps filters still see only the first 500 or 2,000 rows."
        )
    if stack["lom_single"]:
        return True, "Inside the author ceiling for one living document. Concurrent writers remain a design limit."
    return True, "No row-count ceiling in this model. Cost, not the list threshold, is the constraint."


def one_time_weeks(stack: dict, band: str = "mid") -> float:
    idx = {"low": 0, "mid": 1, "high": 2}[band]
    if stack["id"] == "m365":
        return WEEKS["m365_gate"][idx]
    if stack["id"] == "dwan":
        return WEEKS["dwan_procure"][idx] + WEEKS["new_boundary"][idx]
    if stack["boundary"] == "new":
        shell = WEEKS[stack["weeks_key"]][idx]
        baseline = WEEKS["m365_app"][idx]
        return max(0, shell - baseline) + WEEKS["new_boundary"][idx]
    extra = WEEKS[stack["weeks_key"]][idx] + WEEKS["m365_gate"][idx]
    if stack["id"] == "fabric":
        extra += WEEKS["fabric_stand"][idx]
    return extra


def adjusted_weeks(weeks: float, prior: float | None) -> float:
    if not prior:
        return weeks
    return weeks * ((1 - COMPRESSIBLE) + COMPRESSIBLE / prior)


def build(fx: float = FX_USD_CAD, load: float = LOAD["mid"], step: int = 3, prior: float | None = None) -> dict:
    loaded = salary(step) * load
    week_cost = loaded / (MAKERS * WEEKS_PER_YEAR)
    step_gap = (salary(3) - salary(0)) * LOAD["mid"]
    noise = 0.5 * step_gap
    by_id = {s["id"]: s for s in stacks()}

    def rows_for(maker_only: bool) -> list[dict]:
        rows = []
        for scale in SCALES:
            for stack in stacks():
                if not stack["priced"]:
                    continue
                ok, note = feasible(stack, scale["openers"])
                host = host_for(stack["id"], fx)
                seat = seats(scale["openers"], fx, maker_only) if stack["premium"] else 0.0
                platform = host + seat
                weeks = adjusted_weeks(one_time_weeks(stack), prior)
                once = weeks * week_cost
                annual = loaded + platform
                five = HORIZON_YEARS * annual + once
                guide_seat = seats(scale["openers"], fx, False) if stack["premium"] else 0.0
                maker_seat = seats(scale["openers"], fx, True) if stack["premium"] else 0.0
                # Money saved against staying on a premium SQL connector, which is
                # what the guide charges if the screen stays in Power Apps and the
                # list is no longer SharePoint. Full M365 avoids nothing: its seat is already zero.
                sql_guide = host_for("sql", fx) + seats(scale["openers"], fx, maker_only)
                # Only a stack that has left Power Apps avoids the premium seat.
                if stack["id"] in ("web_azure", "web_aws", "web_lom", "dwan"):
                    avoided = max(0.0, sql_guide - platform)
                else:
                    avoided = 0.0
                delay = 1 if stack["boundary"] == "tenant" else 12
                payback = None
                if avoided > 0:
                    payback = once / (avoided / 12) + delay
                active = scale["openers"] * ACTIVE_FRACTION
                rows.append(
                    {
                        "stack": stack["id"],
                        "scale": scale["id"],
                        "openers": scale["openers"],
                        "licensing": "maker" if maker_only else "guide",
                        "annual_cad": round(annual, 2),
                        "platform_cad": round(platform, 2),
                        "labour_cad": round(loaded, 2),
                        "host_cad": round(host, 2),
                        "seat_cad": round(seat, 2),
                        "guide_seat_cad": round(guide_seat, 2),
                        "maker_seat_cad": round(maker_seat, 2),
                        "one_time_cad": round(once, 2),
                        "one_time_weeks": round(weeks, 2),
                        "five_year_cad": round(five, 2),
                        "feasible": ok,
                        "feasibility_note": note,
                        "licence_avoided_cad": round(avoided, 2),
                        "payback_months": None if payback is None else round(payback, 1),
                        "delay_months": delay,
                        "cost_per_app_cad": round(annual / APPS_IN_MAINTENANCE, 2),
                        "cost_per_active_opener_cad": round(annual / active, 2) if active else None,
                        "you_are_here": stack["id"] == "m365" and scale["id"] == "vcds_2026" and not maker_only,
                        "you_are_here_ai": bool(
                            prior and stack["id"] == "m365" and scale["id"] == "vcds_2026" and not maker_only
                        ),
                    }
                )
        mark_sweet(rows, "annual_cad", "sweet_annual", "cluster_annual", noise)
        mark_sweet(rows, "five_year_cad", "sweet_five", "cluster_five", noise * HORIZON_YEARS)
        return rows

    def pieces(maker_only: bool) -> list[dict]:
        openers = 10500
        out = []
        specs = [
            (
                "lists",
                "Replace lists only. Screens stay in Power Apps.",
                "lists_only",
                "tenant",
                0.0,
                "The custom or premium connector remains. Under the licensing guide the per-opener seat does not go away. The build pays only if the 5,000-item view was the reason.",
            ),
            (
                "approvals",
                "Replace Power Automate approvals only.",
                "approvals",
                "tenant",
                cad(FLOW_USD * FLOW_MIN * 12, fx),
                "A per-flow licence covers the flow, not each person who opens an app. The minimum published purchase is five flows.",
            ),
            (
                "screens_sql",
                "Replace screens and lists with a web app on SQL.",
                "shell_sql",
                "new",
                seats(openers, fx, maker_only) + host_for("sql", fx) - host_for("web_azure", fx),
                "This is the build that drops the per-opener Power Apps seat. Later apps reuse the shell.",
            ),
            (
                "screens_lom",
                "Replace screens and lists with a web app on LOM.",
                "shell_lom",
                "new",
                max(0.0, seats(openers, fx, maker_only) + host_for("sql", fx) - host_for("web_lom", fx)),
                "Same seat drop, shorter weeks because the engine already exists. Still a new authorization. One document is the wrong shape at this size; the weeks assume the documents are partitioned.",
            ),
            (
                "no_fabric",
                "Do not buy Fabric. Stand up SQL.",
                "fabric_stand",
                "tenant",
                host_for("fabric", fx),
                "Fabric is treated as a purchase not yet made. The avoided bill is the F2 capacity, not a migration.",
            ),
        ]
        for key, title, weeks_key, boundary, avoided, why in specs:
            idx = 1
            weeks = WEEKS[weeks_key][idx]
            if boundary == "new":
                weeks = max(0, weeks - WEEKS["m365_app"][idx]) + WEEKS["new_boundary"][idx]
            elif key != "no_fabric":
                weeks = weeks + WEEKS["m365_gate"][idx]
            raw_weeks = weeks
            weeks_adj = adjusted_weeks(weeks, prior)
            once = weeks_adj * week_cost
            low_w = adjusted_weeks(WEEKS[weeks_key][0], prior)
            high_w = adjusted_weeks(WEEKS[weeks_key][2], prior)
            delay = 1 if boundary == "tenant" else 12
            payback = None if avoided <= 0 else once / (avoided / 12) + delay
            out.append(
                {
                    "id": key,
                    "title": title,
                    "why": why,
                    "weeks_low": round(low_w, 1),
                    "weeks_mid": round(weeks_adj, 1),
                    "weeks_high": round(high_w, 1),
                    "weeks_unadjusted": round(raw_weeks, 1),
                    "one_time_cad": round(once, 2),
                    "avoided_cad": round(avoided, 2),
                    "payback_months": None if payback is None else round(payback, 1),
                    "delay_months": delay,
                    "openers": openers,
                }
            )
        return out

    public_rows = rows_for(False) + rows_for(True)
    return {
        "disclaimer": DISCLAIMER,
        "loaded_cad": round(loaded, 2),
        "salary_cad": round(salary(step), 2),
        "week_cost_cad": round(week_cost, 2),
        "noise_cad": round(noise, 2),
        "step_gap_cad": round(step_gap, 2),
        "hosts_cad": {k: round(v, 2) for k, v in hosts(fx).items()},
        "rows": public_rows,
        "pieces": pieces(False),
        "fx": fx,
        "load": load,
        "prior": prior,
    }


def mark_sweet(rows: list[dict], field: str, flag: str, cluster_flag: str, noise: float) -> None:
    groups: dict[tuple, list] = {}
    for row in rows:
        row[flag] = False
        row[cluster_flag] = False
        groups.setdefault((row["scale"], row["licensing"]), []).append(row)
    for group in groups.values():
        feasible_rows = [r for r in group if r["feasible"]]
        if not feasible_rows:
            continue
        best = min(r[field] for r in feasible_rows)
        winners = [r for r in feasible_rows if r[field] <= best + noise]
        for r in winners:
            r[cluster_flag] = True
            r[flag] = True


def headline(rows: list[dict], noise: float, stack_names: dict[str, str]) -> str:
    guide = [r for r in rows if r["licensing"] == "guide" and r["scale"] == "vcds_2026"]
    cluster = [stack_names[r["stack"]] for r in guide if r["cluster_annual"]]
    names = "; ".join(cluster)
    return (
        "At about 10,500 openers, under the licensing guide, the feasible stacks that are not a "
        f"per-person Power Apps licence are: {names}. They sit inside the salary-step noise "
        f"(${noise:,.0f}). There is no single cheapest host. Full M365 with SharePoint "
        "lists is where the team is, and a year of submissions at that size fails the SharePoint "
        "5,000-item view threshold (not the Power Apps 500 / 2,000 delegation limit)."
    )


def tornado(base_fx: float, base_load: float) -> list[dict]:
    """What moves the five-year gap between premium SQL and Azure web, at 10,500."""

    def gap(fx, load, step, discount, prior):
        b = build(fx=fx, load=load, step=step, prior=prior)
        rows = [r for r in b["rows"] if r["licensing"] == "guide" and r["scale"] == "vcds_2026"]
        sql = next(r for r in rows if r["stack"] == "sql")
        web = next(r for r in rows if r["stack"] == "web_azure")
        sql_five = sql["five_year_cad"] - sql["seat_cad"] * HORIZON_YEARS * (1 - discount)
        return sql_five - web["five_year_cad"]

    mid = gap(base_fx, base_load, 3, 1.0, None)
    swings = [
        ("Salary step (1 vs 8)", gap(base_fx, base_load, 0, 1.0, None), gap(base_fx, base_load, 7, 1.0, None)),
        ("Benefits factor (1.20 vs 1.40)", gap(base_fx, 1.20, 3, 1.0, None), gap(base_fx, 1.40, 3, 1.0, None)),
        ("Exchange rate ±3%", gap(base_fx * (1 - FX_BAND), base_load, 3, 1.0, None), gap(base_fx * (1 + FX_BAND), base_load, 3, 1.0, None)),
        ("Seat price at a 40% discount vs list", gap(base_fx, base_load, 3, 0.60, None), mid),
        (f"No coding-speed change vs the VCR pace ({PRIORS['vcr']})", gap(base_fx, base_load, 3, 1.0, None), gap(base_fx, base_load, 3, 1.0, PRIORS["vcr"])),
    ]
    out = []
    for name, low, high in swings:
        out.append(
            {
                "name": name,
                "low_gap_cad": round(low, 2),
                "high_gap_cad": round(high, 2),
                "swing_cad": round(abs(high - low), 2),
                "mid_gap_cad": round(mid, 2),
            }
        )
    out.sort(key=lambda r: -r["swing_cad"])
    return out


def payload(internal: bool) -> dict:
    prior = PRIORS["vcr"] if internal else None
    model = build(prior=prior)
    names = {s["id"]: s for s in stacks()}
    return {
        "disclaimer": DISCLAIMER,
        "internal": internal,
        "fx": {"usd_cad": FX_USD_CAD, "date": FX_DATE, "band": FX_BAND},
        "team": {
            "mix": "1 IT-04, 3 IT-03, 6 IT-02, step 4, rates of 22 December 2024",
            "salary_cad": model["salary_cad"],
            "loaded_cad": model["loaded_cad"],
            "load_factor": LOAD["mid"],
            "person_week_cad": model["week_cost_cad"],
            "apps": APPS_IN_MAINTENANCE,
            "deliveries": DELIVERIES_PER_YEAR,
            "submissions": SUBMISSIONS_PER_YEAR,
            "active_fraction": ACTIVE_FRACTION,
        },
        "noise_cad": model["noise_cad"],
        "step_gap_cad": model["step_gap_cad"],
        "hosts_cad": model["hosts_cad"],
        "scales": SCALES,
        "stacks": [
            {
                "id": s["id"],
                "name": s["name"],
                "short": s["short"],
                "priced": s["priced"],
                "premium": s["premium"],
                "compliance": s["compliance"],
            }
            for s in stacks()
        ],
        "rows": model["rows"],
        "pieces": model["pieces"],
        "headline": headline(model["rows"], model["noise_cad"], {s["id"]: s["name"] for s in stacks()}),
        "effectiveness": effectiveness(model["loaded_cad"], include_author=internal),
        "tornado": tornado(FX_USD_CAD, LOAD["mid"]) if internal else [],
        "ai": ai_annex() if internal else None,
        "other_options": other_options(model["loaded_cad"]),
        "citations": citations(),
        "you_are_here": {"stack": "m365", "scale": "vcds_2026"},
    }


def ai_annex() -> dict:
    return {
        "compressible": COMPRESSIBLE,
        "priors": PRIORS,
        "text": (
            "Sixty percent of build weeks are treated as work that can compress (formulae, connectors, "
            "repairs) and 40% as work that does not (DGDS packages, bilingual content, acceptance, records). "
            f"The VCR measurement is {PRIORS['vcr']}× on YAML characters per hour, from the saved app files, "
            "applied to the compressible share only. Making coding take no time still does not clear the "
            "2030 backlog with ten people. The upper sensitivity is 4.0 on the coding share and is not a "
            "measured team output. "
            "AI tool list prices are added to labour when "
            "scoring cost per project."
        ),
    }


def other_options(loaded_cad: float, fx: float = FX_USD_CAD) -> list[dict]:
    openers = next(s["openers"] for s in SCALES if s["id"] == "vcds_2026")
    fulfillers = MAKERS + VCDS_L2_COUNT

    def row(name, platform, note):
        return {
            "name": name,
            "openers": openers,
            "platform_cad": round(platform, 2),
            "with_labour_cad": round(platform + loaded_cad, 2),
            "note": note,
        }

    packs = (openers + PAGES_PACK - 1) // PAGES_PACK
    page_usd = PAGES_TIER2_USD if packs >= PAGES_TIER2_MIN_PACKS else PAGES_TIER1_USD
    pages = cad(packs * page_usd * 12, fx)
    pages_payg = cad(openers * PAGES_PAYG_USD * 12, fx)
    snow = cad(SNOW_FULFILLER_USD * fulfillers * 12, fx)
    snow_all = cad(SNOW_FULFILLER_USD * openers * 12, fx)
    appian = cad(APPIAN_STD_USD * openers * 12, fx)
    appian_infrequent = cad(APPIAN_INFREQUENT_USD * openers * 12, fx)
    pega = cad(PEGA_NAMED_USD * openers * 12, fx)
    gcdocs = GCDOCS_CAD_PER_USER_YEAR * openers
    host = APP_P1V3_PER_HOUR * HOURS_YEAR * fx
    flow = cad(FLOW_USD * FLOW_MIN * 12, fx)
    return [
        row(
            "ServiceNow",
            snow,
            (
                f"No published list price. US${SNOW_FULFILLER_USD:.0f} per fulfiller per month is the low end of a 2026 "
                f"third-party Foundation band, times {fulfillers} people: the ten builders plus one action officer in each "
                f"of the 16 organizations. Requesters who only submit are not in that count. If every opener were a "
                f"fulfiller the same rate would be {money(snow_all)} a year. Not a GC contract. Citation 31."
            ),
        ),
        row(
            "DRMIS / SAP workflow",
            0.0,
            (
                "Platform increment is $0. The department already runs DRMIS, so a new approval inside it does not buy "
                "a second ERP. The cost that remains is this team's labour, and the calendar to change SAP. "
                "SAP Business One prices are a different product and are not used."
            ),
        ),
        row(
            "GCDocs / OpenText",
            gcdocs,
            (
                f"${GCDOCS_CAD_PER_USER_YEAR:.0f} per user per year is the 2016-17 cost-recovery figure for Shared Services "
                f"Canada partners, times {openers:,} openers. It is not a 2026 rate. If those people already have a "
                "GCDocs seat, the increment is $0 and the remaining cost is a thin app in front of the record. Citation 32."
            ),
        ),
        row(
            "Power Pages",
            pages,
            (
                f"June 2026 guide, authenticated users. {packs} packs of {PAGES_PACK} at US${page_usd:.0f} a pack a month "
                f"(tier 2, because 10,000 users is the threshold). Pay-as-you-go at US${PAGES_PAYG_USD:.0f} per active "
                f"authenticated user, if all {openers:,} were active every month, would be {money(pages_payg)}. "
                "Same licensing guide as the Premium seat. Citation 4."
            ),
        ),
        row(
            "Appian",
            appian,
            (
                f"No printed list price. Third-party figure of US${APPIAN_STD_USD:.0f} per user per month per app, "
                f"times {openers:,} openers. The same writeups describe an infrequent user near US${APPIAN_INFREQUENT_USD:.0f}, "
                f"which would be {money(appian_infrequent)} a year if it applied to every opener. Not an Appian invoice. Citation 33."
            ),
        ),
        row(
            "Pega",
            pega,
            (
                f"No printed list price. Third-party named-user band starts at US${PEGA_NAMED_USD:.0f} a month. "
                f"That rate times {openers:,} openers is the figure. The top of the same band is US$300. Not a Pega invoice. Citation 34."
            ),
        ),
        row(
            "Camunda",
            host,
            (
                f"2026 production SaaS is a quote, so it is not given an invented price. The number is a self-managed "
                f"engine at a zero licence (Camunda 7 is Apache-2.0) plus one Canada Central App Service P1v3, "
                f"the same host already priced for a custom web app ({money(host)}). Citation 35 and citation 6."
            ),
        ),
        row(
            "Approvals with no custom app",
            flow,
            (
                f"Power Automate per-flow, June 2026 guide: US${FLOW_USD:.0f} per flow per month, minimum {FLOW_MIN} flows. "
                "The flow is licensed, not each person who opens it. Same guide as the Premium seat. Citation 4."
            ),
        ),
    ]


def write_csv(path: Path, rows: list[dict], stacks_by_id: dict) -> None:
    fields = [
        "stack",
        "stack_name",
        "scale",
        "openers",
        "licensing",
        "annual_cad",
        "platform_cad",
        "labour_cad",
        "five_year_cad",
        "feasible",
        "sweet_spot",
        "sweet_spot_five_year",
        "you_are_here",
        "you_are_here_ai",
        "feasibility_note",
    ]
    with path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        for r in rows:
            if r["licensing"] != "guide":
                continue
            w.writerow(
                {
                    "stack": r["stack"],
                    "stack_name": stacks_by_id[r["stack"]]["name"],
                    "scale": r["scale"],
                    "openers": r["openers"],
                    "licensing": r["licensing"],
                    "annual_cad": r["annual_cad"],
                    "platform_cad": r["platform_cad"],
                    "labour_cad": r["labour_cad"],
                    "five_year_cad": r["five_year_cad"],
                    "feasible": str(r["feasible"]).lower(),
                    "sweet_spot": str(r["sweet_annual"]).lower(),
                    "sweet_spot_five_year": str(r["sweet_five"]).lower(),
                    "you_are_here": str(r["you_are_here"]).lower(),
                    "you_are_here_ai": str(r.get("you_are_here_ai", False)).lower(),
                    "feasibility_note": r["feasibility_note"],
                }
            )


def write_md(path: Path, data: dict) -> None:
    lines = [
        f"# {DISCLAIMER}",
        "",
        "Annual cost of a ten-person team at Carling Campus building approval apps for the Vice Chief of the Defence Staff.",
        "",
        data["headline"],
        "",
        "## Team",
        "",
        f"Salary at step 4: {money(data['team']['salary_cad'])}. Loaded at {data['team']['load_factor']}: {money(data['team']['loaded_cad'])}. "
        f"Mix: {data['team']['mix']}. Exchange rate {data['fx']['usd_cad']} USD/CAD on {data['fx']['date']}.",
        "",
        "## Cost-effectiveness to 2030",
        "",
        data["effectiveness"]["headline"],
        "",
        data["effectiveness"]["backlog"]["method"],
        "",
        "| Scenario | Coding assistant | Projects / year | By end-2030 | Gap vs mid backlog | AI tools / year | Cost / project |",
        "| --- | --- | ---: | ---: | ---: | ---: | ---: |",
    ]
    for s in data["effectiveness"]["scenarios"]:
        lines.append(
            f"| {s['label']} | {s['assistant']} | {s['projects_per_year']} | {s['projects_by_2030']} | "
            f"{s['gap_to_backlog']} | {money(s['ai_tools_cad'])} | {money(s['cost_per_project_cad'])} |"
        )
    lines += [
        "",
        "## Guide rule, annual cost",
        "",
        "| Stack | Directorate 200 | VCDS 10,500 | 2020 total 14,241 |",
        "| --- | ---: | ---: | ---: |",
    ]
    by = {}
    for r in data["rows"]:
        if r["licensing"] != "guide":
            continue
        by.setdefault(r["stack"], {})[r["scale"]] = r
    names = {s["id"]: s["name"] for s in data["stacks"]}
    for sid, scales in by.items():
        cells = []
        for key in ("directorate", "vcds_2026", "vcds_2020"):
            r = scales[key]
            mark = ""
            if r["you_are_here"]:
                mark = " You are here."
            if r["sweet_annual"]:
                mark += " Sweet spot."
            if not r["feasible"]:
                mark += " Does not clear the ceiling."
            cells.append(money(r["annual_cad"]) + mark)
        lines.append(f"| {names[sid]} | {cells[0]} | {cells[1]} | {cells[2]} |")
    lines += [
        "",
        "Labour is the same on every row. The tower at 10,500 is the Power Apps Premium list price, US$20 times openers times 12, times the exchange rate. "
        "A 40% discount, or the guide’s US$12 price for 2,000 or more new licences, shrinks that tower and does not remove it. "
        "Maker-only licensing is a counterfactual on the webpage, not this table.",
        "",
        "## Build",
        "",
    ]
    for p in data["pieces"]:
        pay = "does not pay back on licences" if p["payback_months"] is None else f"payback about {p['payback_months']} months including {p['delay_months']} months of calendar before savings"
        lines.append(
            f"- {p['title']} Mid {p['weeks_mid']} person-weeks, one-time {money(p['one_time_cad'])}, "
            f"licence avoided {money(p['avoided_cad'])} a year at 10,500 openers. {pay}. {p['why']}"
        )
    lines += ["", "## Citations", ""]
    for c in data["citations"]:
        url = f" {c['url']}" if c["url"] else ""
        lines.append(f"{c['id']}. {c['text']}{url}")
    lines.append("")
    path.write_text("\n".join(lines), encoding="utf-8")


def write_pbip(folder_name: str, csv_name: str, title: str) -> None:
    report = ROOT / f"{folder_name}.Report"
    model = ROOT / f"{folder_name}.SemanticModel"
    if report.exists():
        shutil.rmtree(report)
    if model.exists():
        shutil.rmtree(model)
    page = report / "definition" / "pages" / "ReportSectionCost" / "visuals"
    (page / "column").mkdir(parents=True)
    (page / "table").mkdir(parents=True)
    (model / "definition" / "tables").mkdir(parents=True)
    csv_path = str((DATA / csv_name).resolve()).replace("\\", "\\\\")
    (ROOT / f"{folder_name}.pbip").write_text(
        json.dumps(
            {
                "version": "1.0",
                "artifacts": [{"report": {"path": f"{folder_name}.Report"}}],
                "settings": {"enableAutoRecovery": True},
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    (report / "definition.pbir").write_text(
        json.dumps(
            {
                "$schema": "https://developer.microsoft.com/json-schemas/fabric/item/report/definitionProperties/2.0.0/schema.json",
                "version": "4.0",
                "datasetReference": {"byPath": {"path": f"../{folder_name}.SemanticModel"}},
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    (report / ".platform").write_text(
        json.dumps(
            {
                "$schema": "https://developer.microsoft.com/json-schemas/fabric/gitIntegration/platformProperties/2.0.0/schema.json",
                "metadata": {"type": "Report", "displayName": title},
                "config": {"version": "2.0", "logicalId": "c0ffee00-0000-4000-8000-000000000001"},
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    (report / "definition" / "version.json").write_text(
        json.dumps(
            {
                "$schema": "https://developer.microsoft.com/json-schemas/fabric/item/report/definition/versionMetadata/1.0.0/schema.json",
                "version": "2.0.0",
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    (report / "definition" / "report.json").write_text(
        json.dumps(
            {
                "$schema": "https://developer.microsoft.com/json-schemas/fabric/item/report/definition/report/1.2.0/schema.json",
                "layoutOptimization": "None",
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    (report / "definition" / "pages" / "pages.json").write_text(
        json.dumps(
            {
                "$schema": "https://developer.microsoft.com/json-schemas/fabric/item/report/definition/pagesMetadata/1.0.0/schema.json",
                "pageOrder": ["ReportSectionCost"],
                "activePageName": "ReportSectionCost",
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    (report / "definition" / "pages" / "ReportSectionCost" / "page.json").write_text(
        json.dumps(
            {
                "$schema": "https://developer.microsoft.com/json-schemas/fabric/item/report/definition/page/2.0.0/schema.json",
                "name": "ReportSectionCost",
                "displayName": "Cost",
                "displayOption": "FitToPage",
                "height": 720,
                "width": 1280,
            },
            indent=2,
        ),
        encoding="utf-8",
    )

    def field(prop: str) -> dict:
        return {
            "field": {"Column": {"Expression": {"SourceRef": {"Entity": "Scenarios"}}, "Property": prop}},
            "queryRef": f"Scenarios.{prop}",
            "nativeQueryRef": prop,
        }

    column = {
        "$schema": "https://developer.microsoft.com/json-schemas/fabric/item/report/definition/visualContainer/2.9.0/schema.json",
        "name": "a1000000000000000001",
        "position": {"x": 20, "y": 20, "z": 0, "height": 420, "width": 760, "tabOrder": 0},
        "visual": {
            "visualType": "clusteredColumnChart",
            "query": {
                "queryState": {
                    "Category": {"projections": [field("stack_name")]},
                    "Y": {"projections": [field("annual_cad")]},
                    "Series": {"projections": [field("scale")]},
                }
            },
        },
    }
    table = {
        "$schema": "https://developer.microsoft.com/json-schemas/fabric/item/report/definition/visualContainer/2.9.0/schema.json",
        "name": "a1000000000000000002",
        "position": {"x": 800, "y": 20, "z": 0, "height": 660, "width": 450, "tabOrder": 1},
        "visual": {
            "visualType": "tableEx",
            "query": {
                "queryState": {
                    "Values": {
                        "projections": [
                            field("stack_name"),
                            field("scale"),
                            field("openers"),
                            field("annual_cad"),
                            field("platform_cad"),
                            field("feasible"),
                            field("sweet_spot"),
                            field("you_are_here"),
                        ]
                    }
                }
            },
        },
    }
    (page / "column" / "visual.json").write_text(json.dumps(column, indent=2), encoding="utf-8")
    (page / "table" / "visual.json").write_text(json.dumps(table, indent=2), encoding="utf-8")
    (model / "definition.pbism").write_text(
        json.dumps(
            {
                "$schema": "https://developer.microsoft.com/json-schemas/fabric/item/semanticModel/definitionProperties/1.0.0/schema.json",
                "version": "4.0",
                "settings": {},
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    (model / ".platform").write_text(
        json.dumps(
            {
                "$schema": "https://developer.microsoft.com/json-schemas/fabric/gitIntegration/platformProperties/2.0.0/schema.json",
                "metadata": {"type": "SemanticModel", "displayName": title},
                "config": {"version": "2.0", "logicalId": "c0ffee00-0000-4000-8000-000000000002"},
            },
            indent=2,
        ),
        encoding="utf-8",
    )
    (model / "definition" / "model.tmdl").write_text(
        "\n".join(
            [
                "model Model",
                "\tculture: en-CA",
                "\tdefaultPowerBIDataSourceVersion: powerBI_V3",
                "\tsourceQueryCulture: en-CA",
                "",
                "annotation PBI_QueryOrder = [\"Scenarios\"]",
                "",
                "ref table Scenarios",
                "",
            ]
        ),
        encoding="utf-8",
    )
    m_source = "\n".join(
        [
            "\tpartition Scenarios = m",
            "\t\tmode: import",
            "\t\tsource = ```",
            "\t\t\t\tlet",
            f"\t\t\t\t    Source = Csv.Document(File.Contents(\"{csv_path}\"),[Delimiter=\",\", Encoding=65001, QuoteStyle=QuoteStyle.Csv]),",
            "\t\t\t\t    Promoted = Table.PromoteHeaders(Source, [PromoteAllScalars=true]),",
            "\t\t\t\t    Typed = Table.TransformColumnTypes(Promoted,{{\"openers\", Int64.Type}, {\"annual_cad\", type number}, {\"platform_cad\", type number}, {\"labour_cad\", type number}, {\"five_year_cad\", type number}, {\"feasible\", type logical}, {\"sweet_spot\", type logical}, {\"sweet_spot_five_year\", type logical}, {\"you_are_here\", type logical}, {\"you_are_here_ai\", type logical}})",
            "\t\t\t\tin",
            "\t\t\t\t    Typed",
            "\t\t\t\t```",
            "",
        ]
    )
    cols = []
    for name, dtype in (
        ("stack", "string"),
        ("stack_name", "string"),
        ("scale", "string"),
        ("licensing", "string"),
        ("feasibility_note", "string"),
        ("openers", "int64"),
        ("annual_cad", "double"),
        ("platform_cad", "double"),
        ("labour_cad", "double"),
        ("five_year_cad", "double"),
        ("feasible", "boolean"),
        ("sweet_spot", "boolean"),
        ("sweet_spot_five_year", "boolean"),
        ("you_are_here", "boolean"),
        ("you_are_here_ai", "boolean"),
    ):
        summary = "none" if dtype in ("string", "boolean") else "sum"
        cols.append(
            f"\tcolumn {name}\n\t\tdataType: {dtype}\n\t\tlineageTag: {name}\n\t\tsummarizeBy: {summary}\n\t\tsourceColumn: {name}\n"
        )
    (model / "definition" / "tables" / "Scenarios.tmdl").write_text(
        "table Scenarios\n\tlineageTag: scenarios\n\n" + "\n".join(cols) + "\n" + m_source,
        encoding="utf-8",
    )


def write_js(path: Path, data: dict) -> None:
    path.write_text("window.COST_DATA = " + json.dumps(data, ensure_ascii=False, indent=2) + ";\n", encoding="utf-8")


def check(data: dict) -> None:
    rows = [r for r in data["rows"] if r["licensing"] == "guide"]
    d200 = next(r for r in rows if r["stack"] == "m365" and r["scale"] == "directorate")
    v = next(r for r in rows if r["stack"] == "m365" and r["scale"] == "vcds_2026")
    assert d200["feasible"] and d200["sweet_annual"], d200
    assert not v["feasible"] and v["you_are_here"]
    cluster = [r["stack"] for r in rows if r["scale"] == "vcds_2026" and r["cluster_annual"]]
    for sid in ("web_azure", "web_aws", "web_lom", "dwan"):
        assert sid in cluster, cluster
    assert "m365" not in cluster and "sql" not in cluster
    sql = next(r for r in rows if r["stack"] == "sql" and r["scale"] == "vcds_2026")
    assert sql["seat_cad"] > sql["labour_cad"]
    print("loaded", data["team"]["loaded_cad"])
    print("noise", data["noise_cad"])
    print("seat 10500", sql["seat_cad"])
    print("cluster", cluster)
    print("headline", data["headline"])


def main() -> None:
    DATA.mkdir(parents=True, exist_ok=True)
    public = payload(False)
    internal = payload(True)
    names = {s["id"]: s for s in stacks()}
    write_js(DATA / "data_public.js", public)
    write_js(DATA / "data_internal.js", internal)
    write_csv(DATA / "public_scenarios.csv", public["rows"], names)
    write_csv(DATA / "internal_scenarios.csv", internal["rows"], names)
    write_md(ROOT / "approval-apps-cost-public.md", public)
    write_md(ROOT / "approval-apps-cost-internal.md", internal)
    write_pbip("approval-apps", "public_scenarios.csv", "VCDS approval app costs")
    write_pbip("approval-apps-internal", "internal_scenarios.csv", "VCDS approval app costs, internal")
    src = (ROOT / "public.html").read_text(encoding="utf-8")
    internal_html = src.replace("data/data_public.js", "data/data_internal.js").replace(
        "embed-config.js", "embed-config-internal.js"
    )
    (ROOT / "internal.html").write_text(internal_html, encoding="utf-8")
    (ROOT / "embed-config-internal.js").write_text(
        "window.PBI_EMBED = { embedUrl: \"\", reportId: \"\", accessToken: \"\" };\n",
        encoding="utf-8",
    )
    check(public)
    print("wrote", DATA)


if __name__ == "__main__":
    main()
