#!/usr/bin/env python3
"""Authenticated, allowlisted Theme release artifact download for the trusted Gateway.

No customer-supplied URL or path enters this module. Only a reviewed policy
chooses the GitHub repository/workflow, and exact commit/run/artifact IDs bind
the result. All release bytes are pinned by SHA-256 before MCP transport.
"""
from __future__ import annotations

import base64
import json
import re
from typing import Any, Callable
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import (
    HTTPRedirectHandler,
    Request,
    build_opener,
)

from site_manager_theme_release_provenance import (
    ThemePackageProvenanceError,
    ThemeReleasePolicy,
    extract_pinned_theme_zip,
    verify_release_reference,
)


class ThemeReleaseDownloadError(RuntimeError):
    """Refuse untrusted or unavailable official release assets."""


MAX_ARTIFACT_BYTES = 25_000_000
MAX_METADATA_BYTES = 2_000_000


def _official_url(policy: ThemeReleasePolicy, kind: str, resource_id: int) -> str:
    if re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", policy.repository) is None:
        raise ThemeReleaseDownloadError("untrusted_release_policy")
    if type(resource_id) is not int or resource_id < 1:
        raise ThemeReleaseDownloadError("invalid_release_id")
    owner, repository = policy.repository.split("/")
    if kind == "run":
        return f"https://api.github.com/repos/{owner}/{repository}/actions/runs/{resource_id}"
    if kind == "artifact":
        return f"https://api.github.com/repos/{owner}/{repository}/actions/artifacts/{resource_id}"
    if kind == "download":
        return f"https://api.github.com/repos/{owner}/{repository}/actions/artifacts/{resource_id}/zip"
    raise ThemeReleaseDownloadError("unsupported_release_resource")


class VerifiedArtifactRedirects(HTTPRedirectHandler):
    """Reject SSRF, downgrade and cross-origin Authorization leakage."""

    @staticmethod
    def allowed(url: str) -> bool:
        try:
            parsed = urlparse(url)
            if parsed.scheme != "https" or parsed.username or parsed.password or parsed.port not in (None, 443):
                return False
            host = (parsed.hostname or "").lower()
            return (host == "api.github.com"
                    or host.endswith(".actions.githubusercontent.com")
                    or host.endswith(".githubusercontent.com")
                    or host.endswith(".blob.core.windows.net"))
        except (ValueError, TypeError):
            return False

    def redirect_request(self, req: Request, fp: Any, code: int, msg: str,
                         headers: Any, newurl: str) -> Request:
        if code not in (301, 302, 303, 307, 308) or not self.allowed(newurl):
            raise ThemeReleaseDownloadError("untrusted_artifact_redirect")
        origin = urlparse(req.full_url).netloc.lower()
        destination = urlparse(newurl).netloc.lower()
        safe = {key: value for key, value in req.header_items()
                if key.lower() not in {"host", "content-length", "content-type"}}
        if origin != destination:
            # The bearer is valid only at the GitHub API, never at an Azure
            # CDN host. GitHub's one-time redirect is already URL-signed.
            safe.pop("Authorization", None)
            safe.pop("authorization", None)
        return Request(newurl, headers=safe, method="GET")


def _read_bounded(response: Any, bound: int) -> bytes:
    data = response.read(bound + 1)
    if not isinstance(data, bytes) or len(data) > bound:
        raise ThemeReleaseDownloadError("release_response_size_limit")
    return data


def _github_fetch(url: str, token: str, binary: bool) -> Any:
    if not url.startswith("https://api.github.com/repos/"):
        raise ThemeReleaseDownloadError("release_source_configuration_missing")
    headers = {
        "Accept": "application/vnd.github+json" if not binary else "application/zip",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "site-manager-release-gateway/1",
    }
    if token:
        headers["Authorization"] = f"Bearer {token}"
    try:
        request = Request(url, headers=headers, method="GET")
        # Metadata must never redirect; artifact downloads may redirect only
        # to GitHub's HTTPS signed action blob storage.
        redirects = VerifiedArtifactRedirects()
        if not binary:
            redirects.allowed = lambda _url: False  # type: ignore[method-assign]
        opener = build_opener(redirects)
        with opener.open(request, timeout=35) as response:
            data = _read_bounded(response, MAX_ARTIFACT_BYTES if binary else MAX_METADATA_BYTES)
        if binary:
            return data
        value = json.loads(data.decode("utf-8"))
        if not isinstance(value, dict):
            raise ThemeReleaseDownloadError("invalid_release_metadata")
        return value
    except ThemeReleaseDownloadError:
        raise
    except (HTTPError, URLError, OSError, ValueError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ThemeReleaseDownloadError("official_release_request_failed") from exc


def prepare_official_theme_plan(
    policy: ThemeReleasePolicy,
    *,
    theme_slug: str,
    source_commit_sha: str,
    source_run_id: int,
    source_artifact_id: int,
    package_sha256: str,
    github_token: str,
    fetch_metadata: Callable[[str], Any] | None = None,
    fetch_binary: Callable[[str], bytes] | None = None,
) -> dict[str, str | int]:
    """Construct a WordPress plan payload solely from verified official bytes.

    The returned Base64 ZIP is destined for protected MCP tool arguments and
    MUST NEVER be logged, persisted in the GitHub command/result or shown to
    the operator. Only the small WP preview is stored in results.
    """
    if not github_token and not policy.public_repository:
        raise ThemeReleaseDownloadError("release_authentication_unconfigured")
    # A reviewed public repository needs no cross-repository token; avoid
    # accidental 403 from the current repository-scoped GITHUB_TOKEN.
    effective_token = "" if policy.public_repository else github_token
    if not re.fullmatch(r"[a-z0-9][a-z0-9-]{1,79}", theme_slug):
        raise ThemeReleaseDownloadError("invalid_release_theme_slug")
    if not re.fullmatch(r"[a-f0-9]{64}", package_sha256):
        raise ThemeReleaseDownloadError("invalid_release_digest")

    metadata = fetch_metadata or (lambda url: _github_fetch(url, effective_token, False))
    binary = fetch_binary or (lambda url: _github_fetch(url, effective_token, True))
    run = metadata(_official_url(policy, "run", source_run_id))
    artifact = metadata(_official_url(policy, "artifact", source_artifact_id))
    if not isinstance(run, dict) or not isinstance(artifact, dict):
        raise ThemeReleaseDownloadError("invalid_release_metadata")
    try:
        verify_release_reference(
            policy=policy,
            run=run,
            artifact=artifact,
            expected_commit_sha=source_commit_sha,
            expected_run_id=source_run_id,
            expected_artifact_id=source_artifact_id,
        )
        outer = binary(_official_url(policy, "download", source_artifact_id))
        if not isinstance(outer, bytes) or len(outer) > MAX_ARTIFACT_BYTES:
            raise ThemeReleaseDownloadError("release_response_size_limit")
        inner = extract_pinned_theme_zip(outer, expected_zip_sha256=package_sha256)
    except ThemePackageProvenanceError as exc:
        raise ThemeReleaseDownloadError("official_release_provenance_failed") from exc
    return {
        "theme_slug": theme_slug,
        "package_sha256": package_sha256,
        "package_base64": base64.b64encode(inner).decode("ascii"),
        "source_commit_sha": source_commit_sha,
        "source_run_id": source_run_id,
        "source_artifact_id": source_artifact_id,
    }
