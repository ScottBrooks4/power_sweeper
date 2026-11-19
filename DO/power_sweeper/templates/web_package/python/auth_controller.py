"""MVC AuthController: Azure AD / OpenID Connect entry points."""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any
from urllib.parse import quote

from graph_gateway import GraphGateway


def handle_auth(root: Path, action: str, query: dict[str, list[str]]) -> tuple[int, str, bytes]:
    """Return (status, content_type_or_redirect, body). content_type 'redirect' uses Location body."""
    cfg_path = root / "server" / "config.json"
    cfg = json.loads(cfg_path.read_text(encoding="utf-8")) if cfg_path.is_file() else {}
    if not isinstance(cfg, dict):
        cfg = {}
    graph = GraphGateway(root, cfg)
    auth = cfg.get("auth") if isinstance(cfg.get("auth"), dict) else {}
    action = (action or "login").strip("/") or "login"

    if action.endswith("logout") or action == "logout":
        graph.sign_out()
        return 302, "redirect", b"/"

    if action.endswith("callback") or action == "callback":
        graph.sign_in(
            {
                "name": (query.get("name") or ["Demo M365 User"])[0],
                "preferred_username": (query.get("email") or ["demo.user@forces.gc.ca"])[0],
                "oid": (query.get("oid") or ["demo-user"])[0],
                "tid": str(auth.get("tenant") or "common"),
            }
        )
        return 302, "redirect", b"/"

    mode = str(auth.get("mode") or "demo").lower()
    client_id = str(auth.get("client_id") or "")
    tenant = str(auth.get("tenant") or "common")
    if mode == "live" and client_id:
        redirect_uri = quote("http://localhost:8080/auth/callback", safe="")
        scope = quote("openid profile email User.Read Mail.Send", safe="")
        url = (
            f"https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize"
            f"?client_id={client_id}&response_type=code&redirect_uri={redirect_uri}"
            f"&response_mode=query&scope={scope}"
        )
        return 302, "redirect", url.encode("utf-8")

    graph.sign_in(
        {
            "name": "Demo M365 User",
            "preferred_username": "demo.user@forces.gc.ca",
            "oid": "demo-user",
            "tid": tenant,
        }
    )
    return 302, "redirect", b"/"
