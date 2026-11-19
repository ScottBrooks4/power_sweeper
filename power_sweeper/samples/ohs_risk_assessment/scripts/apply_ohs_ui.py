#!/usr/bin/env python3
"""Apply OHS UI: one sidebar, dual-mode risk grid, themed filter pills, drop TDR leftovers."""
from __future__ import annotations

import json
import re
import shutil
from copy import deepcopy
from pathlib import Path

ROOT = Path("/srv/http/power_sweeper/samples/ohs_risk_assessment/app")
SRC = ROOT / "Src"
UID = 40000

TDR_FLOW_NOTIFY = (
    'Notify("OHS data refresh is not wired to the TDR import flow.", '
    "NotificationType.Information, 3000)"
)


def next_uid() -> str:
    global UID
    UID += 1
    return str(UID)


def walk(node, fn=None):
    if fn:
        fn(node)
    yield node
    for child in node.get("Children") or []:
        yield from walk(child, fn)
    info = ((node.get("Template") or {}).get("ComponentDefinitionInfo") or {})
    for child in info.get("Children") or []:
        yield from walk(child, fn)


def find_named(root: dict, name: str) -> dict:
    for node in walk(root):
        if node.get("Name") == name:
            return node
    raise KeyError(name)


def find_template(root: dict, template_name: str) -> dict:
    for node in walk(root):
        if (node.get("Template") or {}).get("Name") == template_name:
            return node
    raise KeyError(template_name)


def set_rule(node: dict, prop: str, script: str, category: str | None = None) -> None:
    cats = {
        "OnSelect": "Behavior",
        "OnVisible": "Behavior",
        "OnChange": "Behavior",
        "OnReset": "Behavior",
        "Text": "Data",
        "Items": "Data",
        "Image": "Data",
        "Tooltip": "Data",
        "HtmlText": "Data",
        "Mode": "Data",
        "HighlightP": "Data",
        "HighlightS": "Data",
        "SelectedP": "Data",
        "SelectedS": "Data",
    }
    if category is None:
        category = cats.get(prop, "Design")
    for rule in node.get("Rules") or []:
        if rule.get("Property") == prop:
            rule["InvariantScript"] = script
            return
    node.setdefault("Rules", []).append(
        {
            "Property": prop,
            "Category": category,
            "InvariantScript": script,
            "RuleProviderType": "User",
        }
    )


def get_rule(node: dict, prop: str) -> str | None:
    for rule in node.get("Rules") or []:
        if rule.get("Property") == prop:
            return rule.get("InvariantScript")
    return None


def yaml_formula_block(path: Path, key: str) -> str:
    lines = path.read_text().splitlines()
    start = None
    for i, line in enumerate(lines):
        if line.startswith(f"    {key}: |-"):
            start = i + 1
            break
    if start is None:
        raise SystemExit(f"{key} block not found in {path}")
    body = []
    for line in lines[start:]:
        if line.startswith("      "):
            body.append(line[6:])
        elif line.strip() == "":
            body.append("")
        else:
            break
    while body and body[-1] == "":
        body.pop()
    if body and body[0].startswith("="):
        body[0] = body[0][1:]
    return "\n".join(body)


def place(node: dict, index: int, z: int) -> dict:
    node["Index"] = index
    node["PublishOrderIndex"] = z
    set_rule(node, "ZIndex", str(z))
    return node


def clone_control(template: dict, name: str, parent: str, **props) -> dict:
    node = deepcopy(template)
    node["Name"] = name
    node["Parent"] = parent
    node["ControlUniqueId"] = next_uid()
    node["Children"] = []
    node["HasDynamicProperties"] = False
    for key, value in props.items():
        set_rule(node, key, value)
    return node


def make_component_instance(definition: dict, name: str, parent: str, **props) -> dict:
    inst = deepcopy(definition)
    inst["Name"] = name
    inst["Parent"] = parent
    inst["ControlUniqueId"] = next_uid()
    inst["HasDynamicProperties"] = False
    tmpl = inst.setdefault("Template", {})
    tmpl["IsComponentDefinition"] = False
    tmpl["ComponentType"] = "CanvasComponent"
    children = inst.pop("Children", []) or []
    tmpl["ComponentDefinitionInfo"] = {
        "Name": definition.get("Name"),
        "LastModifiedTimestamp": tmpl.get("LastModifiedTimestamp", "0"),
        "AllowAccessToGlobals": True,
        "Rules": deepcopy(definition.get("Rules", [])),
        "ControlPropertyState": deepcopy(definition.get("ControlPropertyState", [])),
        "CustomProperties": deepcopy(tmpl.get("CustomProperties") or []),
        "Children": children,
    }
    inst["Children"] = []
    for key, value in props.items():
        set_rule(inst, key, value)
    return inst


