#!/bin/sh

set -eu
umask 077

die()
{
    printf 'eStab backup: %s\n' "$*" >&2
    exit 1
}

if [ "$#" -ne 1 ]; then
    printf 'Usage: %s ABSOLUTE_BACKUP_DIRECTORY\n' "$0" >&2
    exit 64
fi

requested_target=$1
case "$requested_target" in
    /*) ;;
    *)
        printf 'eStab backup: backup target must be an explicit absolute path.\n' >&2
        exit 64
        ;;
esac

backup_target=${requested_target%/}
case "$backup_target" in
    ''|/)
        printf 'eStab backup: refusing unsafe backup target: %s\n' \
            "$requested_target" >&2
        exit 64
        ;;
esac

backup_name=${backup_target##*/}
backup_parent=${backup_target%/*}
[ -n "$backup_parent" ] || backup_parent=/
case "$backup_name" in
    ''|.|..|*[!A-Za-z0-9_.-]*)
        printf 'eStab backup: backup directory name must use only A-Z, a-z, 0-9, dot, underscore, or hyphen.\n' >&2
        exit 64
        ;;
esac

[ -d "$backup_parent" ] ||
    die "backup parent directory does not exist: $backup_parent"
canonical_parent=$(CDPATH='' cd -- "$backup_parent" && pwd -P) ||
    die "cannot resolve backup parent directory: $backup_parent"
backup_target="${canonical_parent%/}/$backup_name"

portable_path_identity()
{
    identity_path=$1
    if stat -c '%d:%i:%u:%a' "$identity_path" 2>/dev/null; then
        return
    fi
    stat -f '%d:%i:%u:%Lp' "$identity_path" 2>/dev/null
}

verify_no_extended_acl()
{
    acl_path=$1
    acl_label=$2

    if command -v synoacltool >/dev/null 2>&1; then
        synology_acl_status=0
        synology_acl_output=$(LC_ALL=C synoacltool -get "$acl_path" \
            2>&1) || synology_acl_status=$?
        case "$synology_acl_output" in
            *"It's Linux mode"*|*"is Linux mode"*)
                # Synology reports paths without a DSM ACL through this
                # diagnostic (commonly with a non-zero status). Every caller
                # separately binds owner, inode, and exact 0700 mode. Stock
                # DSM need not provide GNU ls or getfacl, so this is the
                # terminal native ACL proof.
                return
                ;;
            *)
                if [ "$synology_acl_status" -eq 0 ]; then
                    die "$acl_label has a Synology DSM ACL; only owner access is allowed"
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
              $0 == "user::rwx" {
                owner++
                next
              }
              $0 == "group::---" {
                group++
                next
              }
              $0 == "other::---" {
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
            die "$acl_label has an extended or access-granting POSIX ACL"
        return
    fi

    case "$acl_system" in
        Darwin)
            acl_listing=$(LC_ALL=C ls -lde "$acl_path" 2>/dev/null) ||
                die "cannot inspect macOS ACLs for $acl_label"
            acl_mode=$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'NR == 1 { print $1 }') ||
                die "cannot parse macOS ACL metadata for $acl_label"
            case "$acl_mode" in
                d?????????|d?????????@) ;;
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
            case "$acl_mode" in
                d?????????) ;;
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
            case "$(LC_ALL=C ls --version 2>/dev/null || :)" in
                *'GNU coreutils'*) ;;
                *)
                    die "no trusted ACL probe (getfacl, GNU ls, or Synology ACL tool) is available for $acl_label"
                    ;;
            esac
            acl_listing=$(LC_ALL=C ls -ld -- "$acl_path" 2>/dev/null) ||
                die "cannot inspect GNU ACL metadata for $acl_label"
            acl_mode=$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'NR == 1 { print $1 }') ||
                die "cannot parse GNU ACL metadata for $acl_label"
            case "$acl_mode" in
                *+*)
                    die "$acl_label has an extended Linux ACL"
                    ;;
                d?????????|d?????????.) ;;
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

if [ -e "$backup_target" ] || [ -L "$backup_target" ]; then
    die "backup target already exists: $backup_target"
fi
[ -w "$canonical_parent" ] ||
    die "backup parent directory is not writable: $canonical_parent"
backup_parent_identity=$(portable_path_identity "$canonical_parent") ||
    die "cannot inspect backup parent metadata: $canonical_parent"
backup_operator_uid=$(id -u) ||
    die "cannot determine backup operator UID"
case "$backup_parent_identity" in
    *:*:"$backup_operator_uid":700) ;;
    *)
        die "backup parent must be an owner-only 0700 directory owned by the backup operator: $canonical_parent"
        ;;
esac
verify_no_extended_acl "$canonical_parent" "backup parent"

require_backup_parent_secure()
{
    current_parent_identity=$(portable_path_identity "$canonical_parent") ||
        die "cannot reinspect backup parent metadata: $canonical_parent"
    [ "$current_parent_identity" = "$backup_parent_identity" ] ||
        die "backup parent filesystem identity, owner, or mode changed"
    verify_no_extended_acl "$canonical_parent" "backup parent"
}

require_private_directory_unchanged()
{
    private_path=$1
    private_expected_identity=$2
    private_label=$3
    [ -d "$private_path" ] && [ ! -L "$private_path" ] ||
        die "$private_label is no longer a real directory"
    private_current_identity=$(portable_path_identity "$private_path") ||
        die "cannot inspect $private_label metadata"
    [ "$private_current_identity" = "$private_expected_identity" ] ||
        die "$private_label filesystem identity, owner, or mode changed"
    case "$private_current_identity" in
        *:*:"$backup_operator_uid":700) ;;
        *) die "$private_label is not an owner-only 0700 directory" ;;
    esac
    verify_no_extended_acl "$private_path" "$private_label"
}

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
backup_verifier=$script_dir/verify-backup.sh
[ -f "$backup_verifier" ] && [ ! -L "$backup_verifier" ] &&
    [ -r "$backup_verifier" ] ||
    die "backup verifier is missing or unreadable: $backup_verifier"

move_cli=${ESTAB_MOVE_CLI:-mv}
case "$move_cli" in
    *' '*|*'	'*|*'
'*)
        die "ESTAB_MOVE_CLI must name one executable without arguments"
        ;;
esac
command -v "$move_cli" >/dev/null 2>&1 ||
    die "move executable is unavailable: $move_cli"

mkdir_cli=${ESTAB_MKDIR_CLI:-mkdir}
case "$mkdir_cli" in
    *' '*|*'	'*|*'
'*)
        die "ESTAB_MKDIR_CLI must name one executable without arguments"
        ;;
esac
command -v "$mkdir_cli" >/dev/null 2>&1 ||
    die "directory reservation executable is unavailable: $mkdir_cli"

health_timeout_seconds=${ESTAB_BACKUP_HEALTH_TIMEOUT_SECONDS:-240}
case "$health_timeout_seconds" in
    ''|*[!0-9]*|0?*)
        die "ESTAB_BACKUP_HEALTH_TIMEOUT_SECONDS must be an integer from 1 to 3600"
        ;;
esac
if [ "${#health_timeout_seconds}" -gt 4 ] ||
    [ "$health_timeout_seconds" -lt 1 ] ||
    [ "$health_timeout_seconds" -gt 3600 ]; then
    die "ESTAB_BACKUP_HEALTH_TIMEOUT_SECONDS must be an integer from 1 to 3600"
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
    safe_identifier "Compose service name" "$container_service"
    provider_container_rows=$("$container_cli" ps \
        --all \
        --no-trunc \
        --filter "label=com.docker.compose.project=$confirmed_project" \
        --filter "label=com.docker.compose.service=$container_service" \
        --format '{{.ID}}') ||
        die "cannot enumerate engine containers for Compose service: $container_service"
    provider_container_id=$(
        printf '%s\n' "$provider_container_rows" |
            LC_ALL=C awk '
              NF == 0 {
                next
              }
              NF != 1 || length($0) < 12 || length($0) > 64 ||
              $0 ~ /[^0-9a-f]/ {
                invalid = 1
                next
              }
              {
                matches++
                result = $0
              }
              END {
                if (matches != 1 || invalid) {
                  exit 1
                }
                print result
              }
            '
    ) || die "Compose service must resolve to exactly one engine container: $container_service"
    container_id=$(inspect_value "$provider_container_id" '{{.Id}}') ||
        die "cannot normalize Compose service to an exact container ID: $container_service"
    container_id=$(canonical_container_id "$container_id") ||
        die "Compose service returned an unsafe exact container ID: $container_service"
    resolved_project=$(inspect_value "$container_id" \
        '{{ index .Config.Labels "com.docker.compose.project" }}') ||
        die "cannot verify Compose project label for service: $container_service"
    resolved_service=$(inspect_value "$container_id" \
        '{{ index .Config.Labels "com.docker.compose.service" }}') ||
        die "cannot verify Compose service label for service: $container_service"
    [ "$resolved_project" = "$confirmed_project" ] &&
        [ "$resolved_service" = "$container_service" ] ||
        die "engine service filters returned a container with mismatched Compose labels: $container_service"
    printf '%s\n' "$container_id"
}

compose_project_name()
{
    compose_configuration=$(compose config) ||
        die "cannot render the active Compose configuration"
    printf '%s\n' "$compose_configuration" |
        LC_ALL=C awk '
          /^name: / {
            value = substr($0, 7)
            if (value !~ /^[a-z0-9][a-z0-9_-]*$/) {
              invalid = 1
            }
            matches++
            result = value
          }
          END {
            if (matches != 1 || invalid) {
              exit 1
            }
            print result
          }
        '
}

wait_for_healthy()
{
    health_service=$1
    health_container=$2
    health_elapsed=0

    while :; do
        health_running=$(inspect_value "$health_container" \
            '{{.State.Running}}' 2>/dev/null) || {
                printf 'eStab backup: cannot inspect Compose service while waiting for health: %s\n' \
                    "$health_service" >&2
                return 1
            }
        health_status=$(inspect_value "$health_container" \
            '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' \
            2>/dev/null) || {
                printf 'eStab backup: cannot inspect health status for Compose service: %s\n' \
                    "$health_service" >&2
                return 1
            }

        if [ "$health_running" != true ]; then
            printf 'eStab backup: Compose service stopped while waiting for health: %s\n' \
                "$health_service" >&2
            return 1
        fi
        case "$health_status" in
            healthy)
                return 0
                ;;
            unhealthy)
                printf 'eStab backup: Compose service became unhealthy: %s\n' \
                    "$health_service" >&2
                return 1
                ;;
            starting)
                ;;
            missing|''|'<no value>'|'<nil>')
                printf 'eStab backup: Compose service has no inspectable health check: %s\n' \
                    "$health_service" >&2
                return 1
                ;;
            *)
                printf 'eStab backup: Compose service reported an unknown health status (%s): %s\n' \
                    "$health_status" "$health_service" >&2
                return 1
                ;;
        esac

        if [ "$health_elapsed" -ge "$health_timeout_seconds" ]; then
            printf 'eStab backup: Compose service did not become healthy within %s seconds: %s\n' \
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

restart_application()
{
    "$container_cli" start "$app_container" >/dev/null 2>&1 || return 1
    wait_for_healthy app "$app_container"
}

require_application_stopped()
{
    stopped_app_id=$(inspect_value "$app_container" '{{.Id}}' 2>/dev/null) ||
        die "cannot re-identify the stopped application container"
    stopped_app_id=$(canonical_container_id "$stopped_app_id") ||
        die "stopped application container has an invalid exact ID"
    [ "$stopped_app_id" = "$app_exact_id" ] ||
        die "application container identity changed while stopped"
    stopped_app_running=$(inspect_value "$app_exact_id" \
        '{{.State.Running}}' 2>/dev/null) ||
        die "cannot prove that the application remains stopped"
    [ "$stopped_app_running" = false ] ||
        die "application is running; consistent backup capture will not begin"
}

safe_identifier()
{
    identifier_kind=$1
    identifier_value=$2
    case "$identifier_value" in
        ''|*[!A-Za-z0-9_.-]*)
            die "$identifier_kind is empty or unsafe"
            ;;
    esac
}

safe_compose_project()
{
    case "$1" in
        ''|[!a-z0-9]*|*[!a-z0-9_-]*)
            die "Compose project name is not in canonical lowercase form"
            ;;
    esac
    [ "${#1}" -le 128 ] ||
        die "Compose project name is too long for the maintenance lock"
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

canonical_container_id()
{
    canonical_container_value=$1
    case "$canonical_container_value" in
        ''|*[!0123456789abcdef]*) return 1 ;;
    esac
    [ "${#canonical_container_value}" -eq 64 ] || return 1
    printf '%s\n' "$canonical_container_value"
}

image_record()
{
    image_role=$1
    image_container=$2
    image_reference=$(inspect_value "$image_container" '{{.Config.Image}}' 2>/dev/null || :)
    case "$image_reference" in
        ''|'<no value>'|'<nil>'|null)
            image_reference=$(inspect_value "$image_container" '{{.ImageName}}' 2>/dev/null || :)
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
    image_digest="sha256:$image_digest_hex"
    printf '%s\t%s\t%s\n' "$image_role" "$image_reference" "$image_digest"
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
    separation_alias_one=
    separation_alias_two=
    separation_platform=$(uname -s 2>/dev/null) ||
        die "cannot identify the host platform for storage separation"
    if [ "$separation_platform" = Darwin ] &&
        [ "${container_cli##*/}" = docker ]; then
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
          NF != 5 ||
          overlaps(operator_path, $5) ||
          (alias_one != "" && overlaps(alias_one, $5)) ||
          (alias_two != "" && overlaps(alias_two, $5)) {
            exit 1
          }
        ' ||
        die "backup parent overlaps a productive storage source"
}

