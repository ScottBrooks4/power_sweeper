#!/usr/bin/env python3
"""Upload /srv/http/power_sweeper → public_html/power_sweeper on freement.cloud.

Reads FTP password from FileZilla recentservers.xml (same as convert/ffs).
Skips .git, samples, dist, tests, and *.msapp packages.
Does not print the password.
"""
from __future__ import annotations

import base64
import ftplib
import os
import sys
import time
import xml.etree.ElementTree as ET
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

LOCAL = Path("/srv/http/power_sweeper")
REMOTE = "/public_html/power_sweeper"
FTP_HOST = "ftp.freement.cloud"
FTP_USER = "upload@freement.cloud"

SKIP_DIRS = {
    ".git",
    ".github",
    ".cursor",
    "samples",
    "dist",
    "tests",
    "import",
    "node_modules",
    "__pycache__",
    "storage/tmp",
    "storage/out",
}
# Keep storage/benchmarks/estimate_model.json for shared ETA learning; skip run dumps.
SKIP_BENCHMARK_NAMES = {
    "live_runs.jsonl",
    "estimate_model.lock",
}
SKIP_NAMES = {".DS_Store", "Thumbs.db", "composer.phar"}
SKIP_SUFFIXES = {".msapp", ".log"}


def ftp_password() -> str:
    env = os.environ.get("CONVERT_FTP_PASS")
    if env:
        return env
    recent = Path.home() / ".config/filezilla/recentservers.xml"
    root = ET.parse(recent).getroot()
    for server in root.iter("Server"):
        host = (server.findtext("Host") or "").lower()
        user = server.findtext("User") or ""
        if "freement" in host and "upload" in user:
            pw = server.find("Pass")
            if pw is not None and (pw.text or ""):
                return base64.b64decode(pw.text).decode()
    raise SystemExit("No FTP password (set CONVERT_FTP_PASS or use FileZilla saved site)")


def connect() -> ftplib.FTP:
    ftp = ftplib.FTP(FTP_HOST, timeout=120)
    ftp.login(FTP_USER, ftp_password())
    ftp.set_pasv(True)
    return ftp


def ensure_dir(ftp: ftplib.FTP, path: str) -> None:
    cur = ""
    for part in [p for p in path.split("/") if p]:
        cur += "/" + part
        try:
            ftp.mkd(cur)
        except ftplib.error_perm:
            pass


def collect() -> list[tuple[Path, str]]:
    items: list[tuple[Path, str]] = []
    for dirpath, dirnames, filenames in os.walk(LOCAL):
        rel_dir = Path(dirpath).relative_to(LOCAL)
        rel_posix = rel_dir.as_posix()
        dirnames[:] = [
            d
            for d in dirnames
            if d not in SKIP_DIRS
            and (rel_posix == "." or f"{rel_posix}/{d}" not in SKIP_DIRS)
        ]
        for name in filenames:
            if name in SKIP_NAMES or Path(name).suffix.lower() in SKIP_SUFFIXES:
                continue
            if rel_posix == "storage/benchmarks" and (
                name in SKIP_BENCHMARK_NAMES or name.startswith("estimate_runs_")
            ):
                continue
            local = Path(dirpath) / name
            if not local.is_file():
                continue
            rel = rel_dir / name if str(rel_dir) != "." else Path(name)
            items.append((local, f"{REMOTE}/{rel.as_posix()}"))
    return items


def upload_one(local: Path, remote: str) -> tuple[str, str | None]:
    ftp = connect()
    try:
        ensure_dir(ftp, str(Path(remote).parent).replace("\\", "/"))
        with local.open("rb") as fh:
            ftp.storbinary(f"STOR {remote}", fh)
        return remote, None
    except Exception as e:
        return remote, str(e)
    finally:
        try:
            ftp.quit()
        except Exception:
            pass


def main() -> int:
    if not LOCAL.is_dir():
        raise SystemExit(f"missing {LOCAL}")
    if not (LOCAL / "vendor").is_dir():
        raise SystemExit("missing vendor/ — run composer install first")

    items = collect()
    print(f"power_sweeper: {len(items)} files -> {REMOTE}")
    ftp = connect()
    ensure_dir(ftp, REMOTE)
    ensure_dir(ftp, f"{REMOTE}/storage/tmp")
    ensure_dir(ftp, f"{REMOTE}/storage/out")
    stamp = time.strftime("%Y%m%d-%H%M%S")
    print(f"connected; runtime dirs ready ({stamp})")
    ftp.quit()

    ok = fail = 0
    with ThreadPoolExecutor(max_workers=6) as pool:
        futs = [pool.submit(upload_one, local, remote) for local, remote in items]
        for i, fut in enumerate(as_completed(futs), 1):
            remote, err = fut.result()
            if err:
                fail += 1
                print(f"FAIL {remote}: {err}")
            else:
                ok += 1
            if i % 50 == 0 or i == len(futs):
                print(f"  … {i}/{len(futs)} (ok={ok} fail={fail})")
    print(f"done ok={ok} fail={fail}")
    return 0 if fail == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
