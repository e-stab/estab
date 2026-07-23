#!/usr/bin/env bash

set -Eeuo pipefail

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

container_cli=${ESTAB_CONTAINER_CLI:-docker}
case "$container_cli" in
    docker | podman) ;;
    *)
        echo "CI integration: ESTAB_CONTAINER_CLI must be docker or podman" >&2
        exit 1
        ;;
esac
command -v "$container_cli" >/dev/null 2>&1 || {
    echo "CI integration: ${container_cli} is required" >&2
    exit 1
}
"$container_cli" compose version >/dev/null
command -v openssl >/dev/null 2>&1 || {
    echo "CI integration: openssl is required for ephemeral secrets" >&2
    exit 1
}

export COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-estab_ci}
export ESTAB_DB_NAME=${ESTAB_DB_NAME:-estab}
export ESTAB_DB_USER=${ESTAB_DB_USER:-estab}
export ESTAB_ADMIN_USER=${ESTAB_ADMIN_USER:-estab-admin}
export ESTAB_HTTP_BIND=${ESTAB_HTTP_BIND:-127.0.0.1}
export ESTAB_HTTP_PORT=${ESTAB_HTTP_PORT:-18080}
export ESTAB_PUBLIC_URL=${ESTAB_PUBLIC_URL:-http://127.0.0.1:${ESTAB_HTTP_PORT}/}
export ESTAB_ALLOW_SELF_REGISTRATION=true
export ESTAB_TEST_COMPOSE_ENGINE=${ESTAB_TEST_COMPOSE_ENGINE:-$container_cli}
export TZ=${TZ:-Europe/Berlin}

if [[ ! $COMPOSE_PROJECT_NAME =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
    echo "CI integration: invalid COMPOSE_PROJECT_NAME" >&2
    exit 1
fi
case "$COMPOSE_PROJECT_NAME" in
    estab_ci | estab_ci_*) ;;
    *)
        echo "CI integration: refusing destructive cleanup outside an estab_ci project" >&2
        exit 1
        ;;
esac
if [[ ! $ESTAB_HTTP_PORT =~ ^[0-9]+$ ]] || ((ESTAB_HTTP_PORT < 1024 || ESTAB_HTTP_PORT > 65535)); then
    echo "CI integration: ESTAB_HTTP_PORT must be between 1024 and 65535" >&2
    exit 1
fi

temporary_parent=${ESTAB_CI_TEMP_PARENT:-}
if [[ -z $temporary_parent ]]; then
    # Podman Desktop only shares selected host paths with its VM. Keeping its
    # short-lived bind-mounted Compose secrets below the checked-out workspace
    # avoids an unreadable macOS /tmp mount. Docker runners can use RUNNER_TEMP.
    if [[ $container_cli == podman ]]; then
        temporary_parent=$repo_root
    else
        temporary_parent=${RUNNER_TEMP:-/tmp}
    fi
fi
if [[ ! -d $temporary_parent || ! -w $temporary_parent ]]; then
    echo "CI integration: temporary parent is not a writable directory" >&2
    exit 1
fi
secret_dir=$(mktemp -d "${temporary_parent%/}/.estab-ci-secrets.XXXXXX")
roundtrip_dir=$(mktemp -d "${temporary_parent%/}/.estab-ci-roundtrip.XXXXXX")
chmod 0700 "$secret_dir"
chmod 0700 "$roundtrip_dir"
umask 077

db_password=$(openssl rand -hex 32)
db_root_password=$(openssl rand -hex 32)
admin_password=$(openssl rand -hex 32)
login_password=$(openssl rand -hex 32)
printf '%s\n' "$db_password" >"$secret_dir/db_password.txt"
printf '%s\n' "$db_root_password" >"$secret_dir/db_root_password.txt"
printf '%s\n' "$admin_password" >"$secret_dir/admin_password.txt"
printf '%s\n' "$login_password" >"$secret_dir/login_password.txt"
unset db_password db_root_password admin_password login_password

export ESTAB_DB_PASSWORD_SECRET_FILE="$secret_dir/db_password.txt"
export ESTAB_DB_ROOT_PASSWORD_SECRET_FILE="$secret_dir/db_root_password.txt"
export ESTAB_ADMIN_PASSWORD_SECRET_FILE="$secret_dir/admin_password.txt"
export ESTAB_TEST_LOGIN_PASSWORD_FILE="$secret_dir/login_password.txt"
export ESTAB_TEST_ADMIN_PASSWORD_FILE="$secret_dir/admin_password.txt"

compose() {
    "$container_cli" compose "$@"
}

run_timed() {
    local duration=$1
    shift
    if command -v timeout >/dev/null 2>&1; then
        timeout --signal=TERM --kill-after=30s "$duration" "$@"
    else
        "$@"
    fi
}

capture_diagnostics() {
    local destination=${ESTAB_CI_LOG_DIR:-}
    if [[ -n $destination ]]; then
        mkdir -p "$destination"
        compose ps --all --format json >"$destination/compose-ps.json" 2>&1 || true
        compose logs --no-color --timestamps >"$destination/compose.log" 2>&1 || true
        for service in db migrate app; do
            local container_id
            container_id=$(compose ps --all -q "$service" 2>/dev/null || true)
            if [[ -n $container_id ]]; then
                "$container_cli" inspect "$container_id" >"$destination/${service}-inspect.json" 2>&1 || true
            fi
        done
    fi

    echo "CI integration: container status at failure" >&2
    compose ps --all >&2 || true
    echo "CI integration: last container log lines" >&2
    compose logs --no-color --tail=300 >&2 || true
}

cleanup() {
    local status=$?
    trap - EXIT INT TERM
    if ((status != 0)); then
        capture_diagnostics
    fi
    compose down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1 || true
    rm -rf -- "$secret_dir" "$roundtrip_dir"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

wait_for_healthy() {
    local service=$1
    local timeout_seconds=$2
    local deadline=$((SECONDS + timeout_seconds))
    local container_id status

    while ((SECONDS < deadline)); do
        container_id=$(compose ps -q "$service" 2>/dev/null || true)
        if [[ -n $container_id ]]; then
            status=$("$container_cli" inspect --format \
                '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
                "$container_id" 2>/dev/null || true)
            case "$status" in
                healthy)
                    echo "CI integration: ${service} is healthy"
                    return 0
                    ;;
                unhealthy | exited | dead)
                    echo "CI integration: ${service} entered ${status}" >&2
                    return 1
                    ;;
            esac
        fi
        sleep 3
    done
    echo "CI integration: ${service} did not become healthy within ${timeout_seconds}s" >&2
    return 1
}

