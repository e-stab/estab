#!/bin/sh
set -eu

# This test intentionally mutates administrative state and is safe only in the
# synthetic, disposable Compose project created by tests/integration/ci.sh.
if [ "${ESTAB_ADMIN_HTTP_TEST_ALLOW_MUTATION:-false}" != "true" ]; then
    echo 'Admin HTTP: set ESTAB_ADMIN_HTTP_TEST_ALLOW_MUTATION=true only for a disposable stack' >&2
    exit 1
fi

project_name=${COMPOSE_PROJECT_NAME:-estab}
case "$project_name" in
    estab_ci | estab_ci_*) ;;
    *)
        echo 'Admin HTTP: refusing mutation outside an estab_ci project' >&2
        exit 1
        ;;
esac

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
original_standard_matrix=$work_dir/original-standard-matrix.txt
observed_standard_matrix=$work_dir/observed-standard-matrix.txt
active_before_load=$work_dir/active-before-load.txt
users_before=$work_dir/users-before.txt
users_after=$work_dir/users-after.txt
backup_created=false

test_number=$(printf '%s' "$project_name" | cksum | awk '{print $1}')
case "$test_number" in
    '' | *[!0-9]*)
        echo 'Admin HTTP: could not derive a safe fixture number' >&2
        exit 1
        ;;
esac
backup_table="estab_admin_matrix_${test_number}"
standard_backup_table="estab_admin_standard_${test_number}"
print_backup_table="estab_admin_print_${test_number}"
marker="ESTAB_ADMIN_RESET_${test_number}_$$"
rollback_trigger="estab_admin_standard_fail_${test_number}_$$"

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
standard_matrix_auto_increment=1
message_auto_increment=1
protocol_auto_increment=1
message_floor=0
audit_floor=0
active_incident_id=0

