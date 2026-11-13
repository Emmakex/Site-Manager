#!/usr/bin/env python3
"""Safety acceptance for official immutable theme release provenance."""
from __future__ import annotations

from copy import deepcopy
from hashlib import sha256
from io import BytesIO
from pathlib import Path
import os
import sys
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from site_manager_theme_release_provenance import (  # noqa: E402
    ThemeReleasePolicy,
    ThemePackageProvenanceError,
    extract_pinned_theme_zip,
    verify_release_reference,
)


def expect_reject(fn, code: str):
    try:
        fn()
    except ThemePackageProvenanceError as exc:
        assert str(exc) == code, (str(exc), code)
        return
    raise AssertionError(f"Expected failure {code}")


policy = ThemeReleasePolicy(
    repository="SiteOwner/official-theme",
    workflow_path=".github/workflows/release-artifact.yml",
)
expected_sha = "9" * 40  # Git commit SHA is 40 hex chars; archive digest is 64.
# A Git commit is intentionally a 40-character object ID: the contract must
# not conflate it with a SHA-256 release archive digest.
run = {
    "id": 42,
    "event": "push",
    "head_branch": "main",
    "head_sha": expected_sha,
    "path": ".github/workflows/release-artifact.yml@main",
    "status": "completed",
    "conclusion": "success",
    "repository": {"full_name": "SiteOwner/official-theme"},
    "head_repository": {"full_name": "SiteOwner/official-theme"},
}
artifact = {
    "id": 77,
    "name": "seo-geo-theme-release-candidate",
    "expired": False,
    "size_in_bytes": 10_000,
    "workflow_run": {"id": 42, "head_sha": expected_sha},
}


def verify(r=run, a=artifact, pol=policy):
    return verify_release_reference(
        policy=pol, run=r, artifact=a, expected_commit_sha=expected_sha,
        expected_run_id=42, expected_artifact_id=77,
    )


proof = verify()
assert proof["repository"] == policy.repository
assert proof["artifact_id"] == 77
assert "download_url" not in proof

for field, value, failure in [
    ("event", "pull_request", "untrusted_release_branch"),
    ("head_branch", "testing", "untrusted_release_branch"),
    ("status", "in_progress", "release_checks_incomplete"),
    ("conclusion", "failure", "release_checks_incomplete"),
    ("path", ".github/workflows/untrusted.yml@main", "untrusted_release_workflow"),
    ("head_sha", "1" * 40, "stale_release_run"),
]:
    sample = deepcopy(run)
    sample[field] = value
    expect_reject(lambda s=sample: verify(r=s), failure)
other = deepcopy(run)
other["repository"]["full_name"] = "Attacker/official-theme"
expect_reject(lambda: verify(r=other), "untrusted_release_repository")
forked = deepcopy(run)
forked["head_repository"]["full_name"] = "Attacker/fork"
expect_reject(lambda: verify(r=forked), "untrusted_head_repository")
for field, value, failure in [
    ("id", 88, "wrong_release_artifact"),
    ("name", "random-build", "wrong_release_artifact"),
    ("expired", True, "release_artifact_expired"),
    ("size_in_bytes", 30_000_000, "release_artifact_size_limit"),
]:
    sample = deepcopy(artifact)
    sample[field] = value
    expect_reject(lambda s=sample: verify(a=s), failure)
wrong_run = deepcopy(artifact)
wrong_run["workflow_run"]["id"] = 43
expect_reject(lambda: verify(a=wrong_run), "release_artifact_run_mismatch")
wrong_sha = deepcopy(artifact)
wrong_sha["workflow_run"]["head_sha"] = "a" * 40
expect_reject(lambda: verify(a=wrong_sha), "release_artifact_sha_mismatch")
expect_reject(
    lambda: verify(pol=ThemeReleasePolicy("bad url", policy.workflow_path)),
    "invalid_trusted_repository",
)

payload = b"PK\x03\x04" + os.urandom(2048)
digest = sha256(payload).hexdigest()
name = "seo-geo-theme.zip"


def wrapper(files: dict[str, bytes]) -> bytes:
    stream = BytesIO()
    with zipfile.ZipFile(stream, "w", zipfile.ZIP_DEFLATED) as archive:
        for path, content in files.items():
            archive.writestr(path, content)
    return stream.getvalue()


valid = wrapper({name: payload, name + ".sha256": f"{digest}  {name}\n".encode()})
assert extract_pinned_theme_zip(valid, expected_zip_sha256=digest) == payload
expect_reject(
    lambda: extract_pinned_theme_zip(valid, expected_zip_sha256="0" * 64),
    "release_checksum_file_mismatch",
)
expect_reject(
    lambda: extract_pinned_theme_zip(wrapper({
        name: payload, name + ".sha256": f"{digest}  {name}\n".encode(),
        "evil.php": b"attack",
    }), expected_zip_sha256=digest),
    "unexpected_artifact_wrapper_entries",
)
expect_reject(
    lambda: extract_pinned_theme_zip(wrapper({
        name: b"different" + os.urandom(2048),
        name + ".sha256": f"{digest}  {name}\n".encode(),
    }), expected_zip_sha256=digest),
    "release_package_digest_mismatch",
)
expect_reject(
    lambda: extract_pinned_theme_zip(b"not a zip", expected_zip_sha256=digest),
    "artifact_wrapper_size_limit",
)
expect_reject(
    lambda: extract_pinned_theme_zip(wrapper({
        name: b"x" * 2_000_000,
        name + ".sha256": f"{digest}  {name}\n".encode(),
    }), expected_zip_sha256=digest),
    "artifact_wrapper_expansion_limit",
)
print("Trusted theme release provenance: OK (official run, branch, commit, artifact, wrapper and digest)")