def yaml_escape(text: str) -> str:
    return text.replace('"', '\\"')


FILTER_ONSELECT = {
    "colFilters": """UpdateIf(colFilters, true, {Selected: false});
                    Patch(colFilters, ThisItem, {Selected: true});
                    If(
                        ThisItem.Kind = "L2",
                        Set(varL2, ThisItem.Value),
                        ThisItem.Kind = "Program",
                        Set(varProgram, ThisItem.Value),
                        Set(varHighOnly, ThisItem.Value = "High + Extreme")
                    )""",
    "colInboxFilters": """UpdateIf(colInboxFilters, true, {Selected: false});
                    Patch(colInboxFilters, ThisItem, {Selected: true})""",
}


def yaml_filter_gallery(name: str, btn: str, items: str, y: str) -> str:
    onselect = FILTER_ONSELECT[items]
    return f"""      - {name}:
          Control: Gallery@2.15.0
          Variant: Horizontal
          Properties:
            BorderColor: =RGBA(0, 0, 0, 0)
            Fill: =RGBA(0, 0, 0, 0)
            Height: =36
            Items: ={items}
            TemplatePadding: =0
            TemplateSize: =124
            Width: =App.Width - gblNavWidth - 44
            X: =gblNavWidth + 22
            Y: ={y}
            ZIndex: =50
            Layout: =Layout.Horizontal
          Children:
            - {btn}:
                Control: Classic/Button@2.2.0
                Properties:
                  BorderColor: =If(ThisItem.Selected, gblTheme.ChipOn, gblTheme.Border)
                  BorderThickness: =1
                  Color: =If(ThisItem.Selected, gblTheme.ChipOnInk, gblTheme.Text)
                  Fill: =If(ThisItem.Selected, gblTheme.ChipOn, gblTheme.Chip)
                  Font: =Font.'Segoe UI'
                  FontWeight: =FontWeight.Semibold
                  Height: =28
                  HoverFill: =If(ThisItem.Selected, ColorFade(gblTheme.ChipOn, 10%), ColorFade(gblTheme.Chip, -6%))
                  OnSelect: |-
                    ={onselect}
                  RadiusBottomLeft: =gblRadiusPill
                  RadiusBottomRight: =gblRadiusPill
                  RadiusTopLeft: =gblRadiusPill
                  RadiusTopRight: =gblRadiusPill
                  Size: =10
                  Text: =ThisItem.Value
                  Width: =Parent.TemplateWidth - 8
                  X: =0
                  Y: =4"""


def yaml_screen(spec: dict) -> str:
    kids = []
    kids.append(
        f"""      - {spec['image_ctl']}:
          Control: Image@2.2.3
          Properties:
            DisplayMode: =DisplayMode.View
            Height: =App.Height
            Image: ={spec['image']}
            ImagePosition: =ImagePosition.Fit
            Width: =App.Width
            X: =0
            Y: =0
            ZIndex: =1"""
    )
    kids.append(
        f"""      - {spec['sidebar']}:
          Control: CanvasComponent
          ComponentName: SidebarMenu
          Properties:
            Height: =App.Height
            Visible: =true
            Width: =gblNavWidth
            X: =0
            Y: =0
            ZIndex: =90"""
    )
    if spec.get("filters"):
        f = spec["filters"]
        kids.append(yaml_filter_gallery(f["gallery"], f["button"], f["items"], f["y"]))
    if spec.get("grid"):
        g = spec["grid"]
        kids.append(
            f"""      - {g['name']}:
          Control: CanvasComponent
          ComponentName: cmpRiskGrid
          Properties:
            Fill: =gblTheme.Paper
            Height: =248
            HighlightP: ={g.get('highlight_p', '0')}
            HighlightS: ={g.get('highlight_s', '0')}
            Mode: =\"{g['mode']}\"
            Width: =320
            X: ={g['x']}
            Y: ={g['y']}
            ZIndex: =40"""
        )
    for b in spec["buttons"]:
        kids.append(
            f"""      - {b['name']}:
          Control: Classic/Button@2.2.0
          Properties:
            BorderColor: =gblTheme.Border
            Color: ={b.get('color', 'gblTheme.TextOnAccent')}
            Fill: ={b['fill']}
            Font: =Font.'Segoe UI'
            FontWeight: =FontWeight.Semibold
            Height: =36
            HoverFill: =ColorFade({b['fill']}, -10%)
            OnSelect: ={b['onselect']}
            RadiusBottomLeft: =gblRadius
            RadiusBottomRight: =gblRadius
            RadiusTopLeft: =gblRadius
            RadiusTopRight: =gblRadius
            Size: =12
            Text: =\"{yaml_escape(b['text'])}\"
            Width: ={b.get('width', 140)}
            X: ={b['x']}
            Y: ={b['y']}
            ZIndex: =60"""
        )
    for lab in spec["labels"]:
        kids.append(
            f"""      - {lab['name']}:
          Control: Label@2.5.1
          Properties:
            Color: ={lab.get('color', 'gblTheme.Text')}
            Font: =Font.'Segoe UI'
            FontWeight: ={lab.get('weight', 'FontWeight.Semibold')}
            Height: ={lab.get('height', 32)}
            Size: ={lab.get('size', 14)}
            Text: =\"{yaml_escape(lab['text'])}\"
            Width: ={lab.get('width', 640)}
            X: ={lab['x']}
            Y: ={lab['y']}
            ZIndex: =50"""
        )
    body = "\n".join(kids)
    return f"""# ************************************************************************************************
# Warning: YAML source code for Canvas Apps should only be used to review changes made within Power Apps Studio and for minor edits (Preview).
# Use the maker portal to create and edit your Power Apps.
# ************************************************************************************************
Screens:
  {spec['screen']}:
    Properties:
      Fill: =gblTheme.Page
      LoadingSpinnerColor: =gblTheme.Accent
    Children:
{body}
"""


