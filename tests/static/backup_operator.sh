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
chmod 0700 "$output_parent"
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

app_id=6666666666666666666666666666666666666666666666666666666666666666
db_id=7777777777777777777777777777777777777777777777777777777777777777
migrate_id=8888888888888888888888888888888888888888888888888888888888888888
foreign_id=9999999999999999999999999999999999999999999999999999999999999999
transient_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa

emit_provider_id()
{
  case "${FAKE_PROVIDER_IDS:-full}" in
    full)
      printf '%s\n' "$1"
      ;;
    short)
      printf '%.12s\n' "$1"
      ;;
    *)
      exit 98
      ;;
  esac
}

require_helper_volumes_from()
{
  volumes_from_value=
  previous_argument=
  for helper_argument in "$@"; do
    if [ "$previous_argument" = --volumes-from ]; then
      volumes_from_value=$helper_argument
    fi
    previous_argument=$helper_argument
  done
  [ "$volumes_from_value" = "${app_id}:z" ]
}

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
        printf 'name: estabtest\nservices: {}\n'
        ;;
      ps)
        : >"$FAKE_STATE/forbidden-compose-ps"
        exit 97
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
    [ "$1" = app-container ] || [ "$1" = "$app_id" ]
    rm -f -- \
      "$FAKE_STATE/app-stopped" \
      "$FAKE_STATE/app-stop-check-count"
    printf 'started\n' >>"$FAKE_STATE/app-events"
    ;;
  stop)
    [ "$1" = app-container ] || [ "$1" = "$app_id" ]
    : >"$FAKE_STATE/app-stopped"
    rm -f -- "$FAKE_STATE/app-stop-check-count"
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
        require_helper_volumes_from "$@"
        tar -C "$FAKE_DATA" -czf - .
        ;;
      */var/lib/estab/export*)
        require_helper_volumes_from "$@"
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
  ps)
    if [ "$#" -eq 2 ] &&
      [ "$1" = --all ] &&
      [ "$2" = --quiet ]; then
      emit_provider_id "$app_id"
      emit_provider_id "$db_id"
      emit_provider_id "$migrate_id"
      if [ -e "$FAKE_STATE/maintenance-lock-id" ]; then
        emit_provider_id "$(cat "$FAKE_STATE/maintenance-lock-id")"
      fi
      if [ -n "${FAKE_FOREIGN_MOUNT_SOURCE:-}" ] ||
        [ "${FAKE_FOREIGN_MALFORMED:-0}" = 1 ]; then
        emit_provider_id "$foreign_id"
      fi
      if [ "${FAKE_INVENTORY_CHANGE:-0}" = 1 ]; then
        inventory_count=0
        if [ -f "$FAKE_STATE/inventory-count" ]; then
          inventory_count=$(cat "$FAKE_STATE/inventory-count")
        fi
        inventory_count=$((inventory_count + 1))
        printf '%s\n' "$inventory_count" >"$FAKE_STATE/inventory-count"
        if [ $((inventory_count % 2)) -eq 0 ]; then
          emit_provider_id "$transient_id"
        fi
      fi
      exit 0
    fi
    [ "$#" -eq 8 ]
    [ "$1" = --all ]
    [ "$2" = --no-trunc ]
    [ "$3" = --filter ]
    [ "$4" = label=com.docker.compose.project=estabtest ]
    [ "$5" = --filter ]
    case "$6" in
      label=com.docker.compose.service=*) ;;
      *) exit 1 ;;
    esac
    [ "$7" = --format ]
    [ "$8" = '{{.ID}}' ]
    service=${6#label=com.docker.compose.service=}
    printf '%s\n' "${0##*/}" >>"$FAKE_STATE/provider-events"
    case "$service" in
      app) service_id=$app_id ;;
      db) service_id=$db_id ;;
      migrate) service_id=$migrate_id ;;
      *) exit 1 ;;
    esac
    emit_provider_id "$service_id"
    if [ "${FAKE_DUPLICATE_SERVICE:-}" = "$service" ]; then
      emit_provider_id "$service_id"
    fi
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
        [ "$container" = "$(cat "$FAKE_STATE/maintenance-lock-name")" ] ||
        { [ "${FAKE_PROVIDER_IDS:-full}" = short ] &&
          [ "$container" = 444444444444 ]; }; }; then
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
    case "$container" in
      app-container|666666666666|"$app_id") container=app-container ;;
      db-container|777777777777|"$db_id") container=db-container ;;
      migrate-container|888888888888|"$migrate_id") container=migrate-container ;;
      foreign-container|999999999999|"$foreign_id")
        case "$format" in
          '{{.Id}}') printf '%s\n' "$foreign_id" ;;
          '{{ index .Config.Labels "com.docker.compose.project" }}')
            printf '%s\n' "${FAKE_FOREIGN_PROJECT:-foreign-project}"
            ;;
          '{{range .Mounts}}{{printf "%s\t%s\n" .Type .Source}}{{end}}')
            if [ "${FAKE_FOREIGN_MALFORMED:-0}" = 1 ]; then
              printf 'bind\t/srv/foreign\textra-field\n'
            else
              printf 'bind\t%s\n' "$FAKE_FOREIGN_MOUNT_SOURCE"
            fi
            ;;
          *) exit 1 ;;
        esac
        exit 0
        ;;
      transient-container|aaaaaaaaaaaa|"$transient_id")
        case "$format" in
          '{{.Id}}') printf '%s\n' "$transient_id" ;;
          '{{range .Mounts}}{{printf "%s\t%s\n" .Type .Source}}{{end}}')
            ;;
          *) exit 1 ;;
        esac
        exit 0
        ;;
    esac
    case "$format" in
      '{{.Id}}')
        case "$container" in
          app-container) printf '%s\n' "$app_id" ;;
          db-container) printf '%s\n' "$db_id" ;;
          migrate-container) printf '%s\n' "$migrate_id" ;;
          *) exit 1 ;;
        esac
        ;;
      '{{.State.Running}}')
        if [ "$container" = migrate-container ]; then
          printf 'false\n'
        elif [ "$container" = app-container ] &&
          [ -e "$FAKE_STATE/app-stopped" ]; then
          stop_check_count=0
          if [ -f "$FAKE_STATE/app-stop-check-count" ]; then
            stop_check_count=$(cat "$FAKE_STATE/app-stop-check-count")
          fi
          stop_check_count=$((stop_check_count + 1))
          printf '%s\n' "$stop_check_count" \
            >"$FAKE_STATE/app-stop-check-count"
          if [ "${FAKE_RESTART_AT_STOP_CHECK:-0}" -eq \
            "$stop_check_count" ]; then
            rm -f -- "$FAKE_STATE/app-stopped"
            printf 'true\n'
          else
            printf 'false\n'
          fi
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
      '{{ index .Config.Labels "com.docker.compose.service" }}')
        case "$container" in
          app-container) printf 'app\n' ;;
          db-container) printf 'db\n' ;;
          migrate-container) printf 'migrate\n' ;;
          *) exit 1 ;;
        esac
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
            printf 'mariadb:11.8.8@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc\n'
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

