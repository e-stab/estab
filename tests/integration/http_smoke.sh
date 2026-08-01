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
        grep -Eq '^[A-Za-z0-9_]+ Einsatz-[1-9][0-9]* [1-9][0-9]* [EA][.]pdf$'; then
        printf 'HTTP smoke: restore verification requires a safe generated-form name\n' >&2
        exit 1
    fi
    if ! printf '%s' "$restore_vordruck_sha256" |
        grep -Eq '^[a-f0-9]{64}$'; then
        printf 'HTTP smoke: restore verification requires a generated-form checksum\n' >&2
        exit 1
    fi
fi

attachment_fixture_bytes() {
    fixture_action=$1
    fixture_name=$2
    case "$fixture_action" in
        tamper | restore) ;;
        *)
            printf 'HTTP smoke: invalid attachment fixture action\n' >&2
            return 1
            ;;
    esac
    if ! printf '%s' "$fixture_name" |
        grep -Eq '^[A-Za-z0-9_-]{2,238}[.][A-Za-z0-9]{1,16}$'; then
        printf 'HTTP smoke: unsafe attachment fixture name\n' >&2
        return 1
    fi
    "$compose_engine" compose exec -T --user www-data app \
        php -d auto_prepend_file= -r '
        $action = $argv[1] ?? "";
        $filename = $argv[2] ?? "";
        require "/var/www/html/app/file_access.php";
        $database = getenv("ESTAB_DB_NAME");
        if (!is_string($database) || $database === "") {
            $database = "estab";
        }
        $root = "/var/www/html/4fdata/" . $database . "/anhang";
        $path = estab_file_resolve($root, "attachment", $filename);
        $backup = sys_get_temp_dir() . "/estab-http-attachment-"
            . hash("sha256", $filename) . ".backup";

        if ($action === "tamper") {
            if (is_file($backup)) {
                fwrite(STDERR, "Attachment tamper backup already exists\n");
                exit(2);
            }
            $stream = fopen($path, "r+b");
            if ($stream === false || !flock($stream, LOCK_EX)) {
                fwrite(STDERR, "Could not lock attachment fixture\n");
                exit(3);
            }
            try {
                $bytes = stream_get_contents($stream);
                if (!is_string($bytes) || $bytes === "") {
                    throw new RuntimeException("Attachment fixture is empty");
                }
                if (
                    file_put_contents($backup, $bytes, LOCK_EX)
                        !== strlen($bytes)
                    || !chmod($backup, 0600)
                ) {
                    throw new RuntimeException(
                        "Could not back up attachment fixture"
                    );
                }
                $bytes[0] = chr(ord($bytes[0]) ^ 1);
                if (
                    !rewind($stream)
                    || !ftruncate($stream, 0)
                    || fwrite($stream, $bytes) !== strlen($bytes)
                    || !fflush($stream)
                    || (int) fstat($stream)["size"] !== strlen($bytes)
                ) {
                    throw new RuntimeException(
                        "Could not tamper attachment fixture"
                    );
                }
            } finally {
                flock($stream, LOCK_UN);
                fclose($stream);
            }
            exit(0);
        }

        if ($action !== "restore" || !is_file($backup)) {
            fwrite(STDERR, "Attachment tamper backup is unavailable\n");
            exit(4);
        }
        $bytes = file_get_contents($backup);
        $stream = fopen($path, "r+b");
        if (
            !is_string($bytes)
            || $stream === false
            || !flock($stream, LOCK_EX)
        ) {
            fwrite(STDERR, "Could not open attachment fixture restore\n");
            exit(5);
        }
        try {
            if (
                !rewind($stream)
                || !ftruncate($stream, 0)
                || fwrite($stream, $bytes) !== strlen($bytes)
                || !fflush($stream)
                || (int) fstat($stream)["size"] !== strlen($bytes)
            ) {
                throw new RuntimeException(
                    "Could not restore attachment fixture"
                );
            }
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
        if (!unlink($backup)) {
            fwrite(STDERR, "Could not remove attachment fixture backup\n");
            exit(6);
        }
    ' "$fixture_action" "$fixture_name"
}

work_dir=$(mktemp -d /tmp/estab-http-smoke.XXXXXX)
readiness_schema_renamed=0
readiness_message_order_changed=0
conversation_matrix_fixture_changed=0
conversation_matrix_original_rc2=f
conversation_matrix_original_auto=f
tampered_attachment=
cleanup_http_smoke() {
    status=$?
    trap - EXIT HUP INT TERM
    if [ -n "$tampered_attachment" ]; then
        attachment_fixture_bytes restore "$tampered_attachment" \
            >/dev/null 2>&1 || {
                printf '%s\n' \
                    'HTTP smoke: emergency attachment restore failed' >&2
                status=1
            }
        tampered_attachment=
    fi
    if [ "$readiness_schema_renamed" -eq 1 ]; then
        printf '%s\n' \
            'RENAME TABLE estab_readiness_probe_matrix TO nv_empfmtx_standard;' |
            db_sql >/dev/null 2>&1 || {
                printf 'HTTP smoke: emergency readiness-schema restore failed\n' >&2
                status=1
            }
    fi
    if [ "$readiness_message_order_changed" -eq 1 ]; then
        printf '%s\n' \
            'ALTER TABLE nv_nachrichten MODIFY COLUMN `12_anhang` TEXT NULL AFTER `12_betreff`;' |
            db_sql >/dev/null 2>&1 || {
                printf '%s\n' \
                    'HTTP smoke: emergency message-column order restore failed' >&2
                status=1
            }
    fi
    if [ "$conversation_matrix_fixture_changed" -eq 1 ]; then
        printf '%s\n' \
            "UPDATE nv_empfmtx
                SET mtx_typ = 't', mtx_fkt = '', mtx_rolle = '',
                    mtx_mode = 'ro',
                    mtx_rc2 = '$conversation_matrix_original_rc2',
                    mtx_auto = '$conversation_matrix_original_auto'
              WHERE mtx_x = 5 AND mtx_y = 4;" |
            db_sql >/dev/null 2>&1 || {
                printf '%s\n' \
                    'HTTP smoke: emergency recipient-matrix restore failed' >&2
                status=1
            }
    fi
    rm -rf -- "$work_dir"
    exit "$status"
}
trap cleanup_http_smoke EXIT HUP INT TERM
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

assert_header_fixed() {
    expected=$1
    if ! grep -Fqi -- "$expected" "$headers"; then
        printf 'HTTP smoke: response headers do not contain %s\n' "$expected" >&2
        sed -n '1,30p' "$headers" >&2
        exit 1
    fi
}

