#!/usr/bin/env python3
"""Strict read-only projection of WordPress's published page/post target list.

This rejects arbitrary REST responses, private drafts, source HTML, post meta,
cross-origin URLs, excessive results and malformed/duplicated target identities.
Only explicitly public metadata from the trusted site.read route is persisted.
"""
from __future__ import annotations

from datetime import datetime
import re
from typing import Any
from urllib.parse import urlsplit, urlunsplit

SOURCE = "wordpress-native-published-content"
BASE_KEYS = {
    "connection_id", "source", "observed_at", "site_url", "homepage_post_id",
    "scope", "published_only", "page_limit", "post_limit", "pages", "posts",
    "pages_truncated", "posts_truncated", "private_values_included",
    "published_page_total", "published_post_total",
}
LEGACY_BASE_KEYS = BASE_KEYS - {"published_page_total", "published_post_total"}
ENTRY_KEYS = {
    "post_id", "post_type", "status", "title", "slug", "permalink",
    "parent_id", "homepage", "modified_gmt",
}
POST_LIMITS = {"page": 80, "post": 40}


class ContentTargetsError(ValueError):
    """WordPress published-target read did not pass the closed contract."""


def https_url(value: Any) -> str:
    if not isinstance(value, str) or not 1 <= len(value) <= 2048:
        raise ContentTargetsError("WordPress target URL missing or excessive")
    try:
        parsed = urlsplit(value)
        if (parsed.scheme != "https" or not parsed.hostname or parsed.username
                or parsed.password or parsed.query or parsed.fragment
                or any(ord(char) < 32 for char in value)):
            raise ContentTargetsError("WordPress content URL must be bounded HTTPS without credentials")
    except ValueError as exc:
        raise ContentTargetsError("WordPress content URL contains invalid host/port") from exc
    return urlunsplit((parsed.scheme, parsed.netloc, parsed.path, "", ""))


def summarize_content_targets(raw: Any) -> dict[str, Any]:
    if not isinstance(raw, dict) or set(raw) not in (BASE_KEYS, LEGACY_BASE_KEYS):
        raise ContentTargetsError("WordPress content targets returned unknown or missing fields")
    has_totals = set(raw) == BASE_KEYS
    connection = raw["connection_id"]
    if (not isinstance(connection, str) or len(connection) > 128
            or re.fullmatch(r"smc_[a-zA-Z0-9_-]{12,124}", connection) is None):
        raise ContentTargetsError("WordPress content targets lack trusted connection identity")
    if (raw["source"] != SOURCE or raw["scope"] != "site.read"
            or raw["published_only"] is not True or raw["private_values_included"] is not False):
        raise ContentTargetsError("WordPress content target scope or public-only policy changed")
    base_url = https_url(raw["site_url"])
    base = urlsplit(base_url)
    if raw["page_limit"] != 80 or raw["post_limit"] != 40:
        raise ContentTargetsError("WordPress content query exceeded approved limits")
    totals = {}
    for key in ("published_page_total", "published_post_total"):
        total = raw[key] if has_totals else None
        if has_totals and (type(total) is not int or not 0 <= total <= 10_000_000):
            raise ContentTargetsError("WordPress published totals are invalid")
        totals[key] = total
    homepage = raw["homepage_post_id"]
    if homepage is not None and (type(homepage) is not int or homepage <= 0):
        raise ContentTargetsError("WordPress homepage identity is malformed")
    stamp = raw["observed_at"]
    if not isinstance(stamp, str) or len(stamp) > 64:
        raise ContentTargetsError("WordPress content target timestamp missing")
    try:
        parsed_time = datetime.fromisoformat(stamp.replace("Z", "+00:00"))
        if parsed_time.utcoffset() is None:
            raise ValueError("timezone missing")
    except ValueError as exc:
        raise ContentTargetsError("WordPress content target timestamp must include a timezone") from exc
    seen: set[int] = set()
    output: dict[str, Any] = {}
    homepage_found = False
    for type_name, key in (("page", "pages"), ("post", "posts")):
        entries = raw[key]
        if not isinstance(entries, list) or len(entries) > POST_LIMITS[type_name]:
            raise ContentTargetsError("WordPress content target count is not bounded")
        if type(raw[key + "_truncated"]) is not bool:
            raise ContentTargetsError("WordPress content truncation contract changed")
        safe = []
        for item in entries:
            if not isinstance(item, dict) or set(item) != ENTRY_KEYS:
                raise ContentTargetsError("WordPress target leaked private data or omitted required fields")
            post_id = item["post_id"]
            if (type(post_id) is not int or post_id < 1 or post_id in seen
                    or item["post_type"] != type_name or item["status"] != "publish"):
                raise ContentTargetsError("WordPress target is private, duplicate or wrong type")
            seen.add(post_id)
            for field, cap in (("title", 160), ("slug", 200), ("modified_gmt", 32)):
                value = item[field]
                if (not isinstance(value, str) or len(value) > cap
                        or any(ord(char) < 32 for char in value)):
                    raise ContentTargetsError("WordPress target metadata is malformed")
            url = https_url(item["permalink"])
            p = urlsplit(url)
            if (p.scheme, p.netloc) != (base.scheme, base.netloc) or not p.path.startswith(base.path):
                raise ContentTargetsError("WordPress content target URL escaped its bound site")
            parent_id = item["parent_id"]
            if type(parent_id) is not int or parent_id < 0:
                raise ContentTargetsError("WordPress content parent identity is invalid")
            is_home = item["homepage"]
            if type(is_home) is not bool or is_home != (type_name == "page" and post_id == homepage):
                raise ContentTargetsError("WordPress homepage marker conflicts with actual target")
            homepage_found = homepage_found or is_home
            safe.append({
                "post_id": post_id,
                "post_type": type_name,
                "status": "publish",
                "title": item["title"],
                "slug": item["slug"],
                "permalink": url,
                "parent_id": parent_id,
                "homepage": is_home,
                "modified_gmt": item["modified_gmt"],
            })
        total = totals["published_" + type_name + "_total"]
        if total is not None:
            if total < len(safe):
                raise ContentTargetsError("Published total is smaller than returned public targets")
            if (total > len(safe)) != raw[key + "_truncated"]:
                raise ContentTargetsError("WordPress published total disagrees with bounded inventory truncation")
        output[key] = safe
        output[key + "_truncated"] = raw[key + "_truncated"]
    return {
        "source": SOURCE,
        "scope": "site.read",
        "site_url": base_url,
        "observed_at": stamp,
        "homepage_post_id": homepage,
        "homepage_in_inventory": homepage_found,
        "page_count_in_inventory": len(output["pages"]),
        "post_count_in_inventory": len(output["posts"]),
        "published_page_total": totals["published_page_total"],
        "published_post_total": totals["published_post_total"],
        "inventory_is_sample": output["pages_truncated"] or output["posts_truncated"],
        "published_totals_source": "wordpress-native-count" if has_totals else "unavailable-legacy-plugin",
        "pages_truncated": output["pages_truncated"],
        "posts_truncated": output["posts_truncated"],
        "pages": output["pages"],
        "posts": output["posts"],
        "private_content_included": False,
        "permission_note": "Published metadata only. No drafts, bodies, excerpts or private post meta. Any later change needs a separate governed plan and explicit user confirmation.",
    }