engine_container_inventory()
{
    inventory_raw=$("$container_cli" ps --all --quiet) || return 1
    inventory_values=
    while IFS= read -r inventory_candidate; do
        [ -n "$inventory_candidate" ] || continue
        case "$inventory_candidate" in
            *' '*|*'	'*|*'
'*) return 1 ;;
        esac
        inventory_exact=$(inspect_value "$inventory_candidate" \
            '{{.Id}}' 2>/dev/null) || return 1
        inventory_exact=$(canonical_container_id "$inventory_exact") ||
            return 1
        if [ -n "$inventory_values" ]; then
            inventory_values="$inventory_values
$inventory_exact"
        else
            inventory_values=$inventory_exact
        fi
    done <<EOF
$inventory_raw
EOF
    [ -n "$inventory_values" ] || return 0
    inventory_sorted=$(printf '%s\n' "$inventory_values" |
        LC_ALL=C sort) || return 1
    printf '%s\n' "$inventory_sorted" |
        LC_ALL=C awk '
          NF != 1 || length($0) != 64 ||
          $0 ~ /[^0-9a-f]/ || seen[$0]++ {
            exit 1
          }
        ' || return 1
    printf '%s\n' "$inventory_sorted"
}

verify_no_foreign_storage_consumers()
{
    scan_storage_records=$1
    foreign_ids_before=$(engine_container_inventory) ||
        die "cannot capture a stable engine-wide container inventory before backup"
    while IFS= read -r foreign_exact_id; do
        [ -n "$foreign_exact_id" ] || continue
        case "$foreign_exact_id" in
            "$app_exact_id"|"$db_exact_id"|"$migrate_exact_id"|\
            "$maintenance_lock_id")
                continue
                ;;
        esac

        foreign_mount_rows=$(inspect_value "$foreign_exact_id" \
            '{{range .Mounts}}{{printf "%s\t%s\n" .Type .Source}}{{end}}') ||
            die "cannot inspect foreign container mounts during backup: $foreign_exact_id"
        foreign_sources=$(
            printf '%s\n' "$foreign_mount_rows" |
                LC_ALL=C awk -F '	' '
                  NF == 0 {
                    next
                  }
                  NF != 2 || $1 !~ /^[A-Za-z0-9_.-]+$/ {
                    exit 1
                  }
                  $1 == "bind" || $1 == "volume" {
                    if ($2 !~ /^\// ||
                        $2 ~ /\/\// ||
                        $2 ~ /\/\.\.?($|\/)/ ||
                        ($2 != "/" && $2 ~ /\/$/)) {
                      exit 1
                    }
                    print $1 "\t" $2
                  }
                '
        ) || die "foreign container has unsafe or uninspectable mount metadata: $foreign_exact_id"
        foreign_conflict=
        while IFS='	' read -r foreign_mount_type foreign_mount_source; do
            [ -n "$foreign_mount_type" ] || continue
            while IFS='	' read -r target_role _target_destination \
                _target_type _target_name target_source
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
$scan_storage_records
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
            die "foreign container $foreign_exact_id (project $foreign_project) mounts $foreign_conflict_source equal to or overlapping productive $foreign_conflict_role source $foreign_conflict_target"
        fi
    done <<EOF
