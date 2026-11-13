#!/usr/bin/env python3
"""Closed response verification for WordPress-owned Theme package lifecycle.

GitHub stores only whitelisted evidence. A 200 status, repository merge,
CI success or opaque MCP content is not proof of WordPress apply/rollback.
"""
from __future__ import annotations

import re
from typing import Any, Mapping


class ThemePackageEvidenceError(ValueError):
    pass


def _sha(value: Any) -> bool:
    return isinstance(value, str) and re.fullmatch(r"[a-f0-9]{64}", value) is not None


def _plan_id(value: Any) -> bool:
    return isinstance(value, str) and re.fullmatch(r"sm-plan-[a-f0-9]{24}", value) is not None


def _safe_plan(value: Any, stage: str, wanted_plan_id: str | None = None) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ThemePackageEvidenceError("theme_package_plan_evidence_missing")
    plan_id = value.get("plan_id")
    if (not _plan_id(plan_id)
            or (wanted_plan_id is not None and wanted_plan_id != plan_id)
            or value.get("capability_id") != "theme.package.write"
            or value.get("operation") != "theme.package.deploy"
            or not isinstance(value.get("target"), dict)
            or value["target"].get("theme_slug") in (None, "")
            or value.get("reversible") is not True):
        raise ThemePackageEvidenceError("theme_package_plan_ownership_mismatch")
    state = {"plan":"planned", "apply":"applied", "rollback":"rolled-back"}[stage]
    if value.get("status") != state:
        raise ThemePackageEvidenceError("theme_package_plan_state_mismatch")
    return {
        "plan_id": plan_id,
        "status": state,
        "capability_id": "theme.package.write",
        "operation": "theme.package.deploy",
        "target": {"theme_slug":value["target"]["theme_slug"]},
        "reversible": True,
        "confirmation_required": value.get("confirmation_required") is True,
        "expires_at": value.get("expires_at") if isinstance(value.get("expires_at"), str) else None,
    }


def verify_theme_package_evidence(stage: str, data: Any,
                                  command_payload: Mapping[str, Any]) -> tuple[bool, dict[str, Any]]:
    if stage not in {"plan", "apply", "rollback"} or not isinstance(data, dict):
        raise ThemePackageEvidenceError("theme_package_lifecycle_evidence_invalid")
    allowed = {
        "plan":{"stage","status","plan","preview"},
        "apply":{"stage","status","verified","plan","verification","rollback_available","recovered"},
        "rollback":{"stage","status","verified","plan","restoration"},
    }[stage]
    if set(data) != allowed - ({"recovered"} if stage == "apply" and "recovered" not in data else set()):
        raise ThemePackageEvidenceError("theme_package_unexpected_evidence_fields")
    if data.get("stage") != stage:
        raise ThemePackageEvidenceError("theme_package_wrong_stage")
    plan = _safe_plan(data.get("plan"), stage,
                      command_payload.get("plan_id") if stage != "plan" else None)
    target_slug = plan["target"]["theme_slug"]
    if not isinstance(target_slug,str) or re.fullmatch(r"[a-z0-9][a-z0-9-]{1,79}",target_slug) is None:
        raise ThemePackageEvidenceError("theme_package_invalid_target_slug")

    if stage == "plan":
        preview = data.get("preview")
        if data.get("status") != "planned" or not isinstance(preview,dict):
            raise ThemePackageEvidenceError("theme_package_preview_missing")
        if set(preview) != {
            "theme_slug","before_tree_sha256","after_tree_sha256","package_sha256",
            "theme_version","file_count","source_commit_sha","source_run_id",
            "source_artifact_id","confirmation_required","values_or_paths_included"
        }:
            raise ThemePackageEvidenceError("theme_package_preview_unexpected_fields")
        if (preview.get("theme_slug") != target_slug
                or preview.get("theme_slug") != command_payload["theme_slug"]
                or any(preview.get(k) != command_payload[k] for k in (
                    "source_commit_sha","source_run_id","source_artifact_id","package_sha256"))
                or preview.get("confirmation_required") is not True
                or preview.get("values_or_paths_included") is not False
                or plan.get("confirmation_required") is not True
                or not _sha(preview.get("before_tree_sha256"))
                or not _sha(preview.get("after_tree_sha256"))
                or preview["before_tree_sha256"] == preview["after_tree_sha256"]
                or not isinstance(preview.get("theme_version"), str)
                or not (1 <= len(preview["theme_version"]) <= 80)
                or type(preview.get("file_count")) is not int
                or not 2 <= preview["file_count"] <= 2500):
            raise ThemePackageEvidenceError("theme_package_preview_mismatch")
        # Planning is never reported as an applied/verified site mutation.
        return False, {
            "stage":"plan", "plan":plan,
            "preview": {
                "theme_slug":target_slug,
                "before_tree_sha256":preview["before_tree_sha256"],
                "after_tree_sha256":preview["after_tree_sha256"],
                "package_sha256":preview["package_sha256"],
                "source_commit_sha":preview["source_commit_sha"],
                "source_run_id":preview["source_run_id"],
                "source_artifact_id":preview["source_artifact_id"],
                "theme_version":preview["theme_version"],
                "file_count":preview["file_count"],
                "confirmation_required":True,
            },
        }

    if stage == "apply":
        verification = data.get("verification")
        if (data.get("status") != "verified" or data.get("verified") is not True
                or data.get("rollback_available") is not True
                or not isinstance(verification,dict)
                or set(verification) != {"verified","status","installed_tree_sha256"}
                or verification.get("verified") is not True
                or verification.get("status") != "verified"
                or not _sha(verification.get("installed_tree_sha256"))):
            raise ThemePackageEvidenceError("theme_package_apply_not_verified")
        return True, {
            "stage":"apply", "status":"verified", "plan":plan,
            "verification": {
                "verified":True,
                "installed_tree_sha256":verification["installed_tree_sha256"],
            }, "rollback_available":True,
        }

    restoration = data.get("restoration")
    if (data.get("status") != "rolled_back" or data.get("verified") is not True
            or not isinstance(restoration,dict)
            or set(restoration) != {"verified","tree_sha256"}
            or restoration.get("verified") is not True
            or not _sha(restoration.get("tree_sha256"))):
        raise ThemePackageEvidenceError("theme_package_rollback_not_verified")
    return True, {
        "stage":"rollback", "status":"rolled_back", "plan":plan,
        "restoration":{"verified":True,"tree_sha256":restoration["tree_sha256"]},
    }
