"""Storage adapters: LOM (via /srv/http/LOM) or SQL."""
from __future__ import annotations

import json
import os
import re
import sqlite3
import sys
from pathlib import Path
from typing import Any, Dict, List, Optional
from xml.etree import ElementTree as ET


TABLE_GROUPS = {
    "VCRTrackingList": [
        "VCRTrackingList",
        "Tracking",
        "CDLS (L) VCR Tracking List",
        "CDLS_L_VCR_Tracking_List",
    ],
    "ApprovalTracking": [
        "ApprovalTracking",
        "Approvals",
        "CDLS (L) Approval Tracking",
        "CDLS_L_Approval_Tracking",
    ],
    "MemberLocations": [
        "MemberLocations",
        "Locations",
        "CDLS (L) Member Locations",
        "CDLS_L_Member_Locations",
    ],
    "AppUsers": ["AppUsers", "Users", "CDLS (L) App Users", "CDLS_L_App_Users"],
    "AppSettings": ["AppSettings", "Settings", "CDLS (L) App Settings", "CDLS_L_App_Settings"],
    "UserTracking": ["UserTracking", "CDLS (L) User Tracking", "CDLS_L_User_Tracking"],
}


def safe_name(name: str) -> str:
    return re.sub(r"\W+", "_", name) or "Items"


def _norm(name: str) -> str:
    return re.sub(r"[^\w]+", "_", name.strip().strip("'\""), flags=re.UNICODE).strip("_").lower()


def canonical_table(name: str) -> str:
    want = _norm(name)
    for canon, aliases in TABLE_GROUPS.items():
        if any(_norm(a) == want for a in aliases):
            return canon
    return safe_name(name)


def table_candidates(name: str) -> List[str]:
    canon = canonical_table(name)
    out = [canon]
    for alias in TABLE_GROUPS.get(canon, []):
        out.append(alias)
        out.append(safe_name(alias))
    out.append(safe_name(name))
    # preserve order, drop dups
    seen = set()
    uniq: List[str] = []
    for item in out:
        if item not in seen:
            seen.add(item)
            uniq.append(item)
    return uniq


def open_store(root: Path):
    cfg_path = root / "server" / "config.json"
    cfg = json.loads(cfg_path.read_text(encoding="utf-8")) if cfg_path.is_file() else {}
    storage = str(cfg.get("storage") or "xml").lower()
    if storage == "sql":
        return SqlStore(root, cfg)
    if storage == "lom":
        return LomStore(root, cfg)
    return XmlStore(root, cfg)


class SqlStore:
    def __init__(self, root: Path, cfg: Dict[str, Any]):
        self.root = root
        dsn = str(cfg.get("sql_dsn") or os.environ.get("SQL_DSN") or "")
        if dsn and not dsn.startswith("sqlite:"):
            # External engines can be wired via SQL_DSN later; default path is local sqlite.
            raise RuntimeError("Only sqlite DSN (or empty for local file) is bundled by default")
        db = root / "data" / "app.sqlite"
        schema = root / "data" / "schema.sql"
        if not db.exists() and schema.exists():
            conn = sqlite3.connect(db)
            conn.executescript(schema.read_text(encoding="utf-8"))
            conn.commit()
            conn.close()
        self.db = str(db)

    def _conn(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db)
        conn.row_factory = sqlite3.Row
        return conn

    def _resolve(self, table: str) -> str:
        for cand in table_candidates(table):
            ident = safe_name(cand)
            try:
                with self._conn() as conn:
                    conn.execute(f"SELECT 1 FROM {ident} LIMIT 0")
                return ident
            except Exception:
                continue
        return safe_name(canonical_table(table))

    def list(self, table: str) -> List[Dict[str, Any]]:
        t = self._resolve(table)
        with self._conn() as conn:
            rows = conn.execute(f"SELECT * FROM {t}").fetchall()
            return [dict(r) for r in rows]

    def get(self, table: str, row_id: Any) -> Optional[Dict[str, Any]]:
        t = safe_name(table)
        with self._conn() as conn:
            row = conn.execute(f"SELECT * FROM {t} WHERE Id = ?", (row_id,)).fetchone()
            return dict(row) if row else None

    def insert(self, table: str, row: Dict[str, Any]) -> Dict[str, Any]:
        t = safe_name(table)
        if "Id" not in row:
            row = {**row, "Id": str(len(self.list(table)) + 1)}
        cols = list(row.keys())
        placeholders = ",".join(["?"] * len(cols))
        sql = f"INSERT INTO {t} ({','.join(cols)}) VALUES ({placeholders})"
        with self._conn() as conn:
            conn.execute(sql, [row[c] for c in cols])
            conn.commit()
        return row

    def update(self, table: str, row: Dict[str, Any]) -> Dict[str, Any]:
        if "Id" not in row:
            return self.insert(table, row)
        t = safe_name(table)
        cols = [c for c in row.keys() if c != "Id"]
        if not cols:
            return row
        sets = ", ".join(f"{c}=?" for c in cols)
        with self._conn() as conn:
            conn.execute(
                f"UPDATE {t} SET {sets} WHERE Id = ?",
                [row[c] for c in cols] + [row["Id"]],
            )
            conn.commit()
        return row

    def delete(self, table: str, row_id: Any) -> bool:
        t = safe_name(table)
        with self._conn() as conn:
            conn.execute(f"DELETE FROM {t} WHERE Id = ?", (row_id,))
            conn.commit()
        return True