SPECS = [
    {
        "screen": "Dashboard",
        "json": "Controls/226.json",
        "yaml": "Src/Dashboard.pa.yaml",
        "image": "MockupDashboard",
        "image_ctl": "imgMockupDashboard",
        "sidebar": "SidebarMenu_5",
        "filters": {"gallery": "galDashFilters", "button": "btnDashFilter", "items": "colFilters", "y": "148"},
        "grid": {
            "name": "cmpRiskGrid_Dashboard",
            "x": "gblNavWidth + 22",
            "y": "200",
            "mode": "Overview",
            "highlight_p": "0",
            "highlight_s": "0",
        },
        "labels": [
            {"name": "lblDashCrumb", "text": "Leadership view · PIP dashboard", "x": "gblNavWidth + 22", "y": "18", "size": 10, "color": "gblTheme.TextMuted", "weight": "FontWeight.Normal", "height": 18},
            {"name": "lblDashTitle", "text": "Risk picture", "x": "gblNavWidth + 22", "y": "36", "size": 21, "width": 400, "height": 36},
        ],
        "buttons": [
            {"name": "btnDashAlerts", "text": "Alerts", "fill": "gblTheme.Quiet", "color": "gblTheme.Text", "onselect": "Navigate(Homepage, ScreenTransition.Fade)", "x": "App.Width - 280", "y": "22", "width": 90},
            {"name": "btnDashIdentify", "text": "Identify risk", "fill": "gblTheme.Accent", "onselect": "Navigate(Homepage, ScreenTransition.Fade)", "x": "App.Width - 178", "y": "22", "width": 150},
        ],
    },
    {
        "screen": "Homepage",
        "json": "Controls/113.json",
        "yaml": "Src/Homepage.pa.yaml",
        "image": "MockupInbox",
        "image_ctl": "imgMockupInbox",
        "sidebar": "SidebarMenu_Home",
        "filters": {"gallery": "galInboxFilters", "button": "btnInboxFilter", "items": "colInboxFilters", "y": "168"},
        "grid": None,
        "labels": [
            {"name": "lblInboxCrumb", "text": "Role · L1 Safety Advisor", "x": "gblNavWidth + 22", "y": "18", "size": 10, "color": "gblTheme.TextMuted", "weight": "FontWeight.Normal", "height": 18},
            {"name": "lblInboxTitle", "text": "New risks identified", "x": "gblNavWidth + 22", "y": "36", "size": 21, "width": 520, "height": 36},
        ],
        "buttons": [
            {"name": "btnNotifyOPIs", "text": "Notify OPIs", "fill": "gblTheme.Quiet", "color": "gblTheme.Text", "onselect": 'Notify("OPIs notified for this cycle.", NotificationType.Success)', "x": "App.Width - 300", "y": "22", "width": 120},
            {"name": "btnInboxReview", "text": "Open review", "fill": "gblTheme.Accent", "onselect": "Navigate(Details, ScreenTransition.Fade)", "x": "App.Width - 168", "y": "22", "width": 140},
        ],
    },
    {
        "screen": "Details",
        "json": "Controls/151.json",
        "yaml": "Src/Details.pa.yaml",
        "image": "MockupReview",
        "image_ctl": "imgMockupReview",
        "sidebar": "SidebarMenu_1",
        "filters": None,
        "grid": {
            "name": "cmpRiskGrid_Review",
            "x": "App.Width - 360",
            "y": "188",
            "mode": "Select",
            "highlight_p": "varHighlightP",
            "highlight_s": "varHighlightS",
        },
        "labels": [
            {"name": "lblReviewCrumb", "text": "Review & action · CFSG (O-G)", "x": "gblNavWidth + 22", "y": "18", "size": 10, "color": "gblTheme.TextMuted", "weight": "FontWeight.Normal", "height": 18},
            {"name": "lblReviewTitle", "text": "CFSG(O-G) · RA-0007-HS", "x": "gblNavWidth + 22", "y": "36", "size": 21, "width": 520, "height": 36},
        ],
        "buttons": [
            {"name": "btnContactOPI", "text": "Contact OPI", "fill": "gblTheme.Quiet", "color": "gblTheme.Text", "onselect": 'Notify("Contact Luc Joly · CFSU.O.SO.requirements@forces.gc.ca", NotificationType.Information)', "x": "App.Width - 300", "y": "22", "width": 120},
            {"name": "btnSendTreat", "text": "Send to Treat", "fill": "gblTheme.Accent", "onselect": "Navigate(Approvals, ScreenTransition.Fade)", "x": "App.Width - 168", "y": "22", "width": 140},
        ],
    },
    {
        "screen": "Approvals",
        "json": "Controls/342.json",
        "yaml": "Src/Approvals.pa.yaml",
        "image": "MockupTreat",
        "image_ctl": "imgMockupTreat",
        "sidebar": "SidebarMenu_6",
        "filters": None,
        "grid": {
            "name": "cmpRiskGrid_Treat",
            "x": "gblNavWidth + 28",
            "y": "420",
            "mode": "Select",
            "highlight_p": "varHighlightP",
            "highlight_s": "varHighlightS",
        },
        "labels": [
            {"name": "lblTreatCrumb", "text": "Treat · mitigate · archive", "x": "gblNavWidth + 22", "y": "18", "size": 10, "color": "gblTheme.TextMuted", "weight": "FontWeight.Normal", "height": 18},
            {"name": "lblTreatTitle", "text": "Hazardous substances program", "x": "gblNavWidth + 22", "y": "36", "size": 21, "width": 560, "height": 36},
        ],
        "buttons": [
            {"name": "btnReassess", "text": "Reassess score", "fill": "gblTheme.Quiet", "color": "gblTheme.Text", "onselect": "Navigate(Details, ScreenTransition.Fade)", "x": "App.Width - 320", "y": "22", "width": 140},
            {"name": "btnArchive", "text": "Close & archive", "fill": "gblTheme.Danger", "onselect": 'Notify("Record queued for archive / active monitoring.", NotificationType.Success); Navigate(Dashboard, ScreenTransition.Fade)', "x": "App.Width - 168", "y": "22", "width": 140},
        ],
    },
]


