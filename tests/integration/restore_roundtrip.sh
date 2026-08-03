#!/usr/bin/env bash

set -Eeuo pipefail

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

: "${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME is required}"
: "${ESTAB_BACKUP_DIR:?ESTAB_BACKUP_DIR is required}"
container_cli=${ESTAB_CONTAINER_CLI:-docker}
compose_file=${ESTAB_COMPOSE_FILE:-}
storage_mode=${ESTAB_RESTORE_STORAGE_MODE:-named}
bind_storage_root=${ESTAB_BIND_STORAGE_ROOT:-}
case "$container_cli" in
    docker | podman) ;;
    *)
        echo "Restore roundtrip: ESTAB_CONTAINER_CLI must be docker or podman" >&2
        exit 1
        ;;
esac
command -v "$container_cli" >/dev/null 2>&1 || {
    echo "Restore roundtrip: ${container_cli} is required" >&2
    exit 1
}
command -v sha256sum >/dev/null 2>&1 || {
    echo "Restore roundtrip: sha256sum is required" >&2
    exit 1
}
case "$storage_mode" in
    named | bind) ;;
    *)
        echo "Restore roundtrip: ESTAB_RESTORE_STORAGE_MODE must be named or bind" >&2
        exit 1
        ;;
esac

case "$COMPOSE_PROJECT_NAME" in
    estab_ci | estab_ci_*) ;;
    *)
        echo "Restore roundtrip: refusing to remove volumes outside an estab_ci project" >&2
        exit 1
        ;;
esac
if [[ ! -d $ESTAB_BACKUP_DIR || $ESTAB_BACKUP_DIR == / ]]; then
    echo "Restore roundtrip: backup directory must be an existing private directory" >&2
    exit 1
fi
chmod 0700 "$ESTAB_BACKUP_DIR"
umask 077

compose_command=("$container_cli" compose)
if [[ -n $compose_file ]]; then
    if [[ ! -f $compose_file || ! -r $compose_file ]]; then
        echo "Restore roundtrip: Compose file is not readable" >&2
        exit 1
    fi
    compose_file=$(CDPATH='' cd -- "$(dirname -- "$compose_file")" && pwd -P)/$(basename -- "$compose_file")
    compose_command+=(-f "$compose_file" -p "$COMPOSE_PROJECT_NAME")
fi

validate_bind_storage() {
    local canonical_root expected_db expected_data expected_export guard_file

    if [[ -z $bind_storage_root || ! -d $bind_storage_root || -L $bind_storage_root ]]; then
        echo "Restore roundtrip: bind storage root must be a real directory" >&2
        return 1
    fi
    canonical_root=$(CDPATH='' cd -- "$bind_storage_root" && pwd -P)
    case "$(basename -- "$canonical_root")" in
        .estab-registry-bind.*) ;;
        *)
            echo "Restore roundtrip: bind storage root lacks the guarded CI prefix" >&2
            return 1
            ;;
    esac
    if [[ $canonical_root == / || $canonical_root == "$repo_root" ]]; then
        echo "Restore roundtrip: refusing a broad bind storage root" >&2
        return 1
    fi

    guard_file=$canonical_root/.estab-ci-bind-storage
    if [[ ! -f $guard_file || -L $guard_file ]] ||
        [[ $(sed -n '1p' "$guard_file") != "$COMPOSE_PROJECT_NAME" ]]; then
        echo "Restore roundtrip: bind storage guard does not match the CI project" >&2
        return 1
    fi

    expected_db=$canonical_root/data/db
    expected_data=$canonical_root/data/4fdata
    expected_export=$canonical_root/data/export
    if [[ ${ESTAB_DB_DATA_SOURCE:-} != "$expected_db" ]] ||
        [[ ${ESTAB_APP_DATA_SOURCE:-} != "$expected_data" ]] ||
        [[ ${ESTAB_EXPORT_DATA_SOURCE:-} != "$expected_export" ]]; then
        echo "Restore roundtrip: bind sources do not match the guarded storage root" >&2
        return 1
    fi
    for storage_directory in "$expected_db" "$expected_data" "$expected_export"; do
        if [[ ! -d $storage_directory || -L $storage_directory ]]; then
            echo "Restore roundtrip: bind source is not a real directory" >&2
            return 1
        fi
    done

    bind_storage_root=$canonical_root
}

if [[ $storage_mode == bind ]]; then
    validate_bind_storage
fi

db_dump=$ESTAB_BACKUP_DIR/database.sql
data_archive=$ESTAB_BACKUP_DIR/4fdata.tar
export_archive=$ESTAB_BACKUP_DIR/export.tar
for backup_file in "$db_dump" "$data_archive" "$export_archive"; do
    if [[ -e $backup_file ]]; then
        echo "Restore roundtrip: refusing to overwrite an existing backup file" >&2
        exit 1
    fi
done

compose() {
    "${compose_command[@]}" "$@"
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
                    echo "Restore roundtrip: ${service} is healthy"
                    return 0
                    ;;
                unhealthy | exited | dead)
                    echo "Restore roundtrip: ${service} entered ${status}" >&2
                    return 1
                    ;;
            esac
        fi
        sleep 3
    done
    echo "Restore roundtrip: ${service} did not become healthy within ${timeout_seconds}s" >&2
    return 1
}

