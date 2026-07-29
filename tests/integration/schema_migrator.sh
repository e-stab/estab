#!/bin/sh
set -eu

: "${ESTAB_DB_HOST:=db}"
: "${ESTAB_DB_PORT:=3306}"
: "${ESTAB_DB_ROOT_PASSWORD_FILE:=/run/secrets/estab_db_root_password}"
: "${ESTAB_MIGRATOR_BIN:=/usr/local/bin/estab-migrate}"
: "${ESTAB_SCHEMA_BASELINE_FILE:=/opt/estab/schema/10-schema.sql}"
export ESTAB_SCHEMA_VERIFY_FILE=

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
fixture="$script_dir/../fixtures/legacy-runtime-schema.sql"
standard_matrix_fixture="$script_dir/../fixtures/recipient-matrix-standard.txt"
test_database="estab_migration_test_$$"
retry_database="estab_baseline_retry_test_$$"
guard_database="estab_baseline_guard_test_$$"
collision_database="estab_standard_collision_test_$$"
incident_guard_database="estab_incident_guard_test_$$"

for database_name in \
    "$test_database" "$retry_database" "$guard_database" "$collision_database" \
    "$incident_guard_database"
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

cleanup()
{
    status=$?
    trap - EXIT HUP INT TERM
    admin_query "
DROP DATABASE IF EXISTS \`$test_database\`;
DROP DATABASE IF EXISTS \`$retry_database\`;
DROP DATABASE IF EXISTS \`$guard_database\`;
DROP DATABASE IF EXISTS \`$collision_database\`;
DROP DATABASE IF EXISTS \`$incident_guard_database\`" >/dev/null 2>&1 || true
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

fixture_query "DELETE FROM nv_anhang WHERE \`lfd-nr\` = 2"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"
ESTAB_DB_NAME="$test_database" "$ESTAB_MIGRATOR_BIN"

assert_equal "5" "$(fixture_query "
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
  (kennung, name, beginn, ende, ort, organisation, einsatzleitung,
   beschreibung, metadaten, erstellt_am, erstellt_von)
VALUES
  ('SCHEMA-WIDTH-TEST', 'Schema width test', NOW(), NULL, '', '', '',
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
   \`14_zeichen\`, \`15_quitzeichen\`, \`x03_sperruser\`)
VALUES ('wid', 'wid', 'wid', 'wid', 'wid', 'wid');
SET @estab_width_message_id = LAST_INSERT_ID();
INSERT INTO nv_anhang (filename, org_filename, kuerzel)
VALUES ('SCHEMA-WIDTH-TEST', 'schema-width-test.txt', 'wid');
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