CUSTOM_PROP = lambda name, kind, tooltip, hidden=False: {
    "Name": name,
    "Category": 0,
    "Type": json.dumps(
        {"Version": "1.349", "Type": {"Name": "Root", "Kind": kind, "EnumString": "", "Type": []}},
        separators=(",", ":"),
    ),
    "PropertyDataTypeKey": "String" if kind == "String" else "Number",
    "Hidden": hidden,
    "DisplayName": name,
    "Tooltip": tooltip,
}


def patch_risk_grid(comp: dict) -> None:
    tmpl = comp.setdefault("Template", {})
    tmpl["CustomProperties"] = [
        CUSTOM_PROP("Mode", "String", "Overview shows counts per P×S cell; Select lets the user pick a score"),
        CUSTOM_PROP("HighlightP", "Number", "Probability (1–5) to outline in Select mode"),
        CUSTOM_PROP("HighlightS", "Number", "Severity (1–5) to outline in Select mode"),
        CUSTOM_PROP("SelectedP", "Number", "Probability of the selected cell", hidden=True),
        CUSTOM_PROP("SelectedS", "Number", "Severity of the selected cell", hidden=True),
    ]
    set_rule(
        comp,
        "OnReset",
        "ClearCollect(\r\n    colRiskCells,\r\n    ForAll(\r\n        Sequence(25) As i,\r\n        With(\r\n            {\r\n                p: 6 - RoundUp(i.Value / 5, 0),\r\n                s: If(Mod(i.Value, 5) = 0, 5, Mod(i.Value, 5))\r\n            },\r\n            {\r\n                Probability: p,\r\n                Severity: s,\r\n                Score: p * s,\r\n                Count: Coalesce(\r\n                    LookUp(colRiskCounts, Probability = p && Severity = s).Count,\r\n                    0\r\n                )\r\n            }\r\n        )\r\n    )\r\n)",
        "Behavior",
    )
    set_rule(comp, "Mode", '"Select"', "Data")
    set_rule(comp, "HighlightP", "0", "Data")
    set_rule(comp, "HighlightS", "0", "Data")
    set_rule(comp, "SelectedP", "galRiskCells.Selected.Probability", "Data")
    set_rule(comp, "SelectedS", "galRiskCells.Selected.Severity", "Data")
    state = comp.setdefault("ControlPropertyState", [])
    for prop in ("Mode", "HighlightP", "HighlightS", "SelectedP", "SelectedS"):
        if not any(
            (isinstance(s, dict) and s.get("InvariantPropertyName") == prop) or s == prop
            for s in state
        ):
            state.append(
                {
                    "InvariantPropertyName": prop,
                    "AutoRuleBindingEnabled": False,
                    "AutoRuleBindingString": "",
                    "NameMapSourceSchema": "?",
                    "IsLockable": False,
                    "AFDDataSourceName": "",
                }
            )
    btn = find_named(comp, "btnCell")
    set_rule(
        btn,
        "Text",
        'If(cmpRiskGrid.Mode = "Overview", Text(Coalesce(ThisItem.Count, 0)), Text(ThisItem.Score))',
        "Data",
    )
    set_rule(
        btn,
        "Fill",
        'With(\r\n    {\r\n        base: fxRiskFill(ThisItem.Score),\r\n        empty: cmpRiskGrid.Mode = "Overview" && Coalesce(ThisItem.Count, 0) = 0\r\n    },\r\n    If(empty, ColorFade(base, 70%), base)\r\n)',
    )
    set_rule(btn, "DisplayMode", 'If(cmpRiskGrid.Mode = "Overview", DisplayMode.View, DisplayMode.Edit)')
    set_rule(
        btn,
        "BorderThickness",
        'If(\r\n    cmpRiskGrid.Mode = "Select" && ThisItem.Probability = cmpRiskGrid.HighlightP && ThisItem.Severity = cmpRiskGrid.HighlightS,\r\n    2,\r\n    0\r\n)',
    )
    set_rule(btn, "BorderColor", "gblTheme.Text")
    set_rule(btn, "Color", "gblTheme.TextOnLight")
    set_rule(btn, "HoverFill", "ColorFade(Self.Fill, -10%)")
    set_rule(btn, "HoverColor", "gblTheme.TextOnLight")
    set_rule(btn, "PressedFill", "ColorFade(Self.Fill, -20%)")
    set_rule(btn, "OnSelect", "Set(varHighlightP, ThisItem.Probability);\r\nSet(varHighlightS, ThisItem.Severity)", "Behavior")
    set_rule(
        btn,
        "Tooltip",
        'fxRiskBand(ThisItem.Score) & " · P" & ThisItem.Probability & " × S" & ThisItem.Severity',
        "Data",
    )


