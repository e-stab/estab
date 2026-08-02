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
export ESTAB_ORGANISATION=GLOBAL-CONFIG-MUST-NOT-APPEAR
export ESTAB_ALLOW_SELF_REGISTRATION=false
export ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=false
export ESTAB_UPLOAD_MAX_BYTES=20971520
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
        for service in db migrate admin-auth-init app; do
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

verify_admin_secret_isolation() {
    local app_container init_container init_status app_environment
    local admin_secret_mount auth_mount_mode init_network_mode

    app_container=$(compose ps -q app)
    init_container=$(compose ps --all -q admin-auth-init)
    if [[ -z $app_container || -z $init_container ]]; then
        echo "CI integration: admin authentication containers are missing" >&2
        return 1
    fi

    init_status=$("$container_cli" inspect --format \
        '{{.State.Status}} {{.State.ExitCode}}' "$init_container")
    if [[ $init_status != "exited 0" ]]; then
        echo "CI integration: admin authentication initializer ended as $init_status" >&2
        return 1
    fi
    init_network_mode=$("$container_cli" inspect --format \
        '{{.HostConfig.NetworkMode}}' "$init_container")
    if [[ $init_network_mode != none ]]; then
        echo "CI integration: admin authentication initializer has network access" >&2
        return 1
    fi

    app_environment=$("$container_cli" inspect --format \
        '{{range .Config.Env}}{{println .}}{{end}}' "$app_container")
    if printf '%s\n' "$app_environment" |
        grep -Eq '^ESTAB_ADMIN_PASSWORD(_FILE)?='; then
        echo "CI integration: web container environment exposes the admin secret" >&2
        return 1
    fi
    admin_secret_mount=$("$container_cli" inspect --format \
        '{{range .Mounts}}{{if eq .Destination "/run/secrets/estab_admin_password"}}present{{end}}{{end}}' \
        "$app_container")
    if [[ -n $admin_secret_mount ]]; then
        echo "CI integration: web container mounts the cleartext admin secret" >&2
        return 1
    fi
    auth_mount_mode=$("$container_cli" inspect --format \
        '{{range .Mounts}}{{if eq .Destination "/run/estab-auth"}}{{if .RW}}rw{{else}}ro{{end}}{{end}}{{end}}' \
        "$app_container")
    if [[ $auth_mount_mode != ro ]]; then
        echo "CI integration: derived admin authentication mount is not read-only" >&2
        return 1
    fi

    compose exec -T app sh -ceu '
        test ! -e /run/secrets/estab_admin_password
        test -r /run/estab-auth/admin.htpasswd
        test "$(find /run/estab-auth/admin.htpasswd -prune \
            -type f -user root -group www-data -perm 0640 -print)" \
            = /run/estab-auth/admin.htpasswd
        admin_hash=$(cut -d: -f2 /run/estab-auth/admin.htpasswd)
        case "$admin_hash" in
          "\$2a\$12\$"*|"\$2b\$12\$"*|"\$2y\$12\$"*) ;;
          *)
            echo "Derived authentication hash does not use bcrypt cost 12" >&2
            exit 1
            ;;
        esac
        if touch /run/estab-auth/.write-probe 2>/dev/null; then
            rm -f /run/estab-auth/.write-probe
            echo "Derived authentication directory is writable" >&2
            exit 1
        fi
    '

    run_timed 2m "$container_cli" compose run --rm --no-deps -T \
        app php -r '
            if (getenv("ESTAB_ADMIN_PASSWORD") !== false
                || getenv("ESTAB_ADMIN_PASSWORD_FILE") !== false
                || !is_readable("/run/estab-auth/admin.htpasswd")) {
                exit(1);
            }
            echo "no-deps admin isolation: OK\n";
        '
    echo "CI integration: cleartext admin secret is isolated from the web container"
}

