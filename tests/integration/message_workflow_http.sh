#!/bin/sh
set -eu

# This test creates real users, messages, dynamic state tables and generated
# forms. It may run only in the disposable Compose project created by ci.sh.
if [ "${ESTAB_MESSAGE_WORKFLOW_HTTP_TEST_ALLOW_MUTATION:-false}" != "true" ]; then
    echo 'Message workflow HTTP: mutation flag is required for a disposable stack' >&2
    exit 1
fi

project_name=${COMPOSE_PROJECT_NAME:-estab}
case "$project_name" in
    estab_ci | estab_ci_*) ;;
    *)
        echo 'Message workflow HTTP: refusing mutation outside an estab_ci project' >&2
        exit 1
        ;;
esac

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

base_url=${ESTAB_TEST_BASE_URL:-http://127.0.0.1:8080}
base_url=${base_url%/}
workflow_marker=${ESTAB_TEST_WORKFLOW_MARKER:-}
compose_engine=${ESTAB_TEST_COMPOSE_ENGINE:-docker}
account_restore_state_file=${ESTAB_TEST_ACCOUNT_RESTORE_STATE_FILE:-${ESTAB_TEST_ACTIVE_DUTY_STATE_FILE:-}}

if ! printf '%s' "$workflow_marker" | grep -Eq '^[A-Za-z0-9_:-]{1,120}$'; then
    echo 'Message workflow HTTP: workflow marker is missing or unsafe' >&2
    exit 1
fi
if [ -n "$account_restore_state_file" ]; then
    case "$account_restore_state_file" in
        /*) ;;
        *)
            echo 'Message workflow HTTP: account-restore state path must be absolute' >&2
            exit 1
            ;;
    esac
    account_restore_state_dir=$(dirname -- "$account_restore_state_file")
    if [ ! -d "$account_restore_state_dir" ] \
        || [ ! -w "$account_restore_state_dir" ]; then
        echo 'Message workflow HTTP: account-restore state directory is not writable' >&2
        exit 1
    fi
fi
case "$compose_engine" in
    docker | podman) ;;
    *)
        echo 'Message workflow HTTP: compose engine must be docker or podman' >&2
        exit 1
        ;;
esac
"$compose_engine" compose version >/dev/null
command -v openssl >/dev/null 2>&1 || {
    echo 'Message workflow HTTP: openssl is required' >&2
    exit 1
}

password_file=${ESTAB_TEST_ROLE_PASSWORD_FILE:-${ESTAB_TEST_LOGIN_PASSWORD_FILE:-}}
if [ -z "$password_file" ] || [ ! -r "$password_file" ]; then
    echo 'Message workflow HTTP: a readable role password file is required' >&2
    exit 1
fi
role_password=$(tr -d '\r\n' <"$password_file")
if [ -z "$role_password" ]; then
    echo 'Message workflow HTTP: role password must not be empty' >&2
    exit 1
fi

identity_seed=$(printf '%s:%s:%s' \
    "$project_name" "$workflow_marker" "dv-mandatory" |
    openssl dgst -sha256 -r | awk '{ print substr($1, 1, 5) }')
if ! printf '%s' "$identity_seed" | grep -Eq '^[a-f0-9]{5}$'; then
    echo 'Message workflow HTTP: could not derive isolated identities' >&2
    exit 1
fi

aw_code="w${identity_seed}"
ldf_code="d${identity_seed}"
si_code="i${identity_seed}"
s1_code="a${identity_seed}"
s2_code="l${identity_seed}"
s3_code="n${identity_seed}"
s6_code="x${identity_seed}"
pol_code="p${identity_seed}"
aw_name="Workflow A-W ${identity_seed}"
ldf_name="Workflow LdF ${identity_seed}"
si_name="Workflow Si ${identity_seed}"
s1_name="Workflow S1 ${identity_seed}"
s2_name="Workflow S2 ${identity_seed}"
s3_name="Workflow S3 ${identity_seed}"
s6_name="Workflow S6 ${identity_seed}"
pol_name="Workflow POL ${identity_seed}"
incoming_marker="E2EIN_${identity_seed}_dv"
incoming_phone="+49 711 ${identity_seed}"
incoming_subject="E2E Eingang ${identity_seed}"
outgoing_marker="E2EOUT_${identity_seed}_dv"
outgoing_subject="E2E Ausgang ${identity_seed}"
mapping_incoming_marker="E2EMAPIN_${identity_seed}_dv"
mapping_outgoing_marker="E2EMAPOUT_${identity_seed}_dv"
reply_marker="E2EREPLY_${identity_seed}_dv"
forward_marker="E2EFORWARD_${identity_seed}_dv"
fm_admin_note="FMADMIN_${identity_seed}_dv"
si_admin_note="SIADMIN_${identity_seed}_dv"

for code in \
    "$aw_code" "$ldf_code" "$si_code" "$s1_code" "$s2_code" "$s3_code" \
    "$s6_code" "$pol_code"
do
    if ! printf '%s' "$code" | grep -Eq '^[a-z0-9_]{1,6}$'; then
        echo 'Message workflow HTTP: derived an unsafe user code' >&2
        exit 1
    fi
done
for marker in \
    "$incoming_marker" "$outgoing_marker" \
    "$mapping_incoming_marker" "$mapping_outgoing_marker" \
    "$reply_marker" "$forward_marker"
do
    if ! printf '%s' "$marker" | grep -Eq '^[A-Za-z0-9_-]{1,64}$'; then
        echo 'Message workflow HTTP: derived an unsafe message marker' >&2
        exit 1
    fi
done

work_dir=$(mktemp -d /tmp/estab-message-workflow-http.XXXXXX)
chmod 0700 "$work_dir"
body=$work_dir/body.html
aw_cookies=$work_dir/aw-cookies.txt
ldf_cookies=$work_dir/ldf-cookies.txt
si_cookies=$work_dir/si-cookies.txt
s1_cookies=$work_dir/s1-cookies.txt
s2_cookies=$work_dir/s2-cookies.txt
s3_cookies=$work_dir/s3-cookies.txt
s6_cookies=$work_dir/s6-cookies.txt
pol_cookies=$work_dir/pol-cookies.txt

incoming_id=0
incoming_number=0
outgoing_id=0
outgoing_number=0
telecom_route_id=0
telecom_route_b_id=0
telecom_replaced_route_id=0
telecom_plan_version=0
telecom_plan_b_version=0
telecom_route_text='CI Betriebsstelle · CI Rufname · Kanal 404 · G/U · Gegenverkehr'
telecom_route_b_text='CI Ersatz-Betriebsstelle · CI Ersatz-Rufname · Kanal 505 · O/U · Wechselverkehr'
incoming_form_cleanup_owned=false
outgoing_form_cleanup_owned=false
mutation_started=false
message_auto_increment=1
protocol_auto_increment=1
message_count_before=0
protocol_count_before=0
s1_function_tables_before=0
s2_function_tables_before=0
s3_function_tables_before=0
si_function_tables_before=0
pol_function_tables_before=0
permission_mode_restore_required=false

db_sql()
{
    "$compose_engine" compose exec -T db sh -ceu '
        umask 077
        client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-message-workflow-client.XXXXXX")
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

set_test_permission_mode()
{
    requested_mode=$1
    case "$requested_mode" in
        STRICT | LOOSE) ;;
        *)
            echo 'Message workflow HTTP: invalid fixture permission mode' >&2
            return 1
            ;;
    esac
    db_sql >/dev/null <<SQL
SET @estab_permission_mode_test_id = (
  SELECT \`active_einsatz_id\`
    FROM \`nv_einsatz_status\`
   WHERE \`singleton_id\` = 1
);
SET @estab_permission_mode_admin_write_id =
  @estab_permission_mode_test_id;
UPDATE \`nv_einsaetze\`
   SET \`estab_permission_mode\` = '${requested_mode}'
 WHERE \`einsatz_id\` = @estab_permission_mode_test_id
   AND \`estab_status\` = 'open';
SET @estab_permission_mode_admin_write_id = NULL;
SET @estab_permission_mode_test_id = NULL;
SQL
}

generated_form_check()
{
    mode=$1
    direction=$2
    number=$3
    incident_id=$(db_sql <<'SQL'
SELECT `active_einsatz_id`
  FROM `nv_einsatz_status`
 WHERE `singleton_id` = 1;
SQL
)
    case "$incident_id" in
        '' | 0 | *[!0-9]*) return 2 ;;
    esac
    "$compose_engine" compose exec -T app sh -ceu '
        mode=$1
        direction=$2
        number=$3
        incident_id=$4
        case "$mode" in present | absent | remove) ;; *) exit 2 ;; esac
        case "$direction" in E | A) ;; *) exit 2 ;; esac
        case "$number" in "" | *[!0-9]* | 0) exit 2 ;; esac
        case "$incident_id" in "" | *[!0-9]* | 0) exit 2 ;; esac
        case "$ESTAB_DB_NAME" in
            "" | *[!A-Za-z0-9_]*) exit 2 ;;
        esac
        file="/var/www/html/4fdata/$ESTAB_DB_NAME/vordruck/$ESTAB_DB_NAME Einsatz-$incident_id $number $direction.pdf"
        case "$mode" in
            present) test -f "$file" ;;
            absent) test ! -e "$file" ;;
            remove)
                rm -f -- "$file"
                test ! -e "$file"
                ;;
        esac
    ' message-workflow-cleanup "$mode" "$direction" "$number" "$incident_id"
}

purge_session_file()
{
    cookie_jar=$1
    [ -f "$cookie_jar" ] || return 0
    session_id=$(awk '
        ($0 !~ /^#/ || $0 ~ /^#HttpOnly_/) && $6 == "PHPSESSID" {
            value = $7
        }
        END { print value }
    ' "$cookie_jar")
    [ -n "$session_id" ] || return 0
    if ! printf '%s' "$session_id" |
        grep -Eq '^[A-Za-z0-9,-]{16,128}$'; then
        echo 'Message workflow HTTP: refusing to remove an unsafe session file' >&2
        return 1
    fi
    "$compose_engine" compose exec -T app sh -ceu '
        session_id=$1
        printf "%s" "$session_id" | grep -Eq "^[A-Za-z0-9,-]{16,128}$"
        session_file="/var/lib/php/sessions/sess_$session_id"
        rm -f -- "$session_file"
        test ! -e "$session_file"
    ' message-workflow-cleanup "$session_id"
}

function_table_count()
{
    function_name=$1
    db_sql <<SQL
SELECT COUNT(*)
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name IN (
     'usr__fkt_${function_name}_erl',
     'usr__fkt_${function_name}_katego',
     'usr__fkt_${function_name}_kategolink'
   );
SQL
}

drop_new_function_tables()
{
    function_name=$1
    tables_before=$2
    if [ "$tables_before" = 0 ]; then
        db_sql >/dev/null <<SQL
DROP TABLE IF EXISTS
  \`usr__fkt_${function_name}_erl\`,
  \`usr__fkt_${function_name}_katego\`,
  \`usr__fkt_${function_name}_kategolink\`;
SQL
    fi
}

cleanup()
{
    status=$?
    trap - EXIT HUP INT TERM
    set +e
    cleanup_status=0

    if [ "$permission_mode_restore_required" = true ]; then
        set_test_permission_mode STRICT >/dev/null 2>&1 || cleanup_status=1
        permission_mode_restore_required=false
    fi

    # Workflow messages and their hash-linked evidence are deliberately
    # append-only. This test runs in ci.sh's disposable data volume and uses a
    # collision-resistant marker, so cleanup must never delete or rewrite the
    # canonical records merely to make the test repeatable.
    for cookie_jar in \
        "$aw_cookies" "$ldf_cookies" "$si_cookies" "$s1_cookies" "$s2_cookies" \
        "$s3_cookies" "$s6_cookies" "$pol_cookies"
    do
        purge_session_file "$cookie_jar" >/dev/null 2>&1 || cleanup_status=1
    done

    rm -rf -- "$work_dir"
    unset role_password

    if [ "$status" -eq 0 ] && [ "$cleanup_status" -ne 0 ]; then
        exit 1
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' HUP INT TERM

request_status()
{
    curl --silent --show-error --max-time 20 --connect-timeout 5 \
        --output "$body" --write-out '%{http_code}' "$@"
}

assert_status()
{
    expected=$1
    label=$2
    shift 2
    actual=$(request_status "$@")
    if [ "$actual" != "$expected" ]; then
        printf 'Message workflow HTTP: %s expected HTTP %s, got %s\n' \
            "$label" "$expected" "$actual" >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
}

assert_body()
{
    expected=$1
    label=${2:-response}
    if ! grep -Fq -- "$expected" "$body"; then
        printf 'Message workflow HTTP: %s lacks required UI control/content\n' \
            "$label" >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
}

assert_body_absent()
{
    forbidden=$1
    label=${2:-response}
    if grep -Fq -- "$forbidden" "$body"; then
        printf 'Message workflow HTTP: %s contains unexpected content\n' \
            "$label" >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
}

assert_no_runtime_error()
{
    label=${1:-response}
    if grep -Eq 'Fatal error|Uncaught (Error|TypeError)|Warning:|Deprecated:' "$body"; then
        printf 'Message workflow HTTP: PHP runtime error in %s\n' "$label" >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
}

assert_single_html_document()
{
    label=${1:-response}
    doctype_count=$(grep -Eio '<!doctype[[:space:]]+html' "$body" |
        wc -l | tr -d ' ')
    html_count=$(grep -Eio '<html([[:space:]>])' "$body" |
        wc -l | tr -d ' ')
    if [ "$doctype_count" != 1 ] || [ "$html_count" != 1 ]; then
        printf 'Message workflow HTTP: %s rendered %s doctypes and %s html roots\n' \
            "$label" "$doctype_count" "$html_count" >&2
        sed -n '1,160p' "$body" >&2
        exit 1
    fi
}

assert_loose_single_dispatch()
{
    cookie_jar=$1
    csrf_token=$2
    identity_label=$3
    action_name=$4
    expected_marker=$5
    label="LOOSE ${identity_label} -> ${action_name}"

    assert_status 200 "$label" \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$csrf_token" \
        --data-urlencode "${action_name}=1" \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "$label"
    assert_single_html_document "$label"
    assert_body "$expected_marker" "$label target renderer"
}

prove_loose_dispatch_for_identity()
{
    cookie_jar=$1
    identity_label=$2

    load_dashboard "$cookie_jar" "LOOSE dashboard for $identity_label"
    assert_single_html_document "LOOSE dashboard for $identity_label"
    dispatch_csrf=$(csrf_from_body)

    assert_loose_single_dispatch \
        "$cookie_jar" "$dispatch_csrf" "$identity_label" \
        stab_schreiben_x 'name="task" value="Stab_schreiben"'
    assert_loose_single_dispatch \
        "$cookie_jar" "$dispatch_csrf" "$identity_label" \
        stab_korrekturen_x 'Offene Korrekturen'
    assert_loose_single_dispatch \
        "$cookie_jar" "$dispatch_csrf" "$identity_label" \
        stab_sichten_x 'Sichterliste'
    assert_loose_single_dispatch \
        "$cookie_jar" "$dispatch_csrf" "$identity_label" \
        ldf_nachrichten_x 'LdF-Disposition'
    assert_loose_single_dispatch \
        "$cookie_jar" "$dispatch_csrf" "$identity_label" \
        fm_eingang_x 'name="task" value="FM-Eingang"'
    assert_loose_single_dispatch \
        "$cookie_jar" "$dispatch_csrf" "$identity_label" \
        fm_ausgang_x 'FMD Ausgang'

    assert_status 403 "LOOSE multiple primary selectors for $identity_label" \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$dispatch_csrf" \
        --data-urlencode 'stab_schreiben_x=1' \
        --data-urlencode 'fm_eingang_x=1' \
        "$base_url/4fach/mainindex.php"
}

csrf_from_body()
{
    token=$(sed -n \
        's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$token" | grep -Eq '^[a-f0-9]{64}$'; then
        echo 'Message workflow HTTP: CSRF token missing' >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
    printf '%s' "$token"
}

recipient_matrix_revision_from_body()
{
    revision=$(sed -n \
        's/.*name="recipient_matrix_revision" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$revision" | grep -Eq '^[a-f0-9]{64}$'; then
        echo 'Message workflow HTTP: recipient-matrix revision missing' >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
    printf '%s' "$revision"
}

attachment_flow_from_body()
{
    flow_token=$(sed -n \
        's/.*name="attachment_flow" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$flow_token" | grep -Eq '^[a-f0-9]{32}$'; then
        echo 'Message workflow HTTP: attachment flow token missing' >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
    printf '%s' "$flow_token"
}

message_attachment_request_token_from_body()
{
    request_token=$(sed -n \
        's/.*name="message_attachment_request_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$request_token" | grep -Eq '^[a-f0-9]{64}$'; then
        echo 'Message workflow HTTP: direct attachment request token missing' >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
    printf '%s' "$request_token"
}

roundtrip_staff_followup_attachment()
{
    label=$1
    destination=$2
    phone=$3
    subject=$4
    draft_content=$5

    followup_csrf=$(csrf_from_body)
    followup_matrix_revision=$(recipient_matrix_revision_from_body)
    assert_status 422 "$label malformed attachment draft" \
        --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$followup_csrf" \
        --data-urlencode \
            "recipient_matrix_revision=$followup_matrix_revision" \
        --data-urlencode 'anhang_plus_x=1' \
        --data-urlencode 'task=Stab_schreiben' \
        --data-urlencode '00_lfd=' \
        --data-urlencode "10_anschrift=$destination" \
        --data-urlencode "11_rufnummer=$phone" \
        --data-urlencode "12_betreff=$subject" \
        --data-urlencode '12_inhalt[]=unzulässiger Arraywert' \
        --data-urlencode '13_abseinheit=Browser-Manipulation' \
        --data-urlencode "14_zeichen=$pol_code" \
        --data-urlencode '14_funktion=POL' \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "$label malformed attachment draft"
    assert_body 'Die Anhangverwaltung wurde nicht geöffnet' \
        "$label malformed attachment draft explanation"
    assert_body "name=\"13_abseinheit\" value=\"$authoritative_sender\"" \
        "$label malformed draft authoritative sender"
    assert_body "name=\"14_zeichen\" value=\"$pol_code\"" \
        "$label malformed draft authoritative author code"
    assert_body 'name="14_funktion" value="POL"' \
        "$label malformed draft authoritative author function"
    assert_body_absent 'Browser-Manipulation' \
        "$label malformed draft rejected sender"

    followup_csrf=$(csrf_from_body)
    followup_matrix_revision=$(recipient_matrix_revision_from_body)
    assert_status 200 "$label attachment picker" \
        --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$followup_csrf" \
        --data-urlencode \
            "recipient_matrix_revision=$followup_matrix_revision" \
        --data-urlencode 'anhang_plus_x=1' \
        --data-urlencode 'task=Stab_schreiben' \
        --data-urlencode '00_lfd=' \
        --data-urlencode '04_richtung=' \
        --data-urlencode '04_nummer=' \
        --data-urlencode '07_durchspruch=D' \
        --data-urlencode '08_befhinweis=' \
        --data-urlencode '08_befhinwausw=' \
        --data-urlencode '09_vorrangstufe=eee' \
        --data-urlencode "10_anschrift=$destination" \
        --data-urlencode "11_rufnummer=$phone" \
        --data-urlencode '11_gesprnotiz=f' \
        --data-urlencode '12_anhang=' \
        --data-urlencode "12_betreff=$subject" \
        --data-urlencode "12_inhalt=$draft_content" \
        --data-urlencode '12_abfzeit=' \
        --data-urlencode "13_abseinheit=$authoritative_sender" \
        --data-urlencode "14_zeichen=$pol_code" \
        --data-urlencode '14_funktion=POL' \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "$label attachment picker"
    assert_body 'Liste der verfügbaren Dateien' "$label attachment picker"
    attachment_csrf=$(csrf_from_body)
    attachment_flow=$(attachment_flow_from_body)
    assert_status 200 "$label attachment return" \
        --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$attachment_csrf" \
        --data-urlencode "attachment_flow=$attachment_flow" \
        --data-urlencode 'ah_abbrechen_x=1' \
        "$base_url/4fach/anhang.php"
    assert_no_runtime_error "$label attachment return"
    assert_body 'name="task" value="Stab_schreiben"' \
        "$label attachment return task"
    assert_body 'name="00_lfd" value=""' \
        "$label attachment return new record id"
    assert_body "name=\"12_betreff\" value=\"$subject\"" \
        "$label attachment return subject"
    assert_body "$draft_content" "$label attachment return content"
    assert_body \
        "name=\"recipient_matrix_revision\" value=\"$followup_matrix_revision\"" \
        "$label attachment return recipient-matrix revision"
}

assert_numeric()
{
    label=$1
    value=$2
    case "$value" in
        '' | *[!0-9]* | 0)
            printf 'Message workflow HTTP: %s is not a positive number\n' "$label" >&2
            exit 1
            ;;
    esac
}

assert_db_equals()
{
    expected=$1
    label=$2
    query=$3
    actual=$(printf '%s\n' "$query" | db_sql)
    if [ "$actual" != "$expected" ]; then
        printf 'Message workflow HTTP: DB assertion failed for %s\n' "$label" >&2
        printf 'expected: %s\nactual:   %s\n' "$expected" "$actual" >&2
        exit 1
    fi
}

app_tactical_clock()
{
    "$compose_engine" compose exec -T app \
        php -r 'echo date("Hi");'
}

incident_authoritative_sender()
{
    db_sql <<'SQL'
SELECT e.`fuehrungsstellenname`
  FROM `nv_einsatz_status` AS s
  JOIN `nv_einsaetze` AS e
    ON e.`einsatz_id` = s.`active_einsatz_id`
 WHERE s.`singleton_id` = 1
   AND e.`estab_status` = 'open';
SQL
}

app_backdated_clock()
{
    "$compose_engine" compose exec -T app php -r '
        $time = (new DateTimeImmutable("now"))->modify("-5 minutes");
        echo $time->format("dHi")
            . strtolower($time->format("M"))
            . $time->format("Y|Y-m-d H:i:00");
    '
}

assert_current_editable_tactical_time_input()
{
    field_id=$1
    before_clock=$2
    after_clock=$3
    label=$4
    input_tag=$(sed -n \
        "s/.*\\(<input id=\"$field_id\"[^>]*>\\).*/\\1/p" \
        "$body" | head -n 1)
    input_value=$(printf '%s\n' "$input_tag" |
        sed -n 's/.*[[:space:]]value="\([^"]*\)".*/\1/p')

    if [ -z "$input_tag" ]; then
        printf 'Message workflow HTTP: %s input is missing\n' "$label" >&2
        exit 1
    fi
    if printf '%s\n' "$input_tag" |
        grep -Eqi '(^|[[:space:]])(readonly|disabled)(=|[[:space:]>])|type[[:space:]]*=[[:space:]]*"hidden"'; then
        printf 'Message workflow HTTP: %s input is not editable\n' "$label" >&2
        exit 1
    fi
    case "$input_value" in
        "$before_clock" | "$after_clock") ;;
        *)
            printf 'Message workflow HTTP: %s default is not the current app time\n' \
                "$label" >&2
            printf 'before: %s\nafter:  %s\nactual: %s\n' \
                "$before_clock" "$after_clock" "$input_value" >&2
            exit 1
            ;;
    esac
}

