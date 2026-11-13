#!/usr/bin/env python3
"""Restricted streaming-HTTP MCP plan client for CI-pinned Theme ZIP bytes.

The ordinary Gateway Inspector CLI passes tool JSON in a single argv string,
which cannot safely carry a WordPress ZIP (> Linux MAX_ARG_STRLEN). This
client uses the standard MCP Streamable HTTP JSON-RPC transport for ONE fixed
tool, without changing the canonical operator path. No arbitrary tools, MCP
server URLs from commands, redirects, private-path parameters or shell calls.
"""
from __future__ import annotations

import json
from typing import Any, Callable, Mapping
from urllib.parse import urlparse
from urllib.request import Request, HTTPRedirectHandler, build_opener

MAX_TOOL_REQUEST_BYTES = 28_000_000
MAX_TOOL_RESPONSE_BYTES = 512_000
PROTOCOL_VERSION = "2025-06-18"


class ThemePackageMcpTransportError(RuntimeError):
    pass


class _RejectRedirect(HTTPRedirectHandler):
    def redirect_request(self, req: Request, fp: Any, code: int, msg: str,
                         headers: Any, newurl: str) -> None:
        raise ThemePackageMcpTransportError("theme_package_mcp_redirect_refused")


def _wire(url: str, headers: Mapping[str, str], body: bytes) -> tuple[int, dict[str, str], bytes]:
    if len(body) > MAX_TOOL_REQUEST_BYTES:
        raise ThemePackageMcpTransportError("theme_package_mcp_payload_too_large")
    request = Request(url, method="POST", data=body, headers=dict(headers))
    try:
        with build_opener(_RejectRedirect()).open(request, timeout=120) as response:
            raw = response.read(MAX_TOOL_RESPONSE_BYTES + 1)
            if len(raw) > MAX_TOOL_RESPONSE_BYTES:
                raise ThemePackageMcpTransportError("theme_package_mcp_response_too_large")
            return int(response.status), dict(response.headers.items()), raw
    except ThemePackageMcpTransportError:
        raise
    except Exception as exc:
        # Never persist bearer, package bytes, header contents or URL.
        raise ThemePackageMcpTransportError("theme_package_mcp_transport_failed") from exc


def _decoded(status: int, headers: Mapping[str, str], body: bytes, request_id: int) -> dict[str, Any]:
    if status != 200 or len(body) > MAX_TOOL_RESPONSE_BYTES:
        raise ThemePackageMcpTransportError("theme_package_mcp_protocol_failed")
    content_type = next((v.lower() for k, v in headers.items() if k.lower() == "content-type"), "")
    if "application/json" in content_type:
        try:
            event = json.loads(body.decode("utf-8"))
        except (ValueError, UnicodeDecodeError) as exc:
            raise ThemePackageMcpTransportError("theme_package_mcp_invalid_json") from exc
        if not isinstance(event, dict):
            raise ThemePackageMcpTransportError("theme_package_mcp_invalid_json")
        responses = [event]
    elif "text/event-stream" in content_type:
        responses = []
        for event in body.decode("utf-8", errors="replace").split("\n\n"):
            data = [row[5:].strip() for row in event.splitlines() if row.startswith("data:")]
            if not data:
                continue
            try:
                record = json.loads("\n".join(data))
            except ValueError as exc:
                raise ThemePackageMcpTransportError("theme_package_mcp_invalid_sse") from exc
            if isinstance(record, dict):
                responses.append(record)
    else:
        raise ThemePackageMcpTransportError("theme_package_mcp_invalid_content_type")

    for event in responses:
        if event.get("jsonrpc") != "2.0" or event.get("id") != request_id:
            continue
        if "error" in event or not isinstance(event.get("result"), dict):
            raise ThemePackageMcpTransportError("theme_package_mcp_tool_refused")
        return event["result"]
    raise ThemePackageMcpTransportError("theme_package_mcp_response_missing")


