#!/bin/sh
set -eu

: "${ESTAB_DB_HOST:=db}"
: "${ESTAB_DB_PORT:=3306}"
: "${ESTAB_DB_NAME:=estab}"
: "${ESTAB_DB_ROOT_PASSWORD_FILE:=/run/secrets/estab_db_root_password}"
: "${ESTAB_MIGRATIONS_DIR:=/opt/estab/migrations}"
ESTAB_SCHEMA_VERIFY_FILE=${ESTAB_SCHEMA_VERIFY_FILE-/opt/estab/verify.sql}

case "$ESTAB_DB_HOST" in
    ''|*[!A-Za-z0-9_.:-]*)
        echo "ESTAB_DB_HOST contains invalid characters" >&2
        exit 1
        ;;
esac
case "$ESTAB_DB_PORT" in
    ''|*[!0-9]*)
        echo "ESTAB_DB_PORT must be numeric" >&2
        exit 1
        ;;
esac
case "$ESTAB_DB_NAME" in
    ''|*[!A-Za-z0-9_]*)
        echo "ESTAB_DB_NAME contains invalid characters" >&2
        exit 1
        ;;
esac

if [ ! -r "$ESTAB_DB_ROOT_PASSWORD_FILE" ]; then
    echo "MariaDB root secret is not readable" >&2
    exit 1
fi
if [ ! -d "$ESTAB_MIGRATIONS_DIR" ]; then
    echo "Migration directory is not readable: $ESTAB_MIGRATIONS_DIR" >&2
    exit 1
fi

umask 077
client_defaults=$(mktemp "${TMPDIR:-/tmp}/estab-mariadb-client.XXXXXX")
migration_list=$(mktemp "${TMPDIR:-/tmp}/estab-migration-list.XXXXXX")
cleanup()
{
    rm -f -- "$client_defaults" "$migration_list"
}
trap cleanup EXIT HUP INT TERM

escape_option_value()
{
    sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

database_password=$(tr -d '\r\n' < "$ESTAB_DB_ROOT_PASSWORD_FILE")
if [ -z "$database_password" ]; then
    echo "MariaDB root secret is empty" >&2
    exit 1
fi
escaped_password=$(printf '%s' "$database_password" | escape_option_value)
unset database_password

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

database_query()
{
    mariadb \
        --defaults-extra-file="$client_defaults" \
        --batch \
        --skip-column-names \
        --raw \
        --database="$ESTAB_DB_NAME" \
        --execute="$1"
}

database_apply()
{
    mariadb \
        --defaults-extra-file="$client_defaults" \
        --database="$ESTAB_DB_NAME"
}

database_verify()
{
    mariadb \
        --defaults-extra-file="$client_defaults" \
        --batch \
        --skip-column-names \
        --raw \
        --database="$ESTAB_DB_NAME"
}

database_query "
CREATE TABLE IF NOT EXISTS estab_schema_migrations (
  version VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state ENUM('applying','applied') NOT NULL,
  run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  started_at DATETIME(6) NOT NULL,
  applied_at DATETIME(6) NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"

set -- "$ESTAB_MIGRATIONS_DIR"/*.sql
if [ ! -f "$1" ]; then
    echo "No SQL migrations found in $ESTAB_MIGRATIONS_DIR" >&2
    exit 1
fi
printf '%s\n' "$@" | sort -V > "$migration_list"

while IFS= read -r migration_path; do
    migration_name=$(basename "$migration_path")
    if ! printf '%s\n' "$migration_name" \
        | grep -Eq '^[0-9]+-[a-z0-9]+([a-z0-9-]*[a-z0-9])?\.sql$'; then
        echo "Invalid migration filename: $migration_name" >&2
        exit 1
    fi

    migration_checksum=$(sha256sum "$migration_path" | awk '{print $1}')
    case "$migration_checksum" in
        *[!0-9a-f]*|'')
            echo "Could not calculate SHA-256 for $migration_name" >&2
            exit 1
            ;;
    esac

    migration_record=$(database_query \
        "SELECT CONCAT(checksum, '|', state)
           FROM estab_schema_migrations
          WHERE version = '$migration_name'")

    if [ "$migration_record" = "$migration_checksum|applied" ]; then
        echo "Migration already applied: $migration_name"
        continue
    fi
    if [ -n "$migration_record" ]; then
        stored_checksum=${migration_record%%|*}
        stored_state=${migration_record#*|}
        if [ "$stored_checksum" != "$migration_checksum" ]; then
            echo "Checksum mismatch for applied migration: $migration_name" >&2
        else
            echo "Migration is already in state $stored_state: $migration_name" >&2
        fi
        exit 1
    fi

    run_id=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
    database_query "
INSERT INTO estab_schema_migrations
  (version, checksum, state, run_id, started_at, applied_at)
VALUES
  ('$migration_name', '$migration_checksum', 'applying', '$run_id', NOW(6), NULL)"

    echo "Applying migration: $migration_name"
    if ! database_apply < "$migration_path"; then
        database_query "
DELETE FROM estab_schema_migrations
 WHERE version = '$migration_name'
   AND checksum = '$migration_checksum'
   AND state = 'applying'
   AND run_id = '$run_id'" || true
        echo "Migration failed; application start remains blocked: $migration_name" >&2
        exit 1
    fi

    updated_rows=$(database_query "
UPDATE estab_schema_migrations
   SET state = 'applied', run_id = NULL, applied_at = NOW(6)
 WHERE version = '$migration_name'
   AND checksum = '$migration_checksum'
   AND state = 'applying'
   AND run_id = '$run_id';
SELECT ROW_COUNT();")
    if [ "$updated_rows" != "1" ]; then
        echo "Could not finalize migration record: $migration_name" >&2
        exit 1
    fi
done < "$migration_list"

if [ -n "$ESTAB_SCHEMA_VERIFY_FILE" ]; then
    if [ ! -r "$ESTAB_SCHEMA_VERIFY_FILE" ]; then
        echo "Schema verification file is not readable: $ESTAB_SCHEMA_VERIFY_FILE" >&2
        exit 1
    fi
    verification_output=$(database_verify < "$ESTAB_SCHEMA_VERIFY_FILE")
    if ! printf '%s\n' "$verification_output" | awk -F '\t' '
        NR == 1 {
            if (NF < 1) exit 1
            for (column = 1; column <= NF; column++) {
                if ($column != "1") exit 1
            }
            verified = 1
            next
        }
        { exit 1 }
        END { if (!verified) exit 1 }
    '; then
        echo "Post-migration schema verification failed:" >&2
        printf '%s\n' "$verification_output" >&2
        exit 1
    fi
    verification_count=$(printf '%s\n' "$verification_output" \
        | awk -F '\t' 'NR == 1 { print NF }')
    echo "Post-migration schema verification passed ($verification_count checks)."
fi

echo "All schema migrations are applied."