fake_linux_acl_bin=$work_dir/fake-linux-acl-bin
mkdir "$fake_linux_acl_bin"
cat >"$fake_linux_acl_bin/uname" <<'EOF'
#!/bin/sh
[ "$#" -eq 1 ] && [ "$1" = -s ]
printf 'Linux\n'
EOF
cat >"$fake_linux_acl_bin/getfacl" <<'EOF'
#!/bin/sh
set -eu
acl_path=
for acl_argument in "$@"; do
  acl_path=$acl_argument
done
printf 'user::rwx\n'
case "$acl_path" in
  *"${FAKE_EXTENDED_ACL_MATCH:-no-path-can-match-this-value}"*)
    printf 'user:intruder:rwx\nmask::rwx\n'
    ;;
esac
printf 'group::---\nother::---\n'
EOF
chmod 0700 \
    "$fake_linux_acl_bin/uname" \
    "$fake_linux_acl_bin/getfacl"

fake_synology_acl_bin=$work_dir/fake-synology-acl-bin
mkdir "$fake_synology_acl_bin"
cp "$fake_linux_acl_bin/uname" "$fake_synology_acl_bin/uname"
cp "$fake_linux_acl_bin/getfacl" "$fake_synology_acl_bin/getfacl"
cat >"$fake_synology_acl_bin/synoacltool" <<'EOF'
#!/bin/sh
[ "$#" -eq 2 ] && [ "$1" = -get ]
case "${FAKE_SYNOLOGY_ACL_MODE:-extended}" in
  linux)
    printf "%s\n" "It's Linux mode" >&2
    exit 1
    ;;
  extended)
    printf 'ACL version: 1\nArchive: has_ACL\n'
    ;;
  *)
    exit 64
    ;;
