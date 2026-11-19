#!/usr/bin/env python3
"""Replace the four main screens with mockup chrome that Studio actually paints."""
from __future__ import annotations

import json
import shutil
import uuid
from copy import deepcopy
from pathlib import Path

ROOT = Path("/srv/http/power_sweeper/samples/ohs_risk_assessment/app")
MOCK = Path("/srv/http/power_sweeper/samples/ohs_risk_assessment/mockups/ohs-risk-assessment/backgrounds")

UID = 30000


def next_uid() -> str:
    global UID
    UID += 1
    return str(UID)


def walk(node):
    yield node
    for child in node.get("Children") or []:
        yield from walk(child)


def set_rule(node: dict, prop: str, script: str, category: str = "Design") -> None:
    for rule in node.get("Rules", []):
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


def find_first(root: dict, template_name: str) -> dict:
    for node in walk(root):
        if (node.get("Template") or {}).get("Name") == template_name:
            return node
    raise KeyError(template_name)


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
        category = "Behavior" if key in {"OnSelect", "OnVisible", "OnChange"} else (
            "Data" if key in {"Text", "Items", "Image", "Tooltip"} else "Design"
        )
        set_rule(node, key, value, category)
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
    tmpl["ComponentDefinitionInfo"] = {
        "Name": definition.get("Name"),
        "LastModifiedTimestamp": tmpl.get("LastModifiedTimestamp", "0"),
        "AllowAccessToGlobals": True,
        "Rules": deepcopy(definition.get("Rules", [])),
        "ControlPropertyState": deepcopy(definition.get("ControlPropertyState", [])),
        "Children": deepcopy(definition.get("Children", [])),
    }
    inst["Children"] = []
    for key, value in props.items():
        set_rule(inst, key, value)
    return inst


NAV_ITEMS = [
    {"key": "Dashboard", "y": 75, "h": 30, "goto": "Dashboard"},
    {"key": "Inbox", "y": 111, "h": 34, "goto": "Homepage"},
    {"key": "Review", "y": 149, "h": 30, "goto": "Details"},
    {"key": "Treat", "y": 186, "h": 28, "goto": "Approvals"},
    {"key": "Identify", "y": 243, "h": 24, "goto": "Homepage"},
    {"key": "Score", "y": 267, "h": 32, "goto": "Details"},
    {"key": "Mitigate", "y": 297, "h": 28, "goto": "Approvals"},
    {"key": "Close", "y": 323, "h": 26, "goto": "Dashboard"},
]
NAV_X = 14
NAV_W = "gblNavWidth - 28"


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
            Visible: =false
            Width: =gblNavWidth
            X: =0
            Y: =0"""
    )
    if spec.get("grid"):
        g = spec["grid"]
        kids.append(
            f"""      - {g['name']}:
          Control: CanvasComponent
          ComponentName: cmpRiskGrid
          Properties:
            Fill: =gblColorPaper
            Height: =248
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
            BorderColor: =gblColorLine
            Color: ={b.get('color', 'gblColorOnAccent')}
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
            Text: =\"{b['text']}\"
            Width: ={b.get('width', 140)}
            X: ={b['x']}
            Y: ={b['y']}"""
        )
    for lab in spec["labels"]:
        kids.append(
            f"""      - {lab['name']}:
          Control: Label@2.5.1
          Properties:
            Color: ={lab.get('color', 'gblColorInk')}
            Font: =Font.'Segoe UI'
            FontWeight: ={lab.get('weight', 'FontWeight.Semibold')}
            Height: ={lab.get('height', 32)}
            Size: ={lab.get('size', 14)}
            Text: =\"{lab['text']}\"
            Width: ={lab.get('width', 640)}
            X: ={lab['x']}
            Y: ={lab['y']}"""
        )
    for item in NAV_ITEMS:
        name = f"btnRail{spec['prefix']}{item['key']}"
        kids.append(
            f"""      - {name}:
          Control: Classic/Button@2.2.0
          Properties:
            BorderColor: =RGBA(0, 0, 0, 0)
            BorderThickness: =0
            Color: =RGBA(0, 0, 0, 0)
            Fill: =RGBA(0, 0, 0, 0)
            FocusedBorderThickness: =0
            Font: =Font.'Segoe UI'
            Height: ={item['h']}
            HoverColor: =RGBA(0, 0, 0, 0)
            HoverFill: =RGBA(255, 255, 255, 0.08)
            OnSelect: =Navigate({item['goto']}, ScreenTransition.Fade)
            PressedColor: =RGBA(0, 0, 0, 0)
            PressedFill: =RGBA(255, 255, 255, 0.14)
            RadiusBottomLeft: =10
            RadiusBottomRight: =10
            RadiusTopLeft: =10
            RadiusTopRight: =10
            Size: =1
            TabIndex: =0
            Text: =\"\"
            Tooltip: =\"{item['key']}\"
            Width: ={NAV_W}
            X: ={NAV_X}
            Y: ={item['y']}
            ZIndex: =80"""
        )
    body = "\n".join(kids)
    return f"""# ************************************************************************************************
