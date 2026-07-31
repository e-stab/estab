#!/bin/sh

set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
backup_operator=$repo_root/deploy/registry/backup.sh
backup_verifier=$repo_root/deploy/registry/verify-backup.sh
work_dir=$(mktemp -d "${TMPDIR:-/tmp}/estab-backup-operator.XXXXXX")
trap 'rm -rf -- "$work_dir"' EXIT HUP INT TERM

fixture_data=$work_dir/fixture-data
fixture_export=$work_dir/fixture-export
state_dir=$work_dir/state
output_parent=$work_dir/backups
project_dir=$work_dir/project
project_dir_two=$work_dir/project-two
mkdir -p \
    "$fixture_data/nested" \
    "$fixture_export" \
    "$state_dir" \
    "$output_parent" \
    "$project_dir" \
    "$project_dir_two"
output_parent=$(CDPATH= cd -- "$output_parent" && pwd -P)
project_dir=$(CDPATH= cd -- "$project_dir" && pwd -P)
project_dir_two=$(CDPATH= cd -- "$project_dir_two" && pwd -P)
printf 'attachment fixture\n' >"$fixture_data/nested/attachment.txt"
printf 'export fixture\n' >"$fixture_export/export.txt"
cd "$project_dir"

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
      ps)
        service=
        for argument in "$@"; do
          service=$argument
        done
        case "$service" in
          app) printf 'app-container\n' ;;
          db) printf 'db-container\n' ;;
          migrate) printf 'migrate-container\n' ;;
          *) exit 1 ;;
        esac
        ;;
      stop)
        [ "$1" = app ]
        printf 'stopped\n' >>"$FAKE_STATE/app-events"
        ;;
      start)
        [ "$1" = app ]
        printf 'started\n' >>"$FAKE_STATE/app-events"
        ;;
      exec)
        case "$*" in
          *mariadb-dump*)
            cat <<'DUMP'
-- MariaDB dump 10.19
CREATE DATABASE /*!32312 IF NOT EXISTS*/ `estab`;
USE `estab`;
CREATE TABLE `fixture` (`id` int);
-- Dump completed on 2026-07-29  02:00:00
DUMP
            ;;
          *MARIADB_DATABASE*)
            printf 'estab\n'
            ;;
          *)
            exit 1
            ;;
        esac
        ;;
      run)
        case "$*" in
          */var/www/html/4fdata*)
            tar -C "$FAKE_DATA" -czf - .
            ;;
          */var/lib/estab/export*)
            if [ "${FAKE_FAIL_EXPORT:-0}" = 1 ]; then
              exit 42
            fi
            tar -C "$FAKE_EXPORT" -czf - .
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
    [ "$1" = app-container ]
    printf 'started\n' >>"$FAKE_STATE/app-events"
    ;;
  stop)
    [ "$1" = app-container ]
    printf 'stopped\n' >>"$FAKE_STATE/app-events"
    ;;
  exec)
    case "$*" in
      *mariadb-dump*)
        cat <<'DUMP'
-- MariaDB dump 10.19
CREATE DATABASE /*!32312 IF NOT EXISTS*/ `estab`;
USE `estab`;
CREATE TABLE `fixture` (`id` int);
-- Dump completed on 2026-07-29  02:00:00
DUMP
        ;;
      *MARIADB_DATABASE*)
        printf 'estab\n'
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
      */var/www/html/4fdata*)
        tar -C "$FAKE_DATA" -czf - .
        ;;
      */var/lib/estab/export*)
        if [ "${FAKE_FAIL_EXPORT:-0}" = 1 ]; then
          exit 42
        fi
        tar -C "$FAKE_EXPORT" -czf - .
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
        if [ "$container" = migrate-container ]; then
          printf 'false\n'
        else
          printf 'true\n'
        fi
        ;;
      '{{.State.ExitCode}}')
        printf '0\n'
        ;;
      '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}')
        printf '%s\n' "$container" >>"$FAKE_STATE/health-events"
        if [ "${FAKE_UNHEALTHY_CONTAINER:-}" = "$container" ]; then
          printf 'unhealthy\n'
        else
          printf 'healthy\n'
        fi
        ;;
      '{{ index .Config.Labels "com.docker.compose.project" }}')
        printf 'estabtest\n'
        ;;
      '{{.Config.Image}}')
        case "$container" in
          app-container)
            printf 'ghcr.io/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n'
            ;;
          migrate-container)
            printf '<no value>\n'
            ;;
          db-container)
            printf 'mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc\n'
            ;;
          *) exit 1 ;;
        esac
        ;;
      '{{.ImageName}}')
        if [ "$container" = migrate-container ]; then
          printf 'ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\n'
        else
          exit 1
        fi
        ;;
      '{{.Image}}')
        case "$container" in
          app-container)
            printf '%s\n' "${FAKE_APP_IMAGE_ID:-sha256:1111111111111111111111111111111111111111111111111111111111111111}"
            ;;
          migrate-container)
            printf '%s\n' "${FAKE_MIGRATE_IMAGE_ID:-2222222222222222222222222222222222222222222222222222222222222222}"
            ;;
          db-container)
            printf '%s\n' "${FAKE_DB_IMAGE_ID:-sha256:3333333333333333333333333333333333333333333333333333333333333333}"
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
            printf '/var/lib/mysql\tvolume\testabtest_estab_db\t/var/lib/containers/storage/volumes/estabtest_estab_db/_data\n'
            ;;
          *) exit 1 ;;
        esac
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

