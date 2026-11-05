#!/usr/bin/env python3
"""Contract tests: read-only bootstrap, provider/tenant identity and replay refusal."""
from __future__ import annotations

import copy
import json
from pathlib import Path
import sys
from tempfile import TemporaryDirectory

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from site_manager_site_bootstrap import (  # noqa: E402
    BootstrapError, normalize_bootstrap_evidence, prove_connection,
    validate_bootstrap_config,
)
from site_manager_site_catalog import load_verified_catalog  # noqa: E402


SITE = "test-client-one"
SITE_HOME = "https://tenant-one.example.test/"
CONNECTION = "smc_" + "a" * 32
TOKEN = "test-bootstrap-private-bearer"
RUN_ID = "42000000123"
URL = "https://mcp.example.test/site-manager/tenant-one/mcp"


def read_proof() -> dict:
    return {
        "connection_id": CONNECTION,
        "discovery": {
            "fingerprint": "b" * 64,
            "generated_at": "2026-11-05T20:00:00+00:00",
            "platform": {"platform": "wordpress", "version": "6.8", "metadata": {"locale": "es_ES"}},
            "inventory": {
                "urls": {"site": SITE_HOME},
                "plugins": {"items": {"site-manager/site-manager.php": {"version": "0.11.6"}}},
                "theme": {"name": "WordPress Theme"},
                "seo_authority": {"authority_resolved": False},
            },
        },
        "capabilities": {"capabilities": [{
            "id": "site.read",
            "detected": True, "understood": True, "governable": True,
            "required_scopes": ["site.read"], "provider": "wordpress-core",
            "blockers": [],
        }]},
        "raw_secrets": {"token": TOKEN, "wp_password": "DO_NOT_LEAK"},
    }


class FakeClient:
    clients: list["FakeClient"] = []
    response = read_proof()
    refusal = False

    def __init__(self, url: str, token: str) -> None:
        assert url == URL
        assert token == TOKEN
        self.calls: list[tuple[str, dict]] = []
        self.closed = False
        FakeClient.clients.append(self)

    def initialize(self) -> None:
        pass

    def call(self, tool: str, payload: dict) -> dict:
        self.calls.append((tool, payload))
        if FakeClient.refusal:
            raise BootstrapError("MCP refused site.read (scope missing)")
        return copy.deepcopy(FakeClient.response)

    def close(self) -> None:
        self.closed = True


def config(**overrides: str) -> dict:
    base = {
        "requested_site": SITE,
        "environment_site": SITE,
        "expected_site_url": SITE_HOME,
        "expected_connection_id": CONNECTION,
        "mcp_url": URL,
        "token": TOKEN,
        "run_id": RUN_ID,
    }
    base.update(overrides)
    return base


def expect_refused(fn: object) -> None:
    try:
        fn()  # type: ignore[operator]
    except BootstrapError:
        return
    raise AssertionError("invalid client bootstrap was accepted")


assert validate_bootstrap_config(**config())["site"] == SITE
for bad in [
    {"requested_site": "../../secret"},
    {"environment_site": "test-client-two"},
    {"expected_site_url": ""},
    {"expected_site_url": "http://tenant-one.example.test/"},
    {"expected_site_url": "https://user:secret@tenant-one.example.test/"},
    {"expected_connection_id": ""},
    {"expected_connection_id": "smc_" + "c" * 8},
    {"mcp_url": "http://mcp.example.test/"},
    {"mcp_url": "https://user:password@mcp.example.test/mcp"},
    {"token": ""},
    {"run_id": "../409"},
]:
    expect_refused(lambda bad=bad: validate_bootstrap_config(**config(**bad)))

correct = normalize_bootstrap_evidence(
    read_proof(), requested_site=SITE, run_id=RUN_ID,
    expected_site_url=SITE_HOME, expected_connection_id=CONNECTION,
)
assert correct["action"] == "site.overview"
assert correct["stage"] == "read"
assert correct["status"] == "success"
assert correct["verified"] is True
assert correct["required_scope"] == "site.read"
assert correct["command_id"] == "cmd-bootstrap-" + RUN_ID
assert correct["bootstrap"] == {
    "source": "protected-environment-bootstrap",
    "workflow_run_id": RUN_ID,
    "expected_site_url_verified": True,
    "expected_connection_id_verified": True,
    "read_only": True,
}
assert "raw_secrets" not in json.dumps(correct)
assert correct["data"]["site_url"] == SITE_HOME
assert correct["data"]["connection_id"] == CONNECTION

