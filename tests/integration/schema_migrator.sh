#!/bin/sh
set -eu

: "${ESTAB_DB_HOST:=db}"
: "${ESTAB_DB_PORT:=3306}"
: "${ESTAB_DB_ROOT_PASSWORD_FILE:=/run/secrets/estab_db_root_password}"
: "${ESTAB_MIGRATOR_BIN:=/usr/local/bin/estab-migrate}"
: "${ESTAB_SCHEMA_BASELINE_FILE:=/opt/estab/schema/10-schema.sql}"
: "${ESTAB_MIGRATIONS_DIR:=/opt/estab/migrations}"
export ESTAB_SCHEMA_VERIFY_FILE=

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
fixture="$script_dir/../fixtures/legacy-runtime-schema.sql"
standard_matrix_fixture="$script_dir/../fixtures/recipient-matrix-standard.txt"
test_database="estab_migration_test_$$"
fresh_database="estab_fresh_default_test_$$"
retry_database="estab_baseline_retry_test_$$"
guard_database="estab_baseline_guard_test_$$"
collision_database="estab_standard_collision_test_$$"
incident_guard_database="estab_incident_guard_test_$$"
predecessor_database="estab_incident_predecessor_test_$$"
logbook_upgrade_database="estab_logbook_upgrade_test_$$"
incident_predecessor_checksum="6732e9c87f0532fce41ee9a58658bf4888fdf7c2ced1ed6bad75a756d6e08edf"
command_post_predecessor_checksum="68a32692bf90d6987539e36076f0ecfea32f46ca870c06efc55ebaef4d75a1c4"

for database_name in \
    "$test_database" "$fresh_database" "$retry_database" "$guard_database" \
    "$collision_database" \
    "$incident_guard_database" "$predecessor_database" \
    "$logbook_upgrade_database"
do
    case "$database_name" in
        *[!A-Za-z0-9_]*)
            echo "schema migrator test: unsafe fixture database name" >&2
            exit 1
            ;;
    esac
