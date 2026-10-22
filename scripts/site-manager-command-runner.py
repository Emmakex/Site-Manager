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
SUPPORTED_ACTIONS = {"site.read", "site.diagnostics"}
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
        raise CommandError("registry action set does not match the bounded read-only surface")

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
        raise CommandError("action is outside the bounded SM-4F read-only surface")

    if command.get("requested_by") != "chatgpt":
        raise CommandError("requested_by must be chatgpt")

    payload = command.get("payload")
    if not isinstance(payload, dict) or payload:
        raise CommandError("read-only Site Manager commands require an empty payload object")


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

    def call(self, tool: str) -> Any:
        payload = self.run(
            [
                "--method",
                "tools/call",
                "--tool-name",
                tool,
                "--tool-args-json",
                "{}",
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
        tool = str(action["tool"])

        client = McpClient(base_url, token)
        client.initialize()
        data = client.call(tool)
        sanitized = sanitize_value(data, token)

        result = {
            "version": "1",
            "status": "success",
            "site": site,
            "action": action_name,
            "command_id": command_id,
            "verified": True,
            "required_scope": action["required_scope"],
            "gateway_generation": generation,
            "data": sanitized,
        }
        path = write_result(result_dir, command_id, result)
        print(f"SUCCESS {path}")
        return 0
    except RefusalError as exc:
        error_code = "mcp_refused"
        error_message = "Site Manager refused the bounded read operation."
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
    if not isinstance(payload_schema, dict) or payload_schema.get("maxProperties") != 0:
        raise CommandError("SM-4F slice 1 payload must remain empty/read-only")

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
