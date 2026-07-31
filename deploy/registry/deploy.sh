#!/bin/sh

set -eu

die()
{
    printf 'eStab deployment: %s\n' "$*" >&2
    exit 1
}

usage()
{
    printf 'Usage: %s check|pull|up\n' "$0" >&2
    exit 64
}

[ "$#" -eq 1 ] || usage
action=$1
case "$action" in
    check|pull|up) ;;
    *) usage ;;
esac

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
cd "$script_dir"

if [ "${ESTAB_APP_IMAGE+x}" = x ] ||
    [ "${ESTAB_MIGRATE_IMAGE+x}" = x ] ||
    [ "${ESTAB_ADMIN_PASSWORD_SECRET_FILE+x}" = x ] ||
    [ "${ESTAB_ADMIN_USER+x}" = x ] ||
    [ "${ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF+x}" = x ] ||
    [ "${ESTAB_ALLOW_SELF_REGISTRATION+x}" = x ] ||
    [ "${ESTAB_APP_DATA_SOURCE+x}" = x ] ||
    [ "${ESTAB_AUTHORITY_CODE+x}" = x ] ||
    [ "${ESTAB_BASE_PATH+x}" = x ] ||
    [ "${ESTAB_DB_DATA_SOURCE+x}" = x ] ||
    [ "${ESTAB_DB_NAME+x}" = x ] ||
    [ "${ESTAB_DB_PASSWORD_SECRET_FILE+x}" = x ] ||
    [ "${ESTAB_DB_ROOT_PASSWORD_SECRET_FILE+x}" = x ] ||
    [ "${ESTAB_DB_USER+x}" = x ] ||
    [ "${ESTAB_EXPORT_DATA_SOURCE+x}" = x ] ||
    [ "${ESTAB_HTTP_BIND+x}" = x ] ||
    [ "${ESTAB_HTTP_PORT+x}" = x ] ||
    [ "${ESTAB_PDF_ATTACHMENT_MAX_BYTES+x}" = x ] ||
    [ "${ESTAB_PUBLIC_URL+x}" = x ] ||
    [ "${ESTAB_TRUSTED_PROXIES+x}" = x ] ||
    [ "${ESTAB_TRUST_PROXY_HEADERS+x}" = x ] ||
    [ "${ESTAB_UPLOAD_MAX_BYTES+x}" = x ] ||
    [ "${TZ+x}" = x ] ||
    [ "${COMPOSE_FILE+x}" = x ] ||
    [ "${COMPOSE_ENV_FILES+x}" = x ] ||
    [ "${COMPOSE_DISABLE_ENV_FILE+x}" = x ] ||
    [ "${COMPOSE_PROJECT_NAME+x}" = x ]; then
    die "Compose runtime overrides must not be set in the process environment; place supported settings in the verified .env"
fi

container_cli=${ESTAB_CONTAINER_CLI:-}
if [ -z "$container_cli" ]; then
    if command -v docker >/dev/null 2>&1 &&
        docker compose version >/dev/null 2>&1; then
        container_cli=docker
    elif command -v podman >/dev/null 2>&1 &&
        podman compose version >/dev/null 2>&1; then
        container_cli=podman
    else
        die "neither Docker Compose nor Podman Compose is available"
    fi
fi
case "$container_cli" in
    docker|podman) ;;
    *) die "ESTAB_CONTAINER_CLI must be docker or podman" ;;
esac
command -v "$container_cli" >/dev/null 2>&1 ||
    die "container executable is unavailable: $container_cli"
"$container_cli" compose version >/dev/null 2>&1 ||
    die "Compose is unavailable through $container_cli"

for forbidden_override in \
    compose.override.yaml \
    compose.override.yml \
    docker-compose.yaml \
    docker-compose.yml
do
    [ ! -e "$script_dir/$forbidden_override" ] &&
        [ ! -L "$script_dir/$forbidden_override" ] ||
        die "unverified automatic Compose override is forbidden: $forbidden_override"
done

compose()
{
    "$container_cli" compose \
        --env-file "$script_dir/.env" \
        -f "$script_dir/compose.yaml" \
        "$@"
}

canonical_image_id()
{
    canonical_image_value=$1
    case "$canonical_image_value" in
        sha256:*)
            canonical_image_hex=${canonical_image_value#sha256:}
            ;;
        *)
            canonical_image_hex=$canonical_image_value
            ;;
    esac
    case "$canonical_image_hex" in
        ''|*[!0123456789abcdef]*) return 1 ;;
    esac
    [ "${#canonical_image_hex}" -eq 64 ] || return 1
    printf 'sha256:%s\n' "$canonical_image_hex"
}