class LomStore:
    """XML document store. Uses LOM Python connector when liblom is available; else ElementTree."""

    def __init__(self, root: Path, cfg: Dict[str, Any]):
        self.root = root
        self.xml_path = root / "data" / "app.xml"
        if not self.xml_path.exists():
            self.xml_path.write_text("<app></app>\n", encoding="utf-8")
        self._native = None
        lom_home = Path(str(cfg.get("lom_home") or os.environ.get("LOM_HOME") or "/srv/http/LOM"))
        connector = lom_home / "connectors" / "python" / "lom.py"
        lib = lom_home / "native" / "lib" / "liblom.so"
        if connector.is_file() and lib.is_file():
            sys.path.insert(0, str(connector.parent))
            try:
                import lom  # type: ignore

                os.environ.setdefault("LOM_LIB", str(lib))
                self._native = lom.LomNative(str(self.xml_path), lib=str(lib))
            except Exception:
                self._native = None

    def _tree(self) -> ET.ElementTree:
        return ET.parse(self.xml_path)

    def list(self, table: str) -> List[Dict[str, Any]]:
        t = safe_name(table)
        tree = self._tree()
        parent = tree.find(t)
        if parent is None:
            parent = tree.getroot().find(t)
        rows: List[Dict[str, Any]] = []
        if parent is None:
            return rows
        for row_el in parent.findall("row"):
            item = dict(row_el.attrib)
            if row_el.text and "Title" not in item:
                item["Title"] = row_el.text
            rows.append(item)
        return rows

    def get(self, table: str, row_id: Any) -> Optional[Dict[str, Any]]:
        for row in self.list(table):
            if str(row.get("Id", "")) == str(row_id):
                return row
        return None

    def insert(self, table: str, row: Dict[str, Any]) -> Dict[str, Any]:
        t = safe_name(table)
        if "Id" not in row:
            row = {**row, "Id": str(len(self.list(table)) + 1)}
        tree = self._tree()
        root = tree.getroot()
        parent = root.find(t)
        if parent is None:
            parent = ET.SubElement(root, t)
        el = ET.SubElement(parent, "row", {str(k): str(v) for k, v in row.items()})
        el.text = str(row.get("Title", ""))
        tree.write(self.xml_path, encoding="unicode")
        if self._native is not None:
            try:
                self._native.save(str(self.xml_path))
            except Exception:
                pass
        return row

    def update(self, table: str, row: Dict[str, Any]) -> Dict[str, Any]:
        if "Id" not in row:
            return self.insert(table, row)
        existing = self.get(table, row["Id"])
        if existing is None:
            return self.insert(table, row)
        self.delete(table, row["Id"])
        merged = {**existing, **row}
        return self.insert(table, merged)

    def delete(self, table: str, row_id: Any) -> bool:
        t = safe_name(table)
        tree = self._tree()
        root = tree.getroot()
        parent = root.find(t)
        if parent is None:
            return False
        for row_el in list(parent.findall("row")):
            if str(row_el.attrib.get("Id", "")) == str(row_id):
                parent.remove(row_el)
        tree.write(self.xml_path, encoding="unicode")
        return True


class XmlStore:
    """XML document store (`data/app.xml`). Rows are attributes on `<row>` elements."""

    def __init__(self, root: Path, cfg: Dict[str, Any]):
        del cfg
        self.xml_path = root / "data" / "app.xml"
        self.xml_path.parent.mkdir(parents=True, exist_ok=True)
        if not self.xml_path.exists():
            self.xml_path.write_text("<app></app>\n", encoding="utf-8")

    def _tree(self) -> ET.ElementTree:
        return ET.parse(self.xml_path)

    def list(self, table: str) -> List[Dict[str, Any]]:
        t = safe_name(table)
        tree = self._tree()
        parent = tree.getroot().find(t)
        rows: List[Dict[str, Any]] = []
        if parent is None:
            return rows
        for row_el in parent.findall("row"):
            item = dict(row_el.attrib)
            if row_el.text and "Title" not in item:
                item["Title"] = row_el.text
            rows.append(item)
        return rows

    def get(self, table: str, row_id: Any) -> Optional[Dict[str, Any]]:
        for row in self.list(table):
            if str(row.get("Id", "")) == str(row_id):
                return row
        return None

    def insert(self, table: str, row: Dict[str, Any]) -> Dict[str, Any]:
        t = safe_name(table)
        if "Id" not in row:
            row = {**row, "Id": str(len(self.list(table)) + 1)}
        tree = self._tree()
        root = tree.getroot()
        parent = root.find(t)
        if parent is None:
            parent = ET.SubElement(root, t)
        el = ET.SubElement(parent, "row", {str(k): str(v) for k, v in row.items()})
        el.text = str(row.get("Title", ""))
        tree.write(self.xml_path, encoding="unicode")
        return row

    def update(self, table: str, row: Dict[str, Any]) -> Dict[str, Any]:
        if "Id" not in row:
            return self.insert(table, row)
        existing = self.get(table, row["Id"])
        if existing is None:
            return self.insert(table, row)
        self.delete(table, row["Id"])
        return self.insert(table, {**existing, **row})

    def delete(self, table: str, row_id: Any) -> bool:
        t = safe_name(table)
        tree = self._tree()
        parent = tree.getroot().find(t)
        if parent is None:
            return False
        removed = False
        for row_el in list(parent.findall("row")):
            if str(row_el.attrib.get("Id", "")) == str(row_id):
                parent.remove(row_el)
                removed = True
        if removed:
            tree.write(self.xml_path, encoding="unicode")
        return removed
