#!/usr/bin/env bash
set -euo pipefail

ACTION="${SITE_MANAGER_DEPLOY_ACTION:-deploy}"
SITE_ID="${SITE_MANAGER_SITE_ID:?set SITE_MANAGER_SITE_ID}"
DEPLOY_ROOT="${SITE_MANAGER_DEPLOY_ROOT:-/opt/site-manager-mcp}"
SOURCE_DIR="${SITE_MANAGER_RELEASE_SOURCE_DIR:-}"
PUBLIC_EDGE_NETWORK="${KAIROSETH_PUBLIC_EDGE_NETWORK:-kairoseth-public-edge}"

if ! [[ "$SITE_ID" =~ ^[a-z0-9][a-z0-9-]{2,63}$ ]]; then
  echo 'SITE_MANAGER_SITE_ID is invalid' >&2
  exit 2
fi
case "$ACTION" in
  deploy|rollback) ;;
  *) echo 'SITE_MANAGER_DEPLOY_ACTION must be deploy or rollback' >&2; exit 2 ;;
esac

PROJECT_NAME="site-manager-mcp-${SITE_ID}"
EDGE_ALIAS="site-manager-mcp-${SITE_ID}"
DEPLOY_DIR="${DEPLOY_ROOT}/${SITE_ID}"
ENV_FILE="${DEPLOY_DIR}/.env.production"
COMPOSE_FILE="${DEPLOY_DIR}/compose.edge.yaml"
SCRIPT_FILE="${DEPLOY_DIR}/site-manager-mcp-ovh-deploy.sh"
CURRENT_REF_FILE="${DEPLOY_DIR}/.current-image-ref"
PREVIOUS_REF_FILE="${DEPLOY_DIR}/.previous-image-ref"
PREVIOUS_ENV_FILE="${DEPLOY_DIR}/.previous-env.production"
PREVIOUS_COMPOSE_FILE="${DEPLOY_DIR}/.previous-compose.edge.yaml"

valid_image_ref() {
  [[ "$1" =~ ^ghcr\.io/emmakex/site-manager-mcp@sha256:[0-9a-f]{64}$ ]]
}

validate_env_file() {
  local path="$1"
  test -f "$path" || { echo 'production environment file is missing' >&2; return 1; }
  if [ -n "$(find "$path" -maxdepth 0 -perm /077 -print -quit)" ]; then
    echo 'production environment file must have no group/world permissions' >&2
    return 1
  fi
  [ "$(grep -c '^SITE_MANAGER_MCP_EDGE_ALIAS=' "$path" || true)" -eq 1 ]
  [ "$(grep -c '^SITE_MANAGER_REST_BASE=' "$path" || true)" -eq 1 ]
  [ "$(grep -c '^SITE_MANAGER_CONNECTION_ID=' "$path" || true)" -eq 1 ]
  [ "$(grep -c '^SITE_MANAGER_TOKEN=' "$path" || true)" -eq 1 ]
  grep -Fqx "SITE_MANAGER_MCP_EDGE_ALIAS=${EDGE_ALIAS}" "$path" || {
    echo 'production edge alias does not match the logical site id' >&2
    return 1
  }
  grep -Eq '^SITE_MANAGER_REST_BASE=https://[^[:space:]#]+/$' "$path" || {
    echo 'SITE_MANAGER_REST_BASE must be a bounded HTTPS URL ending in /' >&2
    return 1
  }
  grep -Eq '^SITE_MANAGER_CONNECTION_ID=smc_[0-9a-f]{32}$' "$path" || {
    echo 'SITE_MANAGER_CONNECTION_ID is invalid' >&2
    return 1
  }
  grep -Eq '^SITE_MANAGER_TOKEN=[0-9a-f]{64}$' "$path" || {
    echo 'SITE_MANAGER_TOKEN must be the 64-hex Site Manager token' >&2
    return 1
  }
}

command -v docker >/dev/null
docker compose version >/dev/null
docker network inspect "$PUBLIC_EDGE_NETWORK" >/dev/null 2>&1 || {
  echo "$PUBLIC_EDGE_NETWORK must exist before deploying Site Manager MCP" >&2
  exit 1
}

mkdir -p "$DEPLOY_DIR"
chmod 0700 "$DEPLOY_DIR"