assert_header_regex() {
    expected=$1
    description=$2
    if ! grep -Eiq -- "$expected" "$headers"; then
        printf 'HTTP smoke: response headers do not match %s\n' \
            "$description" >&2
        sed -n '1,30p' "$headers" >&2
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
    expected_command_post=${2:-}
    "$compose_engine" compose run --rm --no-deps -T \
        app php -d auto_prepend_file= -r '
        $expectedMarker = $argv[1] ?? "";
        $expectedCommandPost = $argv[2] ?? "";
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
        $attachmentIntegrity = $manifest["attachment_integrity"] ?? null;
        if (
            !is_array($attachmentIntegrity)
            || ($attachmentIntegrity["scheme"] ?? null)
                !== "sha256-ingest-v1"
            || ($attachmentIntegrity["files_checked"] ?? null) !== true
            || !is_int($attachmentIntegrity["total"] ?? null)
            || !is_int($attachmentIntegrity["verified"] ?? null)
            || !is_int(
                $attachmentIntegrity["legacy_unverifiable"] ?? null
            )
            || ($attachmentIntegrity["integrity_errors"] ?? null) !== 0
            || ($attachmentIntegrity["statement"] ?? null)
                !== "Integrität beim Eingang nicht belegbar"
            || $attachmentIntegrity["verified"]
                + $attachmentIntegrity["legacy_unverifiable"]
                !== $attachmentIntegrity["total"]
        ) {
            $archive->close();
            @unlink($temporary);
            fwrite(
                STDERR,
                "HTTP smoke: attachment integrity manifest is invalid\n"
            );
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
        if ($expectedCommandPost !== "") {
            $csv = $archive->getFromName("nv_einsaetze.csv");
            $stream = fopen("php://temp", "w+b");
            if (
                !is_string($csv)
                || $stream === false
                || fwrite($stream, $csv) !== strlen($csv)
                || !rewind($stream)
            ) {
                $archive->close();
                @unlink($temporary);
                fwrite(STDERR, "HTTP smoke: incident export could not be read\n");
                exit(1);
            }
            $headers = fgetcsv($stream, null, ";", "\"", "");
            $identifierIndex = is_array($headers)
                ? array_search("kennung", $headers, true)
                : false;
            $commandPostIndex = is_array($headers)
                ? array_search("fuehrungsstellenname", $headers, true)
                : false;
            $found = false;
            if (is_int($identifierIndex) && is_int($commandPostIndex)) {
                while (($row = fgetcsv($stream, null, ";", "\"", "")) !== false) {
                    if (
                        ($row[$identifierIndex] ?? null) === "CI-INTEGRATION"
                        && ($row[$commandPostIndex] ?? null)
                            === $expectedCommandPost
                    ) {
                        $found = true;
                        break;
                    }
                }
            }
            fclose($stream);
            if (!$found) {
                $archive->close();
                @unlink($temporary);
                fwrite(
                    STDERR,
                    "HTTP smoke: incident command-post name is missing from export\n"
                );
                exit(1);
            }
        }
        $archive->close();
        @unlink($temporary);
        ' "$expected_marker" "$expected_command_post" <"$body"
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

recipient_matrix_revision_from_body() {
    revision=$(sed -n \
        's/.*name="recipient_matrix_revision" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$revision" | grep -Eq '^[a-f0-9]{64}$'; then
        printf 'HTTP smoke: recipient-matrix revision missing\n' >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
    printf '%s' "$revision"
}

message_attachment_request_token_from_body() {
    request_token=$(sed -n \
        's/.*name="message_attachment_request_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$request_token" | grep -Eq '^[a-f0-9]{64}$'; then
        printf 'HTTP smoke: direct attachment request token missing\n' >&2
        sed -n '1,100p' "$body" >&2
        exit 1
    fi
    printf '%s' "$request_token"
}

shift_confirmation_version_from_body() {
    version=$(sed -n \
        's/.*name="expected_confirmation_version"[[:space:]]*value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$version" | grep -Eq '^[a-f0-9]{64}$'; then
        printf 'HTTP smoke: shift confirmation version missing\n' >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
    printf '%s' "$version"
}

attachment_flow_from_body() {
    flow_token=$(sed -n \
        's/.*name="attachment_flow" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$flow_token" | grep -Eq '^[a-f0-9]{32}$'; then
        printf 'HTTP smoke: attachment flow token missing\n' >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
    printf '%s' "$flow_token"
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
    printf "SELECT CONCAT(DATABASE(), ' Einsatz-', \`einsatz_id\`, ' ', \`04_nummer\`, ' ', \`04_richtung\`, '.pdf') FROM nv_nachrichten WHERE \`12_inhalt\` = '%s' AND \`x01_abschluss\` = 't' AND \`x04_druck\` = 't' ORDER BY \`00_lfd\` DESC LIMIT 1;\n" \
        "$marker" | db_sql
}

stage_stale_vordruck_archive() {
    archive_name=$1
    if ! printf '%s' "$archive_name" |
        grep -Eq '^[A-Za-z0-9_]+ Einsatz-[1-9][0-9]* [1-9][0-9]* [EA][.]pdf$'; then
        printf 'HTTP smoke: unsafe stale generated-form fixture name\n' >&2
        exit 1
    fi
    "$compose_engine" compose exec -T --user www-data app \
        php -d auto_prepend_file= -r '
        $filename = $argv[1] ?? "";
        require "/var/www/html/4fbak/backup_pdf.php";
        $pdf = new FPDF("P", "mm", "A4");
        $pdf->SetCompression(false);
        $pdf->SetTitle("eStab stale generated-form regression fixture");
        $pdf->AddPage();
        $pdf->SetFont("Arial", "B", 16);
        $pdf->Text(20, 30, "ARCHIVE-ONLY-VS-NfD");
        $document = $pdf->Output("", "S");
        $database = getenv("ESTAB_DB_NAME");
        if (!is_string($database) || $database === "") {
            $database = "estab";
        }
        estab_generated_form_publish(
            "/var/www/html/4fdata/" . $database . "/vordruck",
            $filename,
            $document
        );
    ' "$archive_name"
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

active_attachment_reservation_count() {
    printf '%s\n' \
        "SELECT COUNT(*) FROM nv_anhang WHERE status = 8 AND einsatz_id = (SELECT active_einsatz_id FROM nv_einsatz_status WHERE singleton_id = 1);" |
        db_sql
}

assert_uploaded_attachment() {
    reservation=$1
    expected_code=$2
    expected_extension=${3:-txt}
    expected_md5=${4:-}
    if ! printf '%s' "$reservation" | grep -Eq '^[A-Za-z]{2}[0-9]{4,}$'; then
        printf 'HTTP smoke: unsafe attachment reservation assertion\n' >&2
        exit 1
    fi
    case "$expected_code" in
        '' | *[!a-z0-9_]*)
            printf 'HTTP smoke: unsafe attachment account assertion\n' >&2
            exit 1
            ;;
    esac
    case "$expected_extension" in
        '' | *[!a-z0-9]*)
            printf 'HTTP smoke: unsafe attachment extension assertion\n' >&2
            exit 1
            ;;
    esac
    if [ -n "$expected_md5" ] &&
        ! printf '%s' "$expected_md5" | grep -Eq '^[a-f0-9]{32}$'; then
        printf 'HTTP smoke: unsafe attachment digest assertion\n' >&2
        exit 1
    fi
    matching_rows=$(
        printf "SELECT COUNT(*) FROM nv_anhang AS a JOIN nv_einsatz_status AS s ON s.singleton_id = 1 AND s.active_einsatz_id = a.einsatz_id WHERE BINARY a.filename = BINARY '%s' AND a.status = 1 AND BINARY a.fileext = BINARY '%s' AND BINARY a.kuerzel = BINARY '%s' AND ('%s' = '' OR BINARY a.md5hash = BINARY '%s');\n" \
            "$reservation" "$expected_extension" "$expected_code" \
            "$expected_md5" "$expected_md5" |
            db_sql
    )
    if [ "$matching_rows" != 1 ]; then
        printf 'HTTP smoke: uploaded attachment was not finalized for its active incident and account\n' >&2
        exit 1
    fi
    if [ "$(active_attachment_reservation_count)" != 0 ]; then
        printf 'HTTP smoke: successful attachment upload left an active reservation\n' >&2
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

account_activity_timestamp() {
    account_code=$1
    case "$account_code" in
        '' | *[!a-z0-9_]*) printf 'HTTP smoke: unsafe activity account code\n' >&2; exit 1 ;;
    esac
    printf "SELECT DATE_FORMAT(estab_letzte_aktivitaet, '%%Y-%%m-%%d %%H:%%i:%%s.%%f') FROM nv_benutzer WHERE kuerzel = '%s';\n" \
        "$account_code" | db_sql
}

account_activity_is_recent() {
    account_code=$1
    case "$account_code" in
        '' | *[!a-z0-9_]*) printf 'HTTP smoke: unsafe activity account code\n' >&2; exit 1 ;;
    esac
    printf "SELECT IF(estab_letzte_aktivitaet BETWEEN UTC_TIMESTAMP(6) - INTERVAL 30 SECOND AND UTC_TIMESTAMP(6), 1, 0) FROM nv_benutzer WHERE kuerzel = '%s';\n" \
        "$account_code" | db_sql
}

account_session_storage() {
    account_code=$1
    case "$account_code" in
        '' | *[!a-z0-9_]*) printf 'HTTP smoke: unsafe session account code\n' >&2; exit 1 ;;
    esac
    printf "SELECT CONCAT(aktiv, '|', IF(sid = '', 'empty', 'set')) FROM nv_benutzer WHERE kuerzel = '%s';\n" \
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
assert_body 'href="./handbuch/"'
assert_body_absent 'id="estab-register"'
assert_body 'Neue Konten können auf dieser Installation nicht selbst angelegt werden'
assert_body 'Administration → Benutzerverwaltung'
assert_body_absent 'href="./stabetb/etb.php"'
assert_body_absent 'data-estab-session-bar'
assert_body_absent 'data-estab-logout-form'
assert_status 200 "$base_url/stabinfo/index.php"
assert_status 200 "$base_url/handbuch/"
assert_header_fixed 'Content-Type: text/html; charset=UTF-8'
assert_header_fixed 'Cache-Control: private, no-store, max-age=0'
assert_body '<title>eStab Web-Handbuch</title>'
assert_body 'data-estab-handbook-search'
assert_body 'data-estab-handbook-toc'
assert_body 'href="./handbuch.css"'
assert_body 'src="./handbuch.js"'
assert_body 'data-estab-public-bar'
assert_body 'data-estab-nav-key="handbook" aria-current="page"'
assert_body_absent 'data-estab-session-bar'
assert_body_absent 'data-estab-logout-form'
assert_status 200 "$base_url/handbuch/handbuch.css"
assert_header_regex \
    '^Content-Type:[[:space:]]*text/css([;[:space:]]|$)' \
    'the handbook CSS content type'
assert_body '.estab-handbook-layout'
assert_body '@media (max-width: 48rem)'
assert_status 200 "$base_url/handbuch/handbuch.js"
assert_header_regex \
    '^Content-Type:[[:space:]]*(text|application)/(javascript|x-javascript)([;[:space:]]|$)' \
    'the handbook JavaScript content type'
assert_body "document.querySelector('[data-estab-handbook-search]')"
assert_body 'section.hidden = !matches'
assert_body 'status.textContent ='
assert_body_absent 'innerHTML'
assert_body_absent 'eval('
assert_status 404 "$base_url/doku/Handbuch_eStab.pdf"

assert_status 403 "$base_url/app/bootstrap.php"
assert_status 403 "$base_url/4fadm/make_conf.php?task=einsatz_ende"
assert_status 403 "$base_url/4fdata/estab/evil.php"
assert_status 403 "$base_url/4fach/showpic.php?file=EL0001.txt"
assert_status 403 "$base_url/4fach/counter.php"
assert_status 403 "$base_url/4fach/status.php"
for protected_route in \
    '4fach/fuehrungsstelle.php|command-post|index.php' \
    '4fach/vordrucke.php|forms|index.php' \
    '4fueltg/ue_ltg.php|message-overview|index.php' \
    'stabetb/etb.php|incident-log|index.php' \
    'fmtbb/tbb.php|technical-log|index.php' \
    '4fach/nachwea.php?nwalle=1|tracking|index.php' \
    '4fach/anhang.php|messages|mainindex.php' \
    '4fach/katgoedt.php?dbtyp=fkt&msgno=1|messages|mainindex.php'
do
    route=${protected_route%%|*}
    protected_target=${protected_route#*|}
    destination=${protected_target%%|*}
    login_document=${protected_target#*|}
    assert_status 303 "$base_url/$route"
    assert_header_fixed \
        "Location: $expected_app_root/4fach/$login_document?login_flow=existing&next=$destination"
    assert_body_absent 'Anmeldung erforderlich'
done
assert_status 303 \
    "$base_url/4fach/download.php?area=attachment&file=EL0001.txt"
assert_header_fixed \
    "Location: $expected_app_root/4fach/index.php?login_flow=existing&next=messages"
assert_body_absent 'Anmeldung erforderlich'
assert_status 405 "$base_url/4fach/logout.php"
assert_status 303 --request POST \
    --data-urlencode 'csrf_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    --data-urlencode 'logout_action=logout' \
    "$base_url/4fach/logout.php"
assert_header_fixed "Location: $expected_app_root/"
assert_status 405 "$base_url/4fach/activity.php"
assert_status 401 --request POST \
    --data-urlencode 'csrf_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    "$base_url/4fach/activity.php"
assert_status 410 "$base_url/4fach/upload.php"
assert_status 410 "$base_url/4fach/upload/upload.php"
assert_status 401 "$base_url/4fadm/admin.php"
assert_status 401 "$base_url/4fadm/password_policy.php"

# Credentials must come from POST. The root menu may safely preselect the
# display-only account flow with GET so both user journeys are direct.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/mainindex.php?login_flow=existing"
assert_body 'Mit bestehendem Konto anmelden'
assert_body 'autocomplete="current-password"'
assert_body 'data-estab-auth-cancel'
assert_body '>Anmeldung abbrechen · Zur Übersicht</a>'

# The legacy image button still enters the chooser for compatible clients.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode 'login_x=12' \
    --data-urlencode 'login_y=4' \
    "$base_url/4fach/mainindex.php"
assert_body 'Wie möchten Sie fortfahren?'
assert_body_absent 'name="kennwort1"'
preauth_csrf_token=$(csrf_from_body)

# Public account creation is disabled in the production-default stack. Even a
# complete, CSRF-valid compatibility request must remain anonymous and leave
# the database untouched.
assert_body_absent 'name="login_flow" value="new"'
assert_body 'Neue Konten können hier nicht erstellt werden'
assert_body 'Administration → Benutzerverwaltung'
disabled_registration_code=rno001
assert_account_count 0 "$disabled_registration_code"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=new' \
    --data-urlencode 'benutzer=Öffentliche Registrierung blockiert' \
    --data-urlencode "kuerzel=$disabled_registration_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode "kennwort2@$login_password_file" \
    --data-urlencode '2teskennwort=Yes' \
    "$base_url/4fach/mainindex.php"
assert_body 'Neue Konten können hier nicht erstellt werden'
assert_status 303 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_account_count 0 "$disabled_registration_code"

if [ "$restore_verify_only" = true ]; then
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
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/4fach/mainindex.php"
    assert_body 'Meldung/Seite:'
    restore_role=$(account_assignment "$test_code" | awk -F '	' '{print $2}')
    assert_session_bar "$test_name" "$test_code" "$test_function" "$restore_role"

    assert_body "$workflow_marker"
    # The restored fixed S1 account may read its own workflow object but does
    # not acquire S2 Lage-/Dokumentationsrechte merely because the export was
    # restored. No legacy duty assignment is required after the restore.
    assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/4fueltg/ue_ltg.php"
    assert_body_absent "$workflow_marker"
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
    assert_body 'layout=current'
    assert_body 'PDF im aktuellen Layout öffnen'
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --get \
        --data-urlencode 'area=vordruck' \
        --data-urlencode "file=$restore_vordruck" \
        "$base_url/4fach/download.php"
    assert_pdf_body
    assert_body 'ARCHIVE-ONLY-VS-NfD'
    if [ "$(file_sha256 "$body")" != "$restore_vordruck_sha256" ]; then
        printf 'HTTP smoke: restored generated-form checksum differs\n' >&2
        exit 1
    fi
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --get \
        --data-urlencode 'area=vordruck' \
        --data-urlencode "file=$restore_vordruck" \
        --data-urlencode 'layout=current' \
        "$base_url/4fach/download.php"
    assert_pdf_body
    assert_body_absent 'ARCHIVE-ONLY-VS-NfD'
    if ! grep -Eiq '^X-eStab-PDF-Layout: current' "$headers"; then
        printf 'HTTP smoke: restored current-layout PDF marker missing\n' >&2
        exit 1
    fi

    # Read the logbooks without invoking their mutation-oriented integration
    # helper. Missing restore data must fail here instead of being recreated.
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/stabetb/etb.php"
    assert_body 'data-estab-incident-code="CI-INTEGRATION"'
    assert_body 'Automatisierter CI-Integrationstest'
    assert_body 'CI-Führungsstelle Nord'
    assert_body_absent 'value="save_title"'
    assert_body 'LOGBOOK_ETB_ENTRY_E2E'
    assert_session_bar "$test_name" "$test_code" "$test_function" "$restore_role"
    if grep -Eq 'Fatal error|Uncaught (Error|TypeError)|Warning:|Deprecated:' "$body"; then
        printf 'HTTP smoke: PHP runtime error leaked while reading restored ETB state\n' >&2
        exit 1
    fi
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/fmtbb/tbb.php"
    assert_body 'data-estab-incident-code="CI-INTEGRATION"'
    assert_body 'Automatisierter CI-Integrationstest'
    assert_body 'CI-Führungsstelle Nord'
    assert_body_absent 'value="save_title"'
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
assert_status 303 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_account_count 0 "$unknown_existing_code"

# Provision the primary fixture through the administrative domain boundary,
# then prove the normal existing-account flow retains its requested target.
sh tests/integration/provision_user.sh \
    "$test_name" "$test_code" "$test_function" "$test_password"
: > "$cookie_jar"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/mainindex.php?next=incident-log"
assert_body 'Wie möchten Sie fortfahren?'
assert_body 'name="next" value="incident-log"'
preauth_csrf_token=$(csrf_from_body)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=existing' \
    --data-urlencode 'next=incident-log' \
    "$base_url/4fach/mainindex.php"
assert_body 'Mit bestehendem Konto anmelden'
assert_body 'autocomplete="current-password"'
assert_body_absent 'name="kennwort2"'
assert_body 'name="next" value="incident-log"'
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
    --data-urlencode 'next=incident-log' \
    "$base_url/4fach/mainindex.php"
assert_body 'Der gewählte eStab-Bereich wird geöffnet'
assert_body "href=\"$expected_app_root/stabetb/etb.php\" target=\"_top\""
assert_body \
    "window.top.location.replace(\"$expected_app_root/stabetb/etb.php\")"
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
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/stabetb/etb.php"
assert_body 'data-estab-incident-code="CI-INTEGRATION"'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/fuehrungsstelle.php"
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
assert_body 'Zugewiesene Funktion'
assert_body_absent 'name="dienstbesetzung_id"'
assert_body_absent 'value="select_hat"'

# Disabled public registration must neither log in an existing code nor
# replace its password hash.
stored_password_before=$(account_password_hex "$test_code")
if [ -z "$stored_password_before" ]; then
    printf 'HTTP smoke: stored password hash is missing before registration gate test\n' >&2
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
assert_body 'Neue Konten können hier nicht erstellt werden'
assert_status 303 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_account_count 1 "$test_code"
stored_password_after=$(account_password_hex "$test_code")
if [ "$stored_password_after" != "$stored_password_before" ]; then
    printf 'HTTP smoke: disabled registration changed the stored password hash\n' >&2
    exit 1
fi

# The production-default stack rejects every tokenless credential request,
# including requests that claim to be same-origin. The explicit compatibility
# opt-in is exercised separately after this complete default-mode run.
: > "$cookie_jar"
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --header 'Sec-Fetch-Site: cross-site' \
    --request POST \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    "$base_url/4fach/mainindex.php"
assert_status 303 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

: > "$cookie_jar"
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --header 'Sec-Fetch-Site: same-origin' \
    --request POST \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    "$base_url/4fach/mainindex.php"
assert_status 303 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

: > "$cookie_jar"
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --header 'Sec-Fetch-Site: same-origin' \
    --request POST \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode "kennwort2@$login_password_file" \
    --data-urlencode '2teskennwort=Yes' \
    "$base_url/4fach/mainindex.php"
assert_status 303 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

# A separately provisioned account keeps its administrative assignment across
# logout; the login request can never repurpose the inactive row.
legacy_registration_code=${ESTAB_TEST_TBB_CODE:-e2l001}
legacy_registration_name=${ESTAB_TEST_TBB_NAME:-Logbook Integration A-W}
assert_account_count 0 "$legacy_registration_code"
sh tests/integration/provision_user.sh \
    "$legacy_registration_name" "$legacy_registration_code" A/W \
    "$collision_password"

# A path appended after an executable PHP filename is never an authorization
# boundary. Prove this with the exact historical lock-reset action while the
# valid A/W account lacks the administrative capability: Apache must reject
# the request and the locked message must remain byte-for-byte in its stage.
guard_cookie_jar=$work_dir/path-info-guard-cookies.txt
assert_status 200 --cookie "$guard_cookie_jar" \
    --cookie-jar "$guard_cookie_jar" \
    --request POST --data-urlencode 'login_flow=existing' \
    "$base_url/4fach/mainindex.php"
preauth_csrf_token=$(csrf_from_body)
assert_status 200 --cookie "$guard_cookie_jar" \
    --cookie-jar "$guard_cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$preauth_csrf_token" \
    --data-urlencode 'login_flow=existing' \
    --data-urlencode "benutzer=$legacy_registration_name" \
    --data-urlencode "kuerzel=$legacy_registration_code" \
    --data-urlencode 'funktion=A/W' \
    --data-urlencode "kennwort1@$collision_password_file" \
    --data-urlencode '2teskennwort=No' \
    "$base_url/4fach/mainindex.php"
path_info_csrf_token=$(csrf_from_body)
path_info_marker="PATH_INFO_LOCK_GUARD_$$"
path_info_record_id=$(
    printf '%s\n' \
        "SET @estab_path_info_incident = (SELECT active_einsatz_id FROM nv_einsatz_status WHERE singleton_id = 1); INSERT INTO nv_nachrichten (einsatz_id, \`04_richtung\`, \`12_inhalt\`, \`x00_status\`, \`x02_sperre\`, \`x03_sperruser\`) VALUES (@estab_path_info_incident, 'E', '${path_info_marker}', 1, 't', '${legacy_registration_code}'); SET @estab_path_info_incident = NULL; SELECT LAST_INSERT_ID();" |
        db_sql |
        tail -n 1
)
case "$path_info_record_id" in
    '' | 0 | *[!0-9]*)
        printf 'HTTP smoke: path-info lock fixture was not created\n' >&2
        exit 1
        ;;
esac
assert_status 403 --cookie "$guard_cookie_jar" \
    --cookie-jar "$guard_cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$path_info_csrf_token" \
    --data-urlencode "reset_record=$path_info_record_id" \
    "$base_url/4fach/mainindex.php/4fadm"
path_info_lock_state=$(
    printf "SELECT CONCAT(HEX(\`x02_sperre\`), '|', \`x03_sperruser\`, '|', \`x00_status\`, '|', \`04_richtung\`) FROM nv_nachrichten WHERE \`00_lfd\` = %s AND \`12_inhalt\` = '%s';\n" \
        "$path_info_record_id" "$path_info_marker" |
        db_sql
)
if [ "$path_info_lock_state" != "74|$legacy_registration_code|1|E" ]; then
    printf 'HTTP smoke: PHP path-info changed the guarded message lock: %s\n' \
        "$path_info_lock_state" >&2
    exit 1
fi
printf "DELETE FROM nv_nachrichten WHERE \`00_lfd\` = %s AND \`12_inhalt\` = '%s';\n" \
    "$path_info_record_id" "$path_info_marker" |
    db_sql >/dev/null

# Provision the fixed account functions used by this and the following HTTP
# suites. An active incident is the only operational lifecycle prerequisite;
# neither login nor fachliche writes require a formal legacy duty shift.
account_s2_name=${ESTAB_TEST_ETB_NAME:-Logbook Integration S2}
account_s2_code=${ESTAB_TEST_ETB_CODE:-e2s200}
account_si_name=${ESTAB_TEST_CATEGORY_SI_NAME:-Category Integration Si}
account_si_code=${ESTAB_TEST_CATEGORY_SI_CODE:-e2si00}
account_s6_code=${ESTAB_TEST_HTTP_S6_CODE:-e2s600}
account_ldf_code=${ESTAB_TEST_HTTP_LDF_CODE:-e2ldf0}
sh tests/integration/provision_user.sh \
    "$account_s2_name" \
    "$account_s2_code" S2 \
    "${ESTAB_TEST_ETB_PASSWORD:-Logbook-Test-S2-20260723}"
sh tests/integration/provision_user.sh \
    "$account_si_name" \
    "$account_si_code" Si \
    "${ESTAB_TEST_CATEGORY_SI_PASSWORD:-Category-Test-Si-20260723}"
sh tests/integration/provision_user.sh \
    'HTTP Integration S6' "$account_s6_code" S6 \
    'HTTP-Account-S6-Only-20260730'
sh tests/integration/provision_user.sh \
    'HTTP Integration LdF' "$account_ldf_code" LdF \
    'HTTP-Account-LdF-Only-20260730'

formal_shift_count=$(printf '%s\n' \
    "SELECT COUNT(*) FROM nv_dienstschichten WHERE einsatz_id = (SELECT active_einsatz_id FROM nv_einsatz_status WHERE singleton_id = 1);" |
    db_sql | tr -d '\r\n')
if [ "$formal_shift_count" != 0 ]; then
    printf 'HTTP smoke: fresh operational flow unexpectedly has %s formal duty shifts\n' \
        "$formal_shift_count" >&2
    exit 1
fi
aw_access_membership_count=$(printf '%s\n' \
    "SELECT COUNT(*) FROM nv_zugangsschicht_mitglieder WHERE BINARY benutzer_kuerzel = BINARY '${legacy_registration_code}' AND entfernt_am IS NULL;" |
    db_sql | tr -d '\r\n')
if [ "$aw_access_membership_count" != 0 ]; then
    printf 'HTTP smoke: unassigned A/W access fixture has %s memberships\n' \
        "$aw_access_membership_count" >&2
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
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/fuehrungsstelle.php"
assert_account_count 1 "$legacy_registration_code"
legacy_assignment=$(account_assignment "$legacy_registration_code")
if [ "$legacy_assignment" != "$(printf 'A/W\tFernmelder\t1')" ]; then
    printf 'HTTP smoke: provisioned account has unexpected assignment: %s\n' \
        "$legacy_assignment" >&2
    exit 1
fi
assert_body 'Zugewiesene Funktion'
assert_body_absent 'name="dienstbesetzung_id"'
assert_body_absent 'value="select_hat"'

# The fixed A/W account can write in the active incident while it is completely
# unassigned to both optional access shifts and historical duty shifts.
fixed_account_write_marker="FIXED-ACCOUNT-WRITE-$$"
fixed_account_write_before=$(
    printf "SELECT COUNT(*) FROM nv_tbb WHERE estab_operations = '%s' AND estab_shift_id IS NULL AND estab_writer_assignment_id IS NULL;\n" \
        "$fixed_account_write_marker" |
        db_sql
)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/fmtbb/tbb.php?tbb_eintrag_x=1"
fixed_account_write_csrf=$(csrf_from_body)
assert_body 'value="save_entry"'
assert_status 303 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$fixed_account_write_csrf" \
    --data-urlencode 'logbook_action=save_entry' \
    --data-urlencode 'entry_type=betriebsereignis' \
    --data-urlencode "operations=$fixed_account_write_marker" \
    --data-urlencode 'comment=Feste Kontofunktion ohne Schichtauswahl' \
    "$base_url/fmtbb/tbb.php"
fixed_account_write_after=$(
    printf "SELECT COUNT(*) FROM nv_tbb WHERE estab_operations = '%s' AND estab_shift_id IS NULL AND estab_writer_assignment_id IS NULL;\n" \
        "$fixed_account_write_marker" |
        db_sql
)
if [ "$fixed_account_write_after" -ne "$((fixed_account_write_before + 1))" ]; then
    printf 'HTTP smoke: fixed A/W account did not write TBB without a shift: %s -> %s\n' \
        "$fixed_account_write_before" "$fixed_account_write_after" >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

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
if [ "$legacy_assignment" != "$(printf 'A/W\tFernmelder\t0')" ]; then
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
    --data-urlencode 'funktion=S1' \
    --data-urlencode "kennwort1@$collision_password_file" \
    --data-urlencode '2teskennwort=No' \
    "$base_url/4fach/mainindex.php"
assert_body 'administrativ zugewiesene Funktion'
assert_status 303 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"
legacy_assignment=$(account_assignment "$legacy_registration_code")
if [ "$legacy_assignment" != "$(printf 'A/W\tFernmelder\t0')" ]; then
    printf 'HTTP smoke: inactive account changed its assignment on login: %s\n' \
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
    printf 'HTTP smoke: account did not reactivate its assigned function: %s\n' \
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
assert_body 'name="task" value="FM-Eingang"'
assert_body_absent 'name="15_quitzeichen"'
assert_body_absent 'name="16_gncopy"'

# Reproduce the 0.9.26c attachment regression through the real authenticated
# A/W form. Missing inactive controls such as 06_befweg and an initially empty
# 12_anhang are deliberately not invented here: this request mirrors what a
# browser submits. The blue and green recipient selections, every active
# writable message value and the sighter note must survive upload and selection
# in the returned form itself. The incoming sender deliberately has no request
# field: only LdF translates the received callsign into that value.
aw_workflow_csrf_token=$(csrf_from_body)
aw_recipient_matrix_revision=$(recipient_matrix_revision_from_body)
aw_attachment_request_token=$(
    message_attachment_request_token_from_body
)
aw_content_marker="AW_ATTACHMENT_FORM_STATE_$$"
aw_note_marker="AW_ATTACHMENT_NOTE_STATE_$$"
aw_counterpart_marker='AW-GEGENSTELLE-STATE'
aw_transport_marker='AW-BEFOERDERUNG-STATE'
aw_address_marker='AW-ANSCHRIFT-STATE'
aw_phone_marker='+49 711 123456'
aw_subject_marker='AW-EINGANG-BETREFF'
aw_author_marker='awz001'
aw_received_at='281915Jul2026'
aw_written_at='1917'
aw_reviewed_at='281918Jul2026'
aw_upload_file=$work_dir/aw-large-valid.jpeg
cp "$repo_root/4fach/design/HS/null.jpg" "$aw_upload_file"
dd if=/dev/zero bs=1048576 count=6 >>"$aw_upload_file" 2>/dev/null
aw_upload_size=$(wc -c <"$aw_upload_file" | tr -d '[:space:]')
if [ "$aw_upload_size" -le 5242880 ] || [ "$aw_upload_size" -ge 20971520 ]; then
    printf 'HTTP smoke: generated JPEG does not prove the raised upload limit\n' >&2
    exit 1
fi
aw_upload_md5=$(openssl dgst -md5 -r "$aw_upload_file" | awk '{print $1}')

# The primary workflow now uploads directly inside the Nachrichtenvordruck.
# It must retain every unsaved field, automatically bind the server-generated
# reference and show authorized metadata without visiting anhang.php.
direct_aw_upload_file=$repo_root/4fach/design/HS/null.jpg
direct_aw_upload_md5=$(openssl dgst -md5 -r "$direct_aw_upload_file" |
    awk '{print $1}')
direct_aw_comment='Direkt & <img src=x onerror=alert(1)>'
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --form "csrf_token=$aw_workflow_csrf_token" \
    --form \
        "recipient_matrix_revision=$aw_recipient_matrix_revision" \
    --form \
        "message_attachment_request_token=$aw_attachment_request_token" \
    --form 'message_attachment_upload_x=1' \
    --form "message_attachment_comment=$direct_aw_comment" \
    --form 'task=FM-Eingang' \
    --form '01_medium=Fu' \
    --form "01_datum=$aw_received_at" \
    --form "05_gegenstelle=$aw_counterpart_marker" \
    --form '06_befwegausw=' \
    --form '07_durchspruch=S' \
    --form "08_befhinweis=$aw_transport_marker" \
    --form '08_befhinwausw=Fax' \
    --form '09_vorrangstufe=sss' \
    --form "10_anschrift=$aw_address_marker" \
    --form "11_rufnummer=$aw_phone_marker" \
    --form '11_gesprnotiz=' \
    --form '12_anhang=' \
    --form "12_betreff=$aw_subject_marker" \
    --form "12_inhalt=$aw_content_marker" \
    --form "12_abfzeit=$aw_written_at" \
    --form "14_zeichen=$aw_author_marker" \
    --form '14_funktion=A/W' \
    --form "17_vermerke=$aw_note_marker" \
    --form \
        "message_attachment_upload=@$direct_aw_upload_file;type=image/jpeg;filename=Direktes-Lagebild.JPEG" \
    "$base_url/4fach/mainindex.php"
assert_body 'name="task" value="FM-Eingang"'
assert_body 'data-estab-message-attachments'
assert_body 'data-estab-attachment-count="1"'
assert_body '>1 Anlage</a>'
assert_body 'Direktes-Lagebild.JPEG'
assert_body 'Direkt &amp; &lt;img src=x onerror=alert(1)&gt;'
assert_body_absent '<img src=x onerror=alert(1)>'
assert_body "$aw_content_marker"
assert_body "$aw_note_marker"
assert_body 'showpic.php?file='
assert_body 'Im Browser ansehen'
assert_body_absent 'Liste der verfügbaren Dateien'
direct_aw_reference=$(sed -n \
    's/.*id="f_12_anhang" type="hidden" name="12_anhang" value="\([A-Za-z0-9_.;-][A-Za-z0-9_.;-]*\)".*/\1/p' \
    "$body" | head -n 1)
if ! printf '%s' "$direct_aw_reference" |
    grep -Eq '^[A-Za-z]{2}[0-9]{4,}\.jpeg;$'; then
    printf 'HTTP smoke: direct message attachment reference missing\n' >&2
    exit 1
fi
direct_aw_attachment=${direct_aw_reference%;}
direct_aw_reservation=${direct_aw_attachment%.*}
assert_uploaded_attachment \
    "$direct_aw_reservation" "$legacy_registration_code" jpeg \
    "$direct_aw_upload_md5"

# Detaching changes only this unsaved message reference. The evidence file
# remains available in the incident archive and no message text is mutated.
aw_workflow_csrf_token=$(csrf_from_body)
aw_recipient_matrix_revision=$(recipient_matrix_revision_from_body)
aw_attachment_request_token=$(
    message_attachment_request_token_from_body
)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$aw_workflow_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$aw_recipient_matrix_revision" \
    --data-urlencode \
        "message_attachment_request_token=$aw_attachment_request_token" \
    --data-urlencode \
        "message_attachment_remove_x=$direct_aw_attachment" \
    --data-urlencode 'task=FM-Eingang' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode "01_datum=$aw_received_at" \
    --data-urlencode "05_gegenstelle=$aw_counterpart_marker" \
    --data-urlencode '06_befwegausw=' \
    --data-urlencode '07_durchspruch=S' \
    --data-urlencode "08_befhinweis=$aw_transport_marker" \
    --data-urlencode '08_befhinwausw=Fax' \
    --data-urlencode '09_vorrangstufe=sss' \
    --data-urlencode "10_anschrift=$aw_address_marker" \
    --data-urlencode "11_rufnummer=$aw_phone_marker" \
    --data-urlencode '11_gesprnotiz=' \
    --data-urlencode "12_anhang=$direct_aw_reference" \
    --data-urlencode "12_betreff=$aw_subject_marker" \
    --data-urlencode "12_inhalt=$aw_content_marker" \
    --data-urlencode "12_abfzeit=$aw_written_at" \
    --data-urlencode "14_zeichen=$aw_author_marker" \
    --data-urlencode '14_funktion=A/W' \
    --data-urlencode "17_vermerke=$aw_note_marker" \
    "$base_url/4fach/mainindex.php"
assert_body 'data-estab-attachment-count="0"'
assert_body 'Noch keine Anlage hinzugefügt.'
assert_body 'Die archivierte Datei wurde nicht gelöscht.'
assert_body 'id="f_12_anhang" type="hidden" name="12_anhang" value=""'
assert_body "$aw_content_marker"
aw_workflow_csrf_token=$(csrf_from_body)
aw_recipient_matrix_revision=$(recipient_matrix_revision_from_body)

# A stale legacy reference whose archive row is missing must not trap an
# otherwise authorised correction forever. Removing it validates the complete
# resulting list, which is empty here, without pretending the missing file was
# readable.
aw_attachment_request_token=$(
    message_attachment_request_token_from_body
)
orphaned_aw_attachment='ZZ999999.pdf'
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$aw_workflow_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$aw_recipient_matrix_revision" \
    --data-urlencode \
        "message_attachment_request_token=$aw_attachment_request_token" \
    --data-urlencode \
        "message_attachment_remove_x=$orphaned_aw_attachment" \
    --data-urlencode 'task=FM-Eingang' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode "01_datum=$aw_received_at" \
    --data-urlencode "05_gegenstelle=$aw_counterpart_marker" \
    --data-urlencode '06_befwegausw=' \
    --data-urlencode '07_durchspruch=S' \
    --data-urlencode "08_befhinweis=$aw_transport_marker" \
    --data-urlencode '08_befhinwausw=Fax' \
    --data-urlencode '09_vorrangstufe=sss' \
    --data-urlencode "10_anschrift=$aw_address_marker" \
    --data-urlencode "11_rufnummer=$aw_phone_marker" \
    --data-urlencode '11_gesprnotiz=' \
    --data-urlencode "12_anhang=$orphaned_aw_attachment;" \
    --data-urlencode "12_betreff=$aw_subject_marker" \
    --data-urlencode "12_inhalt=$aw_content_marker" \
    --data-urlencode "12_abfzeit=$aw_written_at" \
    --data-urlencode "14_zeichen=$aw_author_marker" \
    --data-urlencode '14_funktion=A/W' \
    --data-urlencode "17_vermerke=$aw_note_marker" \
    "$base_url/4fach/mainindex.php"
assert_body 'data-estab-attachment-count="0"'
assert_body 'Die archivierte Datei wurde nicht gelöscht.'
assert_body_absent "$orphaned_aw_attachment"
aw_workflow_csrf_token=$(csrf_from_body)
aw_recipient_matrix_revision=$(recipient_matrix_revision_from_body)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/download.php?area=attachment&file=$direct_aw_attachment"
if ! cmp -s "$direct_aw_upload_file" "$body"; then
    printf 'HTTP smoke: detached direct attachment was deleted or changed\n' >&2
    exit 1
fi

# Continue to prove the backward-compatible archive selection flow as well.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$aw_workflow_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$aw_recipient_matrix_revision" \
    --data-urlencode 'anhang_plus_x=1' \
    --data-urlencode 'task=FM-Eingang' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode "01_datum=$aw_received_at" \
    --data-urlencode "05_gegenstelle=$aw_counterpart_marker" \
    --data-urlencode '06_befwegausw=' \
    --data-urlencode '07_durchspruch=S' \
    --data-urlencode "08_befhinweis=$aw_transport_marker" \
    --data-urlencode '08_befhinwausw=Fax' \
    --data-urlencode '09_vorrangstufe=sss' \
    --data-urlencode "10_anschrift=$aw_address_marker" \
    --data-urlencode "11_rufnummer=$aw_phone_marker" \
    --data-urlencode '11_gesprnotiz=' \
    --data-urlencode "12_betreff=$aw_subject_marker" \
    --data-urlencode "12_inhalt=$aw_content_marker" \
    --data-urlencode "12_abfzeit=$aw_written_at" \
    --data-urlencode "14_zeichen=$aw_author_marker" \
    --data-urlencode '14_funktion=A/W' \
    --data-urlencode "17_vermerke=$aw_note_marker" \
    "$base_url/4fach/mainindex.php"
assert_body 'Liste der verfügbaren Dateien'
assert_body_absent 'Warning:'
aw_attachment_menu_csrf_token=$(csrf_from_body)
aw_attachment_flow=$(attachment_flow_from_body)
aw_reservations_before_csrf=$(active_attachment_reservation_count)

assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode \
        'csrf_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    --data-urlencode "attachment_flow=$aw_attachment_flow" \
    --data-urlencode 'ah_upload_x=1' \
    "$base_url/4fach/anhang.php"
assert_body 'ungültig oder abgelaufen'
aw_reservations_after_csrf=$(active_attachment_reservation_count)
if [ "$aw_reservations_after_csrf" != "$aw_reservations_before_csrf" ]; then
    printf 'HTTP smoke: rejected attachment CSRF created a reservation\n' >&2
    exit 1
fi

assert_status 405 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/anhang.php?ah_upload_x=1"
assert_body 'nur per Formular'

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$aw_attachment_menu_csrf_token" \
    --data-urlencode "attachment_flow=$aw_attachment_flow" \
    --data-urlencode 'ah_upload_x=1' \
    "$base_url/4fach/anhang.php"
assert_body 'Anhang hochladen'
assert_body 'accept=".jpg,.jpeg,.tif,.tiff'
assert_body 'Erlaubte Formate: JPG, JPEG'
assert_body 'Maximale Dateigröße: 20 MiB'
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
    --form "attachment_flow=$aw_attachment_flow" \
    --form "fs_nextfilename=$aw_reserved_name" \
    --form 'fs_comment=A/W & <Beschreibung>' \
    --form "fs_shortname=$legacy_registration_code" \
    --form "fs_timestamp=$aw_upload_timestamp" \
    --form 'absenden_x=1' \
    --form "upload=@$aw_upload_file;type=image/jpeg;filename=Lagebild.JPEG" \
    "$base_url/4fach/anhang.php"
aw_stored_attachment=$aw_reserved_name.jpeg
assert_body_absent 'Der Anhang konnte nicht sicher gespeichert werden.'
assert_body "$aw_reserved_name"
assert_body 'Lagebild.JPEG'
assert_uploaded_attachment \
    "$aw_reserved_name" "$legacy_registration_code" jpeg "$aw_upload_md5"
aw_attachment_menu_csrf_token=$(csrf_from_body)

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers" \
    "$base_url/4fach/download.php?area=attachment&file=$aw_stored_attachment"
if ! cmp -s "$aw_upload_file" "$body"; then
    printf 'HTTP smoke: A/W JPEG attachment content differs after upload\n' >&2
    exit 1
fi
if ! grep -Eiq '^Content-Type: image/jpeg' "$headers"; then
    printf 'HTTP smoke: A/W JPEG download MIME differs\n' >&2
    exit 1
fi
if ! grep -Eiq '^Content-Disposition: attachment;' "$headers"; then
    printf 'HTTP smoke: normal JPEG download unexpectedly became inline\n' >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers" \
    --get \
    --data-urlencode 'area=attachment' \
    --data-urlencode "file=$aw_stored_attachment" \
    --data-urlencode 'view=inline' \
    "$base_url/4fach/download.php"
if ! cmp -s "$aw_upload_file" "$body"; then
    printf 'HTTP smoke: inline JPEG attachment content differs\n' >&2
    exit 1
fi
for header_pattern in \
    '^Content-Type: image/jpeg' \
    '^Content-Disposition: inline;' \
    '^Cache-Control: private, no-store' \
    '^X-Content-Type-Options: nosniff' \
    '^Content-Security-Policy: sandbox'
do
    if ! grep -Eiq "$header_pattern" "$headers"; then
        printf 'HTTP smoke: inline JPEG header missing: %s\n' \
            "$header_pattern" >&2
        exit 1
    fi
done
assert_status 400 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --get \
    --data-urlencode 'area=attachment' \
    --data-urlencode "file=$aw_stored_attachment" \
    --data-urlencode 'view=preview' \
    "$base_url/4fach/download.php"
assert_body 'Ungültige Dateianforderung'
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers" \
    "$base_url/4fach/showpic.php?file=$aw_stored_attachment&width=160&height=80"
if ! grep -Eiq '^Content-Type: image/png' "$headers"; then
    printf 'HTTP smoke: A/W JPEG preview is not PNG\n' >&2
    exit 1
fi
aw_preview_dimensions=$(od -An -tx1 -j16 -N8 "$body" | tr -d ' \n')
if [ "$aw_preview_dimensions" != '0000005000000050' ]; then
    printf 'HTTP smoke: A/W JPEG preview was not decoded to 80x80 pixels\n' >&2
    exit 1
fi

# The 20-MiB application boundary rejects an otherwise fully transported file
# before MIME persistence. The user receives the configured limit and the
# reservation still has to be released.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$aw_attachment_menu_csrf_token" \
    --data-urlencode "attachment_flow=$aw_attachment_flow" \
    --data-urlencode 'ah_upload_x=1' \
    "$base_url/4fach/anhang.php"
oversized_jpeg_csrf_token=$(csrf_from_body)
oversized_jpeg_reserved_name=$(sed -n \
    's/.*name="fs_nextfilename" value="\([A-Za-z0-9_-][A-Za-z0-9_-]*\)".*/\1/p' \
    "$body" | head -n 1)
oversized_jpeg_timestamp=$(sed -n \
    's/.*name="fs_timestamp" value="\([A-Za-z0-9][A-Za-z0-9]*\)".*/\1/p' \
    "$body" | head -n 1)
if ! printf '%s' "$oversized_jpeg_csrf_token" | grep -Eq '^[a-f0-9]{64}$'; then
    printf 'HTTP smoke: oversized JPEG CSRF token missing\n' >&2
    exit 1
fi
if ! printf '%s' "$oversized_jpeg_reserved_name" | grep -Eq '^[A-Za-z]{2}[0-9]{4,}$'; then
    printf 'HTTP smoke: oversized JPEG reservation missing\n' >&2
    exit 1
fi
if ! printf '%s' "$oversized_jpeg_timestamp" |
    grep -Eq '^[0-9]{6}[A-Za-z]{3}[0-9]{4}$'; then
    printf 'HTTP smoke: oversized JPEG timestamp missing\n' >&2
    exit 1
fi
oversized_jpeg_file=$work_dir/oversized.jpeg
cp "$repo_root/4fach/design/HS/null.jpg" "$oversized_jpeg_file"
dd if=/dev/zero bs=1048576 count=21 >>"$oversized_jpeg_file" 2>/dev/null
oversized_jpeg_size=$(wc -c <"$oversized_jpeg_file" | tr -d '[:space:]')
if [ "$oversized_jpeg_size" -le 20971520 ] ||
    [ "$oversized_jpeg_size" -ge 25165824 ]; then
    printf 'HTTP smoke: generated oversized JPEG is outside the application-limit test window\n' >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --form "csrf_token=$oversized_jpeg_csrf_token" \
    --form "attachment_flow=$aw_attachment_flow" \
    --form "fs_nextfilename=$oversized_jpeg_reserved_name" \
    --form 'fs_comment=Zu großes JPEG' \
    --form "fs_shortname=$legacy_registration_code" \
    --form "fs_timestamp=$oversized_jpeg_timestamp" \
    --form 'absenden_x=1' \
    --form "upload=@$oversized_jpeg_file;type=image/jpeg;filename=zu-gross.JPEG" \
    "$base_url/4fach/anhang.php"
assert_body 'Die Datei ist größer als die erlaubten 20 MiB.'
assert_body_absent 'Der Anhang konnte nicht sicher gespeichert werden.'
aw_attachment_menu_csrf_token=$(csrf_from_body)
if [ "$(active_attachment_reservation_count)" != 0 ]; then
    printf 'HTTP smoke: oversized JPEG left an active reservation\n' >&2
    exit 1
fi
oversized_jpeg_rows=$(
    printf "SELECT COUNT(*) FROM nv_anhang WHERE BINARY filename = BINARY '%s' AND status = 1;\n" \
        "$oversized_jpeg_reserved_name" |
        db_sql
)
if [ "$oversized_jpeg_rows" != 0 ]; then
    printf 'HTTP smoke: oversized JPEG produced finalized metadata\n' >&2
    exit 1
fi
assert_status 404 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/download.php?area=attachment&file=$oversized_jpeg_reserved_name.jpeg"
assert_body 'Datei nicht gefunden'

# A browser-supplied MIME claim is not trusted. Plain text named ".JPEG" must
# fail visibly and release its reservation without publishing bytes or metadata.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$aw_attachment_menu_csrf_token" \
    --data-urlencode "attachment_flow=$aw_attachment_flow" \
    --data-urlencode 'ah_upload_x=1' \
    "$base_url/4fach/anhang.php"
fake_jpeg_csrf_token=$(csrf_from_body)
fake_jpeg_reserved_name=$(sed -n \
    's/.*name="fs_nextfilename" value="\([A-Za-z0-9_-][A-Za-z0-9_-]*\)".*/\1/p' \
    "$body" | head -n 1)
fake_jpeg_timestamp=$(sed -n \
    's/.*name="fs_timestamp" value="\([A-Za-z0-9][A-Za-z0-9]*\)".*/\1/p' \
    "$body" | head -n 1)
if ! printf '%s' "$fake_jpeg_csrf_token" | grep -Eq '^[a-f0-9]{64}$'; then
    printf 'HTTP smoke: fake JPEG CSRF token missing\n' >&2
    exit 1
fi
if ! printf '%s' "$fake_jpeg_reserved_name" | grep -Eq '^[A-Za-z]{2}[0-9]{4,}$'; then
    printf 'HTTP smoke: fake JPEG reservation missing\n' >&2
    exit 1
fi
if ! printf '%s' "$fake_jpeg_timestamp" |
    grep -Eq '^[0-9]{6}[A-Za-z]{3}[0-9]{4}$'; then
    printf 'HTTP smoke: fake JPEG timestamp missing\n' >&2
    exit 1
fi
fake_jpeg_file=$work_dir/not-a-jpeg.txt
printf 'plain text must never pass as JPEG\n' >"$fake_jpeg_file"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --form "csrf_token=$fake_jpeg_csrf_token" \
    --form "attachment_flow=$aw_attachment_flow" \
    --form "fs_nextfilename=$fake_jpeg_reserved_name" \
    --form 'fs_comment=Manipulierter JPEG-Test' \
    --form "fs_shortname=$legacy_registration_code" \
    --form "fs_timestamp=$fake_jpeg_timestamp" \
    --form 'absenden_x=1' \
    --form "upload=@$fake_jpeg_file;type=image/jpeg;filename=fake.JPEG" \
    "$base_url/4fach/anhang.php"
assert_body 'Dateiendung und erkannter Dateityp passen nicht zusammen.'
assert_body_absent 'Der Anhang konnte nicht sicher gespeichert werden.'
aw_attachment_menu_csrf_token=$(csrf_from_body)
if [ "$(active_attachment_reservation_count)" != 0 ]; then
    printf 'HTTP smoke: rejected fake JPEG left an active reservation\n' >&2
    exit 1
fi
fake_jpeg_rows=$(
    printf "SELECT COUNT(*) FROM nv_anhang WHERE BINARY filename = BINARY '%s' AND status = 1;\n" \
        "$fake_jpeg_reserved_name" |
        db_sql
)
if [ "$fake_jpeg_rows" != 0 ]; then
    printf 'HTTP smoke: rejected fake JPEG produced finalized metadata\n' >&2
    exit 1
fi
assert_status 404 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/download.php?area=attachment&file=$fake_jpeg_reserved_name.jpeg"
assert_body 'Datei nicht gefunden'

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$aw_attachment_menu_csrf_token" \
    --data-urlencode "attachment_flow=$aw_attachment_flow" \
    --data-urlencode 'ah_auswahl_x=1' \
    --data-urlencode "lfd_901=$aw_stored_attachment" \
    "$base_url/4fach/anhang.php"
assert_body 'name="task" value="FM-Eingang"'
assert_body_regex 'name="01_medium" value="Fu" type="radio"[^>]*checked="checked"' \
    'preserved A/W medium'
assert_body "name=\"01_datum\" value=\"$aw_received_at\""
assert_body "id=\"f_01_zeichen\" data-estab-readonly=\"true\""
assert_body ">$legacy_registration_code</strong>"
assert_body ">$legacy_registration_code</strong>"
assert_body "name=\"05_gegenstelle\" value=\"$aw_counterpart_marker\""
assert_body_regex 'name="07_durchspruch" value="S" type="radio"[^>]*checked="checked"' \
    'preserved A/W message type'
assert_body "name=\"08_befhinweis\" value=\"$aw_transport_marker\""
assert_body_regex 'name="08_befhinwausw" value="Fax" type="radio"[^>]*checked="checked"' \
    'preserved A/W transport selection'
assert_body 'name="09_vorrangstufe" value="sss"'
assert_body_regex "name=\"10_anschrift\">$aw_address_marker</textarea>" \
    'preserved A/W address'
assert_body "name=\"11_rufnummer\" value=\"$aw_phone_marker\""
assert_body "name=\"12_betreff\" value=\"$aw_subject_marker\""
assert_body 'name="12_inhalt"'
assert_body "$aw_content_marker"
assert_body "name=\"12_anhang\" value=\"$aw_stored_attachment;\""
assert_body "name=\"12_abfzeit\" value=\"$aw_written_at\""
assert_body 'Wird durch LdF aus dem Rufnamen ergänzt'
assert_body_absent 'name="13_abseinheit"'
assert_body "name=\"14_zeichen\" value=\"$aw_author_marker\""
assert_body 'name="14_funktion" value="A/W"'
assert_body_absent 'name="15_quitdatum"'
assert_body_absent 'name="15_quitzeichen"'
assert_body \
    "name=\"recipient_matrix_revision\" value=\"$aw_recipient_matrix_revision\""
assert_body 'A/W &amp; &lt;Beschreibung&gt;'
assert_body_absent 'A/W &amp;amp; &amp;lt;Beschreibung&amp;gt;'
assert_body_absent 'name="16_gncopy"'
assert_body "$aw_note_marker"
assert_body_absent 'name="17_vermerke"'
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
assert_body 'administrativ zugewiesene Funktion'
assert_status 303 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"
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
assert_status 303 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"

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
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/mainindex.php"
assert_body 'Meldung/Seite:'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/handbuch/"
assert_body '<title>eStab Web-Handbuch</title>'
assert_body 'data-estab-handbook-search'
assert_body 'data-estab-nav-key="handbook" aria-current="page"'
assert_body_absent 'data-estab-public-bar'
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
assert_body "id=\"f_14_zeichen\" type=\"hidden\" name=\"14_zeichen\" value=\"$test_code\""
workflow_csrf_token=$(sed -n 's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' "$body" | head -n 1)
if ! printf '%s' "$workflow_csrf_token" | grep -Eq '^[a-f0-9]{64}$'; then
    printf 'HTTP smoke: workflow CSRF token missing\n' >&2
    exit 1
fi
workflow_recipient_matrix_revision=$(recipient_matrix_revision_from_body)
workflow_attachment_request_token=$(
    message_attachment_request_token_from_body
)

if [ -z "$workflow_marker" ]; then
    workflow_marker="ESTAB_HTTP_WORKFLOW_$(date +%s)_$$"
fi
workflow_phone='+49 711 7654321'
workflow_subject="HTTP-Anhang ${workflow_marker}"
upload_file=$work_dir/workflow.txt
printf '%s\n' "$workflow_marker" > "$upload_file"
tactical_time=$(date '+%H%M')

# If ordinary message validation rejects a submit after the bytes were safely
# archived, replaying the same browser POST must recover that exact reference
# at the form. It may neither upload a duplicate nor silently persist a
# message with invalid fields.
invalid_direct_staff_marker="${workflow_marker}_INVALID_ATTACHMENT_SUBMIT"
invalid_direct_staff_comment="Ungültiger Direktentwurf ${workflow_marker}"
invalid_direct_original_token=$workflow_attachment_request_token
invalid_direct_staff_before=$(
    printf "SELECT COUNT(*) FROM nv_anhang WHERE BINARY comment = BINARY '%s' AND BINARY kuerzel = BINARY '%s';\n" \
        "$invalid_direct_staff_comment" "$test_code" | db_sql
)
submit_invalid_direct_staff_message() {
    include_file=${1:-yes}
    set -- \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --form "csrf_token=$workflow_csrf_token" \
        --form \
            "recipient_matrix_revision=$workflow_recipient_matrix_revision" \
        --form \
            "message_attachment_request_token=$workflow_attachment_request_token" \
        --form 'absenden_x=1' \
        --form 'task=Stab_schreiben' \
        --form '02_zeit=' \
        --form '07_durchspruch=D' \
        --form '08_befhinweis=' \
        --form '08_befhinwausw=' \
        --form '09_vorrangstufe=' \
        --form '10_anschrift=' \
        --form "11_rufnummer=$workflow_phone" \
        --form '11_gesprnotiz=f' \
        --form '12_anhang=' \
        --form '12_betreff=Ungültiger Direktentwurf' \
        --form "12_inhalt=$invalid_direct_staff_marker" \
        --form "12_abfzeit=$tactical_time" \
        --form '13_abseinheit=HTTP-Direktintegration' \
        --form "14_zeichen=$test_code" \
        --form "14_funktion=$test_function" \
        --form '17_vermerke=' \
        --form "message_attachment_comment=$invalid_direct_staff_comment"
    if [ "$include_file" = yes ]; then
        set -- "$@" --form \
            "message_attachment_upload=@$direct_staff_upload_file;type=image/jpeg;filename=Unfertiger-Entwurf.JPEG"
    fi
    assert_status 200 "$@" "$base_url/4fach/mainindex.php"
}

# This fixture is also used by the successful primary-flow proof below.
direct_staff_upload_file=$repo_root/4fach/design/HS/null.jpg
submit_invalid_direct_staff_message yes
assert_body "$invalid_direct_staff_marker"
assert_body 'data-estab-attachment-count="1"'
assert_body 'Unfertiger-Entwurf.JPEG'
invalid_direct_reference=$(sed -n \
    's/.*id="f_12_anhang" type="hidden" name="12_anhang" value="\([A-Za-z0-9_.;-][A-Za-z0-9_.;-]*\)".*/\1/p' \
    "$body" | head -n 1)
if ! printf '%s' "$invalid_direct_reference" |
    grep -Eq '^[A-Za-z]{2}[0-9]{4,}\.jpeg;$'; then
    printf 'HTTP smoke: rejected direct submit lost its attachment reference\n' >&2
    exit 1
fi
invalid_direct_attachment=${invalid_direct_reference%;}

submit_invalid_direct_staff_message no
assert_body "$invalid_direct_staff_marker"
assert_body 'Die Anlage wurde bereits sicher gespeichert'
assert_body 'Prüfen Sie die Meldungsliste'
assert_body "data-estab-message-attachment=\"$invalid_direct_attachment\""
assert_body "value=\"$invalid_direct_reference\""
invalid_direct_staff_after=$(
    printf "SELECT COUNT(*) FROM nv_anhang WHERE BINARY comment = BINARY '%s' AND BINARY kuerzel = BINARY '%s';\n" \
        "$invalid_direct_staff_comment" "$test_code" | db_sql
)
invalid_direct_message_count=$(
    printf "SELECT COUNT(*) FROM nv_nachrichten WHERE BINARY \`12_inhalt\` = BINARY '%s';\n" \
        "$invalid_direct_staff_marker" | db_sql
)
if [ "$invalid_direct_staff_after" != \
        "$((invalid_direct_staff_before + 1))" ] ||
    [ "$invalid_direct_message_count" != 0 ]; then
    printf 'HTTP smoke: rejected direct-submit replay duplicated or persisted data\n' >&2
    exit 1
fi

# Correct the rejected draft without resending the file. Repeating that exact
# no-file POST with its completed token must remain an idempotent redirect.
# Replaying the original, still ambiguous upload token afterwards must only
# recover the draft with a warning; an attachment reused by another message is
# never treated as proof that this POST committed.
workflow_csrf_token=$(csrf_from_body)
workflow_recipient_matrix_revision=$(recipient_matrix_revision_from_body)
workflow_attachment_request_token=$(
    message_attachment_request_token_from_body
)
submit_recovered_direct_staff_message() {
    expected_status=$1
    request_token=$2
    assert_status "$expected_status" \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --form "csrf_token=$workflow_csrf_token" \
        --form \
            "recipient_matrix_revision=$workflow_recipient_matrix_revision" \
        --form "message_attachment_request_token=$request_token" \
        --form 'absenden_x=1' \
        --form 'task=Stab_schreiben' \
        --form '02_zeit=' \
        --form '07_durchspruch=D' \
        --form '08_befhinweis=' \
        --form '08_befhinwausw=' \
        --form '09_vorrangstufe=' \
        --form '10_anschrift=HTTP-Wiederholungsziel' \
        --form "11_rufnummer=$workflow_phone" \
        --form '11_gesprnotiz=f' \
        --form "12_anhang=$invalid_direct_reference" \
        --form '12_betreff=Korrigierter Direktentwurf' \
        --form "12_inhalt=$invalid_direct_staff_marker" \
        --form "12_abfzeit=$tactical_time" \
        --form '13_abseinheit=HTTP-Direktintegration' \
        --form "14_zeichen=$test_code" \
        --form "14_funktion=$test_function" \
        --form '17_vermerke=' \
        "$base_url/4fach/mainindex.php"
}
submit_recovered_direct_staff_message 200 \
    "$workflow_attachment_request_token"
submit_recovered_direct_staff_message 303 \
    "$workflow_attachment_request_token"
submit_recovered_direct_staff_message 200 \
    "$invalid_direct_original_token"
assert_body 'Prüfen Sie die Meldungsliste'
invalid_direct_message_count=$(
    printf "SELECT COUNT(*) FROM nv_nachrichten WHERE BINARY \`12_inhalt\` = BINARY '%s';\n" \
        "$invalid_direct_staff_marker" | db_sql
)
if [ "$invalid_direct_message_count" != 1 ]; then
    printf 'HTTP smoke: ambiguous pending-submit replay duplicated the corrected message\n' >&2
    exit 1
fi

# Start a separate clean draft for the primary image/PDF workflow below.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'stab_schreiben_x=1' \
    "$base_url/4fach/mainindex.php"
assert_body 'name="task" value="Stab_schreiben"'
workflow_csrf_token=$(csrf_from_body)
workflow_recipient_matrix_revision=$(recipient_matrix_revision_from_body)
workflow_attachment_request_token=$(
    message_attachment_request_token_from_body
)

# Add a PDF in the same Nachrichtenvordruck before the final image-and-submit
# action. The saved message will therefore prove both embedded browser
# representations and a multi-attachment badge without using the old archive
# upload page.
direct_staff_pdf_file=$repo_root/4fbak/fpdf/ex.pdf
direct_staff_pdf_comment="PDF ${workflow_marker}"
direct_staff_pdf_before=$(
    printf "SELECT COUNT(*) FROM nv_anhang WHERE BINARY comment = BINARY '%s' AND BINARY kuerzel = BINARY '%s';\n" \
        "$direct_staff_pdf_comment" "$test_code" | db_sql
)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --form "csrf_token=$workflow_csrf_token" \
    --form "recipient_matrix_revision=$workflow_recipient_matrix_revision" \
    --form "message_attachment_request_token=$workflow_attachment_request_token" \
    --form 'message_attachment_upload_x=1' \
    --form 'task=Stab_schreiben' \
    --form '07_durchspruch=D' \
    --form '10_anschrift=HTTP-Direktempfänger' \
    --form "11_rufnummer=$workflow_phone" \
    --form '11_gesprnotiz=f' \
    --form '12_anhang=' \
    --form '12_betreff=Direkter PDF-Entwurf' \
    --form "12_inhalt=${workflow_marker}_PDF_DRAFT" \
    --form "12_abfzeit=$tactical_time" \
    --form '13_abseinheit=HTTP-Direktintegration' \
    --form "14_zeichen=$test_code" \
    --form "14_funktion=$test_function" \
    --form "message_attachment_comment=$direct_staff_pdf_comment" \
    --form \
        "message_attachment_upload=@$direct_staff_pdf_file;type=application/pdf;filename=Staff-Direkt.PDF" \
    "$base_url/4fach/mainindex.php"
assert_body 'Staff-Direkt.PDF'
assert_body 'data-estab-pdf-preview'
assert_body 'data-src="'
direct_staff_pdf_reference=$(sed -n \
    's/.*id="f_12_anhang" type="hidden" name="12_anhang" value="\([A-Za-z0-9_.;-][A-Za-z0-9_.;-]*\)".*/\1/p' \
    "$body" | head -n 1)
if ! printf '%s' "$direct_staff_pdf_reference" |
    grep -Eq '^[A-Za-z]{2}[0-9]{4,}\.pdf;$'; then
    printf 'HTTP smoke: direct PDF reference missing from message form\n' >&2
    exit 1
fi
direct_staff_pdf_attachment=${direct_staff_pdf_reference%;}
direct_staff_pdf_after=$(
    printf "SELECT COUNT(*) FROM nv_anhang WHERE BINARY comment = BINARY '%s' AND BINARY kuerzel = BINARY '%s';\n" \
        "$direct_staff_pdf_comment" "$test_code" | db_sql
)
if [ "$direct_staff_pdf_after" != "$((direct_staff_pdf_before + 1))" ]; then
    printf 'HTTP smoke: direct PDF upload did not create exactly one archive row\n' >&2
    exit 1
fi
workflow_csrf_token=$(csrf_from_body)
workflow_recipient_matrix_revision=$(recipient_matrix_revision_from_body)
workflow_attachment_request_token=$(
    message_attachment_request_token_from_body
)

# Selecting a file and pressing the ordinary "Absenden" button is the primary
# staff workflow. The same multipart request must archive exactly one image and
# persist its server-generated reference with exactly one message. Replaying
# the browser request without the non-repeatable file part models browser
# recovery and must not duplicate either row.
direct_staff_marker="${workflow_marker}_DIRECT_ATTACHMENT_SUBMIT"
direct_staff_subject="Direktversand ${workflow_marker}"
direct_staff_comment="Direkt ${workflow_marker}"
direct_staff_upload_md5=$(
    openssl dgst -md5 -r "$direct_staff_upload_file" | awk '{print $1}'
)
direct_staff_attachment_before=$(
    printf "SELECT COUNT(*) FROM nv_anhang WHERE BINARY comment = BINARY '%s' AND BINARY kuerzel = BINARY '%s';\n" \
        "$direct_staff_comment" "$test_code" | db_sql
)
submit_direct_staff_message() {
    expected_direct_status=$1
    include_file=${2:-yes}
    set -- \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --form "csrf_token=$workflow_csrf_token" \
        --form \
            "recipient_matrix_revision=$workflow_recipient_matrix_revision" \
        --form \
            "message_attachment_request_token=$workflow_attachment_request_token" \
        --form 'absenden_x=1' \
        --form 'task=Stab_schreiben' \
        --form '02_zeit=' \
        --form '07_durchspruch=D' \
        --form '08_befhinweis=' \
        --form '08_befhinwausw=' \
        --form '09_vorrangstufe=' \
        --form '10_anschrift=HTTP-Direktempfänger' \
        --form "11_rufnummer=$workflow_phone" \
        --form '11_gesprnotiz=f' \
        --form "12_anhang=$direct_staff_pdf_reference" \
        --form "12_betreff=$direct_staff_subject" \
        --form "12_inhalt=$direct_staff_marker" \
        --form "12_abfzeit=$tactical_time" \
        --form '13_abseinheit=HTTP-Direktintegration' \
        --form "14_zeichen=$test_code" \
        --form "14_funktion=$test_function" \
        --form '17_vermerke=' \
        --form "message_attachment_comment=$direct_staff_comment"
    if [ "$include_file" = yes ]; then
        set -- "$@" --form \
            "message_attachment_upload=@$direct_staff_upload_file;type=image/jpeg;filename=Staff-Direkt.JPEG"
    fi
    assert_status "$expected_direct_status" "$@" \
        "$base_url/4fach/mainindex.php"
}
submit_direct_staff_message 200 yes
if grep -Eq 'Fatal error|Uncaught (Error|TypeError)|Warning:' "$body"; then
    printf 'HTTP smoke: direct attachment submit leaked a PHP runtime error\n' >&2
    exit 1
fi
submit_direct_staff_message 303 no

direct_staff_message_count=$(
    printf "SELECT COUNT(*) FROM nv_nachrichten AS n JOIN nv_einsatz_status AS s ON s.singleton_id = 1 AND s.active_einsatz_id = n.einsatz_id WHERE BINARY n.\`12_inhalt\` = BINARY '%s';\n" \
        "$direct_staff_marker" | db_sql
)
direct_staff_attachment_after=$(
    printf "SELECT COUNT(*) FROM nv_anhang WHERE BINARY comment = BINARY '%s' AND BINARY kuerzel = BINARY '%s';\n" \
        "$direct_staff_comment" "$test_code" | db_sql
)
if [ "$direct_staff_message_count" != 1 ] ||
    [ "$direct_staff_attachment_after" != \
        "$((direct_staff_attachment_before + 1))" ]; then
    printf 'HTTP smoke: direct attachment replay duplicated message or file rows\n' >&2
    exit 1
fi
direct_staff_record=$(
    printf "SELECT CONCAT(n.\`00_lfd\`, '|', COALESCE(n.\`12_anhang\`, '')) FROM nv_nachrichten AS n JOIN nv_einsatz_status AS s ON s.singleton_id = 1 AND s.active_einsatz_id = n.einsatz_id WHERE BINARY n.\`12_inhalt\` = BINARY '%s' ORDER BY n.\`00_lfd\` DESC LIMIT 1;\n" \
        "$direct_staff_marker" | db_sql
)
if ! printf '%s' "$direct_staff_record" |
    grep -Eq '^[1-9][0-9]*\|[A-Za-z]{2}[0-9]{4,}\.pdf;[A-Za-z]{2}[0-9]{4,}\.jpeg;$'; then
    printf 'HTTP smoke: direct staff message lacks its persisted attachment reference\n' >&2
    exit 1
fi
direct_staff_record_id=${direct_staff_record%%|*}
direct_staff_reference=${direct_staff_record#*|}
direct_staff_persisted_pdf=${direct_staff_reference%%;*}
direct_staff_image_reference=${direct_staff_reference#*;}
direct_staff_attachment=${direct_staff_image_reference%;}
if [ "$direct_staff_persisted_pdf" != "$direct_staff_pdf_attachment" ]; then
    printf 'HTTP smoke: final message replaced its already uploaded PDF reference\n' >&2
    exit 1
fi
direct_staff_reservation=${direct_staff_attachment%.*}
assert_uploaded_attachment \
    "$direct_staff_reservation" "$test_code" jpeg \
    "$direct_staff_upload_md5"

# Search isolates the saved row so the badge cannot accidentally belong to a
# different message. Opening that exact row must expose its image card and the
# authorized browser-preview link without visiting the attachment archive.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'flt_find_mask_ein_x=1' \
    "$base_url/4fach/mainindex.php"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode "flt_search=$direct_staff_marker" \
    "$base_url/4fach/mainindex.php"
assert_body "$direct_staff_marker"
assert_body 'data-estab-message-attachment-badge'
assert_body \
    'data-estab-message-attachment-count="2" aria-label="2 Anlagen">2 Anlagen</span>'
direct_staff_detail_csrf=$(csrf_from_body)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$direct_staff_detail_csrf" \
    --data-urlencode 'stab=meldung' \
    --data-urlencode "00_lfd=$direct_staff_record_id" \
    "$base_url/4fach/mainindex.php"
assert_body 'data-estab-message-attachments'
assert_body 'data-estab-attachment-count="2"'
assert_body "data-estab-message-attachment=\"$direct_staff_attachment\""
assert_body "data-estab-message-attachment=\"$direct_staff_pdf_attachment\""
assert_body 'Staff-Direkt.JPEG'
assert_body 'Staff-Direkt.PDF'
assert_body "$direct_staff_comment"
assert_body "$direct_staff_pdf_comment"
assert_body 'class="estab-message-attachment-preview"'
assert_body "showpic.php?file=$direct_staff_attachment"
assert_body 'Im Browser ansehen'
assert_body 'data-estab-pdf-preview'
assert_body "file=$direct_staff_pdf_attachment&amp;view=inline"

# Reset the isolated list search and open a fresh form before continuing the
# full legacy archive compatibility workflow below.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'filter_suche_reset=1' \
    "$base_url/4fach/mainindex.php"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'stab_schreiben_x=1' \
    "$base_url/4fach/mainindex.php"
assert_body 'name="task" value="Stab_schreiben"'
workflow_csrf_token=$(csrf_from_body)
workflow_recipient_matrix_revision=$(recipient_matrix_revision_from_body)
workflow_attachment_request_token=$(
    message_attachment_request_token_from_body
)

# A rejected draft must return the user to this exact message form with a
# useful error instead of creating a half-initialised attachment flow. Keep
# markers at both ends to prove that the controller rehydrates the submitted
# work rather than rendering an empty form after the size check fails.
oversized_draft_file=$work_dir/oversized-message.txt
oversized_draft_start="${workflow_marker}_DRAFT_BEGIN"
oversized_draft_end="${workflow_marker}_DRAFT_END"
printf '%s\n' "$oversized_draft_start" > "$oversized_draft_file"
dd if=/dev/zero bs=1048576 count=1 2>/dev/null |
    tr '\000' 'N' >> "$oversized_draft_file"
printf '\n%s\n' "$oversized_draft_end" >> "$oversized_draft_file"
assert_status 422 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$workflow_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$workflow_recipient_matrix_revision" \
    --data-urlencode 'anhang_plus_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '10_anschrift=HTTP-Integrationsempfänger' \
    --data-urlencode "11_rufnummer=$workflow_phone" \
    --data-urlencode "12_betreff=$workflow_subject" \
    --data-urlencode "12_inhalt@$oversized_draft_file" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=HTTP-Integration' \
    --data-urlencode "14_zeichen=$test_code" \
    --data-urlencode "14_funktion=$test_function" \
    "$base_url/4fach/mainindex.php"
assert_body 'name="task" value="Stab_schreiben"'
assert_body 'role="alert"'
assert_body 'Die Anhangverwaltung wurde nicht geöffnet:'
assert_body 'bleiben in diesem Formular erhalten.'
assert_body "$workflow_subject"
assert_body "$workflow_phone"
assert_body "$oversized_draft_start"
assert_body "$oversized_draft_end"
assert_body_absent 'Liste der verfügbaren Dateien'
assert_body_absent 'name="attachment_flow"'
assert_body_absent 'Fatal error'
assert_body_absent 'Warning'
workflow_csrf_token=$(csrf_from_body)
workflow_recipient_matrix_revision=$(recipient_matrix_revision_from_body)

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$workflow_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$workflow_recipient_matrix_revision" \
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
    --data-urlencode "11_rufnummer=$workflow_phone" \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_betreff=$workflow_subject" \
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
attachment_menu_csrf_token=$(csrf_from_body)
attachment_flow=$(attachment_flow_from_body)

# Two message-form tabs and the standalone attachment overview must not share
# an origin, draft, or menu state. A forged/stale token and the historical
# browser-controlled `anhang_plus_x` exception must not consume either flow.
parallel_marker="${workflow_marker}_PARALLEL_TAB"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$workflow_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$workflow_recipient_matrix_revision" \
    --data-urlencode 'anhang_plus_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '00_lfd=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '10_anschrift=HTTP-Mehrtab-Empfänger' \
    --data-urlencode '12_betreff=HTTP-Mehrtab' \
    --data-urlencode "12_inhalt=$parallel_marker" \
    --data-urlencode "14_zeichen=$test_code" \
    --data-urlencode "14_funktion=$test_function" \
    "$base_url/4fach/mainindex.php"
assert_body 'Liste der verfügbaren Dateien'
parallel_attachment_flow=$(attachment_flow_from_body)
if [ "$parallel_attachment_flow" = "$attachment_flow" ]; then
    printf 'HTTP smoke: parallel attachment tabs share one flow token\n' >&2
    exit 1
fi

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$workflow_csrf_token" \
    --data-urlencode 'stab_anhang_x=1' \
    "$base_url/4fach/mainindex.php"
assert_body 'Hier können Sie vorhandene Anhänge ansehen oder neue Dateien hochladen.'

assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "attachment_flow=$parallel_attachment_flow" \
    --data-urlencode 'ah_upload_x=1' \
    "$base_url/4fach/anhang.php"
assert_body 'ungültig oder abgelaufen'

assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$attachment_menu_csrf_token" \
    --data-urlencode 'attachment_flow=00000000000000000000000000000000' \
    --data-urlencode 'ah_upload_x=1' \
    "$base_url/4fach/anhang.php"
assert_body 'ungültig oder nicht mehr autorisiert'

assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$attachment_menu_csrf_token" \
    --data-urlencode 'anhang_plus_x=1' \
    --data-urlencode 'ah_auswahl_x=1' \
    "$base_url/4fach/anhang.php"
assert_body 'ungültig oder nicht mehr autorisiert'

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$attachment_menu_csrf_token" \
    --data-urlencode "attachment_flow=$parallel_attachment_flow" \
    --data-urlencode 'ah_abbrechen_x=1' \
    "$base_url/4fach/anhang.php"
assert_body 'name="task" value="Stab_schreiben"'
assert_body "$parallel_marker"
assert_body_absent "$workflow_marker</textarea>"
assert_body \
    "name=\"recipient_matrix_revision\" value=\"$workflow_recipient_matrix_revision\""

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$attachment_menu_csrf_token" \
    --data-urlencode "attachment_flow=$attachment_flow" \
    --data-urlencode 'ah_upload_x=1' \
    "$base_url/4fach/anhang.php"
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
    --form "attachment_flow=$attachment_flow" \
    --form "fs_nextfilename=$reserved_name" \
    --form "fs_comment=HTTP integration attachment" \
    --form "fs_shortname=$test_code" \
    --form "fs_timestamp=$upload_timestamp" \
    --form 'absenden_x=1' \
    --form "upload=@$upload_file;type=text/plain;filename=workflow.txt" \
    "$base_url/4fach/anhang.php"
stored_attachment=$reserved_name.txt
assert_body_absent 'Der Anhang konnte nicht sicher gespeichert werden.'
assert_body "$reserved_name"
assert_uploaded_attachment "$reserved_name" "$test_code"
attachment_menu_csrf_token=$(csrf_from_body)

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers" \
    "$base_url/4fach/download.php?area=attachment&file=$stored_attachment"
if ! cmp -s "$upload_file" "$body"; then
    printf 'HTTP smoke: downloaded attachment content differs\n' >&2
    exit 1
fi
upload_sha256=$(file_sha256 "$upload_file")
for header in \
    Content-Disposition \
    Content-Security-Policy \
    X-Content-Type-Options \
    X-eStab-Attachment-Integrity \
    X-eStab-Attachment-SHA256
do
    if ! grep -qi "^${header}:" "$headers"; then
        printf 'HTTP smoke: attachment response header missing: %s\n' "$header" >&2
        exit 1
    fi
done
if ! grep -Eiq '^Content-Disposition: attachment;' "$headers"; then
    printf 'HTTP smoke: normal text attachment is not a download\n' >&2
    exit 1
fi
if ! grep -Eiq '^X-eStab-Attachment-Integrity: verified' "$headers" \
    || ! grep -Eiq \
        "^X-eStab-Attachment-SHA256: $upload_sha256" "$headers"; then
    printf 'HTTP smoke: attachment response has invalid integrity evidence\n' >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers" \
    --get \
    --data-urlencode 'area=attachment' \
    --data-urlencode "file=$stored_attachment" \
    --data-urlencode 'view=inline' \
    "$base_url/4fach/download.php"
if ! cmp -s "$upload_file" "$body"; then
    printf 'HTTP smoke: text attachment content differs after inline request\n' >&2
    exit 1
fi
if ! grep -Eiq '^Content-Type: text/plain' "$headers" \
    || ! grep -Eiq '^Content-Disposition: attachment;' "$headers"; then
    printf 'HTTP smoke: unsupported text MIME was rendered inline\n' >&2
    exit 1
fi

# A same-size byte change must fail before binary response headers or file
# bytes are emitted. The fixture backup is private to the disposable app
# container and the EXIT trap restores it if any assertion aborts.
tampered_attachment=$stored_attachment
attachment_fixture_bytes tamper "$tampered_attachment"
assert_status 409 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers" \
    "$base_url/4fach/download.php?area=attachment&file=$stored_attachment"
assert_body 'Die Integrität des Anhangs konnte nicht bestätigt werden.'
if grep -Eiq '^Content-Disposition:' "$headers" \
    || cmp -s "$upload_file" "$body"; then
    printf 'HTTP smoke: tampered attachment produced binary download output\n' >&2
    exit 1
fi
assert_status 409 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers" \
    "$base_url/4fach/showpic.php?file=$stored_attachment&width=160&height=80"
assert_body 'Die Integrität des Anhangs konnte nicht bestätigt werden.'
if grep -Eiq '^Content-Type: image/png' "$headers" \
    || [ "$(od -An -tx1 -N8 "$body" | tr -d ' \n')" = \
        '89504e470d0a1a0a' ]; then
    printf 'HTTP smoke: tampered attachment produced preview bytes\n' >&2
    exit 1
fi
attachment_fixture_bytes restore "$tampered_attachment"
tampered_attachment=

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers" \
    "$base_url/4fach/download.php?area=attachment&file=$stored_attachment"
if ! cmp -s "$upload_file" "$body"; then
    printf 'HTTP smoke: restored attachment content differs\n' >&2
    exit 1
fi
if ! grep -Eiq '^X-eStab-Attachment-Integrity: verified' "$headers" \
    || ! grep -Eiq \
        "^X-eStab-Attachment-SHA256: $upload_sha256" "$headers"; then
    printf 'HTTP smoke: restored attachment lost integrity evidence\n' >&2
    exit 1
fi

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --dump-header "$headers" \
    "$base_url/4fach/showpic.php?file=$stored_attachment&width=160&height=80"
if ! grep -qi '^Content-Type: image/png' "$headers"; then
    printf 'HTTP smoke: attachment preview is not a PNG response\n' >&2
    exit 1
fi
preview_magic=$(od -An -tx1 -N8 "$body" | tr -d ' \n')
if [ "$preview_magic" != '89504e470d0a1a0a' ]; then
    printf 'HTTP smoke: attachment preview has an invalid PNG signature\n' >&2
    exit 1
fi
assert_status 400 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --get \
    --data-urlencode "file[]=$stored_attachment" \
    --data-urlencode 'width=160' \
    "$base_url/4fach/showpic.php"
assert_body 'Ungültiger Anhangname'
assert_body_absent 'Warning:'

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$attachment_menu_csrf_token" \
    --data-urlencode "attachment_flow=$attachment_flow" \
    --data-urlencode 'ah_auswahl_x=1' \
    --data-urlencode "lfd_999=$stored_attachment" \
    "$base_url/4fach/anhang.php"
assert_body "value=\"$stored_attachment;\""
assert_body 'name="task" value="Stab_schreiben"'
assert_body "name=\"11_rufnummer\" value=\"$workflow_phone\""
assert_body "name=\"12_betreff\" value=\"$workflow_subject\""
workflow_recipient_matrix_revision=$(recipient_matrix_revision_from_body)
workflow_attachment_request_token=$(
    message_attachment_request_token_from_body
)
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$workflow_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$workflow_recipient_matrix_revision" \
    --data-urlencode \
        "message_attachment_request_token=$workflow_attachment_request_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=' \
    --data-urlencode '10_anschrift=HTTP-Integrationsempfänger' \
    --data-urlencode "11_rufnummer=$workflow_phone" \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode "12_anhang=$stored_attachment;" \
    --data-urlencode "12_betreff=$workflow_subject" \
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

# A completed staff conversation note must first exercise the real application
# generator and publish a downloadable PDF. The test then replaces only this
# disposable archive with a valid, deliberately stale marker PDF: the current
# layout must ignore that marker while the archive's exact byte sequence is
# carried through the later backup/restore roundtrip.
vordruck_marker="${workflow_marker}_VORDRUCK"
forged_vordruck_marker="${workflow_marker}_FORGED_NOTE"
vordruck_subject='HTTP Gesprächsnotiz'

# Use one otherwise empty matrix cell to prove that coordinate binding and
# recipient rehydration preserve a legitimate function containing an
# underscore. The EXIT trap restores the exact fresh-install cell even when an
# assertion aborts this disposable test halfway through.
conversation_matrix_cell=$(
    printf '%s\n' \
        "SELECT CONCAT(mtx_x, ':', mtx_y, ':', mtx_typ, ':', mtx_fkt,
                       ':', mtx_rolle, ':', mtx_mode, ':', mtx_rc2,
                       ':', mtx_auto)
           FROM nv_empfmtx
          WHERE mtx_x = 5 AND mtx_y = 4;" |
        db_sql
)
case "$conversation_matrix_cell" in
    '5:4:t:::ro:f:f')
        conversation_matrix_original_rc2=f
        conversation_matrix_original_auto=f
        ;;
    '5:4:t:::ro:f:0')
        conversation_matrix_original_rc2=f
        conversation_matrix_original_auto=0
        ;;
    '5:4:t:::ro:0:f')
        conversation_matrix_original_rc2=0
        conversation_matrix_original_auto=f
        ;;
    '5:4:t:::ro:0:0')
        conversation_matrix_original_rc2=0
        conversation_matrix_original_auto=0
        ;;
    *)
        printf 'HTTP smoke: underscore-recipient fixture cell is not empty: %s\n' \
            "$conversation_matrix_cell" >&2
        exit 1
        ;;
esac
printf '%s\n' \
    "UPDATE nv_empfmtx
        SET mtx_typ = 'cb', mtx_fkt = 'AB_C', mtx_rolle = 'FB',
            mtx_mode = 'ro', mtx_rc2 = 'f', mtx_auto = 'f'
      WHERE mtx_x = 5 AND mtx_y = 4;" |
    db_sql >/dev/null
conversation_matrix_fixture_changed=1

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'stab_schreiben_x=1' \
    "$base_url/4fach/mainindex.php"
assert_body 'name="task" value="Stab_schreiben"'
assert_body \
    'aria-label="AB_C, keine Durchschrift ausgewählt, schreibgeschützt"'
assert_body_absent 'name="16_54" value="16_54_bl" type="checkbox"'
assert_body '>AB_C</span>'
workflow_csrf_token=$(csrf_from_body)
conversation_matrix_revision=$(recipient_matrix_revision_from_body)
conversation_attachment_request_token=$(
    message_attachment_request_token_from_body
)

assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$workflow_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$conversation_matrix_revision" \
    --data-urlencode \
        "message_attachment_request_token=$conversation_attachment_request_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_gesprnoti' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode '01_datum=' \
    --data-urlencode '01_zeichen=forged' \
    --data-urlencode '10_anschrift=HTTP-Fälschungstest' \
    --data-urlencode "12_inhalt=$forged_vordruck_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=Fremde Einheit' \
    --data-urlencode '14_zeichen=forged' \
    --data-urlencode '14_funktion=S6' \
    --data-urlencode '15_quitdatum=30122000' \
    --data-urlencode '15_quitzeichen=si0001' \
    "$base_url/4fach/mainindex.php"
forged_vordruck_count=$(
    printf "SELECT COUNT(*) FROM nv_nachrichten WHERE \`12_inhalt\` = '%s';\n" \
        "$forged_vordruck_marker" | db_sql
)
if [ "$forged_vordruck_count" != 0 ]; then
    printf 'HTTP smoke: forged conversation note reached persistent state\n' >&2
    exit 1
fi

# Exercise the real two-step UI transition with a direct attachment.
# Browser-supplied author, organisation and fictitious review marks from the
# originating staff form must be replaced. Repeating that exact multipart POST
# must rebuild Stab_gesprnoti and never downgrade it to an ordinary message.
conversation_attachment_comment="Gesprächsnotiz ${workflow_marker}"
conversation_attachment_before=$(
    printf "SELECT COUNT(*) FROM nv_anhang WHERE BINARY comment = BINARY '%s' AND BINARY kuerzel = BINARY '%s';\n" \
        "$conversation_attachment_comment" "$test_code" | db_sql
)
submit_conversation_attachment_stage() {
    assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --form "csrf_token=$workflow_csrf_token" \
        --form \
            "recipient_matrix_revision=$conversation_matrix_revision" \
        --form \
            "message_attachment_request_token=$conversation_attachment_request_token" \
        --form 'absenden_x=1' \
        --form 'task=Stab_schreiben' \
        --form '01_medium=Fu' \
        --form '01_datum=' \
        --form '01_zeichen=forged' \
        --form '10_anschrift=HTTP-Vordruckempfänger' \
        --form '11_rufnummer=' \
        --form '11_gesprnotiz=on' \
        --form '12_anhang=' \
        --form "12_betreff=$vordruck_subject" \
        --form "12_inhalt=$vordruck_marker" \
        --form "12_abfzeit=$tactical_time" \
        --form '13_abseinheit=Browserseitig gefälschte Einheit' \
        --form "14_zeichen=$test_code" \
        --form "14_funktion=$test_function" \
        --form '15_quitdatum=30122000' \
        --form '15_quitzeichen=si0001' \
        --form '16_gncopy=' \
        --form '16_empf=' \
        --form '17_vermerke=Backup-Restore-Nachweis' \
        --form "message_attachment_comment=$conversation_attachment_comment" \
        --form \
            "message_attachment_upload=@$direct_staff_upload_file;type=image/jpeg;filename=Gesprächsnotiz.JPEG" \
        "$base_url/4fach/mainindex.php"
}
submit_conversation_attachment_stage
assert_body 'name="task" value="Stab_gesprnoti"'
assert_body 'id="f_01_zeichen" data-estab-readonly="true"'
assert_body_absent 'Browserseitig gefälschte Einheit'
assert_body_absent 'name="15_quitdatum"'
assert_body_absent 'name="15_quitzeichen"'
assert_body_absent 'name="16_gncopy"'
assert_body 'name="16_54" value="16_54_bl" type="checkbox"'
conversation_attachment_reference=$(sed -n \
    's/.*id="f_12_anhang" type="hidden" name="12_anhang" value="\([A-Za-z0-9_.;-][A-Za-z0-9_.;-]*\)".*/\1/p' \
    "$body" | head -n 1)
if ! printf '%s' "$conversation_attachment_reference" |
    grep -Eq '^[A-Za-z]{2}[0-9]{4,}\.jpeg;$'; then
    printf 'HTTP smoke: conversation-note transition lost its attachment\n' >&2
    exit 1
fi
submit_conversation_attachment_stage
assert_body 'name="task" value="Stab_gesprnoti"'
assert_body 'Übergang zur Gesprächsnotiz wurde bereits vorbereitet'
assert_body "value=\"$conversation_attachment_reference\""
conversation_attachment_after=$(
    printf "SELECT COUNT(*) FROM nv_anhang WHERE BINARY comment = BINARY '%s' AND BINARY kuerzel = BINARY '%s';\n" \
        "$conversation_attachment_comment" "$test_code" | db_sql
)
if [ "$conversation_attachment_after" != \
        "$((conversation_attachment_before + 1))" ]; then
    printf 'HTTP smoke: conversation-note transition replay duplicated its attachment\n' >&2
    exit 1
fi
staged_note_csrf_token=$(csrf_from_body)
staged_note_matrix_revision=$(recipient_matrix_revision_from_body)
staged_note_attachment_request_token=$(
    message_attachment_request_token_from_body
)
if [ "$staged_note_matrix_revision" = \
    "$workflow_recipient_matrix_revision" ]; then
    printf '%s\n' \
        'HTTP smoke: recipient-matrix revision did not change for AB_C fixture' >&2
    exit 1
fi

# A conversation-note stage belongs to its form token, not to the shared PHP
# session. Keep the first stage open while a second browser tab starts the
# same workflow. The second tab must also reach Stab_gesprnoti and must not
# accidentally save its draft as an ordinary staff message.
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'stab_schreiben_x=1' \
    "$base_url/4fach/mainindex.php"
parallel_note_csrf_token=$(csrf_from_body)
parallel_note_matrix_revision=$(recipient_matrix_revision_from_body)
parallel_note_request_token=$(message_attachment_request_token_from_body)
parallel_note_marker="Parallel-Tab ${workflow_marker}"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$parallel_note_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$parallel_note_matrix_revision" \
    --data-urlencode \
        "message_attachment_request_token=$parallel_note_request_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode '01_datum=' \
    --data-urlencode '10_anschrift=HTTP-Parallel-Tab' \
    --data-urlencode '11_rufnummer=' \
    --data-urlencode '11_gesprnotiz=on' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_betreff=$parallel_note_marker" \
    --data-urlencode "12_inhalt=$parallel_note_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '16_empf=' \
    "$base_url/4fach/mainindex.php"
assert_body 'name="task" value="Stab_gesprnoti"'
assert_body "$parallel_note_marker"
parallel_note_count=$(
    printf "SELECT COUNT(*) FROM nv_nachrichten WHERE \`12_inhalt\` = '%s';\n" \
        "$parallel_note_marker" | db_sql
)
if [ "$parallel_note_count" != 0 ]; then
    printf '%s\n' \
        'HTTP smoke: parallel conversation-note tab persisted prematurely' >&2
    exit 1
fi

assert_status 409 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$workflow_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$workflow_recipient_matrix_revision" \
    --data-urlencode \
        "message_attachment_request_token=$staged_note_attachment_request_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_gesprnoti' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode '01_datum=' \
    --data-urlencode "01_zeichen=$test_code" \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '09_vorrangstufe=' \
    --data-urlencode '10_anschrift=HTTP-Vordruckempfänger' \
    --data-urlencode '11_rufnummer=' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode "12_anhang=$conversation_attachment_reference" \
    --data-urlencode "12_betreff=$vordruck_subject" \
    --data-urlencode "12_inhalt=$vordruck_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode "14_zeichen=$test_code" \
    --data-urlencode "14_funktion=$test_function" \
    --data-urlencode '16_54=16_54_bl' \
    --data-urlencode '17_vermerke=Backup-Restore-Nachweis' \
    "$base_url/4fach/mainindex.php"
assert_body 'Nachricht wurde zwischenzeitlich geändert'
assert_body 'Die Empfängermatrix'
assert_body_absent 'Fatal error'
assert_body_absent 'Warning:'
staged_vordruck_count=$(
    printf "SELECT COUNT(*) FROM nv_nachrichten WHERE \`12_inhalt\` = '%s';\n" \
        "$vordruck_marker" | db_sql
)
if [ "$staged_vordruck_count" != 0 ]; then
    printf 'HTTP smoke: conversation-note staging persisted prematurely\n' >&2
    exit 1
fi

# The attachment picker must return to the exact staged conversation-note
# task. It must not silently downgrade the form to a normal staff message.
assert_status 422 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$staged_note_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$staged_note_matrix_revision" \
    --data-urlencode 'anhang_plus_x=1' \
    --data-urlencode 'task=Stab_gesprnoti' \
    --data-urlencode '00_lfd=' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode '01_datum=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '10_anschrift=HTTP-Vordruckempfänger' \
    --data-urlencode '11_rufnummer=' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode "12_anhang=$conversation_attachment_reference" \
    --data-urlencode "12_betreff=$vordruck_subject" \
    --data-urlencode "12_inhalt=$vordruck_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode "14_zeichen=$test_code" \
    --data-urlencode "14_funktion=$test_function" \
    --data-urlencode '16_54=16_54_bl' \
    --data-urlencode '17_vermerke[]=unzulässiger Arraywert' \
    "$base_url/4fach/mainindex.php"
assert_body 'name="task" value="Stab_gesprnoti"'
assert_body 'Die Anhangverwaltung wurde nicht geöffnet:'
assert_body "$vordruck_marker"
assert_body_regex \
    'name="16_54" value="16_54_bl" type="checkbox"[^>]*checked' \
    '422 conversation-note underscore recipient'
assert_body_absent 'name="16_gncopy"'
assert_body_absent 'name="attachment_flow"'
assert_body_absent 'Fatal error'
assert_body_absent 'Warning:'
rejected_vordruck_count=$(
    printf "SELECT COUNT(*) FROM nv_nachrichten WHERE \`12_inhalt\` = '%s';\n" \
        "$vordruck_marker" |
        db_sql
)
if [ "$rejected_vordruck_count" != 0 ]; then
    printf '%s\n' \
        'HTTP smoke: rejected conversation-note draft reached persistent state' >&2
    exit 1
fi
staged_note_csrf_token=$(csrf_from_body)
staged_note_matrix_revision=$(recipient_matrix_revision_from_body)
staged_note_attachment_request_token=$(
    message_attachment_request_token_from_body
)

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$staged_note_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$staged_note_matrix_revision" \
    --data-urlencode 'anhang_plus_x=1' \
    --data-urlencode 'task=Stab_gesprnoti' \
    --data-urlencode '00_lfd=' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode '01_datum=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '10_anschrift=HTTP-Vordruckempfänger' \
    --data-urlencode '11_rufnummer=' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode "12_anhang=$conversation_attachment_reference" \
    --data-urlencode "12_betreff=$vordruck_subject" \
    --data-urlencode "12_inhalt=$vordruck_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode "14_zeichen=$test_code" \
    --data-urlencode "14_funktion=$test_function" \
    --data-urlencode '16_54=16_54_bl' \
    --data-urlencode '15_quitdatum=' \
    --data-urlencode '15_quitzeichen=' \
    --data-urlencode '17_vermerke=Backup-Restore-Nachweis' \
    "$base_url/4fach/mainindex.php"
assert_body 'Liste der verfügbaren Dateien'
staged_note_attachment_csrf=$(csrf_from_body)
staged_note_attachment_flow=$(attachment_flow_from_body)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$staged_note_attachment_csrf" \
    --data-urlencode \
        "attachment_flow=$staged_note_attachment_flow" \
    --data-urlencode 'ah_abbrechen_x=1' \
    "$base_url/4fach/anhang.php"
assert_body 'name="task" value="Stab_gesprnoti"'
assert_body "$vordruck_marker"
assert_body ">$test_code</strong>"
assert_body_regex \
    'name="16_54" value="16_54_bl" type="checkbox"[^>]*checked' \
    'attachment-returned conversation-note underscore recipient'
assert_body_absent 'name="16_gncopy"'
assert_body_absent 'name="task" value="Stab_schreiben"'
staged_note_return_csrf=$(csrf_from_body)
staged_note_matrix_revision=$(recipient_matrix_revision_from_body)
staged_note_return_attachment_request_token=$(
    message_attachment_request_token_from_body
)

submit_completed_conversation_note() {
    expected_status=$1
    assert_status "$expected_status" \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$staged_note_return_csrf" \
        --data-urlencode \
            "recipient_matrix_revision=$staged_note_matrix_revision" \
        --data-urlencode \
            "message_attachment_request_token=$staged_note_return_attachment_request_token" \
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
        --data-urlencode '11_rufnummer=' \
        --data-urlencode '11_gesprnotiz=f' \
        --data-urlencode "12_anhang=$conversation_attachment_reference" \
        --data-urlencode "12_betreff=$vordruck_subject" \
        --data-urlencode "12_inhalt=$vordruck_marker" \
        --data-urlencode "12_abfzeit=$tactical_time" \
        --data-urlencode '13_abseinheit=HTTP-Vordrucktest' \
        --data-urlencode "14_zeichen=$test_code" \
        --data-urlencode "14_funktion=$test_function" \
        --data-urlencode '15_quitdatum=' \
        --data-urlencode '15_quitzeichen=' \
        --data-urlencode '16_54=16_54_bl' \
        --data-urlencode '16_empf=' \
        --data-urlencode '17_vermerke=Backup-Restore-Nachweis' \
        "$base_url/4fach/mainindex.php"
}
submit_completed_conversation_note 200
if grep -Eq 'Fatal error|Uncaught (Error|TypeError)|Warning:' "$body"; then
    printf 'HTTP smoke: PHP runtime error leaked while generating a form\n' >&2
    exit 1
fi
submit_completed_conversation_note 303
conversation_evidence_count=$(
    printf "SELECT COUNT(*) FROM nv_nachrichten n JOIN nv_nachrichten_ereignisse e ON e.message_id = n.\`00_lfd\` AND e.einsatz_id = n.einsatz_id WHERE n.\`12_inhalt\` = '%s' AND n.\`01_zeichen\` = '%s' AND n.\`14_zeichen\` = '%s' AND n.\`14_funktion\` = '%s' AND COALESCE(n.\`15_quitzeichen\`, '') = '' AND COALESCE(n.\`15_quitdatum\`, '') = '' AND e.event_type = 'conversation_note_created' AND e.actor_code = '%s' AND e.actor_function = '%s' AND JSON_UNQUOTE(JSON_EXTRACT(e.field_snapshot, '$.object_type')) = 'conversation_note' AND JSON_UNQUOTE(JSON_EXTRACT(e.field_snapshot, '$.author_code')) = '%s' AND JSON_EXTRACT(e.field_snapshot, '$.review_required') = FALSE;\n" \
        "$vordruck_marker" "$test_code" "$test_code" "$test_function" \
        "$test_code" "$test_function" "$test_code" | db_sql
)
if [ "$conversation_evidence_count" != 1 ]; then
    printf 'HTTP smoke: conversation-note actor/mark evidence is inconsistent\n' >&2
    exit 1
fi
conversation_distribution=$(
    printf "SELECT \`16_empf\` FROM nv_nachrichten WHERE \`12_inhalt\` = '%s';\n" \
        "$vordruck_marker" |
        db_sql
)
if [ "$conversation_distribution" != \
    "S2_rt,${test_function}_gn,AB_C_bl," ]; then
    printf '%s\n' \
        'HTTP smoke: conversation-note red/author-green/underscore-blue distribution differs' >&2
    printf 'actual: %s\n' "$conversation_distribution" >&2
    exit 1
fi
conversation_attachment_stored=$(
    printf "SELECT \`12_anhang\` FROM nv_nachrichten WHERE \`12_inhalt\` = '%s';\n" \
        "$vordruck_marker" |
        db_sql
)
if [ "$conversation_attachment_stored" != \
        "$conversation_attachment_reference" ]; then
    printf 'HTTP smoke: replay-safe conversation note lost its attachment\n' >&2
    exit 1
fi

printf '%s\n' \
    "UPDATE nv_empfmtx
        SET mtx_typ = 't', mtx_fkt = '', mtx_rolle = '',
            mtx_mode = 'ro',
            mtx_rc2 = '$conversation_matrix_original_rc2',
            mtx_auto = '$conversation_matrix_original_auto'
      WHERE mtx_x = 5 AND mtx_y = 4;" |
    db_sql >/dev/null
conversation_matrix_fixture_changed=0

stored_vordruck=$(vordruck_name_for_marker "$vordruck_marker")
if ! printf '%s' "$stored_vordruck" |
    grep -Eq '^[A-Za-z0-9_]+ Einsatz-[1-9][0-9]* [1-9][0-9]* [EA][.]pdf$'; then
    printf 'HTTP smoke: completed workflow produced no safe generated-form name\n' >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_body "$stored_vordruck"
assert_body 'layout=current'
assert_body 'PDF im aktuellen Layout öffnen'
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
generated_vordruck_sha256=$(file_sha256 "$body")
stage_stale_vordruck_archive "$stored_vordruck"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --get \
    --data-urlencode 'area=vordruck' \
    --data-urlencode "file=$stored_vordruck" \
    "$base_url/4fach/download.php"
assert_pdf_body
assert_body 'ARCHIVE-ONLY-VS-NfD'
stored_vordruck_sha256=$(file_sha256 "$body")
if [ "$stored_vordruck_sha256" = "$generated_vordruck_sha256" ]; then
    printf 'HTTP smoke: stale generated-form fixture did not replace archive\n' >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --get \
    --data-urlencode 'area=vordruck' \
    --data-urlencode "file=$stored_vordruck" \
    --data-urlencode 'layout=current' \
    "$base_url/4fach/download.php"
assert_pdf_body
assert_body_absent 'ARCHIVE-ONLY-VS-NfD'
for header_pattern in \
    '^Content-Type: application/pdf' \
    '^Content-Disposition: inline;' \
    '^X-eStab-PDF-Layout: current'
do
    if ! grep -Eiq "$header_pattern" "$headers"; then
        printf 'HTTP smoke: current-layout form header missing: %s\n' \
            "$header_pattern" >&2
        exit 1
    fi
done
assert_status 400 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --get \
    --data-urlencode 'area=vordruck' \
    --data-urlencode "file=$stored_vordruck" \
    --data-urlencode 'view=inline' \
    "$base_url/4fach/download.php"
assert_body 'Ungültige Dateianforderung'
assert_status 400 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --get \
    --data-urlencode 'area=vordruck' \
    --data-urlencode "file=$stored_vordruck" \
    --data-urlencode 'layout=archive' \
    "$base_url/4fach/download.php"
assert_body 'Ungültige Dateianforderung'
assert_status 400 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --get \
    --data-urlencode 'area=vordruck' \
    --data-urlencode "file=$stored_vordruck" \
    --data-urlencode 'layout=' \
    "$base_url/4fach/download.php"
assert_body 'Ungültige Dateianforderung'
assert_status 400 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --get \
    --data-urlencode 'area=attachment' \
    --data-urlencode "file=$stored_attachment" \
    --data-urlencode 'layout=current' \
    "$base_url/4fach/download.php"
assert_body 'Ungültige Dateianforderung'
assert_status 400 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --get \
    --data-urlencode 'area=vordruck' \
    --data-urlencode "file=$stored_vordruck" \
    --data-urlencode 'layout[]=current' \
    "$base_url/4fach/download.php"
assert_body 'Ungültige Dateianforderung'
assert_status 404 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --get \
    --data-urlencode 'area=vordruck' \
    --data-urlencode 'file=nicht-kanonisch.pdf' \
    "$base_url/4fach/download.php"
assert_body 'Datei nicht gefunden'

# Raw UTF-8 message storage must preserve punctuation and SQL-shaped text,
# while every HTML list/search reflection remains inert.
security_payload="MSGSEC 'quoted' \"double\" & <script>alert(\"x\")</script> ' OR 1=1 --"
security_subject='Sicherer UTF-8-Betreff äöü'
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST --data-urlencode 'stab_schreiben_x=1' \
    "$base_url/4fach/mainindex.php"
assert_body 'name="task" value="Stab_schreiben"'
security_message_csrf_token=$(csrf_from_body)
security_message_matrix_revision=$(recipient_matrix_revision_from_body)
security_message_attachment_request_token=$(
    message_attachment_request_token_from_body
)
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$security_message_csrf_token" \
    --data-urlencode \
        "recipient_matrix_revision=$security_message_matrix_revision" \
    --data-urlencode \
        "message_attachment_request_token=$security_message_attachment_request_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=' \
    --data-urlencode "10_anschrift=Security ' & Empfänger" \
    --data-urlencode '11_rufnummer=' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_betreff=$security_subject" \
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

# The fixed S1 account may work with its own messages and read both books, but
# it does not inherit LdF/A-W transport rights or the S2 Lageübersicht.
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/nachwea.php?nwalle=1"
assert_body_absent 'Nachweisung Eingang / Ausgang'
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fueltg/ue_ltg.php"
assert_body_absent "$workflow_marker"
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/stabetb/etb.php"
assert_body 'Ihre Funktion hat lesenden Zugriff.'
assert_body_absent 'value="save_entry"'
assert_body_absent 'Neuen ETB-Eintrag anlegen'
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"

assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/fmtbb/tbb.php"
assert_body 'Ihre Funktion hat lesenden Zugriff.'
assert_body_absent 'value="save_entry"'
assert_body_absent 'Neuen TBB-Eintrag anlegen'
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

# Presence is based on genuine browser interaction, not on the sidebar's
# automatic status polling. Keep the authoritative session valid but make it
# old enough to cross the 15-minute display boundary, then prove repeated
# polling neither refreshes the timestamp nor disguises the inactive state.
activity_backdate_rows=$(
    printf "UPDATE nv_benutzer SET estab_letzte_aktivitaet = UTC_TIMESTAMP(6) - INTERVAL 16 MINUTE WHERE kuerzel = '%s' AND aktiv = 1; SELECT ROW_COUNT();\n" \
        "$test_code" | db_sql | tail -n 1
)
if [ "$activity_backdate_rows" != 1 ]; then
    printf 'HTTP smoke: could not backdate the active presence fixture\n' >&2
    exit 1
fi
inactive_activity_before=$(account_activity_timestamp "$test_code")
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vorgaben.php?fragment=status"
assert_body 'data-estab-current-activity="inactive"'
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vorgaben.php?fragment=status"
assert_body 'data-estab-current-activity="inactive"'
if [ "$(account_activity_timestamp "$test_code")" != "$inactive_activity_before" ]; then
    printf 'HTTP smoke: sidebar status polling refreshed user activity\n' >&2
    exit 1
fi

# A forged activity report must be rejected without altering the timestamp.
# The session-bound token then proves that the dedicated endpoint records a
# real interaction and immediately returns the current account to "online".
case "$older_logout_csrf" in
    0*) wrong_activity_csrf="1${older_logout_csrf#?}" ;;
    *) wrong_activity_csrf="0${older_logout_csrf#?}" ;;
