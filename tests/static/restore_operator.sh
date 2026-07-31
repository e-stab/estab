#!/bin/sh

set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
restore_operator=$repo_root/deploy/registry/restore.sh
backup_verifier=$repo_root/deploy/registry/verify-backup.sh
work_dir=$(mktemp -d "${TMPDIR:-/tmp}/estab-restore-operator.XXXXXX")
trap 'rm -rf -- "$work_dir"' EXIT HUP INT TERM

backup_dir=$work_dir/backup-v2
source_data=$work_dir/source-data
source_export=$work_dir/source-export
runtime_data=$work_dir/runtime-data
runtime_export=$work_dir/runtime-export
state_dir=$work_dir/state
project_dir=$work_dir/project
project_dir_two=$work_dir/project-two
mkdir -p \
    "$backup_dir" \
    "$source_data/estab/anhang" \
    "$source_export/run" \
    "$runtime_data/stale" \
    "$runtime_export/stale" \
    "$state_dir" \
    "$project_dir" \
    "$project_dir_two"
printf 'verified attachment\n' >"$source_data/estab/anhang/file.txt"
printf 'verified export\n' >"$source_export/run/export.txt"
printf 'must disappear\n' >"$runtime_data/stale/file.txt"
printf 'must disappear\n' >"$runtime_export/stale/file.txt"

tar -C "$source_data" -czf "$backup_dir/4fdata.tar.gz" .
tar -C "$source_export" -czf "$backup_dir/export.tar.gz" .
cat >"$backup_dir/database.sql" <<'EOF'
-- MariaDB dump 10.19
CREATE DATABASE /*!32312 IF NOT EXISTS*/ `estab`;
USE `estab`;
CREATE TABLE `restore_fixture` (`id` int);
-- Dump completed on 2026-07-31  08:00:00
EOF
printf 'estab-full-backup-v2\n' >"$backup_dir/backup-format.txt"
printf '2026-07-31T08:00:00Z\n' >"$backup_dir/backup-created-utc.txt"
printf 'restoretest\n' >"$backup_dir/project-name.txt"
printf 'estab\n' >"$backup_dir/database-name.txt"
cat >"$backup_dir/storage-sources.txt" <<'EOF'
database	/var/lib/mysql	volume	restoretest_estab_db	/var/lib/containers/storage/volumes/restoretest_estab_db/_data
application	/var/www/html/4fdata	bind	-	/srv/estab/data/4fdata
export	/var/lib/estab/export	bind	-	/srv/estab/data/export
EOF
cat >"$backup_dir/image-references.txt" <<'EOF'
app	ghcr.io/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa	sha256:1111111111111111111111111111111111111111111111111111111111111111
migrate	ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb	sha256:2222222222222222222222222222222222222222222222222222222222222222
database	mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc	sha256:3333333333333333333333333333333333333333333333333333333333333333
EOF
(
    cd "$backup_dir"
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum \
            4fdata.tar.gz \
            backup-created-utc.txt \
            backup-format.txt \
            database-name.txt \
            database.sql \
            export.tar.gz \
            image-references.txt \
            project-name.txt \
            storage-sources.txt \
            >SHA256SUMS
    else
        shasum -a 256 \
            4fdata.tar.gz \
            backup-created-utc.txt \
            backup-format.txt \
            database-name.txt \
            database.sql \
            export.tar.gz \
            image-references.txt \
            project-name.txt \
            storage-sources.txt \
            >SHA256SUMS
    fi
)
sh "$backup_verifier" "$backup_dir" estab >/dev/null

fake_cli=$work_dir/fake-container-cli
cat >"$fake_cli" <<'EOF'
#!/bin/sh
set -eu

printf '%s\n' "$*" >>"$FAKE_STATE/events"

