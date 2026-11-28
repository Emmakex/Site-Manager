#!/usr/bin/env python3
"""Run the actual private forced command entrypoint against disposable WP."""
import base64, hashlib, json, subprocess, sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"scripts"))
from site_manager_host_update import check_receipt

container=sys.argv[1]
def docker(args,raw=None,check=True):
    return subprocess.run(["docker","exec","-i","--user","www-data",*args[:0],container,*args],input=raw,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=check)
# The previous preflight fixture already configured the private worker and a
# real site identity/connection. Do not select any production host or data.
setup=r'''
require "/var/www/html/wp-load.php";
if (wp_get_environment_type() !== "staging" || get_option("blog_public") !== "0") { exit(1); }
$dir="/tmp/sm-host-preflight/";
$admin=get_user_by("login","testadmin");
file_put_contents($dir."update-authority.json",json_encode(["enabled"=>true,"administrator_id"=>$admin->ID]));
chmod($dir."update-authority.json",0600);
$state=get_option("site_manager_remote_connection");
echo json_encode(["connection_id"=>$state["connection_id"],"version"=>SITE_MANAGER_VERSION]);
'''
config=json.loads(docker(["php","-r",setup]).stdout)
current=config["version"]
a,b,c=current.split(".")
candidate=f"{a}.{b}.{int(c)+1}"
build=r'''
require "/var/www/html/wp-load.php";
if (wp_get_environment_type() !== "staging") { exit(1); }
$root=WP_PLUGIN_DIR."/site-manager";
$path="/tmp/sm-host-preflight/test-candidate.zip";
$zip=new ZipArchive();
if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) { exit(1); }
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $entry) {
    if (!$entry->isFile()) { continue; }
    $name=substr($entry->getPathname(),strlen($root)+1);
    $bytes=file_get_contents($entry->getPathname());
    if ($name==="site-manager.php") {
        $bytes=str_replace(SITE_MANAGER_VERSION,$argv[1],$bytes);
        $bytes=str_replace("if (! defined('ABSPATH')) { exit; }","if (! defined('ABSPATH')) { exit; }\nthrow new RuntimeException('HOST_WORKER_BROKEN_FIXTURE');",$bytes);
    }
    if (!$zip->addFromString("site-manager/".$name,$bytes)) { exit(1); }
}
if (!$zip->close()) { exit(1); }
echo base64_encode(file_get_contents($path));
unlink($path);
'''
blob=base64.b64decode(docker(["php","-r",build,candidate]).stdout,validate=True)
challenge={"site":"fixture-site","home":"https://fixture.invalid/","connection_id":config["connection_id"],"nonce":"a"*64}
def invoke(stage,p,ok=True):
    result=subprocess.run(["docker","exec","-i","--user","www-data","-e","SSH_ORIGINAL_COMMAND=site-manager-update-"+stage+"-v1",container,"php","/tmp/sm-host-preflight/preflight.php"],input=json.dumps({**challenge,**p}).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if not ok:
        assert result.returncode!=0, "unexpected accepted stage "+stage
        return
    if result.returncode!=0:
        raise AssertionError("failed stage "+stage+": "+result.stderr.decode(errors="replace")[-500:])
    data=json.loads(result.stdout)
    assert data["site"]==challenge["site"] and data["nonce"]==challenge["nonce"]
    return data
p={"version":candidate,"package_sha256":hashlib.sha256(blob).hexdigest(),"source":{"commit_sha":"a"*40,"run_id":123,"artifact_id":456},"package_base64":base64.b64encode(blob).decode()}
plan=invoke("plan",p)
assert plan["status"]=="planned" and not plan["mutation_performed"]
assert invoke("status",{})["status"]=="idle"
invoke("apply",{"plan_id":plan["plan_id"],"package_sha256":p["package_sha256"],"confirmed":False},False)
invoke("apply",{"plan_id":"f"*32,"package_sha256":p["package_sha256"],"confirmed":True},False)
result=invoke("apply",{"plan_id":plan["plan_id"],"package_sha256":p["package_sha256"],"confirmed":True})
assert result["status"]=="installed_pending_runtime" and result["verified"] is False
operation=result["operation_id"]
invoke("verify",{"operation_id":operation},False)
status=invoke("status",{})
assert status["operation_id"]==operation and status["status"]=="installed_pending_runtime"
invoke("restore",{"operation_id":"f"*32,"confirmed":True},False)
restored=invoke("restore",{"operation_id":operation,"confirmed":True})
assert restored["status"]=="restored_pending_runtime"
verified=invoke("verify",{"operation_id":operation})
assert verified["version"]==current and verified["local_verified"] is True and verified["remote_verified"] is False
final=invoke("finalize",{"operation_id":operation,"confirmed":True})
assert final["status"]=="finalized_local" and final["archive_removed"] is True
assert invoke("status",{})["status"]=="idle"
print("DISPOSABLE_WP_GOVERNED_HOST_UPDATE_RECOVERY=PASSED")
