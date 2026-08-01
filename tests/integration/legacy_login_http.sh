#!/bin/sh

set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

base_url=${ESTAB_TEST_BASE_URL:-http://127.0.0.1:8080}
test_name=${ESTAB_TEST_LOGIN_NAME:-Container Integration}
test_code=${ESTAB_TEST_LOGIN_CODE:-e2e001}
test_code=$(printf '%s' "$test_code" | tr '[:upper:]' '[:lower:]')
test_function=${ESTAB_TEST_LOGIN_FUNCTION:-S1}
test_password=${ESTAB_TEST_LOGIN_PASSWORD:-}
if [ -n "${ESTAB_TEST_LOGIN_PASSWORD_FILE:-}" ]; then
    if [ ! -r "$ESTAB_TEST_LOGIN_PASSWORD_FILE" ]; then
        printf 'Legacy login HTTP: login password file is unreadable\n' >&2
        exit 1
    fi
    test_password=$(tr -d '\r\n' <"$ESTAB_TEST_LOGIN_PASSWORD_FILE")
fi
if [ -z "$test_password" ]; then
    printf 'Legacy login HTTP: login password is required\n' >&2
    exit 1
fi
case "$test_code" in
    '' | *[!a-z0-9_]*)
        printf 'Legacy login HTTP: unsafe login code\n' >&2
        exit 1
        ;;
esac

work_dir=$(mktemp -d /tmp/estab-legacy-login-http.XXXXXX)
cleanup_legacy_login_http() {
    status=$?
    trap - EXIT HUP INT TERM
    rm -rf -- "$work_dir"
    exit "$status"
}
trap cleanup_legacy_login_http EXIT HUP INT TERM

cookie_jar=$work_dir/cookies.txt
body=$work_dir/body.html
headers=$work_dir/headers.txt
password_file=$work_dir/password.txt
printf '%s' "$test_password" >"$password_file"
chmod 0600 "$password_file"
unset test_password

request_status() {
    : >"$headers"
    curl --silent --show-error --max-time 20 --connect-timeout 5 \
        --dump-header "$headers" --output "$body" --write-out '%{http_code}' "$@"
}

assert_status() {
    expected=$1
    shift
    actual=$(request_status "$@")
    if [ "$actual" != "$expected" ]; then
        printf 'Legacy login HTTP: expected %s, got %s for %s\n' \
            "$expected" "$actual" "$*" >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
}

assert_body() {
    pattern=$1
    if ! grep -Fq -- "$pattern" "$body"; then
        printf 'Legacy login HTTP: response does not contain %s\n' "$pattern" >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
}

# Enabling the compatibility switch must never relax the cross-site boundary.
: >"$cookie_jar"
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --header 'Sec-Fetch-Site: cross-site' \
    --request POST \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$password_file" \
    "$base_url/4fach/mainindex.php"
assert_status 303 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

# The narrowly scoped historical one-password request works only after the
# stack has deliberately been recreated with the compatibility switch.
: >"$cookie_jar"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --header 'Sec-Fetch-Site: same-origin' \
    --request POST \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$password_file" \
    "$base_url/4fach/mainindex.php"
assert_body 'data-estab-session-bar'
assert_body "data-estab-user-name=\"$test_name\""
assert_body "data-estab-user-code=\"$test_code\""
assert_body "data-estab-user-function=\"$test_function\""
assert_body 'Der gewählte eStab-Bereich wird geöffnet'
assert_body '4fach/index.php'

# The compatibility switch changes only the pre-authentication CSRF boundary.
# A successful historical login receives exactly the fixed function and role
# stored on the account. Optional access shifts do not assign fachliche rights,
# and no legacy duty assignment is selected or fabricated in the session.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/fuehrungsstelle.php"
assert_body 'data-estab-session-bar'
assert_body "data-estab-user-name=\"$test_name\""
assert_body 'data-estab-dv-operations'
assert_body 'Zugewiesene Funktion'
if grep -Eq 'operation_action[^>]*select_hat|name="dienstbesetzung_id"' \
    "$body"; then
    printf 'Legacy login HTTP: obsolete duty-selection UI is visible\n' >&2
    exit 1
fi

logout_csrf=$(sed -n \
    's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
    "$body" | head -n 1)
if ! printf '%s' "$logout_csrf" | grep -Eq '^[a-f0-9]{64}$'; then
    printf 'Legacy login HTTP: logout CSRF token is missing\n' >&2
    exit 1
fi

# Generated forms are available immediately to the authenticated fixed account
# while the incident is active; a formal duty shift is not a prerequisite.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_body 'Generierte Vordrucke'
assert_body 'data-estab-session-bar'

assert_status 303 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$logout_csrf" \
    --data-urlencode 'logout_action=logout' \
    "$base_url/4fach/logout.php"
assert_status 303 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

printf 'Legacy login HTTP integration: OK\n'
