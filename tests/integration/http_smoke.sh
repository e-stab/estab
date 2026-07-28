#!/bin/sh
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

base_url=${ESTAB_TEST_BASE_URL:-http://127.0.0.1:8080}
expected_app_root=${ESTAB_TEST_EXPECTED_APP_ROOT:-}
if [ -z "$expected_app_root" ]; then
    expected_app_root=${ESTAB_PUBLIC_URL:-/}
    expected_app_root=${expected_app_root%/}
    expected_base_path=${ESTAB_BASE_PATH:-}
    expected_base_path=${expected_base_path#/}
    expected_base_path=${expected_base_path%/}
    if [ -n "$expected_base_path" ]; then
        expected_app_root="$expected_app_root/$expected_base_path"
    fi
else
    expected_app_root=${expected_app_root%/}
fi
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
restore_vordruck=${ESTAB_TEST_RESTORE_VORDRUCK:-}
restore_vordruck_sha256=${ESTAB_TEST_RESTORE_VORDRUCK_SHA256:-}
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
command -v openssl >/dev/null 2>&1 || {
    printf 'HTTP smoke: openssl is required for content verification\n' >&2
    exit 1
}

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
    if ! printf '%s' "$restore_vordruck" |
        grep -Eq '^[A-Za-z0-9_]+ [0-9]+ [EA][.]pdf$'; then
        printf 'HTTP smoke: restore verification requires a safe generated-form name\n' >&2
        exit 1
    fi
    if ! printf '%s' "$restore_vordruck_sha256" |
        grep -Eq '^[a-f0-9]{64}$'; then
        printf 'HTTP smoke: restore verification requires a generated-form checksum\n' >&2
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
    : >"$headers"
    curl --silent --show-error --max-time 20 --connect-timeout 5 \
        --dump-header "$headers" --output "$body" --write-out '%{http_code}' "$@"
}

file_sha256() {
    digest=$(openssl dgst -sha256 -r "$1" | awk '{print $1}')
    if ! printf '%s' "$digest" | grep -Eq '^[a-f0-9]{64}$'; then
        printf 'HTTP smoke: could not calculate SHA-256 for %s\n' "$1" >&2
        exit 1
    fi
    printf '%s' "$digest"
}

assert_pdf_body() {
    pdf_magic=$(od -An -tx1 -N5 "$body" | tr -d ' \n')
    if [ "$pdf_magic" != '255044462d' ]; then
        printf 'HTTP smoke: generated form does not start with PDF magic\n' >&2
        exit 1
    fi
    if ! tail -c 128 "$body" | grep -q '%%EOF'; then
        printf 'HTTP smoke: generated form has no PDF trailer\n' >&2
        exit 1
    fi
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

assert_body_regex() {
    pattern=$1
    description=${2:-$pattern}
    if ! grep -Eq -- "$pattern" "$body"; then
        printf 'HTTP smoke: response does not match %s\n' "$description" >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
}

assert_export_zip() {
    expected_marker=${1:-}
    "$compose_engine" compose run --rm --no-deps -T \
        app php -d auto_prepend_file= -r '
        $expectedMarker = $argv[1] ?? "";
        $bytes = stream_get_contents(STDIN);
        $temporary = tempnam(sys_get_temp_dir(), "estab-export-http-");
        if (
            !is_string($bytes)
            || $bytes === ""
            || $temporary === false
            || file_put_contents($temporary, $bytes) !== strlen($bytes)
        ) {
            fwrite(STDERR, "HTTP smoke: could not stage downloaded ZIP\n");
            exit(1);
        }
        $archive = new ZipArchive();
        if ($archive->open($temporary) !== true) {
            @unlink($temporary);
            fwrite(STDERR, "HTTP smoke: downloaded export is not a ZIP\n");
            exit(1);
        }
        $manifestJson = $archive->getFromName("manifest.json");
        try {
            $manifest = is_string($manifestJson)
                ? json_decode($manifestJson, true, 32, JSON_THROW_ON_ERROR)
                : null;
        } catch (Throwable) {
            $manifest = null;
        }
        if (
            !is_array($manifest)
            || ($manifest["format"] ?? null) !== 1
            || !is_array($manifest["tables"] ?? null)
            || $manifest["tables"] === []
        ) {
            $archive->close();
            @unlink($temporary);
            fwrite(STDERR, "HTTP smoke: export manifest is missing or invalid\n");
            exit(1);
        }
        foreach ($manifest["tables"] as $table) {
            $file = $table["file"] ?? null;
            $sha256 = $table["sha256"] ?? null;
            if (
                !is_string($file)
                || basename($file) !== $file
                || !is_string($sha256)
                || preg_match("/\A[a-f0-9]{64}\z/D", $sha256) !== 1
            ) {
                $archive->close();
                @unlink($temporary);
                fwrite(STDERR, "HTTP smoke: unsafe export manifest entry\n");
                exit(1);
            }
            $csv = $archive->getFromName($file);
            if (!is_string($csv) || !hash_equals($sha256, hash("sha256", $csv))) {
                $archive->close();
                @unlink($temporary);
                fwrite(STDERR, "HTTP smoke: export CSV checksum differs\n");
                exit(1);
            }
        }
        if ($expectedMarker !== "") {
            $csv = $archive->getFromName("nv_nachrichten.csv");
            $stream = fopen("php://temp", "w+b");
            if (
                !is_string($csv)
                || $stream === false
                || fwrite($stream, $csv) !== strlen($csv)
                || !rewind($stream)
            ) {
                $archive->close();
                @unlink($temporary);
                fwrite(STDERR, "HTTP smoke: message export could not be read\n");
                exit(1);
            }
            $headers = fgetcsv($stream, null, ";", "\"", "");
            $contentIndex = is_array($headers)
                ? array_search("12_inhalt", $headers, true)
                : false;
            $found = false;
            if (is_int($contentIndex)) {
                while (($row = fgetcsv($stream, null, ";", "\"", "")) !== false) {
                    if (($row[$contentIndex] ?? null) === $expectedMarker) {
                        $found = true;
                        break;
                    }
                }
            }
            fclose($stream);
            if (!$found) {
                $archive->close();
                @unlink($temporary);
                fwrite(STDERR, "HTTP smoke: exact workflow marker is missing from message export\n");
                exit(1);
            }
        }
        $archive->close();
        @unlink($temporary);
        ' "$expected_marker" <"$body"
}

assert_session_bar() {
    expected_name=$1
    expected_code=$2
    expected_function=$3
    expected_role=$4
    bar_count=$(grep -o 'data-estab-session-bar' "$body" | wc -l | tr -d ' ')
    logout_count=$(grep -o 'data-estab-logout-form' "$body" | wc -l | tr -d ' ')
    if [ "$bar_count" != 1 ] || [ "$logout_count" != 1 ]; then
        printf 'HTTP smoke: expected exactly one session bar/logout form, got %s/%s\n' \
            "$bar_count" "$logout_count" >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
    for marker in \
        'Angemeldet als' \
        "Kürzel $expected_code" \
        "Funktion $expected_function" \
        "Rolle $expected_role" \
        "data-estab-user-name=\"$expected_name\"" \
        "data-estab-user-code=\"$expected_code\"" \
        "data-estab-user-function=\"$expected_function\"" \
        "data-estab-user-role=\"$expected_role\"" \
        'data-estab-logout-form' \
        'method="post"' \
        'target="_top"' \
        'data-estab-navigation' \
        'data-estab-nav-key="overview"' \
        '>Übersicht</span>' \
        '4fach/logout.php' \
        'name="logout_action" value="logout"' \
        '>Abmelden</button>'
    do
        assert_body "$marker"
    done
    csrf_from_body >/dev/null
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

session_cookie_from_jar() {
    jar=$1
    awk -F '\t' '$6 == "PHPSESSID" { value = $7 } END { print value }' "$jar"
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

vordruck_name_for_marker() {
    marker=$1
    if ! printf '%s' "$marker" | grep -Eq '^[A-Za-z0-9_:-]{1,180}$'; then
        printf 'HTTP smoke: unsafe generated-form marker\n' >&2
        exit 1
    fi
    printf "SELECT CONCAT(DATABASE(), ' ', \`04_nummer\`, ' ', \`04_richtung\`, '.pdf') FROM nv_nachrichten WHERE \`12_inhalt\` = '%s' AND \`x01_abschluss\` = 't' AND \`x04_druck\` = 't' ORDER BY \`00_lfd\` DESC LIMIT 1;\n" \
        "$marker" | db_sql
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

logout_audit_count() {
    account_code=$1
    case "$account_code" in
        '' | *[!a-z0-9_]*) printf 'HTTP smoke: unsafe audit account code\n' >&2; exit 1 ;;
    esac
    printf "SELECT COUNT(*) FROM nv_protokoll WHERE p_was = 'Abmelden' AND SUBSTRING_INDEX(SUBSTRING_INDEX(p_ereignis, ';', 2), ';', -1) = '%s';\n" \
        "$account_code" | db_sql
}

logout_audit_reference_count() {
    account_code=$1
    session_id=$2
    case "$account_code" in
        '' | *[!a-z0-9_]*) printf 'HTTP smoke: unsafe audit account code\n' >&2; exit 1 ;;
    esac
    case "$session_id" in
        '' | *[!A-Za-z0-9]*) printf 'HTTP smoke: unsafe audit session ID\n' >&2; exit 1 ;;
    esac
    printf "SELECT COUNT(*) FROM nv_protokoll WHERE p_was = 'Abmelden' AND SUBSTRING_INDEX(SUBSTRING_INDEX(p_ereignis, ';', 2), ';', -1) = '%s' AND SUBSTRING_INDEX(SUBSTRING_INDEX(p_ereignis, ';', 5), ';', -1) = CONCAT('sha256:', SHA2('%s', 256));\n" \
        "$account_code" "$session_id" | db_sql
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
assert_body 'BOS-Info'
assert_body 'id="estab-login"'
assert_body "href=\"$expected_app_root/4fach/index.php?login_flow=existing\""
assert_body '>Mit bestehendem Konto anmelden</a>'
assert_body 'Anmeldung erforderlich'
assert_body 'Separater Administrationszugang'
if [ "$restore_verify_only" = true ]; then
    assert_body_absent 'id="estab-register"'
    assert_body 'Neue Konten können auf dieser Installation nicht selbst angelegt werden'
else
    assert_body 'id="estab-register"'
    assert_body "href=\"$expected_app_root/4fach/index.php?login_flow=new\""
fi
assert_body_absent 'href="./stabetb/etb.php"'
assert_body_absent 'data-estab-session-bar'
assert_body_absent 'data-estab-logout-form'
assert_status 200 "$base_url/stabinfo/index.php"
assert_status 200 "$base_url/doku/Handbuch_eStab.pdf"

assert_status 403 "$base_url/app/bootstrap.php"
assert_status 403 "$base_url/4fadm/make_conf.php?task=einsatz_ende"
assert_status 403 "$base_url/4fdata/estab/evil.php"
assert_status 403 "$base_url/4fach/anhang.php"
assert_status 403 "$base_url/4fach/download.php?area=attachment&file=EL0001.txt"
assert_status 403 "$base_url/4fach/showpic.php?file=EL0001.txt"
assert_status 403 "$base_url/4fach/vordrucke.php"
assert_status 200 "$base_url/4fach/counter.php"
assert_body_absent 'data-estab-session-bar'
assert_status 200 "$base_url/4fach/status.php"
assert_body_absent 'data-estab-session-bar'
assert_status 403 "$base_url/4fach/nachwea.php?nwalle=1"
assert_status 403 "$base_url/4fueltg/ue_ltg.php"
assert_status 403 "$base_url/stabetb/etb.php"
assert_status 403 "$base_url/fmtbb/tbb.php"
assert_status 405 "$base_url/4fach/logout.php"
assert_status 403 --request POST \
    --data-urlencode 'csrf_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    --data-urlencode 'logout_action=logout' \
    "$base_url/4fach/logout.php"
assert_status 410 "$base_url/4fach/upload.php"
assert_status 410 "$base_url/4fach/upload/upload.php"
assert_status 401 "$base_url/4fadm/admin.php"

# Credentials must come from POST. The root menu may safely preselect the
# display-only account flow with GET so both user journeys are direct.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/mainindex.php?login_flow=existing"
assert_body 'Mit bestehendem Konto anmelden'
assert_body 'autocomplete="current-password"'

# The legacy image button still enters the chooser for compatible clients.
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
    restore_role=$(account_assignment "$test_code" | awk -F '	' '{print $2}')
    assert_session_bar "$test_name" "$test_code" "$test_function" "$restore_role"

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

    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/4fach/vordrucke.php"
    assert_body "$restore_vordruck"
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --get \
        --data-urlencode 'area=vordruck' \
        --data-urlencode "file=$restore_vordruck" \
        "$base_url/4fach/download.php"
    assert_pdf_body
    if [ "$(file_sha256 "$body")" != "$restore_vordruck_sha256" ]; then
        printf 'HTTP smoke: restored generated-form checksum differs\n' >&2
        exit 1
    fi

    # Read the logbooks without invoking their mutation-oriented integration
    # helper. Missing restore data must fail here instead of being recreated.
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/stabetb/etb.php"
    assert_body 'LOGBOOK_ETB_E2E'
    assert_body 'LOGBOOK_ETB_ENTRY_E2E'
    assert_session_bar "$test_name" "$test_code" "$test_function" "$restore_role"
    if grep -Eq 'Fatal error|Uncaught (Error|TypeError)|Warning:|Deprecated:' "$body"; then
        printf 'HTTP smoke: PHP runtime error leaked while reading restored ETB state\n' >&2
        exit 1
    fi
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/fmtbb/tbb.php"
    assert_body 'LOGBOOK_TBB_E2E'
    assert_body 'LOGBOOK_TBB_ENTRY_E2E'
    assert_session_bar "$test_name" "$test_code" "$test_function" "$restore_role"
    if grep -Eq 'Fatal error|Uncaught (Error|TypeError)|Warning:|Deprecated:' "$body"; then
        printf 'HTTP smoke: PHP runtime error leaked while reading restored TBB state\n' >&2
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
    "$base_url/4fach/mainindex.php?next=incident-log"
assert_body 'Wie möchten Sie fortfahren?'
assert_body 'name="next" value="incident-log"'
preauth_csrf_token=$(csrf_from_body)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=new' \
    --data-urlencode 'next=incident-log' \
    "$base_url/4fach/mainindex.php"
assert_body 'Neues Funktionskonto anlegen'
assert_body 'name="kennwort2"'
assert_body 'Kennwort wiederholen'
assert_body 'name="next" value="incident-log"'
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
    --data-urlencode 'next=incident-log' \
    "$base_url/4fach/mainindex.php"
assert_body 'Die beiden Kennwörter stimmen nicht überein'
assert_body 'name="next" value="incident-log"'
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
    --data-urlencode 'next=incident-log' \
    "$base_url/4fach/mainindex.php"
assert_body 'Der gewählte eStab-Bereich wird geöffnet'
assert_body "href=\"$expected_app_root/stabetb/etb.php\" target=\"_top\""
assert_body "window.top.location.replace(\"$expected_app_root/stabetb/etb.php\")"
assert_body_absent '//4fach/'
if grep -Eq 'Fatal error|Uncaught (Error|TypeError)|Warning:' "$body"; then
    printf 'HTTP smoke: PHP runtime error leaked into authenticated response\n' >&2
    exit 1
fi
assert_account_count 1 "$test_code"
test_role=$(account_assignment "$test_code" | awk -F '	' '{print $2}')
if [ -z "$test_role" ]; then
    printf 'HTTP smoke: could not determine the authenticated account role\n' >&2
    exit 1
fi
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

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
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vorgaben.php"
assert_session_bar "$legacy_registration_name" "$legacy_registration_code" \
    'A/W' 'Fernmelder'
assert_body 'data-estab-sidebar-status'
assert_body 'data-estab-navigation-mode="sidebar"'
assert_body 'data-estab-presence-state="current"'
assert_body 'data-estab-presence-function="A/W"'
assert_body 'data-estab-sound-toggle'
assert_body 'data-estab-sidebar-audio'
assert_body "src=\"$expected_app_root/4fach/audio/notify_aw.wav\""
for workflow_key in fm_eingang fm_ausgang fm_admin fm_anhang m2_benutzer; do
    assert_body "data-estab-workflow-key=\"$workflow_key\""
done
assert_body_absent 'data-estab-workflow-key="stab_schreiben"'
sidebar_csrf_token=$(csrf_from_body)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vorgaben.php?fragment=status"
assert_body 'data-estab-sidebar-status'
assert_body 'data-estab-presence-function="A/W"'
assert_body 'data-estab-sound-toggle'
assert_body_absent 'data-estab-sidebar-audio'
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$sidebar_csrf_token" \
    --data-urlencode 'fm_eingang_x=1' \
    "$base_url/4fach/mainindex.php"
assert_body 'name="task" value="FM-Eingang_Sichter"'
assert_body 'name="16_11" value="16_11_bl"'
assert_body 'name="16_gncopy" type="radio"'
assert_body 'value="16_12_gn"'

# Reproduce the 0.9.26c attachment regression through the real authenticated
# A/W form. Missing inactive controls such as 06_befweg and an initially empty
# 12_anhang are deliberately not invented here: this request mirrors what a
# browser submits. The blue and green recipient selections, every active
# message value and the sighter note must survive upload and selection in the
# returned form itself.
aw_workflow_csrf_token=$(csrf_from_body)
aw_content_marker="AW_ATTACHMENT_FORM_STATE_$$"
aw_note_marker="AW_ATTACHMENT_NOTE_STATE_$$"
aw_counterpart_marker='AW-GEGENSTELLE-STATE'
aw_transport_marker='AW-BEFOERDERUNG-STATE'
aw_address_marker='AW-ANSCHRIFT-STATE'
aw_sender_marker='AW-ABSENDER-STATE'
aw_author_marker='awz001'
aw_received_at='281915Jul2026'
aw_written_at='1917'
aw_reviewed_at='281918Jul2026'
aw_upload_file=$work_dir/aw-attachment-state.txt
printf '%s\n' "$aw_content_marker" >"$aw_upload_file"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$aw_workflow_csrf_token" \
    --data-urlencode 'anhang_plus_x=1' \
    --data-urlencode 'task=FM-Eingang_Sichter' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode "01_datum=$aw_received_at" \
    --data-urlencode "01_zeichen=$legacy_registration_code" \
    --data-urlencode "05_gegenstelle=$aw_counterpart_marker" \
    --data-urlencode '06_befwegausw=' \
    --data-urlencode '07_durchspruch=S' \
    --data-urlencode "08_befhinweis=$aw_transport_marker" \
    --data-urlencode '08_befhinwausw=Fax' \
    --data-urlencode '09_vorrangstufe=sss' \
    --data-urlencode "10_anschrift=$aw_address_marker" \
    --data-urlencode '11_gesprnotiz=' \
    --data-urlencode "12_inhalt=$aw_content_marker" \
    --data-urlencode "12_abfzeit=$aw_written_at" \
    --data-urlencode "13_abseinheit=$aw_sender_marker" \
    --data-urlencode "14_zeichen=$aw_author_marker" \
    --data-urlencode '14_funktion=A/W' \
    --data-urlencode "15_quitdatum=$aw_reviewed_at" \
    --data-urlencode "15_quitzeichen=$legacy_registration_code" \
    --data-urlencode '16_11=16_11_bl' \
    --data-urlencode '16_gncopy=16_12_gn' \
    --data-urlencode "17_vermerke=$aw_note_marker" \
    "$base_url/4fach/mainindex.php"
assert_body 'Liste der verfügbaren Dateien'
assert_body_absent 'Warning:'

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/anhang.php?ah_upload_x=1"
assert_body 'Anhang hochladen'
assert_session_bar "$legacy_registration_name" "$legacy_registration_code" \
    'A/W' 'Fernmelder'
aw_attachment_csrf_token=$(csrf_from_body)
aw_reserved_name=$(sed -n \
    's/.*name="fs_nextfilename" value="\([A-Za-z0-9_-][A-Za-z0-9_-]*\)".*/\1/p' \
    "$body" | head -n 1)
aw_upload_timestamp=$(sed -n \
    's/.*name="fs_timestamp" value="\([A-Za-z0-9][A-Za-z0-9]*\)".*/\1/p' \
    "$body" | head -n 1)
if ! printf '%s' "$aw_reserved_name" | grep -Eq '^[A-Za-z]{2}[0-9]{4,}$'; then
    printf 'HTTP smoke: A/W attachment reservation missing\n' >&2
    exit 1
fi
if ! printf '%s' "$aw_upload_timestamp" |
    grep -Eq '^[0-9]{6}[A-Za-z]{3}[0-9]{4}$'; then
    printf 'HTTP smoke: A/W attachment timestamp missing\n' >&2
    exit 1
fi

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --form "csrf_token=$aw_attachment_csrf_token" \
    --form "fs_nextfilename=$aw_reserved_name" \
    --form 'fs_comment=A/W form-state integration attachment' \
    --form "fs_shortname=$legacy_registration_code" \
    --form "fs_timestamp=$aw_upload_timestamp" \
    --form 'absenden_x=1' \
    --form "upload=@$aw_upload_file;type=text/plain;filename=aw-state.txt" \
    "$base_url/4fach/anhang.php"
aw_stored_attachment=$aw_reserved_name.txt
assert_body "$aw_reserved_name"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/download.php?area=attachment&file=$aw_stored_attachment"
if ! cmp -s "$aw_upload_file" "$body"; then
    printf 'HTTP smoke: A/W attachment content differs after upload\n' >&2
    exit 1
fi

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/anhang.php?ah_auswahl_x=1&lfd_901=$aw_stored_attachment"
assert_body 'name="task" value="FM-Eingang_Anhang_Sichter"'
assert_body_regex 'name="01_medium" value="Fu" type="radio"[^>]*checked="checked"' \
    'preserved A/W medium'
assert_body "name=\"01_datum\" value=\"$aw_received_at\""
assert_body "name=\"01_zeichen\" value=\"$legacy_registration_code\""
assert_body "name=\"05_gegenstelle\" value=\"$aw_counterpart_marker\""
assert_body_regex 'name="07_durchspruch" value="S" type="radio"[^>]*checked="checked"' \
    'preserved A/W message type'
assert_body "name=\"08_befhinweis\" value=\"$aw_transport_marker\""
assert_body_regex 'name="08_befhinwausw" value="Fax" type="radio"[^>]*checked="checked"' \
    'preserved A/W transport selection'
assert_body 'name="09_vorrangstufe" value="sss"'
assert_body_regex "name=\"10_anschrift\">$aw_address_marker</textarea>" \
    'preserved A/W address'
assert_body 'name="12_inhalt"'
assert_body "$aw_content_marker"
assert_body "name=\"12_anhang\" value=\"$aw_stored_attachment;\""
assert_body "name=\"12_abfzeit\" value=\"$aw_written_at\""
assert_body "name=\"13_abseinheit\" value=\"$aw_sender_marker\""
assert_body "name=\"14_zeichen\" value=\"$aw_author_marker\""
assert_body 'name="14_funktion" value="A/W"'
assert_body "name=\"15_quitdatum\" value=\"$aw_reviewed_at\""
assert_body "name=\"15_quitzeichen\" value=\"$legacy_registration_code\""
assert_body_regex 'name="16_11" value="16_11_bl" type="checkbox"[^>]*checked="checked"' \
    'preserved blue recipient'
assert_body_regex 'name="16_gncopy" type="radio"[^>]*checked="checked"[^>]*value="16_12_gn"' \
    'preserved green-copy recipient'
assert_body_regex "name=\"17_vermerke\"[^>]*>$aw_note_marker</textarea>" \
    'preserved submitted sighter note control'
assert_body_absent 'Warning:'
assert_session_bar "$legacy_registration_name" "$legacy_registration_code" \
    'A/W' 'Fernmelder'

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
preauth_session_id=$(session_cookie_from_jar "$cookie_jar")
if [ -z "$preauth_session_id" ]; then
    printf 'HTTP smoke: pre-authentication session cookie missing\n' >&2
    exit 1
fi

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
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
authenticated_session_id=$(session_cookie_from_jar "$cookie_jar")
authenticated_csrf_token=$(csrf_from_body)
if [ -z "$authenticated_session_id" ] || [ "$authenticated_session_id" = "$preauth_session_id" ]; then
    printf 'HTTP smoke: successful login did not rotate the session cookie\n' >&2
    exit 1
fi
if [ "$authenticated_csrf_token" = "$preauth_csrf_token" ]; then
    printf 'HTTP smoke: successful login did not rotate the CSRF token\n' >&2
    exit 1
fi

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
assert_body 'Liste der verfügbaren Dateien'
assert_body_absent 'Liste der verfÃ¼gbaren Dateien'

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/anhang.php?ah_upload_x=1"
assert_body 'Anhang hochladen'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
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
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

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
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/"
assert_body 'id="estab-open"'
assert_body_absent 'id="estab-login"'
assert_body_absent 'Anmeldung erforderlich'
assert_body 'href="./stabetb/etb.php"'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

# A completed staff conversation note must exercise the real application
# generator, publish a downloadable PDF in the persistent vordruck volume and
# carry that exact byte sequence through the later backup/restore roundtrip.
vordruck_marker="${workflow_marker}_VORDRUCK"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$workflow_csrf_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_gesprnoti' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode '01_datum=' \
    --data-urlencode "01_zeichen=$test_code" \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=' \
    --data-urlencode '10_anschrift=HTTP-Vordruckempfänger' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_inhalt=$vordruck_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=HTTP-Vordrucktest' \
    --data-urlencode "14_zeichen=$test_code" \
    --data-urlencode "14_funktion=$test_function" \
    --data-urlencode '15_quitdatum=' \
    --data-urlencode "15_quitzeichen=$test_code" \
    --data-urlencode '16_gncopy=' \
    --data-urlencode '16_empf=' \
    --data-urlencode '17_vermerke=Backup-Restore-Nachweis' \
    "$base_url/4fach/mainindex.php"
if grep -Eq 'Fatal error|Uncaught (Error|TypeError)|Warning:' "$body"; then
    printf 'HTTP smoke: PHP runtime error leaked while generating a form\n' >&2
    exit 1
fi
stored_vordruck=$(vordruck_name_for_marker "$vordruck_marker")
if ! printf '%s' "$stored_vordruck" |
    grep -Eq '^[A-Za-z0-9_]+ [0-9]+ [EA][.]pdf$'; then
    printf 'HTTP smoke: completed workflow produced no safe generated-form name\n' >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_body "$stored_vordruck"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --get \
    --data-urlencode 'area=vordruck' \
    --data-urlencode "file=$stored_vordruck" \
    "$base_url/4fach/download.php"
assert_pdf_body
for header_pattern in \
    '^Content-Type: application/pdf' \
    '^Content-Disposition: inline;'
do
    if ! grep -Eiq "$header_pattern" "$headers"; then
        printf 'HTTP smoke: generated-form header missing: %s\n' \
            "$header_pattern" >&2
        exit 1
    fi
done
stored_vordruck_sha256=$(file_sha256 "$body")

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
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fueltg/ue_ltg.php"
assert_body "$workflow_marker"
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/stabetb/etb.php"
assert_body 'Einsatzdaten erfassen'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/fmtbb/tbb.php"
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_body 'Generierte Vordrucke'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/stabinfo/l_index.php"
assert_body 'Info-Bereiche'
assert_body 'estab-session-bar-compact'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/info.php?sub=Test&info=Hinweis"
assert_body 'Problembericht'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/language/german/helptext.php?Errorart=01_medium"
assert_body 'Aufnahmevermerk'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vorgaben.php"
assert_body 'estab-session-bar-compact'
assert_body 'data-estab-navigation-mode="sidebar"'
assert_body 'data-estab-sidebar-status'
assert_body 'data-estab-sidebar-refresh'
assert_body 'data-estab-sound-toggle'
assert_body 'data-estab-sidebar-audio'
case "$test_role:$test_function" in
    Fernmelder:*)
        expected_sidebar_sound=notify_aw.wav
        ;;
    Stab:Si)
        expected_sidebar_sound=notify_si.wav
        ;;
    Stab:*|FB:*)
        expected_sidebar_sound=notify_stab.wav
        ;;
    *)
        printf 'HTTP smoke: no sidebar sound mapping for %s/%s\n' \
            "$test_role" "$test_function" >&2
        exit 1
        ;;
