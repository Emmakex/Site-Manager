#!/usr/bin/env python3
"""Bounded Streamable HTTP transport contract for a single Theme ZIP plan."""
from __future__ import annotations

import json
from pathlib import Path
import sys
from typing import Any, Mapping

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from site_manager_theme_large_mcp import (
    ThemePackageMcpPlanClient,
    ThemePackageMcpTransportError,
    _decoded,
)

TOKEN = "test-connection-bearer-" + "0" * 32
URL = "https://managed-mcp.example.test/mcp"
PACKAGE = {
    "theme_slug": "seo-geo-theme",
    "package_sha256": "a" * 64,
    "package_base64": "QUJD" * 250000,  # 1MB, cannot pass via argv
    "source_commit_sha": "f" * 40,
    "source_run_id": 1234,
    "source_artifact_id": 4321,
}


def reject(fn, code: str):
    try:
        fn()
    except ThemePackageMcpTransportError as exc:
        assert str(exc) == code, (str(exc), code)
        return
    raise AssertionError("Expected " + code)


def response(id_: int, result: dict[str, Any]) -> bytes:
    return json.dumps({"jsonrpc":"2.0","id":id_,"result":result}).encode()


class FakeWire:
    def __init__(self, *, sse: bool = False, bad_tool: bool = False,
                 protocol: str = "2025-06-18"):
        self.calls: list[tuple[str, dict[str, str], dict[str, Any]]] = []
        self.sse = sse
        self.bad_tool = bad_tool
        self.protocol = protocol

    def __call__(self, url: str, headers: Mapping[str, str], body: bytes):
        doc = json.loads(body)
        self.calls.append((url, dict(headers), doc))
        assert url == URL
        assert headers["authorization"] == f"Bearer {TOKEN}"
        assert "Bearer" not in body.decode()
        if doc["method"] == "initialize":
            assert "mcp-session-id" not in headers
            return (200, {"Content-Type":"application/json","Mcp-Session-Id":"secret-session"},
                    response(1, {"protocolVersion":self.protocol,"capabilities":{},"serverInfo":{"name":"SiteManager","version":"0.5"}}))
        assert headers.get("mcp-session-id") == "secret-session"
        assert headers.get("mcp-protocol-version") == self.protocol
        if doc["method"] == "notifications/initialized":
            return (202, {}, b"")
        assert doc["method"] == "tools/call"
        assert doc["params"]["name"] == "theme_package_plan"
        assert doc["params"]["arguments"] == PACKAGE
        data = {"stage":"plan","status":"planned","plan":{"plan_id":"sm-plan-"+"a"*24}}
        if self.bad_tool:
            data["status"] = "unverified"
        payload = response(2, {"structuredContent":{"data":data}})
        if self.sse:
            return (200, {"content-type":"text/event-stream"}, b"event: message\n"
                    + b"data: " + payload + b"\n\n")
        return (200, {"Content-Type":"application/json"}, payload)


wire = FakeWire()
actual = ThemePackageMcpPlanClient(URL,TOKEN,wire=wire).stage_official_release(PACKAGE)
assert actual["status"] == "planned"
assert len(wire.calls) == 3
assert wire.calls[2][2]["params"]["arguments"]["package_base64"] == PACKAGE["package_base64"]
assert wire.calls[2][1]["mcp-session-id"] == "secret-session"
assert "package_base64" not in actual

sse = FakeWire(sse=True)
assert ThemePackageMcpPlanClient(URL,TOKEN,wire=sse).stage_official_release(PACKAGE)["status"] == "planned"
reject(lambda: ThemePackageMcpPlanClient(URL,TOKEN,wire=FakeWire(bad_tool=True)).stage_official_release(PACKAGE),
       "theme_package_mcp_not_planned")
reject(lambda: ThemePackageMcpPlanClient(URL,TOKEN,wire=FakeWire(protocol="unknown")).stage_official_release(PACKAGE),
       "theme_package_mcp_unsupported_protocol")
assert ThemePackageMcpPlanClient("http://127.0.0.1:8787/mcp", TOKEN)
assert ThemePackageMcpPlanClient("http://localhost:8787/mcp", TOKEN)
reject(lambda: ThemePackageMcpPlanClient("http://169.254.169.254/latest", TOKEN),
       "theme_package_mcp_invalid_trusted_url")
reject(lambda: ThemePackageMcpPlanClient("https://user:pass@example.test/mcp", TOKEN),
       "theme_package_mcp_invalid_trusted_url")
reject(lambda: ThemePackageMcpPlanClient(URL,""),
       "theme_package_mcp_auth_unavailable")
reject(lambda: ThemePackageMcpPlanClient(URL,TOKEN,wire=FakeWire()).stage_official_release(
    {**PACKAGE, "arbitrary_rest_path":"/wp-json/debug"}),
    "theme_package_mcp_untrusted_payload")
reject(lambda: ThemePackageMcpPlanClient(URL,TOKEN,wire=FakeWire()).stage_official_release(
    {**PACKAGE, "package_base64": "X" * 27000001}),
    "theme_package_mcp_untrusted_payload")
reject(lambda: _decoded(302, {"content-type":"application/json"}, b"{}",2),
       "theme_package_mcp_protocol_failed")
reject(lambda: _decoded(200, {"content-type":"application/json"},
       response(2, {"structuredContent": {}}) + b"no", 2),
       "theme_package_mcp_invalid_json")
reject(lambda: _decoded(200, {"content-type":"text/html"}, response(2,{}),2),
       "theme_package_mcp_invalid_content_type")
reject(lambda: _decoded(200, {"content-type":"application/json"}, response(3,{}),2),
       "theme_package_mcp_response_missing")
print("Bounded large Theme MCP transfer: OK (1MB JSON-RPC POST, SSE, session, scope, no URL/argv, fail-closed)")