verified_environment_value()
{
    verified_environment_name=$1
    LC_ALL=C awk -v name="$verified_environment_name" '
      index($0, name "=") == 1 {
        matches++
        value = substr($0, length(name) + 2)
      }
      END {
        if (matches != 1 || value == "" || index(value, "\r") != 0) {
          exit 1
        }
        print value
      }
    ' "$script_dir/.env"
}

canonical_storage_source()
{
    storage_role=$1
    storage_value=$2
    storage_named_default=$3

    if [ "$storage_value" = "$storage_named_default" ]; then
        printf '%s\tvolume\t%s\n' "$storage_role" "$storage_value"
        return
    fi

    case "$storage_value" in
        /*|./*|../*) ;;
        *)
            printf 'eStab deployment: %s storage must use its dedicated named volume %s or an explicit absolute/relative host directory: %s\n' \
                "$storage_role" "$storage_named_default" "$storage_value" >&2
            return 1
            ;;
    esac
    case "$storage_value" in
        *':'*|*'	'*|*'
'*)
            printf 'eStab deployment: %s host storage path contains an unsupported character.\n' \
                "$storage_role" >&2
            return 1
            ;;
    esac
    if [ ! -d "$storage_value" ] || [ -L "$storage_value" ]; then
        printf 'eStab deployment: %s host storage path must already be a real, non-symlink directory: %s\n' \
            "$storage_role" "$storage_value" >&2
        return 1
    fi
    storage_canonical=$(CDPATH= cd -- "$storage_value" && pwd -P) ||
        return 1
    [ "$storage_canonical" != / ] || {
        printf 'eStab deployment: filesystem root is forbidden as productive %s storage.\n' \
            "$storage_role" >&2
        return 1
    }
    case "$storage_canonical" in
        /*/*) ;;
        *)
            printf 'eStab deployment: broad top-level host directory is forbidden as productive %s storage: %s\n' \
                "$storage_role" "$storage_canonical" >&2
            return 1
            ;;
    esac
    case "$script_dir" in
        "$storage_canonical"|"$storage_canonical"/*)
            printf 'eStab deployment: productive %s storage must not be the release directory or one of its ancestors: %s\n' \
                "$storage_role" "$storage_canonical" >&2
            return 1
            ;;
    esac
    printf '%s\tbind\t%s\n' "$storage_role" "$storage_canonical"
}

verify_storage_configuration()
{
    storage_db=$(verified_environment_value ESTAB_DB_DATA_SOURCE) ||
        die "cannot read verified database storage source"
    storage_app=$(verified_environment_value ESTAB_APP_DATA_SOURCE) ||
        die "cannot read verified application storage source"
    storage_export=$(verified_environment_value ESTAB_EXPORT_DATA_SOURCE) ||
        die "cannot read verified export storage source"

    storage_records=$(
        canonical_storage_source database "$storage_db" estab_db || exit 1
        canonical_storage_source application "$storage_app" estab_data ||
            exit 1
        canonical_storage_source export "$storage_export" estab_export ||
            exit 1
    ) || exit 1

    printf '%s\n' "$storage_records" |
        LC_ALL=C awk -F '	' '
          function overlaps(first, second) {
            return first == second ||
              first == "/" || second == "/" ||
              index(first, second "/") == 1 ||
              index(second, first "/") == 1
          }
          {
            if (NF != 3 ||
                ($2 != "volume" && $2 != "bind") ||
                $3 == "") {
              exit 1
            }
            types[++count] = $2
            sources[count] = $3
          }
          END {
            if (count != 3) {
              exit 1
            }
            for (left = 1; left <= count; left++) {
              for (right = left + 1; right <= count; right++) {
                if (types[left] == types[right] &&
                    (sources[left] == sources[right] ||
                     (types[left] == "bind" &&
                      overlaps(sources[left], sources[right])))) {
                  exit 1
                }
              }
            }
          }
        ' ||
        die "productive storage sources are equal, nested, overlapping, or unsafe"
    printf '%s\n' "$storage_records"
}

inspect_value()
{
    inspect_container=$1
    inspect_template=$2
    "$container_cli" inspect --format "$inspect_template" "$inspect_container"
}

diagnose_maintenance_lock()
{
    diagnose_lock_id=$(inspect_value "$maintenance_lock_name" '{{.Id}}' \
        2>/dev/null || :)
    if [ -z "$diagnose_lock_id" ]; then
        printf 'eStab deployment: no inspectable lock container named %s was found; inspect the preceding container-engine error.\n' \
            "$maintenance_lock_name" >&2
        return
    fi
    diagnose_lock_project=$(inspect_value "$maintenance_lock_name" \
        '{{ index .Config.Labels "org.e-stab.compose-project" }}' \
        2>/dev/null || :)
    diagnose_lock_operation=$(inspect_value "$maintenance_lock_name" \
        '{{ index .Config.Labels "org.e-stab.maintenance-operation" }}' \
        2>/dev/null || :)
    diagnose_lock_owner=$(inspect_value "$maintenance_lock_name" \
        '{{ index .Config.Labels "org.e-stab.maintenance-owner" }}' \
        2>/dev/null || :)
    diagnose_lock_started=$(inspect_value "$maintenance_lock_name" \
        '{{ index .Config.Labels "org.e-stab.maintenance-started-utc" }}' \
        2>/dev/null || :)
    diagnose_lock_status=$(inspect_value "$maintenance_lock_name" \
        '{{.State.Status}}' 2>/dev/null || :)
    printf 'eStab deployment: engine-global maintenance lock: name=%s id=%s project=%s operation=%s owner=%s started_utc=%s status=%s\n' \
        "$maintenance_lock_name" "$diagnose_lock_id" \
        "$diagnose_lock_project" "$diagnose_lock_operation" \
        "$diagnose_lock_owner" "$diagnose_lock_started" \
        "$diagnose_lock_status" >&2
}

maintenance_lock_is_owned()
{
    owned_lock_id=$(inspect_value "$maintenance_lock_id" '{{.Id}}' \
        2>/dev/null || :) &&
    [ "$owned_lock_id" = "$maintenance_lock_id" ] || return 1
    owned_lock_name=$(inspect_value "$maintenance_lock_id" '{{.Name}}' \
        2>/dev/null || :) || return 1
    case "$owned_lock_name" in
        "$maintenance_lock_name"|"/$maintenance_lock_name") ;;
        *) return 1 ;;
    esac
    owned_lock_marker=$(inspect_value "$maintenance_lock_id" \
        '{{ index .Config.Labels "org.e-stab.maintenance-lock" }}' \
        2>/dev/null || :) || return 1
    owned_lock_project=$(inspect_value "$maintenance_lock_id" \
        '{{ index .Config.Labels "org.e-stab.compose-project" }}' \
        2>/dev/null || :) || return 1
    owned_lock_operation=$(inspect_value "$maintenance_lock_id" \
        '{{ index .Config.Labels "org.e-stab.maintenance-operation" }}' \
        2>/dev/null || :) || return 1
    owned_lock_token=$(inspect_value "$maintenance_lock_id" \
        '{{ index .Config.Labels "org.e-stab.maintenance-owner" }}' \
        2>/dev/null || :) || return 1
    owned_lock_status=$(inspect_value "$maintenance_lock_id" \
        '{{.State.Status}}' 2>/dev/null || :) || return 1
    owned_lock_running=$(inspect_value "$maintenance_lock_id" \
        '{{.State.Running}}' 2>/dev/null || :) || return 1
    owned_lock_image=$(inspect_value "$maintenance_lock_id" '{{.Image}}' \
        2>/dev/null || :) || return 1
    owned_lock_image=$(canonical_image_id "$owned_lock_image") || return 1
    [ "$owned_lock_marker" = true ] &&
    [ "$owned_lock_project" = "$maintenance_lock_project" ] &&
    [ "$owned_lock_operation" = deploy ] &&
    [ "$owned_lock_token" = "$maintenance_lock_token" ] &&
    [ "$owned_lock_status" = running ] &&
    [ "$owned_lock_running" = true ] &&
    [ "$owned_lock_image" = "$maintenance_lock_image" ]
}

acquire_maintenance_lock()
{
    maintenance_lock_project=$1
    maintenance_lock_image=$2
    case "$maintenance_lock_project" in
        ''|[!a-z0-9]*|*[!a-z0-9_-]*)
            die "verified Compose project is not in canonical lowercase form"
            ;;
    esac
    [ "${#maintenance_lock_project}" -le 128 ] ||
        die "verified Compose project is too long for the maintenance lock"
    maintenance_lock_image=$(canonical_image_id "$maintenance_lock_image") ||
        die "verified app image ID is invalid for the maintenance lock"
    maintenance_lock_name=estab-maintenance-lock-$maintenance_lock_project
    maintenance_lock_started=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    maintenance_lock_token="deploy-$$-$(date -u '+%Y%m%dT%H%M%SZ')"

    if ! "$container_cli" run --detach \
        --name "$maintenance_lock_name" \
        --label org.e-stab.maintenance-lock=true \
        --label "org.e-stab.compose-project=$maintenance_lock_project" \
        --label org.e-stab.maintenance-operation=deploy \
        --label "org.e-stab.maintenance-owner=$maintenance_lock_token" \
        --label "org.e-stab.maintenance-started-utc=$maintenance_lock_started" \
        --network none \
        --restart no \
        --entrypoint /bin/sh \
        "$maintenance_lock_image" -ceu \
        'trap "exit 0" HUP INT TERM
         while :; do
           sleep 3600 &
           wait "$!"
         done' >/dev/null; then
        diagnose_maintenance_lock
        die "cannot acquire engine-global maintenance lock for project $maintenance_lock_project; a stale lock remains fail-closed until an operator proves no maintenance is running and removes that exact lock container"
    fi
    maintenance_lock_id=$(inspect_value "$maintenance_lock_name" '{{.Id}}' \
        2>/dev/null || :)
    case "$maintenance_lock_id" in
        ''|*[!0123456789abcdef]*)
            diagnose_maintenance_lock
            die "created maintenance lock has no valid exact container ID; it was left for manual inspection"
            ;;
    esac
    [ "${#maintenance_lock_id}" -eq 64 ] || {
        diagnose_maintenance_lock
        die "created maintenance lock has no valid exact container ID; it was left for manual inspection"
    }
    maintenance_lock_is_owned || {
        diagnose_maintenance_lock
        die "created maintenance lock identity or running state could not be proven; it was left for manual inspection"
    }
    maintenance_lock_held=1
}

cleanup()
{
    cleanup_status=$1
    trap - EXIT HUP INT TERM
    if [ "$maintenance_lock_held" -eq 1 ]; then
        if maintenance_lock_is_owned; then
            if ! "$container_cli" container rm --force \
                "$maintenance_lock_id" >/dev/null; then
                printf 'eStab deployment: WARNING: owned maintenance lock container could not be removed: %s (%s)\n' \
                    "$maintenance_lock_name" "$maintenance_lock_id" >&2
                [ "$cleanup_status" -ne 0 ] || cleanup_status=1
            fi
        else
            printf 'eStab deployment: WARNING: maintenance lock ownership changed; refusing removal by name: %s (owned id %s)\n' \
                "$maintenance_lock_name" "$maintenance_lock_id" >&2
            [ "$cleanup_status" -ne 0 ] || cleanup_status=1
        fi
        maintenance_lock_held=0
    fi
    exit "$cleanup_status"
}

verify_release=$script_dir/verify-release.sh
[ -f "$verify_release" ] && [ ! -L "$verify_release" ] &&
    [ -x "$verify_release" ] ||
    die "release verifier is missing or not executable: $verify_release"

ESTAB_CONTAINER_CLI=$container_cli "$verify_release" "$script_dir"
compose config >/dev/null
verified_storage_configuration=$(verify_storage_configuration) || exit 1
[ "$action" != check ] || exit 0

compose pull
ESTAB_CONTAINER_CLI=$container_cli \
    "$verify_release" --inspect-images "$script_dir"
[ "$action" != pull ] || exit 0

maintenance_lock_held=0
maintenance_lock_id=
maintenance_lock_name=
maintenance_lock_project=
maintenance_lock_token=
maintenance_lock_image=

verified_project=$(verified_environment_value COMPOSE_PROJECT_NAME) ||
    die "cannot read verified Compose project"
verified_app_reference=$(verified_environment_value ESTAB_APP_IMAGE) ||
    die "cannot read verified app image"
verified_app_image=$("$container_cli" image inspect --format '{{.Id}}' \
    "$verified_app_reference") ||
    die "cannot inspect verified app image ID"
verified_app_image=$(canonical_image_id "$verified_app_image") ||
    die "verified app image has an invalid runtime ID"

trap 'cleanup $?' EXIT
trap 'cleanup 129' HUP
trap 'cleanup 130' INT
trap 'cleanup 143' TERM
acquire_maintenance_lock "$verified_project" "$verified_app_image"

# Re-run all release and Compose checks after the global project lock closes
# the race with backup, restore, and another release directory.
ESTAB_CONTAINER_CLI=$container_cli "$verify_release" "$script_dir"
compose config >/dev/null
locked_storage_configuration=$(verify_storage_configuration) || exit 1
ESTAB_CONTAINER_CLI=$container_cli \
    "$verify_release" --inspect-images "$script_dir"
locked_project=$(verified_environment_value COMPOSE_PROJECT_NAME) ||
    die "cannot reread verified Compose project under the maintenance lock"
locked_app_reference=$(verified_environment_value ESTAB_APP_IMAGE) ||
    die "cannot reread verified app image under the maintenance lock"
locked_app_image=$("$container_cli" image inspect --format '{{.Id}}' \
    "$locked_app_reference") ||
    die "cannot reinspect verified app image ID"
locked_app_image=$(canonical_image_id "$locked_app_image") ||
    die "verified app image has an invalid runtime ID under the maintenance lock"
[ "$locked_project" = "$verified_project" ] &&
[ "$locked_app_reference" = "$verified_app_reference" ] &&
[ "$locked_app_image" = "$verified_app_image" ] &&
[ "$locked_storage_configuration" = "$verified_storage_configuration" ] ||
    die "release identity changed while the maintenance lock was acquired"
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before Compose up"

compose up --detach
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost during Compose up"

health_timeout_seconds=${ESTAB_DEPLOY_HEALTH_TIMEOUT_SECONDS:-300}
case "$health_timeout_seconds" in
    ''|*[!0-9]*)
        die "ESTAB_DEPLOY_HEALTH_TIMEOUT_SECONDS must be an integer from 1 to 3600"
        ;;
esac
if [ "${#health_timeout_seconds}" -gt 4 ] ||
    [ "$health_timeout_seconds" -lt 1 ] ||
    [ "$health_timeout_seconds" -gt 3600 ]; then
    die "ESTAB_DEPLOY_HEALTH_TIMEOUT_SECONDS must be an integer from 1 to 3600"
fi

deadline=$(( $(date +%s) + health_timeout_seconds ))
while :; do
    maintenance_lock_is_owned ||
        die "engine-global maintenance lock was lost while waiting for deployment readiness"
    failed_service=
    ready=1
    for service in db app; do
        container_id=$(compose ps -q "$service" 2>/dev/null || :)
        if [ -z "$container_id" ]; then
            ready=0
            continue
        fi
        state=$("$container_cli" inspect --format \
            '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' \
            "$container_id" 2>/dev/null || :)
        case "$state" in
            running\ healthy) ;;
            running\ starting) ready=0 ;;
            *) failed_service="$service ($state)"; break ;;
        esac
    done

    migrate_id=$(compose ps --all -q migrate 2>/dev/null || :)
    if [ -z "$migrate_id" ]; then
        ready=0
    else
        migrate_state=$("$container_cli" inspect --format \
            '{{.State.Status}} {{.State.ExitCode}}' "$migrate_id" \
            2>/dev/null || :)
        case "$migrate_state" in
            exited\ 0) ;;
            created\ *|running\ *) ready=0 ;;
            *) failed_service="migrate ($migrate_state)" ;;
        esac
    fi

    admin_auth_id=$(compose ps --all -q admin-auth-init \
        2>/dev/null || :)
    if [ -z "$admin_auth_id" ]; then
        ready=0
    else
        admin_auth_state=$("$container_cli" inspect --format \
            '{{.State.Status}} {{.State.ExitCode}}' "$admin_auth_id" \
            2>/dev/null || :)
        case "$admin_auth_state" in
            exited\ 0) ;;
            created\ *|running\ *) ready=0 ;;
            *) failed_service="admin-auth-init ($admin_auth_state)" ;;
        esac
    fi

    if [ -n "$failed_service" ]; then
        compose ps --all >&2 || true
        compose logs --no-color --tail=200 >&2 || true
        die "service failed during deployment: $failed_service"
    fi
    if [ "$ready" -eq 1 ]; then
        printf 'eStab deployment: ready\n'
        compose ps
        exit 0
    fi
    if [ "$(date +%s)" -ge "$deadline" ]; then
        compose ps --all >&2 || true
        compose logs --no-color --tail=200 >&2 || true
        die "services did not become ready within ${health_timeout_seconds} seconds"
    fi
    sleep 3
done
