#!/usr/bin/env python3
"""Verify approved image source and receipt; never return raw image bytes to Git."""
from __future__ import annotations

import base64
import re
from typing import Any

from site_manager_image_artifact import fetch_image,validate_manifest,validate_source,ImageArtifactError
from site_manager_image_artifact_drive import fetch_configured_drive_image
from site_manager_theme_large_mcp import ThemePackageMcpPlanClient
from site_manager_image_artifact_optimize import optimize_image, PROFILES

def optimized_source(source):
    return isinstance(source,dict) and set(source)=={"provider","optimization"} and source.get("provider")=="google_drive" and isinstance(source.get("optimization"),str) and source["optimization"] in PROFILES

def validate_payload(payload: Any) -> None:
    if not isinstance(payload,dict):
        raise ImageArtifactError("invalid_image_upload_payload")
    stage=payload.get("stage")
    if stage=="plan":
        if set(payload)!={"stage","parent_id","manifest","source"} or type(payload["parent_id"]) is not int or payload["parent_id"]<1:
            raise ImageArtifactError("invalid_image_upload_plan")
    elif stage=="apply":
        if (set(payload)!={"stage","plan_id","confirmed","manifest","source","parent_id","plan_command_id"}
                or not isinstance(payload["plan_command_id"],str) or re.fullmatch(r"cmd-[a-z0-9][a-z0-9-]{7,63}",payload["plan_command_id"]) is None
                or payload["confirmed"] is not True or not isinstance(payload["plan_id"],str)
                or re.fullmatch(r"sm-plan-[a-f0-9]{24}",payload["plan_id"]) is None
                or type(payload["parent_id"]) is not int or payload["parent_id"]<1):
            raise ImageArtifactError("invalid_image_upload_apply")
    else:
        raise ImageArtifactError("invalid_image_upload_stage")
    validate_manifest(payload["manifest"])
    if payload["source"] != {"provider":"google_drive"} and not optimized_source(payload["source"]):
        validate_source(payload["source"])

def fetch_approved_image(source: dict[str,Any],manifest: dict[str,Any],github_token: str) -> bytes:
    if source == {"provider":"google_drive"} or optimized_source(source):
        return fetch_configured_drive_image(manifest)
    return fetch_image(source,manifest,github_token)

def prepare_image(payload, github_token):
    validate_payload(payload)
    raw = fetch_approved_image(payload["source"],payload["manifest"],github_token)
    if optimized_source(payload["source"]):
        return optimize_image(raw,payload["manifest"],payload["source"]["optimization"])
    return raw,payload["manifest"]

def verify_plan(data: Any,payload: dict[str,Any], optimized_manifest=None) -> dict[str,Any]:
    validate_payload(payload)
    if not isinstance(data,dict) or data.get("stage")!="plan" or data.get("status")!="planned":
        raise ImageArtifactError("image_upload_plan_missing")
    expected = validate_manifest(optimized_manifest) if optimized_source(payload["source"]) else payload["manifest"]
    plan=data.get("plan")
    preview=data.get("preview")
    if (not isinstance(plan,dict) or not isinstance(preview,dict)
            or not isinstance(plan.get("plan_id"),str) or not re.fullmatch(r"sm-plan-[a-f0-9]{24}",plan["plan_id"])
            or plan.get("status")!="planned" or plan.get("operation")!="content.image_upload"
            or plan.get("capability_id")!="content.write" or plan.get("reversible") is not False
            or plan.get("confirmation_required") is not True or plan.get("fields")!=["manifest"]
            or not isinstance(plan.get("target"),dict) or plan["target"].get("parent_id")!=payload["parent_id"]
            or plan["target"].get("post_type") not in ("post","page")
            or preview.get("parent_id")!=payload["parent_id"] or preview.get("manifest")!=expected
            or preview.get("byte_validation_pending") is not True or preview.get("public_url_possible") is not True
            or preview.get("rollback_available") is not False or preview.get("confirmation_required") is not True):
        raise ImageArtifactError("image_upload_plan_mismatch")
    return {"stage":"plan","status":"planned","plan_id":plan["plan_id"],"parent_id":payload["parent_id"],
            "manifest":payload["manifest"],"source":payload["source"],"expires_at":plan.get("expires_at"),
            "byte_validation_pending":True,"public_url_possible":True,"rollback_available":False,**({"optimized_manifest":expected} if optimized_source(payload["source"]) else {})}

def verify_apply(data: Any,payload: dict[str,Any], optimized_manifest=None) -> dict[str,Any]:
    validate_payload(payload)
    if (not isinstance(data,dict) or data.get("stage")!="apply" or data.get("status")!="verified"
            or data.get("verified") is not True or data.get("rollback_available") is not False
            or data.get("parent_id")!=payload["parent_id"]):
        raise ImageArtifactError("image_upload_receipt_missing")
    expected = validate_manifest(optimized_manifest) if optimized_source(payload["source"]) else payload["manifest"]
    plan=data.get("plan")
    result=data.get("result")
    if (not isinstance(plan,dict) or not isinstance(result,dict)
            or plan.get("plan_id")!=payload["plan_id"] or plan.get("status")!="applied"
            or plan.get("operation")!="content.image_upload" or plan.get("capability_id")!="content.write"
            or plan.get("reversible") is not False or not isinstance(plan.get("target"),dict)
            or plan["target"].get("parent_id")!=payload["parent_id"]
            or result.get("verified") is not True or result.get("status")!="verified"
            or type(result.get("attachment_id")) is not int or result["attachment_id"]<1
            or not isinstance(result.get("stored_sha256"),str) or not re.fullmatch(r"[a-f0-9]{64}",result["stored_sha256"])
            or any(result.get(k)!=expected[k] for k in ("width","height","mime"))):
        raise ImageArtifactError("image_upload_receipt_mismatch")
    stored_bytes = result.get("stored_bytes")
    if "stored_bytes" in result and (type(stored_bytes) is not int or not 1 <= stored_bytes <= 2000000):
        raise ImageArtifactError("image_upload_stored_size_invalid")
    return {"stage":"apply","status":"verified","plan_id":payload["plan_id"],"parent_id":payload["parent_id"],
            "attachment_id":result["attachment_id"],"source_sha256":payload["manifest"]["sha256"],
            "stored_sha256":result["stored_sha256"],"width":result["width"],"height":result["height"],
            "mime":result["mime"],"verified":True,"rollback_available":False,**({"stored_bytes":stored_bytes} if stored_bytes is not None else {}),**({"optimized_manifest":expected} if optimized_source(payload["source"]) else {})}

def apply_image(payload: dict[str,Any],approved: dict[str,Any],*,github_token: str,mcp_url: str,site_token: str) -> dict[str,Any]:
    validate_payload(payload)
    if payload["stage"]!="apply" or not isinstance(approved,dict) or approved.get("stage")!="plan":
        raise ImageArtifactError("image_upload_prior_plan_missing")
    if any(approved.get(k)!=payload[k] for k in ("plan_id","parent_id","manifest","source")):
        raise ImageArtifactError("image_upload_prior_plan_mismatch")
    image, derived = prepare_image(payload,github_token)
    if optimized_source(payload["source"]) and approved.get("optimized_manifest") != derived:
        raise ImageArtifactError("image_optimization_reviewed_derivative_changed")
    # Bytes never enter argv, the result, or the Git repository.
    result=ThemePackageMcpPlanClient(mcp_url,site_token).apply_reviewed_image({
        "plan_id":payload["plan_id"],"confirmed":True,"image_base64":base64.b64encode(image).decode("ascii")})
    return verify_apply(result,payload,derived)