compose() {
  local image_ref="$1"
  shift
  SITE_MANAGER_MCP_IMAGE_REF="$image_ref" \
    docker compose \
      --project-name "$PROJECT_NAME" \
      --env-file "$ENV_FILE" \
      -f "$COMPOSE_FILE" \
      "$@"
}

wait_healthy() {
  local image_ref="$1"
  local cid status
  for _ in $(seq 1 60); do
    cid="$(compose "$image_ref" ps -q mcp 2>/dev/null || true)"
    if [ -n "$cid" ]; then
      status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null || true)"
      if [ "$status" = 'healthy' ]; then
        return 0
      fi
      if [ "$status" = 'unhealthy' ] || [ "$status" = 'exited' ] || [ "$status" = 'dead' ]; then
        return 1
      fi
    fi
    sleep 2
  done
  return 1
}

activate() {
  local image_ref="$1"
  validate_env_file "$ENV_FILE"
  test -f "$COMPOSE_FILE"
  compose "$image_ref" config --quiet
  compose "$image_ref" pull
  compose "$image_ref" up -d --remove-orphans
  wait_healthy "$image_ref"
}

current_ref=''
if [ -f "$CURRENT_REF_FILE" ]; then
  current_ref="$(tr -d '\r\n' < "$CURRENT_REF_FILE")"
fi

if [ "$ACTION" = 'rollback' ]; then
  if [ ! -f "$PREVIOUS_REF_FILE" ] || [ ! -f "$PREVIOUS_ENV_FILE" ] || [ ! -f "$PREVIOUS_COMPOSE_FILE" ]; then
    # A first accepted deployment has no prior image. Rolling it back means
    # returning to the pre-deployment baseline: no Site Manager MCP runtime.
    if [ -n "$current_ref" ] && valid_image_ref "$current_ref" && [ -f "$ENV_FILE" ] && [ -f "$COMPOSE_FILE" ]; then
      validate_env_file "$ENV_FILE"
      compose "$current_ref" down --remove-orphans || true
      rm -f "$CURRENT_REF_FILE" "$ENV_FILE" "$COMPOSE_FILE" "$SCRIPT_FILE"
      rm -f "$PREVIOUS_REF_FILE" "$PREVIOUS_ENV_FILE" "$PREVIOUS_COMPOSE_FILE"
      echo "Site Manager MCP rollback restored empty baseline for ${SITE_ID}"
      exit 0
    fi
    echo 'no previous accepted Site Manager MCP deployment is recorded' >&2
    exit 1
  fi

  target_ref="$(tr -d '\r\n' < "$PREVIOUS_REF_FILE")"
  valid_image_ref "$target_ref" || { echo 'recorded previous image reference is invalid' >&2; exit 1; }

  tmp_dir="$(mktemp -d "${DEPLOY_DIR}/.rollback.XXXXXX")"
  trap 'rm -rf "$tmp_dir"' EXIT
  [ -f "$ENV_FILE" ] && cp -p "$ENV_FILE" "$tmp_dir/current.env"
  [ -f "$COMPOSE_FILE" ] && cp -p "$COMPOSE_FILE" "$tmp_dir/current.compose"
  [ -n "$current_ref" ] && printf '%s\n' "$current_ref" > "$tmp_dir/current.ref"

  install -m 0600 "$PREVIOUS_ENV_FILE" "$ENV_FILE"
  install -m 0644 "$PREVIOUS_COMPOSE_FILE" "$COMPOSE_FILE"
  if ! activate "$target_ref"; then
    echo 'rollback target failed health checks; restoring current deployment contract' >&2
    [ -f "$tmp_dir/current.env" ] && install -m 0600 "$tmp_dir/current.env" "$ENV_FILE"
    [ -f "$tmp_dir/current.compose" ] && install -m 0644 "$tmp_dir/current.compose" "$COMPOSE_FILE"
    if [ -f "$tmp_dir/current.ref" ]; then
      restore_ref="$(cat "$tmp_dir/current.ref")"
      valid_image_ref "$restore_ref" && activate "$restore_ref" || true
    fi
    exit 1
  fi

  if [ -f "$tmp_dir/current.ref" ]; then
    install -m 0600 "$tmp_dir/current.ref" "$PREVIOUS_REF_FILE"
  else
    rm -f "$PREVIOUS_REF_FILE"
  fi
  if [ -f "$tmp_dir/current.env" ]; then
    install -m 0600 "$tmp_dir/current.env" "$PREVIOUS_ENV_FILE"
  else
    rm -f "$PREVIOUS_ENV_FILE"
  fi
  if [ -f "$tmp_dir/current.compose" ]; then
    install -m 0644 "$tmp_dir/current.compose" "$PREVIOUS_COMPOSE_FILE"
  else
    rm -f "$PREVIOUS_COMPOSE_FILE"
  fi
  printf '%s\n' "$target_ref" > "$CURRENT_REF_FILE"
  chmod 0600 "$CURRENT_REF_FILE"
  echo "Site Manager MCP rollback healthy for ${SITE_ID}: ${target_ref}"
  exit 0
