#!/bin/sh
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

base_url=${ESTAB_TEST_BASE_URL:-http://127.0.0.1:8080}
test_name=${ESTAB_TEST_LOGIN_NAME:-Container Integration}
test_code=${ESTAB_TEST_LOGIN_CODE:-e2e001}
test_code=$(printf '%s' "$test_code" | tr '[:upper:]' '[:lower:]')
test_function=${ESTAB_TEST_LOGIN_FUNCTION:-S1}
test_password=${ESTAB_TEST_LOGIN_PASSWORD:-Integration-Test-Only-20260722}
if [ -n "${ESTAB_TEST_LOGIN_PASSWORD_FILE:-}" ]; then
    if [ ! -r "$ESTAB_TEST_LOGIN_PASSWORD_FILE" ]; then
        printf 'HTTP smoke: login password file is unreadable\n' >&2
        exit 1
    fi
    test_password=$(tr -d '\r\n' <"$ESTAB_TEST_LOGIN_PASSWORD_FILE")
fi
if [ -z "$test_password" ]; then
    printf 'HTTP smoke: login password must not be empty\n' >&2
    exit 1
fi
workflow_marker=${ESTAB_TEST_WORKFLOW_MARKER:-}
restore_verify_only=${ESTAB_TEST_RESTORE_VERIFY_ONLY:-false}
restore_attachment=${ESTAB_TEST_RESTORE_ATTACHMENT:-}
state_file=${ESTAB_TEST_STATE_FILE:-}
compose_engine=${ESTAB_TEST_COMPOSE_ENGINE:-}

case "$test_code" in
    '' | *[!a-z0-9_]*)
        printf 'HTTP smoke: login code must contain only lowercase letters, digits, or underscore\n' >&2
        exit 1
        ;;
esac
if [ "${#test_code}" -gt 6 ]; then
    printf 'HTTP smoke: login code exceeds six characters\n' >&2
    exit 1
fi
case "$compose_engine" in
    docker | podman) ;;
    *)
        printf 'HTTP smoke: ESTAB_TEST_COMPOSE_ENGINE is required and must be docker or podman\n' >&2
        exit 1
        ;;
esac
"$compose_engine" compose version >/dev/null

case "$restore_verify_only" in
    true | false) ;;
    *)
        printf 'HTTP smoke: ESTAB_TEST_RESTORE_VERIFY_ONLY must be true or false\n' >&2
        exit 1
        ;;
esac
if [ -n "$workflow_marker" ] &&
    ! printf '%s' "$workflow_marker" | grep -Eq '^[A-Za-z0-9_:-]{1,120}$'; then
    printf 'HTTP smoke: workflow marker contains unsafe characters\n' >&2
    exit 1
fi
if [ "$restore_verify_only" = true ]; then
    if [ -z "$workflow_marker" ]; then
        printf 'HTTP smoke: restore verification requires a workflow marker\n' >&2
        exit 1
    fi
    if ! printf '%s' "$restore_attachment" |
        grep -Eq '^[A-Za-z]{2}[0-9]{4,}[.][A-Za-z0-9]{1,16}$'; then
        printf 'HTTP smoke: restore verification requires a safe attachment name\n' >&2
        exit 1
    fi
fi

work_dir=$(mktemp -d /tmp/estab-http-smoke.XXXXXX)
trap 'rm -rf -- "$work_dir"' EXIT HUP INT TERM
cookie_jar=$work_dir/cookies.txt
body=$work_dir/body.html
headers=$work_dir/headers.txt
login_password_file=$work_dir/login-password.txt
collision_password_file=$work_dir/collision-password.txt
printf '%s' "$test_password" >"$login_password_file"
collision_password='Collision-Must-Not-Replace-20260728'
if [ "$collision_password" = "$test_password" ]; then
    collision_password='Collision-Must-Not-Replace-Alternative'
fi
printf '%s' "$collision_password" >"$collision_password_file"
chmod 0600 "$login_password_file" "$collision_password_file"

request_status() {
    curl --silent --show-error --max-time 20 --connect-timeout 5 \
        --output "$body" --write-out '%{http_code}' "$@"
}

assert_status() {
    expected=$1
    shift
    actual=$(request_status "$@")
    if [ "$actual" != "$expected" ]; then
        printf 'HTTP smoke: expected %s, got %s for %s\n' "$expected" "$actual" "$*" >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
}