$foreign_ids_before
EOF
    foreign_ids_after=$(engine_container_inventory) ||
        die "cannot recapture the engine-wide container inventory after backup storage scan"
    [ "$foreign_ids_after" = "$foreign_ids_before" ] ||
        die "engine-wide container inventory changed during backup storage scan"
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
        printf 'eStab backup: no inspectable lock container named %s was found; inspect the preceding container-engine error.\n' \
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
    printf 'eStab backup: engine-global maintenance lock: name=%s id=%s project=%s operation=%s owner=%s started_utc=%s status=%s\n' \
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
    [ "$owned_lock_project" = "$maintenance_lock_project" ] &&
    [ "$owned_lock_operation" = backup ] &&
    [ "$owned_lock_token" = "$maintenance_lock_token" ] &&
    [ "$owned_lock_status" = running ] &&
    [ "$owned_lock_running" = true ] &&
    [ "$owned_lock_image" = "$maintenance_lock_image" ]
}

acquire_maintenance_lock()
{
    maintenance_lock_project=$1
    maintenance_lock_runtime_image=$2
    safe_compose_project "$maintenance_lock_project"
    maintenance_lock_image=$(canonical_image_id \
        "$maintenance_lock_runtime_image") ||
        die "verified app runtime image is invalid for the maintenance lock"
    maintenance_lock_name=estab-maintenance-lock-$maintenance_lock_project
    maintenance_lock_started=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    maintenance_lock_token="backup-$$-$(date -u '+%Y%m%dT%H%M%SZ')"

    if ! "$container_cli" run --detach \
        --name "$maintenance_lock_name" \
        --label org.e-stab.maintenance-lock=true \
        --label "org.e-stab.compose-project=$maintenance_lock_project" \
        --label org.e-stab.maintenance-operation=backup \
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
        die "cannot acquire engine-global maintenance lock for project $maintenance_lock_project; a stale lock remains fail-closed until an operator proves no maintenance is running and removes that exact lock container"
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

inspect_backup_runtime()
{
    app_container=$(single_container app)
    db_container=$(single_container db)
    migrate_container=$(single_container migrate)
    app_exact_id=$(inspect_value "$app_container" '{{.Id}}' 2>/dev/null) ||
        die "cannot resolve the exact application container ID"
    app_exact_id=$(canonical_container_id "$app_exact_id") ||
        die "application container has an invalid exact ID"
    db_exact_id=$(inspect_value "$db_container" '{{.Id}}' 2>/dev/null) ||
        die "cannot resolve the exact database container ID"
    db_exact_id=$(canonical_container_id "$db_exact_id") ||
        die "database container has an invalid exact ID"
    migrate_exact_id=$(inspect_value "$migrate_container" '{{.Id}}' \
        2>/dev/null) ||
        die "cannot resolve the exact migration container ID"
    migrate_exact_id=$(canonical_container_id "$migrate_exact_id") ||
        die "migration container has an invalid exact ID"
    [ "$app_exact_id" != "$db_exact_id" ] &&
        [ "$app_exact_id" != "$migrate_exact_id" ] &&
        [ "$db_exact_id" != "$migrate_exact_id" ] ||
        die "Compose services do not resolve to three distinct containers"
    wait_for_healthy app "$app_container" ||
        die "application is not healthy before backup"
    wait_for_healthy db "$db_container" ||
        die "database is not healthy before backup"

    migrate_running=$(inspect_value "$migrate_container" '{{.State.Running}}') ||
        die "cannot inspect Compose service: migrate"
    migrate_exit_code=$(inspect_value "$migrate_container" '{{.State.ExitCode}}') ||
        die "cannot inspect migration exit status"
    [ "$migrate_running" = false ] && [ "$migrate_exit_code" = 0 ] ||
        die "migration container has not completed successfully"

    project_name=$(inspect_value "$app_container" \
        '{{ index .Config.Labels "com.docker.compose.project" }}') ||
        die "cannot determine Compose project name"
    safe_compose_project "$project_name"
    [ "$project_name" = "$confirmed_project" ] ||
        die "running services do not belong to the confirmed Compose project"
    for project_container in "$db_container" "$migrate_container"; do
        container_project=$(inspect_value "$project_container" \
            '{{ index .Config.Labels "com.docker.compose.project" }}') ||
            die "cannot verify Compose project identity"
        [ "$container_project" = "$project_name" ] ||
            die "services do not belong to one Compose project"
    done

    database_name=$("$container_cli" exec -i "$db_container" sh -ceu \
        'printf "%s\n" "$MARIADB_DATABASE"') ||
        die "cannot determine the running database name"
    case "$database_name" in
        ''|*[!A-Za-z0-9_]*)
            die "running database name is empty or unsafe"
            ;;
    esac

    storage_records=$(
        mount_record database "$db_container" /var/lib/mysql
        mount_record application "$app_container" /var/www/html/4fdata
        mount_record export "$app_container" /var/lib/estab/export
    )
    verify_storage_source_separation "$storage_records"
    verify_operator_path_separation "$canonical_parent" "$storage_records"
    image_records=$(
        image_record app "$app_container"
        image_record migrate "$migrate_container"
        image_record database "$db_container"
    )
    release_identity=$(
        printf '%s\n' "$image_records" |
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
    ) || die "cannot derive the canonical release identity"
    app_runtime_image=$(inspect_value "$app_container" '{{.Image}}') ||
        die "cannot bind backup helper to the verified app image"
    app_runtime_image_canonical=$(canonical_image_id "$app_runtime_image") ||
        die "cannot bind backup helper to a valid app image ID"
}

