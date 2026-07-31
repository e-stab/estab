#!/bin/sh

set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
restore_operator=$repo_root/deploy/registry/restore.sh
backup_verifier=$repo_root/deploy/registry/verify-backup.sh
work_dir=$(mktemp -d "${TMPDIR:-/tmp}/estab-restore-operator.XXXXXX")
trap 'rm -rf -- "$work_dir"' EXIT HUP INT TERM

backup_dir=$work_dir/backup-v3
backup_v2=$work_dir/backup-v2
backup_mutable=$work_dir/backup-v3-mutable
backup_portable=$work_dir/backup-v3-named-volumes
backup_cross_engine=$work_dir/backup-v3-cross-engine
backup_tocou=$work_dir/backup-v3-tocou
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
printf 'estab-full-backup-v3\n' >"$backup_dir/backup-format.txt"
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
cat >"$backup_dir/release-identity.txt" <<'EOF'
app	ghcr.io/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
migrate	ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
database	mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
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
            release-identity.txt \
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
            release-identity.txt \
            storage-sources.txt \
            >SHA256SUMS
    fi
)
sh "$backup_verifier" "$backup_dir" estab >/dev/null

cp -R "$backup_dir" "$backup_v2"
printf 'estab-full-backup-v2\n' >"$backup_v2/backup-format.txt"
rm -f -- "$backup_v2/release-identity.txt"
(
    cd "$backup_v2"
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
sh "$backup_verifier" "$backup_v2" estab >/dev/null

cp -R "$backup_dir" "$backup_mutable"
sed \
    -e 's#ghcr.io/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa#ghcr.io/e-stab/estab:portable-test#g' \
    -e 's#ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb#ghcr.io/e-stab/estab-migrate:portable-test#g' \
    -e 's#mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc#mariadb:11.8#g' \
    "$backup_dir/image-references.txt" \
    >"$backup_mutable/image-references.txt"
sed \
    -e 's#ghcr.io/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa#ghcr.io/e-stab/estab:portable-test#g' \
    -e 's#ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb#ghcr.io/e-stab/estab-migrate:portable-test#g' \
    -e 's#mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc#mariadb:11.8#g' \
    "$backup_dir/release-identity.txt" \
    >"$backup_mutable/release-identity.txt"
(
    cd "$backup_mutable"
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
            release-identity.txt \
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
            release-identity.txt \
            storage-sources.txt \
            >SHA256SUMS
    fi
)
sh "$backup_verifier" "$backup_mutable" estab >/dev/null

cp -R "$backup_dir" "$backup_portable"
cat >"$backup_portable/storage-sources.txt" <<'EOF'
database	/var/lib/mysql	volume	restoretest_estab_db	/var/lib/containers/storage/volumes/restoretest_estab_db/_data
application	/var/www/html/4fdata	volume	restoretest_estab_data	/var/lib/containers/storage/volumes/restoretest_estab_data/_data
export	/var/lib/estab/export	volume	restoretest_estab_export	/var/lib/containers/storage/volumes/restoretest_estab_export/_data
EOF
(
    cd "$backup_portable"
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
            release-identity.txt \
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
            release-identity.txt \
            storage-sources.txt \
            >SHA256SUMS
    fi
)
sh "$backup_verifier" "$backup_portable" estab >/dev/null

cp -R "$backup_portable" "$backup_cross_engine"
cat >"$backup_cross_engine/image-references.txt" <<'EOF'
app	registry.example:5443/e-stab/estab:2026.07@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa	sha256:1111111111111111111111111111111111111111111111111111111111111111
migrate	ghcr.io/e-stab/estab-migrate:2026.07@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb	sha256:2222222222222222222222222222222222222222222222222222222222222222
database	mariadb:11.8.8@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc	sha256:3333333333333333333333333333333333333333333333333333333333333333
EOF
cat >"$backup_cross_engine/release-identity.txt" <<'EOF'
app	registry.example:5443/e-stab/estab:2026.07@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
migrate	ghcr.io/e-stab/estab-migrate:2026.07@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
database	mariadb:11.8.8@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
EOF
(
    cd "$backup_cross_engine"
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
            release-identity.txt \
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
            release-identity.txt \
            storage-sources.txt \
            >SHA256SUMS
    fi
)
sh "$backup_verifier" "$backup_cross_engine" estab >/dev/null

cp -R "$backup_dir" "$backup_tocou"

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
        [ "${FAKE_REJECT_COMPOSE_PS:-0}" != 1 ] || exit 97
        service=
        for argument in "$@"; do
          service=$argument
        done
        case "$service" in
          app)
            app_provider_id=app-container
            if [ "${FAKE_COMPOSE_SHORT_IDS:-0}" = 1 ]; then
              app_provider_id=app-short
            fi
            if [ -e "$FAKE_STATE/app-ps-transient" ]; then
              rm -f -- "$FAKE_STATE/app-ps-transient"
              case "${FAKE_APP_PS_AFTER_START:-}" in
                empty) ;;
                multiple)
                  printf '%s\n' "$app_provider_id" replacement-app-container
                  ;;
                *) printf '%s\n' "$app_provider_id" ;;
              esac
            else
              printf '%s\n' "$app_provider_id"
            fi
            ;;
          admin-auth-init)
            if [ "${FAKE_COMPOSE_SHORT_IDS:-0}" = 1 ]; then
              printf 'admin-short\n'
            else
              printf 'admin-auth-container\n'
            fi
            ;;
          db)
            if [ "${FAKE_COMPOSE_SHORT_IDS:-0}" = 1 ]; then
              printf 'db-short\n'
            else
              printf 'db-container\n'
            fi
            ;;
          migrate)
            if [ "${FAKE_COMPOSE_SHORT_IDS:-0}" = 1 ]; then
              printf 'migrate-short\n'
            else
              printf 'migrate-container\n'
            fi
            ;;
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
            if [ -n "${FAKE_MUTATE_BACKUP_AFTER_SNAPSHOT:-}" ] &&
              [ ! -e "$FAKE_STATE/source-backup-mutated" ]; then
              printf 'changed after verified snapshot\n' \
                >"$FAKE_MUTATE_BACKUP_AFTER_SNAPSHOT/database.sql"
              printf 'not an archive\n' \
                >"$FAKE_MUTATE_BACKUP_AFTER_SNAPSHOT/4fdata.tar.gz"
              printf 'not an archive\n' \
                >"$FAKE_MUTATE_BACKUP_AFTER_SNAPSHOT/export.tar.gz"
              : >"$FAKE_STATE/source-backup-mutated"
            fi
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
  ps)
    filtered_service=
    ps_all=0
    ps_no_trunc=0
    ps_id_format=0
    for ps_argument in "$@"; do
      case "$ps_argument" in
        --all) ps_all=1 ;;
        --no-trunc) ps_no_trunc=1 ;;
        '{{.ID}}') ps_id_format=1 ;;
        label=com.docker.compose.service=*)
          filtered_service=${ps_argument##*=}
          ;;
      esac
    done
    if [ -n "$filtered_service" ]; then
      [ "$ps_all" -eq 1 ]
      [ "$ps_no_trunc" -eq 1 ]
      [ "$ps_id_format" -eq 1 ]
      case "$filtered_service" in
        app)
          app_provider_id=app-container
          if [ "${FAKE_COMPOSE_SHORT_IDS:-0}" = 1 ]; then
            app_provider_id=app-short
          fi
          if [ -e "$FAKE_STATE/app-ps-transient" ]; then
            rm -f -- "$FAKE_STATE/app-ps-transient"
            case "${FAKE_APP_PS_AFTER_START:-}" in
              empty) ;;
              multiple)
                printf '%s\n' "$app_provider_id" replacement-app-container
                ;;
              *) printf '%s\n' "$app_provider_id" ;;
            esac
          else
            printf '%s\n' "$app_provider_id"
          fi
          ;;
        admin-auth-init)
          if [ "${FAKE_COMPOSE_SHORT_IDS:-0}" = 1 ]; then
            printf 'admin-short\n'
          else
            printf 'admin-auth-container\n'
          fi
          ;;
        db)
          if [ "${FAKE_COMPOSE_SHORT_IDS:-0}" = 1 ]; then
            printf 'db-short\n'
          else
            printf 'db-container\n'
          fi
          ;;
        migrate)
          if [ "${FAKE_COMPOSE_SHORT_IDS:-0}" = 1 ]; then
            printf 'migrate-short\n'
          else
            printf 'migrate-container\n'
          fi
          ;;
        *) exit 1 ;;
      esac
    else
      [ "$ps_all" -eq 1 ]
      [ "$ps_no_trunc" -eq 1 ]
      [ "$ps_id_format" -eq 1 ]
      printf '%s\n' \
        app-container \
        admin-auth-container \
        db-container \
        migrate-container
      if [ -e "$FAKE_STATE/maintenance-lock-id" ]; then
        cat "$FAKE_STATE/maintenance-lock-id"
      fi
      if [ -n "${FAKE_FOREIGN_MOUNT_SOURCE:-}" ] ||
        [ -n "${FAKE_FOREIGN_MOUNT_ROW:-}" ]; then
        printf 'foreign-container\n'
      fi
    fi
    ;;
  start)
    start_container=
    for start_argument in "$@"; do
      start_container=$start_argument
    done
    case "$start_container" in
      app-container)
        rm -f -- "$FAKE_STATE/app-stopped"
        if [ -n "${FAKE_APP_PS_AFTER_START:-}" ]; then
          : >"$FAKE_STATE/app-ps-transient"
        fi
        printf 'up-app\n' >>"$FAKE_STATE/operations"
        ;;
      db-container)
        printf 'start-db\n' >>"$FAKE_STATE/operations"
        ;;
      migrate-container)
        if [ "${FAKE_RESTART_APP_AT:-}" = migration ]; then
          rm -f -- "$FAKE_STATE/app-stopped"
        fi
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
        if [ -n "${FAKE_MUTATE_BACKUP_AFTER_SNAPSHOT:-}" ] &&
          [ ! -e "$FAKE_STATE/source-backup-mutated" ]; then
          printf 'changed after verified snapshot\n' \
            >"$FAKE_MUTATE_BACKUP_AFTER_SNAPSHOT/database.sql"
          printf 'not an archive\n' \
            >"$FAKE_MUTATE_BACKUP_AFTER_SNAPSHOT/4fdata.tar.gz"
          printf 'not an archive\n' \
            >"$FAKE_MUTATE_BACKUP_AFTER_SNAPSHOT/export.tar.gz"
          : >"$FAKE_STATE/source-backup-mutated"
        fi
        cat >"$FAKE_STATE/restored-database.sql"
        printf 'database-import\n' >>"$FAKE_STATE/operations"
        if [ "${FAKE_RESTART_APP_AT:-}" = database-import ]; then
          rm -f -- "$FAKE_STATE/app-stopped"
        fi
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
    volumes_from=
    expect_volumes_from=0
    for run_argument in "$@"; do
      if [ "$expect_volumes_from" -eq 1 ]; then
        volumes_from=$run_argument
        expect_volumes_from=0
        continue
      fi
      if [ "$run_argument" = --volumes-from ]; then
        expect_volumes_from=1
      fi
    done
    [ "$expect_volumes_from" -eq 0 ]
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
        [ "$volumes_from" = app-container:z ]
        if [ "${FAKE_UNWRITABLE_MOUNTS:-0}" = 1 ]; then
          exit 43
        fi
        printf 'file-write-preflight\n' >>"$FAKE_STATE/operations"
        ;;
      *'find /var/www/html/4fdata'*)
        [ "$volumes_from" = app-container:z ]
        find "$FAKE_DATA" -mindepth 1 -maxdepth 1 \
            -exec rm -rf -- {} +
        find "$FAKE_EXPORT" -mindepth 1 -maxdepth 1 \
            -exec rm -rf -- {} +
        printf 'file-volumes-cleared\n' >>"$FAKE_STATE/operations"
        if [ "${FAKE_RESTART_APP_AT:-}" = file-clear ]; then
          rm -f -- "$FAKE_STATE/app-stopped"
        fi
        ;;
      *'tar -xzf - -C /var/www/html/4fdata'*)
        [ "$volumes_from" = app-container:z ]
        case " $* " in
          *' --interactive '*) ;;
          *) exit 44 ;;
        esac
        tar -xzf - -C "$FAKE_DATA"
        printf 'application-data-extracted\n' >>"$FAKE_STATE/operations"
        if [ "${FAKE_RESTART_APP_AT:-}" = application-extract ]; then
          rm -f -- "$FAKE_STATE/app-stopped"
        fi
        ;;
      *'tar -xzf - -C /var/lib/estab/export'*)
        [ "$volumes_from" = app-container:z ]
        case " $* " in
          *' --interactive '*) ;;
          *) exit 44 ;;
        esac
        if [ "${FAKE_FAIL_EXPORT_RESTORE:-0}" = 1 ]; then
          if [ "${FAKE_LOSE_LOCK_ON_FAILURE:-0}" = 1 ]; then
            printf 'foreign-owner\n' \
              >"$FAKE_STATE/maintenance-lock-owner"
          fi
          exit 42
        fi
        tar -xzf - -C "$FAKE_EXPORT"
        printf 'export-data-extracted\n' >>"$FAKE_STATE/operations"
        if [ "${FAKE_RESTART_APP_AT:-}" = export-extract ]; then
          rm -f -- "$FAKE_STATE/app-stopped"
        fi
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
    foreign_id=9999999999999999999999999999999999999999999999999999999999999999
    if [ "$container" = foreign-container ] ||
      [ "$container" = "$foreign_id" ]; then
      case "$format" in
        '{{.Id}}') printf '%s\n' "$foreign_id" ;;
        '{{ index .Config.Labels "com.docker.compose.project" }}')
          printf '%s\n' "${FAKE_FOREIGN_PROJECT:-foreign-project}"
          ;;
        '{{range .Mounts}}{{printf "%s\t%s\n" .Type .Source}}{{end}}')
          if [ -n "${FAKE_FOREIGN_MOUNT_ROW:-}" ]; then
            printf '%s\n' "$FAKE_FOREIGN_MOUNT_ROW"
          else
            printf 'bind\t%s\n' "$FAKE_FOREIGN_MOUNT_SOURCE"
          fi
          ;;
        *) exit 1 ;;
      esac
      exit 0
    fi
    case "$format" in
      '{{.Id}}')
        case "$container" in
          app-short)
            if [ "${FAKE_INVALID_CANONICAL_ID_SERVICE:-}" = app ]; then
              printf 'app-container\nreplacement-app-container\n'
            else
              printf 'app-container\n'
            fi
            ;;
          admin-short) printf 'admin-auth-container\n' ;;
          db-short) printf 'db-container\n' ;;
          migrate-short) printf 'migrate-container\n' ;;
          app-container|admin-auth-container|db-container|migrate-container)
            printf '%s\n' "$container"
            ;;
          *) exit 1 ;;
        esac
        ;;
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
      '{{.State.Status}}')
        case "$container" in
          app-container)
            if [ -e "$FAKE_STATE/app-stopped" ]; then
              printf 'exited\n'
            else
              printf 'running\n'
            fi
            ;;
          db-container) printf 'running\n' ;;
          admin-auth-container|migrate-container) printf 'exited\n' ;;
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
      '{{ index .Config.Labels "com.docker.compose.service" }}')
        case "$container" in
          app-container) printf 'app\n' ;;
          admin-auth-container) printf 'admin-auth-init\n' ;;
          db-container) printf 'db\n' ;;
          migrate-container) printf 'migrate\n' ;;
          *) exit 1 ;;
        esac
        ;;
      '{{range .Config.Env}}{{println .}}{{end}}')
        [ "$container" = db-container ]
        printf 'MARIADB_DATABASE=%s\n' "${FAKE_DATABASE_NAME:-estab}"
        printf 'TZ=Europe/Berlin\n'
        ;;
      '{{.Config.Image}}')
        case "$container" in
          app-container|admin-auth-container)
            printf '%s\n' \
              "${FAKE_APP_IMAGE_REFERENCE:-ghcr.io/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}"
            ;;
          migrate-container)
            printf '%s\n' \
              "${FAKE_MIGRATE_IMAGE_REFERENCE:-ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb}"
            ;;
          db-container)
            printf '%s\n' \
              "${FAKE_DB_IMAGE_REFERENCE:-mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc}"
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
            printf '%s\n' \
              "${FAKE_ADMIN_AUTH_IMAGE_ID:-${FAKE_APP_IMAGE_ID:-sha256:1111111111111111111111111111111111111111111111111111111111111111}}"
            ;;
          migrate-container)
            printf '%s\n' \
              "${FAKE_MIGRATE_IMAGE_ID:-sha256:2222222222222222222222222222222222222222222222222222222222222222}"
            ;;
          db-container)
            printf '%s\n' \
              "${FAKE_DB_IMAGE_ID:-sha256:3333333333333333333333333333333333333333333333333333333333333333}"
            ;;
          *) exit 1 ;;
        esac
        ;;
      '{{range .Mounts}}{{printf "%s\t%s\t%s\t%s\n" .Destination .Type .Name .Source}}{{end}}')
        case "$container" in
          app-container)
            printf '/var/www/html/4fdata\t%s\t%s\t%s\n' \
              "${FAKE_APP_MOUNT_TYPE:-bind}" \
              "${FAKE_APP_VOLUME_NAME-}" \
              "${FAKE_OPERATOR_SOURCE:-${FAKE_APP_SOURCE:-/srv/estab/data/4fdata}}"
            if [ "${FAKE_OVERLAPPING_SOURCE:-0}" = 1 ]; then
              printf '/var/lib/estab/export\tbind\t\t/srv/estab/data/4fdata/export\n'
            else
              printf '/var/lib/estab/export\t%s\t%s\t%s\n' \
                "${FAKE_EXPORT_MOUNT_TYPE:-bind}" \
                "${FAKE_EXPORT_VOLUME_NAME-}" \
                "${FAKE_EXPORT_SOURCE:-/srv/estab/data/export}"
            fi
            if [ "${FAKE_NESTED_MOUNT:-0}" = 1 ]; then
              printf '/var/www/html/4fdata/foreign\tbind\t\t/srv/foreign\n'
            fi
            ;;
          db-container)
            printf '/var/lib/mysql\t%s\t%s\t%s\n' \
              "${FAKE_DB_MOUNT_TYPE:-volume}" \
              "${FAKE_DB_VOLUME_NAME-restoretest_estab_db}" \
              "${FAKE_DB_SOURCE:-/var/lib/containers/storage/volumes/restoretest_estab_db/_data}"
            ;;
          admin-auth-container)
            printf '/var/lib/estab/auth\tvolume\trestoretest_estab_auth\t/var/lib/containers/storage/volumes/restoretest_estab_auth/_data\n'
            ;;
          *) exit 1 ;;
        esac
        ;;
      '{{range .Mounts}}{{printf "%s\t%t\n" .Destination .RW}}{{end}}')
        case "$container" in
          app-container)
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
          db-container)
            if [ "${FAKE_READ_ONLY_MOUNT:-}" = database ]; then
              printf '/var/lib/mysql\tfalse\n'
            else
              printf '/var/lib/mysql\ttrue\n'
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
    ;;
  *)
    exit 1
    ;;
