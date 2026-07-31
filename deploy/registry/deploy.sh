#!/bin/sh

set -eu

die()
{
    printf 'eStab deployment: %s\n' "$*" >&2
    exit 1
}

usage()
{
    printf 'Usage: %s check|pull|up\n' "$0" >&2
    exit 64
}

[ "$#" -eq 1 ] || usage
action=$1
case "$action" in
    check|pull|up) ;;
    *) usage ;;
esac

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
cd "$script_dir"

if [ "${ESTAB_APP_IMAGE+x}" = x ] ||
    [ "${ESTAB_MIGRATE_IMAGE+x}" = x ] ||
    [ "${ESTAB_ADMIN_PASSWORD_SECRET_FILE+x}" = x ] ||
    [ "${ESTAB_ADMIN_USER+x}" = x ] ||
    [ "${ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF+x}" = x ] ||
    [ "${ESTAB_ALLOW_SELF_REGISTRATION+x}" = x ] ||
    [ "${ESTAB_APP_DATA_SOURCE+x}" = x ] ||
    [ "${ESTAB_AUTHORITY_CODE+x}" = x ] ||
    [ "${ESTAB_BASE_PATH+x}" = x ] ||
    [ "${ESTAB_DB_DATA_SOURCE+x}" = x ] ||
    [ "${ESTAB_DB_NAME+x}" = x ] ||
    [ "${ESTAB_DB_PASSWORD_SECRET_FILE+x}" = x ] ||
    [ "${ESTAB_DB_ROOT_PASSWORD_SECRET_FILE+x}" = x ] ||
    [ "${ESTAB_DB_USER+x}" = x ] ||
    [ "${ESTAB_EXPORT_DATA_SOURCE+x}" = x ] ||
    [ "${ESTAB_HTTP_BIND+x}" = x ] ||
    [ "${ESTAB_HTTP_PORT+x}" = x ] ||
    [ "${ESTAB_PDF_ATTACHMENT_MAX_BYTES+x}" = x ] ||
    [ "${ESTAB_PUBLIC_URL+x}" = x ] ||
    [ "${ESTAB_TRUSTED_PROXIES+x}" = x ] ||
    [ "${ESTAB_TRUST_PROXY_HEADERS+x}" = x ] ||
    [ "${ESTAB_UPLOAD_MAX_BYTES+x}" = x ] ||
    [ "${TZ+x}" = x ]; then
    die "Compose runtime overrides must not be set in the process environment; place supported settings in the verified .env"
fi
if env | LC_ALL=C awk -F= '
  $1 ~ /^COMPOSE_[A-Za-z0-9_]*$/ {
    found = 1
  }
  END {
    exit(found ? 0 : 1)
  }
'; then
    die "COMPOSE_* process overrides are forbidden; the verified .env and explicit Compose arguments are the only deployment configuration"
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
    docker|podman) ;;
    *) die "ESTAB_CONTAINER_CLI must be docker or podman" ;;
esac
command -v "$container_cli" >/dev/null 2>&1 ||
    die "container executable is unavailable: $container_cli"
"$container_cli" compose version >/dev/null 2>&1 ||
    die "Compose is unavailable through $container_cli"

for forbidden_override in \
    compose.override.yaml \
    compose.override.yml \
    docker-compose.yaml \
    docker-compose.yml
do
    [ ! -e "$script_dir/$forbidden_override" ] &&
        [ ! -L "$script_dir/$forbidden_override" ] ||
        die "unverified automatic Compose override is forbidden: $forbidden_override"
done

active_compose_environment=$script_dir/.env
active_compose_file=$script_dir/compose.yaml

compose()
{
    "$container_cli" compose \
        --env-file "$active_compose_environment" \
        -f "$active_compose_file" \
        "$@"
}

engine_project_service_ids()
{
    engine_project=$1
    engine_service=$2
    engine_raw_ids=$("$container_cli" ps \
        --all \
        --no-trunc \
        --filter "label=com.docker.compose.project=$engine_project" \
        --filter "label=com.docker.compose.service=$engine_service" \
        --format '{{.ID}}') ||
        return 1
    while IFS= read -r engine_provider_id; do
        [ -n "$engine_provider_id" ] || continue
        case "$engine_provider_id" in
            *' '*|*'	'*|*'
'*) return 1 ;;
        esac
        engine_exact_id=$("$container_cli" inspect --format '{{.Id}}' \
            "$engine_provider_id") ||
            return 1
        case "$engine_exact_id" in
            *[!0123456789abcdef]*|'') return 1 ;;
        esac
        [ "${#engine_exact_id}" -eq 64 ] || return 1
        engine_actual_project=$("$container_cli" inspect --format \
            '{{ index .Config.Labels "com.docker.compose.project" }}' \
            "$engine_exact_id") ||
            return 1
        engine_actual_service=$("$container_cli" inspect --format \
            '{{ index .Config.Labels "com.docker.compose.service" }}' \
            "$engine_exact_id") ||
            return 1
        [ "$engine_actual_project" = "$engine_project" ] &&
            [ "$engine_actual_service" = "$engine_service" ] ||
            return 1
        printf '%s\n' "$engine_exact_id"
    done <<EOF
$engine_raw_ids
EOF
}

engine_project_service_id()
{
    unique_project=$1
    unique_service=$2
    unique_ids=$(engine_project_service_ids \
        "$unique_project" "$unique_service") ||
        return 1
    printf '%s\n' "$unique_ids" |
        LC_ALL=C awk '
          NF != 1 { invalid = 1 }
          NF == 1 { count++; value = $1 }
          END {
            if (invalid || count != 1) exit 1
            print value
          }
        '
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

verified_environment_value()
{
    verified_environment_name=$1
    LC_ALL=C awk -v name="$verified_environment_name" '
      index($0, name "=") == 1 {
        matches++
        value = substr($0, length(name) + 2)
      }
      END {
        if (matches != 1 || value == "" || index(value, "\r") != 0) {
          exit 1
        }
        print value
      }
    ' "$script_dir/.env"
}

environment_value_allow_empty()
{
    inspected_environment_name=$1
    LC_ALL=C awk -v name="$inspected_environment_name" '
      index($0, name "=") == 1 {
        matches++
        value = substr($0, length(name) + 2)
      }
      END {
        if (matches != 1 || index(value, "\r") != 0) {
          exit 1
        }
        print value
      }
    ' "$script_dir/.env"
}

collect_environment_records()
{
    LC_ALL=C awk '
      BEGIN {
        split("ESTAB_APP_IMAGE ESTAB_MIGRATE_IMAGE COMPOSE_PROJECT_NAME ESTAB_DB_PASSWORD_SECRET_FILE ESTAB_DB_ROOT_PASSWORD_SECRET_FILE ESTAB_ADMIN_PASSWORD_SECRET_FILE ESTAB_DB_DATA_SOURCE ESTAB_APP_DATA_SOURCE ESTAB_EXPORT_DATA_SOURCE ESTAB_DB_NAME ESTAB_DB_USER ESTAB_ADMIN_USER ESTAB_HTTP_BIND ESTAB_HTTP_PORT ESTAB_PUBLIC_URL ESTAB_BASE_PATH ESTAB_AUTHORITY_CODE ESTAB_ALLOW_SELF_REGISTRATION ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF ESTAB_TRUST_PROXY_HEADERS ESTAB_TRUSTED_PROXIES ESTAB_UPLOAD_MAX_BYTES ESTAB_PDF_ATTACHMENT_MAX_BYTES TZ", names, " ")
        for (position in names) {
          allowed[names[position]] = 1
        }
      }
      /^[[:space:]]*($|#)/ {
        next
      }
      {
        separator = index($0, "=")
        name = substr($0, 1, separator - 1)
        if (separator < 2 || !(name in allowed) || seen[name]++) {
          exit 1
        }
        assignments++
      }
      END {
        if (assignments != 24) {
          exit 1
        }
      }
    ' "$script_dir/.env" ||
        die ".env must contain exactly the 24 supported canonical assignments and no unknown setting"

    for environment_name in \
        ESTAB_APP_IMAGE \
        ESTAB_MIGRATE_IMAGE \
        COMPOSE_PROJECT_NAME \
        ESTAB_DB_PASSWORD_SECRET_FILE \
        ESTAB_DB_ROOT_PASSWORD_SECRET_FILE \
        ESTAB_ADMIN_PASSWORD_SECRET_FILE \
        ESTAB_DB_DATA_SOURCE \
        ESTAB_APP_DATA_SOURCE \
        ESTAB_EXPORT_DATA_SOURCE \
        ESTAB_DB_NAME \
        ESTAB_DB_USER \
        ESTAB_ADMIN_USER \
        ESTAB_HTTP_BIND \
        ESTAB_HTTP_PORT \
        ESTAB_PUBLIC_URL \
        ESTAB_BASE_PATH \
        ESTAB_AUTHORITY_CODE \
        ESTAB_ALLOW_SELF_REGISTRATION \
        ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF \
        ESTAB_TRUST_PROXY_HEADERS \
        ESTAB_TRUSTED_PROXIES \
        ESTAB_UPLOAD_MAX_BYTES \
        ESTAB_PDF_ATTACHMENT_MAX_BYTES \
        TZ
    do
        environment_value=$(environment_value_allow_empty \
            "$environment_name") ||
            die ".env assignment is missing or ambiguous: $environment_name"
        case "$environment_name" in
            ESTAB_BASE_PATH|ESTAB_TRUSTED_PROXIES) ;;
            *)
                [ -n "$environment_value" ] ||
                    die ".env assignment must not be empty: $environment_name"
                ;;
        esac
        case "$environment_value" in
            *' '*|*'	'*|*'