esac
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$wrong_activity_csrf" \
    "$base_url/4fach/activity.php"
if [ "$(account_activity_timestamp "$test_code")" != "$inactive_activity_before" ]; then
    printf 'HTTP smoke: rejected activity report changed the timestamp\n' >&2
    exit 1
fi
assert_status 204 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$older_logout_csrf" \
    "$base_url/4fach/activity.php"
if [ "$(account_activity_is_recent "$test_code")" != 1 ]; then
    printf 'HTTP smoke: accepted activity report did not refresh presence\n' >&2
    exit 1
fi
assert_status 200 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/vorgaben.php?fragment=status"
assert_body 'data-estab-current-activity="online"'

assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/status.php"
assert_status 403 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/4fach/counter.php"
assert_status 403 --cookie "$cookie_jar" \
    "$base_url/4fach/status.php?embedded=1"
assert_status 403 --cookie "$cookie_jar" \
    "$base_url/4fach/counter.php?embedded=1"

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
assert_status 200 \
    --cookie "$newer_cookie_jar" --cookie-jar "$newer_cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
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
assert_status 303 --cookie "$cookie_jar" "$base_url/4fach/vordrucke.php"
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

# A third browser now supersedes the still-active second browser. Unlike the
# deliberately permitted stale logout above, its next protected data request
# must validate the authoritative SID and clear its local workflow state. A
# stale browser-form POST is discarded and sent to a frame-safe login instead
# of leaving the user on a raw denial page. The third browser remains usable
# and can log out normally.
current_cookie_jar=$work_dir/current-cookies.txt
assert_status 200 --cookie "$current_cookie_jar" --cookie-jar "$current_cookie_jar" \
    --request POST --data-urlencode 'login_flow=existing' \
    "$base_url/4fach/mainindex.php"
