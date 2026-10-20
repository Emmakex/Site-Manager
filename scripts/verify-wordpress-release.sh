#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP="${1:-}"
[[ -n "$ZIP" ]] || ZIP="$(ls -1 "$ROOT"/dist/site-manager-*.zip 2>/dev/null | head -1 || true)"
[[ -f "$ZIP" ]] || { echo "Release ZIP not found" >&2; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
unzip -q "$ZIP" -d "$TMP"
PLUGIN="$TMP/site-manager/site-manager.php"

required=(
  "site-manager/site-manager.php"
  "site-manager/readme.txt"
  "site-manager/vendor/autoload.php"
  "site-manager/src/Core/Contracts/PlatformAdapter.php"
  "site-manager/src/Adapters/WordPress/Plugin.php"
  "site-manager/src/Adapters/WordPress/DiscoveryService.php"
)
for path in "${required[@]}"; do
  [[ -e "$TMP/$path" ]] || { echo "Missing packaged path: $path" >&2; exit 1; }
done

grep -q '^ \* Version: 0\.1\.0$' "$PLUGIN" || { echo "Unexpected plugin version" >&2; exit 1; }
! grep -q -- '-dev' "$PLUGIN" || { echo "Development version leaked into release" >&2; exit 1; }

for forbidden in tests docs .github composer.json composer.lock; do
  [[ ! -e "$TMP/site-manager/$forbidden" ]] || { echo "Development-only path leaked into package: $forbidden" >&2; exit 1; }
done

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find "$TMP/site-manager" -type f -name '*.php' -print0)

php -r '
require $argv[1];
if (!interface_exists("SiteManager\\Core\\Contracts\\PlatformAdapter")) { fwrite(STDERR, "Core contract autoload failed\n"); exit(1); }
if (!class_exists("SiteManager\\Adapters\\WordPress\\WordPressAdapter")) { fwrite(STDERR, "WordPress adapter autoload failed\n"); exit(1); }
' "$TMP/site-manager/vendor/autoload.php"

echo "PASS: installable Site Manager WordPress package $(basename "$ZIP")"
