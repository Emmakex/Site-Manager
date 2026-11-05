#!/usr/bin/env python3
"""SM-4J: prove enrollment isolation, provenance and two-site catalog behavior."""
from __future__ import annotations

import copy
import json
from pathlib import Path
import sys
from tempfile import TemporaryDirectory

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from site_manager_site_catalog import (  # noqa: E402
    SiteCatalogError, catalog_snapshot, load_verified_catalog, require_registered_site,
)


def proof(site: str, command_id: str) -> dict:
    return {
        "version": "1",
        "action": "site.overview",
        "stage": "read",
        "site": site,
        "command_id": command_id,
        "command_sha256": "a" * 64,
        "status": "success",
        "verified": True,
        "required_scope": "site.read",
        "data": {
            "source": "live-site-read",
            "site_url": f"https://{site}.example.test/",
            "connection_id": "smc_private_connection_id",
            "discovery_fingerprint": "b" * 64,
            "generated_at": "2026-11-05T18:00:00+00:00",
            "private_post": "PRIVATE_POST_CONTENT",
            "bearer_token": "PRIVATE_SECRET_TOKEN",
        },
    }


def write_json(path: Path, value: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2) + "\n", encoding="utf-8")


def rejected(catalog_path: Path, results: Path, expected: str | None = None) -> None:
    try:
        load_verified_catalog(catalog_path, results)
    except SiteCatalogError as exc:
        if expected is not None and expected not in str(exc):
            raise AssertionError(f"expected {expected!r} in error, got {exc!r}") from exc
    else:
        raise AssertionError("unverified, ambiguous or unsafe site enrollment was accepted")


with TemporaryDirectory(prefix="site-manager-catalog-") as folder:
    directory = Path(folder)
    catalog = directory / "catalog.json"
    results = directory / "results"
    entries = [
        {"site": "demo-two", "label": "Zeta client", "proof_command_id": "cmd-demo-two-verified-0001"},
        {"site": "demo-one", "label": "Alpha client", "proof_command_id": "cmd-demo-one-verified-0001"},
    ]
    proofs = {item["site"]: proof(item["site"], item["proof_command_id"]) for item in entries}
    write_json(catalog, {"version": "1", "sites": entries})
    for item in entries:
        write_json(results / (item["proof_command_id"] + ".json"), proofs[item["site"]])

    sites = load_verified_catalog(catalog, results)
    assert [item["site"] for item in sites] == ["demo-one", "demo-two"]
    snapshot = catalog_snapshot(catalog, results)
    assert snapshot["count"] == 2
    assert snapshot["live_connectivity_verified"] is False
    assert snapshot["write_authorization_verified"] is False
    assert all(s["write_scope_grants_verified"] is False and s["live_status"] == "not_checked"
               and s["status"] == "previously_verified" for s in snapshot["sites"])
    assert require_registered_site("demo-one", catalog, results)["label"] == "Alpha client"
    try:
        require_registered_site("non-enrolled", catalog, results)
    except SiteCatalogError as exc:
        assert "not enrolled" in str(exc)
    else:
        raise AssertionError("unknown site was allowed to request a GitHub Environment")
    string_snapshot = json.dumps(snapshot)
    for secret in ("PRIVATE_POST_CONTENT", "PRIVATE_SECRET_TOKEN", "smc_private_connection_id"):
        assert secret not in string_snapshot

    # No new enrollment without a previous exact-site, exact-command verified read.
    malformed_catalogs = [
        {"version": "1", "sites": []},
        {"version": "2", "sites": entries},
        {"version": "1", "sites": [entries[0], entries[0]]},
        {"version": "1", "sites": [entries[0], {**entries[1], "label": "zeta client"}]},
        {"version": "1", "sites": [{**entries[0], "site": "../another-tenant"}]},
        {"version": "1", "sites": [{**entries[0], "proof_command_id": "../../secret"}]},
        {"version": "1", "sites": [{**entries[0], "mcp_url": "https://attacker.example"}]},
        {"version": "1", "sites": [{**entries[0], "label": "  Zeta client  "}]},
        {"version": "1", "sites": entries, "environment_token": "secret"},
    ]
    for invalid in malformed_catalogs:
        write_json(catalog, invalid)
        rejected(catalog, results)

    write_json(catalog, {"version": "1", "sites": entries})
    path = results / "cmd-demo-one-verified-0001.json"
    bad_proofs = [
        lambda x: x.update(site="demo-two"),
        lambda x: x.update(action="site.read"),
        lambda x: x.update(stage="apply"),
        lambda x: x.update(verified=False),
        lambda x: x.update(status="failed"),
        lambda x: x.update(required_scope="seo.write"),
        lambda x: x.update(command_id="cmd-unrelated"),
        lambda x: x.update(command_sha256="bad-digest"),
        lambda x: x["data"].update(source="client-supplied"),
        lambda x: x["data"].update(connection_id=""),
        lambda x: x["data"].update(site_url="http://demo-one.example.test/"),
        lambda x: x["data"].update(site_url="https://user:secret@demo-one.example.test/"),
        lambda x: x["data"].update(site_url="https://demo-one.example.test/?secret=yes"),
        lambda x: x["data"].update(generated_at="2026-11-05"),
        lambda x: x["data"].update(discovery_fingerprint=None),
    ]
    for mutate in bad_proofs:
        invalid = copy.deepcopy(proofs["demo-one"])
        mutate(invalid)
        write_json(path, invalid)
        rejected(catalog, results)

    path.unlink()
    rejected(catalog, results, "missing or invalid")

    # Restore both synthetic clients; verify original trust boundaries.
    write_json(path, proofs["demo-one"])
    assert len(load_verified_catalog(catalog, results)) == 2

# The actual first enrolled site must also have persisted read-only proof.
real = catalog_snapshot()
assert real["count"] >= 1
assert all(s["site_url"].startswith("https://") for s in real["sites"])
assert all(s["live_status"] == "not_checked" for s in real["sites"])

print("site_manager_site_catalog_test: OK")
