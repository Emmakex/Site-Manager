#!/usr/bin/env python3
"""SM-4K: one read-only, protected-environment connection bootstrap.

The normal Gateway deliberately rejects unenrolled sites. This separate,
manually dispatched and protected workflow can establish a new site's first
canonical site.overview proof without relaxing that guard. Its only MCP call
is the fixed site_read tool with no arguments.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
from typing import Any, Callable

from site_manager_site_catalog import SITE_ID_RE, _validate_url
from site_manager_site_overview import OverviewError, summarize_site_overview

ROOT = Path(__file__).resolve().parents[1]
RESULTS = ROOT / "commands" / "site-manager" / "results"
RUN_ID_RE = re.compile(r"^[1-9][0-9]{5,19}$")
CONNECTION_RE = re.compile(r"^smc_[a-zA-Z0-9_-]{12,128}$")
BOOTSTRAP_GENERATION = "sm-4k-bootstrap-v1"
BOOTSTRAP_KEYS = frozenset({
    "source", "workflow_run_id", "expected_site_url_verified",
    "expected_connection_id_verified", "read_only",
})


class BootstrapError(ValueError):
    """Only safe, generic errors are displayed by the trusted workflow."""


def _load_mcp_support() -> Any:
    """Reuse the accepted MCP inspector transport without copying credentials."""
    path = ROOT / "scripts" / "site-manager-command-runner.py"
    spec = importlib.util.spec_from_file_location("sm4k_readonly_mcp", path)
    if spec is None or spec.loader is None:
        raise BootstrapError("MCP transport is unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def validate_bootstrap_config(
    *, requested_site: Any, environment_site: Any, expected_site_url: Any,
    expected_connection_id: Any, mcp_url: Any, token: Any, run_id: Any,
) -> dict[str, str]:
    if (not isinstance(requested_site, str)
            or SITE_ID_RE.fullmatch(requested_site) is None):
        raise BootstrapError("invalid requested site ID")
    if not isinstance(environment_site, str) or environment_site != requested_site:
        raise BootstrapError("protected Environment is not bound to the requested site")
    if not isinstance(run_id, str) or RUN_ID_RE.fullmatch(run_id) is None:
        raise BootstrapError("invalid GitHub workflow run identity")
    if not isinstance(expected_site_url, str) or not expected_site_url:
        raise BootstrapError("expected WordPress site URL must be provisioned")
    try:
        clean_site_url = _validate_url(expected_site_url)
    except ValueError as exc:
        raise BootstrapError("expected WordPress site URL is unsafe") from exc
    if (not isinstance(expected_connection_id, str)
            or CONNECTION_RE.fullmatch(expected_connection_id) is None):
        raise BootstrapError("expected WordPress connection ID must be provisioned")
    # The MCP transport URI comes exclusively from the existing protected
    # Environment; it cannot be selected in the GitHub dispatch request.
    if not isinstance(mcp_url, str) or not mcp_url:
        raise BootstrapError("protected Site Manager MCP URL is missing")
    if not isinstance(token, str) or not token.strip():
        raise BootstrapError("protected Site Manager MCP token is missing")
    runner = _load_mcp_support()
    try:
        clean_mcp_url = runner.validate_mcp_url(mcp_url)
    except runner.CommandError as exc:
        raise BootstrapError("protected Site Manager MCP URL is invalid") from exc
    return {
        "site": requested_site,
        "run_id": run_id,
        "expected_site_url": clean_site_url,
        "expected_connection_id": expected_connection_id,
        "mcp_url": clean_mcp_url,
        "token": token.strip(),
    }


def normalize_bootstrap_evidence(
    raw_site_read: Any, *, requested_site: str, run_id: str,
    expected_site_url: str, expected_connection_id: str,
) -> dict[str, Any]:
    """Fail closed on wrong tenant, wrong WordPress installation or weak proof."""
    try:
        overview = summarize_site_overview(raw_site_read)
    except OverviewError as exc:
        raise BootstrapError("Site Manager did not return valid live discovery") from exc
    if overview.get("platform", {}).get("name") != "wordpress":
        raise BootstrapError("the requested connection is not a WordPress installation")
    if not isinstance(overview.get("wordpress"), dict) or not overview["wordpress"].get("site_manager_version"):
        raise BootstrapError("Site Manager WordPress plugin is not verified")
    if not isinstance(overview.get("site_url"), str):
        raise BootstrapError("WordPress did not report a valid HTTPS site URL")
    if overview["site_url"].rstrip("/") != expected_site_url.rstrip("/"):
        raise BootstrapError("WordPress site URL differs from protected Environment expectation")
    if overview.get("connection_id") != expected_connection_id:
        raise BootstrapError("WordPress connection identity differs from protected Environment")
    if (not isinstance(overview.get("discovery_fingerprint"), str)
            or not re.fullmatch(r"[a-f0-9]{64}", overview["discovery_fingerprint"])):
        raise BootstrapError("WordPress discovery fingerprint is invalid")
    caps = overview.get("capabilities")
    if not isinstance(caps, list) or not any(
        isinstance(item, dict) and item.get("id") == "site.read"
        and item.get("state") == "governable"
        and "site.read" in item.get("required_scopes", [])
        for item in caps
    ):
        raise BootstrapError("WordPress did not report an accepted site.read capability")
    command_id = "cmd-bootstrap-" + run_id
    command_envelope = {
        "version": "1", "command_id": command_id, "site": requested_site,
        "action": "site.overview", "requested_by": "site-manager-bootstrap", "payload": {},
    }
    digest = hashlib.sha256(
        (json.dumps(command_envelope, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")
    ).hexdigest()
    return {
        "version": "1",
        "command_id": command_id,
        "command_sha256": digest,
        "site": requested_site,
        "action": "site.overview",
        "stage": "read",
        "status": "success",
        "verified": True,
        "required_scope": "site.read",
        "gateway_generation": BOOTSTRAP_GENERATION,
        "bootstrap": {
            "source": "protected-environment-bootstrap",
            "workflow_run_id": run_id,
            "expected_site_url_verified": True,
            "expected_connection_id_verified": True,
            "read_only": True,
        },
        "data": overview,
    }


def prove_connection(
    *, requested_site: str, run_id: str, environment_site: str,
    expected_site_url: str, expected_connection_id: str,
    mcp_url: str, token: str, result_dir: Path = RESULTS,
    client_factory: Callable[[str, str], Any] | None = None,
) -> Path:
    config = validate_bootstrap_config(
        requested_site=requested_site, run_id=run_id,
        environment_site=environment_site,
        expected_site_url=expected_site_url,
        expected_connection_id=expected_connection_id,
        mcp_url=mcp_url, token=token,
    )
    if client_factory is None:
        client_factory = _load_mcp_support().McpClient

    result_dir.mkdir(parents=True, exist_ok=True)
    target = result_dir / ("cmd-bootstrap-" + config["run_id"] + ".json")
    if target.exists():
        raise BootstrapError("bootstrap workflow identity has already produced a result")

    client = None
    try:
        client = client_factory(config["mcp_url"], config["token"])
        client.initialize()
        # **THE ONLY EXTERNAL MCP TOOL CALLED BY BOOTSTRAP.**
        raw = client.call("site_read", {})
        record = normalize_bootstrap_evidence(
            raw, requested_site=config["site"], run_id=config["run_id"],
            expected_site_url=config["expected_site_url"],
            expected_connection_id=config["expected_connection_id"],
        )
        # The overview is already a closed non-secret projection. Reuse
        # the existing Gateway sanitizer as a final defense in depth.
        safe = _load_mcp_support().sanitize_value(record, config["token"])
        serialized = json.dumps(safe, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
        if len(serialized.encode("utf-8")) > 48_000:
            raise BootstrapError("bootstrap proof exceeds the bounded evidence size")
        with target.open("x", encoding="utf-8") as file:
            file.write(serialized)
    except FileExistsError as exc:
        raise BootstrapError("duplicate bootstrap run refused") from exc
    finally:
        if client is not None:
            client.close()
    return target


def main() -> int:
    parser = argparse.ArgumentParser(description="Protected read-only Site Manager site bootstrap")
    parser.add_argument("--site", required=True)
    parser.add_argument("--run-id", required=True)
    args = parser.parse_args()
    try:
        result = prove_connection(
            requested_site=args.site,
            run_id=args.run_id,
            environment_site=os.environ.get("SITE_MANAGER_SITE_ID", ""),
            expected_site_url=os.environ.get("SITE_MANAGER_EXPECTED_SITE_URL", ""),
            expected_connection_id=os.environ.get("SITE_MANAGER_EXPECTED_CONNECTION_ID", ""),
            mcp_url=os.environ.get("SITE_MANAGER_MCP_BASE_URL", ""),
            token=os.environ.get("SITE_MANAGER_MCP_TOKEN", ""),
        )
    except (BootstrapError, OSError, ValueError):
        # Never print raw MCP tool results or errors; they might include
        # sensitive WordPress/provider details or a bearer token.
        parser.exit(2, "Site Manager bootstrap refused: protected connection, identity or live read failed\n")
    print("Site Manager read-only enrollment proof generated: " + result.name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
