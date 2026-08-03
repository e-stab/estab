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
    printf '%s\n' \
        "Usage: $0 --confirm-project TARGET_PROJECT" \
        "  [--remap-project SOURCE=TARGET]" \
        "  [--remap-mount-type ROLE:SOURCE=TARGET]..." \
        "  [--remap-storage ROLE:SOURCE=TARGET]..." \
        "  [--remap-volume ROLE:SOURCE=TARGET]..." \
        "  [--allow-runtime-image-id-change]" \
        "  ABSOLUTE_BACKUP_DIRECTORY" >&2
    exit 64
}

confirmed_project=
project_remap=
database_storage_remap=
application_storage_remap=
export_storage_remap=
database_mount_type_remap=
application_mount_type_remap=
export_mount_type_remap=
database_volume_remap=
application_volume_remap=
export_volume_remap=
allow_runtime_image_id_change=0
requested_backup=

set_role_remap()
{
    remap_kind=$1
    remap_spec=$2
    remap_role=${remap_spec%%:*}
    remap_pair=${remap_spec#*:}
    [ "$remap_role" != "$remap_spec" ] || usage
    case "$remap_pair" in
        *=*) ;;
        *) usage ;;
    esac
    remap_source=${remap_pair%%=*}
    remap_target=${remap_pair#*=}
    [ -n "$remap_source" ] && [ -n "$remap_target" ] || usage

    case "$remap_kind:$remap_role" in
        storage:database)
            [ -z "$database_storage_remap" ] || usage
            database_storage_remap=$remap_pair
            ;;
        storage:application)
            [ -z "$application_storage_remap" ] || usage
            application_storage_remap=$remap_pair
            ;;
        storage:export)
            [ -z "$export_storage_remap" ] || usage
            export_storage_remap=$remap_pair
            ;;
        mount-type:database)
            [ -z "$database_mount_type_remap" ] || usage
            database_mount_type_remap=$remap_pair
            ;;
        mount-type:application)
            [ -z "$application_mount_type_remap" ] || usage
            application_mount_type_remap=$remap_pair
            ;;
        mount-type:export)
            [ -z "$export_mount_type_remap" ] || usage
            export_mount_type_remap=$remap_pair
            ;;
        volume:database)
            [ -z "$database_volume_remap" ] || usage
            database_volume_remap=$remap_pair
            ;;
        volume:application)
            [ -z "$application_volume_remap" ] || usage
            application_volume_remap=$remap_pair
            ;;
        volume:export)
            [ -z "$export_volume_remap" ] || usage
            export_volume_remap=$remap_pair
            ;;
        *)
            usage
            ;;
    esac
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --confirm-project)
            [ "$#" -ge 2 ] && [ -z "$confirmed_project" ] || usage
            confirmed_project=$2
            shift 2
            ;;
        --remap-project)
            [ "$#" -ge 2 ] && [ -z "$project_remap" ] || usage
            case "$2" in
                *=*) ;;
                *) usage ;;
            esac
            project_remap_source=${2%%=*}
            project_remap_target=${2#*=}
            [ -n "$project_remap_source" ] &&
                [ -n "$project_remap_target" ] || usage
            project_remap=$2
            shift 2
            ;;
        --remap-storage)
            [ "$#" -ge 2 ] || usage
            set_role_remap storage "$2"
            shift 2
            ;;
        --remap-mount-type)
            [ "$#" -ge 2 ] || usage
            set_role_remap mount-type "$2"
            shift 2
            ;;
        --remap-volume)
            [ "$#" -ge 2 ] || usage
            set_role_remap volume "$2"
            shift 2
            ;;
        --allow-runtime-image-id-change)
            [ "$allow_runtime_image_id_change" -eq 0 ] || usage
            allow_runtime_image_id_change=1
            shift
            ;;
        --)
            shift
            [ "$#" -eq 1 ] && [ -z "$requested_backup" ] || usage
            requested_backup=$1
            shift
            ;;
        -*)
            usage
            ;;
        *)
            [ -z "$requested_backup" ] || usage
            requested_backup=$1
            shift
            ;;
    esac
done

[ -n "$confirmed_project" ] && [ -n "$requested_backup" ] || usage

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
backup_dir=$(CDPATH='' cd -- "$backup_request_without_slash" && pwd -P) ||
    die "cannot resolve backup directory: $requested_backup"
[ "$backup_dir" != / ] ||
    die "refusing the filesystem root as backup directory"
requested_backup_dir=$backup_dir

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
backup_verifier=$script_dir/verify-backup.sh
[ -f "$backup_verifier" ] && [ ! -L "$backup_verifier" ] &&
    [ -r "$backup_verifier" ] ||
    die "backup verifier is missing or unreadable: $backup_verifier"

health_timeout_seconds=${ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS:-240}
case "$health_timeout_seconds" in
    ''|*[!0-9]*|0?*)
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
    provider_container_id=$(
        "$container_cli" ps --all --no-trunc \
            --filter \
            "label=com.docker.compose.project=$confirmed_project" \
            --filter \
            "label=com.docker.compose.service=$container_service" \
            --format '{{.ID}}'
    ) || die "cannot resolve service through the engine inventory: $container_service"
    case "$provider_container_id" in
        ''|*' '*|*'	'*|*'
'*)
            die "engine inventory must resolve to exactly one container for service: $container_service"
            ;;
    esac
    container_id=$(inspect_value "$provider_container_id" '{{.Id}}') ||
        die "cannot normalize service to an exact container ID: $container_service"
    case "$container_id" in
        ''|*' '*|*'	'*|*'
'*)
            die "service returned an unsafe exact container ID: $container_service"
            ;;
    esac
    container_project=$(inspect_value "$container_id" \
        '{{ index .Config.Labels "com.docker.compose.project" }}') ||
        die "cannot verify project label for service: $container_service"
    container_service_label=$(inspect_value "$container_id" \
        '{{ index .Config.Labels "com.docker.compose.service" }}') ||
        die "cannot verify service label for service: $container_service"
    [ "$container_project" = "$confirmed_project" ] &&
        [ "$container_service_label" = "$container_service" ] ||
        die "engine inventory returned a container with mismatched project or service labels: $container_service"
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