validate_archive() {
    local archive=$1
    local expected_root=$2
    if [[ ! -s $archive ]]; then
        echo "Restore roundtrip: empty archive: $(basename -- "$archive")" >&2
        return 1
    fi
    if ! tar -tf "$archive" | awk -v root="$expected_root" '
        /^\// || /(^|\/)\.\.(\/|$)/ { exit 1 }
        $0 == root || index($0, root "/") == 1 { entries++; next }
        { exit 1 }
        END { if (!entries) exit 1 }
    '; then
        echo "Restore roundtrip: unsafe archive member in $(basename -- "$archive")" >&2
        return 1
    fi
    if ! tar -tvf "$archive" | awk '
        substr($1, 1, 1) == "-" || substr($1, 1, 1) == "d" { entries++; next }
        { exit 1 }
        END { if (!entries) exit 1 }
    '; then
        echo "Restore roundtrip: links or unsupported members in $(basename -- "$archive")" >&2
        return 1
    fi
}

echo "Restore roundtrip: quiescing the application"
run_timed 2m "${compose_command[@]}" stop --timeout 20 app

echo "Restore roundtrip: creating a private logical database dump"
run_timed 5m "${compose_command[@]}" exec -T db sh -ceu '
    umask 077
    client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-backup-client.XXXXXX")
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

    mariadb-dump \
        --defaults-extra-file="$client_defaults" \
        --single-transaction \
        --quick \
        --routines \
        --events \
        --triggers \
        --hex-blob \
        --add-drop-database \
        --databases "$MARIADB_DATABASE"
' >"$db_dump"

echo "Restore roundtrip: archiving 4fdata and export volumes"
run_timed 5m "${compose_command[@]}" run --rm --no-deps -T \
    --entrypoint tar app -C /var/www/html -cpf - 4fdata >"$data_archive"
run_timed 5m "${compose_command[@]}" run --rm --no-deps -T \
    --entrypoint tar app -C /var/lib/estab -cpf - export >"$export_archive"

if [[ ! -s $db_dump ]]; then
    echo "Restore roundtrip: database dump is empty" >&2
    exit 1
fi
validate_archive "$data_archive" 4fdata
validate_archive "$export_archive" export
(
    cd "$ESTAB_BACKUP_DIR"
    sha256sum database.sql 4fdata.tar export.tar >SHA256SUMS
    sha256sum --check --strict SHA256SUMS >/dev/null
)

volumes=()
if [[ $storage_mode == named ]]; then
    volumes=(
        "${COMPOSE_PROJECT_NAME}_estab_db"
        "${COMPOSE_PROJECT_NAME}_estab_data"
        "${COMPOSE_PROJECT_NAME}_estab_export"
        "${COMPOSE_PROJECT_NAME}_estab_auth"
    )
    for volume in "${volumes[@]}"; do
        "$container_cli" volume inspect "$volume" >/dev/null
    done

    echo "Restore roundtrip: deleting only guarded CI containers and named volumes"
    run_timed 3m "${compose_command[@]}" down --volumes --remove-orphans --timeout 20
    for volume in "${volumes[@]}"; do
        if "$container_cli" volume inspect "$volume" >/dev/null 2>&1; then
            echo "Restore roundtrip: CI volume was not deleted: $volume" >&2
            exit 1
        fi
    done
else
    echo "Restore roundtrip: deleting only guarded CI containers and bind-mounted data"
    validate_bind_storage
    run_timed 3m "${compose_command[@]}" down --volumes --remove-orphans --timeout 20

    # These one-shot containers see exactly the three sources validated above.
    # Clearing inside their mount points also works when MariaDB changed the
    # host directory owner to its container UID.
    run_timed 3m "${compose_command[@]}" run --rm --no-deps -T \
        --entrypoint sh db -ceu '
            find /var/lib/mysql -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
            test -z "$(find /var/lib/mysql -mindepth 1 -print -quit)"
        '
    run_timed 3m "${compose_command[@]}" run --rm --no-deps -T \
        --entrypoint sh app -ceu '
            find /var/www/html/4fdata -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
            find /var/lib/estab/export -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
            test -z "$(find /var/www/html/4fdata -mindepth 1 -print -quit)"
            test -z "$(find /var/lib/estab/export -mindepth 1 -print -quit)"
        '
    run_timed 2m "${compose_command[@]}" down --volumes --remove-orphans --timeout 20
    validate_bind_storage
fi

echo "Restore roundtrip: creating fresh database storage"
run_timed 5m "${compose_command[@]}" up --detach db
wait_for_healthy db 180

echo "Restore roundtrip: restoring the logical database dump"
(
    cd "$ESTAB_BACKUP_DIR"
    sha256sum --check --strict SHA256SUMS >/dev/null
)
run_timed 5m "${compose_command[@]}" exec -T db sh -ceu '
    umask 077
    client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-restore-client.XXXXXX")
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

    mariadb --defaults-extra-file="$client_defaults"
' <"$db_dump"

echo "Restore roundtrip: restoring application data volumes"
run_timed 5m "${compose_command[@]}" run --rm --no-deps -T \
    --entrypoint tar app -C /var/www/html -xpf - <"$data_archive"
run_timed 5m "${compose_command[@]}" run --rm --no-deps -T \
    --entrypoint tar app -C /var/lib/estab -xpf - <"$export_archive"
if [[ $storage_mode == named ]]; then
    for volume in "${volumes[@]}"; do
        "$container_cli" volume inspect "$volume" >/dev/null
    done
else
    validate_bind_storage
fi

echo "Restore roundtrip: starting migrator and restored application"
export ESTAB_ALLOW_SELF_REGISTRATION=false
run_timed 5m "${compose_command[@]}" up --detach
wait_for_healthy db 60
wait_for_healthy app 240

echo "Restore roundtrip: backup and restore completed"
