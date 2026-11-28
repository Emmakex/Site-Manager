#!/usr/bin/env python3
from pathlib import Path
from hashlib import sha256
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"scripts"))
from site_manager_host_preflight import verify_receipt

challenge={"site":"emmake-produccion","home":"https://emmake.com/","connection_id":"smc_"+"a"*32,"nonce":"b"*64}
receipt={"schema":"site-manager-host-preflight-v1","site":challenge["site"],"home":challenge["home"],
         "nonce":challenge["nonce"],"connection_sha256":sha256(challenge["connection_id"].encode()).hexdigest(),
         "plugin_version":"0.12.3","tree_sha256":"c"*64,"php_version":"8.2.29","wordpress_version":"6.9",
         "plugin_bypassed":True,"active":True,"zip_available":True,"private_temp_available":True,
         "plugin_dir_writable":False,"file_mod_allowed":True,"environment":"production","mutation_performed":False}
out=verify_receipt(receipt,challenge)
assert out["independent_access_verified"] and not out["production_update_authorized"]
assert out["readiness_checks"]["plugin_dir_writable"] is False
assert "nonce" not in out and "connection_sha256" not in out
for k,v in (("nonce","d"*64),("site","emmake-nuevaweb"),("home","https://emmake.com/nuevaweb/"),
            ("connection_sha256","e"*64),("plugin_bypassed",False),("active",False),
            ("mutation_performed",True),("zip_available",1),("tree_sha256","bad")):
    try:
        verify_receipt({**receipt,k:v},challenge)
    except ValueError:
        pass
    else:
        raise AssertionError("accepted invalid host receipt: "+k)
try:
    verify_receipt({**receipt,"path":"/private/path"},challenge)
except ValueError:
    pass
else:
    raise AssertionError("accepted unexpected private field")
print("HOST_PREFLIGHT_RECEIPT_CONTRACT=PASSED")
