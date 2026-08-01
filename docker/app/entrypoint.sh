#!/bin/sh
set -eu

umask 027

# Keep PHP and the startup janitor on the same fixed, container-local root.
TMPDIR=/tmp
export TMPDIR

load_secret() {
    variable_name="$1"
    file_variable_name="${variable_name}_FILE"
    file_name="$(printenv "$file_variable_name" 2>/dev/null || true)"
    current_value="$(printenv "$variable_name" 2>/dev/null || true)"

    if [ -n "$file_name" ]; then
        if [ ! -r "$file_name" ]; then
            echo "Secret file for $variable_name is not readable" >&2
            exit 1
        fi
        current_value="$(tr -d '\r\n' < "$file_name")"
    fi

    if [ -z "$current_value" ]; then
        echo "$variable_name must be provided directly or via ${variable_name}_FILE" >&2
        exit 1
    fi

    export "$variable_name=$current_value"
    unset file_name current_value file_variable_name variable_name
}

load_secret ESTAB_DB_PASSWORD

: "${ESTAB_DB_HOST:=db}"
: "${ESTAB_DB_PORT:=3306}"
: "${ESTAB_DB_USER:=estab}"
: "${ESTAB_DB_NAME:=estab}"
: "${ESTAB_ADMIN_USER:=estab-admin}"
: "${ESTAB_BASE_PATH:=}"

case "$ESTAB_DB_PORT" in
    ''|*[!0-9]*) echo "ESTAB_DB_PORT must be numeric" >&2; exit 1 ;;
esac
case "$ESTAB_DB_NAME" in
    ''|*[!A-Za-z0-9_]*) echo "ESTAB_DB_NAME contains invalid characters" >&2; exit 1 ;;
esac
case "$ESTAB_ADMIN_USER" in
    [A-Za-z0-9]*)
        case "$ESTAB_ADMIN_USER" in
            *[!A-Za-z0-9_.-]*)
                echo "ESTAB_ADMIN_USER contains invalid characters" >&2
                exit 1
                ;;
        esac
        ;;
    *)
        echo "ESTAB_ADMIN_USER must start with an alphanumeric character" >&2
        exit 1
        ;;
esac

php -d display_errors=stderr -r \
    'require "/var/www/html/app/bootstrap.php"; estab_validate_runtime_configuration();'

# A killed PDF export can leave its private raster workspace behind. The
# janitor only accepts old, owner/mode/name-verified, flat workspaces.
estab-cleanup-pdf-render-tmp /tmp 1440 www-data

prepare_writable_directory() {
    writable_directory=$1
    if [ -L "$writable_directory" ] ||
        { [ -e "$writable_directory" ] && [ ! -d "$writable_directory" ]; }; then
        echo "Writable application path must be a real directory: $writable_directory" >&2
        exit 1
    fi
    if ! install -d -o www-data -g www-data -m 0770 "$writable_directory"; then
        echo "Cannot assign writable application path to www-data: $writable_directory" >&2
        exit 1
    fi
    if ! setfacl -b -k -- "$writable_directory"; then
        echo "Cannot remove extended ACLs from writable application path: $writable_directory" >&2
        exit 1
    fi
    if ! chown www-data:www-data "$writable_directory" ||
        ! chmod 0770 "$writable_directory"; then
        echo "Cannot normalize writable application path metadata: $writable_directory" >&2
        exit 1
    fi
    if ! getfacl -cp -- "$writable_directory" |
        LC_ALL=C awk '
          /^$/ {
            next
          }
          $0 == "user::rwx" {
            owner++
            next
          }
          $0 == "group::rwx" {
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
            if (owner != 1 || group != 1 || other != 1 || extended) {
              exit 1
            }
          }
        '; then
        echo "Writable application path retains an extended or access-granting ACL: $writable_directory" >&2
        exit 1
    fi
    if [ -L "$writable_directory" ] ||
        [ -z "$(find "$writable_directory" -prune -type d \
            -perm 0770 -print)" ]; then
        echo "Writable application path does not have mode 0770: $writable_directory" >&2
        exit 1
    fi
    if [ -z "$(find "$writable_directory" -prune -type d \
        -user www-data -group www-data -print)" ]; then
        printf 'Writable application path uses provider-mapped ownership; verifying effective www-data access: %s\n' \
            "$writable_directory" >&2
    fi
    if ! setpriv --reuid=www-data --regid=www-data --clear-groups \
        sh -ceu '
            probe_root=$1
            write_probe=
            cleanup_write_probe()
            {
                [ -z "$write_probe" ] || rm -f -- "$write_probe"
            }
            trap cleanup_write_probe EXIT
            trap "exit 129" HUP
            trap "exit 130" INT
            trap "exit 143" TERM
            write_probe=$(mktemp \
                "$probe_root/.estab-entrypoint-write-probe.XXXXXX")
            printf "www-data-write-probe\n" >"$write_probe"
            test "$(cat "$write_probe")" = "www-data-write-probe"
            rm -f -- "$write_probe"
            write_probe=
        ' estab-write-probe "$writable_directory"; then
        echo "Writable application path is not writable by www-data: $writable_directory" >&2
        exit 1
    fi
}

app_data_root=/var/www/html/4fdata
export_root=${ESTAB_EXPORT_DIR:-/var/lib/estab/export}
prepare_writable_directory "$app_data_root"
prepare_writable_directory "$export_root"

data_root="$app_data_root/$ESTAB_DB_NAME"
prepare_writable_directory "$data_root"
prepare_writable_directory "$data_root/anhang"
prepare_writable_directory "$data_root/vordruck"
prepare_writable_directory /var/lib/php/sessions

admin_auth_file=/run/estab-auth/admin.htpasswd
if [ -L "$admin_auth_file" ] ||
    [ ! -f "$admin_auth_file" ] ||
    [ ! -r "$admin_auth_file" ]; then
    echo "Derived admin authentication file is not readable" >&2
    exit 1
fi
if [ -z "$(find "$admin_auth_file" -prune -type f \
    -user root -group www-data -perm 0640 -print)" ]; then
    echo "Derived admin authentication file has unsafe ownership or permissions" >&2
    exit 1
fi
if ! awk -F ':' -v expected_user="$ESTAB_ADMIN_USER" '
    NR == 1 {
        prefix = substr($2, 1, 4)
        cost = substr($2, 5, 2)
        payload = substr($2, 8)
        if (NF != 2 || $1 != expected_user || length($2) != 60 ||
            (prefix != "$2a$" && prefix != "$2b$" && prefix != "$2y$") ||
            cost != "12" || substr($2, 7, 1) != "$" ||
            length(payload) != 53 || payload ~ /[^.\/A-Za-z0-9]/) {
            exit 1
        }
        valid = 1
        next
    }
    { exit 1 }
    END { if (!valid) exit 1 }
' "$admin_auth_file"; then
    echo "Derived admin authentication file is invalid or belongs to another user" >&2
    exit 1
fi

export ESTAB_DB_HOST ESTAB_DB_PORT ESTAB_DB_USER ESTAB_DB_NAME ESTAB_ADMIN_USER ESTAB_BASE_PATH

exec docker-php-entrypoint "$@"