esac
EOF
chmod 0700 "$fake_cli"

darwin_bin=$work_dir/darwin-bin
darwin_acl_bin=$work_dir/darwin-acl-bin
linux_acl_bin=$work_dir/linux-acl-bin
synology_acl_bin=$work_dir/synology-acl-bin
synology_linux_bin=$work_dir/synology-linux-bin
mkdir -p \
    "$darwin_bin" \
    "$darwin_acl_bin" \
    "$linux_acl_bin" \
    "$synology_acl_bin" \
    "$synology_linux_bin"
cp "$fake_cli" "$darwin_bin/docker"
cat >"$darwin_bin/uname" <<'EOF'
#!/bin/sh
printf 'Darwin\n'
EOF
cat >"$darwin_bin/ls" <<'EOF'
#!/bin/sh
listed_path=
for listed_argument in "$@"; do
  listed_path=$listed_argument
done
if [ -d "$listed_path" ]; then
  printf 'drwx------ 1 restore restore 0 Jul 31 08:00 %s\n' "$listed_path"
else
  printf -- '-rw------- 1 restore restore 0 Jul 31 08:00 %s\n' "$listed_path"
fi
EOF
cat >"$darwin_acl_bin/uname" <<'EOF'
#!/bin/sh
printf 'Darwin\n'
EOF
cat >"$darwin_acl_bin/ls" <<'EOF'
#!/bin/sh
printf 'drwx------+ 1 restore restore 0 Jul 31 08:00 protected\n'
printf ' 0: user:someone allow read\n'
EOF
cat >"$linux_acl_bin/uname" <<'EOF'
#!/bin/sh
printf 'Linux\n'
EOF
cat >"$linux_acl_bin/getfacl" <<'EOF'
#!/bin/sh
printf '%s\n' \
  'user::rwx' \
  'user:someone:r-x' \
  'group::---' \
  'mask::r-x' \
  'other::---'