assert_body() {
    pattern=$1
    if ! grep -Fq -- "$pattern" "$body"; then
        printf 'HTTP smoke: response does not contain %s\n' "$pattern" >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
}

assert_body_absent() {
    pattern=$1
    if grep -Fq -- "$pattern" "$body"; then
        printf 'HTTP smoke: response unexpectedly contains %s\n' "$pattern" >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
}

csrf_from_body() {
    token=$(sed -n \
        's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$token" | grep -Eq '^[a-f0-9]{64}$'; then
        printf 'HTTP smoke: pre-authentication CSRF token missing\n' >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
    printf '%s' "$token"
}

db_sql() {
    "$compose_engine" compose exec -T db sh -ceu '
        umask 077
        client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-http-client.XXXXXX")
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

assert_account_count() {
    expected=$1
    account_code=$2
    case "$account_code" in
        '' | *[!a-z0-9_]*) printf 'HTTP smoke: unsafe account code assertion\n' >&2; exit 1 ;;
    esac
    actual=$(printf "SELECT COUNT(*) FROM nv_benutzer WHERE kuerzel = '%s';\n" \
        "$account_code" | db_sql)
    if [ "$actual" != "$expected" ]; then
        printf 'HTTP smoke: expected %s account rows for %s, got %s\n' \
            "$expected" "$account_code" "$actual" >&2
        exit 1
    fi
}

account_password_hex() {
    account_code=$1
    case "$account_code" in
        '' | *[!a-z0-9_]*) printf 'HTTP smoke: unsafe password account code\n' >&2; exit 1 ;;
    esac
    printf "SELECT HEX(password) FROM nv_benutzer WHERE kuerzel = '%s';\n" \
        "$account_code" | db_sql
}

account_assignment() {
    account_code=$1
    case "$account_code" in
        '' | *[!a-z0-9_]*) printf 'HTTP smoke: unsafe assignment account code\n' >&2; exit 1 ;;
    esac
    printf "SELECT funktion, rolle, aktiv FROM nv_benutzer WHERE kuerzel = '%s';\n" \
        "$account_code" | db_sql
}

curl --silent --show-error --fail --max-time 20 --connect-timeout 5 \
    --dump-header "$headers" --output "$body" "$base_url/health.php"
assert_body '"status":"ready"'
for header in X-Content-Type-Options X-Frame-Options Referrer-Policy Content-Security-Policy; do
    if ! grep -qi "^${header}:" "$headers"; then
        printf 'HTTP smoke: security header missing: %s\n' "$header" >&2
        exit 1
    fi
done

assert_status 200 "$base_url/"
assert_body 'Nachrichtenvordruck'
assert_body 'Infosammlung BOS'
assert_body 'id="estab-login"'
assert_body 'href="./4fach/index.php"'
assert_status 200 "$base_url/stabinfo/index.php"
assert_status 200 "$base_url/doku/Handbuch_eStab.pdf"

assert_status 403 "$base_url/app/bootstrap.php"
assert_status 403 "$base_url/4fadm/make_conf.php?task=einsatz_ende"
assert_status 403 "$base_url/4fdata/estab/evil.php"
assert_status 403 "$base_url/4fach/anhang.php"
assert_status 403 "$base_url/4fach/download.php?area=attachment&file=EL0001.txt"
assert_status 403 "$base_url/4fach/showpic.php?file=EL0001.txt"
assert_status 403 "$base_url/4fach/vordrucke.php"
assert_status 403 "$base_url/4fach/nachwea.php?nwalle=1"
assert_status 403 "$base_url/4fueltg/ue_ltg.php"
assert_status 403 "$base_url/stabetb/etb.php"
assert_status 403 "$base_url/fmtbb/tbb.php"
assert_status 410 "$base_url/4fach/upload.php"
assert_status 410 "$base_url/4fach/upload/upload.php"
assert_status 401 "$base_url/4fadm/admin.php"

# Login state must come from POST. First enter the account chooser; creating a
# new account and using an existing one are intentionally separate flows.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode 'login_x=12' \
    --data-urlencode 'login_y=4' \
    "$base_url/4fach/mainindex.php"
