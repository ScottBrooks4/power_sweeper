#!/usr/bin/env python3
"""Fix Studio errors: risk colors, sidebar nav/items, empty formulas, TDR stubs."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path("/srv/http/power_sweeper/samples/ohs_risk_assessment/app")
SRC = ROOT / "Src"

FILL = (
    "ColorFade(\r\n"
    "    If(\r\n"
    "        ThisItem.Score <= 3, gblColorRiskLow,\r\n"
    "        ThisItem.Score <= 6, gblColorRiskModerate,\r\n"
    "        ThisItem.Score <= 12, gblColorRiskHigh,\r\n"
    "        gblColorRiskExtreme\r\n"
    "    ),\r\n"
    "    If(App.ActiveScreen = Dashboard && Coalesce(ThisItem.RiskCount, 0) = 0, 70%, 0%)\r\n"
    ")"
)
TEXT = 'If(App.ActiveScreen = Dashboard, Text(Coalesce(ThisItem.RiskCount, 0)), Text(ThisItem.Score))'
ONRESET = (
    "ClearCollect(\r\n    colRiskCells,\r\n    ForAll(\r\n        Sequence(25) As i,\r\n        With(\r\n            {\r\n                p: 6 - RoundUp(i.Value / 5, 0),\r\n                s: If(Mod(i.Value, 5) = 0, 5, Mod(i.Value, 5))\r\n            },\r\n            {\r\n                Probability: p,\r\n                Severity: s,\r\n                Score: p * s,\r\n                RiskCount: Coalesce(\r\n                    LookUp(colRiskCounts, Probability = p && Severity = s).RiskCount,\r\n                    0\r\n                )\r\n            }\r\n        )\r\n    )\r\n)"
)
BORDER = (
    "If(\r\n    App.ActiveScreen <> Dashboard && ThisItem.Probability = varHighlightP && ThisItem.Severity = varHighlightS,\r\n    2,\r\n    0\r\n)"
)
DISPLAY = "If(App.ActiveScreen = Dashboard, DisplayMode.View, DisplayMode.Edit)"
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
HIGHLIGHT = "ThisItem.ScreenName = App.ActiveScreen.Name"
TOOLTIP = (
    "If(\r\n"
    '    ThisItem.Score <= 3, "Low",\r\n'
    '    ThisItem.Score <= 6, "Moderate",\r\n'
    '    ThisItem.Score <= 12, "High",\r\n'
    '    "Extreme"\r\n'
    ') & " · P" & ThisItem.Probability & " × S" & ThisItem.Severity'
)
CHAT_NOTIFY = 'Notify(Coalesce(message, "Chat"), NotificationType.Information)'
ACTIONS = {
    "ClearCollections",
    "LoadApprovals",
    "LoadAttachments",
    "LoadTemplate",
    "MoveApprovalListItem",
    "MoveTemplateItemOrder",
    "RemovedUnSigned",
    "TotalRefresh",
    "approvalNotifyEmail",
    "completeNotify",
}
EF_PROPS = """    Properties:
      ClearCollections: =false
      Fill: =RGBA(224, 86, 6, 1)
      Height: =100
      LoadApprovals: =false
      LoadAttachments: =false
      LoadTemplate: =false
      MoveApprovalListItem: =false
      MoveTemplateItemOrder: =false
      RemovedUnSigned: =false
      TotalRefresh: =false
      Width: =100
      approvalNotifyEmail: =false
      chatNotify: =Notify(Coalesce(message, "Chat"), NotificationType.Information)
      completeNotify: =false
    Children:
      - TextCanvas1:
          Control: ModernText@1.0.0
          Properties:
            Align: =Align.Center
            Color: =RGBA(255, 255, 255, 1)
            Font: =Font.'Segoe UI'
            FontWeight: =FontWeight.Semibold
            Height: =Parent.Height
            PaddingBottom: =0
            PaddingLeft: =0
            PaddingRight: =0
            PaddingTop: =0
            RadiusBottomLeft: =0
            RadiusBottomRight: =0
            RadiusTopLeft: =0
            RadiusTopRight: =0
            Size: =14
            Text: ="External Functions"
            Width: =Parent.Width