'*|*'$'*|*'#'*|*'"'*|*"'"*|*'\'*)
                die ".env value contains whitespace or Compose-sensitive syntax: $environment_name"
                ;;
        esac
        printf '%s\t%s\n' "$environment_name" "$environment_value"
    done
}

environment_record_value()
{
    environment_records=$1
    requested_environment_name=$2
    printf '%s\n' "$environment_records" |
        LC_ALL=C awk -F '	' -v name="$requested_environment_name" '
          $1 == name {
            matches++
            value = substr($0, length($1) + 2)
          }
          END {
            if (matches != 1) {
              exit 1
            }
            print value
          }
        '
}

sha256_file()
{
    sha256_path=$1
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$sha256_path" | LC_ALL=C awk '
          NR == 1 && $1 ~ /^[0-9a-f]{64}$/ {
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
        shasum -a 256 "$sha256_path" | LC_ALL=C awk '
          NR == 1 && $1 ~ /^[0-9a-f]{64}$/ {
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

sha256_stdin()
{
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum | LC_ALL=C awk '
          NR == 1 && $1 ~ /^[0-9a-f]{64}$/ {
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
        shasum -a 256 | LC_ALL=C awk '
          NR == 1 && $1 ~ /^[0-9a-f]{64}$/ {
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
    portable_stat_path=$1
    portable_stat_result=
    if portable_stat_result=$(stat -c '%u' "$portable_stat_path" \
        2>/dev/null); then
        :
    elif portable_stat_result=$(stat -f '%u' "$portable_stat_path" \
        2>/dev/null); then
        :
    else
        return 1
    fi
    case "$portable_stat_result" in
        ''|*[!0-9]*) return 1 ;;
    esac
    printf '%s\n' "$portable_stat_result"
}

portable_stat_mode()
{
    portable_stat_path=$1
    portable_stat_result=
    if portable_stat_result=$(stat -c '%a' "$portable_stat_path" \
        2>/dev/null); then
        :
    elif portable_stat_result=$(stat -f '%Lp' "$portable_stat_path" \
        2>/dev/null); then
        :
    else
        return 1
    fi
    case "$portable_stat_result" in
        ''|*[!0-7]*) return 1 ;;
    esac
    printf '%s\n' "$portable_stat_result"
}

verify_no_extended_acl()
{
    acl_path=$1
    acl_label=$2
    acl_expected_owner=${3:-file}
    case "$acl_expected_owner" in
        file)
            acl_expected_owner_expression='r--|rw-'
            acl_expected_group_expression='---'
            ;;
        directory)
            acl_expected_owner_expression='rwx'
            acl_expected_group_expression='---'
            ;;
        productive-directory)
            acl_expected_owner_expression='rwx'
            acl_expected_group_expression='---|rwx'
            ;;
        *) die "internal ACL owner-permission contract is invalid" ;;
    esac

    if command -v synoacltool >/dev/null 2>&1; then
        synology_acl_status=0
        synology_acl_output=$(LC_ALL=C synoacltool -get "$acl_path" \
            2>&1) || synology_acl_status=$?
        case "$synology_acl_output" in
            *"It's Linux mode"*|*"is Linux mode"*)
                # DSM's native probe has established that this path is in
                # numeric Linux-mode operation. Ownership and numeric modes
                # are checked separately by every caller. Stock DSM does not
                # necessarily ship GNU ls or getfacl, so this is the terminal
                # ACL proof for that platform.
                return
                ;;
            *)
                if [ "$synology_acl_status" -eq 0 ]; then
                    die "$acl_label has a Synology DSM ACL; only the numeric owner mode is allowed"
                fi
                die "cannot prove absence of a Synology DSM ACL for $acl_label"
                ;;
        esac
    fi

    acl_system=$(uname -s 2>/dev/null) ||
        die "cannot identify the ACL inspection platform for $acl_label"

    # BSD getfacl variants do not implement the GNU -c/-p/-- interface used
    # below. Their native ls exposes the ACL marker and entries reliably, so
    # only Linux uses the POSIX getfacl parser.
    if [ "$acl_system" = Linux ] &&
        command -v getfacl >/dev/null 2>&1; then
        acl_listing=$(LC_ALL=C getfacl -cp -- "$acl_path" 2>/dev/null) ||
            die "cannot inspect POSIX ACLs for $acl_label"
        printf '%s\n' "$acl_listing" |
            LC_ALL=C awk \
                -v owner_expression="$acl_expected_owner_expression" \
                -v group_expression="$acl_expected_group_expression" '
              /^$/ {
                next
              }
              $0 ~ ("^user::(" owner_expression ")$") {
                owner++
                next
              }
              $0 ~ ("^group::(" group_expression ")$") {
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
                *+*)
                    die "$acl_label has an extended macOS ACL"
                    ;;
            esac
            case "$acl_expected_owner" in
                file)
                    case "$acl_mode" in
                        -?????????|-?????????@) ;;
                        *)
                            die "macOS ACL probe returned an unknown file-mode marker for $acl_label"
                            ;;
                    esac
                    ;;
                directory|productive-directory)
                    case "$acl_mode" in
                        d?????????|d?????????@) ;;
                        *)
                            die "macOS ACL probe returned an unknown directory-mode marker for $acl_label"
                            ;;
                    esac
                    ;;
            esac
            [ "$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'END { print NR + 0 }')" -eq 1 ] ||
                die "$acl_label has extended macOS ACL entries"
            return
            ;;
        FreeBSD|OpenBSD|NetBSD)
            # Unlike macOS, these ls implementations do not share the -e
            # option. Their long listing exposes an ACL through the mode
            # marker; accept only the plain ten-character file type/mode.
            acl_listing=$(LC_ALL=C ls -ld "$acl_path" 2>/dev/null) ||
                die "cannot inspect BSD ACL metadata for $acl_label"
            acl_mode=$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'NR == 1 { print $1 }') ||
                die "cannot parse BSD ACL metadata for $acl_label"
            case "$acl_expected_owner:$acl_mode" in
                file:-?????????|directory:d?????????|productive-directory:d?????????) ;;
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
                die "no trusted ACL probe (getfacl, GNU ls, or Synology ACL tool) is available for $acl_label"
            fi
            acl_listing=$(LC_ALL=C ls -ld -- "$acl_path" 2>/dev/null) ||
                die "cannot inspect GNU ACL metadata for $acl_label"
            acl_mode=$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'NR == 1 { print $1 }') ||
                die "cannot parse GNU ACL metadata for $acl_label"
            case "$acl_mode" in
                *+*)
                    die "$acl_label has an extended Linux ACL"
                    ;;
            esac
            case "$acl_expected_owner" in
                file)
                    case "$acl_mode" in
                        -?????????|-?????????.) ;;
                        *)
                            die "GNU ACL probe returned an unknown file-mode marker for $acl_label"
                            ;;
                    esac
                    ;;
                directory|productive-directory)
                    case "$acl_mode" in
                        d?????????|d?????????.) ;;
                        *)
                            die "GNU ACL probe returned an unknown directory-mode marker for $acl_label"
                            ;;
                    esac
                    ;;
            esac
            return
            ;;
        *)
            die "no trusted ACL probe is implemented for $acl_label on $acl_system"
            ;;
    esac
}