assert_body 'Wie möchten Sie fortfahren?'
assert_body_absent 'name="kennwort1"'
preauth_csrf_token=$(csrf_from_body)

if [ "$restore_verify_only" = true ]; then
    assert_body_absent 'name="login_flow" value="new"'
    assert_body 'Neue Konten können hier nicht erstellt werden'

    restore_unknown_code=rno001
    assert_account_count 0 "$restore_unknown_code"
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$preauth_csrf_token" \
        --data-urlencode 'login_flow=new' \
        --data-urlencode 'benutzer=Restore Registrierung blockiert' \
        --data-urlencode "kuerzel=$restore_unknown_code" \
        --data-urlencode "funktion=$test_function" \
        --data-urlencode "kennwort1@$login_password_file" \
        --data-urlencode "kennwort2@$login_password_file" \
        --data-urlencode '2teskennwort=Yes' \
        "$base_url/4fach/mainindex.php"
    assert_body 'Neue Konten können hier nicht erstellt werden'
    assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/4fach/vordrucke.php"
    assert_account_count 0 "$restore_unknown_code"

    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST --data-urlencode 'login_flow=existing' \
        "$base_url/4fach/mainindex.php"
    assert_body 'Mit bestehendem Konto anmelden'
    assert_body 'autocomplete="current-password"'
    assert_body_absent 'name="kennwort2"'
    preauth_csrf_token=$(csrf_from_body)

    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$preauth_csrf_token" \
        --data-urlencode 'login_flow=existing' \
        --data-urlencode "benutzer=$test_name" \
        --data-urlencode "kuerzel=$test_code" \
        --data-urlencode "funktion=$test_function" \
        --data-urlencode "kennwort1@$login_password_file" \
        --data-urlencode '2teskennwort=No' \
        --data-urlencode 'absenden_x=1' \
        "$base_url/4fach/mainindex.php"
    assert_body 'Meldung/Seite:'

    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/4fach/mainindex.php"
    assert_body "$workflow_marker"
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/4fueltg/ue_ltg.php"
    assert_body "$workflow_marker"
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/4fach/download.php?area=attachment&file=$restore_attachment"
    expected_attachment=$work_dir/expected-attachment.txt
    printf '%s\n' "$workflow_marker" >"$expected_attachment"
    if ! cmp -s "$expected_attachment" "$body"; then
        printf 'HTTP smoke: restored attachment content differs\n' >&2
        exit 1
    fi

    printf 'HTTP restore verification: OK\n'
    exit 0
fi

# An unknown code in the existing-account flow must never create an account.
unknown_existing_code=e2n001
assert_account_count 0 "$unknown_existing_code"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'login_flow=existing' \
    "$base_url/4fach/mainindex.php"
assert_body 'Mit bestehendem Konto anmelden'
assert_body_absent 'name="kennwort2"'
preauth_csrf_token=$(csrf_from_body)
# The browser flow never accepts credentials without its session-bound token,
# even when the request claims to be same-origin.
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --header 'Sec-Fetch-Site: same-origin' \
    --request POST \
    --data-urlencode 'login_flow=existing' \
    --data-urlencode 'benutzer=Unbekanntes Bestandskonto' \
    --data-urlencode "kuerzel=$unknown_existing_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode '2teskennwort=No' \
    "$base_url/4fach/mainindex.php"
assert_account_count 0 "$unknown_existing_code"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=existing' \
    --data-urlencode 'benutzer=Unbekanntes Bestandskonto' \
    --data-urlencode "kuerzel=$unknown_existing_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode '2teskennwort=No' \
    "$base_url/4fach/mainindex.php"
assert_body 'stimmen nicht mit einem bestehenden Konto überein'
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_account_count 0 "$unknown_existing_code"

: > "$cookie_jar"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'login_flow=new' \
    "$base_url/4fach/mainindex.php"
assert_body 'Neues Funktionskonto anlegen'
assert_body 'name="kennwort2"'
assert_body 'Kennwort wiederholen'
preauth_csrf_token=$(csrf_from_body)

# A failed confirmation remains in the registration form and creates no
# authenticated session or account.
assert_account_count 0 "$test_code"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=new' \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode 'kennwort2=does-not-match' \
    --data-urlencode '2teskennwort=Yes' \
    "$base_url/4fach/mainindex.php"