current_preauth_csrf=$(csrf_from_body)
assert_status 200 --cookie "$current_cookie_jar" --cookie-jar "$current_cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$current_preauth_csrf" \
    --data-urlencode 'login_flow=existing' \
    --data-urlencode "benutzer=$test_name" \
    --data-urlencode "kuerzel=$test_code" \
    --data-urlencode "funktion=$test_function" \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode '2teskennwort=No' \
    --data-urlencode 'absenden_x=1' \
    "$base_url/4fach/mainindex.php"
assert_status 200 \
    --cookie "$current_cookie_jar" --cookie-jar "$current_cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
current_logout_csrf=$(csrf_from_body)
current_authenticated_session_id=$(session_cookie_from_jar "$current_cookie_jar")
if [ -z "$current_authenticated_session_id" ] ||
    [ "$current_authenticated_session_id" = "$newer_authenticated_session_id" ]; then
    printf 'HTTP smoke: superseding login did not establish a distinct session\n' >&2
    exit 1
fi

expired_message_marker="EXPIRED_MESSAGE_POST_MUST_NOT_PERSIST_$$"
expired_message_before=$(
    printf "SELECT COUNT(*) FROM nv_nachrichten WHERE \`12_inhalt\` = '%s';\n" \
        "$expired_message_marker" | db_sql
)
assert_status 303 \
    --cookie "$newer_cookie_jar" --cookie-jar "$newer_cookie_jar" \
    --header 'Sec-Fetch-Site: same-origin' \
    --request POST \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode "12_inhalt=$expired_message_marker" \
    "$base_url/4fach/mainindex.php"