verify_secret_configuration()
{
    secret_operator_uid=$(id -u) ||
        die "cannot determine the deployment operator UID"
    case "$secret_operator_uid" in
        ''|*[!0-9]*)
            die "deployment operator UID is invalid"
            ;;
    esac
    secret_sudo_uid=
    if [ "$secret_operator_uid" -eq 0 ]; then
        case "${SUDO_UID:-}" in
            ''|*[!0-9]*) ;;
            *) secret_sudo_uid=$SUDO_UID ;;
        esac
    fi

    for secret_environment_name in \
        ESTAB_DB_PASSWORD_SECRET_FILE \
        ESTAB_DB_ROOT_PASSWORD_SECRET_FILE \
        ESTAB_ADMIN_PASSWORD_SECRET_FILE
    do
        secret_source=$(verified_environment_value \
            "$secret_environment_name") ||
            die "cannot read verified local source for $secret_environment_name"
        case "$secret_source" in
            *'	'*|*'
'*)
                die "local secret source contains an unsupported character: $secret_environment_name"
                ;;
            /*) secret_path=$secret_source ;;
            *) secret_path=$script_dir/$secret_source ;;
        esac

        if [ -L "$secret_path" ] ||
            [ ! -f "$secret_path" ] ||
            [ ! -r "$secret_path" ]; then
            die "local secret source must be a readable regular, non-symlink file: $secret_environment_name"
        fi
        secret_parent=$(dirname -- "$secret_path") ||
            die "cannot resolve local secret parent: $secret_environment_name"
        secret_basename=$(basename -- "$secret_path") ||
            die "cannot resolve local secret basename: $secret_environment_name"
        secret_parent=$(CDPATH= cd -- "$secret_parent" && pwd -P) ||
            die "cannot canonicalize local secret parent: $secret_environment_name"
        secret_path=$secret_parent/$secret_basename
        case "$secret_path" in
            *'	'*|*'
'*)
                die "canonical local secret source contains an unsupported character: $secret_environment_name"
                ;;
        esac

        secret_owner_uid=$(portable_stat_owner_uid "$secret_path") ||
            die "cannot determine local secret owner with GNU, BSD, or BusyBox stat: $secret_environment_name"
        if [ "$secret_owner_uid" -ne 0 ] &&
            [ "$secret_owner_uid" -ne "$secret_operator_uid" ] &&
            { [ -z "$secret_sudo_uid" ] ||
                [ "$secret_owner_uid" -ne "$secret_sudo_uid" ]; }; then
            die "local secret source owner is neither root nor the deployment operator: $secret_environment_name"
        fi
        secret_mode=$(portable_stat_mode "$secret_path") ||
            die "cannot determine local secret mode with GNU, BSD, or BusyBox stat: $secret_environment_name"
        secret_mode_value=$((0$secret_mode))
        if [ $((secret_mode_value & 077)) -ne 0 ]; then
            die "local secret source grants group or world permissions: $secret_environment_name"
        fi
        if [ $((secret_mode_value & 0400)) -eq 0 ] ||
            [ $((secret_mode_value & 0111)) -ne 0 ]; then
            die "local secret source must be owner-readable and non-executable: $secret_environment_name"
        fi
        verify_no_extended_acl "$secret_path" \
            "local secret source $secret_environment_name"
        secret_digest=$(sha256_file "$secret_path") ||
            die "cannot hash local secret source: $secret_environment_name"

        if [ -L "$secret_path" ] ||
            [ ! -f "$secret_path" ] ||
            [ ! -r "$secret_path" ] ||
            [ "$(portable_stat_owner_uid "$secret_path")" != "$secret_owner_uid" ] ||
            [ "$(portable_stat_mode "$secret_path")" != "$secret_mode" ] ||
            [ "$(sha256_file "$secret_path")" != "$secret_digest" ]; then
            die "local secret source changed while it was inspected: $secret_environment_name"
        fi
        verify_no_extended_acl "$secret_path" \
            "local secret source $secret_environment_name"

        printf '%s\t%s\t%s\t%s\t%s\n' \
            "$secret_environment_name" \
            "$secret_path" \
            "$secret_owner_uid" \
            "$secret_mode" \
            "$secret_digest"
    done
}

canonical_storage_source()
{
    storage_role=$1
    storage_value=$2
    storage_named_default=$3

    if [ "$storage_value" = "$storage_named_default" ]; then
        printf '%s\tvolume\t%s\n' "$storage_role" "$storage_value"
        return
    fi

    case "$storage_value" in
        /*|./*|../*) ;;
        *)
            printf 'eStab deployment: %s storage must use its dedicated named volume %s or an explicit absolute/relative host directory: %s\n' \
                "$storage_role" "$storage_named_default" "$storage_value" >&2
            return 1
            ;;
    esac
    case "$storage_value" in
        *':'*|*'	'*|*'
'*)
            printf 'eStab deployment: %s host storage path contains an unsupported character.\n' \
                "$storage_role" >&2
            return 1
            ;;
    esac
    if [ ! -d "$storage_value" ] || [ -L "$storage_value" ]; then
        printf 'eStab deployment: %s host storage path must already be a real, non-symlink directory: %s\n' \
            "$storage_role" "$storage_value" >&2
        return 1
    fi
    storage_canonical=$(CDPATH= cd -- "$storage_value" && pwd -P) ||
        return 1
    case "$storage_canonical" in
        *' '*|*'	'*|*'
'*|*'$'*|*'#'*|*'"'*|*"'"*|*'\'*)
            printf 'eStab deployment: canonical %s host storage path contains unsupported Compose syntax: %s\n' \
                "$storage_role" "$storage_canonical" >&2
            return 1
            ;;
    esac
    [ "$storage_canonical" != / ] || {
        printf 'eStab deployment: filesystem root is forbidden as productive %s storage.\n' \
            "$storage_role" >&2
        return 1
    }
    case "$storage_canonical" in
        /*/*) ;;
        *)
            printf 'eStab deployment: broad top-level host directory is forbidden as productive %s storage: %s\n' \
                "$storage_role" "$storage_canonical" >&2
            return 1
            ;;
    esac
    case "$script_dir" in
        "$storage_canonical"|"$storage_canonical"/*)
            printf 'eStab deployment: productive %s storage must not be the release directory or one of its ancestors: %s\n' \
                "$storage_role" "$storage_canonical" >&2
            return 1
            ;;
    esac
    storage_mode=$(portable_stat_mode "$storage_canonical") ||
        return 1
    case "$storage_mode" in
        700|770) ;;
        *)
            printf 'eStab deployment: productive %s bind storage must be mode 0700 before first start or 0770 after application initialization: %s\n' \
                "$storage_role" "$storage_canonical" >&2
            return 1
            ;;
    esac
    verify_no_extended_acl "$storage_canonical" \
        "productive $storage_role bind storage" \
        productive-directory
    printf '%s\tbind\t%s\n' "$storage_role" "$storage_canonical"
}

verify_storage_configuration()
{
    storage_db=$(verified_environment_value ESTAB_DB_DATA_SOURCE) ||
        die "cannot read verified database storage source"
    storage_app=$(verified_environment_value ESTAB_APP_DATA_SOURCE) ||
        die "cannot read verified application storage source"
    storage_export=$(verified_environment_value ESTAB_EXPORT_DATA_SOURCE) ||
        die "cannot read verified export storage source"

    storage_records=$(
        canonical_storage_source database "$storage_db" estab_db || exit 1
        canonical_storage_source application "$storage_app" estab_data ||
            exit 1
        canonical_storage_source export "$storage_export" estab_export ||
            exit 1
    ) || exit 1

    printf '%s\n' "$storage_records" |
        LC_ALL=C awk -F '	' '
          function overlaps(first, second) {
            return first == second ||
              first == "/" || second == "/" ||
              index(first, second "/") == 1 ||
              index(second, first "/") == 1
          }
          {
            if (NF != 3 ||
                ($2 != "volume" && $2 != "bind") ||
                $3 == "") {
              exit 1
            }
            types[++count] = $2
            sources[count] = $3
          }
          END {
            if (count != 3) {
              exit 1
            }
            for (left = 1; left <= count; left++) {
              for (right = left + 1; right <= count; right++) {
                if (types[left] == types[right] &&
                    (sources[left] == sources[right] ||
                     (types[left] == "bind" &&
                      overlaps(sources[left], sources[right])))) {
                  exit 1
                }
              }
            }
          }
        ' ||
        die "productive storage sources are equal, nested, overlapping, or unsafe"
    printf '%s\n' "$storage_records"
}

verify_compose_capabilities()
{
    compose config >/dev/null ||
        die "Compose cannot render the verified configuration"
    "$container_cli" ps \
        --all \
        --no-trunc \
        --filter label=com.docker.compose.project=estab-capability-probe \
        --format '{{.ID}}' >/dev/null ||
        die "container engine does not support the required non-mutating labelled inventory query"
}

storage_record_field()
{
    storage_field_records=$1
    storage_field_role=$2
    storage_field_number=$3
    printf '%s\n' "$storage_field_records" |
        LC_ALL=C awk -F '	' \
            -v role="$storage_field_role" \
            -v field="$storage_field_number" '
              $1 == role {
                matches++
                value = $field
              }
              END {
                if (matches != 1 || value == "") {
                  exit 1
                }
                print value
              }
            '
}

secret_record_field()
{
    secret_field_records=$1
    secret_field_name=$2
    secret_field_number=$3
    printf '%s\n' "$secret_field_records" |
        LC_ALL=C awk -F '	' \
            -v name="$secret_field_name" \
            -v field="$secret_field_number" '
              $1 == name {
                matches++
                value = $field
              }
              END {
                if (matches != 1 || value == "") {
                  exit 1
                }
                print value
              }
            '
}

bound_release_digest()
{
    requested_bound_name=$1
    LC_ALL=C awk -v name="$requested_bound_name" '
      substr($0, 67) == name {
        matches++
        digest = substr($0, 1, 64)
      }
      END {
        if (matches != 1 || digest !~ /^[0-9a-f]{64}$/) {
          exit 1
        }
        print digest
      }
    ' "$script_dir/SHA256SUMS"
}

ensure_private_directory()
{
    private_directory=$1
    private_directory_label=$2
    if [ -L "$private_directory" ] ||
        { [ -e "$private_directory" ] && [ ! -d "$private_directory" ]; }; then
        die "$private_directory_label must be a real, non-symlink directory"
    fi
    if [ ! -d "$private_directory" ]; then
        mkdir -p -m 0700 "$private_directory" ||
            die "cannot create $private_directory_label"
    fi
    chmod 0700 "$private_directory" ||
        die "cannot protect $private_directory_label"
    if [ -L "$private_directory" ] ||
        [ ! -d "$private_directory" ]; then
        die "$private_directory_label changed while it was prepared"
    fi
    private_directory_owner=$(portable_stat_owner_uid "$private_directory") ||
        die "cannot inspect owner of $private_directory_label"
    private_directory_mode=$(portable_stat_mode "$private_directory") ||
        die "cannot inspect mode of $private_directory_label"
    [ "$private_directory_owner" -eq "$(id -u)" ] ||
        die "$private_directory_label is not owned by the deployment operator"
    [ "$private_directory_mode" = 700 ] ||
        die "$private_directory_label is not mode 0700"
    verify_no_extended_acl "$private_directory" "$private_directory_label" \
        directory
}

runtime_state_base()
{
    state_operator_uid=$(id -u) ||
        die "cannot determine deployment UID for private runtime state"
    if [ "$state_operator_uid" -eq 0 ]; then
        state_base=/var/lib/estab-deploy
    else
        state_parent=${XDG_STATE_HOME:-}
        if [ -z "$state_parent" ]; then
            case "${HOME:-}" in
                /*) state_parent=$HOME/.local/state ;;
                *) die "HOME must be absolute when XDG_STATE_HOME is unset" ;;
            esac
        fi
        case "$state_parent" in
            /*) ;;
            *) die "XDG_STATE_HOME must be an absolute path" ;;
        esac
        if [ -L "$state_parent" ] ||
            { [ -e "$state_parent" ] && [ ! -d "$state_parent" ]; }; then
            die "XDG state parent must be a real directory"
        fi
        [ -d "$state_parent" ] || mkdir -p -m 0700 "$state_parent" ||
            die "cannot create XDG state parent"
        state_parent=$(CDPATH= cd -- "$state_parent" && pwd -P) ||
            die "cannot canonicalize XDG state parent"
        state_base=$state_parent/estab-deploy
    fi
    case "$state_base" in
        *' '*|*'	'*|*'
'*|*'$'*|*'#'*|*'"'*|*"'"*|*'\'*)
            die "private runtime-state path contains unsupported Compose syntax"
            ;;
    esac
    printf '%s\n' "$state_base"
}

emit_snapshot_environment()
{
    emitted_environment_records=$1
    emitted_storage_records=$2
    emitted_snapshot_directory=$3
    while IFS='	' read -r emitted_environment_name \
        emitted_environment_value
    do
        case "$emitted_environment_name" in
            ESTAB_DB_PASSWORD_SECRET_FILE)
                emitted_environment_value=$emitted_snapshot_directory/secrets/db_password.txt
                ;;
            ESTAB_DB_ROOT_PASSWORD_SECRET_FILE)
                emitted_environment_value=$emitted_snapshot_directory/secrets/db_root_password.txt
                ;;
            ESTAB_ADMIN_PASSWORD_SECRET_FILE)
                emitted_environment_value=$emitted_snapshot_directory/secrets/admin_password.txt
                ;;
            ESTAB_DB_DATA_SOURCE)
                emitted_environment_value=$(storage_record_field \
                    "$emitted_storage_records" database 3)
                ;;
            ESTAB_APP_DATA_SOURCE)
                emitted_environment_value=$(storage_record_field \
                    "$emitted_storage_records" application 3)
                ;;
            ESTAB_EXPORT_DATA_SOURCE)
                emitted_environment_value=$(storage_record_field \
                    "$emitted_storage_records" export 3)
                ;;
        esac
        printf '%s=%s\n' "$emitted_environment_name" \
            "$emitted_environment_value"
    done <<EOF
$emitted_environment_records
EOF
}

emit_snapshot_identity_material()
{
    identity_environment_records=$1
    identity_storage_records=$2
    identity_secret_records=$3
    identity_compose_digest=$4

    printf 'compose.yaml\t%s\n' "$identity_compose_digest"
    while IFS='	' read -r identity_name identity_value
    do
        case "$identity_name" in
            ESTAB_DB_PASSWORD_SECRET_FILE|ESTAB_DB_ROOT_PASSWORD_SECRET_FILE|ESTAB_ADMIN_PASSWORD_SECRET_FILE)
                identity_value=$(secret_record_field \
                    "$identity_secret_records" "$identity_name" 5)
                ;;
            ESTAB_DB_DATA_SOURCE)
                identity_value=$(storage_record_field \
                    "$identity_storage_records" database 3)
                ;;
            ESTAB_APP_DATA_SOURCE)
                identity_value=$(storage_record_field \
                    "$identity_storage_records" application 3)
                ;;
            ESTAB_EXPORT_DATA_SOURCE)
                identity_value=$(storage_record_field \
                    "$identity_storage_records" export 3)
                ;;
        esac
        printf '%s\t%s\n' "$identity_name" "$identity_value"
    done <<EOF
$identity_environment_records
EOF
}

create_runtime_snapshot()
{
    snapshot_environment_records=$1
    snapshot_storage_records=$2
    snapshot_secret_records=$3
    snapshot_project=$(environment_record_value \
        "$snapshot_environment_records" COMPOSE_PROJECT_NAME) ||
        die "cannot read snapshot Compose project"
    expected_compose_digest=$(bound_release_digest compose.yaml) ||
        die "cannot read the bound Compose digest"

    snapshot_identity=$(
        emit_snapshot_identity_material "$snapshot_environment_records" \
            "$snapshot_storage_records" "$snapshot_secret_records" \
            "$expected_compose_digest" |
            sha256_stdin
    ) || die "cannot derive deterministic private snapshot identity"

    snapshot_state_base=$(runtime_state_base)
    ensure_private_directory "$snapshot_state_base" \
        "private deployment state root"
    snapshot_project_root=$snapshot_state_base/$snapshot_project
    snapshot_parent=$snapshot_project_root/snapshots
    ensure_private_directory "$snapshot_project_root" \
        "private project deployment state"
    ensure_private_directory "$snapshot_parent" \
        "private deployment snapshot parent"

    runtime_snapshot_directory=$snapshot_parent/snapshot-$snapshot_identity
    runtime_snapshot_created=0
    if [ ! -e "$runtime_snapshot_directory" ] &&
        [ ! -L "$runtime_snapshot_directory" ]; then
        if mkdir -m 0700 "$runtime_snapshot_directory"; then
            runtime_snapshot_created=1
        elif [ ! -d "$runtime_snapshot_directory" ] ||
            [ -L "$runtime_snapshot_directory" ]; then
            die "cannot reserve deterministic private deployment snapshot"
        fi
    fi
    ensure_private_directory "$runtime_snapshot_directory" \
        "private deployment snapshot"
    if [ "$runtime_snapshot_created" -eq 1 ]; then
        mkdir -m 0700 "$runtime_snapshot_directory/secrets" ||
            die "cannot create private snapshot secret directory"
    fi
    ensure_private_directory "$runtime_snapshot_directory/secrets" \
        "private snapshot secret directory"

    expected_snapshot_environment_digest=$(
        emit_snapshot_environment \
            "$snapshot_environment_records" \
            "$snapshot_storage_records" \
            "$runtime_snapshot_directory" |
            sha256_stdin
    ) || die "cannot derive canonical private Compose environment digest"

    if [ "$runtime_snapshot_created" -eq 1 ]; then
        cp "$script_dir/compose.yaml" \
            "$runtime_snapshot_directory/compose.yaml" ||
            die "cannot copy the verified Compose file into private state"
        (
            umask 077
            emit_snapshot_environment \
                "$snapshot_environment_records" \
                "$snapshot_storage_records" \
                "$runtime_snapshot_directory"
        ) >"$runtime_snapshot_directory/.env" ||
            die "cannot create canonical private Compose environment"
        {
            printf 'identity=%s\n' "$snapshot_identity"
            printf 'project=%s\n' "$snapshot_project"
        } >"$runtime_snapshot_directory/SNAPSHOT" ||
            die "cannot record private deployment snapshot identity"

        for snapshot_secret_name in \
            ESTAB_DB_PASSWORD_SECRET_FILE \
            ESTAB_DB_ROOT_PASSWORD_SECRET_FILE \
            ESTAB_ADMIN_PASSWORD_SECRET_FILE
        do
            snapshot_secret_source=$(secret_record_field \
                "$snapshot_secret_records" "$snapshot_secret_name" 2) ||
                die "cannot read verified source for $snapshot_secret_name"
            case "$snapshot_secret_name" in
                ESTAB_DB_PASSWORD_SECRET_FILE)
                    snapshot_secret_file=db_password.txt
                    ;;
                ESTAB_DB_ROOT_PASSWORD_SECRET_FILE)
                    snapshot_secret_file=db_root_password.txt
                    ;;
                ESTAB_ADMIN_PASSWORD_SECRET_FILE)
                    snapshot_secret_file=admin_password.txt
                    ;;
                *) die "internal snapshot secret mapping is invalid" ;;
            esac
            snapshot_secret_target=$runtime_snapshot_directory/secrets/$snapshot_secret_file
            (
                umask 077
                cp "$snapshot_secret_source" "$snapshot_secret_target"
            ) || die "cannot copy $snapshot_secret_name into private state"
        done
        chmod 0400 \
            "$runtime_snapshot_directory/compose.yaml" \
            "$runtime_snapshot_directory/.env" \
            "$runtime_snapshot_directory/SNAPSHOT" \
            "$runtime_snapshot_directory/secrets/db_password.txt" \
            "$runtime_snapshot_directory/secrets/db_root_password.txt" \
            "$runtime_snapshot_directory/secrets/admin_password.txt" ||
            die "cannot protect private deployment snapshot files"
    fi

    [ "$(sha256_file "$runtime_snapshot_directory/compose.yaml")" = \
        "$expected_compose_digest" ] ||
        die "private Compose snapshot differs from the verified release"
    [ "$(sha256_file "$runtime_snapshot_directory/.env")" = \
        "$expected_snapshot_environment_digest" ] ||
        die "private Compose environment differs from canonical configuration"
    [ "$(sed -n '1p' "$runtime_snapshot_directory/SNAPSHOT")" = \
        "identity=$snapshot_identity" ] &&
    [ "$(sed -n '2p' "$runtime_snapshot_directory/SNAPSHOT")" = \
        "project=$snapshot_project" ] &&
    [ "$(LC_ALL=C awk 'END { print NR + 0 }' \
        "$runtime_snapshot_directory/SNAPSHOT")" -eq 2 ] ||
        die "private deployment snapshot identity is invalid"

    for snapshot_file in \
        "$runtime_snapshot_directory/compose.yaml" \
        "$runtime_snapshot_directory/.env" \
        "$runtime_snapshot_directory/SNAPSHOT" \
        "$runtime_snapshot_directory/secrets/db_password.txt" \
        "$runtime_snapshot_directory/secrets/db_root_password.txt" \
        "$runtime_snapshot_directory/secrets/admin_password.txt"
    do
        [ ! -L "$snapshot_file" ] &&
        [ -f "$snapshot_file" ] &&
        [ "$(portable_stat_owner_uid "$snapshot_file")" -eq "$(id -u)" ] &&
        [ "$(portable_stat_mode "$snapshot_file")" = 400 ] ||
            die "private deployment snapshot file metadata is unsafe"
        verify_no_extended_acl "$snapshot_file" \
            "private deployment snapshot file"
    done

    for snapshot_secret_name in \
        ESTAB_DB_PASSWORD_SECRET_FILE \
        ESTAB_DB_ROOT_PASSWORD_SECRET_FILE \
        ESTAB_ADMIN_PASSWORD_SECRET_FILE
    do
        snapshot_secret_digest=$(secret_record_field \
            "$snapshot_secret_records" "$snapshot_secret_name" 5) ||
            die "cannot read verified digest for $snapshot_secret_name"
        case "$snapshot_secret_name" in
            ESTAB_DB_PASSWORD_SECRET_FILE)
                snapshot_secret_file=db_password.txt
                ;;
            ESTAB_DB_ROOT_PASSWORD_SECRET_FILE)
                snapshot_secret_file=db_root_password.txt
                ;;
            ESTAB_ADMIN_PASSWORD_SECRET_FILE)
                snapshot_secret_file=admin_password.txt
                ;;
        esac
        [ "$(sha256_file \
            "$runtime_snapshot_directory/secrets/$snapshot_secret_file")" = \
            "$snapshot_secret_digest" ] ||
            die "private snapshot content changed for $snapshot_secret_name"
    done

    active_compose_environment=$runtime_snapshot_directory/.env
    active_compose_file=$runtime_snapshot_directory/compose.yaml
    runtime_snapshot_ready=1
}

verify_productive_runtime_mount()
{
    runtime_mount_container=$1
    runtime_mount_role=$2
    runtime_mount_type=$3
    runtime_mount_source=$4
    runtime_mount_target=$5
    runtime_mount_alias_one=
    runtime_mount_alias_two=
    if [ "$runtime_mount_type" = bind ] &&
        [ "$container_cli" = docker ] &&
        [ "$(uname -s 2>/dev/null || :)" = Darwin ]; then
        runtime_mount_alias_one=/host_mnt$runtime_mount_source
        runtime_mount_alias_two=/run/desktop/mnt/host$runtime_mount_source
    fi

    runtime_mount_records=$(inspect_value "$runtime_mount_container" \
        '{{range .Mounts}}{{printf "%s\t%s\t%s\t%s\t%t\n" .Type .Source .Name .Destination .RW}}{{end}}') ||
        die "cannot inspect existing $runtime_mount_role container mounts"
    printf '%s\n' "$runtime_mount_records" |
        LC_ALL=C awk -F '	' \
            -v expected_type="$runtime_mount_type" \
            -v expected_source="$runtime_mount_source" \
            -v source_alias_one="$runtime_mount_alias_one" \
            -v source_alias_two="$runtime_mount_alias_two" \
            -v expected_target="$runtime_mount_target" '
              function overlaps(first, second) {
                return first == second ||
                  first == "/" || second == "/" ||
                  index(first, second "/") == 1 ||
                  index(second, first "/") == 1
              }
              {
                if (NF != 5 || $4 !~ /^\// ||
                    ($1 != "bind" && $1 != "volume") ||
                    $2 !~ /^\// || $2 == "/" ||
                    ($1 == "bind" && $3 != "") ||
                    ($1 == "volume" && $3 == "")) {
                  malformed = 1
                }
                if ($4 != expected_target &&
                    overlaps($4, expected_target)) {
                  overlap = 1
                }
              }
              $4 == expected_target {
                matches++
                if ($1 != expected_type || $5 != "true" ||
                    (expected_type == "bind" &&
                     ($2 != expected_source &&
                      $2 != source_alias_one &&
                      $2 != source_alias_two || $3 != "")) ||
                    (expected_type == "volume" &&
                     $3 != expected_source)) {
                  mismatch = 1
                }
              }
              END {
                if (matches != 1 || mismatch || malformed || overlap) {
                  exit 1
                }
              }
            ' ||
        die "existing $runtime_mount_role productive mount does not match the locked .env (type, bind source or volume name, safe engine source, target, and read/write mode)"
}

verify_existing_runtime_storage()
{
    runtime_project=$1
    runtime_storage_records=$2

    runtime_db_ids=$(engine_project_service_ids "$runtime_project" db) ||
        die "cannot enumerate existing database containers"
    runtime_app_ids=$(engine_project_service_ids "$runtime_project" app) ||
        die "cannot enumerate existing application containers"
    runtime_db_count=$(printf '%s\n' "$runtime_db_ids" |
        LC_ALL=C awk 'NF {
          if (NF != 1) exit 1
          count++
        }
        END { print count + 0 }') ||
        die "database container enumeration is ambiguous"
    runtime_app_count=$(printf '%s\n' "$runtime_app_ids" |
        LC_ALL=C awk 'NF {
          if (NF != 1) exit 1
          count++
        }
        END { print count + 0 }') ||
        die "application container enumeration is ambiguous"

    if [ "$runtime_db_count" -eq 0 ] &&
        [ "$runtime_app_count" -eq 0 ]; then
        return
    fi
    if [ "$runtime_db_count" -ne 1 ] ||
        [ "$runtime_app_count" -ne 1 ]; then
        die "existing deployment must contain exactly one database and one application container"
    fi
    runtime_db_id=$(printf '%s\n' "$runtime_db_ids" |
        LC_ALL=C awk 'NF { print; exit }')
    runtime_app_id=$(printf '%s\n' "$runtime_app_ids" |
        LC_ALL=C awk 'NF { print; exit }')

    runtime_db_type=$(storage_record_field \
        "$runtime_storage_records" database 2) ||
        die "locked database storage record is invalid"
    runtime_db_source=$(storage_record_field \
        "$runtime_storage_records" database 3) ||
        die "locked database storage record is invalid"
    runtime_app_type=$(storage_record_field \
        "$runtime_storage_records" application 2) ||
        die "locked application storage record is invalid"
    runtime_app_source=$(storage_record_field \
        "$runtime_storage_records" application 3) ||
        die "locked application storage record is invalid"
    runtime_export_type=$(storage_record_field \
        "$runtime_storage_records" export 2) ||
        die "locked export storage record is invalid"
    runtime_export_source=$(storage_record_field \
        "$runtime_storage_records" export 3) ||
        die "locked export storage record is invalid"

    if [ "$runtime_db_type" = volume ]; then
        runtime_db_source=${runtime_project}_$runtime_db_source
    fi
    if [ "$runtime_app_type" = volume ]; then
        runtime_app_source=${runtime_project}_$runtime_app_source
    fi
    if [ "$runtime_export_type" = volume ]; then
        runtime_export_source=${runtime_project}_$runtime_export_source
    fi

    verify_productive_runtime_mount \
        "$runtime_db_id" database "$runtime_db_type" "$runtime_db_source" \
        /var/lib/mysql
    verify_productive_runtime_mount \
        "$runtime_app_id" application "$runtime_app_type" \
        "$runtime_app_source" /var/www/html/4fdata
    verify_productive_runtime_mount \
        "$runtime_app_id" export "$runtime_export_type" \
        "$runtime_export_source" /var/lib/estab/export
}

compose_service_container_id()
{
    service_name=$1
    engine_project_service_id "$locked_project" "$service_name" ||
        die "ready deployment must contain exactly one $service_name container"
}

verify_runtime_secret_mount()
{
    secret_container=$1
    secret_service=$2
    secret_source=$3
    secret_target=$4
    secret_source_alias_one=
    secret_source_alias_two=
    if [ "$container_cli" = docker ] &&
        [ "$(uname -s 2>/dev/null || :)" = Darwin ]; then
        secret_source_alias_one=/host_mnt$secret_source
        secret_source_alias_two=/run/desktop/mnt/host$secret_source
    fi

    secret_mount_records=$(inspect_value "$secret_container" \
        '{{range .Mounts}}{{printf "%s\t%s\t%s\t%s\t%t\n" .Type .Source .Name .Destination .RW}}{{end}}') ||
        die "cannot inspect $secret_service secret mounts"
    printf '%s\n' "$secret_mount_records" |
        LC_ALL=C awk -F '	' \
            -v expected_source="$secret_source" \
            -v source_alias_one="$secret_source_alias_one" \
            -v source_alias_two="$secret_source_alias_two" \
            -v expected_target="$secret_target" '
              function overlaps(first, second) {
                return first == second ||
                  first == "/" || second == "/" ||
                  index(first, second "/") == 1 ||
                  index(second, first "/") == 1
              }
              {
                if (NF != 5 || $4 !~ /^\//) {
                  malformed = 1
                }
                if ($4 != expected_target &&
                    overlaps($4, expected_target)) {
                  overlap = 1
                }
              }
              $4 == expected_target {
                matches++
                if ($1 != "bind" ||
                    ($2 != expected_source &&
                     $2 != source_alias_one &&
                     $2 != source_alias_two) ||
                    $3 != "" || $5 != "false") {
                  mismatch = 1
                }
              }
              END {
                if (matches != 1 || mismatch || malformed || overlap) {
                  exit 1
                }
              }
            ' ||
        die "$secret_service secret mount does not use the current private read-only deployment snapshot"
}

verify_runtime_secret_snapshot()
{
    secret_db_id=$(compose_service_container_id db)
    secret_app_id=$(compose_service_container_id app)
    secret_migrate_id=$(compose_service_container_id migrate)
    secret_auth_id=$(compose_service_container_id admin-auth-init)

    verify_runtime_secret_mount "$secret_db_id" database \
        "$runtime_snapshot_directory/secrets/db_password.txt" \
        /run/secrets/estab_db_password
    verify_runtime_secret_mount "$secret_db_id" database \
        "$runtime_snapshot_directory/secrets/db_root_password.txt" \
        /run/secrets/estab_db_root_password
    verify_runtime_secret_mount "$secret_app_id" application \
        "$runtime_snapshot_directory/secrets/db_password.txt" \
        /run/secrets/estab_db_password
    verify_runtime_secret_mount "$secret_migrate_id" migration \
        "$runtime_snapshot_directory/secrets/db_root_password.txt" \
        /run/secrets/estab_db_root_password
    verify_runtime_secret_mount "$secret_auth_id" \
        "administrator authentication initialization" \
        "$runtime_snapshot_directory/secrets/admin_password.txt" \
        /run/secrets/estab_admin_password
}

project_runtime_mount_sources()
{
    mount_project=$1
    mount_container_ids=$("$container_cli" ps \
        --all \
        --no-trunc \
        --filter "label=com.docker.compose.project=$mount_project" \
        --format '{{.ID}}') ||
        die "cannot enumerate project containers before snapshot cleanup"

    while IFS= read -r mount_container_id
    do
        [ -n "$mount_container_id" ] || continue
        mount_container_id=$(inspect_value "$mount_container_id" '{{.Id}}') ||
            die "project container inventory changed during snapshot cleanup"
        case "$mount_container_id" in
            *[!0123456789abcdef]*)
                die "project container enumeration returned an invalid ID"
                ;;
        esac
        [ "${#mount_container_id}" -eq 64 ] ||
            die "project container enumeration returned an invalid ID"
        mount_actual_project=$(inspect_value "$mount_container_id" \
            '{{ index .Config.Labels "com.docker.compose.project" }}') ||
            die "cannot bind project container to its Compose label"
        [ "$mount_actual_project" = "$mount_project" ] ||
            die "project container inventory changed during snapshot cleanup"
        mount_records=$(inspect_value "$mount_container_id" \
            '{{range .Mounts}}{{printf "%s\t%s\t%s\t%s\t%t\n" .Type .Source .Name .Destination .RW}}{{end}}') ||
            die "cannot inspect every project mount before snapshot cleanup"
        printf '%s\n' "$mount_records" |
            LC_ALL=C awk -F '	' '
              NF {
                if (NF != 5 || $2 !~ /^\//) {
                  exit 1
                }
                print $2
              }
            ' ||
            die "project mount metadata is malformed; refusing snapshot cleanup"
    done <<EOF
$mount_container_ids
EOF
}

validate_private_snapshot_for_removal()
{
    removal_snapshot=$1
    removal_basename=${removal_snapshot##*/}
    removal_identity=${removal_basename#snapshot-}
    case "$removal_basename" in
        snapshot-*) ;;
        *) die "refusing cleanup of a non-snapshot private state entry" ;;
    esac
    case "$removal_identity" in
        ''|*[!0123456789abcdef]*)
            die "private deployment state contains an invalid snapshot name"
            ;;
    esac
    [ "${#removal_identity}" -eq 64 ] ||
        die "private deployment state contains an invalid snapshot name"
    [ -d "$removal_snapshot" ] && [ ! -L "$removal_snapshot" ] ||
        die "private deployment state contains an unsafe snapshot entry"
    removal_parent=$(CDPATH= cd -- "$removal_snapshot/.." && pwd -P) ||
        die "cannot canonicalize private snapshot parent"
    [ "$removal_parent" = "$snapshot_parent" ] ||
        die "private snapshot escaped its verified parent"

    ensure_private_directory "$removal_snapshot" \
        "old private deployment snapshot"
    ensure_private_directory "$removal_snapshot/secrets" \
        "old private snapshot secret directory"

    removal_entry_count=$(find "$removal_snapshot" -mindepth 1 -print |
        LC_ALL=C awk 'END { print NR + 0 }') ||
        die "cannot inspect old private snapshot contents"
    [ "$removal_entry_count" -eq 7 ] ||
        die "old private deployment snapshot contains unexpected entries"
    for removal_file in \
        "$removal_snapshot/compose.yaml" \
        "$removal_snapshot/.env" \
        "$removal_snapshot/SNAPSHOT" \
        "$removal_snapshot/secrets/db_password.txt" \
        "$removal_snapshot/secrets/db_root_password.txt" \
        "$removal_snapshot/secrets/admin_password.txt"
    do
        [ -f "$removal_file" ] && [ ! -L "$removal_file" ] &&
        [ "$(portable_stat_owner_uid "$removal_file")" -eq "$(id -u)" ] &&
        [ "$(portable_stat_mode "$removal_file")" = 400 ] ||
            die "old private deployment snapshot file metadata is unsafe"
        verify_no_extended_acl "$removal_file" \
            "old private deployment snapshot file"
    done
    [ "$(sed -n '1p' "$removal_snapshot/SNAPSHOT")" = \
        "identity=$removal_identity" ] &&
    [ "$(sed -n '2p' "$removal_snapshot/SNAPSHOT")" = \
        "project=$snapshot_project" ] &&
    [ "$(LC_ALL=C awk 'END { print NR + 0 }' \
        "$removal_snapshot/SNAPSHOT")" -eq 2 ] ||
        die "old private deployment snapshot identity is invalid"
}