maintenance_lock_held=0
maintenance_lock_id=
maintenance_lock_name=
maintenance_lock_project=
maintenance_lock_token=
maintenance_lock_image=
publication_lock="${canonical_parent%/}/.estab-backup.lock"
publication_lock_held=0
publication_lock_owner=$publication_lock/owner.txt
publication_target_owner=$publication_lock/target-owner.txt
publication_target_token=
publication_target_reserved=0
publication_target_identity=
staging_prefix="${canonical_parent%/}/.${backup_name}.incomplete."
staging_dir=
staging_identity=
app_needs_restart=0
app_container=
app_exact_id=
db_exact_id=
migrate_exact_id=
confirmed_project=
cleanup()
{
    cleanup_status=$1
    trap - EXIT HUP INT TERM
    if [ "$app_needs_restart" -eq 1 ]; then
        if ! restart_application; then
            printf 'eStab backup: WARNING: application restart or health verification failed; recover it manually.\n' >&2
            [ "$cleanup_status" -ne 0 ] || cleanup_status=1
        fi
    fi
    if [ -n "$staging_dir" ] && [ -d "$staging_dir" ]; then
        case "$staging_dir" in
            "$staging_prefix"*)
                rm -rf -- "$staging_dir"
                ;;
            *)
                printf 'eStab backup: refusing to remove unexpected staging path: %s\n' \
                    "$staging_dir" >&2
                [ "$cleanup_status" -ne 0 ] || cleanup_status=1
            ;;
        esac
    fi
    if [ "$publication_target_reserved" -eq 1 ]; then
        target_cleanup_failed=0
        if [ ! -d "$backup_target" ] ||
            [ -L "$backup_target" ] ||
            [ -z "$publication_target_identity" ] ||
            [ "$(portable_path_identity "$backup_target" 2>/dev/null || :)" != \
                "$publication_target_identity" ] ||
            [ ! -f "$publication_target_owner" ] ||
            [ -L "$publication_target_owner" ] ||
            [ "$(sed -n '1p' "$publication_target_owner" 2>/dev/null || :)" != \
                "$publication_target_token" ]; then
            printf 'eStab backup: refusing to remove an unproven reserved target: %s\n' \
                "$backup_target" >&2
            target_cleanup_failed=1
        else
            while IFS= read -r target_cleanup_name; do
                target_cleanup_path=$backup_target/$target_cleanup_name
                if [ -e "$target_cleanup_path" ] ||
                    [ -L "$target_cleanup_path" ]; then
                    if [ -f "$target_cleanup_path" ] ||
                        [ -L "$target_cleanup_path" ]; then
                        rm -f -- "$target_cleanup_path" ||
                            target_cleanup_failed=1
                    else
                        printf 'eStab backup: refusing to remove unexpected reserved-target entry: %s\n' \
                            "$target_cleanup_path" >&2
                        target_cleanup_failed=1
                    fi
                fi
            done <<'EOF'
