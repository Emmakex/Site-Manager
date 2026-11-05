#!/usr/bin/env python3
"""Static contract checks for the SM-4F GitHub Command Gateway."""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

registry = json.loads((ROOT / "config/site-manager-command-registry.json").read_text(encoding="utf-8"))
schema = json.loads((ROOT / "schemas/site-manager-command-v1.schema.json").read_text(encoding="utf-8"))
example = json.loads((ROOT / "commands/site-manager/examples/site-read.json").read_text(encoding="utf-8"))
runner = (ROOT / "scripts/site-manager-command-runner.py").read_text(encoding="utf-8")
guard = (ROOT / "scripts/site-manager-command-guard.py").read_text(encoding="utf-8")
workflow = (ROOT / ".github/workflows/site-manager-command-gateway.yml").read_text(encoding="utf-8")

expected_actions = {"site.read", "site.diagnostics", "content.update", "content.acceptance"}
assert registry["version"] == "1"
assert set(registry["actions"]) == expected_actions
assert registry["actions"]["site.read"] == {
    "tool": "site_read",
    "required_scope": "site.read",
    "read_only": True,
}
assert registry["actions"]["site.diagnostics"] == {
    "tool": "site_diagnostics",
    "required_scope": "site.diagnostics",
    "read_only": True,
}
assert registry["actions"]["content.update"] == {
    "tools": {"plan": "content_update_plan", "apply": "content_update_apply", "rollback": "content_update_rollback"},
    "required_scope": "content.write",
    "read_only": False,
}
assert registry["actions"]["content.acceptance"] == {
    "tools": {"start": "content_acceptance_start", "status": "content_acceptance_status"},
    "required_scope": "content.write",
    "read_only": False,
}

assert schema["additionalProperties"] is False
assert set(schema["required"]) == {"version", "command_id", "site", "action", "requested_by", "payload"}
assert set(schema["properties"]["action"]["enum"]) == expected_actions
assert schema["properties"]["requested_by"]["const"] == "chatgpt"
assert schema["properties"]["payload"]["type"] == "object"
conditional = schema["allOf"][0]
assert conditional["if"]["properties"]["action"]["const"] == "content.update"
followup = conditional["else"]
assert followup["if"]["properties"]["action"]["const"] == "content.acceptance"
assert followup["then"]["properties"]["payload"] == {
    "type": "object",
    "additionalProperties": False,
    "required": ["confirmed"],
    "properties": {"confirmed": {"const": True}},
}
assert followup["else"]["properties"]["payload"] == {
    "type": "object", "additionalProperties": False, "maxProperties": 0
}
stages = conditional["then"]["properties"]["payload"]["oneOf"]
assert len(stages) == 3
assert {stage["properties"]["stage"]["const"] for stage in stages} == {"plan", "apply", "rollback"}
assert all(stage["additionalProperties"] is False for stage in stages)
assert stages[0]["properties"]["fields"]["additionalProperties"] is False
assert set(stages[0]["properties"]["fields"]["properties"]) == {
    "title", "slug", "content", "excerpt", "status"
}
for stage in stages[1:]:
    assert stage["properties"]["confirmed"]["const"] is True
    assert set(stage["required"]) == {"stage", "plan_id", "confirmed"}


assert example["action"] == "site.read"
assert example["requested_by"] == "chatgpt"
assert example["payload"] == {}
assert "url" not in example
assert "token" not in example
assert "tool" not in example

assert "EXPECTED_TOOLS" in runner
assert "CONTENT_TOOLS" in runner
assert "ACCEPTANCE_TOOLS" in runner
assert "summarize_content_acceptance" in runner
assert "run_content_acceptance" in runner
assert "mcp-content-only" in runner
assert "validate_content_payload" in runner
assert "verify_content_response" in runner
assert "sanitize_write_evidence" in runner
assert "confirmation_required" in runner
assert "rollback_available" in runner
assert "SITE_MANAGER_MCP_BASE_URL" in runner
assert "SITE_MANAGER_MCP_TOKEN" in runner
assert "SITE_MANAGER_SITE_ID" in runner
assert "Authorization" in runner
assert "@modelcontextprotocol/inspector@2.5.0" in runner
assert "mcp_url" in runner  # self-test proves arbitrary transport fields are rejected
assert "command_sha256" in guard
assert "replay_success" in guard
assert "failed status; use a new command_id" in guard

assert "commands/site-manager/pending/*.json" in workflow
assert "concurrency:" in workflow
assert "cancel-in-progress: false" in workflow
assert "site-manager-${{ needs.resolve.outputs.site }}" in workflow
assert "vars.SITE_MANAGER_MCP_BASE_URL" in workflow
assert "vars.SITE_MANAGER_SITE_ID" in workflow
assert "secrets.SITE_MANAGER_MCP_TOKEN" in workflow
assert "site-manager-command-guard.py guard" in workflow
assert "site-manager-command-guard.py stamp" in workflow
assert "Persist sanitized result for ChatGPT readback" in workflow
assert "Execute bounded production MCP operation" in workflow
assert "https://mcp." not in workflow

print("site_manager_command_gateway_test: OK")
