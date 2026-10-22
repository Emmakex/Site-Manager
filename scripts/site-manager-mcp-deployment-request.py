#!/usr/bin/env python3
"""Validate and replay-guard bounded Site Manager MCP deployment requests."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import tempfile
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_RESULTS = ROOT / "deployments" / "site-manager-mcp" / "results"
DEPLOYMENT_ID_RE = re.compile(r"^deploy-[a-z0-9][a-z0-9-]{7,63}$")
SITE_RE = re.compile(r"^[a-z0-9][a-z0-9-]{2,63}$")
IMAGE_RE = re.compile(r"^ghcr\.io/emmakex/site-manager-mcp@sha256:[0-9a-f]{64}$")


class RequestError(RuntimeError):
    pass


def load(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RequestError(f"invalid deployment request: {path}") from exc
    if not isinstance(value, dict):
        raise RequestError("deployment request must be a JSON object")
    return value


def validate(value: dict[str, Any]) -> None:
    required = {"version", "deployment_id", "site", "action", "image_ref", "requested_by"}
    if set(value) != required:
        raise RequestError("deployment request keys do not match v1 contract")
    if value.get("version") != "1":
        raise RequestError("unsupported deployment request version")
    deployment_id = value.get("deployment_id")
    site = value.get("site")
    action = value.get("action")
    requested_by = value.get("requested_by")
    image_ref = value.get("image_ref")
    if not isinstance(deployment_id, str) or DEPLOYMENT_ID_RE.fullmatch(deployment_id) is None:
        raise RequestError("invalid deployment_id")
    if not isinstance(site, str) or SITE_RE.fullmatch(site) is None:
        raise RequestError("invalid site id")
    if action not in {"deploy", "rollback"}:
        raise RequestError("action must be deploy or rollback")
    if requested_by not in {"chatgpt", "operator"}:
        raise RequestError("requested_by is invalid")
    if action == "deploy":
        if not isinstance(image_ref, str) or IMAGE_RE.fullmatch(image_ref) is None:
            raise RequestError("deploy requires an immutable Site Manager MCP image digest")
    elif image_ref is not None:
        raise RequestError("rollback must set image_ref to null")


def digest(value: dict[str, Any]) -> str:
    raw = json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


def emit(key: str, value: str) -> None:
    output = os.environ.get("GITHUB_OUTPUT", "").strip()
    if output:
        with open(output, "a", encoding="utf-8") as handle:
            handle.write(f"{key}={value}\n")


def guard(path: Path, result_dir: Path) -> int:
    request = load(path)
    validate(request)
    fingerprint = digest(request)
    result_path = result_dir / f"{request['deployment_id']}.json"
    emit("id", request["deployment_id"])
    emit("site", request["site"])
    emit("action", request["action"])
    emit("image_ref", request["image_ref"] or "")
    emit("request_sha256", fingerprint)

    if not result_path.exists():
        emit("decision", "new")
        print(f"NEW_DEPLOYMENT {request['deployment_id']}")
        return 0

    result = load(result_path)
    for key in ("deployment_id", "site", "action"):
        if result.get(key) != request.get(key):
            raise RequestError(f"existing deployment result {key} does not match request")
    if result.get("request_sha256") != fingerprint:
        raise RequestError("deployment_id collision with a different request fingerprint")
    if result.get("status") == "success":
        emit("decision", "replay_success")
        print(f"ALREADY_COMPLETED {result_path}")
        return 0
    raise RequestError("deployment request already has a non-success result; use a new deployment_id")


def self_test() -> int:
    request = {
        "version": "1",
        "deployment_id": "deploy-selftest-0001",
        "site": "example-site",
        "action": "deploy",
        "image_ref": "ghcr.io/emmakex/site-manager-mcp@sha256:" + "a" * 64,
        "requested_by": "chatgpt",
    }
    validate(request)
    rollback = dict(request)
    rollback["deployment_id"] = "deploy-selftest-0002"
    rollback["action"] = "rollback"
    rollback["image_ref"] = None
    validate(rollback)
    bad = dict(request)
    bad["token"] = "forbidden"
    try:
        validate(bad)
    except RequestError:
        pass
    else:
        raise RequestError("arbitrary deployment fields were accepted")
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        path = root / "request.json"
        results = root / "results"
        path.write_text(json.dumps(request), encoding="utf-8")
        guard(path, results)
        results.mkdir()
        result = {
            "version": "1",
            "status": "success",
            "deployment_id": request["deployment_id"],
            "site": request["site"],
            "action": request["action"],
            "request_sha256": digest(request),
        }
        (results / f"{request['deployment_id']}.json").write_text(json.dumps(result), encoding="utf-8")
        guard(path, results)
    print("site-manager-mcp-deployment-request self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="mode", required=True)
    guard_p = sub.add_parser("guard")
    guard_p.add_argument("request", type=Path)
    guard_p.add_argument("--result-dir", type=Path, default=DEFAULT_RESULTS)
    sub.add_parser("self-test")
    args = parser.parse_args()
    try:
        if args.mode == "guard":
            return guard(args.request, args.result_dir)
        return self_test()
    except RequestError as exc:
        print(f"ERROR: {exc}", file=os.sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
