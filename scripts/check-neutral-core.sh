#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGETS=("$ROOT/src/Core" "$ROOT/composer.json")

if grep -RniE 'Kairoseth|Eduardo_Research|research-manager' "${TARGETS[@]}"; then
  echo "FAIL: commercial/personal legacy naming leaked into the technical core" >&2
  exit 1
fi

if grep -RniE 'WordPress|PrestaShop|ABSPATH|wp_[a-zA-Z0-9_]+|WC_[a-zA-Z0-9_]+' "$ROOT/src/Core"; then
  echo "FAIL: platform-specific runtime assumption leaked into Core" >&2
  exit 1
fi

echo "PASS: neutral core naming and platform boundary"
