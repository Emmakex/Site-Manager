#!/usr/bin/env python3
"""SM-4L fail-closed published-only content target Gateway contract."""
from __future__ import annotations

import copy
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from site_manager_content_targets import (  # noqa: E402
    ContentTargetsError, summarize_content_targets,
)


def valid_response() -> dict:
    return {
        "connection_id": "smc_" + "c" * 32,
        "source": "wordpress-native-published-content",
        "observed_at": "2026-11-06T09:33:00+00:00",
        "site_url": "https://emmake.com/nuevaweb/",
        "homepage_post_id": 7,
        "scope": "site.read",
        "published_only": True,
        "page_limit": 80,
        "post_limit": 40,
        "pages": [{
            "post_id": 7, "post_type": "page", "status": "publish",
            "title": "Inicio EMMAKE", "slug": "inicio",
            "permalink": "https://emmake.com/nuevaweb/",
            "parent_id": 0, "homepage": True,
            "modified_gmt": "2026-11-06 09:00:00",
        }],
        "posts": [{
            "post_id": 9, "post_type": "post", "status": "publish",
            "title": "Artículo público", "slug": "articulo-publico",
            "permalink": "https://emmake.com/nuevaweb/blog/articulo-publico/",
            "parent_id": 0, "homepage": False,
            "modified_gmt": "2026-11-06 09:00:00",
        }],
        "pages_truncated": False, "posts_truncated": False,
        "private_values_included": False,
    }


one = summarize_content_targets(valid_response())
assert one["homepage_post_id"] == 7
assert one["homepage_in_inventory"] is True
assert one["page_count_in_inventory"] == 1
assert one["post_count_in_inventory"] == 1
assert one["private_content_included"] is False
assert one["pages"][0]["title"] == "Inicio EMMAKE"
assert one["posts"][0]["post_id"] == 9
assert one["permission_note"].startswith("Published metadata only")

for mutate in (
    lambda x: x.update(scope="content.write"),
    lambda x: x.update(published_only=False),
    lambda x: x.update(private_values_included=True),
    lambda x: x.update(page_limit=10000),
    lambda x: x.update(posts_truncated="false"),
    lambda x: x.update(connection_id="wrong_connection"),
    lambda x: x.update(site_url="https://emmake.com@attacker.example/"),
    lambda x: x.update(observed_at="today"),
    lambda x: x["pages"][0].update(status="draft"),
    lambda x: x["pages"][0].update(post_id=0),
    lambda x: x["pages"][0].update(title="Hidden\nprivate"),
    lambda x: x["pages"][0].update(permalink="https://attacker.example/nuevaweb/"),
    lambda x: x["pages"][0].update(permalink="https://emmake.com/other-site/"),
    lambda x: x["pages"][0].update(content="SECRET PAGE BODY"),
    lambda x: x["posts"][0].update(post_id=7),
    lambda x: x["posts"][0].update(post_type="page"),
    lambda x: x["posts"][0].update(homepage=True),
    lambda x: x["pages"][0].update(homepage=False),
    lambda x: x.update(private_editor_notes="PRIVATE"),
):
    bad = valid_response()
    mutate(bad)
    try:
        summarize_content_targets(bad)
    except ContentTargetsError:
        pass
    else:
        raise AssertionError("unsafe published-target result was certified")

overlimit = valid_response()
overlimit["pages"] = [copy.deepcopy(overlimit["pages"][0]) for _ in range(81)]
try:
    summarize_content_targets(overlimit)
except ContentTargetsError:
    pass
else:
    raise AssertionError("overlong inventory accepted")

empty = valid_response()
empty["pages"] = []
empty["posts"] = []
result = summarize_content_targets(empty)
assert result["homepage_in_inventory"] is False
assert result["page_count_in_inventory"] == 0

print("site_manager_content_targets_test: OK")
