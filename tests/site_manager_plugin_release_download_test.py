#!/usr/bin/env python3
from copy import deepcopy
from hashlib import sha256
from io import BytesIO
from pathlib import Path
import sys
import zipfile
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"scripts"))
from site_manager_plugin_release_download import fetch_official_plugin_release, PluginReleaseError

version="0.12.4"
commit="a"*40
name="site-manager-"+version+".zip"
package=b"fixture package bytes"*20
digest=sha256(package).hexdigest()
def wrapper(entries):
    buf=BytesIO()
    with zipfile.ZipFile(buf,"w") as z:
        for path,value in entries:
            z.writestr(path,value)
    return buf.getvalue()
outer=wrapper([(name,package),(name+".sha256",f"{digest}  {name}\n")])
run={"id":21,"repository":{"full_name":"Emmakex/Site-Manager"},
     "head_repository":{"full_name":"Emmakex/Site-Manager"},"head_sha":commit,
     "event":"push","head_branch":"main","path":".github/workflows/wordpress-package.yml",
     "status":"completed","conclusion":"success","run_attempt":1}
artifact={"id":22,"name":"site-manager-wordpress-"+version,"expired":False,
          "size_in_bytes":len(outer),"workflow_run":{"id":21,"head_sha":commit}}
def fetch(r=run,a=artifact,b=outer,**overrides):
    def metadata(url):
        assert url.startswith("https://api.github.com/repos/Emmakex/Site-Manager/actions/")
        return r if "/runs/" in url else a
    def binary(url):
        assert url.endswith("/artifacts/22/zip")
        return b
    kwargs={"version":version,"commit_sha":commit,"run_id":21,"artifact_id":22,
            "package_sha256":digest,"github_token":"fixture","fetch_metadata":metadata,"fetch_binary":binary}
    return fetch_official_plugin_release(**{**kwargs,**overrides})
def reject(fn):
    try:
        fn()
    except PluginReleaseError:
        return
    raise AssertionError("untrusted plugin release accepted")
assert fetch()==package
for k,v in (("event","pull_request"),("head_branch","release/0.12.4"),
            ("head_repository",{"full_name":"fork/Site-Manager"}),("head_sha","b"*40),
            ("path",".github/workflows/unknown.yml"),("conclusion","failure"),("run_attempt",2)):
    reject(lambda k=k,v=v:fetch(r={**run,k:v}))
for k,v in (("expired",True),("name","arbitrary-plugin"),("workflow_run",{"id":21}),("size_in_bytes",10100001)):
    reject(lambda k=k,v=v:fetch(a={**artifact,k:v}))
reject(lambda:fetch(b=wrapper([(name,package),(name+".sha256","bad")])))
reject(lambda:fetch(b=wrapper([(name,package),(name+".sha256",f"{digest}  {name}\n"),("../extra",b"x")])))
reject(lambda:fetch(version="../0.12.4"))
reject(lambda:fetch(package_sha256="b"*64))
print("OFFICIAL_PLUGIN_RELEASE_SOURCE_CONTRACT=PASSED")
