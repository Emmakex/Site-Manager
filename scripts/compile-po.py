#!/usr/bin/env python3
"""Deterministic, strict stdlib-only PO -> MO fallback for WordPress release builds.

Used only when GNU msgfmt is unavailable. Unsupported PO constructs fail closed
instead of silently shipping incorrect translations.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import struct
import sys


FIELD = re.compile(r"^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+(\".*\")$")


def compile_po(text: str) -> bytes:
    entries: dict[bytes, bytes] = {}
    current: dict[str, str] = {}
    active: str | None = None
    fuzzy = False

    def flush() -> None:
        nonlocal current, active, fuzzy
        if not current:
            active = None
            fuzzy = False
            return
        if "msgid" not in current:
            raise ValueError("PO entry without msgid")
        if not fuzzy:
            key = current["msgid"]
            if "msgctxt" in current:
                key = current["msgctxt"] + "\x04" + key
            if "msgid_plural" in current:
                plural = sorted(
                    int(name[7:-1]) for name in current if re.fullmatch(r"msgstr\[\d+\]", name)
                )
                if not plural or plural != list(range(len(plural))):
                    raise ValueError("Plural translations must be consecutive")
                val = "\x00".join(current[f"msgstr[{index}]"] for index in plural)
                key += "\x00" + current["msgid_plural"]
            elif "msgstr" in current:
                val = current["msgstr"]
            else:
                raise ValueError("PO entry without msgstr")
            raw_key = key.encode("utf-8")
            if raw_key in entries:
                raise ValueError("Duplicate PO message")
            entries[raw_key] = val.encode("utf-8")
        current, active, fuzzy = {}, None, False

    for raw_line in text.splitlines() + [""]:
        line = raw_line.strip()
        if not line:
            flush()
            continue
        if line.startswith("#"):
            if line.startswith("#,") and "fuzzy" in line:
                fuzzy = True
            continue
        if line.startswith('"'):
            if active is None:
                raise ValueError("Unexpected PO continuation")
            value = ast.literal_eval(line)
            if not isinstance(value, str):
                raise ValueError("Invalid PO string")
            current[active] += value
            continue
        match = FIELD.fullmatch(line)
        if match is None:
            raise ValueError("Unsupported PO syntax")
        active = match.group(1)
        value = ast.literal_eval(match.group(2))
        if active in current:
            raise ValueError("Duplicate PO field")
        current[active] = value

    sorted_keys = sorted(entries)
    count = len(sorted_keys)
    ids_offset = 28
    strs_offset = ids_offset + count * 8
    pool_offset = strs_offset + count * 8
    ids_table = bytearray()
    strs_table = bytearray()
    pool = bytearray()
    for key in sorted_keys:
        ids_table.extend(struct.pack("<II", len(key), pool_offset + len(pool)))
        pool.extend(key + b"\x00")
    for key in sorted_keys:
        value = entries[key]
        strs_table.extend(struct.pack("<II", len(value), pool_offset + len(pool)))
        pool.extend(value + b"\x00")
    header = struct.pack("<7I", 0x950412DE, 0, count, ids_offset, strs_offset, 0, 0)
    return header + ids_table + strs_table + pool


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: compile-po.py input.po output.mo")
    input_path, output_path = map(Path, sys.argv[1:])
    try:
        payload = compile_po(input_path.read_text(encoding="utf-8-sig"))
    except (ValueError, SyntaxError, UnicodeError) as exc:
        raise SystemExit(f"Invalid PO source: {exc}") from exc
    output_path.write_bytes(payload)
