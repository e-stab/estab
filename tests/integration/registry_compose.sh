#!/bin/sh
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

container_cli=${ESTAB_CONTAINER_CLI:-docker}
project=${ESTAB_REGISTRY_PROJECT:-estab_ci_registry}
bind_project=${project}_bind
http_port=${ESTAB_REGISTRY_HTTP_PORT:-18081}
app_image=${ESTAB_REGISTRY_APP_IMAGE:-}
migrate_image=${ESTAB_REGISTRY_MIGRATE_IMAGE:-}
compose_file=$repo_root/deploy/registry/compose.yaml
restore_script=$repo_root/tests/integration/restore_roundtrip.sh
temporary_parent=${ESTAB_REGISTRY_TEMP_PARENT:-$repo_root}
bind_root=
bind_db=
bind_data=
bind_export=
backup_dir=

case "$container_cli" in
    docker | podman) ;;
    *) echo "Registry compose integration: unsupported container CLI" >&2; exit 1 ;;
esac
case "$project" in
    estab_ci | estab_ci_*) ;;
    *)
        echo "Registry compose integration: refusing destructive cleanup outside estab_ci" >&2
        exit 1
        ;;
esac
case "$bind_project" in
    estab_ci*_bind) ;;
    *)
        echo "Registry compose integration: invalid bind project" >&2
        exit 1
        ;;
esac
case "$http_port" in
    '' | *[!0-9]*)
        echo "Registry compose integration: HTTP port must be numeric" >&2
        exit 1
        ;;
esac
if [ "$http_port" -lt 1024 ] || [ "$http_port" -gt 65535 ]; then
    echo "Registry compose integration: HTTP port is outside 1024-65535" >&2
    exit 1
fi
if [ -z "$app_image" ] || [ -z "$migrate_image" ]; then
    echo "Registry compose integration: both local image references are required" >&2
    exit 1
fi
for secret_file in \
    "${ESTAB_DB_PASSWORD_SECRET_FILE:-}" \
    "${ESTAB_DB_ROOT_PASSWORD_SECRET_FILE:-}" \
    "${ESTAB_ADMIN_PASSWORD_SECRET_FILE:-}"
do
    if [ -z "$secret_file" ] || [ ! -r "$secret_file" ]; then
        echo "Registry compose integration: a secret file is unreadable" >&2
        exit 1
    fi
done
if [ ! -x "$restore_script" ]; then
    echo "Registry compose integration: restore roundtrip is not executable" >&2
    exit 1
fi
if [ ! -d "$temporary_parent" ] || [ ! -w "$temporary_parent" ]; then
    echo "Registry compose integration: temporary parent is not writable" >&2
    exit 1
fi
temporary_parent=$(CDPATH= cd -- "$temporary_parent" && pwd -P)
if [ "$temporary_parent" = / ]; then
    echo "Registry compose integration: refusing the filesystem root as temporary parent" >&2
    exit 1
fi
"$container_cli" image inspect "$app_image" >/dev/null
"$container_cli" image inspect "$migrate_image" >/dev/null

bind_root=$(mktemp -d "${temporary_parent%/}/.estab-registry-bind.XXXXXX")
chmod 0700 "$bind_root"
bind_root=$(CDPATH= cd -- "$bind_root" && pwd -P)
case "$bind_root" in
    "$temporary_parent"/.estab-registry-bind.*) ;;
    *)
        echo "Registry compose integration: mktemp returned an unexpected path" >&2
        exit 1
        ;;
esac
bind_db=$bind_root/data/db
bind_data=$bind_root/data/4fdata
bind_export=$bind_root/data/export
backup_dir=$bind_root/backups/roundtrip
mkdir -p "$bind_db" "$bind_data" "$bind_export" "$backup_dir"
chmod 0700 "$bind_root/data" "$bind_root/backups" "$backup_dir"
printf '%s\n' "$bind_project" >"$bind_root/.estab-ci-bind-storage"
chmod 0600 "$bind_root/.estab-ci-bind-storage"

