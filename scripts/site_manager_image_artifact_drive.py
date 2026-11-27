#!/usr/bin/env python3
"""Private, per-site Drive image source. No caller-supplied URLs or credentials.

The environment supplies a dedicated read-only service account and one folder.
The command supplies only an approved filename and immutable SHA-256 manifest.
No Drive IDs, credentials, bearer URLs or binary data are returned to Git.
"""
from __future__ import annotations

import base64
from hashlib import sha256
import json
import os
import re
import subprocess
import tempfile
import time
from typing import Any, Callable
from urllib.parse import urlencode
from urllib.request import Request, build_opener, HTTPRedirectHandler

from site_manager_image_artifact import ImageArtifactError, validate_manifest, MAX_IMAGE

TOKEN_URL = "https://oauth2.googleapis.com/token"
DRIVE_URL = "https://www.googleapis.com/drive/v3/files"
SCOPE = "https://www.googleapis.com/auth/drive.readonly"
ID = re.compile(r"[A-Za-z0-9_-]{10,150}\Z")
FIELDS = "id,name,mimeType,size,parents,trashed,version"

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ImageArtifactError("drive_redirect_refused")

def _request(url: str, headers: dict[str,str], body: bytes|None, limit: int) -> bytes:
    # Only module-constructed official Google endpoints reach this function.
    try:
        req = Request(url, data=body, headers=headers)
        with build_opener(NoRedirect()).open(req, timeout=30) as response:
            if response.status != 200:
                raise ImageArtifactError("drive_http_failed")
            value=response.read(limit+1)
            if len(value)>limit:
                raise ImageArtifactError("drive_response_size_limit")
            return value
    except ImageArtifactError:
        raise
    except Exception:
        # Never leak URLs, OAuth responses, credentials or provider error bodies.
        raise ImageArtifactError("drive_request_failed") from None

def _json(raw: bytes) -> dict[str,Any]:
    try:
        obj=json.loads(raw)
    except (ValueError,TypeError):
        raise ImageArtifactError("drive_invalid_response") from None
    if not isinstance(obj,dict):
        raise ImageArtifactError("drive_invalid_response")
    return obj

def _b64(raw: bytes) -> bytes:
    return base64.urlsafe_b64encode(raw).rstrip(b"=")

def access_token(credentials: str, request: Callable = _request) -> str:
    if not credentials or len(credentials)>32_000:
        raise ImageArtifactError("drive_credentials_unavailable")
    cred=_json(credentials.encode())
    email=cred.get("client_email")
    key=cred.get("private_key")
    if (cred.get("type")!="service_account" or cred.get("token_uri")!=TOKEN_URL
            or not isinstance(email,str) or not re.fullmatch(r"[a-z0-9-]+@[a-z0-9-]+\.iam\.gserviceaccount\.com",email)
            or not isinstance(key,str) or len(key)>16_000 or not key.startswith("-----BEGIN PRIVATE KEY-----\n")):
        raise ImageArtifactError("drive_credentials_invalid")
    now=int(time.time())
    head=_b64(b'{"alg":"RS256","typ":"JWT"}')
    claims=_b64(json.dumps({"iss":email,"scope":SCOPE,"aud":TOKEN_URL,"iat":now,"exp":now+300},
        separators=(",",":")).encode())
    message=head+b"."+claims
    try:
        # NamedTemporaryFile is mode 0600; only key path (not key) is in argv.
        # The file is unlinked immediately after signing, including on failure.
        with tempfile.NamedTemporaryFile(mode="wb") as pem:
            pem.write(key.encode()); pem.flush()
            signature=subprocess.run(["openssl","dgst","-sha256","-sign",pem.name],
                input=message,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,
                timeout=5,check=True).stdout
        if not 128<=len(signature)<=1024:
            raise ImageArtifactError("drive_jwt_sign_failed")
    except (OSError,subprocess.SubprocessError):
        raise ImageArtifactError("drive_jwt_sign_failed") from None
    assertion=(message+b"."+_b64(signature)).decode("ascii")
    body=urlencode({"grant_type":"urn:ietf:params:oauth:grant-type:jwt-bearer","assertion":assertion}).encode()
    response=_json(request(TOKEN_URL,{"Content-Type":"application/x-www-form-urlencoded"},body,16384))
    token=response.get("access_token")
    if (response.get("token_type","").lower()!="bearer" or not isinstance(token,str)
            or not re.fullmatch(r"[A-Za-z0-9._~+/\-=]{20,8192}",token)):
        raise ImageArtifactError("drive_token_invalid")
    return token

def _check_file(obj: Any, folder: str, manifest: dict[str,Any]) -> dict[str,Any]:
    if not isinstance(obj,dict):
        raise ImageArtifactError("drive_file_metadata_invalid")
    file_id=obj.get("id")
    version=obj.get("version")
    if (not isinstance(file_id,str) or not ID.fullmatch(file_id)
            or not isinstance(version,str) or not re.fullmatch(r"[0-9]{1,30}",version)
            or obj.get("parents")!=[folder] or obj.get("trashed") is not False
            or obj.get("name")!=manifest["filename"] or obj.get("mimeType")!=manifest["mime"]
            or obj.get("size")!=str(manifest["bytes"])):
        raise ImageArtifactError("drive_file_metadata_mismatch")
    return {k:obj[k] for k in ("id","name","mimeType","size","parents","trashed","version")}

def fetch_drive_image(manifest: dict[str,Any], folder: str, credentials: str,
                      request: Callable = _request, token_provider: Callable = access_token) -> bytes:
    manifest=validate_manifest(manifest)
    if not isinstance(folder,str) or not ID.fullmatch(folder):
        raise ImageArtifactError("drive_folder_not_configured")
    token=token_provider(credentials,request)
    headers={"Authorization":"Bearer "+token,"Accept-Encoding":"identity"}
    # Folder and filename are already constrained; no arbitrary Drive search.
    query="'"+folder+"' in parents and trashed = false and name = '"+manifest["filename"]+"'"
    url=DRIVE_URL+"?"+urlencode({"q":query,"fields":"nextPageToken,files("+FIELDS+")",
        "pageSize":"2","spaces":"drive"})
    found=_json(request(url,headers,None,32768))
    files=found.get("files")
    if not isinstance(files,list) or len(files)!=1 or found.get("nextPageToken"):
        raise ImageArtifactError("drive_image_not_unique")
    before=_check_file(files[0],folder,manifest)
    file_url=DRIVE_URL+"/"+before["id"]
    image=request(file_url+"?alt=media",headers,None,MAX_IMAGE)
    if not isinstance(image,bytes) or len(image)!=manifest["bytes"] or sha256(image).hexdigest()!=manifest["sha256"]:
        raise ImageArtifactError("drive_image_digest_mismatch")
    after=_check_file(_json(request(file_url+"?"+urlencode({"fields":FIELDS}),headers,None,16384)),folder,manifest)
    if after!=before:
        raise ImageArtifactError("drive_file_changed_during_read")
    return image

def fetch_configured_drive_image(manifest: dict[str,Any]) -> bytes:
    return fetch_drive_image(manifest,os.environ.get("SITE_MANAGER_DRIVE_IMAGE_FOLDER_ID",""),
        os.environ.get("SITE_MANAGER_DRIVE_READER_JSON",""))
