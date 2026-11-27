#!/usr/bin/env python3
from copy import deepcopy
from hashlib import sha256
from io import BytesIO
from pathlib import Path
import base64
import json
import sys
import zipfile

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"scripts"))
from site_manager_image_artifact import POLICY,ImageArtifactError,extract_image,fetch_image
from site_manager_image_artifact_gateway import validate_payload,verify_plan,verify_apply,apply_image
from site_manager_theme_large_mcp import ThemePackageMcpPlanClient,ThemePackageMcpTransportError

def rejects(fn,error=ImageArtifactError):
    try:
        fn()
    except error:
        return
    raise AssertionError("unsafe image operation accepted")

image=base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jf2sAAAAASUVORK5CYII=")
manifest={"filename":"fixture.png","mime":"image/png","sha256":sha256(image).hexdigest(),"bytes":len(image),"width":1,"height":1}
source={"commit_sha":"a"*40,"run_id":42,"artifact_id":43}
def archive(entries):
    buf=BytesIO()
    with zipfile.ZipFile(buf,"w") as z:
        for name,value in entries:
            z.writestr(name,value)
    return buf.getvalue()
outer=archive([("fixture.png",image)])
assert extract_image(outer,manifest)==image
rejects(lambda:extract_image(archive([("../fixture.png",image)]),manifest))
rejects(lambda:extract_image(archive([("fixture.png",image),("extra.txt",b"x")]),manifest))
rejects(lambda:extract_image(outer,{**manifest,"sha256":"0"*64}))
run={"repository":{"full_name":POLICY.repository},"head_repository":{"full_name":POLICY.repository},
     "id":42,"head_sha":source["commit_sha"],"event":"push","head_branch":"main",
     "path":POLICY.workflow_path,"status":"completed","conclusion":"success","run_attempt":1}
artifact={"id":43,"name":POLICY.artifact_name,"expired":False,"size_in_bytes":len(outer),
          "workflow_run":{"id":42,"head_sha":source["commit_sha"]}}
def meta(url):
    assert url.startswith("https://api.github.com/repos/Emmakex/Site-Manager/actions/")
    return run if "/runs/" in url else artifact
def binary(url):
    assert url.endswith("/artifacts/43/zip")
    return outer
assert fetch_image(source,manifest,"fixture-token",meta,binary)==image
for key,value in (("head_branch","untrusted"),("run_attempt",2),("conclusion","failure"),("path",".github/workflows/unknown.yml")):
    rejects(lambda k=key,v=value:fetch_image(source,manifest,"fixture-token",
        lambda u:{**run,k:v} if "/runs/" in u else artifact,binary))
rejects(lambda:fetch_image({**source,"artifact_id":99},manifest,"fixture-token",meta,binary))
pid="sm-plan-"+"b"*24
plan={"stage":"plan","parent_id":17,"manifest":manifest,"source":source}
apply={**plan,"stage":"apply","plan_id":pid,"plan_command_id":"cmd-media-plan-0001","confirmed":True}
validate_payload(plan)
validate_payload(apply)
for invalid in ({**plan,"image_base64":"AAAA"},{**plan,"source":{"url":"https://example.test/a.png"}},
                {**apply,"confirmed":False},{**apply,"plan_command_id":"../../etc/passwd"}):
    rejects(lambda p=invalid:validate_payload(p))
wpplan={"plan_id":pid,"status":"planned","operation":"content.image_upload","capability_id":"content.write",
        "reversible":False,"confirmation_required":True,"fields":["manifest"],"target":{"parent_id":17,"post_type":"post"}}
preview={"parent_id":17,"manifest":manifest,"byte_validation_pending":True,"public_url_possible":True,
         "rollback_available":False,"confirmation_required":True}
planned={"stage":"plan","status":"planned","plan":wpplan,"preview":preview}
approved=verify_plan(planned,plan)
assert approved["source"]==source
rejects(lambda:verify_plan({**planned,"preview":{**preview,"public_url_possible":False}},plan))
rejects(lambda:apply_image(apply,{**approved,"parent_id":18},github_token="fixture-token",mcp_url="https://fixture.test/mcp",site_token="fixture-secret"))
receipt={"stage":"apply","status":"verified","verified":True,"rollback_available":False,"parent_id":17,
         "plan":{**wpplan,"status":"applied"},"result":{"attachment_id":44,"verified":True,"status":"verified",
           "stored_sha256":"c"*64,"width":1,"height":1,"mime":"image/png"}}
safe=verify_apply(receipt,apply)
assert safe["attachment_id"]==44 and "image_base64" not in json.dumps(safe)
rejects(lambda:verify_apply({**receipt,"result":{**receipt["result"],"width":2}},apply))
calls=[]
def wire(url,headers,body):
    request=json.loads(body)
    calls.append(request)
    assert url=="https://fixture.test/mcp"
    assert headers["authorization"]=="Bearer fixture-secret"
    if request["method"]=="initialize":
        return 200,{"content-type":"application/json"},json.dumps({"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18"}}).encode()
    if request["method"]=="notifications/initialized":
        return 202,{},b""
    assert request["params"]["name"]=="image_upload_apply"
    return 200,{"content-type":"application/json"},json.dumps({"jsonrpc":"2.0","id":2,"result":{"structuredContent":{"data":receipt}}}).encode()
client=ThemePackageMcpPlanClient("https://fixture.test/mcp","fixture-secret",wire)
args={"plan_id":pid,"confirmed":True,"image_base64":base64.b64encode(image).decode()}
assert client.apply_reviewed_image(args)==receipt
assert calls[-1]["params"]["arguments"]==args
rejects(lambda:client.apply_reviewed_image({**args,"confirmed":False}),ThemePackageMcpTransportError)
print("IMAGE_ARTIFACT_GATEWAY_CONTRACT=PASSED")
