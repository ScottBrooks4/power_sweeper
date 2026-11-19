"""MVC model: rows from the XML document or the SQL database."""
from __future__ import annotations

from pathlib import Path

from store import open_store


class AppModel:
    def __init__(self, root: Path):
        self.root = root

    def list(self, table: str):
        return open_store(self.root).list(table)
