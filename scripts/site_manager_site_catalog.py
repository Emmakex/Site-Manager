#!/usr/bin/env python3
"""Operator-maintained, proof-backed logical-site catalog for Site Manager.

The catalog is deliberately NOT a list of GitHub Environments or a permission
grant. It contains only operator-approved site IDs, labels and the IDs of
previously verified `site.overview` results. A selected site must still pass
the protected GitHub Environment + live Site Manager check on each operation.
"""
from __future__ import annotations

import argparse
from datetime import datetime
import json
from pathlib import Path
import re
from typing import Any
from urllib.parse import urlsplit, urlunsplit

ROOT = Path(__file__).resolve().parents[1]
CATALOG_PATH = ROOT / "config" / "site-manager-site-catalog.json"
RESULT_DIR = ROOT / "commands" / "site-manager" / "results"

SITE_ID_RE = re.compile(r"^[a-z0-9][a-z0-9-]{2,63}$")
COMMAND_ID_RE = re.compile(r"^cmd-[a-z0-9][a-z0-9-]{7,63}$")
SHA256_RE = re.compile(r"^[a-f0-9]{64}$")
MAX_SITES = 100


class SiteCatalogError(ValueError):
    """The requested logical site has no trustworthy catalog enrollment."""


def _read_json(path: Path) -> dict[str, Any]:
    try:
        result = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise SiteCatalogError("site catalog or its proof is missing or invalid") from exc
    if not isinstance(result, dict):
        raise SiteCatalogError("site catalog or its proof must be a JSON object")
    return result


def _validate_url(value: Any) -> str:
    if not isinstance(value, str) or len(value) > 2048:
        raise SiteCatalogError("site overview has no bounded HTTPS homepage")
    try:
        parsed = urlsplit(value)
        if (parsed.scheme != "https" or not parsed.hostname or parsed.username
                or parsed.password or parsed.query or parsed.fragment
                or parsed.port is not None and not (1 <= parsed.port <= 65535)
                or any(ord(ch) < 32 for ch in value)):
            raise SiteCatalogError("site overview homepage is not a safe HTTPS URL")
        return urlunsplit((parsed.scheme, parsed.netloc, parsed.path, "", ""))
    except ValueError as exc:
        raise SiteCatalogError("site overview homepage has invalid host or port") from exc


def _validate_timestamp(value: Any) -> str:
    if not isinstance(value, str) or len(value) > 80:
        raise SiteCatalogError("site overview has no bounded verification timestamp")
    try:
        dt = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise SiteCatalogError("site overview has malformed verification timestamp") from exc
    if dt.tzinfo is None or dt.utcoffset() is None:
        raise SiteCatalogError("site overview verification timestamp must include its offset")
    return value