assert_body 'Die beiden Kennwörter stimmen nicht überein'
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_account_count 0 "$test_code"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=new' \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode "kennwort2@$login_password_file" \
    --data-urlencode '2teskennwort=Yes' \
    --data-urlencode 'absenden_x=1' \
    "$base_url/4fach/mainindex.php"
assert_body 'Meldung/Seite:'
if grep -Eq 'Fatal error|Uncaught (Error|TypeError)|Warning:' "$body"; then
    printf 'HTTP smoke: PHP runtime error leaked into authenticated response\n' >&2
    exit 1
fi
assert_account_count 1 "$test_code"

# The new-account flow must neither log in an existing code nor replace its
# password. Use a deliberately different password and compare the stored hash
# byte-for-byte when the test runs with its CI Compose binding.
stored_password_before=$(account_password_hex "$test_code")
if [ -z "$stored_password_before" ]; then
    printf 'HTTP smoke: stored password hash is missing before collision test\n' >&2
    exit 1
fi
: > "$cookie_jar"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'login_flow=new' \
    "$base_url/4fach/mainindex.php"
preauth_csrf_token=$(csrf_from_body)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=new' \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$collision_password_file" \
    --data-urlencode "kennwort2@$collision_password_file" \
    --data-urlencode '2teskennwort=Yes' \
    "$base_url/4fach/mainindex.php"
assert_body 'Dieses Kürzel ist bereits vergeben'
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_account_count 1 "$test_code"
stored_password_after=$(account_password_hex "$test_code")
if [ "$stored_password_after" != "$stored_password_before" ]; then
    printf 'HTTP smoke: new-account collision changed the stored password hash\n' >&2
    exit 1
fi

# Historical clients remain able to authenticate an existing account with the
# old one-password request or the old two-password confirmation marker. Neither
# compatibility request changes the explicit semantics used by the new UI.
: > "$cookie_jar"
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --header 'Sec-Fetch-Site: cross-site' \
    --request POST \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    "$base_url/4fach/mainindex.php"
assert_status 403 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

: > "$cookie_jar"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --header 'Sec-Fetch-Site: same-origin' \
    --request POST \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    "$base_url/4fach/mainindex.php"
assert_status 200 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

: > "$cookie_jar"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --header 'Sec-Fetch-Site: same-origin' \
    --request POST \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode "kennwort2@$login_password_file" \
    --data-urlencode '2teskennwort=Yes' \
    "$base_url/4fach/mainindex.php"
assert_status 200 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

# The historical two-password request also retains its original registration
# semantics for direct legacy clients. Prove that this compatibility route
# still obeys the same account lifecycle: create as S1, log out, then reassign
# the now-inactive account to A/W on its next authenticated login.
legacy_registration_code=e2l001
legacy_registration_name='Legacy HTTP Registrierung'
assert_account_count 0 "$legacy_registration_code"
: > "$cookie_jar"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --header 'Sec-Fetch-Site: same-origin' \
    --request POST \
    --data-urlencode "benutzer=$legacy_registration_name" \
    --data-urlencode "kuerzel=$legacy_registration_code" \
    --data-urlencode 'funktion=S1' \
    --data-urlencode "kennwort1@$collision_password_file" \
    --data-urlencode "kennwort2@$collision_password_file" \
    --data-urlencode '2teskennwort=Yes' \
    "$base_url/4fach/mainindex.php"
assert_status 200 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"
assert_account_count 1 "$legacy_registration_code"
legacy_assignment=$(account_assignment "$legacy_registration_code")
if [ "$legacy_assignment" != "$(printf 'S1\tStab\t1')" ]; then
    printf 'HTTP smoke: legacy registration has unexpected assignment: %s\n' \
        "$legacy_assignment" >&2
    exit 1
fi

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vorgaben.php"
logout_csrf_token=$(sed -n \
    's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
    "$body" | head -n 1)
if ! printf '%s' "$logout_csrf_token" | grep -Eq '^[a-f0-9]{64}$'; then
    printf 'HTTP smoke: logout CSRF token missing\n' >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$logout_csrf_token" \
    --data-urlencode 'm2_abmelden_x=1' \
    "$base_url/4fach/mainindex.php"
