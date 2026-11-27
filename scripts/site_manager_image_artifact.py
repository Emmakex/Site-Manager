#!/usr/bin/env python3
"""Bounded in-memory image handoff from a trusted Actions artifact.

Never fetches a caller-selected URL, extracts archive paths, or stores image
bytes in a Git command. Initial source is an explicitly non-confidential CI
fixture. Other sources require an engineering review of trusted policy.
"""
from __future__ import annotations

from hashlib import sha256
from io import BytesIO
import re
from typing import Any, Callable
import zipfile

from site_manager_theme_release_download import _github_fetch, _official_url, ThemeReleaseDownloadError
from site_manager_theme_release_provenance import ThemeReleasePolicy, verify_release_reference, ThemePackageProvenanceError

POLICY = ThemeReleasePolicy(
    repository="Emmakex/Site-Manager",
    workflow_path=".github/workflows/site-manager-media-artifact.yml",
    artifact_name="site-manager-media-image",
)
MAX_IMAGE = 2_000_000
MAX_ARCHIVE = 2_100_000
MIME_EXT = {"image/jpeg":"jpg","image/png":"png","image/webp":"webp"}

class ImageArtifactError(ValueError):
    pass

def validate_manifest(manifest: Any) -> dict[str, Any]:
    if not isinstance(manifest, dict) or set(manifest) != {"filename","mime","sha256","bytes","width","height"}:
        raise ImageArtifactError("invalid_image_manifest")
    name = manifest["filename"]
    mime = manifest["mime"]
    if (not isinstance(name,str) or not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,99}\.(jpg|png|webp)", name)
            or not isinstance(mime,str) or MIME_EXT.get(mime) != name.rsplit(".",1)[1]
            or not isinstance(manifest["sha256"],str) or not re.fullmatch(r"[a-f0-9]{64}",manifest["sha256"])
            or type(manifest["bytes"]) is not int or not 1 <= manifest["bytes"] <= MAX_IMAGE
            or any(type(manifest[k]) is not int or not 1 <= manifest[k] <= 4096 for k in ("width","height"))
            or manifest["width"]*manifest["height"]>12_000_000):
        raise ImageArtifactError("invalid_image_manifest")
    return dict(manifest)

def validate_source(source: Any) -> dict[str, Any]:
    if (not isinstance(source,dict) or set(source)!={"commit_sha","run_id","artifact_id"}
            or not isinstance(source["commit_sha"],str) or not re.fullmatch(r"[a-f0-9]{40}",source["commit_sha"])
            or any(type(source[k]) is not int or source[k]<1 for k in ("run_id","artifact_id"))):
        raise ImageArtifactError("invalid_image_artifact_reference")
    return dict(source)

def extract_image(outer: bytes, manifest: dict[str,Any]) -> bytes:
    manifest=validate_manifest(manifest)
    if not isinstance(outer,bytes) or not 0<len(outer)<=MAX_ARCHIVE:
        raise ImageArtifactError("image_artifact_size_limit")
    try:
        with zipfile.ZipFile(BytesIO(outer)) as z:
            entries=z.infolist()
            if len(entries)!=1:
                raise ImageArtifactError("unexpected_image_artifact_entries")
            entry=entries[0]
            if (entry.filename!=manifest["filename"] or entry.is_dir() or entry.flag_bits & 1
                    or entry.file_size!=manifest["bytes"] or entry.compress_size<1
                    or entry.create_system==3 and ((entry.external_attr>>16)&0o170000) not in (0,0o100000)):
                raise ImageArtifactError("unsafe_image_artifact_entry")
            with z.open(entry) as f:
                image=f.read(MAX_IMAGE+1)
    except (zipfile.BadZipFile,RuntimeError,OSError,NotImplementedError) as exc:
        raise ImageArtifactError("invalid_image_artifact_archive") from exc
    if len(image)!=manifest["bytes"] or sha256(image).hexdigest()!=manifest["sha256"]:
        raise ImageArtifactError("image_artifact_digest_mismatch")
    return image

def fetch_image(source: dict[str,Any], manifest: dict[str,Any], token: str,
                fetch_metadata: Callable[[str],Any]|None=None,
                fetch_binary: Callable[[str],bytes]|None=None) -> bytes:
    source=validate_source(source)
    manifest=validate_manifest(manifest)
    if not token:
        raise ImageArtifactError("image_artifact_auth_unavailable")
    metadata=fetch_metadata or (lambda u:_github_fetch(u,token,False))
    binary=fetch_binary or (lambda u:_github_fetch(u,token,True))
    try:
        run=metadata(_official_url(POLICY,"run",source["run_id"]))
        artifact=metadata(_official_url(POLICY,"artifact",source["artifact_id"]))
        if not isinstance(run,dict) or not isinstance(artifact,dict):
            raise ImageArtifactError("invalid_image_artifact_metadata")
        verify_release_reference(policy=POLICY,run=run,artifact=artifact,
            expected_commit_sha=source["commit_sha"],expected_run_id=source["run_id"],
            expected_artifact_id=source["artifact_id"])
        if artifact["size_in_bytes"]>MAX_ARCHIVE or run.get("run_attempt")!=1:
            raise ImageArtifactError("image_artifact_size_or_attempt_refused")
        archive=binary(_official_url(POLICY,"download",source["artifact_id"]))
    except (ThemeReleaseDownloadError,ThemePackageProvenanceError) as exc:
        raise ImageArtifactError("image_artifact_provenance_failed") from exc
    return extract_image(archive,manifest)
