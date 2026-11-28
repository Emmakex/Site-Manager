#!/usr/bin/env python3
"""Protected host updater: only exact, typed operations; no arbitrary shell."""
import base64
import json
import os
import re
import secrets
import subprocess
import sys
import tempfile
from pathlib import Path
from site_manager_plugin_release_download import fetch_official_plugin_release
from site_manager_site_bootstrap import prove_connection

SITE="emmake-produccion"
HOME="https://emmake.com/"
STAGES={
    "plan":{"version","package_sha256","source"},
    "apply":{"plan_id","package_sha256","confirmed"},
    "status":set(),
    "verify":{"operation_id"},
    "restore":{"operation_id","confirmed"},
    "finalize":{"operation_id","confirmed"},
}
def hexval(value,n):
    return isinstance(value,str) and re.fullmatch(r"[a-f0-9]{"+str(n)+"}",value) is not None
def ver(value):
    return isinstance(value,str) and re.fullmatch(r"[0-9]{1,9}\.[0-9]{1,9}\.[0-9]{1,9}",value) is not None
def validate(value):
    if not isinstance(value,dict) or set(value)!={"site","stage","payload","request_id"} or value["site"]!=SITE:
        raise ValueError("invalid_request")
    stage=value["stage"]; p=value["payload"]
    if not isinstance(stage,str) or stage not in STAGES or not isinstance(p,dict) or set(p)!=STAGES[stage]:
        raise ValueError("invalid_stage")
    if not isinstance(value["request_id"],str) or not re.fullmatch(r"host-update-[a-z0-9-]{8,70}",value["request_id"]):
        raise ValueError("invalid_request_id")
    if stage=="plan":
        src=p["source"]
        if not ver(p["version"]) or not hexval(p["package_sha256"],64) or not isinstance(src,dict) or set(src)!={"commit_sha","run_id","artifact_id"} or not hexval(src["commit_sha"],40) or any(type(src[k]) is not int or src[k]<1 for k in ("run_id","artifact_id")):
            raise ValueError("invalid_release")
    if stage=="apply" and (not hexval(p["plan_id"],32) or not hexval(p["package_sha256"],64)):
        raise ValueError("invalid_plan")
    if stage in ("verify","restore","finalize") and not hexval(p["operation_id"],32):
        raise ValueError("invalid_operation")
    if stage in ("apply","restore","finalize") and p["confirmed"] is not True:
        raise ValueError("confirmation_required")
    return stage,p

def check_receipt(stage,data,challenge,payload):
    if not isinstance(data,dict) or data.get("schema")!="site-manager-host-update-v1" or data.get("site")!=SITE or data.get("nonce")!=challenge["nonce"]:
        raise ValueError("host_receipt_identity")
    result={k:v for k,v in data.items() if k not in ("schema","site","nonce")}
    status=result.get("status")
    if stage=="plan":
        keys={"plan_id","expires_at","status","current_version","version","package_sha256","source","before_tree","after_tree","verified","mutation_performed","confirmation_required"}
        if set(result)!=keys or status!="planned" or not hexval(result["plan_id"],32) or type(result["expires_at"]) is not int or not ver(result["current_version"]) or result["version"]!=payload["version"] or result["source"]!=payload["source"] or result["package_sha256"]!=payload["package_sha256"] or any(not hexval(result[k],64) for k in ("before_tree","after_tree")) or result["verified"] is not False or result["mutation_performed"] is not False or result["confirmation_required"] is not True:
            raise ValueError("invalid_plan_receipt")
    elif stage=="status":
        if status=="idle":
            if set(result)!={"status","mutation_performed"} or result["mutation_performed"] is not False: raise ValueError("invalid_status")
        elif status not in ("preparing","installing","installed_pending_runtime","restored_pending_runtime") or set(result)!={"status","operation_id","mutation_performed"} or not hexval(result["operation_id"],32) or result["mutation_performed"] is not False:
            raise ValueError("invalid_status")
    elif stage=="apply":
        if result.get("plan_id")!=payload["plan_id"] or result.get("verified") is not False or result.get("mutation_performed") is not True or not hexval(result.get("operation_id"),32):
            raise ValueError("invalid_apply_receipt")
        if status=="installed_pending_runtime":
            if set(result)!={"plan_id","status","verified","tree_verified","operation_id","version","mutation_performed"} or result["tree_verified"] is not True or not ver(result["version"]): raise ValueError("invalid_apply_receipt")
        elif status!="recovery_required" or set(result)!={"plan_id","status","verified","operation_id","mutation_performed"}:
            raise ValueError("invalid_apply_receipt")
    elif stage=="restore":
        if result.get("verified") is not False:
            raise ValueError("invalid_restore_receipt")
        if status=="restored_pending_runtime":
            if set(result)!={"status","verified","tree_verified","version"} or result["tree_verified"] is not True or not ver(result["version"]): raise ValueError("invalid_restore_receipt")
        elif status!="manual_recovery_required" or set(result)!={"status","verified"}:
            raise ValueError("invalid_restore_receipt")
    else:
        keys={"operation_id","status","version","tree_sha256","local_verified","remote_verified","rollback_available"}
        if stage=="finalize": keys.add("archive_removed")
        if set(result)!=keys or result["operation_id"]!=payload["operation_id"] or not ver(result["version"]) or not hexval(result["tree_sha256"],64) or result["local_verified"] is not True or result["remote_verified"] is not False:
            raise ValueError("invalid_runtime_receipt")
        if stage=="verify" and (status not in ("installed_runtime_verified_local","restored_runtime_verified_local") or result["rollback_available"] is not True):
            raise ValueError("invalid_runtime_receipt")
        if stage=="finalize" and (status!="finalized_local" or result["rollback_available"] is not False or type(result["archive_removed"]) is not bool):
            raise ValueError("invalid_runtime_receipt")
    return result

