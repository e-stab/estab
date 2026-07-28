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
test_database="estab_migration_test_$$"
retry_database="estab_baseline_retry_test_$$"
guard_database="estab_baseline_guard_test_$$"

for database_name in "$test_database" "$retry_database" "$guard_database"; do
    case "$database_name" in
        *[!A-Za-z0-9_]*)
            echo "schema migrator test: unsafe fixture database name" >&2
            exit 1
            ;;
    esac
done
if [ ! -r "$fixture" ] \
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
DROP DATABASE IF EXISTS \`$guard_database\`" >/dev/null 2>&1 || true
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
assert_equal "14" "$(database_query "$retry_database" "
SELECT COUNT(*)
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_type = 'BASE TABLE'
   AND LEFT(table_name, 3) = 'nv_'")" \
    "retried baseline did not produce all runtime tables"

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

assert_equal "2" "$(fixture_query "
SELECT COUNT(*) FROM estab_schema_migrations
 WHERE state = 'applied'
   AND checksum REGEXP BINARY '^[0-9a-f]{64}$'")" \
    "versioned migration records are incomplete"
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

fixture_query "
SET SESSION sql_mode =
  'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
UPDATE nv_nachrichten
   SET \`01_zeichen\` = 'abcdef',
       \`02_zeichen\` = 'abcdef',
       \`03_zeichen\` = 'abcdef',
       \`14_zeichen\` = 'abcdef',
       \`15_quitzeichen\` = 'abcdef',
       \`x03_sperruser\` = 'abcdef'
 WHERE \`00_lfd\` = 1;
UPDATE nv_benutzer
   SET kuerzel = 'abc123',
       ip = '2001:db8:1:2:3:4:5:6',
       fwdip = '2001:db8:6:5:4:3:2:1'
 WHERE kuerzel = 'abc';
UPDATE nv_anhang SET kuerzel = 'abc123' WHERE \`lfd-nr\` = 1"

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
