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
  "site-manager/languages/site-manager-es_ES.po"
  "site-manager/languages/site-manager-es_ES.mo"
)
for path in "${required[@]}"; do
  [[ -e "$TMP/$path" ]] || { echo "Missing packaged path: $path" >&2; exit 1; }
done

VERSION="$(awk -F': ' '/^ \* Version: / {print $2; exit}' "$PLUGIN" | tr -d '\r')"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Unexpected plugin version: $VERSION" >&2; exit 1; }
[[ "$(basename "$ZIP")" == "site-manager-$VERSION.zip" ]] || { echo "ZIP/version mismatch" >&2; exit 1; }
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

grep -q "add_menu_page" "$TMP/site-manager/src/Adapters/WordPress/Plugin.php" || { echo "Top-level Site Manager menu missing" >&2; exit 1; }
! grep -q "add_management_page" "$TMP/site-manager/src/Adapters/WordPress/Plugin.php" || { echo "Legacy Tools menu registration leaked into release" >&2; exit 1; }
grep -q "loadTranslations" "$TMP/site-manager/src/Adapters/WordPress/Plugin.php" || { echo "WordPress translation loader missing" >&2; exit 1; }
grep -q 'msgstr "Plataforma"' "$TMP/site-manager/languages/site-manager-es_ES.po" || { echo "Spanish dashboard catalogue is incomplete" >&2; exit 1; }
grep -q 'msgstr "Panel de Site Manager"' "$TMP/site-manager/languages/site-manager-es_ES.po" || { echo "Spanish dashboard title is missing" >&2; exit 1; }
[[ -s "$TMP/site-manager/languages/site-manager-es_ES.mo" ]] || { echo "Compiled Spanish catalogue is empty" >&2; exit 1; }

echo "PASS: installable Site Manager WordPress package $(basename "$ZIP")"