4fdata.tar.gz
SHA256SUMS
backup-created-utc.txt
backup-format.txt
database-name.txt
database.sql
export.tar.gz
image-references.txt
project-name.txt
release-identity.txt
storage-sources.txt
EOF
            if [ "$target_cleanup_failed" -eq 0 ]; then
                if rmdir -- "$backup_target"; then
                    rm -f -- "$publication_target_owner" ||
                        target_cleanup_failed=1
                else
                    printf 'eStab backup: reserved target is not empty; leaving it fail-closed: %s\n' \
                        "$backup_target" >&2
                    target_cleanup_failed=1
                fi
            fi
        fi
        if [ "$target_cleanup_failed" -ne 0 ]; then
            [ "$cleanup_status" -ne 0 ] || cleanup_status=1
        fi
        publication_target_reserved=0
    fi
    if [ "$publication_lock_held" -eq 1 ]; then
        if [ -d "$publication_lock" ] && [ ! -L "$publication_lock" ]; then
            if ! rm -f -- "$publication_lock_owner" ||
                ! rmdir -- "$publication_lock"; then
                printf 'eStab backup: WARNING: exclusive backup lock could not be released: %s\n' \
                    "$publication_lock" >&2
                [ "$cleanup_status" -ne 0 ] || cleanup_status=1
            fi
        else
            printf 'eStab backup: WARNING: exclusive backup lock changed unexpectedly: %s\n' \
                "$publication_lock" >&2
            [ "$cleanup_status" -ne 0 ] || cleanup_status=1
        fi
        publication_lock_held=0
    fi
    if [ "$maintenance_lock_held" -eq 1 ]; then
        if maintenance_lock_is_owned; then
            if ! "$container_cli" container rm --force \
                "$maintenance_lock_id" >/dev/null; then
                printf 'eStab backup: WARNING: owned maintenance lock container could not be removed: %s (%s)\n' \
                    "$maintenance_lock_name" "$maintenance_lock_id" >&2
                [ "$cleanup_status" -ne 0 ] || cleanup_status=1
            fi
        else
            printf 'eStab backup: WARNING: maintenance lock ownership changed; refusing removal by name: %s (owned id %s)\n' \
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

