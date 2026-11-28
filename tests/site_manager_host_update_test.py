#!/usr/bin/env python3
"""Contract for fixed host updater request and response; no real hosting access."""
import sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"scripts"))
from site_manager_host_update import validate,check_receipt
def reject(fn):
    try: fn()
    except ValueError: return
    raise AssertionError("unexpected acceptance")
src={"commit_sha":"a"*40,"run_id":123,"artifact_id":456}
p={"version":"0.12.4","package_sha256":"b"*64,"source":src}
request={"site":"emmake-produccion","stage":"plan","request_id":"host-update-contract-001","payload":p}
assert validate(request)==("plan",p)
reject(lambda:validate({**request,"site":"emmake-nuevaweb"}))
reject(lambda:validate({**request,"payload":{**p,"url":"https://other.invalid"}}))
reject(lambda:validate({**request,"payload":{**p,"source":{**src,"artifact_id":True}}}))
apply={"plan_id":"c"*32,"package_sha256":"b"*64,"confirmed":True}
assert validate({**request,"stage":"apply","payload":apply})==("apply",apply)
reject(lambda:validate({**request,"stage":"apply","payload":{**apply,"confirmed":False}}))
reject(lambda:validate({**request,"stage":"apply","payload":{**apply,"package_path":"/tmp/evil"}}))
challenge={"nonce":"d"*64}
header={"schema":"site-manager-host-update-v1","site":request["site"],"nonce":challenge["nonce"]}
plan={**header,**p,"plan_id":"c"*32,"expires_at":1234567890,"status":"planned","current_version":"0.12.3","before_tree":"e"*64,"after_tree":"f"*64,"verified":False,"mutation_performed":False,"confirmation_required":True}
assert check_receipt("plan",plan,challenge,p)["plan_id"]==apply["plan_id"]
for k,v in (("nonce","a"*64),("site","emmake-nuevaweb"),("package_sha256","a"*64),("mutation_performed",True),("verified",True)):
    reject(lambda:check_receipt("plan",{**plan,k:v},challenge,p))
reject(lambda:check_receipt("plan",{**plan,"private_path":"/secret"},challenge,p))
operation={"operation_id":"a"*32}
runtime={**header,**operation,"status":"installed_runtime_verified_local","version":"0.12.4","tree_sha256":"f"*64,"local_verified":True,"remote_verified":False,"rollback_available":True}
assert not check_receipt("verify",runtime,challenge,operation)["remote_verified"]
reject(lambda:check_receipt("verify",{**runtime,"remote_verified":True},challenge,operation))
reject(lambda:check_receipt("verify",{**runtime,"operation_id":"b"*32},challenge,operation))
print("GOVERNED_HOST_UPDATE_CONTRACT=PASSED")