assert_header_fixed \
    "Location: $expected_app_root/4fach/mainindex.php?login_flow=existing&next=messages&interrupted=1"
assert_body_absent 'Aktion nicht erlaubt'
expired_message_after=$(
    printf "SELECT COUNT(*) FROM nv_nachrichten WHERE \`12_inhalt\` = '%s';\n" \
        "$expired_message_marker" | db_sql
)
if [ "$expired_message_after" != "$expired_message_before" ]; then
    printf 'HTTP smoke: expired message POST mutated data: %s -> %s\n' \
        "$expired_message_before" "$expired_message_after" >&2
    exit 1
fi
assert_status 200 \
    --cookie "$newer_cookie_jar" --cookie-jar "$newer_cookie_jar" \
    "$base_url/4fach/mainindex.php?login_flow=existing&next=messages&interrupted=1"
assert_body 'data-estab-submission-discarded'
assert_body 'Die Eingabe wurde nicht gespeichert.'
assert_body 'data-estab-auth-cancel'

assert_status 303 --cookie "$newer_cookie_jar" --cookie-jar "$newer_cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_status 200 --cookie "$current_cookie_jar" --cookie-jar "$current_cookie_jar" \
    "$base_url/4fach/vordrucke.php"
assert_session_bar "$test_name" "$test_code" "$test_function" "$test_role"
if [ "$(account_assignment "$test_code")" != "$(printf '%s\t%s\t1' "$test_function" "$test_role")" ]; then
    printf 'HTTP smoke: stale protected request deactivated the current login\n' >&2
    exit 1
