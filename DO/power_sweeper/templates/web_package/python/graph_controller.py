"""MVC GraphController: Microsoft Graph / M365 JSON API."""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any
from urllib.parse import parse_qs, urlparse

# models/ is on sys.path when served from the package root
try:
    from graph_model import GraphModel
except ImportError:
    import sys

    sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "models"))
    from graph_model import GraphModel


def handle_graph(root: Path, path: str, method: str, body: dict[str, Any], query: dict[str, list[str]]) -> dict[str, Any]:
    resource = str(body.get("resource") or "")
    if not resource:
        parts = urlparse(path).path.rstrip("/").split("/")
        if parts and parts[-1] and parts[-1] != "graph":
            resource = parts[-1]
    if not resource:
        resource = (query.get("resource") or ["status"])[0]

    model = GraphModel(root)
    if resource in ("status", "me"):
        return model.status()
    if resource in ("mail", "sendMail"):
        return model.send_mail(
            {
                "to": str(body.get("to") or (query.get("to") or [""])[0]),
                "subject": str(body.get("subject") or ""),
                "body": str(body.get("body") or ""),
                "cc": str(body.get("cc") or ""),
                "bcc": str(body.get("bcc") or ""),
                "connector": str(body.get("connector") or "Microsoft.Graph"),
                "operation": str(body.get("operation") or "sendMail"),
            }
        )
    if resource == "calendar":
        return model.calendar(body)
    if resource in ("files", "drive"):
        return model.files(body)
    if resource in ("serviceAccount", "service_account"):
        return model.service_account(body)
    return {"ok": False, "error": "unknown graph resource"}
