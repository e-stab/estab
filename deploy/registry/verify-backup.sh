#!/bin/sh

set -eu

if [ "$#" -ne 2 ]; then
    printf 'Usage: %s BACKUP_DIRECTORY EXPECTED_DATABASE\n' "$0" >&2
    exit 64
fi

backup_dir=${1%/}
expected_database=$2
if [ -z "$backup_dir" ] || [ ! -d "$backup_dir" ]; then
    printf 'Backup verification: directory does not exist: %s\n' "$1" >&2
    exit 1
fi
case "$expected_database" in
    ''|*[!A-Za-z0-9_]*)
        printf 'Backup verification: expected database is not a safe identifier.\n' >&2
        exit 64
        ;;
esac

manifest=$backup_dir/SHA256SUMS
database_dump=$backup_dir/database.sql
data_archive=$backup_dir/4fdata.tar.gz
export_archive=$backup_dir/export.tar.gz

if [ ! -f "$manifest" ] || [ -L "$manifest" ] || [ ! -r "$manifest" ]; then
    printf 'Backup verification: required regular file is missing: %s\n' \
        "$manifest" >&2
    exit 1
fi

backup_format=legacy
if [ -e "$backup_dir/backup-format.txt" ] ||
    [ -L "$backup_dir/backup-format.txt" ]; then
    if [ ! -f "$backup_dir/backup-format.txt" ] ||
        [ -L "$backup_dir/backup-format.txt" ] ||
        [ ! -r "$backup_dir/backup-format.txt" ]; then
        printf 'Backup verification: backup format metadata is not a readable regular file.\n' >&2
        exit 1
    fi
    format_record=$(sed -n '1p' "$backup_dir/backup-format.txt")
    case "$format_record" in
        estab-full-backup-v2)
            backup_format=v2
            ;;
        estab-full-backup-v3)
            backup_format=v3
            ;;
        *)
            printf 'Backup verification: unsupported backup format metadata.\n' >&2
            exit 1
            ;;
    esac
    expected_manifest_names='4fdata.tar.gz
backup-created-utc.txt
backup-format.txt
database-name.txt
database.sql
export.tar.gz
image-references.txt
project-name.txt
storage-sources.txt'
    if [ "$backup_format" = v3 ]; then
        expected_manifest_names='4fdata.tar.gz
backup-created-utc.txt
backup-format.txt
database-name.txt
database.sql
export.tar.gz
image-references.txt
project-name.txt
release-identity.txt
storage-sources.txt'
    fi
else
    expected_manifest_names='4fdata.tar.gz
database.sql
export.tar.gz'
fi

if [ "$backup_format" != legacy ]; then
    expected_directory_names='4fdata.tar.gz
SHA256SUMS
backup-created-utc.txt
backup-format.txt
database-name.txt
database.sql
export.tar.gz
image-references.txt
project-name.txt
storage-sources.txt'
    if [ "$backup_format" = v3 ]; then
        expected_directory_names='4fdata.tar.gz
SHA256SUMS
backup-created-utc.txt
backup-format.txt
database-name.txt
database.sql
export.tar.gz
image-references.txt
project-name.txt
release-identity.txt
storage-sources.txt'
    fi
    actual_directory_names=$(
        find "$backup_dir"/. ! -name . -prune -exec basename {} \; |
            LC_ALL=C sort
    ) || {
        printf 'Backup verification: cannot inspect backup directory entries.\n' >&2
        exit 1
    }
    if [ "$actual_directory_names" != "$expected_directory_names" ]; then
        printf 'Backup verification: format %s contains an unbound or missing directory entry.\n' \
            "$backup_format" >&2
        exit 1
    fi
fi

while IFS= read -r required_name; do
    required_file=$backup_dir/$required_name
    if [ ! -f "$required_file" ] || [ -L "$required_file" ] ||
        [ ! -r "$required_file" ]; then
        printf 'Backup verification: required regular file is missing: %s\n' \
            "$required_file" >&2
        exit 1
    fi
done <<EOF
$expected_manifest_names
EOF

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
    printf 'Backup verification: SHA256SUMS has missing, extra, or duplicate entries.\n' >&2
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
if [ -z "$created_database" ] ||
    [ "$created_database" != "$selected_database" ] ||
    [ "$created_database" != "$expected_database" ]; then
    printf 'Backup verification: CREATE DATABASE and USE must name the expected safe database (%s).\n' \
        "$expected_database" >&2
    exit 1
fi