# Warning: YAML source code for Canvas Apps should only be used to review changes made within Power Apps Studio and for minor edits (Preview).
# Use the maker portal to create and edit your Power Apps.
# ************************************************************************************************
Screens:
  {spec['screen']}:
    Properties:
      Fill: =gblColorPage
      LoadingSpinnerColor: =gblColorPrimary
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
        "png": "01-dashboard.png",
        "prefix": "Dash",
        "sidebar": "SidebarMenu_5",
        "grid": {"name": "cmpRiskGrid_Dashboard", "x": "gblNavWidth + 22", "y": "200"},
        "labels": [
            {"name": "lblDashCrumb", "text": "Leadership view · PIP dashboard", "x": "gblNavWidth + 22", "y": "18", "size": 10, "color": "gblColorMuted", "weight": "FontWeight.Normal", "height": 18},
            {"name": "lblDashTitle", "text": "Risk picture", "x": "gblNavWidth + 22", "y": "36", "size": 21, "width": 400, "height": 36},
        ],
        "buttons": [
            {"name": "btnDashAlerts", "text": "Alerts", "fill": "gblColorQuiet", "color": "gblColorInk", "onselect": "Navigate(Homepage, ScreenTransition.Fade)", "x": "App.Width - 280", "y": "22", "width": 90},
            {"name": "btnDashIdentify", "text": "Identify risk", "fill": "gblColorPrimary", "onselect": "Navigate(Homepage, ScreenTransition.Fade)", "x": "App.Width - 178", "y": "22", "width": 150},
        ],
    },
    {
        "screen": "Homepage",
        "json": "Controls/113.json",
        "yaml": "Src/Homepage.pa.yaml",
        "image": "MockupInbox",
        "image_ctl": "imgMockupInbox",
        "png": "02-inbox.png",
        "prefix": "Home",
        "sidebar": "SidebarMenu_Home",
        "grid": None,
        "labels": [
            {"name": "lblInboxCrumb", "text": "Role · L1 Safety Advisor", "x": "gblNavWidth + 22", "y": "18", "size": 10, "color": "gblColorMuted", "weight": "FontWeight.Normal", "height": 18},
            {"name": "lblInboxTitle", "text": "New risks identified", "x": "gblNavWidth + 22", "y": "36", "size": 21, "width": 520, "height": 36},
        ],
        "buttons": [
            {"name": "btnNotifyOPIs", "text": "Notify OPIs", "fill": "gblColorQuiet", "color": "gblColorInk", "onselect": "Notify(\"OPIs notified for this cycle.\", NotificationType.Success)", "x": "App.Width - 300", "y": "22", "width": 120},
            {"name": "btnInboxReview", "text": "Open review", "fill": "gblColorPrimary", "onselect": "Navigate(Details, ScreenTransition.Fade)", "x": "App.Width - 168", "y": "22", "width": 140},
        ],
    },
    {
        "screen": "Details",
        "json": "Controls/151.json",
        "yaml": "Src/Details.pa.yaml",
        "image": "MockupReview",
        "image_ctl": "imgMockupReview",
        "png": "03-review.png",
        "prefix": "Review",
        "sidebar": "SidebarMenu_1",
        "grid": {"name": "cmpRiskGrid_Review", "x": "App.Width - 360", "y": "188"},
        "labels": [
            {"name": "lblReviewCrumb", "text": "Review & action · CFSG (O-G)", "x": "gblNavWidth + 22", "y": "18", "size": 10, "color": "gblColorMuted", "weight": "FontWeight.Normal", "height": 18},
            {"name": "lblReviewTitle", "text": "CFSG(O-G) · RA-0007-HS", "x": "gblNavWidth + 22", "y": "36", "size": 21, "width": 520, "height": 36},
        ],
        "buttons": [
            {"name": "btnContactOPI", "text": "Contact OPI", "fill": "gblColorQuiet", "color": "gblColorInk", "onselect": "Notify(\"Contact Luc Joly · CFSU.O.SO.requirements@forces.gc.ca\", NotificationType.Information)", "x": "App.Width - 300", "y": "22", "width": 120},
            {"name": "btnSendTreat", "text": "Send to Treat", "fill": "gblColorPrimary", "onselect": "Navigate(Approvals, ScreenTransition.Fade)", "x": "App.Width - 168", "y": "22", "width": 140},
        ],
    },
    {
        "screen": "Approvals",
        "json": "Controls/342.json",
        "yaml": "Src/Approvals.pa.yaml",
        "image": "MockupTreat",
        "image_ctl": "imgMockupTreat",
        "png": "04-treat.png",
        "prefix": "Treat",
        "sidebar": "SidebarMenu_6",
        "grid": {"name": "cmpRiskGrid_Treat", "x": "gblNavWidth + 28", "y": "420"},
        "labels": [
            {"name": "lblTreatCrumb", "text": "Treat · mitigate · archive", "x": "gblNavWidth + 22", "y": "18", "size": 10, "color": "gblColorMuted", "weight": "FontWeight.Normal", "height": 18},
            {"name": "lblTreatTitle", "text": "Hazardous substances program", "x": "gblNavWidth + 22", "y": "36", "size": 21, "width": 560, "height": 36},
        ],
        "buttons": [
            {"name": "btnReassess", "text": "Reassess score", "fill": "gblColorQuiet", "color": "gblColorInk", "onselect": "Navigate(Details, ScreenTransition.Fade)", "x": "App.Width - 320", "y": "22", "width": 140},
            {"name": "btnArchive", "text": "Close & archive", "fill": "gblColorDanger", "onselect": "Notify(\"Record queued for archive / active monitoring.\", NotificationType.Success); Navigate(Dashboard, ScreenTransition.Fade)", "x": "App.Width - 168", "y": "22", "width": 140},
        ],
    },
]


