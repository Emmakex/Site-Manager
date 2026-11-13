#!/usr/bin/env python3
"""Actual Node MCP HTTP acceptance for a >1 MB governed Theme package plan.

Uses genuine @modelcontextprotocol/node/server over loopback with a strictly
fake WordPress REST upstream. No external WordPress, GitHub credentials, client
site or production service is contacted. Tests the wire-level upload and MCP
session/response semantics, not the WordPress upgrader (tested separately).
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.error import HTTPError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT / "scripts"))
from site_manager_theme_large_mcp import (  # noqa: E402
    ThemePackageMcpPlanClient,
)

TOKEN = "a" * 64
CONNECTION = "smc_disposable_mcp_acceptance"
MCP_BODY = "QUJD" * 310000  # 1.24 MB: exceeds Linux argument size and common 1 MB default bodies.
PACKAGE = {
    "theme_slug":"seo-geo-theme",
    "package_sha256":"b" * 64,
    "package_base64":MCP_BODY,
    "source_commit_sha":"c" * 40,
    "source_run_id":456,
    "source_artifact_id":789,
}

state = {"connection_reads":0,"package_plans":0,"package_size":0,"valid_source":False}


class DisposableWordPress(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args) -> None:
        return

    def send_json(self, code: int, body: dict) -> None:
        contents = json.dumps(body,separators=(",",":")).encode("utf-8")
        self.send_response(code)
        self.send_header("content-type","application/json")
        self.send_header("content-length",str(len(contents)))
        self.end_headers()
        self.wfile.write(contents)

    def authenticated(self) -> bool:
        if self.headers.get("authorization") != f"Bearer {TOKEN}":
            self.send_json(401,{"error":{"code":"invalid_test_bearer"}})
            return False
        return True

    def do_GET(self) -> None:
        if not self.authenticated():
            return
        if self.path != "/wp-json/site-manager/v1/connection":
            self.send_json(404,{"error":{"code":"not_found"}})
            return
        state["connection_reads"] += 1
        self.send_json(200,{"data":{
            "connection_id":CONNECTION,
            "enabled":True,
            "scopes":["site.read","theme.package.write"],
        }})

    def do_POST(self) -> None:
        if not self.authenticated():
            return
        if self.path != "/wp-json/site-manager/v1/write/theme/package/plan":
            self.send_json(404,{"error":{"code":"not_found"}})
            return
        try:
            length = int(self.headers.get("content-length","-1"))
        except ValueError:
            length = -1
        if not 1_000_000 <= length <= 2_000_000:
            self.send_json(413,{"error":{"code":"incorrect_test_package_size"}})
            return
        try:
            body = json.loads(self.rfile.read(length))
        except (ValueError,UnicodeDecodeError):
            self.send_json(400,{"error":{"code":"invalid_test_json"}})
            return
        expected = {"theme_slug","package_sha256","package_base64",
                    "source_commit_sha","source_run_id","source_artifact_id"}
        if (not isinstance(body,dict) or set(body) != expected
                or any(body.get(k) != PACKAGE[k] for k in expected)):
            self.send_json(409,{"error":{"code":"untrusted_test_package"}})
            return
        state["package_plans"] += 1
        state["package_size"] = length
        state["valid_source"] = True
        self.send_json(200,{"data":{
            "stage":"plan", "status":"planned",
            "plan":{"plan_id":"sm-plan-" + "d"*24},
        }})


def free_port() -> int:
    with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1",0))
        return sock.getsockname()[1]


wp = ThreadingHTTPServer(("127.0.0.1",0), DisposableWordPress)
wp_thread = threading.Thread(target=wp.serve_forever,daemon=True)
wp_thread.start()
node_port = free_port()
node_dir = ROOT / "bridge/site-manager-mcp"
node_entry = node_dir / "dist/index.js"
if not node_entry.is_file():
    raise SystemExit("MCP must be built before running this acceptance test")

env = dict(os.environ)
env.update({
    "SITE_MANAGER_REST_BASE":f"http://127.0.0.1:{wp.server_port}/wp-json/site-manager/v1/",
    "SITE_MANAGER_TOKEN":TOKEN,
    "SITE_MANAGER_CONNECTION_ID":CONNECTION,
    "SITE_MANAGER_TIMEOUT_MS":"30000",
    "HOST":"127.0.0.1",
    "PORT":str(node_port),
})
process = subprocess.Popen(
    ["node",str(node_entry)],cwd=node_dir,env=env,
    stdout=subprocess.PIPE,stderr=subprocess.PIPE,
)
try:
    live = False
    for attempt in range(120):
        if process.poll() is not None:
            raise AssertionError("MCP Node process exited before health was ready")
        try:
            with urlopen(f"http://127.0.0.1:{node_port}/healthz",timeout=1) as response:
                health = json.loads(response.read())
            if health.get("ok") is True and health.get("mode") == "governed-write":
                live = True
                break
        except (OSError,ValueError):
            pass
        time.sleep(0.2)
    assert live, "real Node MCP did not expose a healthy site-scoped bridge"
    assert state["connection_reads"] >= 1, "Node MCP read scoped WordPress connection at startup"

    # This tests actual HTTP authorization, not merely the local Python mock.
    invalid = Request(
        f"http://127.0.0.1:{node_port}/mcp",
        data=json.dumps({"jsonrpc":"2.0","id":1,"method":"ping"}).encode(),
        headers={"authorization":"Bearer wrong","content-type":"application/json"},
        method="POST",
    )
    try:
        with urlopen(invalid,timeout=5):
            raise AssertionError("MCP accepted a forged bearer token")
    except HTTPError as error:
        assert error.code == 401, f"unexpected bearer refusal {error.code}"

    actual = ThemePackageMcpPlanClient(
        f"http://127.0.0.1:{node_port}/mcp",TOKEN,
    ).stage_official_release(PACKAGE)
    assert actual["status"] == "planned", "real MCP did not return a planned WordPress result"
    assert actual["plan"]["plan_id"] == "sm-plan-" + "d"*24, "WordPress plan identifier lost"
    assert state["package_plans"] == 1, "MCP called WordPress more than once"
    assert state["package_size"] > 1_000_000, "actual payload was truncated"
    assert state["valid_source"], "MCP altered immutable source references or ZIP bytes"
    assert "package_base64" not in json.dumps(actual), "MCP plan exposed incoming ZIP bytes"
    print("REAL_MCP_LARGE_THEME_PLAN=PASSED")
    print(f"REAL_MCP_PACKAGE_BYTES={state['package_size']}")
    print("REAL_MCP_FIXED_TOOL_AND_BEARER=VERIFIED")
finally:
    process.terminate()
    try:
        process.communicate(timeout=5)
    except subprocess.TimeoutExpired:
        process.kill()
        process.communicate(timeout=5)
    wp.shutdown()
    wp.server_close()
    wp_thread.join(timeout=5)