esac
EOF
chmod 0700 "$fake_synology_acl_bin/synoacltool"
chmod 0700 \
    "$fake_synology_acl_bin/uname" \
    "$fake_synology_acl_bin/getfacl"

fake_darwin_acl_bin=$work_dir/fake-darwin-acl-bin
mkdir "$fake_darwin_acl_bin"
cat >"$fake_darwin_acl_bin/uname" <<'EOF'
#!/bin/sh
[ "$#" -eq 1 ] && [ "$1" = -s ]
printf 'Darwin\n'
EOF
cat >"$fake_darwin_acl_bin/ls" <<'EOF'
#!/bin/sh
set -eu
if [ "$#" -eq 2 ] && [ "$1" = -lde ]; then
  if [ "${FAKE_MACOS_ACL:-0}" = 1 ]; then
    printf 'drwx------+ 2 operator staff 64 Jul 31 12:00 %s\n' "$2"
    printf ' 0: group:everyone allow read\n'
  else
    printf 'drwx------ 2 operator staff 64 Jul 31 12:00 %s\n' "$2"
  fi
  exit 0
fi
exec "$FAKE_REAL_LS" "$@"
EOF
chmod 0700 \
    "$fake_darwin_acl_bin/uname" \
    "$fake_darwin_acl_bin/ls"
fake_docker_bin=$work_dir/fake-docker-bin
mkdir "$fake_docker_bin"
ln -s "$fake_cli" "$fake_docker_bin/docker"
fake_podman_bin=$work_dir/fake-podman-bin
mkdir "$fake_podman_bin"
ln -s "$fake_cli" "$fake_podman_bin/podman"

real_ls=$(command -v ls)
real_mv=$(command -v mv)
fake_move=$work_dir/fake-mv
cat >"$fake_move" <<'EOF'
#!/bin/sh
set -eu
[ "$#" -eq 2 ]
source_directory=$1
target_directory=$2
if [ -n "${FAKE_SWAP_TARGET:-}" ] &&
    [ "${target_directory%/*}" = "$FAKE_SWAP_TARGET" ] &&
    [ ! -e "$FAKE_SWAP_TARGET.swap-triggered" ]; then
    "$FAKE_REAL_MV" "$FAKE_SWAP_TARGET" "$FAKE_SWAP_TARGET.original"
    mkdir "$FAKE_SWAP_TARGET"
    printf 'foreign replacement must remain untouched\n' \
        >"$FAKE_SWAP_TARGET/foreign.txt"
    : >"$FAKE_SWAP_TARGET.swap-triggered"
    exit 88
fi
if [ -e "$target_directory" ] || [ -L "$target_directory" ]; then
    exit 1
fi
exec "$FAKE_REAL_MV" "$source_directory" "$target_directory"
EOF
chmod 0700 "$fake_move"