def add_images(resources: dict) -> None:
    existing = {r["Name"] for r in resources["Resources"]}
    for spec in SPECS:
        if spec["image"] in existing:
            continue
        guid = str(uuid.uuid4())
        dest = ROOT / "Assets" / "Images" / f"{guid}.png"
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(MOCK / spec["png"], dest)
        resources["Resources"].append(
            {
                "Name": spec["image"],
                "Schema": "i",
                "IsSampleData": False,
                "IsWritable": False,
                "Type": "ResourceInfo",
                "FileName": f"{guid}.png",
                "Path": f"Assets\\Images\\{guid}.png",
                "Content": "Image",
                "ResourceKind": "LocalFile",
                "RootPath": "",
            }
        )
        print("added image", spec["image"], dest.name)


def main() -> None:
    home = json.loads((ROOT / "Controls" / "113.json").read_text())
    label_t = find_first(home["TopParent"], "label")
    button_t = find_first(home["TopParent"], "button")
    refresh = json.loads((ROOT / "Controls" / "758.json").read_text())
    image_t = find_first(refresh["TopParent"], "image")
    grid_def = json.loads((ROOT / "Components" / "98.json").read_text())["TopParent"]

    resources = json.loads((ROOT / "References" / "Resources.json").read_text())
    add_images(resources)
    (ROOT / "References" / "Resources.json").write_text(json.dumps(resources, indent=2) + "\n")

    for spec in SPECS:
        path = ROOT / spec["json"]
        data = json.loads(path.read_text())
        screen = data["TopParent"]
        set_rule(screen, "Fill", "gblColorPage")
        set_rule(screen, "BackgroundImage", "", "Data")
        set_rule(screen, "ImagePosition", "ImagePosition.Fit")

        sidebar = None
        for child in screen.get("Children") or []:
            if child.get("Name") == spec["sidebar"] or (child.get("Name") or "").startswith("SidebarMenu"):
                sidebar = deepcopy(child)
                break
        if sidebar is None:
            raise SystemExit(f"no sidebar on {spec['screen']}")
        sidebar["Name"] = spec["sidebar"]
        sidebar["Parent"] = spec["screen"]
        set_rule(sidebar, "Height", "App.Height")
        set_rule(sidebar, "Width", "gblNavWidth")
        set_rule(sidebar, "X", "0")
        set_rule(sidebar, "Y", "0")
        set_rule(sidebar, "Visible", "false")

        mockup = place(
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
        children = [mockup, place(sidebar, 1, 2)]
        z = 10
        if spec.get("grid"):
            g = spec["grid"]
            grid = place(
                make_component_instance(
                    grid_def,
                    g["name"],
                    spec["screen"],
                    Height="248",
                    Width="320",
                    X=g["x"],
                    Y=g["y"],
                    Fill="gblColorPaper",
                ),
                2,
                40,
            )
            children.append(grid)
            z = 11
        for lab in spec["labels"]:
            z += 1
            children.append(
                place(
                clone_control(
                    label_t,
                    lab["name"],
                    spec["screen"],
                    Text=f"\"{lab['text']}\"",
                    Color=lab.get("color", "gblColorInk"),
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
                    Text=f"\"{b['text']}\"",
                    Fill=b["fill"],
                    Color=b.get("color", "gblColorOnAccent"),
                    HoverFill=f"ColorFade({b['fill']}, -10%)",
                    PressedFill=f"ColorFade({b['fill']}, -20%)",
                    BorderColor="gblColorLine",
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
        for item in NAV_ITEMS:
            z += 1
            children.append(
                place(
                clone_control(
                    button_t,
                    f"btnRail{spec['prefix']}{item['key']}",
                    spec["screen"],
                    Text="\"\"",
                    Tooltip=f"\"{item['key']}\"",
                    Fill="RGBA(0, 0, 0, 0)",
                    Color="RGBA(0, 0, 0, 0)",
                    HoverFill="RGBA(255, 255, 255, 0.08)",
                    HoverColor="RGBA(0, 0, 0, 0)",
                    PressedFill="RGBA(255, 255, 255, 0.14)",
                    PressedColor="RGBA(0, 0, 0, 0)",
                    BorderColor="RGBA(0, 0, 0, 0)",
                    BorderThickness="0",
                    FocusedBorderThickness="0",
                    Font="Font.'Segoe UI'",
                    Size="1",
                    Height=str(item["h"]),
                    Width=NAV_W,
                    X=str(NAV_X),
                    Y=str(item["y"]),
                    OnSelect=f"Navigate({item['goto']}, ScreenTransition.Fade)",
                    TabIndex="0",
                    RadiusTopLeft="10",
                    RadiusTopRight="10",
                    RadiusBottomLeft="10",
                    RadiusBottomRight="10",
                ),
                    z,
                    80,
                )
            )
        screen["Children"] = children
        path.write_text(json.dumps(data, indent=2) + "\n")
        (ROOT / spec["yaml"]).write_text(yaml_screen(spec))
        print("rebuilt", spec["screen"], "children", len(children))


if __name__ == "__main__":
    main()
