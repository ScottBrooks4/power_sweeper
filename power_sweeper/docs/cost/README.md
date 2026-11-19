# VCDS approval-app costs

These are estimates with citations.

Open [public.html](public.html) in a browser. Tabs: **2030 effectiveness** (backlog, AI cost, cost per project), **Stack cost** (the 3D / bar chart), **Build payback**. The internal page is generated beside it and is gitignored.

```bash
python3 docs/cost/model.py
```

That rewrites the CSV files, both page data files, both print copies, and both Power BI projects from [model.py](model.py).

## Pages

- `public.html` — licensing guide, no AI annex.
- `internal.html` — same layout, plus the tornado and the productivity annex. Not for the public remote.

The first line of each page is the disclaimer. Click a bar, or a block in the 3D chart. The detail and the matching citations highlight. The premium-seat control switches the chart to the maker-only counterfactual. That counterfactual is Microsoft’s rule set aside, not a second invoice.

## Power BI

`approval-apps.pbip` is already a Desktop project bound to `data/public_scenarios.csv`. The webpage does not embed it. The charts on the page are the interactive view. The internal project is `approval-apps-internal.pbip`.

The CSV path inside the semantic model is absolute on the machine that last ran the script. If Desktop is on another machine, change `File.Contents` in `approval-apps.SemanticModel/definition/tables/Scenarios.tmdl`.

## What is an assumption

Salary rates, the exchange rate, and the cloud unit prices are dated in the citations. Person-weeks, the DGDS calendar, the 15 submissions a person a year, and the loaded-cost multipliers are author planning factors. The Guide to Costing points at an ARLU benefit rate and an accommodation charge that are not one national published number. SSC broker markups and ADM(DS) chargeback are omitted rather than invented.
