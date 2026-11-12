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
        "published_page_total": 1, "published_post_total": 1,
        "private_values_included": False,
    }


one = summarize_content_targets(valid_response())
assert one["homepage_post_id"] == 7
assert one["homepage_in_inventory"] is True
assert one["page_count_in_inventory"] == 1
assert one["post_count_in_inventory"] == 1
assert one["published_page_total"] == 1
assert one["published_post_total"] == 1
assert one["inventory_is_sample"] is False
assert one["published_totals_source"] == "wordpress-native-count"

legacy = valid_response()
legacy.pop("published_page_total")
legacy.pop("published_post_total")
legacy["posts_truncated"] = True
legacy_one = summarize_content_targets(legacy)
assert legacy_one["published_post_total"] is None
assert legacy_one["post_count_in_inventory"] == 1
assert legacy_one["inventory_is_sample"] is True
assert legacy_one["published_totals_source"] == "unavailable-legacy-plugin"

bad_half = valid_response()
bad_half.pop("published_page_total")
try:
    summarize_content_targets(bad_half)
except ContentTargetsError:
    pass
else:
    raise AssertionError("half-upgraded WordPress schema must be rejected")

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
    lambda x: x.update(published_post_total="1726"),
    lambda x: x.update(published_post_total=-1),
    lambda x: x.update(published_page_total=0),
    lambda x: x.update(published_post_total=0),
    lambda x: x.update(published_post_total=1726, posts_truncated=False),

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

large = valid_response()
large["published_post_total"] = 1726
large["posts_truncated"] = True
summarized = summarize_content_targets(large)
assert summarized["published_post_total"] == 1726
assert summarized["post_count_in_inventory"] == 1
assert summarized["inventory_is_sample"] is True

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
empty["published_page_total"] = 0
empty["published_post_total"] = 0
result = summarize_content_targets(empty)
assert result["homepage_in_inventory"] is False
assert result["page_count_in_inventory"] == 0

print("site_manager_content_targets_test: OK")


from site_manager_content_targets import summarize_content_page

def public_page(page=1, total=1, ids=(9,), kind="post"):
    fixture = valid_response()
    items = []
    for post_id in ids:
        item = copy.deepcopy(fixture["posts"][0] if kind == "post" else fixture["pages"][0])
        item["post_id"] = post_id
        item["slug"] = f"published-{post_id}"
        item["permalink"] = f"https://emmake.com/nuevaweb/published-{post_id}/"
        item["homepage"] = kind == "page" and post_id == 7
        items.append(item)
    limit = 40 if kind == "post" else 80
    return {
        "connection_id": fixture["connection_id"],
        "source": "wordpress-native-published-content-page",
        "observed_at": fixture["observed_at"],
        "site_url": fixture["site_url"],
        "homepage_post_id": fixture["homepage_post_id"],
        "scope": "site.read", "published_only": True,
        "post_type": kind, "page": page, "page_size": limit,
        "published_total": total, "total_pages": max(1,(total+limit-1)//limit),
        "has_more": page < max(1,(total+limit-1)//limit),
        "items": items, "private_values_included": False,
    }

single = summarize_content_page(public_page())
assert single["published_total"] == 1 and single["items"][0]["post_id"] == 9
large_first = summarize_content_page(public_page(1, 1726, range(100, 140)))
large_last = summarize_content_page(public_page(44, 1726, range(200, 206)))
assert len(large_first["items"]) == 40 and large_first["has_more"] is True
assert len(large_last["items"]) == 6 and large_last["has_more"] is False
assert summarize_content_page(public_page(1,1,(7,),"page"))["items"][0]["homepage"] is True
for tamper in (
    lambda v: v.update(page=0), lambda v: v.update(page=True),
    lambda v: v.update(page_size=500), lambda v: v.update(total_pages=6),
    lambda v: v.update(has_more=True), lambda v: v.update(post_type="draft"),
    lambda v: v.update(private_values_included=True),
    lambda v: v.update(content="PRIVATE BODY"),
    lambda v: v["items"][0].update(status="private"),
    lambda v: v["items"][0].update(permalink="https://outside.example/p/"),
):
    wrong = public_page()
    tamper(wrong)
    try:
        summarize_content_page(wrong)
    except ContentTargetsError:
        pass
    else:
        raise AssertionError("unsafe paginated inventory was certified")
