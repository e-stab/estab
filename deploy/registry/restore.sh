#!/bin/sh

set -eu
umask 077

die()
{
    printf 'eStab restore: %s\n' "$*" >&2
    exit 1
}

usage()
{
    printf 'Usage: %s --confirm-project COMPOSE_PROJECT ABSOLUTE_BACKUP_DIRECTORY\n' \
        "$0" >&2
    exit 64
}

[ "$#" -eq 3 ] || usage
[ "$1" = --confirm-project ] || usage
confirmed_project=$2
requested_backup=$3

case "$confirmed_project" in
    ''|[!a-z0-9]*|*[!a-z0-9_-]*)
        printf 'eStab restore: confirmed Compose project must use canonical lowercase letters, digits, underscores, or hyphens and start alphanumerically.\n' >&2
        exit 64
        ;;
esac
[ "${#confirmed_project}" -le 128 ] || {
    printf 'eStab restore: confirmed Compose project is too long for the maintenance lock.\n' >&2
    exit 64
}
case "$requested_backup" in
    /*) ;;
    *)
        printf 'eStab restore: backup directory must be an explicit absolute path.\n' >&2
        exit 64
        ;;
esac

backup_request_without_slash=${requested_backup%/}
case "$backup_request_without_slash" in
    ''|/)
        printf 'eStab restore: refusing unsafe backup directory: %s\n' \
            "$requested_backup" >&2
        exit 64
        ;;
esac
if [ ! -d "$backup_request_without_slash" ] ||
    [ -L "$backup_request_without_slash" ]; then
    die "backup directory is missing, unreadable, or a symbolic link: $requested_backup"
fi
backup_dir=$(CDPATH= cd -- "$backup_request_without_slash" && pwd -P) ||
    die "cannot resolve backup directory: $requested_backup"
[ "$backup_dir" != / ] ||
    die "refusing the filesystem root as backup directory"

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
backup_verifier=$script_dir/verify-backup.sh
[ -f "$backup_verifier" ] && [ ! -L "$backup_verifier" ] &&
    [ -r "$backup_verifier" ] ||
    die "backup verifier is missing or unreadable: $backup_verifier"

health_timeout_seconds=${ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS:-240}
case "$health_timeout_seconds" in
    ''|*[!0-9]*)
        die "ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS must be an integer from 1 to 3600"
        ;;
esac
if [ "${#health_timeout_seconds}" -gt 4 ] ||
    [ "$health_timeout_seconds" -lt 1 ] ||
    [ "$health_timeout_seconds" -gt 3600 ]; then
    die "ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS must be an integer from 1 to 3600"
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
    *' '*|*'	'*|*'
'*)
        die "ESTAB_CONTAINER_CLI must name one executable without arguments"
        ;;
esac
command -v "$container_cli" >/dev/null 2>&1 ||
    die "container executable is unavailable: $container_cli"
"$container_cli" compose version >/dev/null 2>&1 ||
    die "Compose is unavailable through $container_cli"

compose()
{
    "$container_cli" compose "$@"
}

inspect_value()
{
    inspect_container=$1
    inspect_template=$2
    "$container_cli" inspect --format "$inspect_template" "$inspect_container"
}

single_container()
{
    container_service=$1
    container_id=$(compose ps --all -q "$container_service") ||
        die "cannot resolve Compose service: $container_service"
    case "$container_id" in
        ''|*' '*|*'	'*|*'
'*)
            die "Compose service must resolve to exactly one container: $container_service"
            ;;
    esac
    printf '%s\n' "$container_id"
}

wait_for_healthy()
{
    health_service=$1
    health_container=$2
    health_elapsed=0

    while :; do
        health_running=$(inspect_value "$health_container" \
            '{{.State.Running}}' 2>/dev/null) || {
                printf 'eStab restore: cannot inspect Compose service while waiting for health: %s\n' \
                    "$health_service" >&2
                return 1
            }
        health_status=$(inspect_value "$health_container" \
            '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' \
            2>/dev/null) || {
                printf 'eStab restore: cannot inspect health status for Compose service: %s\n' \
                    "$health_service" >&2
                return 1
            }

        if [ "$health_running" != true ]; then
            printf 'eStab restore: Compose service stopped while waiting for health: %s\n' \
                "$health_service" >&2
            return 1
        fi
        case "$health_status" in
            healthy)
                return 0
                ;;
            unhealthy)
                printf 'eStab restore: Compose service became unhealthy: %s\n' \
                    "$health_service" >&2
                return 1
                ;;
            starting)
                ;;
            missing|''|'<no value>'|'<nil>')
                printf 'eStab restore: Compose service has no inspectable health check: %s\n' \
                    "$health_service" >&2
                return 1
                ;;
            *)
                printf 'eStab restore: Compose service reported an unknown health status (%s): %s\n' \
                    "$health_status" "$health_service" >&2
                return 1
                ;;
        esac

        if [ "$health_elapsed" -ge "$health_timeout_seconds" ]; then
            printf 'eStab restore: Compose service did not become healthy within %s seconds: %s\n' \
                "$health_timeout_seconds" "$health_service" >&2
            return 1
        fi
        health_sleep=2
        health_remaining=$((health_timeout_seconds - health_elapsed))
        if [ "$health_remaining" -lt "$health_sleep" ]; then
            health_sleep=$health_remaining
        fi
        sleep "$health_sleep"
        health_elapsed=$((health_elapsed + health_sleep))
    done
}

image_record()
{
    image_role=$1
    image_container=$2
    image_reference=$(inspect_value "$image_container" '{{.Config.Image}}' \
        2>/dev/null || :)
    case "$image_reference" in
        ''|'<no value>'|'<nil>'|null)
            image_reference=$(inspect_value "$image_container" \
                '{{.ImageName}}' 2>/dev/null || :)
            ;;
    esac
    case "$image_reference" in
        ''|*[!A-Za-z0-9_./:@+-]*)
            die "container image reference is empty or unsafe for $image_role"
            ;;
    esac
    image_digest=$(inspect_value "$image_container" '{{.Image}}') ||
        die "cannot read runtime image digest for $image_role"
    case "$image_digest" in
        sha256:*)
            image_digest_hex=${image_digest#sha256:}
            ;;
        *)
            image_digest_hex=$image_digest
            ;;
    esac
    case "$image_digest_hex" in
        ''|*[!0123456789abcdef]*)
            die "runtime image digest is invalid for $image_role"
            ;;
    esac
    [ "${#image_digest_hex}" -eq 64 ] ||
        die "runtime image digest is invalid for $image_role"
    printf '%s\t%s\tsha256:%s\n' \
        "$image_role" "$image_reference" "$image_digest_hex"
}

mount_record()
{
    mount_role=$1
    mount_container=$2
    mount_destination=$3
    mount_rows=$(inspect_value "$mount_container" \
        '{{range .Mounts}}{{printf "%s\t%s\t%s\t%s\n" .Destination .Type .Name .Source}}{{end}}') ||
        die "cannot inspect storage mount for $mount_role"
    mount_record_value=$(
        printf '%s\n' "$mount_rows" |
            LC_ALL=C awk -F '	' \
                -v role="$mount_role" -v destination="$mount_destination" '
                  function canonical_path(path) {
                    return path ~ /^\// &&
                      path !~ /\/\// &&
                      path !~ /\/\.\.?($|\/)/ &&
                      (path == "/" || path !~ /\/$/)
                  }
                  {
                    if (NF != 4 || !canonical_path($1)) {
                      invalid = 1
                    }
                    if ($1 != destination &&
                        ($1 == "/" ||
                         index($1, destination "/") == 1 ||
                         index(destination, $1 "/") == 1)) {
                      overlap = 1
                    }
                  }
                  $1 == destination {
                    matches++
                    name = $3
                    if (name == "") {
                      name = "-"
                    }
                    if (NF != 4 ||
                        ($2 != "volume" && $2 != "bind") ||
                        $4 !~ /^\// ||
                        name ~ /[^A-Za-z0-9_.\/-]/) {
                      invalid = 1
                    }
                    result = role "\t" $1 "\t" $2 "\t" name "\t" $4
                  }
                  END {
                    if (matches != 1 || invalid || overlap) {
                      exit 1
                    }
                    print result
                  }
                '
    ) || die "storage mount is missing or unsafe for $mount_role"
    printf '%s\n' "$mount_record_value"
}

verify_mount_read_write()
{
    mount_rw_role=$1
    mount_rw_container=$2
    mount_rw_destination=$3
    mount_rw_rows=$(inspect_value "$mount_rw_container" \
        '{{range .Mounts}}{{printf "%s\t%t\n" .Destination .RW}}{{end}}') ||
        die "cannot inspect read/write state for $mount_rw_role"
    printf '%s\n' "$mount_rw_rows" |
        LC_ALL=C awk -F '	' -v destination="$mount_rw_destination" '
          $1 == destination {
            matches++
            writable = $2
          }
          END {
            if (matches != 1 || writable != "true") {
              exit 1
            }
          }
        ' ||
        die "productive storage mount is not explicitly read/write for $mount_rw_role"
}

verify_storage_source_separation()
{
    printf '%s\n' "$1" |
        LC_ALL=C awk -F '	' '
          function canonical_path(path) {
            return path ~ /^\// &&
              path !~ /\/\// &&
              path !~ /\/\.\.?($|\/)/ &&
              (path == "/" || path !~ /\/$/)
          }
          function overlaps(first, second) {
            return first == second ||
              first == "/" || second == "/" ||
              index(first, second "/") == 1 ||
              index(second, first "/") == 1
          }
          {
            if (NF != 5 || !canonical_path($5)) {
              exit 1
            }
            sources[++count] = $5
          }
          END {
            if (count != 3) {
              exit 1
            }
            for (left = 1; left <= count; left++) {
              for (right = left + 1; right <= count; right++) {
                if (overlaps(sources[left], sources[right])) {
                  exit 1
                }
              }
            }
          }
        ' ||
        die "productive storage sources are equal, nested, overlapping, or unsafe"
}

verify_operator_path_separation()
{
    separation_operator_path=$1
    separation_storage_records=$2
    printf '%s\n' "$separation_storage_records" |
        LC_ALL=C awk -F '	' -v operator_path="$separation_operator_path" '
          function overlaps(first, second) {
            return first == second ||
              first == "/" || second == "/" ||
              index(first, second "/") == 1 ||
              index(second, first "/") == 1
          }
          NF != 5 || overlaps(operator_path, $5) {
            exit 1
          }
        ' ||
        die "backup directory overlaps a productive storage source"
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

diagnose_maintenance_lock()
{
    diagnose_lock_id=$(inspect_value "$maintenance_lock_name" '{{.Id}}' \
        2>/dev/null || :)
    if [ -z "$diagnose_lock_id" ]; then
        printf 'eStab restore: no inspectable lock container named %s was found; inspect the preceding container-engine error.\n' \
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
    printf 'eStab restore: engine-global maintenance lock: name=%s id=%s project=%s operation=%s owner=%s started_utc=%s status=%s\n' \
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
    [ "$owned_lock_project" = "$confirmed_project" ] &&
    [ "$owned_lock_operation" = restore ] &&
    [ "$owned_lock_token" = "$maintenance_lock_token" ] &&
    [ "$owned_lock_status" = running ] &&
    [ "$owned_lock_running" = true ] &&
    [ "$owned_lock_image" = "$maintenance_lock_image" ]
}

acquire_maintenance_lock()
{
    maintenance_lock_runtime_image=$1
    maintenance_lock_image=$(canonical_image_id \
        "$maintenance_lock_runtime_image") ||
        die "verified app runtime image is invalid for the maintenance lock"
    maintenance_lock_name=estab-maintenance-lock-$confirmed_project
    maintenance_lock_started=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    maintenance_lock_token="restore-$$-$(date -u '+%Y%m%dT%H%M%SZ')"

    if ! "$container_cli" run --detach \
        --name "$maintenance_lock_name" \
        --label org.e-stab.maintenance-lock=true \
        --label "org.e-stab.compose-project=$confirmed_project" \
        --label org.e-stab.maintenance-operation=restore \
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
        die "cannot acquire engine-global maintenance lock for project $confirmed_project; a stale lock remains fail-closed until an operator proves no maintenance is running and removes that exact lock container"
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

verify_backup()
{
    sh "$backup_verifier" "$backup_dir" "$database_name" >/dev/null
}

verify_project_container()
{
    project_service=$1
    project_container=$2
    actual_project=$(inspect_value "$project_container" \
        '{{ index .Config.Labels "com.docker.compose.project" }}') ||
        die "cannot determine Compose project for $project_service"
    [ "$actual_project" = "$confirmed_project" ] ||
        die "Compose service $project_service belongs to project $actual_project, not confirmed project $confirmed_project"
}

refresh_runtime()
{
    app_container=$(single_container app)
    db_container=$(single_container db)
    migrate_container=$(single_container migrate)
}

verify_runtime_identity()
{
    refresh_runtime
    verify_project_container app "$app_container"
    verify_project_container db "$db_container"
    verify_project_container migrate "$migrate_container"

    runtime_database_environment=$(inspect_value "$db_container" \
        '{{range .Config.Env}}{{println .}}{{end}}') ||
        die "cannot inspect configured database name"
    runtime_database_environment=$(
        printf '%s\n' "$runtime_database_environment" |
            LC_ALL=C awk -F '=' '
              $1 == "MARIADB_DATABASE" {
                matches++
                value = substr($0, length($1) + 2)
              }
              END {
                if (matches != 1 || value !~ /^[A-Za-z0-9_]+$/) {
                  exit 1
                }
                print value
              }
            '
    ) || die "configured database name is missing or unsafe"
    [ "$runtime_database_environment" = "$database_name" ] ||
        die "configured database $runtime_database_environment does not match backup database $database_name"

    runtime_storage=$(
        mount_record database "$db_container" /var/lib/mysql
        mount_record application "$app_container" /var/www/html/4fdata
        mount_record export "$app_container" /var/lib/estab/export
    )
    verify_mount_read_write application "$app_container" \
        /var/www/html/4fdata
    verify_mount_read_write export "$app_container" \
        /var/lib/estab/export
    verify_storage_source_separation "$runtime_storage"
    verify_operator_path_separation "$backup_dir" "$runtime_storage"
    runtime_storage=$(printf '%s\n' "$runtime_storage" | LC_ALL=C sort)
    [ "$runtime_storage" = "$backup_storage" ] ||
        die "runtime storage mounts do not match the verified backup metadata"

    runtime_images=$(
        image_record app "$app_container"
        image_record migrate "$migrate_container"
        image_record database "$db_container"
    )
    runtime_images=$(printf '%s\n' "$runtime_images" | LC_ALL=C sort)
    [ "$runtime_images" = "$backup_images" ] ||
        die "runtime image references or digests do not match the verified backup metadata"
}

verify_runtime_target()
{
    verify_runtime_identity
    wait_for_healthy db "$db_container" ||
        die "database is not healthy at a restore boundary"

    runtime_database=$("$container_cli" exec -i "$db_container" sh -ceu \
        'printf "%s\n" "$MARIADB_DATABASE"') ||
        die "cannot determine the running database name"
    [ "$runtime_database" = "$database_name" ] ||
        die "running database $runtime_database does not match backup database $database_name"
}

verify_admin_auth_state()
{
    admin_auth_container=$(single_container admin-auth-init)
    verify_project_container admin-auth-init "$admin_auth_container"
    admin_auth_running=$(inspect_value "$admin_auth_container" \
        '{{.State.Running}}') ||
        die "cannot inspect admin authentication initializer state"
    admin_auth_exit_code=$(inspect_value "$admin_auth_container" \
        '{{.State.ExitCode}}') ||
        die "cannot inspect admin authentication initializer exit status"
    [ "$admin_auth_running" = false ] && [ "$admin_auth_exit_code" = 0 ] ||
        die "admin authentication initializer did not complete successfully"
    admin_auth_image=$(image_record app "$admin_auth_container")
    [ "$admin_auth_image" = "$backup_app_image" ] ||
        die "admin authentication initializer does not use the verified app image"
}

verify_file_mount_writes()
{
    maintenance_lock_is_owned ||
        die "engine-global maintenance lock was lost before file write preflight"
    "$container_cli" run --rm --network none --read-only \
        --volumes-from "$app_container" \
        --entrypoint /bin/sh "$app_runtime_image" -ceu '
        umask 077
        data_probe=
        export_probe=
        cleanup_probe()
        {
          [ -z "$data_probe" ] || rm -f -- "$data_probe"
          [ -z "$export_probe" ] || rm -f -- "$export_probe"
        }
        trap cleanup_probe EXIT HUP INT TERM

        data_probe=$(mktemp \
          /var/www/html/4fdata/.estab-restore-write-probe.XXXXXX)
        test -f "$data_probe"
        test ! -L "$data_probe"
        printf "%s\n" "estab-restore-write-probe" > "$data_probe"
        test "$(cat "$data_probe")" = "estab-restore-write-probe"
        rm -f -- "$data_probe"
        data_probe=

        export_probe=$(mktemp \
          /var/lib/estab/export/.estab-restore-write-probe.XXXXXX)
        test -f "$export_probe"
        test ! -L "$export_probe"
        printf "%s\n" "estab-restore-write-probe" > "$export_probe"
        test "$(cat "$export_probe")" = "estab-restore-write-probe"
        rm -f -- "$export_probe"
        export_probe=
        trap - EXIT HUP INT TERM
    ' >/dev/null ||
        die "productive file mounts failed the create/write/delete preflight"
}

database_name=$(sed -n '1p' "$backup_dir/database-name.txt" 2>/dev/null || :)
case "$database_name" in
    ''|*[!A-Za-z0-9_]*)
        die "backup does not contain a safe format-2 database name"
        ;;
esac
verify_backup ||
    die "backup verification failed"
grep -Fqx 'estab-full-backup-v2' "$backup_dir/backup-format.txt" ||
    die "production restore requires the fully bound backup format 2"
backup_project=$(sed -n '1p' "$backup_dir/project-name.txt")
[ "$backup_project" = "$confirmed_project" ] ||
    die "backup project $backup_project does not match --confirm-project $confirmed_project"
backup_storage=$(LC_ALL=C sort "$backup_dir/storage-sources.txt")
verify_storage_source_separation "$backup_storage"
backup_images=$(LC_ALL=C sort "$backup_dir/image-references.txt")
backup_app_image=$(LC_ALL=C awk -F '	' '$1 == "app" { print }' \
    "$backup_dir/image-references.txt")

compose config >/dev/null ||
    die "effective Compose configuration is invalid"

maintenance_lock_held=0
maintenance_lock_id=
maintenance_lock_name=
maintenance_lock_token=
maintenance_lock_image=
restore_completed=0
app_was_running=false
app_stopped_by_restore=0
destructive_started=0
restore_stage=preflight

cleanup()
{
    cleanup_status=$1
    trap - EXIT HUP INT TERM
    preserve_maintenance_lock=0

    if [ "$cleanup_status" -ne 0 ]; then
        if [ "$destructive_started" -eq 1 ]; then
            preserve_maintenance_lock=1
            "$container_cli" stop "$app_container" >/dev/null 2>&1 || :
            printf '%s\n' \
                "eStab restore: RECOVERY REQUIRED for project $confirmed_project." \
                "eStab restore: stage=$restore_stage; database and file data may be partially restored." \
                "eStab restore: the application is intentionally stopped. Do not start it manually." \
                "eStab restore: the engine-global lock $maintenance_lock_name ($maintenance_lock_id) is intentionally retained." \
                "eStab restore: correct the cause, prove that no maintenance is running, remove exactly that lock container, and rerun this exact verified restore." \
                >&2
        elif [ "$app_stopped_by_restore" -eq 1 ] &&
            [ "$app_was_running" = true ]; then
            if "$container_cli" start "$app_container" >/dev/null 2>&1; then
                if ! wait_for_healthy app "$app_container"; then
                    printf 'eStab restore: RECOVERY REQUIRED: original data was not overwritten, but the application could not be returned to healthy state.\n' >&2
                else
                    printf 'eStab restore: aborted before overwrite; original data is unchanged and the application is healthy again.\n' >&2
                fi
            else
                printf 'eStab restore: RECOVERY REQUIRED: original data was not overwritten, but the application could not be restarted.\n' >&2
            fi
        fi
    fi

    if [ "$maintenance_lock_held" -eq 1 ]; then
        if [ "$preserve_maintenance_lock" -eq 1 ]; then
            printf 'eStab restore: retained fail-closed maintenance lock: %s (%s)\n' \
                "$maintenance_lock_name" "$maintenance_lock_id" >&2
        elif maintenance_lock_is_owned; then
            if ! "$container_cli" container rm --force \
                "$maintenance_lock_id" >/dev/null; then
                printf 'eStab restore: WARNING: owned maintenance lock container could not be removed: %s (%s)\n' \
                    "$maintenance_lock_name" "$maintenance_lock_id" >&2
                [ "$cleanup_status" -ne 0 ] || cleanup_status=1
            fi
        else
            printf 'eStab restore: WARNING: maintenance lock ownership changed; refusing removal by name: %s (owned id %s)\n' \
                "$maintenance_lock_name" "$maintenance_lock_id" >&2
            [ "$cleanup_status" -ne 0 ] || cleanup_status=1
        fi
        maintenance_lock_held=0
    fi
    exit "$cleanup_status"
}
trap 'cleanup $?' EXIT
trap 'cleanup 129' HUP
trap 'cleanup 130' INT
trap 'cleanup 143' TERM

refresh_runtime
verify_project_container app "$app_container"
verify_project_container db "$db_container"
verify_project_container migrate "$migrate_container"
migrate_running=$(inspect_value "$migrate_container" '{{.State.Running}}') ||
    die "cannot inspect Compose service: migrate"
[ "$migrate_running" = false ] ||
    die "migration service is still running"

verify_runtime_identity
app_runtime_image=$(inspect_value "$app_container" '{{.Image}}') ||
    die "cannot bind restore helper to the verified app image"
prelock_app_runtime_image=$(canonical_image_id "$app_runtime_image") ||
    die "cannot bind restore helper to a valid app image ID"
acquire_maintenance_lock "$app_runtime_image"

# Close the lock-acquisition race before the first container start or stop.
verify_runtime_identity
locked_app_runtime_image=$(inspect_value "$app_container" '{{.Image}}') ||
    die "cannot rebind restore helper to the verified app image"
locked_app_runtime_image=$(canonical_image_id "$locked_app_runtime_image") ||
    die "cannot rebind restore helper to a valid app image ID"
[ "$locked_app_runtime_image" = "$prelock_app_runtime_image" ] ||
    die "app runtime image changed while the maintenance lock was acquired"
app_runtime_image=$locked_app_runtime_image
migrate_running=$(inspect_value "$migrate_container" '{{.State.Running}}') ||
    die "cannot reinspect Compose service: migrate"
[ "$migrate_running" = false ] ||
    die "migration service started while the maintenance lock was acquired"
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before the restore"
verify_admin_auth_state
app_was_running=$(inspect_value "$app_container" '{{.State.Running}}') ||
    die "cannot inspect application state"
case "$app_was_running" in
    true|false) ;;
    *) die "application reported an unknown running state" ;;
esac

"$container_cli" stop "$app_container" >/dev/null ||
    die "application could not be stopped for restore"
app_stopped_by_restore=1
app_running_after_stop=$(inspect_value "$app_container" '{{.State.Running}}') ||
    die "cannot prove that the application stopped"
[ "$app_running_after_stop" = false ] ||
    die "application is still running; restore will not begin"
verify_file_mount_writes
verify_backup ||
    die "backup changed during file write preflight"

"$container_cli" start "$db_container" >/dev/null ||
    die "database service could not be started"
verify_runtime_target

restore_stage=database-boundary
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before database import"
verify_admin_auth_state
verify_runtime_target
verify_backup ||
    die "backup changed before database import"

restore_stage=database-import
destructive_started=1
"$container_cli" exec -i "$db_container" sh -ceu \
    'umask 077
    client_file=$(mktemp)
    trap "rm -f -- \"$client_file\"" EXIT HUP INT TERM
    {
      printf "[client]\nuser=root\npassword=\""
      sed -e "s/\\\\/\\\\\\\\/g" -e "s/\"/\\\\\"/g" \
        "$MARIADB_ROOT_PASSWORD_FILE" | tr -d "\r\n"
      printf "\"\n"
    } > "$client_file"
    mariadb --defaults-extra-file="$client_file"' \
    <"$backup_dir/database.sql"
wait_for_healthy db "$db_container" ||
    die "database is not healthy after import"

restore_stage=file-boundary
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before file restore"
verify_runtime_target
verify_backup ||
    die "backup changed before file-volume restore"

restore_stage=file-volumes-cleared
"$container_cli" run --rm --network none \
    --volumes-from "$app_container" \
    --entrypoint sh "$app_runtime_image" -ceu '
    find /var/www/html/4fdata -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
    find /var/lib/estab/export -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
    test -z "$(find /var/www/html/4fdata -mindepth 1 -print -quit)"
    test -z "$(find /var/lib/estab/export -mindepth 1 -print -quit)"
'

restore_stage=application-data-extract
"$container_cli" run --rm --interactive --network none \
    --volumes-from "$app_container" \
    --entrypoint sh "$app_runtime_image" -ceu \
    'tar -xzf - -C /var/www/html/4fdata' \
    <"$backup_dir/4fdata.tar.gz"

restore_stage=export-data-extract
"$container_cli" run --rm --interactive --network none \
    --volumes-from "$app_container" \
    --entrypoint sh "$app_runtime_image" -ceu \
    'tar -xzf - -C /var/lib/estab/export' \
    <"$backup_dir/export.tar.gz"
verify_backup ||
    die "backup changed while file volumes were restored"

restore_stage=migration
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before migration"
"$container_cli" start --attach "$migrate_container"
migrate_container=$(single_container migrate)
migrate_running=$(inspect_value "$migrate_container" '{{.State.Running}}') ||
    die "cannot inspect migration state after restore"
migrate_exit_code=$(inspect_value "$migrate_container" '{{.State.ExitCode}}') ||
    die "cannot inspect migration exit status after restore"
[ "$migrate_running" = false ] && [ "$migrate_exit_code" = 0 ] ||
    die "migration did not complete successfully after restore"
verify_runtime_target

restore_stage=application-start
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before application start"
"$container_cli" start "$app_container" >/dev/null ||
    die "application could not be started after restore"
app_container=$(single_container app)
wait_for_healthy app "$app_container" ||
    die "application did not become healthy after restore"
"$container_cli" exec -i "$app_container" estab-healthcheck >/dev/null ||
    die "application readiness check failed after restore"
verify_runtime_target
verify_admin_auth_state
verify_backup ||
    die "backup changed before final restore verification"

restore_completed=1
restore_stage=complete
printf 'eStab restore: complete and healthy: project=%s backup=%s\n' \
    "$confirmed_project" "$backup_dir"