done
if [ ! -r "$fixture" ] \
    || [ ! -r "$standard_matrix_fixture" ] \
    || [ ! -r "$ESTAB_SCHEMA_BASELINE_FILE" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/20-nullable-dates.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/30-runtime-schema.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/40-recipient-matrix-standard.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/45-global-incidents-prepare.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/50-global-incidents.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/55-global-incidents-finish.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/70-user-account-blocking.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/80-dv-evidence-retention.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/94-dv-organisational-controls.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/95-attachment-ingest-integrity.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/96-etb-duty-function.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/97-incident-command-post-name.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/98-official-message-form-fields.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/99-message-list-search.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/100-session-presence.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/110-etb-tbb-rules.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/111-logbook-shift-assignment.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/112-optional-access-shifts.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/113-password-policy.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/114-self-registration-policy.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/115-incident-permission-mode.sql" ] \
    || [ ! -r "$ESTAB_MIGRATIONS_DIR/116-standard-categories.sql" ] \
    || [ ! -x "$ESTAB_MIGRATOR_BIN" ]; then
    echo "schema migrator test: fixture, baseline, or migrator is unavailable" >&2
    exit 1
fi
if [ ! -r "$ESTAB_DB_ROOT_PASSWORD_FILE" ]; then
    echo "schema migrator test: root secret is unavailable" >&2
    exit 1
fi

umask 077
client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-migration-test-client.XXXXXX")
failure_log=$(mktemp "${TMPDIR:-/tmp}/estab-migration-test-failure.XXXXXX")
concurrency_log=$(mktemp "${TMPDIR:-/tmp}/estab-logbook-concurrency.XXXXXX")
pre_110_migrations=$(mktemp -d "${TMPDIR:-/tmp}/estab-pre-110-migrations.XXXXXX")

for migration_path in "$ESTAB_MIGRATIONS_DIR"/*.sql; do
    case "$(basename "$migration_path")" in
        110-etb-tbb-rules.sql|111-logbook-shift-assignment.sql|112-optional-access-shifts.sql|113-password-policy.sql|114-self-registration-policy.sql|115-incident-permission-mode.sql|116-standard-categories.sql)
            continue
            ;;
    esac
    cp "$migration_path" "$pre_110_migrations/"
done

escape_option_value()
{
    sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

root_password=$(tr -d '\r\n' < "$ESTAB_DB_ROOT_PASSWORD_FILE")
escaped_password=$(printf '%s' "$root_password" | escape_option_value)
unset root_password
{
    printf '[client]\n'
    printf 'host=%s\n' "$ESTAB_DB_HOST"
    printf 'port=%s\n' "$ESTAB_DB_PORT"
    printf 'user=root\n'
    printf 'password="%s"\n' "$escaped_password"
    printf 'protocol=tcp\n'
    printf 'default-character-set=utf8mb4\n'
} > "$client_defaults"
unset escaped_password
chmod 0600 "$client_defaults"

admin_query()
{
    mariadb \
        --defaults-extra-file="$client_defaults" \
        --batch \
        --skip-column-names \
        --raw \
        --execute="$1"
}

fixture_query()
{
    database_query "$test_database" "$1"
}

database_query()
{
    database_name=$1
    sql=$2
    mariadb \
        --defaults-extra-file="$client_defaults" \
        --batch \
        --skip-column-names \
        --raw \
        --database="$database_name" \
        --execute="$sql"
}

message_index_snapshot()
{
    fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*)
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_nachrichten'
             AND index_name = 'ft_nachrichten_inhalt'), '|',
         COALESCE((
           SELECT CONCAT(
                    COUNT(*), ':', MIN(index_type), ':', MAX(non_unique),
                    ':', SUM(sub_part IS NULL), ':',
                    GROUP_CONCAT(
                      column_name ORDER BY seq_in_index SEPARATOR ','
                    )
                  )
             FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'nv_nachrichten'
              AND index_name = 'ft_nachrichten_suche'
         ), ''), '|',
         COALESCE((
           SELECT CONCAT(
                    COUNT(*), ':', MIN(index_type), ':', MAX(non_unique),
                    ':', SUM(sub_part IS NULL), ':',
                    GROUP_CONCAT(
                      column_name ORDER BY seq_in_index SEPARATOR ','
                    )
                  )
             FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'nv_nachrichten'
              AND index_name = 'idx_nachrichten_einsatz_status_zeit'
         ), ''), '|',
         COALESCE((
           SELECT CONCAT(
                    COUNT(*), ':', MIN(index_type), ':', MAX(non_unique),
                    ':', SUM(sub_part IS NULL), ':',
                    GROUP_CONCAT(
                      column_name ORDER BY seq_in_index SEPARATOR ','
                    )
                  )
             FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'nv_nachrichten'
              AND index_name = 'idx_nachrichten_einsatz_richtung_nummer'
         ), ''), '|',
         (SELECT COUNT(*)
            FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name IN (
               'estab_migrate_99_preflight',
               'estab_migrate_99_add_search',
               'estab_migrate_99_drop_legacy_search',
               'estab_migrate_99_add_status_time',
               'estab_migrate_99_add_direction_number',
               'estab_migrate_99_validate'
             ))
       )"
}

cleanup()
{
    status=$?
    trap - EXIT HUP INT TERM
    admin_query "
DROP DATABASE IF EXISTS \`$test_database\`;
DROP DATABASE IF EXISTS \`$fresh_database\`;
DROP DATABASE IF EXISTS \`$retry_database\`;
DROP DATABASE IF EXISTS \`$guard_database\`;
DROP DATABASE IF EXISTS \`$collision_database\`;
DROP DATABASE IF EXISTS \`$incident_guard_database\`;
DROP DATABASE IF EXISTS \`$predecessor_database\`;
DROP DATABASE IF EXISTS \`$logbook_upgrade_database\`" >/dev/null 2>&1 || true
    rm -f -- "$client_defaults" "$failure_log" "$concurrency_log"
    rm -f -- "$pre_110_migrations"/*.sql
    rmdir "$pre_110_migrations" 2>/dev/null || true
    exit "$status"
}
trap cleanup EXIT HUP INT TERM

assert_equal()
{
    expected=$1
    actual=$2
    message=$3
    if [ "$actual" != "$expected" ]; then
        printf 'schema migrator test: %s (expected %s, got %s)\n' \
            "$message" "$expected" "$actual" >&2
        exit 1
    fi
}

assert_equal "ON|ON" "$(admin_query "
SELECT CONCAT(@@GLOBAL.innodb_snapshot_isolation, '|',
              @@SESSION.innodb_snapshot_isolation)")" \
    "MariaDB default snapshot isolation is not enabled for concurrency tests"

# Simulate a real upgrade in which every released migration through 100 is
# already recorded with its immutable checksum. Migration 110 must reject an
# ambiguous historic attachment link before changing the schema, then upgrade
# cleanly once the operator resolves that ambiguity without rewriting history.
assert_equal "$command_post_predecessor_checksum" "$(
    sha256sum "$ESTAB_MIGRATIONS_DIR/97-incident-command-post-name.sql" |
        awk '{print $1}'
)" \
    "immutable command-post migration 97 no longer has its released checksum"
admin_query "
DROP DATABASE IF EXISTS \`$logbook_upgrade_database\`;
CREATE DATABASE \`$logbook_upgrade_database\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mariadb \
    --defaults-extra-file="$client_defaults" \
    --database="$logbook_upgrade_database" \
    < "$ESTAB_SCHEMA_BASELINE_FILE"
ESTAB_DB_NAME="$logbook_upgrade_database" \
ESTAB_MIGRATIONS_DIR="$pre_110_migrations" "$ESTAB_MIGRATOR_BIN"
assert_equal "15|15|$command_post_predecessor_checksum|3" "$(
    database_query "$logbook_upgrade_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE state = 'applied'), '|',
         (SELECT checksum FROM estab_schema_migrations
           WHERE version = '97-incident-command-post-name.sql'), '|',
         (SELECT COUNT(*) FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name IN (
               'estab_incident_for_insert', 'estab_incident_for_update',
               'estab_incident_for_delete'
             ) AND routine_definition NOT LIKE '%FOR UPDATE%')
       )"
)" \
    "pre-110 upgrade fixture does not preserve the released migration ledger"
pre_110_ledger_snapshot=$(
    database_query "$logbook_upgrade_database" "
SELECT GROUP_CONCAT(CONCAT(version, ':', checksum, ':', state)
                    ORDER BY version SEPARATOR ',')
  FROM estab_schema_migrations"
)
database_query "$logbook_upgrade_database" "
INSERT INTO nv_einsaetze
  (kennung, name, beginn, ende, ort, organisation, fuehrungsstellenname,
   einsatzleitung, beschreibung, metadaten, erstellt_am, erstellt_von)
VALUES
  ('SCHEMA-ATTACHMENT-UPGRADE', 'Attachment upgrade fixture', NOW(), NULL,
   '', '', 'Upgrade command post', '',
   'Ambiguous attachment evidence must block migration 110.', '{}', NOW(6),
   'schema-migrator-test');
SET @upgrade_incident_id = LAST_INSERT_ID();
UPDATE nv_einsatz_status
   SET active_einsatz_id = @upgrade_incident_id,
       revision = revision + 1,
       geaendert_am = NOW(6),
       geaendert_von = 'schema-migrator-test'
 WHERE singleton_id = 1;
INSERT INTO nv_anhang (filename, org_filename, kuerzel, status)
VALUES ('SCHEMA-ATTACHMENT-UPGRADE', 'upgrade-evidence.txt', 'upg', 4);
SET @upgrade_attachment_id = LAST_INSERT_ID();
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_attachment_id)
VALUES
  (NOW(), 'Erster Anlagenbezug', '', 'Schema Test', 'upg', 'S2',
   NOW(6), 'ohne', @upgrade_attachment_id),
  (NOW(), 'Mehrdeutiger Anlagenbezug', '', 'Schema Test', 'upg', 'S2',
   NOW(6), 'ohne', @upgrade_attachment_id)"
if ESTAB_DB_NAME="$logbook_upgrade_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: duplicate historic ETB attachment was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Logbook rules migration blocked: duplicate ETB attachment link' \
    "$failure_log"; then
    echo "schema migrator test: duplicate ETB attachment failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "0|0|0" "$(database_query "$logbook_upgrade_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '110-etb-tbb-rules.sql'), '|',
         (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_logbuch_koepfe'), '|',
         (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
             AND column_name = 'estab_book_lfd')
       )")" \
    "blocked attachment upgrade changed schema or migration history"

# Test-only operator resolution: remove one ambiguous legacy reference. The
# released delete trigger is deliberately dropped only in this isolated DB;
# migration 110 recreates the canonical append-only trigger during upgrade.
database_query "$logbook_upgrade_database" "
DROP TRIGGER estab_etb_bd_einsatz;
DELETE FROM nv_etb
 WHERE \`etb_lfd-nr\` = (
   SELECT duplicate_id FROM (
     SELECT MAX(\`etb_lfd-nr\`) AS duplicate_id FROM nv_etb
      WHERE estab_attachment_id = (
        SELECT \`lfd-nr\` FROM nv_anhang
         WHERE filename = 'SCHEMA-ATTACHMENT-UPGRADE'
      )
   ) AS duplicate_row
 )"
ESTAB_DB_NAME="$logbook_upgrade_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$logbook_upgrade_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "$pre_110_ledger_snapshot" "$(
    database_query "$logbook_upgrade_database" "
SELECT GROUP_CONCAT(CONCAT(version, ':', checksum, ':', state)
                    ORDER BY version SEPARATOR ',')
 FROM estab_schema_migrations
 WHERE version NOT IN (
   '110-etb-tbb-rules.sql', '111-logbook-shift-assignment.sql',
   '112-optional-access-shifts.sql', '113-password-policy.sql',
   '114-self-registration-policy.sql', '115-incident-permission-mode.sql',
   '116-standard-categories.sql'
 )"
)" \
    "migration 110 upgrade rewrote a released migration ledger row"
assert_equal "1|1|1|1|1|1|1|1|3|1|2|1" "$(database_query "$logbook_upgrade_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '110-etb-tbb-rules.sql' AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '111-logbook-shift-assignment.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '112-optional-access-shifts.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '113-password-policy.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '114-self-registration-policy.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '115-incident-permission-mode.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '116-standard-categories.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
             AND index_name = 'uq_etb_attachment_id'
             AND non_unique = 0 AND column_name = 'estab_attachment_id'), '|',
         (SELECT COUNT(*) FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name IN (
               'estab_incident_for_insert', 'estab_incident_for_update',
               'estab_incident_for_delete'
             ) AND routine_definition LIKE '%FOR UPDATE%'), '|',
         (SELECT COUNT(*) FROM nv_etb
           WHERE estab_attachment_id = (
             SELECT \`lfd-nr\` FROM nv_anhang
              WHERE filename = 'SCHEMA-ATTACHMENT-UPGRADE'
           )), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-ATTACHMENT-UPGRADE'
           ) AND buchart = 'ETB'), '|',
         (SELECT COUNT(*) FROM nv_etb
           WHERE estab_shift_id IS NULL
             AND estab_writer_assignment_id IS NULL
             AND estab_assignee_assignment_id IS NULL
             AND estab_assignment IS NULL)
       )")" \
    "logbook upgrade omitted immutable-ledger, locking, history, or attachment rules"

# A running shift may gain a genuinely new function, but a function that was
# already occupied in that shift cannot be replaced or reoccupied later.
database_query "$logbook_upgrade_database" "
INSERT INTO nv_benutzer
  (benutzer, kuerzel, funktion, rolle, aktiv, password)
VALUES
  ('Upgrade S2', 's2a', 'S2', 'Stab', 1, ''),
  ('Upgrade ETB', 'etba', 'ETB', 'Stab', 1, ''),
  ('Upgrade Si', 'sia', 'Si', 'Stab', 1, ''),
  ('Upgrade S6', 's6a', 'S6', 'Stab', 1, ''),
  ('Upgrade LdF', 'ldfa', 'LdF', 'Fernmelder', 1, ''),
  ('Upgrade A/W', 'awa', 'A/W', 'Fernmelder', 1, ''),
  ('Additional A/W', 'awb', 'A/W', 'Fernmelder', 1, ''),
  ('Upgrade S3', 's3a', 'S3', 'Stab', 1, ''),
  ('Replacement S2', 's2b', 'S2', 'Stab', 1, '');
INSERT INTO nv_dienstschichten
  (einsatz_id, nummer, bezeichnung, status, erstellt_von)
SELECT einsatz_id, 1, 'Upgrade shift', 'GEPLANT', 'schema-migrator-test'
  FROM nv_einsaetze WHERE kennung = 'SCHEMA-ATTACHMENT-UPGRADE';
SET @upgrade_shift_id = LAST_INSERT_ID();
INSERT INTO nv_dienstbesetzungen
  (dienstschicht_id, benutzer_kuerzel, funktion, rolle, status,
   zugewiesen_von)
VALUES
  (@upgrade_shift_id, 's2a', 'S2', 'Stab', 'ZUGEWIESEN', 'schema-test'),
  (@upgrade_shift_id, 'etba', 'ETB', 'Stab', 'ZUGEWIESEN', 'schema-test'),
  (@upgrade_shift_id, 'sia', 'Si', 'Stab', 'ZUGEWIESEN', 'schema-test'),
  (@upgrade_shift_id, 's6a', 'S6', 'Stab', 'ZUGEWIESEN', 'schema-test'),
  (@upgrade_shift_id, 'ldfa', 'LdF', 'Fernmelder', 'ZUGEWIESEN', 'schema-test'),
  (@upgrade_shift_id, 'awa', 'A/W', 'Fernmelder', 'ZUGEWIESEN', 'schema-test');
UPDATE nv_dienstbesetzungen
   SET status = 'ANGENOMMEN', angenommen_am = NOW(6)
 WHERE dienstschicht_id = @upgrade_shift_id;
UPDATE nv_dienstschichten
   SET status = 'AKTIV', aktiviert_am = NOW(6)
 WHERE dienstschicht_id = @upgrade_shift_id;
INSERT INTO nv_dienstbesetzungen
  (dienstschicht_id, benutzer_kuerzel, funktion, rolle, status,
   zugewiesen_von)
VALUES
  (@upgrade_shift_id, 's3a', 'S3', 'Stab', 'ZUGEWIESEN', 'schema-test');
INSERT INTO nv_dienstbesetzungen
  (dienstschicht_id, benutzer_kuerzel, funktion, rolle, status,
   zugewiesen_von)
VALUES
  (@upgrade_shift_id, 'awb', 'A/W', 'Fernmelder', 'ZUGEWIESEN', 'schema-test');
UPDATE nv_dienstbesetzungen
   SET status = 'ABGELOEST', abgeloest_am = NOW(6)
 WHERE dienstschicht_id = @upgrade_shift_id
   AND BINARY funktion = BINARY 'S2'"
upgrade_shift_id=$(database_query "$logbook_upgrade_database" "
SELECT dienstschicht_id FROM nv_dienstschichten
 WHERE bezeichnung = 'Upgrade shift'")
if database_query "$logbook_upgrade_database" "
INSERT INTO nv_dienstbesetzungen
  (dienstschicht_id, benutzer_kuerzel, funktion, rolle, status,
   zugewiesen_von)
VALUES
  ($upgrade_shift_id, 's2b', 'S2', 'Stab', 'ZUGEWIESEN', 'schema-test')" \
    >"$failure_log" 2>&1; then
    echo "schema migrator test: active-shift function replacement was accepted" >&2
    exit 1
fi
if ! grep -q 'Active duty shift function was already assigned' \
    "$failure_log"; then
    echo "schema migrator test: active-shift reuse failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "AKTIV|8|1|0|1|2" "$(
    database_query "$logbook_upgrade_database" "
SELECT CONCAT(
         shift_row.status, '|',
         (SELECT COUNT(*) FROM nv_dienstbesetzungen
           WHERE dienstschicht_id = shift_row.dienstschicht_id), '|',
         (SELECT COUNT(*) FROM nv_dienstbesetzungen
           WHERE dienstschicht_id = shift_row.dienstschicht_id
             AND BINARY funktion = BINARY 'S3'), '|',
         (SELECT COUNT(*) FROM nv_dienstbesetzungen
           WHERE dienstschicht_id = shift_row.dienstschicht_id
             AND BINARY benutzer_kuerzel = BINARY 's2b'), '|',
         (SELECT COUNT(*) FROM nv_dienstbesetzungen
           WHERE dienstschicht_id = shift_row.dienstschicht_id
             AND BINARY funktion = BINARY 'S2'
             AND status = 'ABGELOEST'), '|',
         (SELECT COUNT(*) FROM nv_dienstbesetzungen
           WHERE dienstschicht_id = shift_row.dienstschicht_id
             AND BINARY funktion = BINARY 'A/W')
       )
  FROM nv_dienstschichten AS shift_row
 WHERE shift_row.bezeichnung = 'Upgrade shift'"
)" \
    "active-shift extension or no-replacement evidence is incomplete"

if database_query "$logbook_upgrade_database" "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_attachment_id,
   estab_shift_id, estab_writer_assignment_id)
SELECT NOW(), 'Doppelter Anlagenbezug', '', 'Upgrade ETB', 'etba', 'ETB',
       NOW(6), 'ohne', \`lfd-nr\`, $upgrade_shift_id,
       (SELECT dienstbesetzung_id FROM nv_dienstbesetzungen
         WHERE dienstschicht_id = $upgrade_shift_id
           AND BINARY benutzer_kuerzel = BINARY 'etba')
  FROM nv_anhang
 WHERE filename = 'SCHEMA-ATTACHMENT-UPGRADE'" >"$failure_log" 2>&1; then
    echo "schema migrator test: duplicate ETB attachment link was accepted" >&2
    exit 1
fi
if ! grep -Eq 'Duplicate entry|uq_etb_attachment_id' "$failure_log"; then
    echo "schema migrator test: ETB attachment uniqueness failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "1|2" "$(database_query "$logbook_upgrade_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_etb
           WHERE estab_attachment_id = (
             SELECT \`lfd-nr\` FROM nv_anhang
              WHERE filename = 'SCHEMA-ATTACHMENT-UPGRADE'
           )), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-ATTACHMENT-UPGRADE'
           ) AND buchart = 'ETB')
       )")" \
    "rejected duplicate attachment changed ETB evidence or its local counter"

baseline_checksum=$(sha256sum "$ESTAB_SCHEMA_BASELINE_FILE" | awk '{print $1}')
self_registration_checksum=$(
    sha256sum "$ESTAB_MIGRATIONS_DIR/114-self-registration-policy.sql" |
        awk '{print $1}'
)

# A genuinely empty installation is identified durably while its embedded
# baseline is being applied. Even an explicitly true legacy compatibility
# environment must not open account creation on that new installation.
admin_query "
DROP DATABASE IF EXISTS \`$fresh_database\`;
CREATE DATABASE \`$fresh_database\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
ESTAB_ALLOW_SELF_REGISTRATION=true \
ESTAB_DB_NAME="$fresh_database" "$ESTAB_MIGRATOR_BIN"
fresh_standard_category_snapshot=$(database_query "$fresh_database" "
SELECT GROUP_CONCAT(
         CONCAT(lfd, ':', HEX(kategorie))
         ORDER BY lfd SEPARATOR ','
       )
  FROM nv_masterkatego")
assert_equal "Allgemein,EA1,EA2,EA3,EA4,EA5,EA6" "$(
    database_query "$fresh_database" "
SELECT GROUP_CONCAT(kategorie ORDER BY BINARY kategorie SEPARATOR ',')
  FROM nv_masterkatego"
)" \
    "fresh installation did not receive exact standard categories"
assert_equal "7|7|1|22" "$(database_query "$fresh_database" "
SELECT CONCAT(
         COUNT(*), '|', COUNT(DISTINCT BINARY kategorie), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '116-standard-categories.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$')
       )
  FROM nv_masterkatego")" \
    "fresh standard categories or their migration ledger are incomplete"
ESTAB_ALLOW_SELF_REGISTRATION=true \
ESTAB_DB_NAME="$fresh_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "$fresh_standard_category_snapshot" "$(
    database_query "$fresh_database" "
SELECT GROUP_CONCAT(
         CONCAT(lfd, ':', HEX(kategorie))
         ORDER BY lfd SEPARATOR ','
       )
  FROM nv_masterkatego"
)" \
    "second fresh migration run duplicated or changed standard categories"
assert_equal "2|1|1|1|DISABLED|1|1|fresh-install" "$(
    database_query "$fresh_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_baselines), '|',
         (SELECT COUNT(*) FROM estab_schema_baselines
           WHERE version = '10-schema.sql'
             AND checksum = '$baseline_checksum'
             AND state = 'applied'
             AND applied_at IS NOT NULL), '|',
         (SELECT COUNT(*) FROM estab_schema_baselines
           WHERE version = '114-self-registration-fresh-default'
             AND checksum = '$self_registration_checksum'
             AND state = 'applied'
             AND applied_at IS NOT NULL), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '114-self-registration-policy.sql'
             AND checksum = '$self_registration_checksum'
             AND state = 'applied'), '|',
         (SELECT mode FROM nv_selbstregistrierung
           WHERE singleton_id = 1), '|',
         (SELECT enabled_until_utc IS NULL FROM nv_selbstregistrierung
           WHERE singleton_id = 1), '|',
         (SELECT revision FROM nv_selbstregistrierung
           WHERE singleton_id = 1), '|',
         (SELECT updated_by FROM nv_selbstregistrierung
           WHERE singleton_id = 1)
       )"
)" \
    "fresh installation with environment opt-in did not start disabled"

# Reproduce process loss after migration 114 but before the final multi-table
# UPDATE. The retained applying marker and pristine singleton must converge in
# one retry and remain idempotent on the next run.
database_query "$fresh_database" "
UPDATE estab_schema_baselines
   SET state = 'applying', applied_at = NULL
 WHERE version = '114-self-registration-fresh-default';
UPDATE nv_selbstregistrierung
   SET mode = 'ENVIRONMENT',
       enabled_until_utc = NULL,
       revision = 0,
       updated_at = UTC_TIMESTAMP(6),
       updated_by = 'migration-114'
 WHERE singleton_id = 1"
ESTAB_ALLOW_SELF_REGISTRATION=true \
ESTAB_DB_NAME="$fresh_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_ALLOW_SELF_REGISTRATION=true \
ESTAB_DB_NAME="$fresh_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|DISABLED|1|1|fresh-install" "$(
    database_query "$fresh_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_baselines
           WHERE version = '114-self-registration-fresh-default'
             AND checksum = '$self_registration_checksum'
             AND state = 'applied'
             AND applied_at IS NOT NULL), '|',
         mode, '|', enabled_until_utc IS NULL, '|', revision, '|', updated_by
       )
  FROM nv_selbstregistrierung
 WHERE singleton_id = 1"
)" \
    "interrupted fresh-install default was not completed safely"

# A same-name marker with any other checksum is foreign state. It must remain
# untouched and block startup before the already secure policy is rewritten.
database_query "$fresh_database" "
UPDATE estab_schema_baselines
   SET checksum = REPEAT('0', 64)
 WHERE version = '114-self-registration-fresh-default'"
if ESTAB_ALLOW_SELF_REGISTRATION=true ESTAB_DB_NAME="$fresh_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: manipulated fresh-default checksum was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Checksum mismatch for fresh-install default marker' \
    "$failure_log"; then
    echo "schema migrator test: fresh-default checksum failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "$(printf '%064d' 0)|applied|DISABLED|1|fresh-install" "$(
    database_query "$fresh_database" "
SELECT CONCAT(
         (SELECT CONCAT(checksum, '|', state)
            FROM estab_schema_baselines
           WHERE version = '114-self-registration-fresh-default'), '|',
         mode, '|', revision, '|', updated_by
       )
  FROM nv_selbstregistrierung
 WHERE singleton_id = 1"
)" \
    "blocked fresh-default checksum manipulation changed marker or policy"
database_query "$fresh_database" "
UPDATE estab_schema_baselines
   SET checksum = '$self_registration_checksum'
 WHERE version = '114-self-registration-fresh-default'"

# An applied marker cannot coexist with the pristine upgrade-compatible row.
# Treat that impossible combination as manipulation instead of silently
# replaying or trusting it.
database_query "$fresh_database" "
UPDATE nv_selbstregistrierung
   SET mode = 'ENVIRONMENT',
       revision = 0,
       updated_at = UTC_TIMESTAMP(6),
       updated_by = 'migration-114'
 WHERE singleton_id = 1"
if ESTAB_ALLOW_SELF_REGISTRATION=true ESTAB_DB_NAME="$fresh_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: inconsistent applied fresh-default marker was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Applied fresh-install default marker is inconsistent' \
    "$failure_log"; then
    echo "schema migrator test: inconsistent fresh-default marker failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "applied|ENVIRONMENT|0|migration-114" "$(
    database_query "$fresh_database" "
SELECT CONCAT(
         (SELECT state FROM estab_schema_baselines
           WHERE version = '114-self-registration-fresh-default'), '|',
         mode, '|', revision, '|', updated_by
       )
  FROM nv_selbstregistrierung
 WHERE singleton_id = 1"
)" \
    "blocked fresh-default marker inconsistency changed marker or policy"
database_query "$fresh_database" "
UPDATE nv_selbstregistrierung
   SET mode = 'DISABLED',
       revision = 1,
       updated_at = UTC_TIMESTAMP(6),
       updated_by = 'fresh-install'
 WHERE singleton_id = 1"
ESTAB_DB_NAME="$fresh_database" "$ESTAB_MIGRATOR_BIN"

admin_query "
DROP DATABASE IF EXISTS \`$retry_database\`;
CREATE DATABASE \`$retry_database\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
database_query "$retry_database" "
CREATE TABLE estab_schema_baselines (
  version VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state ENUM('applying','applied') NOT NULL,
  started_at DATETIME(6) NOT NULL,
  applied_at DATETIME(6) NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO estab_schema_baselines
  (version, checksum, state, started_at, applied_at)
VALUES
  ('10-schema.sql', '$baseline_checksum', 'applying', NOW(6), NULL)"
awk '
    /^CREATE TABLE IF NOT EXISTS `nv_nachrichten`/ { copying = 1 }
    copying { print }
    copying && /ENGINE=InnoDB.*;$/ { exit }
' "$ESTAB_SCHEMA_BASELINE_FILE" |
    mariadb \
        --defaults-extra-file="$client_defaults" \
        --database="$retry_database"

ESTAB_ALLOW_SELF_REGISTRATION=true \
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_ALLOW_SELF_REGISTRATION=true \
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|1|DISABLED|1|fresh-install" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_baselines
           WHERE version = '10-schema.sql'
             AND checksum = '$baseline_checksum'
             AND state = 'applied'
             AND applied_at IS NOT NULL), '|',
         (SELECT COUNT(*) FROM estab_schema_baselines
           WHERE version = '114-self-registration-fresh-default'
             AND checksum = '$self_registration_checksum'
             AND state = 'applied'
             AND applied_at IS NOT NULL), '|',
         (SELECT mode FROM nv_selbstregistrierung
           WHERE singleton_id = 1), '|',
         (SELECT revision FROM nv_selbstregistrierung
           WHERE singleton_id = 1), '|',
         (SELECT updated_by FROM nv_selbstregistrierung
           WHERE singleton_id = 1)
       )")" \
    "interrupted baseline was not retried and recorded"
assert_equal "nv_anhang,nv_benutzer,nv_bhp50,nv_einsaetze,nv_einsatz_ereignisse,nv_einsatz_status,nv_empfmtx,nv_empfmtx_standard,nv_etb,nv_etbtitel,nv_komplan,nv_masterkatego,nv_masterkategolink,nv_nachrichten,nv_protokoll,nv_tbb,nv_tbbtitel,nv_ubb" "$(database_query "$retry_database" "
SELECT GROUP_CONCAT(table_name ORDER BY BINARY table_name SEPARATOR ',')
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_type = 'BASE TABLE'
   AND table_name IN (
     'nv_nachrichten', 'nv_empfmtx', 'nv_empfmtx_standard', 'nv_benutzer',
     'nv_masterkatego', 'nv_masterkategolink', 'nv_protokoll',
     'nv_anhang', 'nv_etb', 'nv_tbb', 'nv_ubb', 'nv_komplan',
     'nv_bhp50', 'nv_etbtitel', 'nv_tbbtitel', 'nv_einsaetze',
     'nv_einsatz_status', 'nv_einsatz_ereignisse'
   )")" \
    "retried baseline and migrations did not produce all runtime tables"

# Removing the ledger simulates a lost final acknowledgement. A same-name
# column without the exact ownership marker must not be adopted or rewritten.
database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '95-attachment-ingest-integrity.sql';
ALTER TABLE nv_anhang
  MODIFY COLUMN integrity_required TINYINT UNSIGNED NOT NULL DEFAULT 1
  COMMENT 'foreign-owner-must-survive'
  AFTER md5hash"
if ESTAB_DB_NAME="$retry_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign attachment-integrity column was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Attachment integrity migration blocked: foreign column collision' \
    "$failure_log"; then
    echo "schema migrator test: integrity-column collision failure was not explicit" >&2
    sed -n '1,160p' "$failure_log" >&2
    exit 1
fi
assert_equal "foreign-owner-must-survive|0" "$(
    database_query "$retry_database" "
SELECT CONCAT(
         (SELECT column_comment
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_anhang'
             AND column_name = 'integrity_required'), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '95-attachment-ingest-integrity.sql')
       )"
)" \
    "blocked attachment-integrity collision was changed or recorded"
database_query "$retry_database" "
ALTER TABLE nv_anhang
  MODIFY COLUMN integrity_required TINYINT UNSIGNED NOT NULL DEFAULT 1
  COMMENT 'estab:migration:95:integrity-required:v1'
  AFTER md5hash"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"

# Reproduce the later durable boundary after the INSERT trigger was created
# but before the UPDATE trigger and ledger acknowledgement. The exact owned
# trigger must be accepted and the pair recreated canonically.
database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '95-attachment-ingest-integrity.sql';
DROP TRIGGER estab_attachment_integrity_bu"
assert_equal "1|0" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT COUNT(*)
            FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND trigger_name IN (
               'estab_attachment_integrity_bi',
               'estab_attachment_integrity_bu'
             )), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '95-attachment-ingest-integrity.sql')
       )")" \
    "partial attachment-integrity trigger phase was not reproduced"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "2|1" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT COUNT(*)
            FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND trigger_name IN (
               'estab_attachment_integrity_bi',
               'estab_attachment_integrity_bu'
             )), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '95-attachment-ingest-integrity.sql'
             AND state = 'applied')
       )")" \
    "partial attachment-integrity trigger phase did not resume"

# Reproduce process loss after migration 96 dropped the old global capability
# uniqueness but before extending the ENUM/seeding the two ETB rows. The exact
# owned prefix must converge, whereas a foreign primary key must fail before
# the catalogue is changed or a ledger row is written.
database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '96-etb-duty-function.sql';
DELETE FROM nv_funktionsfaehigkeiten
 WHERE faehigkeit = 'EINSATZTAGEBUCH';
ALTER TABLE nv_funktionsfaehigkeiten
  MODIFY faehigkeit ENUM(
    'LAGE_DOKUMENTATION',
    'SICHTUNG',
    'FERNMELDEPLANUNG',
    'FERNMELDEBETRIEB',
    'BEFOERDERUNG'
  ) NOT NULL"
assert_equal "5|0|0" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_funktionsfaehigkeiten), '|',
         (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_funktionsfaehigkeiten'
             AND index_name = 'uq_funktionsfaehigkeit_eindeutig'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '96-etb-duty-function.sql')
       )")" \
    "partial ETB duty unique-index phase was not reproduced"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "7|2|1|0" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_funktionsfaehigkeiten), '|',
         (SELECT COUNT(*) FROM nv_funktionsfaehigkeiten
           WHERE faehigkeit = 'EINSATZTAGEBUCH'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '96-etb-duty-function.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_funktionsfaehigkeiten'
             AND index_name <> 'PRIMARY')
       )")" \
    "partial ETB duty unique-index phase did not converge canonically"

database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '96-etb-duty-function.sql';
DELETE FROM nv_funktionsfaehigkeiten
 WHERE faehigkeit = 'EINSATZTAGEBUCH'"
assert_equal "5|1|0" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_funktionsfaehigkeiten), '|',
         (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_funktionsfaehigkeiten'
             AND column_name = 'faehigkeit'
             AND column_type LIKE '%EINSATZTAGEBUCH%'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '96-etb-duty-function.sql')
       )")" \
    "partial ETB duty enum phase was not reproduced"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "7|2|1" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_funktionsfaehigkeiten), '|',
         (SELECT COUNT(*) FROM nv_funktionsfaehigkeiten
           WHERE faehigkeit = 'EINSATZTAGEBUCH'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '96-etb-duty-function.sql'
             AND state = 'applied')
       )")" \
    "partial ETB duty enum phase did not converge canonically"

database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '96-etb-duty-function.sql'"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "7|1" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_funktionsfaehigkeiten), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '96-etb-duty-function.sql'
             AND state = 'applied')
       )")" \
    "completed ETB duty catalogue without ledger did not converge"

database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '96-etb-duty-function.sql';
DELETE FROM nv_funktionsfaehigkeiten
 WHERE funktion = 'S6' AND faehigkeit = 'FERNMELDEPLANUNG'"
if ESTAB_DB_NAME="$retry_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: mixed ETB duty catalogue was accepted" >&2
    exit 1
fi
if ! grep -q \
    'ETB duty migration blocked: capability catalogue is not canonical' \
    "$failure_log"; then
    echo "schema migrator test: mixed ETB duty catalogue failure was not explicit" >&2
    sed -n '1,160p' "$failure_log" >&2
    exit 1
fi
assert_equal "6|0" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_funktionsfaehigkeiten), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '96-etb-duty-function.sql')
       )")" \
    "mixed ETB duty catalogue was changed or recorded"
database_query "$retry_database" "
INSERT INTO nv_funktionsfaehigkeiten
  (funktion, rolle, faehigkeit, bezeichnung)
VALUES ('S6', 'Stab', 'FERNMELDEPLANUNG', 'Fernmeldeplanung')"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"

database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '96-etb-duty-function.sql';
ALTER TABLE nv_funktionsfaehigkeiten
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (funktion, rolle, faehigkeit)"
if ESTAB_DB_NAME="$retry_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: ETB duty primary-key drift was accepted" >&2
    exit 1
fi
if ! grep -q \
    'ETB duty migration blocked: capability primary key is incompatible' \
    "$failure_log"; then
    echo "schema migrator test: capability primary-key failure was not explicit" >&2
    sed -n '1,160p' "$failure_log" >&2
    exit 1
fi
assert_equal "3|7|0" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_funktionsfaehigkeiten'
             AND index_name = 'PRIMARY'), '|',
         (SELECT COUNT(*) FROM nv_funktionsfaehigkeiten), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '96-etb-duty-function.sql')
       )")" \
    "blocked ETB duty primary-key drift was changed or recorded"
database_query "$retry_database" "
ALTER TABLE nv_funktionsfaehigkeiten
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (funktion, faehigkeit)"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"

# Migration 97 may be interrupted after its autocommitted ADD COLUMN but before
# the ledger acknowledgement. Only the exact owned VARCHAR(128) shape is
# resumable; a same-name foreign or narrower field must remain untouched.
database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '97-incident-command-post-name.sql';
ALTER TABLE nv_einsaetze
  MODIFY COLUMN fuehrungsstellenname VARCHAR(127)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    NULL DEFAULT NULL
    COMMENT 'foreign-command-post-owner'
    AFTER organisation"
if ESTAB_DB_NAME="$retry_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign command-post column was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Command-post name migration blocked: foreign column collision' \
    "$failure_log"; then
    echo "schema migrator test: command-post collision failure was not explicit" >&2
    sed -n '1,160p' "$failure_log" >&2
    exit 1
fi
assert_equal "varchar(127)|foreign-command-post-owner|0" "$(
    database_query "$retry_database" "
SELECT CONCAT(
         (SELECT column_type
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_einsaetze'
             AND column_name = 'fuehrungsstellenname'), '|',
         (SELECT column_comment
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_einsaetze'
             AND column_name = 'fuehrungsstellenname'), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '97-incident-command-post-name.sql')
       )"
)" \
    "blocked command-post column was changed or recorded"
database_query "$retry_database" "
ALTER TABLE nv_einsaetze
  MODIFY COLUMN fuehrungsstellenname VARCHAR(128)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    NULL DEFAULT NULL
    COMMENT 'estab:migration:97:incident-command-post-name:v1'
    AFTER organisation"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "varchar(128)|1" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT column_type
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_einsaetze'
             AND column_name = 'fuehrungsstellenname'
             AND column_comment =
               'estab:migration:97:incident-command-post-name:v1'), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '97-incident-command-post-name.sql'
             AND state = 'applied')
       )")" \
	    "owned partial command-post column did not resume canonically"

database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '97-incident-command-post-name.sql';
ALTER TABLE nv_einsaetze
  MODIFY COLUMN fuehrungsstellenname_gesperrt TINYINT NOT NULL DEFAULT 0
    COMMENT 'foreign-command-post-lock-owner'
    AFTER fuehrungsstellenname"
if ESTAB_DB_NAME="$retry_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign command-post lock was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Command-post name migration blocked: foreign lock column collision' \
    "$failure_log"; then
    echo "schema migrator test: command-post lock collision was not explicit" >&2
    sed -n '1,160p' "$failure_log" >&2
    exit 1
fi
assert_equal "tinyint(4)|foreign-command-post-lock-owner|0" "$(
    database_query "$retry_database" "
SELECT CONCAT(
         (SELECT column_type
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_einsaetze'
             AND column_name = 'fuehrungsstellenname_gesperrt'), '|',
         (SELECT column_comment
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_einsaetze'
             AND column_name = 'fuehrungsstellenname_gesperrt'), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '97-incident-command-post-name.sql')
       )"
)" \
    "blocked command-post lock column was changed or recorded"
database_query "$retry_database" "
ALTER TABLE nv_einsaetze
  MODIFY COLUMN fuehrungsstellenname_gesperrt TINYINT UNSIGNED
    NOT NULL DEFAULT 0
    COMMENT 'estab:migration:97:incident-command-post-lock:v1'
    AFTER fuehrungsstellenname"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"

database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '97-incident-command-post-name.sql';
DROP FUNCTION estab_incident_command_post_for_write;
CREATE FUNCTION estab_incident_command_post_for_write(
  requested_incident BIGINT UNSIGNED
) RETURNS BIGINT UNSIGNED
DETERMINISTIC NO SQL
COMMENT 'foreign-command-post-routine'
RETURN requested_incident"
if ESTAB_DB_NAME="$retry_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign command-post routine was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Command-post name migration blocked: foreign routine collision' \
    "$failure_log"; then
    echo "schema migrator test: command-post routine collision was not explicit" >&2
    sed -n '1,160p' "$failure_log" >&2
    exit 1
fi
assert_equal "foreign-command-post-routine|0" "$(
    database_query "$retry_database" "
SELECT CONCAT(
         (SELECT routine_comment
            FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name =
               'estab_incident_command_post_for_write'), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '97-incident-command-post-name.sql')
       )"
)" \
    "blocked command-post routine was changed or recorded"
database_query "$retry_database" "
DROP FUNCTION estab_incident_command_post_for_write"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"

database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '97-incident-command-post-name.sql';
DROP TRIGGER estab_command_post_incident_insert;
CREATE TRIGGER estab_command_post_incident_insert
BEFORE INSERT ON nv_einsaetze FOR EACH ROW
SET NEW.name = NEW.name"
if ESTAB_DB_NAME="$retry_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign command-post trigger was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Command-post name migration blocked: foreign trigger collision' \
    "$failure_log"; then
    echo "schema migrator test: command-post trigger collision was not explicit" >&2
    sed -n '1,160p' "$failure_log" >&2
    exit 1
fi
assert_equal "SET NEW.name = NEW.name|0" "$(
    database_query "$retry_database" "
SELECT CONCAT(
         (SELECT action_statement
            FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND trigger_name =
               'estab_command_post_incident_insert'), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '97-incident-command-post-name.sql')
       )"
)" \
    "blocked command-post trigger was changed or recorded"
database_query "$retry_database" "
DROP TRIGGER estab_command_post_incident_insert"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"

# MariaDB commits CREATE TABLE independently of the seed transaction. Prove
# both possible interruption points are resumable only for the migration-owned
# table: after CREATE with zero rows and after the canonical seed commit.
database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '40-recipient-matrix-standard.sql';
DELETE FROM nv_empfmtx_standard"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "20|1" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_empfmtx_standard), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '40-recipient-matrix-standard.sql'
             AND state = 'applied')
       )")" \
    "empty migration-owned standard matrix was not safely resumed"

database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '40-recipient-matrix-standard.sql'"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "$(cat "$standard_matrix_fixture")" "$(database_query "$retry_database" "
SELECT CONCAT(
         mtx_x, '|', mtx_y, '|', mtx_typ, '|', mtx_fkt, '|', mtx_rolle, '|',
         mtx_mode, '|',
         IF(mtx_rc2 IN ('t','1'), '1', '0'), '|',
         IF(mtx_auto IN ('t','1'), '1', '0')
       )
  FROM nv_empfmtx_standard
 ORDER BY mtx_x, mtx_y")" \
    "fully seeded migration-owned standard matrix was not resumed exactly"

# The ownership marker is not permission to overwrite later or foreign data.
# Any non-canonical content must stay untouched and require operator review.
database_query "$retry_database" "
DELETE FROM estab_schema_migrations
 WHERE version = '40-recipient-matrix-standard.sql';
UPDATE nv_empfmtx_standard
   SET mtx_fkt = 'BROKEN'
 WHERE mtx_x = 1 AND mtx_y = 1"
if ESTAB_DB_NAME="$retry_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: modified migration-owned standard matrix was overwritten" >&2
    exit 1
fi
if ! grep -q \
    'Standard matrix migration blocked: owned table content is not resumable' \
    "$failure_log"; then
    echo "schema migrator test: modified owned-table failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "BROKEN|0" "$(database_query "$retry_database" "
SELECT CONCAT(
         (SELECT mtx_fkt FROM nv_empfmtx_standard
           WHERE mtx_x = 1 AND mtx_y = 1), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '40-recipient-matrix-standard.sql')
       )")" \
    "blocked owned standard matrix was changed or recorded"
database_query "$retry_database" "
UPDATE nv_empfmtx_standard
   SET mtx_fkt = 'LS'
 WHERE mtx_x = 1 AND mtx_y = 1"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"

admin_query "
DROP DATABASE IF EXISTS \`$guard_database\`;
CREATE DATABASE \`$guard_database\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
database_query "$guard_database" "
CREATE TABLE nv_partial_probe (
  id INT NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
if ESTAB_DB_NAME="$guard_database" "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: untracked partial namespace was accepted" >&2
    exit 1
fi
if ! grep -q 'partial nv_\* tables already exist' "$failure_log"; then
    echo "schema migrator test: partial namespace failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "0" "$(database_query "$guard_database" "
SELECT COUNT(*)
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name IN ('nv_nachrichten', 'estab_schema_baselines')")" \
    "partial namespace guard modified the blocked database"

admin_query "
DROP DATABASE IF EXISTS \`$collision_database\`;
CREATE DATABASE \`$collision_database\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mariadb \
    --defaults-extra-file="$client_defaults" \
    --database="$collision_database" \
    < "$ESTAB_SCHEMA_BASELINE_FILE"
database_query "$collision_database" "
CREATE TABLE nv_empfmtx_standard (
  marker VARCHAR(64) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO nv_empfmtx_standard (marker) VALUES ('preserve-this-table')"
if ESTAB_DB_NAME="$collision_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: pre-existing standard matrix table was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Standard matrix migration blocked: pre-existing nv_empfmtx_standard table' \
    "$failure_log"; then
    echo "schema migrator test: standard matrix collision failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "preserve-this-table" "$(database_query "$collision_database" "
SELECT marker FROM nv_empfmtx_standard")" \
    "standard matrix collision table was modified"
assert_equal "0" "$(database_query "$collision_database" "
SELECT COUNT(*) FROM estab_schema_migrations
 WHERE version = '40-recipient-matrix-standard.sql'")" \
    "failed standard matrix collision left a migration record"

admin_query "
DROP DATABASE IF EXISTS \`$incident_guard_database\`;
CREATE DATABASE \`$incident_guard_database\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mariadb \
    --defaults-extra-file="$client_defaults" \
    --database="$incident_guard_database" \
    < "$fixture"
database_query "$incident_guard_database" "
DELETE FROM nv_anhang WHERE \`lfd-nr\` = 2;
DROP TABLE nv_etb"
if ESTAB_DB_NAME="$incident_guard_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: incomplete incident runtime was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Incident migration blocked: required operational table is missing' \
    "$failure_log"; then
    echo "schema migrator test: missing incident table failure was not explicit" >&2
    sed -n '1,160p' "$failure_log" >&2
    exit 1
fi
assert_equal "0|0|2" "$(database_query "$incident_guard_database" "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '50-global-incidents.sql'), '|',
         (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name IN (
               'nv_einsaetze',
               'nv_einsatz_status',
               'nv_einsatz_ereignisse'
             )), '|',
         (SELECT COUNT(*) FROM nv_nachrichten)
       )")" \
    "blocked incomplete incident runtime was mutated or recorded"

# Reproduce the exact predecessor installed on the local test deployment:
# migration 50 is already applied with its immutable 6732... checksum, while
# the new prepare/finish migrations do not exist in the ledger yet. The
# migrator must apply those two missing versions around the skipped migration
# without rewriting history or changing already imported timestamps.
assert_equal "$incident_predecessor_checksum" "$(
    sha256sum "$ESTAB_MIGRATIONS_DIR/50-global-incidents.sql" |
        awk '{print $1}'
)" \
    "immutable incident migration 50 no longer has its released checksum"

admin_query "
DROP DATABASE IF EXISTS \`$predecessor_database\`;
CREATE DATABASE \`$predecessor_database\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mariadb \
    --defaults-extra-file="$client_defaults" \
    --database="$predecessor_database" \
    < "$fixture"
database_query "$predecessor_database" "
DELETE FROM nv_anhang WHERE \`lfd-nr\` = 2;
CREATE TABLE nv_masterkatego (
  lfd BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kategorie VARCHAR(10) NOT NULL,
  beschreibung VARCHAR(254) NULL,
  PRIMARY KEY (lfd)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
CREATE TABLE nv_masterkategolink (
  msg BIGINT NOT NULL,
  katego BIGINT NOT NULL,
  PRIMARY KEY (msg)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
CREATE TABLE estab_schema_migrations (
  version VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state ENUM('applying','applied') NOT NULL,
  run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  started_at DATETIME(6) NOT NULL,
  applied_at DATETIME(6) NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"

for predecessor_migration in \
    20-nullable-dates.sql \
    30-runtime-schema.sql \
    40-recipient-matrix-standard.sql \
    50-global-incidents.sql \
    70-user-account-blocking.sql
do
    predecessor_path="$ESTAB_MIGRATIONS_DIR/$predecessor_migration"
    predecessor_migration_checksum=$(
        sha256sum "$predecessor_path" | awk '{print $1}'
    )
    mariadb \
        --defaults-extra-file="$client_defaults" \
        --database="$predecessor_database" \
        < "$predecessor_path"
    database_query "$predecessor_database" "
INSERT INTO estab_schema_migrations
  (version, checksum, state, run_id, started_at, applied_at)
VALUES
  ('$predecessor_migration', '$predecessor_migration_checksum',
   'applied', NULL, NOW(6), NOW(6))"
done

assert_equal "5|$incident_predecessor_checksum" "$(
    database_query "$predecessor_database" "
SELECT CONCAT(
         COUNT(*), '|',
         MAX(CASE WHEN version = '50-global-incidents.sql'
                  THEN checksum ELSE '' END)
       )
  FROM estab_schema_migrations
 WHERE state = 'applied'"
)" \
    "predecessor migration ledger was not reproduced exactly"

predecessor_50_ledger_snapshot=$(
    database_query "$predecessor_database" "
SELECT CONCAT(
         checksum, '|', state, '|', COALESCE(run_id, 'NULL'), '|',
         DATE_FORMAT(started_at, '%Y-%m-%d %H:%i:%s.%f'), '|',
         DATE_FORMAT(applied_at, '%Y-%m-%d %H:%i:%s.%f')
       )
  FROM estab_schema_migrations
 WHERE version = '50-global-incidents.sql'"
)
predecessor_timestamp_snapshot=$(
    database_query "$predecessor_database" "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(
                   CONCAT(\`00_lfd\`, ':',
                          COALESCE(
                            DATE_FORMAT(
                              \`99_lstacc\`, '%Y-%m-%d %H:%i:%s'
                            ),
                            'NULL'
                          ))
                   ORDER BY \`00_lfd\` SEPARATOR ',')
            FROM nv_nachrichten),
         '|',
         (SELECT GROUP_CONCAT(
                   CONCAT(\`lfd-nr\`, ':',
                          COALESCE(
                            DATE_FORMAT(
                              \`sich1_zeit\`, '%Y-%m-%d %H:%i:%s'
                            ),
                            'NULL'
                          ))
                   ORDER BY \`lfd-nr\` SEPARATOR ',')
            FROM nv_bhp50)
       )"
)

# The predecessor's automatic timestamps have only whole-second precision.
# Cross a second boundary so an accidental ON UPDATE during 45/55 cannot
# produce the same value as the snapshot and escape this regression.
sleep 2
ESTAB_DB_NAME="$predecessor_database" "$ESTAB_MIGRATOR_BIN"
predecessor_standard_category_snapshot=$(
    database_query "$predecessor_database" "
SELECT GROUP_CONCAT(
         CONCAT(lfd, ':', HEX(kategorie))
         ORDER BY lfd SEPARATOR ','
       )
  FROM nv_masterkatego"
)
assert_equal "Allgemein,EA1,EA2,EA3,EA4,EA5,EA6" "$(
    database_query "$predecessor_database" "
SELECT GROUP_CONCAT(kategorie ORDER BY BINARY kategorie SEPARATOR ',')
  FROM nv_masterkatego"
)" \
    "empty legacy category table did not receive exact standard categories"
ESTAB_DB_NAME="$predecessor_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "$predecessor_standard_category_snapshot" "$(
    database_query "$predecessor_database" "
SELECT GROUP_CONCAT(
         CONCAT(lfd, ':', HEX(kategorie))
         ORDER BY lfd SEPARATOR ','
       )
  FROM nv_masterkatego"
)" \
    "second legacy-upgrade run duplicated or changed standard categories"

prepare_checksum=$(
    sha256sum "$ESTAB_MIGRATIONS_DIR/45-global-incidents-prepare.sql" |
        awk '{print $1}'
)
finish_checksum=$(
    sha256sum "$ESTAB_MIGRATIONS_DIR/55-global-incidents-finish.sql" |
        awk '{print $1}'
)
assert_equal \
    "22|22|$prepare_checksum|$incident_predecessor_checksum|$finish_checksum" \
    "$(database_query "$predecessor_database" "
SELECT CONCAT(
         COUNT(*), '|',
         SUM(state = 'applied'), '|',
         MAX(CASE WHEN version = '45-global-incidents-prepare.sql'
                  THEN checksum ELSE '' END), '|',
         MAX(CASE WHEN version = '50-global-incidents.sql'
                  THEN checksum ELSE '' END), '|',
         MAX(CASE WHEN version = '55-global-incidents-finish.sql'
                  THEN checksum ELSE '' END)
       )
  FROM estab_schema_migrations")" \
    "predecessor upgrade rewrote history or omitted prepare/finish migrations"
assert_equal "$predecessor_50_ledger_snapshot" "$(
    database_query "$predecessor_database" "
SELECT CONCAT(
         checksum, '|', state, '|', COALESCE(run_id, 'NULL'), '|',
         DATE_FORMAT(started_at, '%Y-%m-%d %H:%i:%s.%f'), '|',
         DATE_FORMAT(applied_at, '%Y-%m-%d %H:%i:%s.%f')
       )
  FROM estab_schema_migrations
 WHERE version = '50-global-incidents.sql'"
)" \
    "predecessor incident migration ledger row was rewritten"
assert_equal "$predecessor_timestamp_snapshot" "$(
    database_query "$predecessor_database" "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(
                   CONCAT(\`00_lfd\`, ':',
                          COALESCE(
                            DATE_FORMAT(
                              \`99_lstacc\`, '%Y-%m-%d %H:%i:%s'
                            ),
                            'NULL'
                          ))
                   ORDER BY \`00_lfd\` SEPARATOR ',')
            FROM nv_nachrichten),
         '|',
         (SELECT GROUP_CONCAT(
                   CONCAT(\`lfd-nr\`, ':',
                          COALESCE(
                            DATE_FORMAT(
                              \`sich1_zeit\`, '%Y-%m-%d %H:%i:%s'
                            ),
                            'NULL'
                          ))
                   ORDER BY \`lfd-nr\` SEPARATOR ',')
            FROM nv_bhp50)
       )"
)" \
    "predecessor prepare/finish upgrade changed imported timestamps"
assert_equal "2|0" "$(database_query "$predecessor_database" "
SELECT CONCAT(
         (SELECT COUNT(*)
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND (
               (table_name = 'nv_nachrichten'
                 AND column_name = '99_lstacc')
               OR
               (table_name = 'nv_bhp50'
                 AND column_name = 'sich1_zeit')
             )
             AND is_nullable = 'YES'
             AND column_default = 'NULL'
             AND LOWER(extra) = 'on update current_timestamp()'), '|',
         (SELECT COUNT(*)
            FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name IN (
               'estab_migrate_45_prepare_preflight',
               'estab_migrate_45_prepare_validate',
               'estab_migrate_55_finish_preflight',
               'estab_migrate_55_finish_validate'
             ))
       )")" \
    "predecessor finish migration left timestamp guards or helper routines"

admin_query "
DROP DATABASE IF EXISTS \`$test_database\`;
CREATE DATABASE \`$test_database\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mariadb \
    --defaults-extra-file="$client_defaults" \
    --database="$test_database" \
    < "$fixture"

# A legitimate legacy installation may already have a customised global
# category catalogue. Migration 116 must treat any existing row as an
# operator-owned catalogue and leave the complete table and its links alone;
# it must never fill only the names which happen to be missing.
fixture_query "
CREATE TABLE nv_masterkatego (
  lfd BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kategorie VARCHAR(10) NOT NULL,
  beschreibung VARCHAR(254) NULL,
  PRIMARY KEY (lfd)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
CREATE TABLE nv_masterkategolink (
  msg BIGINT NOT NULL,
  katego BIGINT NOT NULL,
  PRIMARY KEY (msg)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO nv_masterkatego (lfd, kategorie, beschreibung) VALUES
  (41, 'Allgemein', 'Individuell beibehalten'),
  (77, 'EIGEN', 'Eigene Kategorie');
INSERT INTO nv_masterkategolink (msg, katego) VALUES (900001, 77)"
legacy_category_snapshot="$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(
                   CONCAT(lfd, ':', HEX(kategorie), ':',
                          HEX(COALESCE(beschreibung, '')))
                   ORDER BY lfd SEPARATOR ',')
            FROM nv_masterkatego), '|',
         (SELECT GROUP_CONCAT(CONCAT(msg, ':', katego)
                   ORDER BY msg SEPARATOR ',')
            FROM nv_masterkategolink)
       )")"

if ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: duplicate attachment names did not block migration" >&2
    exit 1
fi
if ! grep -q 'duplicate nv_anhang.filename' "$failure_log"; then
    echo "schema migrator test: duplicate failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "1" "$(fixture_query "
SELECT COUNT(*) FROM estab_schema_migrations
 WHERE version = '20-nullable-dates.sql' AND state = 'applied'")" \
    "nullable-date migration was not recorded"
assert_equal "0" "$(fixture_query "
SELECT COUNT(*) FROM estab_schema_migrations
 WHERE version = '30-runtime-schema.sql'")" \
    "failed runtime migration left an applied/in-progress record"

fixture_query "
DELETE FROM nv_anhang WHERE \`lfd-nr\` = 2;

-- Historic logbook rows deliberately use event times which differ from their
-- global primary-key order. Migration 110 must number them by recorded time
-- and then by the stable global key without rewriting their original text.
INSERT INTO nv_etb
  (\`etb_lfd-nr\`, etb_time, etb_aktion, etb_bemerk,
   etb_benutzer, etb_kuerzel, etb_funktion)
VALUES
  (10, '2020-01-01 11:00:00', 'ETB später', 'Bemerkung 10',
   'Legacy User', 'abc', 'S2'),
  (20, '2020-01-01 10:00:00', 'ETB zuerst', 'Bemerkung 20',
   'Legacy User', 'abc', 'S2'),
  (30, '2020-01-01 10:00:00', 'ETB danach', 'Bemerkung 30',
   'Legacy User', 'abc', 'S2');
INSERT INTO nv_tbb
  (\`tbb_lfd-nr\`, tbb_time, tbb_aktion, tbb_bemerk,
   tbb_benutzer, tbb_kuerzel, tbb_funktion)
VALUES
  (10, '2020-01-01 11:00:00', 'Kanalwechsel', 'auf 2 m',
   'Legacy User', 'abc', 'LdF'),
  (20, '2020-01-01 10:00:00', 'Dienstübernahme', 'vollständig',
   'Legacy User', 'abc', 'LdF'),
  (30, '2020-01-01 10:00:00', 'Quittung', 'erteilt',
   'Legacy User', 'abc', 'LdF');

-- A legacy browser can leave this flag behind indefinitely. Migration 100
-- must revoke it because no trustworthy activity timestamp exists yet.
UPDATE nv_benutzer
   SET aktiv = 1, sid = 'legacy-session', ip = '192.0.2.10';

-- Deliberately reproduce process loss after migration 95's first
-- autocommitted ADD COLUMN. The migration ledger is absent, so the next
-- migrator run must recognise this exact owned prefix, complete all later
-- phases, and never reclassify the imported attachment as verified.
ALTER TABLE nv_anhang
  ADD COLUMN integrity_required TINYINT UNSIGNED NOT NULL DEFAULT 0
  COMMENT 'estab:migration:95:integrity-required:v1'
  AFTER md5hash"
assert_equal "1|0|estab:migration:95:integrity-required:v1" "$(fixture_query "
SELECT CONCAT(COUNT(*), '|', MAX(column_default), '|', MAX(column_comment))
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name = 'nv_anhang'
   AND column_name = 'integrity_required'")" \
    "deliberate partial attachment-integrity DDL was not reproduced"
legacy_message_snapshot="$(fixture_query "
SELECT GROUP_CONCAT(
         CONCAT(
           \`00_lfd\`, ':',
           HEX(\`01_zeichen\`), ':',
           HEX(\`02_zeichen\`), ':',
           HEX(\`03_zeichen\`), ':',
           HEX(\`14_zeichen\`), ':',
           HEX(\`15_quitzeichen\`), ':',
           HEX(\`x03_sperruser\`)
         )
         ORDER BY \`00_lfd\` SEPARATOR ','
       )
  FROM nv_nachrichten")"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "$legacy_category_snapshot" "$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(
                   CONCAT(lfd, ':', HEX(kategorie), ':',
                          HEX(COALESCE(beschreibung, '')))
                   ORDER BY lfd SEPARATOR ',')
            FROM nv_masterkatego), '|',
         (SELECT GROUP_CONCAT(CONCAT(msg, ':', katego)
                   ORDER BY msg SEPARATOR ',')
            FROM nv_masterkategolink)
       )")" \
    "upgrade changed a nonempty global category catalogue or its links"
assert_equal "2|0|1" "$(fixture_query "
SELECT CONCAT(
         COUNT(*), '|',
         SUM(BINARY kategorie IN (
           BINARY 'EA1', BINARY 'EA2', BINARY 'EA3',
           BINARY 'EA4', BINARY 'EA5', BINARY 'EA6'
         )), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '116-standard-categories.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$')
       )
  FROM nv_masterkatego")" \
    "upgrade partially filled a nonempty global category catalogue"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "$legacy_category_snapshot" "$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(
                   CONCAT(lfd, ':', HEX(kategorie), ':',
                          HEX(COALESCE(beschreibung, '')))
                   ORDER BY lfd SEPARATOR ',')
            FROM nv_masterkatego), '|',
         (SELECT GROUP_CONCAT(CONCAT(msg, ':', katego)
                   ORDER BY msg SEPARATOR ',')
            FROM nv_masterkategolink)
       )")" \
    "second upgrade run changed existing global categories or links"

assert_equal "22" "$(fixture_query "
SELECT COUNT(*) FROM estab_schema_migrations
 WHERE state = 'applied'
   AND checksum REGEXP BINARY '^[0-9a-f]{64}$'")" \
    "versioned migration records are incomplete"
assert_equal "1" "$(fixture_query "
SELECT COUNT(*) FROM estab_schema_migrations
 WHERE version = '40-recipient-matrix-standard.sql'
   AND state = 'applied'
   AND checksum REGEXP BINARY '^[0-9a-f]{64}$'")" \
    "standard matrix migration was not recorded"
assert_equal "1" "$(fixture_query "
SELECT COUNT(*) FROM estab_schema_migrations
 WHERE version = '116-standard-categories.sql'
   AND state = 'applied'
   AND checksum REGEXP BINARY '^[0-9a-f]{64}$'")" \
    "standard category migration was not recorded"
assert_equal "1|1|1|1|1|1|1|1|1|1|1|1|1|1|1|11" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '80-dv-evidence-retention.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '94-dv-organisational-controls.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '95-attachment-ingest-integrity.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '96-etb-duty-function.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '97-incident-command-post-name.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '98-official-message-form-fields.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '99-message-list-search.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '100-session-presence.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '110-etb-tbb-rules.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '111-logbook-shift-assignment.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '112-optional-access-shifts.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '113-password-policy.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '114-self-registration-policy.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '115-incident-permission-mode.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_betriebsereignisse'
             AND column_name = 'objekttyp'
             AND column_type =
               'enum(''DIENSTSCHICHT'',''DIENSTBESETZUNG'',''DIENSTUEBERGABE'',''FERNMELDEPLAN'',''MELDERAUFTRAG'',''EINSATZ'',''ZUGANGSSCHICHT'')'
             AND column_comment =
               'estab:migration:112:event-object-types:v1'), '|',
         (SELECT COUNT(*)
            FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name IN (
               'nv_funktionsfaehigkeiten',
               'nv_betriebsereignis_kopf',
               'nv_betriebsereignisse',
               'nv_dienstschichten',
               'nv_dienstbesetzungen',
               'nv_dienstuebergaben',
               'nv_fernmeldeplaene',
               'nv_fernmeldeplan_eintraege',
               'nv_melderauftraege',
               'nv_zugangsschichten',
               'nv_zugangsschicht_mitglieder'
             ))
       )")" \
    "DV evidence or organisational migration was not applied completely"
assert_equal "1|9|8|1|12|0|0|0|0|0|migration-113" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_kennwortrichtlinie'
             AND table_type = 'BASE TABLE'
             AND engine = 'InnoDB'
             AND table_collation = 'utf8mb4_unicode_ci'
             AND table_comment =
               'estab:migration:113:password-policy:v1'), '|',
         (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_kennwortrichtlinie'), '|',
         (SELECT COUNT(*) FROM information_schema.table_constraints
           WHERE constraint_schema = DATABASE()
             AND table_name = 'nv_kennwortrichtlinie'), '|',
         (SELECT COUNT(*) FROM nv_kennwortrichtlinie), '|',
         (SELECT minimum_length FROM nv_kennwortrichtlinie
           WHERE singleton_id = 1), '|',
         (SELECT require_uppercase FROM nv_kennwortrichtlinie
           WHERE singleton_id = 1), '|',
         (SELECT require_lowercase FROM nv_kennwortrichtlinie
           WHERE singleton_id = 1), '|',
         (SELECT require_digit FROM nv_kennwortrichtlinie
           WHERE singleton_id = 1), '|',
         (SELECT require_symbol FROM nv_kennwortrichtlinie
           WHERE singleton_id = 1), '|',
         (SELECT revision FROM nv_kennwortrichtlinie
           WHERE singleton_id = 1), '|',
         (SELECT updated_by FROM nv_kennwortrichtlinie
           WHERE singleton_id = 1)
       )")" \
    "password-policy migration did not create its canonical defaults"
assert_equal "0|1|6|3|1|ENVIRONMENT|1|0|migration-114" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name = 'estab_schema_baselines'), '|',
         (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_selbstregistrierung'
             AND table_type = 'BASE TABLE'
             AND engine = 'InnoDB'
             AND table_collation = 'utf8mb4_unicode_ci'
             AND table_comment =
               'estab:migration:114:self-registration-policy:v1'), '|',
         (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_selbstregistrierung'), '|',
         (SELECT COUNT(*) FROM information_schema.table_constraints
           WHERE constraint_schema = DATABASE()
             AND table_name = 'nv_selbstregistrierung'), '|',
         (SELECT COUNT(*) FROM nv_selbstregistrierung), '|',
         (SELECT mode FROM nv_selbstregistrierung
           WHERE singleton_id = 1), '|',
         (SELECT enabled_until_utc IS NULL FROM nv_selbstregistrierung
           WHERE singleton_id = 1), '|',
         (SELECT revision FROM nv_selbstregistrierung
           WHERE singleton_id = 1), '|',
         (SELECT updated_by FROM nv_selbstregistrierung
           WHERE singleton_id = 1)
       )")" \
    "legacy upgrade did not preserve the environment-compatible self-registration default"
assert_equal "1|1|0|8|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_einsaetze'
             AND column_name = 'estab_permission_mode'
             AND ordinal_position = (
               SELECT ordinal_position + 1 FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'nv_einsaetze'
                  AND column_name = 'erstellt_von'
             )
             AND data_type = 'enum'
             AND column_type = 'enum(''STRICT'',''LOOSE'')'
             AND character_set_name = 'ascii'
             AND collation_name = 'ascii_bin'
             AND is_nullable = 'NO'
             AND column_default = '''STRICT'''
             AND column_comment =
               'estab:migration:115:incident-permission-mode:v1'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '115-incident-permission-mode.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM nv_einsaetze
           WHERE estab_permission_mode <> 'STRICT'), '|',
         (SELECT COUNT(*) FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND trigger_name IN (
               'estab_permission_mode_incident_insert',
               'estab_permission_mode_incident_update',
               'estab_etb_bi_einsatz', 'estab_tbb_bi_einsatz',
               'estab_dv94_fernmeldeplan_insert',
               'estab_dv94_fernmeldeplan_immutable',
               'estab_dv94_messenger_insert',
               'estab_dv94_messenger_update'
             )), '|',
         (SELECT COUNT(*) FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name LIKE 'estab_migrate_115_%')
       )")" \
    "incident permission mode was not migrated fail-closed and canonically"
assert_equal "1|3|aktiv,estab_gesperrt,estab_letzte_aktivitaet|0||NULL|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*)
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_benutzer'
             AND column_name = 'estab_letzte_aktivitaet'
             AND data_type = 'datetime'
             AND datetime_precision = 6
             AND is_nullable = 'YES'
             AND column_comment =
               'estab:migration:100:last-browser-activity-utc:v1'), '|',
         (SELECT COUNT(*)
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_benutzer'
             AND index_name = 'idx_benutzer_presence'), '|',
         (SELECT GROUP_CONCAT(
                   column_name ORDER BY seq_in_index SEPARATOR ','
                 )
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_benutzer'
             AND index_name = 'idx_benutzer_presence'), '|',
         (SELECT aktiv FROM nv_benutzer WHERE kuerzel = 'abc'), '|',
         (SELECT sid FROM nv_benutzer WHERE kuerzel = 'abc'), '|',
         COALESCE((SELECT CAST(estab_letzte_aktivitaet AS CHAR)
            FROM nv_benutzer WHERE kuerzel = 'abc'), 'NULL'), '|',
         (SELECT COUNT(*)
            FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name LIKE 'estab_migrate_100_%')
       )")" \
    "session-presence migration was not canonical or retained a legacy SID"
assert_equal "1|12|11|3|10|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_logbuch_koepfe'
             AND table_comment = 'estab:migration:110:logbook-heads:v1'), '|',
         (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND ((table_name = 'nv_etb'
                   AND column_name = 'estab_book_lfd')
               OR (table_name = 'nv_tbb' AND column_name IN (
                 'estab_book_lfd', 'estab_event_time', 'estab_recorded_at',
                 'estab_entry_type', 'estab_message_id',
                 'estab_personnel_duty', 'estab_channel',
                 'estab_message_route', 'estab_operations', 'estab_receipt',
                 'estab_correction_of'
               )))), '|',
         (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND index_name IN (
               'uq_etb_einsatz_book_lfd', 'uq_tbb_einsatz_book_lfd',
               'uq_etb_attachment_id', 'idx_tbb_einsatz_event_time',
               'idx_tbb_message', 'idx_tbb_correction'
             )), '|',
         (SELECT COUNT(*) FROM information_schema.referential_constraints
           WHERE constraint_schema = DATABASE()
             AND constraint_name IN (
               'fk_logbuch_koepfe_einsatz', 'fk_tbb_message',
               'fk_tbb_correction'
             )), '|',
         (SELECT COUNT(*) FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND trigger_name IN (
               'estab_einsaetze_bu_evidence',
               'estab_einsaetze_bu_logbook_retention',
               'estab_einsaetze_ai_logbook_heads',
               'estab_etb_bi_einsatz', 'estab_etb_bu_einsatz',
               'estab_etb_bd_einsatz', 'estab_tbb_bi_einsatz',
               'estab_tbb_bu_einsatz', 'estab_tbb_bd_einsatz',
               'estab_dv94_hat_insert'
             )), '|',
         (SELECT COUNT(*) FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name LIKE 'estab_migrate_110_%')
       )")" \
    "incident-local ETB/TBB numbering and append-only TTB rules are not canonical"
assert_equal "6|9|5|4|3|3|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND (
             (table_name = 'nv_etb' AND column_name = 'estab_shift_id'
               AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
               AND is_nullable = 'YES'
               AND column_comment = 'estab:migration:111:etb-shift:v1')
             OR (table_name = 'nv_etb'
               AND column_name = 'estab_writer_assignment_id'
               AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
               AND is_nullable = 'YES'
               AND column_comment = 'estab:migration:111:etb-writer:v1')
             OR (table_name = 'nv_etb'
               AND column_name = 'estab_assignee_assignment_id'
               AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
               AND is_nullable = 'YES'
               AND column_comment = 'estab:migration:111:etb-assignee:v1')
             OR (table_name = 'nv_etb' AND column_name = 'estab_assignment'
               AND column_type = 'varchar(255)'
               AND character_set_name = 'utf8mb4'
               AND collation_name = 'utf8mb4_unicode_ci'
               AND is_nullable = 'YES'
               AND column_comment =
                 'estab:migration:111:etb-assignment-snapshot:v1')
             OR (table_name = 'nv_tbb' AND column_name = 'estab_shift_id'
               AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
               AND is_nullable = 'YES'
               AND column_comment = 'estab:migration:111:tbb-shift:v1')
             OR (table_name = 'nv_tbb'
               AND column_name = 'estab_writer_assignment_id'
               AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
               AND is_nullable = 'YES'
               AND column_comment = 'estab:migration:111:tbb-writer:v1')
           )), '|',
         (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = DATABASE() AND sub_part IS NULL AND (
             (table_name = 'nv_etb'
               AND index_name = 'idx_etb_einsatz_shift_book'
               AND non_unique = 1 AND index_type = 'BTREE'
               AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
                 OR (seq_in_index = 2 AND column_name = 'estab_shift_id')
                 OR (seq_in_index = 3 AND column_name = 'estab_book_lfd')))
             OR (table_name = 'nv_etb'
               AND index_name = 'idx_etb_writer_assignment'
               AND non_unique = 1 AND index_type = 'BTREE'
               AND seq_in_index = 1
               AND column_name = 'estab_writer_assignment_id')
             OR (table_name = 'nv_etb'
               AND index_name = 'idx_etb_assignee_assignment'
               AND non_unique = 1 AND index_type = 'BTREE'
               AND seq_in_index = 1
               AND column_name = 'estab_assignee_assignment_id')
             OR (table_name = 'nv_tbb'
               AND index_name = 'idx_tbb_einsatz_shift_book'
               AND non_unique = 1 AND index_type = 'BTREE'
               AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
                 OR (seq_in_index = 2 AND column_name = 'estab_shift_id')
                 OR (seq_in_index = 3 AND column_name = 'estab_book_lfd')))
             OR (table_name = 'nv_tbb'
               AND index_name = 'idx_tbb_writer_assignment'
               AND non_unique = 1 AND index_type = 'BTREE'
               AND seq_in_index = 1
               AND column_name = 'estab_writer_assignment_id')
           )), '|',
         (SELECT COUNT(*)
            FROM information_schema.referential_constraints AS relation
            JOIN information_schema.key_column_usage AS key_column
              ON key_column.constraint_schema = relation.constraint_schema
             AND key_column.table_name = relation.table_name
             AND key_column.constraint_name = relation.constraint_name
           WHERE relation.constraint_schema = DATABASE()
             AND relation.update_rule = 'RESTRICT'
             AND relation.delete_rule = 'RESTRICT'
             AND (
               (relation.constraint_name = 'fk_etb_shift'
                 AND relation.table_name = 'nv_etb'
                 AND relation.referenced_table_name = 'nv_dienstschichten'
                 AND key_column.column_name = 'estab_shift_id'
                 AND key_column.referenced_column_name = 'dienstschicht_id')
               OR (relation.constraint_name = 'fk_etb_writer_assignment'
                 AND relation.table_name = 'nv_etb'
                 AND relation.referenced_table_name = 'nv_dienstbesetzungen'
                 AND key_column.column_name = 'estab_writer_assignment_id'
                 AND key_column.referenced_column_name = 'dienstbesetzung_id')
               OR (relation.constraint_name = 'fk_etb_assignee_assignment'
                 AND relation.table_name = 'nv_etb'
                 AND relation.referenced_table_name = 'nv_dienstbesetzungen'
                 AND key_column.column_name = 'estab_assignee_assignment_id'
                 AND key_column.referenced_column_name = 'dienstbesetzung_id')
               OR (relation.constraint_name = 'fk_tbb_shift'
                 AND relation.table_name = 'nv_tbb'
                 AND relation.referenced_table_name = 'nv_dienstschichten'
                 AND key_column.column_name = 'estab_shift_id'
                 AND key_column.referenced_column_name = 'dienstschicht_id')
               OR (relation.constraint_name = 'fk_tbb_writer_assignment'
                 AND relation.table_name = 'nv_tbb'
                 AND relation.referenced_table_name = 'nv_dienstbesetzungen'
                 AND key_column.column_name = 'estab_writer_assignment_id'
                 AND key_column.referenced_column_name = 'dienstbesetzung_id')
             )), '|',
         (SELECT COUNT(*) FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND action_timing = 'BEFORE'
             AND ((trigger_name = 'estab_etb_bi_einsatz'
                   AND event_manipulation = 'INSERT'
                   AND action_statement LIKE
                         '%ETB optional duty provenance must be complete%'
                   AND action_statement LIKE
                         '%ETB assignee duty provenance is invalid%'
                   AND action_statement LIKE
                         '%SET NEW.\`estab_assignment\` = assignment_snapshot%')
               OR (trigger_name = 'estab_tbb_bi_einsatz'
                   AND event_manipulation = 'INSERT'
                   AND action_statement LIKE
                         '%TTB optional duty provenance must be complete%'
                   AND action_statement LIKE
                         '%TTB writer does not belong to its duty shift%')
               OR (trigger_name = 'estab_log111_handover_insert_time'
                   AND event_manipulation = 'INSERT'
                   AND action_statement LIKE
                         '%Duty handover completion times are inconsistent%')
               OR (trigger_name = 'estab_log111_handover_confirm_time'
                   AND event_manipulation = 'UPDATE'
                   AND action_statement LIKE
                         '%Duty handover confirmation times are inconsistent%'))), '|',
         (SELECT COUNT(*) FROM nv_etb
           WHERE estab_shift_id IS NULL
             AND estab_writer_assignment_id IS NULL
             AND estab_assignee_assignment_id IS NULL
             AND estab_assignment IS NULL), '|',
         (SELECT COUNT(*) FROM nv_tbb
           WHERE estab_shift_id IS NULL
             AND estab_writer_assignment_id IS NULL), '|',
         (SELECT COUNT(*) FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name LIKE 'estab_migrate_111_%')
       )")" \
    "historical logbook rows did not retain unknown shift provenance"
assert_equal "10:3,20:1,30:2|10:3,20:1,30:2|4|4" "$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(CONCAT(\`etb_lfd-nr\`, ':', estab_book_lfd)
                  ORDER BY \`etb_lfd-nr\` SEPARATOR ',') FROM nv_etb), '|',
         (SELECT GROUP_CONCAT(CONCAT(\`tbb_lfd-nr\`, ':', estab_book_lfd)
                  ORDER BY \`tbb_lfd-nr\` SEPARATOR ',') FROM nv_tbb), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe
           WHERE buchart = 'ETB'), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe
           WHERE buchart = 'TTB')
       )")" \
    "historic logbook rows were not deterministically numbered"
assert_equal "3|3|1|1|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_tbb
           WHERE estab_entry_type = 'legacy_import'), '|',
         (SELECT COUNT(*) FROM nv_tbb
           WHERE estab_event_time = tbb_time
             AND estab_recorded_at = tbb_time), '|',
         (SELECT COUNT(*) FROM nv_tbb WHERE \`tbb_lfd-nr\` = 10
           AND estab_operations LIKE 'Betriebsvorgang: Kanalwechsel%'), '|',
         (SELECT COUNT(*) FROM nv_tbb WHERE \`tbb_lfd-nr\` = 10
           AND estab_operations LIKE '%Bemerkung: auf 2 m'), '|',
         (SELECT COUNT(*) FROM nv_einsaetze
           WHERE estab_status = 'closed'
             AND (estab_closed_at IS NULL OR estab_retain_until IS NULL
               OR estab_retain_until
                    < DATE_ADD(estab_closed_at, INTERVAL 10 YEAR)))
       )")" \
    "historic TTB text or ten-year incident retention was not preserved honestly"
assert_equal "$legacy_message_snapshot" "$(fixture_query "
SELECT GROUP_CONCAT(
         CONCAT(
           \`00_lfd\`, ':',
           HEX(\`01_zeichen\`), ':',
           HEX(\`02_zeichen\`), ':',
           HEX(\`03_zeichen\`), ':',
           HEX(\`14_zeichen\`), ':',
           HEX(\`15_quitzeichen\`), ':',
           HEX(\`x03_sperruser\`)
         )
         ORDER BY \`00_lfd\` SEPARATOR ','
       )
  FROM nv_nachrichten")" \
    "official message field migration changed existing message data"
assert_equal "1|1|10_anschrift,11_rufnummer,11_gesprnotiz,12_betreff,12_anhang:4|2|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*)
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_nachrichten'
             AND column_name = '11_rufnummer'
             AND column_type = 'varchar(128)'
             AND character_set_name = 'utf8mb4'
             AND collation_name = 'utf8mb4_unicode_ci'
             AND is_nullable = 'NO'
             AND HEX(column_default) = '2727'
             AND column_comment =
               'estab:migration:98:message-counterparty-number:v1'), '|',
         (SELECT COUNT(*)
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_nachrichten'
             AND column_name = '12_betreff'
             AND column_type = 'varchar(255)'
             AND character_set_name = 'utf8mb4'
             AND collation_name = 'utf8mb4_unicode_ci'
             AND is_nullable = 'NO'
             AND HEX(column_default) = '2727'
             AND column_comment =
               'estab:migration:98:message-subject:v1'), '|',
         (SELECT CONCAT(
                   GROUP_CONCAT(
                     column_name ORDER BY ordinal_position SEPARATOR ','
                   ),
                   ':',
                   MAX(ordinal_position) - MIN(ordinal_position)
                 )
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_nachrichten'
             AND column_name IN (
               '10_anschrift', '11_rufnummer', '11_gesprnotiz',
               '12_betreff', '12_anhang'
             )), '|',
         (SELECT COUNT(*) FROM nv_nachrichten
           WHERE \`11_rufnummer\` = '' AND \`12_betreff\` = ''), '|',
         (SELECT COUNT(*)
            FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name IN (
               'estab_migrate_98_preflight',
               'estab_migrate_98_add_counterparty_number',
               'estab_migrate_98_add_subject',
               'estab_migrate_98_validate'
             ))
       )")" \
    "official message fields migration was not canonical or left helpers"
canonical_message_indexes="0|7:FULLTEXT:1:7:05_gegenstelle,10_anschrift,11_rufnummer,12_betreff,12_inhalt,13_abseinheit,14_funktion|4:BTREE:1:4:einsatz_id,x00_status,12_abfzeit,00_lfd|4:BTREE:1:4:einsatz_id,04_richtung,04_nummer,00_lfd|0"
assert_equal "$canonical_message_indexes" "$(message_index_snapshot)" \
    "message-list search indexes were not canonical after migration"

# Reproduce process loss after migration 112 durably created both owned access
# group tables but after one trigger DROP and before its matching CREATE. The
# absent ledger acknowledgement and missing owned trigger must converge on the
# next run, and the following run must remain a no-op.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version = '112-optional-access-shifts.sql';
DROP TRIGGER estab_dv94_fernmeldeplan_immutable"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|2|6" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '112-optional-access-shifts.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name IN (
               'nv_zugangsschichten', 'nv_zugangsschicht_mitglieder'
             )
             AND table_comment =
               'estab:migration:112:optional-access-shifts:v1'), '|',
         (SELECT COUNT(*) FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND ((trigger_name = 'estab_etb_bi_einsatz'
                   AND action_statement LIKE
                         '%ETB optional duty provenance must be complete%')
               OR (trigger_name = 'estab_tbb_bi_einsatz'
                   AND action_statement LIKE
                         '%TTB optional duty provenance must be complete%')
               OR (trigger_name = 'estab_dv94_fernmeldeplan_insert'
                   AND action_statement LIKE
                         '%Telecommunications plan creator account is invalid%')
               OR (trigger_name = 'estab_dv94_fernmeldeplan_immutable'
                   AND action_statement LIKE
                         '%Telecommunications plan release account is invalid%')
               OR (trigger_name = 'estab_dv94_messenger_insert'
                   AND action_statement LIKE
                         '%Messenger assignment account functions are invalid%')
               OR (trigger_name = 'estab_dv94_messenger_update'
                   AND action_statement LIKE
                         '%Messenger report account function is invalid%')))
       )")" \
    "partial optional access-shift trigger phase did not resume canonically"

# The phase marker only permits a missing owned trigger. A present same-name
# definition without an accepted predecessor/final ownership body must fail
# closed, remain untouched, and receive no migration acknowledgement.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version = '112-optional-access-shifts.sql';
DROP TRIGGER estab_dv94_messenger_insert;
CREATE TRIGGER estab_dv94_messenger_insert
BEFORE INSERT ON nv_melderauftraege FOR EACH ROW
SET @estab_foreign_optional_shift_trigger = 1"
if ESTAB_DB_NAME="$test_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign optional access-shift trigger was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Optional access-shift migration blocked: trigger collision' \
    "$failure_log"; then
    echo "schema migrator test: optional access-shift trigger collision failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "1|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND trigger_name = 'estab_dv94_messenger_insert'
             AND action_statement LIKE
                   '%estab_foreign_optional_shift_trigger%'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '112-optional-access-shifts.sql')
       )")" \
    "blocked optional access-shift trigger collision was changed or recorded"
fixture_query "DROP TRIGGER estab_dv94_messenger_insert"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|1" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '112-optional-access-shifts.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND trigger_name = 'estab_dv94_messenger_insert'
             AND action_statement LIKE
                   '%Messenger assignment account functions are invalid%')
       )")" \
    "optional access-shift trigger did not recover after removing the collision"

# Password policy constraints must protect the documented bounds independently
# of the application. An interrupted run with an owned empty table must seed the
# canonical defaults, while a ledger retry must never overwrite a configured
# policy. A foreign same-name table remains untouched and unacknowledged.
if fixture_query "
UPDATE nv_kennwortrichtlinie
   SET minimum_length = 7
 WHERE singleton_id = 1" >"$failure_log" 2>&1; then
    echo "schema migrator test: password minimum below eight was accepted" >&2
    exit 1
fi
if fixture_query "
UPDATE nv_kennwortrichtlinie
   SET require_symbol = 2
 WHERE singleton_id = 1" >"$failure_log" 2>&1; then
    echo "schema migrator test: non-boolean password requirement was accepted" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_kennwortrichtlinie (singleton_id)
VALUES (2)" >"$failure_log" 2>&1; then
    echo "schema migrator test: second password-policy row was accepted" >&2
    exit 1
fi
assert_equal "12|0|1" "$(fixture_query "
SELECT CONCAT(
         minimum_length, '|', require_symbol, '|',
         (SELECT COUNT(*) FROM nv_kennwortrichtlinie)
       )
  FROM nv_kennwortrichtlinie
 WHERE singleton_id = 1")" \
    "password-policy constraints changed the canonical singleton after rejection"

fixture_query "
DELETE FROM nv_kennwortrichtlinie;
DELETE FROM estab_schema_migrations
 WHERE version = '113-password-policy.sql'"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|12|0|0|0|0|0|migration-113" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '113-password-policy.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         minimum_length, '|', require_uppercase, '|', require_lowercase, '|',
         require_digit, '|', require_symbol, '|', revision, '|', updated_by
       )
  FROM nv_kennwortrichtlinie
 WHERE singleton_id = 1")" \
    "canonical password-policy table without singleton was not safely resumed"

fixture_query "
UPDATE nv_kennwortrichtlinie
   SET minimum_length = 24,
       require_uppercase = 1,
       require_lowercase = 1,
       require_digit = 1,
       require_symbol = 1,
       revision = 7,
       updated_at = '2026-01-02 03:04:05.123456',
       updated_by = 'schema-retry'
 WHERE singleton_id = 1;
DELETE FROM estab_schema_migrations
 WHERE version = '113-password-policy.sql'"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|24|1|1|1|1|7|2026-01-02 03:04:05.123456|schema-retry" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '113-password-policy.sql'
             AND state = 'applied'), '|',
         minimum_length, '|', require_uppercase, '|', require_lowercase, '|',
         require_digit, '|', require_symbol, '|', revision, '|',
         DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s.%f'), '|', updated_by
       )
  FROM nv_kennwortrichtlinie
 WHERE singleton_id = 1")" \
    "password-policy migration retry overwrote configured values"

# A table marker is not sufficient ownership evidence. Keep the canonical
# table/column comments but change one flag default; migration 113 must reject
# this marked partial schema before seeding or acknowledging it.
fixture_query "
ALTER TABLE nv_kennwortrichtlinie
  MODIFY COLUMN require_symbol TINYINT UNSIGNED NOT NULL DEFAULT 1
  COMMENT 'estab:migration:113:require-symbol:v1'
  AFTER require_digit;
DELETE FROM estab_schema_migrations
 WHERE version = '113-password-policy.sql'"
if ESTAB_DB_NAME="$test_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: marked password-policy flag default drift was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Password-policy migration blocked: foreign table collision' \
    "$failure_log"; then
    echo "schema migrator test: marked password-policy drift failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "1|6|estab:migration:113:require-symbol:v1|estab:migration:113:password-policy:v1|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT column_default FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_kennwortrichtlinie'
             AND column_name = 'require_symbol'), '|',
         (SELECT ordinal_position FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_kennwortrichtlinie'
             AND column_name = 'require_symbol'), '|',
         (SELECT column_comment FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_kennwortrichtlinie'
             AND column_name = 'require_symbol'), '|',
         (SELECT table_comment FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_kennwortrichtlinie'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '113-password-policy.sql')
       )")" \
    "marked password-policy drift was changed or recorded"
fixture_query "
ALTER TABLE nv_kennwortrichtlinie
  MODIFY COLUMN require_symbol TINYINT UNSIGNED NOT NULL DEFAULT 0
  COMMENT 'estab:migration:113:require-symbol:v1'
  AFTER require_digit"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|0|24|1|7|schema-retry" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '113-password-policy.sql'
             AND state = 'applied'), '|',
         (SELECT column_default FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_kennwortrichtlinie'
             AND column_name = 'require_symbol'), '|',
         minimum_length, '|', require_symbol, '|', revision, '|', updated_by
       )
  FROM nv_kennwortrichtlinie
 WHERE singleton_id = 1")" \
    "password-policy migration did not recover after marked schema repair"

fixture_query "
UPDATE nv_kennwortrichtlinie
   SET minimum_length = 12,
       require_uppercase = 0,
       require_lowercase = 0,
       require_digit = 0,
       require_symbol = 0,
       revision = 0,
       updated_by = 'migration-113'
 WHERE singleton_id = 1;
DELETE FROM estab_schema_migrations
 WHERE version = '113-password-policy.sql';
ALTER TABLE nv_kennwortrichtlinie
  COMMENT = 'foreign-password-policy-owner'"
if ESTAB_DB_NAME="$test_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign password-policy table was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Password-policy migration blocked: foreign table collision' \
    "$failure_log"; then
    echo "schema migrator test: password-policy collision failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "foreign-password-policy-owner|12|0|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT table_comment FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_kennwortrichtlinie'), '|',
         minimum_length, '|', require_symbol, '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '113-password-policy.sql')
       )
  FROM nv_kennwortrichtlinie
 WHERE singleton_id = 1")" \
    "blocked password-policy collision was changed or recorded"
fixture_query "
ALTER TABLE nv_kennwortrichtlinie
  COMMENT = 'estab:migration:113:password-policy:v1'"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|12|0|0|0|0|0|migration-113" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '113-password-policy.sql'
             AND state = 'applied'), '|',
         minimum_length, '|', require_uppercase, '|', require_lowercase, '|',
         require_digit, '|', require_symbol, '|', revision, '|', updated_by
       )
  FROM nv_kennwortrichtlinie
 WHERE singleton_id = 1")" \
    "password-policy migration did not recover after removing the collision"

# Migration 114 must only ever replace its own missing ledger acknowledgement.
# Snapshot every field of the existing twenty-one records so retries and rejected
# collisions prove that the released history remains byte-for-byte unchanged.
pre_114_ledger_snapshot="$(fixture_query "
SELECT GROUP_CONCAT(
         CONCAT(
           version, ':', checksum, ':', state, ':',
           COALESCE(run_id, 'NULL'), ':',
           DATE_FORMAT(started_at, '%Y-%m-%d %H:%i:%s.%f'), ':',
           COALESCE(
             DATE_FORMAT(applied_at, '%Y-%m-%d %H:%i:%s.%f'),
             'NULL'
           )
         )
         ORDER BY version SEPARATOR ','
       )
  FROM estab_schema_migrations
 WHERE version <> '114-self-registration-policy.sql'")"
assert_equal "21|21" "$(fixture_query "
SELECT CONCAT(
         COUNT(*), '|',
         SUM(state = 'applied' AND checksum REGEXP BINARY '^[0-9a-f]{64}$')
       )
  FROM estab_schema_migrations
 WHERE version <> '114-self-registration-policy.sql'")" \
    "migration 114 predecessor ledger fixture is incomplete"

# Both the singleton and mode/deadline invariants are database constraints so
# direct writers cannot bypass the administrative policy model.
if fixture_query "
INSERT INTO nv_selbstregistrierung (singleton_id)
VALUES (2)" >"$failure_log" 2>&1; then
    echo "schema migrator test: second self-registration policy row was accepted" >&2
    exit 1
fi
if fixture_query "
UPDATE nv_selbstregistrierung
   SET mode = 'UNTIL'
 WHERE singleton_id = 1" >"$failure_log" 2>&1; then
    echo "schema migrator test: timed self-registration policy without a deadline was accepted" >&2
    exit 1
fi
if fixture_query "
UPDATE nv_selbstregistrierung
   SET enabled_until_utc = '2026-12-31 23:59:59.123456'
 WHERE singleton_id = 1" >"$failure_log" 2>&1; then
    echo "schema migrator test: non-timed self-registration policy with a deadline was accepted" >&2
    exit 1
fi
assert_equal "ENVIRONMENT|1|0|migration-114|1" "$(fixture_query "
SELECT CONCAT(
         mode, '|', enabled_until_utc IS NULL, '|', revision, '|', updated_by,
         '|', (SELECT COUNT(*) FROM nv_selbstregistrierung)
       )
  FROM nv_selbstregistrierung
 WHERE singleton_id = 1")" \
    "self-registration constraints changed the canonical singleton after rejection"

# A crash may leave the owned table without its seed row. Reapplying migration
# 114 twice must seed exactly once and produce one checksum-valid ledger row.
fixture_query "
DELETE FROM nv_selbstregistrierung;
DELETE FROM estab_schema_migrations
 WHERE version = '114-self-registration-policy.sql'"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|1|ENVIRONMENT|1|0|migration-114" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '114-self-registration-policy.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         (SELECT COUNT(*) FROM nv_selbstregistrierung), '|',
         mode, '|', enabled_until_utc IS NULL, '|', revision, '|', updated_by
       )
  FROM nv_selbstregistrierung
 WHERE singleton_id = 1")" \
    "canonical self-registration table without singleton was not safely resumed"

# A ledger retry must adopt a configured canonical row without resetting its
# timed policy, deadline, audit metadata, or revision.
fixture_query "
UPDATE nv_selbstregistrierung
   SET mode = 'UNTIL',
       enabled_until_utc = '2026-12-31 23:59:59.123456',
       revision = 7,
       updated_at = '2026-01-02 03:04:05.123456',
       updated_by = 'schema-retry'
 WHERE singleton_id = 1;
DELETE FROM estab_schema_migrations
 WHERE version = '114-self-registration-policy.sql'"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|UNTIL|2026-12-31 23:59:59.123456|7|2026-01-02 03:04:05.123456|schema-retry" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '114-self-registration-policy.sql'
             AND state = 'applied'), '|',
         mode, '|',
         DATE_FORMAT(enabled_until_utc, '%Y-%m-%d %H:%i:%s.%f'), '|',
         revision, '|', DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s.%f'),
         '|', updated_by
       )
  FROM nv_selbstregistrierung
 WHERE singleton_id = 1")" \
    "self-registration migration retry overwrote configured values"

# A familiar constraint name is not ownership evidence. Replace the deadline
# rule by a weak same-named CHECK; migration 114 must fail before it mutates the
# configured row or acknowledges the migration.
fixture_query "
ALTER TABLE nv_selbstregistrierung
  DROP CONSTRAINT chk_selbstregistrierung_deadline,
  ADD CONSTRAINT chk_selbstregistrierung_deadline CHECK (1 = 1);
DELETE FROM estab_schema_migrations
 WHERE version = '114-self-registration-policy.sql'"
if ESTAB_DB_NAME="$test_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: manipulated self-registration deadline CHECK was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Self-registration migration blocked: foreign table collision' \
    "$failure_log"; then
    echo "schema migrator test: manipulated self-registration CHECK failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "1|0|0|UNTIL|2026-12-31 23:59:59.123456|7|schema-retry" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM information_schema.table_constraints
           WHERE constraint_schema = DATABASE()
             AND table_name = 'nv_selbstregistrierung'
             AND constraint_name = 'chk_selbstregistrierung_deadline'
             AND constraint_type = 'CHECK'), '|',
         (SELECT COUNT(*)
            FROM information_schema.table_constraints AS table_constraint
            JOIN information_schema.check_constraints AS check_constraint
              ON check_constraint.constraint_schema =
                   table_constraint.constraint_schema
             AND check_constraint.constraint_name =
                   table_constraint.constraint_name
           WHERE table_constraint.constraint_schema = DATABASE()
             AND table_constraint.table_name = 'nv_selbstregistrierung'
             AND table_constraint.constraint_name =
                   'chk_selbstregistrierung_deadline'
             AND check_constraint.check_clause LIKE
                   '%enabled_until_utc%'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '114-self-registration-policy.sql'), '|',
         mode, '|',
         DATE_FORMAT(enabled_until_utc, '%Y-%m-%d %H:%i:%s.%f'), '|',
         revision, '|', updated_by
       )
  FROM nv_selbstregistrierung
 WHERE singleton_id = 1")" \
    "blocked self-registration CHECK collision was changed or recorded"
assert_equal "$pre_114_ledger_snapshot" "$(fixture_query "
SELECT GROUP_CONCAT(
         CONCAT(
           version, ':', checksum, ':', state, ':',
           COALESCE(run_id, 'NULL'), ':',
           DATE_FORMAT(started_at, '%Y-%m-%d %H:%i:%s.%f'), ':',
           COALESCE(
             DATE_FORMAT(applied_at, '%Y-%m-%d %H:%i:%s.%f'),
             'NULL'
           )
         )
         ORDER BY version SEPARATOR ','
       )
  FROM estab_schema_migrations
 WHERE version <> '114-self-registration-policy.sql'")" \
    "migration 114 rewrote one of the existing twenty-one ledger rows"

fixture_query "
ALTER TABLE nv_selbstregistrierung
  DROP CONSTRAINT chk_selbstregistrierung_deadline,
  ADD CONSTRAINT chk_selbstregistrierung_deadline
    CHECK (
      (mode = 'UNTIL' AND enabled_until_utc IS NOT NULL)
      OR (mode <> 'UNTIL' AND enabled_until_utc IS NULL)
    )"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|1|UNTIL|2026-12-31 23:59:59.123456|7|schema-retry" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '114-self-registration-policy.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*)
            FROM information_schema.table_constraints AS table_constraint
            JOIN information_schema.check_constraints AS check_constraint
              ON check_constraint.constraint_schema =
                   table_constraint.constraint_schema
             AND check_constraint.constraint_name =
                   table_constraint.constraint_name
           WHERE table_constraint.constraint_schema = DATABASE()
             AND table_constraint.table_name = 'nv_selbstregistrierung'
             AND table_constraint.constraint_name =
                   'chk_selbstregistrierung_deadline'
             AND check_constraint.check_clause LIKE
                   '%enabled_until_utc%'), '|',
         mode, '|',
         DATE_FORMAT(enabled_until_utc, '%Y-%m-%d %H:%i:%s.%f'), '|',
         revision, '|', updated_by
       )
  FROM nv_selbstregistrierung
 WHERE singleton_id = 1")" \
    "self-registration migration did not recover after CHECK repair"

# The durable table marker is also part of the ownership contract. A foreign
# table with the same name must remain untouched and absent from the ledger.
fixture_query "
UPDATE nv_selbstregistrierung
   SET mode = 'ENVIRONMENT',
       enabled_until_utc = NULL,
       revision = 0,
       updated_at = '2026-01-02 03:04:05.123456',
       updated_by = 'migration-114'
 WHERE singleton_id = 1;
DELETE FROM estab_schema_migrations
 WHERE version = '114-self-registration-policy.sql';
ALTER TABLE nv_selbstregistrierung
  COMMENT = 'foreign-self-registration-owner'"
if ESTAB_DB_NAME="$test_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign self-registration table was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Self-registration migration blocked: foreign table collision' \
    "$failure_log"; then
    echo "schema migrator test: self-registration table collision failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "foreign-self-registration-owner|ENVIRONMENT|1|0|migration-114|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT table_comment FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_selbstregistrierung'), '|',
         mode, '|', enabled_until_utc IS NULL, '|', revision, '|', updated_by,
         '|', (SELECT COUNT(*) FROM estab_schema_migrations
                WHERE version = '114-self-registration-policy.sql')
       )
  FROM nv_selbstregistrierung
 WHERE singleton_id = 1")" \
    "blocked self-registration table collision was changed or recorded"
fixture_query "
ALTER TABLE nv_selbstregistrierung
  COMMENT = 'estab:migration:114:self-registration-policy:v1'"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|ENVIRONMENT|1|0|migration-114" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '114-self-registration-policy.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$'), '|',
         mode, '|', enabled_until_utc IS NULL, '|', revision, '|', updated_by
       )
  FROM nv_selbstregistrierung
 WHERE singleton_id = 1")" \
    "self-registration migration did not recover after removing the collision"
assert_equal "$pre_114_ledger_snapshot" "$(fixture_query "
SELECT GROUP_CONCAT(
         CONCAT(
           version, ':', checksum, ':', state, ':',
           COALESCE(run_id, 'NULL'), ':',
           DATE_FORMAT(started_at, '%Y-%m-%d %H:%i:%s.%f'), ':',
           COALESCE(
             DATE_FORMAT(applied_at, '%Y-%m-%d %H:%i:%s.%f'),
             'NULL'
           )
         )
         ORDER BY version SEPARATOR ','
       )
  FROM estab_schema_migrations
 WHERE version <> '114-self-registration-policy.sql'")" \
    "migration 114 rewrote one of the existing twenty-one ledger rows"

# Reproduce interruption after some autocommitted migration-99 phases. The
# canonical status/time index remains, the direction/number index is missing,
# and the released one-column full-text index exists again. A rerun must first
# create the wider search index, then remove the released index and finish the
# missing phase without disturbing the already canonical index.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version = '99-message-list-search.sql';
ALTER TABLE nv_nachrichten
  DROP INDEX ft_nachrichten_suche,
  DROP INDEX idx_nachrichten_einsatz_richtung_nummer,
  ADD FULLTEXT INDEX ft_nachrichten_inhalt (\`12_inhalt\`)"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "$canonical_message_indexes" "$(message_index_snapshot)" \
    "partial message-list index migration did not resume canonically"
assert_equal "1" "$(fixture_query "
SELECT COUNT(*) FROM estab_schema_migrations
 WHERE version = '99-message-list-search.sql'
   AND state = 'applied'
   AND checksum REGEXP BINARY '^[0-9a-f]{64}$'")" \
    "resumed message-list index migration was not recorded"

# A foreign definition reusing an owned name must fail closed before changing
# any index. This also proves that a failed phase never receives a ledger row.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version = '99-message-list-search.sql';
ALTER TABLE nv_nachrichten
  DROP INDEX ft_nachrichten_suche,
  ADD FULLTEXT INDEX ft_nachrichten_suche (\`12_inhalt\`)"
if ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign message search index was accepted" >&2
    exit 1
fi
if ! grep -q 'Message-list index migration blocked: foreign search full-text index collision' "$failure_log"; then
    echo "schema migrator test: message search collision failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "12_inhalt|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(
                   column_name ORDER BY seq_in_index SEPARATOR ','
                 )
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_nachrichten'
             AND index_name = 'ft_nachrichten_suche'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '99-message-list-search.sql')
       )")" \
    "blocked message-list search-index collision was changed or recorded"
fixture_query "ALTER TABLE nv_nachrichten DROP INDEX ft_nachrichten_suche"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "$canonical_message_indexes" "$(message_index_snapshot)" \
    "message-list indexes did not recover after removing the collision"

# Migration 100 may lose its ledger acknowledgement after the owned activity
# column was committed but before the presence index was created. The exact
# owned column must be adopted, the missing index added, and the ledger entry
# restored without operator intervention.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version = '100-session-presence.sql';
ALTER TABLE nv_benutzer
  DROP INDEX idx_benutzer_presence"
assert_equal "1|0|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*)
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_benutzer'
             AND column_name = 'estab_letzte_aktivitaet'
             AND data_type = 'datetime'
             AND datetime_precision = 6
             AND is_nullable = 'YES'
             AND column_comment =
               'estab:migration:100:last-browser-activity-utc:v1'), '|',
         (SELECT COUNT(*)
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_benutzer'
             AND index_name = 'idx_benutzer_presence'), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '100-session-presence.sql')
       )")" \
    "partial session-presence column phase was not reproduced"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "3|aktiv,estab_gesperrt,estab_letzte_aktivitaet|1" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*)
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_benutzer'
             AND index_name = 'idx_benutzer_presence'
             AND index_type = 'BTREE'
             AND non_unique = 1
             AND sub_part IS NULL), '|',
         (SELECT GROUP_CONCAT(
                   column_name ORDER BY seq_in_index SEPARATOR ','
                 )
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_benutzer'
             AND index_name = 'idx_benutzer_presence'), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '100-session-presence.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$')
       )")" \
    "partial session-presence migration did not resume canonically"

# A same-name activity column without the exact ownership marker is foreign.
# It must survive unchanged and must never receive an applied ledger record.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version = '100-session-presence.sql';
ALTER TABLE nv_benutzer
  MODIFY COLUMN estab_letzte_aktivitaet DATETIME(6) NULL DEFAULT NULL
  COMMENT 'foreign-session-activity-owner'
  AFTER estab_gesperrt"
if ESTAB_DB_NAME="$test_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign session activity column was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Session-presence migration blocked: foreign activity column collision' \
    "$failure_log"; then
    echo "schema migrator test: session activity column collision failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "foreign-session-activity-owner|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT column_comment
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_benutzer'
             AND column_name = 'estab_letzte_aktivitaet'), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '100-session-presence.sql')
       )")" \
    "blocked session activity column collision was changed or recorded"
fixture_query "
ALTER TABLE nv_benutzer
  MODIFY COLUMN estab_letzte_aktivitaet DATETIME(6) NULL DEFAULT NULL
  COMMENT 'estab:migration:100:last-browser-activity-utc:v1'
  AFTER estab_gesperrt"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"

# The owned index name is equally protected: a different column order must
# block before any schema rewrite or ledger acknowledgement takes place.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version = '100-session-presence.sql';
ALTER TABLE nv_benutzer
  DROP INDEX idx_benutzer_presence,
  ADD INDEX idx_benutzer_presence (
    estab_gesperrt, aktiv, estab_letzte_aktivitaet
  )"
if ESTAB_DB_NAME="$test_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign session presence index was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Session-presence migration blocked: foreign presence index collision' \
    "$failure_log"; then
    echo "schema migrator test: session presence index collision failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "estab_gesperrt,aktiv,estab_letzte_aktivitaet|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(
                   column_name ORDER BY seq_in_index SEPARATOR ','
                 )
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_benutzer'
             AND index_name = 'idx_benutzer_presence'), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '100-session-presence.sql')
       )")" \
    "blocked session presence index collision was changed or recorded"
fixture_query "ALTER TABLE nv_benutzer DROP INDEX idx_benutzer_presence"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "aktiv,estab_gesperrt,estab_letzte_aktivitaet|1" "$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(
                   column_name ORDER BY seq_in_index SEPARATOR ','
                 )
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_benutzer'
             AND index_name = 'idx_benutzer_presence'), '|',
         (SELECT COUNT(*)
            FROM estab_schema_migrations
           WHERE version = '100-session-presence.sql'
             AND state = 'applied'
             AND checksum REGEXP BINARY '^[0-9a-f]{64}$')
       )")" \
    "session presence index did not recover after removing the collision"

# Reproduce process loss during migration 110 after its columns, numbering and
# most indexes exist, but before the message FK/index and final TTB delete guard
# are durable. Every owned phase must converge without renumbering history.
logbook_number_snapshot="$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(CONCAT(\`etb_lfd-nr\`, ':', estab_book_lfd)
                  ORDER BY \`etb_lfd-nr\` SEPARATOR ',') FROM nv_etb), '|',
         (SELECT GROUP_CONCAT(CONCAT(\`tbb_lfd-nr\`, ':', estab_book_lfd)
                  ORDER BY \`tbb_lfd-nr\` SEPARATOR ',') FROM nv_tbb)
       )")"
fixture_query "
INSERT INTO nv_einsaetze
  (kennung, name, beginn, ende, ort, organisation, fuehrungsstellenname,
   einsatzleitung, beschreibung, metadaten, erstellt_am, erstellt_von)
VALUES
  ('SCHEMA-EMPTY-UPGRADE', 'Empty pre-existing incident', NOW(), NULL,
   '', '', 'Empty command post', '', 'No logbook rows yet.', '{}', NOW(6),
   'schema-migrator-test');
DELETE FROM estab_schema_migrations
 WHERE version IN (
   '110-etb-tbb-rules.sql', '111-logbook-shift-assignment.sql',
   '112-optional-access-shifts.sql'
 );
DROP TRIGGER estab_einsaetze_ai_logbook_heads;
DELETE FROM nv_logbuch_koepfe
 WHERE einsatz_id = (
   SELECT einsatz_id FROM nv_einsaetze
    WHERE kennung = 'SCHEMA-EMPTY-UPGRADE'
 );
ALTER TABLE nv_tbb DROP FOREIGN KEY fk_tbb_message;
ALTER TABLE nv_tbb DROP INDEX idx_tbb_message;
DROP TRIGGER estab_tbb_bd_einsatz"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "$logbook_number_snapshot|2|1|1|1|2|ETB:1,TTB:1" "$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(CONCAT(\`etb_lfd-nr\`, ':', estab_book_lfd)
                  ORDER BY \`etb_lfd-nr\` SEPARATOR ',') FROM nv_etb), '|',
         (SELECT GROUP_CONCAT(CONCAT(\`tbb_lfd-nr\`, ':', estab_book_lfd)
                  ORDER BY \`tbb_lfd-nr\` SEPARATOR ',') FROM nv_tbb), '|',
         (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = DATABASE() AND table_name = 'nv_tbb'
             AND index_name = 'idx_tbb_message'), '|',
         (SELECT COUNT(*) FROM information_schema.referential_constraints
           WHERE constraint_schema = DATABASE()
             AND constraint_name = 'fk_tbb_message'), '|',
         (SELECT COUNT(*) FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND trigger_name = 'estab_tbb_bd_einsatz'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '110-etb-tbb-rules.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-EMPTY-UPGRADE'
           )), '|',
         (SELECT GROUP_CONCAT(CONCAT(buchart, ':', next_lfd)
                  ORDER BY buchart SEPARATOR ',')
            FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-EMPTY-UPGRADE'
           ))
       )")" \
    "partial logbook migration did not restore empty pre-existing book heads canonically"

# A same-name TTB column without migration 110's ownership marker is foreign.
# The migrator must fail before changing it or acknowledging the migration.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version IN (
   '110-etb-tbb-rules.sql', '111-logbook-shift-assignment.sql',
   '112-optional-access-shifts.sql'
 );
ALTER TABLE nv_tbb
  MODIFY COLUMN estab_message_id BIGINT NULL DEFAULT NULL
  COMMENT 'foreign-tbb-message-owner'
  AFTER estab_entry_type"
if ESTAB_DB_NAME="$test_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign logbook column was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Logbook rules migration blocked: foreign or partial TTB column collision' \
    "$failure_log"; then
    echo "schema migrator test: logbook column collision failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "foreign-tbb-message-owner|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT column_comment FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'nv_tbb'
             AND column_name = 'estab_message_id'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '110-etb-tbb-rules.sql')
       )")" \
    "blocked logbook column collision was changed or recorded"
fixture_query "
ALTER TABLE nv_tbb
  MODIFY COLUMN estab_message_id BIGINT NULL DEFAULT NULL
  COMMENT 'estab:migration:110:tbb-message:v1'
  AFTER estab_entry_type"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"

# Reproduce process loss during migration 111 after only a canonical prefix
# was durably committed. Nullable provenance remains unknown on historic rows;
# all missing columns, indexes, constraints and insert triggers must converge.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version IN (
   '111-logbook-shift-assignment.sql', '112-optional-access-shifts.sql'
 );
DROP TRIGGER estab_etb_bi_einsatz;
DROP TRIGGER estab_tbb_bi_einsatz;
ALTER TABLE nv_etb
  DROP FOREIGN KEY fk_etb_assignee_assignment,
  DROP INDEX idx_etb_assignee_assignment,
  DROP COLUMN estab_assignment,
  DROP COLUMN estab_assignee_assignment_id;
ALTER TABLE nv_tbb
  DROP FOREIGN KEY fk_tbb_writer_assignment,
  DROP INDEX idx_tbb_writer_assignment,
  DROP COLUMN estab_writer_assignment_id"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1|1|2|6|5|5|4|3|3" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '111-logbook-shift-assignment.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '112-optional-access-shifts.sql'
             AND state = 'applied'), '|',
         (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE()
             AND table_name IN (
               'nv_zugangsschichten', 'nv_zugangsschicht_mitglieder'
             ) AND table_comment =
               'estab:migration:112:optional-access-shifts:v1'), '|',
         (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND ((table_name = 'nv_etb' AND column_name IN (
                    'estab_shift_id', 'estab_writer_assignment_id',
                    'estab_assignee_assignment_id', 'estab_assignment'
                  )) OR (table_name = 'nv_tbb' AND column_name IN (
                    'estab_shift_id', 'estab_writer_assignment_id'
                  )))), '|',
         (SELECT COUNT(DISTINCT index_name)
            FROM information_schema.statistics
           WHERE table_schema = DATABASE() AND index_name IN (
             'idx_etb_einsatz_shift_book', 'idx_etb_writer_assignment',
             'idx_etb_assignee_assignment', 'idx_tbb_einsatz_shift_book',
             'idx_tbb_writer_assignment'
           )), '|',
         (SELECT COUNT(*) FROM information_schema.referential_constraints
           WHERE constraint_schema = DATABASE() AND constraint_name IN (
             'fk_etb_shift', 'fk_etb_writer_assignment',
             'fk_etb_assignee_assignment', 'fk_tbb_shift',
             'fk_tbb_writer_assignment'
           )), '|',
         (SELECT COUNT(*) FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND ((trigger_name = 'estab_etb_bi_einsatz'
                   AND action_statement LIKE
                         '%ETB optional duty provenance must be complete%')
               OR (trigger_name = 'estab_tbb_bi_einsatz'
                   AND action_statement LIKE
                         '%TTB optional duty provenance must be complete%')
               OR (trigger_name = 'estab_log111_handover_insert_time'
                   AND action_statement LIKE
                         '%Duty handover completion times are inconsistent%')
               OR (trigger_name = 'estab_log111_handover_confirm_time'
                   AND action_statement LIKE
                         '%Duty handover confirmation times are inconsistent%'))), '|',
         (SELECT COUNT(*) FROM nv_etb
           WHERE estab_shift_id IS NULL
             AND estab_writer_assignment_id IS NULL
             AND estab_assignee_assignment_id IS NULL
             AND estab_assignment IS NULL), '|',
         (SELECT COUNT(*) FROM nv_tbb
           WHERE estab_shift_id IS NULL
             AND estab_writer_assignment_id IS NULL)
       )")" \
    "partial logbook and optional access-shift migrations did not resume canonically"

# A same-name provenance column without migration 111's ownership marker is
# foreign. It must survive unchanged and receive no ledger acknowledgement.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version IN (
   '111-logbook-shift-assignment.sql', '112-optional-access-shifts.sql'
 );
ALTER TABLE nv_etb
  MODIFY COLUMN estab_assignment VARCHAR(255)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL
    COMMENT 'foreign-logbook-assignment-owner'
    AFTER estab_assignee_assignment_id"
if ESTAB_DB_NAME="$test_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign logbook shift column was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Logbook shift migration blocked: foreign column collision' \
    "$failure_log"; then
    echo "schema migrator test: logbook shift column collision was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "foreign-logbook-assignment-owner|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT column_comment FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
             AND column_name = 'estab_assignment'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '111-logbook-shift-assignment.sql')
       )")" \
    "blocked logbook shift collision was changed or recorded"
fixture_query "
ALTER TABLE nv_etb
  MODIFY COLUMN estab_assignment VARCHAR(255)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL
    COMMENT 'estab:migration:111:etb-assignment-snapshot:v1'
    AFTER estab_assignee_assignment_id"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"

# A same-name handover-time trigger with foreign logic must not be dropped or
# silently replaced when migration 111 is resumed.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version IN (
   '111-logbook-shift-assignment.sql', '112-optional-access-shifts.sql'
 );
DROP TRIGGER estab_log111_handover_insert_time;
CREATE TRIGGER estab_log111_handover_insert_time
  BEFORE INSERT ON nv_dienstuebergaben
  FOR EACH ROW SET @estab_foreign_handover_time_trigger = 1"
if ESTAB_DB_NAME="$test_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign handover time trigger was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Logbook shift migration blocked: foreign trigger collision' \
    "$failure_log"; then
    echo "schema migrator test: handover trigger collision was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "1|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND trigger_name = 'estab_log111_handover_insert_time'
             AND action_statement LIKE
                   '%estab_foreign_handover_time_trigger%'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '111-logbook-shift-assignment.sql')
       )")" \
    "blocked handover trigger collision was changed or recorded"
fixture_query "DROP TRIGGER estab_log111_handover_insert_time"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"

# A constraint with the owned name and target table but a different local
# column is still foreign. This proves the migration checks KEY_COLUMN_USAGE.
fixture_query "
DELETE FROM estab_schema_migrations
 WHERE version IN (
   '111-logbook-shift-assignment.sql', '112-optional-access-shifts.sql'
 );
ALTER TABLE nv_etb
  DROP FOREIGN KEY fk_etb_assignee_assignment;
ALTER TABLE nv_etb
  ADD CONSTRAINT fk_etb_assignee_assignment
    FOREIGN KEY (estab_writer_assignment_id)
    REFERENCES nv_dienstbesetzungen (dienstbesetzung_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT"
if ESTAB_DB_NAME="$test_database" \
    "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: foreign logbook shift constraint was accepted" >&2
    exit 1
fi
if ! grep -q \
    'Logbook shift migration blocked: foreign constraint collision' \
    "$failure_log"; then
    echo "schema migrator test: logbook shift constraint collision was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "estab_writer_assignment_id|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT column_name FROM information_schema.key_column_usage
           WHERE constraint_schema = DATABASE()
             AND table_name = 'nv_etb'
             AND constraint_name = 'fk_etb_assignee_assignment'), '|',
         (SELECT COUNT(*) FROM estab_schema_migrations
           WHERE version = '111-logbook-shift-assignment.sql')
       )")" \
    "blocked logbook shift constraint collision was changed or recorded"
fixture_query "ALTER TABLE nv_etb
  DROP FOREIGN KEY fk_etb_assignee_assignment"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"

assert_equal "1|1|1|2|4|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*)
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_einsaetze'
             AND column_name = 'fuehrungsstellenname'
             AND column_type = 'varchar(128)'
             AND character_set_name = 'utf8mb4'
             AND collation_name = 'utf8mb4_unicode_ci'
             AND is_nullable = 'YES'
             AND (
               column_default IS NULL
               OR UPPER(column_default) = 'NULL'
             )
             AND ordinal_position = (
               SELECT ordinal_position + 1
                 FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'nv_einsaetze'
                  AND column_name = 'organisation'
             )
	             AND column_comment =
	               'estab:migration:97:incident-command-post-name:v1'), '|',
         (SELECT COUNT(*)
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_einsaetze'
             AND column_name = 'fuehrungsstellenname_gesperrt'
             AND data_type = 'tinyint'
             AND column_type LIKE 'tinyint%unsigned'
             AND is_nullable = 'NO'
             AND column_default = '0'
             AND ordinal_position = (
               SELECT ordinal_position + 2
                 FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'nv_einsaetze'
                  AND column_name = 'organisation'
             )
             AND column_comment =
               'estab:migration:97:incident-command-post-lock:v1'), '|',
         (SELECT COUNT(*) FROM nv_einsaetze
           WHERE kennung = 'LEGACY-IMPORT'
             AND fuehrungsstellenname IS NULL
             AND fuehrungsstellenname_gesperrt = 0), '|',
         (SELECT COUNT(*)
            FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND trigger_name IN (
               'estab_command_post_incident_insert',
               'estab_command_post_incident_update'
             )), '|',
         (SELECT COUNT(*)
            FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_type = 'FUNCTION'
             AND routine_name IN (
               'estab_incident_command_post_for_write',
               'estab_incident_for_insert',
               'estab_incident_for_update',
               'estab_incident_for_delete'
             )), '|',
         (SELECT COUNT(*)
            FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name IN (
               'estab_migrate_97_preflight',
               'estab_migrate_97_add_column',
               'estab_migrate_97_add_lock_column',
               'estab_migrate_97_validate',
               'estab_migrate_97_final_validate'
             ))
       )")" \
    "incident command-post name migration was not canonical or invented history"
assert_equal "20|20|1|0" "$(fixture_query "
SELECT CONCAT(
         COUNT(*), '|',
         COUNT(DISTINCT mtx_x, mtx_y), '|',
         SUM(mtx_fkt = 'S2' AND mtx_rolle = 'Stab'
             AND mtx_rc2 IN ('t','1')), '|',
         SUM(mtx_auto IN ('t','1'))
       )
  FROM nv_empfmtx")" \
    "missing legacy active matrix was not restored with the DV recipient contract"
assert_equal "20|20|20|1|0" "$(fixture_query "
SELECT CONCAT(
         COUNT(*), '|',
         COUNT(DISTINCT mtx_x, mtx_y), '|',
         SUM(mtx_x BETWEEN 1 AND 5 AND mtx_y BETWEEN 1 AND 4), '|',
         SUM(mtx_rc2 IN ('t','1')), '|',
         SUM(mtx_auto IN ('t','1'))
       )
  FROM nv_empfmtx_standard")" \
    "single standard recipient matrix was not seeded exactly"
assert_equal "$(cat "$standard_matrix_fixture")" "$(fixture_query "
SELECT CONCAT(
         mtx_x, '|', mtx_y, '|', mtx_typ, '|', mtx_fkt, '|', mtx_rolle, '|',
         mtx_mode, '|',
         IF(mtx_rc2 IN ('t','1'), '1', '0'), '|',
         IF(mtx_auto IN ('t','1'), '1', '0')
       )
  FROM nv_empfmtx_standard
 ORDER BY mtx_x, mtx_y")" \
    "standard recipient matrix differs from the historical 20-cell fixture"
assert_equal "8" "$(fixture_query "
SELECT COUNT(*)
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND character_maximum_length = 6
   AND (
     (table_name = 'nv_benutzer' AND column_name = 'kuerzel')
     OR (table_name = 'nv_anhang' AND column_name = 'kuerzel')
     OR (table_name = 'nv_nachrichten' AND column_name IN (
       '01_zeichen','02_zeichen','03_zeichen',
       '14_zeichen','15_quitzeichen','x03_sperruser'
     ))
   )")" \
    "one or more six-character code columns were not migrated"
assert_equal "3" "$(fixture_query "
SELECT COUNT(*)
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name = 'nv_benutzer'
   AND (
     (column_name = 'password' AND character_maximum_length = 255)
     OR (column_name IN ('ip','fwdip') AND character_maximum_length = 45)
   )")" \
    "user credential/address widths were not migrated"
assert_equal "2" "$(fixture_query "
SELECT COUNT(*)
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name = 'nv_anhang'
   AND (
     (column_name = 'fileext' AND character_maximum_length = 16)
     OR (column_name = 'id' AND character_maximum_length = 128)
   )")" \
    "attachment metadata widths were not migrated"
assert_equal "4|2|1" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*)
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_anhang'
             AND column_name IN (
               'integrity_required', 'ingest_sha256', 'ingest_size',
               'integrity_captured_at'
             )), '|',
         (SELECT COUNT(*)
            FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND trigger_name IN (
               'estab_attachment_integrity_bi',
               'estab_attachment_integrity_bu'
             )), '|',
         (SELECT COUNT(*)
            FROM nv_anhang
           WHERE integrity_required = 0
             AND ingest_sha256 IS NULL
             AND ingest_size IS NULL
             AND integrity_captured_at IS NULL)
       )")" \
    "attachment integrity schema or explicit legacy marker is incomplete"
assert_equal "4|1|4|2|2|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*)
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_anhang'
             AND column_name IN (
               'integrity_required', 'ingest_sha256', 'ingest_size',
               'integrity_captured_at'
             )), '|',
         (SELECT COUNT(*)
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_anhang'
             AND column_name = 'integrity_required'
             AND column_default = '1'), '|',
         (SELECT COUNT(*)
            FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_anhang'
             AND column_comment LIKE
               'estab:migration:95:%:v1'), '|',
         (SELECT COUNT(*)
            FROM information_schema.table_constraints
           WHERE constraint_schema = DATABASE()
             AND table_name = 'nv_anhang'
             AND constraint_name IN (
               'chk_anhang_integrity_required',
               'chk_anhang_integrity_shape'
             )), '|',
         (SELECT COUNT(*)
            FROM information_schema.triggers
           WHERE trigger_schema = DATABASE()
             AND event_object_table = 'nv_anhang'
             AND trigger_name IN (
               'estab_attachment_integrity_bi',
               'estab_attachment_integrity_bu'
             )), '|',
         (SELECT COUNT(*)
            FROM information_schema.routines
           WHERE routine_schema = DATABASE()
             AND routine_name IN (
               'estab_migrate_95_preflight',
               'estab_migrate_95_add_constraints'
             ))
       )")" \
    "partial attachment-integrity migration did not converge canonically"
assert_equal "funktion,aktiv" "$(fixture_query "
SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')
  FROM information_schema.statistics
 WHERE table_schema = DATABASE()
   AND table_name = 'nv_benutzer'
   AND index_name = 'idx_benutzer_funktion_aktiv'")" \
    "user lookup index has the wrong definition"
assert_equal "1" "$(fixture_query "
SELECT COUNT(*)
  FROM information_schema.statistics
 WHERE table_schema = DATABASE()
   AND table_name = 'nv_anhang'
   AND index_name = 'uq_anhang_filename'
   AND non_unique = 0
   AND seq_in_index = 1
   AND column_name = 'filename'")" \
    "attachment filename index is not uniquely enforced"
assert_equal "filename,status|id|md5hash" "$(fixture_query "
SELECT CONCAT(
  COALESCE((
    SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND index_name = 'idx_anhang_filename_status'
  ), ''),
  '|',
  COALESCE((
    SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND index_name = 'idx_anhang_id'
  ), ''),
  '|',
  COALESCE((
    SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND index_name = 'idx_anhang_md5hash'
  ), '')
)")" \
    "attachment lookup indexes have the wrong definitions"
assert_equal "1" "$(fixture_query "
SELECT COUNT(*) FROM nv_nachrichten
 WHERE \`01_datum\` IS NULL
   AND \`15_quitdatum\` IS NULL
   AND \`99_lstacc\` IS NULL")" \
    "zero-date values were not converted to NULL"
assert_equal "2019-02-03 04:05:06" "$(fixture_query "
SELECT DATE_FORMAT(\`99_lstacc\`, '%Y-%m-%d %H:%i:%s')
  FROM nv_nachrichten
 WHERE \`00_lfd\` = 2")" \
    "incident backfill changed a historic message last-access timestamp"
assert_equal "1" "$(fixture_query "
SELECT COUNT(*) FROM nv_bhp50
 WHERE \`lfd-nr\` = 1
   AND \`sich1_zeit\` IS NULL
   AND \`sich2_zeit\` IS NULL
   AND \`sich3_zeit\` IS NULL
   AND \`sich4_zeit\` IS NULL
   AND \`trans_start\` IS NULL")" \
    "incident backfill changed converted BHP-50 zero timestamps"
assert_equal "2018-01-02 03:04:05" "$(fixture_query "
SELECT DATE_FORMAT(\`sich1_zeit\`, '%Y-%m-%d %H:%i:%s')
  FROM nv_bhp50
 WHERE \`lfd-nr\` = 2")" \
    "incident backfill changed a historic BHP-50 timestamp"
assert_equal "legacy-secret" "$(fixture_query "
SELECT password FROM nv_benutzer WHERE kuerzel = 'abc'")" \
    "legacy password value changed during width migration"
assert_equal "0" "$(fixture_query "
SELECT COUNT(*)
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND LEFT(table_name, 3) = 'nv_'
   AND table_type = 'BASE TABLE'
   AND (
     engine <> 'InnoDB'
     OR table_collation <> 'utf8mb4_unicode_ci'
   )")" \
    "one or more discovered eStab tables retained a legacy engine/collation"
assert_equal "Müller erhält Größe" "$(fixture_query "
SELECT notiz FROM nv_legacy_read WHERE msg = 17")" \
    "dynamic-table text changed during charset conversion"
assert_equal "2" "$(fixture_query "
SELECT COUNT(*)
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name IN ('nv_etbtitel', 'nv_tbbtitel')")" \
    "lazy ETB/TBB title tables were not supplied"

# Migration 50 closes the imported history and leaves no incident active.
# Prove that its write boundary is already effective. The independent width
# checks below use a new open fixture incident; LEGACY-IMPORT itself must never
# be reactivated, even by this isolated test.
if fixture_query "
UPDATE nv_nachrichten
   SET \`01_zeichen\` = \`01_zeichen\`
 WHERE \`00_lfd\` = 1" >"$failure_log" 2>&1; then
    echo "schema migrator test: inactive legacy incident remained writable" >&2
    exit 1
fi
if ! grep -q 'Operational update targets inactive incident' "$failure_log"; then
    echo "schema migrator test: inactive legacy update failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
fixture_query "
INSERT INTO nv_einsaetze
  (kennung, name, beginn, ende, ort, organisation, fuehrungsstellenname,
   einsatzleitung,
   beschreibung, metadaten, erstellt_am, erstellt_von)
VALUES
  ('SCHEMA-WIDTH-TEST', 'Schema width test', NOW(), NULL, '', '',
   'Schema migration command post', '',
   'Isolated schema-migrator fixture.', '{}', NOW(6),
   'schema-migrator-test');
SET @estab_width_incident_id = LAST_INSERT_ID();
UPDATE nv_einsatz_status
   SET active_einsatz_id = @estab_width_incident_id,
       revision = revision + 1,
       geaendert_am = NOW(6),
       geaendert_von = 'schema-migrator-test'
 WHERE singleton_id = 1;
INSERT INTO nv_nachrichten
  (\`01_zeichen\`, \`02_zeichen\`, \`03_zeichen\`,
   \`12_inhalt\`, \`14_zeichen\`, \`15_quitzeichen\`, \`x03_sperruser\`)
VALUES ('wid', 'wid', 'wid', 'Schema width fixture', 'wid', 'wid', 'wid');
SET @estab_width_message_id = LAST_INSERT_ID();
INSERT INTO nv_anhang (filename, org_filename, kuerzel, status)
VALUES ('SCHEMA-WIDTH-TEST', 'schema-width-test.txt', 'wid', 4);
SET @estab_width_attachment_id = LAST_INSERT_ID();
SET SESSION sql_mode =
  'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
UPDATE nv_nachrichten
   SET \`01_zeichen\` = 'abcdef',
       \`02_zeichen\` = 'abcdef',
       \`03_zeichen\` = 'abcdef',
       \`14_zeichen\` = 'abcdef',
       \`15_quitzeichen\` = 'abcdef',
       \`x03_sperruser\` = 'abcdef'
 WHERE \`00_lfd\` = @estab_width_message_id;
UPDATE nv_benutzer
   SET kuerzel = 'abc123',
       funktion = 'S2',
       rolle = 'Stab',
       aktiv = 1,
       ip = '2001:db8:1:2:3:4:5:6',
       fwdip = '2001:db8:6:5:4:3:2:1'
 WHERE kuerzel = 'abc';
INSERT INTO nv_benutzer
  (benutzer, kuerzel, funktion, rolle, aktiv, password, estab_gesperrt)
VALUES
  ('Aufnahme Weitergabe', 'aw112', 'A/W', 'Fernmelder', 1, '', 0),
  ('ETB Führung', 'etb112', 'ETB', 'Stab', 1, '', 0),
  ('S6 Planung', 's6112', 'S6', 'Stab', 1, '', 0),
  ('Leitung Fernmeldezentrale', 'ldf112', 'LdF', 'Fernmelder', 1, '', 0),
  ('Sichtung', 'si112', 'Si', 'Stab', 1, '', 0);
UPDATE nv_anhang
   SET kuerzel = 'abc123'
 WHERE \`lfd-nr\` = @estab_width_attachment_id;
INSERT INTO nv_dienstschichten
  (einsatz_id, nummer, bezeichnung, status, erstellt_von)
VALUES
  (@estab_width_incident_id, 1, 'Schema width shift', 'GEPLANT',
   'schema-migrator-test');
SET @estab_width_shift_id = LAST_INSERT_ID();
INSERT INTO nv_dienstbesetzungen
  (dienstschicht_id, benutzer_kuerzel, funktion, rolle, status,
   zugewiesen_von)
VALUES
  (@estab_width_shift_id, 'abc123', 'S2', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_width_shift_id, 'si112', 'Si', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_width_shift_id, 's6112', 'S6', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_width_shift_id, 'ldf112', 'LdF', 'Fernmelder', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_width_shift_id, 'aw112', 'A/W', 'Fernmelder', 'ZUGEWIESEN',
   'schema-migrator-test');
SELECT dienstbesetzung_id INTO @estab_width_s2_assignment_id
  FROM nv_dienstbesetzungen
 WHERE dienstschicht_id = @estab_width_shift_id
   AND BINARY funktion = BINARY 'S2';
SELECT dienstbesetzung_id INTO @estab_width_aw_assignment_id
  FROM nv_dienstbesetzungen
 WHERE dienstschicht_id = @estab_width_shift_id
   AND BINARY funktion = BINARY 'A/W';
UPDATE nv_dienstbesetzungen
   SET status = 'ANGENOMMEN', angenommen_am = NOW(6)
 WHERE dienstschicht_id = @estab_width_shift_id;
UPDATE nv_dienstschichten
   SET status = 'AKTIV', aktiviert_am = NOW(6)
 WHERE dienstschicht_id = @estab_width_shift_id;
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES
  (NOW(), 'Erster ETB-Eintrag', '', 'Legacy User', 'abc123', 'S2',
   NOW(6), 'ohne', @estab_width_shift_id,
   @estab_width_s2_assignment_id);
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_message_id,
   estab_channel, estab_shift_id)
VALUES
  (NOW(), 'Nachricht aufgenommen', '', 'eStab-System', 'system', '',
   NOW(6), 'nachricht', @estab_width_message_id, 'BOS-Kanal 25',
   @estab_width_shift_id);
SET @estab_first_tbb_id = LAST_INSERT_ID();

INSERT INTO nv_einsaetze
  (kennung, name, beginn, ende, ort, organisation, fuehrungsstellenname,
   einsatzleitung, beschreibung, metadaten, erstellt_am, erstellt_von)
VALUES
  ('SCHEMA-LOGBOOK-SECOND', 'Second logbook incident', NOW(), NULL, '', '',
   'Second command post', '', 'Second numbering scope.', '{}', NOW(6),
   'schema-migrator-test');
SET @estab_second_incident_id = LAST_INSERT_ID();
UPDATE nv_einsatz_status
   SET active_einsatz_id = @estab_second_incident_id,
       revision = revision + 1,
       geaendert_am = NOW(6),
       geaendert_von = 'schema-migrator-test'
 WHERE singleton_id = 1;
INSERT INTO nv_nachrichten (\`12_inhalt\`)
VALUES ('Second incident message');
SET @estab_second_message_id = LAST_INSERT_ID();
INSERT INTO nv_dienstschichten
  (einsatz_id, nummer, bezeichnung, status, erstellt_von)
VALUES
  (@estab_second_incident_id, 1, 'Second schema shift', 'GEPLANT',
   'schema-migrator-test');
SET @estab_second_shift_id = LAST_INSERT_ID();
INSERT INTO nv_dienstbesetzungen
  (dienstschicht_id, benutzer_kuerzel, funktion, rolle, status,
   zugewiesen_von)
VALUES
  (@estab_second_shift_id, 'abc123', 'S2', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_second_shift_id, 'si112', 'Si', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_second_shift_id, 's6112', 'S6', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_second_shift_id, 'ldf112', 'LdF', 'Fernmelder', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_second_shift_id, 'aw112', 'A/W', 'Fernmelder', 'ZUGEWIESEN',
   'schema-migrator-test');
SELECT dienstbesetzung_id INTO @estab_second_s2_assignment_id
  FROM nv_dienstbesetzungen
 WHERE dienstschicht_id = @estab_second_shift_id
   AND BINARY funktion = BINARY 'S2';
UPDATE nv_dienstbesetzungen
   SET status = 'ANGENOMMEN', angenommen_am = NOW(6)
 WHERE dienstschicht_id = @estab_second_shift_id;
UPDATE nv_dienstschichten
   SET status = 'AKTIV', aktiviert_am = NOW(6)
 WHERE dienstschicht_id = @estab_second_shift_id;
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES
  (NOW(), 'Eigenständiger ETB-Eintrag', '', 'Legacy User', 'abc123', 'S2',
   NOW(6), 'A', @estab_second_shift_id,
   @estab_second_s2_assignment_id);
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_message_id,
   estab_message_route, estab_shift_id)
VALUES
  (NOW(), 'Nachrichtenweg', '', 'eStab-System', 'system', '',
   NOW(6), 'nachricht', @estab_second_message_id, 'über Funk',
   @estab_second_shift_id);

UPDATE nv_einsatz_status
   SET active_einsatz_id = @estab_width_incident_id,
       revision = revision + 1,
       geaendert_am = NOW(6),
       geaendert_von = 'schema-migrator-test'
 WHERE singleton_id = 1;
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES
  (NOW(), 'Zweiter ETB-Eintrag', '', 'Legacy User', 'abc123', 'S2',
   NOW(6), 'E', @estab_width_shift_id,
   @estab_width_s2_assignment_id);
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_correction_of,
   estab_operations, estab_shift_id, estab_writer_assignment_id)
VALUES
  (NOW(), 'Korrektur', '', 'Aufnahme Weitergabe', 'aw112', 'A/W',
   NOW(6), 'korrektur', @estab_first_tbb_id, 'Kanalbezeichnung berichtigt',
   @estab_width_shift_id, @estab_width_aw_assignment_id);
UPDATE nv_einsatz_status
   SET active_einsatz_id = NULL,
       revision = revision + 1,
       geaendert_am = NOW(6),
       geaendert_von = 'schema-migrator-test'
 WHERE singleton_id = 1"

assert_equal "abcdef|abcdef|abcdef|abcdef|abcdef|abcdef" "$(fixture_query "
SELECT CONCAT(
         \`01_zeichen\`, '|', \`02_zeichen\`, '|', \`03_zeichen\`, '|',
         \`14_zeichen\`, '|', \`15_quitzeichen\`, '|', \`x03_sperruser\`
       )
  FROM nv_nachrichten
 WHERE einsatz_id = (
         SELECT einsatz_id FROM nv_einsaetze
          WHERE kennung = 'SCHEMA-WIDTH-TEST'
       )")" \
    "six-character message codes were not accepted in an active incident"
assert_equal "abc123|2001:db8:1:2:3:4:5:6|2001:db8:6:5:4:3:2:1" "$(fixture_query "
SELECT CONCAT(kuerzel, '|', ip, '|', fwdip)
  FROM nv_benutzer
 WHERE kuerzel = 'abc123'")" \
    "widened user code or IPv6 address fields rejected valid values"
assert_equal "abc123" "$(fixture_query "
SELECT kuerzel FROM nv_anhang WHERE filename = 'SCHEMA-WIDTH-TEST'")" \
    "six-character attachment user code was not accepted"
assert_equal "1,2|1,2|3|3|1" "$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(estab_book_lfd ORDER BY estab_book_lfd)
            FROM nv_etb WHERE einsatz_id = (
              SELECT einsatz_id FROM nv_einsaetze
               WHERE kennung = 'SCHEMA-WIDTH-TEST'
            )), '|',
         (SELECT GROUP_CONCAT(estab_book_lfd ORDER BY estab_book_lfd)
            FROM nv_tbb WHERE einsatz_id = (
              SELECT einsatz_id FROM nv_einsaetze
               WHERE kennung = 'SCHEMA-WIDTH-TEST'
            )), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-WIDTH-TEST'
           ) AND buchart = 'ETB'), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-WIDTH-TEST'
           ) AND buchart = 'TTB'), '|',
         (SELECT COUNT(*) FROM nv_tbb AS entry_row
           JOIN nv_nachrichten AS message_row
             ON message_row.\`00_lfd\` = entry_row.estab_message_id
            AND message_row.einsatz_id = entry_row.einsatz_id
          WHERE entry_row.einsatz_id = (
            SELECT einsatz_id FROM nv_einsaetze
             WHERE kennung = 'SCHEMA-WIDTH-TEST'
          ) AND entry_row.estab_entry_type = 'nachricht'
            AND BINARY entry_row.tbb_kuerzel = BINARY 'system'
            AND BINARY entry_row.tbb_benutzer = BINARY 'eStab-System')
       )")" \
    "first incident did not receive canonical local logbook sequences or message link"
assert_equal "1|1|2|2" "$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(estab_book_lfd ORDER BY estab_book_lfd)
            FROM nv_etb WHERE einsatz_id = (
              SELECT einsatz_id FROM nv_einsaetze
               WHERE kennung = 'SCHEMA-LOGBOOK-SECOND'
            )), '|',
         (SELECT GROUP_CONCAT(estab_book_lfd ORDER BY estab_book_lfd)
            FROM nv_tbb WHERE einsatz_id = (
              SELECT einsatz_id FROM nv_einsaetze
               WHERE kennung = 'SCHEMA-LOGBOOK-SECOND'
            )), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-LOGBOOK-SECOND'
           ) AND buchart = 'ETB'), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-LOGBOOK-SECOND'
           ) AND buchart = 'TTB')
       )")" \
    "second incident did not start independent ETB/TBB sequences at one"

# Exercise migration 111's write boundary in an isolated incident so the
# established numbering/concurrency fixtures below retain their exact counts.
fixture_query "
INSERT INTO nv_einsaetze
  (kennung, name, beginn, ende, ort, organisation, fuehrungsstellenname,
   einsatzleitung, beschreibung, metadaten, erstellt_am, erstellt_von)
VALUES
  ('SCHEMA-SHIFT-RULES', 'Shift provenance rules', NOW(), NULL, '', '',
   'Shift rules command post', '',
   'Duty-shift provenance boundary fixture.', '{}', NOW(6),
   'schema-migrator-test');
SET @estab_rules_incident_id = LAST_INSERT_ID();
UPDATE nv_einsatz_status
   SET active_einsatz_id = @estab_rules_incident_id,
       revision = revision + 1,
       geaendert_am = NOW(6),
       geaendert_von = 'schema-migrator-test'
 WHERE singleton_id = 1;
INSERT INTO nv_dienstschichten
  (einsatz_id, nummer, bezeichnung, status, erstellt_von)
VALUES
  (@estab_rules_incident_id, 1, 'Shift provenance fixture', 'GEPLANT',
   'schema-migrator-test');
SET @estab_rules_shift_id = LAST_INSERT_ID();
INSERT INTO nv_dienstbesetzungen
  (dienstschicht_id, benutzer_kuerzel, funktion, rolle, status,
   zugewiesen_von)
VALUES
  (@estab_rules_shift_id, 'abc123', 'S2', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_rules_shift_id, 'si112', 'Si', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_rules_shift_id, 's6112', 'S6', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_rules_shift_id, 'ldf112', 'LdF', 'Fernmelder', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_rules_shift_id, 'aw112', 'A/W', 'Fernmelder', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_rules_shift_id, 'etb112', 'ETB', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test');
UPDATE nv_dienstbesetzungen
   SET status = 'ANGENOMMEN', angenommen_am = NOW(6)
 WHERE dienstschicht_id = @estab_rules_shift_id
   AND funktion IN ('S2', 'Si', 'S6', 'LdF', 'A/W');
UPDATE nv_dienstschichten
   SET status = 'AKTIV', aktiviert_am = NOW(6)
 WHERE dienstschicht_id = @estab_rules_shift_id;
INSERT INTO nv_benutzer
  (benutzer, kuerzel, funktion, rolle, aktiv, password, estab_gesperrt)
VALUES
  ('Gesperrte Zuordnung', 'as111', 'S3', 'Stab', 1, '', 0);
INSERT INTO nv_dienstbesetzungen
  (dienstschicht_id, benutzer_kuerzel, funktion, rolle, status,
   zugewiesen_von)
VALUES
  (@estab_rules_shift_id, 'as111', 'S3', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test');
UPDATE nv_dienstbesetzungen
   SET status = 'ANGENOMMEN', angenommen_am = NOW(6)
 WHERE dienstschicht_id = @estab_rules_shift_id
   AND BINARY benutzer_kuerzel = BINARY 'as111'
   AND BINARY funktion = BINARY 'S3';
UPDATE nv_benutzer SET estab_gesperrt = 1 WHERE kuerzel = 'as111'"
rules_shift_id=$(fixture_query "
SELECT dienstschicht_id FROM nv_dienstschichten
 WHERE bezeichnung = 'Shift provenance fixture'")
rules_s2_assignment_id=$(fixture_query "
SELECT assignment.dienstbesetzung_id
  FROM nv_dienstbesetzungen AS assignment
  JOIN nv_dienstschichten AS shift_row
    ON shift_row.dienstschicht_id = assignment.dienstschicht_id
 WHERE shift_row.bezeichnung = 'Shift provenance fixture'
   AND BINARY assignment.funktion = BINARY 'S2'")
rules_aw_assignment_id=$(fixture_query "
SELECT assignment.dienstbesetzung_id
  FROM nv_dienstbesetzungen AS assignment
  JOIN nv_dienstschichten AS shift_row
    ON shift_row.dienstschicht_id = assignment.dienstschicht_id
 WHERE shift_row.bezeichnung = 'Shift provenance fixture'
   AND BINARY assignment.funktion = BINARY 'A/W'")
rules_pending_etb_assignment_id=$(fixture_query "
SELECT assignment.dienstbesetzung_id
  FROM nv_dienstbesetzungen AS assignment
  JOIN nv_dienstschichten AS shift_row
    ON shift_row.dienstschicht_id = assignment.dienstschicht_id
 WHERE shift_row.bezeichnung = 'Shift provenance fixture'
   AND BINARY assignment.funktion = BINARY 'ETB'")

fixture_query "
INSERT INTO nv_zugangsschichten
  (einsatz_id, bezeichnung, beginn, ende, zugang_aktiv,
   erstellt_von, geaendert_von)
SELECT einsatz_id, 'Zugang Früh', NOW(6), NULL, 1,
       'schema-migrator-test', 'schema-migrator-test'
  FROM nv_einsaetze WHERE kennung = 'SCHEMA-SHIFT-RULES';
INSERT INTO nv_zugangsschichten
  (einsatz_id, bezeichnung, beginn, ende, zugang_aktiv,
   erstellt_von, geaendert_von)
SELECT einsatz_id, 'Zugang Spät', NOW(6), NULL, 1,
       'schema-migrator-test', 'schema-migrator-test'
  FROM nv_einsaetze WHERE kennung = 'SCHEMA-SHIFT-RULES';
INSERT INTO nv_zugangsschicht_mitglieder
  (zugangsschicht_id, benutzer_kuerzel, zugeordnet_von)
SELECT zugangsschicht_id, 'abc123', 'schema-migrator-test'
  FROM nv_zugangsschichten WHERE bezeichnung = 'Zugang Früh'"
access_membership_id=$(fixture_query "
SELECT zugangsschicht_mitglied_id
  FROM nv_zugangsschicht_mitglieder
 WHERE benutzer_kuerzel = 'abc123'")
if fixture_query "
INSERT INTO nv_zugangsschicht_mitglieder
  (zugangsschicht_id, benutzer_kuerzel, zugeordnet_von)
SELECT zugangsschicht_id, 'abc123', 'duplicate-test'
  FROM nv_zugangsschichten WHERE bezeichnung = 'Zugang Früh'" \
    >"$failure_log" 2>&1; then
    echo "schema migrator test: duplicate access-shift membership was accepted" >&2
    exit 1
fi
if ! grep -Eq 'Duplicate entry|uq_zugangsschicht_aktives_mitglied' \
    "$failure_log"; then
    echo "schema migrator test: duplicate membership failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
fixture_query "
UPDATE nv_zugangsschicht_mitglieder
   SET entfernt_am = NOW(6), entfernt_von = 'schema-migrator-test'
 WHERE zugangsschicht_mitglied_id = $access_membership_id;
INSERT INTO nv_zugangsschicht_mitglieder
  (zugangsschicht_id, benutzer_kuerzel, zugeordnet_von)
SELECT zugangsschicht_id, 'abc123', 'schema-migrator-readd'
  FROM nv_zugangsschichten WHERE bezeichnung = 'Zugang Früh'"
access_readded_membership_id=$(fixture_query "
SELECT zugangsschicht_mitglied_id
  FROM nv_zugangsschicht_mitglieder
 WHERE benutzer_kuerzel = 'abc123' AND entfernt_am IS NULL")
assert_equal "2|2|2|1|1|1|schema-migrator-test|schema-migrator-readd" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_zugangsschichten
           WHERE zugang_aktiv = 1 AND einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-SHIFT-RULES')), '|',
         (SELECT COUNT(*) FROM nv_zugangsschichten
           WHERE einsatz_id = (SELECT einsatz_id FROM nv_einsaetze
             WHERE kennung = 'SCHEMA-SHIFT-RULES')), '|',
         (SELECT COUNT(*) FROM nv_zugangsschicht_mitglieder
           WHERE benutzer_kuerzel = 'abc123'), '|',
         (SELECT COUNT(*) FROM nv_zugangsschicht_mitglieder
           WHERE benutzer_kuerzel = 'abc123'
             AND entfernt_am IS NULL), '|',
         (SELECT COUNT(*) FROM nv_zugangsschicht_mitglieder
           WHERE benutzer_kuerzel = 'abc123'
             AND entfernt_am IS NOT NULL), '|',
         ($access_readded_membership_id <> $access_membership_id), '|',
         (SELECT zugeordnet_von FROM nv_zugangsschicht_mitglieder
           WHERE zugangsschicht_mitglied_id = $access_membership_id), '|',
         (SELECT zugeordnet_von FROM nv_zugangsschicht_mitglieder
           WHERE zugangsschicht_mitglied_id =
                 $access_readded_membership_id)
       )")" \
    "optional access shifts do not preserve membership intervals on re-addition"
# Two access groups can be active and membership re-addition preserves its history.

if fixture_query "
UPDATE nv_dienstbesetzungen
   SET status = 'ANGENOMMEN', angenommen_am = NOW(6)
 WHERE dienstbesetzung_id = $rules_pending_etb_assignment_id" \
    >"$failure_log" 2>&1; then
    echo "schema migrator test: active-shift ETB writer replacement was accepted" >&2
    exit 1
fi
if ! grep -q 'Active shift ETB writer change requires confirmed handover' \
    "$failure_log"; then
    echo "schema migrator test: active-shift ETB writer rejection was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "ZUGEWIESEN|NULL" "$(fixture_query "
SELECT CONCAT(status, '|', COALESCE(CAST(angenommen_am AS CHAR), 'NULL'))
  FROM nv_dienstbesetzungen
 WHERE dienstbesetzung_id = $rules_pending_etb_assignment_id")" \
    "rejected active-shift ETB writer change mutated its assignment"
rules_locked_assignee_id=$(fixture_query "
SELECT assignment.dienstbesetzung_id
  FROM nv_dienstbesetzungen AS assignment
  JOIN nv_dienstschichten AS shift_row
    ON shift_row.dienstschicht_id = assignment.dienstschicht_id
 WHERE shift_row.bezeichnung = 'Shift provenance fixture'
   AND BINARY assignment.benutzer_kuerzel = BINARY 'as111'")
second_shift_id=$(fixture_query "
SELECT dienstschicht_id FROM nv_dienstschichten
 WHERE bezeichnung = 'Second schema shift'")
second_s2_assignment_id=$(fixture_query "
SELECT assignment.dienstbesetzung_id
  FROM nv_dienstbesetzungen AS assignment
  JOIN nv_dienstschichten AS shift_row
    ON shift_row.dienstschicht_id = assignment.dienstschicht_id
 WHERE shift_row.bezeichnung = 'Second schema shift'
   AND BINARY assignment.funktion = BINARY 'S2'")

fixture_query "
START TRANSACTION;
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type)
VALUES
  (NOW(), 'Ohne Schicht', '', 'Legacy User', 'abc123', 'S2', NOW(6),
   'ohne');
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_operations)
VALUES
  (NOW(), 'Ohne Schicht', '', 'Aufnahme Weitergabe', 'aw112', 'A/W',
   NOW(6), 'betriebsereignis', 'Optionale Schicht');
ROLLBACK"
# Manual ETB/TBB entries without a duty shift were accepted by account function.
if fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id)
VALUES
  (NOW(), 'Fremde Schicht', '', 'eStab-System', 'system', '', NOW(6),
   'ohne', $second_shift_id)" >"$failure_log" 2>&1; then
    echo "schema migrator test: ETB duty shift from another incident was accepted" >&2
    exit 1
fi
if ! grep -q 'ETB duty shift targets another incident' "$failure_log"; then
    echo "schema migrator test: ETB duty shift from another incident was not rejected explicitly" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_operations,
   estab_shift_id)
VALUES
  (NOW(), 'Fremde Schicht', '', 'eStab-System', 'system', '', NOW(6),
   'betriebsereignis', 'Fremder Einsatz', $second_shift_id)" \
    >"$failure_log" 2>&1; then
    echo "schema migrator test: TTB duty shift from another incident was accepted" >&2
    exit 1
fi
if ! grep -q 'TTB duty shift targets another incident' "$failure_log"; then
    echo "schema migrator test: TTB duty shift from another incident was not rejected explicitly" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES
  (NOW(), 'Fremder Schreiber', '', 'Legacy User', 'abc123', 'S2', NOW(6),
   'ohne', $rules_shift_id, $second_s2_assignment_id)" \
    >"$failure_log" 2>&1; then
    echo "schema migrator test: ETB writer from another duty shift was accepted" >&2
    exit 1
fi
if ! grep -q 'ETB writer does not belong to its duty shift' "$failure_log"; then
    echo "schema migrator test: ETB writer from another duty shift was not rejected explicitly" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_operations,
   estab_shift_id, estab_writer_assignment_id)
VALUES
  (NOW(), 'Fremder Schreiber', '', 'Aufnahme Weitergabe', 'aw112', 'A/W',
   NOW(6),
   'betriebsereignis', 'Fremde Besetzung', $rules_shift_id,
   $second_s2_assignment_id)" >"$failure_log" 2>&1; then
    echo "schema migrator test: TTB writer from another duty shift was accepted" >&2
    exit 1
fi
if ! grep -q 'TTB writer does not belong to its duty shift' "$failure_log"; then
    echo "schema migrator test: TTB writer from another duty shift was not rejected explicitly" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id, estab_assignee_assignment_id,
   estab_assignment)
VALUES
  (NOW(), 'Fremde Zuordnung', '', 'Legacy User', 'abc123', 'S2', NOW(6),
   'ohne', $rules_shift_id, $rules_s2_assignment_id,
   $second_s2_assignment_id, 'Browser-Freitext')" >"$failure_log" 2>&1; then
    echo "schema migrator test: ETB assignee from another duty shift was accepted" >&2
    exit 1
fi
if ! grep -q 'ETB assignee duty provenance is invalid' "$failure_log"; then
    echo "schema migrator test: ETB assignee from another duty shift was not rejected explicitly" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id, estab_assignment)
VALUES
  (NOW(), 'Freier Browsertext', '', 'Legacy User', 'abc123', 'S2', NOW(6),
   'ohne', $rules_shift_id, $rules_s2_assignment_id,
   'Browser-Freitext')" >"$failure_log" 2>&1; then
    echo "schema migrator test: browser ETB assignment text was accepted" >&2
    exit 1
fi
if ! grep -q 'ETB assignee snapshot requires an assignment' "$failure_log"; then
    echo "schema migrator test: browser ETB assignment text was accepted without an assignment" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES
  (NOW(), 'Falsche Schreiberidentität', '', 'Browser-Fälschung', 'abc123',
   'S2', NOW(6), 'ohne', $rules_shift_id, $rules_s2_assignment_id)" \
    >"$failure_log" 2>&1; then
    echo "schema migrator test: mismatched ETB writer identity was accepted" >&2
    exit 1
fi
if ! grep -q 'ETB writer account function or status is invalid' "$failure_log"; then
    echo "schema migrator test: mismatched ETB writer identity was not rejected explicitly" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
fixture_query "
START TRANSACTION;
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES
  (NOW(), 'Nicht angenommene ETB-Führung', '', 'ETB Führung', 'etb112',
   'ETB', NOW(6), 'ohne', $rules_shift_id,
   $rules_pending_etb_assignment_id);
ROLLBACK"
# Optional legacy writer provenance remains valid without ANGENOMMEN status.
if fixture_query "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_operations,
   estab_shift_id, estab_writer_assignment_id)
VALUES
  (NOW(), 'Falsche TTB-Funktion', '', 'Legacy User', 'abc123', 'S2',
   NOW(6), 'betriebsereignis', 'Nicht A/W', $rules_shift_id,
   $rules_s2_assignment_id)" >"$failure_log" 2>&1; then
    echo "schema migrator test: non-A/W TTB writer was accepted" >&2
    exit 1
fi
if ! grep -q 'TTB writer account function or status is invalid' "$failure_log"; then
    echo "schema migrator test: non-A/W TTB writer was not rejected explicitly" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_operations,
   estab_shift_id, estab_writer_assignment_id)
VALUES
  (NOW(), 'Falsche TTB-Identität', '', 'Browser-Fälschung', 'abc123', 'A/W',
   NOW(6), 'betriebsereignis', 'Identität abweichend', $rules_shift_id,
   $rules_aw_assignment_id)" >"$failure_log" 2>&1; then
    echo "schema migrator test: mismatched TTB writer identity was accepted" >&2
    exit 1
fi
if ! grep -q 'TTB writer account function or status is invalid' "$failure_log"; then
    echo "schema migrator test: mismatched TTB writer identity was not rejected explicitly" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
for optional_assignee_id in \
    "$rules_pending_etb_assignment_id" "$rules_locked_assignee_id"; do
    fixture_query "
START TRANSACTION;
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type,
   estab_assignee_assignment_id)
VALUES
  (NOW(), 'Optionale Bearbeitungszuordnung', '', 'Legacy User', 'abc123',
   'S2', NOW(6), 'A', $optional_assignee_id);
ROLLBACK"
done
# Non-withdrawn assignees remain selectable independent of account presence.
fixture_query "
UPDATE nv_dienstbesetzungen
   SET status = 'ZURUECKGEZOGEN', abgeloest_am = NOW(6)
 WHERE dienstbesetzung_id = $rules_pending_etb_assignment_id"
if fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type,
   estab_assignee_assignment_id)
VALUES
  (NOW(), 'Zurückgezogene Bearbeitungszuordnung', '', 'Legacy User',
   'abc123', 'S2', NOW(6), 'A', $rules_pending_etb_assignment_id)" \
    >"$failure_log" 2>&1; then
    echo "schema migrator test: withdrawn ETB assignee was accepted" >&2
    exit 1
fi
if ! grep -q 'ETB assignee duty provenance is invalid' "$failure_log"; then
    echo "schema migrator test: withdrawn ETB assignee rejection was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
fixture_query "UPDATE nv_benutzer
   SET estab_gesperrt = 0, aktiv = 0
 WHERE kuerzel = 'as111'"
fixture_query "UPDATE nv_benutzer SET aktiv = 0 WHERE kuerzel = 'abc123'"
if fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES
  (NOW(), 'Deaktiviertes Konto', '', 'Legacy User', 'abc123', 'S2', NOW(6),
   'ohne', $rules_shift_id, $rules_s2_assignment_id)" \
    >"$failure_log" 2>&1; then
    echo "schema migrator test: deactivated ETB writer account was accepted" >&2
    exit 1
fi
if ! grep -q 'ETB writer account function or status is invalid' "$failure_log"; then
    echo "schema migrator test: deactivated ETB writer was not rejected explicitly" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
fixture_query "UPDATE nv_benutzer SET aktiv = 1 WHERE kuerzel = 'abc123'"

fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type)
VALUES
  (NOW(), 'System ETB', '', 'eStab-System', 'system', '', NOW(6), 'ohne');
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_operations)
VALUES
  (NOW(), 'System TTB', '', 'eStab-System', 'system', '', NOW(6),
   'betriebsereignis', 'Systemnachweis');
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id, estab_assignee_assignment_id,
   estab_assignment)
VALUES
  (NOW(), 'Manuelles ETB', '', 'Legacy User', 'abc123', 'S2', NOW(6), 'E',
   $rules_shift_id, $rules_s2_assignment_id, $rules_locked_assignee_id,
   'Manipulierter Browser-Freitext');
SET @estab_rules_manual_etb_id = LAST_INSERT_ID();
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_operations,
   estab_shift_id, estab_writer_assignment_id)
VALUES
  (NOW(), 'Manuelles TTB', '', 'Aufnahme Weitergabe', 'aw112', 'A/W', NOW(6),
   'betriebsereignis', 'Manueller Nachweis', $rules_shift_id,
   $rules_aw_assignment_id)"
rules_manual_etb_id=$(fixture_query "
SELECT \`etb_lfd-nr\` FROM nv_etb
 WHERE einsatz_id = (SELECT einsatz_id FROM nv_einsaetze
   WHERE kennung = 'SCHEMA-SHIFT-RULES')
   AND estab_book_lfd = 2")
for invalid_reference in "Freitext" "02" "99"; do
    if fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_reference)
VALUES
  (NOW(), 'Ungültige Referenz', '', 'eStab-System', 'system', '', NOW(6),
   'ohne', $rules_shift_id, '$invalid_reference')" \
        >"$failure_log" 2>&1; then
        echo "schema migrator test: invalid ETB reference was accepted" >&2
        exit 1
    fi
    if [ "$invalid_reference" = "99" ]; then
        expected_reference_error='ETB reference target is not an earlier incident entry'
    else
        expected_reference_error='ETB reference must be a canonical local number'
    fi
    if ! grep -q "$expected_reference_error" "$failure_log"; then
        echo "schema migrator test: invalid ETB reference was not rejected explicitly" >&2
        sed -n '1,120p' "$failure_log" >&2
        exit 1
    fi
done
for correction_reference in "NULL" "'1'"; do
    if fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_reference, estab_correction_of)
VALUES
  (NOW(), 'Falscher Korrekturbezug', '', 'eStab-System', 'system', '', NOW(6),
   'korrektur', $rules_shift_id, $correction_reference,
   $rules_manual_etb_id)" >"$failure_log" 2>&1; then
        echo "schema migrator test: noncanonical ETB correction reference was accepted" >&2
        exit 1
    fi
    if ! grep -q 'ETB correction requires canonical local reference' \
        "$failure_log"; then
        echo "schema migrator test: noncanonical correction reference was not rejected explicitly" >&2
        sed -n '1,120p' "$failure_log" >&2
        exit 1
    fi
done
fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id, estab_reference)
VALUES
  (NOW(), 'Referenzzweig eins', '', 'Legacy User', 'abc123', 'S2', NOW(6),
   'A', $rules_shift_id, $rules_s2_assignment_id, '2'),
  (NOW(), 'Referenzzweig zwei', '', 'Legacy User', 'abc123', 'S2', NOW(6),
   'B', $rules_shift_id, $rules_s2_assignment_id, '2');
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id, estab_reference)
VALUES
  (NOW(), 'Referenzkette', '', 'Legacy User', 'abc123', 'S2', NOW(6), 'E',
   $rules_shift_id, $rules_s2_assignment_id, '3');
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id, estab_reference, estab_correction_of)
VALUES
  (NOW(), 'Kanonische Korrektur', '', 'Legacy User', 'abc123', 'S2', NOW(6),
   'korrektur', $rules_shift_id, $rules_s2_assignment_id, '2',
   $rules_manual_etb_id)"
assert_equal "6|2|6|6|1|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_etb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-SHIFT-RULES')), '|',
         (SELECT COUNT(*) FROM nv_tbb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-SHIFT-RULES')), '|',
         (SELECT COUNT(*) FROM (
           SELECT estab_shift_id FROM nv_etb WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-SHIFT-RULES')
           UNION ALL
           SELECT estab_shift_id FROM nv_tbb WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-SHIFT-RULES')
         ) AS entry_row WHERE estab_shift_id = $rules_shift_id), '|',
         (SELECT COUNT(*) FROM (
           SELECT estab_writer_assignment_id AS writer_id FROM nv_etb
            WHERE einsatz_id = (SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-SHIFT-RULES')
           UNION ALL
           SELECT estab_writer_assignment_id AS writer_id FROM nv_tbb
            WHERE einsatz_id = (SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-SHIFT-RULES')
         ) AS writer_row WHERE writer_id IS NOT NULL), '|',
         (SELECT COUNT(*) FROM nv_etb WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-SHIFT-RULES')
           AND estab_assignee_assignment_id = $rules_locked_assignee_id
           AND BINARY estab_assignment = BINARY
             'S3 (Stab): Gesperrte Zuordnung [as111]'), '|',
         (SELECT COUNT(*) FROM nv_etb WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-SHIFT-RULES')
           AND estab_assignment LIKE '%Browser-Freitext%')
       )")" \
    "optional system and valid manual logbook provenance was not accepted"
assert_equal "3:2,4:2,5:3,6:2|2" "$(fixture_query "
SELECT CONCAT(
         (SELECT GROUP_CONCAT(CONCAT(estab_book_lfd, ':', estab_reference)
                  ORDER BY estab_book_lfd SEPARATOR ',')
            FROM nv_etb
           WHERE einsatz_id = (SELECT einsatz_id FROM nv_einsaetze
             WHERE kennung = 'SCHEMA-SHIFT-RULES')
             AND estab_reference IS NOT NULL), '|',
         (SELECT original.estab_book_lfd
            FROM nv_etb AS correction
            JOIN nv_etb AS original
              ON original.\`etb_lfd-nr\` = correction.estab_correction_of
             AND original.einsatz_id = correction.einsatz_id
           WHERE correction.einsatz_id = (SELECT einsatz_id FROM nv_einsaetze
             WHERE kennung = 'SCHEMA-SHIFT-RULES')
             AND correction.estab_event_type = 'korrektur')
       )")" \
    "canonical ETB reference branches, chain, or correction target were lost"
if fixture_query "
UPDATE nv_etb
   SET estab_assignment = 'Nachträglich verändert'
 WHERE einsatz_id = (
   SELECT einsatz_id FROM nv_einsaetze WHERE kennung = 'SCHEMA-SHIFT-RULES'
 ) AND estab_assignee_assignment_id = $rules_locked_assignee_id" \
    >"$failure_log" 2>&1; then
    echo "schema migrator test: canonical ETB assignment snapshot was mutable" >&2
    exit 1
fi
if ! grep -q 'ETB entries are append-only; write a correction' \
    "$failure_log"; then
    echo "schema migrator test: canonical ETB assignment snapshot was not immutable" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "S3 (Stab): Gesperrte Zuordnung [as111]" "$(fixture_query "
SELECT estab_assignment FROM nv_etb
 WHERE einsatz_id = (
   SELECT einsatz_id FROM nv_einsaetze WHERE kennung = 'SCHEMA-SHIFT-RULES'
 ) AND estab_assignee_assignment_id = $rules_locked_assignee_id")" \
    "canonical ETB assignment snapshot was not generated by the database"

# Re-activate the first test incident to prove the insert validation order and
# the unconditional append-only TTB boundary with explicit database errors.
fixture_query "
UPDATE nv_einsatz_status
   SET active_einsatz_id = (
         SELECT einsatz_id FROM nv_einsaetze
          WHERE kennung = 'SCHEMA-WIDTH-TEST'
       ),
       revision = revision + 1,
       geaendert_am = NOW(6),
       geaendert_von = 'schema-migrator-test'
 WHERE singleton_id = 1"
width_shift_id=$(fixture_query "
SELECT dienstschicht_id FROM nv_dienstschichten
 WHERE bezeichnung = 'Schema width shift'")
width_s2_assignment_id=$(fixture_query "
SELECT assignment.dienstbesetzung_id
  FROM nv_dienstbesetzungen AS assignment
  JOIN nv_dienstschichten AS shift_row
    ON shift_row.dienstschicht_id = assignment.dienstschicht_id
 WHERE shift_row.bezeichnung = 'Schema width shift'
   AND BINARY assignment.funktion = BINARY 'S2'")
width_aw_assignment_id=$(fixture_query "
SELECT assignment.dienstbesetzung_id
  FROM nv_dienstbesetzungen AS assignment
  JOIN nv_dienstschichten AS shift_row
    ON shift_row.dienstschicht_id = assignment.dienstschicht_id
 WHERE shift_row.bezeichnung = 'Schema width shift'
   AND BINARY assignment.funktion = BINARY 'A/W'")

# Separate database sessions contend for the same two head rows. The row lock
# and unique incident/book key must yield complete, gap-free sequences.
: > "$concurrency_log"
concurrency_pids=
concurrency_counter=1
while [ "$concurrency_counter" -le 8 ]; do
    (
        database_query "$test_database" "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES
  (NOW(), 'Concurrent ETB $concurrency_counter', '', 'Legacy User', 'abc123',
   'S2', NOW(6), 'ohne', $width_shift_id,
   $width_s2_assignment_id)" >>"$concurrency_log" 2>&1
    ) &
    concurrency_pids="$concurrency_pids $!"
    (
        database_query "$test_database" "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_operations,
   estab_shift_id, estab_writer_assignment_id)
VALUES
  (NOW(), 'Concurrent TTB $concurrency_counter', '',
   'Aufnahme Weitergabe', 'aw112',
   'A/W', NOW(6), 'betriebsereignis',
   'Parallel erfasst $concurrency_counter', $width_shift_id,
   $width_aw_assignment_id)" >>"$concurrency_log" 2>&1
    ) &
    concurrency_pids="$concurrency_pids $!"
    concurrency_counter=$((concurrency_counter + 1))
done
for concurrency_pid in $concurrency_pids; do
    if ! wait "$concurrency_pid"; then
        echo "schema migrator test: concurrent logbook insert failed" >&2
        sed -n '1,160p' "$concurrency_log" >&2
        exit 1
    fi
done
assert_equal "10|10|1|10|11|10|10|1|10|11" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_etb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-WIDTH-TEST')), '|',
         (SELECT COUNT(DISTINCT estab_book_lfd) FROM nv_etb
           WHERE einsatz_id = (SELECT einsatz_id FROM nv_einsaetze
             WHERE kennung = 'SCHEMA-WIDTH-TEST')), '|',
         (SELECT MIN(estab_book_lfd) FROM nv_etb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-WIDTH-TEST')), '|',
         (SELECT MAX(estab_book_lfd) FROM nv_etb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-WIDTH-TEST')), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-WIDTH-TEST') AND buchart = 'ETB'), '|',
         (SELECT COUNT(*) FROM nv_tbb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-WIDTH-TEST')), '|',
         (SELECT COUNT(DISTINCT estab_book_lfd) FROM nv_tbb
           WHERE einsatz_id = (SELECT einsatz_id FROM nv_einsaetze
             WHERE kennung = 'SCHEMA-WIDTH-TEST')), '|',
         (SELECT MIN(estab_book_lfd) FROM nv_tbb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-WIDTH-TEST')), '|',
         (SELECT MAX(estab_book_lfd) FROM nv_tbb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-WIDTH-TEST')), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-WIDTH-TEST') AND buchart = 'TTB')
       )")" \
    "concurrent ETB/TBB inserts did not allocate complete unique local numbers"

# A new incident must own both empty heads before any book entry can race.
# Concurrent first entries then contend only for the already-visible head rows.
fixture_query "
INSERT INTO nv_einsaetze
  (kennung, name, beginn, ende, ort, organisation, fuehrungsstellenname,
   einsatzleitung, beschreibung, metadaten, erstellt_am, erstellt_von)
VALUES
  ('SCHEMA-LOGBOOK-PRECREATED', 'Pre-created-head concurrency incident',
   NOW(), NULL, '', '', 'Pre-created-head command post', '',
   'Concurrent first entries use pre-created logbook heads.', '{}', NOW(6),
   'schema-migrator-test');
UPDATE nv_einsatz_status
   SET active_einsatz_id = LAST_INSERT_ID(),
       revision = revision + 1,
       geaendert_am = NOW(6),
       geaendert_von = 'schema-migrator-test'
 WHERE singleton_id = 1;
SET @estab_precreated_incident_id = (
  SELECT einsatz_id FROM nv_einsaetze
   WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED'
);
INSERT INTO nv_dienstschichten
  (einsatz_id, nummer, bezeichnung, status, erstellt_von)
VALUES
  (@estab_precreated_incident_id, 1, 'Pre-created-head shift', 'GEPLANT',
   'schema-migrator-test');
SET @estab_precreated_shift_id = LAST_INSERT_ID();
INSERT INTO nv_dienstbesetzungen
  (dienstschicht_id, benutzer_kuerzel, funktion, rolle, status,
   zugewiesen_von)
VALUES
  (@estab_precreated_shift_id, 'abc123', 'S2', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_precreated_shift_id, 'si112', 'Si', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_precreated_shift_id, 's6112', 'S6', 'Stab', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_precreated_shift_id, 'ldf112', 'LdF', 'Fernmelder', 'ZUGEWIESEN',
   'schema-migrator-test'),
  (@estab_precreated_shift_id, 'aw112', 'A/W', 'Fernmelder', 'ZUGEWIESEN',
   'schema-migrator-test');
UPDATE nv_dienstbesetzungen
   SET status = 'ANGENOMMEN', angenommen_am = NOW(6)
 WHERE dienstschicht_id = @estab_precreated_shift_id;
UPDATE nv_dienstschichten
   SET status = 'AKTIV', aktiviert_am = NOW(6)
 WHERE dienstschicht_id = @estab_precreated_shift_id"
assert_equal "2|ETB:1,TTB:1|0|0" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED'
           )), '|',
         (SELECT GROUP_CONCAT(CONCAT(buchart, ':', next_lfd)
                  ORDER BY buchart SEPARATOR ',')
            FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED'
           )), '|',
         (SELECT COUNT(*) FROM nv_etb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED')), '|',
         (SELECT COUNT(*) FROM nv_tbb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED'))
       )")" \
    "new incident did not receive both empty book heads before first entry"
precreated_shift_id=$(fixture_query "
SELECT dienstschicht_id FROM nv_dienstschichten
 WHERE bezeichnung = 'Pre-created-head shift'")
precreated_s2_assignment_id=$(fixture_query "
SELECT assignment.dienstbesetzung_id
  FROM nv_dienstbesetzungen AS assignment
  JOIN nv_dienstschichten AS shift_row
    ON shift_row.dienstschicht_id = assignment.dienstschicht_id
 WHERE shift_row.bezeichnung = 'Pre-created-head shift'
   AND BINARY assignment.funktion = BINARY 'S2'")
precreated_aw_assignment_id=$(fixture_query "
SELECT assignment.dienstbesetzung_id
  FROM nv_dienstbesetzungen AS assignment
  JOIN nv_dienstschichten AS shift_row
    ON shift_row.dienstschicht_id = assignment.dienstschicht_id
 WHERE shift_row.bezeichnung = 'Pre-created-head shift'
   AND BINARY assignment.funktion = BINARY 'A/W'")
: > "$concurrency_log"
concurrency_pids=
concurrency_counter=1
while [ "$concurrency_counter" -le 4 ]; do
    (
        database_query "$test_database" "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES
  (NOW(), 'First-head ETB $concurrency_counter', '', 'Legacy User', 'abc123',
   'S2', NOW(6), 'ohne', $precreated_shift_id,
   $precreated_s2_assignment_id)" >>"$concurrency_log" 2>&1
    ) &
    concurrency_pids="$concurrency_pids $!"
    (
        database_query "$test_database" "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_operations,
   estab_shift_id, estab_writer_assignment_id)
VALUES
  (NOW(), 'First-head TTB $concurrency_counter', '',
   'Aufnahme Weitergabe', 'aw112',
   'A/W', NOW(6), 'betriebsereignis',
   'Erste parallele Erfassung $concurrency_counter', $precreated_shift_id,
   $precreated_aw_assignment_id)" \
            >>"$concurrency_log" 2>&1
    ) &
    concurrency_pids="$concurrency_pids $!"
    concurrency_counter=$((concurrency_counter + 1))
done
for concurrency_pid in $concurrency_pids; do
    if ! wait "$concurrency_pid"; then
        echo "schema migrator test: concurrent first-entry insert failed" >&2
        sed -n '1,160p' "$concurrency_log" >&2
        exit 1
    fi
done
assert_equal "4|4|1|4|5|4|4|1|4|5" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_etb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED')), '|',
         (SELECT COUNT(DISTINCT estab_book_lfd) FROM nv_etb
           WHERE einsatz_id = (SELECT einsatz_id FROM nv_einsaetze
             WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED')), '|',
         (SELECT MIN(estab_book_lfd) FROM nv_etb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED')), '|',
         (SELECT MAX(estab_book_lfd) FROM nv_etb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED')), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED') AND buchart = 'ETB'), '|',
         (SELECT COUNT(*) FROM nv_tbb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED')), '|',
         (SELECT COUNT(DISTINCT estab_book_lfd) FROM nv_tbb
           WHERE einsatz_id = (SELECT einsatz_id FROM nv_einsaetze
             WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED')), '|',
         (SELECT MIN(estab_book_lfd) FROM nv_tbb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED')), '|',
         (SELECT MAX(estab_book_lfd) FROM nv_tbb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED')), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED') AND buchart = 'TTB')
       )")" \
    "first concurrent ETB/TBB entries did not use pre-created book heads"

# The book triggers must never recreate missing infrastructure. A damaged head
# fails closed, leaves the book unchanged, and can be repaired explicitly.
fixture_query "
DELETE FROM nv_logbuch_koepfe
 WHERE einsatz_id = (
   SELECT einsatz_id FROM nv_einsaetze
    WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED'
 ) AND buchart = 'ETB'"
if fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES
  (NOW(), 'Missing-head ETB', '', 'Legacy User', 'abc123', 'S2',
   NOW(6), 'ohne', $precreated_shift_id,
   $precreated_s2_assignment_id)" >"$failure_log" 2>&1; then
    echo "schema migrator test: missing ETB head was recreated by entry trigger" >&2
    exit 1
fi
if ! grep -q 'ETB book head is missing' "$failure_log"; then
    echo "schema migrator test: missing ETB head was not rejected explicitly" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
fixture_query "
INSERT INTO nv_logbuch_koepfe (einsatz_id, buchart, next_lfd)
SELECT einsatz_id, 'ETB', 5 FROM nv_einsaetze
 WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED';
DELETE FROM nv_logbuch_koepfe
 WHERE einsatz_id = (
   SELECT einsatz_id FROM nv_einsaetze
    WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED'
 ) AND buchart = 'TTB'"
if fixture_query "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_operations,
   estab_shift_id, estab_writer_assignment_id)
VALUES
  (NOW(), 'Missing-head TTB', '', 'Aufnahme Weitergabe', 'aw112', 'A/W',
   NOW(6), 'betriebsereignis', 'Darf nicht gespeichert werden',
   $precreated_shift_id, $precreated_aw_assignment_id)" \
    >"$failure_log" 2>&1; then
    echo "schema migrator test: missing TTB head was recreated by entry trigger" >&2
    exit 1
fi
if ! grep -q 'TTB book head is missing' "$failure_log"; then
    echo "schema migrator test: missing TTB head was not rejected explicitly" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
fixture_query "
INSERT INTO nv_logbuch_koepfe (einsatz_id, buchart, next_lfd)
SELECT einsatz_id, 'TTB', 5 FROM nv_einsaetze
 WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED'"
assert_equal "4|4|ETB:5,TTB:5" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_etb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED')), '|',
         (SELECT COUNT(*) FROM nv_tbb WHERE einsatz_id = (
           SELECT einsatz_id FROM nv_einsaetze
            WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED')), '|',
         (SELECT GROUP_CONCAT(CONCAT(buchart, ':', next_lfd)
                  ORDER BY buchart SEPARATOR ',')
            FROM nv_logbuch_koepfe WHERE einsatz_id = (
              SELECT einsatz_id FROM nv_einsaetze
               WHERE kennung = 'SCHEMA-LOGBOOK-PRECREATED'
            ))
       )")" \
    "missing book-head failures changed entries or local counters"
fixture_query "
UPDATE nv_einsatz_status
   SET active_einsatz_id = (
         SELECT einsatz_id FROM nv_einsaetze
          WHERE kennung = 'SCHEMA-WIDTH-TEST'
       ),
       revision = revision + 1,
       geaendert_am = NOW(6),
       geaendert_von = 'schema-migrator-test'
 WHERE singleton_id = 1"
if fixture_query "
INSERT INTO nv_etb
  (etb_time, etb_aktion, etb_bemerk, etb_benutzer, etb_kuerzel,
   etb_funktion, estab_event_time, estab_event_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES (NOW(), 'Unzulässig', '', 'Schema Test', 'wid', 'S2',
        NOW(6), 'legacy_import', $width_shift_id,
        $width_s2_assignment_id)" >"$failure_log" 2>&1; then
    echo "schema migrator test: new legacy ETB type was accepted" >&2
    exit 1
fi
if ! grep -q 'ETB entry type is not permitted' "$failure_log"; then
    echo "schema migrator test: invalid ETB type failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_shift_id,
   estab_writer_assignment_id)
VALUES (NOW(), '', '', 'Aufnahme Weitergabe', 'aw112', 'A/W', NOW(6), 'kanal',
        $width_shift_id, $width_aw_assignment_id)" \
    >"$failure_log" 2>&1; then
    echo "schema migrator test: empty TTB entry was accepted" >&2
    exit 1
fi
if ! grep -q 'TTB entry requires at least one content area' "$failure_log"; then
    echo "schema migrator test: empty TTB failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type,
   estab_message_route, estab_shift_id)
VALUES
  (NOW(), 'Unverknüpfter Nachrichtennachweis', '', 'eStab-System', 'system',
   '', NOW(6), 'nachricht', 'ohne verbindliche Nachricht',
   $width_shift_id)" >"$failure_log" 2>&1; then
    echo "schema migrator test: unlinked TTB message row was accepted" >&2
    exit 1
fi
if ! grep -q 'TTB message entry requires canonical message link' \
    "$failure_log"; then
    echo "schema migrator test: unlinked TTB message failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_message_id,
   estab_channel, estab_shift_id)
SELECT NOW(), 'Manueller Link mit falschem Typ', '', 'eStab-System', 'system',
       '', NOW(6), 'kanal', \`00_lfd\`, 'BOS-Kanal 25', $width_shift_id
  FROM nv_nachrichten
 WHERE \`12_inhalt\` = 'Schema width fixture'" >"$failure_log" 2>&1; then
    echo "schema migrator test: non-message TTB row linked a message" >&2
    exit 1
fi
if ! grep -q 'TTB message link requires canonical message entry' \
    "$failure_log"; then
    echo "schema migrator test: wrong-type message link failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_message_id,
   estab_message_route, estab_shift_id, estab_writer_assignment_id)
SELECT NOW(), 'Manuell erzeugter Nachrichtennachweis', '',
       'Aufnahme Weitergabe', 'aw112', 'A/W', NOW(6), 'nachricht',
       \`00_lfd\`, 'manueller Link',
       $width_shift_id, $width_aw_assignment_id
  FROM nv_nachrichten
 WHERE \`12_inhalt\` = 'Schema width fixture'" >"$failure_log" 2>&1; then
    echo "schema migrator test: manual TTB row linked a message" >&2
    exit 1
fi
if ! grep -q 'TTB message link requires system-generated evidence' \
    "$failure_log"; then
    echo "schema migrator test: manual message link failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_message_id,
   estab_message_route, estab_shift_id)
SELECT NOW(), 'Doppelter Nachrichtennachweis', '', 'eStab-System', 'system',
       '', NOW(6), 'nachricht', \`00_lfd\`, 'doppelter Link', $width_shift_id
  FROM nv_nachrichten
 WHERE \`12_inhalt\` = 'Schema width fixture'" >"$failure_log" 2>&1; then
    echo "schema migrator test: duplicate canonical TTB message row was accepted" >&2
    exit 1
fi
if ! grep -Eq 'Duplicate entry|idx_tbb_message' "$failure_log"; then
    echo "schema migrator test: duplicate message evidence failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
INSERT INTO nv_tbb
  (tbb_time, tbb_aktion, tbb_bemerk, tbb_benutzer, tbb_kuerzel,
   tbb_funktion, estab_event_time, estab_entry_type, estab_message_id,
   estab_message_route, estab_shift_id)
SELECT NOW(), 'Falscher Einsatz', '', 'eStab-System', 'system', '',
       NOW(6), 'nachricht', \`00_lfd\`, 'unzulässiger Link', $width_shift_id
  FROM nv_nachrichten
 WHERE \`12_inhalt\` = 'Second incident message'" >"$failure_log" 2>&1; then
    echo "schema migrator test: cross-incident TTB message link was accepted" >&2
    exit 1
fi
if ! grep -q 'TTB message link targets another incident' "$failure_log"; then
    echo "schema migrator test: cross-incident TTB link failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "10|11|1" "$(fixture_query "
SELECT CONCAT(
         (SELECT COUNT(*) FROM nv_tbb
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-WIDTH-TEST'
           )), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-WIDTH-TEST'
           ) AND buchart = 'TTB'), '|',
         (SELECT COUNT(*) FROM nv_tbb AS entry_row
           JOIN nv_nachrichten AS message_row
             ON message_row.\`00_lfd\` = entry_row.estab_message_id
            AND message_row.einsatz_id = entry_row.einsatz_id
          WHERE entry_row.einsatz_id = (
            SELECT einsatz_id FROM nv_einsaetze
             WHERE kennung = 'SCHEMA-WIDTH-TEST'
          ) AND entry_row.estab_entry_type = 'nachricht'
            AND BINARY entry_row.tbb_kuerzel = BINARY 'system'
            AND BINARY entry_row.tbb_benutzer = BINARY 'eStab-System')
       )")" \
    "rejected TTB message links changed evidence or consumed a local number"
if fixture_query "
UPDATE nv_tbb SET tbb_bemerk = 'mutiert'
 WHERE einsatz_id = (
   SELECT einsatz_id FROM nv_einsaetze WHERE kennung = 'SCHEMA-WIDTH-TEST'
 ) AND estab_book_lfd = 1" >"$failure_log" 2>&1; then
    echo "schema migrator test: TTB update was accepted" >&2
    exit 1
fi
if ! grep -q 'TTB entries are append-only; write a correction' "$failure_log"; then
    echo "schema migrator test: TTB update failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
if fixture_query "
DELETE FROM nv_tbb
 WHERE einsatz_id = (
   SELECT einsatz_id FROM nv_einsaetze WHERE kennung = 'SCHEMA-WIDTH-TEST'
 ) AND estab_book_lfd = 1" >"$failure_log" 2>&1; then
    echo "schema migrator test: TTB delete was accepted" >&2
    exit 1
fi
if ! grep -q 'TTB entries are protected by retention policy' "$failure_log"; then
    echo "schema migrator test: TTB delete failure was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi
assert_equal "11|11" "$(fixture_query "
SELECT CONCAT(
         (SELECT next_lfd FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-WIDTH-TEST'
           ) AND buchart = 'ETB'), '|',
         (SELECT next_lfd FROM nv_logbuch_koepfe
           WHERE einsatz_id = (
             SELECT einsatz_id FROM nv_einsaetze
              WHERE kennung = 'SCHEMA-WIDTH-TEST'
           ) AND buchart = 'TTB')
       )")" \
    "rejected logbook inserts consumed a local sequence number"
fixture_query "
UPDATE nv_einsatz_status
   SET active_einsatz_id = NULL,
       revision = revision + 1,
       geaendert_am = NOW(6),
       geaendert_von = 'schema-migrator-test'
 WHERE singleton_id = 1"
assert_equal "S1|1" "$(fixture_query "
SELECT CONCAT(
         (SELECT \`01_zeichen\` FROM nv_nachrichten WHERE \`00_lfd\` = 1),
         '|',
         (SELECT active_einsatz_id IS NULL
            FROM nv_einsatz_status WHERE singleton_id = 1)
       )")" \
    "legacy history changed or the schema width fixture stayed active"

fixture_query "
UPDATE estab_schema_migrations
   SET checksum = REPEAT('0', 64)
 WHERE version = '30-runtime-schema.sql'"
if ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN" >"$failure_log" 2>&1; then
    echo "schema migrator test: checksum tampering was accepted" >&2
    exit 1
fi
if ! grep -q 'Checksum mismatch' "$failure_log"; then
    echo "schema migrator test: checksum mismatch was not explicit" >&2
    sed -n '1,120p' "$failure_log" >&2
    exit 1
fi

echo "schema migrator integration test: OK"
