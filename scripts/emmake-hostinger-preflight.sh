#!/usr/bin/env bash
# Emmake Hostinger pre-cutover inventory. READ ONLY.
# Usage: bash scripts/emmake-hostinger-preflight.sh /absolute/old-wp /absolute/new-wp
set -euo pipefail
if [[ $# -ne 2 || "$1" != /* || "$2" != /* ]]; then
  echo "Usage: $0 /absolute/path/to/legacy-wp /absolute/path/to/nuevaweb-wp" >&2
  exit 2
fi
command -v wp >/dev/null 2>&1 || { echo "FAIL: WP-CLI not available" >&2; exit 2; }
command -v sha256sum >/dev/null 2>&1 || { echo "FAIL: sha256sum not available" >&2; exit 2; }
legacy="$(realpath -e -- "$1")"
staging="$(realpath -e -- "$2")"
[[ "$legacy" != "$staging" ]] || { echo "FAIL: both paths resolve to the same installation"; exit 1; }
[[ -f "$legacy/wp-config.php" && -f "$staging/wp-config.php" ]] || {
  echo "FAIL: wp-config.php must exist in both paths"; exit 1;
}
query() { wp --path="$1" --skip-plugins --skip-themes "$@" --quiet; }
identity() {
  local name host prefix
  name="$(query "$1" config get DB_NAME --type=constant)"
  host="$(query "$1" config get DB_HOST --type=constant)"
  prefix="$(query "$1" db prefix)"
  printf '%s/%s/%s' "$host" "$name" "$prefix" | sha256sum | awk '{print $1}'
}
get_info() {
  local path="$1" label="$2"
  local home siteurl public posts pages db_id
  home="$(query "$path" option get home)"
  siteurl="$(query "$path" option get siteurl)"
  public="$(query "$path" option get blog_public)"
  posts="$(query "$path" post list --post_type=post --post_status=publish --format=count)"
  pages="$(query "$path" post list --post_type=page --post_status=publish --format=count)"
  db_id="$(identity "$path")"
  printf '%s: home=%s siteurl=%s blog_public=%s posts=%s pages=%s db_identity_sha256=%s\n' \
    "$label" "$home" "$siteurl" "$public" "$posts" "$pages" "$db_id"
  case "$label" in
    legacy)
      [[ "$home" == "https://emmake.com" || "$home" == "https://emmake.com/" ]] || {
        echo "FAIL: unexpected legacy home URL"; exit 1;
      }
      [[ "$public" == "1" ]] || { echo "WARN: old live WordPress discourages indexing"; }
      ;;
    staging)
      [[ "$home" == "https://emmake.com/nuevaweb" || "$home" == "https://emmake.com/nuevaweb/" ]] || {
        echo "FAIL: unexpected staging home URL"; exit 1;
      }
      [[ "$public" == "0" ]] || { echo "FAIL: staging must remain noindex until cutover"; exit 1; }
      ;;
  esac
  [[ "$posts" == "1726" ]] || { echo "FAIL: published post count must match verified 1726"; exit 1; }
}
echo "EMMAKE READ-ONLY HOSTINGER PRE-CUTOVER"
get_info "$legacy" legacy
get_info "$staging" staging
old_id="$(identity "$legacy")"
new_id="$(identity "$staging")"
[[ "$old_id" != "$new_id" ]] || {
  echo "FAIL: both WordPress paths share identical DB host/name/prefix; verify real installations"; exit 1;
}
echo "PASS: installation paths, base URLs, indexability, published counts and DB identities"
echo "NOTE: BACKUP NOT VERIFIED. This script NEVER authorizes migration."
