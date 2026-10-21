#!/usr/bin/env sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$ROOT"

command -v docker >/dev/null 2>&1 || {
  echo "Docker is required." >&2
  exit 1
}
docker compose version >/dev/null 2>&1 || {
  echo "Docker Compose v2 is required (docker compose)." >&2
  exit 1
}

printf "Site Manager REST base (https://.../wp-json/site-manager/v1/): "
IFS= read -r REST_BASE
printf "Site Manager connection id (smc_...): "
IFS= read -r CONNECTION_ID
printf "Site Manager bearer token: "
stty -echo 2>/dev/null || true
IFS= read -r TOKEN
stty echo 2>/dev/null || true
printf "\n"
printf "Loopback port [8787]: "
IFS= read -r BIND_PORT
BIND_PORT="${BIND_PORT:-8787}"

case "$REST_BASE" in
  https://*) ;;
  *) echo "REST base must use HTTPS." >&2; exit 1 ;;
esac
case "$CONNECTION_ID" in
  smc_*) ;;
  *) echo "Connection id must start with smc_." >&2; exit 1 ;;
esac
case "$BIND_PORT" in
  ''|*[!0-9]*) echo "Port must be numeric." >&2; exit 1 ;;
esac
if [ "$BIND_PORT" -lt 1 ] || [ "$BIND_PORT" -gt 65535 ]; then
  echo "Port must be between 1 and 65535." >&2
  exit 1
fi
if [ -z "$TOKEN" ]; then
  echo "Bearer token is required." >&2
  exit 1
fi

# Docker Compose dotenv single-quoted values are literal. Reject quote/newline
# characters rather than attempting to transform a secret value.
for VALUE in "$REST_BASE" "$CONNECTION_ID" "$TOKEN"; do
  case "$VALUE" in
    *"'"*|*"\n"*|*"\r"*)
      echo "Configuration values must not contain quotes or newlines." >&2
      exit 1
      ;;
  esac
done

umask 077
cat > .env <<EOF
SITE_MANAGER_REST_BASE='$REST_BASE'
SITE_MANAGER_CONNECTION_ID='$CONNECTION_ID'
SITE_MANAGER_TOKEN='$TOKEN'
SITE_MANAGER_MCP_BIND_PORT='$BIND_PORT'
SITE_MANAGER_TIMEOUT_MS='10000'
EOF
chmod 600 .env
unset TOKEN

# Validate silently: never print the resolved environment because it contains
# the bearer secret.
docker compose -f compose.yaml config --quiet

docker compose -f compose.yaml up -d --build

echo "Persistent Site Manager MCP service started."
echo "Health: http://127.0.0.1:${BIND_PORT}/healthz"
echo "Status: docker compose -f compose.yaml ps"
echo "Logs: docker compose -f compose.yaml logs --tail=100 site-manager-mcp"
