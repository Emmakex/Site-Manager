#!/usr/bin/env python3
"""Read-only public HTTP HTML acceptance for EMMAKE staging.

No arbitrary URL, path, credentials, content, or HTML body can enter Git results.
This is an HTTP structural audit, not a browser or aesthetic acceptance.
"""
from __future__ import annotations

import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import sys
from datetime import datetime, timezone
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit, unquote
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
PENDING = ROOT / "audits" / "emmake" / "public-home" / "pending"
RESULTS = ROOT / "audits" / "emmake" / "public-home" / "results"
URL = "https://emmake.com/nuevaweb/"
BLOG_URLS = {
    "blog": "https://emmake.com/nuevaweb/blog/",
    "blog-transformacion": "https://emmake.com/nuevaweb/blog-transformacion-digital/",
}
EXPECTED_H1 = "Conectamos estrategia, datos y tecnología para impulsar lo que viene."
EXPECTED_CLASSES = {
    "corporate_main": "seo-geo-corporate-v5-home",
    "hero": "seo-geo-corporate-native-hero",
    "capabilities": "seo-geo-corporate-native-capabilities",
    "process": "seo-geo-corporate-native-process",
    "insights": "seo-geo-corporate-native-insights",
    "final_cta": "seo-geo-final-cta",
}
MAX_HTML_BYTES = 1_500_000
MAX_PENDING = 5


class SemanticHTMLParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.classes: set[str] = set()
        self.headings: list[str] = []
        self.in_h1 = 0
        self.h1_parts: list[str] = []
        self.robots_noindex = False
        self.has_lang = False
        self.nested_headings = False
        self.anchors: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        classes = values.get("class") or ""
        self.classes.update(classes.split())
        if tag == "html" and (values.get("lang") or "").strip():
            self.has_lang = True
        if tag == "h1":
            if self.in_h1:
                self.nested_headings = True
            self.in_h1 += 1
            self.h1_parts = []
        if tag == "meta" and (values.get("name") or "").lower() == "robots":
            self.robots_noindex = "noindex" in (values.get("content") or "").lower()
        if tag == "a":
            href = values.get("href")
            if isinstance(href, str) and len(self.anchors) < 500:
                self.anchors.append(href)

    def handle_endtag(self, tag: str) -> None:
        if tag == "h1" and self.in_h1:
            self.in_h1 -= 1
            self.headings.append("".join(self.h1_parts).strip())

    def handle_data(self, data: str) -> None:
        if self.in_h1:
            self.h1_parts.append(data)


def unresolved_permalink_link(href: str) -> bool:
    """Detect WordPress permalink substitution markers, not private URL data."""
    # WP esc_url() can percent-encode curly braces; nested encoding happens in migrations.
    # Decode twice for detection only. Never fetch or persist user-authored link URLs.
    candidates = (href, unquote(href), unquote(unquote(href)))
    # A second unquote can consume %ca in "%category%"; keep each decoding stage.
    return any(re.search(
        r"%category%|%postname%|\{[a-f0-9]{30,}\}(?:category|postname)",
        candidate, re.IGNORECASE
    ) is not None for candidate in candidates)


def analyze_html(html: str, final_url: str, status: int, x_robots: str) -> dict[str, bool]:
    parser = SemanticHTMLParser()
    parser.feed(html)
    target = urlsplit(final_url)
    location_ok = (target.scheme == "https" and target.hostname == "emmake.com"
                   and target.path.rstrip("/") == "/nuevaweb" and not target.query)
    checks: dict[str, bool] = {
        "http_200": status == 200,
        "https_same_staging_url": location_ok,
        "has_html_lang": parser.has_lang,
        "exactly_one_approved_h1": len(parser.headings) == 1
            and " ".join(parser.headings[0].split()) == EXPECTED_H1
            and not parser.nested_headings,
        "staging_noindex": parser.robots_noindex or "noindex" in x_robots.lower(),
    }
    checks.update({key: value in parser.classes for key, value in EXPECTED_CLASSES.items()})
    checks["internal_cta_present"] = any(
        h in parser.anchors
        for h in ("https://emmake.com/nuevaweb/contacto/", "/nuevaweb/contacto/")
    )
    checks["no_unresolved_blog_permalink_tokens"] = not any(
        unresolved_permalink_link(href) for href in parser.anchors
    )
    return checks