fi
if [ "$(logout_audit_count "$test_code")" -ne "$((logout_audit_before + 1))" ]; then
    printf 'HTTP smoke: stale protected request wrote a logout audit event\n' >&2
    exit 1
fi

assert_status 303 --cookie "$current_cookie_jar" --cookie-jar "$current_cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$current_logout_csrf" \
    --data-urlencode 'logout_action=logout' \
    "$base_url/4fach/logout.php"
assert_status 303 --cookie "$current_cookie_jar" "$base_url/4fach/vordrucke.php"
if [ "$(account_assignment "$test_code")" != "$(printf '%s\t%s\t0' "$test_function" "$test_role")" ]; then
    printf 'HTTP smoke: current-session logout did not deactivate the account\n' >&2
    exit 1
fi
if [ "$(logout_audit_count "$test_code")" -ne "$((logout_audit_before + 2))" ]; then
    printf 'HTTP smoke: current-session logout audit event missing\n' >&2
    exit 1
fi
if [ "$(logout_audit_reference_count "$test_code" "$current_authenticated_session_id")" -ne 1 ]; then
    printf 'HTTP smoke: current-session logout audit lacks its hashed session reference\n' >&2
    exit 1
fi
assert_status 200 --cookie "$current_cookie_jar" "$base_url/"
assert_body 'id="estab-login"'
assert_body_absent 'data-estab-session-bar'