verify_prebuilt_runtime_images() {
    local phase=${1:-runtime}
    local service expected_image_id container_id actual_image_id
    if [[ ! $phase =~ ^[a-z][a-z0-9_-]*$ ]]; then
        echo "CI integration: invalid exact-image verification phase" >&2
        return 1
    fi
    for service in migrate admin-auth-init app; do
        case "$service" in
            migrate) expected_image_id=$expected_migrate_id ;;
            admin-auth-init | app) expected_image_id=$expected_app_id ;;
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
    local database_name=${2:-estab_incident_ci_test}
    case "$action" in
        create | drop) ;;
        *)
            echo "CI integration: invalid incident test database action" >&2
            return 1
            ;;
    esac
    case "$database_name" in
        estab_incident_ci_test \
        | estab_dv_evidence_ci_test \
        | estab_dv_operations_ci_test \
        | estab_shift_access_ci_test \
        | estab_attachment_reservation_ci_test \
        | estab_message_concurrency_ci_test \
        | estab_message_suggestions_ci_test \
        | estab_message_list_scale_ci_test \
        | estab_self_registration_handler_ci_test) ;;
        *)
            echo "CI integration: invalid isolated incident database name" >&2
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

        database_name=$2
        case "$1" in
            create)
                statement="
                    DROP DATABASE IF EXISTS \`$database_name\`;
                    CREATE DATABASE \`$database_name\`
                      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                ;;
            drop)
                statement="
                    DROP DATABASE IF EXISTS \`$database_name\`"
                ;;
        esac
        mariadb \
            --defaults-extra-file="$client_defaults" \
            --execute="$statement"
    ' incident-test-database "$action" "$database_name"
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

run_dv_evidence_integration() {
    local status=0
    local database_name=estab_dv_evidence_ci_test

    echo "CI integration: creating an isolated DV evidence database"
    incident_test_database create "$database_name"
    if ! run_timed 4m "$container_cli" compose run --rm --no-deps -T \
        --env "ESTAB_DB_NAME=$database_name" \
        migrate; then
        status=1
    elif ! run_timed 4m "$container_cli" compose run --rm --no-deps -T \
        --env ESTAB_DV_EVIDENCE_INTEGRATION=1 \
        --env "ESTAB_DB_NAME=$database_name" \
        --env ESTAB_DB_USER=root \
        --env ESTAB_DB_PASSWORD_FILE=/run/secrets/incident_root_password \
        --volume "$repo_root:/workspace:ro" \
        --volume \
            "$secret_dir/db_root_password.txt:/run/secrets/incident_root_password:ro" \
        --workdir /workspace \
        app php -d auto_prepend_file= tests/integration/dv_evidence.php; then
        status=1
    fi
    if ! incident_test_database drop "$database_name"; then
        status=1
    fi
    return "$status"
}

run_dv_operations_integration() {
    local status=0
    local database_name=estab_dv_operations_ci_test

    echo "CI integration: creating an isolated DV operations database"
    incident_test_database create "$database_name"
    if ! run_timed 4m "$container_cli" compose run --rm --no-deps -T \
        --env "ESTAB_DB_NAME=$database_name" \
        migrate; then
        status=1
    elif ! run_timed 4m "$container_cli" compose run --rm --no-deps -T \
        --env ESTAB_DV_OPERATIONS_INTEGRATION=1 \
        --env "ESTAB_DB_NAME=$database_name" \
        --env ESTAB_DB_USER=root \
        --env ESTAB_DB_PASSWORD_FILE=/run/secrets/incident_root_password \
        --volume "$repo_root:/workspace:ro" \
        --volume \
            "$secret_dir/db_root_password.txt:/run/secrets/incident_root_password:ro" \
        --workdir /workspace \
        app php -d auto_prepend_file= tests/integration/dv_operations.php; then
        status=1
    fi
    if ! incident_test_database drop "$database_name"; then
        status=1
    fi
    return "$status"
}

run_isolated_operational_integration() {
    local label=$1
    local database_name=$2
    local test_file=$3
    local status=0

    echo "CI integration: creating an isolated ${label} database"
    incident_test_database create "$database_name"
    if ! run_timed 4m "$container_cli" compose run --rm --no-deps -T \
        --env "ESTAB_DB_NAME=$database_name" \
        migrate; then
        status=1
    elif ! run_timed 5m "$container_cli" compose run --rm --no-deps -T \
        --env "ESTAB_DB_NAME=$database_name" \
        --env ESTAB_DB_USER=root \
        --env ESTAB_DB_PASSWORD_FILE=/run/secrets/incident_root_password \
        --volume "$repo_root:/workspace:ro" \
        --volume \
            "$secret_dir/db_root_password.txt:/run/secrets/incident_root_password:ro" \
        --workdir /workspace \
        app php -d auto_prepend_file= "$test_file"; then
        status=1
    fi
    if ! incident_test_database drop "$database_name"; then
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
verify_admin_secret_isolation
if [[ -n $prebuilt_app_image ]]; then
    verify_prebuilt_runtime_images initial
fi

verify_schema
run_incident_domain_integration
run_dv_evidence_integration
run_dv_operations_integration
run_isolated_operational_integration \
    "optional access-shift policy" \
    estab_shift_access_ci_test \
    tests/integration/shift_access.php
run_php_integration "nullable-date migration" tests/integration/date_compatibility.php
run_php_integration "user administration" tests/integration/user_admin.php
run_php_integration "configurable password policy" tests/integration/password_policy.php
run_php_integration \
    "persistent self-registration policy" \
    tests/integration/self_registration.php
run_isolated_operational_integration \
    "persistent self-registration handler boundary" \
    estab_self_registration_handler_ci_test \
    tests/integration/self_registration_handler.php
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

echo "CI integration: proving visible PDF, PNG, and original attachment pages"
run_timed 3m "$container_cli" compose run --rm --no-deps -T \
    --env ESTAB_PDF_ATTACHMENT_RENDER_INTEGRATION=1 \
    --volume "$repo_root:/workspace:ro" \
    --workdir /workspace \
    app php -d auto_prepend_file= \
        tests/integration/pdf_attachment_render.php

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

run_isolated_operational_integration \
    "parallel attachment reservation" \
    estab_attachment_reservation_ci_test \
    tests/integration/attachment_reservation.php

run_isolated_operational_integration \
    "message concurrency and state ownership" \
    estab_message_concurrency_ci_test \
    tests/integration/message_concurrency.php

run_isolated_operational_integration \
    "active-incident message suggestions" \
    estab_message_suggestions_ci_test \
    tests/integration/message_suggestions.php

run_isolated_operational_integration \
    "message-list search at 10,000-row scale" \
    estab_message_list_scale_ci_test \
    tests/integration/message_list_scale.php

roundtrip_token=$(printf '%s' "$COMPOSE_PROJECT_NAME" |
    openssl dgst -sha256 -r | awk '{ print substr($1, 1, 16) }')
workflow_marker="ESTAB_BACKUP_ROUNDTRIP_${roundtrip_token}"
http_state_file="$roundtrip_dir/http-state"

export ESTAB_TEST_BASE_URL="http://127.0.0.1:${ESTAB_HTTP_PORT}"
export ESTAB_TEST_ADMIN_USER="$ESTAB_ADMIN_USER"
export ESTAB_TEST_WORKFLOW_MARKER="$workflow_marker"

echo "CI integration: checking the direct HTTP surface"
run_timed 3m sh tests/integration/http_surface_http.sh

echo "CI integration: proving administrative self-registration timing and gates"
export ESTAB_SELF_REGISTRATION_HTTP_TEST_ALLOW_MUTATION=true
run_timed 3m sh tests/integration/self_registration_http.sh
unset ESTAB_SELF_REGISTRATION_HTTP_TEST_ALLOW_MUTATION
assert_clean_app_logs

run_browser_acceptance() {
    if [[ $browser_test_enabled != true ]]; then
        return 0
    fi
    echo "CI integration: running real-browser menu and session acceptance"
    (
        # Reuse the centrally provisioned fixed S1 account. The active
        # incident, account state and function now form the write boundary;
        # no legacy duty-shift selection is required.
        export ESTAB_TEST_LOGIN_NAME=${ESTAB_TEST_LOGIN_NAME:-Container Integration}
        export ESTAB_TEST_LOGIN_CODE=${ESTAB_TEST_LOGIN_CODE:-e2e001}
        export ESTAB_TEST_LOGIN_FUNCTION=${ESTAB_TEST_LOGIN_FUNCTION:-S1}
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
        echo "CI integration: running public BOS document acceptance"
        if [[ -n ${ESTAB_CI_LOG_DIR:-} ]]; then
            export ESTAB_BROWSER_ARTIFACT_DIR="$ESTAB_CI_LOG_DIR/browser-bos"
        fi
        run_timed 3m python3 -B tests/browser/headless_ui.py --bos-only
        echo "CI integration: running public handbook acceptance"
        if [[ -n ${ESTAB_CI_LOG_DIR:-} ]]; then
            export \
                ESTAB_BROWSER_ARTIFACT_DIR="$ESTAB_CI_LOG_DIR/browser-handbook"
        fi
        run_timed 3m python3 -B tests/browser/headless_ui.py --handbook-only
        if [[ -n ${ESTAB_CI_LOG_DIR:-} ]]; then
            export ESTAB_BROWSER_ARTIFACT_DIR="$ESTAB_CI_LOG_DIR/browser"
        fi
        run_timed 4m python3 -B tests/browser/headless_ui.py

        echo "CI integration: running real-browser S6 plan versioning acceptance"
        telecom_login_name='Telecommunications Browser S6'
        telecom_login_code=e2t006
        telecom_browser_token="browser-${roundtrip_token}"
        browser_login_password=$(tr -d '\r\n' <"$ESTAB_TEST_LOGIN_PASSWORD_FILE")
        sh tests/integration/provision_user.sh \
            "$telecom_login_name" \
            "$telecom_login_code" \
            S6 \
            "$browser_login_password"
        unset browser_login_password
        run_timed 3m "$container_cli" compose run --rm --no-deps -T \
            --env "COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME" \
            --env ESTAB_TEST_TELECOM_ALLOW_MUTATION=true \
            --env ESTAB_TEST_TELECOM_MODE=initial \
            --env "ESTAB_TEST_TELECOM_S6_CODE=$telecom_login_code" \
            --env "ESTAB_TEST_TELECOM_TOKEN=$telecom_browser_token" \
            --volume "$repo_root:/workspace:ro" \
            --workdir /workspace \
            app php -d auto_prepend_file= \
            tests/integration/create_http_telecom_fixture.php >/dev/null
        export ESTAB_TEST_LOGIN_NAME=$telecom_login_name
        export ESTAB_TEST_LOGIN_CODE=$telecom_login_code
        export ESTAB_TEST_LOGIN_FUNCTION=S6
        if [[ -n ${ESTAB_CI_LOG_DIR:-} ]]; then
            export \
                ESTAB_BROWSER_ARTIFACT_DIR="$ESTAB_CI_LOG_DIR/browser-telecom-plan"
        fi
        run_timed 4m python3 -B tests/browser/headless_ui.py --telecom-plan

        echo "CI integration: running real-browser message heading acceptance"
        overview_login_name='Message Overview S2'
        overview_login_code=e2m002
        browser_login_password=$(tr -d '\r\n' <"$ESTAB_TEST_LOGIN_PASSWORD_FILE")
        sh tests/integration/provision_user.sh \
            "$overview_login_name" \
            "$overview_login_code" \
            S2 \
            "$browser_login_password"
        unset browser_login_password
        export ESTAB_TEST_LOGIN_NAME=$overview_login_name
        export ESTAB_TEST_LOGIN_CODE=$overview_login_code
        export ESTAB_TEST_LOGIN_FUNCTION=S2
        export ESTAB_TEST_MESSAGE_OVERVIEW_SUBJECT='Sicherer UTF-8-Betreff äöü'
        if [[ -n ${ESTAB_CI_LOG_DIR:-} ]]; then
            export \
                ESTAB_BROWSER_ARTIFACT_DIR="$ESTAB_CI_LOG_DIR/browser-message-overview"
        fi
        run_timed 3m python3 -B tests/browser/headless_ui.py \
            --message-overview
        unset ESTAB_TEST_MESSAGE_OVERVIEW_SUBJECT

        echo "CI integration: running real-browser message suggestion acceptance"
        suggestion_login_name=${ESTAB_TEST_TBB_NAME:-Logbook Integration A-W}
        suggestion_login_code=${ESTAB_TEST_TBB_CODE:-e2l001}
        suggestion_marker="BROWSER-GEGENSTELLE-${roundtrip_token}"
        browser_login_password=$(tr -d '\r\n' <"$ESTAB_TEST_LOGIN_PASSWORD_FILE")
        sh tests/integration/provision_user.sh \
            "$suggestion_login_name" \
            "$suggestion_login_code" \
            A/W \
            "$browser_login_password"
        unset browser_login_password

        cleanup_message_suggestion_browser_fixture() {
            run_timed 2m "$container_cli" compose run --rm --no-deps -T \
                --env "COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME" \
                --env ESTAB_MESSAGE_SUGGESTION_BROWSER_FIXTURE=1 \
                --env ESTAB_MESSAGE_SUGGESTION_FIXTURE_ACTION=delete \
                --env \
                    "ESTAB_MESSAGE_SUGGESTION_FIXTURE_MARKER=$suggestion_marker" \
                --volume "$repo_root:/workspace:ro" \
                --workdir /workspace \
                app php -d auto_prepend_file= \
                tests/integration/message_suggestion_browser_fixture.php \
                >/dev/null 2>&1 || true
        }
        trap cleanup_message_suggestion_browser_fixture EXIT
        run_timed 2m "$container_cli" compose run --rm --no-deps -T \
            --env "COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME" \
            --env ESTAB_MESSAGE_SUGGESTION_BROWSER_FIXTURE=1 \
            --env ESTAB_MESSAGE_SUGGESTION_FIXTURE_ACTION=create \
            --env "ESTAB_MESSAGE_SUGGESTION_FIXTURE_MARKER=$suggestion_marker" \
            --volume "$repo_root:/workspace:ro" \
            --workdir /workspace \
            app php -d auto_prepend_file= \
            tests/integration/message_suggestion_browser_fixture.php

        export ESTAB_TEST_LOGIN_NAME=$suggestion_login_name
        export ESTAB_TEST_LOGIN_CODE=$suggestion_login_code
        export ESTAB_TEST_LOGIN_FUNCTION=A/W
        export ESTAB_TEST_MESSAGE_SUGGESTION_MARKER=$suggestion_marker
        if [[ -n ${ESTAB_CI_LOG_DIR:-} ]]; then
            export \
                ESTAB_BROWSER_ARTIFACT_DIR="$ESTAB_CI_LOG_DIR/browser-suggestions"
        fi
        run_timed 3m python3 -B tests/browser/headless_ui.py \
            --message-suggestions
        cleanup_message_suggestion_browser_fixture
        trap - EXIT
    )
}

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
# The initial service uses the fixed A/W account first created by
# http_smoke.sh. Re-provisioning changes only credentials and presence; the
# account function remains the authoritative write boundary.
export ESTAB_TEST_TBB_CODE=${ESTAB_TEST_TBB_CODE:-e2l001}
export ESTAB_TEST_TBB_NAME=${ESTAB_TEST_TBB_NAME:-Logbook Integration A-W}
run_timed 5m sh tests/integration/logbooks_http.sh

echo "CI integration: running category HTTP integration"
export ESTAB_CATEGORY_HTTP_TEST_ALLOW_MUTATION=true
run_timed 5m sh tests/integration/categories_http.sh
unset ESTAB_CATEGORY_HTTP_TEST_ALLOW_MUTATION

echo "CI integration: proving the mandatory DV message workflows"
export ESTAB_MESSAGE_WORKFLOW_HTTP_TEST_ALLOW_MUTATION=true
active_duty_state_file="$roundtrip_dir/active-duty-state"
export ESTAB_TEST_ACTIVE_DUTY_STATE_FILE="$active_duty_state_file"
run_timed 6m sh tests/integration/message_workflow_http.sh
assert_clean_app_logs
unset \
    ESTAB_MESSAGE_WORKFLOW_HTTP_TEST_ALLOW_MUTATION \
    ESTAB_TEST_ACTIVE_DUTY_STATE_FILE
if [[ ! -f $active_duty_state_file ]]; then
    echo "CI integration: active-duty state file is missing" >&2
    exit 1
fi
active_duty_state_lines=$(wc -l <"$active_duty_state_file" | tr -d '[:space:]')
if [[ $active_duty_state_lines != 3 ]]; then
    echo "CI integration: active-duty state must contain exactly three lines" >&2
    exit 1
fi
restore_login_name=$(sed -n '1p' "$active_duty_state_file")
restore_login_code=$(sed -n '2p' "$active_duty_state_file")
restore_login_function=$(sed -n '3p' "$active_duty_state_file")
if [[ ! $restore_login_name =~ ^Workflow\ S1\ [a-f0-9]{5}$ \
    || ! $restore_login_code =~ ^a[a-f0-9]{5}$ \
    || $restore_login_function != S1 ]]; then
    echo "CI integration: active-duty restore identity is invalid" >&2
    exit 1
fi

# Run the mutating browser acceptance only after the HTTP workflow has proved
# the genuinely empty-plan create path. The S6 browser fixture deliberately
# versions an existing active plan when one is present, whereas the HTTP
# controller test must begin without a plan to exercise create_plan itself.
# Keeping this order makes both tests independent of optional browser support.
run_browser_acceptance

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
export ESTAB_TEST_LOGIN_NAME="$restore_login_name"
export ESTAB_TEST_LOGIN_CODE="$restore_login_code"
export ESTAB_TEST_LOGIN_FUNCTION="$restore_login_function"
run_timed 5m sh tests/integration/http_smoke.sh
unset \
    ESTAB_TEST_RESTORE_VERIFY_ONLY \
    ESTAB_TEST_RESTORE_ATTACHMENT \
    ESTAB_TEST_RESTORE_VORDRUCK \
    ESTAB_TEST_RESTORE_VORDRUCK_SHA256 \
    ESTAB_TEST_LOGIN_NAME \
    ESTAB_TEST_LOGIN_CODE \
    ESTAB_TEST_LOGIN_FUNCTION

wait_for_healthy db 30
wait_for_healthy app 60
verify_schema
assert_clean_app_logs
if [[ -n $prebuilt_app_image ]]; then
    verify_prebuilt_runtime_images final
fi

echo "CI integration: OK"