command_name=$1
shift
case "$command_name" in
  compose)
    compose_command=$1
    shift
    case "$compose_command" in
      version)
        printf 'fake compose 1.0\n'
        ;;
      config)
        printf 'name: restoretest\n'
        ;;
      ps)
        service=
        for argument in "$@"; do
          service=$argument
        done
        case "$service" in
          app) printf 'app-container\n' ;;
          admin-auth-init) printf 'admin-auth-container\n' ;;
          db) printf 'db-container\n' ;;
          migrate) printf 'migrate-container\n' ;;
          *) exit 1 ;;
        esac
        ;;
      stop)
        [ "$1" = app ]
        : >"$FAKE_STATE/app-stopped"
        printf 'stop-app\n' >>"$FAKE_STATE/operations"
        ;;
      start)
        case "$1" in
          app)
            rm -f -- "$FAKE_STATE/app-stopped"
            printf 'start-app\n' >>"$FAKE_STATE/operations"
            ;;
          db)
            printf 'start-db\n' >>"$FAKE_STATE/operations"
            ;;
          *)
            exit 1
            ;;
        esac
        ;;
      up)
        case "$*" in
          *migrate*)
            printf 'run-migrate\n' >>"$FAKE_STATE/operations"
            ;;
          *app*)
            rm -f -- "$FAKE_STATE/app-stopped"
            printf 'up-app\n' >>"$FAKE_STATE/operations"
            ;;
          *db*)
            printf 'up-db\n' >>"$FAKE_STATE/operations"
            ;;
          *)
            exit 1
            ;;
        esac
        ;;
      exec)
        case "$*" in
          *MARIADB_DATABASE*)
            printf 'estab\n'
            ;;
          *'mariadb --defaults-extra-file='*)
            cat >"$FAKE_STATE/restored-database.sql"
            printf 'database-import\n' >>"$FAKE_STATE/operations"
            ;;
          *estab-healthcheck*)
            printf 'ready\n'
            ;;
          *)
            exit 1
            ;;
        esac
        ;;
      run)
        case "$*" in
          *'find /var/www/html/4fdata'*)
            find "$FAKE_DATA" -mindepth 1 -maxdepth 1 \
                -exec rm -rf -- {} +
            find "$FAKE_EXPORT" -mindepth 1 -maxdepth 1 \
                -exec rm -rf -- {} +
            printf 'file-volumes-cleared\n' >>"$FAKE_STATE/operations"
            ;;
          *'tar -xzf - -C /var/www/html/4fdata'*)
            case " $* " in
              *' --interactive '*) ;;
              *) exit 44 ;;
            esac
            tar -xzf - -C "$FAKE_DATA"
            printf 'application-data-extracted\n' >>"$FAKE_STATE/operations"
            ;;
          *'tar -xzf - -C /var/lib/estab/export'*)
            case " $* " in
              *' --interactive '*) ;;
              *) exit 44 ;;
            esac
            if [ "${FAKE_FAIL_EXPORT_RESTORE:-0}" = 1 ]; then
              exit 42
            fi
            tar -xzf - -C "$FAKE_EXPORT"
            printf 'export-data-extracted\n' >>"$FAKE_STATE/operations"
            ;;
          *)
            exit 1
            ;;
        esac
        ;;
      *)
        exit 1
        ;;
    esac
    ;;
  start)
    start_container=
    for start_argument in "$@"; do
      start_container=$start_argument
    done
    case "$start_container" in
      app-container)
        rm -f -- "$FAKE_STATE/app-stopped"
        printf 'up-app\n' >>"$FAKE_STATE/operations"
        ;;
      db-container)
        printf 'start-db\n' >>"$FAKE_STATE/operations"
        ;;
      migrate-container)
        printf 'run-migrate\n' >>"$FAKE_STATE/operations"
        ;;
      *)
        exit 1
        ;;
    esac
    ;;
  stop)
    [ "$1" = app-container ]
    : >"$FAKE_STATE/app-stopped"
    printf 'stop-app\n' >>"$FAKE_STATE/operations"
    ;;
  exec)
    case "$*" in
      *MARIADB_DATABASE*)
        printf 'estab\n'
        ;;
      *'mariadb --defaults-extra-file='*)
        cat >"$FAKE_STATE/restored-database.sql"
        printf 'database-import\n' >>"$FAKE_STATE/operations"
        ;;
      *estab-healthcheck*)
        printf 'ready\n'
        ;;
      *)
        exit 1
        ;;
    esac
    ;;
  run)
    case "$*" in
      *'--detach --name estab-maintenance-lock-'*)
        [ ! -e "$FAKE_STATE/maintenance-lock-id" ] || exit 125
        lock_name=
        lock_project=
        lock_operation=
        lock_owner=
        lock_started=
        lock_image=
        while [ "$#" -gt 0 ]; do
          case "$1" in
            --name)
              lock_name=$2
              shift 2
              ;;
            --label)
              case "$2" in
                org.e-stab.compose-project=*) lock_project=${2#*=} ;;
                org.e-stab.maintenance-operation=*) lock_operation=${2#*=} ;;
                org.e-stab.maintenance-owner=*) lock_owner=${2#*=} ;;
                org.e-stab.maintenance-started-utc=*) lock_started=${2#*=} ;;
              esac
              shift 2
              ;;
            --network|--restart|--entrypoint)
              shift 2
              ;;
            --detach)
              shift
              ;;
            sha256:*)
              lock_image=$1
              break
              ;;
            *)
              shift
              ;;
          esac
        done
        lock_id=4444444444444444444444444444444444444444444444444444444444444444
        printf '%s\n' "$lock_id" >"$FAKE_STATE/maintenance-lock-id"
        printf '%s\n' "$lock_name" >"$FAKE_STATE/maintenance-lock-name"
        printf '%s\n' "$lock_project" >"$FAKE_STATE/maintenance-lock-project"
        printf '%s\n' "$lock_operation" >"$FAKE_STATE/maintenance-lock-operation"
        printf '%s\n' "$lock_owner" >"$FAKE_STATE/maintenance-lock-owner"
        printf '%s\n' "$lock_started" >"$FAKE_STATE/maintenance-lock-started"
        printf '%s\n' "$lock_image" >"$FAKE_STATE/maintenance-lock-image"
        printf 'running\n' >"$FAKE_STATE/maintenance-lock-status"
        printf '%s\n' "$lock_id"
        ;;
      *'.estab-restore-write-probe.'*)
        if [ "${FAKE_UNWRITABLE_MOUNTS:-0}" = 1 ]; then
          exit 43
        fi
        printf 'file-write-preflight\n' >>"$FAKE_STATE/operations"
        ;;
      *'find /var/www/html/4fdata'*)
        find "$FAKE_DATA" -mindepth 1 -maxdepth 1 \
            -exec rm -rf -- {} +
        find "$FAKE_EXPORT" -mindepth 1 -maxdepth 1 \
            -exec rm -rf -- {} +
        printf 'file-volumes-cleared\n' >>"$FAKE_STATE/operations"
        ;;
      *'tar -xzf - -C /var/www/html/4fdata'*)
        case " $* " in
          *' --interactive '*) ;;
          *) exit 44 ;;
        esac
        tar -xzf - -C "$FAKE_DATA"
        printf 'application-data-extracted\n' >>"$FAKE_STATE/operations"
        ;;
      *'tar -xzf - -C /var/lib/estab/export'*)
        case " $* " in
          *' --interactive '*) ;;
          *) exit 44 ;;
        esac
        if [ "${FAKE_FAIL_EXPORT_RESTORE:-0}" = 1 ]; then
          exit 42
        fi
        tar -xzf - -C "$FAKE_EXPORT"
        printf 'export-data-extracted\n' >>"$FAKE_STATE/operations"
        ;;
      *)
        exit 1
        ;;
    esac
    ;;
  container)
    [ "$1" = rm ]
    [ "$2" = --force ]
    lock_id=$3
    [ "$lock_id" = "$(cat "$FAKE_STATE/maintenance-lock-id")" ]
    rm -f -- \
      "$FAKE_STATE/maintenance-lock-id" \
      "$FAKE_STATE/maintenance-lock-name" \
      "$FAKE_STATE/maintenance-lock-project" \
      "$FAKE_STATE/maintenance-lock-operation" \
      "$FAKE_STATE/maintenance-lock-owner" \
      "$FAKE_STATE/maintenance-lock-started" \
      "$FAKE_STATE/maintenance-lock-image" \
      "$FAKE_STATE/maintenance-lock-status"
    ;;
  inspect)
    [ "$1" = --format ]
    format=$2
    container=$3
    if [ -e "$FAKE_STATE/maintenance-lock-id" ] &&
      { [ "$container" = "$(cat "$FAKE_STATE/maintenance-lock-id")" ] ||
        [ "$container" = "$(cat "$FAKE_STATE/maintenance-lock-name")" ]; }; then
      case "$format" in
        '{{.Id}}') cat "$FAKE_STATE/maintenance-lock-id" ;;
        '{{.Name}}') cat "$FAKE_STATE/maintenance-lock-name" ;;
        '{{ index .Config.Labels "org.e-stab.maintenance-lock" }}')
          printf 'true\n'
          ;;
        '{{ index .Config.Labels "org.e-stab.compose-project" }}')
          cat "$FAKE_STATE/maintenance-lock-project"
          ;;
        '{{ index .Config.Labels "org.e-stab.maintenance-operation" }}')
          cat "$FAKE_STATE/maintenance-lock-operation"
          ;;
        '{{ index .Config.Labels "org.e-stab.maintenance-owner" }}')
          cat "$FAKE_STATE/maintenance-lock-owner"
          ;;
        '{{ index .Config.Labels "org.e-stab.maintenance-started-utc" }}')
          cat "$FAKE_STATE/maintenance-lock-started"
          ;;
        '{{.State.Status}}') cat "$FAKE_STATE/maintenance-lock-status" ;;
        '{{.State.Running}}')
          [ "$(cat "$FAKE_STATE/maintenance-lock-status")" = running ] &&
            printf 'true\n' || printf 'false\n'
          ;;
        '{{.Image}}') cat "$FAKE_STATE/maintenance-lock-image" ;;
        *) exit 1 ;;
      esac
      exit 0
    fi
    case "$format" in
      '{{.State.Running}}')
        case "$container" in
          app-container)
            if [ -e "$FAKE_STATE/app-stopped" ]; then
              printf 'false\n'
            else
              printf 'true\n'
            fi
            ;;
          db-container) printf 'true\n' ;;
          admin-auth-container) printf 'false\n' ;;
          migrate-container) printf 'false\n' ;;
          *) exit 1 ;;
        esac
        ;;
      '{{.State.ExitCode}}')
        if [ "$container" = admin-auth-container ]; then
          printf '%s\n' "${FAKE_ADMIN_AUTH_EXIT_CODE:-0}"
        else
          printf '%s\n' "${FAKE_MIGRATE_EXIT_CODE:-0}"
        fi
        ;;
      '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}')
        if [ "${FAKE_UNHEALTHY_CONTAINER:-}" = "$container" ]; then
          printf 'unhealthy\n'
        else
          printf 'healthy\n'
        fi
        ;;
      '{{ index .Config.Labels "com.docker.compose.project" }}')
        printf '%s\n' "${FAKE_PROJECT:-restoretest}"
        ;;
      '{{range .Config.Env}}{{println .}}{{end}}')
        [ "$container" = db-container ]
        printf 'MARIADB_DATABASE=%s\n' "${FAKE_DATABASE_NAME:-estab}"
        printf 'TZ=Europe/Berlin\n'
        ;;
      '{{.Config.Image}}')
        case "$container" in
          app-container|admin-auth-container)
            printf 'ghcr.io/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n'
            ;;
          migrate-container)
            printf 'ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\n'
            ;;
          db-container)
            printf 'mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc\n'
            ;;
          *) exit 1 ;;
        esac
        ;;
      '{{.Image}}')
        case "$container" in
          app-container)
            printf '%s\n' "${FAKE_APP_IMAGE_ID:-sha256:1111111111111111111111111111111111111111111111111111111111111111}"
            ;;
          admin-auth-container)
            printf '%s\n' "${FAKE_ADMIN_AUTH_IMAGE_ID:-sha256:1111111111111111111111111111111111111111111111111111111111111111}"
            ;;
          migrate-container)
            printf 'sha256:2222222222222222222222222222222222222222222222222222222222222222\n'
            ;;
          db-container)
            printf 'sha256:3333333333333333333333333333333333333333333333333333333333333333\n'
            ;;
          *) exit 1 ;;
        esac
        ;;
      '{{range .Mounts}}{{printf "%s\t%s\t%s\t%s\n" .Destination .Type .Name .Source}}{{end}}')
        case "$container" in
          app-container)
            printf '/var/www/html/4fdata\tbind\t\t%s\n' \
              "${FAKE_OPERATOR_SOURCE:-/srv/estab/data/4fdata}"
            if [ "${FAKE_OVERLAPPING_SOURCE:-0}" = 1 ]; then
              printf '/var/lib/estab/export\tbind\t\t/srv/estab/data/4fdata/export\n'
            else
              printf '/var/lib/estab/export\tbind\t\t/srv/estab/data/export\n'
            fi
            if [ "${FAKE_NESTED_MOUNT:-0}" = 1 ]; then
              printf '/var/www/html/4fdata/foreign\tbind\t\t/srv/foreign\n'
            fi
            ;;
          db-container)
            printf '/var/lib/mysql\tvolume\trestoretest_estab_db\t/var/lib/containers/storage/volumes/restoretest_estab_db/_data\n'
            ;;
          *) exit 1 ;;
        esac
        ;;
      '{{range .Mounts}}{{printf "%s\t%t\n" .Destination .RW}}{{end}}')
        [ "$container" = app-container ]
        if [ "${FAKE_READ_ONLY_MOUNT:-}" = 4fdata ]; then
          printf '/var/www/html/4fdata\tfalse\n'
        else
          printf '/var/www/html/4fdata\ttrue\n'
        fi
        if [ "${FAKE_READ_ONLY_MOUNT:-}" = export ]; then
          printf '/var/lib/estab/export\tfalse\n'
        else
          printf '/var/lib/estab/export\ttrue\n'
        fi
        ;;
      *)
        exit 1
        ;;
    esac
    ;;
  *)
    exit 1
    ;;
