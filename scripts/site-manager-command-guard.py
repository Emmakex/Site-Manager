#!/usr/bin/env python3
"""Replay/idempotency guard for the Site Manager GitHub Command Gateway."""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
RUNNER_PATH = Path(__file__).with_name("site-manager-command-runner.py")
DEFAULT_RESULT_DIR = ROOT / "commands" / "site-manager" / "results"

spec = importlib.util.spec_from_file_location("site_manager_command_runner", RUNNER_PATH)
if spec is None or spec.loader is None:
    raise SystemExit("unable to load Site Manager command runner")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class GuardError(RuntimeError):
    """Fail-closed replay guard error."""


def emit_output(key: str, value: str) -> None:
    output = os.environ.get("GITHUB_OUTPUT", "").strip()
    if not output:
        return
    with open(output, "a", encoding="utf-8") as handle:
        handle.write(f"{key}={value}\n")


def load_command(path: Path) -> dict[str, Any]:
    registry = runner.load_json(runner.REGISTRY_PATH)
    runner.validate_registry(registry)
    command = runner.load_json(path)
    runner.validate_command(command, registry)
    return command


def command_sha256(command: dict[str, Any]) -> str:
    canonical = json.dumps(
        command,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")
    return hashlib.sha256(canonical).hexdigest()


def result_path(command: dict[str, Any], result_dir: Path) -> Path:
    return result_dir / f"{command['command_id']}.json"


def check_replay(command_path: Path, result_dir: Path) -> int:
    command = load_command(command_path)
    digest = command_sha256(command)
    path = result_path(command, result_dir)
    emit_output("command_sha256", digest)

    if not path.exists():
        emit_output("decision", "new")
        print(f"NEW_COMMAND {command['command_id']}")
        return 0

    result = runner.load_json(path)
    for field in ("command_id", "site", "action"):
        if result.get(field) != command.get(field):
            raise GuardError(f"existing result {field} does not match the command")

    recorded = result.get("command_sha256")
    if not isinstance(recorded, str) or len(recorded) != 64:
        raise GuardError("existing result lacks a valid command fingerprint; refusing ambiguous replay")
    if recorded != digest:
        raise GuardError("command_id collision: existing result fingerprint differs from current command")

    status = result.get("status")
    if status == "success":
        emit_output("decision", "replay_success")
        print(f"ALREADY_COMPLETED {path}")
        return 0
    if status == "failed":
        emit_output("decision", "replay_failed")
        raise GuardError("command already completed with failed status; use a new command_id")
    raise GuardError("existing result has an unsupported status")


def stamp_result(command_path: Path, result_dir: Path) -> int:
    command = load_command(command_path)
    digest = command_sha256(command)
    path = result_path(command, result_dir)
    if not path.is_file():
        raise GuardError("execution produced no sanitized result to fingerprint")

    result = runner.load_json(path)
    for field in ("command_id", "site", "action"):
        if result.get(field) != command.get(field):
            raise GuardError(f"result {field} does not match the command")

    existing = result.get("command_sha256")
    if existing is not None and existing != digest:
        raise GuardError("refusing to overwrite a different command fingerprint")

    result["command_sha256"] = digest
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(
        json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    os.replace(tmp, path)
    print(f"FINGERPRINTED_RESULT {path}")
    return 0


def self_test() -> int:
    command = {
        "version": "1",
        "command_id": "cmd-guard-selftest-0001",
        "site": "acceptance-site",
        "action": "site.read",
        "requested_by": "chatgpt",
        "payload": {},
    }

    import tempfile

    with tempfile.TemporaryDirectory(prefix="site-manager-guard-") as temp:
        root = Path(temp)
        command_path = root / "command.json"
        result_dir = root / "results"
        command_path.write_text(json.dumps(command), encoding="utf-8")

        if check_replay(command_path, result_dir) != 0:
            raise GuardError("self-test: new command was not accepted")

        result_dir.mkdir(parents=True)
        result = {
            "version": "1",
            "status": "success",
            "site": command["site"],
            "action": command["action"],
            "command_id": command["command_id"],
            "verified": True,
            "data": {"ok": True},
        }
        result_path(command, result_dir).write_text(json.dumps(result), encoding="utf-8")
        stamp_result(command_path, result_dir)
        if check_replay(command_path, result_dir) != 0:
            raise GuardError("self-test: exact successful replay was not idempotent")

        failed_command = dict(command)
        failed_command["command_id"] = "cmd-guard-selftest-0002"
        command_path.write_text(json.dumps(failed_command), encoding="utf-8")
        failed_result = {
            "version": "1",
            "status": "failed",
            "site": failed_command["site"],
            "action": failed_command["action"],
            "command_id": failed_command["command_id"],
            "verified": False,
        }
        result_path(failed_command, result_dir).write_text(json.dumps(failed_result), encoding="utf-8")
        stamp_result(command_path, result_dir)
        try:
            check_replay(command_path, result_dir)
        except GuardError as exc:
            if "failed status" not in str(exc):
                raise
        else:
            raise GuardError("self-test: failed command replay did not fail closed")

    print("site-manager-command-guard self-test: OK")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="mode", required=True)

    guard = sub.add_parser("guard")
    guard.add_argument("command", type=Path)
    guard.add_argument("--result-dir", type=Path, default=DEFAULT_RESULT_DIR)

    stamp = sub.add_parser("stamp")
    stamp.add_argument("command", type=Path)
    stamp.add_argument("--result-dir", type=Path, default=DEFAULT_RESULT_DIR)

    sub.add_parser("self-test")
    args = parser.parse_args()

    try:
        if args.mode == "guard":
            return check_replay(args.command, args.result_dir)
        if args.mode == "stamp":
            return stamp_result(args.command, args.result_dir)
        if args.mode == "self-test":
            return self_test()
        raise GuardError("unsupported mode")
    except (GuardError, runner.CommandError) as exc:
        print(f"ERROR: {exc}", file=os.sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
