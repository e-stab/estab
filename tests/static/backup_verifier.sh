#!/bin/sh

set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
verifier=$repo_root/deploy/registry/verify-backup.sh
work_dir=$(mktemp -d "${TMPDIR:-/tmp}/estab-backup-verifier.XXXXXX")
trap 'rm -rf -- "$work_dir"' EXIT HUP INT TERM

valid=$work_dir/valid
mkdir -p "$valid/data/nested" "$valid/export"
printf 'attachment\n' >"$valid/data/nested/file.txt"
printf 'export\n' >"$valid/export/run.txt"
tar -C "$valid/data" -czf "$valid/4fdata.tar.gz" .
tar -C "$valid/export" -czf "$valid/export.tar.gz" .
cat >"$valid/database.sql" <<'EOF'
-- MariaDB dump 10.19
CREATE DATABASE /*!32312 IF NOT EXISTS*/ `estab`;
USE `estab`;
-- Dump completed on 2026-07-29  01:00:00
EOF
(cd "$valid" && sha256sum database.sql 4fdata.tar.gz export.tar.gz >SHA256SUMS)

sh "$verifier" "$valid" >/dev/null

tampered=$work_dir/tampered
cp -R "$valid" "$tampered"
printf 'tampered\n' >>"$tampered/database.sql"
if sh "$verifier" "$tampered" >/dev/null 2>&1; then
    printf 'Backup verifier test: tampered payload accepted\n' >&2
    exit 1
fi

unsafe_manifest=$work_dir/unsafe-manifest
cp -R "$valid" "$unsafe_manifest"
printf '%064d  ../outside\n' 0 >>"$unsafe_manifest/SHA256SUMS"
if sh "$verifier" "$unsafe_manifest" >/dev/null 2>&1; then
    printf 'Backup verifier test: unsafe manifest accepted\n' >&2
    exit 1
fi

link_archive=$work_dir/link-archive
cp -R "$valid" "$link_archive"
mkdir -p "$work_dir/link-source"
ln -s target "$work_dir/link-source/link"
tar -C "$work_dir/link-source" -czf "$link_archive/4fdata.tar.gz" .
(cd "$link_archive" && sha256sum database.sql 4fdata.tar.gz export.tar.gz >SHA256SUMS)
if sh "$verifier" "$link_archive" >/dev/null 2>&1; then
    printf 'Backup verifier test: symlink archive accepted\n' >&2
    exit 1
fi

malformed_archive=$work_dir/malformed-archive
cp -R "$valid" "$malformed_archive"
printf 'valid gzip, but not a tar archive\n' >"$work_dir/not-a-tar"
gzip -c "$work_dir/not-a-tar" >"$malformed_archive/4fdata.tar.gz"
(cd "$malformed_archive" && sha256sum database.sql 4fdata.tar.gz export.tar.gz >SHA256SUMS)
if sh "$verifier" "$malformed_archive" >/dev/null 2>&1; then
    printf 'Backup verifier test: malformed tar archive accepted\n' >&2
    exit 1
fi

wrong_database=$work_dir/wrong-database
cp -R "$valid" "$wrong_database"
sed 's/USE `estab`;/USE `other`;/' "$valid/database.sql" \
    >"$wrong_database/database.sql"
(cd "$wrong_database" && sha256sum database.sql 4fdata.tar.gz export.tar.gz >SHA256SUMS)
if sh "$verifier" "$wrong_database" >/dev/null 2>&1; then
    printf 'Backup verifier test: mismatched database names accepted\n' >&2
    exit 1
fi

printf 'Backup verifier tests: OK\n'
