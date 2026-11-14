#!/usr/bin/env python3
"""Strict GitHub Actions artifact provenance + inner-release extraction.

This is a pure, offline security gate. It neither calls GitHub/WordPress nor
applies a package. The future trusted Gateway supplies GitHub API metadata
using protected configuration; the command sender never supplies arbitrary
repository URLs or artifact download destinations.
"""
from __future__ import annotations

from dataclasses import dataclass
from hashlib import sha256
from io import BytesIO
import re
from typing import Any, Mapping
import zipfile


class ThemePackageProvenanceError(ValueError):
    """Fail closed on non-official artifact provenance or bytes."""


@dataclass(frozen=True)
class ThemeReleasePolicy:
    repository: str
    workflow_path: str
    branch: str = "main"
    artifact_name: str = "seo-geo-theme-release-candidate"
    public_repository: bool = False


def _hex_digest(value: Any) -> bool:
    return isinstance(value, str) and re.fullmatch(r"[a-f0-9]{64}", value) is not None


def verify_release_reference(
    *,
    policy: ThemeReleasePolicy,
    run: Mapping[str, Any],
    artifact: Mapping[str, Any],
    expected_commit_sha: str,
    expected_run_id: int,
    expected_artifact_id: int,
) -> dict[str, str | int]:
    """Verify only immutable GitHub run/artifact metadata.

    The policy MUST come from the trusted operator environment, not a user
    command. A valid status/ID alone is insufficient: require the official
    workflow, repository, trusted branch, exact commit and completed success.
    """
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", policy.repository):
        raise ThemePackageProvenanceError("invalid_trusted_repository")
    if not re.fullmatch(r"\.github/workflows/[A-Za-z0-9_.-]+\.ya?ml", policy.workflow_path):
        raise ThemePackageProvenanceError("invalid_trusted_workflow")
    if not isinstance(expected_commit_sha, str) or re.fullmatch(r"[a-f0-9]{40}", expected_commit_sha) is None:
        raise ThemePackageProvenanceError("invalid_expected_commit")
    if not isinstance(expected_run_id, int) or isinstance(expected_run_id, bool) or expected_run_id <= 0:
        raise ThemePackageProvenanceError("invalid_run_id")
    if not isinstance(expected_artifact_id, int) or isinstance(expected_artifact_id, bool) or expected_artifact_id <= 0:
        raise ThemePackageProvenanceError("invalid_artifact_id")
    repository = run.get("repository")
    head_repository = run.get("head_repository")
    run_path = run.get("path")
    if not isinstance(repository, Mapping) or repository.get("full_name") != policy.repository:
        raise ThemePackageProvenanceError("untrusted_release_repository")
    if head_repository is not None and (not isinstance(head_repository, Mapping)
                                        or head_repository.get("full_name") != policy.repository):
        raise ThemePackageProvenanceError("untrusted_head_repository")
    if run.get("id") != expected_run_id or run.get("head_sha") != expected_commit_sha:
        raise ThemePackageProvenanceError("stale_release_run")
    if run.get("event") != "push" or run.get("head_branch") != policy.branch:
        raise ThemePackageProvenanceError("untrusted_release_branch")
    if not isinstance(run_path, str) or run_path.split("@", 1)[0] != policy.workflow_path:
        raise ThemePackageProvenanceError("untrusted_release_workflow")
    if run.get("status") != "completed" or run.get("conclusion") != "success":
        raise ThemePackageProvenanceError("release_checks_incomplete")
    if artifact.get("id") != expected_artifact_id or artifact.get("name") != policy.artifact_name:
        raise ThemePackageProvenanceError("wrong_release_artifact")
    if artifact.get("expired") is not False:
        raise ThemePackageProvenanceError("release_artifact_expired")
    size = artifact.get("size_in_bytes")
    if not isinstance(size, int) or isinstance(size, bool) or not (100 < size <= 25_000_000):
        raise ThemePackageProvenanceError("release_artifact_size_limit")
    workflow_run = artifact.get("workflow_run")
    if not isinstance(workflow_run, Mapping) or workflow_run.get("id") != expected_run_id:
        raise ThemePackageProvenanceError("release_artifact_run_mismatch")
    if workflow_run.get("head_sha") not in (None, expected_commit_sha):
        raise ThemePackageProvenanceError("release_artifact_sha_mismatch")
    # No URL or filesystem location is returned to the caller.
    return {
        "repository": policy.repository,
        "commit_sha": expected_commit_sha,
        "run_id": expected_run_id,
        "artifact_id": expected_artifact_id,
        "artifact_name": policy.artifact_name,
        "artifact_bytes": size,
    }


def extract_pinned_theme_zip(
    outer_artifact_bytes: bytes,
    *,
    expected_zip_sha256: str,
    inner_name: str = "seo-geo-theme.zip",
) -> bytes:
    """Validate GitHub's artifact wrapper without writing any extracted path.

    The future Gateway must send the returned bytes over its protected MCP
    connection; the WordPress ThemePackageArchiveGuard independently rechecks
    the inner package digest, theme root and embedded runtime manifest.
    """
    if not _hex_digest(expected_zip_sha256):
        raise ThemePackageProvenanceError("invalid_expected_package_digest")
    if not isinstance(outer_artifact_bytes, bytes) or not (100 < len(outer_artifact_bytes) <= 25_000_000):
        raise ThemePackageProvenanceError("artifact_wrapper_size_limit")
    if not re.fullmatch(r"[a-z0-9][a-z0-9-]{1,79}\.zip", inner_name):
        raise ThemePackageProvenanceError("invalid_inner_package_name")
    try:
        archive = zipfile.ZipFile(BytesIO(outer_artifact_bytes), "r")
    except (zipfile.BadZipFile, ValueError) as exc:
        raise ThemePackageProvenanceError("invalid_artifact_wrapper") from exc
    with archive:
        files = archive.infolist()
        expected = {inner_name, f"{inner_name}.sha256"}
        if len(files) != 2 or {entry.filename for entry in files} != expected:
            raise ThemePackageProvenanceError("unexpected_artifact_wrapper_entries")
        for entry in files:
            if entry.is_dir() or entry.flag_bits & 1 or entry.file_size > 20_000_000:
                raise ThemePackageProvenanceError("unreadable_artifact_wrapper_entry")
            if entry.compress_size == 0 or entry.file_size > 400 * entry.compress_size:
                raise ThemePackageProvenanceError("artifact_wrapper_expansion_limit")
            if entry.create_system == 3 and ((entry.external_attr >> 16) & 0o170000) not in (0, 0o100000):
                raise ThemePackageProvenanceError("artifact_wrapper_unsupported_file_type")
        try:
            inner = archive.read(inner_name)
            checksum = archive.read(f"{inner_name}.sha256").decode("ascii", errors="strict")
        except (RuntimeError, OSError, ValueError, KeyError, UnicodeDecodeError, zipfile.BadZipFile) as exc:
            raise ThemePackageProvenanceError("unreadable_artifact_wrapper") from exc
    if len(inner) < 100 or len(inner) > 20_000_000:
        raise ThemePackageProvenanceError("inner_package_size_limit")
    # The official release builder writes "<sha>  <filename>\n", never a URL.
    if checksum != f"{expected_zip_sha256}  {inner_name}\n":
        raise ThemePackageProvenanceError("release_checksum_file_mismatch")
    if sha256(inner).hexdigest() != expected_zip_sha256:
        raise ThemePackageProvenanceError("release_package_digest_mismatch")
    return inner