if [ "$backup_format" != legacy ]; then
    if ! awk '
      NR != 1 ||
      ($0 != "estab-full-backup-v2" &&
       $0 != "estab-full-backup-v3") {
        exit 1
      }
      END { if (NR != 1) exit 1 }
    ' "$backup_dir/backup-format.txt"; then
        printf 'Backup verification: unsupported backup format metadata.\n' >&2
        exit 1
    fi

    if ! LC_ALL=C awk '
      NR != 1 ||
      $0 !~ /^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z$/ {
        exit 1
      }
      END { if (NR != 1) exit 1 }
    ' "$backup_dir/backup-created-utc.txt"; then
        printf 'Backup verification: creation timestamp metadata is invalid.\n' >&2
        exit 1
    fi

    if ! LC_ALL=C awk '
      NR != 1 || $0 !~ /^[A-Za-z0-9_.-]+$/ { exit 1 }
      END { if (NR != 1) exit 1 }
    ' "$backup_dir/project-name.txt"; then
        printf 'Backup verification: Compose project metadata is invalid.\n' >&2
        exit 1
    fi

    if ! LC_ALL=C awk -v expected="$expected_database" '
      NR != 1 || $0 != expected { exit 1 }
      END { if (NR != 1) exit 1 }
    ' "$backup_dir/database-name.txt"; then
        printf 'Backup verification: database metadata does not match the expected database.\n' >&2
        exit 1
    fi

    if ! LC_ALL=C awk -F '	' '
      BEGIN {
        destination["database"] = "/var/lib/mysql"
        destination["application"] = "/var/www/html/4fdata"
        destination["export"] = "/var/lib/estab/export"
      }
      {
        if (NF != 5 || !($1 in destination) || seen[$1]++ ||
            $2 != destination[$1] ||
            ($3 != "volume" && $3 != "bind") ||
            $5 !~ /^\//) {
          exit 1
        }
        if ($3 == "volume" &&
            ($4 == "-" || $4 !~ /^[A-Za-z0-9_.-]+$/)) {
          exit 1
        }
        if ($3 == "bind" && $4 != "-") {
          exit 1
        }
      }
      END {
        if (NR != 3 ||
            !seen["database"] ||
            !seen["application"] ||
            !seen["export"]) {
          exit 1
        }
      }
    ' "$backup_dir/storage-sources.txt"; then
        printf 'Backup verification: storage-source metadata is invalid.\n' >&2
        exit 1
    fi

    if ! LC_ALL=C awk -F '	' '
      {
        if (NF != 3 ||
            ($1 != "app" && $1 != "migrate" && $1 != "database") ||
            seen[$1]++ ||
            $2 !~ /^[A-Za-z0-9_.\/:@+-]+$/ ||
            length($3) != 71 ||
            $3 !~ /^sha256:[0-9a-f]+$/) {
          exit 1
        }
      }
      END {
        if (NR != 3 || !seen["app"] || !seen["migrate"] || !seen["database"]) {
          exit 1
        }
      }
    ' "$backup_dir/image-references.txt"; then
        printf 'Backup verification: image-reference metadata is invalid.\n' >&2
        exit 1
    fi

    if [ "$backup_format" = v3 ]; then
        if ! LC_ALL=C awk -F '	' '
          NR == FNR {
            if (NF != 3 ||
                ($1 != "app" && $1 != "migrate" && $1 != "database") ||
                image_seen[$1]++ ||
                $2 !~ /^[A-Za-z0-9_.\/:@+-]+$/) {
              exit 1
            }
            image_reference[$1] = $2
            next
          }
          NR != FNR {
            if (NF != 2 ||
                ($1 != "app" && $1 != "migrate" && $1 != "database") ||
                release_seen[$1]++ ||
                $2 !~ /^[A-Za-z0-9_.\/:@+-]+$/ ||
                $2 != image_reference[$1]) {
              exit 1
            }
          }
          END {
            if (image_seen["app"] != 1 ||
                image_seen["migrate"] != 1 ||
                image_seen["database"] != 1 ||
                release_seen["app"] != 1 ||
                release_seen["migrate"] != 1 ||
                release_seen["database"] != 1) {
              exit 1
            }
          }
        ' "$backup_dir/image-references.txt" \
            "$backup_dir/release-identity.txt"; then
            printf 'Backup verification: canonical release identity is invalid or differs from the diagnostic image records.\n' >&2
            exit 1
        fi
    fi
fi

verify_archive()
{
    archive=$1
    gzip -t "$archive" || return 1
    # -P preserves absolute member names while listing. Without it GNU tar
    # silently strips the leading slash before this safety check can see it.
    archive_names=$(tar -P -tzf "$archive") || return 1
    printf '%s\n' "$archive_names" | awk '
      length($0) == 0 { next }
      /^\// || /(^|\/)\.\.(\/|$)/ { exit 1 }
      { entries++ }
      END { if (!entries) exit 1 }
    ' || return 1
    archive_details=$(tar -P -tvzf "$archive") || return 1
    printf '%s\n' "$archive_details" | awk '
      length($0) == 0 { next }
      substr($1, 1, 1) == "-" || substr($1, 1, 1) == "d" {
        entries++
        next
      }
      { exit 1 }
      END { if (!entries) exit 1 }
    ' || return 1
}

verify_archive "$data_archive" || {
    printf 'Backup verification: invalid 4fdata archive.\n' >&2
    exit 1
}
verify_archive "$export_archive" || {
    printf 'Backup verification: invalid export archive.\n' >&2
    exit 1
}

printf 'Backup verification: OK (%s, %s)\n' \
    "$created_database" "$backup_format"
