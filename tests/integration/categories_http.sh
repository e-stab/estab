#!/bin/sh
set -eu

# Category tests create real rows and a foreign-message fixture. They may run
# only inside the disposable Compose project created by integration/ci.sh.
if [ "${ESTAB_CATEGORY_HTTP_TEST_ALLOW_MUTATION:-false}" != "true" ]; then
    echo 'Category HTTP: mutation flag is required for a disposable stack' >&2
    exit 1
fi

project_name=${COMPOSE_PROJECT_NAME:-estab}
case "$project_name" in
    estab_ci | estab_ci_*) ;;
    *)
        echo 'Category HTTP: refusing mutation outside an estab_ci project' >&2
        exit 1
        ;;
esac

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

base_url=${ESTAB_TEST_BASE_URL:-http://127.0.0.1:8080}
base_url=${base_url%/}
workflow_marker=${ESTAB_TEST_WORKFLOW_MARKER:-}
if ! printf '%s' "$workflow_marker" | grep -Eq '^[A-Za-z0-9_:-]{1,120}$'; then
    echo 'Category HTTP: workflow marker is missing or unsafe' >&2
    exit 1
fi

s1_name=${ESTAB_TEST_LOGIN_NAME:-Container Integration}
s1_code=${ESTAB_TEST_LOGIN_CODE:-e2e001}
s1_function=${ESTAB_TEST_LOGIN_FUNCTION:-S1}
s1_password=${ESTAB_TEST_LOGIN_PASSWORD:-Integration-Test-Only-20260722}
if [ -n "${ESTAB_TEST_LOGIN_PASSWORD_FILE:-}" ]; then
    if [ ! -r "$ESTAB_TEST_LOGIN_PASSWORD_FILE" ]; then
        echo 'Category HTTP: login password file is unreadable' >&2
        exit 1
    fi
    s1_password=$(tr -d '\r\n' <"$ESTAB_TEST_LOGIN_PASSWORD_FILE")
fi
s2_name=${ESTAB_TEST_ETB_NAME:-Logbook Integration S2}
s2_code=${ESTAB_TEST_ETB_CODE:-e2s200}
s2_password=${ESTAB_TEST_ETB_PASSWORD:-Logbook-Test-S2-20260723}
si_name=${ESTAB_TEST_CATEGORY_SI_NAME:-Category Integration Si}
si_code=${ESTAB_TEST_CATEGORY_SI_CODE:-e2si00}
si_password=${ESTAB_TEST_CATEGORY_SI_PASSWORD:-Category-Test-Si-20260723}

case "$s1_code" in
    '' | *[!a-z0-9_]*) echo 'Category HTTP: unsafe S1 code' >&2; exit 1 ;;
esac
case "$s1_function" in
    '' | *[!A-Za-z0-9_]*) echo 'Category HTTP: unsafe S1 function' >&2; exit 1 ;;
esac
if [ "${#s1_code}" -gt 6 ] || [ "${#s1_function}" -gt 10 ]; then
    echo 'Category HTTP: S1 identity exceeds table-name limits' >&2
    exit 1
fi
case "$si_code" in
    '' | *[!a-z0-9_]*) echo 'Category HTTP: unsafe Si code' >&2; exit 1 ;;
esac
if [ "${#si_code}" -gt 6 ]; then
    echo 'Category HTTP: Si code exceeds table-name limits' >&2
    exit 1
fi
s1_function_lower=$(printf '%s' "$s1_function" | tr '[:upper:]' '[:lower:]')
function_category_table="usr__fkt_${s1_function_lower}_katego"
function_link_table="usr__fkt_${s1_function_lower}_kategolink"
user_category_table="usr_${s1_function_lower}_${s1_code}_katego"
user_link_table="usr_${s1_function_lower}_${s1_code}_kategolink"
user_read_table="usr_${s1_function_lower}_${s1_code}_read"
function_done_table="usr__fkt_${s1_function_lower}_erl"

compose_engine=${ESTAB_TEST_COMPOSE_ENGINE:-docker}
case "$compose_engine" in
    docker | podman) ;;
    *) echo 'Category HTTP: compose engine must be docker or podman' >&2; exit 1 ;;
esac
"$compose_engine" compose version >/dev/null