confirmed_project=$(compose_project_name) ||
    die "active Compose configuration has no unique safe top-level project name"
safe_compose_project "$confirmed_project"

if ! mkdir "$publication_lock"; then
    die "exclusive backup lock exists or cannot be created: $publication_lock (inspect owner.txt; remove a stale lock only after proving that no backup is running)"
fi
publication_lock_held=1
chmod 0700 "$publication_lock" ||
    die "cannot protect exclusive backup lock: $publication_lock"
{
    printf 'pid=%s\n' "$$"
    printf 'target=%s\n' "$backup_target"
    printf 'started_utc=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
} >"$publication_lock_owner" ||
    die "cannot record exclusive backup lock ownership"
chmod 0600 "$publication_lock_owner" ||
    die "cannot protect exclusive backup lock ownership"

if [ -e "$backup_target" ] || [ -L "$backup_target" ]; then
    die "backup target appeared before locked creation: $backup_target"
fi

inspect_backup_runtime
prelock_project_name=$project_name
prelock_app_runtime_image=$app_runtime_image_canonical
prelock_app_exact_id=$app_exact_id
prelock_db_exact_id=$db_exact_id
prelock_migrate_exact_id=$migrate_exact_id
acquire_maintenance_lock "$prelock_project_name" "$app_runtime_image"

