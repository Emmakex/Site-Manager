#!/usr/bin/env python3
"""Verify one fixed host worker challenge over strict SSH; no shell or file writes remotely."""
import hashlib
import json
import os
import re
import secrets
import subprocess
import sys
from pathlib import Path

def verify_receipt(data, challenge):
    keys={"schema","site","nonce","home","connection_sha256","plugin_version","tree_sha256",
          "php_version","wordpress_version","plugin_bypassed","active","zip_available",
          "private_temp_available","plugin_dir_writable","file_mod_allowed","environment","mutation_performed"}
    if not isinstance(data,dict) or set(data)!=keys:
        raise ValueError("invalid_host_receipt")
    if (data["schema"]!="site-manager-host-preflight-v1" or data["site"]!=challenge["site"]
            or data["nonce"]!=challenge["nonce"] or data["home"]!=challenge["home"]
            or data["connection_sha256"]!=hashlib.sha256(challenge["connection_id"].encode()).hexdigest()):
        raise ValueError("host_identity_mismatch")
    for key in ("plugin_version","php_version","wordpress_version"):
        if not isinstance(data[key],str) or not re.fullmatch(r"[0-9]+\.[0-9]+(?:\.[0-9]+)?",data[key]):
            raise ValueError("invalid_host_version")
    if not isinstance(data["tree_sha256"],str) or not re.fullmatch(r"[a-f0-9]{64}",data["tree_sha256"]):
        raise ValueError("invalid_host_tree")
    for key in ("plugin_bypassed","active","zip_available","private_temp_available","plugin_dir_writable","file_mod_allowed","mutation_performed"):
        if type(data[key]) is not bool:
            raise ValueError("invalid_host_flag")
    if not data["plugin_bypassed"] or not data["active"] or data["mutation_performed"]:
        raise ValueError("host_preflight_not_read_only")
    if data["environment"] not in ("local","development","staging","production"):
        raise ValueError("invalid_host_environment")
    return {"schema":data["schema"],"site":data["site"],"home":data["home"],
            "plugin_version":data["plugin_version"],"tree_sha256":data["tree_sha256"],
            "php_version":data["php_version"],"wordpress_version":data["wordpress_version"],
            "environment":data["environment"],"independent_access_verified":True,
            "readiness_checks":{k:data[k] for k in ("zip_available","private_temp_available","plugin_dir_writable","file_mod_allowed")},
            "production_update_authorized":False,"mutation_performed":False}

def main():
    if len(sys.argv)!=4:
        raise ValueError("invalid_worker_invocation")
    key,known_hosts,output=sys.argv[1:]
    host=os.environ.get("HOSTINGER_SM_HOST","")
    user=os.environ.get("HOSTINGER_SM_USER","")
    port=os.environ.get("HOSTINGER_SM_PORT","")
    identity=os.environ.get("SITE_MANAGER_EXPECTED_CONNECTION_ID","")
    home=os.environ.get("SITE_MANAGER_EXPECTED_SITE_URL","")
    if (not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.-]{0,252}",host)
            or not re.fullmatch(r"[A-Za-z0-9_][A-Za-z0-9_-]{0,63}",user)
            or not re.fullmatch(r"[0-9]{1,5}",port) or not 1<=int(port)<=65535
            or not re.fullmatch(r"smc_[a-f0-9]{32}",identity)
            or home!="https://emmake.com/"):
        raise ValueError("host_preflight_configuration_required")
    challenge={"site":"emmake-produccion","home":home,"connection_id":identity,"nonce":secrets.token_hex(32)}
    args=["ssh","-F","/dev/null","-T","-i",key,"-p",port,
          "-o","BatchMode=yes","-o","IdentitiesOnly=yes","-o","StrictHostKeyChecking=yes",
          "-o","UserKnownHostsFile="+known_hosts,"-o","ConnectTimeout=10",
          "-o","ClearAllForwardings=yes","-o","RequestTTY=no",
          user+"@"+host,"site-manager-preflight-v1"]
    # Bound output on disk instead of capturing unbounded host output in memory.
    import tempfile
    with tempfile.TemporaryFile() as raw:
        run=subprocess.run(args,input=json.dumps(challenge).encode(),stdout=raw,stderr=subprocess.DEVNULL,timeout=45,check=False)
        raw.seek(0)
        data=raw.read(8193)
    if run.returncode!=0 or len(data)>8192:
        raise ValueError("host_preflight_transport_failed")
    receipt=verify_receipt(json.loads(data),challenge)
    Path(output).write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n",encoding="utf-8")
    print("HOST_INDEPENDENT_PREFLIGHT=VERIFIED")

if __name__=="__main__":
    try:
        main()
    except Exception:
        print("HOST_PREFLIGHT_FAILED: check protected configuration and installed worker",file=sys.stderr)
        sys.exit(1)
