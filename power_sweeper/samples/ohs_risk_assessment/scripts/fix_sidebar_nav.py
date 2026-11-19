#!/usr/bin/env python3
"""Sync SidebarMenu visuals + gallery OnSelect navigation into JSON."""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path("/srv/http/power_sweeper/samples/ohs_risk_assessment/app")
SRC = ROOT / "Src"

NAV = (
    "Switch(\r\n"
    "    ThisItem.ScreenName,\r\n"
    '    "Dashboard", Navigate(Dashboard, ScreenTransition.Fade),\r\n'
    '    "Homepage", Navigate(Homepage, ScreenTransition.Fade),\r\n'
    '    "Details", Navigate(Details, ScreenTransition.Fade),\r\n'
    '    "Approvals", Navigate(Approvals, ScreenTransition.Fade),\r\n'
    '    "Chat", Navigate(Chat, ScreenTransition.Fade)\r\n'
    ")"
)
DASH = "Navigate(Dashboard, ScreenTransition.Fade)"
ACTIVE = "ThisItem.ScreenName = App.ActiveScreen.Name"
CYCLE_ON = "ThisItem.Step = fxRiskCycleStep"


def yaml_block(path: Path, key: str) -> str:
    lines = path.read_text().splitlines()
    start = None
    for i, line in enumerate(lines):
        if line.startswith(f"    {key}: |-"):
            start = i + 1
            break
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
    return "\r\n".join(body)


def walk(node):
    if isinstance(node, dict):
        yield node
        for child in node.get("Children") or []:
            yield from walk(child)
        info = ((node.get("Template") or {}).get("ComponentDefinitionInfo") or {})
        for child in info.get("Children") or []:
            yield from walk(child)
        for value in node.values():
            if isinstance(value, (dict, list)) and value is not node.get("Children") and value is not info.get("Children"):
                if isinstance(value, dict) and value.get("Type") == "ControlInfo":
                    yield from walk(value)
                elif isinstance(value, list):
                    for item in value:
                        if isinstance(item, dict) and item.get("Type") == "ControlInfo":
                            yield from walk(item)
    elif isinstance(node, list):
        for item in node:
            yield from walk(item)


def set_rule(node, prop, script, category="Design"):
    rules = node.setdefault("Rules", [])
    for rule in rules:
        if rule.get("Property") == prop:
            rule["InvariantScript"] = script
            return
    cat = "Behavior" if prop in {"OnSelect", "OnChange", "OnVisible"} else category
    if prop in {"Items", "Text", "Tooltip", "Selectable"}:
        cat = "Data"
    if prop == "OnSelect":
        cat = "Behavior"
    rules.append({"Property": prop, "Category": cat, "InvariantScript": script, "RuleProviderType": "User"})