verify_schema() {
    local output check_count
    if ! output=$(compose exec -T db sh -ceu \
        '
        umask 077
        client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-ci-client.XXXXXX")
        cleanup_client_defaults()
        {
            rm -f -- "$client_defaults"
        }
        trap cleanup_client_defaults EXIT HUP INT TERM

        root_password=$(tr -d "\r\n" </run/secrets/estab_db_root_password)
        escaped_password=$(printf "%s" "$root_password" |
            sed -e "s/\\\\/\\\\\\\\/g" -e "s/\"/\\\\\"/g")
        unset root_password
        {
            printf "[client]\n"
            printf "user=root\n"
            printf "password=\"%s\"\n" "$escaped_password"
            printf "protocol=socket\n"
            printf "default-character-set=utf8mb4\n"
        } >"$client_defaults"
        unset escaped_password
        chmod 0600 "$client_defaults"

        mariadb \
            --defaults-extra-file="$client_defaults" \
            --batch \
            --skip-column-names \
            --raw \
            --database="$MARIADB_DATABASE"
        ' \
        <docker/db/verify.sql); then
        echo "CI integration: schema verification command failed" >&2
        return 1
    fi
    if ! printf '%s\n' "$output" | awk -F '\t' '
        NR == 1 {
            if (NF < 1) exit 1
            for (column = 1; column <= NF; column++) {
                if ($column != "1") exit 1
            }
            first = 1
            next
        }
        { exit 1 }
        END { if (!first) exit 1 }
    '; then
        echo "CI integration: one or more schema checks failed" >&2
        printf '%s\n' "$output" >&2
        return 1
    fi
    check_count=$(printf '%s\n' "$output" | awk -F '\t' 'NR == 1 { print NF }')
    echo "CI integration: schema verification OK (${check_count} checks)"
}

run_php_integration() {
    local label=$1
    local test_file=$2
    echo "CI integration: running ${label}"
    run_timed 4m "$container_cli" compose run --rm --no-deps -T \
        --volume "$repo_root:/workspace:ro" \
        --workdir /workspace \
        app php -d auto_prepend_file= "$test_file"
}

assert_clean_app_logs() {
    local app_logs
    local php_log_error_pattern='PHP (Warning|Deprecated|Notice|Fatal error|Parse error):|Uncaught'
    app_logs=$(compose logs --no-color app)
    if printf '%s\n' "$app_logs" | grep -E "$php_log_error_pattern" >/dev/null; then
        echo "CI integration: PHP warning, deprecation, notice, or fatal runtime error found in application log" >&2
        printf '%s\n' "$app_logs" | grep -E "$php_log_error_pattern" >&2
        return 1
    fi
}

echo "CI integration: validating Compose configuration"
compose config >/dev/null

# A previous interrupted local run must never turn this into an upgrade test.
compose down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1 || true

echo "CI integration: pulling and building pinned runtime images"
run_timed 5m "$container_cli" compose pull db
run_timed 15m "$container_cli" compose build --pull migrate app

echo "CI integration: starting a fresh database"
run_timed 5m "$container_cli" compose up --detach db
wait_for_healthy db 180

echo "CI integration: exercising versioned schema migration"
run_timed 8m "$container_cli" compose run --rm --no-deps -T \
    --volume "$repo_root:/workspace:ro" \
    --entrypoint /workspace/tests/integration/schema_migrator.sh \
    migrate

echo "CI integration: starting the migrated application stack"
run_timed 5m "$container_cli" compose up --detach
wait_for_healthy app 240

verify_schema
run_php_integration "nullable-date migration" tests/integration/date_compatibility.php

echo "CI integration: running dynamic-table migration"
run_timed 5m "$container_cli" compose run --rm --no-deps -T \
    --volume "$repo_root:/workspace:ro" \
    --workdir /workspace \
    app sh -ceu '
        export ESTAB_TEST_DB_HOST="$ESTAB_DB_HOST"
        export ESTAB_TEST_DB_PORT="$ESTAB_DB_PORT"
        export ESTAB_TEST_DB_NAME="$ESTAB_DB_NAME"
        export ESTAB_TEST_DB_USER="$ESTAB_DB_USER"
        export ESTAB_TEST_DB_PASSWORD="$ESTAB_DB_PASSWORD"
        exec php -d auto_prepend_file= tests/integration/dynamic_tables.php
    '

run_php_integration \
    "parallel attachment reservation" \
    tests/integration/attachment_reservation.php

run_php_integration \
    "message concurrency and state ownership" \
    tests/integration/message_concurrency.php

roundtrip_token=$(printf '%s' "$COMPOSE_PROJECT_NAME" |
    openssl dgst -sha256 -r | awk '{ print substr($1, 1, 16) }')
workflow_marker="ESTAB_BACKUP_ROUNDTRIP_${roundtrip_token}"
http_state_file="$roundtrip_dir/http-state"

export ESTAB_TEST_BASE_URL="http://127.0.0.1:${ESTAB_HTTP_PORT}"
export ESTAB_TEST_ADMIN_USER="$ESTAB_ADMIN_USER"
export ESTAB_TEST_WORKFLOW_MARKER="$workflow_marker"

echo "CI integration: checking the direct HTTP surface"
run_timed 3m sh tests/integration/http_surface_http.sh

echo "CI integration: running authenticated HTTP smoke"
export ESTAB_TEST_STATE_FILE="$http_state_file"
run_timed 5m sh tests/integration/http_smoke.sh
unset ESTAB_TEST_STATE_FILE

if [[ $(sed -n '1p' "$http_state_file") != "$workflow_marker" ]]; then
    echo "CI integration: HTTP smoke did not persist the expected workflow marker state" >&2
    exit 1
fi
restore_attachment=$(sed -n '2p' "$http_state_file")
if [[ ! $restore_attachment =~ ^[A-Za-z]{2}[0-9]{4,}\.[A-Za-z0-9]{1,16}$ ]]; then
    echo "CI integration: HTTP smoke returned an unsafe attachment name" >&2
    exit 1
fi

echo "CI integration: running ETB/TBB HTTP integration"
run_timed 5m sh tests/integration/logbooks_http.sh

echo "CI integration: running category HTTP integration"
export ESTAB_CATEGORY_HTTP_TEST_ALLOW_MUTATION=true
run_timed 5m sh tests/integration/categories_http.sh
unset ESTAB_CATEGORY_HTTP_TEST_ALLOW_MUTATION

echo "CI integration: running administrative workflow HTTP integration"
export ESTAB_ADMIN_HTTP_TEST_ALLOW_MUTATION=true
run_timed 5m sh tests/integration/admin_workflows_http.sh
unset ESTAB_ADMIN_HTTP_TEST_ALLOW_MUTATION

wait_for_healthy db 30
wait_for_healthy app 60
verify_schema
assert_clean_app_logs

echo "CI integration: running destructive backup/restore roundtrip"
if [[ -n ${ESTAB_CI_LOG_DIR:-} ]]; then
    mkdir -p "$ESTAB_CI_LOG_DIR"
    compose ps --all --format json >"$ESTAB_CI_LOG_DIR/pre-restore-compose-ps.json" 2>&1 || true
    compose logs --no-color --timestamps >"$ESTAB_CI_LOG_DIR/pre-restore-compose.log" 2>&1 || true
fi
export ESTAB_BACKUP_DIR="$roundtrip_dir"
run_timed 15m bash tests/integration/restore_roundtrip.sh
unset ESTAB_BACKUP_DIR

wait_for_healthy db 30
wait_for_healthy app 60
verify_schema

echo "CI integration: checking restored export-volume marker"
run_timed 4m "$container_cli" compose run --rm --no-deps -T \
    --volume "$http_state_file:/run/estab/http-state:ro" \
    --entrypoint sh \
    app -ceu '
        marker=$(sed -n "1p" /run/estab/http-state)
        test -n "$marker"
        grep -R -F -q -- "$marker" /var/lib/estab/export
    '

echo "CI integration: re-authenticating against restored state"
export ESTAB_TEST_RESTORE_VERIFY_ONLY=true
export ESTAB_TEST_RESTORE_ATTACHMENT="$restore_attachment"
run_timed 5m sh tests/integration/http_smoke.sh
unset ESTAB_TEST_RESTORE_VERIFY_ONLY ESTAB_TEST_RESTORE_ATTACHMENT

wait_for_healthy db 30
wait_for_healthy app 60
verify_schema
assert_clean_app_logs

echo "CI integration: OK"