EOF
cat >"$synology_acl_bin/synoacltool" <<'EOF'
#!/bin/sh
printf 'ACL version: 1\n'
printf '[0] user:someone:allow:rwxpdDaARWc--:fd--\n'
EOF
cat >"$synology_linux_bin/synoacltool" <<'EOF'
#!/bin/sh
printf "(synoacltool.c, 498)It's Linux mode\n" >&2
exit 1
EOF
cat >"$synology_linux_bin/uname" <<'EOF'
#!/bin/sh
printf 'Linux\n'
EOF
cat >"$synology_linux_bin/getfacl" <<'EOF'
#!/bin/sh
exit 127
EOF
cat >"$synology_linux_bin/ls" <<'EOF'
#!/bin/sh
exit 127
EOF
chmod 0700 \
    "$darwin_bin/docker" \
    "$darwin_bin/uname" \
    "$darwin_bin/ls" \
    "$darwin_acl_bin/uname" \
    "$darwin_acl_bin/ls" \
    "$linux_acl_bin/uname" \
    "$linux_acl_bin/getfacl" \
    "$synology_acl_bin/synoacltool" \
    "$synology_linux_bin/synoacltool" \
    "$synology_linux_bin/uname" \
    "$synology_linux_bin/getfacl" \
    "$synology_linux_bin/ls"