def patch_node(node: dict) -> None:
    name = node.get("Name") or ""
    if name in {"SidebarGallery1", "SidebarGallery2"}:
        set_rule(node, "OnSelect", NAV, "Behavior")
        set_rule(node, "Selectable", "true", "Data")
        set_rule(node, "DelayItemLoading", "false")
        set_rule(node, "BorderColor", "RGBA(0, 0, 0, 0)")
        set_rule(node, "BorderThickness", "0")
        set_rule(node, "Fill", "RGBA(0, 0, 0, 0)")
        if name == "SidebarGallery1":
            set_rule(node, "TemplateSize", "44")
            set_rule(node, "Height", "220")
            set_rule(node, "LayoutMinHeight", "220")
        else:
            set_rule(node, "TemplateSize", "34")
            set_rule(node, "Height", "136")
            set_rule(node, "LayoutMinHeight", "136")
    if name in {"SidebarIcon", "SidebarTextLabel", "Label7_190", "Icon2_3"}:
        set_rule(node, "OnSelect", NAV, "Behavior")
        set_rule(node, "TabIndex", "0")
        set_rule(node, "DisplayMode", "DisplayMode.Edit")
        set_rule(node, "BorderColor", "RGBA(0, 0, 0, 0)")
    if name == "SidebarIcon":
        set_rule(node, "Color", f"If({ACTIVE}, RGBA(255, 255, 255, 1), gblColorNavText)")
        set_rule(node, "Height", "18")
        set_rule(node, "Width", "18")
        set_rule(node, "X", "12")
    if name == "SidebarTextLabel":
        set_rule(node, "Color", f"If({ACTIVE}, RGBA(255, 255, 255, 1), gblColorNavText)")
        set_rule(node, "Size", "11")
        set_rule(node, "Width", "110")
        set_rule(node, "X", "38")
        set_rule(node, "Text", "ThisItem.Name")
        set_rule(node, "Wrap", "false")
    if name == "Label7_190":
        set_rule(node, "Color", f"If({CYCLE_ON}, gblColorNavTitle, gblColorNavMuted)")
        set_rule(node, "Size", "10")
        set_rule(node, "Text", 'Text(ThisItem.Step) & "  " & ThisItem.Name')
        set_rule(node, "Width", "160")
        set_rule(node, "X", "34")
        set_rule(node, "FontWeight", "FontWeight.Normal")
    if name == "Icon2_3":
        set_rule(node, "Visible", "false")
        set_rule(node, "Icon", "Icon.Add")
    if name == "Container70_1":
        set_rule(node, "Visible", ACTIVE)
        set_rule(node, "Fill", "gblColorNavActive")
        set_rule(node, "DropShadow", "DropShadow.None")
        set_rule(node, "RadiusBottomLeft", "10")
        set_rule(node, "RadiusBottomRight", "10")
        set_rule(node, "RadiusTopLeft", "10")
        set_rule(node, "RadiusTopRight", "10")
        set_rule(node, "Width", "Parent.TemplateWidth")
        set_rule(node, "X", "0")
        set_rule(node, "Y", "2")
        set_rule(node, "Height", "Parent.TemplateHeight - 4")
    if name == "Container70":
        set_rule(node, "Visible", "false")
    if name in {"SidebarButton", "ButtonCanvas4_1", "ButtonCanvas1", "ButtonCanvas4_2", "Expand"}:
        set_rule(node, "Visible", "false")
        if name != "Expand":
            set_rule(node, "OnSelect", NAV if name != "ButtonCanvas1" else DASH, "Behavior")
    if name == "HamburgerIcon":
        set_rule(node, "OnSelect", DASH, "Behavior")
        set_rule(node, "Fill", "gblColorPrimary")
        set_rule(node, "Color", "gblColorOnAccent")
        set_rule(node, "Icon", "Icon.View")
        set_rule(node, "Height", "34")
        set_rule(node, "Width", "34")
        set_rule(node, "X", "6")
        set_rule(node, "TabIndex", "0")
        set_rule(node, "RadiusBottomLeft", "8")
        set_rule(node, "RadiusBottomRight", "8")
        set_rule(node, "RadiusTopLeft", "8")
        set_rule(node, "RadiusTopRight", "8")
    if name == "CloseMenuLabel":
        set_rule(node, "OnSelect", DASH, "Behavior")
        set_rule(node, "Text", '"VCDS GROUP" & Char(10) & "OHS Risk"')
        set_rule(node, "Wrap", "true")
        set_rule(node, "Width", "140")
        set_rule(node, "Height", "40")
        set_rule(node, "X", "48")
        set_rule(node, "Y", "8")
        set_rule(node, "Size", "10")
        set_rule(node, "TabIndex", "0")
        set_rule(node, "Color", "gblColorNavTitle")
    if name == "HamburgerMenuCont":
        set_rule(node, "Height", "58")
    if name == "SidebarBottomLine":
        set_rule(node, "Height", "32")
        ensure_cycle_label(node)
    if name == "HamburgerSpacer":
        set_rule(node, "Height", "6")
    if name == "HamburgerSpacerInner":
        set_rule(node, "Visible", "false")
    if name.startswith("imgMockup"):
        set_rule(node, "DisplayMode", "DisplayMode.View")
        set_rule(node, "TabIndex", "-1")


