#!/usr/bin/env python3
"""Read-only operator overview from the already-authoritative Site Manager site_read.

This module does NOT query WordPress, decide whether a write scope is granted,
select providers, or authorize mutations. It summarizes a single live read
into a small, sanitizable operational contract for ChatGPT.
"""
from __future__ import annotations

import re
from typing import Any
from urllib.parse import urlsplit, urlunsplit

_CAPABILITY_ACTIONS = (
    ("content.update", "content.write", "existing WordPress post/page ID"),
    ("navigation.update", "navigation.write", "existing provider-owned navigation item/post ID"),
    ("seo.update", "seo.write", "existing WordPress post/page ID"),
)
_SCOPE_PATTERN = re.compile(r"^[a-z][a-z0-9._-]{1,63}$")
_FIELD_PATTERN = re.compile(r"^[a-z][a-z0-9_]{0,63}$")


class OverviewError(ValueError):
    """A live Site Manager read is missing required trustworthy evidence."""


def _record(value: Any) -> dict[str, Any]:
    return value if isinstance(value, dict) else {}


def _small_string(value: Any, limit: int = 128) -> str | None:
    if not isinstance(value, str) or not value or len(value) > limit:
        return None
    if any(ord(char) < 32 for char in value):
        return None
    return value


def _safe_https_home(value: Any) -> str | None:
    if not isinstance(value, str) or len(value) > 2048:
        return None
    try:
        parsed = urlsplit(value)
        if (parsed.scheme != "https" or not parsed.hostname or parsed.username
                or parsed.password or parsed.fragment or parsed.query
                or parsed.port is not None and not (1 <= parsed.port <= 65535)):
            return None
        return urlunsplit((parsed.scheme, parsed.netloc, parsed.path, "", ""))
    except ValueError:
        return None


def _nonnegative(value: Any) -> int | None:
    return value if type(value) is int and value >= 0 else None


def _safe_scopes(value: Any) -> list[str]:
    if not isinstance(value, list) or len(value) > 32:
        raise OverviewError("capability scope evidence is malformed")
    if any(not isinstance(scope, str) or not _SCOPE_PATTERN.fullmatch(scope) for scope in value):
        raise OverviewError("capability contains an invalid required scope")
    return sorted(set(value))


def _capability_state(entry: dict[str, Any]) -> str:
    if entry.get("governable") is True and entry.get("understood") is True and entry.get("detected") is True:
        return "governable"
    if entry.get("understood") is True and entry.get("detected") is True:
        return "understood"
    if entry.get("detected") is True:
        return "detected"
    return "unavailable"