legacy_assignment=$(account_assignment "$legacy_registration_code")
if [ "$legacy_assignment" != "$(printf 'S1\tStab\t0')" ]; then
    printf 'HTTP smoke: logout did not deactivate legacy account: %s\n' \
        "$legacy_assignment" >&2
    exit 1
fi

: > "$cookie_jar"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'login_flow=existing' \
    "$base_url/4fach/mainindex.php"
preauth_csrf_token=$(csrf_from_body)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=existing' \
    --data-urlencode "benutzer=$legacy_registration_name" \
    --data-urlencode "kuerzel=$legacy_registration_code" \
    --data-urlencode 'funktion=A/W' \
    --data-urlencode "kennwort1@$collision_password_file" \
    --data-urlencode '2teskennwort=No' \
    "$base_url/4fach/mainindex.php"
assert_status 200 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"
legacy_assignment=$(account_assignment "$legacy_registration_code")
if [ "$legacy_assignment" != "$(printf 'A/W\tFernmelder\t1')" ]; then
    printf 'HTTP smoke: inactive account was not reassigned on login: %s\n' \
        "$legacy_assignment" >&2
    exit 1
fi

# An active account may not select a different function and thereby acquire a
# different role while its stored assignment remains unchanged.
case "$test_function" in
    A/W) alternate_function=S1 ;;
    *) alternate_function=A/W ;;
esac
assignment_before=$(account_assignment "$test_code")
: > "$cookie_jar"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'login_flow=existing' \
    "$base_url/4fach/mainindex.php"
preauth_csrf_token=$(csrf_from_body)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=existing' \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$alternate_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode '2teskennwort=No' \
    "$base_url/4fach/mainindex.php"
assert_body 'aktive Konto ist einer anderen Funktion zugeordnet'
assert_status 403 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"
assignment_after=$(account_assignment "$test_code")
if [ "$assignment_after" != "$assignment_before" ]; then
    printf 'HTTP smoke: rejected function change modified stored assignment\n' >&2
    exit 1
fi

# Exercise the visible account-list button. Its opaque identity token may only
# prefill the existing-account form; the password remains mandatory.
: > "$cookie_jar"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'login_flow=existing' \
    "$base_url/4fach/mainindex.php"
assert_body 'Konto auswählen'
assert_body "mit Kürzel $test_code auswählen"
preauth_csrf_token=$(csrf_from_body)
identity_row=$(grep -F "<td>$test_code</td>" "$body" | head -n 1)
identity_token=$(printf '%s\n' "$identity_row" | sed -n \
    's/.*name="login_identity" value="\([^"]*\)".*/\1/p' \
    | head -n 1)
case "$identity_token" in
    '' | *[!A-Za-z0-9_-]*)
        printf 'HTTP smoke: selectable identity token is missing or unsafe\n' >&2
        exit 1
        ;;
esac
if [ "${#identity_token}" -gt 512 ]; then
    printf 'HTTP smoke: selectable identity token is missing or unsafe\n' >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode "login_identity=$identity_token" \
    "$base_url/4fach/mainindex.php"
assert_body 'Mit bestehendem Konto anmelden'
assert_body "name=\"kuerzel\" type=\"text\" value=\"$test_code\""
assert_body "option value=\"$test_function\" selected"
assert_body 'autocomplete="current-password"'
assert_body_absent 'name="kennwort2"'
preauth_csrf_token=$(csrf_from_body)

# The collision password must still fail. The original password then proves a
# fresh login against the unchanged stored hash.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=existing' \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$collision_password_file" \
    --data-urlencode '2teskennwort=No' \
    "$base_url/4fach/mainindex.php"
assert_body 'Name, Kürzel oder Kennwort stimmen nicht'
assert_status 403 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=existing' \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode '2teskennwort=No' \
    --data-urlencode 'absenden_x=1' \
    "$base_url/4fach/mainindex.php"
assert_body 'Meldung/Seite:'

# An authenticated session must log off before another login or account
# creation request can reach the controller.
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'login_flow=new' \
    "$base_url/4fach/mainindex.php"
assert_status 200 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

# Exercise a complete Stab workflow with the maximum supported six-character
# user code: open the form, upload an attachment through its reservation/CSRF
# flow, download it through the authenticated boundary, select it, save a
# message and find the persisted content in the user's list.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'stab_schreiben_x=1' \
    "$base_url/4fach/mainindex.php"