run_restore()
{
    (
        cd "$project_dir"
        ESTAB_CONTAINER_CLI=${RESTORE_CONTAINER_CLI:-$fake_cli} \
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
        ESTAB_CONTAINER_CLI=${RESTORE_CONTAINER_CLI:-$fake_cli} \
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

remove_fake_maintenance_lock()
{
    removable_lock_id=$(cat "$state_dir/maintenance-lock-id")
    ESTAB_CONTAINER_CLI=$fake_cli \
    FAKE_STATE=$state_dir \
        "$fake_cli" container rm --force "$removable_lock_id"
}

original_test_path=$PATH

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

if ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS=0240 \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/leading-zero-timeout.stdout" \
    2>"$work_dir/leading-zero-timeout.stderr"; then
    printf 'Restore operator test: timeout with a leading zero was accepted\n' >&2
    exit 1
fi
unset ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS
grep -Fq \
    'ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS must be an integer from 1 to 3600' \
    "$work_dir/leading-zero-timeout.stderr"

if PATH=$synology_acl_bin:$PATH \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/synology-acl.stdout" \
    2>"$work_dir/synology-acl.stderr"; then
    printf 'Restore operator test: Synology DSM ACL was accepted\n' >&2
    exit 1
fi
PATH=$original_test_path
grep -Fq 'backup directory has a Synology DSM ACL' \
    "$work_dir/synology-acl.stderr" || {
        cat "$work_dir/synology-acl.stderr" >&2
        exit 1
    }

if PATH=$synology_linux_bin:$PATH \
    FAKE_PROJECT=other \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/synology-linux-mode.stdout" \
    2>"$work_dir/synology-linux-mode.stderr"; then
    printf 'Restore operator test: Synology Linux-mode fixture unexpectedly restored\n' >&2
    exit 1
fi
PATH=$original_test_path
unset FAKE_PROJECT
grep -Fq \
    'engine inventory returned a container with mismatched project or service labels: app' \
    "$work_dir/synology-linux-mode.stderr" || {
        cat "$work_dir/synology-linux-mode.stderr" >&2
        exit 1
    }
if grep -Eq 'ACL|acl|trusted ACL probe' \
    "$work_dir/synology-linux-mode.stderr"; then
    printf 'Restore operator test: Synology Linux mode fell through to a non-DSM ACL probe\n' >&2
    cat "$work_dir/synology-linux-mode.stderr" >&2
    exit 1
fi

if PATH=$darwin_acl_bin:$PATH \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/darwin-acl.stdout" \
    2>"$work_dir/darwin-acl.stderr"; then
    printf 'Restore operator test: extended macOS ACL was accepted\n' >&2
    exit 1
fi
PATH=$original_test_path
grep -Fq 'backup directory has an extended or unknown macOS ACL marker' \
    "$work_dir/darwin-acl.stderr" || {
        cat "$work_dir/darwin-acl.stderr" >&2
        exit 1
    }

if PATH=$linux_acl_bin:$PATH \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/linux-acl.stdout" \
    2>"$work_dir/linux-acl.stderr"; then
    printf 'Restore operator test: extended Linux ACL was accepted\n' >&2
    exit 1
fi
PATH=$original_test_path
grep -Fq 'backup directory has an extended POSIX ACL' \
    "$work_dir/linux-acl.stderr" || {
        cat "$work_dir/linux-acl.stderr" >&2
        exit 1
    }

if FAKE_COMPOSE_SHORT_IDS=1 \
    FAKE_INVALID_CANONICAL_ID_SERVICE=app \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/invalid-provider-id.stdout" \
    2>"$work_dir/invalid-provider-id.stderr"; then
    printf 'Restore operator test: malformed canonical provider ID was accepted\n' >&2
    exit 1
fi
unset FAKE_COMPOSE_SHORT_IDS FAKE_INVALID_CANONICAL_ID_SERVICE
grep -Fq 'service returned an unsafe exact container ID: app' \
    "$work_dir/invalid-provider-id.stderr" || {
        cat "$work_dir/invalid-provider-id.stderr" >&2
        exit 1
    }
[ ! -e "$state_dir/maintenance-lock-id" ]

portable_project=restoretarget
portable_db_volume=restoretarget_estab_db
portable_db_source=/var/lib/containers/storage/volumes/restoretarget_estab_db/_data
portable_app_source=/srv/restoretarget/data/4fdata
portable_export_source=/srv/restoretarget/data/export
portable_bind_db_source=/srv/restoretarget/data/db
portable_old_app_source=/var/lib/containers/storage/volumes/restoretest_estab_data/_data
portable_old_export_source=/var/lib/containers/storage/volumes/restoretest_estab_export/_data
portable_app_id=sha256:8888888888888888888888888888888888888888888888888888888888888888
portable_migrate_id=sha256:7777777777777777777777777777777777777777777777777777777777777777
portable_db_id=sha256:6666666666666666666666666666666666666666666666666666666666666666

run_named_to_bind_restore()
{
    run_restore \
        --confirm-project "$portable_project" \
        --remap-project restoretest="$portable_project" \
        --remap-storage \
            database:/var/lib/containers/storage/volumes/restoretest_estab_db/_data="$portable_bind_db_source" \
        --remap-volume database:restoretest_estab_db=- \
        --remap-storage \
            application:"$portable_old_app_source"="$portable_app_source" \
        --remap-volume application:restoretest_estab_data=- \
        --remap-storage \
            export:"$portable_old_export_source"="$portable_export_source" \
        --remap-volume export:restoretest_estab_export=- \
        "$@" \
        "${PORTABLE_BACKUP_OVERRIDE:-$backup_portable}"
}

if FAKE_PROJECT=$portable_project \
    FAKE_DB_MOUNT_TYPE=bind \
    FAKE_DB_VOLUME_NAME= \
    FAKE_DB_SOURCE=$portable_bind_db_source \
    FAKE_APP_MOUNT_TYPE=bind \
    FAKE_APP_VOLUME_NAME= \
    FAKE_APP_SOURCE=$portable_app_source \
    FAKE_EXPORT_MOUNT_TYPE=bind \
    FAKE_EXPORT_VOLUME_NAME= \
    FAKE_EXPORT_SOURCE=$portable_export_source \
    run_named_to_bind_restore \
        >"$work_dir/missing-type.stdout" \
        2>"$work_dir/missing-type.stderr"; then
    printf 'Restore operator test: Named-to-Bind change without type remaps accepted\n' >&2
    exit 1
fi
grep -Fq 'do not match the verified backup metadata or explicit role remaps' \
    "$work_dir/missing-type.stderr"

if FAKE_PROJECT=$portable_project \
    FAKE_DB_MOUNT_TYPE=bind \
    FAKE_DB_VOLUME_NAME= \
    FAKE_DB_SOURCE=$portable_bind_db_source \
    FAKE_APP_MOUNT_TYPE=bind \
    FAKE_APP_VOLUME_NAME= \
    FAKE_APP_SOURCE=$portable_app_source \
    FAKE_EXPORT_MOUNT_TYPE=bind \
    FAKE_EXPORT_VOLUME_NAME= \
    FAKE_EXPORT_SOURCE=$portable_export_source \
    run_named_to_bind_restore \
        --remap-mount-type database:bind=volume \
        --remap-mount-type application:volume=bind \
        --remap-mount-type export:volume=bind \
        >"$work_dir/wrong-type.stdout" \
        2>"$work_dir/wrong-type.stderr"; then
    printf 'Restore operator test: incorrect mount-type remap accepted\n' >&2
    exit 1
fi
grep -Fq 'do not match the verified backup metadata or explicit role remaps' \
    "$work_dir/wrong-type.stderr"

if FAKE_PROJECT=$portable_project \
    FAKE_DB_MOUNT_TYPE=bind \
    FAKE_DB_VOLUME_NAME= \
    FAKE_DB_SOURCE=$portable_bind_db_source \
    FAKE_APP_MOUNT_TYPE=bind \
    FAKE_APP_VOLUME_NAME= \
    FAKE_APP_SOURCE=$portable_app_source \
    FAKE_EXPORT_MOUNT_TYPE=bind \
    FAKE_EXPORT_VOLUME_NAME= \
    FAKE_EXPORT_SOURCE=$portable_export_source \
    run_named_to_bind_restore \
        --remap-mount-type database:volume=bind \
        --remap-mount-type application:volume=bind \
        >"$work_dir/partial-type.stdout" \
        2>"$work_dir/partial-type.stderr"; then
    printf 'Restore operator test: partial mount-type remaps accepted\n' >&2
    exit 1
fi
grep -Fq 'do not match the verified backup metadata or explicit role remaps' \
    "$work_dir/partial-type.stderr"
unset \
    FAKE_PROJECT \
    FAKE_DB_MOUNT_TYPE \
    FAKE_DB_VOLUME_NAME \
    FAKE_DB_SOURCE \
    FAKE_APP_MOUNT_TYPE \
    FAKE_APP_VOLUME_NAME \
    FAKE_APP_SOURCE \
    FAKE_EXPORT_MOUNT_TYPE \
    FAKE_EXPORT_VOLUME_NAME \
    FAKE_EXPORT_SOURCE

if FAKE_PROJECT=$portable_project \
    run_restore --confirm-project "$portable_project" "$backup_dir" \
    >"$work_dir/missing-project.stdout" \
    2>"$work_dir/missing-project.stderr"; then
    printf 'Restore operator test: differing project without explicit remap accepted\n' >&2
    exit 1
fi
grep -Fq 'provide the exact explicit --remap-project SOURCE=TARGET' \
    "$work_dir/missing-project.stderr"

if FAKE_PROJECT=$portable_project \
    run_restore \
        --confirm-project "$portable_project" \
        --remap-project restoretest=wrong-target \
        "$backup_dir" \
        >"$work_dir/wrong-project.stdout" \
        2>"$work_dir/wrong-project.stderr"; then
    printf 'Restore operator test: incorrect project remap accepted\n' >&2
    exit 1
fi
grep -Fq 'project remap does not exactly map' \
    "$work_dir/wrong-project.stderr"

if FAKE_PROJECT=$portable_project \
    FAKE_DB_VOLUME_NAME=$portable_db_volume \
    FAKE_DB_SOURCE=$portable_db_source \
    FAKE_APP_SOURCE=$portable_app_source \
    FAKE_EXPORT_SOURCE=$portable_export_source \
    run_restore \
        --confirm-project "$portable_project" \
        --remap-project restoretest="$portable_project" \
        --remap-storage \
            database:/var/lib/containers/storage/volumes/restoretest_estab_db/_data="$portable_db_source" \
        --remap-volume \
            database:restoretest_estab_db="$portable_db_volume" \
        --remap-storage \
            application:/srv/estab/data/4fdata="$portable_app_source" \
        "$backup_dir" \
        >"$work_dir/partial-storage.stdout" \
        2>"$work_dir/partial-storage.stderr"; then
    printf 'Restore operator test: partial role-specific storage remaps accepted\n' >&2
    exit 1
fi
grep -Fq 'do not match the verified backup metadata or explicit role remaps' \
    "$work_dir/partial-storage.stderr"

if FAKE_PROJECT=$portable_project \
    FAKE_DB_VOLUME_NAME=$portable_db_volume \
    FAKE_DB_SOURCE=$portable_db_source \
    FAKE_APP_SOURCE=$portable_app_source \
    FAKE_EXPORT_SOURCE=$portable_export_source \
    run_restore \
        --confirm-project "$portable_project" \
        --remap-project restoretest="$portable_project" \
        --remap-storage \
            database:/var/lib/containers/storage/volumes/restoretest_estab_db/_data="$portable_db_source" \
        --remap-volume \
            database:restoretest_estab_db="$portable_db_volume" \
        --remap-storage \
            application:/srv/estab/data/4fdata="$portable_app_source" \
        --remap-storage \
            export:/srv/estab/data/export=/srv/wrong/export \
        "$backup_dir" \
        >"$work_dir/wrong-storage.stdout" \
        2>"$work_dir/wrong-storage.stderr"; then
    printf 'Restore operator test: incorrect storage remap accepted\n' >&2
    exit 1
fi
grep -Fq 'do not match the verified backup metadata or explicit role remaps' \
    "$work_dir/wrong-storage.stderr"

if FAKE_PROJECT=$portable_project \
    FAKE_DB_VOLUME_NAME=$portable_db_volume \
    FAKE_DB_SOURCE=$portable_db_source \
    FAKE_APP_SOURCE=$portable_app_source \
    FAKE_EXPORT_SOURCE=$portable_export_source \
    run_restore \
        --confirm-project "$portable_project" \
        --remap-project restoretest="$portable_project" \
        --remap-storage \
            database:/var/lib/containers/storage/volumes/restoretest_estab_db/_data="$portable_db_source" \
        --remap-storage \
            application:/srv/estab/data/4fdata="$portable_app_source" \
        --remap-storage \
            export:/srv/estab/data/export="$portable_export_source" \
        "$backup_dir" \
        >"$work_dir/missing-volume.stdout" \
        2>"$work_dir/missing-volume.stderr"; then
    printf 'Restore operator test: changed named volume without explicit remap accepted\n' >&2
    exit 1
fi
grep -Fq 'do not match the verified backup metadata or explicit role remaps' \
    "$work_dir/missing-volume.stderr"

if FAKE_APP_IMAGE_ID=sha256:9999999999999999999999999999999999999999999999999999999999999999 \
    run_restore --confirm-project restoretest "$backup_dir" \
    >/dev/null 2>&1; then
    printf 'Restore operator test: runtime image mismatch accepted\n' >&2
    exit 1
fi
unset FAKE_APP_IMAGE_ID

if FAKE_PROJECT=$portable_project \
    FAKE_DB_VOLUME_NAME=$portable_db_volume \
    FAKE_DB_SOURCE=$portable_db_source \
    FAKE_APP_SOURCE=$portable_app_source \
    FAKE_EXPORT_SOURCE=$portable_export_source \
    FAKE_APP_IMAGE_ID=$portable_app_id \
    FAKE_MIGRATE_IMAGE_ID=$portable_migrate_id \
    FAKE_DB_IMAGE_ID=$portable_db_id \
    run_restore \
        --confirm-project "$portable_project" \
        --remap-project restoretest="$portable_project" \
        --remap-storage \
            database:/var/lib/containers/storage/volumes/restoretest_estab_db/_data="$portable_db_source" \
        --remap-volume \
            database:restoretest_estab_db="$portable_db_volume" \
        --remap-storage \
            application:/srv/estab/data/4fdata="$portable_app_source" \
        --remap-storage \
            export:/srv/estab/data/export="$portable_export_source" \
        "$backup_dir" \
        >"$work_dir/missing-runtime-opt-in.stdout" \
        2>"$work_dir/missing-runtime-opt-in.stderr"; then
    printf 'Restore operator test: changed runtime image IDs without opt-in accepted\n' >&2
    exit 1
fi
grep -Fq 'runtime image IDs differ from the backup' \
    "$work_dir/missing-runtime-opt-in.stderr"

if FAKE_PROJECT=$portable_project \
    FAKE_DB_VOLUME_NAME=$portable_db_volume \
    FAKE_DB_SOURCE=$portable_db_source \
    FAKE_APP_SOURCE=$portable_app_source \
    FAKE_EXPORT_SOURCE=$portable_export_source \
    FAKE_APP_IMAGE_ID=$portable_app_id \
    FAKE_MIGRATE_IMAGE_ID=$portable_migrate_id \
    FAKE_DB_IMAGE_ID=$portable_db_id \
    FAKE_APP_IMAGE_REFERENCE=ghcr.io/e-stab/estab@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd \
    run_restore \
        --confirm-project "$portable_project" \
        --remap-project restoretest="$portable_project" \
        --remap-storage \
            database:/var/lib/containers/storage/volumes/restoretest_estab_db/_data="$portable_db_source" \
        --remap-volume \
            database:restoretest_estab_db="$portable_db_volume" \
        --remap-storage \
            application:/srv/estab/data/4fdata="$portable_app_source" \
        --remap-storage \
            export:/srv/estab/data/export="$portable_export_source" \
        --allow-runtime-image-id-change \
        "$backup_dir" \
        >"$work_dir/wrong-release.stdout" \
        2>"$work_dir/wrong-release.stderr"; then
    printf 'Restore operator test: differing canonical release identity accepted\n' >&2
    exit 1
fi
grep -Fq 'Config.Image references differ from the backup release identity' \
    "$work_dir/wrong-release.stderr"

if FAKE_PROJECT=restoretest \
    FAKE_DB_VOLUME_NAME=restoretest_estab_db \
    FAKE_DB_SOURCE=/var/lib/containers/storage/volumes/restoretest_estab_db/_data \
    FAKE_APP_SOURCE=/srv/estab/data/4fdata \
    FAKE_EXPORT_SOURCE=/srv/estab/data/export \
    FAKE_APP_IMAGE_ID=$portable_app_id \
    FAKE_MIGRATE_IMAGE_ID=$portable_migrate_id \
    FAKE_DB_IMAGE_ID=$portable_db_id \
    FAKE_APP_IMAGE_REFERENCE=ghcr.io/e-stab/estab:portable-test \
    FAKE_MIGRATE_IMAGE_REFERENCE=ghcr.io/e-stab/estab-migrate:portable-test \
    FAKE_DB_IMAGE_REFERENCE=mariadb:11.8 \
    run_restore \
        --confirm-project restoretest \
        --allow-runtime-image-id-change \
        "$backup_mutable" \
        >"$work_dir/mutable-release.stdout" \
        2>"$work_dir/mutable-release.stderr"; then
    printf 'Restore operator test: mutable release references authorized a runtime-ID change\n' >&2
    exit 1
fi
grep -Fq 'mutable or local tags are forbidden' \
    "$work_dir/mutable-release.stderr" || {
        cat "$work_dir/mutable-release.stderr" >&2
        exit 1
    }

if run_restore \
    --confirm-project restoretest \
    --allow-runtime-image-id-change \
    "$backup_v2" \
    >"$work_dir/v2-portable.stdout" \
    2>"$work_dir/v2-portable.stderr"; then
    printf 'Restore operator test: format 2 authorized a runtime-ID change opt-in\n' >&2
    exit 1
fi
grep -Fq 'format-2 backups cannot authorize runtime image ID changes' \
    "$work_dir/v2-portable.stderr"

if run_restore \
    --confirm-project restoretarget \
    --remap-project restoretest=restoretarget \
    "$backup_v2" \
    >"$work_dir/v2-project-remap.stdout" \
    2>"$work_dir/v2-project-remap.stderr"; then
    printf 'Restore operator test: format 2 accepted a project remap\n' >&2
    exit 1
fi
grep -Fq 'format-2 backups require an exact same-host restore' \
    "$work_dir/v2-project-remap.stderr"

if run_restore \
    --confirm-project restoretest \
    --remap-storage application:/srv/estab/data/4fdata=/srv/restoretarget/data/4fdata \
    "$backup_v2" \
    >"$work_dir/v2-storage-remap.stdout" \
    2>"$work_dir/v2-storage-remap.stderr"; then
    printf 'Restore operator test: format 2 accepted a storage remap\n' >&2
    exit 1
fi
grep -Fq 'format-2 backups require an exact same-host restore' \
    "$work_dir/v2-storage-remap.stderr"

if run_restore \
    --confirm-project restoretest \
    --remap-mount-type database:volume=bind \
    "$backup_v2" \
    >"$work_dir/v2-mount-type-remap.stdout" \
    2>"$work_dir/v2-mount-type-remap.stderr"; then
    printf 'Restore operator test: format 2 accepted a mount-type remap\n' >&2
    exit 1
fi
grep -Fq 'format-2 backups require an exact same-host restore' \
    "$work_dir/v2-mount-type-remap.stderr"

if run_restore \
    --confirm-project restoretest \
    --remap-volume database:restoretest_estab_db=restoretarget_estab_db \
    "$backup_v2" \
    >"$work_dir/v2-volume-remap.stdout" \
    2>"$work_dir/v2-volume-remap.stderr"; then
    printf 'Restore operator test: format 2 accepted a volume remap\n' >&2
    exit 1
fi
grep -Fq 'format-2 backups require an exact same-host restore' \
    "$work_dir/v2-volume-remap.stderr"
unset \
    FAKE_PROJECT \
    FAKE_DB_VOLUME_NAME \
    FAKE_DB_SOURCE \
    FAKE_APP_SOURCE \
    FAKE_EXPORT_SOURCE \
    FAKE_APP_IMAGE_ID \
    FAKE_MIGRATE_IMAGE_ID \
    FAKE_DB_IMAGE_ID \
    FAKE_APP_IMAGE_REFERENCE \
    FAKE_MIGRATE_IMAGE_REFERENCE \
    FAKE_DB_IMAGE_REFERENCE

insecure_snapshot_parent=$work_dir/insecure-restore-snapshots
mkdir -p "$insecure_snapshot_parent"
chmod 0777 "$insecure_snapshot_parent"
if ESTAB_RESTORE_SNAPSHOT_PARENT=$insecure_snapshot_parent \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/insecure-snapshot-parent.stdout" \
    2>"$work_dir/insecure-snapshot-parent.stderr"; then
    printf 'Restore operator test: insecure snapshot parent was accepted\n' >&2
    exit 1
fi
unset ESTAB_RESTORE_SNAPSHOT_PARENT
grep -Fq 'group/world-writable restore snapshot parent requires the sticky bit' \
    "$work_dir/insecure-snapshot-parent.stderr"
chmod 0700 "$insecure_snapshot_parent"

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
if FAKE_READ_ONLY_MOUNT=database \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/read-only-database.stdout" \
    2>"$work_dir/read-only-database.stderr"; then
    printf 'Restore operator test: read-only database mount was accepted\n' >&2
    exit 1
fi
unset FAKE_READ_ONLY_MOUNT
grep -Fq 'not explicitly read/write for database' \
    "$work_dir/read-only-database.stderr"
[ "$(operation_count)" = "$preflight_operations_before" ]
[ "$(database_operation_count)" = "$preflight_database_before" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

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
grep -Fq 'admin authentication initializer does not use the current verified app image' \
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

foreign_operations_before=$(operation_count)
foreign_database_before=$(database_operation_count)
if FAKE_PROJECT=$portable_project \
    FAKE_DB_MOUNT_TYPE=bind \
    FAKE_DB_VOLUME_NAME= \
    FAKE_DB_SOURCE=$portable_bind_db_source \
    FAKE_APP_MOUNT_TYPE=bind \
    FAKE_APP_VOLUME_NAME= \
    FAKE_APP_SOURCE=$portable_app_source \
    FAKE_EXPORT_MOUNT_TYPE=bind \
    FAKE_EXPORT_VOLUME_NAME= \
    FAKE_EXPORT_SOURCE=$portable_export_source \
    FAKE_APP_IMAGE_ID=$portable_app_id \
    FAKE_MIGRATE_IMAGE_ID=$portable_migrate_id \
    FAKE_DB_IMAGE_ID=$portable_db_id \
    FAKE_FOREIGN_MOUNT_SOURCE=/srv/restoretarget/data \
    run_named_to_bind_restore \
        --remap-mount-type database:volume=bind \
        --remap-mount-type application:volume=bind \
        --remap-mount-type export:volume=bind \
        --allow-runtime-image-id-change \
        >"$work_dir/foreign-overlap.stdout" \
        2>"$work_dir/foreign-overlap.stderr"; then
    printf 'Restore operator test: foreign stopped-container storage overlap was accepted\n' >&2
    exit 1
fi
grep -Fq \
    'foreign container 9999999999999999999999999999999999999999999999999999999999999999' \
    "$work_dir/foreign-overlap.stderr"
grep -Fq 'equal to or overlapping target' \
    "$work_dir/foreign-overlap.stderr"
[ "$(operation_count)" = "$foreign_operations_before" ]
[ "$(database_operation_count)" = "$foreign_database_before" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
unset \
    FAKE_PROJECT \
    FAKE_DB_MOUNT_TYPE \
    FAKE_DB_VOLUME_NAME \
    FAKE_DB_SOURCE \
    FAKE_APP_MOUNT_TYPE \
    FAKE_APP_VOLUME_NAME \
    FAKE_APP_SOURCE \
    FAKE_EXPORT_MOUNT_TYPE \
    FAKE_EXPORT_VOLUME_NAME \
    FAKE_EXPORT_SOURCE \
    FAKE_APP_IMAGE_ID \
    FAKE_MIGRATE_IMAGE_ID \
    FAKE_DB_IMAGE_ID \
    FAKE_FOREIGN_MOUNT_SOURCE

same_project_foreign_operations_before=$(operation_count)
same_project_foreign_database_before=$(database_operation_count)
if FAKE_PROJECT=restoretest \
    FAKE_DB_MOUNT_TYPE=bind \
    FAKE_DB_VOLUME_NAME= \
    FAKE_DB_SOURCE=$portable_bind_db_source \
    FAKE_APP_MOUNT_TYPE=bind \
    FAKE_APP_VOLUME_NAME= \
    FAKE_APP_SOURCE=$portable_app_source \
    FAKE_EXPORT_MOUNT_TYPE=bind \
    FAKE_EXPORT_VOLUME_NAME= \
    FAKE_EXPORT_SOURCE=$portable_export_source \
    FAKE_FOREIGN_MOUNT_SOURCE=/srv/restoretarget/data \
    run_restore \
        --confirm-project restoretest \
        --remap-mount-type database:volume=bind \
        --remap-storage \
            database:/var/lib/containers/storage/volumes/restoretest_estab_db/_data="$portable_bind_db_source" \
        --remap-volume database:restoretest_estab_db=- \
        --remap-mount-type application:volume=bind \
        --remap-storage \
            application:"$portable_old_app_source"="$portable_app_source" \
        --remap-volume application:restoretest_estab_data=- \
        --remap-mount-type export:volume=bind \
        --remap-storage \
            export:"$portable_old_export_source"="$portable_export_source" \
        --remap-volume export:restoretest_estab_export=- \
        "$backup_portable" \
        >"$work_dir/same-project-foreign-overlap.stdout" \
        2>"$work_dir/same-project-foreign-overlap.stderr"; then
    printf 'Restore operator test: same-project layout remap ignored a foreign storage consumer\n' >&2
    exit 1
fi
grep -Fq \
    'foreign container 9999999999999999999999999999999999999999999999999999999999999999' \
    "$work_dir/same-project-foreign-overlap.stderr"
grep -Fq 'equal to or overlapping target' \
    "$work_dir/same-project-foreign-overlap.stderr"
[ "$(operation_count)" = "$same_project_foreign_operations_before" ]
[ "$(database_operation_count)" = "$same_project_foreign_database_before" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
unset \
    FAKE_PROJECT \
    FAKE_DB_MOUNT_TYPE \
    FAKE_DB_VOLUME_NAME \
    FAKE_DB_SOURCE \
    FAKE_APP_MOUNT_TYPE \
    FAKE_APP_VOLUME_NAME \
    FAKE_APP_SOURCE \
    FAKE_EXPORT_MOUNT_TYPE \
    FAKE_EXPORT_VOLUME_NAME \
    FAKE_EXPORT_SOURCE \
    FAKE_FOREIGN_MOUNT_SOURCE

malformed_foreign_row=$(printf 'bind\t/srv/foreign\textra-field')
malformed_foreign_operations_before=$(operation_count)
malformed_foreign_database_before=$(database_operation_count)
if FAKE_FOREIGN_MOUNT_ROW=$malformed_foreign_row \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/malformed-foreign-row.stdout" \
    2>"$work_dir/malformed-foreign-row.stderr"; then
    printf 'Restore operator test: foreign mount row with extra fields was accepted\n' >&2
    exit 1
fi
unset FAKE_FOREIGN_MOUNT_ROW
grep -Fq 'unsafe or uninspectable productive mount source' \
    "$work_dir/malformed-foreign-row.stderr"
[ "$(operation_count)" = "$malformed_foreign_operations_before" ]
[ "$(database_operation_count)" = "$malformed_foreign_database_before" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

unknown_foreign_row=$(printf 'cluster\t/srv/foreign')
if FAKE_FOREIGN_MOUNT_ROW=$unknown_foreign_row \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/unknown-foreign-row.stdout" \
    2>"$work_dir/unknown-foreign-row.stderr"; then
    printf 'Restore operator test: uninspectable foreign storage type was accepted\n' >&2
    exit 1
fi
unset FAKE_FOREIGN_MOUNT_ROW
grep -Fq 'unsafe or uninspectable productive mount source' \
    "$work_dir/unknown-foreign-row.stderr"
[ "$(operation_count)" = "$malformed_foreign_operations_before" ]
[ "$(database_operation_count)" = "$malformed_foreign_database_before" ]
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
    "$work_dir/operator-overlap.stderr" || {
        cat "$work_dir/operator-overlap.stderr" >&2
        exit 1
    }
[ "$(operation_count)" = "$overlap_operations_after" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

if PATH=$darwin_bin:$PATH \
    RESTORE_CONTAINER_CLI=docker \
    FAKE_OPERATOR_SOURCE=/host_mnt$operator_source \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/docker-host-mnt-overlap.stdout" \
    2>"$work_dir/docker-host-mnt-overlap.stderr"; then
    printf 'Restore operator test: Docker Desktop /host_mnt backup overlap was accepted\n' >&2
    exit 1
fi
PATH=$original_test_path
unset RESTORE_CONTAINER_CLI FAKE_OPERATOR_SOURCE
grep -Fq 'backup directory overlaps a productive storage source' \
    "$work_dir/docker-host-mnt-overlap.stderr"
[ "$(operation_count)" = "$overlap_operations_after" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

if PATH=$darwin_bin:$PATH \
    RESTORE_CONTAINER_CLI=docker \
    FAKE_OPERATOR_SOURCE=/run/desktop/mnt/host$operator_source \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/docker-desktop-host-overlap.stdout" \
    2>"$work_dir/docker-desktop-host-overlap.stderr"; then
    printf 'Restore operator test: Docker Desktop /run/desktop backup overlap was accepted\n' >&2
    exit 1
fi
PATH=$original_test_path
unset RESTORE_CONTAINER_CLI FAKE_OPERATOR_SOURCE
grep -Fq 'backup directory overlaps a productive storage source' \
    "$work_dir/docker-desktop-host-overlap.stderr"
[ "$(operation_count)" = "$overlap_operations_after" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

docker_snapshot_parent=$work_dir/docker-snapshot-parent
mkdir -p "$docker_snapshot_parent"
chmod 0700 "$docker_snapshot_parent"
docker_snapshot_parent=$(CDPATH= cd -- "$docker_snapshot_parent" && pwd -P)
if PATH=$darwin_bin:$PATH \
    RESTORE_CONTAINER_CLI=docker \
    ESTAB_RESTORE_SNAPSHOT_PARENT=$docker_snapshot_parent \
    FAKE_APP_SOURCE=/host_mnt$docker_snapshot_parent \
    run_restore \
        --confirm-project restoretest \
        --remap-storage \
            application:/srv/estab/data/4fdata=/host_mnt$docker_snapshot_parent \
        "$backup_dir" \
        >"$work_dir/docker-snapshot-overlap.stdout" \
        2>"$work_dir/docker-snapshot-overlap.stderr"; then
    printf 'Restore operator test: Docker Desktop snapshot-parent overlap was accepted\n' >&2
    exit 1
fi
PATH=$original_test_path
unset \
    RESTORE_CONTAINER_CLI \
    ESTAB_RESTORE_SNAPSHOT_PARENT \
    FAKE_APP_SOURCE
grep -Fq 'restore snapshot parent overlaps a productive storage source' \
    "$work_dir/docker-snapshot-overlap.stderr" || {
        cat "$work_dir/docker-snapshot-overlap.stderr" >&2
        exit 1
    }
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

snapshot_parent=$work_dir/restore-snapshots
mkdir -p "$snapshot_parent"
chmod 0700 "$snapshot_parent"
rm -f -- "$state_dir/source-backup-mutated"
ESTAB_RESTORE_SNAPSHOT_PARENT=$snapshot_parent \
FAKE_MUTATE_BACKUP_AFTER_SNAPSHOT=$backup_tocou \
    run_restore --confirm-project restoretest "$backup_tocou" >/dev/null
[ -e "$state_dir/source-backup-mutated" ]
if sh "$backup_verifier" "$backup_tocou" estab >/dev/null 2>&1; then
    printf 'Restore operator test: source backup was not mutated by the TOCTOU fixture\n' >&2
    exit 1
fi
grep -Fq -- '-- Dump completed on ' "$state_dir/restored-database.sql"
cmp "$source_data/estab/anhang/file.txt" \
    "$runtime_data/estab/anhang/file.txt"
cmp "$source_export/run/export.txt" \
    "$runtime_export/run/export.txt"
if [ -n "$(find "$snapshot_parent" -mindepth 1 -print -quit)" ]; then
    printf 'Restore operator test: successful restore retained a private snapshot\n' >&2
    exit 1
fi
rm -f -- "$state_dir/source-backup-mutated"
unset \
    ESTAB_RESTORE_SNAPSHOT_PARENT \
    FAKE_MUTATE_BACKUP_AFTER_SNAPSHOT

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

retained_snapshot=$(
    sed -n \
        's/^eStab restore: retained verified recovery snapshot: //p' \
        "$failure_log" |
        tail -n 1
)
[ -n "$retained_snapshot" ]
[ -d "$retained_snapshot" ]
[ ! -L "$retained_snapshot" ]
sh "$backup_verifier" "$retained_snapshot" estab >/dev/null

retained_lock_id=$(cat "$state_dir/maintenance-lock-id")
ESTAB_CONTAINER_CLI=$fake_cli \
FAKE_STATE=$state_dir \
    "$fake_cli" container rm --force "$retained_lock_id"
[ ! -e "$state_dir/maintenance-lock-id" ]

run_restore --confirm-project restoretest "$retained_snapshot" >/dev/null
[ ! -e "$state_dir/app-stopped" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
sh "$backup_verifier" "$retained_snapshot" estab >/dev/null
grep -Fq -- '-- Dump completed on ' "$state_dir/restored-database.sql"
cmp "$source_data/estab/anhang/file.txt" \
    "$runtime_data/estab/anhang/file.txt"
cmp "$source_export/run/export.txt" \
    "$runtime_export/run/export.txt"
[ ! -e "$runtime_data/stale" ]
[ ! -e "$runtime_export/stale" ]
grep -Fq 'run-migrate' "$state_dir/operations"
grep -Fq 'up-app' "$state_dir/operations"

assert_external_restart_rejected()
{
    restart_point=$1
    expected_stage=$2
    restart_log=$work_dir/restart-$restart_point.stderr
    if FAKE_RESTART_APP_AT=$restart_point \
        run_restore --confirm-project restoretest "$backup_dir" \
        >"$work_dir/restart-$restart_point.stdout" \
        2>"$restart_log"; then
        printf 'Restore operator test: external app restart at %s reported success\n' \
            "$restart_point" >&2
        exit 1
    fi
    unset FAKE_RESTART_APP_AT
    grep -Fq "stage=$expected_stage" "$restart_log" || {
        cat "$restart_log" >&2
        exit 1
    }
    grep -Fq \
        'the exact application container ID, project, stopped flag, and non-running lifecycle status are proven' \
        "$restart_log"
    grep -Fq 'retained fail-closed maintenance lock' "$restart_log"
    [ -e "$state_dir/app-stopped" ]
    [ -e "$state_dir/maintenance-lock-id" ]
    remove_fake_maintenance_lock
}

assert_external_restart_rejected database-import database-import
assert_external_restart_rejected export-extract export-data-extract
assert_external_restart_rejected migration migration

unverified_lock_log=$work_dir/unverified-lock.stderr
if FAKE_FAIL_EXPORT_RESTORE=1 \
    FAKE_LOSE_LOCK_ON_FAILURE=1 \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/unverified-lock.stdout" \
    2>"$unverified_lock_log"; then
    printf 'Restore operator test: restore with a lost maintenance lock reported success\n' >&2
    exit 1
fi
unset FAKE_FAIL_EXPORT_RESTORE FAKE_LOSE_LOCK_ON_FAILURE
grep -Fq \
    'WARNING: ownership and running state of the expected maintenance lock cannot be proven; no retained-lock claim is made' \
    "$unverified_lock_log"
grep -Fq \
    'WARNING: refusing to remove or claim retention of an unverified maintenance lock' \
    "$unverified_lock_log"
if grep -Fq 'retained fail-closed maintenance lock' \
    "$unverified_lock_log"; then
    printf 'Restore operator test: unverified lock was claimed as retained\n' >&2
    exit 1
fi
[ -e "$state_dir/app-stopped" ]
[ -e "$state_dir/maintenance-lock-id" ]
remove_fake_maintenance_lock

assert_transient_app_ps_rejected()
{
    transient_mode=$1
    transient_log=$work_dir/transient-app-ps-$transient_mode.stderr
    if FAKE_APP_PS_AFTER_START=$transient_mode \
        run_restore --confirm-project restoretest "$backup_dir" \
        >"$work_dir/transient-app-ps-$transient_mode.stdout" \
        2>"$transient_log"; then
        printf 'Restore operator test: transient %s app resolution reported success\n' \
            "$transient_mode" >&2
        exit 1
    fi
    unset FAKE_APP_PS_AFTER_START
    grep -Fq 'stage=application-start' "$transient_log" || {
        cat "$transient_log" >&2
        exit 1
    }
    grep -Fq \
        'the exact application container ID, project, stopped flag, and non-running lifecycle status are proven' \
        "$transient_log"
    grep -Fq 'retained fail-closed maintenance lock' "$transient_log"
    [ -e "$state_dir/app-stopped" ]
    [ -e "$state_dir/maintenance-lock-id" ]
    remove_fake_maintenance_lock
}

assert_transient_app_ps_rejected empty
assert_transient_app_ps_rejected multiple

run_restore --confirm-project restoretest "$backup_dir" >/dev/null
[ ! -e "$state_dir/app-stopped" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

FAKE_COMPOSE_SHORT_IDS=1 \
FAKE_REJECT_COMPOSE_PS=1 \
    run_restore --confirm-project restoretest "$backup_dir" \
    >"$work_dir/podman-short-ids.stdout" \
    2>"$work_dir/podman-short-ids.stderr"
unset FAKE_COMPOSE_SHORT_IDS FAKE_REJECT_COMPOSE_PS
[ ! -e "$state_dir/app-stopped" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
grep -Fq 'inspect --format {{.Id}} app-short' "$state_dir/events"
grep -Fq \
    'ps --all --no-trunc --filter label=com.docker.compose.project=restoretest --filter label=com.docker.compose.service=app --format {{.ID}}' \
    "$state_dir/events"
grep -Fq 'stop app-container' "$state_dir/events"
grep -Fq -- '--volumes-from app-container:z' "$state_dir/events"

run_restore --confirm-project restoretest "$backup_v2" >/dev/null
[ ! -e "$state_dir/app-stopped" ]
[ ! -e "$state_dir/maintenance-lock-id" ]

FAKE_PROJECT=$portable_project \
FAKE_DB_MOUNT_TYPE=bind \
FAKE_DB_VOLUME_NAME= \
FAKE_DB_SOURCE=$portable_bind_db_source \
FAKE_APP_MOUNT_TYPE=bind \
FAKE_APP_VOLUME_NAME= \
FAKE_APP_SOURCE=$portable_app_source \
FAKE_EXPORT_MOUNT_TYPE=bind \
FAKE_EXPORT_VOLUME_NAME= \
FAKE_EXPORT_SOURCE=$portable_export_source \
FAKE_APP_IMAGE_ID=$portable_app_id \
FAKE_MIGRATE_IMAGE_ID=$portable_migrate_id \
FAKE_DB_IMAGE_ID=$portable_db_id \
FAKE_APP_IMAGE_REFERENCE=registry.example:5443/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
FAKE_MIGRATE_IMAGE_REFERENCE=ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
FAKE_DB_IMAGE_REFERENCE=docker.io/library/mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc \
PORTABLE_BACKUP_OVERRIDE=$backup_cross_engine \
    run_named_to_bind_restore \
        --remap-mount-type database:volume=bind \
        --remap-mount-type application:volume=bind \
        --remap-mount-type export:volume=bind \
        --allow-runtime-image-id-change \
        >"$work_dir/cross-engine.stdout" \
        2>"$work_dir/cross-engine.stderr"
[ ! -e "$state_dir/app-stopped" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
unset \
    FAKE_APP_IMAGE_REFERENCE \
    FAKE_MIGRATE_IMAGE_REFERENCE \
    FAKE_DB_IMAGE_REFERENCE \
    PORTABLE_BACKUP_OVERRIDE

FAKE_PROJECT=$portable_project \
FAKE_DB_MOUNT_TYPE=bind \
FAKE_DB_VOLUME_NAME= \
FAKE_DB_SOURCE=$portable_bind_db_source \
FAKE_APP_MOUNT_TYPE=bind \
FAKE_APP_VOLUME_NAME= \
FAKE_APP_SOURCE=$portable_app_source \
FAKE_EXPORT_MOUNT_TYPE=bind \
FAKE_EXPORT_VOLUME_NAME= \
FAKE_EXPORT_SOURCE=$portable_export_source \
FAKE_APP_IMAGE_ID=$portable_app_id \
FAKE_MIGRATE_IMAGE_ID=$portable_migrate_id \
FAKE_DB_IMAGE_ID=$portable_db_id \
    run_named_to_bind_restore \
        --remap-mount-type database:volume=bind \
        --remap-mount-type application:volume=bind \
        --remap-mount-type export:volume=bind \
        --allow-runtime-image-id-change \
        >/dev/null
[ ! -e "$state_dir/app-stopped" ]
[ ! -e "$state_dir/maintenance-lock-id" ]
cmp "$source_data/estab/anhang/file.txt" \
    "$runtime_data/estab/anhang/file.txt"
cmp "$source_export/run/export.txt" \
    "$runtime_export/run/export.txt"

printf 'Restore operator tests: OK\n'
