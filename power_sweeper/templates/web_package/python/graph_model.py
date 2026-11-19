"""MVC model for Microsoft Graph / M365."""
from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any


class GraphModel:
    def __init__(self, root: Path):
        self.root = root
        server = str(root / "server")
        if server not in sys.path:
            sys.path.insert(0, server)
        from graph_gateway import GraphGateway  # noqa: WPS433

        cfg_path = root / "server" / "config.json"
        cfg = json.loads(cfg_path.read_text(encoding="utf-8")) if cfg_path.is_file() else {}
        self.graph = GraphGateway(root, cfg if isinstance(cfg, dict) else {})

    def status(self) -> dict[str, Any]:
        return self.graph.status()

    def current_user(self) -> dict[str, Any] | None:
        return self.graph.current_user()

    def send_mail(self, message: dict[str, Any]) -> dict[str, Any]:
        return self.graph.send_mail(message)

    def calendar(self, query: dict[str, Any] | None = None) -> dict[str, Any]:
        return self.graph.list_calendar(query)

    def files(self, query: dict[str, Any] | None = None) -> dict[str, Any]:
        return self.graph.list_files(query)

    def service_account(self, body: dict[str, Any] | None = None) -> dict[str, Any]:
        return self.graph.service_account(body)
