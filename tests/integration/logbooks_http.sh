#!/bin/sh
set -eu

base_url=${ESTAB_TEST_BASE_URL:-http://127.0.0.1:8080}
base_url=${base_url%/}
repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
s2_name=${ESTAB_TEST_ETB_NAME:-Logbook Integration S2}
s2_code=${ESTAB_TEST_ETB_CODE:-e2s200}
s2_password=${ESTAB_TEST_ETB_PASSWORD:-Logbook-Test-S2-20260723}
aw_name=${ESTAB_TEST_TBB_NAME:-Logbook Integration A-W}
aw_code=${ESTAB_TEST_TBB_CODE:-e2l001}
aw_password=${ESTAB_TEST_TBB_PASSWORD:-Logbook-Test-AW-20260723}

work_dir=$(mktemp -d /tmp/estab-logbooks-http.XXXXXX)
trap 'rm -rf -- "$work_dir"' EXIT HUP INT TERM
body=$work_dir/body.html
s2_cookies=$work_dir/s2-cookies.txt
aw_cookies=$work_dir/aw-cookies.txt

request_status()
{
    curl --silent --show-error --max-time 20 --connect-timeout 5 \
        --output "$body" --write-out '%{http_code}' "$@"
}

assert_status()
{
    expected=$1
    shift
    actual=$(request_status "$@")
    if [ "$actual" != "$expected" ]; then
        printf 'Logbook HTTP: expected %s, got %s for %s\n' \
            "$expected" "$actual" "$*" >&2
        sed -n '1,100p' "$body" >&2
        exit 1
    fi
}

assert_body()
{
    expected=$1
    if ! grep -Fq -- "$expected" "$body"; then
        printf 'Logbook HTTP: response does not contain %s\n' "$expected" >&2
        sed -n '1,100p' "$body" >&2
        exit 1
    fi
}

assert_body_absent()
{
    forbidden=$1
    if grep -Fq -- "$forbidden" "$body"; then
        printf 'Logbook HTTP: response contains unsafe/unexpected %s\n' "$forbidden" >&2
        sed -n '1,100p' "$body" >&2
        exit 1
    fi
}

assert_no_runtime_error()
{
    if grep -Eq 'Fatal error|Uncaught (Error|TypeError)|Warning:|Deprecated:' "$body"; then
        echo 'Logbook HTTP: PHP runtime error leaked into response' >&2
        sed -n '1,100p' "$body" >&2
        exit 1
    fi
}

assert_session_identity()
{
    expected_name=$1
    expected_code=$2
    expected_function=$3
    expected_role=$4
    bar_count=$(grep -o 'data-estab-session-bar' "$body" | wc -l | tr -d ' ')
    if [ "$bar_count" != 1 ]; then
        printf 'Logbook HTTP: expected one session bar, got %s\n' "$bar_count" >&2
        exit 1
    fi
    for marker in \
        "data-estab-user-name=\"$expected_name\"" \
        "data-estab-user-code=\"$expected_code\"" \
        "data-estab-user-function=\"$expected_function\"" \
        "data-estab-user-role=\"$expected_role\"" \
        'data-estab-logout-form' \
        'target="_top"' \
        '>Abmelden</button>'
    do
        assert_body "$marker"
    done
}

csrf_from_body()
{
    token=$(sed -n \
        's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$token" | grep -Eq '^[a-f0-9]{64}$'; then
        echo 'Logbook HTTP: CSRF token missing' >&2
        sed -n '1,100p' "$body" >&2
        exit 1
    fi
    printf '%s' "$token"
}

active_hat_id_from_body()
{
    awk '
        /name="operation_action"/ {
            saw_action_name = 1
        }
        saw_action_name && /value="select_hat"/ {
            in_select_form = 1
            saw_action_name = 0
        }
        in_select_form && /name="dienstbesetzung_id"/ {
            saw_assignment_name = 1
        }
        saw_assignment_name && match($0, /value="[0-9]+"/) {
            value = substr($0, RSTART, RLENGTH)
            gsub(/[^0-9]/, "", value)
            print value
            exit
        }
        in_select_form && /<\/form>/ {
            in_select_form = 0
            saw_assignment_name = 0
        }
    ' "$body"
}

load_active_hat_context()
{
    select_hat_cookie_jar=$1

    assert_status 200 \
        --cookie "$select_hat_cookie_jar" \
        --cookie-jar "$select_hat_cookie_jar" \
        "$base_url/4fach/fuehrungsstelle.php"
    select_hat_assignment_id=$(active_hat_id_from_body)
    case "$select_hat_assignment_id" in
        '' | 0 | *[!0-9]*)
            echo 'Logbook HTTP: no personal accepted active hat is selectable' >&2
            sed -n '1,160p' "$body" >&2
            exit 1
            ;;
    esac
    select_hat_csrf=$(csrf_from_body)
}

select_active_hat()
{
    select_hat_cookie_jar=$1

    load_active_hat_context "$select_hat_cookie_jar"
    assert_status 303 \
        --cookie "$select_hat_cookie_jar" \
        --cookie-jar "$select_hat_cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$select_hat_csrf" \
        --data-urlencode 'operation_action=select_hat' \
        --data-urlencode "dienstbesetzung_id=$select_hat_assignment_id" \
        "$base_url/4fach/fuehrungsstelle.php"
}

login_user()
{
    cookie_jar=$1
    name=$2
    code=$3
    function_name=$4
    password=$5

    : > "$cookie_jar"
    assert_status 200 \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST --data-urlencode 'login_flow=existing' \
        "$base_url/4fach/mainindex.php"
    assert_body 'name="kennwort1"'
    login_csrf=$(csrf_from_body)

    assert_status 200 \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$login_csrf" \
        --data-urlencode 'login_flow=existing' \
        --data-urlencode "benutzer=$name" \
        --data-urlencode "kuerzel=$code" \
        --data-urlencode "funktion=$function_name" \
        --data-urlencode "kennwort1=$password" \
        --data-urlencode '2teskennwort=No' \
        --data-urlencode 'absenden_x=1' \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error
}

assert_global_incident_header()
{
    cookie_jar=$1
    endpoint=$2

    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/$endpoint"
    assert_body 'data-estab-incident-state="active"'
    assert_body 'data-estab-incident-code="CI-INTEGRATION"'
    assert_body 'CI-INTEGRATION'
    assert_body 'Automatisierter CI-Integrationstest'
    assert_body 'CI-Führungsstelle Nord'
    assert_body_absent 'value="save_title"'
    assert_no_runtime_error
}

ensure_entry()
{
    cookie_jar=$1
    endpoint=$2
    entry_query=$3
    marker=$4
    escaped_markup=$5
    raw_markup=$6

    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/$endpoint"
    if ! grep -Fq -- "$marker" "$body"; then
        assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
            "$base_url/$endpoint?$entry_query"
        assert_body 'value="save_entry"'
        csrf_token=$(csrf_from_body)
        assert_status 303 \
            --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
            --request POST \
            --data-urlencode "csrf_token=$csrf_token" \
            --data-urlencode 'logbook_action=save_entry' \
            --data-urlencode "event=$marker $raw_markup" \
            --data-urlencode 'comment=HTTP-Integration & Ausgabeprüfung' \
            "$base_url/$endpoint"
    fi

    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/$endpoint"
    assert_body "$marker"
    assert_body "$escaped_markup"
    assert_body 'HTTP-Integration &amp; Ausgabeprüfung'
    assert_body_absent "$raw_markup"
    assert_no_runtime_error
}

# Authentication and an exact, personally accepted active function are
# mandatory before logbook data is rendered. Only S2/ETB may write ETB and
# only A/W may write TBB.
assert_status 303 "$base_url/stabetb/etb.php"
assert_status 303 "$base_url/fmtbb/tbb.php"

sh "$repo_root/tests/integration/provision_user.sh" \
    "$s2_name" "$s2_code" S2 "$s2_password"
sh "$repo_root/tests/integration/provision_user.sh" \
    "$aw_name" "$aw_code" A/W "$aw_password"
login_user "$s2_cookies" "$s2_name" "$s2_code" S2 "$s2_password"
load_active_hat_context "$s2_cookies"
s2_assignment_id=$select_hat_assignment_id
s2_unselected_csrf=$select_hat_csrf
s2_unselected_marker="LOGBOOK_ETB_UNSELECTED_$$"
assert_status 423 \
    --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s2_unselected_csrf" \
    --data-urlencode 'logbook_action=save_entry' \
    --data-urlencode "event=$s2_unselected_marker" \
    "$base_url/stabetb/etb.php"
assert_body 'Wählen Sie vor dieser Eingabe eine persönlich angenommene Dienstfunktion aus.'
select_active_hat "$s2_cookies"
if [ "$select_hat_assignment_id" != "$s2_assignment_id" ]; then
    echo 'Logbook HTTP: S2 selected a different assignment than its validated hat' >&2
    exit 1
fi
assert_status 200 --cookie "$s2_cookies" "$base_url/stabetb/etb.php"
assert_body_absent "$s2_unselected_marker"
assert_no_runtime_error

login_user "$aw_cookies" "$aw_name" "$aw_code" A/W "$aw_password"
load_active_hat_context "$aw_cookies"
aw_assignment_id=$select_hat_assignment_id
aw_unselected_csrf=$select_hat_csrf
if [ "$aw_assignment_id" = "$s2_assignment_id" ]; then
    echo 'Logbook HTTP: S2 and A/W duty assignments unexpectedly collide' >&2
    exit 1
fi

# A browser cannot borrow another account's accepted assignment. The failed
# selection must leave this session without an operational hat, so the
# following TTB write remains closed before the endpoint can persist data.
assert_status 403 \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$aw_unselected_csrf" \
    --data-urlencode 'operation_action=select_hat' \
    --data-urlencode "dienstbesetzung_id=$s2_assignment_id" \
    "$base_url/4fach/fuehrungsstelle.php"
aw_unselected_marker="LOGBOOK_TBB_FOREIGN_HAT_$$"
assert_status 423 \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$aw_unselected_csrf" \
    --data-urlencode 'logbook_action=save_entry' \
    --data-urlencode "event=$aw_unselected_marker" \
    "$base_url/fmtbb/tbb.php"
assert_body 'Wählen Sie vor dieser Eingabe eine persönlich angenommene Dienstfunktion aus.'
select_active_hat "$aw_cookies"
if [ "$select_hat_assignment_id" != "$aw_assignment_id" ]; then
    echo 'Logbook HTTP: A/W selected a different assignment than its validated hat' >&2
    exit 1
fi
assert_status 200 --cookie "$aw_cookies" "$base_url/fmtbb/tbb.php"
assert_body_absent "$aw_unselected_marker"
assert_no_runtime_error

assert_status 200 --cookie "$aw_cookies" "$base_url/stabetb/etb.php"
assert_session_identity "$aw_name" "$aw_code" A/W Fernmelder
assert_body_absent 'value="save_title"'
assert_body_absent 'value="save_entry"'
assert_no_runtime_error
assert_status 200 --cookie "$s2_cookies" "$base_url/fmtbb/tbb.php"
assert_session_identity "$s2_name" "$s2_code" S2 Stab
assert_body_absent 'value="save_title"'
assert_body_absent 'value="save_entry"'
assert_no_runtime_error

# Cross-role writes are rejected before CSRF/action processing.
assert_status 403 \
    --cookie "$aw_cookies" --request POST \
    --data-urlencode 'logbook_action=save_entry' \
    --data-urlencode 'event=cross-role-etb-must-not-be-written' \
    "$base_url/stabetb/etb.php"
assert_body_absent 'data-estab-session-bar'
assert_status 403 \
    --cookie "$s2_cookies" --request POST \
    --data-urlencode 'logbook_action=save_entry' \
    --data-urlencode 'event=cross-role-tbb-must-not-be-written' \
    "$base_url/fmtbb/tbb.php"
assert_body_absent 'data-estab-session-bar'

# A write without a session-bound token remains forbidden even for the writer.
assert_status 403 \
    --cookie "$s2_cookies" --request POST \
    --data-urlencode 'logbook_action=save_entry' \
    --data-urlencode 'event=must-not-be-written' \
    "$base_url/stabetb/etb.php"
assert_status 403 \
    --cookie "$aw_cookies" --request POST \
    --data-urlencode 'logbook_action=save_entry' \
    --data-urlencode 'event=must-not-be-written' \
    "$base_url/fmtbb/tbb.php"

# Historic GET write parameters are now inert. On a fresh stack these requests
# must leave the title tables empty; on a rerun they must not alter the title.
assert_status 200 --cookie "$s2_cookies" \
    "$base_url/stabetb/etb.php?absenden_x=1&Einsatzdaten=erfassen&einsatz=GET_WRITE_MUST_FAIL&ort=GET"
assert_body_absent 'GET_WRITE_MUST_FAIL'
assert_status 200 --cookie "$aw_cookies" \
    "$base_url/fmtbb/tbb.php?absenden_x=1&Einsatzdaten=erfassen&einsatz=GET_WRITE_MUST_FAIL&ort=GET"
assert_body_absent 'GET_WRITE_MUST_FAIL'

assert_global_incident_header "$s2_cookies" stabetb/etb.php
assert_global_incident_header "$aw_cookies" fmtbb/tbb.php

# Prove the shared server-side length limit through a real ETB write request.
assert_status 200 --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    "$base_url/stabetb/etb.php?etb_eintrag_x=1"
csrf_token=$(csrf_from_body)
overlong_event=$(awk 'BEGIN { for (i = 0; i < 10001; i++) printf "x" }')
assert_status 422 \
    --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$csrf_token" \
    --data-urlencode 'logbook_action=save_entry' \
    --data-urlencode "event=$overlong_event" \
    --data-urlencode 'comment=length boundary' \
    "$base_url/stabetb/etb.php"
unset overlong_event

ensure_entry \
    "$s2_cookies" stabetb/etb.php etb_eintrag_x=1 \
    'LOGBOOK_ETB_ENTRY_E2E' \
    '&lt;script&gt;alert(&quot;etb&quot;)&lt;/script&gt;' \
    '<script>alert("etb")</script>'
ensure_entry \
    "$aw_cookies" fmtbb/tbb.php tbb_eintrag_x=1 \
    'LOGBOOK_TBB_ENTRY_E2E' \
    '&lt;script&gt;alert(&quot;tbb&quot;)&lt;/script&gt;' \
    '<script>alert("tbb")</script>'

# Cross-role readers see the persisted content but never a write form.
assert_status 200 --cookie "$aw_cookies" "$base_url/stabetb/etb.php"
assert_session_identity "$aw_name" "$aw_code" A/W Fernmelder
assert_body 'LOGBOOK_ETB_ENTRY_E2E'
assert_body_absent 'value="save_title"'
assert_body_absent 'value="save_entry"'
assert_no_runtime_error
assert_status 200 --cookie "$s2_cookies" "$base_url/fmtbb/tbb.php"
assert_session_identity "$s2_name" "$s2_code" S2 Stab
assert_body 'LOGBOOK_TBB_ENTRY_E2E'
assert_body_absent 'value="save_title"'
assert_body_absent 'value="save_entry"'
assert_no_runtime_error

echo 'Logbook HTTP integration: OK'
