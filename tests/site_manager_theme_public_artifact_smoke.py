#!/usr/bin/env python3
"""Temporary CI probe for the verified public official release transport.

This fixed release expires 2026-11-20; remove this probe after the one-time
acceptance test. It cannot modify WordPress or store ZIP bytes.
"""
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from site_manager_theme_release_download import prepare_official_theme_plan, ThemeReleaseDownloadError
from site_manager_theme_package_gateway import trusted_release_policy

policy = trusted_release_policy("seo-geo-theme")
assert policy.public_repository is True
try:
    evidence = prepare_official_theme_plan(
        policy=policy,
        theme_slug="seo-geo-theme",
        source_commit_sha="aa1f25c6c16c381deafc59619912532785cfe312",
        source_run_id=43418785500,
        source_artifact_id=14032254429,
        package_sha256="2c689b23c93ed2b3dd5f75dfd078e5df734f08665f16bf801fca36b9db882c5f",
        github_token="",
    )
except ThemeReleaseDownloadError as exc:
    reason = exc.__cause__
    if reason is not None:
        print("PUBLIC_THEME_SMOKE_FAILED:", str(exc), "CAUSE_TYPE:", type(reason).__name__, "HTTP_CODE:", getattr(reason, "code", None))
    else:
        print("PUBLIC_THEME_SMOKE_FAILED:", str(exc))
    raise SystemExit(1)
assert len(evidence["package_base64"]) > 1000
assert evidence["package_sha256"] == "2c689b23c93ed2b3dd5f75dfd078e5df734f08665f16bf801fca36b9db882c5f"
print("PUBLIC_THEME_SMOKE_VERIFIED=YES")