remove_private_snapshot()
{
    removal_snapshot=$1
    rm -f -- \
        "$removal_snapshot/secrets/db_password.txt" \
        "$removal_snapshot/secrets/db_root_password.txt" \
        "$removal_snapshot/secrets/admin_password.txt" \
        "$removal_snapshot/.env" \
        "$removal_snapshot/compose.yaml" \
        "$removal_snapshot/SNAPSHOT" ||
        die "cannot remove obsolete private snapshot files"
    rmdir -- "$removal_snapshot/secrets" "$removal_snapshot" ||
        die "cannot remove obsolete private snapshot directories"
    printf 'eStab deployment: removed obsolete private snapshot %s\n' \
        "${removal_snapshot##*/}"
}

prune_unreferenced_runtime_snapshots()
{
    prune_project=$1
    all_project_mount_sources=$(project_runtime_mount_sources \
        "$prune_project")

    for prune_snapshot in "$snapshot_parent"/snapshot-*
    do
        [ -e "$prune_snapshot" ] || [ -L "$prune_snapshot" ] || continue
        [ "$prune_snapshot" != "$runtime_snapshot_directory" ] || continue
        validate_private_snapshot_for_removal "$prune_snapshot"
        if printf '%s\n' "$all_project_mount_sources" |
            LC_ALL=C awk -v directory="$prune_snapshot" '
              $0 == directory ||
              index($0, directory "/") == 1 {
                referenced = 1
              }
              END { exit referenced ? 0 : 1 }
            '
        then
            printf 'eStab deployment: retaining referenced private snapshot %s\n' \
                "${prune_snapshot##*/}"
        else
            remove_private_snapshot "$prune_snapshot"
        fi
    done
}