cleanup()
{
    status=$?
    trap - EXIT HUP INT TERM
    set +e
    if [ "$backup_created" = true ]; then
        db_sql >/dev/null 2>&1 <<SQL
DROP TRIGGER IF EXISTS \`${rollback_trigger}\`;
START TRANSACTION;
DELETE FROM \`nv_empfmtx\`;
INSERT INTO \`nv_empfmtx\` SELECT * FROM \`${backup_table}\`;
DELETE FROM \`nv_empfmtx_standard\`;
INSERT INTO \`nv_empfmtx_standard\` SELECT * FROM \`${standard_backup_table}\`;
DELETE FROM \`nv_nachrichten\`
 WHERE \`00_lfd\` > ${message_floor}
   AND \`einsatz_id\` = ${active_incident_id}
   AND (
     \`12_inhalt\` = '${marker}'
     OR \`12_inhalt\` LIKE 'eStab Systemmeldung.%Nachrichtenzähler wurde nach Systemausfall%'
   );
UPDATE \`nv_nachrichten\` AS current_message
  JOIN \`${print_backup_table}\` AS original_print
    ON original_print.\`00_lfd\` = current_message.\`00_lfd\`
   SET current_message.\`x04_druck\` = original_print.\`x04_druck\`
 WHERE current_message.\`einsatz_id\` = ${active_incident_id};
DELETE FROM \`nv_protokoll\`
 WHERE \`p_lfd\` > ${audit_floor}
   AND \`p_was\` IN ('Empfängermatrix', 'Nachrichtennummer Sync', 'Grafikstatus Reset');
COMMIT;
DROP TABLE IF EXISTS \`${backup_table}\`;
DROP TABLE IF EXISTS \`${standard_backup_table}\`;
DROP TABLE IF EXISTS \`${print_backup_table}\`;
ALTER TABLE \`nv_empfmtx\` AUTO_INCREMENT = ${matrix_auto_increment};
ALTER TABLE \`nv_empfmtx_standard\` AUTO_INCREMENT = ${standard_matrix_auto_increment};
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

assert_body_absent()
{
    unexpected=$1
    if grep -Fq -- "$unexpected" "$body"; then
        printf 'Admin HTTP: response unexpectedly contains %s\n' "$unexpected" >&2
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

normalized_standard_matrix()
{
    db_sql <<'SQL'
SELECT CONCAT(
         `mtx_x`, '|', `mtx_y`, '|', `mtx_fkt`, '|', `mtx_rolle`, '|',
         IF(`mtx_rc2` IN ('t','1'), '1', '0'), '|',
         IF(`mtx_auto` IN ('t','1'), '1', '0')
       )
  FROM `nv_empfmtx_standard`
 ORDER BY `mtx_x`, `mtx_y`;
SQL
}

exact_matrix_snapshot()
{
    db_sql <<'SQL'
SELECT CONCAT_WS('|',
         `mtx_lfd`, `mtx_x`, `mtx_y`, HEX(`mtx_typ`), HEX(`mtx_fkt`),
         HEX(`mtx_rolle`), HEX(`mtx_mode`), HEX(`mtx_rc2`), HEX(`mtx_auto`)
       )
  FROM `nv_empfmtx`
 ORDER BY `mtx_x`, `mtx_y`;
SQL
}

exact_standard_matrix_snapshot()
{
    db_sql <<'SQL'
SELECT CONCAT_WS('|',
         `mtx_lfd`, `mtx_x`, `mtx_y`, HEX(`mtx_typ`), HEX(`mtx_fkt`),
         HEX(`mtx_rolle`), HEX(`mtx_mode`), HEX(`mtx_rc2`), HEX(`mtx_auto`)
       )
  FROM `nv_empfmtx_standard`
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
    action=${4:-save_matrix}
    source_matrix=${5:-$original_matrix}
    changed_function=${6:-E2EADM}

    case "$action" in
        save_matrix | save_matrix_and_standard) ;;
        *)
            echo 'Admin HTTP: invalid matrix test action' >&2
            exit 1
            ;;
    esac

    redcopy_position=$(awk -F '|' '$5 == "1" { print $1 $2 }' "$source_matrix")
    if ! printf '%s' "$redcopy_position" | grep -Eq '^[1-5][1-4]$'; then
        echo 'Admin HTTP: original matrix has no unique red-copy target' >&2
        exit 1
    fi

    printf 'csrf_token=%s&admin_action=%s&lagerot=%s' \
        "$csrf_token" "$action" "$redcopy_position" >"$destination"
    while IFS='|' read -r row column function_name role redcopy auto; do
        position="${row}${column}"
        if [ -n "$changed_position" ] && [ "$position" = "$changed_position" ]; then
            function_name=$changed_function
            role=FB
            auto=0
        fi
        printf '&pos_%s=%s&rolle_%s=%s' \
            "$position" "$function_name" "$position" "$role" >>"$destination"
        if [ "$auto" = 1 ]; then
            printf '&stasi_%s=1' "$position" >>"$destination"
        fi
    done <"$source_matrix"
}

# Apache authentication and deny rules must fire before PHP renders data.
assert_status 401 "$base_url/4fadm/make_fkt.php"
assert_status 401 "$base_url/4fadm/set_number_after_crash.php"
assert_status 401 "$base_url/4fadm/users.php"
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
standard_matrix_auto_increment=$(db_sql <<'SQL'
SELECT COALESCE(`AUTO_INCREMENT`, 1)
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name = 'nv_empfmtx_standard';
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
active_incident_id=$(db_sql <<'SQL'
SELECT `active_einsatz_id`
  FROM `nv_einsatz_status`
 WHERE `singleton_id` = 1;
SQL
)
for number in "$matrix_auto_increment" "$standard_matrix_auto_increment" "$message_auto_increment" \
    "$protocol_auto_increment" "$message_floor" "$audit_floor"; do
    case "$number" in
        '' | *[!0-9]*)
            echo 'Admin HTTP: database returned a non-numeric fixture boundary' >&2
            exit 1
            ;;
    esac
done
case "$active_incident_id" in
    '' | 0 | *[!0-9]*)
        echo 'Admin HTTP: no active incident is available for the fixture' >&2
        exit 1
        ;;
esac

db_sql <<SQL
DROP TABLE IF EXISTS \`${backup_table}\`;
CREATE TABLE \`${backup_table}\` LIKE \`nv_empfmtx\`;
INSERT INTO \`${backup_table}\` SELECT * FROM \`nv_empfmtx\`;
DROP TABLE IF EXISTS \`${standard_backup_table}\`;
CREATE TABLE \`${standard_backup_table}\` LIKE \`nv_empfmtx_standard\`;
INSERT INTO \`${standard_backup_table}\` SELECT * FROM \`nv_empfmtx_standard\`;
DROP TABLE IF EXISTS \`${print_backup_table}\`;
CREATE TABLE \`${print_backup_table}\` (
  \`00_lfd\` BIGINT NOT NULL PRIMARY KEY,
  \`x04_druck\` BINARY(1) NOT NULL
) ENGINE=InnoDB;
INSERT INTO \`${print_backup_table}\` (\`00_lfd\`, \`x04_druck\`)
SELECT \`00_lfd\`, \`x04_druck\` FROM \`nv_nachrichten\`
 WHERE \`einsatz_id\` = ${active_incident_id};
INSERT INTO \`nv_nachrichten\`
  (\`einsatz_id\`, \`04_richtung\`, \`04_nummer\`, \`12_inhalt\`, \`x04_druck\`)
VALUES (${active_incident_id}, 'E', 0, '${marker}', 't');
SQL
backup_created=true

normalized_matrix >"$original_matrix"
normalized_standard_matrix >"$original_standard_matrix"
users_snapshot >"$users_before"
if [ "$(wc -l <"$original_matrix" | tr -d ' ')" != 20 ]; then
    echo 'Admin HTTP: original matrix is not five by four' >&2
    exit 1
fi
if [ "$(wc -l <"$original_standard_matrix" | tr -d ' ')" != 20 ]; then
    echo 'Admin HTTP: original standard matrix is not five by four' >&2
    exit 1
fi
blank_position=$(awk -F '|' '$3 == "" { print $1 $2; exit }' "$original_matrix")
if ! printf '%s' "$blank_position" | grep -Eq '^[1-5][1-4]$'; then
    echo 'Admin HTTP: disposable default matrix has no blank test position' >&2
    exit 1
fi

# Historical submit, load and save image-button GET controls are inert for
# both the active and the persistent standard matrix.
for legacy_matrix_control in absenden_x laden_x speichern_x; do
    assert_status 200 --config "$admin_curl_config" \
        "$base_url/4fadm/make_fkt.php?${legacy_matrix_control}=1&pos_11=GETWRITE"
    normalized_matrix >"$restored_matrix"
    cmp -s "$original_matrix" "$restored_matrix" || {
        printf 'Admin HTTP: active matrix changed through historical GET %s\n' \
            "$legacy_matrix_control" >&2
        exit 1
    }
    normalized_standard_matrix >"$observed_standard_matrix"
    cmp -s "$original_standard_matrix" "$observed_standard_matrix" || {
        printf 'Admin HTTP: standard matrix changed through historical GET %s\n' \
            "$legacy_matrix_control" >&2
        exit 1
    }
done

counter_before=$(db_sql <<'SQL'
SELECT GREATEST(
         COALESCE((
           SELECT MAX(m.`04_nummer`)
             FROM `nv_nachrichten` AS m
            WHERE m.`einsatz_id` = s.`active_einsatz_id`
         ), 0),
         COALESCE((
           SELECT MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(
                        e.`details`, '$.after.ea_nummer'
                      )) AS UNSIGNED))
             FROM `nv_betriebsereignisse` AS e
            WHERE e.`einsatz_id` = s.`active_einsatz_id`
              AND e.`objekttyp` = 'DIENSTSCHICHT'
              AND e.`aktion` = 'message_counter_repaired'
         ), 0)
       )
  FROM `nv_einsatz_status` AS s
 WHERE s.`singleton_id` = 1;
SQL
)
assert_status 200 --config "$admin_curl_config" \
    "$base_url/4fadm/set_number_after_crash.php?page=2&ea_nummer=999999999"
counter_after_get=$(db_sql <<'SQL'
SELECT GREATEST(
         COALESCE((
           SELECT MAX(m.`04_nummer`)
             FROM `nv_nachrichten` AS m
            WHERE m.`einsatz_id` = s.`active_einsatz_id`
         ), 0),
         COALESCE((
           SELECT MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(
                        e.`details`, '$.after.ea_nummer'
                      )) AS UNSIGNED))
             FROM `nv_betriebsereignisse` AS e
            WHERE e.`einsatz_id` = s.`active_einsatz_id`
              AND e.`objekttyp` = 'DIENSTSCHICHT'
              AND e.`aktion` = 'message_counter_repaired'
         ), 0)
       )
  FROM `nv_einsatz_status` AS s
 WHERE s.`singleton_id` = 1;
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
    --request POST --data-urlencode 'admin_action=load_standard' \
    "$base_url/4fadm/make_fkt.php"
assert_status 403 --config "$admin_curl_config" \
    --request POST --data-urlencode 'admin_action=save_matrix_and_standard' \
    "$base_url/4fadm/make_fkt.php"
assert_status 403 --config "$admin_curl_config" \
    --request POST --data-urlencode 'ea_nummer=999999999' \
    "$base_url/4fadm/set_number_after_crash.php"
assert_status 403 --config "$admin_curl_config" \
    --request POST \
    --data-urlencode 'admin_action=block' \
    --data-urlencode 'target_code=e2e001' \
    "$base_url/4fadm/users.php"
assert_status 403 --config "$admin_curl_config" \
    --request POST --data-urlencode 'admin_action=reset_print_flags' \
    "$base_url/4fach/resetpic.php"

# The account-management controller must be reachable through the same
# independently authenticated administration surface and issue a per-session
# CSRF token before any block or password-reset action can be submitted.
assert_status 200 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    "$base_url/4fadm/users.php"
assert_body 'data-estab-user-admin'
assert_body 'Benutzerverwaltung'
users_csrf=$(csrf_from_body)
missing_user_code=zz404z
if [ "$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_benutzer\`
 WHERE \`kuerzel\` = '${missing_user_code}';
SQL
)" != 0 ]; then
    missing_user_code=zz405z
fi
if [ "$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_benutzer\`
 WHERE \`kuerzel\` = '${missing_user_code}';
SQL
)" != 0 ]; then
    echo 'Admin HTTP: could not reserve a missing user fixture' >&2
    exit 1
fi
assert_status 404 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST \
    --data-urlencode "csrf_token=$users_csrf" \
    --data-urlencode 'admin_action=block' \
    --data-urlencode "target_code=$missing_user_code" \
    "$base_url/4fadm/users.php"
assert_body 'Das ausgewählte Konto wurde nicht gefunden.'

# Change one unused recipient cell, verify it, then restore the complete
# original matrix through the same HTTP transaction.
assert_status 200 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    "$base_url/4fadm/make_fkt.php"
matrix_csrf=$(csrf_from_body)

# Automatic sighting cannot replace the qualified Si workflow required by
# DV 1-101. Prove that a syntactically complete matrix carrying the historic
# stasi_* flag is rejected without changing either matrix or the audit ledger.
autosight_payload=$work_dir/matrix-autosight-invalid.txt
write_matrix_payload \
    "$autosight_payload" "$matrix_csrf" "$blank_position" \
    save_matrix "$original_matrix" E2EAUT
printf '&stasi_%s=1' "$blank_position" >>"$autosight_payload"
matrix_audit_before_autosight=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_protokoll\`
 WHERE \`p_lfd\` > ${audit_floor}
   AND \`p_was\` = 'Empfängermatrix';
SQL
)
assert_status 422 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@$autosight_payload" \
    "$base_url/4fadm/make_fkt.php"
assert_body 'Die Matrix ist ungültig.'
assert_body 'Autosichtung ist nicht'
normalized_matrix >"$restored_matrix"
cmp -s "$original_matrix" "$restored_matrix" || {
    echo 'Admin HTTP: rejected autosighting changed the active matrix' >&2
    exit 1
}
normalized_standard_matrix >"$observed_standard_matrix"
cmp -s "$original_standard_matrix" "$observed_standard_matrix" || {
    echo 'Admin HTTP: rejected autosighting changed the standard matrix' >&2
    exit 1
}
matrix_audit_after_autosight=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_protokoll\`
 WHERE \`p_lfd\` > ${audit_floor}
   AND \`p_was\` = 'Empfängermatrix';
SQL
)
if [ "$matrix_audit_before_autosight" != "$matrix_audit_after_autosight" ]; then
    echo 'Admin HTTP: rejected autosighting wrote an audit mutation' >&2
    exit 1
fi

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
if [ "$changed_cell" != 'E2EADM|FB|0' ]; then
    echo 'Admin HTTP: matrix change was not persisted exactly' >&2
    exit 1
fi

# Saving only the active matrix must not modify the persistent preset.
normalized_matrix >"$active_before_load"
normalized_standard_matrix >"$observed_standard_matrix"
cmp -s "$original_standard_matrix" "$observed_standard_matrix" || {
    echo 'Admin HTTP: active-only save changed the standard matrix' >&2
    exit 1
}

# Loading the standard is a POST+CSRF read: it changes neither table nor the
# audit ledger and presents an explicitly unsaved editor state.
matrix_audit_before_load=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_protokoll\`
 WHERE \`p_lfd\` > ${audit_floor}
   AND \`p_was\` = 'Empfängermatrix';
SQL
)
assert_status 200 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST \
    --data-urlencode "csrf_token=$matrix_csrf" \
    --data-urlencode 'admin_action=load_standard' \
    "$base_url/4fadm/make_fkt.php"
assert_body 'Die Standardmatrix wurde in den Editor geladen, aber noch nicht gespeichert.'
assert_body 'data-estab-dirty-initial'
assert_body_absent 'value="E2EADM"'
normalized_matrix >"$restored_matrix"
cmp -s "$active_before_load" "$restored_matrix" || {
    echo 'Admin HTTP: loading the standard matrix changed the active matrix' >&2
    exit 1
}
normalized_standard_matrix >"$observed_standard_matrix"
cmp -s "$original_standard_matrix" "$observed_standard_matrix" || {
    echo 'Admin HTTP: loading the standard matrix changed the preset itself' >&2
    exit 1
}
matrix_audit_after_load=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_protokoll\`
 WHERE \`p_lfd\` > ${audit_floor}
   AND \`p_was\` = 'Empfängermatrix';
SQL
)
if [ "$matrix_audit_before_load" != "$matrix_audit_after_load" ]; then
    echo 'Admin HTTP: loading the standard matrix wrote an audit mutation' >&2
    exit 1
fi

# The combined action persists an exact copy to both tables in one transaction,
# including the red-copy flag while keeping forbidden autosighting disabled.
save_both_payload=$work_dir/matrix-save-both.txt
write_matrix_payload \
    "$save_both_payload" "$matrix_csrf" "$blank_position" \
    save_matrix_and_standard

# Force the second table's first insert to fail. The active replacement has
# already run at that point, so exact before/after row snapshots prove that the
# shared transaction rolls back both tables rather than leaving a half-save.
failed_both_payload=$work_dir/matrix-save-both-failed.txt
write_matrix_payload \
    "$failed_both_payload" "$matrix_csrf" "$blank_position" \
    save_matrix_and_standard "$original_matrix" E2ERBK
active_before_failed_save=$work_dir/active-before-failed-save.txt
standard_before_failed_save=$work_dir/standard-before-failed-save.txt
active_after_failed_save=$work_dir/active-after-failed-save.txt
standard_after_failed_save=$work_dir/standard-after-failed-save.txt
exact_matrix_snapshot >"$active_before_failed_save"
exact_standard_matrix_snapshot >"$standard_before_failed_save"
db_sql <<SQL
DROP TRIGGER IF EXISTS \`${rollback_trigger}\`;
CREATE TRIGGER \`${rollback_trigger}\`
BEFORE INSERT ON \`nv_empfmtx_standard\`
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'eStab intentional standard matrix rollback proof';
SQL
assert_status 500 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@$failed_both_payload" \
    "$base_url/4fadm/make_fkt.php"
assert_body 'Aktive Empfängermatrix und Standardmatrix konnten nicht atomar gespeichert werden.'
assert_body 'value="E2ERBK"'
db_sql <<SQL
DROP TRIGGER IF EXISTS \`${rollback_trigger}\`;
SQL
exact_matrix_snapshot >"$active_after_failed_save"
exact_standard_matrix_snapshot >"$standard_after_failed_save"
cmp -s "$active_before_failed_save" "$active_after_failed_save" || {
    echo 'Admin HTTP: failed combined save did not roll back the active matrix exactly' >&2
    exit 1
}
cmp -s "$standard_before_failed_save" "$standard_after_failed_save" || {
    echo 'Admin HTTP: failed combined save did not preserve the standard matrix exactly' >&2
    exit 1
}
matrix_audit_after_failed_save=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_protokoll\`
 WHERE \`p_lfd\` > ${audit_floor}
   AND \`p_was\` = 'Empfängermatrix';
SQL
)
if [ "$matrix_audit_after_failed_save" != "$matrix_audit_after_load" ]; then
    echo 'Admin HTTP: failed combined save retained an audit row' >&2
    exit 1
fi

assert_status 303 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@$save_both_payload" \
    "$base_url/4fadm/make_fkt.php"
normalized_matrix >"$restored_matrix"
cmp -s "$active_before_load" "$restored_matrix" || {
    echo 'Admin HTTP: combined save changed the submitted active matrix' >&2
    exit 1
}
normalized_standard_matrix >"$observed_standard_matrix"
cmp -s "$active_before_load" "$observed_standard_matrix" || {
    echo 'Admin HTTP: combined save did not persist an exact standard copy' >&2
    exit 1
}
changed_standard_cell=$(db_sql <<SQL
SELECT CONCAT(\`mtx_fkt\`, '|', \`mtx_rolle\`, '|',
              IF(\`mtx_rc2\` IN ('t','1'), '1', '0'), '|',
              IF(\`mtx_auto\` IN ('t','1'), '1', '0'))
  FROM \`nv_empfmtx_standard\`
 WHERE CONCAT(\`mtx_x\`, \`mtx_y\`) = '${blank_position}';
SQL
)
if [ "$changed_standard_cell" != 'E2EADM|FB|0|0' ]; then
    echo 'Admin HTTP: combined save lost a standard matrix flag' >&2
    exit 1
fi

# Loading the newly stored preset must now render that exact changed cell while
# remaining free of database mutations.
assert_status 200 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST \
    --data-urlencode "csrf_token=$matrix_csrf" \
    --data-urlencode 'admin_action=load_standard' \
    "$base_url/4fadm/make_fkt.php"
assert_body 'value="E2EADM"'
assert_body 'data-estab-dirty-initial'

# Restore the active matrix first. The active-only action must leave the
# changed standard untouched.
restore_payload=$work_dir/matrix-restore-active.txt
write_matrix_payload "$restore_payload" "$matrix_csrf"
assert_status 303 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@$restore_payload" \
    "$base_url/4fadm/make_fkt.php"
normalized_matrix >"$restored_matrix"
cmp -s "$original_matrix" "$restored_matrix" || {
    echo 'Admin HTTP: active matrix roundtrip did not restore all 20 cells' >&2
    exit 1
}
normalized_standard_matrix >"$observed_standard_matrix"
cmp -s "$active_before_load" "$observed_standard_matrix" || {
    echo 'Admin HTTP: active-only restore changed the saved standard matrix' >&2
    exit 1
}

# Restore potentially different original active and standard states through
# the public actions, so the test is safe beyond the fresh default fixture.
restore_standard_payload=$work_dir/matrix-restore-standard.txt
write_matrix_payload \
    "$restore_standard_payload" "$matrix_csrf" "" \
    save_matrix_and_standard "$original_standard_matrix"
assert_status 303 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@$restore_standard_payload" \
    "$base_url/4fadm/make_fkt.php"
normalized_standard_matrix >"$observed_standard_matrix"
cmp -s "$original_standard_matrix" "$observed_standard_matrix" || {
    echo 'Admin HTTP: standard matrix roundtrip did not restore all 20 cells' >&2
    exit 1
}
write_matrix_payload "$restore_payload" "$matrix_csrf"
assert_status 303 --config "$admin_curl_config" \
    --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
    --request POST --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@$restore_payload" \
    "$base_url/4fadm/make_fkt.php"
normalized_matrix >"$restored_matrix"
cmp -s "$original_matrix" "$restored_matrix" || {
    echo 'Admin HTTP: final active matrix restore is incomplete' >&2
    exit 1
}

# Two independent PHP sessions submit the same next number concurrently. The
# database lock permits exactly one increase and rejects the stale request.
if [ "$counter_before" -ge 999999999 ]; then
    echo 'Admin HTTP: disposable counter has exhausted the supported range' >&2
    exit 1
fi
counter_target=$((counter_before + 1))
counter_message_rows_before=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_nachrichten\`
 WHERE \`einsatz_id\` = ${active_incident_id};
SQL
)
counter_open_rows_before=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_nachrichten\`
 WHERE \`einsatz_id\` = ${active_incident_id}
   AND \`x00_status\` <> 8;
SQL
)

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
counter_message_state=$(db_sql <<SQL
SELECT CONCAT(
         COUNT(*), '|',
         SUM(CASE WHEN \`x00_status\` <> 8 THEN 1 ELSE 0 END)
       )
  FROM \`nv_nachrichten\`
 WHERE \`einsatz_id\` = ${active_incident_id};
SQL
)
if [ "$counter_message_state" != "${counter_message_rows_before}|${counter_open_rows_before}" ]; then
    echo 'Admin HTTP: counter repair created or changed a fachliche message blocker' >&2
    exit 1
fi
counter_evidence=$(db_sql <<SQL
SELECT CONCAT(
         COUNT(*), '|',
         COALESCE(MAX(JSON_UNQUOTE(JSON_EXTRACT(
           \`details\`, '$.numbering_mode'
         ))), ''), '|',
         COALESCE(MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(
           \`details\`, '$.after.ea_nummer'
         )) AS UNSIGNED)), 0), '|',
         COALESCE(MAX(CASE
           WHEN \`ereignis_hash\` = (
             SELECT \`letzter_hash\`
               FROM \`nv_betriebsereignis_kopf\`
              WHERE \`einsatz_id\` = ${active_incident_id}
           )
           THEN 1 ELSE 0 END), 0)
       )
  FROM \`nv_betriebsereignisse\`
 WHERE \`einsatz_id\` = ${active_incident_id}
   AND \`objekttyp\` = 'DIENSTSCHICHT'
   AND \`aktion\` = 'message_counter_repaired'
   AND CAST(JSON_UNQUOTE(JSON_EXTRACT(
         \`details\`, '$.after.ea_nummer'
       )) AS UNSIGNED) = ${counter_target};
SQL
)
if [ "$counter_evidence" != "1|gemeinsam|${counter_target}|1" ]; then
    printf 'Admin HTTP: counter repair lacks one head-linked evidence event: %s\n' \
        "$counter_evidence" >&2
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
if [ "$audit_count" -lt 7 ]; then
    echo 'Admin HTTP: prepared audit records are incomplete' >&2
    exit 1
fi
matrix_audit_count=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_protokoll\`
 WHERE \`p_lfd\` > ${audit_floor}
   AND \`p_was\` = 'Empfängermatrix';
SQL
)
if [ "$matrix_audit_count" != 5 ]; then
    echo 'Admin HTTP: matrix load/save audit boundary is not exact' >&2
    exit 1
fi
scoped_admin_audit_count=$(db_sql <<SQL
SELECT COUNT(*) FROM \`nv_protokoll\`
 WHERE \`p_lfd\` > ${audit_floor}
   AND \`p_was\` IN ('Nachrichtennummer Sync', 'Grafikstatus Reset')
   AND \`einsatz_id\` = ${active_incident_id};
SQL
)
if [ "$scoped_admin_audit_count" != 2 ]; then
    echo 'Admin HTTP: operational admin audit is not bound to the active incident' >&2
    exit 1
fi

echo 'Admin workflows HTTP integration: OK'