esac
EOF
chmod 0700 "$fake_cli"

run_restore()
{
    (
        cd "$project_dir"
        ESTAB_CONTAINER_CLI=$fake_cli \
        FAKE_STATE=$state_dir \
        FAKE_DATA=$runtime_data \
        FAKE_EXPORT=$runtime_export \
            sh "$restore_operator" "$@"
    )
}

run_restore_two()
{
    (
        cd "$project_dir_two"
        ESTAB_CONTAINER_CLI=$fake_cli \
        FAKE_STATE=$state_dir \
        FAKE_DATA=$runtime_data \
        FAKE_EXPORT=$runtime_export \
            sh "$restore_operator" "$@"
    )
}

operation_count()
{
    if [ -f "$state_dir/operations" ]; then
        wc -l <"$state_dir/operations" | tr -d ' '
    else
        printf '0\n'
    fi
}

database_operation_count()
{
    if [ -f "$state_dir/operations" ]; then
        LC_ALL=C awk '
          $0 == "start-db" || $0 == "database-import" {
            count++
          }
          END {
            print count + 0
          }
        ' "$state_dir/operations"
    else
        printf '0\n'
    fi
}

if run_restore --confirm-project restoretest relative/backup \
    >/dev/null 2>&1; then
    printf 'Restore operator test: relative backup directory accepted\n' >&2
    exit 1