canonical_image_reference()
{
    printf '%s\n' "$1" |
        LC_ALL=C awk '
          function last_index(value, needle, position, found) {
            found = 0
            for (position = 1; position <= length(value); position++) {
              if (substr(value, position, 1) == needle) {
                found = position
              }
            }
            return found
          }
          {
            reference = $0
            if (reference !~ /^[A-Za-z0-9_.\/:@+-]+$/) {
              exit 1
            }
            if (length(reference) <= 72 ||
                substr(reference, length(reference) - 71, 8) != "@sha256:") {
              print reference
              next
            }

            digest = substr(reference, length(reference) - 63)
            if (length(digest) != 64 || digest ~ /[^0-9a-f]/) {
              exit 1
            }
            name = substr(reference, 1, length(reference) - 72)
            if (name == "" || index(name, "@") != 0) {
              exit 1
            }

            slash = last_index(name, "/")
            colon = last_index(name, ":")
            if (colon > slash) {
              name = substr(name, 1, colon - 1)
            }
            if (name == "") {
              exit 1
            }

            slash = index(name, "/")
            if (slash == 0) {
              name = "docker.io/library/" name
            } else {
              first = substr(name, 1, slash - 1)
              if (first !~ /[.:]/ && first != "localhost") {
                name = "docker.io/" name
              } else if (first == "index.docker.io") {
                name = "docker.io" substr(name, slash)
              }
            }
            print tolower(name) "@sha256:" digest
          }
          END {
            if (NR != 1) {
              exit 1
            }
          }
        '
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
    image_reference=$(canonical_image_reference "$image_reference") ||
        die "container image reference is invalid for $image_role"
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
                        ($2 == "volume" &&
                         (name == "-" || name ~ /[^A-Za-z0-9_.-]/)) ||
                        ($2 == "bind" && name != "-")) {
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

verify_storage_identity()
{
    storage_runtime_records=$1
    printf '%s\n' "$storage_runtime_records" |
        LC_ALL=C awk -F '	' \
            -v database_storage="$database_storage_remap" \
            -v application_storage="$application_storage_remap" \
            -v export_storage="$export_storage_remap" \
            -v database_mount_type="$database_mount_type_remap" \
            -v application_mount_type="$application_mount_type_remap" \
            -v export_mount_type="$export_mount_type_remap" \
            -v database_volume="$database_volume_remap" \
            -v application_volume="$application_volume_remap" \
            -v export_volume="$export_volume_remap" '
          function canonical_path(path) {
            return path ~ /^\// &&
              path !~ /\/\// &&
              path !~ /\/\.\.?($|\/)/ &&
              (path == "/" || path !~ /\/$/)
          }
          function safe_volume(name) {
            return name ~ /^[A-Za-z0-9_.-]+$/
          }
          function load_mapping(role, kind, specification, parts) {
            if (specification == "") {
              return
            }
            if (split(specification, parts, "=") != 2 ||
                parts[1] == "" || parts[2] == "" ||
                parts[1] == parts[2]) {
              invalid = 1
              return
            }
            if (kind == "storage") {
              if (!canonical_path(parts[1]) ||
                  !canonical_path(parts[2])) {
                invalid = 1
                return
              }
              storage_old[role] = parts[1]
              storage_new[role] = parts[2]
            } else if (kind == "volume") {
              if ((parts[1] != "-" && !safe_volume(parts[1])) ||
                  (parts[2] != "-" && !safe_volume(parts[2]))) {
                invalid = 1
                return
              }
              volume_old[role] = parts[1]
              volume_new[role] = parts[2]
            } else {
              if ((parts[1] != "volume" && parts[1] != "bind") ||
                  (parts[2] != "volume" && parts[2] != "bind")) {
                invalid = 1
                return
              }
              mount_type_old[role] = parts[1]
              mount_type_new[role] = parts[2]
            }
          }
          BEGIN {
            required["database"] = 1
            required["application"] = 1
            required["export"] = 1
            load_mapping("database", "storage", database_storage)
            load_mapping("application", "storage", application_storage)
            load_mapping("export", "storage", export_storage)
            load_mapping("database", "mount-type", database_mount_type)
            load_mapping("application", "mount-type", application_mount_type)
            load_mapping("export", "mount-type", export_mount_type)
            load_mapping("database", "volume", database_volume)
            load_mapping("application", "volume", application_volume)
            load_mapping("export", "volume", export_volume)
          }
          FNR == NR {
            if (NF != 5 || !($1 in required) || backup_seen[$1]++) {
              invalid = 1
              next
            }
            backup_destination[$1] = $2
            backup_type[$1] = $3
            backup_name[$1] = $4
            backup_source[$1] = $5
            next
          }
          {
            if (NF != 5 || !($1 in required) || runtime_seen[$1]++) {
              invalid = 1
              next
            }
            runtime_destination[$1] = $2
            runtime_type[$1] = $3
            runtime_name[$1] = $4
            runtime_source[$1] = $5
          }
          END {
            for (role in required) {
              if (backup_seen[role] != 1 ||
                  runtime_seen[role] != 1 ||
                  backup_destination[role] != runtime_destination[role]) {
                invalid = 1
                continue
              }

              if (backup_type[role] == runtime_type[role]) {
                if (mount_type_old[role] != "" ||
                    mount_type_new[role] != "") {
                  invalid = 1
                }
              } else if (mount_type_old[role] != backup_type[role] ||
                         mount_type_new[role] != runtime_type[role]) {
                invalid = 1
              }

              if (backup_source[role] == runtime_source[role]) {
                if (storage_old[role] != "" ||
                    storage_new[role] != "") {
                  invalid = 1
                }
              } else if (storage_old[role] != backup_source[role] ||
                         storage_new[role] != runtime_source[role]) {
                invalid = 1
              }

              if (backup_name[role] == runtime_name[role]) {
                if (volume_old[role] != "" ||
                    volume_new[role] != "") {
                  invalid = 1
                }
              } else if (volume_old[role] != backup_name[role] ||
                         volume_new[role] != runtime_name[role]) {
                invalid = 1
              }
            }
            if (invalid) {
              exit 1
            }
          }
        ' "$backup_dir/storage-sources.txt" - ||
        die "runtime storage mounts do not match the verified backup metadata or explicit role remaps"
}

verify_no_foreign_storage_consumers()
{
    foreign_ids_before_raw=$(
        "$container_cli" ps --all --no-trunc --format '{{.ID}}'
    ) ||
        die "cannot enumerate engine-wide containers before restore"
    foreign_ids_before=$(
        printf '%s\n' "$foreign_ids_before_raw" | LC_ALL=C sort
    )
    while IFS= read -r foreign_candidate; do
        [ -n "$foreign_candidate" ] || continue
        foreign_exact_id=$(inspect_value "$foreign_candidate" '{{.Id}}') ||
            die "container inventory changed during engine-wide storage scan"
        case "$foreign_exact_id" in
            "$app_container"|"$db_container"|"$migrate_container"|\
            "$admin_auth_container"|\
            "$maintenance_lock_id")
                continue
                ;;
        esac

        foreign_mount_rows=$(inspect_value "$foreign_exact_id" \
            '{{range .Mounts}}{{printf "%s\t%s\n" .Type .Source}}{{end}}') ||
            die "cannot inspect foreign container mounts during portable restore: $foreign_exact_id"
        foreign_sources=$(
            printf '%s' "$foreign_mount_rows" |
                LC_ALL=C awk -F '	' '
                  function canonical_path(path) {
                    return path ~ /^\// &&
                      path !~ /\/\// &&
                      path !~ /\/\.\.?($|\/)/ &&
                      (path == "/" || path !~ /\/$/)
                  }
                  {
                    if (NF != 2 || $1 !~ /^[a-z][a-z0-9_-]*$/) {
                      invalid = 1
                      next
                    }
                    if ($1 == "bind" || $1 == "volume") {
                      if (!canonical_path($2)) {
                        invalid = 1
                        next
                      }
                      print $1 "\t" $2
                      next
                    }
                    if ($1 != "tmpfs" || $2 != "") {
                      invalid = 1
                    }
                  }
                  END {
                    if (invalid) {
                      exit 1
                    }
                  }
                '
        ) || die "foreign container has an unsafe or uninspectable productive mount source: $foreign_exact_id"
        foreign_conflict=
        while IFS='	' read -r foreign_mount_type foreign_mount_source; do
            [ -n "$foreign_mount_type" ] || continue
            while IFS='	' read -r target_role target_destination \
                target_type target_name target_source
            do
                [ -n "$target_role" ] || continue
                if [ "$foreign_mount_source" = "$target_source" ] ||
                    [ "$foreign_mount_source" = / ] ||
                    [ "$target_source" = / ]; then
                    foreign_conflict="$target_role	$target_source	$foreign_mount_source"
                    break
                fi
                case "$foreign_mount_source" in
                    "$target_source"/*)
                        foreign_conflict="$target_role	$target_source	$foreign_mount_source"
                        ;;
                esac
                if [ -z "$foreign_conflict" ]; then
                    case "$target_source" in
                        "$foreign_mount_source"/*)
                            foreign_conflict="$target_role	$target_source	$foreign_mount_source"
                            ;;
                    esac
                fi
                [ -z "$foreign_conflict" ] || break
            done <<EOF
$runtime_storage
EOF
            [ -z "$foreign_conflict" ] || break
        done <<EOF
$foreign_sources
EOF
        if [ -n "$foreign_conflict" ]; then
            foreign_project=$(inspect_value "$foreign_exact_id" \
                '{{ index .Config.Labels "com.docker.compose.project" }}' \
                2>/dev/null || :)
            [ -n "$foreign_project" ] || foreign_project='<unlabelled>'
            foreign_conflict_role=$(printf '%s\n' "$foreign_conflict" |
                LC_ALL=C awk -F '	' 'NR == 1 { print $1 }')
            foreign_conflict_target=$(printf '%s\n' "$foreign_conflict" |
                LC_ALL=C awk -F '	' 'NR == 1 { print $2 }')
            foreign_conflict_source=$(printf '%s\n' "$foreign_conflict" |
                LC_ALL=C awk -F '	' 'NR == 1 { print $3 }')
            die "foreign container $foreign_exact_id (project $foreign_project) mounts $foreign_conflict_source equal to or overlapping target $foreign_conflict_role source $foreign_conflict_target"
        fi
    done <<EOF
$foreign_ids_before
EOF
    foreign_ids_after_raw=$(
        "$container_cli" ps --all --no-trunc --format '{{.ID}}'
    ) ||
        die "cannot re-enumerate engine-wide containers after storage scan"
    foreign_ids_after=$(
        printf '%s\n' "$foreign_ids_after_raw" | LC_ALL=C sort
    )
    [ "$foreign_ids_after" = "$foreign_ids_before" ] ||
        die "engine-wide container inventory changed during portable restore storage scan"
}

verify_operator_path_separation()
{
    separation_operator_path=$1
    separation_storage_records=$2
    separation_label=${3:-backup directory}
    separation_alias_one=
    separation_alias_two=
    if [ "${container_cli##*/}" = docker ] &&
        [ "$(uname -s 2>/dev/null || :)" = Darwin ]; then
        separation_alias_one=/host_mnt$separation_operator_path
        separation_alias_two=/run/desktop/mnt/host$separation_operator_path
    fi
    printf '%s\n' "$separation_storage_records" |
        LC_ALL=C awk -F '	' \
            -v operator_path="$separation_operator_path" \
            -v alias_one="$separation_alias_one" \
            -v alias_two="$separation_alias_two" '
          function overlaps(first, second) {
            return first == second ||
              first == "/" || second == "/" ||
              index(first, second "/") == 1 ||
              index(second, first "/") == 1
          }
          {
            if (NF != 5 ||
                overlaps(operator_path, $5) ||
                (alias_one != "" && overlaps(alias_one, $5)) ||
                (alias_two != "" && overlaps(alias_two, $5))) {
              exit 1
            }
          }
        ' ||
        die "$separation_label overlaps a productive storage source"
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