esac
assert_body \
    "src=\"$expected_app_root/4fach/audio/$expected_sidebar_sound\""
assert_body 'data-estab-workflow-key="stab_schreiben"'
assert_body 'data-estab-workflow-key="stab_lesen"'
assert_body 'data-estab-workflow-key="m2_benutzer"'
assert_body_absent 'data-estab-workflow-key="fm_eingang"'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
older_logout_csrf=$(csrf_from_body)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vorgaben.php?fragment=status"
assert_body 'data-estab-sidebar-status'
assert_body "data-estab-presence-function=\"$test_function\""
assert_body 'data-estab-sound-toggle'
assert_body_absent 'data-estab-sidebar-audio'
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/status.php"
assert_body 'estab-session-bar-compact'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/counter.php"
assert_body 'estab-session-bar-compact'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
assert_status 200 --cookie "$cookie_jar" \
    "$base_url/4fach/status.php?embedded=1"
assert_body_absent 'data-estab-session-bar'
assert_status 200 --cookie "$cookie_jar" \
    "$base_url/4fach/counter.php?embedded=1"
assert_body_absent 'data-estab-session-bar'

logout_audit_before=$(logout_audit_count "$test_code")
case "$logout_audit_before" in
    '' | *[!0-9]*) printf 'HTTP smoke: invalid initial logout audit count\n' >&2; exit 1 ;;
