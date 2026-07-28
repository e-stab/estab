#!/bin/sh
set -eu

# This test intentionally mutates administrative state and is safe only in the
# synthetic, disposable Compose project created by tests/integration/ci.sh.
if [ "${ESTAB_ADMIN_HTTP_TEST_ALLOW_MUTATION:-false}" != "true" ]; then
    echo 'Admin HTTP: set ESTAB_ADMIN_HTTP_TEST_ALLOW_MUTATION=true only for a disposable stack' >&2
    exit 1
fi

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

base_url=${ESTAB_TEST_BASE_URL:-http://127.0.0.1:8080}
base_url=${base_url%/}
admin_user=${ESTAB_TEST_ADMIN_USER:-}
admin_password=${ESTAB_TEST_ADMIN_PASSWORD:-}
if [ -z "$admin_password" ] && [ -n "${ESTAB_TEST_ADMIN_PASSWORD_FILE:-}" ]; then
    if [ ! -r "$ESTAB_TEST_ADMIN_PASSWORD_FILE" ]; then
        echo 'Admin HTTP: admin password file is unreadable' >&2
        exit 1
    fi
    admin_password=$(tr -d '\r\n' <"$ESTAB_TEST_ADMIN_PASSWORD_FILE")
fi
if [ -z "$admin_user" ] || [ -z "$admin_password" ]; then
    echo 'Admin HTTP: Basic-Auth test credentials are required' >&2
    exit 1
fi

compose_engine=${ESTAB_TEST_COMPOSE_ENGINE:-docker}
case "$compose_engine" in
    docker | podman) ;;
    *)
        echo 'Admin HTTP: ESTAB_TEST_COMPOSE_ENGINE must be docker or podman' >&2
        exit 1
        ;;
esac
"$compose_engine" compose version >/dev/null

work_dir=$(mktemp -d /tmp/estab-admin-http.XXXXXX)
body=$work_dir/body.html
admin_cookie=$work_dir/admin-cookies.txt
second_cookie=$work_dir/second-admin-cookies.txt
admin_curl_config=$work_dir/admin-curl.conf
original_matrix=$work_dir/original-matrix.txt
restored_matrix=$work_dir/restored-matrix.txt
users_before=$work_dir/users-before.txt
users_after=$work_dir/users-after.txt
backup_created=false

project_name=${COMPOSE_PROJECT_NAME:-estab}
test_number=$(printf '%s' "$project_name" | cksum | awk '{print $1}')
case "$test_number" in
    '' | *[!0-9]*)
        echo 'Admin HTTP: could not derive a safe fixture number' >&2
        exit 1
        ;;
esac
backup_table="estab_admin_matrix_${test_number}"
print_backup_table="estab_admin_print_${test_number}"
marker="ESTAB_ADMIN_RESET_${test_number}_$$"