def patch_sidebar(comp: dict) -> None:
    for name in ("HamburgerIcon", "ButtonCanvas1", "CloseMenuLabel"):
        try:
            node = find_named(comp, name)
        except KeyError:
            continue
        set_rule(node, "OnSelect", "Navigate(Dashboard, ScreenTransition.Fade)", "Behavior")
    try:
        gal = find_named(comp, "SidebarGallery1")
    except KeyError:
        return
    if not any(c.get("Name") == "SidebarBadge" for c in gal.get("Children") or []):
        label = find_named(comp, "SidebarTextLabel")
        gal.setdefault("Children", []).append(
            clone_control(
                label,
                "SidebarBadge",
                "SidebarGallery1",
                Text="Text(varInboxCount)",
                Visible='ThisItem.Name = "Inbox" && varInboxCount > 0',
                Fill="gblTheme.Badge",
                Color="gblTheme.TextOnAccent",
                Align="Align.Center",
                Width="18",
                Height="18",
                X="Parent.TemplateWidth - 28",
                Y="(Parent.TemplateHeight / 2) - (Self.Height / 2)",
                Size="9",
                FontWeight="FontWeight.Semibold",
                RadiusTopLeft="20",
                RadiusTopRight="20",
                RadiusBottomLeft="20",
                RadiusBottomRight="20",
            )
        )
    try:
        cont = find_named(comp, "SidebarCont")
    except KeyError:
        return
    names = {c.get("Name") for c in cont.get("Children") or []}
    spacer = find_named(comp, "HamburgerSpacer")
    who_label = find_named(comp, "CloseMenuLabel")
    if "SidebarGrow" not in names:
        cont.setdefault("Children", []).append(
            clone_control(
                spacer,
                "SidebarGrow",
                "SidebarCont",
                FillPortions="1",
                Height="1",
                Width="Parent.Width",
                DropShadow="DropShadow.None",
            )
        )
    if "SidebarWho" not in names:
        who = clone_control(
            spacer,
            "SidebarWho",
            "SidebarCont",
            FillPortions="0",
            Height="58",
            Width="Parent.Width",
            DropShadow="DropShadow.None",
        )
        who["Children"] = [
            clone_control(
                who_label,
                "SidebarWhoAvatar",
                "SidebarWho",
                Text='"L1"',
                Align="Align.Center",
                VerticalAlign="VerticalAlign.Middle",
                Fill="gblTheme.Avatar",
                Color="gblTheme.AvatarInk",
                Width="34",
                Height="34",
                X="6",
                Y="14",
                Size="10",
                FontWeight="FontWeight.Semibold",
                RadiusTopLeft="20",
                RadiusTopRight="20",
                RadiusBottomLeft="20",
                RadiusBottomRight="20",
            ),
            clone_control(
                who_label,
                "SidebarWhoName",
                "SidebarWho",
                Text="varUserDisplay",
                Color="gblTheme.RailTitle",
                Fill="RGBA(0, 0, 0, 0)",
                Width="150",
                Height="18",
                X="46",
                Y="14",
                Size="10",
                FontWeight="FontWeight.Semibold",
                Wrap="false",
            ),
            clone_control(
                who_label,
                "SidebarWhoRole",
                "SidebarWho",
                Text="varUserRole",
                Color="gblTheme.RailMuted",
                Fill="RGBA(0, 0, 0, 0)",
                Width="150",
                Height="16",
                X="46",
                Y="32",
                Size="9",
                Wrap="false",
            ),
        ]
        cont["Children"].append(who)