esac

# A dedicated POST endpoint makes the same logout form reliable in the
# frameset and in standalone tabs. Invalid requests leave both session and
# database state untouched.
assert_status 405 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/logout.php"
assert_status 200 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode 'logout_action=logout' \
    "$base_url/4fach/logout.php"
assert_status 200 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"
case "$older_logout_csrf" in
    0*) wrong_logout_csrf="1${older_logout_csrf#?}" ;;
    *) wrong_logout_csrf="0${older_logout_csrf#?}" ;;
esac
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$wrong_logout_csrf" \
    --data-urlencode 'logout_action=logout' \
    "$base_url/4fach/logout.php"
assert_status 200 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"
if [ "$(account_assignment "$test_code")" != "$(printf '%s\t%s\t1' "$test_function" "$test_role")" ]; then
    printf 'HTTP smoke: rejected logout changed the active account state\n' >&2
    exit 1
fi
if [ "$(logout_audit_count "$test_code")" != "$logout_audit_before" ]; then
    printf 'HTTP smoke: rejected logout wrote an audit event\n' >&2
    exit 1
fi

# A newer login replaces the account's stored SID. Logging out the older
# browser must end only that local session and must not deactivate the newer
# one.
newer_cookie_jar=$work_dir/newer-cookies.txt
assert_status 200 --cookie "$newer_cookie_jar" --cookie-jar "$newer_cookie_jar" \
    --request POST --data-urlencode 'login_flow=existing' \
    "$base_url/4fach/mainindex.php"
