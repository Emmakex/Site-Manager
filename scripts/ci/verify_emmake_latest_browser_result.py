#!/usr/bin/env python3
"""Gate the latest requested EMMAKE browser report, retaining historical failures."""
from __future__ import annotations

from pathlib import Path
import json

ROOT = Path("audits/emmake/browser")
PENDING = ROOT / "pending"
RESULTS = ROOT / "results"


def main() -> None:
    requests = sorted(PENDING.glob("visual-emmake-*.json"))
    if not requests:
        raise SystemExit("No browser QA request found")

    newest = requests[-1]
    request = json.loads(newest.read_text(encoding="utf-8"))
    if set(request) != {"version", "audit_id", "check", "site", "requested_by"}:
        raise SystemExit("Latest browser request schema mismatch")
    if request != {
        "version": "1",
        "audit_id": newest.stem,
        "check": "responsive-links",
        "site": "emmake-nuevaweb",
        "requested_by": "chatgpt",
    }:
        raise SystemExit("Latest browser request identity mismatch")

    result = RESULTS / newest.name
    if not result.is_file():
        raise SystemExit(f"Latest requested browser report missing: {result}")
    report = json.loads(result.read_text(encoding="utf-8"))
    if report.get("audit_id") != newest.stem or report.get("site") != "emmake-nuevaweb":
        raise SystemExit("Latest browser report is not bound to request")
    checks = report.get("checks")
    if not isinstance(checks, list) or len(checks) < 240:
        raise SystemExit("Latest report has insufficient acceptance coverage")
    if report.get("status") != "passed" or report.get("errors") != []:
        raise SystemExit("Latest browser report has failed")
    if any(not isinstance(c, dict) or c.get("ok") is not True for c in checks):
        raise SystemExit("Latest browser report has failed checks")
    if report.get("screenshots_taken") != 20:
        raise SystemExit("Expected 20 public desktop/mobile screenshots")

    check_map = {c.get("id"): c for c in checks}
    pages = (
        "home", "blog", "services", "about", "contact", "brands",
        "blog-digital", "privacy", "careers", "voice",
    )
    for viewport in ("desktop", "mobile"):
        for page in pages:
            for condition in ("http_200", "one_h1", "staging_noindex_global", "no_overflow"):
                key = f"{viewport}_{page}_{condition}"
                if key not in check_map or check_map[key]["ok"] is not True:
                    raise SystemExit(f"Missing or failed critical browser check: {key}")
    print(f"PASS: latest requested browser acceptance {newest.stem}: "
          f"{len(checks)} checks, 20 screenshots. Older baseline reports retained.")


if __name__ == "__main__":
    main()