variants = [
    lambda v: v["discovery"]["inventory"]["urls"].update(site="https://tenant-two.example.test/"),
    lambda v: v.update(connection_id="smc_" + "b" * 32),
    lambda v: v["discovery"]["platform"].update(platform="prestashop"),
    lambda v: v["discovery"]["inventory"]["plugins"].update(items={}),
    lambda v: v["discovery"].update(fingerprint="bad"),
    lambda v: v["capabilities"]["capabilities"][0].update(governable=False),
    lambda v: v["capabilities"]["capabilities"][0].update(required_scopes=[]),
    lambda v: v["capabilities"].update(capabilities=[]),
]
for mutate in variants:
    bad = read_proof()
    mutate(bad)
    expect_refused(lambda bad=bad: normalize_bootstrap_evidence(
        bad, requested_site=SITE, run_id=RUN_ID,
        expected_site_url=SITE_HOME, expected_connection_id=CONNECTION,
    ))

with TemporaryDirectory(prefix="site-manager-sm4k-") as temp:
    out = Path(temp) / "results"
    path = prove_connection(**config(), result_dir=out, client_factory=FakeClient)
    assert path.name == "cmd-bootstrap-" + RUN_ID + ".json"
    assert FakeClient.clients[-1].calls == [("site_read", {})]
    assert FakeClient.clients[-1].closed is True
    record = json.loads(path.read_text(encoding="utf-8"))
    assert record["status"] == "success"
    assert record["bootstrap"]["read_only"] is True
    assert TOKEN not in path.read_text(encoding="utf-8")
    assert "DO_NOT_LEAK" not in path.read_text(encoding="utf-8")
    # No second MCP call with the same GitHub workflow run ID.
    count = len(FakeClient.clients)
    expect_refused(lambda: prove_connection(**config(), result_dir=out, client_factory=FakeClient))
    assert len(FakeClient.clients) == count

    # The resulting canonical proof is accepted as a catalog enrollment.
    cat = Path(temp) / "catalog.json"
    cat.write_text(json.dumps({
        "version": "1",
        "sites": [{
            "site": SITE, "label": "Verified test client",
            "proof_command_id": correct["command_id"],
        }],
    }), encoding="utf-8")
    assert load_verified_catalog(cat, out)[0]["site"] == SITE

    for mutate in [
        lambda p: p["bootstrap"].update(source="untrusted-synthetic-proof"),
        lambda p: p["bootstrap"].update(expected_connection_id_verified=False),
        lambda p: p["bootstrap"].update(read_only=False),
        lambda p: p["bootstrap"].update(workflow_run_id="222222222"),
    ]:
        bad = copy.deepcopy(record)
        mutate(bad)
        path.write_text(json.dumps(bad), encoding="utf-8")
        try:
            load_verified_catalog(cat, out)
        except Exception:
            pass
        else:
            raise AssertionError("spoofed bootstrap proof was accepted by catalog")

    path.unlink()
    FakeClient.response = read_proof()
    FakeClient.refusal = True
    expect_refused(lambda: prove_connection(**config(), result_dir=out, client_factory=FakeClient))
    assert list(out.glob("*.json")) == []
    assert FakeClient.clients[-1].closed is True
    FakeClient.refusal = False

# The trusted workflow is intentionally dispatch-only and cannot accept
# a caller-selected MCP endpoint, bearer, provider, WordPress target or tool.
workflow = (Path(__file__).resolve().parents[1] / ".github" / "workflows"
            / "site-manager-site-bootstrap.yml").read_text(encoding="utf-8")
assert "workflow_dispatch:" in workflow
assert "pull_request:" not in workflow and "schedule:" not in workflow
assert "github.actor == 'Emmakex'" in workflow
assert "github.ref == 'refs/heads/main'" in workflow
assert "github.run_attempt == 1" in workflow
assert "environment: site-manager-${{ needs.validate.outputs.site }}" in workflow
assert "SITE_MANAGER_EXPECTED_SITE_URL: ${{ vars.SITE_MANAGER_EXPECTED_SITE_URL }}" in workflow
assert "SITE_MANAGER_EXPECTED_CONNECTION_ID: ${{ secrets.SITE_MANAGER_EXPECTED_CONNECTION_ID }}" in workflow
assert "SITE_MANAGER_MCP_TOKEN: ${{ secrets.SITE_MANAGER_MCP_TOKEN }}" in workflow
assert "site_manager_site_bootstrap.py" in workflow
assert "commands/site-manager/pending/" not in workflow
assert "--site \"$REQUESTED_SITE_ID\" --run-id \"$BOOTSTRAP_RUN_ID\"" in workflow
boot_source = (Path(__file__).resolve().parents[1] / "scripts"
               / "site_manager_site_bootstrap.py").read_text(encoding="utf-8")
assert boot_source.count('client.call("site_read", {})') == 1
for forbidden in ('client.call("content_update_', 'client.call("navigation_update_',
                  'client.call("seo_update_', 'client.call("tools/call"',
                  'client.call("content_acceptance_'):
    assert forbidden not in boot_source

print("site_manager_site_bootstrap_test: OK")
