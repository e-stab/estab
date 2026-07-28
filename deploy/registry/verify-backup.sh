#!/bin/sh

set -eu

if [ "$#" -ne 1 ]; then
    printf 'Usage: %s BACKUP_DIRECTORY\n' "$0" >&2
    exit 64
fi

backup_dir=${1%/}
if [ -z "$backup_dir" ] || [ ! -d "$backup_dir" ]; then
    printf 'Backup verification: directory does not exist: %s\n' "$1" >&2
    exit 1
fi

manifest=$backup_dir/SHA256SUMS
database_dump=$backup_dir/database.sql
data_archive=$backup_dir/4fdata.tar.gz
export_archive=$backup_dir/export.tar.gz

for required_file in \
    "$manifest" \
    "$database_dump" \
    "$data_archive" \
    "$export_archive"
do
    if [ ! -f "$required_file" ] || [ -L "$required_file" ] || [ ! -r "$required_file" ]; then
        printf 'Backup verification: required regular file is missing: %s\n' \
            "$required_file" >&2
        exit 1
    fi
done

expected_manifest_names='4fdata.tar.gz
database.sql
export.tar.gz'
unsorted_manifest_names=$(
    awk '
      {
        digest = substr($0, 1, 64)
        separator = substr($0, 65, 2)
        name = substr($0, 67)
        if (length(digest) != 64 || digest ~ /[^0-9a-fA-F]/ ||
            (separator != "  " && separator != " *") ||
            name == "" || index(name, "/") != 0) {
          exit 1
        }
        print name
      }
    ' "$manifest"
) || {
    printf 'Backup verification: SHA256SUMS has an unsafe format.\n' >&2
    exit 1
}
actual_manifest_names=$(
    printf '%s\n' "$unsorted_manifest_names" | LC_ALL=C sort
)
if [ "$actual_manifest_names" != "$expected_manifest_names" ]; then
    printf 'Backup verification: SHA256SUMS must name exactly the three payload files.\n' >&2
    exit 1
fi

if command -v sha256sum >/dev/null 2>&1; then
    (cd "$backup_dir" && sha256sum -c SHA256SUMS)
elif command -v shasum >/dev/null 2>&1; then
    (cd "$backup_dir" && shasum -a 256 -c SHA256SUMS)
else
    printf 'Backup verification: sha256sum or shasum is required.\n' >&2
    exit 1
fi

if [ ! -s "$database_dump" ] ||
    ! grep -Fq -- '-- MariaDB dump' "$database_dump" ||
    ! grep -Fq -- '-- Dump completed on ' "$database_dump"; then
    printf 'Backup verification: MariaDB dump is empty or incomplete.\n' >&2
    exit 1
fi

created_database=$(
    sed -n 's/^CREATE DATABASE .*`\([A-Za-z0-9_][A-Za-z0-9_]*\)`.*/\1/p' \
        "$database_dump" | head -n 1
)
selected_database=$(
    sed -n 's/^USE `\([A-Za-z0-9_][A-Za-z0-9_]*\)`;$/\1/p' \
        "$database_dump" | head -n 1
)
if [ -z "$created_database" ] || [ "$created_database" != "$selected_database" ]; then
    printf 'Backup verification: CREATE DATABASE and USE do not name the same safe database.\n' >&2
    exit 1
fi

verify_archive()
{
    archive=$1
    gzip -t "$archive"
    archive_names=$(tar -tzf "$archive") || return 1
    printf '%s\n' "$archive_names" | awk '
      length($0) == 0 { next }
      /^\// || /(^|\/)\.\.(\/|$)/ { exit 1 }
      { entries++ }
      END { if (!entries) exit 1 }
    '
    archive_details=$(tar -tvzf "$archive") || return 1
    printf '%s\n' "$archive_details" | awk '
      length($0) == 0 { next }
      substr($1, 1, 1) == "-" || substr($1, 1, 1) == "d" {
        entries++
        next
      }
      { exit 1 }
      END { if (!entries) exit 1 }
    '
}

verify_archive "$data_archive" || {
    printf 'Backup verification: invalid 4fdata archive.\n' >&2
    exit 1
}
verify_archive "$export_archive" || {
    printf 'Backup verification: invalid export archive.\n' >&2
    exit 1
}

printf 'Backup verification: OK (%s)\n' "$created_database"