def inspect_public_blog_links() -> tuple[dict[str, bool], dict[str, dict[str, int | bool]]]:
    """Only fetch fixed public pages. Record counts and statuses, never URLs or HTML."""
    checks: dict[str, bool] = {}
    counts: dict[str, dict[str, int | bool]] = {}
    for page_id, address in BLOG_URLS.items():
        status = 0
        final = ""
        body = b""
        content_type = ""
        try:
            request = Request(address, headers={
                "User-Agent": "SiteManager-PublicHomeAcceptance/1.0 (+read-only)",
                "Accept": "text/html",
            })
            with urlopen(request, timeout=20) as response:
                status = response.status
                final = response.geturl()
                content_type = response.headers.get("content-type", "")
                body = response.read(MAX_HTML_BYTES + 1)
        except HTTPError as exc:
            status = exc.code
        except (URLError, TimeoutError, OSError):
            pass
        final_ok = final.rstrip("/") == address.rstrip("/")
        html_ok = 0 < len(body) <= MAX_HTML_BYTES and "text/html" in content_type.lower()
        parser = SemanticHTMLParser()
        if html_ok:
            parser.feed(body.decode("utf-8", errors="replace"))
        bad = sum(unresolved_permalink_link(h) for h in parser.anchors)
        checks[page_id + "_http_200"] = status == 200 and final_ok and html_ok
        checks[page_id + "_no_unresolved_permalink_tokens"] = html_ok and bad == 0
        counts[page_id] = {
            "http_status": status,
            "fixed_url_verified": final_ok,
            "public_links_scanned": len(parser.anchors),
            "unresolved_permalink_count": bad,
        }
    return checks, counts


def probe() -> dict[str, object]:
    response_bytes = b""
    status_code = 0
    headers: dict[str, str] = {}
    final_url = ""
    error_code = None
    try:
        request = Request(URL, headers={
            "User-Agent": "SiteManager-PublicHomeAcceptance/1.0 (+read-only)",
            "Accept": "text/html",
        })
        with urlopen(request, timeout=20) as response:
            status_code = response.status
            final_url = response.geturl()
            headers = {k.lower(): v for k, v in response.headers.items()}
            response_bytes = response.read(MAX_HTML_BYTES + 1)
    except HTTPError as exc:
        status_code = exc.code
        error_code = "http_response_failure"
    except (URLError, TimeoutError, OSError):
        error_code = "public_http_unavailable"

    if len(response_bytes) > MAX_HTML_BYTES:
        error_code = "html_exceeds_read_limit"
        response_bytes = b""
    content_type = headers.get("content-type", "")
    if response_bytes and "text/html" not in content_type.lower():
        error_code = "not_html"
        response_bytes = b""

    rendered_html = response_bytes.decode("utf-8", errors="replace")
    checks = analyze_html(
        rendered_html, final_url, status_code, headers.get("x-robots-tag", "")
    )
    parser = SemanticHTMLParser()
    parser.feed(rendered_html)
    blog_checks, blog_counts = inspect_public_blog_links()
    checks.update(blog_checks)
    return {
        "public_blog_pages": blog_counts,
        "public_links_scanned": len(parser.anchors),
        "unresolved_permalink_count": sum(unresolved_permalink_link(h) for h in parser.anchors),
        "status": "passed" if checks and all(checks.values()) and error_code is None else "failed",
        "error_code": error_code,
        "http_status": status_code,
        "response_bytes": len(response_bytes),
        "html_sha256": hashlib.sha256(response_bytes).hexdigest() if response_bytes else None,
        "checks": checks,
        "values_in_results": False,
        "rendered_html_in_results": False,
        "browser_visual_review_completed": False,
    }


