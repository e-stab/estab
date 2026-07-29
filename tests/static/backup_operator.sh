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
mkdir -p "$fixture_data/nested" "$fixture_export" "$state_dir" "$output_parent"
output_parent=$(CDPATH= cd -- "$output_parent" && pwd -P)
printf 'attachment fixture\n' >"$fixture_data/nested/attachment.txt"
printf 'export fixture\n' >"$fixture_export/export.txt"

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
  inspect)
    [ "$1" = --format ]
    format=$2
    container=$3
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
            printf '/var/www/html/4fdata\tbind\t\t/srv/estab/data/4fdata\n'
            printf '/var/lib/estab/export\tbind\t\t/srv/estab/data/export\n'
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
done
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

printf 'Backup operator tests: OK\n'