newer_preauth_csrf=$(csrf_from_body)
assert_status 200 --cookie "$newer_cookie_jar" --cookie-jar "$newer_cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$newer_preauth_csrf" \
    --data-urlencode 'login_flow=existing' \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode '2teskennwort=No' \
    --data-urlencode 'absenden_x=1' \
    "$base_url/4fach/mainindex.php"
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
newer_logout_csrf=$(csrf_from_body)
newer_authenticated_session_id=$(session_cookie_from_jar "$newer_cookie_jar")
if [ -z "$newer_authenticated_session_id" ]; then
    printf 'HTTP smoke: newer authenticated session cookie missing\n' >&2
    exit 1
fi

assert_status 303 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$older_logout_csrf" \
    --data-urlencode 'logout_action=logout' \
    "$base_url/4fach/logout.php"
if ! grep -Eiq '^Location: .*4fach/index[.]php[[:space:]]*$' "$headers"; then
    printf 'HTTP smoke: logout did not redirect to the application login\n' >&2
    sed -n '1,30p' "$headers" >&2
    exit 1
fi
assert_status 403 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"
assert_status 200 --cookie "$newer_cookie_jar" "$base_url/4fach/vordrucke.php"
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
if [ "$(account_assignment "$test_code")" != "$(printf '%s\t%s\t1' "$test_function" "$test_role")" ]; then
    printf 'HTTP smoke: stale-session logout deactivated the newer login\n' >&2
    exit 1
