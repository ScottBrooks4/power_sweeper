#!/usr/bin/env python3
"""Self-contained Power Sweeper web package (Python)."""
from __future__ import annotations

import json
import os
import sys
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT / "server"))
sys.path.insert(0, str(ROOT / "controllers"))
sys.path.insert(0, str(ROOT / "models"))
from store import open_store  # noqa: E402

DEFAULT_ALLOWLIST = [
    "morath99@gmail.com",
    "scott.brooks4@ecn.forces.gc.ca",
    "scott.brooks4@forces.gc.ca",
]


def _norm_email(addr: str) -> str:
    addr = (addr or "").strip()
    if "<" in addr and ">" in addr:
        addr = addr[addr.find("<") + 1 : addr.find(">")]
    return addr.strip().lower()


def _parse_emails(raw: str) -> list[str]:
    out: list[str] = []
    for part in (raw or "").replace(",", ";").split(";"):
        e = _norm_email(part)
        if e and "@" in e and e not in out:
            out.append(e)
    return out


def _invoke_mail(root: Path, body: dict) -> dict:
    # Prefer Graph gateway when MVC Graph files are present.
    gateway = root / "server" / "graph_gateway.py"
    if gateway.is_file():
        try:
            from graph_gateway import GraphGateway

            cfg_path = root / "server" / "config.json"
            cfg = json.loads(cfg_path.read_text(encoding="utf-8")) if cfg_path.is_file() else {}
            return GraphGateway(root, cfg if isinstance(cfg, dict) else {}).send_mail(body)
        except Exception:  # noqa: BLE001
            pass
    cfg_path = root / "server" / "config.json"
    cfg = json.loads(cfg_path.read_text(encoding="utf-8")) if cfg_path.is_file() else {}
    allowed = [_norm_email(str(a)) for a in (cfg.get("mail_allowlist") or DEFAULT_ALLOWLIST)]
    to_raw = str(body.get("to") or "")
    original = _parse_emails(to_raw)
    kept = [e for e in original if e in allowed]
    blocked = [e for e in original if e not in allowed]
    rewritten = False
    if not kept and original:
        kept = [allowed[0]] if allowed else []
        rewritten = True
    delivered = "; ".join(kept)
    record = {
        "ok": bool(kept),
        "delivery": "outbox",
        "sent": False,
        "original": {"to": to_raw, "subject": str(body.get("subject") or "")},
        "delivered": {"to": delivered, "subject": str(body.get("subject") or "")},
        "blocked": blocked,
        "rewritten": rewritten,
        "allowed": bool(kept),
    }
    outbox = root / "data" / "outbox"
    outbox.mkdir(parents=True, exist_ok=True)
    (outbox / "last.json").write_text(json.dumps(record, indent=2), encoding="utf-8")
    return record


def _is_mvc() -> bool:
    cfg_path = ROOT / "server" / "config.json"
    if cfg_path.is_file():
        try:
            cfg = json.loads(cfg_path.read_text(encoding="utf-8"))
            if str(cfg.get("layout") or "").lower() == "mvc":
                return True
        except Exception:  # noqa: BLE001
            pass
    return (ROOT / "controllers").is_dir() and (ROOT / "models" / "graph_model.py").is_file()


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT / "public"), **kwargs)

    def _json(self, status: int, result: dict) -> None:
        payload = json.dumps(result).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def _redirect(self, location: str) -> None:
        self.send_response(302)
        self.send_header("Location", location)
        self.end_headers()

    def do_POST(self):  # noqa: N802
        parsed = urlparse(self.path)
        if _is_mvc() and parsed.path.startswith("/api/graph"):
            length = int(self.headers.get("Content-Length", "0"))
            raw = self.rfile.read(length) if length else b"{}"
            try:
                body = json.loads(raw.decode("utf-8") or "{}")
            except json.JSONDecodeError:
                body = {}
            from graph_controller import handle_graph

            result = handle_graph(ROOT, parsed.path, "POST", body, parse_qs(parsed.query))
            self._json(200, result)
            return
        if parsed.path != "/api/data":
            self.send_error(404)
            return
        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length) if length else b"{}"
        try:
            body = json.loads(raw.decode("utf-8") or "{}")
        except json.JSONDecodeError:
            body = {}
        action = body.get("action", "list")
        table = body.get("table", "Items")
        store = open_store(ROOT)
        try:
            if action == "invoke":
                result = _invoke_mail(ROOT, body)
            elif action == "list":
                result = {"ok": True, "rows": store.list(table)}
            elif action == "get":
                result = {"ok": True, "row": store.get(table, body.get("id"))}
            elif action == "insert":
                result = {"ok": True, "row": store.insert(table, body.get("row") or {})}
            elif action == "update":
                result = {"ok": True, "row": store.update(table, body.get("row") or {})}
            elif action == "delete":
                result = {"ok": True, "deleted": store.delete(table, body.get("id"))}
            else:
                result = {"ok": False, "error": "unknown action"}
            self._json(200, result)
        except Exception as exc:  # noqa: BLE001
            self._json(500, {"ok": False, "error": str(exc)})

    def do_GET(self):  # noqa: N802
        parsed = urlparse(self.path)
        query = parse_qs(parsed.query)

        if _is_mvc() and (parsed.path.startswith("/auth/") or parsed.path == "/auth"):
            from auth_controller import handle_auth

            action = parsed.path[len("/auth") :].strip("/") or "login"
            status, kind, body = handle_auth(ROOT, action, query)
            if kind == "redirect":
                self._redirect(body.decode("utf-8"))
                return
            self.send_response(status)
            self.send_header("Content-Type", kind)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return

        if _is_mvc() and parsed.path.startswith("/api/graph"):
            from graph_controller import handle_graph

            result = handle_graph(ROOT, parsed.path, "GET", {}, query)
            self._json(200, result)
            return

        if parsed.path == "/api/data":
            self.do_POST()
            return
        screen = (query.get("screen") or [""])[0]
        if _is_mvc() and (parsed.path in ("", "/") or screen):
            from screen_controller import render_app

            data = render_app(ROOT, screen)
            if data is None:
                self.send_error(404, "Missing public/index.html")
                return
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
            return

        if screen and (ROOT / "views").is_dir():
            from screen_controller import render_screen

            data = render_screen(ROOT, screen)
            if data is None:
                self.send_error(404, "Unknown screen")
                return
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
            return
        if parsed.path in ("", "/"):
            self.path = "/index.html"
        return SimpleHTTPRequestHandler.do_GET(self)


def main() -> None:
    port = int(os.environ.get("PORT", "8080"))
    server = ThreadingHTTPServer(("0.0.0.0", port), Handler)
    print(f"Power Sweeper web package on http://0.0.0.0:{port}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