"""


def yaml_block(path: Path, key: str) -> str:
    lines = path.read_text().splitlines()
    start = None
    for i, line in enumerate(lines):
        if line.startswith(f"    {key}: |-"):
            start = i + 1
            break
    if start is None:
        raise SystemExit(f"missing {key} in {path}")
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


def patch_script(script: str, prop: str, name: str) -> str:
    if not isinstance(script, str):
        return script
    stripped = script.strip()
    if stripped in ("", "="):
        if prop in {"LayoutMaxHeight", "LayoutMaxWidth"}:
            return "10000"
        if prop == "Text":
            return '""'
        if prop in ACTIONS or prop in {"OnSelect", "OnChange", "OnReset"}:
            return "false"
        if prop == "Items":
            return "[]"
        return "false"
    if "fxRiskFill(" in script or (prop == "Fill" and "ThisItem.Score" in script and "gblColorRiskLow" not in script and name in {"btnCell", ""}):
        if prop == "Fill" and ("fxRiskFill" in script or "cmpRiskGrid.Mode" in script):
            return FILL
    if prop == "Text" and "ThisItem.Score" in script and ("cmpRiskGrid.Mode" in script or "ThisItem.RiskCount" in script or "ThisItem.Count" in script):
        if name == "btnCell" or "cmpRiskGrid.Mode" in script:
            return TEXT
    if prop == "Tooltip" and ("fxRiskBand" in script or "ThisItem.Probability" in script) and "ThisItem.Score" in script:
        return TOOLTIP
    if prop == "BorderThickness" and name == "btnCell":
        return BORDER
    if prop == "DisplayMode" and name == "btnCell":
        return DISPLAY
    if prop == "OnReset" and ("colRiskCells" in script or "Count: Coalesce" in script):
        return ONRESET
    if "Navigate(ThisItem.Screen" in script:
        return NAV
    if "ThisItem.Screen = App.ActiveScreen" in script:
        return HIGHLIGHT
    if "Reset(SidebarGallery" in script:
        return "false"
    if prop == "Items" and "Filter()" in script:
        return "[]"
    if "Filter()" in script or "THCEE_" in script or "SendEmailV2" in script:
        if prop in ACTIONS or prop in {"OnSelect", "OnVisible", "OnHidden", "OnStart"}:
            return "false"
        if prop == "chatNotify":
            return CHAT_NOTIFY
    if prop in ACTIONS:
        return "false"
    if prop == "chatNotify":
        return CHAT_NOTIFY
    if "ThisItem.Count" in script:
        script = script.replace("ThisItem.Count", "ThisItem.RiskCount")
    if " && Count > 0" in script:
        script = script.replace(" && Count > 0", " && RiskCount > 0")
    if ").Count" in script and "colRiskCounts" in script:
        script = script.replace(").Count", ").RiskCount")
    if "Count: Coalesce" in script:
        script = script.replace("Count: Coalesce", "RiskCount: Coalesce")
    return script


def walk_json(node, name=""):
    if isinstance(node, dict):
        current = node.get("Name") or name
        if "InvariantScript" in node and "Property" in node:
            node["InvariantScript"] = patch_script(node["InvariantScript"], node.get("Property") or "", current)
        if "AutoRuleBindingString" in node and isinstance(node["AutoRuleBindingString"], str):
            s = node["AutoRuleBindingString"]
            if s.strip() in ("", "=") or "THCEE_" in s or "Filter()" in s or "SendEmailV2" in s or "Reset(SidebarGallery" in s:
                prop = node.get("InvariantPropertyName") or node.get("Property") or ""
                if "LayoutMax" in str(prop):
                    node["AutoRuleBindingString"] = "10000"
                elif s.strip() in ("", "="):
                    pass
                else:
                    node["AutoRuleBindingString"] = "false"
        for key, value in node.items():
            if key in {"InvariantScript", "AutoRuleBindingString"}:
                continue
            walk_json(value, current)
    elif isinstance(node, list):
        for item in node:
            walk_json(item, name)


def patch_yaml_files() -> None:
    for path in SRC.rglob("*.pa.yaml"):
        text = path.read_text()
        original = text
        text = re.sub(r"^[ \t]*LayoutMaxHeight: =\s*\n", "", text, flags=re.M)
        text = re.sub(r"^[ \t]*LayoutMaxWidth: =\s*\n", "", text, flags=re.M)
        text = re.sub(r"^([ \t]*Text: )=\s*$", r'\1=""', text, flags=re.M)
        if text != original:
            path.write_text(text)
            print("yaml cleaned", path.relative_to(SRC))


def rewrite_external_functions_yaml() -> None:
    path = SRC / "Components" / "ExternalFunctions.pa.yaml"
    text = path.read_text()
    marker = "    Properties:"
    idx = text.find(marker)
    if idx < 0:
        raise SystemExit("ExternalFunctions Properties missing")
    path.write_text(text[:idx] + EF_PROPS)
    print("rewrote ExternalFunctions yaml")


def sync_app() -> None:
    app_yaml = SRC / "App.pa.yaml"
    path = ROOT / "Controls" / "1.json"
    app = json.loads(path.read_text())
    formulas = yaml_block(app_yaml, "Formulas")
    onstart = yaml_block(app_yaml, "OnStart")
    assert "ScreenName" in formulas and "RiskCount" in onstart, "App yaml missing expected formulas"
    assert "fxRiskFill" not in formulas
    for rule in app["TopParent"]["Rules"]:
        if rule.get("Property") == "Formulas":
            rule["InvariantScript"] = formulas
        if rule.get("Property") == "OnStart":
            rule["InvariantScript"] = onstart
    path.write_text(json.dumps(app, indent=2) + "\n")
    print("synced App json")


def patch_json_files() -> None:
    for folder in ("Controls", "Components"):
        for path in (ROOT / folder).glob("*.json"):
            data = json.loads(path.read_text())
            walk_json(data)
            path.write_text(json.dumps(data, indent=2) + "\n")
            print("patched", path.name)


def main() -> None:
    rewrite_external_functions_yaml()
    patch_yaml_files()
    sync_app()
    patch_json_files()


if __name__ == "__main__":
    main()