real_mv=$(command -v mv)
fake_move=$work_dir/fake-mv
cat >"$fake_move" <<'EOF'
#!/bin/sh
set -eu
[ "$#" -eq 2 ]
source_directory=$1
target_directory=$2
if [ -e "$target_directory" ] || [ -L "$target_directory" ]; then
    exit 1
fi
exec "$FAKE_REAL_MV" "$source_directory" "$target_directory"
EOF
chmod 0700 "$fake_move"

if sh "$backup_operator" relative/backup >/dev/null 2>&1; then
    printf 'Backup operator test: relative target accepted\n' >&2
    exit 1
fi
if sh "$backup_operator" "$output_parent/unsafe target" >/dev/null 2>&1; then
    printf 'Backup operator test: unsafe target name accepted\n' >&2
    exit 1
fi

existing_target=$output_parent/existing
mkdir "$existing_target"
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    sh "$backup_operator" "$existing_target" >/dev/null 2>&1; then
    printf 'Backup operator test: existing target accepted\n' >&2
    exit 1
fi

lock_id=5555555555555555555555555555555555555555555555555555555555555555
printf '%s\n' "$lock_id" >"$state_dir/maintenance-lock-id"
printf 'estab-maintenance-lock-estabtest\n' >"$state_dir/maintenance-lock-name"
printf 'estabtest\n' >"$state_dir/maintenance-lock-project"
printf 'restore\n' >"$state_dir/maintenance-lock-operation"
printf 'restore-999999-stale\n' >"$state_dir/maintenance-lock-owner"
printf '2026-07-31T08:00:00Z\n' >"$state_dir/maintenance-lock-started"
printf 'sha256:1111111111111111111111111111111111111111111111111111111111111111\n' \
    >"$state_dir/maintenance-lock-image"
printf 'exited\n' >"$state_dir/maintenance-lock-status"
maintenance_target=$output_parent/backup-concurrent-restore
if (
    cd "$project_dir_two"
    ESTAB_CONTAINER_CLI="$fake_cli" \
        ESTAB_MOVE_CLI="$fake_move" \
        FAKE_REAL_MV="$real_mv" \
        FAKE_STATE="$state_dir" \
        FAKE_DATA="$fixture_data" \
        FAKE_EXPORT="$fixture_export" \
        sh "$backup_operator" "$maintenance_target"
) >"$work_dir/maintenance.stdout" 2>"$work_dir/maintenance.stderr"; then
    printf 'Backup operator test: concurrent restore maintenance lock ignored\n' >&2
    exit 1
fi
[ ! -e "$maintenance_target" ]
[ ! -e "$output_parent/.estab-backup.lock" ]
grep -Fq 'operation=restore' "$work_dir/maintenance.stderr"
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

stale_lock=$output_parent/.estab-backup.lock
mkdir "$stale_lock"
printf 'pid=999999\ntarget=stale-test\n' >"$stale_lock/owner.txt"
stale_target=$output_parent/backup-stale-lock
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    sh "$backup_operator" "$stale_target" >/dev/null 2>&1; then
    printf 'Backup operator test: stale publication lock was ignored\n' >&2
    exit 1
