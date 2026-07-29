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
load_secret ESTAB_ADMIN_PASSWORD

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
    ''|*[!A-Za-z0-9_.-]*) echo "ESTAB_ADMIN_USER contains invalid characters" >&2; exit 1 ;;
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
install -d -o root -g www-data -m 0750 /run/estab

printf '%s\n' "$ESTAB_ADMIN_PASSWORD" \
    | htpasswd -Bni "$ESTAB_ADMIN_USER" > /run/estab/admin.htpasswd
chown root:www-data /run/estab/admin.htpasswd
chmod 0640 /run/estab/admin.htpasswd
unset ESTAB_ADMIN_PASSWORD

export ESTAB_DB_HOST ESTAB_DB_PORT ESTAB_DB_USER ESTAB_DB_NAME ESTAB_ADMIN_USER ESTAB_BASE_PATH

exec docker-php-entrypoint "$@"