fi
if run_restore --confirm-project wrong-project "$backup_dir" \
    >/dev/null 2>&1; then
    printf 'Restore operator test: wrong explicit project confirmation accepted\n' >&2
    exit 1
fi
if FAKE_PROJECT=other run_restore --confirm-project restoretest "$backup_dir" \
    >/dev/null 2>&1; then
    printf 'Restore operator test: runtime project mismatch accepted\n' >&2
    exit 1
fi
unset FAKE_PROJECT
if FAKE_APP_IMAGE_ID=sha256:9999999999999999999999999999999999999999999999999999999999999999 \
    run_restore --confirm-project restoretest "$backup_dir" \
    >/dev/null 2>&1; then
    printf 'Restore operator test: runtime image mismatch accepted\n' >&2
    exit 1
fi
unset FAKE_APP_IMAGE_ID
if FAKE_DATABASE_NAME=other \
    run_restore --confirm-project restoretest "$backup_dir" \
    >/dev/null 2>&1; then
    printf 'Restore operator test: configured database drift accepted\n' >&2
    exit 1
fi
unset FAKE_DATABASE_NAME
if [ -e "$state_dir/operations" ]; then
    ! grep -Fq 'start-db' "$state_dir/operations"
    ! grep -Fq 'database-import' "$state_dir/operations"