inspect_value()
{
    inspect_container=$1
    inspect_template=$2
    "$container_cli" inspect --format "$inspect_template" "$inspect_container"
}

diagnose_maintenance_lock()
{
    diagnose_lock_id=$(inspect_value "$maintenance_lock_name" '{{.Id}}' \
        2>/dev/null || :)
    if [ -z "$diagnose_lock_id" ]; then
        printf 'eStab deployment: no inspectable lock container named %s was found; inspect the preceding container-engine error.\n' \
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
    printf 'eStab deployment: engine-global maintenance lock: name=%s id=%s project=%s operation=%s owner=%s started_utc=%s status=%s\n' \
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
    [ "$owned_lock_operation" = deploy ] &&
    [ "$owned_lock_token" = "$maintenance_lock_token" ] &&
    [ "$owned_lock_status" = running ] &&
    [ "$owned_lock_running" = true ] &&
    [ "$owned_lock_image" = "$maintenance_lock_image" ]
}

acquire_maintenance_lock()
{
    maintenance_lock_project=$1
    maintenance_lock_image=$2
    case "$maintenance_lock_project" in
        ''|[!a-z0-9]*|*[!a-z0-9_-]*)
            die "verified Compose project is not in canonical lowercase form"
            ;;
    esac
    [ "${#maintenance_lock_project}" -le 128 ] ||
        die "verified Compose project is too long for the maintenance lock"
    maintenance_lock_image=$(canonical_image_id "$maintenance_lock_image") ||
        die "verified app image ID is invalid for the maintenance lock"
    maintenance_lock_name=estab-maintenance-lock-$maintenance_lock_project
    maintenance_lock_started=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    maintenance_lock_token="deploy-$$-$(date -u '+%Y%m%dT%H%M%SZ')"

    if ! "$container_cli" run --detach \
        --name "$maintenance_lock_name" \
        --label org.e-stab.maintenance-lock=true \
        --label "org.e-stab.compose-project=$maintenance_lock_project" \
        --label org.e-stab.maintenance-operation=deploy \
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