message_state()
{
    marker=$1
    db_sql <<SQL
SELECT CONCAT(
  \`04_richtung\`, '|',
  \`x00_status\`, '|',
  IF(\`x01_abschluss\` IN ('t','1'), 't', 'f'), '|',
  IF(\`15_quitdatum\` IS NULL, 'null', 'set'), '|',
  \`15_quitzeichen\`, '|',
  COALESCE(\`16_empf\`, ''), '|',
  IF(\`x02_sperre\` IN ('t','1'), 't', 'f'), '|',
  \`x03_sperruser\`, '|',
  IF(\`x04_druck\` IN ('t','1'), 't', 'f')
)
  FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${marker}';
SQL
}

message_admin_immutable_fingerprint()
{
    marker=$1
    db_sql <<SQL
SELECT SHA2(CONCAT_WS('|',
  COALESCE(HEX(\`00_lfd\`), 'NULL'),
  COALESCE(HEX(\`01_medium\`), 'NULL'),
  COALESCE(HEX(\`01_datum\`), 'NULL'),
  COALESCE(HEX(\`01_zeichen\`), 'NULL'),
  COALESCE(HEX(\`02_zeit\`), 'NULL'),
  COALESCE(HEX(\`02_zeichen\`), 'NULL'),
  COALESCE(HEX(\`03_datum\`), 'NULL'),
  COALESCE(HEX(\`03_zeichen\`), 'NULL'),
  COALESCE(HEX(\`04_richtung\`), 'NULL'),
  COALESCE(HEX(\`04_nummer\`), 'NULL'),
  COALESCE(HEX(\`05_gegenstelle\`), 'NULL'),
  COALESCE(HEX(\`06_befweg\`), 'NULL'),
  COALESCE(HEX(\`06_befwegausw\`), 'NULL'),
  COALESCE(HEX(\`07_durchspruch\`), 'NULL'),
  COALESCE(HEX(\`08_befhinweis\`), 'NULL'),
  COALESCE(HEX(\`08_befhinwausw\`), 'NULL'),
  COALESCE(HEX(\`09_vorrangstufe\`), 'NULL'),
  COALESCE(HEX(\`10_anschrift\`), 'NULL'),
  COALESCE(HEX(\`11_gesprnotiz\`), 'NULL'),
  COALESCE(HEX(\`12_anhang\`), 'NULL'),
  COALESCE(HEX(\`12_inhalt\`), 'NULL'),
  COALESCE(HEX(\`12_abfzeit\`), 'NULL'),
  COALESCE(HEX(\`13_abseinheit\`), 'NULL'),
  COALESCE(HEX(\`14_zeichen\`), 'NULL'),
  COALESCE(HEX(\`14_funktion\`), 'NULL'),
  COALESCE(HEX(\`15_quitdatum\`), 'NULL'),
  COALESCE(HEX(\`20_master_katego\`), 'NULL'),
  COALESCE(HEX(\`x00_status\`), 'NULL'),
  COALESCE(HEX(\`x01_abschluss\`), 'NULL'),
  COALESCE(HEX(\`x02_sperre\`), 'NULL'),
  COALESCE(HEX(\`x03_sperruser\`), 'NULL'),
  COALESCE(HEX(\`x04_druck\`), 'NULL')
), 256)
  FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${marker}';
SQL
}

assert_message_state()
{
    marker=$1
    expected=$2
    label=$3
    actual=$(message_state "$marker")
    if [ "$actual" != "$expected" ]; then
        printf 'Message workflow HTTP: message state mismatch for %s\n' "$label" >&2
        printf 'expected: %s\nactual:   %s\n' "$expected" "$actual" >&2
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
        echo 'Message workflow HTTP: authenticated response has no unique session bar' >&2
        exit 1
    fi
    for marker in \
        "data-estab-user-name=\"$expected_name\"" \
        "data-estab-user-code=\"$expected_code\"" \
        "data-estab-user-function=\"$expected_function\"" \
        "data-estab-user-role=\"$expected_role\"" \
        'data-estab-logout-form' \
        '>Abmelden</button>'
    do
        assert_body "$marker" 'session identity'
    done
}

assert_route_control()
{
    route=$1
    value=$2
    record_id=$3
    label=$4
    control="name=\"${route}\" value=\"${value}\"><input type=\"hidden\" name=\"00_lfd\" value=\"${record_id}\">"
    assert_body "$control" "$label"
}

load_dashboard()
{
    cookie_jar=$1
    label=$2
    assert_status 200 "$label" \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "$label"
}

load_sidebar()
{
    cookie_jar=$1
    label=$2
    assert_status 200 "$label" \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/4fach/vorgaben.php"
    assert_no_runtime_error "$label"
}

provision_and_login_user()
{
    cookie_jar=$1
    name=$2
    code=$3
    function_name=$4
    role=$5

    sh tests/integration/provision_user.sh \
        "$name" "$code" "$function_name" "$role_password"

    : >"$cookie_jar"
    assert_status 200 "open existing-account login for $function_name" \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST --data-urlencode 'login_flow=existing' \
        "$base_url/4fach/mainindex.php"
    assert_body 'autocomplete="current-password"' \
        "existing-account form for $function_name"
    login_csrf=$(csrf_from_body)

    assert_status 200 "login $function_name" \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$login_csrf" \
        --data-urlencode 'login_flow=existing' \
        --data-urlencode "benutzer=$name" \
        --data-urlencode "kuerzel=$code" \
        --data-urlencode "funktion=$function_name" \
        --data-urlencode "kennwort1=$role_password" \
        --data-urlencode '2teskennwort=No' \
        --data-urlencode 'absenden_x=1' \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "login response for $function_name"

    # The fixed account function is the fachliche permission source. Opening
    # the command-post controller requires only this authenticated identity
    # and the active incident, never a selected legacy duty assignment.
    assert_status 200 "command-post page for $function_name" \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/4fach/fuehrungsstelle.php"
    assert_no_runtime_error "command-post page for $function_name"
    assert_session_identity "$name" "$code" "$function_name" "$role"
    assert_body 'data-estab-dv-operations' \
        "command-post marker for $function_name"
    assert_body 'Zugewiesene Funktion' \
        "fixed account function for $function_name"
}

finish_ldf_incoming()
{
    ldf_marker=$1
    ldf_record_id=$2
    ldf_sender=$3
    ldf_requested_medium=${4:-}
    ldf_correction_reason=${5:-}

    load_dashboard "$ldf_cookies" "LdF queue for $ldf_marker"
    assert_body "$ldf_marker" "LdF queue for $ldf_marker"
    assert_route_control ldf meldung "$ldf_record_id" "LdF incoming detail"
    ldf_csrf=$(csrf_from_body)
    ldf_clock_before=$(app_tactical_clock)
    assert_status 200 "open LdF incoming for $ldf_marker" \
        --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$ldf_csrf" \
        --data-urlencode 'ldf=meldung' \
        --data-urlencode "00_lfd=$ldf_record_id" \
        "$base_url/4fach/mainindex.php"
    ldf_clock_after=$(app_tactical_clock)
    assert_no_runtime_error "LdF incoming form for $ldf_marker"
    assert_body 'name="task" value="LdF-Eingang"' "LdF incoming task"
    assert_current_editable_tactical_time_input \
        f_02_zeit "$ldf_clock_before" "$ldf_clock_after" \
        "LdF incoming acceptance time"
    assert_body \
        'id="f_02_zeichen" data-estab-readonly="true"' \
        'LdF incoming authenticated code'
    assert_body \
        'id="f_13_abseinheit"' \
        'LdF incoming sender translation field'
    assert_body \
        'id="f_01_medium_fu" name="01_medium" value="Fu" type="radio"' \
        'LdF incoming transport medium control'
    assert_body \
        'data-estab-incoming-transport-confirmation="required"' \
        'LdF incoming mandatory transport confirmation'
    assert_body \
        'name="incoming_transport_correction_reason" maxlength="500"' \
        'LdF incoming transport correction reason'
    assert_body_absent \
        'name="01_datum"' \
        'LdF cannot rewrite A/W receipt time'
    assert_body_absent \
        'name="01_zeichen"' \
        'LdF cannot rewrite A/W receipt mark'
    assert_body \
        'data-estab-incident-suggestions="sender"' \
        'LdF incoming incident sender suggestions'
    assert_body \
        'list="estab-message-sender-suggestions-native"' \
        'LdF incoming no-script sender suggestion fallback'
    assert_body \
        'aria-describedby="estab-message-sender-suggestions-hint"' \
        'LdF incoming accessible sender suggestion hint'
    assert_body \
        'id="estab-message-sender-suggestions"' \
        'LdF incoming sender listbox'
    assert_body \
        'role="combobox" aria-autocomplete="list"' \
        'LdF incoming accessible sender combobox'
    assert_body \
        'role="listbox" aria-label="Vorschläge aus dem aktiven Einsatz"' \
        'LdF incoming focus suggestion listbox'
    assert_body \
        'data-estab-message-suggestion-picker' \
        'LdF incoming suggestion keyboard helper'
    assert_body \
        "value=\"$ldf_sender\" label=\"Bestätigtes Nachrichtenpaar\"" \
        'LdF incoming contextual sender mapping'
    assert_body \
        'data-estab-mapping-match="message"' \
        'LdF incoming mapping source marker'
    assert_body \
        'data-estab-mapping-quality="exact"' \
        'LdF incoming exact mapping quality'
    assert_body \
        'Passende Zuordnungen zu „E2E-Gegenstelle“ stehen zuerst' \
        'LdF incoming callsign mapping context'
    assert_body_absent \
        'data-estab-incident-suggestions="callsign"' \
        'LdF incoming cannot edit callsign suggestions'
    ldf_original_medium=$(db_sql <<SQL
SELECT \`01_medium\`
  FROM \`nv_nachrichten\`
 WHERE \`00_lfd\` = ${ldf_record_id};
SQL
)
    case "$ldf_original_medium" in
        Fe | Fu | Me | FAX | FS | @) ;;
        *)
            printf 'Message workflow HTTP: invalid A/W incoming medium: %s\n' \
                "$ldf_original_medium" >&2
            exit 1
            ;;
    esac
    if [ -z "$ldf_requested_medium" ]; then
        ldf_requested_medium=$ldf_original_medium
    fi
    ldf_receipt_evidence=$(db_sql <<SQL
SELECT CONCAT(
  DATE_FORMAT(\`01_datum\`, '%Y-%m-%d %H:%i:%s'),
  '|',
  \`01_zeichen\`
)
  FROM \`nv_nachrichten\`
 WHERE \`00_lfd\` = ${ldf_record_id};
SQL
)
    ldf_event_count_before=$(db_sql <<SQL
SELECT COUNT(*)
  FROM \`nv_nachrichten_ereignisse\`
 WHERE \`message_id\` = ${ldf_record_id};
SQL
)

    # A browser-required checkbox is repeated server-side: omitting it keeps
    # the exact locked record at status 1 without appending evidence.
    ldf_csrf=$(csrf_from_body)
    ldf_time=$(date '+%H%M')
    assert_status 422 "keep LdF incoming open without confirmation for $ldf_marker" \
        --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$ldf_csrf" \
        --data-urlencode 'absenden_x=1' \
        --data-urlencode 'task=LdF-Eingang' \
        --data-urlencode "00_lfd=$ldf_record_id" \
        --data-urlencode "01_medium=$ldf_original_medium" \
        --data-urlencode "02_zeit=$ldf_time" \
        --data-urlencode "02_zeichen=$ldf_code" \
        --data-urlencode "13_abseinheit=$ldf_sender" \
        --data-urlencode 'incoming_transport_correction_reason=' \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error \
        "LdF incoming missing transport confirmation for $ldf_marker"
    assert_body \
        "data-estab-incoming-transport-original=\"$ldf_original_medium\"" \
        "unconfirmed LdF form reloads the A/W medium for $ldf_marker"
    assert_db_equals "1|${ldf_original_medium}" \
        "unconfirmed LdF incoming remains pending for $ldf_marker" \
        "SELECT CONCAT(\`x00_status\`, '|', \`01_medium\`) FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${ldf_record_id};"
    assert_db_equals "$ldf_event_count_before" \
        "unconfirmed LdF incoming appends no evidence for $ldf_marker" \
        "SELECT COUNT(*) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${ldf_record_id};"

    if [ "$ldf_requested_medium" != "$ldf_original_medium" ]; then
        # The repository compares against the A/W value read FOR UPDATE.
        # A changed route without a reason cannot advance the workflow.
        ldf_csrf=$(csrf_from_body)
        assert_status 409 "reject unexplained LdF route correction for $ldf_marker" \
            --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
            --request POST \
            --data-urlencode "csrf_token=$ldf_csrf" \
            --data-urlencode 'absenden_x=1' \
            --data-urlencode 'task=LdF-Eingang' \
            --data-urlencode "00_lfd=$ldf_record_id" \
            --data-urlencode "01_medium=$ldf_requested_medium" \
            --data-urlencode "02_zeit=$ldf_time" \
            --data-urlencode "02_zeichen=$ldf_code" \
            --data-urlencode "13_abseinheit=$ldf_sender" \
            --data-urlencode 'incoming_transport_confirmed=1' \
            --data-urlencode 'incoming_transport_correction_reason=' \
            "$base_url/4fach/mainindex.php"
        assert_no_runtime_error \
            "rejected unexplained LdF route correction for $ldf_marker"
        assert_body \
            'Für die Korrektur des Eingangswegs ist eine Begründung erforderlich.' \
            'LdF incoming route correction explanation'
        assert_body \
            "data-estab-incoming-transport-original=\"$ldf_original_medium\"" \
            "corrected LdF form still shows the A/W medium for $ldf_marker"
        assert_body \
            "id=\"f_01_medium_me\" name=\"01_medium\" value=\"Me\" type=\"radio\" checked=\"checked\"" \
            "corrected LdF form keeps the requested medium for $ldf_marker"
        assert_db_equals "1|${ldf_original_medium}" \
            "unexplained LdF route correction is atomic for $ldf_marker" \
            "SELECT CONCAT(\`x00_status\`, '|', \`01_medium\`) FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${ldf_record_id};"
        assert_db_equals "$ldf_event_count_before" \
            "unexplained LdF route correction appends no evidence for $ldf_marker" \
            "SELECT COUNT(*) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${ldf_record_id};"
    fi

    ldf_csrf=$(csrf_from_body)
    assert_status 200 "save LdF incoming for $ldf_marker" \
        --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$ldf_csrf" \
        --data-urlencode 'absenden_x=1' \
        --data-urlencode 'task=LdF-Eingang' \
        --data-urlencode "00_lfd=$ldf_record_id" \
        --data-urlencode "01_medium=$ldf_requested_medium" \
        --data-urlencode '01_datum=010100Jan2000' \
        --data-urlencode '01_zeichen=forge' \
        --data-urlencode "02_zeit=$ldf_time" \
        --data-urlencode "02_zeichen=$ldf_code" \
        --data-urlencode "13_abseinheit=$ldf_sender" \
        --data-urlencode 'incoming_transport_confirmed=1' \
        --data-urlencode \
            "incoming_transport_correction_reason=$ldf_correction_reason" \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "saved LdF incoming for $ldf_marker"
    assert_db_equals "${ldf_code}|${ldf_sender}|${ldf_requested_medium}" \
        "LdF-authored incoming identity for $ldf_marker" \
        "SELECT CONCAT(\`02_zeichen\`, '|', \`13_abseinheit\`, '|', \`01_medium\`) FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${ldf_record_id};"
    assert_db_equals "$ldf_receipt_evidence" \
        "LdF keeps A/W receipt time and mark immutable for $ldf_marker" \
        "SELECT CONCAT(DATE_FORMAT(\`01_datum\`, '%Y-%m-%d %H:%i:%s'), '|', \`01_zeichen\`) FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${ldf_record_id};"
    if [ "$ldf_requested_medium" != "$ldf_original_medium" ]; then
        assert_db_equals \
            "${ldf_requested_medium}|true|true|${ldf_original_medium}|${ldf_correction_reason}|${ldf_code}" \
            "LdF incoming route correction evidence for $ldf_marker" \
            "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.incoming_transport_medium')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.incoming_transport_confirmed')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_corrected')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.previous_incoming_transport_medium')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_correction_reason')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_confirmed_by'))) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${ldf_record_id} AND \`event_type\`='ldf_dispatched';"
    else
        assert_db_equals \
            "${ldf_requested_medium}|true|false|${ldf_code}" \
            "LdF incoming route confirmation evidence for $ldf_marker" \
            "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.incoming_transport_medium')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.incoming_transport_confirmed')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_corrected')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_confirmed_by'))) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${ldf_record_id} AND \`event_type\`='ldf_dispatched';"
    fi

    # This is the HTTP half of the repository's parallel-save proof: it
    # represents the second tab whose once-valid form arrives after the first
    # tab committed. Object authorization already rejects the stale stage
    # before the repository boundary; it must never become a 500 or append a
    # second LdF event.
    ldf_csrf=$(csrf_from_body)
    assert_status 403 "reject stale LdF incoming save for $ldf_marker" \
        --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$ldf_csrf" \
        --data-urlencode 'absenden_x=1' \
        --data-urlencode 'task=LdF-Eingang' \
        --data-urlencode "00_lfd=$ldf_record_id" \
        --data-urlencode "01_medium=$ldf_requested_medium" \
        --data-urlencode "02_zeit=$ldf_time" \
        --data-urlencode "02_zeichen=$ldf_code" \
        --data-urlencode "13_abseinheit=$ldf_sender" \
        --data-urlencode 'incoming_transport_confirmed=1' \
        --data-urlencode \
            "incoming_transport_correction_reason=$ldf_correction_reason" \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "stale LdF incoming conflict for $ldf_marker"
    assert_body \
        'Aktion nicht erlaubt.' \
        "stale LdF incoming authorization explanation for $ldf_marker"
    assert_db_equals "$((ldf_event_count_before + 1))" \
        "stale LdF incoming appends no duplicate evidence for $ldf_marker" \
        "SELECT COUNT(*) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${ldf_record_id};"
}

finish_ldf_outgoing()
{
    ldf_marker=$1
    ldf_record_id=$2
    ldf_callsign=$3
    ldf_route=$4
    ldf_route_id=${5:-$telecom_route_id}
    ldf_route_text=${6:-$telecom_route_text}

    load_dashboard "$ldf_cookies" "LdF queue for $ldf_marker"
    assert_body "$ldf_marker" "LdF queue for $ldf_marker"
    assert_route_control ldf meldung "$ldf_record_id" "LdF outgoing detail"
    ldf_csrf=$(csrf_from_body)
    assert_status 200 "open LdF outgoing for $ldf_marker" \
        --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$ldf_csrf" \
        --data-urlencode 'ldf=meldung' \
        --data-urlencode "00_lfd=$ldf_record_id" \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "LdF outgoing form for $ldf_marker"
    assert_body 'name="task" value="LdF-Ausgang"' "LdF outgoing task"
    assert_body 'id="f_05_gegenstelle"' "LdF outgoing callsign field"
    assert_body \
        'data-estab-incident-suggestions="callsign"' \
        'LdF outgoing incident callsign suggestions'
    assert_body \
        'list="estab-message-callsign-suggestions-native"' \
        'LdF outgoing no-script callsign suggestion fallback'
    assert_body \
        'aria-describedby="estab-message-callsign-suggestions-hint"' \
        'LdF outgoing accessible callsign suggestion hint'
    assert_body \
        'id="estab-message-callsign-suggestions"' \
        'LdF outgoing callsign listbox'
    assert_body \
        'role="combobox" aria-autocomplete="list"' \
        'LdF outgoing accessible callsign combobox'
    assert_body \
        'role="listbox" aria-label="Vorschläge aus dem aktiven Einsatz"' \
        'LdF outgoing focus suggestion listbox'
    assert_body \
        'value="E2E-Gegenstelle"' \
        'LdF outgoing suggestion from the previous active-incident message'
    assert_body \
        'data-estab-message-suggestion-picker' \
        'LdF outgoing suggestion keyboard helper'
    if [ "$ldf_marker" = "$outgoing_marker" ]; then
        assert_body \
            'value="E2E-Gegenstelle" label="Bestätigtes Nachrichtenpaar"' \
            'LdF outgoing contextual callsign mapping'
        assert_body \
            'data-estab-mapping-match="message"' \
            'LdF outgoing mapping source marker'
        assert_body \
            'data-estab-mapping-quality="exact"' \
            'LdF outgoing exact mapping quality'
        assert_body \
            'Passende Zuordnungen zu „E2E-Zielstelle korrigiert“ stehen zuerst' \
            'LdF outgoing address mapping context'
    fi
    assert_body_absent \
        'data-estab-incident-suggestions="sender"' \
        'LdF outgoing cannot change sender through incident suggestions'
    assert_body \
        'id="f_fernmeldeplan_eintrag_id"' \
        "LdF outgoing S6 plan selector"
    assert_body \
        "value=\"$ldf_route_id\"" \
        "LdF outgoing selected S6 route"
    assert_body_absent \
        'name="06_befweg"' \
        "LdF cannot submit a free transport route"
    assert_body_absent \
        'name="06_befwegausw"' \
        "LdF cannot submit a free transport medium"
    assert_body_absent \
        'id="f_03_datum" maxlength=' \
        'LdF must not write transport completion time'
    ldf_csrf=$(csrf_from_body)
    ldf_time=$(date '+%H%M')
    assert_status 200 "save LdF outgoing for $ldf_marker" \
        --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$ldf_csrf" \
        --data-urlencode 'absenden_x=1' \
        --data-urlencode 'task=LdF-Ausgang' \
        --data-urlencode "00_lfd=$ldf_record_id" \
        --data-urlencode "02_zeit=$ldf_time" \
        --data-urlencode "02_zeichen=$ldf_code" \
        --data-urlencode "05_gegenstelle=$ldf_callsign" \
        --data-urlencode "fernmeldeplan_eintrag_id=$ldf_route_id" \
        --data-urlencode "06_befweg=FORGED-$ldf_route" \
        --data-urlencode '06_befwegausw=Me' \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "saved LdF outgoing for $ldf_marker"
    assert_db_equals \
        "2|${ldf_code}|${ldf_callsign}|Fu|${ldf_route_text}|${ldf_route_id}" \
        "LdF-authored outgoing disposition for $ldf_marker" \
        "SELECT CONCAT(\`x00_status\`, '|', \`02_zeichen\`, '|', \`05_gegenstelle\`, '|', \`06_befwegausw\`, '|', \`06_befweg\`, '|', \`estab_fernmeldeplan_eintrag_id\`) FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${ldf_record_id};"
}

open_viewer_message()
{
    marker=$1
    record_id=$2

    load_dashboard "$si_cookies" "Si queue for $marker"
    assert_body "$marker" "Si queue for $marker"
    assert_route_control sichter meldung "$record_id" "Si queue for $marker"
    viewer_csrf=$(csrf_from_body)
    assert_status 200 "open Si review for $marker" \
        --cookie "$si_cookies" --cookie-jar "$si_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$viewer_csrf" \
        --data-urlencode 'sichter=meldung' \
        --data-urlencode "00_lfd=$record_id" \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "Si review form for $marker"
    assert_body 'name="task" value="Stab_sichten"' "Si review form for $marker"
    assert_body "name=\"00_lfd\" value=\"$record_id\"" "Si review form for $marker"
    assert_body \
        'id="f_15_quitzeichen" data-estab-readonly="true"' \
        "Si signed review mark for $marker"
    assert_body ">$si_code</strong>" "Si signed review mark for $marker"
    assert_body_absent 'name="15_quitdatum"' \
        "Si completion time is server-generated for $marker"
    assert_body_absent 'name="16_gncopy"' \
        "Si form has no external copy selector for $marker"
    assert_body_absent 'estab-message-green-copy' \
        "Si form has no external copy fieldset for $marker"
    assert_body 'name="16_41" value="16_41_bl" type="checkbox"' \
        "Si recipient control for $marker"
    assert_body 'aria-label="S3 als Empfänger auswählen"' \
        "Si recipient label for $marker"
    viewer_matrix_revision=$(recipient_matrix_revision_from_body)
}

finish_viewer_message()
{
    marker=$1
    record_id=$2
    note=$3

    open_viewer_message "$marker" "$record_id"
    viewer_csrf=$(csrf_from_body)
    assert_status 403 "reject forged Si completion time for $marker" \
        --cookie "$si_cookies" --cookie-jar "$si_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$viewer_csrf" \
        --data-urlencode \
            "recipient_matrix_revision=$viewer_matrix_revision" \
        --data-urlencode 'absenden_x=1' \
        --data-urlencode 'task=Stab_sichten' \
        --data-urlencode "00_lfd=$record_id" \
        --data-urlencode '15_quitdatum=0101' \
        --data-urlencode '16_21=16_21_bl' \
        --data-urlencode '16_32=16_32_bl' \
        --data-urlencode '16_41=16_41_bl' \
        --data-urlencode "17_vermerke=$note" \
        "$base_url/4fach/mainindex.php"
    assert_status 200 "finish Si review for $marker" \
        --cookie "$si_cookies" --cookie-jar "$si_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$viewer_csrf" \
        --data-urlencode \
            "recipient_matrix_revision=$viewer_matrix_revision" \
        --data-urlencode 'absenden_x=1' \
        --data-urlencode 'task=Stab_sichten' \
        --data-urlencode "00_lfd=$record_id" \
        --data-urlencode '16_21=16_21_bl' \
        --data-urlencode '16_32=16_32_bl' \
        --data-urlencode '16_41=16_41_bl' \
        --data-urlencode "17_vermerke=$note" \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "finished Si review for $marker"
}

open_viewer_outgoing()
{
    marker=$1
    record_id=$2

    load_dashboard "$si_cookies" "Si formal queue for $marker"
    assert_body "$marker" "Si formal queue for $marker"
    assert_route_control sichter meldung "$record_id" "Si formal queue for $marker"
    viewer_csrf=$(csrf_from_body)
    assert_status 200 "open Si formal review for $marker" \
        --cookie "$si_cookies" --cookie-jar "$si_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$viewer_csrf" \
        --data-urlencode 'sichter=meldung' \
        --data-urlencode "00_lfd=$record_id" \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "Si formal review form for $marker"
    assert_body 'name="task" value="Stab_sichten"' \
        "Si formal review task for $marker"
    assert_body 'data-estab-formal-review="outgoing"' \
        "Si outgoing formal-review boundary for $marker"
    assert_body 'Formal geprüft – an FmZt' \
        "Si approve action for $marker"
    assert_body 'An Verfasser zurückgeben' \
        "Si return action for $marker"
    assert_body_absent 'name="16_gncopy"' \
        "Si cannot reroute outgoing message $marker"
    assert_body_absent 'name="16_41"' \
        "Si cannot add outgoing recipients for $marker"
    assert_body_absent '<textarea id="f_12_inhalt"' \
        "Si cannot edit outgoing content for $marker"
    viewer_matrix_revision=$(recipient_matrix_revision_from_body)
}

finish_viewer_outgoing()
{
    marker=$1
    record_id=$2
    note=$3

    open_viewer_outgoing "$marker" "$record_id"
    viewer_csrf=$(csrf_from_body)
    assert_status 200 "approve Si formal review for $marker" \
        --cookie "$si_cookies" --cookie-jar "$si_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$viewer_csrf" \
        --data-urlencode \
            "recipient_matrix_revision=$viewer_matrix_revision" \
        --data-urlencode 'absenden_x=1' \
        --data-urlencode 'task=Stab_sichten' \
        --data-urlencode "00_lfd=$record_id" \
        --data-urlencode "17_vermerke=$note" \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "approved Si formal review for $marker"
}

return_viewer_outgoing()
{
    marker=$1
    record_id=$2
    note=$3

    open_viewer_outgoing "$marker" "$record_id"
    viewer_csrf=$(csrf_from_body)
    assert_status 200 "return Si formal review for $marker" \
        --cookie "$si_cookies" --cookie-jar "$si_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$viewer_csrf" \
        --data-urlencode \
            "recipient_matrix_revision=$viewer_matrix_revision" \
        --data-urlencode 'zurueckweisen_x=1' \
        --data-urlencode 'task=Stab_sichten' \
        --data-urlencode "00_lfd=$record_id" \
        --data-urlencode "17_vermerke=$note" \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "returned Si formal review for $marker"
}

authoritative_sender=$(incident_authoritative_sender)
if [ -z "$authoritative_sender" ] || printf '%s' "$authoritative_sender" | grep -q '|'; then
    echo 'Message workflow HTTP: unsafe authoritative sender fixture' >&2
    exit 1
fi
if [ "$authoritative_sender" = 'GLOBAL-CONFIG-MUST-NOT-APPEAR' ]; then
    echo 'Message workflow HTTP: installation-wide organisation remained authoritative' >&2
    exit 1
fi

# Prove this is a collision-free fixture before the first mutation. Dynamic
# table names are part of the guard because a stale table must not be dropped.
fixture_collision=$(db_sql <<SQL
SELECT CONCAT(
  (SELECT COUNT(*) FROM \`nv_benutzer\`
    WHERE \`kuerzel\` IN (
      '${aw_code}', '${ldf_code}', '${si_code}', '${s1_code}', '${s2_code}', '${s3_code}',
      '${s6_code}', '${pol_code}'
    )),
  '|',
  (SELECT COUNT(*) FROM \`nv_nachrichten\`
    WHERE \`12_inhalt\` IN (
      '${incoming_marker}', '${outgoing_marker}',
      '${mapping_incoming_marker}', '${mapping_outgoing_marker}'
    )
       OR \`12_inhalt\` LIKE '%${reply_marker}%'
       OR \`12_inhalt\` LIKE '%${forward_marker}%'),
  '|',
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN (
        'usr_s1_${s1_code}_read', 'usr_s1_${s1_code}_katego', 'usr_s1_${s1_code}_kategolink',
        'usr_s2_${s2_code}_read', 'usr_s2_${s2_code}_katego', 'usr_s2_${s2_code}_kategolink',
        'usr_s3_${s3_code}_read', 'usr_s3_${s3_code}_katego', 'usr_s3_${s3_code}_kategolink',
        'usr_si_${si_code}_read', 'usr_si_${si_code}_katego', 'usr_si_${si_code}_kategolink',
        'usr_pol_${pol_code}_read', 'usr_pol_${pol_code}_katego', 'usr_pol_${pol_code}_kategolink'
      ))
);
SQL
)
if [ "$fixture_collision" != '0|0|0' ]; then
    echo 'Message workflow HTTP: fixture identities or markers already exist' >&2
    exit 1
fi

matrix_contract=$(db_sql <<'SQL'
SELECT GROUP_CONCAT(
         CONCAT(
           `mtx_x`, ':', `mtx_y`, ':', `mtx_typ`, ':', `mtx_fkt`, ':',
           IF(`mtx_rc2` IN ('t','1'), 't', 'f')
         )
         ORDER BY `mtx_x`, `mtx_y` SEPARATOR '|'
       )
  FROM `nv_empfmtx`
 WHERE `mtx_fkt` IN ('S1', 'S2', 'S3');
SQL
)
if [ "$matrix_contract" != '2:1:cb:S1:f|3:1:cb:S2:t|4:1:cb:S3:f' ]; then
    echo 'Message workflow HTTP: fresh recipient matrix no longer provides the tested RGB positions' >&2
    exit 1
fi
assert_db_equals 1 'unique red-copy function' \
    "SELECT COUNT(*) FROM \`nv_empfmtx\` WHERE \`mtx_rc2\` IN ('t','1');"
assert_db_equals 'FB|0' 'initial POL role and autosighting state' \
    "SELECT CONCAT(\`mtx_rolle\`, '|', IF(\`mtx_auto\` IN ('t','1'), '1', '0')) FROM \`nv_empfmtx\` WHERE \`mtx_fkt\` = 'POL';"
assert_db_equals 0 'pre-existing unprinted closed messages' \
    "SELECT COUNT(*) FROM \`nv_nachrichten\` WHERE \`x01_abschluss\` IN ('t','1') AND \`x04_druck\` IN ('f','0');"

message_auto_increment=$(db_sql <<'SQL'
SELECT COALESCE(`AUTO_INCREMENT`, 1)
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name = 'nv_nachrichten';
SQL
)
protocol_auto_increment=$(db_sql <<'SQL'
SELECT COALESCE(`AUTO_INCREMENT`, 1)
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name = 'nv_protokoll';
SQL
)
message_count_before=$(db_sql <<'SQL'
SELECT COUNT(*) FROM `nv_nachrichten`;
SQL
)
protocol_count_before=$(db_sql <<'SQL'
SELECT COUNT(*) FROM `nv_protokoll`;
SQL
)
for snapshot in \
    "$message_auto_increment" "$protocol_auto_increment" \
    "$message_count_before" "$protocol_count_before"
do
    case "$snapshot" in
        '' | *[!0-9]*)
            echo 'Message workflow HTTP: invalid database snapshot' >&2
            exit 1
            ;;
    esac
done

s1_function_tables_before=$(function_table_count s1)
s2_function_tables_before=$(function_table_count s2)
s3_function_tables_before=$(function_table_count s3)
si_function_tables_before=$(function_table_count si)
pol_function_tables_before=$(function_table_count pol)
for table_count in \
    "$s1_function_tables_before" "$s2_function_tables_before" \
    "$s3_function_tables_before" "$si_function_tables_before" \
    "$pol_function_tables_before"
do
    case "$table_count" in
        0 | 3) ;;
        *)
            echo 'Message workflow HTTP: refusing to repair a partial shared function-table set' >&2
            exit 1
            ;;
    esac
done

mutation_started=true

# Create eight isolated functional accounts through the administrative domain
# boundary, then exercise only the production bestandskonto login flow.
# Keeping Si signed in makes both A/W forms use their genuine online-Si branch.
provision_and_login_user "$aw_cookies" "$aw_name" "$aw_code" A/W Fernmelder
provision_and_login_user "$ldf_cookies" "$ldf_name" "$ldf_code" LdF Fernmelder
provision_and_login_user "$s1_cookies" "$s1_name" "$s1_code" S1 Stab
provision_and_login_user "$s2_cookies" "$s2_name" "$s2_code" S2 Stab
provision_and_login_user "$s3_cookies" "$s3_name" "$s3_code" S3 Stab
provision_and_login_user "$s6_cookies" "$s6_name" "$s6_code" S6 Stab
provision_and_login_user "$pol_cookies" "$pol_name" "$pol_code" POL FB
provision_and_login_user "$si_cookies" "$si_name" "$si_code" Si Stab

# The workflow accounts deliberately have neither historical duty assignments
# nor optional access-shift memberships. This proves the default case: an
# unassigned account may work, its fixed function supplies the fachliche
# permissions, and only the active incident is a mandatory operational gate.
active_incident_id=$(db_sql <<'SQL'
SELECT `active_einsatz_id`
  FROM `nv_einsatz_status`
 WHERE `singleton_id` = 1;
SQL
)
assert_numeric 'active incident for Führungsstellen HTTP workflow' \
    "$active_incident_id"
workflow_account_codes="'${aw_code}','${ldf_code}','${si_code}','${s1_code}','${s2_code}','${s3_code}','${s6_code}','${pol_code}'"
assert_db_equals 0 'workflow accounts have no legacy duty assignments' \
    "SELECT COUNT(*) FROM \`nv_dienstbesetzungen\` WHERE \`benutzer_kuerzel\` IN (${workflow_account_codes});"
assert_db_equals 0 'workflow accounts have no optional access-shift memberships' \
    "SELECT COUNT(*) FROM \`nv_zugangsschicht_mitglieder\` WHERE \`benutzer_kuerzel\` IN (${workflow_account_codes}) AND \`entfernt_am\` IS NULL;"

# Exercise the production controller with every LOOSE write-navigation action
# from each representative fixed account function. The response must contain
# one document root: an explicit foreign-function view must suppress the
# account's historical default view. This fixture-only mode change uses the
# migration's narrow DML marker; the application requests themselves remain
# real authenticated, CSRF-protected HTTP requests.
assert_db_equals STRICT 'permission-mode dispatch fixture starts strict' \
    "SELECT \`estab_permission_mode\` FROM \`nv_einsaetze\` WHERE \`einsatz_id\`=${active_incident_id};"
permission_mode_restore_required=true
set_test_permission_mode LOOSE
assert_db_equals LOOSE 'permission-mode dispatch fixture entered loose mode' \
    "SELECT \`estab_permission_mode\` FROM \`nv_einsaetze\` WHERE \`einsatz_id\`=${active_incident_id};"

prove_loose_dispatch_for_identity "$s1_cookies" 'S1/Stab'
prove_loose_dispatch_for_identity "$si_cookies" 'Si/Stab'
prove_loose_dispatch_for_identity "$aw_cookies" 'A-W/Fernmelder'
prove_loose_dispatch_for_identity "$ldf_cookies" 'LdF/Fernmelder'
prove_loose_dispatch_for_identity "$pol_cookies" 'POL/FB'

set_test_permission_mode STRICT
permission_mode_restore_required=false
assert_db_equals STRICT 'permission-mode dispatch fixture restored strict mode' \
    "SELECT \`estab_permission_mode\` FROM \`nv_einsaetze\` WHERE \`einsatz_id\`=${active_incident_id};"
load_dashboard "$s1_cookies" 'STRICT dashboard after dispatch matrix'
strict_dispatch_csrf=$(csrf_from_body)
assert_status 403 'STRICT rejects S1 telecommunications view after restore' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$strict_dispatch_csrf" \
    --data-urlencode 'fm_eingang_x=1' \
    "$base_url/4fach/mainindex.php"
load_dashboard "$aw_cookies" 'STRICT A-W dashboard after dispatch matrix'
strict_dispatch_csrf=$(csrf_from_body)
assert_status 403 'STRICT rejects A-W staff view after restore' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$strict_dispatch_csrf" \
    --data-urlencode 'stab_schreiben_x=1' \
    "$base_url/4fach/mainindex.php"

# Cross the real authenticated Führungsstellen controller before the remaining
# workflow. CSRF and the fixed S6 capability are enforced at the HTTP boundary
# even though no duty assignment or access-shift membership is selected.
csrf_probe_plan_origin="CI_HTTP_CSRF_${identity_seed}"
assert_status 403 'reject S6 plan POST without CSRF' \
    --cookie "$s6_cookies" --cookie-jar "$s6_cookies" \
    --request POST \
    --data-urlencode 'operation_action=create_plan' \
    --data-urlencode "herkunft=$csrf_probe_plan_origin" \
    --data-urlencode 'gueltig_ab=2026-01-01T00:00' \
    --data-urlencode 'gueltig_bis=2099-12-31T23:59' \
    --data-urlencode 'betriebsleitung=CSRF muss sperren' \
    "$base_url/4fach/fuehrungsstelle.php"
assert_db_equals 0 'CSRF-rejected S6 plan was not persisted' \
    "SELECT COUNT(*) FROM \`nv_fernmeldeplaene\` WHERE \`einsatz_id\`=${active_incident_id} AND BINARY \`herkunft\`=BINARY '${csrf_probe_plan_origin}';"

assert_status 200 'load Führungsstellen page as S1' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    "$base_url/4fach/fuehrungsstelle.php"
assert_no_runtime_error 'S1 Führungsstellen page'
s1_operations_csrf=$(csrf_from_body)
s1_probe_plan_origin="CI_HTTP_S1_${identity_seed}"
assert_status 403 'reject S6 plan creation by fixed S1 account' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s1_operations_csrf" \
    --data-urlencode 'operation_action=create_plan' \
    --data-urlencode "herkunft=$s1_probe_plan_origin" \
    --data-urlencode 'gueltig_ab=2026-01-01T00:00' \
    --data-urlencode 'gueltig_bis=2099-12-31T23:59' \
    --data-urlencode 'betriebsleitung=Nur S6 darf speichern' \
    "$base_url/4fach/fuehrungsstelle.php"
assert_db_equals 0 'S1 account created no S6 plan' \
    "SELECT COUNT(*) FROM \`nv_fernmeldeplaene\` WHERE \`einsatz_id\`=${active_incident_id} AND BINARY \`herkunft\`=BINARY '${s1_probe_plan_origin}';"

assert_status 200 'load Führungsstellen page as S6' \
    --cookie "$s6_cookies" --cookie-jar "$s6_cookies" \
    "$base_url/4fach/fuehrungsstelle.php"
assert_no_runtime_error 'S6 Führungsstellen page with fixed account function'
assert_body 'S6 · Stab' 'fixed S6 account function'

# The attachment controller is a direct endpoint as well as an included
# message workflow. The fixed LdF and Si account functions must not grant
# upload authority when their menu route offers no attachment action.
assert_status 403 'reject direct attachment administration as LdF' \
    --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
    "$base_url/4fach/anhang.php"
assert_body 'darf die Anhangverwaltung nicht öffnen' \
    'direct LdF attachment rejection'
assert_status 403 'reject direct attachment administration as Si' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    "$base_url/4fach/anhang.php"
assert_body 'darf die Anhangverwaltung nicht öffnen' \
    'direct Si attachment rejection'

http_plan_origin="CI_HTTP_POST_${identity_seed}"
assert_status 200 'load Führungsstellen page for S6 plan creation' \
    --cookie "$s6_cookies" --cookie-jar "$s6_cookies" \
    "$base_url/4fach/fuehrungsstelle.php"
assert_no_runtime_error 'S6 Führungsstellen page before plan creation'
s6_operations_csrf=$(csrf_from_body)
assert_status 303 'create S6 plan through Führungsstellen HTTP controller' \
    --cookie "$s6_cookies" --cookie-jar "$s6_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s6_operations_csrf" \
    --data-urlencode 'operation_action=create_plan' \
    --data-urlencode "herkunft=$http_plan_origin" \
    --data-urlencode 'gueltig_ab=2026-01-01T00:00' \
    --data-urlencode 'gueltig_bis=2099-12-31T23:59' \
    --data-urlencode 'betriebsleitung=S6 HTTP Integrationsprüfung' \
    --data-urlencode 'bemerkungen=Realer HTTP-Nachweis des S6-Fernmeldeplans' \
    "$base_url/4fach/fuehrungsstelle.php"
http_plan_id=$(db_sql <<SQL
SELECT \`fernmeldeplan_id\`
  FROM \`nv_fernmeldeplaene\`
 WHERE \`einsatz_id\` = ${active_incident_id}
   AND BINARY \`herkunft\` = BINARY '${http_plan_origin}'
 ORDER BY \`fernmeldeplan_id\` DESC
 LIMIT 1;
SQL
)
assert_numeric 'HTTP-created S6 plan' "$http_plan_id"
assert_db_equals "ENTWURF|${s6_code}" 'HTTP-created S6 plan ownership' \
    "SELECT CONCAT(\`status\`, '|', \`erstellt_von\`) FROM \`nv_fernmeldeplaene\` WHERE \`fernmeldeplan_id\`=${http_plan_id} AND \`einsatz_id\`=${active_incident_id};"

assert_status 200 'load Führungsstellen page for S6 route creation' \
    --cookie "$s6_cookies" --cookie-jar "$s6_cookies" \
    "$base_url/4fach/fuehrungsstelle.php"
assert_no_runtime_error 'S6 Führungsstellen page before route creation'
s6_operations_csrf=$(csrf_from_body)
assert_status 303 'add S6 route through Führungsstellen HTTP controller' \
    --cookie "$s6_cookies" --cookie-jar "$s6_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s6_operations_csrf" \
    --data-urlencode 'operation_action=add_plan_entry' \
    --data-urlencode "fernmeldeplan_id=$http_plan_id" \
    --data-urlencode 'betriebsstelle=HTTP Betriebsstelle' \
    --data-urlencode 'rufname=HTTP Rufname' \
    --data-urlencode 'medium=Fu' \
    --data-urlencode 'kanal=HTTP-1' \
    --data-urlencode 'bandlage=G/U' \
    --data-urlencode 'verkehrsform=Gegenverkehr' \
    --data-urlencode 'besondere_vermerke=Nur Integrationsprüfung' \
    --data-urlencode 'bemerkungen=Über den echten Controller gespeichert' \
    "$base_url/4fach/fuehrungsstelle.php"
http_plan_entry_id=$(db_sql <<SQL
SELECT \`fernmeldeplan_eintrag_id\`
  FROM \`nv_fernmeldeplan_eintraege\`
 WHERE \`fernmeldeplan_id\` = ${http_plan_id}
 ORDER BY \`sortierung\` DESC
 LIMIT 1;
SQL
)
assert_numeric 'HTTP-created S6 route' "$http_plan_entry_id"

assert_status 200 'load Führungsstellen page for S6 plan activation' \
    --cookie "$s6_cookies" --cookie-jar "$s6_cookies" \
    "$base_url/4fach/fuehrungsstelle.php"
assert_no_runtime_error 'S6 Führungsstellen page before plan activation'
s6_operations_csrf=$(csrf_from_body)
assert_status 303 'activate S6 plan through Führungsstellen HTTP controller' \
    --cookie "$s6_cookies" --cookie-jar "$s6_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s6_operations_csrf" \
    --data-urlencode 'operation_action=activate_plan' \
    --data-urlencode "fernmeldeplan_id=$http_plan_id" \
    "$base_url/4fach/fuehrungsstelle.php"
assert_db_equals \
    "AKTIV|${s6_code}|${s6_code}|HTTP Betriebsstelle|HTTP Rufname|Fu|HTTP-1|G/U|Gegenverkehr" \
    'HTTP-created S6 plan persisted route and release' \
    "SELECT CONCAT(p.\`status\`, '|', p.\`erstellt_von\`, '|', p.\`freigegeben_von\`, '|', e.\`betriebsstelle\`, '|', e.\`rufname\`, '|', e.\`medium\`, '|', e.\`kanal\`, '|', e.\`bandlage\`, '|', e.\`verkehrsform\`) FROM \`nv_fernmeldeplaene\` AS p JOIN \`nv_fernmeldeplan_eintraege\` AS e ON e.\`fernmeldeplan_id\`=p.\`fernmeldeplan_id\` WHERE p.\`fernmeldeplan_id\`=${http_plan_id} AND e.\`fernmeldeplan_eintrag_id\`=${http_plan_entry_id};"
assert_db_equals '3|3|3' 'HTTP-created S6 plan immutable audit trail' \
    "SELECT CONCAT(COUNT(*), '|', COUNT(DISTINCT \`aktion\`), '|', SUM(BINARY \`akteur_kuerzel\`=BINARY '${s6_code}')) FROM \`nv_betriebsereignisse\` WHERE \`einsatz_id\`=${active_incident_id} AND \`objekttyp\`='FERNMELDEPLAN' AND \`objekt_id\`=${http_plan_id} AND \`aktion\` IN ('plan_created','plan_entry_added','plan_activated');"

# Publish both versions through the production S6 domain. Activating the
# second version creates the immutable ERSETZT predecessor used by the stale
# browser-choice test.
telecom_fixture=$(
    ESTAB_TEST_TELECOM_MODE=initial \
    ESTAB_TEST_TELECOM_S6_CODE=$s6_code \
    ESTAB_TEST_TELECOM_TOKEN=$identity_seed \
    "$compose_engine" compose run --rm --no-deps -T \
        --env "COMPOSE_PROJECT_NAME=$project_name" \
        --env ESTAB_TEST_TELECOM_ALLOW_MUTATION=true \
        --env ESTAB_TEST_TELECOM_MODE \
        --env ESTAB_TEST_TELECOM_S6_CODE \
        --env ESTAB_TEST_TELECOM_TOKEN \
        --volume "$repo_root:/workspace:ro" \
        --workdir /workspace \
        app php -d auto_prepend_file= \
        tests/integration/create_http_telecom_fixture.php |
        tail -n 1
)
telecom_replaced_route_id=$(printf '%s' "$telecom_fixture" | cut -d'|' -f1)
telecom_route_id=$(printf '%s' "$telecom_fixture" | cut -d'|' -f2)
telecom_plan_version=$(printf '%s' "$telecom_fixture" | cut -d'|' -f3)
assert_numeric 'superseded S6 route fixture' "$telecom_replaced_route_id"
assert_numeric 'active S6 route fixture' "$telecom_route_id"
assert_numeric 'active S6 plan version' "$telecom_plan_version"

# Seed two already completed pairs in the disposable database. The real LdF
# forms below must recognize them as context matches while keeping the entered
# value freely editable. x04_druck is set so this read-only assistance fixture
# cannot enter the later unprinted-message workflow.
db_sql >/dev/null <<SQL
INSERT INTO \`nv_nachrichten\`
  (
    \`einsatz_id\`, \`04_richtung\`, \`05_gegenstelle\`,
    \`10_anschrift\`, \`12_inhalt\`, \`13_abseinheit\`,
    \`x00_status\`, \`x01_abschluss\`, \`x04_druck\`
  )
VALUES
  (
    ${active_incident_id}, 'E', 'E2E-Gegenstelle', '',
    '${mapping_incoming_marker}', 'E2E-Absender', 8, 't', 't'
  ),
  (
    ${active_incident_id}, 'A', 'E2E-Gegenstelle',
    'E2E-Zielstelle korrigiert', '${mapping_outgoing_marker}',
    '${authoritative_sender}', 8, 't', 't'
  );
SQL
assert_db_equals 2 'completed pair-aware mapping fixtures' \
    "SELECT COUNT(*) FROM \`nv_nachrichten\` WHERE \`einsatz_id\`=${active_incident_id} AND \`12_inhalt\` IN ('${mapping_incoming_marker}', '${mapping_outgoing_marker}') AND \`x00_status\`=8 AND \`x01_abschluss\` IN ('t','1');"

load_sidebar "$ldf_cookies" 'LdF role navigation'
assert_body 'name="ldf_nachrichten_x"' 'LdF disposition action'
assert_body_absent 'name="fm_eingang_x"' 'LdF must not receive A/W input action'
assert_body_absent 'name="fm_ausgang_x"' 'LdF must not receive A/W output action'
assert_body_absent 'name="stab_schreiben_x"' 'LdF must not receive staff action'
assert_db_equals 1 'online Si fixture' \
    "SELECT COUNT(*) FROM \`nv_benutzer\` WHERE \`kuerzel\`='${si_code}' AND \`funktion\`='Si' AND \`aktiv\`=1;"

# Prove the real FB profile before using POL as an automatic recipient. A
# Fachberater gets the staff writer/reader controls, but neither the Si nor the
# telecommunications actions.
load_sidebar "$pol_cookies" 'POL/FB role navigation'
assert_body 'name="stab_schreiben_x"' 'POL/FB write action'
assert_body 'name="stab_lesen_x"' 'POL/FB read action'
assert_body_absent 'name="fm_eingang_x"' 'POL/FB telecommunications action'
assert_body_absent 'name="si_admin_x"' 'POL/FB viewer action'
pol_csrf=$(csrf_from_body)
assert_status 403 'reject POL/FB telecommunications action' \
    --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$pol_csrf" \
    --data-urlencode 'fm_eingang_x=1' \
    "$base_url/4fach/mainindex.php"
load_sidebar "$pol_cookies" 'POL/FB role navigation after rejected action'
pol_csrf=$(csrf_from_body)
assert_status 403 'reject POL/FB viewer administration action' \
    --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$pol_csrf" \
    --data-urlencode 'si_admin_x=1' \
    "$base_url/4fach/mainindex.php"

# Incoming message: A/W captures a real form (status 4), Si reviews it
# (status 8), assigns the exact red/green/blue copies and closes it.
load_dashboard "$aw_cookies" 'A/W dashboard before incoming capture'
incoming_csrf=$(csrf_from_body)
incoming_clock_before=$(app_tactical_clock)
assert_status 200 'open A/W incoming form' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$incoming_csrf" \
    --data-urlencode 'fm_eingang_x=1' \
    "$base_url/4fach/mainindex.php"
incoming_clock_after=$(app_tactical_clock)
assert_no_runtime_error 'A/W incoming form'
assert_body 'name="task" value="FM-Eingang"' 'A/W incoming form'
assert_body_absent 'name="task" value="FM-Eingang_Sichter"' 'A/W incoming form'
assert_body \
    'data-estab-incident-suggestions="callsign"' \
    'A/W incoming incident callsign suggestions'
assert_body \
    'list="estab-message-callsign-suggestions-native"' \
    'A/W incoming no-script callsign suggestion fallback'
assert_body \
    'autocomplete="off"' \
    'A/W incoming suggestion browser-autocomplete isolation'
assert_body \
    'aria-describedby="estab-message-callsign-suggestions-hint"' \
    'A/W incoming accessible callsign suggestion hint'
assert_body \
    'id="estab-message-callsign-suggestions"' \
    'A/W incoming callsign listbox'
assert_body \
    'role="combobox" aria-autocomplete="list"' \
    'A/W incoming accessible callsign combobox'
assert_body \
    'role="listbox" aria-label="Vorschläge aus dem aktiven Einsatz"' \
    'A/W incoming focus suggestion listbox'
assert_body \
    'data-estab-message-suggestion-picker' \
    'A/W incoming suggestion keyboard helper'
assert_body \
    'Wird durch LdF aus dem Rufnamen ergänzt' \
    'A/W incoming sender responsibility'
assert_body_absent \
    'name="13_abseinheit"' \
    'A/W incoming sender input'
assert_body_absent \
    'data-estab-incident-suggestions="sender"' \
    'A/W incoming sender suggestions'
incoming_attachment_request_token=$(
    message_attachment_request_token_from_body
)
assert_current_editable_tactical_time_input \
    f_01_datum "$incoming_clock_before" "$incoming_clock_after" \
    'A/W receipt time'
incoming_csrf=$(csrf_from_body)
tactical_time=$(date '+%H%M')
incoming_backdated_clock=$(app_backdated_clock)
incoming_backdated_tactical=${incoming_backdated_clock%%|*}
incoming_backdated_sql=${incoming_backdated_clock#*|}
assert_status 403 'reject A/W incoming sender overpost' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$incoming_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=FM-Eingang' \
    --data-urlencode '13_abseinheit=Gefälschter Absender' \
    "$base_url/4fach/mainindex.php"
assert_db_equals 0 'rejected A/W sender overpost created no message' \
    "SELECT COUNT(*) FROM \`nv_nachrichten\` WHERE \`12_inhalt\`='${incoming_marker}';"
assert_status 403 'reject A/W incoming recipient overpost' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$incoming_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=FM-Eingang' \
    --data-urlencode '16_empf=S1_bl,' \
    --data-urlencode '16_21=16_21_bl' \
    --data-urlencode '16_gncopy=16_21_gn' \
    --data-urlencode "12_inhalt=$incoming_marker" \
    "$base_url/4fach/mainindex.php"
assert_db_equals 0 'rejected A/W recipient overpost created no message' \
    "SELECT COUNT(*) FROM \`nv_nachrichten\` WHERE \`12_inhalt\`='${incoming_marker}';"
assert_status 200 'save A/W incoming message' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$incoming_csrf" \
    --data-urlencode \
        "message_attachment_request_token=$incoming_attachment_request_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=FM-Eingang' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode "01_datum=$incoming_backdated_tactical" \
    --data-urlencode "01_zeichen=$aw_code" \
    --data-urlencode '05_gegenstelle=E2E-Gegenstelle' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=eee' \
    --data-urlencode '10_anschrift=E2E-Einsatzleitung' \
    --data-urlencode "11_rufnummer=$incoming_phone" \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_betreff=$incoming_subject" \
    --data-urlencode "12_inhalt=$incoming_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '14_zeichen=' \
    --data-urlencode '14_funktion=' \
    --data-urlencode '17_vermerke=' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'saved A/W incoming message'
assert_db_equals \
    "${incoming_phone}|${incoming_subject}" \
    'persisted official incoming phone and subject' \
    "SELECT CONCAT(\`11_rufnummer\`, '|', \`12_betreff\`) FROM \`nv_nachrichten\` WHERE \`12_inhalt\`='${incoming_marker}';"

incoming_id=$(db_sql <<SQL
SELECT \`00_lfd\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${incoming_marker}';
SQL
)
incoming_number=$(db_sql <<SQL
SELECT \`04_nummer\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${incoming_marker}';
SQL
)
assert_numeric 'incoming message ID' "$incoming_id"
assert_numeric 'incoming evidence number' "$incoming_number"
assert_db_equals "$incoming_backdated_sql" 'edited A/W receipt time' \
    "SELECT DATE_FORMAT(\`01_datum\`, '%Y-%m-%d %H:%i:00') FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${incoming_id};"
assert_db_equals '' 'A/W persisted no incoming sender' \
    "SELECT \`13_abseinheit\` FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${incoming_id};"
assert_db_equals "$authoritative_sender" \
    'A/W incoming local destination came from active incident' \
    "SELECT \`10_anschrift\` FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${incoming_id};"
assert_message_state "$incoming_marker" \
    'E|1|f|null||S2_rt,|f||f' \
    'A/W incoming status 1 awaiting LdF'
if ! generated_form_check absent E "$incoming_number"; then
    echo 'Message workflow HTTP: incoming form existed before completion' >&2
    exit 1
fi
incoming_form_cleanup_owned=true

load_dashboard "$si_cookies" 'Si queue before incoming LdF translation'
assert_body_absent \
    "$incoming_marker" \
    'untranslated incoming message hidden from Si'
load_dashboard "$s2_cookies" 'S2 queue before incoming LdF translation'
assert_body_absent \
    "$incoming_marker" \
    'untranslated incoming message hidden from recipients'
finish_ldf_incoming \
    "$incoming_marker" "$incoming_id" 'E2E-Absender' 'Me' \
    'Nach Rücksprache als Melderweg bestätigt'
assert_message_state "$incoming_marker" \
    'E|4|f|null||S2_rt,|f||f' \
    'LdF-translated incoming status 4'
# Simulate a recipient token left by an older or bypassing writer. The list,
# detail object gate and repository-backed state action must all remain closed
# until Si reaches terminal status 8.
db_sql <<SQL
UPDATE \`nv_nachrichten\`
   SET \`16_empf\` = 'S2_rt,S1_bl,'
 WHERE \`00_lfd\` = ${incoming_id}
   AND \`x00_status\` = 4;
SQL
load_dashboard "$s1_cookies" 'S1 queue before incoming Si review'
assert_body_absent \
    "$incoming_marker" \
    'pending incoming message hidden despite forged staff recipient'
s1_pending_csrf=$(csrf_from_body)
assert_status 403 'reject S1 incoming detail before Si completion' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s1_pending_csrf" \
    --data-urlencode 'stab=meldung' \
    --data-urlencode "00_lfd=$incoming_id" \
    "$base_url/4fach/mainindex.php"
assert_status 403 'reject S1 incoming state before Si completion' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s1_pending_csrf" \
    --data-urlencode 'action=gelesen' \
    --data-urlencode 'todo=set' \
    --data-urlencode "00_lfd=$incoming_id" \
    "$base_url/4fach/mainindex.php"
assert_db_equals 0 'pending incoming created no S1 read state' \
    "SELECT COUNT(*) FROM \`usr_s1_${s1_code}_read\` WHERE \`nachnum\`=${incoming_id};"
db_sql <<SQL
UPDATE \`nv_nachrichten\`
   SET \`16_empf\` = 'S2_rt,'
 WHERE \`00_lfd\` = ${incoming_id}
   AND \`x00_status\` = 4;
SQL

open_viewer_message "$incoming_marker" "$incoming_id"
viewer_csrf=$(csrf_from_body)
incoming_events_before_forgery=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_nachrichten_ereignisse\`
 WHERE \`message_id\` = ${incoming_id};
SQL
)
assert_status 403 'reject Si recipient suffix injection' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$viewer_csrf" \
    --data-urlencode \
        "recipient_matrix_revision=$viewer_matrix_revision" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_sichten' \
    --data-urlencode "00_lfd=$incoming_id" \
    --data-urlencode "15_quitzeichen=$si_code" \
    --data-urlencode '16_21=16_21_bl,alle' \
    --data-urlencode '17_vermerke=forged recipient suffix' \
    "$base_url/4fach/mainindex.php"
assert_db_equals \
    "4|f|S2_rt,|${incoming_events_before_forgery}" \
    'rejected Si recipient suffix changed no message evidence' \
    "SELECT CONCAT(n.\`x00_status\`, '|', n.\`x01_abschluss\`, '|', n.\`16_empf\`, '|', (SELECT COUNT(*) FROM \`nv_nachrichten_ereignisse\` AS e WHERE e.\`message_id\`=n.\`00_lfd\`)) FROM \`nv_nachrichten\` AS n WHERE n.\`00_lfd\`=${incoming_id};"

assert_status 403 'reject removed external green-copy input' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$viewer_csrf" \
    --data-urlencode \
        "recipient_matrix_revision=$viewer_matrix_revision" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_sichten' \
    --data-urlencode "00_lfd=$incoming_id" \
    --data-urlencode '16_gncopy=16_21_gn' \
    --data-urlencode '16_21=16_21_bl' \
    --data-urlencode '17_vermerke=forged removed copy control' \
    "$base_url/4fach/mainindex.php"
assert_db_equals \
    "4|f|S2_rt,|${incoming_events_before_forgery}" \
    'removed external copy input changed no message evidence' \
    "SELECT CONCAT(n.\`x00_status\`, '|', n.\`x01_abschluss\`, '|', n.\`16_empf\`, '|', (SELECT COUNT(*) FROM \`nv_nachrichten_ereignisse\` AS e WHERE e.\`message_id\`=n.\`00_lfd\`)) FROM \`nv_nachrichten\` AS n WHERE n.\`00_lfd\`=${incoming_id};"

finish_viewer_message "$incoming_marker" "$incoming_id" 'E2E incoming reviewed'
assert_message_state "$incoming_marker" \
    "E|8|t|set|${si_code}|S2_rt,S1_bl,POL_bl,S3_bl,|f||t" \
    'Si-completed incoming status 8'
if ! generated_form_check present E "$incoming_number"; then
    echo 'Message workflow HTTP: incoming completion generated no form' >&2
    exit 1
fi
assert_db_equals 'created,ldf_dispatched,incoming_routed' \
    'incoming DV transition event order' \
    "SELECT GROUP_CONCAT(\`event_type\` ORDER BY \`event_id\` SEPARATOR ',') FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${incoming_id};"

load_dashboard "$si_cookies" 'Si queue after incoming completion'
assert_body_absent "$incoming_marker" 'Si queue after incoming completion'
for recipient in s1 s2 s3; do
    case "$recipient" in
        s1) recipient_cookies=$s1_cookies ;;
        s2) recipient_cookies=$s2_cookies ;;
        s3) recipient_cookies=$s3_cookies ;;
    esac
    load_dashboard "$recipient_cookies" "$recipient incoming list"
    assert_body "$incoming_marker" "$recipient incoming list"
    assert_route_control stab meldung "$incoming_id" "$recipient incoming list"
done

# Completed messages are documentary evidence. A/W and Si may inspect the
# administration archive, but neither UI nor forged POST may mutate a field or
# append a transition event.
incoming_admin_immutable_before=$(
    message_admin_immutable_fingerprint "$incoming_marker"
)
if ! printf '%s' "$incoming_admin_immutable_before" |
    grep -Eq '^[a-f0-9]{64}$'; then
    echo 'Message workflow HTTP: invalid incoming admin evidence snapshot' >&2
    exit 1
fi
incoming_quit_timestamp_before=$(db_sql <<SQL
SELECT DATE_FORMAT(\`15_quitdatum\`, '%Y-%m-%d %H:%i:%s')
  FROM \`nv_nachrichten\`
 WHERE \`00_lfd\` = ${incoming_id};
SQL
)
if ! printf '%s' "$incoming_quit_timestamp_before" |
    grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'; then
    echo 'Message workflow HTTP: invalid completed incoming review timestamp' >&2
    exit 1
fi
assert_db_equals \
    "${incoming_quit_timestamp_before}|${si_code}|S2_rt,S1_bl,POL_bl,S3_bl,|E2E incoming reviewed" \
    'incoming review evidence before FM-Admin' \
    "SELECT CONCAT(DATE_FORMAT(\`15_quitdatum\`, '%Y-%m-%d %H:%i:%s'), '|', \`15_quitzeichen\`, '|', COALESCE(\`16_empf\`, ''), '|', COALESCE(\`17_vermerke\`, '')) FROM \`nv_nachrichten\` WHERE \`00_lfd\` = ${incoming_id};"

# Exercise the real S2 overview request boundary, including the dynamically
# loaded recipient allowlist, combined filters and an explicit pager request.
assert_status 200 'filter S2 message overview' \
    --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    --get \
    --data-urlencode "ml_q=$incoming_marker" \
    --data-urlencode 'ml_direction=E' \
    --data-urlencode 'ml_recipient=S2' \
    --data-urlencode 'ml_sort=number_asc' \
    --data-urlencode 'ml_page_size=25' \
    --data-urlencode 'ml_page=1' \
    "$base_url/4fueltg/ue_ltg.php"
assert_no_runtime_error 'filtered S2 message overview'
assert_body 'data-estab-message-overview data-estab-message-list' \
    'S2 shared message-list shell'
assert_body 'name="ml_q"' 'S2 message search control'
assert_body 'name="ml_recipient"' 'S2 recipient filter control'
assert_body 'Aktive Filter' 'S2 active filter chips'
assert_body 'Seite 1 von ' 'S2 server-side pager'
assert_body "$incoming_marker" 'S2 filtered message overview result'

assert_status 200 'empty S2 message overview search' \
    --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    --get \
    --data-urlencode "ml_q=NO_MATCH_${identity_seed}" \
    "$base_url/4fueltg/ue_ltg.php"
assert_no_runtime_error 'empty S2 message overview search'
assert_body 'Keine passenden Nachrichten' 'S2 empty filtered state'
assert_body_absent "$incoming_marker" 'S2 empty filtered result'

assert_status 200 'reset S2 message overview filters' \
    --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    --get --data-urlencode 'ml_reset=1' \
    "$base_url/4fueltg/ue_ltg.php"
assert_no_runtime_error 'reset S2 message overview filters'
assert_body "$incoming_marker" 'reset S2 message overview result'

load_sidebar "$aw_cookies" 'A/W navigation before second review'
assert_body 'name="fm_admin_x"' 'rendered A/W second-review action'
admin_csrf=$(csrf_from_body)
assert_status 200 'open A/W second-review list' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode 'fm_admin_x=1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'A/W second-review list'
assert_body 'estab-ui.css' 'styled A/W second-review frame'
assert_body 'data-estab-second-sighting="aw"' 'A/W shared second-review shell'
assert_body "$incoming_marker" 'A/W second-review list'
assert_route_control \
    fm FM-Adminmeldung "$incoming_id" 'A/W FM-Admin detail control'

admin_csrf=$(csrf_from_body)
assert_status 200 'filter A/W second-review list' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode 'fm_admin_x=1' \
    --data-urlencode "ml_q=$incoming_marker" \
    --data-urlencode 'ml_direction=E' \
    --data-urlencode 'ml_sort=number_asc' \
    --data-urlencode 'ml_page_size=25' \
    --data-urlencode 'ml_page=1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'filtered A/W second-review list'
assert_body 'Aktive Filter' 'A/W active filter chips'
assert_body 'Seite 1 von ' 'A/W server-side pager'
assert_body "$incoming_marker" 'A/W filtered second-review result'

admin_csrf=$(csrf_from_body)
assert_status 200 'empty A/W second-review search' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode 'fm_admin_x=1' \
    --data-urlencode "ml_q=NO_MATCH_${identity_seed}" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'empty A/W second-review search'
assert_body 'Keine passenden Nachrichten' 'A/W empty filtered state'
assert_body_absent "$incoming_marker" 'A/W empty filtered result'

admin_csrf=$(csrf_from_body)
assert_status 200 'reset A/W second-review filters' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode 'fm_admin_x=1' \
    --data-urlencode 'ml_reset=1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'reset A/W second-review filters'
assert_body "$incoming_marker" 'reset A/W second-review result'

admin_csrf=$(csrf_from_body)
assert_status 200 'open completed incoming FM-Admin form' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode 'fm=FM-Adminmeldung' \
    --data-urlencode "00_lfd=$incoming_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'completed incoming FM-Admin form'
assert_body 'Abgeschlossener Nachweis – ' 'FM-Admin immutable evidence banner'
assert_body 'schreibgeschützt' 'FM-Admin immutable evidence banner'
assert_body_absent 'name="task" value="FM-Admin"' 'FM-Admin mutation task'
assert_body_absent 'name="absenden"' 'FM-Admin save control'
assert_body_absent 'name="15_quitzeichen"' 'FM-Admin editable review mark'
assert_body_absent 'name="16_gncopy"' 'FM-Admin editable distribution'
assert_body_absent 'name="17_vermerke"' 'FM-Admin editable note'
incoming_event_count_before=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_nachrichten_ereignisse\`
 WHERE \`message_id\` = ${incoming_id};
SQL
)
admin_csrf=$(csrf_from_body)
assert_status 403 'reject completed incoming FM-Admin mutation' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=FM-Admin' \
    --data-urlencode "00_lfd=$incoming_id" \
    --data-urlencode "15_quitzeichen=$aw_code" \
    --data-urlencode '16_21=16_21_bl' \
    --data-urlencode '16_gncopy=16_41_gn' \
    --data-urlencode "17_vermerke=$fm_admin_note" \
    "$base_url/4fach/mainindex.php"

incoming_admin_immutable_after=$(
    message_admin_immutable_fingerprint "$incoming_marker"
)
if [ "$incoming_admin_immutable_after" != \
    "$incoming_admin_immutable_before" ]; then
    echo 'Message workflow HTTP: FM-Admin changed immutable message evidence' >&2
    exit 1
fi
assert_message_state "$incoming_marker" \
    "E|8|t|set|${si_code}|S2_rt,S1_bl,POL_bl,S3_bl,|f||t" \
    'FM-Admin preserved completed incoming state'
assert_db_equals \
    "${incoming_quit_timestamp_before}|${si_code}|S2_rt,S1_bl,POL_bl,S3_bl,|E2E incoming reviewed" \
    'FM-Admin rejected all completed-message changes' \
    "SELECT CONCAT(DATE_FORMAT(\`15_quitdatum\`, '%Y-%m-%d %H:%i:%s'), '|', \`15_quitzeichen\`, '|', COALESCE(\`16_empf\`, ''), '|', COALESCE(\`17_vermerke\`, '')) FROM \`nv_nachrichten\` WHERE \`00_lfd\` = ${incoming_id};"
assert_db_equals "$incoming_event_count_before" \
    'FM-Admin appended no event for rejected mutation' \
    "SELECT COUNT(*) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\` = ${incoming_id};"
if ! generated_form_check present E "$incoming_number"; then
    echo 'Message workflow HTTP: FM-Admin lost the completed incoming form' >&2
    exit 1
fi

# Si independently opens the same archive and gets the identical read-only
# evidence boundary.
si_admin_immutable_before=$(
    message_admin_immutable_fingerprint "$incoming_marker"
)
if ! printf '%s' "$si_admin_immutable_before" |
    grep -Eq '^[a-f0-9]{64}$'; then
    echo 'Message workflow HTTP: invalid SI-Admin evidence snapshot' >&2
    exit 1
fi
load_sidebar "$si_cookies" 'Si navigation before second review'
assert_body 'name="si_admin_x"' 'rendered Si second-review action'
si_admin_csrf=$(csrf_from_body)
assert_status 200 'open Si second-review list' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$si_admin_csrf" \
    --data-urlencode 'si_admin_x=1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'Si second-review list'
assert_body 'estab-ui.css' 'styled Si second-review frame'
assert_body 'data-estab-second-sighting="si"' 'Si shared second-review shell'
assert_body "$incoming_marker" 'Si second-review list'
assert_route_control \
    fm SI-Adminmeldung "$incoming_id" 'SI-Admin detail control'

si_admin_csrf=$(csrf_from_body)
assert_status 200 'filter Si second-review list' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$si_admin_csrf" \
    --data-urlencode 'si_admin_x=1' \
    --data-urlencode "ml_q=$incoming_marker" \
    --data-urlencode 'ml_direction=E' \
    --data-urlencode 'ml_page_size=25' \
    --data-urlencode 'ml_page=1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'filtered Si second-review list'
assert_body 'Aktive Filter' 'Si active filter chips'
assert_body 'Seite 1 von ' 'Si server-side pager'
assert_body "$incoming_marker" 'Si filtered second-review result'

si_admin_csrf=$(csrf_from_body)
assert_status 200 'empty Si second-review search' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$si_admin_csrf" \
    --data-urlencode 'si_admin_x=1' \
    --data-urlencode "ml_q=NO_MATCH_${identity_seed}" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'empty Si second-review search'
assert_body 'Keine passenden Nachrichten' 'Si empty filtered state'
assert_body_absent "$incoming_marker" 'Si empty filtered result'

si_admin_csrf=$(csrf_from_body)
assert_status 200 'reset Si second-review filters' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$si_admin_csrf" \
    --data-urlencode 'si_admin_x=1' \
    --data-urlencode 'ml_reset=1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'reset Si second-review filters'
assert_body "$incoming_marker" 'reset Si second-review result'

si_admin_csrf=$(csrf_from_body)
assert_status 200 'open completed incoming SI-Admin form' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$si_admin_csrf" \
    --data-urlencode 'fm=SI-Adminmeldung' \
    --data-urlencode "00_lfd=$incoming_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'completed incoming SI-Admin form'
assert_body 'Abgeschlossener Nachweis – ' 'SI-Admin immutable evidence banner'
assert_body 'schreibgeschützt' 'SI-Admin immutable evidence banner'
assert_body_absent 'name="task" value="SI-Admin"' 'SI-Admin mutation task'
assert_body_absent 'name="absenden"' 'SI-Admin save control'
assert_body_absent 'name="15_quitzeichen"' 'SI-Admin editable review mark'
assert_body_absent 'name="16_gncopy"' 'SI-Admin editable distribution'
assert_body_absent 'name="17_vermerke"' 'SI-Admin editable note'
si_admin_csrf=$(csrf_from_body)
assert_status 403 'reject completed incoming SI-Admin mutation' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$si_admin_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=SI-Admin' \
    --data-urlencode "00_lfd=$incoming_id" \
    --data-urlencode "15_quitzeichen=$si_code" \
    --data-urlencode '16_32=16_32_bl' \
    --data-urlencode '16_gncopy=16_21_gn' \
    --data-urlencode "17_vermerke=$si_admin_note" \
    "$base_url/4fach/mainindex.php"

si_admin_immutable_after=$(
    message_admin_immutable_fingerprint "$incoming_marker"
)
if [ "$si_admin_immutable_after" != "$si_admin_immutable_before" ]; then
    echo 'Message workflow HTTP: SI-Admin changed immutable message evidence' >&2
    exit 1
fi
assert_message_state "$incoming_marker" \
    "E|8|t|set|${si_code}|S2_rt,S1_bl,POL_bl,S3_bl,|f||t" \
    'SI-Admin preserved completed incoming state'
assert_db_equals \
    "${incoming_quit_timestamp_before}|${si_code}|S2_rt,S1_bl,POL_bl,S3_bl,|E2E incoming reviewed" \
    'SI-Admin rejected all completed-message changes' \
    "SELECT CONCAT(DATE_FORMAT(\`15_quitdatum\`, '%Y-%m-%d %H:%i:%s'), '|', \`15_quitzeichen\`, '|', COALESCE(\`16_empf\`, ''), '|', COALESCE(\`17_vermerke\`, '')) FROM \`nv_nachrichten\` WHERE \`00_lfd\` = ${incoming_id};"
assert_db_equals "$incoming_event_count_before" \
    'SI-Admin appended no event for rejected mutation' \
    "SELECT COUNT(*) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\` = ${incoming_id};"
if ! generated_form_check present E "$incoming_number"; then
    echo 'Message workflow HTTP: SI-Admin lost the completed incoming form' >&2
    exit 1
fi

# Leave the persistent SI-Admin list through the rendered viewer action. Later
# assertions about the ordinary Si queue must not accidentally inspect the
# administration archive merely because this test exercised second review.
load_sidebar "$si_cookies" 'Si navigation after second review'
assert_body 'name="stab_sichten_x"' 'rendered Si viewer action after second review'
si_admin_csrf=$(csrf_from_body)
assert_status 200 'return from Si second review to normal viewer queue' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$si_admin_csrf" \
    --data-urlencode 'stab_sichten_x=1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'Si normal viewer queue after second review'
assert_body_absent "$incoming_marker" 'Si normal viewer queue after second review'

# The real POL/FB recipient now reads the completed message and exercises both
# derivation controls. Each generated Stab_schreiben form is then submitted to
# create a distinct outgoing database record, while the source fingerprint
# remains byte-for-byte unchanged.
derived_source_before=$(
    message_admin_immutable_fingerprint "$incoming_marker"
)
load_dashboard "$pol_cookies" 'POL/FB list before answer'
assert_body "$incoming_marker" 'POL/FB list before answer'
assert_route_control stab meldung "$incoming_id" 'POL/FB incoming detail control'
pol_csrf=$(csrf_from_body)
assert_status 200 'open incoming message as POL/FB for answer' \
    --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$pol_csrf" \
    --data-urlencode 'stab=meldung' \
    --data-urlencode "00_lfd=$incoming_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'POL/FB incoming read form for answer'
assert_body 'name="task" value="Stab_lesen"' 'POL/FB incoming read task'
assert_body 'name="antwort_x"' 'POL/FB answer control'
assert_body 'name="weiterleiten_x"' 'POL/FB forward control'

pol_csrf=$(csrf_from_body)
assert_status 200 'derive POL/FB answer form' \
    --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$pol_csrf" \
    --data-urlencode 'antwort_x=1' \
    --data-urlencode 'task=Stab_lesen' \
    --data-urlencode "00_lfd=$incoming_id" \
    --data-urlencode '04_richtung=E' \
    --data-urlencode "04_nummer=$incoming_number" \
    --data-urlencode '10_anschrift=E2E-Einsatzleitung' \
    --data-urlencode '11_rufnummer=Browser-Manipulation' \
    --data-urlencode '12_betreff=Browser-Manipulation' \
    --data-urlencode "12_inhalt=$incoming_marker" \
    --data-urlencode '13_abseinheit=E2E-Absender' \
    --data-urlencode '14_funktion=' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'derived POL/FB answer form'
assert_body 'name="task" value="Stab_schreiben"' 'derived answer task'
assert_body \
    'name="10_anschrift">E2E-Absender  </textarea>' \
    'derived answer destination'
assert_body \
    "name=\"13_abseinheit\" value=\"$authoritative_sender\"" \
    'derived answer sender'
assert_body \
    "name=\"14_zeichen\" value=\"$pol_code\"" \
    'derived answer author code'
assert_body \
    'name="14_funktion" value="POL"' \
    'derived answer author function'
assert_body \
    "name=\"11_rufnummer\" value=\"$incoming_phone\"" \
    'derived answer authoritative phone number'
assert_body \
    "name=\"12_betreff\" value=\"AW: $incoming_subject\"" \
    'derived answer authoritative subject'
assert_body 'name="00_lfd" value=""' 'derived answer new record id'
assert_body 'name="msglfd" value=""' 'derived answer new message id'
assert_body_absent \
    "name=\"00_lfd\" value=\"$incoming_id\"" \
    'derived answer source record id'
assert_body_absent 'Browser-Manipulation' \
    'derived answer browser message-field overpost'
assert_body "Zitat: von E $incoming_number" 'derived answer quote reference'
assert_body "$incoming_marker" 'derived answer quoted content'

reply_content=$(printf 'Zitat: von E %s\n"%s"\n%s' \
    "$incoming_number" "$incoming_marker" "$reply_marker")
roundtrip_staff_followup_attachment \
    'derived POL/FB answer' \
    'E2E-Absender' \
    "$incoming_phone" \
    "AW: $incoming_subject" \
    "$reply_content"
pol_csrf=$(csrf_from_body)
reply_attachment_request_token=$(
    message_attachment_request_token_from_body
)
assert_status 200 'save POL/FB answer' \
    --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$pol_csrf" \
    --data-urlencode \
        "message_attachment_request_token=$reply_attachment_request_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=eee' \
    --data-urlencode '10_anschrift=E2E-Absender' \
    --data-urlencode "11_rufnummer=$incoming_phone" \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_betreff=AW: $incoming_subject" \
    --data-urlencode "12_inhalt=$reply_content" \
    --data-urlencode '12_abfzeit=' \
    --data-urlencode '13_abseinheit=E2E-Einsatzleitung' \
    --data-urlencode "14_zeichen=$pol_code" \
    --data-urlencode '14_funktion=POL' \
    --data-urlencode '17_vermerke=' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'saved POL/FB answer'
reply_id=$(db_sql <<SQL
SELECT \`00_lfd\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` LIKE '%${reply_marker}%';
SQL
)
assert_numeric 'POL/FB answer message ID' "$reply_id"
assert_db_equals \
    "A|4|E2E-Absender|${authoritative_sender}|${pol_code}|POL|S2_rt,POL_gn|${incoming_phone}|AW: ${incoming_subject}|1|1|1" \
    'persisted POL/FB answer' \
    "SELECT CONCAT(\`04_richtung\`, '|', \`x00_status\`, '|', \`10_anschrift\`, '|', \`13_abseinheit\`, '|', \`14_zeichen\`, '|', \`14_funktion\`, '|', \`16_empf\`, '|', \`11_rufnummer\`, '|', \`12_betreff\`, '|', LOCATE('Zitat: von E ${incoming_number}', \`12_inhalt\`) > 0, '|', LOCATE('${incoming_marker}', \`12_inhalt\`) > 0, '|', LOCATE('${reply_marker}', \`12_inhalt\`) > 0) FROM \`nv_nachrichten\` WHERE \`00_lfd\` = ${reply_id};"

load_dashboard "$pol_cookies" 'POL/FB list before forwarding'
assert_body "$incoming_marker" 'POL/FB list before forwarding'
assert_route_control stab meldung "$incoming_id" 'POL/FB forward source control'
pol_csrf=$(csrf_from_body)
assert_status 200 'open incoming message as POL/FB for forwarding' \
    --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$pol_csrf" \
    --data-urlencode 'stab=meldung' \
    --data-urlencode "00_lfd=$incoming_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'POL/FB incoming read form for forwarding'
pol_csrf=$(csrf_from_body)
assert_status 200 'derive POL/FB forwarding form' \
    --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$pol_csrf" \
    --data-urlencode 'weiterleiten_x=1' \
    --data-urlencode 'task=Stab_lesen' \
    --data-urlencode "00_lfd=$incoming_id" \
    --data-urlencode '04_richtung=E' \
    --data-urlencode "04_nummer=$incoming_number" \
    --data-urlencode '10_anschrift=E2E-Einsatzleitung' \
    --data-urlencode '11_rufnummer=Browser-Manipulation' \
    --data-urlencode '12_betreff=Browser-Manipulation' \
    --data-urlencode "12_inhalt=$incoming_marker" \
    --data-urlencode '13_abseinheit=E2E-Absender' \
    --data-urlencode '14_funktion=' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'derived POL/FB forwarding form'
assert_body 'name="task" value="Stab_schreiben"' 'derived forwarding task'
assert_body \
    'name="10_anschrift"></textarea>' \
    'derived forwarding empty destination'
assert_body \
    "name=\"14_zeichen\" value=\"$pol_code\"" \
    'derived forwarding author code'
assert_body \
    'name="14_funktion" value="POL"' \
    'derived forwarding author function'
assert_body \
    'name="11_rufnummer" value=""' \
    'derived forwarding empty phone number'
assert_body \
    "name=\"12_betreff\" value=\"WG: $incoming_subject\"" \
    'derived forwarding authoritative subject'
assert_body 'name="00_lfd" value=""' 'derived forwarding new record id'
assert_body 'name="msglfd" value=""' 'derived forwarding new message id'
assert_body_absent \
    "name=\"00_lfd\" value=\"$incoming_id\"" \
    'derived forwarding source record id'
assert_body_absent 'Browser-Manipulation' \
    'derived forwarding browser message-field overpost'
assert_body "Zitat: von E $incoming_number" 'derived forwarding quote reference'
assert_body "$incoming_marker" 'derived forwarding quoted content'

forward_content=$(printf 'Zitat: von E %s\n"%s"\n%s' \
    "$incoming_number" "$incoming_marker" "$forward_marker")
roundtrip_staff_followup_attachment \
    'derived POL/FB forwarding' \
    'E2E-Weiterleitungsziel' \
    '' \
    "WG: $incoming_subject" \
    "$forward_content"
pol_csrf=$(csrf_from_body)
forward_attachment_request_token=$(
    message_attachment_request_token_from_body
)
assert_status 200 'save POL/FB forwarding' \
    --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$pol_csrf" \
    --data-urlencode \
        "message_attachment_request_token=$forward_attachment_request_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=eee' \
    --data-urlencode '10_anschrift=E2E-Weiterleitungsziel' \
    --data-urlencode '11_rufnummer=' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_betreff=WG: $incoming_subject" \
    --data-urlencode "12_inhalt=$forward_content" \
    --data-urlencode '12_abfzeit=' \
    --data-urlencode '13_abseinheit=E2E-Einsatzleitung' \
    --data-urlencode "14_zeichen=$pol_code" \
    --data-urlencode '14_funktion=POL' \
    --data-urlencode '17_vermerke=' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'saved POL/FB forwarding'
forward_id=$(db_sql <<SQL
SELECT \`00_lfd\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` LIKE '%${forward_marker}%';
SQL
)
assert_numeric 'POL/FB forwarding message ID' "$forward_id"
assert_db_equals \
    "A|4|E2E-Weiterleitungsziel|${authoritative_sender}|${pol_code}|POL|S2_rt,POL_gn||WG: ${incoming_subject}|1|1|1" \
    'persisted POL/FB forwarding' \
    "SELECT CONCAT(\`04_richtung\`, '|', \`x00_status\`, '|', \`10_anschrift\`, '|', \`13_abseinheit\`, '|', \`14_zeichen\`, '|', \`14_funktion\`, '|', \`16_empf\`, '|', \`11_rufnummer\`, '|', \`12_betreff\`, '|', LOCATE('Zitat: von E ${incoming_number}', \`12_inhalt\`) > 0, '|', LOCATE('${incoming_marker}', \`12_inhalt\`) > 0, '|', LOCATE('${forward_marker}', \`12_inhalt\`) > 0) FROM \`nv_nachrichten\` WHERE \`00_lfd\` = ${forward_id};"
assert_db_equals 1 'POL/FB source read state' \
    "SELECT COUNT(*) FROM \`usr_pol_${pol_code}_read\` WHERE \`nachnum\` = ${incoming_id};"
derived_source_after=$(
    message_admin_immutable_fingerprint "$incoming_marker"
)
if [ "$derived_source_after" != "$derived_source_before" ]; then
    echo 'Message workflow HTTP: answer or forwarding changed source message evidence' >&2
    exit 1
fi

finish_viewer_outgoing \
    "$reply_marker" "$reply_id" 'E2E answer formally approved'
finish_viewer_outgoing \
    "$forward_marker" "$forward_id" 'E2E forwarding formally approved'
finish_ldf_outgoing \
    "$reply_marker" "$reply_id" 'E2E-Antwort-Rufname' 'E2E-Antwortweg'
finish_ldf_outgoing \
    "$forward_marker" "$forward_id" 'E2E-Weiter-Rufname' 'E2E-Weiterweg'

# Leave the persistent administration-list mode through the actual rendered
# A/W navigation control so the following transport queue is the normal one.
load_sidebar "$aw_cookies" 'A/W navigation after second review'
assert_body 'name="fm_ausgang_x"' 'rendered A/W outgoing action'
admin_csrf=$(csrf_from_body)
assert_status 200 'return from A/W second review to outgoing queue' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode 'fm_ausgang_x=1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'A/W outgoing queue after second review'
assert_body_absent \
    "name=\"00_lfd\" value=\"$incoming_id\"" \
    'original incoming record in normal A/W outgoing queue'
assert_body "$reply_marker" 'POL/FB answer in normal A/W outgoing queue'
assert_body "$forward_marker" 'POL/FB forwarding in normal A/W outgoing queue'

# Outgoing message: S1 writes a draft for mandatory formal Si review. Si
# returns it once, the original author corrects it, Si approves it, LdF
# disposes the current S6 route and A/W confirms the actual transport.
load_dashboard "$s1_cookies" 'S1 dashboard before outgoing message'
outgoing_csrf=$(csrf_from_body)
assert_status 200 'open S1 outgoing form' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_csrf" \
    --data-urlencode 'stab_schreiben_x=1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'S1 outgoing form'
assert_body 'name="task" value="Stab_schreiben"' 'S1 outgoing form'
assert_body_absent \
    'data-estab-incident-suggestions=' \
    'staff outgoing form incident suggestions'
outgoing_csrf=$(csrf_from_body)
outgoing_attachment_request_token=$(
    message_attachment_request_token_from_body
)
tactical_time=$(date '+%H%M')
assert_status 200 'save S1 outgoing message' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_csrf" \
    --data-urlencode \
        "message_attachment_request_token=$outgoing_attachment_request_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=eee' \
    --data-urlencode '10_anschrift=E2E-Zielstelle' \
    --data-urlencode '11_rufnummer=' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_betreff=$outgoing_subject" \
    --data-urlencode "12_inhalt=$outgoing_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=E2E-Einsatzleitung' \
    --data-urlencode "14_zeichen=$s1_code" \
    --data-urlencode '14_funktion=S1' \
    --data-urlencode '17_vermerke=' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'saved S1 outgoing message'

outgoing_id=$(db_sql <<SQL
SELECT \`00_lfd\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${outgoing_marker}';
SQL
)
outgoing_number=$(db_sql <<SQL
SELECT \`04_nummer\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${outgoing_marker}';
SQL
)
assert_numeric 'outgoing message ID' "$outgoing_id"
assert_numeric 'outgoing evidence number' "$outgoing_number"
assert_db_equals "$authoritative_sender" \
    'new outgoing local sender came from active incident' \
    "SELECT \`13_abseinheit\` FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${outgoing_id};"
assert_message_state "$outgoing_marker" \
    'A|4|f|null||S2_rt,S1_gn|f||f' \
    'S1 outgoing status 4 awaiting formal Si review'
if ! generated_form_check absent A "$outgoing_number"; then
    echo 'Message workflow HTTP: outgoing form existed before completion' >&2
    exit 1
fi
outgoing_form_cleanup_owned=true

load_dashboard "$s1_cookies" 'S1 list at outgoing status 4'
assert_body "$outgoing_marker" 'S1 list at outgoing status 4'
assert_body 'alt="liegt vorm Sichter"' 'S1 status-4 Si indicator'
load_dashboard "$s2_cookies" 'S2 list at outgoing status 4'
assert_body_absent \
    "$outgoing_marker" \
    'status-4 outgoing hidden from foreign red-copy recipient'
s2_pending_csrf=$(csrf_from_body)
assert_status 403 'reject S2 outgoing detail at status 4' \
    --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s2_pending_csrf" \
    --data-urlencode 'stab=meldung' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_status 403 'reject S2 outgoing state at status 4' \
    --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s2_pending_csrf" \
    --data-urlencode 'action=gelesen' \
    --data-urlencode 'todo=set' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_db_equals 0 'status-4 outgoing created no S2 read state' \
    "SELECT COUNT(*) FROM \`usr_s2_${s2_code}_read\` WHERE \`nachnum\`=${outgoing_id};"
load_dashboard "$aw_cookies" 'A/W queue before LdF disposition'
assert_body_absent \
    "$outgoing_marker" \
    'status-4 outgoing hidden from A/W'
load_dashboard "$ldf_cookies" 'LdF queue before formal Si review'
assert_body_absent "$outgoing_marker" 'unreviewed outgoing hidden from LdF'

return_viewer_outgoing \
    "$outgoing_marker" "$outgoing_id" 'Anschrift fachlich präzisieren'
assert_message_state "$outgoing_marker" \
    "A|10|f|set|${si_code}|S2_rt,S1_gn|f||f" \
    'Si returned outgoing to original author'
load_dashboard "$ldf_cookies" 'LdF queue after Si return'
assert_body_absent "$outgoing_marker" 'returned outgoing hidden from LdF'
load_dashboard "$s2_cookies" 'foreign staff queue after Si return'
assert_body_absent \
    "$outgoing_marker" \
    'returned outgoing hidden from foreign red-copy recipient'
s2_csrf=$(csrf_from_body)
assert_status 403 'reject S2 outgoing detail at status 10' \
    --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s2_csrf" \
    --data-urlencode 'stab=meldung' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_status 403 'reject S2 outgoing state at status 10' \
    --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s2_csrf" \
    --data-urlencode 'action=gelesen' \
    --data-urlencode 'todo=set' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_db_equals 0 'status-10 outgoing created no S2 read state' \
    "SELECT COUNT(*) FROM \`usr_s2_${s2_code}_read\` WHERE \`nachnum\`=${outgoing_id};"
assert_status 403 'reject correction by another staff member' \
    --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s2_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_korrigieren' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"

load_dashboard "$s1_cookies" 'author queue for returned outgoing'
assert_body "$outgoing_marker" 'returned outgoing in author queue'
assert_route_control stab meldung "$outgoing_id" 'returned outgoing author detail'
s1_csrf=$(csrf_from_body)
assert_status 200 'open returned outgoing as original author' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s1_csrf" \
    --data-urlencode 'stab=meldung' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'returned outgoing correction form'
assert_body 'name="task" value="Stab_korrigieren"' \
    'returned outgoing correction task'
assert_body 'Anschrift fachlich präzisieren' \
    'returned outgoing reason visible to author'
assert_body_absent 'name="17_vermerke"' \
    'returned outgoing reason is not author-editable'
assert_body_absent 'name="11_gesprnotiz"' \
    'returned outgoing cannot submit a conversation-note state'
assert_body \
    'id="f_11_gesprnotiz" class="estab-official-box-choice" type="checkbox" disabled' \
    'returned outgoing conversation-note indicator is read-only'
s1_csrf=$(csrf_from_body)
correction_attachment_request_token=$(
    message_attachment_request_token_from_body
)
assert_status 403 'reject forged author correction note' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s1_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_korrigieren' \
    --data-urlencode "00_lfd=$outgoing_id" \
    --data-urlencode '17_vermerke=Browserseitig erfundener Korrekturvermerk' \
    "$base_url/4fach/mainindex.php"
assert_status 403 'reject conversation-note conversion during correction' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s1_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_korrigieren' \
    --data-urlencode "00_lfd=$outgoing_id" \
    --data-urlencode '11_gesprnotiz=on' \
    "$base_url/4fach/mainindex.php"
assert_message_state "$outgoing_marker" \
    "A|10|f|set|${si_code}|S2_rt,S1_gn|f||f" \
    'conversation-note overpost preserved returned outgoing status'
tactical_time=$(date '+%H%M')
assert_status 409 'reject forged attachment during correction resubmission' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s1_csrf" \
    --data-urlencode \
        "message_attachment_request_token=$correction_attachment_request_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_korrigieren' \
    --data-urlencode "00_lfd=$outgoing_id" \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=eee' \
    --data-urlencode '10_anschrift=E2E-Zielstelle korrigiert' \
    --data-urlencode '11_rufnummer=' \
    --data-urlencode '12_anhang=NICHTVORHANDEN.pdf;' \
    --data-urlencode "12_betreff=$outgoing_subject" \
    --data-urlencode "12_inhalt=$outgoing_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=Gefälschter Absender' \
    --data-urlencode "14_zeichen=$s1_code" \
    --data-urlencode '14_funktion=S1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'forged correction attachment rejection'
assert_body 'gehört nicht zum aktiven Einsatz' \
    'forged correction attachment error'
assert_message_state "$outgoing_marker" \
    "A|10|f|set|${si_code}|S2_rt,S1_gn|f||f" \
    'forged correction attachment preserved returned status'
assert_db_equals '' 'forged correction attachment did not mutate message' \
    "SELECT \`12_anhang\` FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${outgoing_id};"
s1_csrf=$(csrf_from_body)
correction_attachment_request_token=$(
    message_attachment_request_token_from_body
)
assert_status 200 'resubmit corrected outgoing as original author' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$s1_csrf" \
    --data-urlencode \
        "message_attachment_request_token=$correction_attachment_request_token" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_korrigieren' \
    --data-urlencode "00_lfd=$outgoing_id" \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=eee' \
    --data-urlencode '10_anschrift=E2E-Zielstelle korrigiert' \
    --data-urlencode '11_rufnummer=' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_betreff=$outgoing_subject" \
    --data-urlencode "12_inhalt=$outgoing_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=Gefälschter Absender' \
    --data-urlencode "14_zeichen=$s1_code" \
    --data-urlencode '14_funktion=S1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'resubmitted corrected outgoing'
assert_message_state "$outgoing_marker" \
    'A|4|f|null||S2_rt,S1_gn|f||f' \
    'corrected outgoing returned to formal Si queue'
assert_db_equals \
    "E2E-Zielstelle korrigiert|${authoritative_sender}|${s1_code}|S1" \
    'correction preserved authenticated author and local sender' \
    "SELECT CONCAT(\`10_anschrift\`, '|', \`13_abseinheit\`, '|', \`14_zeichen\`, '|', \`14_funktion\`) FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${outgoing_id};"
assert_db_equals \
    'Anschrift fachlich präzisieren' \
    'correction evidence retained the authoritative Sichter return reason' \
    "SELECT JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.correction_note')) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${outgoing_id} AND \`event_type\`='author_resubmitted';"

finish_viewer_outgoing \
    "$outgoing_marker" "$outgoing_id" 'Formal vollständig'
assert_message_state "$outgoing_marker" \
    "A|1|f|set|${si_code}|S2_rt,S1_gn|f||f" \
    'Si-approved outgoing status 1'

load_dashboard "$s1_cookies" 'S1 list at outgoing status 1'
assert_body "$outgoing_marker" 'S1 list at outgoing status 1'
assert_body \
    'alt="liegt bei LdF: Rufname und Beförderungsweg festlegen"' \
    'S1 status-1 LdF indicator'
load_dashboard "$ldf_cookies" 'LdF queue before tokenless lock test'
assert_body "$outgoing_marker" 'LdF status-1 outgoing queue'
assert_route_control ldf meldung "$outgoing_id" 'LdF outgoing detail control'
assert_status 403 'reject tokenless LdF outgoing lock request' \
    --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
    --request POST \
    --data-urlencode 'ldf=meldung' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_message_state "$outgoing_marker" \
    "A|1|f|set|${si_code}|S2_rt,S1_gn|f||f" \
    'tokenless LdF request left outgoing unlocked'

load_dashboard "$ldf_cookies" 'LdF queue before outgoing cancel'
ldf_cancel_csrf=$(csrf_from_body)
assert_status 200 'lock LdF outgoing message before cancel' \
    --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$ldf_cancel_csrf" \
    --data-urlencode 'ldf=meldung' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'LdF outgoing form before cancel'
assert_body 'name="task" value="LdF-Ausgang"' 'LdF outgoing cancel form'
ldf_cancel_csrf=$(csrf_from_body)
assert_status 200 'cancel LdF outgoing disposition' \
    --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$ldf_cancel_csrf" \
    --data-urlencode 'abbrechen_x=1' \
    --data-urlencode 'task=LdF-Ausgang' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'LdF outgoing queue after cancel'
assert_single_html_document 'LdF outgoing queue after cancel'
assert_body "$outgoing_marker" 'LdF queue after outgoing cancel'
assert_route_control \
    ldf meldung "$outgoing_id" 'LdF outgoing control after cancel'
assert_message_state "$outgoing_marker" \
    "A|1|f|set|${si_code}|S2_rt,S1_gn|f||f" \
    'LdF cancel released the outgoing stage-one lock'

# A route from a superseded plan must fail while the record is locked, without
# persisting browser-provided 06_* values or an invalid plan reference.
load_dashboard "$ldf_cookies" 'LdF queue before superseded-route test'
ldf_stale_csrf=$(csrf_from_body)
assert_status 200 'lock outgoing for superseded-route test' \
    --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$ldf_stale_csrf" \
    --data-urlencode 'ldf=meldung' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'LdF form before superseded-route test'
ldf_stale_csrf=$(csrf_from_body)
tactical_time=$(date '+%H%M')
assert_status 409 'reject superseded S6 route' \
    --cookie "$ldf_cookies" --cookie-jar "$ldf_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$ldf_stale_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=LdF-Ausgang' \
    --data-urlencode "00_lfd=$outgoing_id" \
    --data-urlencode "02_zeit=$tactical_time" \
    --data-urlencode "02_zeichen=$ldf_code" \
    --data-urlencode '05_gegenstelle=Nicht-persistieren' \
    --data-urlencode "fernmeldeplan_eintrag_id=$telecom_replaced_route_id" \
    --data-urlencode '06_befweg=Gefälschter Weg' \
    --data-urlencode '06_befwegausw=Me' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'superseded S6 route rejection'
assert_body \
    'gehört nicht zum gültigen S6-Fernmeldeplan' \
    'superseded S6 route error'
assert_message_state "$outgoing_marker" \
    "A|1|f|set|${si_code}|S2_rt,S1_gn|t|${ldf_code}|f" \
    'superseded route preserved LdF lock and workflow'
assert_db_equals '||0' 'superseded route persisted no disposition' \
    "SELECT CONCAT(COALESCE(\`05_gegenstelle\`, ''), '|', COALESCE(\`06_befweg\`, ''), '|', COALESCE(\`estab_fernmeldeplan_eintrag_id\`, 0)) FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${outgoing_id};"

finish_ldf_outgoing \
    "$outgoing_marker" "$outgoing_id" 'E2E-Gegenstelle' 'E2E-Transport'
load_dashboard "$s1_cookies" 'S1 list at outgoing status 2'
assert_body 'alt="liegt vorm Fernmelder"' 'S1 status-2 transport indicator'
assert_status 200 'open tracking before outgoing transport' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    "$base_url/4fach/nachwea.php?nwalle=1"
assert_no_runtime_error 'tracking before outgoing transport'
assert_body "$outgoing_marker" 'pending outgoing message in tracking'
assert_body 'Noch nicht befördert' 'pending outgoing transport state'
assert_body_absent \
    "Funk · ${telecom_route_text}" \
    'LdF decision exposed as an already completed transport'
load_dashboard "$aw_cookies" 'A/W transport queue at outgoing status 2'
assert_body "$outgoing_marker" 'A/W transport queue at outgoing status 2'
assert_route_control fm meldung "$outgoing_id" 'A/W outgoing detail control'

assert_status 403 'reject tokenless outgoing lock request' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode 'fm=meldung' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_message_state "$outgoing_marker" \
    "A|2|f|set|${si_code}|S2_rt,S1_gn|f||f" \
    'tokenless request left outgoing unlocked'

load_dashboard "$aw_cookies" 'A/W queue before locking outgoing'
outgoing_csrf=$(csrf_from_body)
outgoing_clock_before=$(app_tactical_clock)
assert_status 200 'open and lock outgoing transport form' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_csrf" \
    --data-urlencode 'fm=meldung' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
outgoing_clock_after=$(app_tactical_clock)
assert_no_runtime_error 'locked outgoing transport form'
assert_body 'name="task" value="FM-Ausgang"' 'locked outgoing transport form'
assert_body "name=\"00_lfd\" value=\"$outgoing_id\"" 'locked outgoing transport form'
assert_body \
    'id="f_03_zeichen" data-estab-readonly="true"' \
    'locked outgoing transport form uses authenticated A/W mark'
assert_body_absent \
    'name="03_zeichen"' \
    'A/W cannot rewrite its authenticated transport mark'
assert_body_absent \
    'id="f_05_gegenstelle" maxlength=' \
    'A/W cannot rewrite LdF callsign'
assert_body_absent \
    'id="f_06_befweg" maxlength=' \
    'A/W cannot rewrite LdF transport route'
assert_body 'data-estab-transport-confirmation="required"' \
    'A/W mandatory transport-route confirmation'
assert_body "Disponierter S6-Weg:</strong> Fu · ${telecom_route_text}" \
    'A/W displays the authoritative S6 route'
assert_body \
    'name="transportweg_bestaetigt" value="1" required' \
    'A/W transport-route confirmation control'
assert_body \
    'name="transport_rueckgabegrund"' \
    'A/W required-reason input for impossible transport'
assert_body \
    'name="transport_nicht_moeglich_x"' \
    'A/W return-to-LdF action for impossible transport'
assert_current_editable_tactical_time_input \
    f_03_datum "$outgoing_clock_before" "$outgoing_clock_after" \
    'A/W transport time'
assert_message_state "$outgoing_marker" \
    "A|2|f|set|${si_code}|S2_rt,S1_gn|t|${aw_code}|f" \
    'A/W-owned outgoing lock'

# A successful A/W cancel must release the stage-two lock and render exactly
# the outgoing queue. Re-lock the same record afterwards so the remaining
# negative/positive transport-save checks retain their original fixture.
outgoing_cancel_csrf=$(csrf_from_body)
assert_status 200 'cancel A/W outgoing transport' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_cancel_csrf" \
    --data-urlencode 'abbrechen_x=1' \
    --data-urlencode 'task=FM-Ausgang' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'A/W outgoing queue after cancel'
assert_single_html_document 'A/W outgoing queue after cancel'
assert_body "$outgoing_marker" 'A/W outgoing queue after cancel'
assert_route_control fm meldung "$outgoing_id" \
    'A/W outgoing control after cancel'
assert_message_state "$outgoing_marker" \
    "A|2|f|set|${si_code}|S2_rt,S1_gn|f||f" \
    'A/W cancel released the outgoing stage-two lock'

outgoing_csrf=$(csrf_from_body)
assert_status 200 're-lock outgoing transport after cancel' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_csrf" \
    --data-urlencode 'fm=meldung' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 're-locked outgoing transport after cancel'
assert_body 'name="task" value="FM-Ausgang"' \
    're-locked outgoing transport form after cancel'
assert_message_state "$outgoing_marker" \
    "A|2|f|set|${si_code}|S2_rt,S1_gn|t|${aw_code}|f" \
    'A/W re-acquired outgoing stage-two lock after cancel'

assert_status 403 'reject tokenless outgoing transport save' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=FM-Ausgang' \
    --data-urlencode "00_lfd=$outgoing_id" \
    --data-urlencode "03_datum=$tactical_time" \
    --data-urlencode "03_zeichen=$aw_code" \
    "$base_url/4fach/mainindex.php"
assert_message_state "$outgoing_marker" \
    "A|2|f|set|${si_code}|S2_rt,S1_gn|t|${aw_code}|f" \
    'tokenless transport save preserved lock and status'

# Re-open the owner-held lock idempotently. A valid CSRF token without the
# explicit route confirmation still must not complete the transport.
load_dashboard "$aw_cookies" 'A/W queue before transport save'
outgoing_csrf=$(csrf_from_body)
assert_status 200 're-open owner-held outgoing lock' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_csrf" \
    --data-urlencode 'fm=meldung' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
outgoing_csrf=$(csrf_from_body)
assert_status 409 'reject transport without S6 route confirmation' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=FM-Ausgang' \
    --data-urlencode "00_lfd=$outgoing_id" \
    --data-urlencode "03_datum=$tactical_time" \
    --data-urlencode "03_zeichen=$aw_code" \
    --data-urlencode '06_befweg=Gefälschter Weg' \
    --data-urlencode '06_befwegausw=Me' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'missing S6 route confirmation rejection'
assert_body \
    'Bestätigen Sie den disponierten S6-Beförderungsweg' \
    'missing S6 route confirmation error'
assert_message_state "$outgoing_marker" \
    "A|2|f|set|${si_code}|S2_rt,S1_gn|t|${aw_code}|f" \
    'missing route confirmation preserved transport lock'

# A/W may not silently invent another route. An impossible disposition goes
# back to LdF and requires an explicit, hash-linked reason.
outgoing_csrf=$(csrf_from_body)
assert_status 422 'reject transport return without mandatory reason' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_csrf" \
    --data-urlencode 'transport_nicht_moeglich_x=1' \
    --data-urlencode 'task=FM-Ausgang' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'missing transport-return reason rejection'
assert_body \
    'Für die Rückgabe an LdF ist ein Grund erforderlich' \
    'mandatory transport-return reason error'
assert_message_state "$outgoing_marker" \
    "A|2|f|set|${si_code}|S2_rt,S1_gn|t|${aw_code}|f" \
    'missing return reason preserved A/W ownership'

outgoing_csrf=$(csrf_from_body)
transport_return_reason='Disponierter Funkweg ist an der Gegenstelle ausgefallen'
assert_status 200 'return impossible transport to LdF with reason' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_csrf" \
    --data-urlencode 'transport_nicht_moeglich_x=1' \
    --data-urlencode 'task=FM-Ausgang' \
    --data-urlencode "00_lfd=$outgoing_id" \
    --data-urlencode "transport_rueckgabegrund=$transport_return_reason" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'A/W return to LdF'
assert_message_state "$outgoing_marker" \
    "A|1|f|set|${si_code}|S2_rt,S1_gn|f||f" \
    'A/W returned impossible transport to unlocked LdF stage'
assert_db_equals \
    "unset||${telecom_route_id}|Fu|${telecom_route_text}" \
    'A/W return retained rejected route while clearing LdF completion mark' \
    "SELECT CONCAT(IF(\`02_zeit\` IS NULL, 'unset', 'set'), '|', \`02_zeichen\`, '|', \`estab_fernmeldeplan_eintrag_id\`, '|', \`06_befwegausw\`, '|', \`06_befweg\`) FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${outgoing_id};"
assert_db_equals \
    "${transport_return_reason}|${telecom_route_id}|Fu|${telecom_route_text}" \
    'A/W return event preserves reason and rejected S6 decision' \
    "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_return_reason')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.rejected_telecom_plan_entry_id')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.rejected_transport_medium')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.rejected_transport_route'))) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${outgoing_id} AND \`event_type\`='aw_transport_returned';"

# S6 publishes a successor plan with another immutable route through the same
# production domain. The old route remains in message history, while only the
# new active entry may be chosen.
telecom_b_fixture=$(
    ESTAB_TEST_TELECOM_MODE=successor \
    ESTAB_TEST_TELECOM_S6_CODE=$s6_code \
    ESTAB_TEST_TELECOM_TOKEN=$identity_seed \
    "$compose_engine" compose run --rm --no-deps -T \
        --env "COMPOSE_PROJECT_NAME=$project_name" \
        --env ESTAB_TEST_TELECOM_ALLOW_MUTATION=true \
        --env ESTAB_TEST_TELECOM_MODE \
        --env ESTAB_TEST_TELECOM_S6_CODE \
        --env ESTAB_TEST_TELECOM_TOKEN \
        --volume "$repo_root:/workspace:ro" \
        --workdir /workspace \
        app php -d auto_prepend_file= \
        tests/integration/create_http_telecom_fixture.php |
        tail -n 1
)
telecom_route_b_id=$(printf '%s' "$telecom_b_fixture" | cut -d'|' -f1)
telecom_plan_b_version=$(printf '%s' "$telecom_b_fixture" | cut -d'|' -f2)
assert_numeric 'redisposition S6 route B fixture' "$telecom_route_b_id"
assert_numeric 'redisposition S6 plan B version' "$telecom_plan_b_version"

if printf '%s\n' \
    "UPDATE \`nv_nachrichten\` SET \`estab_fernmeldeplan_eintrag_id\`=${telecom_route_b_id}, \`06_befwegausw\`='Fu', \`06_befweg\`='${telecom_route_b_text}' WHERE \`00_lfd\`=${outgoing_id};" \
    | db_sql >/dev/null 2>&1
then
    echo 'Message workflow HTTP: route trigger accepted an unlocked direct replacement' >&2
    exit 1
fi
assert_db_equals "$telecom_route_id" \
    'route trigger retained route A outside locked LdF redisposition' \
    "SELECT \`estab_fernmeldeplan_eintrag_id\` FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${outgoing_id};"

finish_ldf_outgoing \
    "$outgoing_marker" "$outgoing_id" 'E2E-Gegenstelle' \
    'E2E-Redisposition' "$telecom_route_b_id" "$telecom_route_b_text"
assert_db_equals \
    "${telecom_route_id}|${telecom_route_b_id}|${telecom_route_text}|${telecom_route_b_text}" \
    'LdF redisposition event links rejected route A to active route B' \
    "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.previous_telecom_plan_entry_id')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.telecom_plan_entry_id')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.previous_transport_route')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_route'))) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${outgoing_id} AND \`event_type\`='ldf_dispatched' ORDER BY \`event_id\` DESC LIMIT 1;"

load_dashboard "$aw_cookies" 'A/W queue before confirmed transport save'
outgoing_csrf=$(csrf_from_body)
assert_status 200 'open A/W transport after LdF redisposition' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_csrf" \
    --data-urlencode 'fm=meldung' \
    --data-urlencode "00_lfd=$outgoing_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'A/W route-B confirmation form'
assert_body "Disponierter S6-Weg:</strong> Fu · ${telecom_route_b_text}" \
    'A/W sees only the newly disposed route B'
outgoing_csrf=$(csrf_from_body)
tactical_time=$(date '+%H%M')
outgoing_backdated_clock=$(app_backdated_clock)
outgoing_backdated_tactical=${outgoing_backdated_clock%%|*}
outgoing_backdated_sql=${outgoing_backdated_clock#*|}
assert_status 200 'save A/W outgoing transport' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=FM-Ausgang' \
    --data-urlencode "00_lfd=$outgoing_id" \
    --data-urlencode "03_datum=$outgoing_backdated_tactical" \
    --data-urlencode "03_zeichen=$aw_code" \
    --data-urlencode '05_gegenstelle=Manipulierte-Gegenstelle' \
    --data-urlencode '06_befweg=Manipulierter-Weg' \
    --data-urlencode '06_befwegausw=Me' \
    --data-urlencode 'transportweg_bestaetigt=1' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'saved A/W outgoing transport'
assert_db_equals "$outgoing_backdated_sql" 'edited A/W transport time' \
    "SELECT DATE_FORMAT(\`03_datum\`, '%Y-%m-%d %H:%i:00') FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${outgoing_id};"
assert_db_equals \
    "${aw_code}|E2E-Gegenstelle|${telecom_route_b_text}|Fu|${telecom_route_b_id}" \
    'A/W transport preserved LdF decision and authenticated code' \
    "SELECT CONCAT(\`03_zeichen\`, '|', \`05_gegenstelle\`, '|', \`06_befweg\`, '|', \`06_befwegausw\`, '|', \`estab_fernmeldeplan_eintrag_id\`) FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${outgoing_id};"
assert_db_equals \
    "true|${telecom_route_b_id}|Fu|${telecom_route_b_text}" \
    'A/W event proves the confirmed database-bound transport route' \
    "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_route_confirmed')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.telecom_plan_entry_id')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_medium')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_route'))) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${outgoing_id} AND \`event_type\`='aw_transported';"
assert_db_equals \
    "${telecom_plan_b_version}|${telecom_route_b_id}|Fu|${telecom_route_b_text}" \
    'final LdF event proves active S6 successor plan and route B' \
    "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.telecom_plan_version')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.telecom_plan_entry_id')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_medium')), '|', JSON_UNQUOTE(JSON_EXTRACT(\`field_snapshot\`, '$.transport_route'))) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${outgoing_id} AND \`event_type\`='ldf_dispatched' ORDER BY \`event_id\` DESC LIMIT 1;"
assert_db_equals 0 'forged transport values absent from event evidence' \
    "SELECT COUNT(*) FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${outgoing_id} AND (\`field_snapshot\` LIKE '%Manipulierter-Weg%' OR \`field_snapshot\` LIKE '%Gefälschter Weg%');"

outgoing_status=$(db_sql <<SQL
SELECT \`x00_status\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${outgoing_marker}';
SQL
)
if [ "$outgoing_status" != 8 ]; then
    echo 'Message workflow HTTP: confirmed outgoing transport did not close' >&2
    exit 1
fi
assert_message_state "$outgoing_marker" \
    "A|8|t|set|${si_code}|S2_rt,S1_gn|f||t" \
    'A/W-completed outgoing status 8'
assert_db_equals \
    'created,si_returned,author_resubmitted,si_approved,ldf_dispatched,aw_transport_returned,ldf_dispatched,aw_transported' \
    'outgoing mandatory DV transition event order' \
    "SELECT GROUP_CONCAT(\`event_type\` ORDER BY \`event_id\` SEPARATOR ',') FROM \`nv_nachrichten_ereignisse\` WHERE \`message_id\`=${outgoing_id};"
load_dashboard "$si_cookies" 'Si queue after outgoing completion'
assert_body_absent "$outgoing_marker" 'Si queue after outgoing completion'

if ! generated_form_check present A "$outgoing_number"; then
    echo 'Message workflow HTTP: outgoing completion generated no form' >&2
    exit 1
fi
load_dashboard "$aw_cookies" 'A/W queue after outgoing completion'
assert_body_absent "$outgoing_marker" 'A/W queue after outgoing completion'
load_dashboard "$s1_cookies" 'S1 list after outgoing completion'
assert_body "$outgoing_marker" 'S1 list after outgoing completion'
assert_body 'alt="Transport abgeschlossen!"' 'S1 completed-transport indicator'
load_dashboard "$s2_cookies" 'S2 list after outgoing completion'
assert_body "$outgoing_marker" 'S2 list after outgoing completion'
load_dashboard "$s3_cookies" 'S3 list after outgoing completion'
assert_body_absent "$outgoing_marker" 'S3 non-recipient outgoing list'

assert_status 200 'open combined transmission tracking' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    "$base_url/4fach/nachwea.php?nwalle=1"
assert_no_runtime_error 'combined transmission tracking'
assert_body 'Nachweisung Eingang / Ausgang' 'combined tracking view'
assert_body "Führungsstelle ${authoritative_sender} – Einsatz" \
    'combined tracking incident-bound command-post heading'
assert_body 'Übermittlungsweg' 'tracking transport-path column'
assert_body "Funk · ${telecom_route_b_text}" \
    'tracking actual outgoing transport path'

if [ -n "$account_restore_state_file" ]; then
    umask 077
    printf '%s\n%s\n%s\n' "$s1_name" "$s1_code" S1 \
        >"$account_restore_state_file"
    chmod 0600 "$account_restore_state_file"
fi

printf '%s\n' \
    'Message workflow HTTP integration: OK; incoming 1 -> 4 -> 8, outgoing 4 -> 10 -> 4 -> 1 -> 2 -> 1 -> 2 -> 8; LdF, S6 route A/B redisposition, Si return/correction, A/W confirmation, immutable archive, Nachweisung, POL/FB, answer and forwarding verified'
