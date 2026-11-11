#!/usr/bin/env python3
"""Offline test for fixed-origin, read-only EMMAKE public HTTP probe."""
from __future__ import annotations

from email.message import Message
from io import BytesIO
from pathlib import Path
import sys
from urllib.error import HTTPError, URLError

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from emmake_public_http_probe import (  # noqa: E402
    BASE, PUBLIC_PATHS, HeadSummary, NoRedirect, probe, valid_public_url,
)


class FakeResponse:
    status = 200
    def __init__(self, body: bytes, content_type: str = "text/html; charset=utf-8") -> None:
        self.body = body
        self.headers = Message()
        self.headers["Content-Type"] = content_type
        self.headers["X-Robots-Tag"] = "noindex, nofollow"

    def __enter__(self):
        return self

    def __exit__(self, *_):
        return False

    def read(self, n: int) -> bytes:
        assert n <= 250_000
        return self.body[:n]


class FakeOpener:
    def __init__(self, error=None):
        self.error = error
        self.calls = []
    def open(self, request, timeout=12):
        assert request.get_method() == "GET"
        assert timeout == 12
        self.calls.append(request.full_url)
        if self.error:
            raise self.error
        return FakeResponse(b"""<html><head><title>EMMAKE staging</title>
          <link rel="canonical" href="https://emmake.com/nuevaweb/"/>
          <meta name="robots" content="noindex,follow">
          <link rel="canonical" href="https://attacker.invalid/private">
          </head><body><form><input name="contact"/></form></body></html>""")


assert valid_public_url("https://emmake.com/nuevaweb/")
for bad in (
    "https://emmake.com@attacker.test/", "http://emmake.com/",
    "https://emmake.com/abc?token=x", "https://emmake.com/abc#secret",
    "https://attacker.test/", "https://emmake.com.evil.test/",
):
    assert not valid_public_url(bad)

opener = FakeOpener()
report = probe("/nuevaweb/", opener)
assert opener.calls == [BASE + "/nuevaweb/"]
assert report["status"] == 200
assert report["title"] == "EMMAKE staging"
assert report["canonical"] == "https://emmake.com/nuevaweb/"
assert report["meta_robots"] == "noindex,follow"
assert report["x_robots_tag"] == "noindex, nofollow"
assert report["form_count_observed"] == 1
assert "private" not in str(report)

headers = Message()
headers["Location"] = "https://attacker.test/redirect"
redirect = HTTPError(BASE + "/", 302, "Found", headers, None)
blocked = probe("/", FakeOpener(redirect))
assert blocked["status"] == 302
assert blocked["redirect"] == "external_or_unverified"

missing = probe("/nuevaweb/servicios/", FakeOpener(HTTPError(BASE, 404, "Not Found", Message(), None)))
assert missing["status"] == 404

offline = probe("/nuevaweb/", FakeOpener(URLError("no-network-private-info")))
assert offline["error"] == "URLError"
assert "no-network-private-info" not in str(offline)

try:
    probe("https://attacker.invalid/secret", FakeOpener())
except ValueError:
    pass
else:
    raise AssertionError("arbitrary URL was accepted")

assert NoRedirect().redirect_request(None, None, 302, "Found", None, "https://attacker.invalid/") is None
assert len(PUBLIC_PATHS) <= 20
print("emmake_public_http_probe_test: OK")
