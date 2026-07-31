#!/bin/sh
set -eu

umask 077

: "${ESTAB_ADMIN_USER:=estab-admin}"
: "${ESTAB_ADMIN_PASSWORD_FILE:=/run/secrets/estab_admin_password}"

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

if [ ! -f "$ESTAB_ADMIN_PASSWORD_FILE" ] ||
    [ ! -r "$ESTAB_ADMIN_PASSWORD_FILE" ]; then
    echo "Admin password secret is not a readable regular file" >&2
    exit 1
fi

if ! LC_ALL=C awk 'END { exit (NR == 1 ? 0 : 1) }' \
    "$ESTAB_ADMIN_PASSWORD_FILE"; then
    echo "Admin password secret must contain exactly one line" >&2
    exit 1
fi
admin_password=$(tr -d '\r\n' <"$ESTAB_ADMIN_PASSWORD_FILE")
admin_password_bytes=$(
    LC_ALL=C printf '%s' "$admin_password" | wc -c | tr -d '[:space:]'
)
case "$admin_password_bytes" in
    ''|*[!0-9]*)
        echo "Could not determine admin password length" >&2
        exit 1
        ;;
esac
if [ "$admin_password_bytes" -lt 16 ] ||
    [ "$admin_password_bytes" -gt 72 ]; then
    echo "Admin password secret must contain 16 to 72 bytes" >&2
    exit 1
fi

auth_root=/var/lib/estab/auth
auth_file=$auth_root/admin.htpasswd
if [ -L "$auth_root" ]; then
    echo "Admin authentication directory must not be a symbolic link" >&2
    exit 1
fi
install -d -o root -g www-data -m 0750 "$auth_root"

temporary_file=$(mktemp "$auth_root/.admin.htpasswd.XXXXXX")
cleanup()
{
    rm -f -- "$temporary_file"
}
trap cleanup EXIT HUP INT TERM

if ! printf '%s\n' "$admin_password" |
    htpasswd -B -C 12 -c -i "$temporary_file" \
        "$ESTAB_ADMIN_USER" >/dev/null; then
    echo "Could not derive the admin authentication file" >&2
    exit 1
fi
unset admin_password

chown root:www-data "$temporary_file"
chmod 0640 "$temporary_file"
mv -fT -- "$temporary_file" "$auth_file"
temporary_file=
trap - EXIT HUP INT TERM

echo "Admin authentication file initialized"
