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
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parents[1]
REGISTRY_PATH = ROOT / "config" / "site-manager-command-registry.json"
SCHEMA_PATH = ROOT / "schemas" / "site-manager-command-v1.schema.json"
DEFAULT_RESULT_DIR = ROOT / "commands" / "site-manager" / "results"
COMMAND_ID_RE = re.compile(r"^cmd-[a-z0-9][a-z0-9-]{7,63}$")
SITE_ID_RE = re.compile(r"^[a-z0-9][a-z0-9-]{2,63}$")
SUPPORTED_ACTIONS = {"site.read", "site.diagnostics", "content.update"}
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
    else:
        validate_content_payload(payload)


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
        stage = "read" if action_name in READ_ACTIONS else command["payload"]["stage"]
        tool = str(action["tool"] if stage == "read" else action["tools"][stage])
        tool_args = {} if stage == "read" else {
            k: v for k, v in command["payload"].items() if k != "stage"
        }

        client = McpClient(base_url, token)
        client.initialize()
        data = client.call(tool, tool_args)
        if stage == "read":
            verified = True
            sanitized = sanitize_value(data, token)
        else:
            verified = verify_content_response(stage, data, command["payload"])
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