canonicalize_image_records()
{
    canonical_records_input=$1
    canonical_records_count=0
    while IFS='	' read -r canonical_role canonical_reference \
        canonical_runtime_id
    do
        [ -n "$canonical_role" ] &&
            [ -n "$canonical_reference" ] &&
            [ -n "$canonical_runtime_id" ] || return 1
        canonical_reference=$(
            canonical_image_reference "$canonical_reference"
        ) || return 1
        printf '%s\t%s\t%s\n' \
            "$canonical_role" "$canonical_reference" "$canonical_runtime_id"
        canonical_records_count=$((canonical_records_count + 1))
    done <<EOF
$canonical_records_input
EOF
    [ "$canonical_records_count" -eq 3 ]
}

canonicalize_release_identity()
{
    canonical_release_input=$1
    canonical_release_count=0
    while IFS='	' read -r canonical_role canonical_reference
    do
        [ -n "$canonical_role" ] &&
            [ -n "$canonical_reference" ] || return 1
        canonical_reference=$(
            canonical_image_reference "$canonical_reference"
        ) || return 1
        printf '%s\t%s\n' "$canonical_role" "$canonical_reference"
        canonical_release_count=$((canonical_release_count + 1))
    done <<EOF
$canonical_release_input
EOF
    [ "$canonical_release_count" -eq 3 ]
}