fi

: "${SITE_MANAGER_MCP_IMAGE_REF:?set SITE_MANAGER_MCP_IMAGE_REF for deploy}"
valid_image_ref "$SITE_MANAGER_MCP_IMAGE_REF" || {
  echo 'SITE_MANAGER_MCP_IMAGE_REF must be an immutable Site Manager MCP GHCR digest' >&2
  exit 2
}
: "${SOURCE_DIR:?set SITE_MANAGER_RELEASE_SOURCE_DIR for deploy}"
CANDIDATE_ENV="${SOURCE_DIR}/.env.production"
CANDIDATE_COMPOSE="${SOURCE_DIR}/compose.edge.yaml"
CANDIDATE_SCRIPT="${SOURCE_DIR}/site-manager-mcp-ovh-deploy.sh"
test -f "$CANDIDATE_COMPOSE"
test -f "$CANDIDATE_SCRIPT"
validate_env_file "$CANDIDATE_ENV"

backup_dir="$(mktemp -d "${DEPLOY_DIR}/.deploy.XXXXXX")"
trap 'rm -rf "$backup_dir"' EXIT
[ -f "$ENV_FILE" ] && cp -p "$ENV_FILE" "$backup_dir/current.env"
[ -f "$COMPOSE_FILE" ] && cp -p "$COMPOSE_FILE" "$backup_dir/current.compose"
[ -n "$current_ref" ] && printf '%s\n' "$current_ref" > "$backup_dir/current.ref"

install -m 0600 "$CANDIDATE_ENV" "$ENV_FILE"
install -m 0644 "$CANDIDATE_COMPOSE" "$COMPOSE_FILE"

if ! activate "$SITE_MANAGER_MCP_IMAGE_REF"; then
  echo 'new Site Manager MCP deployment failed local health checks' >&2
  if [ -f "$backup_dir/current.env" ] && [ -f "$backup_dir/current.compose" ] && [ -f "$backup_dir/current.ref" ]; then
    restore_ref="$(cat "$backup_dir/current.ref")"
    if valid_image_ref "$restore_ref"; then
      echo 'attempting automatic rollback to the previously accepted Site Manager MCP deployment' >&2
      install -m 0600 "$backup_dir/current.env" "$ENV_FILE"
      install -m 0644 "$backup_dir/current.compose" "$COMPOSE_FILE"
      activate "$restore_ref" || { echo 'automatic rollback also failed' >&2; exit 1; }
    fi
  else
    # No prior deployment exists: return to the empty baseline.
    compose "$SITE_MANAGER_MCP_IMAGE_REF" down --remove-orphans || true
    rm -f "$ENV_FILE" "$COMPOSE_FILE"
  fi
  exit 1
fi

if [ -f "$backup_dir/current.ref" ]; then
  install -m 0600 "$backup_dir/current.ref" "$PREVIOUS_REF_FILE"
  install -m 0600 "$backup_dir/current.env" "$PREVIOUS_ENV_FILE"
  install -m 0644 "$backup_dir/current.compose" "$PREVIOUS_COMPOSE_FILE"
fi
printf '%s\n' "$SITE_MANAGER_MCP_IMAGE_REF" > "$CURRENT_REF_FILE"
chmod 0600 "$CURRENT_REF_FILE"
install -m 0700 "$CANDIDATE_SCRIPT" "$SCRIPT_FILE"

echo "Site Manager MCP deployment healthy on shared edge for ${SITE_ID}: ${SITE_MANAGER_MCP_IMAGE_REF}"