assert_body 'name="task" value="Stab_schreiben"'
assert_body 'name="14_zeichen"'
assert_body 'maxlength="6"'
workflow_csrf_token=$(sed -n 's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' "$body" | head -n 1)
if ! printf '%s' "$workflow_csrf_token" | grep -Eq '^[a-f0-9]{64}$'; then
    printf 'HTTP smoke: workflow CSRF token missing\n' >&2
    exit 1
fi

if [ -z "$workflow_marker" ]; then
    workflow_marker="ESTAB_HTTP_WORKFLOW_$(date +%s)_$$"
fi
upload_file=$work_dir/workflow.txt
printf '%s\n' "$workflow_marker" > "$upload_file"
tactical_time=$(date '+%H%M')

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$workflow_csrf_token" \
    --data-urlencode 'anhang_plus_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '01_medium=' \
    --data-urlencode '01_datum=' \
    --data-urlencode '01_zeichen=' \
    --data-urlencode '05_gegenstelle=' \
    --data-urlencode '06_befweg=' \
    --data-urlencode '06_befwegausw=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=' \
    --data-urlencode '10_anschrift=HTTP-Integrationsempfänger' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_inhalt=$workflow_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=HTTP-Integration' \
    --data-urlencode "14_zeichen=$test_code" \
    --data-urlencode "14_funktion=$test_function" \
    --data-urlencode '15_quitdatum=' \
    --data-urlencode '15_quitzeichen=' \
    --data-urlencode '16_gncopy=' \
    --data-urlencode '17_vermerke=' \
    "$base_url/4fach/mainindex.php"
assert_body 'Liste der'

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/anhang.php?ah_upload_x=1"
assert_body 'Anhang hochladen'
csrf_token=$(sed -n 's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' "$body" | head -n 1)
reserved_name=$(sed -n 's/.*name="fs_nextfilename" value="\([A-Za-z0-9_-][A-Za-z0-9_-]*\)".*/\1/p' "$body" | head -n 1)
upload_timestamp=$(sed -n 's/.*name="fs_timestamp" value="\([A-Za-z0-9][A-Za-z0-9]*\)".*/\1/p' "$body" | head -n 1)
if ! printf '%s' "$csrf_token" | grep -Eq '^[a-f0-9]{64}$'; then
    printf 'HTTP smoke: attachment CSRF token missing\n' >&2
    exit 1
fi
if ! printf '%s' "$reserved_name" | grep -Eq '^[A-Za-z]{2}[0-9]{4,}$'; then
    printf 'HTTP smoke: attachment reservation missing\n' >&2
    exit 1
fi
if ! printf '%s' "$upload_timestamp" | grep -Eq '^[0-9]{6}[A-Za-z]{3}[0-9]{4}$'; then
    printf 'HTTP smoke: attachment timestamp missing\n' >&2
    exit 1
fi

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --form "csrf_token=$csrf_token" \
    --form "fs_nextfilename=$reserved_name" \
    --form "fs_comment=HTTP integration attachment" \
    --form "fs_shortname=$test_code" \
    --form "fs_timestamp=$upload_timestamp" \
    --form 'absenden_x=1' \
    --form "upload=@$upload_file;type=text/plain;filename=workflow.txt" \
    "$base_url/4fach/anhang.php"
stored_attachment=$reserved_name.txt
assert_body "$reserved_name"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers" \
    "$base_url/4fach/download.php?area=attachment&file=$stored_attachment"
if ! cmp -s "$upload_file" "$body"; then
    printf 'HTTP smoke: downloaded attachment content differs\n' >&2
    exit 1
fi
for header in Content-Disposition Content-Security-Policy X-Content-Type-Options; do
    if ! grep -qi "^${header}:" "$headers"; then
        printf 'HTTP smoke: attachment response header missing: %s\n' "$header" >&2
        exit 1
    fi
done

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/anhang.php?ah_auswahl_x=1&lfd_999=$stored_attachment"
assert_body "value=\"$stored_attachment;\""

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$workflow_csrf_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=' \
    --data-urlencode '10_anschrift=HTTP-Integrationsempfänger' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode "12_anhang=$stored_attachment;" \
    --data-urlencode "12_inhalt=$workflow_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=HTTP-Integration' \
    --data-urlencode "14_zeichen=$test_code" \
    --data-urlencode "14_funktion=$test_function" \
    --data-urlencode '17_vermerke=' \
    "$base_url/4fach/mainindex.php"