def summarize_site_overview(data: Any) -> dict[str, Any]:
    """Return bounded, read-only WordPress-derived facts, never inferred authorization."""
    if not isinstance(data, dict):
        raise OverviewError("site.read must return a structured response")
    connection = _small_string(data.get("connection_id"), 128)
    discovery = _record(data.get("discovery"))
    graph = _record(data.get("capabilities"))
    platform = _record(discovery.get("platform"))
    inventory = _record(discovery.get("inventory"))
    entries = graph.get("capabilities")
    fingerprint = _small_string(discovery.get("fingerprint"), 128)
    generated_at = _small_string(discovery.get("generated_at"), 80)
    platform_name = _small_string(platform.get("platform"), 64)
    if (not connection or not fingerprint or not generated_at or not platform_name
            or not isinstance(discovery.get("inventory"), dict)
            or not isinstance(entries, list) or not entries or len(entries) > 128):
        raise OverviewError("site.read lacks live connection, fingerprint, platform or capability evidence")

    capabilities: list[dict[str, Any]] = []
    by_id: dict[str, dict[str, Any]] = {}
    for value in entries:
        if not isinstance(value, dict):
            raise OverviewError("Site Manager capability entry is malformed")
        cap_id = _small_string(value.get("id"), 96)
        if not cap_id or not _SCOPE_PATTERN.fullmatch(cap_id) or cap_id in by_id:
            raise OverviewError("Site Manager capabilities must have distinct bounded IDs")
        blockers = value.get("blockers", [])
        if not isinstance(blockers, list) or len(blockers) > 100:
            raise OverviewError("capability blocker evidence is malformed")
        scopes = _safe_scopes(value.get("required_scopes", []))
        state = _capability_state(value)
        provider = _small_string(value.get("provider"), 128)
        cap = {
            "id": cap_id,
            "state": state,
            "provider": provider,
            "required_scopes": scopes,
            "blocker_count": len(blockers),
            "scope_grant_verified": False,
        }
        capabilities.append(cap)
        by_id[cap_id] = cap
    capabilities.sort(key=lambda item: item["id"])

    seo = _record(inventory.get("seo_authority"))
    seo_owner = _record(seo.get("authority"))
    writable_inputs = seo.get("writable_inputs")
    writable_fields = (
        sorted(name for name in writable_inputs
               if isinstance(name, str) and _FIELD_PATTERN.fullmatch(name))
        if isinstance(writable_inputs, dict) and len(writable_inputs) <= 100 else []
    )
    seo_resolved = seo.get("authority_resolved") is True and seo.get("conflict") is not True
    navigation = _record(inventory.get("navigation"))
    providers = navigation.get("providers")
    provider_summary: list[dict[str, Any]] = []
    if isinstance(providers, list) and len(providers) <= 64:
        for provider in providers:
            if not isinstance(provider, dict):
                continue
            provider_id = _small_string(provider.get("id"), 96)
            if provider_id:
                provider_summary.append({
                    "id": provider_id,
                    "detected": provider.get("detected") is True,
                    "actionable_by_provider": provider.get("actionable") is True,
                    "target_count": _nonnegative(provider.get("target_count")),
                })
    provider_summary.sort(key=lambda item: item["id"])

    workflow = []
    for action, capability_id, target in _CAPABILITY_ACTIONS:
        cap = by_id.get(capability_id)
        workflow.append({
            "action": action,
            "capability_id": capability_id,
            "capability_governable": cap is not None and cap["state"] == "governable",
            "scope_grant_verified": False,
            "requires_target": target,
            "planning_mutates_wordpress": False,
            "apply_requires_separate_user_confirmation": True,
            "rollback_requires_separate_user_confirmation": True,
        })

    urls = _record(inventory.get("urls"))
    theme = _record(inventory.get("theme"))
    plugins = _record(inventory.get("plugins"))
    plugin_entry = _record(_record(plugins.get("items")).get("site-manager/site-manager.php"))
    indexability = _record(inventory.get("indexability"))
    search_visibility = _small_string(indexability.get("search_visibility"), 32)
    return {
        "source": "live-site-read",
        "connection_id": connection,
        "site_url": _safe_https_home(urls.get("site")),
        "generated_at": generated_at,
        "discovery_fingerprint": fingerprint,
        "platform": {
            "name": platform_name,
            "version": _small_string(platform.get("version"), 48),
            "locale": _small_string(_record(platform.get("metadata")).get("locale"), 32),
        },
        "wordpress": {
            "site_manager_version": _small_string(plugin_entry.get("version"), 48),
            "theme": _small_string(theme.get("name"), 128),
            "theme_stylesheet": _small_string(theme.get("stylesheet"), 128),
        } if platform_name == "wordpress" else None,
        "search_visibility": search_visibility,
        "seo": {
            "provider": _small_string(seo_owner.get("id"), 96) if seo_resolved else None,
            "provider_resolved": seo_resolved,
            "provider_conflict": seo.get("conflict") is True,
            "writable_semantic_fields": writable_fields if seo_resolved else [],
        },
        "navigation_providers": provider_summary,
        "capabilities": capabilities,
        "governed_actions": workflow,
        "warnings": [
            *(['wordpress_discourages_search_engine_indexing'] if search_visibility == "discourage" else []),
            *(['seo_provider_ownership_unresolved'] if seo and not seo_resolved else []),
        ],
        "authorization_note": (
            "Capability governable is not a grant of permission. site.read does not "
            "establish write-scope authorization; plan/apply recheck WordPress scopes "
            "and the bound administrator. No mutation has been performed."
        ),
    }
