#!/bin/sh
set -eu

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

container_cli=${ESTAB_CONTAINER_CLI:-docker}
project=${ESTAB_REGISTRY_PROJECT:-estab_ci_registry}
bind_project=${project}_bind
http_port=${ESTAB_REGISTRY_HTTP_PORT:-18081}
app_image=${ESTAB_REGISTRY_APP_IMAGE:-}
migrate_image=${ESTAB_REGISTRY_MIGRATE_IMAGE:-}
compose_file=$repo_root/deploy/registry/compose.yaml
backup_operator=$repo_root/deploy/registry/backup.sh
backup_verifier=$repo_root/deploy/registry/verify-backup.sh
restore_operator=$repo_root/deploy/registry/restore.sh
temporary_parent=${ESTAB_REGISTRY_TEMP_PARENT:-$repo_root}
bind_root=
bind_db=
bind_data=
bind_export=
backup_parent=
production_backup=
portable_backup=

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
if [ ! -r "$backup_operator" ] ||
    [ ! -r "$backup_verifier" ] ||
    [ ! -r "$restore_operator" ]; then
    echo "Registry compose integration: production backup/restore tools are unreadable" >&2
    exit 1
fi
if [ ! -d "$temporary_parent" ] || [ ! -w "$temporary_parent" ]; then
    echo "Registry compose integration: temporary parent is not writable" >&2
    exit 1
fi
temporary_parent=$(CDPATH='' cd -- "$temporary_parent" && pwd -P)
if [ "$temporary_parent" = / ]; then
    echo "Registry compose integration: refusing the filesystem root as temporary parent" >&2
    exit 1
fi
"$container_cli" image inspect "$app_image" >/dev/null
"$container_cli" image inspect "$migrate_image" >/dev/null

bind_root=$(mktemp -d "${temporary_parent%/}/.estab-registry-bind.XXXXXX")
chmod 0700 "$bind_root"
bind_root=$(CDPATH='' cd -- "$bind_root" && pwd -P)
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
backup_parent=$bind_root/backups
production_backup=$backup_parent/production-v3
portable_backup=$backup_parent/named-to-bind-v3
mkdir -p "$bind_db" "$bind_data" "$bind_export" "$backup_parent"
chmod 0700 \
    "$bind_root/data" \
    "$bind_db" \
    "$bind_data" \
    "$bind_export" \
    "$backup_parent"
printf '%s\n' "$bind_project" >"$bind_root/.estab-ci-bind-storage"
chmod 0600 "$bind_root/.estab-ci-bind-storage"

portable_mode()
{
    mode_path=$1
    if mode_value=$(stat -c '%a' "$mode_path" 2>/dev/null); then
        :
    elif mode_value=$(stat -f '%Lp' "$mode_path" 2>/dev/null); then
        :
    else
        echo "Registry compose integration: cannot inspect host directory mode" >&2
        return 1
    fi
    printf '%s\n' "$mode_value"
}

for documented_private_directory in \
    "$bind_root/data" \
    "$bind_db" \
    "$bind_data" \
    "$bind_export"
do
    if [ "$(portable_mode "$documented_private_directory")" != 700 ]; then
        echo "Registry compose integration: documented bind directory is not mode 0700" >&2
        exit 1
    fi
done

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

verify_default_project_stability()
{
    first_release=$bind_root/project-name-release-one
    second_release=$bind_root/project-name-release-two
    mkdir -p "$first_release" "$second_release"
    cp "$compose_file" "$first_release/compose.yaml"
    cp "$compose_file" "$second_release/compose.yaml"

    for release_directory in "$first_release" "$second_release"; do
        (
            unset COMPOSE_PROJECT_NAME
            cd "$release_directory"
            "$container_cli" compose -f compose.yaml config
        ) >"$release_directory/effective-compose.yaml"
        if ! grep -Eq '^name: estab$' \
            "$release_directory/effective-compose.yaml"; then
            echo "Registry compose integration: release directory changed the default project name" >&2
            return 1
        fi
        for volume_name in \
            estab_estab_db \
            estab_estab_data \
            estab_estab_export \
            estab_estab_auth
        do
            if ! grep -Fq "name: $volume_name" \
                "$release_directory/effective-compose.yaml"; then
                echo "Registry compose integration: stable volume name is missing: $volume_name" >&2
                return 1
            fi
        done
    done
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
    canonical_bind_root=$(CDPATH='' cd -- "$bind_root" && pwd -P) || return 1
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
        "${checked_project}_estab_export" \
        "${checked_project}_estab_auth"
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

wait_for_db()
{
    deadline=$(( $(date +%s) + 240 ))
    while :; do
        db_id=$(compose ps -q db 2>/dev/null || true)
        if [ -n "$db_id" ]; then
            db_status=$("$container_cli" inspect --format \
                '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
                "$db_id" 2>/dev/null || true)
            case "$db_status" in
                healthy) return 0 ;;
                unhealthy | exited | dead)
                    echo "Registry compose integration: db entered $db_status" >&2
                    return 1
                    ;;
            esac
        fi
        if [ "$(date +%s)" -ge "$deadline" ]; then
            echo "Registry compose integration: db did not become healthy" >&2
            return 1
        fi
        sleep 3
    done
}