fi
[ ! -e "$stale_target" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
grep -Fqx 'pid=999999' "$stale_lock/owner.txt"
rm -f -- "$stale_lock/owner.txt"
rmdir -- "$stale_lock"

unhealthy_target=$output_parent/backup-unhealthy
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    FAKE_UNHEALTHY_CONTAINER=db-container \
    sh "$backup_operator" "$unhealthy_target" >/dev/null 2>&1; then
    printf 'Backup operator test: unhealthy database was accepted\n' >&2
    exit 1
fi
[ ! -e "$unhealthy_target" ]
[ ! -e "$output_parent/.estab-backup.lock" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
if [ -e "$state_dir/app-events" ]; then
    ! grep -q '^stopped$' "$state_dir/app-events"
fi

invalid_image_number=0
for invalid_image_id in \
    SHA256:1111111111111111111111111111111111111111111111111111111111111111 \
    111111111111111111111111111111111111111111111111111111111111111
do
    invalid_image_number=$((invalid_image_number + 1))
    invalid_image_target=$output_parent/backup-invalid-image-$invalid_image_number
    if ESTAB_CONTAINER_CLI="$fake_cli" \
        ESTAB_MOVE_CLI="$fake_move" \
        FAKE_REAL_MV="$real_mv" \
        FAKE_STATE="$state_dir" \
        FAKE_DATA="$fixture_data" \
        FAKE_EXPORT="$fixture_export" \
        FAKE_APP_IMAGE_ID="$invalid_image_id" \
        sh "$backup_operator" "$invalid_image_target" >/dev/null 2>&1; then
        printf 'Backup operator test: invalid runtime image ID was accepted\n' >&2
        exit 1
    fi
    [ ! -e "$invalid_image_target" ]
    [ ! -e "$output_parent/.estab-backup.lock" ]
    [ ! -e "$state_dir/maintenance-lock-id" ]
done
if [ -e "$state_dir/app-events" ]; then
    ! grep -q '^stopped$' "$state_dir/app-events"
fi

nested_mount_target=$output_parent/backup-nested-mount
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    FAKE_NESTED_MOUNT=1 \
    sh "$backup_operator" "$nested_mount_target" >/dev/null 2>&1; then
    printf 'Backup operator test: nested application mount was accepted\n' >&2
    exit 1
fi
[ ! -e "$nested_mount_target" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

overlapping_source_target=$output_parent/backup-overlapping-source
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    FAKE_OVERLAPPING_SOURCE=1 \
    sh "$backup_operator" "$overlapping_source_target" >/dev/null 2>&1; then
    printf 'Backup operator test: overlapping host storage sources were accepted\n' >&2
    exit 1
fi
[ ! -e "$overlapping_source_target" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

operator_overlap_target=$output_parent/backup-operator-overlap
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    FAKE_OPERATOR_SOURCE="$output_parent" \
    sh "$backup_operator" "$operator_overlap_target" \
    >"$work_dir/operator-overlap.stdout" \
    2>"$work_dir/operator-overlap.stderr"; then
    printf 'Backup operator test: backup parent inside productive storage was accepted\n' >&2
    exit 1
fi
grep -Fq 'backup parent overlaps a productive storage source' \
    "$work_dir/operator-overlap.stderr"
[ ! -e "$operator_overlap_target" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
if [ -e "$state_dir/app-events" ]; then
    ! grep -q '^stopped$' "$state_dir/app-events"
fi

successful_target=$output_parent/backup-20260729-020000
ESTAB_CONTAINER_CLI="$fake_cli" \
ESTAB_MOVE_CLI="$fake_move" \
FAKE_REAL_MV="$real_mv" \
FAKE_STATE="$state_dir" \
FAKE_DATA="$fixture_data" \
FAKE_EXPORT="$fixture_export" \
    sh "$backup_operator" "$successful_target" >/dev/null

[ -d "$successful_target" ] ||
    {
        printf 'Backup operator test: final directory was not published\n' >&2
        exit 1
    }
[ "$(wc -l <"$successful_target/SHA256SUMS" | tr -d ' ')" = 9 ] ||
    {
        printf 'Backup operator test: v2 manifest does not bind every expected file\n' >&2
        exit 1
    }
sh "$backup_verifier" "$successful_target" estab >/dev/null
grep -Fqx 'estab-full-backup-v2' "$successful_target/backup-format.txt"
grep -Fqx 'estabtest' "$successful_target/project-name.txt"
grep -Fq 'application	/var/www/html/4fdata	bind	-	/srv/estab/data/4fdata' \
    "$successful_target/storage-sources.txt"
expected_app_image_record=$(printf 'app\t%s\t%s' \
    'ghcr.io/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    'sha256:1111111111111111111111111111111111111111111111111111111111111111')
grep -Fqx "$expected_app_image_record" "$successful_target/image-references.txt"
expected_migrate_image_record=$(printf 'migrate\t%s\t%s' \
    'ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' \
    'sha256:2222222222222222222222222222222222222222222222222222222222222222')
grep -Fqx "$expected_migrate_image_record" "$successful_target/image-references.txt"
[ "$(grep -c '^stopped$' "$state_dir/app-events")" -eq 1 ]
[ "$(grep -c '^started$' "$state_dir/app-events")" -eq 1 ]
[ "$(grep -c '^app-container$' "$state_dir/health-events")" -ge 3 ]
[ "$(grep -c '^db-container$' "$state_dir/health-events")" -ge 2 ]
[ ! -e "$output_parent/.estab-backup.lock" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

failure_target=$output_parent/backup-failure
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    FAKE_FAIL_EXPORT=1 \
    sh "$backup_operator" "$failure_target" >/dev/null 2>&1; then
    printf 'Backup operator test: simulated archive failure reported success\n' >&2
    exit 1
fi
[ ! -e "$failure_target" ] ||
    {
        printf 'Backup operator test: failed backup published a final directory\n' >&2
        exit 1
    }
if find "$output_parent" -maxdepth 1 \
    -name '.backup-failure.incomplete.*' -print |
    grep -q .; then
    printf 'Backup operator test: failed backup left staging data behind\n' >&2
    exit 1
fi
[ "$(grep -c '^stopped$' "$state_dir/app-events")" -eq 2 ]
[ "$(grep -c '^started$' "$state_dir/app-events")" -eq 2 ]
[ "$(grep -c '^app-container$' "$state_dir/health-events")" -ge 5 ]
[ ! -e "$output_parent/.estab-backup.lock" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

printf 'Backup operator tests: OK\n'