def ensure_cycle_label(parent: dict) -> None:
    children = parent.setdefault("Children", [])
    if any(c.get("Name") == "lblRiskCycle" for c in children):
        for child in children:
            if child.get("Name") == "lblRiskCycle":
                set_rule(child, "Text", '"RISK CYCLE"')
                set_rule(child, "Color", "gblColorNavMuted")
                set_rule(child, "Size", "8")
                set_rule(child, "Y", "12")
        return
    children.append(
        {
            "Type": "ControlInfo",
            "Name": "lblRiskCycle",
            "HasDynamicProperties": False,
            "Template": {
                "Id": "http://microsoft.com/appmagic/label",
                "Version": "2.5.1",
                "LastModifiedTimestamp": "0",
                "Name": "label",
                "FirstParty": True,
                "IsPremiumPcfControl": False,
                "IsCustomGroupControlTemplate": False,
                "CustomGroupControlTemplateName": "",
                "IsComponentDefinition": False,
                "OverridableProperties": {},
            },
            "Index": 1,
            "PublishOrderIndex": 900,
            "VariantName": "",
            "LayoutName": "",
            "MetaDataIDKey": "",
            "PersistMetaDataIDKey": False,
            "IsFromScreenLayout": False,
            "StyleName": "defaultLabelStyle",
            "Parent": parent.get("Name") or "SidebarBottomLine",
            "IsDataControl": False,
            "AllowAccessToGlobals": True,
            "OptimizeForDevices": "Off",
            "IsGroupControl": False,
            "IsAutoGenerated": False,
            "Rules": [
                {"Property": "Text", "Category": "Data", "InvariantScript": '"RISK CYCLE"', "RuleProviderType": "User"},
                {"Property": "Color", "Category": "Design", "InvariantScript": "gblColorNavMuted", "RuleProviderType": "User"},
                {"Property": "Font", "Category": "Design", "InvariantScript": "Font.'Segoe UI'", "RuleProviderType": "User"},
                {"Property": "FontWeight", "Category": "Design", "InvariantScript": "FontWeight.Semibold", "RuleProviderType": "User"},
                {"Property": "Size", "Category": "Design", "InvariantScript": "8", "RuleProviderType": "User"},
                {"Property": "Height", "Category": "Design", "InvariantScript": "16", "RuleProviderType": "User"},
                {"Property": "Width", "Category": "Design", "InvariantScript": "Parent.Width", "RuleProviderType": "User"},
                {"Property": "X", "Category": "Design", "InvariantScript": "6", "RuleProviderType": "User"},
                {"Property": "Y", "Category": "Design", "InvariantScript": "12", "RuleProviderType": "User"},
                {"Property": "PaddingLeft", "Category": "Design", "InvariantScript": "0", "RuleProviderType": "User"},
                {"Property": "PaddingTop", "Category": "Design", "InvariantScript": "0", "RuleProviderType": "User"},
                {"Property": "PaddingBottom", "Category": "Design", "InvariantScript": "0", "RuleProviderType": "User"},
                {"Property": "Fill", "Category": "Design", "InvariantScript": "RGBA(0, 0, 0, 0)", "RuleProviderType": "User"},
                {"Property": "BorderColor", "Category": "Design", "InvariantScript": "RGBA(0, 0, 0, 0)", "RuleProviderType": "User"},
            ],
            "ControlPropertyState": ["Text", "Color", "Size", "X", "Y", "Width", "Height"],
            "IsLocked": False,
            "Children": [],
        }
    )


def sync_app() -> None:
    app_yaml = SRC / "App.pa.yaml"
    path = ROOT / "Controls" / "1.json"
    app = json.loads(path.read_text())
    formulas = yaml_block(app_yaml, "Formulas")
    onstart = yaml_block(app_yaml, "OnStart")
    assert "Identify" in formulas and "ScreenName" in formulas
    for rule in app["TopParent"]["Rules"]:
        if rule.get("Property") == "Formulas":
            rule["InvariantScript"] = formulas
        if rule.get("Property") == "OnStart":
            rule["InvariantScript"] = onstart
    path.write_text(json.dumps(app, indent=2) + "\n")
    print("synced App json")


def main() -> None:
    sync_app()
    for folder in ("Controls", "Components"):
        for path in (ROOT / folder).glob("*.json"):
            data = json.loads(path.read_text())
            root = data.get("TopParent", data)
            for node in walk(root):
                patch_node(node)
            path.write_text(json.dumps(data, indent=2) + "\n")
            print("patched", path.name)


if __name__ == "__main__":
    main()
