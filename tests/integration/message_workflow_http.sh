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
workflow_variant=${ESTAB_TEST_WORKFLOW_VARIANT:-default}
review_expectation=${ESTAB_TEST_EXPECT_OUTGOING_REVIEW:-disabled}
compose_engine=${ESTAB_TEST_COMPOSE_ENGINE:-docker}

if ! printf '%s' "$workflow_marker" | grep -Eq '^[A-Za-z0-9_:-]{1,120}$'; then
    echo 'Message workflow HTTP: workflow marker is missing or unsafe' >&2
    exit 1
fi
if ! printf '%s' "$workflow_variant" | grep -Eq '^[a-z0-9_-]{1,20}$'; then
    echo 'Message workflow HTTP: workflow variant is unsafe' >&2
    exit 1
fi
case "$review_expectation" in
    enabled | disabled | auto) ;;
    *)
        echo 'Message workflow HTTP: outgoing-review expectation must be enabled, disabled, or auto' >&2
        exit 1
        ;;
esac
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
    "$project_name" "$workflow_marker" "$workflow_variant" |
    openssl dgst -sha256 -r | awk '{ print substr($1, 1, 5) }')
if ! printf '%s' "$identity_seed" | grep -Eq '^[a-f0-9]{5}$'; then
    echo 'Message workflow HTTP: could not derive isolated identities' >&2
    exit 1
fi

aw_code="w${identity_seed}"
si_code="i${identity_seed}"
s1_code="a${identity_seed}"
s2_code="l${identity_seed}"
s3_code="n${identity_seed}"
pol_code="p${identity_seed}"
aw_name="Workflow A-W ${identity_seed}"
si_name="Workflow Si ${identity_seed}"
s1_name="Workflow S1 ${identity_seed}"
s2_name="Workflow S2 ${identity_seed}"
s3_name="Workflow S3 ${identity_seed}"
pol_name="Workflow POL ${identity_seed}"
incoming_marker="E2EIN_${identity_seed}_${workflow_variant}"
outgoing_marker="E2EOUT_${identity_seed}_${workflow_variant}"
autosight_marker="E2EAUTO_${identity_seed}_${workflow_variant}"
reply_marker="E2EREPLY_${identity_seed}_${workflow_variant}"
forward_marker="E2EFORWARD_${identity_seed}_${workflow_variant}"
fm_admin_note="FMADMIN_${identity_seed}_${workflow_variant}"
si_admin_note="SIADMIN_${identity_seed}_${workflow_variant}"

for code in \
    "$aw_code" "$si_code" "$s1_code" "$s2_code" "$s3_code" "$pol_code"
do
    if ! printf '%s' "$code" | grep -Eq '^[a-z0-9_]{1,6}$'; then
        echo 'Message workflow HTTP: derived an unsafe user code' >&2
        exit 1
    fi
done
for marker in \
    "$incoming_marker" "$outgoing_marker" "$autosight_marker" \
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
si_cookies=$work_dir/si-cookies.txt
s1_cookies=$work_dir/s1-cookies.txt
s2_cookies=$work_dir/s2-cookies.txt
s3_cookies=$work_dir/s3-cookies.txt
pol_cookies=$work_dir/pol-cookies.txt

incoming_id=0
incoming_number=0
outgoing_id=0
outgoing_number=0
autosight_id=0
autosight_number=0
incoming_form_cleanup_owned=false
outgoing_form_cleanup_owned=false
autosight_form_cleanup_owned=false
matrix_auto_mutated=false
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

    for cookie_jar in \
        "$aw_cookies" "$si_cookies" "$s1_cookies" "$s2_cookies" \
        "$s3_cookies" "$pol_cookies"
    do
        purge_session_file "$cookie_jar" >/dev/null 2>&1 || cleanup_status=1
    done

    if [ "$incoming_form_cleanup_owned" = true ] &&
        [ "$incoming_number" -gt 0 ]; then
        generated_form_check remove E "$incoming_number" >/dev/null 2>&1 ||
            cleanup_status=1
    fi
    if [ "$outgoing_form_cleanup_owned" = true ] &&
        [ "$outgoing_number" -gt 0 ]; then
        generated_form_check remove A "$outgoing_number" >/dev/null 2>&1 ||
            cleanup_status=1
    fi
    if [ "$autosight_form_cleanup_owned" = true ] &&
        [ "$autosight_number" -gt 0 ]; then
        generated_form_check remove E "$autosight_number" >/dev/null 2>&1 ||
            cleanup_status=1
    fi

    if [ "$mutation_started" = true ]; then
        db_sql >/dev/null 2>&1 <<SQL || cleanup_status=1