export ESTAB_APP_IMAGE=$app_image
export ESTAB_MIGRATE_IMAGE=$migrate_image
export ESTAB_HTTP_BIND=127.0.0.1
export ESTAB_HTTP_PORT=$http_port

use_named_storage()
{
    export ESTAB_DB_DATA_SOURCE=estab_db
    export ESTAB_APP_DATA_SOURCE=estab_data
    export ESTAB_EXPORT_DATA_SOURCE=estab_export
}

use_bind_storage()
{
    export ESTAB_DB_DATA_SOURCE=$bind_db
    export ESTAB_APP_DATA_SOURCE=$bind_data
    export ESTAB_EXPORT_DATA_SOURCE=$bind_export
}

compose_project()
{
    compose_project_name=$1
    shift
    "$container_cli" compose -f "$compose_file" -p "$compose_project_name" "$@"
}

compose()
{
    compose_project "$active_project" "$@"
}

validate_bind_root()
{
    if [ ! -e "$bind_root" ]; then
        return 1
    fi
    if [ ! -d "$bind_root" ] || [ -L "$bind_root" ]; then
        echo "Registry compose integration: bind root is not a real directory" >&2
        return 1
    fi
    canonical_bind_root=$(CDPATH= cd -- "$bind_root" && pwd -P) || return 1
    case "$canonical_bind_root" in
        "$temporary_parent"/.estab-registry-bind.*) ;;
        *)
            echo "Registry compose integration: bind root escaped its temporary parent" >&2
            return 1
            ;;
    esac
    if [ ! -f "$canonical_bind_root/.estab-ci-bind-storage" ] ||
        [ -L "$canonical_bind_root/.estab-ci-bind-storage" ] ||
        [ "$(sed -n '1p' "$canonical_bind_root/.estab-ci-bind-storage")" != "$bind_project" ]; then
        echo "Registry compose integration: bind root guard is missing or invalid" >&2
        return 1
    fi
    for bind_directory in "$bind_db" "$bind_data" "$bind_export"; do
        if [ ! -d "$bind_directory" ] || [ -L "$bind_directory" ]; then
            echo "Registry compose integration: guarded bind directory is invalid" >&2
            return 1
        fi
    done
    [ "$canonical_bind_root" = "$bind_root" ]
}

capture_diagnostics()
{
    echo "Registry compose integration: named-volume container status" >&2
    use_named_storage
    compose_project "$project" ps --all >&2 || true
    compose_project "$project" logs --no-color --tail=200 >&2 || true
    echo "Registry compose integration: bind-mount container status" >&2
    use_bind_storage
    compose_project "$bind_project" ps --all >&2 || true
    compose_project "$bind_project" logs --no-color --tail=200 >&2 || true
}

project_resources_empty()
{
    checked_project=$1
    resources_empty=0

    if ! remaining_compose_containers=$(compose_project "$checked_project" \
        ps --all --quiet 2>/dev/null); then
        echo "Registry compose integration: could not inspect Compose containers" >&2
        resources_empty=1
        remaining_compose_containers=unknown
    fi
    if ! remaining_containers=$("$container_cli" ps --all --quiet \
        --filter "label=com.docker.compose.project=$checked_project" 2>/dev/null); then
        echo "Registry compose integration: could not inspect remaining containers" >&2
        resources_empty=1
        remaining_containers=unknown
    fi
    if ! remaining_volumes=$("$container_cli" volume ls --quiet \
        --filter "label=com.docker.compose.project=$checked_project" 2>/dev/null); then
        echo "Registry compose integration: could not inspect remaining volumes" >&2
        resources_empty=1
        remaining_volumes=unknown
    fi
    if ! remaining_networks=$("$container_cli" network ls --quiet \
        --filter "label=com.docker.compose.project=$checked_project" 2>/dev/null); then
        echo "Registry compose integration: could not inspect remaining networks" >&2
        resources_empty=1
        remaining_networks=unknown
    fi
    if [ -n "$remaining_compose_containers" ] ||
        [ -n "$remaining_containers" ] ||
        [ -n "$remaining_volumes" ] ||
        [ -n "$remaining_networks" ]; then
        echo "Registry compose integration: resources remain for $checked_project" >&2
        resources_empty=1
    fi
    for known_volume in \
        "${checked_project}_estab_db" \
        "${checked_project}_estab_data" \
        "${checked_project}_estab_export"
    do
        if "$container_cli" volume inspect "$known_volume" >/dev/null 2>&1; then
            echo "Registry compose integration: known volume remains: $known_volume" >&2
            resources_empty=1
        fi
    done
    for known_network in \
        "${checked_project}_frontend" \
        "${checked_project}_database"
    do
        if "$container_cli" network inspect "$known_network" >/dev/null 2>&1; then
            echo "Registry compose integration: known network remains: $known_network" >&2
            resources_empty=1
        fi
    done
    [ "$resources_empty" -eq 0 ]
}