cleanup()
{
    cleanup_status=$1
    trap - EXIT HUP INT TERM
    if [ "$maintenance_lock_held" -eq 1 ]; then
        if maintenance_lock_is_owned; then
            if ! "$container_cli" container rm --force \
                "$maintenance_lock_id" >/dev/null; then
                printf 'eStab deployment: WARNING: owned maintenance lock container could not be removed: %s (%s)\n' \
                    "$maintenance_lock_name" "$maintenance_lock_id" >&2
                [ "$cleanup_status" -ne 0 ] || cleanup_status=1
            fi
        else
            printf 'eStab deployment: WARNING: maintenance lock ownership changed; refusing removal by name: %s (owned id %s)\n' \
                "$maintenance_lock_name" "$maintenance_lock_id" >&2
            [ "$cleanup_status" -ne 0 ] || cleanup_status=1
        fi
        maintenance_lock_held=0
    fi
    if [ -n "$runtime_snapshot_directory" ] &&
        [ "$runtime_snapshot_created" -eq 1 ] &&
        [ "$runtime_snapshot_in_use" -eq 0 ]; then
        if [ -n "$snapshot_parent" ] &&
            [ -d "$runtime_snapshot_directory" ] &&
            [ ! -L "$runtime_snapshot_directory" ]; then
            runtime_snapshot_parent=$(CDPATH= cd -- \
                "$runtime_snapshot_directory/.." && pwd -P 2>/dev/null || :)
            case "$runtime_snapshot_directory" in
                "$runtime_snapshot_parent"/snapshot-[0-9a-f][0-9a-f]*) ;;
                *) runtime_snapshot_parent= ;;
            esac
        else
            runtime_snapshot_parent=
        fi
        if [ -n "$runtime_snapshot_parent" ] &&
            [ "$runtime_snapshot_parent" = "$snapshot_parent" ]; then
            rm -f -- \
                "$runtime_snapshot_directory/secrets/db_password.txt" \
                "$runtime_snapshot_directory/secrets/db_root_password.txt" \
                "$runtime_snapshot_directory/secrets/admin_password.txt" \
                "$runtime_snapshot_directory/.env" \
                "$runtime_snapshot_directory/compose.yaml" \
                "$runtime_snapshot_directory/SNAPSHOT" ||
                cleanup_status=1
            rmdir -- "$runtime_snapshot_directory/secrets" \
                2>/dev/null || cleanup_status=1
            rmdir -- "$runtime_snapshot_directory" \
                2>/dev/null || cleanup_status=1
        else
            printf 'eStab deployment: WARNING: refusing to remove an unverified runtime snapshot path: %s\n' \
                "$runtime_snapshot_directory" >&2
            [ "$cleanup_status" -ne 0 ] || cleanup_status=1
        fi
    fi
    exit "$cleanup_status"
}

