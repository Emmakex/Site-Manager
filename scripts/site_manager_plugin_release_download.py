#!/usr/bin/env python3
"""Read-only acquisition of official Site Manager plugin releases.

Fixed repository, main push workflow, exact commit/run/artifact and checksum.
Never accepts an arbitrary repository, URL, filename, local destination, or
plugin slug. No production installation or generic plugin update capability.
"""
from __future__ import annotations

import re
from typing import Any, Callable

from site_manager_theme_release_download import _github_fetch, _official_url, ThemeReleaseDownloadError
from site_manager_theme_release_provenance import (
    ThemeReleasePolicy, ThemePackageProvenanceError, verify_release_reference, extract_pinned_theme_zip,
)

MAX_PLUGIN_PACKAGE = 10_000_000
MAX_PLUGIN_WRAPPER = 10_100_000

class PluginReleaseError(ValueError):
    pass

def fetch_official_plugin_release(*, version: str, commit_sha: str, run_id: int,
                                  artifact_id: int, package_sha256: str, github_token: str,
                                  fetch_metadata: Callable | None = None,
                                  fetch_binary: Callable | None = None) -> bytes:
    if (not isinstance(version,str) or not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+",version)
            or len(version)>32 or not isinstance(package_sha256,str)
            or not re.fullmatch(r"[a-f0-9]{64}",package_sha256) or not github_token
            or not isinstance(commit_sha,str) or not re.fullmatch(r"[a-f0-9]{40}",commit_sha)
            or type(run_id) is not int or run_id<1 or type(artifact_id) is not int or artifact_id<1):
        raise PluginReleaseError("invalid_official_plugin_reference")
    policy=ThemeReleasePolicy(repository="Emmakex/Site-Manager",
        workflow_path=".github/workflows/wordpress-package.yml",
        branch="main",artifact_name="site-manager-wordpress-"+version)
    metadata=fetch_metadata or (lambda u:_github_fetch(u,github_token,False))
    binary=fetch_binary or (lambda u:_github_fetch(u,github_token,True))
    try:
        run=metadata(_official_url(policy,"run",run_id))
        artifact=metadata(_official_url(policy,"artifact",artifact_id))
        if not isinstance(run,dict) or not isinstance(artifact,dict):
            raise PluginReleaseError("invalid_plugin_release_metadata")
        verify_release_reference(policy=policy,run=run,artifact=artifact,
            expected_commit_sha=commit_sha,expected_run_id=run_id,expected_artifact_id=artifact_id)
        if (run.get("run_attempt")!=1 or artifact["size_in_bytes"]>MAX_PLUGIN_WRAPPER
                or artifact["workflow_run"].get("head_sha")!=commit_sha
                or run.get("head_repository",{}).get("full_name")!=policy.repository):
            raise PluginReleaseError("plugin_release_provenance_mismatch")
        outer=binary(_official_url(policy,"download",artifact_id))
        if not isinstance(outer,bytes) or not 100<len(outer)<=MAX_PLUGIN_WRAPPER:
            raise PluginReleaseError("plugin_release_wrapper_limit")
        inner=extract_pinned_theme_zip(outer,expected_zip_sha256=package_sha256,
                                       inner_name="site-manager-"+version+".zip")
        if len(inner)>MAX_PLUGIN_PACKAGE:
            raise PluginReleaseError("plugin_release_package_limit")
        return inner
    except (ThemeReleaseDownloadError,ThemePackageProvenanceError,AttributeError) as exc:
        raise PluginReleaseError("official_plugin_release_refused") from exc