real_mkdir=$(command -v mkdir)
fake_mkdir=$work_dir/fake-mkdir
cat >"$fake_mkdir" <<'EOF'
#!/bin/sh
set -eu
[ "$#" -eq 1 ]
target_directory=$1
if [ "$target_directory" = "${FAKE_RACE_TARGET:-}" ]; then
    "$FAKE_REAL_MKDIR" "$target_directory"
    printf 'foreign target must remain untouched\n' \
        >"$target_directory/foreign.txt"
    if "$FAKE_REAL_MKDIR" "$target_directory" 2>/dev/null; then
        exit 99
    fi
    exit 1
fi
exec "$FAKE_REAL_MKDIR" "$target_directory"
EOF
chmod 0700 "$fake_mkdir"

if sh "$backup_operator" relative/backup >/dev/null 2>&1; then
    printf 'Backup operator test: relative target accepted\n' >&2
    exit 1
fi
if sh "$backup_operator" "$output_parent/unsafe target" >/dev/null 2>&1; then
    printf 'Backup operator test: unsafe target name accepted\n' >&2
    exit 1
fi

leading_zero_target=$output_parent/backup-leading-zero-timeout
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_BACKUP_HEALTH_TIMEOUT_SECONDS=01 \
    FAKE_STATE="$state_dir" \
    sh "$backup_operator" "$leading_zero_target" \
    >"$work_dir/leading-zero.stdout" \
    2>"$work_dir/leading-zero.stderr"; then
    printf 'Backup operator test: leading-zero health timeout accepted\n' >&2
    exit 1
fi
grep -Fq 'must be an integer from 1 to 3600' \
    "$work_dir/leading-zero.stderr"
[ ! -e "$leading_zero_target" ]

linux_acl_target=$output_parent/backup-linux-parent-acl
if PATH="$fake_linux_acl_bin:$PATH" \
    FAKE_EXTENDED_ACL_MATCH="$output_parent" \
    ESTAB_CONTAINER_CLI="$fake_cli" \
    FAKE_STATE="$state_dir" \
    sh "$backup_operator" "$linux_acl_target" \
    >"$work_dir/linux-acl.stdout" \
    2>"$work_dir/linux-acl.stderr"; then
    printf 'Backup operator test: extended Linux parent ACL accepted\n' >&2
    exit 1
fi
grep -Fq 'extended or access-granting POSIX ACL' \
    "$work_dir/linux-acl.stderr"
[ ! -e "$linux_acl_target" ]

macos_acl_target=$output_parent/backup-macos-parent-acl
if PATH="$fake_darwin_acl_bin:$PATH" \
    FAKE_REAL_LS="$real_ls" \
    FAKE_MACOS_ACL=1 \
    ESTAB_CONTAINER_CLI="$fake_cli" \
    FAKE_STATE="$state_dir" \
    sh "$backup_operator" "$macos_acl_target" \
    >"$work_dir/macos-acl.stdout" \
    2>"$work_dir/macos-acl.stderr"; then
    printf 'Backup operator test: extended macOS parent ACL accepted\n' >&2
    exit 1
fi
grep -Fq 'extended or unknown macOS ACL marker' \
    "$work_dir/macos-acl.stderr"
[ ! -e "$macos_acl_target" ]

synology_acl_target=$output_parent/backup-synology-parent-acl
if PATH="$fake_synology_acl_bin:$PATH" \
    ESTAB_CONTAINER_CLI="$fake_cli" \
    FAKE_STATE="$state_dir" \
    sh "$backup_operator" "$synology_acl_target" \
    >"$work_dir/synology-acl.stdout" \
    2>"$work_dir/synology-acl.stderr"; then
    printf 'Backup operator test: Synology DSM parent ACL accepted\n' >&2
    exit 1
fi
grep -Fq 'has a Synology DSM ACL' "$work_dir/synology-acl.stderr"
[ ! -e "$synology_acl_target" ]

synology_linux_target=$output_parent/backup-synology-linux-mode
PATH="$fake_synology_acl_bin:$PATH" \
    FAKE_SYNOLOGY_ACL_MODE=linux \
    FAKE_EXTENDED_ACL_MATCH="$output_parent" \
    ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    sh "$backup_operator" "$synology_linux_target" >/dev/null
