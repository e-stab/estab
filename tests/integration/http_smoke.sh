#!/bin/sh
set -eu

base_url=${ESTAB_TEST_BASE_URL:-http://127.0.0.1:8080}
test_name=${ESTAB_TEST_LOGIN_NAME:-Container Integration}
test_code=${ESTAB_TEST_LOGIN_CODE:-e2e001}
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
printf '%s' "$test_password" >"$login_password_file"
chmod 0600 "$login_password_file"

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
    if ! grep -q -- "$pattern" "$body"; then
        printf 'HTTP smoke: response does not contain %s\n' "$pattern" >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
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

# Login state must come from POST. First enter the login view, then register or
# authenticate the deterministic integration account.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode 'login_x=12' \
    --data-urlencode 'login_y=4' \
    "$base_url/4fach/mainindex.php"
assert_body 'name="kennwort1"'

if [ "$restore_verify_only" = true ]; then
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
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

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
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

# A second session proves login against the stored password hash, rather than
# merely retaining the registration session.
: > "$cookie_jar"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'login=Anmelden' \
    "$base_url/4fach/mainindex.php"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode '2teskennwort=No' \
    --data-urlencode 'absenden_x=1' \
    "$base_url/4fach/mainindex.php"
assert_body 'Meldung/Seite:'

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
