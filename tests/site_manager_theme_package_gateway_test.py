#!/usr/bin/env python3
"""Gateway Theme package strict business-action and WordPress evidence acceptance."""
from __future__ import annotations

from copy import deepcopy
import importlib.util
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT / "scripts"))
spec = importlib.util.spec_from_file_location(
    "site_manager_command_runner", ROOT / "scripts/site-manager-command-runner.py")
assert spec is not None and spec.loader is not None
runner = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = runner
spec.loader.exec_module(runner)

from site_manager_theme_package_evidence import (  # noqa: E402
    ThemePackageEvidenceError, verify_theme_package_evidence,
)
from site_manager_theme_package_gateway import (  # noqa: E402
    ThemePackageGatewayError, trusted_release_policy,
)

def reject(fn, exception, code: str | None = None):
    try:
        fn()
    except exception as e:
        if code:
            assert str(e) == code, (str(e),code)
        return
    raise AssertionError(f"Expected {exception}")

registry = runner.load_json(runner.REGISTRY_PATH)
runner.validate_registry(registry)
assert registry["actions"]["theme.package.deploy"] == {
    "tools": {"plan":"theme_package_plan","apply":"theme_package_apply","rollback":"theme_package_rollback"},
    "required_scope":"theme.package.write", "read_only":False,
}
policy = trusted_release_policy("seo-geo-theme")
assert policy.repository == "Emmakex/template-wordpress-seo-geo"
assert policy.workflow_path == ".github/workflows/release-artifact.yml"
assert policy.branch == "main"
reject(lambda: trusted_release_policy("random-theme"), ThemePackageGatewayError, "theme_release_not_authorized")
reject(lambda: trusted_release_policy("../../wp-config.php"), ThemePackageGatewayError, "invalid_governed_theme_slug")

command = {
    "version":"1", "command_id":"cmd-test-package-0001", "site":"emmake-nuevaweb",
    "action":"theme.package.deploy", "requested_by":"chatgpt",
    "payload": {
        "stage":"plan", "theme_slug":"seo-geo-theme",
        "source_commit_sha":"a"*40, "source_run_id":123,
        "source_artifact_id":321, "package_sha256":"b"*64,
    },
}
runner.validate_command(command, registry)
for bad in (
    {**command["payload"],"url":"https://attacker.test/latest.zip"},
    {**command["payload"],"package_base64":"ZmFrZQ=="},
    {**command["payload"],"script":"touch /tmp/owned"},
    {**command["payload"],"theme_slug":"other-plugin/"},
    {**command["payload"],"source_run_id":True},
    {**command["payload"],"package_sha256":"f"*63},
    {"stage":"apply", "plan_id":"sm-plan-"+"c"*24, "confirmed":"true"},
    {"stage":"apply", "plan_id":"sm-plan-"+"c"*24, "confirmed":True, "path":"/private"},
    {"stage":"rollback", "plan_id":"sm-plan-"+"c"*24, "confirmed":True, "theme_slug":"seo-geo-theme"},
):
    reject(lambda b=bad: runner.validate_command({**command,"payload":b},registry), runner.CommandError)

pid = "sm-plan-"+"c"*24
plan = {
    "plan_id":pid, "status":"planned", "capability_id":"theme.package.write",
    "operation":"theme.package.deploy", "target":{"theme_slug":"seo-geo-theme"},
    "reversible":True, "confirmation_required":True, "expires_at":"2026-11-13T20:00:00+00:00",
}
preview = {
    "theme_slug":"seo-geo-theme", "before_tree_sha256":"1"*64,
    "after_tree_sha256":"2"*64, "package_sha256":"b"*64,
    "theme_version":"0.1.1", "file_count":120,
    "source_commit_sha":"a"*40, "source_run_id":123,
    "source_artifact_id":321, "confirmation_required":True,
    "values_or_paths_included":False,
}
planned = {"stage":"plan","status":"planned","plan":plan,"preview":preview}
verified, public = verify_theme_package_evidence("plan",planned,command["payload"])
assert verified is False
assert public["plan"]["plan_id"] == pid
assert public["preview"]["after_tree_sha256"] == "2"*64
assert "package_base64" not in json.dumps(public)
assert "path" not in json.dumps(public)
for key,value in (
    ("source_run_id",999), ("source_artifact_id",777),
    ("package_sha256","0"*64),("theme_slug","another-theme"),
    ("values_or_paths_included",True),
):
    wrong=deepcopy(planned)
    wrong["preview"][key]=value
    reject(lambda w=wrong: verify_theme_package_evidence("plan",w,command["payload"]),
           ThemePackageEvidenceError)
wrong = deepcopy(planned)
wrong["preview"]["staged_path"] = "/tmp/secret"
reject(lambda: verify_theme_package_evidence("plan",wrong,command["payload"]),
       ThemePackageEvidenceError)

applied = {
    "stage":"apply", "status":"verified","verified":True,
    "plan":{**plan,"status":"applied"},
    "verification":{"verified":True,"status":"verified","installed_tree_sha256":"2"*64},
    "rollback_available":True,"recovered":False,
}
changed={"stage":"apply","plan_id":pid,"confirmed":True}
assert verify_theme_package_evidence("apply",applied,changed)[0] is True
for key,value in (
    ("verified",False),("status","rolled_back"),("rollback_available",False),
):
    bad=deepcopy(applied)
    bad[key]=value
    reject(lambda v=bad: verify_theme_package_evidence("apply",v,changed),
           ThemePackageEvidenceError)
bad=deepcopy(applied)
bad["verification"]["installed_tree_sha256"]="unsafe"
reject(lambda: verify_theme_package_evidence("apply",bad,changed),ThemePackageEvidenceError)
reject(lambda: verify_theme_package_evidence("apply",applied,{**changed,"plan_id":"sm-plan-"+"d"*24}),
       ThemePackageEvidenceError)

rollback={
    "stage":"rollback","status":"rolled_back","verified":True,
    "plan":{**plan,"status":"rolled-back"},
    "restoration":{"verified":True,"tree_sha256":"1"*64},
}
assert verify_theme_package_evidence("rollback",rollback,
       {"stage":"rollback","plan_id":pid,"confirmed":True})[0] is True
bad=deepcopy(rollback)
bad["restoration"]["tree_sha256"]="wrong"
reject(lambda: verify_theme_package_evidence("rollback",bad,changed),ThemePackageEvidenceError)
bad=deepcopy(rollback)
bad["restoration"]["path"]="/tmp/secret"
reject(lambda: verify_theme_package_evidence("rollback",bad,changed),ThemePackageEvidenceError)
print("Theme package Gateway contract: OK (closed commands, official policy, all stages, exact WP evidence, sanitized result)")
