#!/bin/sh
set -eu

# This contract changes the global registration policy and is safe only in the
# disposable Compose project created by tests/integration/ci.sh.
if [ "${ESTAB_SELF_REGISTRATION_HTTP_TEST_ALLOW_MUTATION:-false}" != "true" ]; then
    echo 'Self-registration HTTP: mutation is allowed only in disposable CI' >&2
    exit 1
fi

project_name=${COMPOSE_PROJECT_NAME:-estab}
case "$project_name" in
    estab_ci | estab_ci_*) ;;
    *)
        echo 'Self-registration HTTP: refusing mutation outside an estab_ci project' >&2
        exit 1
        ;;
esac

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

base_url=${ESTAB_TEST_BASE_URL:-http://127.0.0.1:8080}
base_url=${base_url%/}
admin_user=${ESTAB_TEST_ADMIN_USER:-}
admin_password=${ESTAB_TEST_ADMIN_PASSWORD:-}
if [ -z "$admin_password" ] && [ -n "${ESTAB_TEST_ADMIN_PASSWORD_FILE:-}" ]; then
    if [ ! -r "$ESTAB_TEST_ADMIN_PASSWORD_FILE" ]; then
        echo 'Self-registration HTTP: admin password file is unreadable' >&2
        exit 1
    fi
    admin_password=$(tr -d '\r\n' <"$ESTAB_TEST_ADMIN_PASSWORD_FILE")
fi
if [ -z "$admin_user" ] || [ -z "$admin_password" ]; then
    echo 'Self-registration HTTP: Basic-Auth credentials are required' >&2
    exit 1
fi

compose_engine=${ESTAB_TEST_COMPOSE_ENGINE:-docker}
case "$compose_engine" in
    docker | podman) ;;
    *)
        echo 'Self-registration HTTP: compose engine must be docker or podman' >&2
        exit 1
        ;;
esac
"$compose_engine" compose version >/dev/null

work_dir=$(mktemp -d /tmp/estab-self-registration-http.XXXXXX)
body=$work_dir/body.html
headers=$work_dir/headers.txt
admin_cookie=$work_dir/admin-cookies.txt
admin_curl_config=$work_dir/admin-curl.conf
test_number=$(printf '%s' "$project_name" | cksum | awk '{print $1}')
case "$test_number" in
    '' | *[!0-9]*)
        echo 'Self-registration HTTP: could not derive a safe fixture number' >&2
        exit 1
        ;;
esac
backup_table="estab_selfreg_http_${test_number}_$$"
case "$backup_table" in
    *[!a-z0-9_]*)
        echo 'Self-registration HTTP: unsafe backup table name' >&2
        exit 1
        ;;
esac

