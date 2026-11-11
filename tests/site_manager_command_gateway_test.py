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

expected_actions = {"site.read", "site.diagnostics", "content.update", "content.acceptance", "navigation.update", "seo.update", "navigation.acceptance", "seo.acceptance", "site.overview", "site.content_targets", "site.theme_model"}
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
assert registry["actions"]["site.overview"] == {
    "tool": "site_read",
    "required_scope": "site.read",
    "read_only": True,
}
assert registry["actions"]["site.content_targets"] == {
    "tool": "site_content_targets",
    "required_scope": "site.read",
    "read_only": True,
}
assert registry["actions"]["site.theme_model"] == {
    "tool": "site_theme_model",
    "required_scope": "site.read",
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
assert registry["actions"]["navigation.update"] == {
    "tools": {"plan": "navigation_update_plan", "apply": "navigation_update_apply", "rollback": "navigation_update_rollback"},
    "required_scope": "navigation.write",
    "read_only": False,
}
assert registry["actions"]["navigation.acceptance"] == {
    "tools": {"start": "navigation_acceptance_start", "status": "navigation_acceptance_status"},
    "required_scope": "navigation.write",
    "read_only": False,
}
assert registry["actions"]["seo.acceptance"] == {
    "tools": {"start": "seo_acceptance_start", "status": "seo_acceptance_status"},
    "required_scope": "seo.write",
    "read_only": False,
}
assert registry["actions"]["seo.update"] == {
    "tools": {"plan": "seo_update_plan", "apply": "seo_update_apply", "rollback": "seo_update_rollback"},
    "required_scope": "seo.write",
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
navigation_rule = followup["else"]
assert navigation_rule["if"]["properties"]["action"]["const"] == "navigation.update"
navigation_stages = navigation_rule["then"]["properties"]["payload"]["oneOf"]
assert len(navigation_stages) == 4
assert all(stage["additionalProperties"] is False for stage in navigation_stages)
assert {(stage["properties"]["stage"]["const"], stage["properties"].get("provider", {}).get("const"))
        for stage in navigation_stages} == {
    ("plan", "wordpress-classic-menu"),
    ("plan", "wordpress-block-navigation"),
    ("apply", None), ("rollback", None),
}
classic, block = navigation_stages[:2]
assert set(classic["properties"]["fields"]["properties"]) == {
    "title", "url", "description", "attr_title", "target", "classes", "xfn"
}
assert set(block["properties"]["fields"]["properties"]) == {"title", "content", "status"}
for stage in navigation_stages:
    assert stage["additionalProperties"] is False
    if stage["properties"]["stage"]["const"] == "plan":
        assert set(stage["required"]) == {"stage", "provider", "target_id", "fields"}
        assert stage["properties"]["fields"]["additionalProperties"] is False
    else:
        assert set(stage["required"]) == {"stage", "plan_id", "confirmed"}
        assert stage["properties"]["confirmed"]["const"] is True
seo_rule = navigation_rule["else"]
assert seo_rule["if"]["properties"]["action"]["const"] == "seo.update"
seo_stages = seo_rule["then"]["properties"]["payload"]["oneOf"]
assert len(seo_stages) == 3
assert all(stage["additionalProperties"] is False for stage in seo_stages)
assert {stage["properties"]["stage"]["const"] for stage in seo_stages} == {"plan", "apply", "rollback"}
assert set(seo_stages[0]["required"]) == {"stage", "post_id", "fields"}
assert seo_stages[0]["properties"]["fields"]["additionalProperties"] is False
assert set(seo_stages[0]["properties"]["fields"]["properties"]) == {
    "title", "description", "canonical", "indexability"
}
for stage in seo_stages[1:]:
    assert stage["properties"]["confirmed"]["const"] is True
    assert set(stage["required"]) == {"stage", "plan_id", "confirmed"}
nav_acceptance_rule = seo_rule["else"]
assert nav_acceptance_rule["if"]["properties"]["action"]["const"] == "navigation.acceptance"
assert nav_acceptance_rule["then"]["properties"]["payload"] == {
    "type": "object", "additionalProperties": False,
    "required": ["confirmed"], "properties": {"confirmed": {"const": True}}
}
seo_acceptance_rule = nav_acceptance_rule["else"]
assert seo_acceptance_rule["if"]["properties"]["action"]["const"] == "seo.acceptance"
assert seo_acceptance_rule["then"]["properties"]["payload"] == {
    "type": "object", "additionalProperties": False,
    "required": ["confirmed"], "properties": {"confirmed": {"const": True}},
}
assert seo_acceptance_rule["else"]["properties"]["payload"] == {
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

assert "require_registered_site" in runner
assert "SiteCatalogError" in runner
assert "EXPECTED_TOOLS" in runner
assert "site.overview" in runner
assert "summarize_site_overview" in runner
assert "summarize_content_targets" in runner
assert "summarize_theme_model" in runner
assert "ContentTargetsError" in runner
assert "OverviewError" in runner
assert "CONTENT_TOOLS" in runner
assert "NAVIGATION_TOOLS" in runner
assert "NAV_ACCEPTANCE_TOOLS" in runner
assert "SEO_ACCEPTANCE_TOOLS" in runner
assert "SEO_ACCEPTANCE_STEPS" in runner
assert "summarize_seo_acceptance" in runner
assert "run_seo_acceptance" in runner
assert "NAV_ACCEPTANCE_STEPS" in runner
assert "summarize_navigation_acceptance" in runner
assert "run_navigation_acceptance" in runner
assert "SEO_TOOLS" in runner
assert "validate_seo_payload" in runner
assert "verify_seo_response" in runner
assert "sanitize_seo_evidence" in runner
assert "validate_navigation_payload" in runner
assert "verify_navigation_response" in runner
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

assert "site_manager_site_catalog.py check --site" in workflow
assert "scripts/site_manager_site_catalog.py" in workflow
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
