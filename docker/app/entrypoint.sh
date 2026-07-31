#!/bin/sh
set -eu

umask 027

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

data_root="/var/www/html/4fdata/$ESTAB_DB_NAME"
install -d -o www-data -g www-data -m 0770 \
    "$data_root" \
    "$data_root/anhang" \
    "$data_root/vordruck" \
    "${ESTAB_EXPORT_DIR:-/var/lib/estab/export}" \
    /var/lib/php/sessions

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
