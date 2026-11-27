#!/usr/bin/env python3
from copy import deepcopy
from hashlib import sha256
import json
from pathlib import Path
import sys
from urllib.parse import urlparse, parse_qs

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"scripts"))
from site_manager_image_artifact_drive import fetch_drive_image,access_token,DRIVE_URL,TOKEN_URL,NoRedirect
from site_manager_image_artifact import ImageArtifactError
from site_manager_image_artifact_gateway import validate_payload,fetch_approved_image,apply_image

def reject(fn):
    try:
        fn()
    except ImageArtifactError:
        return
    raise AssertionError("unsafe Drive source accepted")

image=b"test image bytes"
folder="folder_id_123456"
manifest={"filename":"reviewed.png","mime":"image/png","sha256":sha256(image).hexdigest(),
          "bytes":len(image),"width":1,"height":1}
metadata={"id":"file_id_123456","name":"reviewed.png","mimeType":"image/png","size":str(len(image)),
          "parents":[folder],"trashed":False,"version":"9"}
token="fixture-token"
calls=[]
def token_provider(credentials,request):
    assert credentials=="fixture-credentials"
    return token

def reader(found=None,after=None,body=image):
    def request(url,headers,data,limit):
        calls.append(url)
        assert headers=={"Authorization":"Bearer "+token,"Accept-Encoding":"identity"}
        assert data is None and url.startswith(DRIVE_URL) and urlparse(url).hostname=="www.googleapis.com"
        if url.startswith(DRIVE_URL+"?"):
            q=parse_qs(urlparse(url).query)
            assert q["q"]==["'"+folder+"' in parents and trashed = false and name = 'reviewed.png'"]
            assert q["pageSize"]==["2"]
            return json.dumps(found if found is not None else {"files":[metadata]}).encode()
        assert url.startswith(DRIVE_URL+"/"+metadata["id"]+"?")
        if url.endswith("?alt=media"):
            assert limit==2_000_000
            return body
        return json.dumps(after if after is not None else metadata).encode()
    return request

def fetch(req,folder_id=folder,m=manifest):
    return fetch_drive_image(m,folder_id,"fixture-credentials",req,token_provider)

assert fetch(reader())==image
assert len(calls)==3
for found in ({"files":[]},{"files":[metadata,metadata]},{"files":[metadata],"nextPageToken":"next"},
              {"files":[{**metadata,"parents":["other_folder_123"]}]},
              {"files":[{**metadata,"mimeType":"application/vnd.google-apps.shortcut"}]},
              {"files":[{**metadata,"trashed":True}]},
              {"files":[{**metadata,"size":"999"}]},
              {"files":[{**metadata,"id":"../unsafe"}]}):
    reject(lambda f=found:fetch(reader(found=f)))
reject(lambda:fetch(reader(after={**metadata,"version":"10"})))
reject(lambda:fetch(reader(after={**metadata,"parents":["other_folder_123"]})))
reject(lambda:fetch(reader(body=b"x"*len(image))))
reject(lambda:fetch(reader(),"https://example.test/"))
reject(lambda:fetch(reader(),m={**manifest,"filename":"../escape.png"}))
reject(lambda:access_token(""))
reject(lambda:access_token(json.dumps({"type":"service_account","token_uri":"https://evil.test/token"})))
reject(lambda:NoRedirect().redirect_request(None,None,302,"",{},"https://evil.test"))
plan={"stage":"plan","parent_id":17,"manifest":manifest,"source":{"provider":"google_drive"}}
validate_payload(plan)
apply={**plan,"stage":"apply","plan_id":"sm-plan-"+"a"*24,"plan_command_id":"cmd-drive-plan-0001","confirmed":True}
validate_payload(apply)
for src in ({"provider":"google_drive","file_id":"untrusted"},
            {"provider":"google_drive","folder_id":folder},{"provider":"google_drive","url":"https://example.test"},
            {"provider":"other"}, {"provider":"google_drive","token":"unsafe"}):
    reject(lambda s=src:validate_payload({**plan,"source":s}))
# Plan and apply source identity must match, even before attempting any network.
approved={**plan,"plan_id":apply["plan_id"]}
reject(lambda:apply_image(apply,{**approved,"source":{"commit_sha":"a"*40,"run_id":42,"artifact_id":43}},
                         github_token="",mcp_url="https://fixture.test/mcp",site_token="fixture"))
print("PRIVATE_DRIVE_IMAGE_SOURCE_CONTRACT=PASSED")