if grep -Eq 'Fatal error|Uncaught (Error|TypeError)|Warning:' "$body"; then
    printf 'HTTP smoke: PHP runtime error leaked while saving a message\n' >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/mainindex.php"
assert_body "$workflow_marker"

# Raw UTF-8 message storage must preserve punctuation and SQL-shaped text,
# while every HTML list/search reflection remains inert.
security_payload="MSGSEC 'quoted' \"double\" & <script>alert(\"x\")</script> ' OR 1=1 --"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$workflow_csrf_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=' \
    --data-urlencode "10_anschrift=Security ' & Empfänger" \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_inhalt=$security_payload" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=HTTP-Sicherheit' \
    --data-urlencode "14_zeichen=$test_code" \
    --data-urlencode "14_funktion=$test_function" \
    --data-urlencode '17_vermerke=' \
    "$base_url/4fach/mainindex.php"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'flt_find_mask_ein_x=1' \
    "$base_url/4fach/mainindex.php"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode "flt_search=$security_payload" \
    "$base_url/4fach/mainindex.php"
assert_body 'MSGSEC'
assert_body '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;'
assert_body '&amp;'
if grep -F '<script>alert("x")</script>' "$body" >/dev/null; then
    printf 'HTTP smoke: stored message/search payload became executable markup\n' >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'filter_suche_reset=1' \
    "$base_url/4fach/mainindex.php"

# Detail views are POST+CSRF only; a legacy GET cannot read or mutate a row.
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/mainindex.php?stab=meldung&00_lfd=1"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/nachwea.php?nwalle=1"
assert_body 'Nachweisung Eingang / Ausgang'
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fueltg/ue_ltg.php"
assert_body "$workflow_marker"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/stabetb/etb.php"
assert_body 'Einsatzdaten erfassen'

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_body 'Generierte Vordrucke'

admin_password=${ESTAB_TEST_ADMIN_PASSWORD:-}
if [ -z "$admin_password" ] && [ -n "${ESTAB_TEST_ADMIN_PASSWORD_FILE:-}" ]; then
    if [ ! -r "$ESTAB_TEST_ADMIN_PASSWORD_FILE" ]; then
        printf 'HTTP smoke: admin password file is unreadable\n' >&2
        exit 1
    fi
    admin_password=$(tr -d '\r\n' <"$ESTAB_TEST_ADMIN_PASSWORD_FILE")
fi
if [ -n "${ESTAB_TEST_ADMIN_USER:-}" ] && [ -n "$admin_password" ]; then
    admin_curl_config=$work_dir/admin-curl.conf
    escaped_admin_credentials=$(printf '%s:%s' \
        "$ESTAB_TEST_ADMIN_USER" "$admin_password" |
        sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')
    printf 'user = "%s"\n' "$escaped_admin_credentials" >"$admin_curl_config"
    chmod 0600 "$admin_curl_config"
    unset admin_password escaped_admin_credentials

    assert_status 200 --config "$admin_curl_config" \
        "$base_url/4fadm/admin.php"
    assert_body 'Einsatzexport'

    admin_cookie=$work_dir/admin-cookies.txt
    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/export.php"
    csrf_token=$(sed -n 's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' "$body" | head -n 1)
    if ! printf '%s' "$csrf_token" | grep -Eq '^[a-f0-9]{64}$'; then
        printf 'HTTP smoke: export CSRF token missing\n' >&2
        exit 1
    fi
    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST --data-urlencode "csrf_token=$csrf_token" \
        "$base_url/4fadm/export.php"
    assert_body 'Export vollständig.'
fi

if [ -n "$state_file" ]; then
    state_parent=$(dirname -- "$state_file")
    if [ ! -d "$state_parent" ]; then
        printf 'HTTP smoke: state-file parent directory does not exist\n' >&2
        exit 1
    fi
    state_tmp="${state_file}.tmp.$$"
    (
        umask 077
        printf '%s\n%s\n' "$workflow_marker" "$stored_attachment" >"$state_tmp"
    )
    mv -f -- "$state_tmp" "$state_file"
fi

printf 'HTTP smoke: OK\n'
