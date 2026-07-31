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
retry_database="estab_baseline_retry_test_$$"
guard_database="estab_baseline_guard_test_$$"
collision_database="estab_standard_collision_test_$$"
incident_guard_database="estab_incident_guard_test_$$"
predecessor_database="estab_incident_predecessor_test_$$"
incident_predecessor_checksum="6732e9c87f0532fce41ee9a58658bf4888fdf7c2ced1ed6bad75a756d6e08edf"

for database_name in \
    "$test_database" "$retry_database" "$guard_database" "$collision_database" \
    "$incident_guard_database" "$predecessor_database"
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
DROP DATABASE IF EXISTS \`$retry_database\`;
DROP DATABASE IF EXISTS \`$guard_database\`;
DROP DATABASE IF EXISTS \`$collision_database\`;
DROP DATABASE IF EXISTS \`$incident_guard_database\`;
DROP DATABASE IF EXISTS \`$predecessor_database\`" >/dev/null 2>&1 || true
    rm -f -- "$client_defaults" "$failure_log"
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

admin_query "
DROP DATABASE IF EXISTS \`$retry_database\`;
CREATE DATABASE \`$retry_database\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
baseline_checksum=$(sha256sum "$ESTAB_SCHEMA_BASELINE_FILE" | awk '{print $1}')
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

ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$retry_database" "$ESTAB_MIGRATOR_BIN"
assert_equal "1" "$(database_query "$retry_database" "
SELECT COUNT(*)
  FROM estab_schema_baselines
 WHERE version = '10-schema.sql'
   AND checksum = '$baseline_checksum'
   AND state = 'applied'
   AND applied_at IS NOT NULL")" \
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
ESTAB_DB_NAME="$predecessor_database" "$ESTAB_MIGRATOR_BIN"

prepare_checksum=$(
    sha256sum "$ESTAB_MIGRATIONS_DIR/45-global-incidents-prepare.sql" |
        awk '{print $1}'
)
finish_checksum=$(
    sha256sum "$ESTAB_MIGRATIONS_DIR/55-global-incidents-finish.sql" |
        awk '{print $1}'
)
assert_equal \
    "14|14|$prepare_checksum|$incident_predecessor_checksum|$finish_checksum" \
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
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"

assert_equal "14" "$(fixture_query "
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
assert_equal "1|1|1|1|1|1|1|9" "$(fixture_query "
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
               'nv_melderauftraege'
             ))
       )")" \
    "DV evidence or organisational migration was not applied completely"
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
       ip = '2001:db8:1:2:3:4:5:6',
       fwdip = '2001:db8:6:5:4:3:2:1'
 WHERE kuerzel = 'abc';
UPDATE nv_anhang
   SET kuerzel = 'abc123'
 WHERE \`lfd-nr\` = @estab_width_attachment_id;
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