empty_bind_storage_for_cleanup()
{
    validate_bind_root || return 1
    use_bind_storage
    compose_project "$bind_project" down --volumes --remove-orphans --timeout 20 \
        >/dev/null 2>&1 || return 1

    # The validated temporary root is mounted as one cleanup boundary. This
    # also removes DB files whose host ownership was changed by MariaDB while
    # leaving the root itself available for an exact rmdir postcondition.
    "$container_cli" run --rm \
        --volume "$bind_root:/cleanup" \
        --entrypoint sh \
        "$app_image" -ceu '
            find /cleanup -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
            test -z "$(find /cleanup -mindepth 1 -print -quit)"
        ' >/dev/null 2>&1 || return 1
}

test_completed=0
cleanup()
{
    status=$?
    trap - EXIT HUP INT TERM
    if [ "$status" -ne 0 ]; then
        capture_diagnostics
    fi
    cleanup_failed=0

    use_named_storage
    if ! compose_project "$project" down --volumes --remove-orphans --timeout 20 \
        >/dev/null 2>&1; then
        echo "Registry compose integration: named-volume cleanup failed" >&2
        cleanup_failed=1
    fi

    if [ -e "$bind_root" ]; then
        if ! empty_bind_storage_for_cleanup; then
            echo "Registry compose integration: guarded bind cleanup failed" >&2
            cleanup_failed=1
        fi
        if [ -d "$bind_root" ] && ! rmdir -- "$bind_root"; then
            echo "Registry compose integration: guarded bind root is not empty" >&2
            cleanup_failed=1
        fi
    fi
    if [ -e "$bind_root" ]; then
        echo "Registry compose integration: temporary bind root remains" >&2
        cleanup_failed=1
    fi

    if ! project_resources_empty "$project"; then
        cleanup_failed=1
    fi
    if ! project_resources_empty "$bind_project"; then
        cleanup_failed=1
    fi

    if [ "$status" -eq 0 ]; then
        if [ "$cleanup_failed" -ne 0 ] || [ "$test_completed" -ne 1 ]; then
            status=1
        else
            echo "Registry compose integration: OK"
        fi
    fi
    exit "$status"
}
trap cleanup EXIT HUP INT TERM

wait_for_app()
{
    deadline=$(( $(date +%s) + 240 ))
    while :; do
        app_id=$(compose ps -q app 2>/dev/null || true)
        if [ -n "$app_id" ]; then
            app_status=$("$container_cli" inspect --format \
                '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
                "$app_id" 2>/dev/null || true)
            case "$app_status" in
                healthy) return 0 ;;
                unhealthy | exited | dead)
                    echo "Registry compose integration: app entered $app_status" >&2
                    return 1
                    ;;
            esac
        fi
        if [ "$(date +%s)" -ge "$deadline" ]; then
            echo "Registry compose integration: app did not become healthy" >&2
            return 1
        fi
        sleep 3
    done
}

