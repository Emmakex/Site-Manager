#!/usr/bin/env python3
"""Execute one bounded Site Manager command through a configured production MCP.

The command envelope carries business intent only. It never carries an MCP tool
name, MCP URL, bearer token, WordPress REST route, PHP, SQL, filesystem path or
shell command. A trusted registry maps the small business-action surface to the
accepted Site Manager MCP tools.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parents[1]
REGISTRY_PATH = ROOT / "config" / "site-manager-command-registry.json"
SCHEMA_PATH = ROOT / "schemas" / "site-manager-command-v1.schema.json"
DEFAULT_RESULT_DIR = ROOT / "commands" / "site-manager" / "results"
COMMAND_ID_RE = re.compile(r"^cmd-[a-z0-9][a-z0-9-]{7,63}$")
SITE_ID_RE = re.compile(r"^[a-z0-9][a-z0-9-]{2,63}$")
SUPPORTED_ACTIONS = {"site.read", "site.diagnostics", "content.update", "content.acceptance", "navigation.update"}
NAVIGATION_TOOLS = {"plan": "navigation_update_plan", "apply": "navigation_update_apply", "rollback": "navigation_update_rollback"}
NAVIGATION_FIELDS = {
    "wordpress-classic-menu": {"title": 500, "url": 4096, "description": 5000, "attr_title": 500,
                               "target": 6, "classes": 2000, "xfn": 2000},
    "wordpress-block-navigation": {"title": 500, "content": 200_000, "status": 7},
}
ACCEPTANCE_TOOLS = {"start": "content_acceptance_start", "status": "content_acceptance_status"}
ACCEPTANCE_STEPS = ("precheck", "fixture_content_create", "content_plan", "content_apply_verify", "content_rollback", "cleanup")
ACCEPTANCE_PROFILE = "mcp-content-only"
ACCEPTANCE_TIMEOUT_SECONDS = 420
READ_ACTIONS = {"site.read", "site.diagnostics"}
CONTENT_STAGES = {"plan", "apply", "rollback"}
CONTENT_TOOLS = {
    "plan": "content_update_plan",
    "apply": "content_update_apply",
    "rollback": "content_update_rollback",
}
CONTENT_FIELD_LIMITS = {"title": 500, "slug": 200, "content": 200_000, "excerpt": 20_000, "status": 20}
CONTENT_STATUSES = {"draft", "publish", "private", "pending"}
EXPECTED_TOOLS = {
    "site.read": ("site_read", "site.read"),
    "site.diagnostics": ("site_diagnostics", "site.diagnostics"),
}
MAX_RESULT_BYTES = 512_000
SENSITIVE_KEY_PARTS = ("token", "secret", "password", "authorization", "cookie")


class CommandError(RuntimeError):
    """Fail-closed command validation or runtime configuration error."""


class TransportError(CommandError):
    """The configured MCP transport could not complete the request."""


class RefusalError(CommandError):
    """The MCP or Site Manager explicitly refused the bounded operation."""


class VerificationError(CommandError):
    """A write response lacks the required Site Manager lifecycle evidence."""


def load_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise CommandError(f"missing JSON file: {path}") from exc
    except json.JSONDecodeError as exc:
        raise CommandError(f"invalid JSON in {path.name}") from exc
    if not isinstance(value, dict):
        raise CommandError(f"{path.name} must contain a JSON object")
    return value


def validate_registry(registry: dict[str, Any]) -> None:
    if set(registry) != {"version", "actions"} or registry.get("version") != "1":
        raise CommandError("command registry must match the version-1 contract")
    actions = registry.get("actions")
    if not isinstance(actions, dict) or set(actions) != SUPPORTED_ACTIONS:
        raise CommandError("registry action set does not match the bounded Site Manager surface")

    for action_name, (tool, scope) in EXPECTED_TOOLS.items():
        action = actions.get(action_name)
        if not isinstance(action, dict):
            raise CommandError(f"invalid registry entry for {action_name}")
        if set(action) != {"tool", "required_scope", "read_only"}:
            raise CommandError(f"registry entry for {action_name} contains unsupported keys")
        if action.get("tool") != tool or action.get("required_scope") != scope:
            raise CommandError(f"registry mapping for {action_name} changed unexpectedly")
        if action.get("read_only") is not True:
            raise CommandError(f"{action_name} must remain read-only in SM-4F slice 1")

    content = actions.get("content.update")
    if not isinstance(content, dict) or set(content) != {"tools", "required_scope", "read_only"}:
        raise CommandError("content.update registry must be a closed staged business action")
    if content["tools"] != CONTENT_TOOLS or content["required_scope"] != "content.write":
        raise CommandError("content.update must route only to accepted Site Manager content MCP tools")
    if content["read_only"] is not False:
        raise CommandError("content.update cannot be marked read-only")

    acceptance = actions.get("content.acceptance")
    if not isinstance(acceptance, dict) or set(acceptance) != {"tools", "required_scope", "read_only"}:
        raise CommandError("content.acceptance must be a closed governed business action")
    if acceptance["tools"] != ACCEPTANCE_TOOLS or acceptance["required_scope"] != "content.write":
        raise CommandError("content.acceptance may only invoke the registered content-only acceptance tools")
    if acceptance["read_only"] is not False:
        raise CommandError("content.acceptance is not a read-only action")

    navigation = actions.get("navigation.update")
    if not isinstance(navigation, dict) or set(navigation) != {"tools", "required_scope", "read_only"}:
        raise CommandError("navigation.update must be a closed governed business action")
    if navigation["tools"] != NAVIGATION_TOOLS or navigation["required_scope"] != "navigation.write":
        raise CommandError("navigation.update must route only to accepted WordPress navigation MCP tools")
    if navigation["read_only"] is not False:
        raise CommandError("navigation.update cannot be marked read-only")


def validate_command(command: dict[str, Any], registry: dict[str, Any]) -> None:
    allowed = {"version", "command_id", "site", "action", "requested_by", "payload"}
    if set(command) != allowed:
        raise CommandError("command keys do not match command-v1 contract")
    if command.get("version") != "1":
        raise CommandError("unsupported command version")

    command_id = command.get("command_id")
    if not isinstance(command_id, str) or COMMAND_ID_RE.fullmatch(command_id) is None:
        raise CommandError("invalid command_id")

    site = command.get("site")
    if not isinstance(site, str) or SITE_ID_RE.fullmatch(site) is None:
        raise CommandError("invalid logical site id")

    action = command.get("action")
    actions = registry.get("actions")
    if not isinstance(action, str) or not isinstance(actions, dict) or action not in actions:
        raise CommandError("action is not registered")
    if action not in SUPPORTED_ACTIONS:
        raise CommandError("action is outside the bounded Site Manager surface")

    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")

    payload = command.get("payload")
    if not isinstance(payload, dict):
        raise CommandError("command payload must be an object")
    if action in READ_ACTIONS:
        if payload:
            raise CommandError("read-only Site Manager commands require an empty payload object")
    elif action == "content.update":
        validate_content_payload(payload)
    elif action == "content.acceptance":
        if set(payload) != {"confirmed"} or payload.get("confirmed") is not True:
            raise CommandError("content-only acceptance requires exact confirmed=true, with no caller-selected target")
    elif action == "navigation.update":
        validate_navigation_payload(payload)
    else:
        raise CommandError("unregistered business action")



def validate_content_payload(payload: dict[str, Any]) -> None:
    """Strict, stage-specific WordPress Core content intent; no arbitrary transport or meta."""
    stage = payload.get("stage")
    if stage == "plan":
        if not {"stage", "post_id", "fields"} <= set(payload):
            raise CommandError("content plan requires stage, post_id and fields")
        if set(payload) - {"stage", "post_id", "fields", "reason"}:
            raise CommandError("content plan contains unsupported fields")
        post_id = payload["post_id"]
        if type(post_id) is not int or post_id < 1:
            raise CommandError("content post_id must be a positive integer")
        fields = payload["fields"]
        if not isinstance(fields, dict) or not fields or set(fields) - CONTENT_FIELD_LIMITS.keys():
            raise CommandError("content plan fields are not within the WordPress Core allowlist")
        for key, value in fields.items():
            if not isinstance(value, str) or len(value) > CONTENT_FIELD_LIMITS[key]:
                raise CommandError(f"content field {key} has an invalid type or length")
            if key == "status" and value not in CONTENT_STATUSES:
                raise CommandError("unsupported publication status")
            if key in {"title", "slug"} and not value.strip():
                raise CommandError(f"content field {key} may not be empty")
        reason = payload.get("reason")
        if reason is not None and (not isinstance(reason, str) or len(reason) > 500):
            raise CommandError("content plan reason must be a short string")
    elif stage in {"apply", "rollback"}:
        if set(payload) != {"stage", "plan_id", "confirmed"}:
            raise CommandError("content apply/rollback requires only stage, plan_id and confirmed")
        plan_id = payload["plan_id"]
        if not isinstance(plan_id, str) or re.fullmatch(r"[A-Za-z0-9_-]{1,200}", plan_id) is None:
            raise CommandError("invalid Site Manager plan_id")
        if payload["confirmed"] is not True:
            raise CommandError("explicit confirmed=true is required for content mutations")
    else:
        raise CommandError("unregistered content.update stage")


def validate_navigation_payload(payload: dict[str, Any]) -> None:
    """Restrict navigation business intent to WordPress-discovered provider-owned fields."""
    stage = payload.get("stage")
    if stage == "plan":
        if not {"stage", "provider", "target_id", "fields"} <= set(payload):
            raise CommandError("navigation plan requires provider, target_id and fields")
        if set(payload) - {"stage", "provider", "target_id", "fields", "reason"}:
            raise CommandError("navigation plan contains unsupported fields")
        provider = payload["provider"]
        if not isinstance(provider, str) or provider not in NAVIGATION_FIELDS:
            raise CommandError("unsupported WordPress navigation provider")
        target_id = payload["target_id"]
        if type(target_id) is not int or target_id < 1:
            raise CommandError("navigation target_id must be a positive integer")
        fields = payload["fields"]
        if not isinstance(fields, dict) or not fields or set(fields) - NAVIGATION_FIELDS[provider].keys():
            raise CommandError("navigation provider fields violate the allowlist")
        for field, value in fields.items():
            if field == "classes":
                if isinstance(value, str):
                    if len(value) > 2000:
                        raise CommandError("navigation classes string exceeds size limit")
                elif isinstance(value, list):
                    if len(value) > 50 or any(not isinstance(item, str) or len(item) > 100 for item in value):
                        raise CommandError("navigation classes list is invalid")
                else:
                    raise CommandError("navigation classes must be a string or bounded array")
            elif not isinstance(value, str) or len(value) > NAVIGATION_FIELDS[provider][field]:
                raise CommandError(f"navigation field {field} has an invalid type or length")
            if field == "title" and not value.strip():
                raise CommandError("navigation title cannot be empty")
            if field == "target" and value not in {"", "_blank"}:
                raise CommandError("unsupported navigation link target")
            if field == "status" and value not in {"draft", "publish"}:
                raise CommandError("unsupported navigation publication state")
            if field == "url":
                parsed = urlparse(value)
                is_absolute = parsed.scheme in {"http", "https"} and bool(parsed.netloc) and not parsed.username and not parsed.password
                is_relative = value.startswith("/") and not value.startswith("//")
                if not value.strip() or not (is_absolute or is_relative) or any(ord(c) < 32 for c in value):
                    raise CommandError("navigation URL must be an HTTP(S) URL or a safe site-relative path")
        reason = payload.get("reason")
        if reason is not None and (not isinstance(reason, str) or len(reason) > 500):
            raise CommandError("navigation reason must be a short string")
    elif stage in {"apply", "rollback"}:
        if set(payload) != {"stage", "plan_id", "confirmed"}:
            raise CommandError("navigation apply/rollback requires only plan_id and confirmed")
        if not isinstance(payload["plan_id"], str) or re.fullmatch(r"[A-Za-z0-9_-]{1,200}", payload["plan_id"]) is None:
            raise CommandError("invalid WordPress navigation plan_id")
        if payload["confirmed"] is not True:
            raise CommandError("navigation mutations require explicit confirmed=true")
    else:
        raise CommandError("unsupported navigation.update stage")


def validate_mcp_url(value: str) -> str:
    url = value.strip()
    parsed = urlparse(url)
    if parsed.scheme != "https" or not parsed.netloc:
        raise CommandError("SITE_MANAGER_MCP_BASE_URL must be an absolute HTTPS URL")
    if parsed.username or parsed.password:
        raise CommandError("SITE_MANAGER_MCP_BASE_URL must not contain credentials")
    if parsed.query or parsed.fragment:
        raise CommandError("SITE_MANAGER_MCP_BASE_URL must not contain query or fragment data")
    return url.rstrip("/")


def sanitized_message(message: str, token: str = "") -> str:
    safe = message.replace(token, "[REDACTED]") if token else message
    safe = re.sub(r"Bearer\s+[^\s\"']+", "Bearer [REDACTED]", safe, flags=re.I)
    safe = re.sub(r"\bsmc_[A-Za-z0-9._-]{12,}\b", "[CONNECTION_ID]", safe)
    return safe[-800:]


def sanitize_value(value: Any, token: str = "") -> Any:
    if isinstance(value, dict):
        result: dict[str, Any] = {}
        for key, child in value.items():
            key_text = str(key)
            lowered = key_text.lower()
            if any(part in lowered for part in SENSITIVE_KEY_PARTS):
                result[key_text] = "[REDACTED]"
            else:
                result[key_text] = sanitize_value(child, token)
        return result
    if isinstance(value, list):
        return [sanitize_value(child, token) for child in value]
    if isinstance(value, str):
        return sanitized_message(value, token) if (token and token in value) or re.search(r"Bearer\s+", value, re.I) else value
    return value


def write_result(result_dir: Path, command_id: str, result: dict[str, Any]) -> Path:
    result_dir.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    if len(payload.encode("utf-8")) > MAX_RESULT_BYTES:
        raise CommandError("sanitized command result exceeds the bounded result size")
    path = result_dir / f"{command_id}.json"
    path.write_text(payload, encoding="utf-8")
    return path


class McpClient:
    """Small wrapper around the official MCP Inspector CLI used by the accepted gateway."""

    def __init__(self, server_url: str, token: str) -> None:
        self.token = token
        self.temp = tempfile.TemporaryDirectory(prefix="site-manager-command-")
        temp_path = Path(self.temp.name)
        config = temp_path / "mcp.json"
        config.write_text(
            json.dumps(
                {
                    "mcpServers": {
                        "site-manager": {
                            "type": "http",
                            "url": server_url,
                            "headers": {"Authorization": f"Bearer {token}"},
                            "connectionTimeout": 10000,
                            "requestTimeout": 60000,
                        }
                    }
                }
            ),
            encoding="utf-8",
        )
        config.chmod(0o600)
        self.storage = temp_path / "storage"
        self.oauth = temp_path / "oauth.json"
        self.base = [
            "npx",
            "--yes",
            "@modelcontextprotocol/inspector@2.5.0",
            "--cli",
            "--config",
            str(config),
            "--server",
            "site-manager",
            "--stored-auth-only",
            "--format",
            "json",
        ]

    def close(self) -> None:
        self.temp.cleanup()

    def run(self, extra: list[str]) -> dict[str, Any]:
        proc = subprocess.run(
            self.base + extra,
            text=True,
            capture_output=True,
            check=False,
            env={
                **os.environ,
                "MCP_STORAGE_DIR": str(self.storage),
                "MCP_INSPECTOR_OAUTH_STATE_PATH": str(self.oauth),
            },
        )
        if proc.returncode != 0:
            raise TransportError(
                f"MCP inspector failed ({proc.returncode}): {sanitized_message(proc.stderr, self.token)}"
            )
        try:
            payload = json.loads(proc.stdout)
        except json.JSONDecodeError as exc:
            raise TransportError("MCP inspector returned invalid JSON") from exc
        if not isinstance(payload, dict):
            raise TransportError("MCP inspector returned an invalid response object")
        return payload

    def initialize(self) -> None:
        self.run(["--method", "initialize"])

    def call(self, tool: str, arguments: dict[str, Any] | None = None) -> Any:
        payload = self.run(
            [
                "--method",
                "tools/call",
                "--tool-name",
                tool,
                "--tool-args-json",
                json.dumps(arguments or {}, ensure_ascii=False, separators=(",", ":")),
            ]
        )
        result = payload.get("result") or {}
        if not isinstance(result, dict):
            raise TransportError("MCP tool response is malformed")
        if result.get("isError"):
            raise RefusalError(f"Site Manager MCP refused the registered tool {tool}")

        structured = result.get("structuredContent")
        if isinstance(structured, dict) and "data" in structured:
            return structured["data"]

        content = result.get("content")
        if isinstance(content, list):
            for row in content:
                if isinstance(row, dict) and row.get("type") == "text":
                    text = row.get("text")
                    if isinstance(text, str):
                        try:
                            return json.loads(text)
                        except json.JSONDecodeError:
                            continue
        raise TransportError(f"MCP tool {tool} returned no structured result")


def verify_content_response(stage: str, data: Any, request: dict[str, Any]) -> bool:
    """Require evidence from Site Manager, not a 200 response or inspector success."""
    if not isinstance(data, dict) or not isinstance(data.get("plan"), dict):
        raise VerificationError("content tool did not return a governed Site Manager plan")
    plan = data["plan"]
    if plan.get("capability_id") != "content.write" or plan.get("operation") != "content.update":
        raise VerificationError("content plan capability/operation ownership mismatch")
    if not isinstance(plan.get("plan_id"), str) or not plan["plan_id"]:
        raise VerificationError("content plan_id missing from governed response")
    if stage == "plan":
        target = plan.get("target")
        if not isinstance(target, dict) or target.get("post_id") != request["post_id"]:
            raise VerificationError("content plan targets a different WordPress post")
        if plan.get("status") != "planned" or plan.get("confirmation_required") is not True:
            raise VerificationError("content plan lacks planned status or explicit confirmation requirement")
        if not isinstance(data.get("changes"), dict):
            raise VerificationError("content plan returned no preview")
        return False  # Planning did not change or verify WordPress content.
    if plan["plan_id"] != request["plan_id"]:
        raise VerificationError("confirmed content plan identity changed")
    if stage == "apply":
        execution = data.get("execution")
        verification = data.get("verification")
        if (data.get("status") != "verified" or plan.get("status") != "applied"
                or not isinstance(execution, dict) or execution.get("success") is not True
                or not isinstance(verification, dict) or verification.get("verified") is not True):
            raise VerificationError("content apply lacks completed WordPress verification")
        return True
    rollback = data.get("rollback")
    if (data.get("status") != "rolled-back" or plan.get("status") != "rolled-back"
            or not isinstance(rollback, dict) or rollback.get("success") is not True):
        raise VerificationError("content rollback lacks verified restoration")
    return True


def verify_navigation_response(stage: str, data: Any, request: dict[str, Any]) -> bool:
    """Verify exact WordPress-owned navigation provider, plan and mutation lifecycle."""
    if not isinstance(data, dict) or not isinstance(data.get("plan"), dict):
        raise VerificationError("navigation tool did not return a governed plan")
    plan = data["plan"]
    if plan.get("capability_id") != "navigation.write" or plan.get("operation") != "navigation.update":
        raise VerificationError("navigation plan capability/operation mismatch")
    plan_id = plan.get("plan_id")
    if not isinstance(plan_id, str) or not plan_id.startswith("sm-plan-"):
        raise VerificationError("WordPress navigation plan id is invalid")
    if plan.get("confirmation_required") is not True:
        raise VerificationError("WordPress navigation did not require explicit confirmation")
    if stage == "plan":
        target = plan.get("target")
        if (not isinstance(target, dict) or target.get("provider") != request["provider"]
                or target.get("target_id") != request["target_id"]):
            raise VerificationError("navigation plan targets a different menu/provider")
        if plan.get("status") != "planned" or plan.get("reversible") is not True:
            raise VerificationError("navigation plan is not safely planned/reversible")
        if not isinstance(plan.get("fields"), list) or set(plan["fields"]) != set(request["fields"]):
            raise VerificationError("navigation plan omitted or added changed fields")
        changes = data.get("changes")
        if (not isinstance(changes, dict) or not isinstance(changes.get("before"), dict)
                or not isinstance(changes.get("after"), dict)):
            raise VerificationError("navigation plan returned no bounded before/after preview")
        if set(changes["after"]) != set(request["fields"]):
            raise VerificationError("navigation plan preview diverges from requested fields")
        return False
    if plan_id != request["plan_id"]:
        raise VerificationError("navigation apply/rollback plan identity changed")
    if stage == "apply":
        execution = data.get("execution")
        verification = data.get("verification")
        if (data.get("status") != "verified" or plan.get("status") != "applied"
                or not isinstance(execution, dict) or execution.get("success") is not True
                or not isinstance(verification, dict) or verification.get("verified") is not True):
            raise VerificationError("navigation apply lacks verified WordPress evidence")
        return True
    if stage == "rollback":
        rollback = data.get("rollback")
        if (data.get("status") != "rolled-back" or plan.get("status") != "rolled-back"
                or not isinstance(rollback, dict) or rollback.get("success") is not True):
            raise VerificationError("navigation rollback lacks verified restoration evidence")
        return True
    raise VerificationError("unknown navigation verification stage")


def sanitize_write_evidence(stage: str, data: dict[str, Any], token: str) -> dict[str, Any]:
    """Keep plan identifiers/statuses, not full pre-change WordPress content in Git history."""
    plan = data["plan"]
    safe_keys = ("plan_id", "status", "capability_id", "operation", "target", "fields",
                 "reversible", "confirmation_required", "created_at", "expires_at",
                 "rollback_retained_until", "applied_at", "rolled_back_at")
    plan_summary = {k: plan[k] for k in safe_keys if k in plan}
    evidence: dict[str, Any] = {"stage": stage, "plan": plan_summary}
    if stage == "plan":
        before = data["changes"].get("before", {})
        after = data["changes"].get("after", {})
        evidence["preview"] = {
            "changed_fields": list(after) if isinstance(after, dict) else [],
            "before_present": list(before) if isinstance(before, dict) else [],
            "values_retained_in_github": False,
        }
    elif stage == "apply":
        evidence["status"] = data["status"]
        evidence["verification"] = {
            "verified": data["verification"]["verified"],
            "status": data["verification"].get("status"),
        }
        evidence["rollback_available"] = data.get("rollback_available") is True
    else:
        evidence["status"] = data["status"]
        evidence["restoration_verified"] = data["rollback"]["success"] is True
    return sanitize_value(evidence, token)


def summarize_content_acceptance(state: Any, run_id: str) -> dict[str, Any]:
    """Require completed WordPress-authoritative apply, rollback and fixture deletion."""
    if not isinstance(state, dict):
        raise VerificationError("acceptance status is not a structured WordPress response")
    if state.get("run_id") != run_id or state.get("profile") != ACCEPTANCE_PROFILE:
        raise VerificationError("content acceptance run/profile identity mismatch")
    if state.get("status") != "completed" or state.get("had_failure") is not False:
        raise VerificationError("content acceptance has not finished successfully")
    steps = state.get("steps")
    if not isinstance(steps, list) or steps != list(ACCEPTANCE_STEPS):
        raise VerificationError("content acceptance attempted an unapproved step sequence")
    results = state.get("results")
    if not isinstance(results, dict) or set(results) != set(ACCEPTANCE_STEPS):
        raise VerificationError("content acceptance is missing required step evidence")
    for step in ACCEPTANCE_STEPS:
        record = results.get(step)
        if not isinstance(record, dict) or record.get("status") != "passed":
            raise VerificationError("content acceptance step was not verified: " + step)
    fixture = results["fixture_content_create"].get("evidence")
    applied = results["content_apply_verify"].get("evidence")
    rollback = results["content_rollback"].get("evidence")
    cleanup = results["cleanup"].get("evidence")
    planned = results["content_plan"].get("evidence")
    if not all(isinstance(x, dict) for x in (fixture, applied, rollback, cleanup, planned)):
        raise VerificationError("content acceptance evidence is malformed")
    post_id = fixture.get("post_id")
    if type(post_id) is not int or post_id < 1 or fixture.get("status") != "draft":
        raise VerificationError("content acceptance did not start with a disposable draft")
    plan_id = planned.get("plan_id")
    if not isinstance(plan_id, str) or not plan_id.startswith("sm-plan-"):
        raise VerificationError("content acceptance lacks a governed WordPress plan")
    if (applied.get("plan_id") != plan_id or applied.get("verified") is not True
            or applied.get("status") != "verified"):
        raise VerificationError("content acceptance did not verify the applied title change")
    if (rollback.get("plan_id") != plan_id or rollback.get("rollback_success") is not True
            or rollback.get("status") != "rolled-back"):
        raise VerificationError("content acceptance did not verify the rollback")
    removed = cleanup.get("removed")
    if not isinstance(removed, dict) or removed.get("post") is not True:
        raise VerificationError("disposable content acceptance draft was not removed")
    after = state.get("fixture")
    if not isinstance(after, dict) or after.get("post_id") != 0:
        raise VerificationError("disposable content fixture remains present after cleanup")
    if after.get("menu_id") != 0 or after.get("menu_item_id") != 0:
        raise VerificationError("content-only acceptance unexpectedly created navigation")
    return {
        "profile": ACCEPTANCE_PROFILE,
        "run_id": run_id,
        "temporary_post_id": post_id,
        "post_status": "draft",
        "plan_id": plan_id,
        "planned": True,
        "apply_verified": True,
        "rollback_verified": True,
        "draft_deleted": True,
        "navigation_and_seo_untouched_by_test": True,
        "step_statuses": {step: "passed" for step in ACCEPTANCE_STEPS},
    }


def run_content_acceptance(client: McpClient) -> dict[str, Any]:
    """Kick off the existing WordPress-owned test and await its bounded result."""
    started = client.call(ACCEPTANCE_TOOLS["start"], {"confirmed": True})
    if not isinstance(started, dict) or started.get("profile") != ACCEPTANCE_PROFILE:
        raise VerificationError("WordPress refused the isolated acceptance profile")
    if started.get("status") not in {"queued", "running"}:
        raise VerificationError("content acceptance did not start from a permitted state")
    run_id = started.get("run_id")
    if not isinstance(run_id, str) or not re.fullmatch(r"sm-mcp-accept-[a-f0-9]{20}", run_id):
        raise VerificationError("WordPress returned an invalid acceptance run identity")
    deadline = time.monotonic() + ACCEPTANCE_TIMEOUT_SECONDS
    while time.monotonic() < deadline:
        state = client.call(ACCEPTANCE_TOOLS["status"])
        if not isinstance(state, dict) or state.get("run_id") != run_id:
            raise VerificationError("acceptance job was replaced or its identity changed")
        if state.get("profile") != ACCEPTANCE_PROFILE:
            raise VerificationError("an unapproved acceptance profile became active")
        status = state.get("status")
        if status == "completed":
            return summarize_content_acceptance(state, run_id)
        if status == "failed":
            raise VerificationError("content acceptance reported a failed step or cleanup")
        if status not in {"queued", "running", "cleaning"}:
            raise VerificationError("content acceptance entered an unsupported state")
        time.sleep(4)
    raise VerificationError("content acceptance timed out without verified cleanup; inspect WordPress job state")


def execute(command_path: Path, result_dir: Path) -> int:
    registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    command = load_json(command_path)
    validate_command(command, registry)

    command_id = str(command["command_id"])
    site = str(command["site"])
    action_name = str(command["action"])
    existing = result_dir / f"{command_id}.json"
    if existing.exists():
        raise CommandError("result already exists; replay guard must decide whether execution is allowed")

    token = ""
    client: McpClient | None = None
    try:
        expected_site = os.environ.get("SITE_MANAGER_SITE_ID", "").strip()
        if not expected_site:
            raise CommandError("SITE_MANAGER_SITE_ID is required")
        if expected_site != site:
            raise CommandError("command site does not match the configured production Site Manager connection")

        base_url = validate_mcp_url(os.environ.get("SITE_MANAGER_MCP_BASE_URL", ""))
        token = os.environ.get("SITE_MANAGER_MCP_TOKEN", "").strip()
        if not token:
            raise CommandError("SITE_MANAGER_MCP_TOKEN is required")

        generation = os.environ.get("SITE_MANAGER_GATEWAY_GENERATION", "sm-4f-v1").strip() or "sm-4f-v1"
        action = registry["actions"][action_name]
        client = McpClient(base_url, token)
        client.initialize()
        if action_name == "content.acceptance":
            stage = "content-only-roundtrip"
            sanitized = sanitize_value(run_content_acceptance(client), token)
            verified = True
        else:
            stage = "read" if action_name in READ_ACTIONS else command["payload"]["stage"]
            tool = str(action["tool"] if stage == "read" else action["tools"][stage])
            tool_args = {} if stage == "read" else {
                k: v for k, v in command["payload"].items() if k != "stage"
            }
            data = client.call(tool, tool_args)
            if stage == "read":
                verified = True
                sanitized = sanitize_value(data, token)
            elif action_name == "content.update":
                verified = verify_content_response(stage, data, command["payload"])
                sanitized = sanitize_write_evidence(stage, data, token)
            else:
                verified = verify_navigation_response(stage, data, command["payload"])
                sanitized = sanitize_write_evidence(stage, data, token)

        result = {
            "version": "1",
            "status": "success",
            "site": site,
            "action": action_name,
            "stage": stage,
            "command_id": command_id,
            "verified": verified,
            "required_scope": action["required_scope"],
            "gateway_generation": generation,
            "data": sanitized,
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except VerificationError as exc:
        error_code = "verification_failed"
        error_message = "Site Manager did not provide the required lifecycle verification evidence."
        detail = sanitized_message(str(exc), token)
    except RefusalError as exc:
        error_code = "mcp_refused"
        error_message = "Site Manager refused the bounded operation."
        detail = sanitized_message(str(exc), token)
    except TransportError as exc:
        error_code = "transport_failed"
        error_message = "The production MCP transport did not complete successfully."
        detail = sanitized_message(str(exc), token)
    except CommandError as exc:
        error_code = "configuration_failed"
        error_message = "The bounded Site Manager command could not be executed safely."
        detail = sanitized_message(str(exc), token)
    except Exception as exc:  # noqa: BLE001
        error_code = "execution_failed"
        error_message = "The bounded Site Manager command failed unexpectedly."
        detail = sanitized_message(str(exc), token)
    finally:
        if client is not None:
            client.close()

    result = {
        "version": "1",
        "status": "failed",
        "site": site,
        "action": action_name,
        "command_id": command_id,
        "verified": False,
        "error": {
            "code": error_code,
            "message": error_message,
            "detail": detail,
        },
    }
    path = write_result(result_dir, command_id, result)
    print(f"FAILED {path}: {detail}")
    return 1


def self_test() -> int:
    registry = load_json(REGISTRY_PATH)
    validate_registry(registry)
    schema = load_json(SCHEMA_PATH)

    properties = schema.get("properties")
    if not isinstance(properties, dict):
        raise CommandError("schema properties are missing")
    action_enum = properties.get("action", {}).get("enum") if isinstance(properties.get("action"), dict) else None
    if not isinstance(action_enum, list) or set(action_enum) != SUPPORTED_ACTIONS:
        raise CommandError("schema actions and registry actions are out of sync")
    if schema.get("additionalProperties") is not False:
        raise CommandError("command schema must reject additional top-level properties")
    requested_by = properties.get("requested_by")
    if not isinstance(requested_by, dict) or requested_by.get("const") != "chatgpt":
        raise CommandError("command schema must bind requested_by to chatgpt")
    payload_schema = properties.get("payload")
    if not isinstance(payload_schema, dict) or payload_schema.get("type") != "object":
        raise CommandError("command schema payload must be an object")
    rule = schema.get("allOf", [{}])[0]
    if (not isinstance(rule, dict) or
            rule.get("if", {}).get("properties", {}).get("action", {}).get("const") != "content.update"):
        raise CommandError("write payload must be constrained to the content.update business action")
    if not isinstance(rule.get("then", {}).get("properties", {}).get("payload", {}).get("oneOf"), list):
        raise CommandError("write payload must define closed stage-specific schemas")
    next_rule = rule.get("else", {})
    if (not isinstance(next_rule, dict) or
            next_rule.get("if", {}).get("properties", {}).get("action", {}).get("const") != "content.acceptance" or
            next_rule.get("then", {}).get("properties", {}).get("payload", {}).get("properties", {}).get("confirmed", {}).get("const") is not True):
        raise CommandError("content.acceptance must require explicit confirmation")
    navigation_rule = next_rule.get("else", {})
    if (not isinstance(navigation_rule, dict) or
            navigation_rule.get("if", {}).get("properties", {}).get("action", {}).get("const") != "navigation.update"):
        raise CommandError("navigation.update must be defined by an independent conditional payload schema")
    nav_stages = navigation_rule.get("then", {}).get("properties", {}).get("payload", {}).get("oneOf", [])
    if (not isinstance(nav_stages, list) or len(nav_stages) != 4
            or not all(isinstance(item, dict) and item.get("additionalProperties") is False for item in nav_stages)):
        raise CommandError("navigation.update must have four closed provider/stage payload variants")
    if navigation_rule.get("else", {}).get("properties", {}).get("payload", {}).get("maxProperties") != 0:
        raise CommandError("other business actions must continue to reject unknown payload keys")

    valid = {
        "version": "1",
        "command_id": "cmd-selftest-read-0001",
        "site": "acceptance-site",
        "action": "site.read",
        "requested_by": "chatgpt",
        "payload": {},
    }
    validate_command(valid, registry)

    invalid = dict(valid)
    invalid["mcp_url"] = "https://attacker.invalid/mcp"
    try:
        validate_command(invalid, registry)
    except CommandError:
        pass
    else:
        raise CommandError("command accepted an arbitrary transport field")

    invalid_payload = dict(valid)
    invalid_payload["payload"] = {"route": "/wp-json/anything"}
    try:
        validate_command(invalid_payload, registry)
    except CommandError:
        pass
    else:
        raise CommandError("command accepted an arbitrary payload route")

    planned = {
        "stage": "plan", "post_id": 42,
        "fields": {"title": "Updated title", "status": "draft"},
        "reason": "Explicitly requested review",
    }
    write_command = {**valid, "action": "content.update", "payload": planned}
    validate_command(write_command, registry)
    for malformed in (
        {**planned, "rest_route": "/wp-json/wp/v2/posts/42"},
        {**planned, "fields": {"unknown_meta": "unsafe"}},
        {**planned, "fields": {}},
        {**planned, "fields": {"status": "trash"}},
        {"stage": "apply", "plan_id": "plan_42", "confirmed": False},
        {"stage": "rollback", "plan_id": "plan_42", "confirmed": "true"},
        {"stage": "apply", "plan_id": "plan_42", "confirmed": True, "url": "https://example.com"},
    ):
        try:
            validate_command({**write_command, "payload": malformed}, registry)
        except CommandError:
            pass
        else:
            raise CommandError("unsafe content.update payload was accepted")

    expected_plan = {
        "plan": {"plan_id": "plan_42", "status": "planned",
                 "capability_id": "content.write", "operation": "content.update",
                 "confirmation_required": True, "target": {"post_id": 42}},
        "changes": {"before": {"post_title": "private original"}, "after": {"post_title": "Updated title"}},
    }
    if verify_content_response("plan", expected_plan, planned) is not False:
        raise CommandError("planning incorrectly reported a verified mutation")
    safe_plan = sanitize_write_evidence("plan", expected_plan, "")
    if "private original" in json.dumps(safe_plan) or "Updated title" in json.dumps(safe_plan):
        raise CommandError("write preview leaked original or proposed content into Git history")
    for bad in (
        {"plan": {**expected_plan["plan"], "status": "applied"}, "changes": expected_plan["changes"]},
        {"plan": {**expected_plan["plan"], "target": {"post_id": 99}}, "changes": expected_plan["changes"]},
    ):
        try:
            verify_content_response("plan", bad, planned)
        except VerificationError:
            pass
        else:
            raise CommandError("invalid content plan was accepted")
    for stage in ("apply", "rollback"):
        approved = {"stage": stage, "plan_id": "plan_42", "confirmed": True}
        validate_command({**write_command, "payload": approved}, registry)
        safe = {"plan": {**expected_plan["plan"], "status": "applied" if stage == "apply" else "rolled-back"}}
        if stage == "apply":
            safe.update(status="verified", execution={"success": True},
                        verification={"verified": True, "status": "verified"})
        else:
            safe.update(status="rolled-back", rollback={"success": True})
        if verify_content_response(stage, safe, approved) is not True:
            raise CommandError("verified content mutation was rejected")
        try:
            verify_content_response(stage, {**safe, "plan": {**safe["plan"], "plan_id": "other"}}, approved)
        except VerificationError:
            pass
        else:
            raise CommandError("cross-plan write response was accepted")
        if stage == "apply":
            try:
                verify_content_response(stage, {**safe, "verification": {"verified": False}}, approved)
            except VerificationError:
                pass
            else:
                raise CommandError("unverified content apply was accepted")

    acceptance = {**valid, "action": "content.acceptance", "payload": {"confirmed": True}}
    validate_command(acceptance, registry)
    for bad in ({}, {"confirmed": False}, {"confirmed": "true"},
                {"confirmed": True, "post_id": 1}, {"confirmed": True, "url": "https://example.invalid"}):
        try:
            validate_command({**acceptance, "payload": bad}, registry)
        except CommandError:
            pass
        else:
            raise CommandError("unsafe content acceptance intent was accepted")

    run_id = "sm-mcp-accept-" + "a" * 20
    ok_step = lambda evidence: {"status": "passed", "evidence": evidence}
    valid_state = {
        "run_id": run_id, "profile": ACCEPTANCE_PROFILE, "status": "completed",
        "had_failure": False, "steps": list(ACCEPTANCE_STEPS),
        "fixture": {"post_id": 0, "menu_id": 0, "menu_item_id": 0},
        "results": {
            "precheck": ok_step({}),
            "fixture_content_create": ok_step({"post_id": 42, "status": "draft"}),
            "content_plan": ok_step({"plan_id": "sm-plan-xyz"}),
            "content_apply_verify": ok_step({"plan_id": "sm-plan-xyz", "verified": True, "status": "verified"}),
            "content_rollback": ok_step({"plan_id": "sm-plan-xyz", "rollback_success": True, "status": "rolled-back"}),
            "cleanup": ok_step({"removed": {"post": True, "menu": True}}),
        },
    }
    evidence = summarize_content_acceptance(valid_state, run_id)
    if not (evidence["apply_verified"] and evidence["rollback_verified"] and evidence["draft_deleted"]):
        raise CommandError("verified disposable acceptance result was lost")
    for bad in (
        {**valid_state, "profile": "mcp-low-resource"},
        {**valid_state, "status": "running"},
        {**valid_state, "results": {**valid_state["results"],
                                  "content_rollback": ok_step({"plan_id": "sm-plan-xyz", "rollback_success": False, "status": "rollback-failed"})}},
        {**valid_state, "results": {**valid_state["results"],
                                  "cleanup": ok_step({"removed": {"post": False}})}},
        {**valid_state, "fixture": {"post_id": 42, "menu_id": 0, "menu_item_id": 0}},
        {**valid_state, "fixture": {"post_id": 0, "menu_id": 43, "menu_item_id": 5}},
    ):
        try:
            summarize_content_acceptance(bad, run_id)
        except VerificationError:
            pass
        else:
            raise CommandError("incomplete or unauthorized content acceptance was considered verified")

    nav_plan = {
        "stage": "plan", "provider": "wordpress-classic-menu",
        "target_id": 456, "fields": {"title": "Test menu", "classes": ["test"]},
        "reason": "Review isolated navigation change"
    }
    nav_cmd = {**valid, "action": "navigation.update", "payload": nav_plan}
    validate_command(nav_cmd, registry)
    block_plan = {
        **nav_plan, "provider": "wordpress-block-navigation",
        "fields": {"title": "Block navigation", "status": "draft"}
    }
    validate_command({**nav_cmd, "payload": block_plan}, registry)
    invalid_nav = (
        {**nav_plan, "provider": "unknown-menu-provider"},
        {**nav_plan, "fields": {"content": "Forbidden classic-menu field"}},
        {**block_plan, "fields": {"classes": ["not supported by block navigation"]}},
        {**nav_plan, "fields": {"url": "javascript:alert(1)"}},
        {**nav_plan, "fields": {"url": "//unsafe.example"}},
        {**nav_plan, "fields": {"target": "_self"}},
        {**nav_plan, "fields": {"classes": [42]}},
        {**nav_plan, "fields": {}},
        {**nav_plan, "target_id": True},
        {**nav_plan, "mcp_tool": "tools/call"},
        {"stage": "apply", "plan_id": "sm-plan-test", "confirmed": False},
        {"stage": "rollback", "plan_id": "sm-plan-test", "confirmed": "true"},
        {"stage": "apply", "plan_id": "sm-plan-test", "confirmed": True, "url": "https://invalid.test"},
    )
    for bad in invalid_nav:
        try:
            validate_command({**nav_cmd, "payload": bad}, registry)
        except CommandError:
            pass
        else:
            raise CommandError("unsafe navigation.update command was accepted")

    nav_id = "sm-plan-nav0123456789abcdef01234"
    nav_response = {
        "plan": {
            "plan_id": nav_id, "capability_id": "navigation.write",
            "operation": "navigation.update", "status": "planned",
            "target": {"provider": "wordpress-classic-menu", "target_id": 456},
            "fields": ["title", "classes"], "reversible": True,
            "confirmation_required": True,
        },
        "changes": {"before": {"title": "old", "classes": ["old"]},
                    "after": {"title": "Test menu", "classes": ["test"]}},
    }
    if verify_navigation_response("plan", nav_response, nav_plan) is not False:
        raise CommandError("navigation planning incorrectly reported an applied write")
    safe_nav = sanitize_write_evidence("plan", nav_response, "")
    if "old" in json.dumps(safe_nav) or "Test menu" in json.dumps(safe_nav):
        raise CommandError("WordPress navigation content leaked into GitHub evidence")
    for malformed in (
        {**nav_response, "plan": {**nav_response["plan"], "target": {"provider": "wordpress-block-navigation", "target_id": 456}}},
        {**nav_response, "plan": {**nav_response["plan"], "fields": ["title"]}},
        {**nav_response, "plan": {**nav_response["plan"], "confirmation_required": False}},
    ):
        try:
            verify_navigation_response("plan", malformed, nav_plan)
        except VerificationError:
            pass
        else:
            raise CommandError("invalid navigation plan was considered valid")
    for phase in ("apply", "rollback"):
        confirmed = {"stage": phase, "plan_id": nav_id, "confirmed": True}
        validate_command({**nav_cmd, "payload": confirmed}, registry)
        success = {"plan": {**nav_response["plan"], "status": "applied" if phase == "apply" else "rolled-back"}}
        if phase == "apply":
            success.update(status="verified", execution={"success": True}, verification={"verified": True})
        else:
            success.update(status="rolled-back", rollback={"success": True})
        if verify_navigation_response(phase, success, confirmed) is not True:
            raise CommandError("verified navigation mutation was rejected")
        for malformed in (
            {**success, "plan": {**success["plan"], "plan_id": "sm-plan-other"}},
            {**success, **({"verification": {"verified": False}} if phase == "apply" else {"rollback": {"success": False}})},
        ):
            try:
                verify_navigation_response(phase, malformed, confirmed)
            except VerificationError:
                pass
            else:
                raise CommandError("unverified or cross-plan navigation mutation was accepted")

    secret = "unit-test-super-secret-token"
    sanitized = sanitize_value(
        {
            "authorization": "Bearer " + secret,
            "nested": {"api_token": secret, "safe": "ok"},
            "message": "Bearer " + secret,
        },
        secret,
    )
    serialized = json.dumps(sanitized)
    if secret in serialized or "Bearer " + secret in serialized:
        raise CommandError("result sanitization leaked a test secret")
    if sanitized.get("nested", {}).get("safe") != "ok":
        raise CommandError("result sanitization removed safe data")

    try:
        validate_mcp_url("http://example.com/mcp")
    except CommandError:
        pass
    else:
        raise CommandError("non-HTTPS production MCP URL was accepted")

    print("site-manager-command-runner self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", nargs="?", type=Path)
    parser.add_argument("--result-dir", type=Path, default=DEFAULT_RESULT_DIR)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()

    try:
        if args.self_test:
            return self_test()
        if args.command is None:
            raise CommandError("command path is required")
        return execute(args.command, args.result_dir)
    except CommandError as exc:
        print(f"ERROR: {exc}", file=os.sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