def strip_tdr_flow(text: str) -> str:
    if "VCDSTDR" not in text and "ImportDataFlow" not in text:
        return text
    return re.sub(
        r'With\(\s*\{ImportedData: \'VCDSTDR[^\']*\'\.Run\(\)\}[\s\S]*?\)\s*\)',
        TDR_FLOW_NOTIFY,
        text,
        count=1,
    )


def rebuild_screens(sidebar_def: dict, grid_def: dict, label_t: dict, button_t: dict, image_t: dict, gallery_t: dict) -> None:
    for spec in SPECS:
        path = ROOT / spec["json"]
        data = json.loads(path.read_text())
        screen = data["TopParent"]
        set_rule(screen, "Fill", "gblTheme.Page")
        children = []
        children.append(
            place(
                clone_control(
                    image_t,
                    spec["image_ctl"],
                    spec["screen"],
                    Image=spec["image"],
                    ImagePosition="ImagePosition.Fit",
                    Height="App.Height",
                    Width="App.Width",
                    X="0",
                    Y="0",
                    DisplayMode="DisplayMode.View",
                    BorderThickness="0",
                ),
                0,
                1,
            )
        )
        children.append(
            place(
                make_component_instance(
                    sidebar_def,
                    spec["sidebar"],
                    spec["screen"],
                    Height="App.Height",
                    Width="gblNavWidth",
                    X="0",
                    Y="0",
                    Visible="true",
                    Fill="gblTheme.Rail",
                ),
                1,
                90,
            )
        )
        z = 10
        if spec.get("filters"):
            f = spec["filters"]
            gal = clone_control(
                gallery_t,
                f["gallery"],
                spec["screen"],
                Items=f["items"],
                Height="36",
                Width="App.Width - gblNavWidth - 44",
                X="gblNavWidth + 22",
                Y=f["y"],
                TemplateSize="124",
                TemplatePadding="0",
                BorderColor="RGBA(0, 0, 0, 0)",
                Fill="RGBA(0, 0, 0, 0)",
            )
            gal["VariantName"] = "galleryHorizontal"
            gal["Children"] = [
                clone_control(
                    button_t,
                    f["button"],
                    f["gallery"],
                    Text="ThisItem.Value",
                    Fill="If(ThisItem.Selected, gblTheme.ChipOn, gblTheme.Chip)",
                    Color="If(ThisItem.Selected, gblTheme.ChipOnInk, gblTheme.Text)",
                    BorderColor="If(ThisItem.Selected, gblTheme.ChipOn, gblTheme.Border)",
                    BorderThickness="1",
                    HoverFill="If(ThisItem.Selected, ColorFade(gblTheme.ChipOn, 10%), ColorFade(gblTheme.Chip, -6%))",
                    Font="Font.'Segoe UI'",
                    FontWeight="FontWeight.Semibold",
                    Size="10",
                    Height="28",
                    Width="Parent.TemplateWidth - 8",
                    X="0",
                    Y="4",
                    OnSelect=FILTER_ONSELECT[f["items"]].replace("\n                    ", "\r\n"),
                    RadiusTopLeft="gblRadiusPill",
                    RadiusTopRight="gblRadiusPill",
                    RadiusBottomLeft="gblRadiusPill",
                    RadiusBottomRight="gblRadiusPill",
                )
            ]
            z += 1
            children.append(place(gal, z, 50))
        if spec.get("grid"):
            g = spec["grid"]
            z += 1
            children.append(
                place(
                    make_component_instance(
                        grid_def,
                        g["name"],
                        spec["screen"],
                        Height="248",
                        Width="320",
                        X=g["x"],
                        Y=g["y"],
                        Fill="gblTheme.Paper",
                        Mode=f'"{g["mode"]}"',
                        HighlightP=g.get("highlight_p", "0"),
                        HighlightS=g.get("highlight_s", "0"),
                    ),
                    z,
                    40,
                )
            )
        for lab in spec["labels"]:
            z += 1
            children.append(
                place(
                    clone_control(
                        label_t,
                        lab["name"],
                        spec["screen"],
                        Text=f'"{lab["text"]}"',
                        Color=lab.get("color", "gblTheme.Text"),
                        Font="Font.'Segoe UI'",
                        FontWeight=lab.get("weight", "FontWeight.Semibold"),
                        Size=str(lab.get("size", 14)),
                        Height=str(lab.get("height", 32)),
                        Width=str(lab.get("width", 640)),
                        X=lab["x"],
                        Y=lab["y"],
                        Fill="RGBA(0, 0, 0, 0)",
                        PaddingLeft="0",
                        PaddingTop="0",
                    ),
                    z,
                    50,
                )
            )
        for b in spec["buttons"]:
            z += 1
            children.append(
                place(
                    clone_control(
                        button_t,
                        b["name"],
                        spec["screen"],
                        Text=f'"{b["text"]}"',
                        Fill=b["fill"],
                        Color=b.get("color", "gblTheme.TextOnAccent"),
                        HoverFill=f"ColorFade({b['fill']}, -10%)",
                        PressedFill=f"ColorFade({b['fill']}, -20%)",
                        BorderColor="gblTheme.Border",
                        Font="Font.'Segoe UI'",
                        FontWeight="FontWeight.Semibold",
                        Size="12",
                        Height="36",
                        Width=str(b.get("width", 140)),
                        X=b["x"],
                        Y=b["y"],
                        OnSelect=b["onselect"],
                        RadiusTopLeft="gblRadius",
                        RadiusTopRight="gblRadius",
                        RadiusBottomLeft="gblRadius",
                        RadiusBottomRight="gblRadius",
                    ),
                    z,
                    60,
                )
            )
        screen["Children"] = children
        path.write_text(json.dumps(data, indent=2) + "\n")
        (ROOT / spec["yaml"]).write_text(yaml_screen(spec))
        print("rebuilt", spec["screen"], "children", len(children))