verify_stack()
{
    wait_for_app

    migrate_id=$(compose ps --all -q migrate)
    if [ -z "$migrate_id" ]; then
        echo "Registry compose integration: one-shot migrator container is missing" >&2
        return 1
    fi
    migrate_status=$("$container_cli" inspect --format \
        '{{.State.Status}} {{.State.ExitCode}}' "$migrate_id")
    if [ "$migrate_status" != "exited 0" ]; then
        echo "Registry compose integration: migrator ended as $migrate_status" >&2
        return 1
    fi

    expected_app_image_id=$("$container_cli" image inspect --format '{{.Id}}' "$app_image")
    expected_migrate_image_id=$("$container_cli" image inspect --format '{{.Id}}' "$migrate_image")
    actual_app_image_id=$("$container_cli" inspect --format '{{.Image}}' "$app_id")
    actual_migrate_image_id=$("$container_cli" inspect --format '{{.Image}}' "$migrate_id")
    if [ "$actual_app_image_id" != "$expected_app_image_id" ] ||
        [ "$actual_migrate_image_id" != "$expected_migrate_image_id" ]; then
        echo "Registry compose integration: stack did not use the requested images" >&2
        return 1
    fi

    compose run --rm --no-deps -T migrate
    curl --fail --silent --show-error --max-time 20 \
        "http://127.0.0.1:$http_port/health.php" |
        grep -Fq '"status":"ready"'

    app_logs=$(compose logs --no-color app)
    if printf '%s\n' "$app_logs" |
        grep -Eq 'PHP (Warning|Deprecated|Notice|Fatal error|Parse error):|Uncaught'; then
        echo "Registry compose integration: PHP runtime error found in app log" >&2
        return 1
    fi
}

assert_bind_mount()
{
    mount_container=$1
    expected_source=$2
    expected_destination=$3
    mount_record=$("$container_cli" inspect --format \
        '{{range .Mounts}}{{printf "%s|%s|%s\n" .Type .Source .Destination}}{{end}}' \
        "$mount_container" |
        awk -F '|' -v destination="$expected_destination" \
            '$3 == destination { print $1 "|" $2 "|" $3 }')
    if [ "$mount_record" != "bind|$expected_source|$expected_destination" ]; then
        echo "Registry compose integration: unexpected bind mount for $expected_destination" >&2
        printf '%s\n' "$mount_record" >&2
        return 1
    fi
}

verify_bind_mounts()
{
    bind_db_id=$(compose ps -q db)
    bind_app_id=$(compose ps -q app)
    [ -n "$bind_db_id" ] && [ -n "$bind_app_id" ]
    assert_bind_mount "$bind_db_id" "$bind_db" /var/lib/mysql
    assert_bind_mount "$bind_app_id" "$bind_data" /var/www/html/4fdata
    assert_bind_mount "$bind_app_id" "$bind_export" /var/lib/estab/export
}

database_client()
{
    compose exec -T db sh -ceu '
        umask 077
        client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-registry-client.XXXXXX")
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
    '
}

database_marker_count()
{
    printf "SELECT COUNT(*) FROM nv_protokoll WHERE p_was = 'registry-bind' AND p_ereignis = '%s';\n" \
        "$bind_marker" |
        database_client |
        tr -d '\r\n'
}

container_file_sha256()
{
    marker_path=$1
    compose exec -T app sha256sum "$marker_path" |
        awk 'NR == 1 && $1 ~ /^[0-9a-f]{64}$/ { print $1 }'
}

use_named_storage
active_project=$project
compose config >/dev/null
compose down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1

echo "Registry compose integration: starting pull-only named-volume stack"
compose up --detach --pull never
verify_stack

compose down --volumes --remove-orphans --timeout 20
project_resources_empty "$project"

echo "Registry compose integration: starting pull-only guarded bind-mount stack"
validate_bind_root
use_bind_storage
active_project=$bind_project
compose config >/dev/null
compose down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1
compose up --detach --pull never
verify_stack
verify_bind_mounts