fi

preflight_operations_before=$(operation_count)
preflight_database_before=$(database_operation_count)
if FAKE_READ_ONLY_MOUNT=4fdata \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/read-only.stdout" 2>"$work_dir/read-only.stderr"; then
    printf 'Restore operator test: read-only application mount was accepted\n' >&2
    exit 1
fi
unset FAKE_READ_ONLY_MOUNT
grep -Fq 'not explicitly read/write' "$work_dir/read-only.stderr"
[ "$(operation_count)" = "$preflight_operations_before" ]
[ "$(database_operation_count)" = "$preflight_database_before" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

if FAKE_ADMIN_AUTH_EXIT_CODE=42 \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/admin-init.stdout" 2>"$work_dir/admin-init.stderr"; then
    printf 'Restore operator test: failed admin authentication initializer was accepted\n' >&2
    exit 1
fi
unset FAKE_ADMIN_AUTH_EXIT_CODE
grep -Fq 'admin authentication initializer did not complete successfully' \
    "$work_dir/admin-init.stderr"
[ "$(operation_count)" = "$preflight_operations_before" ]
[ "$(database_operation_count)" = "$preflight_database_before" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

if FAKE_ADMIN_AUTH_IMAGE_ID=sha256:9999999999999999999999999999999999999999999999999999999999999999 \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/admin-image.stdout" 2>"$work_dir/admin-image.stderr"; then
    printf 'Restore operator test: wrong admin authentication image was accepted\n' >&2
    exit 1
fi
unset FAKE_ADMIN_AUTH_IMAGE_ID
grep -Fq 'admin authentication initializer does not use the verified app image' \
    "$work_dir/admin-image.stderr"
[ "$(operation_count)" = "$preflight_operations_before" ]
[ "$(database_operation_count)" = "$preflight_database_before" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

if FAKE_UNWRITABLE_MOUNTS=1 \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/unwritable.stdout" 2>"$work_dir/unwritable.stderr"; then
    printf 'Restore operator test: unwritable file mounts passed the write probe\n' >&2
    exit 1
fi
unset FAKE_UNWRITABLE_MOUNTS
grep -Fq 'failed the create/write/delete preflight' \
    "$work_dir/unwritable.stderr"
[ "$(database_operation_count)" = "$preflight_database_before" ]
[ ! -e "$state_dir/app-stopped" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

nested_operations_before=$(operation_count)
if FAKE_NESTED_MOUNT=1 \
    run_restore --confirm-project restoretest "$backup_dir" \
    >/dev/null 2>&1; then
    printf 'Restore operator test: nested application mount was accepted\n' >&2
    exit 1
fi
unset FAKE_NESTED_MOUNT
overlap_operations_before=$(operation_count)
[ "$nested_operations_before" = "$overlap_operations_before" ]
if FAKE_OVERLAPPING_SOURCE=1 \
    run_restore --confirm-project restoretest "$backup_dir" \
    >/dev/null 2>&1; then
    printf 'Restore operator test: overlapping host storage sources were accepted\n' >&2
    exit 1
fi
unset FAKE_OVERLAPPING_SOURCE
overlap_operations_after=$(operation_count)
[ "$overlap_operations_before" = "$overlap_operations_after" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

operator_source=$(CDPATH= cd -- "$backup_dir" && pwd -P)
if FAKE_OPERATOR_SOURCE="$operator_source" \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/operator-overlap.stdout" \
    2>"$work_dir/operator-overlap.stderr"; then
    printf 'Restore operator test: backup directory inside productive storage was accepted\n' >&2
    exit 1
fi
unset FAKE_OPERATOR_SOURCE
grep -Fq 'backup directory overlaps a productive storage source' \
    "$work_dir/operator-overlap.stderr"
[ "$(operation_count)" = "$overlap_operations_after" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

lock_id=5555555555555555555555555555555555555555555555555555555555555555
printf '%s\n' "$lock_id" >"$state_dir/maintenance-lock-id"
printf 'estab-maintenance-lock-restoretest\n' >"$state_dir/maintenance-lock-name"
printf 'restoretest\n' >"$state_dir/maintenance-lock-project"
printf 'backup\n' >"$state_dir/maintenance-lock-operation"
printf 'backup-999999-stale\n' >"$state_dir/maintenance-lock-owner"
printf '2026-07-31T08:00:00Z\n' >"$state_dir/maintenance-lock-started"
printf 'sha256:1111111111111111111111111111111111111111111111111111111111111111\n' \
    >"$state_dir/maintenance-lock-image"
printf 'exited\n' >"$state_dir/maintenance-lock-status"
if run_restore_two --confirm-project restoretest "$backup_dir" \
    >"$work_dir/maintenance.stdout" 2>"$work_dir/maintenance.stderr"; then
    printf 'Restore operator test: concurrent backup maintenance lock ignored\n' >&2
    exit 1
fi
grep -Fq 'operation=backup' "$work_dir/maintenance.stderr" || {
    cat "$work_dir/maintenance.stderr" >&2
    exit 1
}
grep -Fqx "$lock_id" "$state_dir/maintenance-lock-id"
rm -f -- \
    "$state_dir/maintenance-lock-id" \
    "$state_dir/maintenance-lock-name" \
    "$state_dir/maintenance-lock-project" \
    "$state_dir/maintenance-lock-operation" \
    "$state_dir/maintenance-lock-owner" \
    "$state_dir/maintenance-lock-started" \
    "$state_dir/maintenance-lock-image" \
    "$state_dir/maintenance-lock-status"
if [ -e "$state_dir/operations" ]; then
    ! grep -Fq 'start-db' "$state_dir/operations"
    ! grep -Fq 'database-import' "$state_dir/operations"
fi

failure_log=$work_dir/failure.log
if FAKE_FAIL_EXPORT_RESTORE=1 \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/failure.out" 2>"$failure_log"; then
    printf 'Restore operator test: partial file restore reported success\n' >&2
    exit 1
fi
unset FAKE_FAIL_EXPORT_RESTORE
grep -Fq 'RECOVERY REQUIRED for project restoretest' "$failure_log" || {
    cat "$failure_log" >&2
    exit 1
}
grep -Fq 'stage=export-data-extract' "$failure_log" || {
    cat "$failure_log" >&2
    exit 1
}
[ -e "$state_dir/app-stopped" ]
[ -e "$state_dir/maintenance-lock-id" ]
grep -Fq 'retained fail-closed maintenance lock' "$failure_log"
grep -Fq 'database-import' "$state_dir/operations"
grep -Fq 'file-volumes-cleared' "$state_dir/operations"

retained_lock_id=$(cat "$state_dir/maintenance-lock-id")
ESTAB_CONTAINER_CLI=$fake_cli \
FAKE_STATE=$state_dir \
    "$fake_cli" container rm --force "$retained_lock_id"
[ ! -e "$state_dir/maintenance-lock-id" ]

run_restore --confirm-project restoretest "$backup_dir" >/dev/null
[ ! -e "$state_dir/app-stopped" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
grep -Fq -- '-- Dump completed on ' "$state_dir/restored-database.sql"
cmp "$source_data/estab/anhang/file.txt" \
    "$runtime_data/estab/anhang/file.txt"
cmp "$source_export/run/export.txt" \
    "$runtime_export/run/export.txt"
[ ! -e "$runtime_data/stale" ]
[ ! -e "$runtime_export/stale" ]
grep -Fq 'run-migrate' "$state_dir/operations"
grep -Fq 'up-app' "$state_dir/operations"

printf 'Restore operator tests: OK\n'
