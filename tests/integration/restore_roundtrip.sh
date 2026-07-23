#!/usr/bin/env bash

set -Eeuo pipefail

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

: "${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME is required}"
: "${ESTAB_BACKUP_DIR:?ESTAB_BACKUP_DIR is required}"
container_cli=${ESTAB_CONTAINER_CLI:-docker}
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
run_timed 2m "$container_cli" compose stop --timeout 20 app

echo "Restore roundtrip: creating a private logical database dump"
run_timed 5m "$container_cli" compose exec -T db sh -ceu '
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
run_timed 5m "$container_cli" compose run --rm --no-deps -T \
    --entrypoint tar app -C /var/www/html -cpf - 4fdata >"$data_archive"
run_timed 5m "$container_cli" compose run --rm --no-deps -T \
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

volumes=(
    "${COMPOSE_PROJECT_NAME}_estab_db"
    "${COMPOSE_PROJECT_NAME}_estab_data"
    "${COMPOSE_PROJECT_NAME}_estab_export"
)
for volume in "${volumes[@]}"; do
    "$container_cli" volume inspect "$volume" >/dev/null
done

echo "Restore roundtrip: deleting only guarded CI containers and volumes"
run_timed 3m "$container_cli" compose down --volumes --remove-orphans --timeout 20
for volume in "${volumes[@]}"; do
    if "$container_cli" volume inspect "$volume" >/dev/null 2>&1; then
        echo "Restore roundtrip: CI volume was not deleted: $volume" >&2
        exit 1
    fi
done

echo "Restore roundtrip: creating a fresh database volume"
run_timed 5m "$container_cli" compose up --detach db
wait_for_healthy db 180

echo "Restore roundtrip: restoring the logical database dump"
(
    cd "$ESTAB_BACKUP_DIR"
    sha256sum --check --strict SHA256SUMS >/dev/null
)
run_timed 5m "$container_cli" compose exec -T db sh -ceu '
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
run_timed 5m "$container_cli" compose run --rm --no-deps -T \
    --entrypoint tar app -C /var/www/html -xpf - <"$data_archive"
run_timed 5m "$container_cli" compose run --rm --no-deps -T \
    --entrypoint tar app -C /var/lib/estab -xpf - <"$export_archive"
for volume in "${volumes[@]}"; do
    "$container_cli" volume inspect "$volume" >/dev/null
done

echo "Restore roundtrip: starting migrator and restored application"
export ESTAB_ALLOW_SELF_REGISTRATION=false
run_timed 5m "$container_cli" compose up --detach
wait_for_healthy db 60
wait_for_healthy app 240

echo "Restore roundtrip: backup and restore completed"