release_identity_from_images()
{
    printf '%s\n' "$1" |
        LC_ALL=C awk -F '	' '
          NF != 3 || seen[$1]++ {
            exit 1
          }
          {
            print $1 "\t" $2
          }
          END {
            if (NR != 3 ||
                !seen["app"] ||
                !seen["migrate"] ||
                !seen["database"]) {
              exit 1
            }
          }
        '
}

release_identity_is_manifest_bound()
{
    printf '%s\n' "$1" |
        LC_ALL=C awk -F '	' '
          function exact_manifest(reference, prefix, suffix, digest) {
            if (reference !~ /^[A-Za-z0-9_.\/:@+-]+$/ ||
                length(reference) <= 72) {
              return 0
            }
            prefix = substr(reference, 1, length(reference) - 72)
            suffix = substr(reference, length(reference) - 71)
            if (prefix == "" || index(prefix, "@") != 0 ||
                substr(suffix, 1, 8) != "@sha256:") {
              return 0
            }
            digest = substr(suffix, 9)
            return length(digest) == 64 &&
              digest !~ /[^0-9a-f]/
          }
          NF != 2 ||
          ($1 != "app" && $1 != "migrate" && $1 != "database") ||
          seen[$1]++ ||
          !exact_manifest($2) {
            invalid = 1
          }
          END {
            if (invalid ||
                NR != 3 ||
                !seen["app"] ||
                !seen["migrate"] ||
                !seen["database"]) {
              exit 1
            }
          }
        '
}

verify_runtime_images()
{
    runtime_image_records=$1
    if [ "$runtime_image_records" = "$backup_images" ]; then
        return
    fi
    [ "$allow_runtime_image_id_change" -eq 1 ] ||
        die "runtime image IDs differ from the backup; use an explicit portable restore only for the exact manifest-bound release"
    [ "$backup_format" = v3 ] ||
        die "runtime image ID changes are forbidden for format-2 backups because they lack a separate canonical release identity"

    runtime_release_identity=$(
        release_identity_from_images "$runtime_image_records"
    ) || die "cannot derive the current canonical release identity"
    [ "$runtime_release_identity" = "$backup_release_identity" ] ||
        die "current Config.Image references differ from the backup release identity"
    release_identity_is_manifest_bound "$runtime_release_identity" ||
        die "runtime image ID changes require every Config.Image reference to use the same canonical @sha256 manifest identity; mutable or local tags are forbidden"
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
    sh "$backup_verifier" "$backup_dir" "$database_name" >/dev/null &&
        verify_backup_acl
}

sha256_file()
{
    snapshot_hash_path=$1
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$snapshot_hash_path" |
            LC_ALL=C awk '
              NR == 1 &&
              length($1) == 64 &&
              $1 !~ /[^0-9a-f]/ {
                print $1
                valid = 1
              }
              END {
                if (!valid || NR != 1) {
                  exit 1
                }
              }
            '
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$snapshot_hash_path" |
            LC_ALL=C awk '
              NR == 1 &&
              length($1) == 64 &&
              $1 !~ /[^0-9a-f]/ {
                print $1
                valid = 1
              }
              END {
                if (!valid || NR != 1) {
                  exit 1
                }
              }
            '
    else
        return 1
    fi
}

portable_stat_owner_uid()
{
    snapshot_stat_path=$1
    if snapshot_stat_value=$(stat -c '%u' "$snapshot_stat_path" 2>/dev/null); then
        :
    elif snapshot_stat_value=$(stat -f '%u' "$snapshot_stat_path" \
        2>/dev/null); then
        :
    else
        return 1
    fi
    case "$snapshot_stat_value" in
        ''|*[!0-9]*) return 1 ;;
    esac
    printf '%s\n' "$snapshot_stat_value"
}

portable_stat_mode()
{
    snapshot_stat_path=$1
    if snapshot_stat_value=$(stat -c '%a' "$snapshot_stat_path" 2>/dev/null); then
        :
    elif snapshot_stat_value=$(stat -f '%Lp' "$snapshot_stat_path" \
        2>/dev/null); then
        :
    else
        return 1
    fi
    case "$snapshot_stat_value" in
        ''|*[!0-7]*) return 1 ;;
    esac
    printf '%s\n' "$snapshot_stat_value"
}