bind_marker="ESTAB_REGISTRY_BIND_${project}"
case "$bind_marker" in
    *[!A-Za-z0-9_-]*)
        echo "Registry compose integration: generated marker is unsafe" >&2
        exit 1
        ;;
esac
printf "DELETE FROM nv_protokoll WHERE p_was = 'registry-bind';\nINSERT INTO nv_protokoll (p_was, p_ereignis) VALUES ('registry-bind', '%s');\n" \
    "$bind_marker" |
    database_client >/dev/null
if [ "$(database_marker_count)" != 1 ]; then
    echo "Registry compose integration: database marker was not stored" >&2
    exit 1
fi

printf '%s\n' "$bind_marker" |
    compose exec -T --user www-data app sh -ceu '
        IFS= read -r bind_marker
        case "$bind_marker" in
            ESTAB_REGISTRY_BIND_*) ;;
            *) echo "Invalid bind marker" >&2; exit 1 ;;
        esac
        data_root="/var/www/html/4fdata/$ESTAB_DB_NAME"
        test -d "$data_root/anhang"
        test -d /var/lib/estab/export
        umask 077
        printf "%s\n" "$bind_marker" >"$data_root/anhang/.estab-bind-data-marker"
        printf "%s\n" "$bind_marker" >/var/lib/estab/export/.estab-bind-export-marker
    '

data_marker_path="/var/www/html/4fdata/${ESTAB_DB_NAME:-estab}/anhang/.estab-bind-data-marker"
export_marker_path=/var/lib/estab/export/.estab-bind-export-marker
data_marker_sha256=$(container_file_sha256 "$data_marker_path")
export_marker_sha256=$(container_file_sha256 "$export_marker_path")
if [ -z "$data_marker_sha256" ] || [ -z "$export_marker_sha256" ]; then
    echo "Registry compose integration: file marker checksum is missing" >&2
    exit 1
fi

echo "Registry compose integration: backing up, clearing, and restoring guarded bind data"
COMPOSE_PROJECT_NAME=$bind_project \
ESTAB_CONTAINER_CLI=$container_cli \
ESTAB_COMPOSE_FILE=$compose_file \
ESTAB_RESTORE_STORAGE_MODE=bind \
ESTAB_BIND_STORAGE_ROOT=$bind_root \
ESTAB_BACKUP_DIR=$backup_dir \
    bash "$restore_script"

(
    cd "$backup_dir"
    sha256sum --check --strict SHA256SUMS >/dev/null
)
verify_bind_mounts
compose run --rm --no-deps -T migrate
wait_for_app
curl --fail --silent --show-error --max-time 20 \
    "http://127.0.0.1:$http_port/health.php" |
    grep -Fq '"status":"ready"'

if [ "$(database_marker_count)" != 1 ]; then
    echo "Registry compose integration: restored database marker differs" >&2
    exit 1
fi
if [ "$(container_file_sha256 "$data_marker_path")" != "$data_marker_sha256" ] ||
    [ "$(container_file_sha256 "$export_marker_path")" != "$export_marker_sha256" ]; then
    echo "Registry compose integration: restored file marker checksum differs" >&2
    exit 1
fi
restored_data_marker=$(compose exec -T app sh -ceu \
    'cat "/var/www/html/4fdata/$ESTAB_DB_NAME/anhang/.estab-bind-data-marker"')
restored_export_marker=$(compose exec -T app \
    cat /var/lib/estab/export/.estab-bind-export-marker)
if [ "$restored_data_marker" != "$bind_marker" ] ||
    [ "$restored_export_marker" != "$bind_marker" ]; then
    echo "Registry compose integration: restored marker content differs" >&2
    exit 1
fi

app_logs=$(compose logs --no-color app)
if printf '%s\n' "$app_logs" |
    grep -Eq 'PHP (Warning|Deprecated|Notice|Fatal error|Parse error):|Uncaught'; then
    echo "Registry compose integration: restored app log contains a PHP runtime error" >&2
    exit 1
fi

test_completed=1