# Verify the authoritative 12-hour idle timeout on a dedicated account so the
# preceding multi-browser and logout-audit scenarios remain independent. The
# status endpoint must reject the expired browser and revoke its reusable SID
# before any subsequent protected route redirects to the normal login flow.
idle_account_name='HTTP Idle Timeout Integration'
idle_account_code=idle01
sh tests/integration/provision_user.sh \
    "$idle_account_name" "$idle_account_code" S3 "$test_password"
idle_cookie_jar=$work_dir/idle-cookies.txt
assert_status 200 --cookie "$idle_cookie_jar" --cookie-jar "$idle_cookie_jar" \
    --request POST --data-urlencode 'login_flow=existing' \
    "$base_url/4fach/mainindex.php"
idle_preauth_csrf=$(csrf_from_body)
assert_status 200 --cookie "$idle_cookie_jar" --cookie-jar "$idle_cookie_jar" \
    --request POST \
    --data-urlencode "csrf_token=$idle_preauth_csrf" \
    --data-urlencode 'login_flow=existing' \
    --data-urlencode "benutzer=$idle_account_name" \
    --data-urlencode "kuerzel=$idle_account_code" \
    --data-urlencode 'funktion=S3' \
    --data-urlencode "kennwort1@$login_password_file" \
    --data-urlencode '2teskennwort=No' \
    --data-urlencode 'absenden_x=1' \
    "$base_url/4fach/mainindex.php"
if [ "$(account_session_storage "$idle_account_code")" != '1|set' ] ||
    [ "$(account_activity_is_recent "$idle_account_code")" != 1 ]; then
    printf 'HTTP smoke: dedicated idle account did not establish a fresh session\n' >&2
    exit 1
fi
idle_backdate_rows=$(
    printf "UPDATE nv_benutzer SET estab_letzte_aktivitaet = UTC_TIMESTAMP(6) - INTERVAL 43200 SECOND WHERE kuerzel = '%s' AND aktiv = 1; SELECT ROW_COUNT();\n" \
        "$idle_account_code" | db_sql | tail -n 1
)
if [ "$idle_backdate_rows" != 1 ]; then
    printf 'HTTP smoke: could not backdate the idle-timeout fixture\n' >&2
    exit 1
fi
assert_status 401 --cookie "$idle_cookie_jar" --cookie-jar "$idle_cookie_jar" \
    "$base_url/4fach/vorgaben.php?fragment=status"
if [ "$(account_session_storage "$idle_account_code")" != '0|empty' ]; then
    printf 'HTTP smoke: expired idle session retained its active SID\n' >&2
    exit 1
fi
assert_status 303 --cookie "$idle_cookie_jar" --cookie-jar "$idle_cookie_jar" \
    "$base_url/4fach/vordrucke.php"
if ! grep -Eiq '^Location: .*4fach/index[.]php[?]login_flow=existing' "$headers"; then
    printf 'HTTP smoke: expired idle session did not return to login\n' >&2
    sed -n '1,30p' "$headers" >&2
    exit 1