verify_no_extended_acl()
{
    acl_path=$1
    acl_label=$2
    acl_kind=$3
    case "$acl_kind" in
        file|directory) ;;
        *) die "internal restore ACL contract is invalid" ;;
    esac

    if command -v synoacltool >/dev/null 2>&1; then
        synology_acl_status=0
        synology_acl_output=$(LC_ALL=C synoacltool -get "$acl_path" \
            2>&1) || synology_acl_status=$?
        case "$synology_acl_output" in
            *"It's Linux mode"*|*"is Linux mode"*)
                # DSM has authoritatively reported that its extended ACL layer
                # is absent. Stock DSM may provide neither GNU getfacl nor GNU
                # ls, so this native proof is terminal.
                return
                ;;
            *)
                if [ "$synology_acl_status" -eq 0 ]; then
                    die "$acl_label has a Synology DSM ACL"
                fi
                die "cannot prove absence of a Synology DSM ACL for $acl_label"
                ;;
        esac
    fi

    acl_system=$(uname -s 2>/dev/null) ||
        die "cannot identify the ACL inspection platform for $acl_label"
    if [ "$acl_system" = Linux ] &&
        command -v getfacl >/dev/null 2>&1; then
        acl_listing=$(LC_ALL=C getfacl -cp -- "$acl_path" 2>/dev/null) ||
            die "cannot inspect POSIX ACLs for $acl_label"
        printf '%s\n' "$acl_listing" |
            LC_ALL=C awk '
              /^$/ {
                next
              }
              /^user::[rwx-][rwx-][rwx-]$/ {
                owner++
                next
              }
              /^group::[rwx-][rwx-][rwx-]$/ {
                group++
                next
              }
              /^other::[rwx-][rwx-][rwx-]$/ {
                other++
                next
              }
              {
                extended = 1
              }
              END {
                if (owner != 1 || group != 1 || other != 1 ||
                    extended) {
                  exit 1
                }
              }
            ' ||
            die "$acl_label has an extended POSIX ACL"
        return
    fi

    case "$acl_system" in
        Darwin)
            acl_listing=$(LC_ALL=C ls -lde "$acl_path" 2>/dev/null) ||
                die "cannot inspect macOS ACLs for $acl_label"
            acl_mode=$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'NR == 1 { print $1 }') ||
                die "cannot parse macOS ACL metadata for $acl_label"
            case "$acl_kind:$acl_mode" in
                file:-?????????|file:-?????????@|\
                directory:d?????????|directory:d?????????@)
                    ;;
                *)
                    die "$acl_label has an extended or unknown macOS ACL marker"
                    ;;
            esac
            [ "$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'END { print NR + 0 }')" -eq 1 ] ||
                die "$acl_label has extended macOS ACL entries"
            return
            ;;
        FreeBSD|OpenBSD|NetBSD)
            acl_listing=$(LC_ALL=C ls -ld "$acl_path" 2>/dev/null) ||
                die "cannot inspect BSD ACL metadata for $acl_label"
            acl_mode=$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'NR == 1 { print $1 }') ||
                die "cannot parse BSD ACL metadata for $acl_label"
            case "$acl_kind:$acl_mode" in
                file:-?????????|directory:d?????????) ;;
                *)
                    die "$acl_label has an extended or unknown BSD ACL marker"
                    ;;
            esac
            [ "$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'END { print NR + 0 }')" -eq 1 ] ||
                die "$acl_label has unexpected BSD ACL metadata"
            return
            ;;
        Linux)
            if ! LC_ALL=C ls --version 2>/dev/null |
                grep -Fq 'GNU coreutils'; then
                die "no trusted ACL probe is available for $acl_label"
            fi
            acl_listing=$(LC_ALL=C ls -ld -- "$acl_path" 2>/dev/null) ||
                die "cannot inspect GNU ACL metadata for $acl_label"
            acl_mode=$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'NR == 1 { print $1 }') ||
                die "cannot parse GNU ACL metadata for $acl_label"
            case "$acl_kind:$acl_mode" in
                file:-?????????|file:-?????????.|\
                directory:d?????????|directory:d?????????.)
                    ;;
                *)
                    die "$acl_label has an extended or unknown Linux ACL marker"
                    ;;
            esac
            return
            ;;
        *)
            die "no trusted ACL probe is implemented for $acl_label on $acl_system"
            ;;
    esac
}

verify_backup_acl()
{
    verify_no_extended_acl "$backup_dir" "backup directory" directory
    for acl_backup_name in \
        4fdata.tar.gz \
        SHA256SUMS \
        backup-created-utc.txt \
        backup-format.txt \
        database-name.txt \
        database.sql \
        export.tar.gz \
        image-references.txt \
        project-name.txt \
        storage-sources.txt
    do
        verify_no_extended_acl "$backup_dir/$acl_backup_name" \
            "backup input $acl_backup_name" file
    done
    if [ -e "$backup_dir/release-identity.txt" ] ||
        [ -L "$backup_dir/release-identity.txt" ]; then
        verify_no_extended_acl "$backup_dir/release-identity.txt" \
            "backup input release-identity.txt" file
    fi
}