class ThemePackageMcpPlanClient:
    def __init__(self, trusted_mcp_url: str, site_token: str,
                 wire: Callable[[str, Mapping[str, str], bytes],
                                tuple[int, dict[str, str], bytes]] = _wire) -> None:
        p = urlparse(trusted_mcp_url)
        if p.scheme != "https" or not p.netloc or p.username or p.password or p.fragment or p.query:
            raise ThemePackageMcpTransportError("theme_package_mcp_invalid_trusted_url")
        if not site_token:
            raise ThemePackageMcpTransportError("theme_package_mcp_auth_unavailable")
        self._url = trusted_mcp_url
        self._token = site_token
        self._wire = wire

    def stage_official_release(self, package_plan: dict[str, Any]) -> dict[str, Any]:
        required = {"theme_slug", "package_sha256", "package_base64",
                    "source_commit_sha", "source_run_id", "source_artifact_id"}
        if set(package_plan) != required:
            raise ThemePackageMcpTransportError("theme_package_mcp_untrusted_payload")
        if not isinstance(package_plan.get("package_base64"), str) or len(package_plan["package_base64"]) > 27_000_000:
            raise ThemePackageMcpTransportError("theme_package_mcp_untrusted_payload")

        base_headers = {
            "authorization": f"Bearer {self._token}",
            "content-type": "application/json",
            "accept": "application/json, text/event-stream",
        }
        init = {
            "jsonrpc": "2.0", "id": 1, "method": "initialize",
            "params": {
                "protocolVersion": PROTOCOL_VERSION,
                "capabilities": {},
                "clientInfo": {"name": "site-manager-theme-package-gateway", "version": "1.0.0"},
            },
        }
        init_status, init_headers, init_body = self._post(base_headers, init)
        initialized = _decoded(init_status, init_headers, init_body, 1)
        version = initialized.get("protocolVersion")
        if not isinstance(version, str) or version not in {"2025-03-26", "2025-06-18", "2025-11-25", "2026-07-28"}:
            raise ThemePackageMcpTransportError("theme_package_mcp_unsupported_protocol")
        headers = {**base_headers, "mcp-protocol-version": version}
        session_id = next((v for k, v in init_headers.items() if k.lower() == "mcp-session-id"), None)
        if session_id is not None:
            if not isinstance(session_id, str) or len(session_id) > 256 or not session_id.isascii():
                raise ThemePackageMcpTransportError("theme_package_mcp_invalid_session")
            headers["mcp-session-id"] = session_id

        note = {"jsonrpc": "2.0", "method": "notifications/initialized"}
        notify_status, _, notify_body = self._post(headers, note)
        if notify_status not in (200, 202) or len(notify_body) > MAX_TOOL_RESPONSE_BYTES:
            raise ThemePackageMcpTransportError("theme_package_mcp_initialize_failed")

        args = {
            "jsonrpc": "2.0", "id": 2, "method": "tools/call",
            "params": {"name": "theme_package_plan", "arguments": package_plan},
        }
        status, response_headers, raw = self._post(headers, args)
        result = _decoded(status, response_headers, raw, 2)
        if result.get("isError") is True:
            raise ThemePackageMcpTransportError("theme_package_mcp_tool_refused")
        structured = result.get("structuredContent")
        if not isinstance(structured, dict) or not isinstance(structured.get("data"), dict):
            raise ThemePackageMcpTransportError("theme_package_mcp_unverified_response")
        data = structured["data"]
        # Never copy full MCP tool content or opaque errors to GitHub.
        if data.get("stage") != "plan" or data.get("status") != "planned":
            raise ThemePackageMcpTransportError("theme_package_mcp_not_planned")
        return data

    def _post(self, headers: Mapping[str, str], document: dict[str, Any]) -> tuple[int, dict[str, str], bytes]:
        payload = json.dumps(document, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
        if len(payload) > MAX_TOOL_REQUEST_BYTES:
            raise ThemePackageMcpTransportError("theme_package_mcp_payload_too_large")
        return self._wire(self._url, headers, payload)
