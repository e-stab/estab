#!/usr/bin/env bash

set -Eeuo pipefail

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

if [[ ! -x node_modules/.bin/playwright ]]; then
    echo 'Playwright E2E: run npm ci and npm run test:e2e:install first' >&2
    exit 2
fi
command -v openssl >/dev/null 2>&1 || {
    echo 'Playwright E2E: openssl is required' >&2
    exit 2
}
command -v curl >/dev/null 2>&1 || {
    echo 'Playwright E2E: curl is required' >&2
    exit 2
}

container_cli=${ESTAB_CONTAINER_CLI:-}
if [[ -z $container_cli ]]; then
    if command -v docker >/dev/null 2>&1; then
        container_cli=docker
    elif command -v podman >/dev/null 2>&1; then
        container_cli=podman
    else
        echo 'Playwright E2E: Docker or Podman with Compose is required' >&2
        exit 2
    fi
fi
case "$container_cli" in
    docker | podman) ;;
    *)
        echo 'Playwright E2E: ESTAB_CONTAINER_CLI must be docker or podman' >&2
        exit 2
        ;;
esac
"$container_cli" compose version >/dev/null

export COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-estab_e2e_$$}
if [[ ! $COMPOSE_PROJECT_NAME =~ ^estab_e2e(_[a-z0-9_-]+)?$ ]]; then
    echo 'Playwright E2E: refusing mutation outside an estab_e2e project' >&2
    exit 2
fi

export ESTAB_HTTP_BIND=127.0.0.1
export ESTAB_HTTP_PORT=${ESTAB_HTTP_PORT:-$((20000 + ($$ % 20000)))}
if [[ ! $ESTAB_HTTP_PORT =~ ^[0-9]+$ ]] \
    || ((ESTAB_HTTP_PORT < 1024 || ESTAB_HTTP_PORT > 65535)); then
    echo 'Playwright E2E: ESTAB_HTTP_PORT must be between 1024 and 65535' >&2
    exit 2
fi

compose_started=false
secret_dir=$(mktemp -d "$repo_root/.estab-e2e-secrets.XXXXXX")
cleanup() {
    status=$?
    trap - EXIT INT TERM
    if [[ $compose_started == true ]]; then
        if ((status != 0)); then
            "$container_cli" compose ps --all >&2 || true
            "$container_cli" compose logs --tail=200 >&2 || true
        fi
        "$container_cli" compose down --volumes --remove-orphans --timeout 20 \
            >/dev/null 2>&1 || true
    fi
    rm -rf -- "$secret_dir"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

chmod 0700 "$secret_dir"
umask 077

openssl rand -hex 32 >"$secret_dir/db_password.txt"
openssl rand -hex 32 >"$secret_dir/db_root_password.txt"
openssl rand -hex 32 >"$secret_dir/admin_password.txt"
user_password="E2E!aA9-$(openssl rand -hex 18)"
printf '%s\n' "$user_password" >"$secret_dir/user_password.txt"
unset user_password
chmod 0600 "$secret_dir"/*.txt

export ESTAB_DB_NAME=estab
export ESTAB_DB_USER=estab
export ESTAB_ADMIN_USER=estab-admin
export ESTAB_DB_PASSWORD_SECRET_FILE="$secret_dir/db_password.txt"
export ESTAB_DB_ROOT_PASSWORD_SECRET_FILE="$secret_dir/db_root_password.txt"
export ESTAB_ADMIN_PASSWORD_SECRET_FILE="$secret_dir/admin_password.txt"
export ESTAB_ALLOW_SELF_REGISTRATION=false
export ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=false
export ESTAB_PUBLIC_URL=/
export TZ=Europe/Berlin

marker_hash=$(printf '%s' "$COMPOSE_PROJECT_NAME" \
    | openssl dgst -sha256 -r \
    | awk '{ print toupper(substr($1, 1, 8)) }')
if [[ ! $marker_hash =~ ^[A-F0-9]{8}$ ]]; then
    echo 'Playwright E2E: could not derive a safe workflow marker' >&2
    exit 2
fi
export ESTAB_E2E_MARKER="PWE2E_${marker_hash}"
export ESTAB_E2E_BASE_URL="http://127.0.0.1:${ESTAB_HTTP_PORT}"
export ESTAB_E2E_ADMIN_USER="$ESTAB_ADMIN_USER"
export ESTAB_E2E_ADMIN_PASSWORD_FILE="$secret_dir/admin_password.txt"
export ESTAB_E2E_USER_PASSWORD_FILE="$secret_dir/user_password.txt"

fixture() {
    "$container_cli" compose run --rm --no-deps -T \
        --env "COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME" \
        --env ESTAB_E2E_FIXTURE=1 \
        --env "ESTAB_E2E_MARKER=$ESTAB_E2E_MARKER" \
        --volume "$repo_root:/workspace:ro" \
        --workdir /workspace \
        app php -d auto_prepend_file= tests/e2e/fixture.php "$@"
}

echo "Playwright E2E: starting isolated stack ${COMPOSE_PROJECT_NAME} on port ${ESTAB_HTTP_PORT}"
compose_started=true
"$container_cli" compose up --detach --build

healthy=false
for _attempt in $(seq 1 120); do
    if curl --fail --silent --show-error \
        "${ESTAB_E2E_BASE_URL}/health.php" >/dev/null 2>&1; then
        healthy=true
        break
    fi
    sleep 2
done
if [[ $healthy != true ]]; then
    echo 'Playwright E2E: application did not become healthy' >&2
    "$container_cli" compose ps --all >&2 || true
    "$container_cli" compose logs --tail=200 >&2 || true
    exit 1
fi

fixture prepare
node_modules/.bin/playwright test
fixture verify

echo 'Playwright E2E: complete workflow verified'