prepare_snapshot_parent()
{
    requested_snapshot_parent=${ESTAB_RESTORE_SNAPSHOT_PARENT:-${requested_backup_dir%/*}}
    [ -n "$requested_snapshot_parent" ] ||
        requested_snapshot_parent=/
    case "$requested_snapshot_parent" in
        /*) ;;
        *)
            die "ESTAB_RESTORE_SNAPSHOT_PARENT must be an explicit absolute path"
            ;;
    esac
    case "$requested_snapshot_parent" in
        *'	'*|*'
'*)
            die "restore snapshot parent contains an unsupported control character"
            ;;
    esac
    if [ ! -d "$requested_snapshot_parent" ] ||
        [ -L "$requested_snapshot_parent" ] ||
        [ ! -w "$requested_snapshot_parent" ]; then
        die "restore snapshot parent must be an existing writable, non-symlink directory: $requested_snapshot_parent"
    fi
    snapshot_parent=$(
        CDPATH='' cd -- "$requested_snapshot_parent" && pwd -P
    ) || die "cannot resolve restore snapshot parent: $requested_snapshot_parent"
    [ "$snapshot_parent" != / ] ||
        die "filesystem root is forbidden as restore snapshot parent"
    case "$snapshot_parent" in
        "$requested_backup_dir"|"$requested_backup_dir"/*)
            die "restore snapshot parent must not be the backup directory or one of its descendants"
            ;;
    esac

    snapshot_operator_uid=$(id -u) ||
        die "cannot determine restore operator UID"
    snapshot_parent_uid=$(portable_stat_owner_uid "$snapshot_parent") ||
        die "cannot inspect restore snapshot parent owner with GNU, BSD, or BusyBox stat"
    if [ "$snapshot_parent_uid" -ne 0 ] &&
        [ "$snapshot_parent_uid" -ne "$snapshot_operator_uid" ]; then
        die "restore snapshot parent must be owned by root or the restore operator"
    fi
    snapshot_parent_mode=$(portable_stat_mode "$snapshot_parent") ||
        die "cannot inspect restore snapshot parent mode with GNU, BSD, or BusyBox stat"
    snapshot_parent_mode_value=$((0$snapshot_parent_mode))
    if [ $((snapshot_parent_mode_value & 022)) -ne 0 ] &&
        [ $((snapshot_parent_mode_value & 01000)) -eq 0 ]; then
        die "group/world-writable restore snapshot parent requires the sticky bit"
    fi
    verify_no_extended_acl "$snapshot_parent" \
        "restore snapshot parent" directory
}

snapshot_inventory()
{
    if [ "$backup_format" = v3 ]; then
        printf '%s\n' \
            4fdata.tar.gz \
            SHA256SUMS \
            backup-created-utc.txt \
            backup-format.txt \
            database-name.txt \
            database.sql \
            export.tar.gz \
            image-references.txt \
            project-name.txt \
            release-identity.txt \
            storage-sources.txt
    else
        printf '%s\n' \
            4fdata.tar.gz \
            SHA256SUMS \
            backup-created-utc.txt \
            backup-format.txt \
            database-name.txt \
            database.sql \
            export.tar.gz \
            image-references.txt \
            project-name.txt \
            storage-sources.txt
    fi
}

create_restore_snapshot()
{
    verify_backup ||
        die "backup changed before the private restore snapshot"
    current_manifest_sha=$(sha256_file "$backup_dir/SHA256SUMS") ||
        die "cannot hash backup manifest before the private restore snapshot"
    [ "$current_manifest_sha" = "$source_manifest_sha" ] ||
        die "backup manifest changed before the private restore snapshot"
    verify_operator_path_separation "$snapshot_parent" "$runtime_storage" \
        "restore snapshot parent"

    snapshot_dir=$(mktemp -d \
        "${snapshot_parent%/}/.estab-restore-snapshot.XXXXXX") ||
        die "cannot create private restore snapshot"
    chmod 0700 "$snapshot_dir" ||
        die "cannot protect private restore snapshot"
    verify_no_extended_acl "$snapshot_dir" \
        "private restore snapshot" directory
    verify_operator_path_separation "$snapshot_dir" "$runtime_storage" \
        "private restore snapshot"

    while IFS= read -r snapshot_name; do
        snapshot_source=$backup_dir/$snapshot_name
        snapshot_destination=$snapshot_dir/$snapshot_name
        if [ ! -f "$snapshot_source" ] ||
            [ -L "$snapshot_source" ] ||
            [ -e "$snapshot_destination" ] ||
            [ -L "$snapshot_destination" ]; then
            die "backup inventory changed while creating private restore snapshot: $snapshot_name"
        fi
        cp "$snapshot_source" "$snapshot_destination" ||
            die "cannot copy backup entry into private restore snapshot: $snapshot_name"
        chmod 0600 "$snapshot_destination" ||
            die "cannot protect private restore snapshot entry: $snapshot_name"
        verify_no_extended_acl "$snapshot_destination" \
            "private restore snapshot entry $snapshot_name" file
    done <<EOF
$(snapshot_inventory)
EOF

    snapshot_manifest_sha=$(sha256_file "$snapshot_dir/SHA256SUMS") ||
        die "cannot hash private restore snapshot manifest"
    [ "$snapshot_manifest_sha" = "$source_manifest_sha" ] ||
        die "backup changed while the private restore snapshot was copied"
    backup_dir=$snapshot_dir
    verify_backup ||
        die "private restore snapshot verification failed"
    snapshot_ready=1
}

remove_restore_snapshot()
{
    [ -n "$snapshot_dir" ] || return 0
    if [ ! -d "$snapshot_dir" ] || [ -L "$snapshot_dir" ]; then
        return 1
    fi
    snapshot_canonical=$(CDPATH='' cd -- "$snapshot_dir" && pwd -P) ||
        return 1
    case "$snapshot_canonical" in
        "$snapshot_parent"/.estab-restore-snapshot.*) ;;
        *) return 1 ;;
    esac
    [ "$snapshot_canonical" = "$snapshot_dir" ] || return 1

    snapshot_cleanup_names=$(
        find "$snapshot_dir" -mindepth 1 -maxdepth 1 -exec basename {} \; |
            LC_ALL=C sort
    ) || return 1
    while IFS= read -r snapshot_cleanup_name; do
        [ -n "$snapshot_cleanup_name" ] || continue
        case "
$(snapshot_inventory)
" in
            *"
$snapshot_cleanup_name
"*) ;;
            *) return 1 ;;
        esac
        snapshot_cleanup_path=$snapshot_dir/$snapshot_cleanup_name
        if [ ! -f "$snapshot_cleanup_path" ] &&
            [ ! -L "$snapshot_cleanup_path" ]; then
            return 1
        fi
        rm -f -- "$snapshot_cleanup_path" || return 1
    done <<EOF
$snapshot_cleanup_names
EOF
    rmdir -- "$snapshot_dir" || return 1
    snapshot_dir=
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
    refreshed_app_container=$(single_container app) ||
        die "cannot refresh Compose service: app"
    if [ -n "$restore_app_container" ] &&
        [ "$refreshed_app_container" != "$restore_app_container" ]; then
        die "application container identity changed during restore"
    fi
    refreshed_db_container=$(single_container db) ||
        die "cannot refresh Compose service: db"
    refreshed_migrate_container=$(single_container migrate) ||
        die "cannot refresh Compose service: migrate"
    app_container=$refreshed_app_container
    db_container=$refreshed_db_container
    migrate_container=$refreshed_migrate_container
}

bind_restore_app_container()
{
    bound_app_exact_id=$(inspect_value "$app_container" '{{.Id}}') ||
        die "cannot bind restore to the exact application container"
    case "$bound_app_exact_id" in
        ''|*' '*|*'	'*|*'
'*)
            die "application returned an unsafe exact container ID"
            ;;
    esac
    verify_project_container app "$bound_app_exact_id"
    restore_app_container=$bound_app_exact_id
    app_container=$bound_app_exact_id
}

prove_restore_app_stopped()
{
    [ -n "$restore_app_container" ] || return 1
    proven_app_exact_id=$(inspect_value "$restore_app_container" \
        '{{.Id}}' 2>/dev/null) || return 1
    [ "$proven_app_exact_id" = "$restore_app_container" ] || return 1
    proven_app_project=$(inspect_value "$restore_app_container" \
        '{{ index .Config.Labels "com.docker.compose.project" }}' \
        2>/dev/null) || return 1
    [ "$proven_app_project" = "$confirmed_project" ] || return 1
    proven_app_running=$(inspect_value "$restore_app_container" \
        '{{.State.Running}}' 2>/dev/null) || return 1
    [ "$proven_app_running" = false ] || return 1
    proven_app_status=$(inspect_value "$restore_app_container" \
        '{{.State.Status}}' 2>/dev/null) || return 1
    case "$proven_app_status" in
        created|configured|exited) ;;
        *) return 1 ;;
    esac
}

require_restore_app_stopped()
{
    prove_restore_app_stopped ||
        die "the exact application container is not proven stopped at a destructive restore boundary"
}

stop_restore_app_at_boundary()
{
    [ -n "$restore_app_container" ] ||
        die "cannot stop an unbound application container"
    "$container_cli" stop "$restore_app_container" >/dev/null ||
        die "the exact application container could not be stopped at a destructive restore boundary"
    app_stopped_by_restore=1
    require_restore_app_stopped
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
    verify_mount_read_write database "$db_container" /var/lib/mysql
    verify_mount_read_write application "$app_container" \
        /var/www/html/4fdata
    verify_mount_read_write export "$app_container" \
        /var/lib/estab/export
    verify_storage_source_separation "$runtime_storage"
    verify_operator_path_separation "$backup_dir" "$runtime_storage"
    verify_storage_identity "$runtime_storage"

    runtime_images=$(
        image_record app "$app_container"
        image_record migrate "$migrate_container"
        image_record database "$db_container"
    )
    runtime_images=$(printf '%s\n' "$runtime_images" | LC_ALL=C sort)
    verify_runtime_images "$runtime_images"
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
    current_app_image=$(image_record app "$app_container")
    [ "$admin_auth_image" = "$current_app_image" ] ||
        die "admin authentication initializer does not use the current verified app image"
}

verify_file_mount_writes()
{
    maintenance_lock_is_owned ||
        die "engine-global maintenance lock was lost before file write preflight"
    "$container_cli" run --rm --network none --read-only \
        --volumes-from "${app_container}:z" \
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
        die "backup does not contain a safe bound database name"
        ;;
esac
verify_backup ||
    die "backup verification failed"
backup_format_record=$(sed -n '1p' "$backup_dir/backup-format.txt")
case "$backup_format_record" in
    estab-full-backup-v2)
        backup_format=v2
        ;;
    estab-full-backup-v3)
        backup_format=v3
        ;;
    *)
        die "production restore requires the fully bound backup format 2 or 3; legacy backups remain verification-only"
        ;;
esac
if [ "$backup_format" = v2 ] &&
    [ "$allow_runtime_image_id_change" -eq 1 ]; then
    die "format-2 backups cannot authorize runtime image ID changes because they lack a separate canonical release identity; use exact same-runtime restore or create a new format-3 backup"
fi
if [ "$backup_format" = v2 ] &&
    { [ -n "$project_remap" ] ||
      [ -n "$database_storage_remap" ] ||
      [ -n "$application_storage_remap" ] ||
      [ -n "$export_storage_remap" ] ||
      [ -n "$database_mount_type_remap" ] ||
      [ -n "$application_mount_type_remap" ] ||
      [ -n "$export_mount_type_remap" ] ||
      [ -n "$database_volume_remap" ] ||
      [ -n "$application_volume_remap" ] ||
      [ -n "$export_volume_remap" ]; }; then
    die "format-2 backups require an exact same-host restore and cannot use project, mount-type, storage, or volume remaps"
fi
source_manifest_sha=$(sha256_file "$backup_dir/SHA256SUMS") ||
    die "cannot bind restore to the verified backup manifest"
prepare_snapshot_parent
backup_project=$(sed -n '1p' "$backup_dir/project-name.txt")
case "$backup_project" in
    ''|[!a-z0-9]*|*[!a-z0-9_-]*)
        die "backup Compose project is not in canonical lowercase form"
        ;;
esac
[ "${#backup_project}" -le 128 ] ||
    die "backup Compose project is too long"
if [ "$backup_project" = "$confirmed_project" ]; then
    [ -z "$project_remap" ] ||
        die "project remap is unnecessary or ambiguous because source and target projects are already equal"
else
    [ -n "$project_remap" ] ||
        die "backup project $backup_project differs from --confirm-project $confirmed_project; provide the exact explicit --remap-project SOURCE=TARGET"
    [ "$project_remap_source" = "$backup_project" ] &&
        [ "$project_remap_target" = "$confirmed_project" ] ||
        die "project remap does not exactly map backup project $backup_project to confirmed project $confirmed_project"
fi
backup_storage=$(LC_ALL=C sort "$backup_dir/storage-sources.txt")
verify_storage_source_separation "$backup_storage"
backup_images_raw=$(LC_ALL=C sort "$backup_dir/image-references.txt")
backup_images=$(canonicalize_image_records "$backup_images_raw") ||
    die "cannot canonicalize verified backup image records"
backup_images=$(printf '%s\n' "$backup_images" | LC_ALL=C sort)
backup_release_identity=
if [ "$backup_format" = v3 ]; then
    backup_release_identity_raw=$(
        LC_ALL=C sort "$backup_dir/release-identity.txt"
    )
    backup_release_identity=$(
        canonicalize_release_identity "$backup_release_identity_raw"
    ) || die "cannot canonicalize verified backup release identity"
    backup_release_identity=$(
        printf '%s\n' "$backup_release_identity" | LC_ALL=C sort
    )
fi

compose config >/dev/null ||
    die "effective Compose configuration is invalid"

maintenance_lock_held=0
maintenance_lock_id=
maintenance_lock_name=
maintenance_lock_token=
maintenance_lock_image=
snapshot_dir=
snapshot_ready=0
restore_completed=0
app_was_running=false
app_stopped_by_restore=0
app_container=
db_container=
migrate_container=
restore_app_container=
destructive_started=0
restore_stage=preflight

cleanup()
{
    cleanup_status=$1
    trap - EXIT HUP INT TERM
    preserve_maintenance_lock=0
    unverified_maintenance_lock=0

    if [ "$cleanup_status" -ne 0 ]; then
        if [ "$destructive_started" -eq 1 ]; then
            cleanup_app_container=$restore_app_container
            fail_closed_stop_proven=0
            if [ -n "$cleanup_app_container" ] &&
                "$container_cli" stop "$cleanup_app_container" \
                    >/dev/null 2>&1 &&
                prove_restore_app_stopped; then
                fail_closed_stop_proven=1
            fi
            if [ "$maintenance_lock_held" -eq 1 ] &&
                maintenance_lock_is_owned; then
                preserve_maintenance_lock=1
            else
                unverified_maintenance_lock=1
            fi
            printf '%s\n' \
                "eStab restore: RECOVERY REQUIRED for project $confirmed_project." \
                "eStab restore: stage=$restore_stage; database and file data may be partially restored." \
                "eStab restore: private verified recovery snapshot: $snapshot_dir" \
                >&2
            if [ "$preserve_maintenance_lock" -eq 1 ]; then
                printf '%s\n' \
                    "eStab restore: the owned and running engine-global lock $maintenance_lock_name ($maintenance_lock_id) is intentionally retained." \
                    "eStab restore: correct the cause, prove that no maintenance is running, remove exactly that lock container, and rerun this exact verified restore." \
                    >&2
            else
                printf '%s\n' \
                    "eStab restore: WARNING: ownership and running state of the expected maintenance lock cannot be proven; no retained-lock claim is made." \
                    "eStab restore: isolate the project and inspect the container engine before any recovery action." \
                    >&2
            fi
            if [ "$fail_closed_stop_proven" -eq 1 ]; then
                printf '%s\n' \
                    "eStab restore: the exact application container ID, project, stopped flag, and non-running lifecycle status are proven. Do not start it manually." \
                    >&2
            else
                printf '%s\n' \
                    "eStab restore: WARNING: the exact application identity and stopped status could not be proven; isolate the service immediately and do not use it." \
                    >&2
            fi
        elif [ "$app_stopped_by_restore" -eq 1 ] &&
            [ "$app_was_running" = true ]; then
            cleanup_app_container=${restore_app_container:-$app_container}
            if [ -n "$cleanup_app_container" ] &&
                "$container_cli" start "$cleanup_app_container" \
                    >/dev/null 2>&1; then
                if ! wait_for_healthy app "$cleanup_app_container"; then
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
        elif [ "$unverified_maintenance_lock" -eq 1 ]; then
            printf 'eStab restore: WARNING: refusing to remove or claim retention of an unverified maintenance lock: %s (expected id %s)\n' \
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
    if [ "$cleanup_status" -ne 0 ] &&
        [ "$destructive_started" -eq 1 ] &&
        [ "$restore_completed" -eq 0 ] &&
        [ "$snapshot_ready" -eq 1 ]; then
        if sh "$backup_verifier" "$snapshot_dir" "$database_name" >/dev/null; then
            printf 'eStab restore: retained verified recovery snapshot: %s\n' \
                "$snapshot_dir" >&2
        else
            printf 'eStab restore: WARNING: retained recovery snapshot no longer verifies: %s\n' \
                "$snapshot_dir" >&2
        fi
    elif [ -n "$snapshot_dir" ]; then
        if ! remove_restore_snapshot; then
            printf 'eStab restore: WARNING: private restore snapshot could not be safely removed: %s\n' \
                "$snapshot_dir" >&2
            [ "$cleanup_status" -ne 0 ] || cleanup_status=1
        fi
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
bind_restore_app_container
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
verify_no_foreign_storage_consumers
create_restore_snapshot
app_was_running=$(inspect_value "$app_container" '{{.State.Running}}') ||
    die "cannot inspect application state"
case "$app_was_running" in
    true|false) ;;
    *) die "application reported an unknown running state" ;;
esac

stop_restore_app_at_boundary
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
verify_no_foreign_storage_consumers
stop_restore_app_at_boundary

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
require_restore_app_stopped
wait_for_healthy db "$db_container" ||
    die "database is not healthy after import"

restore_stage=file-boundary
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before file restore"
verify_runtime_target
verify_backup ||
    die "backup changed before file-volume restore"
verify_no_foreign_storage_consumers
stop_restore_app_at_boundary

restore_stage=file-volumes-cleared
stop_restore_app_at_boundary
"$container_cli" run --rm --network none \
    --volumes-from "${app_container}:z" \
    --entrypoint sh "$app_runtime_image" -ceu '
    find /var/www/html/4fdata -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
    find /var/lib/estab/export -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
    test -z "$(find /var/www/html/4fdata -mindepth 1 -print -quit)"
    test -z "$(find /var/lib/estab/export -mindepth 1 -print -quit)"
'
require_restore_app_stopped

restore_stage=application-data-extract
stop_restore_app_at_boundary
"$container_cli" run --rm --interactive --network none \
    --volumes-from "${app_container}:z" \
    --entrypoint sh "$app_runtime_image" -ceu \
    'tar -xzf - -C /var/www/html/4fdata' \
    <"$backup_dir/4fdata.tar.gz"
require_restore_app_stopped

restore_stage=export-data-extract
stop_restore_app_at_boundary
"$container_cli" run --rm --interactive --network none \
    --volumes-from "${app_container}:z" \
    --entrypoint sh "$app_runtime_image" -ceu \
    'tar -xzf - -C /var/lib/estab/export' \
    <"$backup_dir/export.tar.gz"
require_restore_app_stopped
verify_backup ||
    die "backup changed while file volumes were restored"

restore_stage=migration
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before migration"
stop_restore_app_at_boundary
"$container_cli" start --attach "$migrate_container"
migrated_container_candidate=$(single_container migrate) ||
    die "cannot resolve migration service after restore"
[ "$migrated_container_candidate" = "$migrate_container" ] ||
    die "migration container identity changed during restore"
migrate_container=$migrated_container_candidate
migrate_running=$(inspect_value "$migrate_container" '{{.State.Running}}') ||
    die "cannot inspect migration state after restore"
migrate_exit_code=$(inspect_value "$migrate_container" '{{.State.ExitCode}}') ||
    die "cannot inspect migration exit status after restore"
[ "$migrate_running" = false ] && [ "$migrate_exit_code" = 0 ] ||
    die "migration did not complete successfully after restore"
require_restore_app_stopped
verify_runtime_target

restore_stage=application-start
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before application start"
require_restore_app_stopped
"$container_cli" start "$app_container" >/dev/null ||
    die "application could not be started after restore"
started_app_container=$(single_container app) ||
    die "cannot resolve application after starting restored service"
[ "$started_app_container" = "$restore_app_container" ] ||
    die "application container identity changed while starting restored service"
verify_project_container app "$started_app_container"
app_container=$started_app_container
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
    "$confirmed_project" "$requested_backup_dir"