def refresh_chat_sidebar(sidebar_def: dict) -> None:
    path = ROOT / "Controls" / "203.json"
    data = json.loads(path.read_text())
    kids = data["TopParent"].get("Children") or []
    new_kids = []
    replaced = False
    for child in kids:
        name = child.get("Name") or ""
        cdef = ((child.get("Template") or {}).get("ComponentDefinitionInfo") or {}).get("Name")
        if name.startswith("SidebarMenu") or cdef == "SidebarMenu":
            inst = make_component_instance(
                sidebar_def,
                "SidebarMenu_4",
                "Chat",
                Height="App.Height",
                Width="gblNavWidth",
                X="0",
                Y="0",
                Visible="true",
                Fill="gblTheme.Rail",
            )
            inst["Index"] = child.get("Index", 0)
            new_kids.append(inst)
            replaced = True
        else:
            new_kids.append(child)
    if not replaced:
        new_kids.insert(
            0,
            make_component_instance(
                sidebar_def,
                "SidebarMenu_4",
                "Chat",
                Height="App.Height",
                Width="gblNavWidth",
                Visible="true",
            ),
        )
    data["TopParent"]["Children"] = new_kids
    path.write_text(json.dumps(data, indent=2) + "\n")
    print("chat sidebar refreshed")


def patch_chat_yaml() -> None:
    path = SRC / "Chat.pa.yaml"
    text = path.read_text()
    text = text.replace(
        "Enter generic TDR description here... Make it pretty using HTML & CSS",
        "OHS Risk Assessment chat. Mentions notify the assigned OPI.",
    )
    path.write_text(text)


