#!/usr/bin/env python3
"""Verify first-adoption Gateway safety without involving a WordPress site."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
spec = importlib.util.spec_from_file_location("site_manager_command_runner", ROOT / "scripts/site-manager-command-runner.py")
assert spec is not None and spec.loader is not None
mod = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = mod
spec.loader.exec_module(mod)

registry = mod.load_json(mod.REGISTRY_PATH)
mod.validate_registry(registry)
fields = {
    "hero-eyebrow": "Digital consultancy",
    "hero-lead": "Responsible services for real organizations",
    "hero-primary-cta": {"label": "Contact us", "url": "https://example.test/staging/contact/"},
}
base = {
    "version":"1", "command_id":"cmd-test-bootstrap-0001",
    "site":"acceptance-site", "action":"theme.bootstrap",
    "requested_by":"chatgpt",
}
command = {**base, "payload":{"stage":"plan","fields":fields}}
mod.validate_command(command, registry)
for invalid in (
    {"stage":"plan","fields":{"hero-lead":"<script>leak</script>"}},
    {"stage":"plan","fields":{"hero-lead":["arbitrary nested data"]}},
    {"stage":"plan","post_id":23,"fields":fields},
    {"stage":"plan","theme":"seo-geo-theme","fields":fields},
    {"stage":"plan","fields":{"hero-primary-cta":{"label":"Bad","url":"javascript:alert(1)"}}},
    {"stage":"plan","fields":{"hero-primary-cta":{"label":"Bad","url":"https://user@host.test/"}}},
    {"stage":"apply","plan_id":"sm-plan-test","confirmed":False},
    {"stage":"apply","plan_id":"sm-plan-test","confirmed":True,"fields":fields},
    {"stage":"rollback","plan_id":"sm-plan-test","confirmed":"true"},
):
    try:
        mod.validate_command({**base,"payload":invalid},registry)
    except mod.CommandError:
        pass
    else:
        raise AssertionError("An unsafe first-adoption Gateway payload was accepted")

plan = {
    "plan_id":"sm-plan-bootstrap123", "status":"planned",
    "capability_id":"content.write", "operation":"theme.bootstrap",
    "target":{"post_id":23,"model_id":"corporate-home-v1"},
    "fields":list(fields), "confirmation_required":True, "reversible":True,
}
preview = {
    "source":"seo-geo-theme-semantic-bootstrap",
    "target_post_id":23, "model_id":"corporate-home-v1",
    "slot_ids":list(fields),"slot_count":len(fields),
    "post_content_unchanged":True,
    "proof_claims_included":False,
    "requires_editorial_confirmation":True,
    "values_included":False,
}
response = {"plan":plan, "preview":preview}
assert mod.verify_bootstrap_response("plan",response,command["payload"]) is False
# Live WP Site Manager 0.11.11 emits a field => true marker map, not a string list.
wp_preview = {**preview, "slot_ids":{name:True for name in fields}}
wp_response = {"plan":plan, "preview":wp_preview}
assert mod.verify_bootstrap_response("plan",wp_response,command["payload"]) is False
wp_safe = mod.sanitize_bootstrap_evidence("plan",wp_response,"secret-token")
assert wp_safe["preview"]["slot_ids"] == list(fields)
assert all(isinstance(id, str) for id in wp_safe["preview"]["slot_ids"])
for poisoned_markers in (
    {"hero-eyebrow":"Private WP content", **{k:True for k in fields if k!="hero-eyebrow"}},
    {"hero-eyebrow":1, **{k:True for k in fields if k!="hero-eyebrow"}},
    {**{k:True for k in fields}, "unapproved":True},
    {**{k:True for k in fields}, "hero-lead":False},
):
    try:
        mod.verify_bootstrap_response("plan",{"plan":plan,"preview":{**preview,"slot_ids":poisoned_markers}},command["payload"])
    except mod.VerificationError:
        pass
    else:
        raise AssertionError("Unreviewed, leaky or nonboolean WordPress field map accepted")
safe = mod.sanitize_bootstrap_evidence("plan",response,"secret-token")
assert safe["preview"]["values_retained_in_result"] is False
assert all(x not in str(safe) for x in ["Digital consultancy","Responsible services","contact/","secret-token"])
for poison in (
    {**preview,"values_included":True},
    {**preview,"slot_ids":["attacker-field"]},
    {**preview,"post_content_unchanged":False},
):
    try:
        mod.verify_bootstrap_response("plan",{**response,"preview":poison},command["payload"])
    except mod.VerificationError:
        pass
    else:
        raise AssertionError("Unverified or leaky bootstrap preview accepted")

applied = {
    "plan":{**plan,"status":"applied"}, "status":"verified",
    "execution":{"success":True}, "verification":{"verified":True,"status":"verified"},
    "rollback_available":True,
}
change = {"stage":"apply","plan_id":plan["plan_id"],"confirmed":True}
mod.validate_command({**base,"payload":change},registry)
assert mod.verify_bootstrap_response("apply",applied,change) is True
rollback = {
    "plan":{**plan,"status":"rolled-back"}, "status":"rolled-back",
    "rollback":{"success":True},
}
revert = {"stage":"rollback","plan_id":plan["plan_id"],"confirmed":True}
mod.validate_command({**base,"payload":revert},registry)
assert mod.verify_bootstrap_response("rollback",rollback,revert) is True
print("PASS: Theme adoption Gateway contract, closed scope, preview and verified rollback")