maintenance_lock_held=0
maintenance_lock_id=
maintenance_lock_name=
maintenance_lock_project=
maintenance_lock_token=
maintenance_lock_image=
runtime_snapshot_directory=
runtime_snapshot_ready=0
runtime_snapshot_in_use=0
runtime_snapshot_created=0
snapshot_parent=

trap 'cleanup $?' EXIT
trap 'cleanup 129' HUP
trap 'cleanup 130' INT
trap 'cleanup 143' TERM

verify_release=$script_dir/verify-release.sh
[ -f "$verify_release" ] && [ ! -L "$verify_release" ] &&
    [ -x "$verify_release" ] ||
    die "release verifier is missing or not executable: $verify_release"

ESTAB_CONTAINER_CLI=$container_cli "$verify_release" "$script_dir"
verified_environment_configuration=$(collect_environment_records) || exit 1
verified_secret_configuration=$(verify_secret_configuration) || exit 1
verified_storage_configuration=$(verify_storage_configuration) || exit 1
create_runtime_snapshot \
    "$verified_environment_configuration" \
    "$verified_storage_configuration" \
    "$verified_secret_configuration"

# Prove that every mutable source still matches the immutable private copy
# before any provider command consumes it.
ESTAB_CONTAINER_CLI=$container_cli "$verify_release" "$script_dir"
snapshot_environment_configuration=$(collect_environment_records) || exit 1
snapshot_secret_configuration=$(verify_secret_configuration) || exit 1
snapshot_storage_configuration=$(verify_storage_configuration) || exit 1
[ "$snapshot_environment_configuration" = \
    "$verified_environment_configuration" ] &&
