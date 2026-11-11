#!/usr/bin/env python3
"""EMMAKE final cutover readiness contract: positive, negative and real blocker tests."""
from __future__ import annotations

import copy
from datetime import datetime, timezone, timedelta
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
from emmake_release_audit import (  # noqa: E402
    PreflightError, GATES, SITE, STAGING, assess, load_json,
    DEFAULT_INVENTORY, DEFAULT_OVERVIEW, DEFAULT_GATES,
)


def fixtures() -> tuple[dict, dict, dict]:
    stamp = datetime.now(timezone.utc).isoformat()
    pages = [{
        "post_id": 7, "post_type": "page", "status": "publish",
        "slug": "emmake", "homepage": True, "permalink": STAGING,
    }]
    for i, slug in enumerate((
        "contacto", "servicios", "casos-de-exito", "sobre-nosotros",
        "blog", "nuestras-marcas", "politica-de-privacidad"
    ), 900):
        pages.append({
            "post_id": i, "post_type": "page", "status": "publish",
            "slug": slug, "homepage": False, "permalink": STAGING + slug + "/",
        })
    posts = [{
        "post_id": 1200, "post_type": "post", "status": "publish",
        "slug": "test-post", "homepage": False, "permalink": STAGING + "blog/test-post/",
    }]
    inventory = {
        "site": SITE, "action": "site.content_targets",
        "stage": "read", "status": "success", "verified": True,
        "data": {
            "source": "wordpress-native-published-content",
            "site_url": STAGING, "scope": "site.read",
            "published_only": True, "private_content_included": False,
            "homepage_post_id": 7, "observed_at": stamp,
            "pages": pages, "posts": posts,
            "pages_truncated": False, "posts_truncated": False,
        },
    }
    overview = {
        "site": SITE, "action": "site.overview",
        "stage": "read", "status": "success", "verified": True,
        "data": {
            "source": "live-site-read", "site_url": STAGING,
            "search_visibility": "discourage", "generated_at": stamp,
        },
    }
    gates = {
        "version": "1", "site": SITE,
        "gates": {
            k: {"state": "verified", "evidence": "reviewed-evidence-id-" + k} for k in GATES
        },
    }
    return inventory, overview, gates


inventory, overview, gates = fixtures()
ready = assess(inventory, overview, gates)
assert ready["ready_for_cutover_review"] is True
assert ready["root_cutover_performed"] is False
assert ready["cutover_authorized_by_this_report"] is False
assert ready["blockers"] == []
assert ready["checked_published_pages"] == 8
assert ready["checked_published_posts"] == 1

for malformed in [
    lambda inv, ov, g: inv.update(verified=False),
    lambda inv, ov, g: inv.update(status="failed"),
    lambda inv, ov, g: inv.update(site="different-tenant"),
    lambda inv, ov, g: ov.update(site="another-site"),
    lambda inv, ov, g: inv["data"].update(site_url="https://other.example/"),
    lambda inv, ov, g: inv["data"].update(published_only=False),
    lambda inv, ov, g: inv["data"].update(private_content_included=True),
    lambda inv, ov, g: inv["data"]["posts"][0].update(status="draft"),
    lambda inv, ov, g: inv["data"]["pages"][0].update(permalink="https://attacker.example/"),
    lambda inv, ov, g: inv["data"]["pages"][0].update(post_id=0),
    lambda inv, ov, g: inv["data"]["posts"][0].update(post_id=7),
    lambda inv, ov, g: g.update(version="999"),
    lambda inv, ov, g: g["gates"].pop("new_database_backup"),
    lambda inv, ov, g: g["gates"]["new_files_backup"].update(state="verified", evidence=""),
    lambda inv, ov, g: g["gates"]["new_files_backup"].update(state="unrecognized"),
    lambda inv, ov, g: g["gates"]["new_files_backup"].update(state="pending", evidence="false-proof"),
]:
    inv, ov, g = copy.deepcopy((inventory, overview, gates))
    malformed(inv, ov, g)
    try:
        assess(inv, ov, g)
    except PreflightError:
        pass
    else:
        raise AssertionError("Untrusted cutover input or forged gate evidence was accepted")

for blocked in [
    lambda inv, ov, g: inv["data"].update(posts_truncated=True),
    lambda inv, ov, g: inv["data"].update(pages_truncated=True),
    lambda inv, ov, g: inv["data"]["posts"][0].update(permalink=STAGING + "%category%/%postname%/"),
    lambda inv, ov, g: inv["data"]["posts"][0].update(permalink=STAGING + "%25category%25/%25postname%25/"),
    lambda inv, ov, g: inv["data"]["posts"][0].update(permalink=STAGING + "{d276abfeceab40cca0e158fc6217176554b8e54a1f85b6eb004941797db52171}category{d276abfeceab40cca0e158fc6217176554b8e54a1f85b6eb004941797db52171}/"),
    lambda inv, ov, g: inv["data"]["pages"].pop(1),
    lambda inv, ov, g: ov["data"].update(search_visibility="index"),
    lambda inv, ov, g: g["gates"]["restore_rehearsal"].update(state="pending", evidence=None),
]:
    inv, ov, g = copy.deepcopy((inventory, overview, gates))
    blocked(inv, ov, g)
    result = assess(inv, ov, g)
    assert result["ready_for_cutover_review"] is False
    assert result["blocker_count"] >= 1

stale = assess(
    inventory, overview, gates,
    now=datetime.now(timezone.utc) + timedelta(hours=2),
)
assert "EVIDENCE_NOT_FRESH" in {b["code"] for b in stale["blockers"]}

actual = assess(
    load_json(DEFAULT_INVENTORY),
    load_json(DEFAULT_OVERVIEW),
    load_json(DEFAULT_GATES),
)
assert actual["ready_for_cutover_review"] is False
codes = {b["code"] for b in actual["blockers"]}
assert "POST_PERMALINK_UNRESOLVED" in codes
assert "POSTS_INVENTORY_TRUNCATED" in codes
assert "GATE_RESTORE_REHEARSAL" in codes
assert "GATE_LEGACY_URL_CRAWL_AND_REDIRECTS" in codes
assert "GATE_HOSTING_CUTOVER_ACCESS" in codes
assert actual["root_cutover_performed"] is False
assert len(actual["release_gates"]) == len(GATES)
assert "private" not in json.dumps(actual)

print("emmake_release_audit_test: OK (current EMMAKE remains blocked)")