work_dir=$(mktemp -d /tmp/estab-category-http.XXXXXX)
body=$work_dir/body.html
headers=$work_dir/headers.txt
s1_cookies=$work_dir/s1-cookies.txt
s2_cookies=$work_dir/s2-cookies.txt
si_cookies=$work_dir/si-cookies.txt
foreign_marker="ESTAB_CATEGORY_FOREIGN_$$"
message_id=0
foreign_message_id=0
message_auto_increment=1
master_auto_increment=1
function_auto_increment=1
user_auto_increment=1
message_state_captured=false
category_state_captured=false
workflow_state_captured=false
workflow_read_timestamp=
workflow_done_timestamp=
si_users_before=0

db_sql()
{
    "$compose_engine" compose exec -T db sh -ceu '
        umask 077
        client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-category-client.XXXXXX")
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

cleanup()
{
    status=$?
    trap - EXIT HUP INT TERM
    set +e
    db_sql >/dev/null 2>&1 <<SQL
START TRANSACTION;
DELETE link_row
  FROM \`${function_link_table}\` AS link_row
  JOIN \`${function_category_table}\` AS category_row
    ON category_row.\`lfd\` = link_row.\`katego\`
 WHERE HEX(category_row.\`kategorie\`) IN
       ('3C7363726970743E', '7827204F522031');
DELETE FROM \`${function_category_table}\`
 WHERE HEX(\`kategorie\`) IN ('3C7363726970743E', '7827204F522031');
DELETE link_row
  FROM \`${user_link_table}\` AS link_row
  JOIN \`${user_category_table}\` AS category_row
    ON category_row.\`lfd\` = link_row.\`katego\`
 WHERE HEX(category_row.\`kategorie\`) = '5553522651';
DELETE FROM \`${user_category_table}\`
 WHERE HEX(\`kategorie\`) = '5553522651';
DELETE link_row
  FROM \`nv_masterkategolink\` AS link_row
  JOIN \`nv_masterkatego\` AS category_row
    ON category_row.\`lfd\` = link_row.\`katego\`
 WHERE HEX(category_row.\`kategorie\`) IN ('4D5354522651', '53494D4153544552');
DELETE FROM \`nv_masterkatego\`
 WHERE HEX(\`kategorie\`) IN ('4D5354522651', '53494D4153544552');
DELETE FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${foreign_marker}';
DELETE FROM \`${user_read_table}\`
 WHERE \`nachnum\` = ${foreign_message_id};
DELETE FROM \`${function_done_table}\`
 WHERE \`nachnum\` = ${foreign_message_id};
COMMIT;
DROP TABLE IF EXISTS
  \`usr_si_${si_code}_read\`,
  \`usr_si_${si_code}_katego\`,
  \`usr_si_${si_code}_kategolink\`;
SQL
    if [ "$message_state_captured" = true ]; then
        db_sql >/dev/null 2>&1 <<SQL
ALTER TABLE \`nv_nachrichten\` AUTO_INCREMENT = ${message_auto_increment};
SQL
    fi
    if [ "$category_state_captured" = true ]; then
        db_sql >/dev/null 2>&1 <<SQL
ALTER TABLE \`nv_masterkatego\` AUTO_INCREMENT = ${master_auto_increment};
ALTER TABLE \`${function_category_table}\` AUTO_INCREMENT = ${function_auto_increment};
ALTER TABLE \`${user_category_table}\` AUTO_INCREMENT = ${user_auto_increment};
SQL
    fi
    if [ "$workflow_state_captured" = true ]; then
        db_sql >/dev/null 2>&1 <<SQL
DELETE FROM \`${user_read_table}\` WHERE \`nachnum\` = ${message_id};
DELETE FROM \`${function_done_table}\` WHERE \`nachnum\` = ${message_id};
SQL
        if [ -n "$workflow_read_timestamp" ]; then
            db_sql >/dev/null 2>&1 <<SQL
INSERT INTO \`${user_read_table}\` (\`nachnum\`, \`gelesen\`)
VALUES (${message_id}, '${workflow_read_timestamp}');
SQL
        fi
        if [ -n "$workflow_done_timestamp" ]; then
            db_sql >/dev/null 2>&1 <<SQL
INSERT INTO \`${function_done_table}\` (\`nachnum\`, \`erledigt\`)
VALUES (${message_id}, '${workflow_done_timestamp}');
SQL
        fi
    fi
    if [ "$si_users_before" = 0 ]; then
        db_sql >/dev/null 2>&1 <<SQL
DELETE FROM \`nv_benutzer\` WHERE \`kuerzel\` = '${si_code}';
SQL
        db_sql >/dev/null 2>&1 <<'SQL'
DROP TABLE IF EXISTS
  `usr__fkt_si_erl`,
  `usr__fkt_si_katego`,
  `usr__fkt_si_kategolink`;
SQL
    fi
    rm -rf -- "$work_dir"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' HUP INT TERM

request_status()
{
    curl --silent --show-error --max-time 20 --connect-timeout 5 \
        --dump-header "$headers" --output "$body" --write-out '%{http_code}' "$@"
}

assert_status()
{
    expected=$1
    shift
    actual=$(request_status "$@")
    if [ "$actual" != "$expected" ]; then
        printf 'Category HTTP: expected %s, got %s for %s\n' \
            "$expected" "$actual" "$*" >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
}

assert_body()
{
    expected=$1
    if ! grep -Fq -- "$expected" "$body"; then
        printf 'Category HTTP: response does not contain %s\n' "$expected" >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
}

assert_body_absent()
{
    forbidden=$1
    if grep -Fq -- "$forbidden" "$body"; then
        printf 'Category HTTP: response contains unsafe %s\n' "$forbidden" >&2
        sed -n '1,120p' "$body" >&2
        exit 1
    fi
}

assert_standard_master_categories_visible()
{
    category_rows=$(grep -o 'data-label="Kategorie"><strong>' "$body" |
        wc -l | tr -d ' ')
    if [ "$category_rows" != 7 ]; then
        printf 'Category HTTP: expected seven initial global categories, got %s\n' \
            "$category_rows" >&2
        sed -n '1,160p' "$body" >&2
        exit 1
    fi
    for standard_category in Allgemein EA1 EA2 EA3 EA4 EA5 EA6; do
        assert_body ">$standard_category</strong>"
    done
}

assert_session_identity()
{
    expected_name=$1
    expected_code=$2
    expected_function=$3
    expected_role=$4
    bar_count=$(grep -o 'data-estab-session-bar' "$body" | wc -l | tr -d ' ')
    if [ "$bar_count" != 1 ]; then
        printf 'Category HTTP: expected one session bar, got %s\n' "$bar_count" >&2
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
        assert_body "$marker"
    done
}

csrf_from_body()
{
    token=$(sed -n \
        's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$token" | grep -Eq '^[a-f0-9]{64}$'; then
        echo 'Category HTTP: CSRF token missing' >&2
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
            printf 'Category HTTP: %s is not a positive ID: %s\n' "$label" "$value" >&2
            exit 1
            ;;
    esac
}

assert_db_equals()
{
    expected=$1
    query=$2
    actual=$(printf '%s\n' "$query" | db_sql)
    if [ "$actual" != "$expected" ]; then
        printf 'Category HTTP: DB assertion expected %s, got %s\nSQL: %s\n' \
            "$expected" "$actual" "$query" >&2
        exit 1
    fi
}

hex_text()
{
    printf '%s' "$1" | od -An -tx1 | tr -d ' \n' | tr '[:lower:]' '[:upper:]'
}

login_existing()
{
    cookie_jar=$1
    name=$2
    code=$3
    function_name=$4
    password=$5

    : >"$cookie_jar"
    assert_status 200 \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST --data-urlencode 'login_flow=existing' \
        "$base_url/4fach/mainindex.php"
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
}

load_manager()
{
    manager_cookie_jar=$1
    manager_type=$2
    manager_message_id=$3
    assert_status 200 \
        --cookie "$manager_cookie_jar" --cookie-jar "$manager_cookie_jar" \
        "$base_url/4fach/katgoedt.php?dbtyp=$manager_type&msgno=$manager_message_id"
}

load_message_detail()
{
    detail_cookie_jar=$1
    detail_message_id=$2
    assert_status 200 \
        --cookie "$detail_cookie_jar" --cookie-jar "$detail_cookie_jar" \
        "$base_url/4fach/mainindex.php"
    detail_csrf=$(csrf_from_body)
    assert_status 200 \
        --cookie "$detail_cookie_jar" --cookie-jar "$detail_cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$detail_csrf" \
        --data-urlencode 'stab=meldung' \
        --data-urlencode "00_lfd=$detail_message_id" \
        "$base_url/4fach/mainindex.php"
}

load_message_list()
{
    cookie_jar=$1
    assert_status 200 \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        "$base_url/4fach/mainindex.php"
}

post_message_state()
{
    cookie_jar=$1
    action=$2
    todo=$3
    record_id=$4
    expected_status=$5
    state_csrf=$(csrf_from_body)
    assert_status "$expected_status" \
        --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$state_csrf" \
        --data-urlencode "action=$action" \
        --data-urlencode "00_lfd=$record_id" \
        --data-urlencode "todo=$todo" \
        "$base_url/4fach/mainindex.php"
}

assert_workflow_state_control()
{
    action=$1
    todo=$2
    assert_body "$workflow_marker"
    state_control="name=\"action\" value=\"$action\"><input type=\"hidden\" name=\"00_lfd\" value=\"$message_id\"><input type=\"hidden\" name=\"todo\" value=\"$todo\""
    if ! grep -Fq -- "$state_control" "$body"; then
        printf 'Category HTTP: workflow message lacks %s/%s control\n' \
            "$action" "$todo" >&2
        sed -n '1,160p' "$body" >&2
        exit 1
    fi
}

assert_workflow_state_controls_absent()
{
    for action in gelesen erledigt; do
        state_control="name=\"action\" value=\"$action\"><input type=\"hidden\" name=\"00_lfd\" value=\"$message_id\">"
        if grep -Fq -- "$state_control" "$body"; then
            printf 'Category HTTP: hidden workflow message still has %s control\n' \
                "$action" >&2
            sed -n '1,160p' "$body" >&2
            exit 1
        fi
    done
}

create_category()
{
    category_cookie_jar=$1
    category_type=$2
    category_message_id=$3
    category_name=$4
    category_description=$5
    load_manager \
        "$category_cookie_jar" "$category_type" "$category_message_id"
    category_csrf_token=$(csrf_from_body)
    assert_status 303 \
        --cookie "$category_cookie_jar" --cookie-jar "$category_cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$category_csrf_token" \
        --data-urlencode 'category_action=create' \
        --data-urlencode "dbtyp=$category_type" \
        --data-urlencode "msgno=$category_message_id" \
        --data-urlencode "kategorie=$category_name" \
        --data-urlencode "beschreibung=$category_description" \
        "$base_url/4fach/katgoedt.php"
}

delete_category()
{
    delete_cookie_jar=$1
    delete_type=$2
    delete_message_id=$3
    delete_category_id=$4
    load_manager "$delete_cookie_jar" "$delete_type" "$delete_message_id"
    delete_csrf_token=$(csrf_from_body)
    assert_status 303 \
        --cookie "$delete_cookie_jar" --cookie-jar "$delete_cookie_jar" \
        --request POST \
        --data-urlencode "csrf_token=$delete_csrf_token" \
        --data-urlencode 'category_action=delete' \
        --data-urlencode "dbtyp=$delete_type" \
        --data-urlencode "msgno=$delete_message_id" \
        --data-urlencode "category_id=$delete_category_id" \
        "$base_url/4fach/katgoedt.php"
}

message_id=$(db_sql <<SQL
SELECT \`00_lfd\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${workflow_marker}'
 ORDER BY \`00_lfd\` DESC LIMIT 1;
SQL
)
assert_numeric 'workflow message' "$message_id"

# The main workflow marker and the conversation note are both still waiting for
# mandatory review. S2 therefore uses the independent terminal PDF/archive
# fixture, whose red copy exercises the real message-object boundary without
# fabricating a completed conversation workflow in this category-only test.
redcopy_message_id=$(db_sql <<SQL
SELECT \`00_lfd\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${workflow_marker}_PDF_ARCHIVE'
   AND \`x00_status\` = 8
   AND FIND_IN_SET('S2_rt', \`16_empf\`) > 0
 ORDER BY \`00_lfd\` DESC LIMIT 1;
SQL
)
assert_numeric 'terminal red-copy message' "$redcopy_message_id"

message_auto_increment=$(db_sql <<'SQL'
SELECT COALESCE(`AUTO_INCREMENT`, 1)
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name = 'nv_nachrichten';
SQL
)
assert_numeric 'message auto increment' "$message_auto_increment"
message_state_captured=true

foreign_message_id=$(db_sql <<SQL
INSERT INTO \`nv_nachrichten\`
  (\`04_richtung\`, \`12_inhalt\`, \`14_funktion\`, \`16_empf\`)
VALUES ('E', '${foreign_marker}', 'S2', 'S2_rt,');
SELECT LAST_INSERT_ID();
SQL
)
assert_numeric 'foreign message' "$foreign_message_id"

# Session and scope gates.
assert_status 303 "$base_url/4fach/katgoedt.php?dbtyp=fkt&msgno=$message_id"
if ! grep -Eiq \
    '^Location: .*/4fach/mainindex[.]php[?]login_flow=existing&next=messages[[:space:]]*$' \
    "$headers"; then
    printf 'Category HTTP: anonymous category login redirect is invalid\n' >&2
    sed -n '1,30p' "$headers" >&2
    exit 1
fi

si_users_before=$(db_sql <<'SQL'
SELECT COUNT(*) FROM `nv_benutzer` WHERE `funktion` = 'Si';
SQL
)
case "$si_users_before" in
    '' | *[!0-9]*) echo 'Category HTTP: invalid Si user count' >&2; exit 1 ;;
esac
sh tests/integration/provision_user.sh \
    "$s1_name" "$s1_code" "$s1_function" "$s1_password"
sh tests/integration/provision_user.sh \
    "$s2_name" "$s2_code" S2 "$s2_password"
sh tests/integration/provision_user.sh \
    "$si_name" "$si_code" Si "$si_password"
login_existing "$s1_cookies" "$s1_name" "$s1_code" "$s1_function" "$s1_password"
login_existing "$s2_cookies" "$s2_name" "$s2_code" S2 "$s2_password"
login_existing "$si_cookies" "$si_name" "$si_code" Si "$si_password"

# In the explicitly LOOSE central incident, categories and message-state
# controls derive their permissions only from primary and explicit personal
# extra functions. The active incident remains mandatory; an optional access
# shift or formal duty assignment is not an input gate.

master_auto_increment=$(db_sql <<'SQL'
SELECT COALESCE(`AUTO_INCREMENT`, 1)
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name = 'nv_masterkatego';
SQL
)
function_auto_increment=$(db_sql <<SQL
SELECT COALESCE(\`AUTO_INCREMENT\`, 1)
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name = '${function_category_table}';
SQL
)
user_auto_increment=$(db_sql <<SQL
SELECT COALESCE(\`AUTO_INCREMENT\`, 1)
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name = '${user_category_table}';
SQL
)
assert_numeric 'master category auto increment' "$master_auto_increment"
assert_numeric 'function category auto increment' "$function_auto_increment"
assert_numeric 'user category auto increment' "$user_auto_increment"
category_state_captured=true

# Exercise the real read/done controls before category mutations change the
# list filter. This covers rendered form fields, CSRF, the object gate,
# session-derived state tables, idempotence and the visible done filter.
# Stab_schreiben marks its new outgoing message as read for the author.
assert_db_equals 1 \
    "SELECT COUNT(*) FROM \`${user_read_table}\` WHERE \`nachnum\`=${message_id} AND \`gelesen\` IS NOT NULL;"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${function_done_table}\` WHERE \`nachnum\`=${message_id};"
workflow_read_timestamp=$(db_sql <<SQL
SELECT COALESCE(MAX(DATE_FORMAT(\`gelesen\`, '%Y-%m-%d %H:%i:%s')), '')
  FROM \`${user_read_table}\`
 WHERE \`nachnum\` = ${message_id};
SQL
)
workflow_done_timestamp=$(db_sql <<SQL
SELECT COALESCE(MAX(DATE_FORMAT(\`erledigt\`, '%Y-%m-%d %H:%i:%s')), '')
  FROM \`${function_done_table}\`
 WHERE \`nachnum\` = ${message_id};
SQL
)
for workflow_timestamp in "$workflow_read_timestamp" "$workflow_done_timestamp"; do
    if [ -n "$workflow_timestamp" ] &&
        ! printf '%s' "$workflow_timestamp" |
            grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'; then
        echo 'Category HTTP: invalid captured workflow timestamp' >&2
        exit 1
    fi
done
workflow_state_captured=true
load_message_list "$s1_cookies"
assert_body "$workflow_marker"
assert_workflow_state_control gelesen unset
assert_workflow_state_control erledigt set

assert_status 403 \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode 'action=gelesen' \
    --data-urlencode "00_lfd=$message_id" \
    --data-urlencode 'todo=unset' \
    "$base_url/4fach/mainindex.php"
assert_db_equals 1 \
    "SELECT COUNT(*) FROM \`${user_read_table}\` WHERE \`nachnum\`=${message_id};"

load_message_list "$s1_cookies"
post_message_state "$s1_cookies" gelesen unset "$message_id" 200
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${user_read_table}\` WHERE \`nachnum\`=${message_id};"
assert_workflow_state_control gelesen set
post_message_state "$s1_cookies" gelesen unset "$message_id" 200
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${user_read_table}\` WHERE \`nachnum\`=${message_id};"

post_message_state "$s1_cookies" gelesen set "$message_id" 200
assert_db_equals 1 \
    "SELECT COUNT(*) FROM \`${user_read_table}\` WHERE \`nachnum\`=${message_id} AND \`gelesen\` IS NOT NULL;"
assert_workflow_state_control gelesen unset
post_message_state "$s1_cookies" gelesen set "$message_id" 200
assert_db_equals 1 \
    "SELECT COUNT(*) FROM \`${user_read_table}\` WHERE \`nachnum\`=${message_id};"

load_message_list "$s1_cookies"
post_message_state "$s1_cookies" gelesen set "$foreign_message_id" 403
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${user_read_table}\` WHERE \`nachnum\`=${foreign_message_id};"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${function_done_table}\` WHERE \`nachnum\`=${foreign_message_id};"

load_message_list "$s1_cookies"
post_message_state "$s1_cookies" erledigt set "$message_id" 200
assert_db_equals 1 \
    "SELECT COUNT(*) FROM \`${function_done_table}\` WHERE \`nachnum\`=${message_id} AND \`erledigt\` IS NOT NULL;"
assert_workflow_state_controls_absent
post_message_state "$s1_cookies" erledigt set "$message_id" 200
assert_db_equals 1 \
    "SELECT COUNT(*) FROM \`${function_done_table}\` WHERE \`nachnum\`=${message_id};"
assert_workflow_state_controls_absent

state_csrf=$(csrf_from_body)
assert_status 200 \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$state_csrf" \
    --data-urlencode 'filter_erledigt_ein_x=1' \
    "$base_url/4fach/mainindex.php"
assert_workflow_state_control erledigt unset
post_message_state "$s1_cookies" erledigt unset "$message_id" 200
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${function_done_table}\` WHERE \`nachnum\`=${message_id};"
assert_body "$workflow_marker"
assert_workflow_state_control erledigt set
post_message_state "$s1_cookies" erledigt unset "$message_id" 200
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${function_done_table}\` WHERE \`nachnum\`=${message_id};"

assert_db_equals 1 \
    "SELECT COUNT(*) FROM \`${user_read_table}\` WHERE \`nachnum\`=${message_id};"
assert_workflow_state_control gelesen unset

state_csrf=$(csrf_from_body)
assert_status 200 \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$state_csrf" \
    --data-urlencode 'filter_erledigt_aus_x=1' \
    "$base_url/4fach/mainindex.php"
assert_body "$workflow_marker"

assert_status 422 \
    --cookie "$s1_cookies" \
    "$base_url/4fach/katgoedt.php?dbtyp=fkt&msgno=0"
assert_body 'data-estab-error-page'
assert_body 'data-estab-error-status="422"'
assert_body 'data-estab-error-context="messages"'
assert_body 'role="alert"'
assert_body 'data-estab-error-recovery'
assert_session_identity "$s1_name" "$s1_code" "$s1_function" Stab
load_manager "$s1_cookies" fkt "$message_id"
assert_session_identity "$s1_name" "$s1_code" "$s1_function" Stab
load_manager "$s1_cookies" user "$message_id"
assert_session_identity "$s1_name" "$s1_code" "$s1_function" Stab
assert_status 403 --cookie "$s1_cookies" \
    "$base_url/4fach/katgoedt.php?dbtyp=master&msgno=$message_id"
assert_body 'data-estab-error-page'
assert_body 'data-estab-error-status="403"'
assert_body 'Aktion nicht erlaubt.'
assert_session_identity "$s1_name" "$s1_code" "$s1_function" Stab
assert_status 403 --cookie "$s2_cookies" \
    "$base_url/4fach/katgoedt.php?dbtyp=master&msgno=$message_id"
assert_body 'data-estab-error-page'
assert_body 'data-estab-error-status="403"'
assert_body 'Aktion nicht erlaubt.'
assert_session_identity "$s2_name" "$s2_code" S2 Stab
load_manager "$s2_cookies" master "$redcopy_message_id"
assert_session_identity "$s2_name" "$s2_code" S2 Stab
assert_standard_master_categories_visible
load_manager "$si_cookies" master "$message_id"
assert_session_identity "$si_name" "$si_code" Si Stab
assert_standard_master_categories_visible

# Historic GET mutation parameters are display-only and cannot create a row.
assert_status 200 --cookie "$s1_cookies" \
    "$base_url/4fach/katgoedt.php?dbtyp=fkt&msgno=$message_id&category_action=create&kategorie=%3Cscript%3E&beschreibung=GET"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${function_category_table}\` WHERE HEX(\`kategorie\`)='3C7363726970743E';"

# Authenticated writes still require the session-bound CSRF token.
assert_status 403 \
    --cookie "$s1_cookies" --request POST \
    --data-urlencode 'category_action=create' \
    --data-urlencode 'dbtyp=fkt' \
    --data-urlencode "msgno=$message_id" \
    --data-urlencode 'kategorie=<script>' \
    --data-urlencode 'beschreibung=no csrf' \
    "$base_url/4fach/katgoedt.php"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${function_category_table}\` WHERE HEX(\`kategorie\`)='3C7363726970743E';"

raw_description='Quotes "'\'' & <script>alert(1)</script>'
raw_description_hex=$(hex_text "$raw_description")
create_category "$s1_cookies" fkt "$message_id" '<script>' "$raw_description"
function_category_id=$(db_sql <<SQL
SELECT \`lfd\` FROM \`${function_category_table}\`
 WHERE HEX(\`kategorie\`) = '3C7363726970743E'
 ORDER BY \`lfd\` DESC LIMIT 1;
SQL
)
assert_numeric 'function category' "$function_category_id"
assert_db_equals "$raw_description_hex" \
    "SELECT HEX(\`beschreibung\`) FROM \`${function_category_table}\` WHERE \`lfd\`=${function_category_id};"
load_manager "$s1_cookies" fkt "$message_id"
assert_body '&lt;script&gt;'
assert_body 'Quotes &quot;&#039; &amp; &lt;script&gt;alert(1)&lt;/script&gt;'
assert_body_absent '<script>alert(1)</script>'

create_category "$s1_cookies" user "$message_id" 'USR&Q' 'User "quote" & <script>u</script>'
user_category_id=$(db_sql <<SQL
SELECT \`lfd\` FROM \`${user_category_table}\`
 WHERE HEX(\`kategorie\`) = '5553522651'
 ORDER BY \`lfd\` DESC LIMIT 1;
SQL
)
assert_numeric 'user category' "$user_category_id"

create_category \
    "$s2_cookies" master "$redcopy_message_id" \
    'MSTR&Q' 'Master & "quote"'
master_category_id=$(db_sql <<'SQL'
SELECT `lfd` FROM `nv_masterkatego`
 WHERE HEX(`kategorie`) = '4D5354522651'
 ORDER BY `lfd` DESC LIMIT 1;
SQL
)
assert_numeric 'master category' "$master_category_id"

create_category "$si_cookies" master "$message_id" 'SIMASTER' 'Si may manage master'
si_master_category_id=$(db_sql <<'SQL'
SELECT `lfd` FROM `nv_masterkatego`
 WHERE HEX(`kategorie`) = '53494D4153544552'
 ORDER BY `lfd` DESC LIMIT 1;
SQL
)
assert_numeric 'Si master category' "$si_master_category_id"

# Prepared UPDATE treats SQL-looking text as data and changes only this row.
load_manager "$s1_cookies" fkt "$message_id"
csrf_token=$(csrf_from_body)
assert_status 303 \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$csrf_token" \
    --data-urlencode 'category_action=update' \
    --data-urlencode 'dbtyp=fkt' \
    --data-urlencode "msgno=$message_id" \
    --data-urlencode "category_id=$function_category_id" \
    --data-urlencode "kategorie=x' OR 1" \
    --data-urlencode "beschreibung=$raw_description" \
    "$base_url/4fach/katgoedt.php"
assert_db_equals 1 \
    "SELECT COUNT(*) FROM \`${function_category_table}\` WHERE \`lfd\`=${function_category_id} AND HEX(\`kategorie\`)='7827204F522031';"
load_manager "$s1_cookies" fkt "$message_id"
assert_body 'x&#039; OR 1'
assert_body_absent '<script>alert(1)</script>'

# The active four-part detail form exposes numeric IDs and submits assignment
# directly to the POST-only category endpoint with its existing CSRF token.
load_message_detail "$s1_cookies" "$message_id"
assert_body 'name="category_fkt_oben"'
assert_body 'name="category_user_oben"'
assert_body_absent 'name="category_master_oben"'
assert_body "option value=\"$function_category_id\""
assert_body "option value=\"$user_category_id\""
assert_body 'name="category_action" value="assign"'
assert_body "formaction=\"katgoedt.php?acting_function=${s1_function}\""

# S1 cannot smuggle a master assignment into the direct POST.
load_manager "$s1_cookies" fkt "$message_id"
csrf_token=$(csrf_from_body)
assert_status 403 \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$csrf_token" \
    --data-urlencode 'category_action=assign' \
    --data-urlencode "msglfd=$message_id" \
    --data-urlencode "category_master_oben=$master_category_id" \
    "$base_url/4fach/katgoedt.php"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`nv_masterkategolink\` WHERE \`msg\`=${message_id};"

# Assign the session-owned function/user categories to an authorised message.
load_message_detail "$s1_cookies" "$message_id"
csrf_token=$(csrf_from_body)
assert_status 303 \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$csrf_token" \
    --data-urlencode 'category_action=assign' \
    --data-urlencode "msglfd=$message_id" \
    --data-urlencode "category_fkt_oben=$function_category_id" \
    --data-urlencode "category_user_oben=$user_category_id" \
    "$base_url/4fach/katgoedt.php"
assert_db_equals "$function_category_id" \
    "SELECT \`katego\` FROM \`${function_link_table}\` WHERE \`msg\`=${message_id};"
assert_db_equals "$user_category_id" \
    "SELECT \`katego\` FROM \`${user_link_table}\` WHERE \`msg\`=${message_id};"

# A valid but foreign object ID fails before touching any link table.
load_manager "$s1_cookies" fkt "$message_id"
csrf_token=$(csrf_from_body)
assert_status 403 \
    --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$csrf_token" \
    --data-urlencode 'category_action=assign' \
    --data-urlencode "msglfd=$foreign_message_id" \
    --data-urlencode "category_fkt_oben=$function_category_id" \
    "$base_url/4fach/katgoedt.php"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${function_link_table}\` WHERE \`msg\`=${foreign_message_id};"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${user_link_table}\` WHERE \`msg\`=${foreign_message_id};"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`nv_masterkategolink\` WHERE \`msg\`=${foreign_message_id};"

# Red-copy may assign a master category to the same recipient-authorised object.
load_manager "$s2_cookies" master "$redcopy_message_id"
csrf_token=$(csrf_from_body)
assert_status 303 \
    --cookie "$s2_cookies" --cookie-jar "$s2_cookies" \
    --request POST \
    --data-urlencode "csrf_token=$csrf_token" \
    --data-urlencode 'category_action=assign' \
    --data-urlencode "msglfd=$redcopy_message_id" \
    --data-urlencode "category_master_oben=$master_category_id" \
    "$base_url/4fach/katgoedt.php"
assert_db_equals "$master_category_id" \
    "SELECT \`katego\` FROM \`nv_masterkategolink\` WHERE \`msg\`=${redcopy_message_id};"

load_message_detail "$s1_cookies" "$message_id"
assert_body 'Quotes &quot;&#039; &amp; &lt;script&gt;alert(1)&lt;/script&gt;'
assert_body_absent '<script>alert(1)</script>'

# List navigation uses the immutable positive ID, never the SQL-looking name.
assert_status 200 --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    "$base_url/4fach/mainindex.php?fk_ktgotyp=fkt&fk_ktgo=$function_category_id"
assert_body "$workflow_marker"
assert_status 403 --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    "$base_url/4fach/mainindex.php?fk_ktgotyp=fkt&fk_ktgo=x%27%20OR%201"

# HTTP deletes clean both category rows and their assignments.
delete_category "$s1_cookies" user "$message_id" "$user_category_id"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${user_category_table}\` WHERE \`lfd\`=${user_category_id};"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${user_link_table}\` WHERE \`msg\`=${message_id};"
delete_category "$s1_cookies" fkt "$message_id" "$function_category_id"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${function_category_table}\` WHERE \`lfd\`=${function_category_id};"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`${function_link_table}\` WHERE \`msg\`=${message_id};"
# Deleting the active filter must restore the unfiltered list instead of leaving
# a stale category ID in the session.
assert_status 200 --cookie "$s1_cookies" --cookie-jar "$s1_cookies" \
    "$base_url/4fach/mainindex.php"
assert_body "$workflow_marker"
delete_category \
    "$s2_cookies" master "$redcopy_message_id" "$master_category_id"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`nv_masterkatego\` WHERE \`lfd\`=${master_category_id};"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`nv_masterkategolink\` WHERE \`msg\`=${redcopy_message_id};"
delete_category "$si_cookies" master "$message_id" "$si_master_category_id"
assert_db_equals 0 \
    "SELECT COUNT(*) FROM \`nv_masterkatego\` WHERE \`lfd\`=${si_master_category_id};"

echo 'Category HTTP integration: OK'