def call_host(stage,payload,key,known_hosts):
    host=os.environ.get("HOSTINGER_SM_HOST",""); user=os.environ.get("HOSTINGER_SM_USER",""); port=os.environ.get("HOSTINGER_SM_PORT","")
    cid=os.environ.get("SITE_MANAGER_EXPECTED_CONNECTION_ID","")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.-]{0,252}",host) or not re.fullmatch(r"[A-Za-z0-9_][A-Za-z0-9_-]{0,63}",user) or not re.fullmatch(r"[0-9]{1,5}",port) or not 1<=int(port)<=65535 or not re.fullmatch(r"smc_[a-f0-9]{32}",cid) or os.environ.get("SITE_MANAGER_EXPECTED_SITE_URL")!=HOME or os.environ.get("SITE_MANAGER_SITE_ID")!=SITE:
        raise ValueError("protected_host_config_invalid")
    challenge={"site":SITE,"home":HOME,"connection_id":cid,"nonce":secrets.token_hex(32)}
    request={**challenge,**payload}
    args=["ssh","-F","/dev/null","-T","-i",key,"-p",port,"-o","BatchMode=yes","-o","IdentitiesOnly=yes","-o","StrictHostKeyChecking=yes","-o","UserKnownHostsFile="+known_hosts,"-o","ConnectTimeout=10","-o","ClearAllForwardings=yes","-o","RequestTTY=no",user+"@"+host,"site-manager-update-"+stage+"-v1"]
    with tempfile.TemporaryFile() as raw:
        run=subprocess.run(args,input=json.dumps(request,separators=(",",":")).encode(),stdout=raw,stderr=subprocess.DEVNULL,timeout=240,check=False)
        raw.seek(0); data=raw.read(16385)
    if run.returncode!=0 or len(data)>16384: raise ValueError("host_operation_unconfirmed")
    return check_receipt(stage,json.loads(data),challenge,payload)

def verify_mcp(version):
    with tempfile.TemporaryDirectory() as d:
        path=prove_connection(requested_site=SITE,run_id=os.environ["GITHUB_RUN_ID"],environment_site=os.environ["SITE_MANAGER_SITE_ID"],expected_site_url=HOME,expected_connection_id=os.environ["SITE_MANAGER_EXPECTED_CONNECTION_ID"],mcp_url=os.environ["SITE_MANAGER_MCP_BASE_URL"],token=os.environ["SITE_MANAGER_MCP_TOKEN"],result_dir=Path(d))
        data=json.loads(path.read_text())
        if data.get("verified") is not True or data.get("data",{}).get("wordpress",{}).get("site_manager_version")!=version:
            raise ValueError("external_runtime_unverified")

def main():
    request_path,key,known,out=sys.argv[1:]
    req=json.loads(Path(request_path).read_text()); stage,p=validate(req)
    if Path(request_path).name!=req["request_id"]+".json": raise ValueError("request_filename_mismatch")
    if stage=="plan":
        package=fetch_official_plugin_release(version=p["version"],package_sha256=p["package_sha256"],github_token=os.environ["GITHUB_TOKEN"],**p["source"])
        result=call_host(stage,{**p,"package_base64":base64.b64encode(package).decode("ascii")},key,known)
    elif stage=="finalize":
        # Separate live read immediately before releasing recovery state.
        local=call_host("verify",{"operation_id":p["operation_id"]},key,known)
        verify_mcp(local["version"])
        result=call_host(stage,p,key,known)
        if result["version"]!=local["version"] or result["tree_sha256"]!=local["tree_sha256"]: raise ValueError("finalize_readback_mismatch")
        result["remote_verified"]=True
    else:
        result=call_host(stage,p,key,known)
        if stage=="verify":
            verify_mcp(result["version"]); result["remote_verified"]=True
    receipt={"request_id":req["request_id"],"site":SITE,"stage":stage,"result":result}
    Path(out).write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
    print("HOST_UPDATE_RECEIPT="+json.dumps(receipt,sort_keys=True,separators=(",",":")))
    if result.get("status") in ("recovery_required","manual_recovery_required"): return 2
    return 0
if __name__=="__main__":
    try:
        sys.exit(main())
    except Exception:
        print("HOST_UPDATE_UNCONFIRMED: reconcile status before any new mutation",file=sys.stderr)
        sys.exit(1)