escaped_admin_credentials=$(printf '%s:%s' "$admin_user" "$admin_password" |
    sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')
printf 'user = "%s"\n' "$escaped_admin_credentials" >"$admin_curl_config"
chmod 0600 "$admin_curl_config"
unset admin_password escaped_admin_credentials

db_sql()
{
    "$compose_engine" compose exec -T db sh -ceu '
        umask 077
        client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-admin-client.XXXXXX")
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

matrix_auto_increment=1
message_auto_increment=1
protocol_auto_increment=1
message_floor=0
audit_floor=0

cleanup()
{
    status=$?
    trap - EXIT HUP INT TERM
    set +e
    if [ "$backup_created" = true ]; then
        db_sql >/dev/null 2>&1 <<SQL
START TRANSACTION;
DELETE FROM \`nv_empfmtx\`;
INSERT INTO \`nv_empfmtx\` SELECT * FROM \`${backup_table}\`;
DELETE FROM \`nv_nachrichten\`
 WHERE \`00_lfd\` > ${message_floor}
   AND (
     \`12_inhalt\` = '${marker}'
     OR \`12_inhalt\` LIKE 'eStab Systemmeldung.%Nachrichtenzähler wurde nach Systemausfall%'
   );
UPDATE \`nv_nachrichten\` AS current_message
  JOIN \`${print_backup_table}\` AS original_print
    ON original_print.\`00_lfd\` = current_message.\`00_lfd\`
   SET current_message.\`x04_druck\` = original_print.\`x04_druck\`;
DELETE FROM \`nv_protokoll\`
 WHERE \`p_lfd\` > ${audit_floor}
   AND \`p_was\` IN ('Empfängermatrix', 'Nachrichtennummer Sync', 'Grafikstatus Reset');
COMMIT;
DROP TABLE IF EXISTS \`${backup_table}\`;
DROP TABLE IF EXISTS \`${print_backup_table}\`;
ALTER TABLE \`nv_empfmtx\` AUTO_INCREMENT = ${matrix_auto_increment};
ALTER TABLE \`nv_nachrichten\` AUTO_INCREMENT = ${message_auto_increment};
ALTER TABLE \`nv_protokoll\` AUTO_INCREMENT = ${protocol_auto_increment};
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
        --output "$body" --write-out '%{http_code}' "$@"
}

assert_status()
{
    expected=$1
    shift
    actual=$(request_status "$@")
    if [ "$actual" != "$expected" ]; then
        printf 'Admin HTTP: expected %s, got %s for %s\n' \
            "$expected" "$actual" "$*" >&2
        sed -n '1,100p' "$body" >&2
        exit 1
    fi
}

assert_body()
{
    expected=$1
    if ! grep -Fq -- "$expected" "$body"; then
        printf 'Admin HTTP: response does not contain %s\n' "$expected" >&2
        sed -n '1,100p' "$body" >&2
        exit 1
    fi
}

csrf_from_body()
{
    token=$(sed -n \
        's/.*name="csrf_token" value="\([a-f0-9][a-f0-9]*\)".*/\1/p' \
        "$body" | head -n 1)
    if ! printf '%s' "$token" | grep -Eq '^[a-f0-9]{64}$'; then
        echo 'Admin HTTP: CSRF token missing' >&2
        sed -n '1,100p' "$body" >&2
        exit 1
    fi
    printf '%s' "$token"
}

normalized_matrix()
{
    db_sql <<'SQL'
SELECT CONCAT(
         `mtx_x`, '|', `mtx_y`, '|', `mtx_fkt`, '|', `mtx_rolle`, '|',
         IF(`mtx_rc2` IN ('t','1'), '1', '0'), '|',
         IF(`mtx_auto` IN ('t','1'), '1', '0')
       )
  FROM `nv_empfmtx`
 ORDER BY `mtx_x`, `mtx_y`;
SQL
}

users_snapshot()
{
    db_sql <<'SQL'
SELECT CONCAT_WS('|',
         HEX(`benutzer`), HEX(`kuerzel`), HEX(`funktion`), HEX(`rolle`),
         HEX(`sid`), HEX(`ip`), HEX(`fwdip`), `aktiv`, HEX(`password`)
       )
  FROM `nv_benutzer`
 ORDER BY `kuerzel`;
SQL
}

write_matrix_payload()
{
    destination=$1
    csrf_token=$2
    changed_position=${3:-}

    redcopy_position=$(awk -F '|' '$5 == "1" { print $1 $2 }' "$original_matrix")
    if ! printf '%s' "$redcopy_position" | grep -Eq '^[1-5][1-4]$'; then
        echo 'Admin HTTP: original matrix has no unique red-copy target' >&2
        exit 1
    fi

    printf 'csrf_token=%s&admin_action=save_matrix&lagerot=%s' \
        "$csrf_token" "$redcopy_position" >"$destination"
    while IFS='|' read -r row column function_name role redcopy auto; do
        position="${row}${column}"
        if [ -n "$changed_position" ] && [ "$position" = "$changed_position" ]; then
            function_name=E2EADM
            role=FB
            auto=1
        fi
        printf '&pos_%s=%s&rolle_%s=%s' \
            "$position" "$function_name" "$position" "$role" >>"$destination"
        if [ "$auto" = 1 ]; then
            printf '&stasi_%s=1' "$position" >>"$destination"
        fi
    done <"$original_matrix"
}

# Apache authentication and deny rules must fire before PHP renders data.
assert_status 401 "$base_url/4fadm/make_fkt.php"
assert_status 401 "$base_url/4fadm/set_number_after_crash.php"
assert_status 401 "$base_url/4fach/resetpic.php"
assert_status 403 "$base_url/4fach/all_msg.php"
assert_status 403 "$base_url/4fach/upload/foto_upload.php"
assert_status 403 "$base_url/4fach/upload/filemenue.php"
assert_status 403 "$base_url/4fach/upload/abfall.php"
assert_status 410 "$base_url/4fach/upload/upload.php"
assert_status 403 "$base_url/4fach/Print/PHPrint.php?url=4fcfg/dbcfg.inc.php"
assert_status 403 "$base_url/4fbak/fpdf/ex.php"

matrix_auto_increment=$(db_sql <<'SQL'
SELECT COALESCE(`AUTO_INCREMENT`, 1)
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name = 'nv_empfmtx';
SQL
)
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
message_floor=$(db_sql <<'SQL'
SELECT COALESCE(MAX(`00_lfd`), 0) FROM `nv_nachrichten`;
SQL
)
audit_floor=$(db_sql <<'SQL'
SELECT COALESCE(MAX(`p_lfd`), 0) FROM `nv_protokoll`;
SQL
)
for number in "$matrix_auto_increment" "$message_auto_increment" \
    "$protocol_auto_increment" "$message_floor" "$audit_floor"; do
    case "$number" in
        '' | *[!0-9]*)
            echo 'Admin HTTP: database returned a non-numeric fixture boundary' >&2
            exit 1
            ;;
    esac
done

db_sql <<SQL
DROP TABLE IF EXISTS \`${backup_table}\`;
CREATE TABLE \`${backup_table}\` LIKE \`nv_empfmtx\`;
INSERT INTO \`${backup_table}\` SELECT * FROM \`nv_empfmtx\`;
DROP TABLE IF EXISTS \`${print_backup_table}\`;
CREATE TABLE \`${print_backup_table}\` (
  \`00_lfd\` BIGINT NOT NULL PRIMARY KEY,
  \`x04_druck\` BINARY(1) NOT NULL
) ENGINE=InnoDB;
INSERT INTO \`${print_backup_table}\` (\`00_lfd\`, \`x04_druck\`)
SELECT \`00_lfd\`, \`x04_druck\` FROM \`nv_nachrichten\`;
INSERT INTO \`nv_nachrichten\`
  (\`04_richtung\`, \`04_nummer\`, \`12_inhalt\`, \`x04_druck\`)
VALUES ('E', 0, '${marker}', 't');
SQL
backup_created=true

normalized_matrix >"$original_matrix"
users_snapshot >"$users_before"
if [ "$(wc -l <"$original_matrix" | tr -d ' ')" != 20 ]; then
    echo 'Admin HTTP: original matrix is not five by four' >&2
    exit 1
fi
blank_position=$(awk -F '|' '$3 == "" { print $1 $2; exit }' "$original_matrix")
if ! printf '%s' "$blank_position" | grep -Eq '^[1-5][1-4]$'; then
    echo 'Admin HTTP: disposable default matrix has no blank test position' >&2
    exit 1
fi

# Historical GET write controls are inert for matrix, counter and reset.
assert_status 200 --config "$admin_curl_config" \
    "$base_url/4fadm/make_fkt.php?absenden_x=1&pos_11=GETWRITE"
normalized_matrix >"$restored_matrix"
cmp -s "$original_matrix" "$restored_matrix" || {
    echo 'Admin HTTP: matrix changed through GET' >&2
    exit 1
}

counter_before=$(db_sql <<'SQL'
SELECT COALESCE(MAX(`04_nummer`), 0) FROM `nv_nachrichten`;
SQL
)
assert_status 200 --config "$admin_curl_config" \
    "$base_url/4fadm/set_number_after_crash.php?page=2&ea_nummer=999999999"
counter_after_get=$(db_sql <<'SQL'
SELECT COALESCE(MAX(`04_nummer`), 0) FROM `nv_nachrichten`;
SQL
)
if [ "$counter_before" != "$counter_after_get" ]; then
    echo 'Admin HTTP: message counter changed through GET' >&2
    exit 1
fi

assert_status 200 --config "$admin_curl_config" \
    "$base_url/4fach/resetpic.php?absenden_x=1"
reset_flag=$(db_sql <<SQL
SELECT \`x04_druck\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${marker}' LIMIT 1;
SQL
)
if [ "$reset_flag" != t ]; then
    echo 'Admin HTTP: graphic flag changed through GET' >&2
    exit 1
fi

# A Basic-authenticated request still needs its own PHP session and CSRF token.
assert_status 403 --config "$admin_curl_config" \
    --request POST --data-urlencode 'admin_action=save_matrix' \
    "$base_url/4fadm/make_fkt.php"
assert_status 403 --config "$admin_curl_config" \
    --request POST --data-urlencode 'ea_nummer=999999999' \
    "$base_url/4fadm/set_number_after_crash.php"
assert_status 403 --config "$admin_curl_config" \
    --request POST --data-urlencode 'admin_action=reset_print_flags' \
    "$base_url/4fach/resetpic.php"

# Change one unused recipient cell, verify it, then restore the complete
# original matrix through the same HTTP transaction.
assert_status 200 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    "$base_url/4fadm/make_fkt.php"
matrix_csrf=$(csrf_from_body)
changed_payload=$work_dir/matrix-changed.txt
write_matrix_payload "$changed_payload" "$matrix_csrf" "$blank_position"
assert_status 303 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@$changed_payload" \
    "$base_url/4fadm/make_fkt.php"
assert_status 200 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    "$base_url/4fadm/make_fkt.php"
assert_body 'value="E2EADM"'
changed_cell=$(db_sql <<SQL
SELECT CONCAT(\`mtx_fkt\`, '|', \`mtx_rolle\`, '|',
              IF(\`mtx_auto\` IN ('t','1'), '1', '0'))
  FROM \`nv_empfmtx\`
 WHERE CONCAT(\`mtx_x\`, \`mtx_y\`) = '${blank_position}';
SQL
)
if [ "$changed_cell" != 'E2EADM|FB|1' ]; then
    echo 'Admin HTTP: matrix change was not persisted exactly' >&2
    exit 1
fi

restore_payload=$work_dir/matrix-restore.txt
write_matrix_payload "$restore_payload" "$matrix_csrf"
assert_status 303 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@$restore_payload" \
    "$base_url/4fadm/make_fkt.php"
normalized_matrix >"$restored_matrix"
cmp -s "$original_matrix" "$restored_matrix" || {
    echo 'Admin HTTP: matrix roundtrip did not restore all 20 cells' >&2
    exit 1
}

# Two independent PHP sessions submit the same next number concurrently. The
# database lock permits exactly one increase and rejects the stale request.
if [ "$counter_before" -ge 999999999 ]; then
    echo 'Admin HTTP: disposable counter has exhausted the supported range' >&2
    exit 1
fi
counter_target=$((counter_before + 1))

assert_status 200 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    "$base_url/4fadm/set_number_after_crash.php"
assert_body 'data-counter-mode="gemeinsam"'
counter_csrf_one=$(csrf_from_body)

assert_status 200 --config "$admin_curl_config" \
    --cookie "$second_cookie" --cookie-jar "$second_cookie" \
    "$base_url/4fadm/set_number_after_crash.php"
counter_csrf_two=$(csrf_from_body)

curl --silent --show-error --max-time 30 --connect-timeout 5 \
    --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --output "$work_dir/counter-one.html" --write-out '%{http_code}' \
    --request POST \
    --data-urlencode "csrf_token=$counter_csrf_one" \
    --data-urlencode 'admin_action=raise_counter' \
    --data-urlencode "ea_nummer=$counter_target" \
    "$base_url/4fadm/set_number_after_crash.php" >"$work_dir/counter-one.status" &
first_pid=$!
curl --silent --show-error --max-time 30 --connect-timeout 5 \
    --config "$admin_curl_config" \
    --cookie "$second_cookie" --cookie-jar "$second_cookie" \
    --output "$work_dir/counter-two.html" --write-out '%{http_code}' \
    --request POST \
    --data-urlencode "csrf_token=$counter_csrf_two" \
    --data-urlencode 'admin_action=raise_counter' \
    --data-urlencode "ea_nummer=$counter_target" \
    "$base_url/4fadm/set_number_after_crash.php" >"$work_dir/counter-two.status" &
second_pid=$!
wait "$first_pid"
wait "$second_pid"
counter_statuses=$(printf '%s\n%s\n' \
    "$(cat "$work_dir/counter-one.status")" \
    "$(cat "$work_dir/counter-two.status")" | LC_ALL=C sort | tr '\n' ' ')
if [ "$counter_statuses" != '303 409 ' ]; then
    printf 'Admin HTTP: concurrent counter statuses were %s, expected 303 and 409\n' \
        "$counter_statuses" >&2
    exit 1
fi
counter_rows=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_nachrichten\`
 WHERE \`04_richtung\` = 'E'
   AND \`04_nummer\` = ${counter_target}
   AND \`12_inhalt\` LIKE 'eStab Systemmeldung.%Nachrichtenzähler wurde nach Systemausfall%';
SQL
)
if [ "$counter_rows" != 1 ]; then
    echo 'Admin HTTP: concurrent counter update did not create exactly one system message' >&2
    exit 1
fi

# The graphic reset is now a separately authenticated POST-only transaction.
assert_status 200 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    "$base_url/4fach/resetpic.php"
reset_csrf=$(csrf_from_body)
assert_status 303 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST \
    --data-urlencode "csrf_token=$reset_csrf" \
    --data-urlencode 'admin_action=reset_print_flags' \
    "$base_url/4fach/resetpic.php"
reset_flag=$(db_sql <<SQL
SELECT \`x04_druck\` FROM \`nv_nachrichten\`
 WHERE \`12_inhalt\` = '${marker}' LIMIT 1;
SQL
)
if [ "$reset_flag" != f ]; then
    echo 'Admin HTTP: graphic reset did not clear the fixture flag' >&2
    exit 1
fi

users_snapshot >"$users_after"
cmp -s "$users_before" "$users_after" || {
    echo 'Admin HTTP: matrix/admin workflows changed current user assignments' >&2
    exit 1
}

audit_count=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_protokoll\`
 WHERE \`p_lfd\` > ${audit_floor}
   AND \`p_was\` IN ('Empfängermatrix', 'Nachrichtennummer Sync', 'Grafikstatus Reset');
SQL
)
if [ "$audit_count" -lt 4 ]; then
    echo 'Admin HTTP: prepared audit records are incomplete' >&2
    exit 1
fi

echo 'Admin workflows HTTP integration: OK'