fi
if [ "$(logout_audit_count "$test_code")" -ne "$((logout_audit_before + 1))" ]; then
    printf 'HTTP smoke: stale-session logout audit event missing\n' >&2
    exit 1
fi
if [ "$(logout_audit_reference_count "$test_code" "$authenticated_session_id")" -ne 1 ]; then
    printf 'HTTP smoke: stale-session logout audit lacks its hashed session reference\n' >&2
    exit 1
fi

assert_status 303 --cookie "$newer_cookie_jar" --cookie-jar "$newer_cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$newer_logout_csrf" \
    --data-urlencode 'logout_action=logout' \
    "$base_url/4fach/logout.php"
assert_status 403 --cookie "$newer_cookie_jar" "$base_url/4fach/vordrucke.php"
if [ "$(account_assignment "$test_code")" != "$(printf '%s\t%s\t0' "$test_function" "$test_role")" ]; then
    printf 'HTTP smoke: current-session logout did not deactivate the account\n' >&2
    exit 1
fi
if [ "$(logout_audit_count "$test_code")" -ne "$((logout_audit_before + 2))" ]; then
    printf 'HTTP smoke: current-session logout audit event missing\n' >&2
    exit 1
fi
if [ "$(logout_audit_reference_count "$test_code" "$newer_authenticated_session_id")" -ne 1 ]; then
    printf 'HTTP smoke: current-session logout audit lacks its hashed session reference\n' >&2
    exit 1