START TRANSACTION;
DELETE FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` IN (
         '${incoming_marker}', '${outgoing_marker}', '${autosight_marker}'
       )
    OR \`12_inhalt\` LIKE '%${reply_marker}%'
    OR \`12_inhalt\` LIKE '%${forward_marker}%';
DELETE FROM \`nv_benutzer\`
 WHERE (\`kuerzel\` = '${aw_code}' AND \`benutzer\` = '${aw_name}' AND \`funktion\` = 'A/W')
    OR (\`kuerzel\` = '${si_code}' AND \`benutzer\` = '${si_name}' AND \`funktion\` = 'Si')
    OR (\`kuerzel\` = '${s1_code}' AND \`benutzer\` = '${s1_name}' AND \`funktion\` = 'S1')
    OR (\`kuerzel\` = '${s2_code}' AND \`benutzer\` = '${s2_name}' AND \`funktion\` = 'S2')
    OR (\`kuerzel\` = '${s3_code}' AND \`benutzer\` = '${s3_name}' AND \`funktion\` = 'S3')
    OR (\`kuerzel\` = '${pol_code}' AND \`benutzer\` = '${pol_name}' AND \`funktion\` = 'POL');
DELETE FROM \`nv_protokoll\` WHERE \`p_lfd\` > ${protocol_auto_increment} - 1;
COMMIT;
DROP TABLE IF EXISTS
  \`usr_s1_${s1_code}_read\`,
  \`usr_s1_${s1_code}_katego\`,
  \`usr_s1_${s1_code}_kategolink\`,
  \`usr_s2_${s2_code}_read\`,
  \`usr_s2_${s2_code}_katego\`,
  \`usr_s2_${s2_code}_kategolink\`,
  \`usr_s3_${s3_code}_read\`,
  \`usr_s3_${s3_code}_katego\`,
  \`usr_s3_${s3_code}_kategolink\`,
  \`usr_si_${si_code}_read\`,
  \`usr_si_${si_code}_katego\`,
  \`usr_si_${si_code}_kategolink\`,
  \`usr_pol_${pol_code}_read\`,
  \`usr_pol_${pol_code}_katego\`,
  \`usr_pol_${pol_code}_kategolink\`;
ALTER TABLE \`nv_nachrichten\` AUTO_INCREMENT = ${message_auto_increment};
ALTER TABLE \`nv_protokoll\` AUTO_INCREMENT = ${protocol_auto_increment};
SQL

        if [ "$matrix_auto_mutated" = true ]; then
            db_sql >/dev/null 2>&1 <<'SQL' || cleanup_status=1
UPDATE `nv_empfmtx`
   SET `mtx_auto` = 'f'
 WHERE `mtx_fkt` = 'POL' AND `mtx_rolle` = 'FB';
SQL
        fi

        drop_new_function_tables s1 "$s1_function_tables_before" >/dev/null 2>&1 ||
            cleanup_status=1
        drop_new_function_tables s2 "$s2_function_tables_before" >/dev/null 2>&1 ||
            cleanup_status=1
        drop_new_function_tables s3 "$s3_function_tables_before" >/dev/null 2>&1 ||
            cleanup_status=1
        drop_new_function_tables si "$si_function_tables_before" >/dev/null 2>&1 ||
            cleanup_status=1
        drop_new_function_tables pol "$pol_function_tables_before" >/dev/null 2>&1 ||
            cleanup_status=1

        cleanup_snapshot=$(db_sql 2>/dev/null <<SQL
SELECT CONCAT(
  (SELECT COUNT(*) FROM \`nv_nachrichten\`),
  '|',
  (SELECT COUNT(*) FROM \`nv_protokoll\`),
  '|',
  (SELECT COUNT(*) FROM \`nv_benutzer\`
    WHERE \`kuerzel\` IN (
      '${aw_code}', '${si_code}', '${s1_code}', '${s2_code}', '${s3_code}',
      '${pol_code}'
    )),
  '|',
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN (
        'usr_s1_${s1_code}_read', 'usr_s1_${s1_code}_katego', 'usr_s1_${s1_code}_kategolink',
        'usr_s2_${s2_code}_read', 'usr_s2_${s2_code}_katego', 'usr_s2_${s2_code}_kategolink',
        'usr_s3_${s3_code}_read', 'usr_s3_${s3_code}_katego', 'usr_s3_${s3_code}_kategolink',
        'usr_si_${si_code}_read', 'usr_si_${si_code}_katego', 'usr_si_${si_code}_kategolink',
        'usr_pol_${pol_code}_read', 'usr_pol_${pol_code}_katego', 'usr_pol_${pol_code}_kategolink'
      )),
  '|',
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('usr__fkt_s1_erl', 'usr__fkt_s1_katego', 'usr__fkt_s1_kategolink')),
  '|',
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('usr__fkt_s2_erl', 'usr__fkt_s2_katego', 'usr__fkt_s2_kategolink')),
  '|',
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('usr__fkt_s3_erl', 'usr__fkt_s3_katego', 'usr__fkt_s3_kategolink')),
  '|',
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('usr__fkt_si_erl', 'usr__fkt_si_katego', 'usr__fkt_si_kategolink')),
  '|',
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('usr__fkt_pol_erl', 'usr__fkt_pol_katego', 'usr__fkt_pol_kategolink')),
  '|',
  (SELECT COUNT(*) FROM \`nv_empfmtx\`
    WHERE \`mtx_fkt\` = 'POL'
      AND \`mtx_rolle\` = 'FB'
      AND \`mtx_auto\` IN ('t','1'))
);
SQL
        )
        expected_cleanup="${message_count_before}|${protocol_count_before}|0|0|${s1_function_tables_before}|${s2_function_tables_before}|${s3_function_tables_before}|${si_function_tables_before}|${pol_function_tables_before}|0"
        if [ "$cleanup_snapshot" != "$expected_cleanup" ]; then
            echo 'Message workflow HTTP: cleanup invariants failed' >&2
            printf 'expected: %s\nactual:   %s\n' \
                "$expected_cleanup" "$cleanup_snapshot" >&2
            cleanup_status=1
        fi
    fi

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
    load_dashboard "$cookie_jar" "dashboard for $function_name"
    assert_session_identity "$name" "$code" "$function_name" "$role"
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
    assert_body "name=\"15_quitzeichen\" value=\"$si_code\"" "Si review form for $marker"
    assert_body 'name="16_gncopy" type="radio"' "Si green-copy control"
    assert_body 'name="16_41" value="16_41_bl" type="checkbox"' "Si blue-copy control"
}

finish_viewer_message()
{
    marker=$1
    record_id=$2
    note=$3

    open_viewer_message "$marker" "$record_id"
    viewer_csrf=$(csrf_from_body)
    tactical_time=$(date '+%H%M')
    assert_status 200 "finish Si review for $marker" \
        --cookie "$si_cookies" --cookie-jar "$si_cookies" \
        --request POST \
        --data-urlencode "csrf_token=$viewer_csrf" \
        --data-urlencode 'absenden_x=1' \
        --data-urlencode 'task=Stab_sichten' \
        --data-urlencode "00_lfd=$record_id" \
        --data-urlencode "15_quitdatum=$tactical_time" \
        --data-urlencode "15_quitzeichen=$si_code" \
        --data-urlencode '16_gncopy=16_21_gn' \
        --data-urlencode '16_41=16_41_bl' \
        --data-urlencode "17_vermerke=$note" \
        "$base_url/4fach/mainindex.php"
    assert_no_runtime_error "finished Si review for $marker"
}

# Prove this is a collision-free fixture before the first mutation. Dynamic
# table names are part of the guard because a stale table must not be dropped.
fixture_collision=$(db_sql <<SQL
SELECT CONCAT(
  (SELECT COUNT(*) FROM \`nv_benutzer\`
    WHERE \`kuerzel\` IN (
      '${aw_code}', '${si_code}', '${s1_code}', '${s2_code}', '${s3_code}',
      '${pol_code}'
    )),
  '|',
  (SELECT COUNT(*) FROM \`nv_nachrichten\`
    WHERE \`12_inhalt\` IN (
      '${incoming_marker}', '${outgoing_marker}', '${autosight_marker}'
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

# Create six isolated functional accounts through the administrative domain
# boundary, then exercise only the production bestandskonto login flow.
# Keeping Si signed in makes both A/W forms use their genuine online-Si branch.
provision_and_login_user "$aw_cookies" "$aw_name" "$aw_code" A/W Fernmelder
provision_and_login_user "$s1_cookies" "$s1_name" "$s1_code" S1 Stab
provision_and_login_user "$s2_cookies" "$s2_name" "$s2_code" S2 Stab
provision_and_login_user "$s3_cookies" "$s3_name" "$s3_code" S3 Stab
provision_and_login_user "$pol_cookies" "$pol_name" "$pol_code" POL FB
provision_and_login_user "$si_cookies" "$si_name" "$si_code" Si Stab
assert_db_equals 1 'online Si fixture' \
    "SELECT COUNT(*) FROM \`nv_benutzer\` WHERE \`kuerzel\`='${si_code}' AND \`funktion\`='Si' AND \`aktiv\`=1;"
assert_db_equals 1 'isolated online Si fixture' \
    "SELECT COUNT(*) FROM \`nv_benutzer\` WHERE \`funktion\`='Si' AND \`aktiv\`=1;"

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

# Exercise automatic sighting, not only persistence of the matrix flag. POL is
# temporarily marked as an autosighting target and Si is made offline in the
# disposable fixture. The rendered FM-Eingang_Sichter form must derive the
# checked POL control; the test submits that exact rendered default.
matrix_auto_mutated=true
db_sql >/dev/null <<SQL
START TRANSACTION;
UPDATE \`nv_empfmtx\`
   SET \`mtx_auto\` = 't'
 WHERE \`mtx_fkt\` = 'POL' AND \`mtx_rolle\` = 'FB';
UPDATE \`nv_benutzer\`
   SET \`aktiv\` = 0
 WHERE \`kuerzel\` = '${si_code}' AND \`funktion\` = 'Si';
COMMIT;
SQL
assert_db_equals '1|0' 'autosighting fixture and offline Si' \
    "SELECT CONCAT((SELECT COUNT(*) FROM \`nv_empfmtx\` WHERE \`mtx_fkt\`='POL' AND \`mtx_auto\` IN ('t','1')), '|', (SELECT COUNT(*) FROM \`nv_benutzer\` WHERE \`funktion\`='Si' AND \`aktiv\`=1));"

load_dashboard "$aw_cookies" 'A/W dashboard before automatic sighting'
autosight_csrf=$(csrf_from_body)
autosight_clock_before=$(app_tactical_clock)
assert_status 200 'open automatic-sighting incoming form' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$autosight_csrf" \
    --data-urlencode 'fm_eingang_x=1' \
    "$base_url/4fach/mainindex.php"
autosight_clock_after=$(app_tactical_clock)
assert_no_runtime_error 'automatic-sighting incoming form'
assert_body \
    'name="task" value="FM-Eingang_Sichter"' \
    'automatic-sighting form task'
assert_current_editable_tactical_time_input \
    f_01_datum "$autosight_clock_before" "$autosight_clock_after" \
    'automatic-sighting receipt time'
auto_checkbox=$(sed -n \
    's/.*name="\(16_32\)" value="\(16_32_bl\)" type="checkbox"[^>]*checked="checked".*/\1=\2/p' \
    "$body" | head -n 1)
if [ "$auto_checkbox" != '16_32=16_32_bl' ]; then
    echo 'Message workflow HTTP: POL autosighting default was not derived from the rendered form' >&2
    exit 1
fi

autosight_csrf=$(csrf_from_body)
tactical_time=$(date '+%H%M')
assert_status 200 'save automatic-sighting incoming message' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$autosight_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=FM-Eingang_Sichter' \
    --data-urlencode '01_medium=Fu' \
    --data-urlencode "01_datum=$tactical_time" \
    --data-urlencode "01_zeichen=$aw_code" \
    --data-urlencode '05_gegenstelle=E2E-Auto-Gegenstelle' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=eee' \
    --data-urlencode '10_anschrift=E2E-Einsatzleitung' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_inhalt=$autosight_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=E2E-Auto-Absender' \
    --data-urlencode '14_zeichen=' \
    --data-urlencode '14_funktion=' \
    --data-urlencode "15_quitdatum=$tactical_time" \
    --data-urlencode "15_quitzeichen=$aw_code" \
    --data-urlencode "$auto_checkbox" \
    --data-urlencode '16_gncopy=' \
    --data-urlencode '17_vermerke=Automatisch ohne Si gesichtet' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'saved automatic-sighting incoming message'

autosight_id=$(db_sql <<SQL
SELECT \`00_lfd\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${autosight_marker}';
SQL
)
autosight_number=$(db_sql <<SQL
SELECT \`04_nummer\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${autosight_marker}';
SQL
)
assert_numeric 'automatic-sighting message ID' "$autosight_id"
assert_numeric 'automatic-sighting evidence number' "$autosight_number"
autosight_form_cleanup_owned=true
assert_message_state "$autosight_marker" \
    "E|8|t|set|${aw_code}|S2_rt,POL_bl,|f||t" \
    'automatic-sighting completed message'
if ! generated_form_check present E "$autosight_number"; then
    echo 'Message workflow HTTP: automatic sighting generated no form' >&2
    exit 1
fi
load_dashboard "$pol_cookies" 'POL/FB automatic-sighting recipient list'
assert_body "$autosight_marker" 'POL/FB automatic-sighting recipient list'
assert_route_control \
    stab meldung "$autosight_id" 'POL/FB automatic-sighting detail control'

db_sql >/dev/null <<SQL
START TRANSACTION;
UPDATE \`nv_empfmtx\`
   SET \`mtx_auto\` = 'f'
 WHERE \`mtx_fkt\` = 'POL' AND \`mtx_rolle\` = 'FB';
UPDATE \`nv_benutzer\`
   SET \`aktiv\` = 1
 WHERE \`kuerzel\` = '${si_code}' AND \`funktion\` = 'Si';
COMMIT;
SQL
assert_db_equals '0|1' 'restored matrix and online Si' \
    "SELECT CONCAT((SELECT COUNT(*) FROM \`nv_empfmtx\` WHERE \`mtx_fkt\`='POL' AND \`mtx_auto\` IN ('t','1')), '|', (SELECT COUNT(*) FROM \`nv_benutzer\` WHERE \`kuerzel\`='${si_code}' AND \`funktion\`='Si' AND \`aktiv\`=1));"
matrix_auto_mutated=false

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
assert_current_editable_tactical_time_input \
    f_01_datum "$incoming_clock_before" "$incoming_clock_after" \
    'A/W receipt time'
incoming_csrf=$(csrf_from_body)
tactical_time=$(date '+%H%M')
incoming_backdated_clock=$(app_backdated_clock)
incoming_backdated_tactical=${incoming_backdated_clock%%|*}
incoming_backdated_sql=${incoming_backdated_clock#*|}
assert_status 200 'save A/W incoming message' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$incoming_csrf" \
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
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_inhalt=$incoming_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=E2E-Absender' \
    --data-urlencode '14_zeichen=' \
    --data-urlencode '14_funktion=' \
    --data-urlencode '16_gncopy=' \
    --data-urlencode '17_vermerke=' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'saved A/W incoming message'

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
assert_message_state "$incoming_marker" \
    'E|4|f|null||S2_rt,|f||f' \
    'A/W incoming status 4'
if ! generated_form_check absent E "$incoming_number"; then
    echo 'Message workflow HTTP: incoming form existed before completion' >&2
    exit 1
fi
incoming_form_cleanup_owned=true

finish_viewer_message "$incoming_marker" "$incoming_id" 'E2E incoming reviewed'
assert_message_state "$incoming_marker" \
    "E|8|t|set|${si_code}|S2_rt,S1_gn,S3_bl,|f||t" \
    'Si-completed incoming status 8'
if ! generated_form_check present E "$incoming_number"; then
    echo 'Message workflow HTTP: incoming completion generated no form' >&2
    exit 1
fi

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

# A/W now enters the rendered second-review administration path for the
# completed message. The UI must expose only fields 15-17 as editable, and the
# real CSRF-protected save must not alter message or transport evidence.
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
incoming_quit_clock_before=$(db_sql <<SQL
SELECT DATE_FORMAT(\`15_quitdatum\`, '%H%i')
  FROM \`nv_nachrichten\`
 WHERE \`00_lfd\` = ${incoming_id};
SQL
)
if ! printf '%s' "$incoming_quit_timestamp_before" |
    grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$' ||
    ! printf '%s' "$incoming_quit_clock_before" |
        grep -Eq '^[0-9]{4}$'; then
    echo 'Message workflow HTTP: invalid completed incoming review timestamp' >&2
    exit 1
fi
assert_db_equals \
    "${incoming_quit_timestamp_before}|${si_code}|S2_rt,S1_gn,S3_bl,|E2E incoming reviewed" \
    'incoming review evidence before FM-Admin' \
    "SELECT CONCAT(DATE_FORMAT(\`15_quitdatum\`, '%Y-%m-%d %H:%i:%s'), '|', \`15_quitzeichen\`, '|', COALESCE(\`16_empf\`, ''), '|', COALESCE(\`17_vermerke\`, '')) FROM \`nv_nachrichten\` WHERE \`00_lfd\` = ${incoming_id};"

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
assert_body "$incoming_marker" 'A/W second-review list'
assert_route_control \
    fm FM-Adminmeldung "$incoming_id" 'A/W FM-Admin detail control'

admin_csrf=$(csrf_from_body)
assert_status 200 'open completed incoming FM-Admin form' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode 'fm=FM-Adminmeldung' \
    --data-urlencode "00_lfd=$incoming_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'completed incoming FM-Admin form'
assert_body 'name="task" value="FM-Admin"' 'FM-Admin form task'
assert_body "name=\"00_lfd\" value=\"$incoming_id\"" 'FM-Admin form message'
assert_body 'name="absenden"' 'FM-Admin save control'
assert_body 'name="abbrechen"' 'FM-Admin cancel control'
assert_body \
    'id="f_15_quitdatum" data-estab-readonly="true"' \
    'FM-Admin read-only acknowledgment time'
assert_body \
    'id="f_15_quitdatum_value" type="hidden" name="15_quitdatum"' \
    'FM-Admin acknowledgment time compatibility value'
assert_body_absent \
    'id="f_15_quitdatum" maxlength=' \
    'FM-Admin editable acknowledgment time'
assert_body 'id="f_15_quitzeichen" maxlength="6"' 'FM-Admin acknowledgment code'
assert_body 'name="16_gncopy" type="radio"' 'FM-Admin green-copy controls'
assert_body 'name="16_21" value="16_21_bl" type="checkbox"' \
    'FM-Admin blue-copy control'
assert_body '<textarea cols="40" rows="10" name="17_vermerke"' \
    'FM-Admin note control'
assert_body '<input id="f_12_inhalt" type="hidden"' \
    'FM-Admin immutable message content'
assert_body '<input id="f_10_anschrift" type="hidden"' \
    'FM-Admin immutable address'
assert_body '<input id="f_14_zeichen" type="hidden"' \
    'FM-Admin immutable author'
assert_body '<input id="f_01_medium" type="hidden"' \
    'FM-Admin immutable receive medium'
assert_body \
    'id="f_01_medium_fe" name="01_medium" value="Fe" type="radio"  disabled ' \
    'FM-Admin disabled receive-medium controls'
assert_body '<input id="f_06_befwegausw" type="hidden"' \
    'FM-Admin immutable transport selection'
assert_body \
    'id="f_06_befwegausw_fe" name="06_befwegausw" value="Fe" type="radio"  disabled ' \
    'FM-Admin disabled transport controls'
assert_body '<input id="f_07_durchspruch" type="hidden"' \
    'FM-Admin immutable message type'
assert_body \
    'id="f_07_durchspruch" name="07_durchspruch" value="D" type="radio"  disabled ' \
    'FM-Admin disabled message-type controls'
assert_body '<input id="f_08_befhinwausw" type="hidden"' \
    'FM-Admin immutable transport-hint selection'
assert_body \
    'id="f_08_befhinwausw_fe" name="08_befhinwausw" value="Fe" type="radio"  disabled ' \
    'FM-Admin disabled transport-hint controls'
assert_body '<input id="09_vorrangstufe" type="hidden"' \
    'FM-Admin immutable priority'
assert_body '<input id="f_11_gesprnotiz" type="hidden"' \
    'FM-Admin immutable conversation-note flag'
assert_body \
    'id="f_11_gesprnotiz" name="11_gesprnotiz" type="checkbox"  disabled ' \
    'FM-Admin disabled conversation-note control'
for forbidden_admin_control in \
    'id="f_01_datum" maxlength=' \
    'id="f_01_zeichen" maxlength=' \
    'id="f_02_zeit" maxlength=' \
    'id="f_02_zeichen" maxlength=' \
    'id="f_03_datum" maxlength=' \
    'id="f_03_zeichen" maxlength=' \
    'id="f_04_nummer" maxlength=' \
    'id="f_04_richtung" name=' \
    'id="f_05_gegenstelle" maxlength=' \
    'id="f_06_befweg" maxlength=' \
    'id="f_08_befhinweis" maxlength=' \
    '<select name="09_vorrangstufe"' \
    '<textarea id="f_10_anschrift"' \
    '<textarea id="f_12_inhalt"' \
    'id="f_12_abfzeit" maxlength=' \
    'id="f_13_abseinheit" style=' \
    'id="f_14_zeichen" maxlength=' \
    'id="f_14_funktion" maxlength='
do
    assert_body_absent "$forbidden_admin_control" \
        'FM-Admin fields 1-14 read-only contract'
done

case "$incoming_quit_clock_before" in
    2359) admin_submitted_quit_time=0001 ;;
    *) admin_submitted_quit_time=2359 ;;
esac
admin_csrf=$(csrf_from_body)
assert_status 200 'save completed incoming FM-Admin review' \
    --cookie "$aw_cookies" --cookie-jar "$aw_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=FM-Admin' \
    --data-urlencode "00_lfd=$incoming_id" \
    --data-urlencode "15_quitdatum=$admin_submitted_quit_time" \
    --data-urlencode "15_quitzeichen=$aw_code" \
    --data-urlencode '16_21=16_21_bl' \
    --data-urlencode '16_gncopy=16_41_gn' \
    --data-urlencode "17_vermerke=$fm_admin_note" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'saved completed incoming FM-Admin review'

incoming_admin_immutable_after=$(
    message_admin_immutable_fingerprint "$incoming_marker"
)
if [ "$incoming_admin_immutable_after" != \
    "$incoming_admin_immutable_before" ]; then
    echo 'Message workflow HTTP: FM-Admin changed immutable message evidence' >&2
    exit 1
fi
assert_message_state "$incoming_marker" \
    "E|8|t|set|${aw_code}|S2_rt,S1_bl,S3_gn,|f||t" \
    'FM-Admin preserved completed incoming state'
assert_db_equals \
    "${incoming_quit_timestamp_before}|${aw_code}|S2_rt,S1_bl,S3_gn,|${fm_admin_note}" \
    'FM-Admin changed only editable second-review evidence and rejected timestamp tampering' \
    "SELECT CONCAT(DATE_FORMAT(\`15_quitdatum\`, '%Y-%m-%d %H:%i:%s'), '|', \`15_quitzeichen\`, '|', COALESCE(\`16_empf\`, ''), '|', COALESCE(\`17_vermerke\`, '')) FROM \`nv_nachrichten\` WHERE \`00_lfd\` = ${incoming_id};"
if ! generated_form_check present E "$incoming_number"; then
    echo 'Message workflow HTTP: FM-Admin lost the completed incoming form' >&2
    exit 1
fi

# Si independently opens the corresponding second-review list and persists the
# same deliberately narrow field set. This is a separate routed workflow, not
# an inference from the analogous A/W form.
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
assert_body "$incoming_marker" 'Si second-review list'
assert_route_control \
    fm SI-Adminmeldung "$incoming_id" 'SI-Admin detail control'

si_admin_csrf=$(csrf_from_body)
assert_status 200 'open completed incoming SI-Admin form' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$si_admin_csrf" \
    --data-urlencode 'fm=SI-Adminmeldung' \
    --data-urlencode "00_lfd=$incoming_id" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'completed incoming SI-Admin form'
assert_body 'name="task" value="SI-Admin"' 'SI-Admin form task'
assert_body "name=\"00_lfd\" value=\"$incoming_id\"" 'SI-Admin form message'
assert_body 'name="absenden"' 'SI-Admin save control'
assert_body 'name="abbrechen"' 'SI-Admin cancel control'
assert_body \
    'id="f_15_quitdatum" data-estab-readonly="true"' \
    'SI-Admin read-only acknowledgment time'
assert_body \
    'id="f_15_quitdatum_value" type="hidden" name="15_quitdatum"' \
    'SI-Admin acknowledgment time compatibility value'
assert_body_absent \
    'id="f_15_quitdatum" maxlength=' \
    'SI-Admin editable acknowledgment time'
assert_body 'id="f_15_quitzeichen" maxlength="6"' \
    'SI-Admin acknowledgment code'
assert_body 'name="16_gncopy" type="radio"' \
    'SI-Admin green-copy controls'
assert_body 'name="16_32" value="16_32_bl" type="checkbox"' \
    'SI-Admin POL blue-copy control'
assert_body '<textarea cols="40" rows="10" name="17_vermerke"' \
    'SI-Admin note control'
assert_body '<input id="f_12_inhalt" type="hidden"' \
    'SI-Admin immutable message content'
assert_body '<input id="f_10_anschrift" type="hidden"' \
    'SI-Admin immutable address'
for forbidden_si_admin_control in \
    'id="f_01_datum" maxlength=' \
    'id="f_01_zeichen" maxlength=' \
    'id="f_02_zeit" maxlength=' \
    'id="f_02_zeichen" maxlength=' \
    'id="f_03_datum" maxlength=' \
    'id="f_03_zeichen" maxlength=' \
    'id="f_04_nummer" maxlength=' \
    'id="f_04_richtung" name=' \
    'id="f_05_gegenstelle" maxlength=' \
    'id="f_06_befweg" maxlength=' \
    '<textarea id="f_10_anschrift"' \
    '<textarea id="f_12_inhalt"' \
    'id="f_12_abfzeit" maxlength=' \
    'id="f_13_abseinheit" style=' \
    'id="f_14_zeichen" maxlength=' \
    'id="f_14_funktion" maxlength='
do
    assert_body_absent "$forbidden_si_admin_control" \
        'SI-Admin fields 1-14 read-only contract'
done

case "$incoming_quit_clock_before" in
    0001) si_admin_submitted_quit_time=2359 ;;
    *) si_admin_submitted_quit_time=0001 ;;
esac
si_admin_csrf=$(csrf_from_body)
assert_status 200 'save completed incoming SI-Admin review' \
    --cookie "$si_cookies" --cookie-jar "$si_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$si_admin_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=SI-Admin' \
    --data-urlencode "00_lfd=$incoming_id" \
    --data-urlencode "15_quitdatum=$si_admin_submitted_quit_time" \
    --data-urlencode "15_quitzeichen=$si_code" \
    --data-urlencode '16_32=16_32_bl' \
    --data-urlencode '16_gncopy=16_21_gn' \
    --data-urlencode "17_vermerke=$si_admin_note" \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'saved completed incoming SI-Admin review'

si_admin_immutable_after=$(
    message_admin_immutable_fingerprint "$incoming_marker"
)
if [ "$si_admin_immutable_after" != "$si_admin_immutable_before" ]; then
    echo 'Message workflow HTTP: SI-Admin changed immutable message evidence' >&2
    exit 1
fi
assert_message_state "$incoming_marker" \
    "E|8|t|set|${si_code}|S2_rt,S1_gn,POL_bl,|f||t" \
    'SI-Admin preserved completed incoming state'
assert_db_equals \
    "${incoming_quit_timestamp_before}|${si_code}|S2_rt,S1_gn,POL_bl,|${si_admin_note}" \
    'SI-Admin changed only editable second-review evidence and rejected timestamp tampering' \
    "SELECT CONCAT(DATE_FORMAT(\`15_quitdatum\`, '%Y-%m-%d %H:%i:%s'), '|', \`15_quitzeichen\`, '|', COALESCE(\`16_empf\`, ''), '|', COALESCE(\`17_vermerke\`, '')) FROM \`nv_nachrichten\` WHERE \`00_lfd\` = ${incoming_id};"
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
assert_body 'name="antwort"' 'POL/FB answer control'
assert_body 'name="weiterleiten"' 'POL/FB forward control'

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
    'name="13_abseinheit" value="E2E-Einsatzleitung"' \
    'derived answer sender'
assert_body \
    "name=\"14_zeichen\" value=\"$pol_code\"" \
    'derived answer author code'
assert_body \
    'name="14_funktion" value="POL"' \
    'derived answer author function'
assert_body "Zitat: von E $incoming_number" 'derived answer quote reference'
assert_body "$incoming_marker" 'derived answer quoted content'

reply_content=$(printf 'Zitat: von E %s\n"%s"\n%s' \
    "$incoming_number" "$incoming_marker" "$reply_marker")
pol_csrf=$(csrf_from_body)
assert_status 200 'save POL/FB answer' \
    --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$pol_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=eee' \
    --data-urlencode '10_anschrift=E2E-Absender' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_inhalt=$reply_content" \
    --data-urlencode '12_abfzeit=' \
    --data-urlencode '13_abseinheit=E2E-Einsatzleitung' \
    --data-urlencode "14_zeichen=$pol_code" \
    --data-urlencode '14_funktion=POL' \
    --data-urlencode '16_gncopy=' \
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
    "A|2|E2E-Absender|E2E-Einsatzleitung|${pol_code}|POL|S2_rt,POL_gn|1|1|1" \
    'persisted POL/FB answer' \
    "SELECT CONCAT(\`04_richtung\`, '|', \`x00_status\`, '|', \`10_anschrift\`, '|', \`13_abseinheit\`, '|', \`14_zeichen\`, '|', \`14_funktion\`, '|', \`16_empf\`, '|', LOCATE('Zitat: von E ${incoming_number}', \`12_inhalt\`) > 0, '|', LOCATE('${incoming_marker}', \`12_inhalt\`) > 0, '|', LOCATE('${reply_marker}', \`12_inhalt\`) > 0) FROM \`nv_nachrichten\` WHERE \`00_lfd\` = ${reply_id};"

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
assert_body "Zitat: von E $incoming_number" 'derived forwarding quote reference'
assert_body "$incoming_marker" 'derived forwarding quoted content'

forward_content=$(printf 'Zitat: von E %s\n"%s"\n%s' \
    "$incoming_number" "$incoming_marker" "$forward_marker")
pol_csrf=$(csrf_from_body)
assert_status 200 'save POL/FB forwarding' \
    --cookie "$pol_cookies" --cookie-jar "$pol_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$pol_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=eee' \
    --data-urlencode '10_anschrift=E2E-Weiterleitungsziel' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_inhalt=$forward_content" \
    --data-urlencode '12_abfzeit=' \
    --data-urlencode '13_abseinheit=E2E-Einsatzleitung' \
    --data-urlencode "14_zeichen=$pol_code" \
    --data-urlencode '14_funktion=POL' \
    --data-urlencode '16_gncopy=' \
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
    "A|2|E2E-Weiterleitungsziel|E2E-Einsatzleitung|${pol_code}|POL|S2_rt,POL_gn|1|1|1" \
    'persisted POL/FB forwarding' \
    "SELECT CONCAT(\`04_richtung\`, '|', \`x00_status\`, '|', \`10_anschrift\`, '|', \`13_abseinheit\`, '|', \`14_zeichen\`, '|', \`14_funktion\`, '|', \`16_empf\`, '|', LOCATE('Zitat: von E ${incoming_number}', \`12_inhalt\`) > 0, '|', LOCATE('${incoming_marker}', \`12_inhalt\`) > 0, '|', LOCATE('${forward_marker}', \`12_inhalt\`) > 0) FROM \`nv_nachrichten\` WHERE \`00_lfd\` = ${forward_id};"
assert_db_equals 1 'POL/FB source read state' \
    "SELECT COUNT(*) FROM \`usr_pol_${pol_code}_read\` WHERE \`nachnum\` = ${incoming_id};"
derived_source_after=$(
    message_admin_immutable_fingerprint "$incoming_marker"
)
if [ "$derived_source_after" != "$derived_source_before" ]; then
    echo 'Message workflow HTTP: answer or forwarding changed source message evidence' >&2
    exit 1
fi

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

# Outgoing message: S1 writes status 2, A/W sees it in the transport queue and
# acquires the record lock through the rendered detail control.
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
outgoing_csrf=$(csrf_from_body)
tactical_time=$(date '+%H%M')
assert_status 200 'save S1 outgoing message' \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$outgoing_csrf" \
    --data-urlencode 'absenden_x=1' \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '02_zeit=' \
    --data-urlencode '07_durchspruch=D' \
    --data-urlencode '08_befhinweis=' \
    --data-urlencode '08_befhinwausw=' \
    --data-urlencode '09_vorrangstufe=eee' \
    --data-urlencode '10_anschrift=E2E-Zielstelle' \
    --data-urlencode '11_gesprnotiz=f' \
    --data-urlencode '12_anhang=' \
    --data-urlencode "12_inhalt=$outgoing_marker" \
    --data-urlencode "12_abfzeit=$tactical_time" \
    --data-urlencode '13_abseinheit=E2E-Einsatzleitung' \
    --data-urlencode "14_zeichen=$s1_code" \
    --data-urlencode '14_funktion=S1' \
    --data-urlencode '16_gncopy=' \
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
assert_message_state "$outgoing_marker" \
    'A|2|f|null||S2_rt,S1_gn|f||f' \
    'S1 outgoing status 2'
if ! generated_form_check absent A "$outgoing_number"; then
    echo 'Message workflow HTTP: outgoing form existed before completion' >&2
    exit 1
fi
outgoing_form_cleanup_owned=true

load_dashboard "$s1_cookies" 'S1 list at outgoing status 2'
assert_body "$outgoing_marker" 'S1 list at outgoing status 2'
assert_body 'alt="liegt vorm Fernmelder"' 'S1 status-2 transport indicator'
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
    'A|2|f|null||S2_rt,S1_gn|f||f' \
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
assert_body "name=\"03_zeichen\" value=\"$aw_code\"" 'locked outgoing transport form'
assert_current_editable_tactical_time_input \
    f_03_datum "$outgoing_clock_before" "$outgoing_clock_after" \
    'A/W transport time'
assert_message_state "$outgoing_marker" \
    "A|2|f|null||S2_rt,S1_gn|t|${aw_code}|f" \
    'A/W-owned outgoing lock'

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
    "A|2|f|null||S2_rt,S1_gn|t|${aw_code}|f" \
    'tokenless transport save preserved lock and status'

# Re-open the owner-held lock idempotently to obtain the real form token, then
# execute the configured transport transition.
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
    --data-urlencode '05_gegenstelle=E2E-Gegenstelle' \
    --data-urlencode '06_befweg=E2E-Transport' \
    --data-urlencode '06_befwegausw=Fu' \
    "$base_url/4fach/mainindex.php"
assert_no_runtime_error 'saved A/W outgoing transport'
assert_db_equals "$outgoing_backdated_sql" 'edited A/W transport time' \
    "SELECT DATE_FORMAT(\`03_datum\`, '%Y-%m-%d %H:%i:00') FROM \`nv_nachrichten\` WHERE \`00_lfd\`=${outgoing_id};"

outgoing_status=$(db_sql <<SQL
SELECT \`x00_status\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${outgoing_marker}';
SQL
)
case "$outgoing_status" in
    4)
        if [ "$review_expectation" = disabled ]; then
            echo 'Message workflow HTTP: outgoing review was enabled unexpectedly' >&2
            exit 1
        fi
        assert_message_state "$outgoing_marker" \
            'A|4|f|null||S2_rt,S1_gn|f||f' \
            'A/W transport status 4 awaiting Si'
        load_dashboard "$aw_cookies" 'A/W queue after status-4 transport'
        assert_body_absent "$outgoing_marker" 'A/W queue after status-4 transport'
        finish_viewer_message "$outgoing_marker" "$outgoing_id" 'E2E outgoing reviewed'
        assert_message_state "$outgoing_marker" \
            "A|8|t|set|${si_code}|S2_rt,S1_gn,S3_bl,|f||t" \
            'Si-completed outgoing status 8'
        load_dashboard "$si_cookies" 'Si queue after outgoing completion'
        assert_body_absent "$outgoing_marker" 'Si queue after outgoing completion'
        outgoing_path='2 -> 4 -> 8'
        ;;
    8)
        if [ "$review_expectation" = enabled ]; then
            echo 'Message workflow HTTP: outgoing review was bypassed unexpectedly' >&2
            exit 1
        fi
        assert_message_state "$outgoing_marker" \
            'A|8|t|null||S2_rt,S1_gn|f||t' \
            'A/W-completed outgoing status 8'
        load_dashboard "$si_cookies" 'Si queue with outgoing review disabled'
        assert_body_absent "$outgoing_marker" 'Si queue with outgoing review disabled'
        outgoing_path='2 -> 8'
        ;;
    *)
        echo 'Message workflow HTTP: outgoing transport reached an invalid status' >&2
        exit 1
        ;;
esac

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
if [ "$outgoing_status" = 4 ]; then
    assert_body "$outgoing_marker" 'S3 blue-copy outgoing list'
else
    assert_body_absent "$outgoing_marker" 'S3 non-recipient outgoing list'
fi

printf '%s\n' \
    "Message workflow HTTP integration (${workflow_variant}): OK; incoming 4 -> 8, outgoing ${outgoing_path}; POL/FB, autosighting, SI-Admin, answer and forwarding verified"
