#!/usr/bin/env python3
"""Fail-closed, public-structure-only Site Manager view of SEO/GEO Theme.

The Theme owns rendering and the semantic contract; this projection never
copies the WordPress post body or semantic slot values into GitHub.
"""
from __future__ import annotations

from datetime import datetime
import re
from typing import Any
from urllib.parse import urlsplit


class ThemeModelError(ValueError):
    """The connected WordPress Theme model is incomplete or ambiguous."""


EXPECTED_KEYS = {
    "source", "site_url", "scope", "observed_at", "post_id",
    "post_type", "post_status", "homepage", "model_id",
    "model_source", "slot_count", "slots", "public_values_included",
    "write_scope_verified", "editing_ready", "note", "connection_id",
}
SLOT_KEYS = {"id", "type", "required", "present", "writable_via_site_manager"}
MODEL_ID = "corporate-home-v1"
REQUIRED_SOURCE = "seo-geo-theme-corporate-model"
SLOT_ID = re.compile(r"^[a-z0-9][a-z0-9-]{0,79}$")
CONNECTION = re.compile(r"^smc_[a-zA-Z0-9_-]{12,124}$")


def summary(raw: Any) -> dict[str, Any]:
    if not isinstance(raw, dict) or set(raw) != EXPECTED_KEYS:
        raise ThemeModelError("Theme model response has unknown or missing data")
    connection = raw["connection_id"]
    if not isinstance(connection, str) or not CONNECTION.fullmatch(connection):
        raise ThemeModelError("Theme model is missing a bound Site Manager connection")
    url = raw["site_url"]
    if not isinstance(url, str) or len(url) > 2048:
        raise ThemeModelError("Theme model site URL missing")
    try:
        parsed = urlsplit(url)
        if (parsed.scheme != "https" or not parsed.hostname or parsed.username
                or parsed.password or parsed.query or parsed.fragment
                or any(ord(char) < 32 for char in url)):
            raise ThemeModelError("Theme model requires safe HTTPS site origin")
    except ValueError as exc:
        raise ThemeModelError("Theme model URL is malformed") from exc

    if (raw["source"] != REQUIRED_SOURCE or raw["scope"] != "site.read"
            or raw["model_id"] != MODEL_ID or raw["post_type"] != "page"
            or raw["post_status"] != "publish"
            or raw["homepage"] is not True
            or raw["public_values_included"] is not False
            or raw["write_scope_verified"] is not False
            or raw["editing_ready"] is not False
            or raw["model_source"] not in ("hydrated-semantic-blocks", "site-manager-native-override", "external", "unknown")):
        raise ThemeModelError("Theme model source/permission contract changed")

    if type(raw["post_id"]) is not int or raw["post_id"] < 1:
        raise ThemeModelError("WordPress homepage ID is invalid")
    stamp = raw["observed_at"]
    if not isinstance(stamp, str) or len(stamp) > 80:
        raise ThemeModelError("Missing Theme model timestamp")
    try:
        if datetime.fromisoformat(stamp.replace("Z", "+00:00")).utcoffset() is None:
            raise ValueError("timezone missing")
    except ValueError as exc:
        raise ThemeModelError("Theme model timestamp needs an offset") from exc

    slots = raw["slots"]
    if (not isinstance(slots, list) or not 1 <= len(slots) <= 100
            or type(raw["slot_count"]) is not int or len(slots) != raw["slot_count"]):
        raise ThemeModelError("Theme slot count exceeds accepted bounds")
    seen: set[str] = set()
    clean = []
    for item in slots:
        if not isinstance(item, dict) or set(item) != SLOT_KEYS:
            raise ThemeModelError("Theme slot contains raw values or missing attributes")
        slot_id = item["id"]
        if not isinstance(slot_id, str) or not SLOT_ID.fullmatch(slot_id) or slot_id in seen:
            raise ThemeModelError("Theme slots have ambiguous identities")
        seen.add(slot_id)
        if item["type"] not in ("text", "link", "list"):
            raise ThemeModelError("Unrecognized Theme semantic slot type")
        if (type(item["required"]) is not bool or type(item["present"]) is not bool
                or item["writable_via_site_manager"] is not False):
            raise ThemeModelError("Theme slot state or write-grant evidence is invalid")
        if item["required"] and not item["present"]:
            raise ThemeModelError("Theme required slot is not resolved")
        clean.append({key:item[key] for key in ("id","type","required","present","writable_via_site_manager")})

    # Caller-supplied notes are not echoed back: no free-form WordPress strings.
    return {
        "source": REQUIRED_SOURCE,
        "scope": "site.read",
        "site_url": url,
        "post_id": raw["post_id"],
        "post_type": "page",
        "homepage": True,
        "model_id": MODEL_ID,
        "model_source": raw["model_source"],
        "observed_at": stamp,
        "slot_count": len(clean),
        "slots": clean,
        "editing_ready": False,
        "write_scope_verified": False,
        "contains_content_values": False,
        "notice": "Theme owns rendering. This is structural inspection only. Editing needs a separately verified semantic plan/apply/verify/rollback contract.",
    }