escaped_admin_credentials=$(printf '%s:%s' "$admin_user" "$admin_password" |
    sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')
printf 'user = "%s"\n' "$escaped_admin_credentials" >"$admin_curl_config"
chmod 0600 "$admin_curl_config"
unset admin_password escaped_admin_credentials

db_sql()
{
    "$compose_engine" compose exec -T db sh -ceu '
        umask 077
        client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-selfreg-client.XXXXXX")
        cleanup_client_defaults()
        {
            rm -f -- "$client_defaults"
        }
        trap cleanup_client_defaults EXIT HUP INT TERM

        root_password=$(tr -d "\r\n" </run/secrets/estab_db_root_password)
        escaped_password=$(printf "%s" "$root_password" |
            sed -e "s/\\\\/\\\\\\\\/g" -e "s/\"/\\\\\"/g")
        unset root_password
        {
            printf "[client]\n"
            printf "user=root\n"
            printf "password=\"%s\"\n" "$escaped_password"
            printf "protocol=socket\n"
            printf "default-character-set=utf8mb4\n"
        } >"$client_defaults"
        unset escaped_password
        chmod 0600 "$client_defaults"

        mariadb \
            --defaults-extra-file="$client_defaults" \
            --batch \
            --skip-column-names \
            --raw \
            --database="$MARIADB_DATABASE"
    '
}

backup_created=false
audit_floor=0
cleanup()
{
    status=$?
    trap - EXIT HUP INT TERM
    set +e
    restore_status=0
    if [ "$backup_created" = true ]; then
        db_sql >/dev/null 2>&1 <<SQL || restore_status=$?
START TRANSACTION;
UPDATE \`nv_selbstregistrierung\` AS current_policy
  JOIN \`${backup_table}\` AS original_policy
    ON original_policy.\`singleton_id\` = current_policy.\`singleton_id\`
   SET current_policy.\`mode\` = original_policy.\`mode\`,
       current_policy.\`enabled_until_utc\` = original_policy.\`enabled_until_utc\`,
       current_policy.\`revision\` = original_policy.\`revision\`,
       current_policy.\`updated_at\` = original_policy.\`updated_at\`,
       current_policy.\`updated_by\` = original_policy.\`updated_by\`;
DELETE FROM \`nv_protokoll\`
 WHERE \`p_lfd\` > ${audit_floor}
   AND \`p_was\` = 'Selbstregistrierung';
COMMIT;
DROP TABLE IF EXISTS \`${backup_table}\`;
SQL
    fi
    rm -rf -- "$work_dir"
    if [ "$status" -eq 0 ] && [ "$restore_status" -ne 0 ]; then
        status=$restore_status
        echo 'Self-registration HTTP: could not restore original policy' >&2
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' HUP INT TERM

request_status()
{
    curl --silent --show-error --max-time 20 --connect-timeout 5 \
        --dump-header "$headers" --output "$body" --write-out '%{http_code}' \
        "$@"
}

assert_status()
{
    expected=$1
    shift
    actual=$(request_status "$@")
    if [ "$actual" != "$expected" ]; then
        printf 'Self-registration HTTP: expected %s, got %s for %s\n' \
            "$expected" "$actual" "$*" >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
}

assert_body()
{
    expected=$1
    if ! grep -Fq -- "$expected" "$body"; then
        printf 'Self-registration HTTP: response does not contain %s\n' \
            "$expected" >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
}

assert_body_absent()
{
    unexpected=$1
    if grep -Fq -- "$unexpected" "$body"; then
        printf 'Self-registration HTTP: response unexpectedly contains %s\n' \
            "$unexpected" >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
}

assert_header()
{
    expected=$1
    if ! grep -Fqi -- "$expected" "$headers"; then
        printf 'Self-registration HTTP: response headers omit %s\n' \
            "$expected" >&2
        sed -n '1,80p' "$headers" >&2
        exit 1
    fi
}

csrf_from_body()
{
    token=$(sed -n \
        's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$token" | grep -Eq '^[a-f0-9]{64}$'; then
        echo 'Self-registration HTTP: CSRF token missing' >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
    printf '%s' "$token"
}

revision_from_body()
{
    revision=$(sed -n \
        '/name="expected_revision"/{n;s/.*value="\([0-9][0-9]*\)".*/\1/p;q;}' \
        "$body" | head -n 1)
    case "$revision" in
        '' | *[!0-9]*)
            echo 'Self-registration HTTP: policy revision missing' >&2
            sed -n '1,120p' "$body" >&2
            exit 1
            ;;
    esac
    printf '%s' "$revision"
}

assert_refresh_milliseconds()
{
    refresh_milliseconds=$(sed -n \
        's/.*data-estab-self-registration-refresh-ms="\([0-9][0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    case "$refresh_milliseconds" in
        '' | *[!0-9]*)
            echo 'Self-registration HTTP: expiry refresh delay missing' >&2
            sed -n '1,120p' "$body" >&2
            exit 1
            ;;
    esac
    if [ "$refresh_milliseconds" -lt 1 ] \
        || [ "$refresh_milliseconds" -gt 86401000 ]; then
        printf 'Self-registration HTTP: unsafe expiry refresh delay %s\n' \
            "$refresh_milliseconds" >&2
        exit 1
    fi
}

admin_get()
{
    assert_status 200 \
        --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/self_registration.php"
}

admin_post()
{
    expected_status=$1
    action=$2
    revision=$3
    csrf_token=$4
    duration=${5:-}
    set -- \
        --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$csrf_token" \
        --data-urlencode "admin_action=$action" \
        --data-urlencode "expected_revision=$revision"
    if [ -n "$duration" ]; then
        set -- "$@" --data-urlencode "duration_minutes=$duration"
    fi
    if [ "$action" != disable ]; then
        set -- "$@" --data-urlencode 'confirm_activation=1'
    fi
    assert_status "$expected_status" "$@" \
        "$base_url/4fadm/self_registration.php"
}

# Preserve every field exactly. The trap restores this row and removes only
# policy audits created after the captured floor.
db_sql <<SQL
DROP TABLE IF EXISTS \`${backup_table}\`;
CREATE TABLE \`${backup_table}\` LIKE \`nv_selbstregistrierung\`;
INSERT INTO \`${backup_table}\` SELECT * FROM \`nv_selbstregistrierung\`;
SQL
backup_created=true
audit_floor=$(db_sql <<'SQL'
SELECT COALESCE(MAX(`p_lfd`), 0) FROM `nv_protokoll`;
SQL
)
case "$audit_floor" in
    '' | *[!0-9]*)
        echo 'Self-registration HTTP: invalid audit floor' >&2
        exit 1
        ;;
esac

# Apache Basic Auth and the PHP method/CSRF boundaries are independent.
assert_status 401 "$base_url/4fadm/self_registration.php"
admin_get
assert_body 'data-estab-self-registration-admin'
assert_body 'Befristet aktivieren'
csrf_token=$(csrf_from_body)
revision=$(revision_from_body)

assert_status 405 \
    --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request PUT "$base_url/4fadm/self_registration.php"
assert_header 'Allow: GET, POST'

assert_status 403 \
    --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST \
    --data-urlencode 'admin_action=disable' \
    --data-urlencode "expected_revision=$revision" \
    "$base_url/4fadm/self_registration.php"
assert_body 'Formularsitzung ist ungültig oder abgelaufen'

# Opening public account creation requires an explicit operator confirmation,
# even when Basic Auth, CSRF and revision are otherwise valid.
admin_get
csrf_token=$(csrf_from_body)
revision=$(revision_from_body)
assert_status 422 \
    --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST \
    --data-urlencode "csrf_token=$csrf_token" \
    --data-urlencode 'admin_action=enable_temporary' \
    --data-urlencode "expected_revision=$revision" \
    --data-urlencode 'duration_minutes=15' \
    "$base_url/4fadm/self_registration.php"
assert_body 'kontrollierten Zugang zur Anmeldeseite'

# A timed policy immediately opens the public account-creation entry.
admin_get
csrf_token=$(csrf_from_body)
revision=$(revision_from_body)
admin_post 303 enable_temporary "$revision" "$csrf_token" 15
assert_header 'Location: self_registration.php'
admin_get
assert_body 'Befristet aktiviert'
assert_body 'Automatisches Ende:'
assert_body 'data-estab-self-registration-refresh-ms='
assert_body 'data-estab-self-registration-expiry-refresh'
assert_refresh_milliseconds

# Even a timed-policy POST error must refresh through a new GET. Replaying the
# failed administrative form could otherwise reopen or close registration.
csrf_token=$(csrf_from_body)
admin_post 409 disable "$revision" "$csrf_token"
assert_body 'zwischenzeitlich geändert'
assert_body "window.location.replace('self_registration.php')"
assert_body_absent 'window.location.reload()'
assert_body 'data-estab-self-registration-expiry-refresh'
assert_status 200 "$base_url/"
assert_body 'id="estab-register"'
assert_body 'login_flow=new'

# Immediate disable closes both the root entry and a directly opened form.
admin_get
csrf_token=$(csrf_from_body)
revision=$(revision_from_body)
admin_post 303 disable "$revision" "$csrf_token"
assert_header 'Location: self_registration.php'
admin_get
assert_body_absent 'data-estab-self-registration-refresh-ms='
assert_body_absent 'data-estab-self-registration-expiry-refresh'
assert_status 200 "$base_url/"
assert_body_absent 'id="estab-register"'
assert_body 'Die Selbstregistrierung ist derzeit'
assert_status 200 "$base_url/4fach/mainindex.php?login_flow=new"
assert_body 'Die Selbstregistrierung ist geschlossen.'
assert_body_absent 'name="kennwort1"'

# A permanent policy remains open, while a stale browser revision cannot close
# or overwrite it. The current revision can still disable it afterwards.
admin_get
csrf_token=$(csrf_from_body)
stale_revision=$(revision_from_body)
admin_post 303 enable_permanent "$stale_revision" "$csrf_token"
assert_header 'Location: self_registration.php'
admin_get
assert_body 'Dauerhaft aktiviert'
assert_body_absent 'data-estab-self-registration-refresh-ms='
assert_body_absent 'data-estab-self-registration-expiry-refresh'
current_csrf=$(csrf_from_body)
assert_status 200 "$base_url/"
assert_body 'id="estab-register"'

admin_post 409 disable "$stale_revision" "$current_csrf"
assert_body 'zwischenzeitlich geändert'
assert_status 200 "$base_url/"
assert_body 'id="estab-register"'

admin_get
current_csrf=$(csrf_from_body)
current_revision=$(revision_from_body)
admin_post 303 disable "$current_revision" "$current_csrf"
assert_status 200 "$base_url/"
assert_body_absent 'id="estab-register"'

printf 'Self-registration HTTP: OK\n'