def delete_tdr_screens() -> None:
    for yaml_name, json_name in (
        ("Admin Screen.pa.yaml", "570.json"),
        ("Refresh Screen.pa.yaml", "758.json"),
    ):
        yp = SRC / yaml_name
        jp = ROOT / "Controls" / json_name
        if yp.exists():
            yp.unlink()
            print("deleted", yp.name)
        if jp.exists():
            jp.unlink()
            print("deleted", jp.name)
    editor = SRC / "_EditorState.pa.yaml"
    text = editor.read_text()
    text = text.replace("    - Control Screen\n    - Refresh Screen\n    - Homepage\n", "    - Control Screen\n    - Homepage\n")
    text = text.replace("    - Chat\n    - Admin Screen\n", "    - Chat\n")
    editor.write_text(text)
    props_path = ROOT / "Properties.json"
    props = json.loads(props_path.read_text())
    props["ParserErrorCount"] = 0
    props["BindingErrorCount"] = 0
    counts = props.get("ControlCount") or {}
    if "screen" in counts:
        counts["screen"] = 6
    props_path.write_text(json.dumps(props, indent=2) + "\n")


def patch_topbar() -> None:
    yaml_path = SRC / "Components" / "TopbarHeader.pa.yaml"
    text = yaml_path.read_text()
    text = re.sub(
        r"OnSelect: \|-\n                          =With\(\n                              \{ImportedData: 'VCDSTDR—ImportDataFlow'\.Run\(\)\},[\s\S]*?                          \)\n",
        "OnSelect: =" + TDR_FLOW_NOTIFY + "\n",
        text,
        count=1,
    )
    yaml_path.write_text(text)
    json_path = ROOT / "Components" / "4.json"
    data = json.loads(json_path.read_text())
    raw = json.dumps(data)
    if "ImportDataFlow" in raw:
        data = json.loads(strip_tdr_flow(raw) if False else raw)

    def fix(node):
        for rule in node.get("Rules") or []:
            script = rule.get("InvariantScript") or ""
            if "ImportDataFlow" in script or "VCDSTDR" in script:
                rule["InvariantScript"] = TDR_FLOW_NOTIFY

    for node in walk(data["TopParent"]):
        fix(node)
    json_path.write_text(json.dumps(data, indent=2) + "\n")
    print("topbar TDR flow removed")


def sync_app_json() -> None:
    app_yaml = SRC / "App.pa.yaml"
    formulas = yaml_formula_block(app_yaml, "Formulas")
    onstart = yaml_formula_block(app_yaml, "OnStart")
    path = ROOT / "Controls" / "1.json"
    app = json.loads(path.read_text())
    set_rule(app["TopParent"], "Formulas", formulas.replace("\n", "\r\n"), "Data")
    set_rule(app["TopParent"], "OnStart", onstart.replace("\n", "\r\n"), "Behavior")
    path.write_text(json.dumps(app, indent=2) + "\n")
    print("wrote App formulas/onstart")


def patch_pack_script() -> None:
    for name in ("pack_msapp.py", "sync_studio_json.py", "build_mockup_screens.py"):
        path = ROOT.parent / "scripts" / name
        if not path.exists():
            continue
        text = path.read_text()
        text = text.replace("/srv/http/ohs_risk_assessment", "/srv/http/power_sweeper/samples/ohs_risk_assessment")
        path.write_text(text)


def main() -> None:
    sidebar_path = ROOT / "Components" / "55.json"
    grid_path = ROOT / "Components" / "98.json"
    sidebar = json.loads(sidebar_path.read_text())
    grid = json.loads(grid_path.read_text())
    patch_sidebar(sidebar["TopParent"])
    patch_risk_grid(grid["TopParent"])
    sidebar_path.write_text(json.dumps(sidebar, indent=2) + "\n")
    grid_path.write_text(json.dumps(grid, indent=2) + "\n")
    print("patched SidebarMenu and cmpRiskGrid")

    home = json.loads((ROOT / "Controls" / "113.json").read_text())
    label_t = find_template(home["TopParent"], "label")
    button_t = find_template(home["TopParent"], "button")
    gallery_t = find_template(home["TopParent"], "gallery")
    refresh = json.loads((ROOT / "Controls" / "758.json").read_text())
    image_t = find_template(refresh["TopParent"], "image")

    rebuild_screens(sidebar["TopParent"], grid["TopParent"], label_t, button_t, image_t, gallery_t)
    refresh_chat_sidebar(sidebar["TopParent"])
    patch_chat_yaml()
    patch_topbar()
    sync_app_json()
    delete_tdr_screens()
    patch_pack_script()

    chat_json = ROOT / "Controls" / "203.json"
    data = json.loads(chat_json.read_text())
    raw = json.dumps(data)
    raw = raw.replace(
        "Enter generic TDR description here... Make it pretty using HTML & CSS",
        "OHS Risk Assessment chat. Mentions notify the assigned OPI.",
    )
    chat_json.write_text(json.dumps(json.loads(raw), indent=2) + "\n")
    print("done")


if __name__ == "__main__":
    main()