fi

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
    assert_body 'data-estab-admin-dashboard'
    assert_body 'data-estab-admin-card="incidents"'
    assert_body 'data-estab-admin-card="users"'
    assert_body 'data-estab-admin-card="password-policy"'
    assert_body 'data-estab-admin-card="command-post"'
    assert_body 'data-estab-admin-card="matrix"'
    assert_body 'data-estab-admin-card="counter"'
    assert_body 'data-estab-admin-card="print-reset"'
    assert_body 'data-estab-admin-card="incident-pdf"'
    assert_body 'data-estab-admin-card="export"'
    assert_body 'data-estab-admin-card="system-status"'
    assert_body '<h1>Administration</h1>'
    assert_body 'Einsatzexport'
    assert_body 'data-estab-public-bar'
    assert_body 'data-estab-navigation'
    assert_body 'Administrationszugang'
    assert_body "data-estab-admin-user=\"$ESTAB_TEST_ADMIN_USER\""
    assert_body 'Kein eStab-Funktionskonto angemeldet'
    assert_body 'data-estab-nav-key="administration" aria-current="page"'
    assert_body_absent 'data-estab-session-bar'
    assert_body_absent 'Administrative Maßnahmen</th>'

    admin_cookie=$work_dir/admin-cookies.txt
    assert_status 401 \
        "$base_url/4fadm/incidents.php"
    assert_status 401 \
        "$base_url/4fadm/users.php"
    assert_status 401 \
        "$base_url/4fadm/password_policy.php"
    assert_status 401 \
        "$base_url/4fadm/fuehrungsstelle.php"
    assert_status 401 \
        "$base_url/4fadm/incident_export.php"
    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/incidents.php"
    assert_body 'data-estab-incident-admin'
    assert_body 'Einsätze verwalten'
    assert_body 'name="fuehrungsstellenname"'
    assert_body 'maxlength="128"'
    assert_body 'CI-Führungsstelle Nord'

    # Expired or forged admin forms are authorization failures, not server
    # errors. These requests stop before incident lookup, export rendering,
    # audit, or any other domain mutation.
    assert_status 403 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode 'admin_action=create' \
        "$base_url/4fadm/incidents.php"
    assert_body 'Formularsitzung ist ungültig oder abgelaufen'
    assert_status 403 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode \
            'csrf_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
        --data-urlencode 'einsatz_id=1' \
        --data-urlencode 'include_messages=1' \
        "$base_url/4fadm/incident_export.php"
    assert_body 'Formularsitzung ist ungültig oder abgelaufen'

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/users.php"
    assert_body 'data-estab-user-admin'
    assert_body 'Benutzerverwaltung'
    assert_body 'Kennwortrichtlinie konfigurieren'
    assert_body 'estab-password-policy-requirements'

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/password_policy.php"
    assert_body 'data-estab-password-policy'
    assert_body 'Aktuell wirksame Richtlinie'
    assert_body 'Die Änderung gilt nur für künftig gesetzte Kennwörter.'
    assert_body 'name="minimum_length"'
    assert_body 'name="require_uppercase"'
    assert_body 'name="require_lowercase"'
    assert_body 'name="require_digit"'
    assert_body 'name="require_symbol"'
    assert_body 'separate technische Administrationskennwort'
    csrf_from_body >/dev/null

    # Optional access shifts are incident-scoped admission groups, not duty
    # functions. First prove an unassigned fixed-function account may log in.
    access_shift_cookie=$work_dir/access-shift-cookies.txt
    : > "$access_shift_cookie"
    assert_status 200 \
        --cookie "$access_shift_cookie" --cookie-jar "$access_shift_cookie" \
        "$base_url/4fach/mainindex.php?login_flow=existing"
    access_login_csrf=$(csrf_from_body)
    assert_status 200 \
        --cookie "$access_shift_cookie" --cookie-jar "$access_shift_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$access_login_csrf" \
        --data-urlencode 'login_flow=existing' \
        --data-urlencode "benutzer=$idle_account_name" \
        --data-urlencode "kuerzel=$idle_account_code" \
        --data-urlencode 'funktion=S3' \
        --data-urlencode "kennwort1@$login_password_file" \
        --data-urlencode '2teskennwort=No' \
        --data-urlencode 'absenden_x=1' \
        "$base_url/4fach/mainindex.php"
    assert_status 200 \
        --cookie "$access_shift_cookie" --cookie-jar "$access_shift_cookie" \
        "$base_url/4fach/vordrucke.php"
    assert_session_bar "$idle_account_name" "$idle_account_code" S3 Stab
    access_memberships_before=$(printf '%s\n' \
        "SELECT COUNT(*) FROM nv_zugangsschicht_mitglieder WHERE BINARY benutzer_kuerzel = BINARY '${idle_account_code}' AND entfernt_am IS NULL;" |
        db_sql | tr -d '\r\n')
    if [ "$access_memberships_before" != 0 ]; then
        printf 'HTTP smoke: access-shift account was not initially unassigned\n' >&2
        exit 1
    fi

    # A newly created group starts disabled. Assigning the live account to its
    # only disabled group must revoke that session immediately.
    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/fuehrungsstelle.php"
    assert_body 'data-estab-shift-admin'
    assert_body 'Optionale Schichten'
    assert_body 'Schicht ist dafür niemals erforderlich.'
    access_shift_csrf=$(csrf_from_body)
    access_shift_label="HTTP Zugang $$"
    assert_status 303 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$access_shift_csrf" \
        --data-urlencode 'admin_action=create_shift' \
        --data-urlencode "bezeichnung=$access_shift_label" \
        "$base_url/4fadm/fuehrungsstelle.php"
    assert_header_fixed \
        'Location: fuehrungsstelle.php?result=shift_created'
    access_shift_id=$(printf '%s\n' \
        "SELECT zugangsschicht_id FROM nv_zugangsschichten WHERE einsatz_id = (SELECT active_einsatz_id FROM nv_einsatz_status WHERE singleton_id = 1) AND bezeichnung = '${access_shift_label}' ORDER BY zugangsschicht_id DESC LIMIT 1;" |
        db_sql | tr -d '\r\n')
    if ! printf '%s' "$access_shift_id" | grep -Eq '^[1-9][0-9]*$'; then
        printf 'HTTP smoke: optional access shift was not created\n' >&2
        exit 1
    fi
    access_shift_state=$(printf '%s\n' \
        "SELECT zugang_aktiv FROM nv_zugangsschichten WHERE zugangsschicht_id = ${access_shift_id};" |
        db_sql | tr -d '\r\n')
    if [ "$access_shift_state" != 0 ]; then
        printf 'HTTP smoke: optional access shift did not start disabled\n' >&2
        exit 1
    fi

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/fuehrungsstelle.php"
    assert_body "$access_shift_label"
    access_shift_csrf=$(csrf_from_body)
    assert_status 303 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$access_shift_csrf" \
        --data-urlencode 'admin_action=add_member' \
        --data-urlencode "zugangsschicht_id=$access_shift_id" \
        --data-urlencode "benutzer_kuerzel=$idle_account_code" \
        --data-urlencode 'confirm_assignment=1' \
        "$base_url/4fadm/fuehrungsstelle.php"
    assert_header_fixed \
        'Location: fuehrungsstelle.php?result=member_added_revoked'
    if [ "$(account_session_storage "$idle_account_code")" != '0|empty' ]; then
        printf 'HTTP smoke: disabled group assignment did not revoke the session\n' >&2
        exit 1
    fi
    assert_status 303 \
        --cookie "$access_shift_cookie" --cookie-jar "$access_shift_cookie" \
        "$base_url/4fach/vordrucke.php"

    # The only membership is disabled, therefore fresh credentials are denied
    # with a helpful explanation and the account remains inactive.
    : > "$access_shift_cookie"
    assert_status 200 \
        --cookie "$access_shift_cookie" --cookie-jar "$access_shift_cookie" \
        "$base_url/4fach/mainindex.php?login_flow=existing"
    access_login_csrf=$(csrf_from_body)
    assert_status 200 \
        --cookie "$access_shift_cookie" --cookie-jar "$access_shift_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$access_login_csrf" \
        --data-urlencode 'login_flow=existing' \
        --data-urlencode "benutzer=$idle_account_name" \
        --data-urlencode "kuerzel=$idle_account_code" \
        --data-urlencode 'funktion=S3' \
        --data-urlencode "kennwort1@$login_password_file" \
        --data-urlencode '2teskennwort=No' \
        "$base_url/4fach/mainindex.php"
    assert_body 'über die optionale Schichtplanung derzeit deaktiviert'
    if [ "$(account_session_storage "$idle_account_code")" != '0|empty' ]; then
        printf 'HTTP smoke: denied group login activated the account\n' >&2
        exit 1
    fi

    # Enabling the group only permits the next login. It must not synthesize a
    # login or alter the account's fixed S3/Stab fachliche assignment.
    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/fuehrungsstelle.php"
    access_shift_csrf=$(csrf_from_body)
    access_shift_version=$(shift_confirmation_version_from_body)
    assert_status 303 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$access_shift_csrf" \
        --data-urlencode 'admin_action=set_enabled' \
        --data-urlencode "zugangsschicht_id=$access_shift_id" \
        --data-urlencode 'expected_enabled=0' \
        --data-urlencode "expected_confirmation_version=$access_shift_version" \
        --data-urlencode 'zugang_aktiv=1' \
        "$base_url/4fadm/fuehrungsstelle.php"
    assert_header_fixed \
        'Location: fuehrungsstelle.php?result=shift_enabled'
    if [ "$(account_session_storage "$idle_account_code")" != '0|empty' ]; then
        printf 'HTTP smoke: enabling access group logged the account in\n' >&2
        exit 1
    fi
    if [ "$(account_assignment "$idle_account_code")" != \
        "$(printf 'S3\tStab\t0')" ]; then
        printf 'HTTP smoke: access group changed the fixed account function\n' >&2
        exit 1
    fi

    : > "$access_shift_cookie"
    assert_status 200 \
        --cookie "$access_shift_cookie" --cookie-jar "$access_shift_cookie" \
        "$base_url/4fach/mainindex.php?login_flow=existing"
    access_login_csrf=$(csrf_from_body)
    assert_status 200 \
        --cookie "$access_shift_cookie" --cookie-jar "$access_shift_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$access_login_csrf" \
        --data-urlencode 'login_flow=existing' \
        --data-urlencode "benutzer=$idle_account_name" \
        --data-urlencode "kuerzel=$idle_account_code" \
        --data-urlencode 'funktion=S3' \
        --data-urlencode "kennwort1@$login_password_file" \
        --data-urlencode '2teskennwort=No' \
        "$base_url/4fach/mainindex.php"
    assert_status 200 \
        --cookie "$access_shift_cookie" --cookie-jar "$access_shift_cookie" \
        "$base_url/4fach/vordrucke.php"
    assert_session_bar "$idle_account_name" "$idle_account_code" S3 Stab

    # Disabling the account's only active group revokes the running session.
    # Operational records are untouched; the group is solely an access gate.
    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/fuehrungsstelle.php"
    access_shift_csrf=$(csrf_from_body)
    access_shift_version=$(shift_confirmation_version_from_body)
    assert_status 303 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$access_shift_csrf" \
        --data-urlencode 'admin_action=set_enabled' \
        --data-urlencode "zugangsschicht_id=$access_shift_id" \
        --data-urlencode 'expected_enabled=1' \
        --data-urlencode "expected_confirmation_version=$access_shift_version" \
        --data-urlencode 'zugang_aktiv=0' \
        "$base_url/4fadm/fuehrungsstelle.php"
    assert_header_fixed \
        'Location: fuehrungsstelle.php?result=shift_disabled_revoked'
    if [ "$(account_session_storage "$idle_account_code")" != '0|empty' ]; then
        printf 'HTTP smoke: disabling the only active group retained the session\n' >&2
        exit 1
    fi
    assert_status 303 \
        --cookie "$access_shift_cookie" --cookie-jar "$access_shift_cookie" \
        "$base_url/4fach/vordrucke.php"

    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        "$base_url/4fadm/incident_export.php"
    assert_body 'data-estab-incident-export'
    assert_body 'PDF-Einsatzdossier'
    assert_body 'Optionale Zugangsschichten samt Zuordnungen'
    incident_pdf_csrf=$(csrf_from_body)
    incident_pdf_id=$(printf '%s\n' \
        'SELECT `active_einsatz_id` FROM `nv_einsatz_status` WHERE `singleton_id` = 1;' |
        db_sql | tr -d '\r\n')
    if ! printf '%s' "$incident_pdf_id" | grep -Eq '^[1-9][0-9]*$'; then
        printf 'HTTP smoke: PDF dossier has no active incident fixture\n' >&2
        exit 1
    fi
    incident_pdf_audit_before=$(printf '%s\n' \
        "SELECT COUNT(*) FROM nv_einsatz_ereignisse WHERE einsatz_id = ${incident_pdf_id} AND aktion = 'pdf_export';" |
        db_sql | tr -d '\r\n')
    assert_status 200 --config "$admin_curl_config" \
        --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
        --request POST \
        --data-urlencode "csrf_token=$incident_pdf_csrf" \
        --data-urlencode "einsatz_id=$incident_pdf_id" \
        --data-urlencode 'include_etb=1' \
        --data-urlencode 'include_ttb=1' \
        --data-urlencode 'include_messages=1' \
        --data-urlencode 'include_attachments=1' \
        "$base_url/4fadm/incident_export.php"
    assert_pdf_body
    for header_pattern in \
        '^Content-Type: application/pdf' \
        '^Content-Disposition: attachment;' \
        '^Cache-Control: private, no-store, max-age=0' \
        '^X-Content-Type-Options: nosniff'
    do
        if ! grep -Eiq "$header_pattern" "$headers"; then
            printf 'HTTP smoke: PDF dossier header missing: %s\n' \
                "$header_pattern" >&2
            sed -n '1,40p' "$headers" >&2
            exit 1
        fi
    done
    incident_pdf_audit_after=$(printf '%s\n' \
        "SELECT COUNT(*) FROM nv_einsatz_ereignisse WHERE einsatz_id = ${incident_pdf_id} AND aktion = 'pdf_export';" |
        db_sql | tr -d '\r\n')
    if [ "$incident_pdf_audit_after" -ne "$((incident_pdf_audit_before + 1))" ]; then
        echo 'HTTP smoke: successful PDF dossier was not audited exactly once' >&2
        exit 1
    fi

    assert_status 401 \
        "$base_url/4fadm/system_status.php"
    assert_status 200 --config "$admin_curl_config" \
        "$base_url/4fadm/system_status.php"
    assert_body 'data-estab-readiness="ready"'
    assert_body 'Gesamtzustand'
    assert_body 'Betriebsbereit'
    assert_body 'Verbindung und Lesetest'
    assert_body 'Schema, Matrix und Migrationen'
    assert_body 'Anhangsspeicher'
    assert_body 'Vordruckspeicher'
    assert_body 'Einsatzexport'
    assert_body 'data-estab-public-bar'
    assert_body "data-estab-admin-user=\"$ESTAB_TEST_ADMIN_USER\""
    assert_body_absent 'Prüfung erforderlich'

    readiness_probe_state=$(printf '%s\n' \
        "SELECT CONCAT((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'nv_empfmtx_standard'), ':', (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'estab_readiness_probe_matrix'));" |
        db_sql | tr -d '\r\n')
    if [ "$readiness_probe_state" != '1:0' ]; then
        printf 'HTTP smoke: readiness failure probe has an unsafe schema precondition\n' >&2
        exit 1
    fi
    printf '%s\n' \
        'RENAME TABLE nv_empfmtx_standard TO estab_readiness_probe_matrix;' |
        db_sql >/dev/null
    readiness_schema_renamed=1

    assert_status 503 "$base_url/health.php"
    assert_body '"schema":false'
    assert_status 200 --config "$admin_curl_config" \
        "$base_url/4fadm/system_status.php"
    assert_body 'data-estab-readiness="failed"'
    assert_body 'Gesamtzustand'
    assert_body 'Prüfung erforderlich'
    assert_body 'Schema, Matrix und Migrationen'
    assert_body 'estab-tool-badge-danger'
    assert_body 'nicht bereit'
    assert_body_absent 'data-estab-readiness="ready"'
    assert_body_absent 'Betriebsbereit'

    printf '%s\n' \
        'RENAME TABLE estab_readiness_probe_matrix TO nv_empfmtx_standard;' |
        db_sql >/dev/null
    readiness_schema_renamed=0
    assert_status 200 "$base_url/health.php"
    assert_body '"schema":true'

    readiness_message_order=$(printf '%s\n' \
        "SELECT CONCAT(GROUP_CONCAT(column_name ORDER BY ordinal_position SEPARATOR ','), ':', MAX(ordinal_position) - MIN(ordinal_position)) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'nv_nachrichten' AND column_name IN ('10_anschrift','11_rufnummer','11_gesprnotiz','12_betreff','12_anhang');" |
        db_sql | tr -d '\r\n')
    if [ "$readiness_message_order" != \
        '10_anschrift,11_rufnummer,11_gesprnotiz,12_betreff,12_anhang:4' ]; then
        printf '%s\n' \
            'HTTP smoke: message-column order probe has an unsafe schema precondition' >&2
        exit 1
    fi
    readiness_message_order_changed=1
    printf '%s\n' \
        'ALTER TABLE nv_nachrichten MODIFY COLUMN `12_anhang` TEXT NULL AFTER `12_inhalt`;' |
        db_sql >/dev/null

    assert_status 503 "$base_url/health.php"
    assert_body '"schema":false'
    verify_order_drift_failures=$(db_sql <docker/db/verify.sql | awk -F '\t' '
        NR == 1 {
            failures = 0
            for (column = 1; column <= NF; column++) {
                if ($column != "1") failures++
            }
            print failures
            exit
        }
    ')
    if [ "$verify_order_drift_failures" != 1 ]; then
        printf 'HTTP smoke: verify.sql did not isolate message-column order drift (%s failures)\n' \
            "$verify_order_drift_failures" >&2
        exit 1
    fi

    printf '%s\n' \
        'ALTER TABLE nv_nachrichten MODIFY COLUMN `12_anhang` TEXT NULL AFTER `12_betreff`;' |
        db_sql >/dev/null
    readiness_message_order_changed=0
    assert_status 200 "$base_url/health.php"
    assert_body '"schema":true'

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
    assert_body 'Anhangprüfung:'
    assert_body 'Integrität beim Eingang nicht belegbar'
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
    assert_export_zip "$workflow_marker" 'CI-Führungsstelle Nord'

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
    assert_export_zip "$workflow_marker" 'CI-Führungsstelle Nord'
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
