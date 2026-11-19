"""Microsoft Graph / M365 gateway (demo outbox + live token hooks)."""
from __future__ import annotations

import json
import os
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any


DEFAULT_SCOPES = [
    "User.Read",
    "Mail.Send",
    "Calendars.ReadWrite",
    "Files.ReadWrite.All",
    "Sites.ReadWrite.All",
]


class GraphGateway:
    def __init__(self, root: Path, config: dict[str, Any] | None = None):
        self.root = root
        self.config = config or {}

    def _session_path(self) -> Path:
        return self.root / "data" / "session.json"

    def current_user(self) -> dict[str, Any] | None:
        path = self._session_path()
        if not path.is_file():
            return None
        raw = json.loads(path.read_text(encoding="utf-8"))
        user = raw.get("user") if isinstance(raw, dict) else None
        return user if isinstance(user, dict) else None

    def status(self) -> dict[str, Any]:
        graph = self.config.get("graph") if isinstance(self.config.get("graph"), dict) else {}
        auth = self.config.get("auth") if isinstance(self.config.get("auth"), dict) else {}
        scopes = graph.get("scopes") if isinstance(graph.get("scopes"), list) else DEFAULT_SCOPES
        sa = graph.get("service_account") if isinstance(graph.get("service_account"), dict) else {}
        return {
            "ok": True,
            "mode": str(graph.get("mode") or "outbox"),
            "tenant": str(auth.get("tenant") or graph.get("tenant") or ""),
            "client_id": str(auth.get("client_id") or ""),
            "scopes": list(scopes),
            "service_account": {
                "enabled": bool(sa.get("enabled", True)),
                "mode": str(sa.get("mode") or "app_only"),
            },
            "signed_in": self.current_user() is not None,
            "user": self.current_user(),
        }

    def sign_in(self, claims: dict[str, Any] | None = None) -> dict[str, Any]:
        claims = claims or {}
        auth = self.config.get("auth") if isinstance(self.config.get("auth"), dict) else {}
        user = {
            "id": str(claims.get("oid") or "demo-user"),
            "displayName": str(claims.get("name") or "Demo M365 User"),
            "mail": str(
                claims.get("preferred_username")
                or claims.get("mail")
                or "demo.user@forces.gc.ca"
            ),
            "tenant": str(claims.get("tid") or auth.get("tenant") or "common"),
            "auth": "oidc_demo",
        }
        data = self.root / "data"
        data.mkdir(parents=True, exist_ok=True)
        self._session_path().write_text(
            json.dumps({"user": user}, indent=2) + "\n",
            encoding="utf-8",
        )
        return {"ok": True, "user": user}

    def sign_out(self) -> dict[str, Any]:
        path = self._session_path()
        if path.is_file():
            path.unlink()
        return {"ok": True}

    def send_mail(self, message: dict[str, Any]) -> dict[str, Any]:
        graph = self.config.get("graph") if isinstance(self.config.get("graph"), dict) else {}
        mode = str(graph.get("mode") or "outbox").lower()
        if mode == "live":
            return self._live_send_mail(message)
        outbox = self.root / "data" / "outbox"
        outbox.mkdir(parents=True, exist_ok=True)
        record = {
            "ok": True,
            "delivery": "outbox",
            "connector": str(message.get("connector") or "Microsoft.Graph"),
            "operation": str(message.get("operation") or "sendMail"),
            "to": str(message.get("to") or ""),
            "subject": str(message.get("subject") or ""),
            "body": str(message.get("body") or ""),
        }
        (outbox / "last.json").write_text(json.dumps(record, indent=2), encoding="utf-8")
        return record

    def list_calendar(self, query: dict[str, Any] | None = None) -> dict[str, Any]:
        graph = self.config.get("graph") if isinstance(self.config.get("graph"), dict) else {}
        return {
            "ok": True,
            "source": "graph.calendar",
            "mode": str(graph.get("mode") or "outbox"),
            "events": [],
            "note": "Wire graph.mode=live + Calendars.ReadWrite to list tenant events.",
            "query": query or {},
        }

    def list_files(self, query: dict[str, Any] | None = None) -> dict[str, Any]:
        graph = self.config.get("graph") if isinstance(self.config.get("graph"), dict) else {}
        return {
            "ok": True,
            "source": "graph.drive",
            "mode": str(graph.get("mode") or "outbox"),
            "files": [],
            "note": "Wire graph.mode=live + Files.ReadWrite.All to list OneDrive/SharePoint files.",
            "query": query or {},
        }

    def service_account(self, body: dict[str, Any] | None = None) -> dict[str, Any]:
        body = body or {}
        graph = self.config.get("graph") if isinstance(self.config.get("graph"), dict) else {}
        sa = graph.get("service_account") if isinstance(graph.get("service_account"), dict) else {}
        return {
            "ok": True,
            "enabled": bool(sa.get("enabled", True)),
            "mode": str(sa.get("mode") or "app_only"),
            "action": str(body.get("action") or "token"),
            "resource": str(body.get("resource") or sa.get("resource") or "https://graph.microsoft.com"),
            "note": "Non-interactive client-credentials token for SharePoint / CRM sync.",
        }

    def _live_send_mail(self, message: dict[str, Any]) -> dict[str, Any]:
        graph = self.config.get("graph") if isinstance(self.config.get("graph"), dict) else {}
        token = str(graph.get("access_token") or os.environ.get("GRAPH_ACCESS_TOKEN") or "")
        if not token:
            return {
                "ok": False,
                "error": "graph.mode=live requires graph.access_token or GRAPH_ACCESS_TOKEN",
            }
        payload = json.dumps(
            {
                "message": {
                    "subject": str(message.get("subject") or ""),
                    "body": {
                        "contentType": "HTML",
                        "content": str(message.get("body") or ""),
                    },
                    "toRecipients": [
                        {"emailAddress": {"address": a.strip()}}
                        for a in str(message.get("to") or "").replace(";", ",").split(",")
                        if a.strip()
                    ],
                },
                "saveToSentItems": True,
            }
        ).encode("utf-8")
        req = urllib.request.Request(
            "https://graph.microsoft.com/v1.0/me/sendMail",
            data=payload,
            method="POST",
            headers={
                "Authorization": f"Bearer {token}",
                "Content-Type": "application/json",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return {"ok": True, "delivery": "graph", "status": resp.status}
        except urllib.error.HTTPError as exc:
            return {
                "ok": False,
                "delivery": "graph",
                "status": exc.code,
                "error": exc.read().decode("utf-8", errors="replace"),
            }
        except Exception as exc:  # noqa: BLE001
            return {"ok": False, "delivery": "graph", "error": str(exc)}