def run_pending() -> int:
    RESULTS.mkdir(parents=True, exist_ok=True)
    files = sorted(PENDING.glob("*.json"))
    if len(files) > MAX_PENDING:
        raise SystemExit("Too many public audits; refuse bulk checks")
    for file in files:
        if not re.fullmatch(r"audit-emmake-public-home-[a-z0-9-]{4,64}\.json", file.name):
            raise SystemExit("Unsupported request filename")
        result_path = RESULTS / file.name
        if result_path.exists():
            continue
        data = json.loads(file.read_text(encoding="utf-8"))
        if (not isinstance(data, dict) or set(data) != {
            "version", "audit_id", "site", "check", "requested_by"
        } or data.get("version") != "1" or data.get("audit_id") != file.stem
                or data.get("site") != "emmake-nuevaweb"
                or data.get("check") != "public-home"
                or data.get("requested_by") != "chatgpt"):
            raise SystemExit("Public audit request has unsupported fields")
        outcome = probe()
        result = {
            "version": "1",
            "audit_id": file.stem,
            "site": "emmake-nuevaweb",
            "checked_url": URL,
            "checked_at": datetime.now(timezone.utc).isoformat(),
            **outcome,
        }
        result_path.write_text(json.dumps(result, sort_keys=True, indent=2) + "\n", encoding="utf-8")
        print(f"Public acceptance {result['audit_id']}: {result['status']}")
    return 0


def self_test() -> None:
    fixture = """<!doctype html><html lang="es-ES"><head>
      <meta name="robots" content="noindex,nofollow"></head>
      <body><main class="seo-geo-corporate-v5-home">
      <section class="seo-geo-corporate-native-hero"><h1>
      Conectamos estrategia, datos y tecnología para impulsar lo que viene.
      </h1><a href="https://emmake.com/nuevaweb/contacto/">Contacto</a></section>
      <section class="seo-geo-corporate-native-capabilities"></section>
      <section class="seo-geo-corporate-native-process"></section>
      <section class="seo-geo-corporate-native-insights"></section>
      <section class="seo-geo-final-cta"></section></main></body></html>"""
    good = analyze_html(fixture, URL, 200, "")
    assert good and all(good.values()), good
    for broken, expected in [
        (fixture.replace(EXPECTED_H1, "Different text"), "exactly_one_approved_h1"),
        (fixture.replace("noindex,nofollow", "index,follow"), "staging_noindex"),
        (fixture.replace(EXPECTED_CLASSES["corporate_main"], "legacy-home"), "corporate_main"),
        (fixture.replace("href=\"https://emmake.com/nuevaweb/contacto/\"", "href=\"https://other.invalid/\""), "internal_cta_present"),
    ]:
        checks = analyze_html(broken, URL, 200, "")
        assert not checks[expected], expected
    assert not analyze_html(fixture, "https://attacker.invalid/", 200, "")["https_same_staging_url"]
    malformed_fixture = fixture.replace(
        'href="https://emmake.com/nuevaweb/contacto/"',
        'href="https://emmake.com/nuevaweb/{d276abfeceab40cca0e158fc6217176554b8e54a1f85b6eb004941797db52171}category{d276abfeceab40cca0e158fc6217176554b8e54a1f85b6eb004941797db52171}/"'
    )
    assert not analyze_html(malformed_fixture, URL, 200, "")["no_unresolved_blog_permalink_tokens"]
    assert not unresolved_permalink_link("https://emmake.com/nuevaweb/blog/")
    assert unresolved_permalink_link("https://emmake.com/nuevaweb/%7Bd276abfeceab40cca0e158fc6217176554b8e54a1f85b6eb004941797db52171%7Dcategory%7Bd276abfeceab40cca0e158fc6217176554b8e54a1f85b6eb004941797db52171%7D/")
    assert unresolved_permalink_link("https://emmake.com/nuevaweb/%257Bd276abfeceab40cca0e158fc6217176554b8e54a1f85b6eb004941797db52171%257Dpostname%257Bd276abfeceab40cca0e158fc6217176554b8e54a1f85b6eb004941797db52171%257D/")
    assert unresolved_permalink_link("https://emmake.com/nuevaweb/%25category%25/")

    print("PASS: bounded public HTML acceptance detects Theme, staging and headline regression")


if __name__ == "__main__":
    if sys.argv[1:] == ["--self-test"]:
        self_test()
    elif sys.argv[1:] == ["--run-pending"]:
        raise SystemExit(run_pending())
    else:
        raise SystemExit("Expected --self-test or --run-pending")
