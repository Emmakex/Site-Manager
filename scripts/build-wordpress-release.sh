#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="$ROOT/wordpress-plugin/site-manager/site-manager.php"
DIST="$ROOT/dist"
BUILD="$ROOT/.build/wordpress-release"
PACKAGE="$BUILD/site-manager"

VERSION="$(awk -F': ' '/^ \* Version: / {print $2; exit}' "$PLUGIN" | tr -d '\r')"
[[ -n "$VERSION" ]] || { echo "Could not determine plugin version" >&2; exit 1; }
[[ "$VERSION" != *-dev* ]] || { echo "Refusing to build a release from development version: $VERSION" >&2; exit 1; }

rm -rf "$BUILD"
mkdir -p "$PACKAGE" "$DIST"

composer install \
  --no-dev \
  --no-interaction \
  --prefer-dist \
  --classmap-authoritative

cp "$PLUGIN" "$PACKAGE/site-manager.php"
cp "$ROOT/wordpress-plugin/site-manager/readme.txt" "$PACKAGE/readme.txt"
cp -R "$ROOT/src" "$PACKAGE/src"
cp -R "$ROOT/vendor" "$PACKAGE/vendor"

find "$PACKAGE" -name '.DS_Store' -delete
find "$PACKAGE" -type d -name '.git' -prune -exec rm -rf {} + || true

ZIP="$DIST/site-manager-$VERSION.zip"
rm -f "$ZIP" "$ZIP.sha256"
(
  cd "$BUILD"
  zip -X -q -r "$ZIP" site-manager
)

unzip -tq "$ZIP" >/dev/null

if command -v sha256sum >/dev/null 2>&1; then
  (cd "$DIST" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")
else
  (cd "$DIST" && shasum -a 256 "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")
fi

echo "Built $ZIP"
echo "SHA256: $(cat "$ZIP.sha256")"