# Container IDs, health, mounts, database identity, project labels, and image
# IDs are all re-read after the engine-global lock closes the acquisition race.
inspect_backup_runtime
verify_no_foreign_storage_consumers "$storage_records"
[ "$project_name" = "$prelock_project_name" ] ||
    die "Compose project changed while the maintenance lock was acquired"
[ "$app_runtime_image_canonical" = "$prelock_app_runtime_image" ] ||
    die "app runtime image changed while the maintenance lock was acquired"
[ "$app_exact_id" = "$prelock_app_exact_id" ] &&
    [ "$db_exact_id" = "$prelock_db_exact_id" ] &&
    [ "$migrate_exact_id" = "$prelock_migrate_exact_id" ] ||
    die "Compose container identities changed while the maintenance lock was acquired"
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before the application stop"
require_backup_parent_secure

staging_dir=$(mktemp -d "${staging_prefix}XXXXXX") ||
    die "cannot create private staging directory"
chmod 0700 "$staging_dir" ||
    die "cannot protect private backup staging"
staging_identity=$(portable_path_identity "$staging_dir") ||
    die "cannot bind private backup staging to its filesystem identity"
require_private_directory_unchanged "$staging_dir" "$staging_identity" \
    "backup staging"
printf '%s\n' 'estab-full-backup-v3' >"$staging_dir/backup-format.txt"
date -u '+%Y-%m-%dT%H:%M:%SZ' >"$staging_dir/backup-created-utc.txt"
printf '%s\n' "$project_name" >"$staging_dir/project-name.txt"
printf '%s\n' "$database_name" >"$staging_dir/database-name.txt"
printf '%s\n' "$storage_records" >"$staging_dir/storage-sources.txt"
printf '%s\n' "$image_records" >"$staging_dir/image-references.txt"
printf '%s\n' "$release_identity" >"$staging_dir/release-identity.txt"

app_needs_restart=1
"$container_cli" stop "$app_container" >/dev/null ||
    die "application could not be stopped for backup"
require_application_stopped

maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before the database dump"
verify_no_foreign_storage_consumers "$storage_records"
require_application_stopped

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
    mariadb-dump \
      --defaults-extra-file="$client_file" \
      --single-transaction \
      --quick \
      --skip-lock-tables \
      --routines \
      --events \
      --triggers \
      --hex-blob \
      --default-character-set=utf8mb4 \
      --add-drop-database \
      --databases "$MARIADB_DATABASE"' \
    >"$staging_dir/database.sql"

maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before application-data capture"
verify_no_foreign_storage_consumers "$storage_records"
require_application_stopped
"$container_cli" run --rm --network none \
    --volumes-from "${app_container}:z" \
    --entrypoint sh "$app_runtime_image" -ceu \
    'umask 077; tar -C /var/www/html/4fdata -czf - .' \
    >"$staging_dir/4fdata.tar.gz"
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before export-data capture"
verify_no_foreign_storage_consumers "$storage_records"
require_application_stopped
"$container_cli" run --rm --network none \
    --volumes-from "${app_container}:z" \
    --entrypoint sh "$app_runtime_image" -ceu \
    'umask 077; tar -C /var/lib/estab/export -czf - .' \
    >"$staging_dir/export.tar.gz"

