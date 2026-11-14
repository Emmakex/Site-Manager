#!/usr/bin/env python3
"""Deterministic tests for allowlisted GitHub Theme package provenance transport."""
from __future__ import annotations

import base64
from hashlib import sha256
from io import BytesIO
from pathlib import Path
import sys
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from site_manager_theme_release_download import (  # noqa: E402
    ThemeReleaseDownloadError,
    VerifiedArtifactRedirects,
    prepare_official_theme_plan,
)
from site_manager_theme_release_provenance import ThemeReleasePolicy  # noqa: E402


def reject(fn, code: str) -> None:
    try:
        fn()
    except ThemeReleaseDownloadError as exc:
        assert str(exc) == code, (str(exc), code)
        return
    raise AssertionError(f"Expected {code}")


policy = ThemeReleasePolicy(
    repository="Emmakex/template-wordpress-seo-geo",
    workflow_path=".github/workflows/release-artifact.yml",
)
commit = "a" * 40
zipbytes = b"PK\x03\x04" + bytes(range(256)) * 15
digest = sha256(zipbytes).hexdigest()
name = "seo-geo-theme.zip"
stream = BytesIO()
with zipfile.ZipFile(stream, "w", zipfile.ZIP_DEFLATED) as wrapper:
    wrapper.writestr(name, zipbytes)
    wrapper.writestr(name + ".sha256", f"{digest}  {name}\n")
artifact_bytes = stream.getvalue()
run = {
    "id": 234, "event": "push", "head_branch": "main", "head_sha": commit,
    "path": ".github/workflows/release-artifact.yml@main",
    "status": "completed", "conclusion": "success",
    "repository": {"full_name": policy.repository},
    "head_repository": {"full_name": policy.repository},
}
artifact = {
    "id": 567, "name": policy.artifact_name, "expired": False,
    "size_in_bytes": len(artifact_bytes),
    "workflow_run": {"id": 234, "head_sha": commit},
}
urls: list[str] = []


def fake_metadata(url: str):
    urls.append(url)
    return run if url.endswith("/actions/runs/234") else artifact


def fake_bytes(url: str):
    urls.append(url)
    return artifact_bytes


def prepare(**changes):
    params = dict(
        policy=policy,
        theme_slug="seo-geo-theme",
        source_commit_sha=commit,
        source_run_id=234,
        source_artifact_id=567,
        package_sha256=digest,
        github_token="test-protected-action-token",
        fetch_metadata=fake_metadata,
        fetch_binary=fake_bytes,
    )
    params.update(changes)
    return prepare_official_theme_plan(**params)


result = prepare()
# Anonymous retrieval is explicitly allowed only for reviewed public repositories.
public_policy = ThemeReleasePolicy(
    repository=policy.repository,
    workflow_path=policy.workflow_path,
    public_repository=True,
)
anonymous = prepare(policy=public_policy, github_token="")
assert base64.b64decode(anonymous["package_base64"]) == zipbytes
assert anonymous["source_artifact_id"] == 567
assert result["package_sha256"] == digest
assert base64.b64decode(result["package_base64"]) == zipbytes
assert result["source_artifact_id"] == 567
assert urls == [
    "https://api.github.com/repos/Emmakex/template-wordpress-seo-geo/actions/runs/234",
    "https://api.github.com/repos/Emmakex/template-wordpress-seo-geo/actions/artifacts/567",
    "https://api.github.com/repos/Emmakex/template-wordpress-seo-geo/actions/artifacts/567/zip",
]
assert "test-protected-action-token" not in repr(result)
assert "download_url" not in repr(result)
reject(lambda: prepare(github_token=""), "release_authentication_unconfigured")
reject(lambda: prepare(theme_slug="../another"), "invalid_release_theme_slug")
reject(lambda: prepare(package_sha256="0" * 64), "official_release_provenance_failed")
reject(lambda: prepare(source_run_id=0), "invalid_release_id")
reject(lambda: prepare(source_artifact_id=0), "invalid_release_id")
reject(lambda: prepare(fetch_metadata=lambda url: {**run, "conclusion": "failure"}
       if "/runs/" in url else artifact), "official_release_provenance_failed")
reject(lambda: prepare(fetch_binary=lambda url: b"evil"), "official_release_provenance_failed")
reject(lambda: prepare(policy=ThemeReleasePolicy("https://evil.test", policy.workflow_path)),
       "untrusted_release_policy")
for url in [
    "http://api.github.com/foo", "https://127.0.0.1/private",
    "https://169.254.169.254/latest/meta-data",
    "https://github.com.attacker.test/release",
    "https://evil.test/zip",
    "https://api.github.com:8080/release",
    "https://user:password@api.github.com/",
]:
    assert not VerifiedArtifactRedirects.allowed(url), url
for url in [
    "https://api.github.com/repos/Emmakex/test",
    "https://results.actions.githubusercontent.com/blob",
    "https://files.blob.core.windows.net/build",
]:
    assert VerifiedArtifactRedirects.allowed(url), url
print("Theme CI release retrieval: OK (fixed source, exact SHA, immutable run, no URL input, safe redirects)")