PAGE_KEYS = {
    "connection_id", "source", "observed_at", "site_url", "homepage_post_id",
    "scope", "published_only", "post_type", "page", "page_size",
    "published_total", "total_pages", "has_more", "items",
    "private_values_included",
}


def summarize_content_page(raw: Any) -> dict[str, Any]:
    """Project exactly one site.read public batch, never an unrestricted result."""
    if not isinstance(raw, dict) or set(raw) != PAGE_KEYS:
        raise ContentTargetsError("Paginated WordPress content returned unexpected keys")
    kind = raw["post_type"]
    if kind not in ("post", "page") or type(kind) is not str:
        raise ContentTargetsError("Unsupported WordPress paginated content type")
    page_size = POST_LIMITS[kind]
    total = raw["published_total"]
    number = raw["page"]
    pages = raw["total_pages"]
    has_more = raw["has_more"]
    if (type(total) is not int or total < 0 or total > 10_000_000
            or type(number) is not int or not 1 <= number <= 10_000
            or type(pages) is not int or pages != max(1, (total + page_size - 1) // page_size)
            or number > pages or raw["page_size"] != page_size
            or type(raw["page_size"]) is not int
            or type(has_more) is not bool or has_more != (number < pages)
            or not isinstance(raw["items"], list)):
        raise ContentTargetsError("Published content pagination contradicts catalog totals")
    expected = min(page_size, max(0, total - (number - 1) * page_size))
    if len(raw["items"]) != expected:
        raise ContentTargetsError("Published WordPress content page is missing or overflowing records")
    if raw["source"] != "wordpress-native-published-content-page":
        raise ContentTargetsError("Unsupported paginated WordPress source")
    # Reuse the strict per-entry, URL, status, identity and timestamp validation
    # without weakening the existing site.content_targets projection.
    synthetic = {
        "connection_id": raw["connection_id"],
        "source": SOURCE,
        "observed_at": raw["observed_at"],
        "site_url": raw["site_url"],
        "homepage_post_id": raw["homepage_post_id"],
        "scope": raw["scope"],
        "published_only": raw["published_only"],
        "page_limit": 80,
        "post_limit": 40,
        "pages": raw["items"] if kind == "page" else [],
        "posts": raw["items"] if kind == "post" else [],
        "pages_truncated": kind == "page" and total > len(raw["items"]),
        "posts_truncated": kind == "post" and total > len(raw["items"]),
        "published_page_total": total if kind == "page" else 0,
        "published_post_total": total if kind == "post" else 0,
        "private_values_included": raw["private_values_included"],
    }
    safe = summarize_content_targets(synthetic)
    return {
        "source": raw["source"],
        "scope": "site.read",
        "site_url": safe["site_url"],
        "observed_at": safe["observed_at"],
        "post_type": kind,
        "page": number,
        "page_size": page_size,
        "published_total": total,
        "total_pages": pages,
        "has_more": has_more,
        "items": safe["pages"] if kind == "page" else safe["posts"],
        "private_content_included": False,
    }