fi
assert_status 200 --cookie "$newer_cookie_jar" "$base_url/"
assert_body 'id="estab-login"'
assert_body_absent 'data-estab-session-bar'

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
    assert_body 'data-estab-public-bar'
    assert_body 'data-estab-navigation'
    assert_body 'Administrationszugang'
    assert_body "data-estab-admin-user=\"$ESTAB_TEST_ADMIN_USER\""
    assert_body 'Kein eStab-Funktionskonto angemeldet'
    assert_body 'data-estab-nav-key="administration" aria-current="page"'
    assert_body_absent 'data-estab-session-bar'

    admin_cookie=$work_dir/admin-cookies.txt
    assert_status 401 \
        "$base_url/4fadm/export.php"
    assert_status 401 \
        "$base_url/4fadm/export.php?action=download&export_id=estab-20260722-120000-aaaaaaaa"

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/export.php"
    assert_body 'data-estab-export-list'
    assert_body 'Vorhandene Exporte'
    assert_body 'Vollständigen Export erstellen'
    assert_body_absent '/var/lib/estab/export'
    csrf_token=$(sed -n 's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' "$body" | head -n 1)
    if ! printf '%s' "$csrf_token" | grep -Eq '^[a-f0-9]{64}$'; then
        printf 'HTTP smoke: export CSRF token missing\n' >&2
        exit 1
    fi
    export_count_before_rejected_create=$(grep -c \
        'data-estab-export-id=' "$body" || true)

    assert_status 403 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode 'admin_action=create_export' \
        --data-urlencode 'csrf_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
        "$base_url/4fadm/export.php"
    assert_body 'Formularsitzung ist ungültig oder abgelaufen'

    assert_status 422 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$csrf_token" \
        --data-urlencode 'admin_action=unknown_export_action' \
        "$base_url/4fadm/export.php"
    assert_body 'Unbekannte administrative Aktion'

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/export.php"
    export_count_after_rejected_create=$(grep -c \
        'data-estab-export-id=' "$body" || true)
    if [ "$export_count_after_rejected_create" \
        -ne "$export_count_before_rejected_create" ]; then
        printf 'HTTP smoke: rejected create request changed export count\n' >&2
        exit 1
    fi
    assert_body_absent 'Der neue Export wurde vollständig erstellt'
    assert_body_absent 'Der ausgewählte Export wurde vollständig gelöscht'

    assert_status 303 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$csrf_token" \
        --data-urlencode 'admin_action=create_export' \
        "$base_url/4fadm/export.php"
    first_export_id=$(sed -n \
        's/^Location: export[.]php?created=\(estab-[0-9][0-9]*-[0-9][0-9]*-[a-f0-9][a-f0-9]*\).*/\1/p' \
        "$headers" | tr -d '\r' | head -n 1)
    if ! printf '%s' "$first_export_id" |
        grep -Eq '^estab-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$'; then
        printf 'HTTP smoke: first export redirect contains no safe run ID\n' >&2
        sed -n '1,30p' "$headers" >&2
        exit 1
    fi

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/export.php?created=$first_export_id"
    assert_body 'Der neue Export wurde vollständig erstellt'
    assert_body "data-estab-export-id=\"$first_export_id\""
    assert_body "action=download&amp;export_id=$first_export_id"
    assert_body 'data-estab-export-delete'
    assert_body 'Inhalt und Prüfsummen anzeigen'
    assert_body_absent '/var/lib/estab/export'

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/export.php?created=$first_export_id"
    assert_body_absent 'Der neue Export wurde vollständig erstellt'
    assert_body_absent 'estab-export-card-new'

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/export.php?action=download&export_id=$first_export_id"
    for header_pattern in \
        '^Content-Type: application/zip' \
        "^Content-Disposition: attachment; filename=\"$first_export_id[.]zip\"" \
        '^Cache-Control: private, no-store, max-age=0' \
        '^X-Content-Type-Options: nosniff'
    do
        if ! grep -Eiq "$header_pattern" "$headers"; then
            printf 'HTTP smoke: export download header missing: %s\n' \
                "$header_pattern" >&2
            sed -n '1,40p' "$headers" >&2
            exit 1
        fi
    done
    export_content_length=$(sed -n \
        's/^Content-Length: \([0-9][0-9]*\).*/\1/p' "$headers" |
        tr -d '\r' | head -n 1)
    export_download_size=$(wc -c <"$body" | tr -d ' ')
    if [ "$export_content_length" != "$export_download_size" ]; then
        printf 'HTTP smoke: export Content-Length differs from body size\n' >&2
        exit 1
    fi
    export_magic=$(od -An -tx1 -N4 "$body" | tr -d ' \n')
    if [ "$export_magic" != '504b0304' ]; then
        printf 'HTTP smoke: export download does not start with ZIP magic\n' >&2
        exit 1
    fi
    assert_export_zip "$workflow_marker"

    assert_status 400 --config "$admin_curl_config" \
        "$base_url/4fadm/export.php?action=download&export_id=..%2Fescape"
    assert_status 400 --config "$admin_curl_config" \
        "$base_url/4fadm/export.php?action=download&export_id%5B%5D=$first_export_id"
    assert_status 404 --config "$admin_curl_config" \
        "$base_url/4fadm/export.php?action=download&export_id=estab-19990101-000000-00000000"
    assert_status 405 --config "$admin_curl_config" \
        --request POST \
        "$base_url/4fadm/export.php?action=download&export_id=$first_export_id"

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/export.php?admin_action=delete_export&export_id=$first_export_id"
    assert_body "data-estab-export-id=\"$first_export_id\""

    assert_status 403 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode 'csrf_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
        --data-urlencode 'admin_action=delete_export' \
        --data-urlencode "export_id=$first_export_id" \
        "$base_url/4fadm/export.php"
    assert_body 'Formularsitzung ist ungültig oder abgelaufen'
    assert_status 200 --config "$admin_curl_config" \
        "$base_url/4fadm/export.php?action=download&export_id=$first_export_id"

    assert_status 422 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$csrf_token" \
        --data-urlencode 'admin_action=delete_export' \
        --data-urlencode 'export_id=../escape' \
        "$base_url/4fadm/export.php"
    assert_status 404 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$csrf_token" \
        --data-urlencode 'admin_action=delete_export' \
        --data-urlencode 'export_id=estab-19990101-000000-00000000' \
        "$base_url/4fadm/export.php"

    assert_status 303 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$csrf_token" \
        --data-urlencode 'admin_action=create_export' \
        "$base_url/4fadm/export.php"
    second_export_id=$(sed -n \
        's/^Location: export[.]php?created=\(estab-[0-9][0-9]*-[0-9][0-9]*-[a-f0-9][a-f0-9]*\).*/\1/p' \
        "$headers" | tr -d '\r' | head -n 1)
    if ! printf '%s' "$second_export_id" |
        grep -Eq '^estab-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$'; then
        printf 'HTTP smoke: second export redirect contains no safe run ID\n' >&2
        exit 1
    fi
    if [ "$second_export_id" = "$first_export_id" ]; then
        printf 'HTTP smoke: two export runs received the same identifier\n' >&2
        exit 1
    fi

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/export.php?deleted=$second_export_id"
    assert_body_absent 'Der ausgewählte Export wurde vollständig gelöscht'
    assert_body "data-estab-export-id=\"$second_export_id\""

    assert_status 200 --config "$admin_curl_config" \
        "$base_url/4fadm/export.php?action=download&export_id=$second_export_id"
    assert_export_zip "$workflow_marker"
    survivor_zip=$work_dir/survivor-export.zip
    cp "$body" "$survivor_zip"

    assert_status 303 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$csrf_token" \
        --data-urlencode 'admin_action=delete_export' \
        --data-urlencode "export_id=$first_export_id" \
        "$base_url/4fadm/export.php"
    if ! grep -Eiq "^Location: export[.]php[?]deleted=$first_export_id" "$headers"; then
        printf 'HTTP smoke: delete redirect does not identify the selected export\n' >&2
        exit 1
    fi

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/export.php?deleted=$first_export_id"
    assert_body 'Der ausgewählte Export wurde vollständig gelöscht'
    assert_body_absent "data-estab-export-id=\"$first_export_id\""
    assert_body "data-estab-export-id=\"$second_export_id\""
    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/export.php?deleted=$first_export_id"
    assert_body_absent 'Der ausgewählte Export wurde vollständig gelöscht'
    assert_status 404 --config "$admin_curl_config" \
        "$base_url/4fadm/export.php?action=download&export_id=$first_export_id"
    assert_status 200 --config "$admin_curl_config" \
        "$base_url/4fadm/export.php?action=download&export_id=$second_export_id"
    if ! cmp -s "$survivor_zip" "$body"; then
        printf 'HTTP smoke: deleting one export changed the survivor ZIP\n' >&2
        exit 1
    fi
    survivor_export_sha256=$(file_sha256 "$survivor_zip")
fi

if [ -n "$state_file" ]; then
    state_parent=$(dirname -- "$state_file")
    if [ ! -d "$state_parent" ]; then
        printf 'HTTP smoke: state-file parent directory does not exist\n' >&2
        exit 1
    fi
    if ! printf '%s' "${second_export_id:-}" |
        grep -Eq '^estab-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$' ||
        ! printf '%s' "${survivor_export_sha256:-}" |
            grep -Eq '^[a-f0-9]{64}$'; then
        printf 'HTTP smoke: state persistence requires a verified survivor export\n' >&2
        exit 1
    fi
    state_tmp="${state_file}.tmp.$$"
    (
        umask 077
        printf '%s\n%s\n%s\n%s\n%s\n%s\n' \
            "$workflow_marker" \
            "$stored_attachment" \
            "$stored_vordruck" \
            "$stored_vordruck_sha256" \
            "$second_export_id" \
            "$survivor_export_sha256" >"$state_tmp"
    )
    mv -f -- "$state_tmp" "$state_file"
fi

printf 'HTTP smoke: OK\n'
