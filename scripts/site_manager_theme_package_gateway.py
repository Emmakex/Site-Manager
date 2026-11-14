#!/usr/bin/env python3
"""Trusted environment-only Theme release policy and in-memory MCP plan transport.

User commands contain only immutable release IDs, desired active Theme slug
and SHA-256. Repositories, workflows, credentials and MCP transport are
selected by the operator's protected Site Manager Gateway environment.
"""
from __future__ import annotations

import json
from pathlib import Path
import re
from typing import Any, Mapping

from site_manager_theme_large_mcp import ThemePackageMcpPlanClient
from site_manager_theme_release_download import prepare_official_theme_plan
from site_manager_theme_release_provenance import ThemeReleasePolicy

POLICY_PATH = Path(__file__).resolve().parents[1] / "config/site-manager-theme-release-policies.json"


class ThemePackageGatewayError(ValueError):
    pass


def trusted_release_policy(theme_slug: str, policy_path: Path = POLICY_PATH) -> ThemeReleasePolicy:
    if not isinstance(theme_slug, str) or re.fullmatch(r"[a-z0-9][a-z0-9-]{1,79}", theme_slug) is None:
        raise ThemePackageGatewayError("invalid_governed_theme_slug")
    try:
        source = json.loads(policy_path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise ThemePackageGatewayError("theme_release_policy_unavailable") from exc
    if not isinstance(source, dict) or set(source) != {"version","themes"} or source["version"] != 1:
        raise ThemePackageGatewayError("theme_release_policy_invalid")
    themes = source["themes"]
    if not isinstance(themes, dict) or theme_slug not in themes:
        raise ThemePackageGatewayError("theme_release_not_authorized")
    spec = themes[theme_slug]
    if not isinstance(spec, dict) or set(spec) != {"repository","workflow_path","branch","artifact_name","access"}:
        raise ThemePackageGatewayError("theme_release_policy_invalid")
    if not all(isinstance(v,str) and v for v in spec.values()):
        raise ThemePackageGatewayError("theme_release_policy_invalid")
    if re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", spec["repository"]) is None:
        raise ThemePackageGatewayError("theme_release_policy_invalid")
    if re.fullmatch(r"\.github/workflows/[A-Za-z0-9_.-]+\.ya?ml", spec["workflow_path"]) is None:
        raise ThemePackageGatewayError("theme_release_policy_invalid")
    if (spec["branch"] != "main" or spec["access"] not in ("public", "private")
            or not re.fullmatch(r"[a-z0-9][a-z0-9_.-]+", spec["artifact_name"])):
        raise ThemePackageGatewayError("theme_release_policy_invalid")
    return ThemeReleasePolicy(
        repository=spec["repository"],
        workflow_path=spec["workflow_path"],
        branch=spec["branch"],
        artifact_name=spec["artifact_name"],
        public_repository=spec["access"] == "public",
    )


def prepare_theme_package_plan(payload: Mapping[str, Any], *, mcp_url: str,
                               site_token: str, github_token: str) -> dict[str, Any]:
    policy = trusted_release_policy(payload.get("theme_slug", ""))
    if not github_token and not policy.public_repository:
        raise ThemePackageGatewayError("theme_release_secret_unconfigured")
    official = prepare_official_theme_plan(
        policy,
        theme_slug=payload["theme_slug"],
        source_commit_sha=payload["source_commit_sha"],
        source_run_id=payload["source_run_id"],
        source_artifact_id=payload["source_artifact_id"],
        package_sha256=payload["package_sha256"],
        github_token=github_token,
    )
    # Never log or preserve the in-memory ZIP Base64. The transport accepts
    # exactly the fixed tool name theme_package_plan and fixed site MCP URL.
    response = ThemePackageMcpPlanClient(mcp_url, site_token).stage_official_release(official)
    return response
