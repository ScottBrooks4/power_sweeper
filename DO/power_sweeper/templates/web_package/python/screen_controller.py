"""MVC ScreenController: serve painted app (same layout as canvas) + auth chip."""
from __future__ import annotations

import html
import re
from pathlib import Path

try:
    from graph_model import GraphModel
except ImportError:
    import sys

    sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "models"))
    from graph_model import GraphModel


def render_app(root: Path, screen: str = "") -> bytes | None:
    index = root / "public" / "index.html"
    if not index.is_file():
        return None
    text = index.read_text(encoding="utf-8")
    if screen:
        safe = html.escape(screen, quote=True)
        text = re.sub(r'data-start-screen="[^"]*"', f'data-start-screen="{safe}"', text, count=1)
        text = re.sub(r"\sscreen--active", "", text)
        text = re.sub(
            rf'(<section\b[^>]*\bdata-screen="{re.escape(safe)}"[^>]*)\bclass="([^"]*)"',
            r'\1 class="\2 screen--active"',
            text,
            count=1,
        )

    try:
        user = GraphModel(root).current_user()
    except Exception:  # noqa: BLE001
        user = None
    if "mvc-auth-chip" not in text:
        if user is None:
            chip = '<a class="mvc-auth-chip" href="auth/login">Sign in with Microsoft</a>'
        else:
            label = html.escape(str(user.get("displayName") or user.get("mail") or "Signed in"))
            chip = (
                f'<span class="mvc-auth-chip mvc-auth-chip--in">{label}'
                f' · <a href="auth/logout">Sign out</a></span>'
            )
        text = text.replace("<body>", "<body>\n  " + chip, 1)

    return text.encode("utf-8")


def render_screen(root: Path, screen: str) -> bytes | None:
    """Legacy per-view file; prefer render_app for the live shell."""
    app = render_app(root, screen)
    if app is not None:
        return app
    safe = re.sub(r"[^\w.-]+", "_", screen or "") or ""
    view = root / "views" / f"{safe}.html"
    if not safe or not view.is_file():
        return None
    return view.read_bytes()
