#!/usr/bin/env python3
from pathlib import Path
from io import BytesIO
from hashlib import sha256
import sys
from unittest.mock import patch
from PIL import Image, ImageDraw
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"scripts"))
from site_manager_image_artifact_optimize import optimize_image, PROFILES
from site_manager_image_artifact import ImageArtifactError
from site_manager_image_artifact_gateway import prepare_image, verify_plan, verify_apply, apply_image, validate_payload

def reject(fn):
    try:
        fn()
    except ImageArtifactError:
        return
    raise AssertionError("invalid input accepted")

image=Image.new("RGBA",(1536,1024),(25,65,123,128))
draw=ImageDraw.Draw(image)
draw.rectangle((300,200,1400,800),fill=(160,125,23,255))
buf=BytesIO()
image.save(buf,format="PNG")
raw=buf.getvalue()
manifest={"filename":"fixture.png","mime":"image/png","bytes":len(raw),"sha256":sha256(raw).hexdigest(),"width":1536,"height":1024}
for profile,(edge,budget) in PROFILES.items():
    data, derived=optimize_image(raw,manifest,profile)
    assert optimize_image(raw,manifest,profile)==(data,derived)
    assert derived["mime"]=="image/webp" and derived["bytes"]<=budget
    assert max(derived["width"],derived["height"])<=edge
    assert derived["width"]<=1536 and derived["height"]<=1024
    with Image.open(BytesIO(data)) as decoded:
        assert decoded.mode=="RGBA"
        assert not decoded.info.get("exif")
reject(lambda:optimize_image(raw,manifest,"unknown"))
reject(lambda:optimize_image(raw,{**manifest,"sha256":"0"*64},"featured_webp_v1"))
reject(lambda:optimize_image(raw,{**manifest,"width":1535},"featured_webp_v1"))
reject(lambda:optimize_image(raw,{**manifest,"filename":"wrong.jpg","mime":"image/jpeg"},"featured_webp_v1"))
pid="sm-plan-"+"a"*24
source={"provider":"google_drive","optimization":"featured_webp_v1"}
payload={"stage":"plan","parent_id":17,"manifest":manifest,"source":source}
apply={**payload,"stage":"apply","plan_id":pid,"plan_command_id":"cmd-media-plan-0001","confirmed":True}
with patch("site_manager_image_artifact_gateway.fetch_approved_image",return_value=raw):
    data,derived=prepare_image(payload,"")
    plan={"plan_id":pid,"status":"planned","operation":"content.image_upload","capability_id":"content.write","reversible":False,"confirmation_required":True,"fields":["manifest"],"target":{"parent_id":17,"post_type":"post"}}
    preview={"parent_id":17,"manifest":derived,"byte_validation_pending":True,"public_url_possible":True,"rollback_available":False,"confirmation_required":True}
    approved=verify_plan({"stage":"plan","status":"planned","plan":plan,"preview":preview},payload,derived)
    assert approved["manifest"]==manifest and approved["optimized_manifest"]==derived
    reject(lambda:verify_plan({"stage":"plan","status":"planned","plan":plan,"preview":{**preview,"manifest":manifest}},payload,derived))
    reject(lambda:apply_image(apply,{**approved,"optimized_manifest":{**derived,"sha256":"f"*64}},github_token="",mcp_url="https://fixture.test/mcp",site_token="test"))
    receipt={"stage":"apply","status":"verified","verified":True,"rollback_available":False,"parent_id":17,"plan":{**plan,"status":"applied"},"result":{"attachment_id":44,"verified":True,"status":"verified","stored_sha256":"b"*64,"stored_bytes":10000,"width":derived["width"],"height":derived["height"],"mime":derived["mime"]}}
    with patch("site_manager_image_artifact_gateway.ThemePackageMcpPlanClient") as client:
        client.return_value.apply_reviewed_image.return_value=receipt
        result=apply_image(apply,approved,github_token="",mcp_url="https://fixture.test/mcp",site_token="test")
        assert result["stored_bytes"]==10000 and result["optimized_manifest"]==derived
        import base64
        assert base64.b64decode(client.return_value.apply_reviewed_image.call_args.args[0]["image_base64"])==data
    reject(lambda:verify_apply({**receipt,"result":{**receipt["result"],"stored_bytes":0}},apply,derived))
reject(lambda:validate_payload({**payload,"source":{**source,"url":"https://example.test/a"}}))
print("AUTOMATIC_WEBP_OPTIMIZATION_CONTRACT=PASSED")
