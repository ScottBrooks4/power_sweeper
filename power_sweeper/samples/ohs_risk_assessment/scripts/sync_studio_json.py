#!/usr/bin/env python3
"""Keep Controls/Components JSON InvariantScripts aligned with Src YAML edits."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path("/srv/http/power_sweeper/samples/ohs_risk_assessment/app")

# Exact InvariantScript replacements (YAML already updated).
REPLACEMENTS: list[tuple[str, str]] = [
    (
        "Set(varSideMenu, false); // sidemenu closed OnStart",
        "Set(varSideMenu, true); // sidemenu always open",
    ),
    (
        "Navigate(ThisItem.Screen,ScreenTransition.Fade); Set(varSideMenu, false);",
        "Navigate(ThisItem.Screen, ScreenTransition.Fade)",
    ),
    (
        "Navigate(ThisItem.Screen, ScreenTransition.Fade); Set(varSideMenu, false);",
        "Navigate(ThisItem.Screen, ScreenTransition.Fade)",
    ),
    ('Set(varSideMenu, !varSideMenu)', "Navigate(Homepage, ScreenTransition.Fade)"),
    ("Set(varSideMenu, false);", "false"),
    ("If(!varSideMenu, Icon.Hamburger, Icon.Cancel)", "Icon.Home"),
    ('"Close Menu"', '"VCDS · OHS Risk"'),
    ("RGBA(240, 240, 240, 0.4)", "gblColorNav"),
    ("If(varSideMenu, App.Width, 75)", "gblNavWidth"),
    ("If(varSideMenu, 170, 75)", "Parent.Width"),
    ("If(varSideMenu, 186, 75)", "gblNavWidth"),
    ("If(varSideMenu, Parent.Width, 75)", "Parent.Width"),
    ("If(varSideMenu, DropShadow.Bold, DropShadow.None)", "DropShadow.None"),
    ("App.Height - 65", "App.Height"),
    (
        "If(!(App.ActiveScreen = Dashboard ||  App.ActiveScreen = 'Admin Screen'), true, false)",
        "true",
    ),
    (
        "If(!(App.ActiveScreen = Dashboard || App.ActiveScreen = 'Admin Screen'), true, false)",
        "true",
    ),
    ("DefaultGrayBackgroud = RGBA(\n    250,\n    250,\n    250,\n    1\n);",
     'DefaultGrayBackgroud = ColorValue("#EFEBE3");'),
    ("DefaultGrayBackgroud = RGBA(\r\n    250,\r\n    250,\r\n    250,\r\n    1\r\n);",
     'DefaultGrayBackgroud = ColorValue("#EFEBE3");'),
]

# Only inside SidebarMenu component trees — do not recolor the rest of the app.
SIDEBAR_COLOR_REPLACEMENTS = [
    ("RGBA(51, 51, 51, 1)", "gblColorNavText"),
    ("RGBA(255, 255, 255, 1)", "gblColorNavActive"),
    ("RGBA(230, 230, 230, 1)", "gblColorNavLine"),
    ("DefaultGrayBackgroud", "gblColorNav"),
]


def patch_text(text: str) -> str:
    for old, new in REPLACEMENTS:
        text = text.replace(old, new)
    # Labels that were gated on the hamburger stay visible.
    text = text.replace('"InvariantScript": "varSideMenu"', '"InvariantScript": "true"')
    return text


def yaml_formula_block(path: Path, key: str) -> str:
    raw = path.read_text()
    m = re.search(rf"    {key}: \|-\n((?:      .*\n)*)", raw)
    if not m:
        raise SystemExit(f"{key} block not found in {path}")
    lines = []
    for line in m.group(1).splitlines():
        body = line[6:] if line.startswith("      ") else line
        if lines == [] and body.startswith("="):
            body = body[1:]
        lines.append(body)
    return "\n".join(lines)


def set_rule(node: dict, prop: str, script: str) -> None:
    for rule in node.get("Rules", []):
        if rule.get("Property") == prop:
            rule["InvariantScript"] = script
            return
    node.setdefault("Rules", []).append(
        {
            "Property": prop,
            "Category": "Design",
            "InvariantScript": script,
            "RuleProviderType": "User",
        }
    )


def walk(node, fn):
    fn(node)
    for child in node.get("Children", []) or []:
        walk(child, fn)


def patch_sidebar_tree(node: dict) -> None:
    raw = json.dumps(node)
    for old, new in SIDEBAR_COLOR_REPLACEMENTS:
        raw = raw.replace(old, new)
    patched = json.loads(raw)
    node.clear()
    node.update(patched)


def main() -> None:
    json_files = list((ROOT / "Controls").glob("*.json")) + list((ROOT / "Components").glob("*.json"))
    for path in json_files:
        original = path.read_text()
        updated = patch_text(original)
        if updated != original:
            path.write_text(updated)
            print("patched", path.name)

    # Recolor only SidebarMenu trees (component def + every screen instance copy).
    sidebar_comp = ROOT / "Components" / "55.json"
    if sidebar_comp.exists():
        data = json.loads(sidebar_comp.read_text())
        patch_sidebar_tree(data)
        sidebar_comp.write_text(json.dumps(data, indent=2) + "\n")
        print("recolored SidebarMenu component")
    for path in (ROOT / "Controls").glob("*.json"):
        data = json.loads(path.read_text())
        changed = False

        def recolor(node):
            nonlocal changed
            name = node.get("Name") or ""
            tmpl = (node.get("Template") or {}).get("Name", "")
            cdef = ((node.get("Template") or {}).get("ComponentDefinitionInfo") or {}).get("Name")
            if name == "SidebarMenu" or name.startswith("SidebarMenu_") or cdef == "SidebarMenu" or tmpl == "a4a0a1d579844001a5a6971114c5ad3d":
                patch_sidebar_tree(node)
                changed = True

        walk(data.get("TopParent", data), recolor)
        if changed:
            path.write_text(json.dumps(data, indent=2) + "\n")
            print("recolored sidebar in", path.name)

    # App Formulas / OnStart from YAML (source of truth).
    app_yaml = ROOT / "Src" / "App.pa.yaml"
    formulas = yaml_formula_block(app_yaml, "Formulas")
    onstart = yaml_formula_block(app_yaml, "OnStart")
    app_json_path = ROOT / "Controls" / "1.json"
    app = json.loads(app_json_path.read_text())
    set_rule(app["TopParent"], "Formulas", formulas)
    set_rule(app["TopParent"], "OnStart", onstart.replace("\n", "\r\n"))
    app_json_path.write_text(json.dumps(app, indent=2) + "\n")
    print("wrote App formulas/onstart")

    # Screen chrome: sidebar full-height, content offset, paper topbar.
    screen_files = {
        "113.json": {  # Homepage — handled separately for new sidebar
            "page": "Container117",
            "sidebar": None,
            "topbar": "TopbarHeader_8",
        },
        "226.json": {"page": "THCEE Dshb_PageContainer", "sidebar": "SidebarMenu_5", "topbar": "TopbarHeader_5"},
    }
    # Discover remaining screen files by sidebar name.
    for path in (ROOT / "Controls").glob("*.json"):
        data = json.loads(path.read_text())
        names = []
        walk(data["TopParent"], lambda n: names.append(n.get("Name")))
        if path.name == "113.json":
            continue
        sidebar = next((n for n in names if n and n.startswith("SidebarMenu_")), None)
        topbar = next((n for n in names if n and n.startswith("TopbarHeader_")), None)
        if not sidebar:
            continue
        def apply(node, sidebar=sidebar, topbar=topbar):
            name = node.get("Name")
            if name == sidebar:
                set_rule(node, "Height", "App.Height")
                set_rule(node, "Width", "gblNavWidth")
                set_rule(node, "Y", "0")
                set_rule(node, "X", "0")
            elif name == topbar:
                set_rule(node, "Fill", "gblColorPaper")
                set_rule(node, "Width", "App.Width - gblNavWidth")
                set_rule(node, "X", "gblNavWidth")
            elif name and ("PageContainer" in name or name.startswith("THCEE") or name in {
                "Container117",
            }):
                pass
            # Content columns that were pinned to the collapsed 75px rail.
            for rule in node.get("Rules", []):
                if rule.get("Property") == "X" and rule.get("InvariantScript") == "75":
                    # Don't move random inner 75s on Chat — only top-level page containers.
                    if node.get("Parent") in ("", None) or node.get("Name", "").endswith("PageContainer"):
                        rule["InvariantScript"] = "gblNavWidth"
                if rule.get("Property") == "Y" and isinstance(rule.get("InvariantScript"), str) and rule["InvariantScript"].startswith("SidebarMenu_"):
                    rule["InvariantScript"] = "65"
        walk(data["TopParent"], apply)
        # Explicit page-container X for known roots.
        for child in data["TopParent"].get("Children", []):
            for rule in child.get("Rules", []):
                if rule.get("Property") == "X" and rule.get("InvariantScript") in ("75", "SidebarMenu_5.X"):
                    rule["InvariantScript"] = "gblNavWidth"
                if rule.get("Property") == "Y" and isinstance(rule.get("InvariantScript"), str) and rule["InvariantScript"].startswith("SidebarMenu_"):
                    rule["InvariantScript"] = "65"
        path.write_text(json.dumps(data, indent=2) + "\n")
        print("chrome", path.name, "sidebar", sidebar, "topbar", topbar)

    # Homepage: fill, offset content, add sidebar instance copied from Dashboard.
    home_path = ROOT / "Controls" / "113.json"
    dash_path = ROOT / "Controls" / "226.json"
    home = json.loads(home_path.read_text())
    dash = json.loads(dash_path.read_text())
    set_rule(home["TopParent"], "Fill", "DefaultGrayBackgroud")

    sidebar_src = next(c for c in dash["TopParent"]["Children"] if c.get("Name") == "SidebarMenu_5")
    sidebar = json.loads(json.dumps(sidebar_src))
    sidebar["Name"] = "SidebarMenu_Home"
    sidebar["ControlUniqueId"] = "980"
    sidebar["Index"] = 0
    set_rule(sidebar, "Height", "App.Height")
    set_rule(sidebar, "Width", "gblNavWidth")
    set_rule(sidebar, "Y", "0")
    set_rule(sidebar, "X", "0")

    for child in home["TopParent"]["Children"]:
        if child.get("Name") == "Container117":
            set_rule(child, "Fill", "gblColorPage")
            set_rule(child, "X", "gblNavWidth")
            set_rule(child, "Width", "Parent.Width - Self.X")
        if child.get("Name") == "TopbarHeader_8":
            set_rule(child, "Fill", "gblColorPaper")
            set_rule(child, "Width", "App.Width - gblNavWidth")
            set_rule(child, "X", "gblNavWidth")

    if not any(c.get("Name") == "SidebarMenu_Home" for c in home["TopParent"]["Children"]):
        home["TopParent"]["Children"].insert(0, sidebar)
    home_path.write_text(json.dumps(home, indent=2) + "\n")
    print("homepage sidebar added")


if __name__ == "__main__":
    main()
