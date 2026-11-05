#!/usr/bin/env python3
"""Contract tests for the bounded, read-only Site Manager operator overview."""
from __future__ import annotations

import copy
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from site_manager_site_overview import OverviewError, summarize_site_overview  # noqa: E402


def live_shape() -> dict:
    return {
        "connection_id": "smc-test-connection",
        "discovery": {
            "fingerprint": "a" * 64,
            "generated_at": "2026-11-05T18:32:33+00:00",
            "platform": {
                "platform": "wordpress",
                "version": "7.1.4",
                "metadata": {"locale": "es_ES", "secret_runtime_password": "do-not-leak"},
            },
            "inventory": {
                "urls": {"site": "https://example.test/client/", "rest": "https://example.test/client/wp-json/"},
                "theme": {"name": "Sample Theme", "stylesheet": "sample-theme"},
                "plugins": {
                    "items": {
                        "site-manager/site-manager.php": {"name": "Site Manager", "version": "0.11.6"},
                    },
                },
                "indexability": {"search_visibility": "discourage"},
                "seo_authority": {
                    "authority_resolved": True, "conflict": False,
                    "authority": {"id": "seo-geo-theme", "private_configuration": "do-not-leak"},
                    "writable_inputs": {
                        "title": {"source": "post_title"},
                        "description": {"source": "post_excerpt", "private_value": "do-not-leak"},
                    },
                },
                "navigation": {
                    "providers": [
                        {"id": "wordpress-classic-menu", "detected": True, "actionable": True, "target_count": 4},
                    ],
                    "classic_menus": [
                        {"name": "Private menu text should not leak", "item_ids": [123, 456]},
                    ],
                },
                "private_post_content": "do-not-leak",
            },
        },
        "capabilities": {
            "capabilities": [
                {"id": "site.read", "detected": True, "understood": True, "governable": True,
                 "provider": "wordpress-core", "required_scopes": ["site.read"], "blockers": []},
                {"id": "content.write", "detected": True, "understood": True, "governable": True,
                 "provider": "wordpress-core", "required_scopes": ["content.write"],
                 "blockers": []},
                {"id": "navigation.write", "detected": True, "understood": True, "governable": False,
                 "provider": "wordpress-core", "required_scopes": ["navigation.write"],
                 "blockers": ["sensitive provider detail do-not-leak"]},
                {"id": "seo.write", "detected": True, "understood": True, "governable": True,
                 "provider": "seo-geo-theme", "required_scopes": ["seo.write"],
                 "blockers": []},
            ],
        },
    }


read = live_shape()
overview = summarize_site_overview(read)
assert overview["source"] == "live-site-read"
assert overview["site_url"] == "https://example.test/client/"
assert overview["platform"] == {"name": "wordpress", "version": "7.1.4", "locale": "es_ES"}
assert overview["wordpress"]["site_manager_version"] == "0.11.6"
assert overview["search_visibility"] == "discourage"
assert overview["warnings"] == ["wordpress_discourages_search_engine_indexing"]
assert overview["seo"]["provider"] == "seo-geo-theme"
assert overview["seo"]["writable_semantic_fields"] == ["description", "title"]
assert overview["navigation_providers"][0]["target_count"] == 4
assert len(overview["capabilities"]) == 4
assert all(cap["scope_grant_verified"] is False for cap in overview["capabilities"])
assert [action["action"] for action in overview["governed_actions"]] == [
    "content.update", "navigation.update", "seo.update",
]
assert [action["capability_governable"] for action in overview["governed_actions"]] == [
    True, False, True,
]
assert all(action["scope_grant_verified"] is False for action in overview["governed_actions"])
assert all(action["planning_mutates_wordpress"] is False for action in overview["governed_actions"])
assert all(action["apply_requires_separate_user_confirmation"] is True
           for action in overview["governed_actions"])
assert "permission_notice" in overview
assert "Capability governable is not a grant of permission" in overview["permission_notice"]
assert "authorization_note" not in overview
# The command Gateway redacts keys containing authorization, token, secret,
# password, or cookie; the ordinary non-secret permission note must survive.
from importlib.util import module_from_spec, spec_from_file_location
runner_spec = spec_from_file_location("sm_command_runner", Path(__file__).resolve().parents[1] / "scripts" / "site-manager-command-runner.py")
assert runner_spec is not None and runner_spec.loader is not None
runner_module = module_from_spec(runner_spec)
runner_spec.loader.exec_module(runner_module)
assert runner_module.sanitize_value(overview, "")["permission_notice"] == overview["permission_notice"]
assert "do-not-leak" not in str(overview)
assert "Private menu text" not in str(overview)
assert "123" not in str(overview)

conflict = copy.deepcopy(read)
conflict["discovery"]["inventory"]["seo_authority"]["conflict"] = True
conflict_result = summarize_site_overview(conflict)
assert conflict_result["seo"]["provider"] is None
assert conflict_result["seo"]["writable_semantic_fields"] == []
assert "seo_provider_ownership_unresolved" in conflict_result["warnings"]

no_url = copy.deepcopy(read)
no_url["discovery"]["inventory"]["urls"]["site"] = "https://user:secret@example.test/?token=unsafe"
assert summarize_site_overview(no_url)["site_url"] is None

other = copy.deepcopy(read)
other["discovery"]["platform"] = {"platform": "prestashop", "version": "8.2"}
other["discovery"]["inventory"]["theme"] = {"name": "Other CMS"}
other_result = summarize_site_overview(other)
assert other_result["platform"]["name"] == "prestashop"
assert other_result["wordpress"] is None

no_seo = copy.deepcopy(read)
del no_seo["discovery"]["inventory"]["seo_authority"]
assert summarize_site_overview(no_seo)["seo"]["provider"] is None

for mutate in (
    lambda x: x.update(connection_id=""),
    lambda x: x["discovery"].pop("fingerprint"),
    lambda x: x["discovery"].update(inventory=None),
    lambda x: x["capabilities"].update(capabilities=[]),
    lambda x: x["capabilities"]["capabilities"].append(
        copy.deepcopy(x["capabilities"]["capabilities"][0])),
    lambda x: x["capabilities"]["capabilities"][0].update(required_scopes=["site.read", 42]),
    lambda x: x["capabilities"]["capabilities"][0].update(blockers="unsafe"),
    lambda x: x["capabilities"]["capabilities"][0].update(id="../arbitrary"),
):
    bad = copy.deepcopy(read)
    mutate(bad)
    try:
        summarize_site_overview(bad)
    except OverviewError:
        pass
    else:
        raise AssertionError("malformed or ambiguous site.read was treated as valid overview")

print("site_manager_overview_test: OK")
