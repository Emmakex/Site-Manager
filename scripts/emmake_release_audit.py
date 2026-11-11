#!/usr/bin/env python3
"""EMMAKE launch preflight from verified, sanitized Site Manager evidence.

READ-ONLY. This is a release-blocking *assessment*, not a migration
command or access to a filesystem, database, hosting panel or WordPress.
It cannot authorize or perform a production cutover.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import re
from typing import Any
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_INVENTORY = ROOT / "commands/site-manager/results/cmd-emmake-published-targets-20261111-01.json"
DEFAULT_OVERVIEW = ROOT / "commands/site-manager/results/cmd-emmake-operational-status-20261111-01.json"
DEFAULT_GATES = ROOT / "ops/emmake/release-gates.json"
STAGING = "https://emmake.com/nuevaweb/"
SITE = "emmake-nuevaweb"
REQUIRED_PAGES = {
    "contacto", "servicios", "casos-de-exito", "sobre-nosotros",
    "blog", "nuestras-marcas", "politica-de-privacidad",
}
GATES = (
    "legacy_files_backup", "legacy_database_backup",
    "new_files_backup", "new_database_backup",
    "restore_rehearsal", "legacy_url_crawl_and_redirects",
    "resolved_post_permalinks", "content_ux_review",
    "forms_functional_test", "responsive_accessibility",
    "seo_canonical_robots_sitemap", "internal_links_and_media",
    "hosting_cutover_access", "cutover_rollback_plan",
)
REWRITE_MARKERS = re.compile(
    r"(?i)(?:%(?:category|postname|year|monthnum|day|author|post_id)%|"
    r"\{[a-f0-9]{32,64}\}(?:category|postname|year|monthnum|day|author|post_id)"
    r"\{[a-f0-9]{32,64}\})"
)


class PreflightError(ValueError):
    """The source of launch evidence is malformed or untrusted."""


def load_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise PreflightError("Missing or unreadable launch evidence") from exc
    if not isinstance(value, dict):
        raise PreflightError("Launch evidence must be a JSON object")
    return value


def _iso(value: Any) -> datetime:
    if not isinstance(value, str) or len(value) > 80:
        raise PreflightError("Missing bounded evidence timestamp")
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise PreflightError("Malformed evidence timestamp") from exc
    if parsed.tzinfo is None or parsed.utcoffset() is None:
        raise PreflightError("Evidence timestamp must include timezone")
    return parsed.astimezone(timezone.utc)


def _staging_url(url: Any) -> bool:
    if not isinstance(url, str) or len(url) > 2048:
        return False
    p = urlsplit(url)
    return (p.scheme == "https" and p.hostname == "emmake.com" and not p.username
            and not p.password and not p.query and not p.fragment and
            (p.path == "/nuevaweb/" or p.path.startswith("/nuevaweb/")))


def _unresolved(url: str) -> bool:
    return REWRITE_MARKERS.search(unquote(url)) is not None


def assess(
    inventory: dict[str, Any],
    overview: dict[str, Any],
    gates: dict[str, Any],
    *,
    now: datetime | None = None,
) -> dict[str, Any]:
    """Evaluate blockers; never infer backup, design, SEO or rollback from CI."""
    for record, action in ((inventory, "site.content_targets"), (overview, "site.overview")):
        if (record.get("site") != SITE or record.get("action") != action
                or record.get("stage") != "read" or record.get("status") != "success"
                or record.get("verified") is not True
                or not isinstance(record.get("data"), dict)):
            raise PreflightError("Launch input is not verified read-only EMMAKE evidence")
    posts = inventory["data"]
    site = overview["data"]
    if (posts.get("source") != "wordpress-native-published-content"
            or posts.get("scope") != "site.read"
            or posts.get("published_only") is not True
            or posts.get("private_content_included") is not False
            or posts.get("site_url") != STAGING
            or site.get("site_url") != STAGING
            or site.get("source") != "live-site-read"):
        raise PreflightError("Read evidence is not bound to the expected EMMAKE staging site")
    if not isinstance(posts.get("pages"), list) or not isinstance(posts.get("posts"), list):
        raise PreflightError("Content inventory is incomplete")
    if (gates.get("version") != "1" or gates.get("site") != SITE
            or set(gates.get("gates", {})) != set(GATES)):
        raise PreflightError("Release gate checklist is not the expected bounded contract")

    blocked: list[dict[str, str]] = []
    def block(code: str, reason: str) -> None:
        blocked.append({"code": code, "reason": reason})

    seen: set[int] = set()
    slugs: set[str] = set()
    unresolved_posts: list[int] = []
    home_found = False
    for kind in ("pages", "posts"):
        for record in posts[kind]:
            if not isinstance(record, dict) or record.get("status") != "publish":
                raise PreflightError("Nonpublic or malformed target in published inventory")
            post_id = record.get("post_id")
            if type(post_id) is not int or post_id < 1 or post_id in seen:
                raise PreflightError("Duplicate/invalid WordPress public target")
            seen.add(post_id)
            url = record.get("permalink")
            if not _staging_url(url):
                raise PreflightError("Published target has unexpected or unsafe origin")
            if kind == "pages":
                slugs.add(record.get("slug", ""))
                if record.get("homepage") is True and post_id == 7 and url == STAGING:
                    home_found = True
            if kind == "posts" and _unresolved(url):
                unresolved_posts.append(post_id)

    if unresolved_posts:
        block("POST_PERMALINK_UNRESOLVED",
              "Unresolved rewrite markers in %d sampled post URLs; first IDs: %s" % (
                  len(unresolved_posts), ", ".join(str(i) for i in unresolved_posts[:5])
              ))

    if not home_found or posts.get("homepage_post_id") != 7:
        block("HOMEPAGE_NOT_VERIFIED", "Canonical staging homepage ID 7 is missing")
    missing_pages = sorted(REQUIRED_PAGES - slugs)
    if missing_pages:
        block("KEY_PAGES_MISSING", "Essential public pages missing: " + ", ".join(missing_pages))
    if posts.get("pages_truncated") is not False:
        block("PAGES_INVENTORY_TRUNCATED", "Complete published page census has not been proven")
    if posts.get("posts_truncated") is not False:
        block("POSTS_INVENTORY_TRUNCATED", "Blog inventory is capped; complete URL coverage is not proven")
    if site.get("search_visibility") != "discourage":
        block("STAGING_INDEXABILITY_UNEXPECTED", "Staging must stay noindex until planned cutover")

    sample_time = _iso(posts.get("observed_at"))
    overview_time = _iso(site.get("generated_at"))
    clock = now or datetime.now(timezone.utc)
    if clock.tzinfo is None:
        raise PreflightError("Assessment clock must be timezone-aware")
    newest = max(sample_time, overview_time)
    age_minutes = (clock - newest).total_seconds() / 60
    if age_minutes > 60 or age_minutes < -5:
        block("EVIDENCE_NOT_FRESH", "A new live WordPress read is required immediately before cutover")

    gates_state: dict[str, str] = {}
    for name in GATES:
        item = gates["gates"][name]
        if not isinstance(item, dict) or set(item) != {"state", "evidence"}:
            raise PreflightError("Release gate structure changed: " + name)
        if item["state"] not in ("pending", "verified"):
            raise PreflightError("Unsupported release gate state")
        ev = item["evidence"]
        if item["state"] == "verified" and (not isinstance(ev, str) or not 8 <= len(ev) <= 200):
            raise PreflightError("Verified gate lacks bounded independently checked evidence")
        if item["state"] == "pending" and ev is not None:
            raise PreflightError("Pending gate may not claim evidence")
        gates_state[name] = item["state"]
        if item["state"] != "verified":
            block("GATE_" + name.upper(), "Required release evidence not certified: " + name)

    # No single preflight is deployment authorization: even zero blockers
    # would still require an explicit, independent protected cutover action.
    return {
        "site": SITE,
        "source": "read-only-verified-gateway-evidence",
        "target_after_cutover": "https://emmake.com/",
        "staging_site": STAGING,
        "checked_published_pages": len(posts["pages"]),
        "checked_published_posts": len(posts["posts"]),
        "required_pages_present": sorted(REQUIRED_PAGES - set(missing_pages)),
        "root_cutover_performed": False,
        "cutover_authorized_by_this_report": False,
        "ready_for_cutover_review": not blocked,
        "blocker_count": len(blocked),
        "blockers": blocked,
        "release_gates": gates_state,
        "notice": "This assessment neither copies WordPress nor changes hosting, WordPress, robots or redirects.",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Read-only EMMAKE release gate")
    parser.add_argument("--inventory", type=Path, default=DEFAULT_INVENTORY)
    parser.add_argument("--overview", type=Path, default=DEFAULT_OVERVIEW)
    parser.add_argument("--gates", type=Path, default=DEFAULT_GATES)
    parser.add_argument("--allow-blocked", action="store_true", help="Write a blocker report without failing this reporting workflow")
    args = parser.parse_args()
    try:
        report = assess(load_json(args.inventory), load_json(args.overview), load_json(args.gates))
    except PreflightError as exc:
        parser.exit(2, "EMMAKE preflight rejected malformed or untrusted evidence: " + str(exc) + "\n")
    print(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2))
    return 0 if report["ready_for_cutover_review"] or args.allow_blocked else 2


if __name__ == "__main__":
    raise SystemExit(main())