verify_admin_secret_isolation()
{
    isolation_app_id=$(compose ps -q app)
    isolation_init_id=$(compose ps --all -q admin-auth-init)
    if [ -z "$isolation_app_id" ] || [ -z "$isolation_init_id" ]; then
        echo "Registry compose integration: admin authentication containers are missing" >&2
        return 1
    fi

    isolation_init_status=$("$container_cli" inspect --format \
        '{{.State.Status}} {{.State.ExitCode}}' "$isolation_init_id")
    if [ "$isolation_init_status" != "exited 0" ]; then
        echo "Registry compose integration: auth initializer ended as $isolation_init_status" >&2
        return 1
    fi
    isolation_network_mode=$("$container_cli" inspect --format \
        '{{.HostConfig.NetworkMode}}' "$isolation_init_id")
    if [ "$isolation_network_mode" != none ]; then
        echo "Registry compose integration: auth initializer has network access" >&2
        return 1
    fi

    isolation_app_environment=$("$container_cli" inspect --format \
        '{{range .Config.Env}}{{println .}}{{end}}' "$isolation_app_id")
    if printf '%s\n' "$isolation_app_environment" |
        grep -Eq '^ESTAB_ADMIN_PASSWORD(_FILE)?='; then
        echo "Registry compose integration: web environment exposes the admin secret" >&2
        return 1
    fi
    isolation_secret_mount=$("$container_cli" inspect --format \
        '{{range .Mounts}}{{if eq .Destination "/run/secrets/estab_admin_password"}}present{{end}}{{end}}' \
        "$isolation_app_id")
    if [ -n "$isolation_secret_mount" ]; then
        echo "Registry compose integration: web container mounts the admin secret" >&2
        return 1
    fi
    isolation_auth_mode=$("$container_cli" inspect --format \
        '{{range .Mounts}}{{if eq .Destination "/run/estab-auth"}}{{if .RW}}rw{{else}}ro{{end}}{{end}}{{end}}' \
        "$isolation_app_id")
    if [ "$isolation_auth_mode" != ro ]; then
        echo "Registry compose integration: derived auth mount is not read-only" >&2
        return 1
    fi

    compose exec -T app sh -ceu '
        test ! -e /run/secrets/estab_admin_password
        test -r /run/estab-auth/admin.htpasswd
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
    compose run --rm --no-deps -T app php -r '
        if (getenv("ESTAB_ADMIN_PASSWORD") !== false
            || getenv("ESTAB_ADMIN_PASSWORD_FILE") !== false
            || !is_readable("/run/estab-auth/admin.htpasswd")) {
            exit(1);
        }
        echo "registry no-deps admin isolation: OK\n";
    '
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
    auth_init_id=$(compose ps --all -q admin-auth-init)
    if [ -z "$auth_init_id" ]; then
        echo "Registry compose integration: auth initializer is missing" >&2
        return 1
    fi
    auth_init_status=$("$container_cli" inspect --format \
        '{{.State.Status}} {{.State.ExitCode}}' "$auth_init_id")
    if [ "$auth_init_status" != "exited 0" ]; then
        echo "Registry compose integration: auth initializer ended as $auth_init_status" >&2
        return 1
    fi

    expected_app_image_id=$("$container_cli" image inspect --format '{{.Id}}' "$app_image")
    expected_migrate_image_id=$("$container_cli" image inspect --format '{{.Id}}' "$migrate_image")
    actual_app_image_id=$("$container_cli" inspect --format '{{.Image}}' "$app_id")
    actual_auth_init_image_id=$("$container_cli" inspect --format '{{.Image}}' "$auth_init_id")
    actual_migrate_image_id=$("$container_cli" inspect --format '{{.Image}}' "$migrate_id")
    if [ "$actual_app_image_id" != "$expected_app_image_id" ] ||
        [ "$actual_auth_init_image_id" != "$expected_app_image_id" ] ||
        [ "$actual_migrate_image_id" != "$expected_migrate_image_id" ]; then
        echo "Registry compose integration: stack did not use the requested images" >&2
        return 1
    fi

    verify_admin_secret_isolation
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

verify_writable_app_roots()
{
    compose exec -T app sh -ceu '
        for writable_root in \
            /var/www/html/4fdata \
            /var/lib/estab/export
        do
            test -n "$(find "$writable_root" -prune -type d \
                -perm 0770 -print)"
        done
    '
    compose exec -T --user www-data app sh -ceu '
        for writable_root in \
            /var/www/html/4fdata \
            /var/lib/estab/export
        do
            write_probe=$(mktemp \
                "$writable_root/.estab-entrypoint-write-probe.XXXXXX")
            printf "www-data-write-probe\n" >"$write_probe"
            test "$(cat "$write_probe")" = "www-data-write-probe"
            rm -f -- "$write_probe"
        done
    '
    for writable_host_root in "$bind_data" "$bind_export"; do
        if [ "$(portable_mode "$writable_host_root")" != 770 ]; then
            echo "Registry compose integration: app entrypoint did not normalize bind root to mode 0770" >&2
            return 1
        fi
    done
    if [ "$(portable_mode "$bind_root/data")" != 700 ]; then
        echo "Registry compose integration: app entrypoint changed the private data parent" >&2
        return 1
    fi
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

backup_storage_field()
{
    storage_role=$1
    storage_field=$2
    LC_ALL=C awk -F '	' \
        -v role="$storage_role" \
        -v field="$storage_field" '
          $1 == role && NF == 5 {
            matches++
            value = $field
          }
          END {
            if (matches != 1 || value == "") {
              exit 1
            }
            print value
          }
        ' "$portable_backup/storage-sources.txt"
}

bind_marker="ESTAB_REGISTRY_BIND_${project}"
case "$bind_marker" in
    *[!A-Za-z0-9_-]*)
        echo "Registry compose integration: generated marker is unsafe" >&2
        exit 1
        ;;
esac
data_marker_path="/var/www/html/4fdata/${ESTAB_DB_NAME:-estab}/anhang/.estab-bind-data-marker"
export_marker_path=/var/lib/estab/export/.estab-bind-export-marker

use_named_storage
active_project=$project
verify_default_project_stability
compose config >/dev/null
compose down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1

echo "Registry compose integration: starting pull-only named-volume stack"
compose up --detach --pull never
verify_stack

echo "Registry compose integration: seeding a portable named-volume backup"
printf "DELETE FROM nv_protokoll WHERE p_was = 'registry-bind';\nINSERT INTO nv_protokoll (p_was, p_ereignis) VALUES ('registry-bind', '%s');\n" \
    "$bind_marker" |
    database_client >/dev/null
if [ "$(database_marker_count)" != 1 ]; then
    echo "Registry compose integration: portable database marker was not stored" >&2
    exit 1
fi
printf '%s\n' "$bind_marker" |
    compose exec -T --user www-data app sh -ceu '
        IFS= read -r bind_marker
        data_root="/var/www/html/4fdata/$ESTAB_DB_NAME"
        test -d "$data_root/anhang"
        test -d /var/lib/estab/export
        umask 077
        printf "%s\n" "$bind_marker" >"$data_root/anhang/.estab-bind-data-marker"
        printf "%s\n" "$bind_marker" >/var/lib/estab/export/.estab-bind-export-marker
    '
portable_data_marker_sha256=$(container_file_sha256 "$data_marker_path")
portable_export_marker_sha256=$(container_file_sha256 "$export_marker_path")
if [ -z "$portable_data_marker_sha256" ] ||
    [ -z "$portable_export_marker_sha256" ]; then
    echo "Registry compose integration: portable marker checksum is missing" >&2
    exit 1
fi
(
    cd "$repo_root/deploy/registry"
    COMPOSE_PROJECT_NAME=$project \
    ESTAB_CONTAINER_CLI=$container_cli \
    ESTAB_BACKUP_HEALTH_TIMEOUT_SECONDS=240 \
        sh "$backup_operator" "$portable_backup"
)
grep -Fqx 'estab-full-backup-v3' "$portable_backup/backup-format.txt"
sh "$backup_verifier" "$portable_backup" "${ESTAB_DB_NAME:-estab}"
if ! LC_ALL=C awk -F '	' '
  FNR == NR {
    if ($1 == "database") {
      if (NF != 3 || image_seen++) {
        exit 1
      }
      image_reference = $2
    }
    next
  }
  $1 == "database" {
    if (NF != 2 || release_seen++) {
      exit 1
    }
    release_reference = $2
  }
  END {
    prefix = "docker.io/library/mariadb@sha256:"
    digest = substr(image_reference, length(prefix) + 1)
    if (image_seen != 1 ||
        release_seen != 1 ||
        image_reference != release_reference ||
        substr(image_reference, 1, length(prefix)) != prefix ||
        length(digest) != 64 ||
        digest ~ /[^0-9a-f]/) {
      exit 1
    }
  }
' "$portable_backup/image-references.txt" \
    "$portable_backup/release-identity.txt"; then
    echo "Registry compose integration: database image reference is not canonical and engine-independent" >&2
    exit 1
fi
if ! LC_ALL=C awk -F '	' '
  NF != 5 || $3 != "volume" || $4 == "-" {
    exit 1
  }
  END {
    if (NR != 3) {
      exit 1
    }
  }
' "$portable_backup/storage-sources.txt"; then
    echo "Registry compose integration: source backup is not fully named-volume based" >&2
    exit 1
fi
portable_db_name=$(backup_storage_field database 4)
portable_db_source=$(backup_storage_field database 5)
portable_app_name=$(backup_storage_field application 4)
portable_app_source=$(backup_storage_field application 5)
portable_export_name=$(backup_storage_field export 4)
portable_export_source=$(backup_storage_field export 5)

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
verify_writable_app_roots

echo "Registry compose integration: rejecting an incomplete Named-to-Bind restore"
if (
    cd "$repo_root/deploy/registry"
    COMPOSE_PROJECT_NAME=$bind_project \
    ESTAB_CONTAINER_CLI=$container_cli \
    ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS=240 \
        sh "$restore_operator" \
            --confirm-project "$bind_project" \
            --remap-project "$project=$bind_project" \
            --remap-storage "database:$portable_db_source=$bind_db" \
            --remap-volume "database:$portable_db_name=-" \
            --remap-storage "application:$portable_app_source=$bind_data" \
            --remap-volume "application:$portable_app_name=-" \
            --remap-storage "export:$portable_export_source=$bind_export" \
            --remap-volume "export:$portable_export_name=-" \
            "$portable_backup"
); then
    echo "Registry compose integration: missing mount-type remaps were accepted" >&2
    exit 1
fi
if [ "$(database_marker_count)" != 0 ]; then
    echo "Registry compose integration: rejected portable restore changed the database" >&2
    exit 1
fi
compose exec -T app sh -ceu '
    data_root="/var/www/html/4fdata/$ESTAB_DB_NAME"
    test ! -e "$data_root/anhang/.estab-bind-data-marker"
    test ! -e /var/lib/estab/export/.estab-bind-export-marker
'

echo "Registry compose integration: restoring Format 3 from Named Volumes into a separate Bind project"
(
    cd "$repo_root/deploy/registry"
    COMPOSE_PROJECT_NAME=$bind_project \
    ESTAB_CONTAINER_CLI=$container_cli \
    ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS=240 \
        sh "$restore_operator" \
            --confirm-project "$bind_project" \
            --remap-project "$project=$bind_project" \
            --remap-mount-type database:volume=bind \
            --remap-storage "database:$portable_db_source=$bind_db" \
            --remap-volume "database:$portable_db_name=-" \
            --remap-mount-type application:volume=bind \
            --remap-storage "application:$portable_app_source=$bind_data" \
            --remap-volume "application:$portable_app_name=-" \
            --remap-mount-type export:volume=bind \
            --remap-storage "export:$portable_export_source=$bind_export" \
            --remap-volume "export:$portable_export_name=-" \
            "$portable_backup"
)
sh "$backup_verifier" "$portable_backup" "${ESTAB_DB_NAME:-estab}"
verify_bind_mounts
verify_writable_app_roots
wait_for_app
curl --fail --silent --show-error --max-time 20 \
    "http://127.0.0.1:$http_port/health.php" |
    grep -Fq '"status":"ready"'
if [ "$(database_marker_count)" != 1 ]; then
    echo "Registry compose integration: portable database marker differs" >&2
    exit 1
fi
if [ "$(container_file_sha256 "$data_marker_path")" != "$portable_data_marker_sha256" ] ||
    [ "$(container_file_sha256 "$export_marker_path")" != "$portable_export_marker_sha256" ]; then
    echo "Registry compose integration: portable file marker checksum differs" >&2
    exit 1
fi
portable_restored_data_marker=$(compose exec -T app sh -ceu \
    'cat "/var/www/html/4fdata/$ESTAB_DB_NAME/anhang/.estab-bind-data-marker"')
portable_restored_export_marker=$(compose exec -T app \
    cat /var/lib/estab/export/.estab-bind-export-marker)
if [ "$portable_restored_data_marker" != "$bind_marker" ] ||
    [ "$portable_restored_export_marker" != "$bind_marker" ]; then
    echo "Registry compose integration: portable marker content differs" >&2
    exit 1
fi
project_resources_empty "$project"

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

data_marker_sha256=$(container_file_sha256 "$data_marker_path")
export_marker_sha256=$(container_file_sha256 "$export_marker_path")
if [ -z "$data_marker_sha256" ] || [ -z "$export_marker_sha256" ]; then
    echo "Registry compose integration: file marker checksum is missing" >&2
    exit 1
fi

echo "Registry compose integration: creating a production format-3 backup"
(
    cd "$repo_root/deploy/registry"
    COMPOSE_PROJECT_NAME=$bind_project \
    ESTAB_CONTAINER_CLI=$container_cli \
    ESTAB_BACKUP_HEALTH_TIMEOUT_SECONDS=240 \
        sh "$backup_operator" "$production_backup"
)
[ -d "$production_backup" ] && [ ! -L "$production_backup" ]
grep -Fqx 'estab-full-backup-v3' "$production_backup/backup-format.txt"
sh "$backup_verifier" "$production_backup" "${ESTAB_DB_NAME:-estab}"

echo "Registry compose integration: corrupting controlled restore fixtures"
validate_bind_root
printf "DELETE FROM nv_protokoll WHERE p_was = 'registry-bind';\n" |
    database_client >/dev/null
printf '%s\n' 'CORRUPTED' |
    compose exec -T --user www-data app sh -ceu '
        IFS= read -r corrupt_marker
        data_root="/var/www/html/4fdata/$ESTAB_DB_NAME"
        printf "%s\n" "$corrupt_marker" \
            >"$data_root/anhang/.estab-bind-data-marker"
        printf "%s\n" "$corrupt_marker" \
            >/var/lib/estab/export/.estab-bind-export-marker
        mkdir "$data_root/anhang/.restore-stale"
        mkdir /var/lib/estab/export/.restore-stale
'
if [ "$(database_marker_count)" != 0 ]; then
    echo "Registry compose integration: database fixture was not corrupted" >&2
    exit 1
fi
if [ "$(container_file_sha256 "$data_marker_path")" = "$data_marker_sha256" ] ||
    [ "$(container_file_sha256 "$export_marker_path")" = "$export_marker_sha256" ]; then
    echo "Registry compose integration: file fixtures were not corrupted" >&2
    exit 1
fi

echo "Registry compose integration: restoring exactly the production format-3 backup"
if (
    cd "$repo_root/deploy/registry"
    COMPOSE_PROJECT_NAME=$bind_project \
    ESTAB_CONTAINER_CLI=$container_cli \
    ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS=240 \
        sh "$restore_operator" \
            --confirm-project "${bind_project}_wrong" \
            "$production_backup"
); then
    echo "Registry compose integration: wrong restore confirmation was accepted" >&2
    exit 1
fi
if [ "$(database_marker_count)" != 0 ]; then
    echo "Registry compose integration: rejected restore changed the database" >&2
    exit 1
fi
(
    cd "$repo_root/deploy/registry"
    COMPOSE_PROJECT_NAME=$bind_project \
    ESTAB_CONTAINER_CLI=$container_cli \
    ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS=240 \
        sh "$restore_operator" \
            --confirm-project "$bind_project" \
            "$production_backup"
)
sh "$backup_verifier" "$production_backup" "${ESTAB_DB_NAME:-estab}"
verify_bind_mounts
verify_writable_app_roots
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
compose exec -T app sh -ceu '
    data_root="/var/www/html/4fdata/$ESTAB_DB_NAME"
    test ! -e "$data_root/anhang/.restore-stale"
    test ! -e /var/lib/estab/export/.restore-stale
'
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
