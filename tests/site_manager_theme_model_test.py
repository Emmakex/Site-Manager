#!/usr/bin/env python3
"""Only Theme structural slots cross Site Manager's GitHub audit boundary."""
from __future__ import annotations

import copy
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from site_manager_theme_model import ThemeModelError, summary


def fixture():
    return {
        "connection_id":"smc_"+"a"*32,
        "source":"seo-geo-theme-corporate-model",
        "site_url":"https://emmake.com/nuevaweb/",
        "scope":"site.read",
        "observed_at":"2026-11-11T12:30:00+00:00",
        "post_id":7,"post_type":"page","post_status":"publish","homepage":True,
        "model_id":"corporate-home-v1","model_source":"hydrated-semantic-blocks",
        "slot_count":2,
        "slots":[
            {"id":"hero-lead","type":"text","required":True,"present":True,"writable_via_site_manager":False},
            {"id":"hero-primary-cta","type":"link","required":True,"present":True,"writable_via_site_manager":False},
        ],
        "public_values_included":False,"write_scope_verified":False,"editing_ready":False,
        "note":"Private raw hero content must not leave WordPress",
    }


good = summary(fixture())
assert good["slot_count"] == 2
assert good["post_id"] == 7
assert good["editing_ready"] is False
assert good["contains_content_values"] is False
assert good["write_scope_verified"] is False
assert "Private raw hero content" not in str(good)
assert "connection_id" not in good
assert good["slots"][0]["id"] == "hero-lead"

for mutation in (
    lambda x: x.update(site_url="https://attacker.invalid/"),
    lambda x: x.update(site_url="http://emmake.com/nuevaweb/"),
    lambda x: x.update(connection_id=""),
    lambda x: x.update(scope="content.write"),
    lambda x: x.update(model_id="free-form-template"),
    lambda x: x.update(editing_ready=True),
    lambda x: x.update(write_scope_verified=True),
    lambda x: x.update(public_values_included=True),
    lambda x: x.update(homepage=False),
    lambda x: x.update(post_status="draft"),
    lambda x: x.update(post_id=True),
    lambda x: x.update(slot_count=5000),
    lambda x: x.update(observed_at="2026-11-11"),
    lambda x: x.update(content="secret Gutenberg blocks"),
    lambda x: x["slots"][0].update(value="private copy"),
    lambda x: x["slots"][0].update(type="html"),
    lambda x: x["slots"][0].update(present=False),
    lambda x: x["slots"][0].update(writable_via_site_manager=True),
    lambda x: x["slots"][0].update(id="bad/slug"),
    lambda x: x["slots"][1].update(id="hero-lead"),
    lambda x: x["slots"].append({}),
):
    bad = copy.deepcopy(fixture())
    mutation(bad)
    try:
        summary(bad)
    except ThemeModelError:
        pass
    else:
        raise AssertionError("Theme slot inspector accepted ambiguous or private response")

print("site_manager_theme_model_test: OK")