restart_application ||
    die "application did not become healthy after backup"
app_needs_restart=0

require_private_directory_unchanged "$staging_dir" "$staging_identity" \
    "backup staging"
(
    cd "$staging_dir"
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
    elif command -v shasum >/dev/null 2>&1; then
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
    else
        die "sha256sum or shasum is required"
    fi
)

sh "$backup_verifier" "$staging_dir" "$database_name" >/dev/null
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before backup publication"
require_private_directory_unchanged "$staging_dir" "$staging_identity" \
    "backup staging"
require_backup_parent_secure
if [ -e "$backup_target" ] || [ -L "$backup_target" ]; then
    die "backup target appeared during creation; refusing publication"
fi

if ! "$mkdir_cli" "$backup_target"; then
    die "backup target appeared during creation; atomic no-clobber reservation failed"
fi
publication_target_reserved=1
if [ ! -d "$backup_target" ] || [ -L "$backup_target" ]; then
    die "reserved backup target is not a real directory"
fi
chmod 0700 "$backup_target" ||
    die "cannot protect the reserved backup target"
publication_target_identity=$(portable_path_identity "$backup_target") ||
    die "cannot bind the reserved backup target to its filesystem identity"
case "$publication_target_identity" in
    *:*:"$backup_operator_uid":700) ;;
    *) die "reserved backup target has unsafe ownership or mode" ;;
esac
publication_target_token="backup-target-$$-$(date -u '+%Y%m%dT%H%M%SZ')"
printf '%s\n' "$publication_target_token" >"$publication_target_owner" ||
    die "cannot record reserved backup-target ownership"
chmod 0600 "$publication_target_owner" ||
    die "cannot protect reserved backup-target ownership"
require_private_directory_unchanged "$backup_target" \
    "$publication_target_identity" "reserved backup target"

while IFS= read -r publication_name; do
    publication_source=$staging_dir/$publication_name
    publication_destination=$backup_target/$publication_name
    require_private_directory_unchanged "$staging_dir" "$staging_identity" \
        "backup staging"
    require_private_directory_unchanged "$backup_target" \
        "$publication_target_identity" "reserved backup target"
    if [ ! -d "$backup_target" ] ||
        [ -L "$backup_target" ] ||
        [ "$(portable_path_identity "$backup_target" 2>/dev/null || :)" != \
            "$publication_target_identity" ] ||
        [ ! -f "$publication_source" ] ||
        [ -L "$publication_source" ] ||
        [ -e "$publication_destination" ] ||
        [ -L "$publication_destination" ]; then
        die "verified staging changed during reserved publication: $publication_name"
    fi
    "$move_cli" "$publication_source" "$publication_destination" ||
        die "cannot publish verified backup entry: $publication_name"
    [ -d "$backup_target" ] &&
        [ ! -L "$backup_target" ] &&
        [ "$(portable_path_identity "$backup_target" 2>/dev/null || :)" = \
            "$publication_target_identity" ] ||
        die "reserved backup target changed during publication"
done <<'EOF'
4fdata.tar.gz
SHA256SUMS
backup-created-utc.txt
backup-format.txt
database-name.txt
database.sql
export.tar.gz
image-references.txt
project-name.txt
release-identity.txt
storage-sources.txt
EOF

rmdir -- "$staging_dir" ||
    die "verified staging directory was not empty after publication"
staging_dir=
staging_identity=
[ -d "$backup_target" ] &&
    [ ! -L "$backup_target" ] &&
    [ "$(portable_path_identity "$backup_target" 2>/dev/null || :)" = \
        "$publication_target_identity" ] ||
    die "reserved backup target changed before final verification"
if ! sh "$backup_verifier" "$backup_target" "$database_name" >/dev/null; then
    die "reserved no-clobber backup publication could not be proven"
fi
require_private_directory_unchanged "$backup_target" \
    "$publication_target_identity" "reserved backup target"
[ "$(portable_path_identity "$backup_target" 2>/dev/null || :)" = \
    "$publication_target_identity" ] ||
    die "reserved backup target changed after final verification"
rm -f -- "$publication_target_owner" ||
    die "cannot complete reserved backup-target ownership"
publication_target_reserved=0

printf 'eStab backup: complete and verified: %s\n' "$backup_target"