[ -f "$synology_linux_target/SHA256SUMS" ]

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

duplicate_service_target=$output_parent/backup-duplicate-service
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    FAKE_DUPLICATE_SERVICE=app \
    sh "$backup_operator" "$duplicate_service_target" \
    >"$work_dir/duplicate-service.stdout" \
    2>"$work_dir/duplicate-service.stderr"; then
    printf 'Backup operator test: duplicate engine service match accepted\n' >&2
    exit 1
fi
grep -Fq \
    'Compose service must resolve to exactly one engine container: app' \
    "$work_dir/duplicate-service.stderr"
[ ! -e "$duplicate_service_target" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

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

desktop_alias_number=0
for desktop_alias_prefix in /host_mnt /run/desktop/mnt/host; do
    desktop_alias_number=$((desktop_alias_number + 1))
    desktop_alias_target=$output_parent/backup-desktop-alias-$desktop_alias_number
    if PATH="$fake_darwin_acl_bin:$PATH" \
        FAKE_REAL_LS="$real_ls" \
        ESTAB_CONTAINER_CLI="$fake_docker_bin/docker" \
        ESTAB_MOVE_CLI="$fake_move" \
        FAKE_REAL_MV="$real_mv" \
        FAKE_STATE="$state_dir" \
        FAKE_DATA="$fixture_data" \
        FAKE_EXPORT="$fixture_export" \
        FAKE_OPERATOR_SOURCE="$desktop_alias_prefix$output_parent" \
        sh "$backup_operator" "$desktop_alias_target" \
        >"$work_dir/desktop-alias-$desktop_alias_number.stdout" \
        2>"$work_dir/desktop-alias-$desktop_alias_number.stderr"; then
        printf 'Backup operator test: Docker Desktop backup-parent alias overlap accepted\n' >&2
        exit 1
    fi
    grep -Fq 'backup parent overlaps a productive storage source' \
        "$work_dir/desktop-alias-$desktop_alias_number.stderr"
    [ ! -e "$desktop_alias_target" ]
    [ ! -e "$state_dir/maintenance-lock-id" ]
done

foreign_overlap_target=$output_parent/backup-foreign-overlap
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    FAKE_FOREIGN_MOUNT_SOURCE=/srv/estab/data/4fdata \
    sh "$backup_operator" "$foreign_overlap_target" \
    >"$work_dir/foreign-overlap.stdout" \
    2>"$work_dir/foreign-overlap.stderr"; then
    printf 'Backup operator test: foreign productive-storage consumer accepted\n' >&2
    exit 1
fi
grep -Fq \
    'foreign container 9999999999999999999999999999999999999999999999999999999999999999' \
    "$work_dir/foreign-overlap.stderr"
grep -Fq 'overlapping productive application source' \
    "$work_dir/foreign-overlap.stderr"
[ ! -e "$foreign_overlap_target" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

foreign_malformed_target=$output_parent/backup-foreign-malformed
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    FAKE_FOREIGN_MALFORMED=1 \
    sh "$backup_operator" "$foreign_malformed_target" \
    >"$work_dir/foreign-malformed.stdout" \
    2>"$work_dir/foreign-malformed.stderr"; then
    printf 'Backup operator test: malformed foreign Type/TAB/Source metadata accepted\n' >&2
    exit 1
fi
grep -Fq 'unsafe or uninspectable mount metadata' \
    "$work_dir/foreign-malformed.stderr"
[ ! -e "$foreign_malformed_target" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

inventory_change_target=$output_parent/backup-inventory-change
rm -f -- "$state_dir/inventory-count"
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    FAKE_INVENTORY_CHANGE=1 \
    sh "$backup_operator" "$inventory_change_target" \
    >"$work_dir/inventory-change.stdout" \
    2>"$work_dir/inventory-change.stderr"; then
    printf 'Backup operator test: unstable engine-wide inventory accepted\n' >&2
    exit 1
fi
grep -Fq 'engine-wide container inventory changed' \
    "$work_dir/inventory-change.stderr"
[ ! -e "$inventory_change_target" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
rm -f -- "$state_dir/inventory-count"

staging_acl_target=$output_parent/backup-staging-acl
if PATH="$fake_linux_acl_bin:$PATH" \
    FAKE_EXTENDED_ACL_MATCH=.backup-staging-acl.incomplete. \
    ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    sh "$backup_operator" "$staging_acl_target" \
    >"$work_dir/staging-acl.stdout" \
    2>"$work_dir/staging-acl.stderr"; then
    printf 'Backup operator test: extended staging ACL accepted\n' >&2
    exit 1
fi
grep -Fq 'backup staging has an extended or access-granting POSIX ACL' \
    "$work_dir/staging-acl.stderr"
[ ! -e "$staging_acl_target" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

restart_check=1
while [ "$restart_check" -le 3 ]; do
    stop_check=$((restart_check + 1))
    restart_target=$output_parent/backup-restarted-$stop_check
    if ESTAB_CONTAINER_CLI="$fake_cli" \
        ESTAB_MOVE_CLI="$fake_move" \
        FAKE_REAL_MV="$real_mv" \
        FAKE_STATE="$state_dir" \
        FAKE_DATA="$fixture_data" \
        FAKE_EXPORT="$fixture_export" \
        FAKE_RESTART_AT_STOP_CHECK="$stop_check" \
        sh "$backup_operator" "$restart_target" \
        >"$work_dir/restarted-$stop_check.stdout" \
        2>"$work_dir/restarted-$stop_check.stderr"; then
        printf 'Backup operator test: externally restarted application passed a capture boundary\n' >&2
        exit 1
    fi
    grep -Fq 'application is running; consistent backup capture will not begin' \
        "$work_dir/restarted-$stop_check.stderr"
    [ ! -e "$restart_target" ]
    [ ! -e "$state_dir/maintenance-lock-id" ]
    restart_check=$((restart_check + 1))
done

target_acl_target=$output_parent/backup-target-acl
if PATH="$fake_linux_acl_bin:$PATH" \
    FAKE_EXTENDED_ACL_MATCH=/backup-target-acl \
    ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    sh "$backup_operator" "$target_acl_target" \
    >"$work_dir/target-acl.stdout" \
    2>"$work_dir/target-acl.stderr"; then
    printf 'Backup operator test: extended reserved-target ACL accepted\n' >&2
    exit 1
fi
grep -Fq \
    'reserved backup target has an extended or access-granting POSIX ACL' \
    "$work_dir/target-acl.stderr"
[ ! -e "$target_acl_target" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

race_target=$output_parent/backup-target-race
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    ESTAB_MKDIR_CLI="$fake_mkdir" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_REAL_MKDIR="$real_mkdir" \
    FAKE_RACE_TARGET="$race_target" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    sh "$backup_operator" "$race_target" \
    >"$work_dir/target-race.stdout" \
    2>"$work_dir/target-race.stderr"; then
    printf 'Backup operator test: concurrent target creation was overwritten\n' >&2
    exit 1
fi
grep -Fq 'atomic no-clobber reservation failed' \
    "$work_dir/target-race.stderr"
grep -Fqx 'foreign target must remain untouched' \
    "$race_target/foreign.txt"
[ "$(find "$race_target" -mindepth 1 -maxdepth 1 | wc -l | tr -d ' ')" = 1 ]
[ ! -e "$output_parent/.estab-backup.lock" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
if find "$output_parent" -maxdepth 1 \
    -name '.backup-target-race.incomplete.*' -print |
    grep -q .; then
    printf 'Backup operator test: target race left staging data behind\n' >&2
    exit 1
fi
rm -f -- "$race_target/foreign.txt"
rmdir -- "$race_target"

swapped_target=$output_parent/backup-target-swap
if ESTAB_CONTAINER_CLI="$fake_cli" \
    ESTAB_MOVE_CLI="$fake_move" \
    FAKE_REAL_MV="$real_mv" \
    FAKE_SWAP_TARGET="$swapped_target" \
    FAKE_STATE="$state_dir" \
    FAKE_DATA="$fixture_data" \
    FAKE_EXPORT="$fixture_export" \
    sh "$backup_operator" "$swapped_target" \
    >"$work_dir/target-swap.stdout" \
    2>"$work_dir/target-swap.stderr"; then
    printf 'Backup operator test: swapped reserved target was accepted\n' >&2
    exit 1
fi
grep -Fq 'refusing to remove an unproven reserved target' \
    "$work_dir/target-swap.stderr"
grep -Fqx 'foreign replacement must remain untouched' \
    "$swapped_target/foreign.txt"
[ -d "$swapped_target.original" ]
[ -d "$output_parent/.estab-backup.lock" ]
rm -f -- \
    "$swapped_target/foreign.txt" \
    "$swapped_target.swap-triggered" \
    "$output_parent/.estab-backup.lock/owner.txt" \
    "$output_parent/.estab-backup.lock/target-owner.txt"
rmdir -- \
    "$swapped_target" \
    "$swapped_target.original" \
    "$output_parent/.estab-backup.lock"
rm -f -- \
    "$state_dir/app-events" \
    "$state_dir/health-events" \
    "$state_dir/events"

successful_target=$output_parent/backup-20260729-020000
ESTAB_CONTAINER_CLI="$fake_podman_bin/podman" \
ESTAB_MOVE_CLI="$fake_move" \
FAKE_REAL_MV="$real_mv" \
FAKE_STATE="$state_dir" \
FAKE_DATA="$fixture_data" \
FAKE_EXPORT="$fixture_export" \
FAKE_PROVIDER_IDS=short \
    sh "$backup_operator" "$successful_target" >/dev/null

[ -d "$successful_target" ] ||
    {
        printf 'Backup operator test: final directory was not published\n' >&2
        exit 1
    }
[ "$(wc -l <"$successful_target/SHA256SUMS" | tr -d ' ')" = 10 ] ||
    {
        printf 'Backup operator test: v3 manifest does not bind every expected file\n' >&2
        exit 1
    }
sh "$backup_verifier" "$successful_target" estab >/dev/null
grep -Fqx 'estab-full-backup-v3' "$successful_target/backup-format.txt"
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
expected_database_image_record=$(printf 'database\t%s\t%s' \
    'docker.io/library/mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc' \
    'sha256:3333333333333333333333333333333333333333333333333333333333333333')
grep -Fqx "$expected_database_image_record" \
    "$successful_target/image-references.txt"
expected_app_release_identity=$(printf 'app\t%s' \
    'ghcr.io/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
grep -Fqx "$expected_app_release_identity" \
    "$successful_target/release-identity.txt"
expected_migrate_release_identity=$(printf 'migrate\t%s' \
    'ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
grep -Fqx "$expected_migrate_release_identity" \
    "$successful_target/release-identity.txt"
expected_database_release_identity=$(printf 'database\t%s' \
    'docker.io/library/mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc')
grep -Fqx "$expected_database_release_identity" \
    "$successful_target/release-identity.txt"
[ "$(grep -c '^stopped$' "$state_dir/app-events")" -eq 1 ]
[ "$(grep -c '^started$' "$state_dir/app-events")" -eq 1 ]
[ "$(grep -c '^app-container$' "$state_dir/health-events")" -ge 3 ]
[ "$(grep -c '^db-container$' "$state_dir/health-events")" -ge 2 ]
[ ! -e "$output_parent/.estab-backup.lock" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
grep -Fqx 'docker' "$state_dir/provider-events"
grep -Fqx 'podman' "$state_dir/provider-events"
grep -Fq \
    'ps --all --no-trunc --filter label=com.docker.compose.project=estabtest --filter label=com.docker.compose.service=app --format {{.ID}}' \
    "$state_dir/events"
grep -Fq 'inspect --format {{.Id}} 666666666666' "$state_dir/events"
[ ! -e "$state_dir/forbidden-compose-ps" ]

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
