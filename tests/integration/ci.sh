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

prebuilt_app_image=${ESTAB_PREBUILT_APP_IMAGE:-}
prebuilt_migrate_image=${ESTAB_PREBUILT_MIGRATE_IMAGE:-}
if [[ -n $prebuilt_app_image || -n $prebuilt_migrate_image ]]; then
    if [[ -z $prebuilt_app_image || -z $prebuilt_migrate_image ]]; then
        echo "CI integration: both prebuilt image references are required" >&2
        exit 1
    fi
    for prebuilt_image in "$prebuilt_app_image" "$prebuilt_migrate_image"; do
        if [[ ! $prebuilt_image =~ ^[^[:space:]@]+@sha256:[a-f0-9]{64}$ ]]; then
            echo "CI integration: prebuilt images must use exact sha256 digest references" >&2
            exit 1
        fi
    done
fi

browser_test_mode=${ESTAB_BROWSER_TEST:-auto}
case "$browser_test_mode" in
    auto | required | skip) ;;
    *)
        echo "CI integration: ESTAB_BROWSER_TEST must be auto, required, or skip" >&2
        exit 1
        ;;
esac
browser_test_enabled=false
if [[ $browser_test_mode != skip ]]; then
    browser_check_output=
    if command -v python3 >/dev/null 2>&1 &&
        browser_check_output=$(python3 -B tests/browser/headless_ui.py --check-browser 2>&1); then
        browser_test_enabled=true
        echo "CI integration: browser acceptance enabled (${browser_check_output})"
    elif [[ $browser_test_mode == required ]]; then
        echo "CI integration: required browser acceptance is unavailable" >&2
        if [[ -n $browser_check_output ]]; then
            printf '%s\n' "$browser_check_output" >&2
        else
            echo "CI integration: python3 is required for browser acceptance" >&2
        fi
        exit 1
    else
        echo "CI integration: browser acceptance skipped; set ESTAB_BROWSER_TEST=required for a release gate"
    fi
else
    echo "CI integration: browser acceptance explicitly skipped"
fi

export COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-estab_ci}
export ESTAB_DB_NAME=${ESTAB_DB_NAME:-estab}
export ESTAB_DB_USER=${ESTAB_DB_USER:-estab}
export ESTAB_ADMIN_USER=${ESTAB_ADMIN_USER:-estab-admin}
export ESTAB_HTTP_BIND=${ESTAB_HTTP_BIND:-127.0.0.1}
export ESTAB_HTTP_PORT=${ESTAB_HTTP_PORT:-18080}
export ESTAB_PUBLIC_URL=${ESTAB_PUBLIC_URL:-/}
export ESTAB_ALLOW_SELF_REGISTRATION=false
export ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=false
export ESTAB_REVIEW_OUTGOING_MESSAGES=false
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

verify_prebuilt_runtime_images() {
    local phase=${1:-runtime}
    local service expected_image_id container_id actual_image_id
    if [[ ! $phase =~ ^[a-z][a-z0-9_-]*$ ]]; then
        echo "CI integration: invalid exact-image verification phase" >&2
        return 1
    fi
    for service in migrate app; do
        case "$service" in
            migrate) expected_image_id=$expected_migrate_id ;;
            app) expected_image_id=$expected_app_id ;;
        esac
        container_id=$(compose ps --all -q "$service")
        if [[ -z $container_id ]]; then
            echo "CI integration: ${service} container is missing from the exact-image stack" >&2
            return 1
        fi
        actual_image_id=$("$container_cli" inspect \
            --format '{{.Image}}' "$container_id")
        if [[ $actual_image_id != "$expected_image_id" ]]; then
            echo "CI integration: ${service} did not execute the exact prebuilt image" >&2
            return 1
        fi
        if [[ -n ${ESTAB_CI_LOG_DIR:-} ]]; then
            printf '%s_%s_image_id=%s\n' "$phase" "$service" "$actual_image_id" \
                >>"$ESTAB_CI_LOG_DIR/prebuilt-images.env"
        fi
    done
    echo "CI integration: exact prebuilt image IDs are running"
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

