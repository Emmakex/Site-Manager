#!/usr/bin/env python3
"""Read-only public HTTP evidence for EMMAKE before root cutover.

Uses a fixed allowlist only; no caller-selected URL, no credentials, no
WordPress writes, no external redirect following and no raw HTML saved.
Run by a dedicated manual GitHub Actions workflow.
"""
from __future__ import annotations

from html.parser import HTMLParser
import json
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, build_opener, HTTPRedirectHandler

BASE = "https://emmake.com"
PUBLIC_PATHS = (
    "/", "/contacto/", "/sobre-nosotros/", "/blog/",
    "/blog/desbloquea-el-potencial-herramientas-de-inteligencia-empresarial/",
    "/nuevaweb/", "/nuevaweb/servicios/", "/nuevaweb/contacto/",
    "/nuevaweb/blog/", "/nuevaweb/casos-de-exito/",
    "/nuevaweb/sobre-nosotros/", "/nuevaweb/nuestras-marcas/",
)


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req: Request, fp: Any, code: int, msg: str, headers: Any, newurl: str) -> None:
        # Redirects are reported, never followed across origins or paths.
        return None


class HeadSummary(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.canonical: str | None = None
        self.robots: str | None = None
        self.title = ""
        self.in_title = False
        self.form_count = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        a = {k.lower(): v or "" for k, v in attrs}
        if tag == "link" and "canonical" in a.get("rel", "").lower().split():
            u = a.get("href", "")
            if valid_public_url(u):
                self.canonical = u[:240]
        elif tag == "meta" and a.get("name", "").lower() == "robots":
            self.robots = a.get("content", "")[:120]
        elif tag == "title":
            self.in_title = True
        elif tag == "form":
            self.form_count += 1

    def handle_endtag(self, tag: str) -> None:
        if tag == "title":
            self.in_title = False

    def handle_data(self, data: str) -> None:
        if self.in_title:
            self.title = (self.title + data)[:180]


def valid_public_url(url: Any) -> bool:
    if not isinstance(url, str) or not url.startswith(BASE + "/"):
        return False
    try:
        p = urlsplit(url)
        return (p.scheme == "https" and p.hostname == "emmake.com" and p.username is None
                and p.password is None and p.query == "" and p.fragment == ""
                and any(ord(c) < 32 for c in url) is False)
    except ValueError:
        return False


def probe(path: str, opener: Any) -> dict[str, Any]:
    if path not in PUBLIC_PATHS:
        raise ValueError("unapproved EMMAKE probe path")
    url = BASE + path
    request = Request(url, headers={
        "User-Agent": "SiteManager-EMMAKE-ReadOnly-LaunchPreflight/1.0",
        "Accept": "text/html,application/xhtml+xml",
    }, method="GET")
    record: dict[str, Any] = {
        "path": path, "status": None, "redirect": None,
        "title": None, "canonical": None, "meta_robots": None,
        "x_robots_tag": None, "form_count_observed": 0,
        "error": None,
    }
    try:
        with opener.open(request, timeout=12) as response:
            record["status"] = int(response.status)
            record["x_robots_tag"] = response.headers.get("X-Robots-Tag", "")[:120] or None
            ctype = response.headers.get("Content-Type", "")
            if "text/html" not in ctype:
                return record
            raw = response.read(250_000)
            html = raw.decode("utf-8", errors="replace")
            parser = HeadSummary()
            parser.feed(html)
            record["title"] = parser.title or None
            record["canonical"] = parser.canonical
            record["meta_robots"] = parser.robots
            record["form_count_observed"] = parser.form_count
    except HTTPError as exc:
        record["status"] = exc.code
        target = exc.headers.get("Location", "")
        if exc.code in (301, 302, 303, 307, 308):
            record["redirect"] = target[:240] if valid_public_url(target) else "external_or_unverified"
    except (URLError, TimeoutError, OSError) as exc:
        # No sensitive transport details or raw server content retained.
        record["error"] = type(exc).__name__
    return record


def main() -> int:
    opener = build_opener(NoRedirect())
    results = [probe(path, opener) for path in PUBLIC_PATHS]
    print(json.dumps({
        "source": "public-read-only-http-probe",
        "origin": BASE,
        "cutover_performed": False,
        "pages": results,
        "http_verified_200_count": sum(x["status"] == 200 for x in results),
        "unreachable_count": sum(x["status"] is None for x in results),
        "note": "Public response metadata only; successful HTTP does not verify WordPress editing, migration, forms delivery or SEO launch.",
    }, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