[ "$snapshot_secret_configuration" = "$verified_secret_configuration" ] &&
[ "$snapshot_storage_configuration" = "$verified_storage_configuration" ] ||
    die "release configuration changed while its private runtime snapshot was created"
verify_compose_capabilities
[ "$action" != check ] || exit 0

compose pull
ESTAB_CONTAINER_CLI=$container_cli "$verify_release" "$script_dir"
pulled_environment_configuration=$(collect_environment_records) || exit 1
pulled_secret_configuration=$(verify_secret_configuration) || exit 1
pulled_storage_configuration=$(verify_storage_configuration) || exit 1
[ "$pulled_environment_configuration" = \
    "$verified_environment_configuration" ] &&
[ "$pulled_secret_configuration" = "$verified_secret_configuration" ] &&
[ "$pulled_storage_configuration" = "$verified_storage_configuration" ] ||
    die "release configuration changed while images were pulled"
ESTAB_CONTAINER_CLI=$container_cli \
    "$verify_release" --inspect-images "$script_dir"
[ "$action" != pull ] || exit 0

verified_project=$(environment_record_value \
    "$verified_environment_configuration" COMPOSE_PROJECT_NAME) ||
    die "cannot read verified Compose project"
verified_app_reference=$(environment_record_value \
    "$verified_environment_configuration" ESTAB_APP_IMAGE) ||
    die "cannot read verified app image"
verified_app_image=$("$container_cli" image inspect --format '{{.Id}}' \
    "$verified_app_reference") ||
    die "cannot inspect verified app image ID"
verified_app_image=$(canonical_image_id "$verified_app_image") ||
    die "verified app image has an invalid runtime ID"

health_timeout_seconds=${ESTAB_DEPLOY_HEALTH_TIMEOUT_SECONDS:-300}
case "$health_timeout_seconds" in
    ''|*[!0-9]*|0?*)
        die "ESTAB_DEPLOY_HEALTH_TIMEOUT_SECONDS must be an integer from 1 to 3600"
        ;;
esac
if [ "${#health_timeout_seconds}" -gt 4 ] ||
    [ "$health_timeout_seconds" -lt 1 ] ||
    [ "$health_timeout_seconds" -gt 3600 ]; then
    die "ESTAB_DEPLOY_HEALTH_TIMEOUT_SECONDS must be an integer from 1 to 3600"
fi

acquire_maintenance_lock "$verified_project" "$verified_app_image"

# Re-run all mutable-source checks after the global project lock. Compose itself
# continues to consume only the already checked private snapshot.
ESTAB_CONTAINER_CLI=$container_cli "$verify_release" "$script_dir"
verify_compose_capabilities
locked_environment_configuration=$(collect_environment_records) || exit 1
locked_secret_configuration=$(verify_secret_configuration) || exit 1
locked_storage_configuration=$(verify_storage_configuration) || exit 1
ESTAB_CONTAINER_CLI=$container_cli \
    "$verify_release" --inspect-images "$script_dir"
locked_project=$(environment_record_value \
    "$locked_environment_configuration" COMPOSE_PROJECT_NAME) ||
    die "cannot reread verified Compose project under the maintenance lock"
locked_app_reference=$(environment_record_value \
    "$locked_environment_configuration" ESTAB_APP_IMAGE) ||
    die "cannot reread verified app image under the maintenance lock"
locked_app_image=$("$container_cli" image inspect --format '{{.Id}}' \
    "$locked_app_reference") ||
    die "cannot reinspect verified app image ID"
locked_app_image=$(canonical_image_id "$locked_app_image") ||
    die "verified app image has an invalid runtime ID under the maintenance lock"
[ "$locked_project" = "$verified_project" ] &&
[ "$locked_app_reference" = "$verified_app_reference" ] &&
[ "$locked_app_image" = "$verified_app_image" ] &&
[ "$locked_environment_configuration" = \
    "$verified_environment_configuration" ] &&
[ "$locked_secret_configuration" = "$verified_secret_configuration" ] &&
[ "$locked_storage_configuration" = "$verified_storage_configuration" ] ||
    die "release identity changed while the maintenance lock was acquired"
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost before Compose up"
verify_existing_runtime_storage \
    "$locked_project" "$locked_storage_configuration"
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost while inspecting existing productive mounts"

runtime_snapshot_in_use=1
# A snapshot path changes whenever configuration or secret content changes.
# Compose does not include top-level secret file paths in every service hash, so
# explicit recreation is required to make the inspected runtime consume the
# exact private snapshot that was just locked and verified.
compose up --detach --force-recreate
maintenance_lock_is_owned ||
    die "engine-global maintenance lock was lost during Compose up"

deadline=$(( $(date +%s) + health_timeout_seconds ))
while :; do
    maintenance_lock_is_owned ||
        die "engine-global maintenance lock was lost while waiting for deployment readiness"
    failed_service=
    ready=1
    for service in db app; do
        container_id=$(engine_project_service_id \
            "$locked_project" "$service" 2>/dev/null || :)
        if [ -z "$container_id" ]; then
            ready=0
            continue
        fi
        state=$("$container_cli" inspect --format \
            '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' \
            "$container_id" 2>/dev/null || :)
        case "$state" in
            running\ healthy) ;;
            running\ starting) ready=0 ;;
            *) failed_service="$service ($state)"; break ;;
        esac
    done

    migrate_id=$(engine_project_service_id \
        "$locked_project" migrate 2>/dev/null || :)
    if [ -z "$migrate_id" ]; then
        ready=0
    else
        migrate_state=$("$container_cli" inspect --format \
            '{{.State.Status}} {{.State.ExitCode}}' "$migrate_id" \
            2>/dev/null || :)
        case "$migrate_state" in
            exited\ 0) ;;
            created\ *|running\ *) ready=0 ;;
            *) failed_service="migrate ($migrate_state)" ;;
        esac
    fi

    admin_auth_id=$(engine_project_service_id \
        "$locked_project" admin-auth-init 2>/dev/null || :)
    if [ -z "$admin_auth_id" ]; then
        ready=0
    else
        admin_auth_state=$("$container_cli" inspect --format \
            '{{.State.Status}} {{.State.ExitCode}}' "$admin_auth_id" \
            2>/dev/null || :)
        case "$admin_auth_state" in
            exited\ 0) ;;
            created\ *|running\ *) ready=0 ;;
            *) failed_service="admin-auth-init ($admin_auth_state)" ;;
        esac
    fi

    if [ -n "$failed_service" ]; then
        compose ps >&2 || true
        compose logs --no-color --tail=200 >&2 || true
        die "service failed during deployment: $failed_service"
    fi
    if [ "$ready" -eq 1 ]; then
        verify_existing_runtime_storage \
            "$locked_project" "$locked_storage_configuration"
        maintenance_lock_is_owned ||
            die "engine-global maintenance lock was lost while verifying productive mounts"
        verify_runtime_secret_snapshot
        maintenance_lock_is_owned ||
            die "engine-global maintenance lock was lost while verifying private secret mounts"
        prune_unreferenced_runtime_snapshots "$locked_project"
        maintenance_lock_is_owned ||
            die "engine-global maintenance lock was lost during private snapshot cleanup"
        printf 'eStab deployment: ready\n'
        compose ps
        exit 0
    fi
    if [ "$(date +%s)" -ge "$deadline" ]; then
        compose ps >&2 || true
        compose logs --no-color --tail=200 >&2 || true
        die "services did not become ready within ${health_timeout_seconds} seconds"
    fi
    sleep 3
done