incident_test_database() {
    local action=$1
    case "$action" in
        create | drop) ;;
        *)
            echo "CI integration: invalid incident test database action" >&2
            return 1
            ;;
    esac

    compose exec -T db sh -ceu '
        umask 077
        client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-incident-client.XXXXXX")
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

        case "$1" in
            create)
                statement="
                    DROP DATABASE IF EXISTS \`estab_incident_ci_test\`;
                    CREATE DATABASE \`estab_incident_ci_test\`
                      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                ;;
            drop)
                statement="
                    DROP DATABASE IF EXISTS \`estab_incident_ci_test\`"
                ;;
        esac
        mariadb \
            --defaults-extra-file="$client_defaults" \
            --execute="$statement"
    ' incident-test-database "$action"
}

run_incident_domain_integration() {
    local status=0
    local database_name=estab_incident_ci_test

    echo "CI integration: creating an isolated incident-domain database"
    incident_test_database create
    if ! run_timed 4m "$container_cli" compose run --rm --no-deps -T \
        --env "ESTAB_DB_NAME=$database_name" \
        migrate; then
        status=1
    elif ! run_timed 4m "$container_cli" compose run --rm --no-deps -T \
        --env ESTAB_INCIDENT_INTEGRATION=1 \
        --env "ESTAB_DB_NAME=$database_name" \
        --env ESTAB_DB_ROOT_PASSWORD_FILE=/run/secrets/incident_root_password \
        --volume "$repo_root:/workspace:ro" \
        --volume \
            "$secret_dir/db_root_password.txt:/run/secrets/incident_root_password:ro" \
        --workdir /workspace \
        app php -d auto_prepend_file= tests/integration/incident_domain.php; then
        status=1
    fi
    if ! incident_test_database drop; then
        status=1
    fi
    return "$status"
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

echo "CI integration: preparing pinned runtime images"
run_timed 5m "$container_cli" compose pull db
if [[ -n $prebuilt_app_image ]]; then
    echo "CI integration: pulling exact prebuilt application and migration digests"
    run_timed 10m "$container_cli" pull "$prebuilt_app_image"
    run_timed 10m "$container_cli" pull "$prebuilt_migrate_image"
    "$container_cli" tag \
        "$prebuilt_app_image" "${COMPOSE_PROJECT_NAME}-app:latest"
    "$container_cli" tag \
        "$prebuilt_migrate_image" "${COMPOSE_PROJECT_NAME}-migrate:latest"
    expected_app_id=$("$container_cli" image inspect \
        --format '{{.Id}}' "$prebuilt_app_image")
    expected_migrate_id=$("$container_cli" image inspect \
        --format '{{.Id}}' "$prebuilt_migrate_image")
    tagged_app_id=$("$container_cli" image inspect \
        --format '{{.Id}}' "${COMPOSE_PROJECT_NAME}-app:latest")
    tagged_migrate_id=$("$container_cli" image inspect \
        --format '{{.Id}}' "${COMPOSE_PROJECT_NAME}-migrate:latest")
    if [[ $expected_app_id != "$tagged_app_id" ||
        $expected_migrate_id != "$tagged_migrate_id" ]]; then
        echo "CI integration: exact prebuilt images were not tagged byte-identically" >&2
        exit 1
    fi
    if [[ -n ${ESTAB_CI_LOG_DIR:-} ]]; then
        mkdir -p "$ESTAB_CI_LOG_DIR"
        {
            printf 'app_reference=%s\n' "$prebuilt_app_image"
            printf 'app_image_id=%s\n' "$expected_app_id"
            printf 'migrate_reference=%s\n' "$prebuilt_migrate_image"
            printf 'migrate_image_id=%s\n' "$expected_migrate_id"
        } >"$ESTAB_CI_LOG_DIR/prebuilt-images.env"
    fi
else
    echo "CI integration: building application and migration images from source"
    run_timed 15m "$container_cli" compose build --pull migrate app
fi

registry_http_port=${ESTAB_REGISTRY_HTTP_PORT:-}
if [[ -z $registry_http_port ]]; then
    if ((ESTAB_HTTP_PORT < 65535)); then
        registry_http_port=$((ESTAB_HTTP_PORT + 1))
    else
        registry_http_port=$((ESTAB_HTTP_PORT - 1))
    fi
fi
export ESTAB_REGISTRY_PROJECT="${COMPOSE_PROJECT_NAME}_registry"
export ESTAB_REGISTRY_HTTP_PORT="$registry_http_port"
export ESTAB_REGISTRY_APP_IMAGE="${COMPOSE_PROJECT_NAME}-app:latest"
export ESTAB_REGISTRY_MIGRATE_IMAGE="${COMPOSE_PROJECT_NAME}-migrate:latest"
export ESTAB_REGISTRY_TEMP_PARENT="$temporary_parent"
echo "CI integration: validating pull-only registry deployment and bind restore"
run_timed 16m sh tests/integration/registry_compose.sh
unset \
    ESTAB_REGISTRY_PROJECT \
    ESTAB_REGISTRY_HTTP_PORT \
    ESTAB_REGISTRY_APP_IMAGE \
    ESTAB_REGISTRY_MIGRATE_IMAGE \
    ESTAB_REGISTRY_TEMP_PARENT

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
if [[ -n $prebuilt_app_image ]]; then
    verify_prebuilt_runtime_images initial
fi

verify_schema
run_incident_domain_integration
run_php_integration "nullable-date migration" tests/integration/date_compatibility.php
run_php_integration "user administration" tests/integration/user_admin.php
run_php_integration \
    "assignment-policy concurrency and revocation" \
    tests/integration/assignment_policy.php

echo "CI integration: activating the named incident through the domain API"
run_timed 4m "$container_cli" compose run --rm --no-deps -T \
    --env ESTAB_INCIDENT_CI_BOOTSTRAP=1 \
    --volume "$repo_root:/workspace:ro" \
    --workdir /workspace \
    app php -d auto_prepend_file= tests/integration/incident_ci_bootstrap.php

echo "CI integration: proving the incident-scoped PDF dossier"
run_timed 4m "$container_cli" compose run --rm --no-deps -T \
    --env ESTAB_INCIDENT_EXPORT_INTEGRATION=1 \
    --volume "$repo_root:/workspace:ro" \
    --workdir /workspace \
    app php -d auto_prepend_file= tests/integration/incident_export.php

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

if [[ $browser_test_enabled == true ]]; then
    echo "CI integration: running real-browser menu and session acceptance"
    reserved_login_codes=(
        "$(printf '%s' "${ESTAB_TEST_LOGIN_CODE:-e2e001}" | tr '[:upper:]' '[:lower:]')"
        "$(printf '%s' "${ESTAB_TEST_ETB_CODE:-e2s200}" | tr '[:upper:]' '[:lower:]')"
        "$(printf '%s' "${ESTAB_TEST_TBB_CODE:-e2aw00}" | tr '[:upper:]' '[:lower:]')"
        "$(printf '%s' "${ESTAB_TEST_CATEGORY_SI_CODE:-e2si00}" | tr '[:upper:]' '[:lower:]')"
    )
    browser_login_code=
    for browser_prefix in b c d f g h j k; do
        browser_candidate="${browser_prefix}${roundtrip_token:0:5}"
        browser_collision=false
        for reserved_login_code in "${reserved_login_codes[@]}"; do
            if [[ $browser_candidate == "$reserved_login_code" ]]; then
                browser_collision=true
                break
            fi
        done
        if [[ $browser_collision == false ]]; then
            browser_login_code=$browser_candidate
            break
        fi
    done
    if [[ -z $browser_login_code ]]; then
        echo "CI integration: could not allocate an isolated browser test code" >&2
        exit 1
    fi
    (
        export ESTAB_TEST_LOGIN_NAME="Browser Acceptance"
        export ESTAB_TEST_LOGIN_CODE="$browser_login_code"
        export ESTAB_TEST_LOGIN_FUNCTION="S1"
        browser_login_password=$(tr -d '\r\n' <"$ESTAB_TEST_LOGIN_PASSWORD_FILE")
        sh tests/integration/provision_user.sh \
            "$ESTAB_TEST_LOGIN_NAME" \
            "$ESTAB_TEST_LOGIN_CODE" \
            "$ESTAB_TEST_LOGIN_FUNCTION" \
            "$browser_login_password"
        unset browser_login_password
        if [[ -n ${ESTAB_CI_LOG_DIR:-} ]]; then
            export ESTAB_BROWSER_ARTIFACT_DIR="$ESTAB_CI_LOG_DIR/browser"
        fi
        run_timed 4m python3 -B tests/browser/headless_ui.py
    )
fi

echo "CI integration: running authenticated HTTP smoke"
export ESTAB_TEST_STATE_FILE="$http_state_file"
run_timed 5m sh tests/integration/http_smoke.sh
unset ESTAB_TEST_STATE_FILE

if [[ $(sed -n '1p' "$http_state_file") != "$workflow_marker" ]]; then
    echo "CI integration: HTTP smoke did not persist the expected workflow marker state" >&2
    exit 1
fi
restore_state_lines=$(wc -l <"$http_state_file" | tr -d '[:space:]')
if [[ "$restore_state_lines" != 6 ]]; then
    echo "CI integration: HTTP smoke state file must contain exactly six lines" >&2
    exit 1
fi
restore_attachment=$(sed -n '2p' "$http_state_file")
if [[ ! $restore_attachment =~ ^[A-Za-z]{2}[0-9]{4,}\.[A-Za-z0-9]{1,16}$ ]]; then
    echo "CI integration: HTTP smoke returned an unsafe attachment name" >&2
    exit 1
fi
restore_vordruck=$(sed -n '3p' "$http_state_file")
restore_vordruck_sha256=$(sed -n '4p' "$http_state_file")
restore_export_id=$(sed -n '5p' "$http_state_file")
restore_export_sha256=$(sed -n '6p' "$http_state_file")
if [[ ! $restore_vordruck =~ ^[A-Za-z0-9_]+\ Einsatz-[1-9][0-9]*\ [1-9][0-9]*\ [EA]\.pdf$ ]]; then
    echo "CI integration: HTTP smoke returned an unsafe generated-form name" >&2
    exit 1
fi
if [[ ! $restore_vordruck_sha256 =~ ^[a-f0-9]{64}$ ]]; then
    echo "CI integration: HTTP smoke returned an invalid generated-form checksum" >&2
    exit 1
fi
if [[ ! $restore_export_id =~ ^estab-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$ ]]; then
    echo "CI integration: HTTP smoke returned an unsafe survivor export ID" >&2
    exit 1
fi
if [[ ! $restore_export_sha256 =~ ^[a-f0-9]{64}$ ]]; then
    echo "CI integration: HTTP smoke returned an invalid survivor export checksum" >&2
    exit 1
fi

echo "CI integration: proving the isolated tokenless legacy-login opt-in"
export ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=true
run_timed 5m "$container_cli" compose up --detach --no-deps --force-recreate app
wait_for_healthy app 240
run_timed 3m sh tests/integration/legacy_login_http.sh

echo "CI integration: restoring the default CSRF-protected login"
export ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=false
run_timed 5m "$container_cli" compose up --detach --no-deps --force-recreate app
wait_for_healthy app 240
assert_clean_app_logs

echo "CI integration: running ETB/TBB HTTP integration"
run_timed 5m sh tests/integration/logbooks_http.sh

echo "CI integration: running category HTTP integration"
export ESTAB_CATEGORY_HTTP_TEST_ALLOW_MUTATION=true
run_timed 5m sh tests/integration/categories_http.sh
unset ESTAB_CATEGORY_HTTP_TEST_ALLOW_MUTATION

echo "CI integration: proving the default incoming and direct outgoing message workflows"
export ESTAB_MESSAGE_WORKFLOW_HTTP_TEST_ALLOW_MUTATION=true
export ESTAB_TEST_WORKFLOW_VARIANT=default
export ESTAB_TEST_EXPECT_OUTGOING_REVIEW=disabled
run_timed 6m sh tests/integration/message_workflow_http.sh
assert_clean_app_logs

echo "CI integration: recreating the app with outgoing Si review enabled"
export ESTAB_REVIEW_OUTGOING_MESSAGES=true
run_timed 5m "$container_cli" compose up --detach --no-deps --force-recreate app
wait_for_healthy app 240

echo "CI integration: proving the complete outgoing 2 -> 4 -> 8 message workflow"
export ESTAB_TEST_WORKFLOW_VARIANT=review
export ESTAB_TEST_EXPECT_OUTGOING_REVIEW=enabled
run_timed 6m sh tests/integration/message_workflow_http.sh
assert_clean_app_logs

echo "CI integration: restoring the default direct outgoing workflow"
export ESTAB_REVIEW_OUTGOING_MESSAGES=false
run_timed 5m "$container_cli" compose up --detach --no-deps --force-recreate app
wait_for_healthy app 240
unset \
    ESTAB_MESSAGE_WORKFLOW_HTTP_TEST_ALLOW_MUTATION \
    ESTAB_TEST_WORKFLOW_VARIANT \
    ESTAB_TEST_EXPECT_OUTGOING_REVIEW

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

echo "CI integration: checking exact restored export run"
run_timed 4m "$container_cli" compose run --rm --no-deps -T \
    --volume "$http_state_file:/run/estab/http-state:ro" \
    --entrypoint sh \
    app -ceu '
        marker=$(sed -n "1p" /run/estab/http-state)
        export_id=$(sed -n "5p" /run/estab/http-state)
        expected_sha256=$(sed -n "6p" /run/estab/http-state)
        case "$export_id" in
            estab-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]-[a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9]) ;;
            *) echo "CI integration: unsafe restored export ID" >&2; exit 1 ;;
        esac
        printf "%s" "$expected_sha256" | grep -Eq "^[a-f0-9]{64}$" || {
            echo "CI integration: unsafe restored export checksum" >&2
            exit 1
        }
        test -n "$marker"
        test -f "/var/lib/estab/export/$export_id.zip"
        test -d "/var/lib/estab/export/$export_id"
        test -f "/var/lib/estab/export/$export_id/manifest.json"
        test -f "/var/lib/estab/export/$export_id/nv_nachrichten.csv"
        actual_sha256=$(sha256sum "/var/lib/estab/export/$export_id.zip" | awk "{ print \$1 }")
        test "$actual_sha256" = "$expected_sha256"
        grep -F -q -- ";$marker;" "/var/lib/estab/export/$export_id/nv_nachrichten.csv"
    '

echo "CI integration: re-authenticating against restored state"
export ESTAB_TEST_RESTORE_VERIFY_ONLY=true
export ESTAB_TEST_RESTORE_ATTACHMENT="$restore_attachment"
export ESTAB_TEST_RESTORE_VORDRUCK="$restore_vordruck"
export ESTAB_TEST_RESTORE_VORDRUCK_SHA256="$restore_vordruck_sha256"
run_timed 5m sh tests/integration/http_smoke.sh
unset \
    ESTAB_TEST_RESTORE_VERIFY_ONLY \
    ESTAB_TEST_RESTORE_ATTACHMENT \
    ESTAB_TEST_RESTORE_VORDRUCK \
    ESTAB_TEST_RESTORE_VORDRUCK_SHA256

wait_for_healthy db 30
wait_for_healthy app 60
verify_schema
assert_clean_app_logs
if [[ -n $prebuilt_app_image ]]; then
    verify_prebuilt_runtime_images final
fi

echo "CI integration: OK"
