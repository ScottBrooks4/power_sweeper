window.COST_DATA = {
  "disclaimer": "These are estimates with citations.",
  "internal": false,
  "fx": {
    "usd_cad": 1.4145,
    "date": "2026-09-25",
    "band": 0.03
  },
  "team": {
    "mix": "1 IT-04, 3 IT-03, 6 IT-02, step 4, rates of 22 December 2024",
    "salary_cad": 1028060.0,
    "loaded_cad": 1305636.2,
    "load_factor": 1.27,
    "person_week_cad": 2502.37,
    "apps": 12,
    "deliveries": 6,
    "submissions": 15,
    "active_fraction": 0.4
  },
  "noise_cad": 58972.45,
  "step_gap_cad": 117944.9,
  "hosts_cad": {
    "sql": 4595.4,
    "fabric": 4956.41,
    "p1": 2081.69,
    "b1": 223.04,
    "aws": 1566.22
  },
  "scales": [
    {
      "id": "directorate",
      "openers": 200,
      "label": "Directorate, about 200",
      "note": "One process, one shop. Not a published headcount."
    },
    {
      "id": "vcds_2026",
      "openers": 10500,
      "label": "VCDS, about 10,500",
      "note": "January 2026 DM welcome package, Personnel about 10,500. Planning population."
    },
    {
      "id": "vcds_2020",
      "openers": 14241,
      "label": "VCDS 2020 total, 14,241",
      "note": "Defence 101, March 2020. Includes 9,668 reserves supporting cadets."
    }
  ],
  "stacks": [
    {
      "id": "m365",
      "name": "Full M365, SharePoint lists",
      "short": "M365 lists",
      "priced": true,
      "premium": false,
      "compliance": {
        "residency": "Data at rest can be in Canada. Microsoft says processing and support may still leave Canada.",
        "inherited": "Reuses the tenant’s residency decision and its existing authorization.",
        "still": "DGDS still sees a change inside that tenant. A personal-information bank still needs a privacy assessment.",
        "aia": "If the app summarizes a file or recommends an approval, the Directive on Automated Decision-Making applies here too. The host does not remove it."
      }
    },
    {
      "id": "dataverse",
      "name": "M365 screens, Dataverse",
      "short": "Dataverse",
      "priced": true,
      "premium": true,
      "compliance": {
        "residency": "Dataverse in the Canadian Microsoft 365 tenant follows the same at-rest story as the tenant, with the same out-of-Canada processing caveat.",
        "inherited": "Still inside the tenant. Premium licensing is the new bill, not a new cloud account.",
        "still": "Privacy assessment. DGDS change inside the tenant.",
        "aia": "If the app summarizes a file or recommends an approval, the Directive on Automated Decision-Making applies here too. The host does not remove it."
      }
    },
    {
      "id": "sql",
      "name": "M365 screens, Azure SQL",
      "short": "SQL connector",
      "priced": true,
      "premium": true,
      "compliance": {
        "residency": "Azure SQL can be pinned to Canada Central or Canada East, including processing, if the SKU is deployed there and backups stay in Canada.",
        "inherited": "The screen stays in the tenant. The database is a new store and a premium connector.",
        "still": "Gateway or private link, backup region, privacy assessment, DGDS.",
        "aia": "If the app summarizes a file or recommends an approval, the Directive on Automated Decision-Making applies here too. The host does not remove it."
      }
    },
    {
      "id": "fabric",
      "name": "M365 screens, Fabric F2",
      "short": "Fabric",
      "priced": true,
      "premium": true,
      "compliance": {
        "residency": "Fabric capacity in Canada Central keeps that capacity’s data in-region only for items you do not replicate out. OneLake shortcuts and backups need a look.",
        "inherited": "Screen stays in the tenant.",
        "still": "Capacity is a new Azure bill plus premium seats. An F2 is a small analytics SKU, not a cheaper list.",
        "aia": "If the app summarizes a file or recommends an approval, the Directive on Automated Decision-Making applies here too. The host does not remove it."
      }
    },
    {
      "id": "lom_connector",
      "name": "M365 screens, XML LOM",
      "short": "LOM connector",
      "priced": true,
      "premium": true,
      "compliance": {
        "residency": "The document sits where you host lomd. A Canada Central App Service pin keeps that file in Canada if backups do too.",
        "inherited": "Screen stays in the tenant, so the custom connector is premium under the licensing guide.",
        "still": "LOM is one living document, not a multi-user database. Partitioning is extra work past the author ceiling.",
        "aia": "If the app summarizes a file or recommends an approval, the Directive on Automated Decision-Making applies here too. The host does not remove it."
      }
    },
    {
      "id": "web_azure",
      "name": "Custom web, SQL, Azure Canada",
      "short": "Web and SQL",
      "priced": true,
      "premium": false,
      "compliance": {
        "residency": "App Service and Azure SQL in Canada Central can keep storage and processing in Canada. Failover to Canada East, not to a US region.",
        "inherited": "Nothing about a new subscription is inherited except Entra ID, if you choose to use it.",
        "still": "Cloud guardrails, a new DGDS authorization, privacy assessment. No per-opener Power Apps seat.",
        "aia": "If the app summarizes a file or recommends an approval, the Directive on Automated Decision-Making applies here too. The host does not remove it."
      }
    },
    {
      "id": "web_aws",
      "name": "Custom web, PostgreSQL, AWS Canada (Central)",
      "short": "Web on AWS Montreal",
      "priced": true,
      "premium": false,
      "compliance": {
        "residency": "ca-central-1 is Montreal. AWS says content stays in the region you choose, except for law or a binding order. Canada West (Calgary) is the Canadian failover. A US replica fails the residency test.",
        "inherited": "Only the services on the CCCS Medium list, and only in the Canadian regions. As of 12 September 2025 that list had 162 services.",
        "still": "SSC broker, guardrails, new DGDS authorization. US legal process still reaches a US company.",
        "aia": "If the app summarizes a file or recommends an approval, the Directive on Automated Decision-Making applies here too. The host does not remove it."
      }
    },
    {
      "id": "web_lom",
      "name": "Custom web, XML LOM, Azure Canada",
      "short": "Web and LOM",
      "priced": true,
      "premium": false,
      "compliance": {
        "residency": "Same pin as any other Canada Central app. The file is not a SharePoint list and not a Power App, so the premium seat does not apply.",
        "inherited": "The engine is already written (Apache-2.0). The authorization of a new host is not.",
        "still": "Single-document concurrency. Past the author ceiling, partition the documents or use SQL. New DGDS gate.",
        "aia": "If the app summarizes a file or recommends an approval, the Directive on Automated Decision-Making applies here too. The host does not remove it."
      }
    },
    {
      "id": "dwan",
      "name": "DWAN virtual machine",
      "short": "DWAN VM",
      "priced": true,
      "premium": false,
      "compliance": {
        "residency": "The machine is in a defence or SSC data centre. That is the row whose operator is not a US public-cloud company.",
        "inherited": "No per-person cloud licence. Data-centre chargeback is not public, so the platform line is zero marginal to the team, not zero to the Crown.",
        "still": "Procurement and a DGDS authorization. Slower calendar than a tenant change.",
        "aia": "If the app summarizes a file or recommends an approval, the Directive on Automated Decision-Making applies here too. The host does not remove it."
      }
    },
    {
      "id": "ssc",
      "name": "SSC cloud computing (GC Cloud One)",
      "short": "SSC cloud",
      "priced": false,
      "premium": false,
      "compliance": {
        "residency": "Same regions as Azure or AWS, behind the SSC broker. GC Private Cloud is the sovereign option and its price is not public.",
        "inherited": "Guardrails are the broker’s baseline. The department still authorizes the workload.",
        "still": "No published unit price, so this row is not on the dollar chart.",
        "aia": "If the app summarizes a file or recommends an approval, the Directive on Automated Decision-Making applies here too. The host does not remove it."
      }
    },
    {
      "id": "adm",
      "name": "ADM(DS) digital foundation",
      "short": "ADM(DS)",
      "priced": false,
      "premium": false,
      "compliance": {
        "residency": "The Level 1 that owns cloud, the data layer, identity, and application platforms for Defence. Intake is the cost you can see. The $100 million foundations figure is not a rate for this team.",
        "inherited": "Policy direction is that other Level 1s sit on this foundation rather than stand up their own.",
        "still": "Chargeback is not published. Not drawn as a dollar bar.",
        "aia": "If the app summarizes a file or recommends an approval, the Directive on Automated Decision-Making applies here too. The host does not remove it."
      }
    }
  ],
  "rows": [
    {
      "stack": "m365",
      "scale": "directorate",
      "openers": 200,
      "licensing": "guide",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 5004.74,
      "one_time_weeks": 2,
      "five_year_cad": 6533185.74,
      "feasible": true,
      "feasibility_note": "A year of submissions is 3,000 rows, under the SharePoint 5,000-item view threshold. Separately, non-delegable Power Apps filters still see only the first 500 or 2,000 rows.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 16320.45,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "dataverse",
      "scale": "directorate",
      "openers": 200,
      "licensing": "guide",
      "annual_cad": 1373532.2,
      "platform_cad": 67896.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 67896.0,
      "guide_seat_cad": 67896.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6897689.43,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 114461.02,
      "cost_per_active_opener_cad": 17169.15,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "sql",
      "scale": "directorate",
      "openers": 200,
      "licensing": "guide",
      "annual_cad": 1378127.6,
      "platform_cad": 72491.4,
      "labour_cad": 1305636.2,
      "host_cad": 4595.4,
      "seat_cad": 67896.0,
      "guide_seat_cad": 67896.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6920666.42,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 114843.97,
      "cost_per_active_opener_cad": 17226.59,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "fabric",
      "scale": "directorate",
      "openers": 200,
      "licensing": "guide",
      "annual_cad": 1378488.61,
      "platform_cad": 72852.41,
      "labour_cad": 1305636.2,
      "host_cad": 4956.41,
      "seat_cad": 67896.0,
      "guide_seat_cad": 67896.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 25023.69,
      "one_time_weeks": 10,
      "five_year_cad": 6917466.73,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 114874.05,
      "cost_per_active_opener_cad": 17231.11,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "lom_connector",
      "scale": "directorate",
      "openers": 200,
      "licensing": "guide",
      "annual_cad": 1373755.24,
      "platform_cad": 68119.04,
      "labour_cad": 1305636.2,
      "host_cad": 223.04,
      "seat_cad": 67896.0,
      "guide_seat_cad": 67896.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6898804.62,
      "feasible": true,
      "feasibility_note": "Inside the author ceiling for one living document. Concurrent writers remain a design limit.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 114479.6,
      "cost_per_active_opener_cad": 17171.94,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "web_azure",
      "scale": "directorate",
      "openers": 200,
      "licensing": "guide",
      "annual_cad": 1312313.29,
      "platform_cad": 6677.09,
      "labour_cad": 1305636.2,
      "host_cad": 6677.09,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6681680.17,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 65814.31,
      "payback_months": 33.9,
      "delay_months": 12,
      "cost_per_app_cad": 109359.44,
      "cost_per_active_opener_cad": 16403.92,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_aws",
      "scale": "directorate",
      "openers": 200,
      "licensing": "guide",
      "annual_cad": 1307202.42,
      "platform_cad": 1566.22,
      "labour_cad": 1305636.2,
      "host_cad": 1566.22,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6656125.85,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 70925.17,
      "payback_months": 32.3,
      "delay_months": 12,
      "cost_per_app_cad": 108933.54,
      "cost_per_active_opener_cad": 16340.03,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_lom",
      "scale": "directorate",
      "openers": 200,
      "licensing": "guide",
      "annual_cad": 1307717.89,
      "platform_cad": 2081.69,
      "labour_cad": 1305636.2,
      "host_cad": 2081.69,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6628674.75,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 70409.71,
      "payback_months": 27.4,
      "delay_months": 12,
      "cost_per_app_cad": 108976.49,
      "cost_per_active_opener_cad": 16346.47,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "dwan",
      "scale": "directorate",
      "openers": 200,
      "licensing": "guide",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6618266.29,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 72491.4,
      "payback_months": 26.9,
      "delay_months": 12,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 16320.45,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "m365",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "guide",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 5004.74,
      "one_time_weeks": 2,
      "five_year_cad": 6533185.74,
      "feasible": false,
      "feasibility_note": "A year of submissions is 157,500 rows. The list can store them. The SharePoint list view threshold is 5,000 items: a view that would return more than that without an index fails. That is not the Power Apps 500 / 2,000 delegation limit, which is separate.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 310.87,
      "you_are_here": true,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "dataverse",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "guide",
      "annual_cad": 4870176.2,
      "platform_cad": 3564540.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 3564540.0,
      "guide_seat_cad": 3564540.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 24380909.43,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 405848.02,
      "cost_per_active_opener_cad": 1159.57,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "sql",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "guide",
      "annual_cad": 4874771.6,
      "platform_cad": 3569135.4,
      "labour_cad": 1305636.2,
      "host_cad": 4595.4,
      "seat_cad": 3564540.0,
      "guide_seat_cad": 3564540.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 24403886.42,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 406230.97,
      "cost_per_active_opener_cad": 1160.66,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "fabric",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "guide",
      "annual_cad": 4875132.61,
      "platform_cad": 3569496.41,
      "labour_cad": 1305636.2,
      "host_cad": 4956.41,
      "seat_cad": 3564540.0,
      "guide_seat_cad": 3564540.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 25023.69,
      "one_time_weeks": 10,
      "five_year_cad": 24400686.73,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 406261.05,
      "cost_per_active_opener_cad": 1160.75,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "lom_connector",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "guide",
      "annual_cad": 4870399.24,
      "platform_cad": 3564763.04,
      "labour_cad": 1305636.2,
      "host_cad": 223.04,
      "seat_cad": 3564540.0,
      "guide_seat_cad": 3564540.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 24382024.62,
      "feasible": false,
      "feasibility_note": "10,500 openers is past the author ceiling of 2,000 for one living document. Partition the documents or use SQL. That ceiling is not a measured limit.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 405866.6,
      "cost_per_active_opener_cad": 1159.62,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "web_azure",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "guide",
      "annual_cad": 1312313.29,
      "platform_cad": 6677.09,
      "labour_cad": 1305636.2,
      "host_cad": 6677.09,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6681680.17,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 3562458.31,
      "payback_months": 12.4,
      "delay_months": 12,
      "cost_per_app_cad": 109359.44,
      "cost_per_active_opener_cad": 312.46,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_aws",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "guide",
      "annual_cad": 1307202.42,
      "platform_cad": 1566.22,
      "labour_cad": 1305636.2,
      "host_cad": 1566.22,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6656125.85,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 3567569.17,
      "payback_months": 12.4,
      "delay_months": 12,
      "cost_per_app_cad": 108933.54,
      "cost_per_active_opener_cad": 311.24,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_lom",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "guide",
      "annual_cad": 1307717.89,
      "platform_cad": 2081.69,
      "labour_cad": 1305636.2,
      "host_cad": 2081.69,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6628674.75,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 3567053.71,
      "payback_months": 12.3,
      "delay_months": 12,
      "cost_per_app_cad": 108976.49,
      "cost_per_active_opener_cad": 311.36,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "dwan",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "guide",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6618266.29,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 3569135.4,
      "payback_months": 12.3,
      "delay_months": 12,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 310.87,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "m365",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "guide",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 5004.74,
      "one_time_weeks": 2,
      "five_year_cad": 6533185.74,
      "feasible": false,
      "feasibility_note": "A year of submissions is 213,615 rows. The list can store them. The SharePoint list view threshold is 5,000 items: a view that would return more than that without an index fails. That is not the Power Apps 500 / 2,000 delegation limit, which is separate.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 229.2,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "dataverse",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "guide",
      "annual_cad": 6140170.88,
      "platform_cad": 4834534.68,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 4834534.68,
      "guide_seat_cad": 4834534.68,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 30730882.83,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 511680.91,
      "cost_per_active_opener_cad": 1077.9,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "sql",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "guide",
      "annual_cad": 6144766.28,
      "platform_cad": 4839130.08,
      "labour_cad": 1305636.2,
      "host_cad": 4595.4,
      "seat_cad": 4834534.68,
      "guide_seat_cad": 4834534.68,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 30753859.82,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 512063.86,
      "cost_per_active_opener_cad": 1078.71,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "fabric",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "guide",
      "annual_cad": 6145127.29,
      "platform_cad": 4839491.09,
      "labour_cad": 1305636.2,
      "host_cad": 4956.41,
      "seat_cad": 4834534.68,
      "guide_seat_cad": 4834534.68,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 25023.69,
      "one_time_weeks": 10,
      "five_year_cad": 30750660.13,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 512093.94,
      "cost_per_active_opener_cad": 1078.77,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "lom_connector",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "guide",
      "annual_cad": 6140393.92,
      "platform_cad": 4834757.72,
      "labour_cad": 1305636.2,
      "host_cad": 223.04,
      "seat_cad": 4834534.68,
      "guide_seat_cad": 4834534.68,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 30731998.02,
      "feasible": false,
      "feasibility_note": "14,241 openers is past the author ceiling of 2,000 for one living document. Partition the documents or use SQL. That ceiling is not a measured limit.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 511699.49,
      "cost_per_active_opener_cad": 1077.94,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "web_azure",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "guide",
      "annual_cad": 1312313.29,
      "platform_cad": 6677.09,
      "labour_cad": 1305636.2,
      "host_cad": 6677.09,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6681680.17,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 4832452.99,
      "payback_months": 12.3,
      "delay_months": 12,
      "cost_per_app_cad": 109359.44,
      "cost_per_active_opener_cad": 230.38,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_aws",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "guide",
      "annual_cad": 1307202.42,
      "platform_cad": 1566.22,
      "labour_cad": 1305636.2,
      "host_cad": 1566.22,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6656125.85,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 4837563.85,
      "payback_months": 12.3,
      "delay_months": 12,
      "cost_per_app_cad": 108933.54,
      "cost_per_active_opener_cad": 229.48,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_lom",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "guide",
      "annual_cad": 1307717.89,
      "platform_cad": 2081.69,
      "labour_cad": 1305636.2,
      "host_cad": 2081.69,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6628674.75,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 4837048.39,
      "payback_months": 12.2,
      "delay_months": 12,
      "cost_per_app_cad": 108976.49,
      "cost_per_active_opener_cad": 229.57,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "dwan",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "guide",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6618266.29,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 4839130.08,
      "payback_months": 12.2,
      "delay_months": 12,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 229.2,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "m365",
      "scale": "directorate",
      "openers": 200,
      "licensing": "maker",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 5004.74,
      "one_time_weeks": 2,
      "five_year_cad": 6533185.74,
      "feasible": true,
      "feasibility_note": "A year of submissions is 3,000 rows, under the SharePoint 5,000-item view threshold. Separately, non-delegable Power Apps filters still see only the first 500 or 2,000 rows.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 16320.45,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "dataverse",
      "scale": "directorate",
      "openers": 200,
      "licensing": "maker",
      "annual_cad": 1309031.0,
      "platform_cad": 3394.8,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 3394.8,
      "guide_seat_cad": 67896.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6575183.43,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109085.92,
      "cost_per_active_opener_cad": 16362.89,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "sql",
      "scale": "directorate",
      "openers": 200,
      "licensing": "maker",
      "annual_cad": 1313626.4,
      "platform_cad": 7990.2,
      "labour_cad": 1305636.2,
      "host_cad": 4595.4,
      "seat_cad": 3394.8,
      "guide_seat_cad": 67896.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6598160.42,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109468.87,
      "cost_per_active_opener_cad": 16420.33,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "fabric",
      "scale": "directorate",
      "openers": 200,
      "licensing": "maker",
      "annual_cad": 1313987.41,
      "platform_cad": 8351.21,
      "labour_cad": 1305636.2,
      "host_cad": 4956.41,
      "seat_cad": 3394.8,
      "guide_seat_cad": 67896.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 25023.69,
      "one_time_weeks": 10,
      "five_year_cad": 6594960.73,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109498.95,
      "cost_per_active_opener_cad": 16424.84,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "lom_connector",
      "scale": "directorate",
      "openers": 200,
      "licensing": "maker",
      "annual_cad": 1309254.04,
      "platform_cad": 3617.84,
      "labour_cad": 1305636.2,
      "host_cad": 223.04,
      "seat_cad": 3394.8,
      "guide_seat_cad": 67896.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6576298.62,
      "feasible": true,
      "feasibility_note": "Inside the author ceiling for one living document. Concurrent writers remain a design limit.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109104.5,
      "cost_per_active_opener_cad": 16365.68,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_azure",
      "scale": "directorate",
      "openers": 200,
      "licensing": "maker",
      "annual_cad": 1312313.29,
      "platform_cad": 6677.09,
      "labour_cad": 1305636.2,
      "host_cad": 6677.09,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6681680.17,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 1313.11,
      "payback_months": 1109.7,
      "delay_months": 12,
      "cost_per_app_cad": 109359.44,
      "cost_per_active_opener_cad": 16403.92,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_aws",
      "scale": "directorate",
      "openers": 200,
      "licensing": "maker",
      "annual_cad": 1307202.42,
      "platform_cad": 1566.22,
      "labour_cad": 1305636.2,
      "host_cad": 1566.22,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6656125.85,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 6423.97,
      "payback_months": 236.4,
      "delay_months": 12,
      "cost_per_app_cad": 108933.54,
      "cost_per_active_opener_cad": 16340.03,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_lom",
      "scale": "directorate",
      "openers": 200,
      "licensing": "maker",
      "annual_cad": 1307717.89,
      "platform_cad": 2081.69,
      "labour_cad": 1305636.2,
      "host_cad": 2081.69,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6628674.75,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 5908.51,
      "payback_months": 195.0,
      "delay_months": 12,
      "cost_per_app_cad": 108976.49,
      "cost_per_active_opener_cad": 16346.47,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "dwan",
      "scale": "directorate",
      "openers": 200,
      "licensing": "maker",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6618266.29,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 7990.2,
      "payback_months": 147.3,
      "delay_months": 12,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 16320.45,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "m365",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "maker",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 5004.74,
      "one_time_weeks": 2,
      "five_year_cad": 6533185.74,
      "feasible": false,
      "feasibility_note": "A year of submissions is 157,500 rows. The list can store them. The SharePoint list view threshold is 5,000 items: a view that would return more than that without an index fails. That is not the Power Apps 500 / 2,000 delegation limit, which is separate.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 310.87,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "dataverse",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "maker",
      "annual_cad": 1309031.0,
      "platform_cad": 3394.8,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 3394.8,
      "guide_seat_cad": 3564540.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6575183.43,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109085.92,
      "cost_per_active_opener_cad": 311.67,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "sql",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "maker",
      "annual_cad": 1313626.4,
      "platform_cad": 7990.2,
      "labour_cad": 1305636.2,
      "host_cad": 4595.4,
      "seat_cad": 3394.8,
      "guide_seat_cad": 3564540.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6598160.42,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109468.87,
      "cost_per_active_opener_cad": 312.77,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "fabric",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "maker",
      "annual_cad": 1313987.41,
      "platform_cad": 8351.21,
      "labour_cad": 1305636.2,
      "host_cad": 4956.41,
      "seat_cad": 3394.8,
      "guide_seat_cad": 3564540.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 25023.69,
      "one_time_weeks": 10,
      "five_year_cad": 6594960.73,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109498.95,
      "cost_per_active_opener_cad": 312.85,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "lom_connector",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "maker",
      "annual_cad": 1309254.04,
      "platform_cad": 3617.84,
      "labour_cad": 1305636.2,
      "host_cad": 223.04,
      "seat_cad": 3394.8,
      "guide_seat_cad": 3564540.0,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6576298.62,
      "feasible": false,
      "feasibility_note": "10,500 openers is past the author ceiling of 2,000 for one living document. Partition the documents or use SQL. That ceiling is not a measured limit.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109104.5,
      "cost_per_active_opener_cad": 311.73,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "web_azure",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "maker",
      "annual_cad": 1312313.29,
      "platform_cad": 6677.09,
      "labour_cad": 1305636.2,
      "host_cad": 6677.09,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6681680.17,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 1313.11,
      "payback_months": 1109.7,
      "delay_months": 12,
      "cost_per_app_cad": 109359.44,
      "cost_per_active_opener_cad": 312.46,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_aws",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "maker",
      "annual_cad": 1307202.42,
      "platform_cad": 1566.22,
      "labour_cad": 1305636.2,
      "host_cad": 1566.22,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6656125.85,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 6423.97,
      "payback_months": 236.4,
      "delay_months": 12,
      "cost_per_app_cad": 108933.54,
      "cost_per_active_opener_cad": 311.24,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_lom",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "maker",
      "annual_cad": 1307717.89,
      "platform_cad": 2081.69,
      "labour_cad": 1305636.2,
      "host_cad": 2081.69,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6628674.75,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 5908.51,
      "payback_months": 195.0,
      "delay_months": 12,
      "cost_per_app_cad": 108976.49,
      "cost_per_active_opener_cad": 311.36,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "dwan",
      "scale": "vcds_2026",
      "openers": 10500,
      "licensing": "maker",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6618266.29,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 7990.2,
      "payback_months": 147.3,
      "delay_months": 12,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 310.87,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "m365",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "maker",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 5004.74,
      "one_time_weeks": 2,
      "five_year_cad": 6533185.74,
      "feasible": false,
      "feasibility_note": "A year of submissions is 213,615 rows. The list can store them. The SharePoint list view threshold is 5,000 items: a view that would return more than that without an index fails. That is not the Power Apps 500 / 2,000 delegation limit, which is separate.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 229.2,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "dataverse",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "maker",
      "annual_cad": 1309031.0,
      "platform_cad": 3394.8,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 3394.8,
      "guide_seat_cad": 4834534.68,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6575183.43,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109085.92,
      "cost_per_active_opener_cad": 229.8,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "sql",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "maker",
      "annual_cad": 1313626.4,
      "platform_cad": 7990.2,
      "labour_cad": 1305636.2,
      "host_cad": 4595.4,
      "seat_cad": 3394.8,
      "guide_seat_cad": 4834534.68,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6598160.42,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109468.87,
      "cost_per_active_opener_cad": 230.61,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "fabric",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "maker",
      "annual_cad": 1313987.41,
      "platform_cad": 8351.21,
      "labour_cad": 1305636.2,
      "host_cad": 4956.41,
      "seat_cad": 3394.8,
      "guide_seat_cad": 4834534.68,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 25023.69,
      "one_time_weeks": 10,
      "five_year_cad": 6594960.73,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109498.95,
      "cost_per_active_opener_cad": 230.67,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "lom_connector",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "maker",
      "annual_cad": 1309254.04,
      "platform_cad": 3617.84,
      "labour_cad": 1305636.2,
      "host_cad": 223.04,
      "seat_cad": 3394.8,
      "guide_seat_cad": 4834534.68,
      "maker_seat_cad": 3394.8,
      "one_time_cad": 30028.43,
      "one_time_weeks": 12,
      "five_year_cad": 6576298.62,
      "feasible": false,
      "feasibility_note": "14,241 openers is past the author ceiling of 2,000 for one living document. Partition the documents or use SQL. That ceiling is not a measured limit.",
      "licence_avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "cost_per_app_cad": 109104.5,
      "cost_per_active_opener_cad": 229.84,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": false,
      "cluster_annual": false,
      "sweet_five": false,
      "cluster_five": false
    },
    {
      "stack": "web_azure",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "maker",
      "annual_cad": 1312313.29,
      "platform_cad": 6677.09,
      "labour_cad": 1305636.2,
      "host_cad": 6677.09,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6681680.17,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 1313.11,
      "payback_months": 1109.7,
      "delay_months": 12,
      "cost_per_app_cad": 109359.44,
      "cost_per_active_opener_cad": 230.38,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_aws",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "maker",
      "annual_cad": 1307202.42,
      "platform_cad": 1566.22,
      "labour_cad": 1305636.2,
      "host_cad": 1566.22,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 120113.73,
      "one_time_weeks": 48,
      "five_year_cad": 6656125.85,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 6423.97,
      "payback_months": 236.4,
      "delay_months": 12,
      "cost_per_app_cad": 108933.54,
      "cost_per_active_opener_cad": 229.48,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "web_lom",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "maker",
      "annual_cad": 1307717.89,
      "platform_cad": 2081.69,
      "labour_cad": 1305636.2,
      "host_cad": 2081.69,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6628674.75,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 5908.51,
      "payback_months": 195.0,
      "delay_months": 12,
      "cost_per_app_cad": 108976.49,
      "cost_per_active_opener_cad": 229.57,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    },
    {
      "stack": "dwan",
      "scale": "vcds_2020",
      "openers": 14241,
      "licensing": "maker",
      "annual_cad": 1305636.2,
      "platform_cad": 0.0,
      "labour_cad": 1305636.2,
      "host_cad": 0.0,
      "seat_cad": 0.0,
      "guide_seat_cad": 0.0,
      "maker_seat_cad": 0.0,
      "one_time_cad": 90085.29,
      "one_time_weeks": 36,
      "five_year_cad": 6618266.29,
      "feasible": true,
      "feasibility_note": "No row-count ceiling in this model. Cost, not the list threshold, is the constraint.",
      "licence_avoided_cad": 7990.2,
      "payback_months": 147.3,
      "delay_months": 12,
      "cost_per_app_cad": 108803.02,
      "cost_per_active_opener_cad": 229.2,
      "you_are_here": false,
      "you_are_here_ai": false,
      "sweet_annual": true,
      "cluster_annual": true,
      "sweet_five": true,
      "cluster_five": true
    }
  ],
  "pieces": [
    {
      "id": "lists",
      "title": "Replace lists only. Screens stay in Power Apps.",
      "why": "The custom or premium connector remains. Under the licensing guide the per-opener seat does not go away. The build pays only if the 5,000-item view was the reason.",
      "weeks_low": 6,
      "weeks_mid": 12,
      "weeks_high": 16,
      "weeks_unadjusted": 12,
      "one_time_cad": 30028.43,
      "avoided_cad": 0.0,
      "payback_months": null,
      "delay_months": 1,
      "openers": 10500
    },
    {
      "id": "approvals",
      "title": "Replace Power Automate approvals only.",
      "why": "A per-flow licence covers the flow, not each person who opens an app. The minimum published purchase is five flows.",
      "weeks_low": 8,
      "weeks_mid": 14,
      "weeks_high": 20,
      "weeks_unadjusted": 14,
      "one_time_cad": 35033.17,
      "avoided_cad": 8487.0,
      "payback_months": 50.5,
      "delay_months": 1,
      "openers": 10500
    },
    {
      "id": "screens_sql",
      "title": "Replace screens and lists with a web app on SQL.",
      "why": "This is the build that drops the per-opener Power Apps seat. Later apps reuse the shell.",
      "weeks_low": 24,
      "weeks_mid": 48,
      "weeks_high": 80,
      "weeks_unadjusted": 48,
      "one_time_cad": 120113.73,
      "avoided_cad": 3562458.31,
      "payback_months": 12.4,
      "delay_months": 12,
      "openers": 10500
    },
    {
      "id": "screens_lom",
      "title": "Replace screens and lists with a web app on LOM.",
      "why": "Same seat drop, shorter weeks because the engine already exists. Still a new authorization. One document is the wrong shape at this size; the weeks assume the documents are partitioned.",
      "weeks_low": 16,
      "weeks_mid": 36,
      "weeks_high": 48,
      "weeks_unadjusted": 36,
      "one_time_cad": 90085.29,
      "avoided_cad": 3567053.71,
      "payback_months": 12.3,
      "delay_months": 12,
      "openers": 10500
    },
    {
      "id": "no_fabric",
      "title": "Do not buy Fabric. Stand up SQL.",
      "why": "Fabric is treated as a purchase not yet made. The avoided bill is the F2 capacity, not a migration.",
      "weeks_low": 2,
      "weeks_mid": 4,
      "weeks_high": 8,
      "weeks_unadjusted": 4,
      "one_time_cad": 10009.48,
      "avoided_cad": 4956.41,
      "payback_months": 25.2,
      "delay_months": 1,
      "openers": 10500
    }
  ],
  "headline": "At about 10,500 openers, under the licensing guide, the feasible stacks that are not a per-person Power Apps licence are: Custom web, SQL, Azure Canada; Custom web, PostgreSQL, AWS Canada (Central); Custom web, XML LOM, Azure Canada; DWAN virtual machine. They sit inside the salary-step noise ($58,972). There is no single cheapest host. Full M365 with SharePoint lists is where the team is, and a year of submissions at that size fails the SharePoint 5,000-item view threshold (not the Power Apps 500 / 2,000 delegation limit).",
  "effectiveness": {
    "historical": {
      "projects": 30,
      "years": 7,
      "rate": 4.29
    },
    "backlog": {
      "l2_count": 16,
      "processes_per_l2": {
        "low": 6,
        "mid": 10,
        "high": 14
      },
      "pan_vcds": {
        "low": 12,
        "mid": 20,
        "high": 30
      },
      "estate": {
        "low": 108,
        "mid": 180,
        "high": 254
      },
      "done": 30,
      "refresh": 15,
      "remaining": {
        "low": 87,
        "mid": 165,
        "high": 254
      },
      "method": "Sixteen VCDS organizations (January 2026 welcome package) times 6–14 digitizable approval or admin processes each, plus 12–30 pan-VCDS workflows. Subtract the 30 already done. Add a refresh of about half of those for 2030. DRMIS, classified C2, and pure records systems are out of scope. Author planning ranges, not a census.",
      "years_to_2030": 4.25,
      "origin": "2026-09-30",
      "january_2030": "2030-01-01",
      "january_2030_year": 3.255,
      "mandate": "CAF Digital Campaign Plan: digitally transformed by 2030."
    },
    "ai_tools_cad": {
      "low": 3225.06,
      "mid": 4243.5,
      "high": 6789.6
    },
    "compressible": 0.6,
    "scenarios": [
      {
        "id": "historical",
        "label": "Historical pace",
        "public": true,
        "base_rate": 4.29,
        "prior": null,
        "throughput_multiplier": 1.0,
        "projects_per_year": 4.29,
        "projects_by_2030": 18.2,
        "backlog_mid": 165,
        "gap_to_backlog": 146.8,
        "clears_mid_backlog": false,
        "meets": false,
        "people": null,
        "ai_tools_cad": 0.0,
        "annual_team_cad": 591888.41,
        "cost_per_project_cad": 138107.3,
        "assistant": "No coding assistant",
        "note": "30 projects in 7 years, about 4.3 a year. Labour in this row is today's loaded rate times the average headcount over the last 5 years you described (4.5 people), not today's ten. The two years before that headcount are not in the figure."
      },
      {
        "id": "vcr",
        "label": "Ten people, VCR pace",
        "public": true,
        "base_rate": 14.34,
        "prior": null,
        "throughput_multiplier": 1.0,
        "projects_per_year": 14.34,
        "projects_by_2030": 60.4,
        "backlog_mid": 165,
        "gap_to_backlog": 104.6,
        "clears_mid_backlog": false,
        "meets": false,
        "people": 10,
        "ai_tools_cad": 4243.5,
        "annual_team_cad": 1309879.7,
        "cost_per_project_cad": 91369.63,
        "assistant": "Coding assistant, measured pace",
        "note": "Does not clear the mid backlog at today's tool level. YAML on VCR moved about 5.2× faster per assisted hour than per person-hour in the 35 weekdays you and a student built the app. That applies only to the coding share. About 14 projects a year after the converter's shared shape is in use. Year 1 loses 18 person-weeks to finish conversion and check the legacy apps, not to a hand rebuild."
      },
      {
        "id": "hire",
        "label": "28 builders",
        "public": true,
        "base_rate": 40.14,
        "prior": null,
        "throughput_multiplier": 1.0,
        "projects_per_year": 40.14,
        "projects_by_2030": 169.2,
        "backlog_mid": 165,
        "gap_to_backlog": -4.2,
        "clears_mid_backlog": true,
        "meets": true,
        "people": 28,
        "ai_tools_cad": 11881.8,
        "annual_team_cad": 3667663.16,
        "cost_per_project_cad": 91369.63,
        "assistant": "Coding assistant, measured pace",
        "note": "Clears the mid backlog. Same pace per person as the VCR measurement, with the shell year. 18 people added to today's ten. Citation 23."
      },
      {
        "id": "borrow",
        "label": "Ten, then 45 people at half time",
        "public": true,
        "base_rate": 46.59,
        "prior": null,
        "throughput_multiplier": 1.0,
        "projects_per_year": 46.59,
        "projects_by_2030": 165.3,
        "backlog_mid": 165,
        "gap_to_backlog": -0.3,
        "clears_mid_backlog": true,
        "meets": true,
        "people": 55,
        "ai_tools_cad": 23339.25,
        "annual_team_cad": 4266656.9,
        "cost_per_project_cad": 91574.56,
        "assistant": "Coding assistant, measured pace",
        "note": "Clears the mid backlog. The ten build the shell in year 1. Then 45 people who already work elsewhere give half their time, at the same pace per hour. Costed at half a loaded salary each. Citation 24."
      },
      {
        "id": "l2ten",
        "label": "Ten builders, L2 help (clears the low line)",
        "public": true,
        "base_rate": 27.8,
        "prior": null,
        "throughput_multiplier": 1.0,
        "projects_per_year": 27.8,
        "projects_by_2030": 110.9,
        "backlog_mid": 165,
        "gap_to_backlog": 54.1,
        "clears_mid_backlog": false,
        "meets": false,
        "people": 10,
        "ai_tools_cad": 4243.5,
        "annual_team_cad": 1309879.7,
        "cost_per_project_cad": 47125.33,
        "assistant": "Coding assistant, measured pace",
        "note": "Clears the low backlog (87) and not the mid one. People who already run the process take bilingual text, acceptance, and process detail (25% of a project). No new builders. Citation 24."
      },
      {
        "id": "l2",
        "label": "15 builders, L2 staff carry acceptance",
        "public": true,
        "base_rate": 41.69,
        "prior": null,
        "throughput_multiplier": 1.0,
        "projects_per_year": 41.69,
        "projects_by_2030": 166.4,
        "backlog_mid": 165,
        "gap_to_backlog": -1.4,
        "clears_mid_backlog": true,
        "meets": true,
        "people": 15,
        "ai_tools_cad": 6365.25,
        "annual_team_cad": 1964819.55,
        "cost_per_project_cad": 47125.33,
        "assistant": "Coding assistant, measured pace",
        "note": "Clears the mid backlog. Same split of the work, with 15 builders instead of 28, because the non-coding quarter is no longer on the builders' clock. Citation 24."
      },
      {
        "id": "improve",
        "label": "Ten people, tools improve to 2030",
        "public": true,
        "base_rate": 19.23,
        "prior": null,
        "throughput_multiplier": 1.0,
        "projects_per_year": 19.23,
        "projects_by_2030": 81.7,
        "backlog_mid": 165,
        "gap_to_backlog": 83.3,
        "clears_mid_backlog": false,
        "meets": false,
        "people": 10,
        "ai_tools_cad": 5516.55,
        "annual_team_cad": 1311152.75,
        "cost_per_project_cad": 68183.41,
        "assistant": "Coding assistant, projected to improve",
        "note": "Projection, not a measurement. The coding-hour ratio moves from 5.16 now to 10 by end-2030, and the share that does not compress falls from 40% to 20% (DGDS stays human). Tool list price moves from US$25 to US$40 per builder a month. Average tool cost 5,517 CAD a year; the 2030 run rate is 6,790. Ending pace about 26 projects a year. Lands about 82 by 2030. Citation 23."
      },
      {
        "id": "improve_hire",
        "label": "21 builders, tools improve",
        "public": true,
        "base_rate": 40.52,
        "prior": null,
        "throughput_multiplier": 1.0,
        "projects_per_year": 40.52,
        "projects_by_2030": 172.2,
        "backlog_mid": 165,
        "gap_to_backlog": -7.2,
        "clears_mid_backlog": true,
        "meets": true,
        "people": 21,
        "ai_tools_cad": 11584.76,
        "annual_team_cad": 2753420.77,
        "cost_per_project_cad": 67954.08,
        "assistant": "Coding assistant, projected to improve",
        "note": "Clears the mid backlog if the same projection holds and these people are in place the whole run. 21 builders instead of 28 at today's tool level. Tool cost uses the average list price over the run. Citation 23."
      }
    ],
    "paths": [
      {
        "id": "vcr",
        "label": "Ten people — Coding assistant, measured pace",
        "assistant": "Coding assistant, measured pace",
        "meets": false,
        "public": true,
        "points": [
          {
            "year": 0.0,
            "projects": 0.0
          },
          {
            "year": 0.25,
            "projects": 3.46
          },
          {
            "year": 0.5,
            "projects": 6.92
          },
          {
            "year": 0.75,
            "projects": 10.38
          },
          {
            "year": 1.0,
            "projects": 13.84
          },
          {
            "year": 1.25,
            "projects": 17.43
          },
          {
            "year": 1.5,
            "projects": 21.01
          },
          {
            "year": 1.75,
            "projects": 24.59
          },
          {
            "year": 2.0,
            "projects": 28.18
          },
          {
            "year": 2.25,
            "projects": 31.76
          },
          {
            "year": 2.5,
            "projects": 35.35
          },
          {
            "year": 2.75,
            "projects": 38.93
          },
          {
            "year": 3.0,
            "projects": 42.51
          },
          {
            "year": 3.25,
            "projects": 46.1
          },
          {
            "year": 3.5,
            "projects": 49.68
          },
          {
            "year": 3.75,
            "projects": 53.27
          },
          {
            "year": 4.0,
            "projects": 56.85
          },
          {
            "year": 4.25,
            "projects": 60.43
          }
        ]
      },
      {
        "id": "l2ten",
        "label": "L2 help, ten builders — Coding assistant, measured pace",
        "assistant": "Coding assistant, measured pace",
        "meets": true,
        "public": true,
        "points": [
          {
            "year": 0.0,
            "projects": 0.0
          },
          {
            "year": 0.25,
            "projects": 5.15
          },
          {
            "year": 0.5,
            "projects": 10.29
          },
          {
            "year": 0.75,
            "projects": 15.44
          },
          {
            "year": 1.0,
            "projects": 20.59
          },
          {
            "year": 1.25,
            "projects": 27.54
          },
          {
            "year": 1.5,
            "projects": 34.49
          },
          {
            "year": 1.75,
            "projects": 41.44
          },
          {
            "year": 2.0,
            "projects": 48.39
          },
          {
            "year": 2.25,
            "projects": 55.33
          },
          {
            "year": 2.5,
            "projects": 62.28
          },
          {
            "year": 2.75,
            "projects": 69.23
          },
          {
            "year": 3.0,
            "projects": 76.18
          },
          {
            "year": 3.25,
            "projects": 83.13
          },
          {
            "year": 3.5,
            "projects": 90.08
          },
          {
            "year": 3.75,
            "projects": 97.03
          },
          {
            "year": 4.0,
            "projects": 103.98
          },
          {
            "year": 4.25,
            "projects": 110.93
          }
        ]
      },
      {
        "id": "hire",
        "label": "28 builders — Coding assistant, measured pace",
        "assistant": "Coding assistant, measured pace",
        "meets": true,
        "public": true,
        "points": [
          {
            "year": 0.0,
            "projects": 0.0
          },
          {
            "year": 0.25,
            "projects": 9.69
          },
          {
            "year": 0.5,
            "projects": 19.38
          },
          {
            "year": 0.75,
            "projects": 29.07
          },
          {
            "year": 1.0,
            "projects": 38.76
          },
          {
            "year": 1.25,
            "projects": 48.79
          },
          {
            "year": 1.5,
            "projects": 58.83
          },
          {
            "year": 1.75,
            "projects": 68.86
          },
          {
            "year": 2.0,
            "projects": 78.9
          },
          {
            "year": 2.25,
            "projects": 88.93
          },
          {
            "year": 2.5,
            "projects": 98.97
          },
          {
            "year": 2.75,
            "projects": 109.0
          },
          {
            "year": 3.0,
            "projects": 119.04
          },
          {
            "year": 3.25,
            "projects": 129.07
          },
          {
            "year": 3.5,
            "projects": 139.11
          },
          {
            "year": 3.75,
            "projects": 149.14
          },
          {
            "year": 4.0,
            "projects": 159.18
          },
          {
            "year": 4.25,
            "projects": 169.21
          }
        ]
      },
      {
        "id": "borrow",
        "label": "Half-time staff after year 1 — Coding assistant, measured pace",
        "assistant": "Coding assistant, measured pace",
        "meets": true,
        "public": true,
        "points": [
          {
            "year": 0.0,
            "projects": 0.0
          },
          {
            "year": 0.25,
            "projects": 3.46
          },
          {
            "year": 0.5,
            "projects": 6.92
          },
          {
            "year": 0.75,
            "projects": 10.38
          },
          {
            "year": 1.0,
            "projects": 13.84
          },
          {
            "year": 1.25,
            "projects": 25.49
          },
          {
            "year": 1.5,
            "projects": 37.14
          },
          {
            "year": 1.75,
            "projects": 48.79
          },
          {
            "year": 2.0,
            "projects": 60.43
          },
          {
            "year": 2.25,
            "projects": 72.08
          },
          {
            "year": 2.5,
            "projects": 83.73
          },
          {
            "year": 2.75,
            "projects": 95.38
          },
          {
            "year": 3.0,
            "projects": 107.03
          },
          {
            "year": 3.25,
            "projects": 118.67
          },
          {
            "year": 3.5,
            "projects": 130.32
          },
          {
            "year": 3.75,
            "projects": 141.97
          },
          {
            "year": 4.0,
            "projects": 153.62
          },
          {
            "year": 4.25,
            "projects": 165.27
          }
        ]
      },
      {
        "id": "l2",
        "label": "L2 help, 15 builders — Coding assistant, measured pace",
        "assistant": "Coding assistant, measured pace",
        "meets": true,
        "public": true,
        "points": [
          {
            "year": 0.0,
            "projects": 0.0
          },
          {
            "year": 0.25,
            "projects": 7.72
          },
          {
            "year": 0.5,
            "projects": 15.44
          },
          {
            "year": 0.75,
            "projects": 23.16
          },
          {
            "year": 1.0,
            "projects": 30.88
          },
          {
            "year": 1.25,
            "projects": 41.31
          },
          {
            "year": 1.5,
            "projects": 51.73
          },
          {
            "year": 1.75,
            "projects": 62.15
          },
          {
            "year": 2.0,
            "projects": 72.58
          },
          {
            "year": 2.25,
            "projects": 83.0
          },
          {
            "year": 2.5,
            "projects": 93.42
          },
          {
            "year": 2.75,
            "projects": 103.85
          },
          {
            "year": 3.0,
            "projects": 114.27
          },
          {
            "year": 3.25,
            "projects": 124.69
          },
          {
            "year": 3.5,
            "projects": 135.12
          },
          {
            "year": 3.75,
            "projects": 145.54
          },
          {
            "year": 4.0,
            "projects": 155.96
          },
          {
            "year": 4.25,
            "projects": 166.39
          }
        ]
      },
      {
        "id": "improve",
        "label": "Tools improve — Coding assistant, projected to improve",
        "assistant": "Coding assistant, projected to improve",
        "meets": false,
        "public": true,
        "points": [
          {
            "year": 0.0,
            "projects": 0.0
          },
          {
            "year": 0.25,
            "projects": 3.51
          },
          {
            "year": 0.5,
            "projects": 7.14
          },
          {
            "year": 0.75,
            "projects": 10.88
          },
          {
            "year": 1.0,
            "projects": 14.74
          },
          {
            "year": 1.25,
            "projects": 18.86
          },
          {
            "year": 1.5,
            "projects": 23.13
          },
          {
            "year": 1.75,
            "projects": 27.53
          },
          {
            "year": 2.0,
            "projects": 32.09
          },
          {
            "year": 2.25,
            "projects": 36.8
          },
          {
            "year": 2.5,
            "projects": 41.69
          },
          {
            "year": 2.75,
            "projects": 46.75
          },
          {
            "year": 3.0,
            "projects": 52.01
          },
          {
            "year": 3.25,
            "projects": 57.48
          },
          {
            "year": 3.5,
            "projects": 63.16
          },
          {
            "year": 3.75,
            "projects": 69.09
          },
          {
            "year": 4.0,
            "projects": 75.27
          },
          {
            "year": 4.25,
            "projects": 81.73
          }
        ]
      },
      {
        "id": "improve_hire",
        "label": "21 builders, tools improve — Coding assistant, projected to improve",
        "assistant": "Coding assistant, projected to improve",
        "meets": true,
        "public": true,
        "points": [
          {
            "year": 0.0,
            "projects": 0.0
          },
          {
            "year": 0.25,
            "projects": 7.52
          },
          {
            "year": 0.5,
            "projects": 15.27
          },
          {
            "year": 0.75,
            "projects": 23.27
          },
          {
            "year": 1.0,
            "projects": 31.53
          },
          {
            "year": 1.25,
            "projects": 40.19
          },
          {
            "year": 1.5,
            "projects": 49.14
          },
          {
            "year": 1.75,
            "projects": 58.39
          },
          {
            "year": 2.0,
            "projects": 67.96
          },
          {
            "year": 2.25,
            "projects": 77.87
          },
          {
            "year": 2.5,
            "projects": 88.13
          },
          {
            "year": 2.75,
            "projects": 98.76
          },
          {
            "year": 3.0,
            "projects": 109.81
          },
          {
            "year": 3.25,
            "projects": 121.28
          },
          {
            "year": 3.5,
            "projects": 133.22
          },
          {
            "year": 3.75,
            "projects": 145.66
          },
          {
            "year": 4.0,
            "projects": 158.64
          },
          {
            "year": 4.25,
            "projects": 172.21
          }
        ]
      }
    ],
    "assistant_key": "No coding assistant: historical pace only. Coding assistant, measured pace: builders use a commercial coding tool at the VCR hour ratio. That is the ten-person line, the extra builders, the half-time staff, and both L2-help lines. Coding assistant, projected to improve: the same kind of tool, with the coding-hour ratio and the human-only share moving through 2030. None of these is the app recommending an approval. That use is the automated-decision rule and applies on every stack.",
    "debt": {
      "sharepoint_apps": 20,
      "replatform_weeks": 18.0,
      "replatform_cad": 45042.65,
      "replatform_team_months": 0.4,
      "manual_weeks": 200,
      "manual_cad": 500473.86,
      "care_weeks_per_year": 45.0,
      "drag": 0.086,
      "note": "Of the 30 apps already built, 20 are SharePoint-list apps that fail the 5,000-item view if the whole L1 opens them. They are not rebuilt by hand. Power sweeper already moves screens, layout, labels, navigation, and theme between a Power App and a web app. Formulas are the part still being finished. Finishing that is 8 person-weeks, then 0.5 person-week to check each app: 18 person-weeks in total. A hand port at 10 person-weeks an app would have been 200 person-weeks. That figure is not the plan. Care of all 30 stays 1.5 person-weeks each per year, because a sweep does not sit with the owner. Author planning choices for the unfinished converter."
    },
    "plan": {
      "need_per_year_mid": 38.8,
      "need_per_year_low": 20.5,
      "one_team_after_shell": 14.34,
      "teams_mid_with_ai": 3,
      "teams_low_with_ai": 2,
      "teams_mid_no_ai": 6,
      "shell_lift": 1.35,
      "steps": [
        "Legacy apps are converted with Power sweeper, not rebuilt. 18 person-weeks to finish conversion and check 20 apps, against 200 if each were ported by hand.",
        "Tools are projected to improve: coding-hour ratio 5.16 now to 10 by 2030, and the human-only share from 40% to 20%. Ten people then land about 82 projects. The ending pace is about 26 a year. Tool list price rises from US$25 to US$40 per builder a month, about 6,790 CAD a year at the end, still small next to salary.",
        "At today's tool level, 28 builders clear the mid backlog. If tools improve as projected, 21 builders do.",
        "Other employees: after year 1, 45 people at half time clear the mid backlog at today's pace. If they instead take bilingual text, acceptance, and process detail, ten builders clear the low backlog and 15 builders clear the mid one.",
        "Do not put a premium connector in front of 10,500 openers. That spend does not add projects.",
        "Keep a human on any approval the app recommends."
      ],
      "ai_ceiling_by_2030": 73.4,
      "ai_cannot_alone": true,
      "hire_people": 28,
      "borrow_people": 45,
      "l2_builders": 15,
      "improve_by_2030": 81.7,
      "improve_end_rate": 26.4,
      "improve_people": 21,
      "ai_tools_2030_cad": 6789.6,
      "sweeper_weeks": 18.0,
      "manual_weeks": 200
    },
    "vcr": {
      "solo_people": 2,
      "solo_weekdays": 35,
      "solo_controls": 150,
      "solo_chars": 667856,
      "weekday_hours": 7.5,
      "ai_hours": 8.45,
      "ai_controls": 19,
      "ai_chars": 55451,
      "char_ratio": 5.16,
      "control_ratio": 7.87,
      "throughput_multiplier": 1.94,
      "note": "27 Jul remit file to the 11 Sep file: +150 controls and about 668,000 YAML characters, you and one student, no Cursor session editing that canvas. 21 Sep (16:49–18:26) added the overview and +7 controls. 25 Sep (09:25–16:15) added fiscal-year ids, the missing-request screen, acknowledgement mail rules, and +12 controls. Those two sessions are the assisted hours. Aug 21 was the dark-theme sweeper, not this app's screens."
    },
    "staffing": {
      "steps": [
        {
          "years": 2.0,
          "people": 2,
          "label": "Five years ago: two members"
        },
        {
          "years": 1.0,
          "people": 3,
          "label": "Three years ago: those two, plus the first student"
        },
        {
          "years": 1.0,
          "people": 5,
          "label": "Two years ago: team of five"
        },
        {
          "years": 1.0,
          "people": 8,
          "label": "One year ago: team of eight"
        }
      ],
      "staff_now": 10,
      "summer_students": 8,
      "summer_months": 4,
      "person_years": 22.7,
      "average_headcount": 4.53,
      "historical_team_cad": 591888.41
    },
    "headline": "Addressable remaining mid backlog about 165 VCDS approval-style projects by 2030 (87–254). Historical pace is 4.3 a year from 30 projects in 7 years, costed at an average 4.5 people over the last five years, not at today's ten. On VCR, assisted hours changed YAML about 5.2× faster than a person-hour in the six weeks you and a student built the body of the app. Ten people at that pace do not clear the mid backlog. If tools improve as projected, those ten land about 82 projects by 2030, and 21 builders clear the mid line. Legacy apps are converted with Power sweeper (18 person-weeks), not hand-ported (200 person-weeks)."
  },
  "tornado": [],
  "ai": null,
  "other_options": [
    {
      "name": "ServiceNow",
      "openers": 10500,
      "platform_cad": 30892.68,
      "with_labour_cad": 1336528.88,
      "note": "No published list price. US$70 per fulfiller per month is the low end of a 2026 third-party Foundation band, times 26 people: the ten builders plus one action officer in each of the 16 organizations. Requesters who only submit are not in that count. If every opener were a fulfiller the same rate would be $12,475,890 a year. Not a GC contract. Citation 31."
    },
    {
      "name": "DRMIS / SAP workflow",
      "openers": 10500,
      "platform_cad": 0.0,
      "with_labour_cad": 1305636.2,
      "note": "Platform increment is $0. The department already runs DRMIS, so a new approval inside it does not buy a second ERP. The cost that remains is this team's labour, and the calendar to change SAP. SAP Business One prices are a different product and are not used."
    },
    {
      "name": "GCDocs / OpenText",
      "openers": 10500,
      "platform_cad": 1312500.0,
      "with_labour_cad": 2618136.2,
      "note": "$125 per user per year is the 2016-17 cost-recovery figure for Shared Services Canada partners, times 10,500 openers. It is not a 2026 rate. If those people already have a GCDocs seat, the increment is $0 and the remaining cost is a thin app in front of the record. Citation 32."
    },
    {
      "name": "Power Pages",
      "openers": 10500,
      "platform_cad": 133670.25,
      "with_labour_cad": 1439306.45,
      "note": "June 2026 guide, authenticated users. 105 packs of 100 at US$75 a pack a month (tier 2, because 10,000 users is the threshold). Pay-as-you-go at US$4 per active authenticated user, if all 10,500 were active every month, would be $712,908. Same licensing guide as the Premium seat. Citation 4."
    },
    {
      "name": "Appian",
      "openers": 10500,
      "platform_cad": 13367025.0,
      "with_labour_cad": 14672661.2,
      "note": "No printed list price. Third-party figure of US$75 per user per month per app, times 10,500 openers. The same writeups describe an infrequent user near US$2, which would be $356,454 a year if it applied to every opener. Not an Appian invoice. Citation 33."
    },
    {
      "name": "Pega",
      "openers": 10500,
      "platform_cad": 26734050.0,
      "with_labour_cad": 28039686.2,
      "note": "No printed list price. Third-party named-user band starts at US$150 a month. That rate times 10,500 openers is the figure. The top of the same band is US$300. Not a Pega invoice. Citation 34."
    },
    {
      "name": "Camunda",
      "openers": 10500,
      "platform_cad": 2081.69,
      "with_labour_cad": 1307717.89,
      "note": "2026 production SaaS is a quote, so it is not given an invented price. The number is a self-managed engine at a zero licence (Camunda 7 is Apache-2.0) plus one Canada Central App Service P1v3, the same host already priced for a custom web app ($2,082). Citation 35 and citation 6."
    },
    {
      "name": "Approvals with no custom app",
      "openers": 10500,
      "platform_cad": 8487.0,
      "with_labour_cad": 1314123.2,
      "note": "Power Automate per-flow, June 2026 guide: US$100 per flow per month, minimum 5 flows. The flow is licensed, not each person who opens it. Same guide as the Premium seat. Citation 4."
    }
  ],
  "citations": [
    {
      "id": 1,
      "text": "Treasury Board IT collective agreement, rates in force 22 December 2024. IT-02 step 4 is $94,095, IT-03 step 4 is $111,817, IT-04 step 4 is $128,039. Weekly rate is annual divided by 52.176.",
      "url": "https://www.canada.ca/en/treasury-board-secretariat/topics/pay/collective-agreements/it.html"
    },
    {
      "id": 2,
      "text": "Treasury Board Guide to Costing. Employee benefits use the Annual Reference Level Update factor. Accommodation is included when the department is charged for it. Neither figure is one published national rate, so the 1.20 / 1.27 / 1.40 multipliers are planning factors.",
      "url": "https://www.tbs-sct.canada.ca/pol/doc-eng.aspx?id=32600"
    },
    {
      "id": 3,
      "text": "Bank of Canada Valet, FXUSDCAD daily average 1.4145 on 2026-09-25.",
      "url": "https://www.bankofcanada.ca/valet/observations/FXUSDCAD/json?recent=5"
    },
    {
      "id": 4,
      "text": "Microsoft Power Platform Licensing Guide, June 2026. Power Apps Premium US$20 per user per month, or US$12 with 2,000 or more new per-user licences. Pay-as-you-go US$10 per active user per app per month. Power Automate per flow US$100 per flow per month, minimum five. Power Pages authenticated capacity is US$200 per 100-user pack per month, or US$75 a pack once 100 packs (10,000 users) are bought; pay-as-you-go is US$4 per active authenticated user per website per month. Multiplexing does not remove user licences. This is the vendor’s rule, not a VCDS invoice.",
      "url": "https://cdn-dynmedia-1.microsoft.com/is/content/microsoftcorp/microsoft/bade/documents/products-and-services/en-us/bizapps/Power-Platform-Licensing-Guide-June-2026-PUB.pdf"
    },
    {
      "id": 5,
      "text": "Power Apps licence designation. Premium means a premium connector, a custom connector, or an on-premises gateway. The person who opens the app needs a per-app or per-user plan.",
      "url": "https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/license-designation"
    },
    {
      "id": 6,
      "text": "Azure Retail Prices API, Canada Central. Fabric capacity CU consumption US$0.20 per CU-hour. SQL Database general purpose 2 vCore US$0.36532 per hour (from 2025-06-01) and storage US$0.1265 per GB-month. App Service Premium v3 Linux P1v3 US$0.168 per hour. Basic B1 Linux US$0.018 per hour. F-SKU reservation rows were not used.",
      "url": "https://prices.azure.com/api/retail/prices"
    },
    {
      "id": 7,
      "text": "AWS public price list, Canada (Central). EC2 Linux t3.medium US$0.0464 per hour, file dated 2026-09-25. RDS for PostgreSQL db.t3.medium, file dated 2026-09-24, two on-demand rates US$0.08 and US$0.16 per hour with no deployment label. The model uses US$0.08.",
      "url": "https://aws.amazon.com/compliance/canada-data-privacy/"
    },
    {
      "id": 8,
      "text": "SharePoint list view threshold is 5,000 items. A list can store more. A view or query that would return more than 5,000 items without a supporting index fails. This is a SharePoint limit, not the Power Apps 500 / 2,000 delegation limit.",
      "url": "https://support.microsoft.com/office/manage-large-lists-and-libraries-in-sharepoint-b8588dae-9387-48c2-9248-c24122f07c59"
    },
    {
      "id": 9,
      "text": "Power Apps delegation is a different limit. Non-delegable filters run on the first 500 rows of a connected list, or 2,000 if the app limit is raised. They do not see the rest. Indexed SharePoint views can still hit the 5,000-item threshold separately.",
      "url": "https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/delegation-overview"
    },
    {
      "id": 10,
      "text": "Vice Chief of the Defence Staff, January 2026 deputy minister welcome package. Personnel about 10,500. Budget $447 million. Sixteen organizations.",
      "url": "https://www.canada.ca/en/department-national-defence/corporate/reports-publications/transition-materials/welcome-package-deputy-minister-nationa-defence-january-2026.html"
    },
    {
      "id": 11,
      "text": "Defence 101, March 2020. VCDS total employees about 14,241: 3,693 Regular Force (890 outside Canada), 200 Primary Reservists, 700 public service, 9,668 reserves supporting cadets.",
      "url": "https://www.canada.ca/en/department-national-defence/corporate/reports-publications/transition-materials/defence-101/2020/03/defence-101/vcds.html"
    },
    {
      "id": 12,
      "text": "DAOD 2006-0. Director General Defence Security is the Chief Security Officer and issues the National Defence Security Orders and Directives. DGDS is the authorization gate, not a hosting service.",
      "url": "https://www.canada.ca/en/department-national-defence/corporate/policies-standards/defence-administrative-orders-directives/2000-series/2006/2006-0-defence-security.html"
    },
    {
      "id": 13,
      "text": "TERMIUM Plus. DGDS is Director General Defence Security, the title to be used by National Defence and the Canadian Forces. An abolished dental title used the same letters. That sense is not used here.",
      "url": "https://www.btb.termiumplus.gc.ca/tpv2alpha/alpha-eng.html?lang=eng&index=enw&srchtxt=DGDS"
    },
    {
      "id": 14,
      "text": "Directive on Service and Digital. Storing Protected B data in Canada is the principal option. Storage outside Canada is a chief information officer decision against published criteria.",
      "url": "https://www.tbs-sct.canada.ca/pol/doc-eng.aspx?id=32601"
    },
    {
      "id": 15,
      "text": "Government of Canada Cloud Guardrails. Departments implement the guardrails before using Protected B cloud services. Shared Services Canada brokers the account.",
      "url": "https://www.tbs-sct.canada.ca/pol/doc-eng.aspx?id=32787"
    },
    {
      "id": 16,
      "text": "AWS CCCS Medium assessment. As of 12 September 2025, 162 services in Canada (Central) and Canada West. A service outside those regions is the department’s own compliance problem. The assessment is not the department’s authorization.",
      "url": "https://aws.amazon.com/compliance/cccs/"
    },
    {
      "id": 17,
      "text": "Microsoft CCCS Medium page. Azure, Microsoft 365, Dynamics 365, and Power Platform are in scope as listed there. Data at rest for many services can sit in Canada Central (Toronto) or Canada East (Quebec City). Microsoft says processing and support may still occur outside Canada.",
      "url": "https://learn.microsoft.com/en-us/compliance/regulatory/offering-cccs-medium"
    },
    {
      "id": 18,
      "text": "AWS Canada (Central) is the Montreal region. Canada West is Calgary. AWS says it does not move customer content out of the chosen region except to comply with law or a binding order. A backup in a US region is still a copy outside Canada.",
      "url": "https://aws.amazon.com/compliance/canada-data-privacy/"
    },
    {
      "id": 19,
      "text": "Government of Canada white paper on data sovereignty and the public cloud. A Canadian region of a US company’s cloud does not remove foreign legal process. Commercial public cloud is contemplated up to Protected B when the policy conditions hold. Classified data is outside this comparison.",
      "url": "https://www.canada.ca/en/government/system/digital-government/digital-government-innovations/cloud-services/digital-sovereignty/gc-white-paper-data-sovereignty-public-cloud.html"
    },
    {
      "id": 20,
      "text": "Treasury Board guide on the use of generative AI. Do not put personal or sensitive information into a public tool. Use a government-managed instance when the data is sensitive. Contract terms should cover residency and not training on the department’s prompts.",
      "url": "https://www.canada.ca/en/government/system/digital-government/digital-government-innovations/responsible-use-ai/guide-use-generative-ai.html"
    },
    {
      "id": 21,
      "text": "Directive on Automated Decision-Making. An Algorithmic Impact Assessment is required when a system makes or informs an administrative decision. Summarizing a file or recommending an approval can be that system. The host does not remove the directive.",
      "url": "https://www.tbs-sct.canada.ca/pol/doc-eng.aspx?id=32592"
    },
    {
      "id": 22,
      "text": "Digital Services Group, January 2026 deputy minister welcome package and the Level 1 mandate note. ADM(DS) owns the defence digital foundation. The mandate note records a stated need of $100 million a year for foundations including cloud from Designated to Top Secret. That is not a chargeback rate for this team.",
      "url": "https://www.canada.ca/en/department-national-defence/corporate/reports-publications/proactive-disclosure/welcome-package-minister-national-defence/overview-level-1-organizational-mandates-priorities-assistant-deputy-minister.html"
    },
    {
      "id": 23,
      "text": "Headcount paths that clear the 2030 backlog. They extend the measured VCR pace. They are not a second measurement. Legacy apps are converted with Power sweeper (structural export already runs; formula conversion is the part still being finished), not hand-rebuilt. The assistant is projected to improve from that measured pace to about twice the YAML-hour ratio by 2030, and tool list price is projected up to the top of the present band. Both projections are planning choices.",
      "url": ""
    },
    {
      "id": 24,
      "text": "Borrowed staff are costed at half a loaded salary. The other half of their job stays where they are. One quarter of each project (bilingual text, acceptance, process knowledge) can sit with those people. Security packages and records stay with the builders. Both fractions are author planning choices.",
      "url": ""
    },
    {
      "id": 25,
      "text": "GC Cloud One. Shared Services Canada brokers public cloud and a GC Private Cloud. The private-cloud price is not public. Sandbox environments are unclassified and short-term.",
      "url": "https://sharedservicescanada.my.site.com/ssccbs/s/gc-cloud-one?language=en_US"
    },
    {
      "id": 26,
      "text": "Living Object Model paper in the LOM tree. LOM is one in-process document, not a multi-document database. A custom connector still counts as premium under the licensing guide while the screen is a Power App.",
      "url": "https://github.com/freementls/LOM"
    },
    {
      "id": 27,
      "text": "Author planning assumptions, marked as such wherever they are used: team mix, 15 submissions a person a year, 40% monthly active, 1.5 apps touched in an active month, 12 apps in maintenance, person-week ranges, DGDS calendar of 1 month inside the tenant and 12 months (range 6 to 18) for a new boundary, LOM single-document ceiling of 2,000 openers, 32 GB SQL database, maintenance drawn from the same ten people, current-team delivery rate of about 6 apps a year (band 4–8), VCDS process counts per L2, AI tool price band.",
      "url": ""
    },
    {
      "id": 28,
      "text": "Canadian Armed Forces Digital Campaign Plan. Vision: digitally transformed CAF by 2030. VCDS has served as CAF Digital Champion. Corporate-space stewardship is part of that vision, which is the lane for approval and admin apps.",
      "url": "https://www.canada.ca/en/department-national-defence/corporate/reports-publications/canadian-armed-forces-digital-campaign-plan.html"
    },
    {
      "id": 29,
      "text": "Author observation: about 30 approval-style projects in about 7 years. Headcount stated for the last five of those years: two members, then the first student, then five, then eight, then about ten staff, with eight students for a summer. Not a published departmental statistic. The VCR pace is from saved CDLS (L) VCR .msapp copies (27 July and 11 September 2026) and the Cursor sessions on 21 and 25 September 2026 that edited them.",
      "url": ""
    },
    {
      "id": 30,
      "text": "AI coding-assistant list prices used as a band: about US$19 to US$40 per builder per month (Copilot Business–class to Cursor Business–class). Converted at the dated Bank of Canada rate. Not a VCDS invoice. Tiny next to loaded labour.",
      "url": ""
    },
    {
      "id": 31,
      "text": "ServiceNow’s ITSM pricing page lists packages and asks for a quote. It does not publish a per-user price. Third-party 2026 estimates put the Foundation tier at about US$70 to US$100 per fulfiller per month, and describe requesters who only submit as unpaid. This model uses US$70. Not a ServiceNow list price and not a GC contract.",
      "url": "https://www.servicenow.com/products/itsm/pricing.html"
    },
    {
      "id": 32,
      "text": "House of Commons committee evidence, 2016. GCDocs cost-recovery for 2016-17 was stated as $125 per user for Shared Services Canada partners and $95 for others. That is the last public per-user figure used here. It is not a 2026 rate.",
      "url": "https://publications.gc.ca/collections/collection_2016/parl/xc73-1/XC73-1-2-421-13-eng.pdf"
    },
    {
      "id": 33,
      "text": "Appian’s pricing page says the platform is priced per user, per month, per app, and does not print a dollar. Third-party 2026 writeups put a standard business user near US$75 a month and an infrequent user near US$2. Those are not Appian list prices.",
      "url": "https://appian.com/products/pricing"
    },
    {
      "id": 34,
      "text": "Pega does not publish a named-user list price. Third-party 2026 writeups put the platform near US$150 to US$300 per named user per month. This model uses US$150. Not a Pega list price.",
      "url": "https://www.pega.com/"
    },
    {
      "id": 35,
      "text": "Camunda’s 2026 pricing page offers a free trial and an enterprise quote. It does not print a production price. Camunda 7 is Apache-2.0. The host dollar on this row is the same Canada Central App Service P1v3 already used for a custom web app, not a Camunda invoice.",
      "url": "https://camunda.com/pricing/"
    }
  ],
  "you_are_here": {
    "stack": "m365",
    "scale": "vcds_2026"
  }
};