def load_verified_catalog(
    catalog_path: Path = CATALOG_PATH, result_dir: Path = RESULT_DIR
) -> list[dict[str, Any]]:
    """Fail closed when enrollment/proof is missing, ambiguous, or mismatched.

    A verified historical read proves past successful operation in a protected
    site environment, NOT that it exists or is currently reachable today.
    """
    catalog = _read_json(catalog_path)
    entries = catalog.get("sites")
    if (set(catalog) != {"version", "sites"} or catalog.get("version") != "1"
            or not isinstance(entries, list) or not 1 <= len(entries) <= MAX_SITES):
        raise SiteCatalogError("site catalog must be a bounded version-1 enrollment list")

    sites: list[dict[str, Any]] = []
    ids: set[str] = set()
    labels: set[str] = set()
    proofs: set[str] = set()
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {"site", "label", "proof_command_id"}:
            raise SiteCatalogError("site enrollment must contain only a site ID, label and proof ID")
        site_id, label, proof_id = (entry[k] for k in ("site", "label", "proof_command_id"))
        if not isinstance(site_id, str) or not SITE_ID_RE.fullmatch(site_id):
            raise SiteCatalogError("enrolled logical site ID is invalid")
        if (not isinstance(label, str) or not 1 <= len(label) <= 96
                or label != label.strip() or any(ord(c) < 32 for c in label)):
            raise SiteCatalogError("site label is not a safe, short display name")
        if not isinstance(proof_id, str) or not COMMAND_ID_RE.fullmatch(proof_id):
            raise SiteCatalogError("site enrollment proof ID is invalid")
        if site_id in ids or label.casefold() in labels or proof_id in proofs:
            raise SiteCatalogError("site enrollment identity, label or proof must be unique")
        ids.add(site_id)
        labels.add(label.casefold())
        proofs.add(proof_id)

        # Path is derived ONLY from a strictly validated command ID: no user path.
        proof = _read_json(result_dir / f"{proof_id}.json")
        if (proof.get("command_id") != proof_id
                or proof.get("site") != site_id
                or proof.get("action") != "site.overview"
                or proof.get("stage") != "read"
                or proof.get("status") != "success"
                or proof.get("verified") is not True
                or proof.get("required_scope") != "site.read"
                or not isinstance(proof.get("command_sha256"), str)
                or SHA256_RE.fullmatch(proof["command_sha256"]) is None):
            raise SiteCatalogError("site enrollment proof is not a verified matching site.overview")
        bootstrap = proof.get("bootstrap")
        if bootstrap is not None:
            if (not isinstance(bootstrap, dict)
                    or set(bootstrap) != {
                        "source", "workflow_run_id", "expected_site_url_verified",
                        "expected_connection_id_verified", "read_only",
                    }
                    or bootstrap.get("source") != "protected-environment-bootstrap"
                    or bootstrap.get("expected_site_url_verified") is not True
                    or bootstrap.get("expected_connection_id_verified") is not True
                    or bootstrap.get("read_only") is not True
                    or proof.get("gateway_generation") != "sm-4k-bootstrap-v1"
                    or not isinstance(bootstrap.get("workflow_run_id"), str)
                    or re.fullmatch(r"[1-9][0-9]{5,19}", bootstrap["workflow_run_id"]) is None
                    or proof_id != "cmd-bootstrap-" + bootstrap["workflow_run_id"]):
                raise SiteCatalogError("protected enrollment bootstrap proof is malformed or mismatched")
        elif proof_id.startswith("cmd-bootstrap-"):
            raise SiteCatalogError("bootstrap enrollment lacks protected run evidence")

        data = proof.get("data")
        if (not isinstance(data, dict) or data.get("source") != "live-site-read"
                or not isinstance(data.get("connection_id"), str)
                or not 1 <= len(data["connection_id"]) <= 128
                or not isinstance(data.get("discovery_fingerprint"), str)
                or not 1 <= len(data["discovery_fingerprint"]) <= 128):
            raise SiteCatalogError("site enrollment lacks WordPress connection/discovery evidence")
        home = _validate_url(data.get("site_url"))
        timestamp = _validate_timestamp(data.get("generated_at"))
        sites.append({
            "site": site_id,
            "label": label,
            "site_url": home,
            "last_verified_at": timestamp,
            "proof_command_id": proof_id,
            "status": "previously_verified",
            "live_status": "not_checked",
            "write_scope_grants_verified": False,
        })

    return sorted(sites, key=lambda item: (item["label"].casefold(), item["site"]))


def require_registered_site(
    site_id: str, catalog_path: Path = CATALOG_PATH, result_dir: Path = RESULT_DIR
) -> dict[str, Any]:
    """Enforce enrollment BEFORE the Gateway selects a protected Environment."""
    if not isinstance(site_id, str) or not SITE_ID_RE.fullmatch(site_id):
        raise SiteCatalogError("invalid logical site ID")
    for site in load_verified_catalog(catalog_path, result_dir):
        if site["site"] == site_id:
            return site
    raise SiteCatalogError("logical site is not enrolled in the trusted Site Manager catalog")


def catalog_snapshot(
    catalog_path: Path = CATALOG_PATH, result_dir: Path = RESULT_DIR
) -> dict[str, Any]:
    """Safe operator UI/readback; no secret environment fields or live claims."""
    sites = load_verified_catalog(catalog_path, result_dir)
    return {
        "version": "1",
        "source": "verified-site-catalog",
        "sites": sites,
        "count": len(sites),
        "live_connectivity_verified": False,
        "write_authorization_verified": False,
        "note": (
            "Only past verified Site Manager connections are listed. Before "
            "any site-specific plan or mutation, use a new site.overview "
            "command under that site's protected GitHub Environment. "
            "Planning does not grant write access; applying requires "
            "separate explicit confirmation and WordPress verification."
        ),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Proof-backed Site Manager logical-site catalog")
    parser.add_argument("action", choices=("list", "check"))
    parser.add_argument("--site", default=None)
    args = parser.parse_args()
    try:
        if args.action == "list" and args.site is None:
            print(json.dumps(catalog_snapshot(), ensure_ascii=False, indent=2, sort_keys=True))
        elif args.action == "check" and args.site is not None:
            site = require_registered_site(args.site)
            print(f"Registered Site Manager site: {site['site']}; fresh live verification still required")
        else:
            raise SiteCatalogError("list takes no site; check requires --site")
    except SiteCatalogError as exc:
        parser.exit(2, f"Site Manager catalog refused request: {exc}\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
