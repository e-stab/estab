#!/bin/sh

set -eu

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
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

sh "$verifier" "$valid" estab >/dev/null

valid_v2=$work_dir/valid-v2
cp -R "$valid" "$valid_v2"
rm -rf -- "$valid_v2/data" "$valid_v2/export"
printf 'estab-full-backup-v2\n' >"$valid_v2/backup-format.txt"
printf '2026-07-29T01:00:00Z\n' >"$valid_v2/backup-created-utc.txt"
printf 'estab\n' >"$valid_v2/project-name.txt"
printf 'estab\n' >"$valid_v2/database-name.txt"
cat >"$valid_v2/storage-sources.txt" <<'EOF'
database	/var/lib/mysql	volume	estab_estab_db	/var/lib/containers/storage/volumes/estab_estab_db/_data
application	/var/www/html/4fdata	bind	-	/volume1/docker/estab/data/4fdata
export	/var/lib/estab/export	bind	-	/volume1/docker/estab/data/export
EOF
cat >"$valid_v2/image-references.txt" <<'EOF'
app	ghcr.io/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa	sha256:1111111111111111111111111111111111111111111111111111111111111111
migrate	ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb	sha256:2222222222222222222222222222222222222222222222222222222222222222
database	mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc	sha256:3333333333333333333333333333333333333333333333333333333333333333
EOF
(
    cd "$valid_v2"
    sha256sum \
        4fdata.tar.gz \
        backup-created-utc.txt \
        backup-format.txt \
        database-name.txt \
        database.sql \
        export.tar.gz \
        image-references.txt \
        project-name.txt \
        storage-sources.txt \
        >SHA256SUMS
)
sh "$verifier" "$valid_v2" estab >/dev/null

valid_v3=$work_dir/valid-v3
cp -R "$valid_v2" "$valid_v3"
printf 'estab-full-backup-v3\n' >"$valid_v3/backup-format.txt"
cat >"$valid_v3/release-identity.txt" <<'EOF'
app	ghcr.io/e-stab/estab@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
migrate	ghcr.io/e-stab/estab-migrate@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
database	mariadb@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
EOF
(
    cd "$valid_v3"
    sha256sum \
        4fdata.tar.gz \
        backup-created-utc.txt \
        backup-format.txt \
        database-name.txt \
        database.sql \
        export.tar.gz \
        image-references.txt \
        project-name.txt \
        release-identity.txt \
        storage-sources.txt \
        >SHA256SUMS
)
sh "$verifier" "$valid_v3" estab >/dev/null

invalid_v3_identity=$work_dir/invalid-v3-identity
cp -R "$valid_v3" "$invalid_v3_identity"
sed 's/sha256:aaaaaaaa/sha256:dddddddd/' \
    "$valid_v3/release-identity.txt" \
    >"$invalid_v3_identity/release-identity.txt"
(
    cd "$invalid_v3_identity"
    sha256sum \
        4fdata.tar.gz \
        backup-created-utc.txt \
        backup-format.txt \
        database-name.txt \
        database.sql \
        export.tar.gz \
        image-references.txt \
        project-name.txt \
        release-identity.txt \
        storage-sources.txt \
        >SHA256SUMS
)
if sh "$verifier" "$invalid_v3_identity" estab >/dev/null 2>&1; then
    printf 'Backup verifier test: release identity differing from diagnostic image records accepted\n' >&2
    exit 1
fi

unbound_v3_identity=$work_dir/unbound-v3-identity
cp -R "$valid_v3" "$unbound_v3_identity"
grep -v 'release-identity.txt$' "$valid_v3/SHA256SUMS" \
    >"$unbound_v3_identity/SHA256SUMS"
if sh "$verifier" "$unbound_v3_identity" estab >/dev/null 2>&1; then
    printf 'Backup verifier test: unbound format-3 release identity accepted\n' >&2
    exit 1
fi

invalid_v2_metadata=$work_dir/invalid-v2-metadata
cp -R "$valid_v2" "$invalid_v2_metadata"
printf 'database\t/var/lib/mysql\tbind\t-\trelative/path\n' \
    >"$invalid_v2_metadata/storage-sources.txt"
(
    cd "$invalid_v2_metadata"
    sha256sum \
        4fdata.tar.gz \
        backup-created-utc.txt \
        backup-format.txt \
        database-name.txt \
        database.sql \
        export.tar.gz \
        image-references.txt \
        project-name.txt \
        storage-sources.txt \
        >SHA256SUMS
)
if sh "$verifier" "$invalid_v2_metadata" estab >/dev/null 2>&1; then
    printf 'Backup verifier test: invalid but correctly hashed v2 metadata accepted\n' >&2
    exit 1
fi

unbound_v2_metadata=$work_dir/unbound-v2-metadata
cp -R "$valid_v2" "$unbound_v2_metadata"
grep -v 'project-name.txt$' "$valid_v2/SHA256SUMS" \
    >"$unbound_v2_metadata/SHA256SUMS"
if sh "$verifier" "$unbound_v2_metadata" estab >/dev/null 2>&1; then
    printf 'Backup verifier test: unbound v2 metadata accepted\n' >&2
    exit 1
fi

for extra_kind in file directory symlink; do
    extra_v2=$work_dir/extra-v2-$extra_kind
    cp -R "$valid_v2" "$extra_v2"
    case "$extra_kind" in
        file)
            printf 'unbound\n' >"$extra_v2/unbound.txt"
            ;;
        directory)
            mkdir "$extra_v2/unbound-directory"
            ;;
        symlink)
            ln -s database.sql "$extra_v2/unbound-link"
            ;;
    esac
    if sh "$verifier" "$extra_v2" estab >/dev/null 2>&1; then
        printf 'Backup verifier test: unbound v2 %s accepted\n' \
            "$extra_kind" >&2
        exit 1
    fi
done

if sh "$verifier" "$valid" other >/dev/null 2>&1; then
    printf 'Backup verifier test: dump for another target database accepted\n' >&2
    exit 1
fi
if sh "$verifier" "$valid" 'estab;other' >/dev/null 2>&1; then
    printf 'Backup verifier test: unsafe expected database accepted\n' >&2
    exit 1
fi
if sh "$verifier" "$valid" >/dev/null 2>&1; then
    printf 'Backup verifier test: missing expected database accepted\n' >&2
    exit 1
fi

tampered=$work_dir/tampered
cp -R "$valid" "$tampered"
printf 'tampered\n' >>"$tampered/database.sql"
if sh "$verifier" "$tampered" estab >/dev/null 2>&1; then
    printf 'Backup verifier test: tampered payload accepted\n' >&2
    exit 1
fi

unsafe_manifest=$work_dir/unsafe-manifest
cp -R "$valid" "$unsafe_manifest"
printf '%064d  ../outside\n' 0 >>"$unsafe_manifest/SHA256SUMS"
if sh "$verifier" "$unsafe_manifest" estab >/dev/null 2>&1; then
    printf 'Backup verifier test: unsafe manifest accepted\n' >&2
    exit 1
fi

link_archive=$work_dir/link-archive
cp -R "$valid" "$link_archive"
mkdir -p "$work_dir/link-source"
ln -s target "$work_dir/link-source/link"
tar -C "$work_dir/link-source" -czf "$link_archive/4fdata.tar.gz" .
(cd "$link_archive" && sha256sum database.sql 4fdata.tar.gz export.tar.gz >SHA256SUMS)
if sh "$verifier" "$link_archive" estab >/dev/null 2>&1; then
    printf 'Backup verifier test: symlink archive accepted\n' >&2
    exit 1
fi

malformed_archive=$work_dir/malformed-archive
cp -R "$valid" "$malformed_archive"
printf 'valid gzip, but not a tar archive\n' >"$work_dir/not-a-tar"
gzip -c "$work_dir/not-a-tar" >"$malformed_archive/4fdata.tar.gz"
(cd "$malformed_archive" && sha256sum database.sql 4fdata.tar.gz export.tar.gz >SHA256SUMS)
if sh "$verifier" "$malformed_archive" estab >/dev/null 2>&1; then
    printf 'Backup verifier test: malformed tar archive accepted\n' >&2
    exit 1
fi

make_unsafe_archive()
{
    unsafe_kind=$1
    unsafe_output=$2
    unsafe_source=$work_dir/unsafe-archive-source
    rm -rf -- "$unsafe_source"
    mkdir "$unsafe_source"
    printf 'must never escape\n' >"$unsafe_source/payload"
    case "$unsafe_kind" in
        parent) unsafe_name='../escape' ;;
        absolute) unsafe_name='/escape' ;;
        *) exit 1 ;;
    esac
    if tar --version 2>/dev/null | grep -Fq 'GNU tar'; then
        tar -P -C "$unsafe_source" -czf "$unsafe_output" \
            --transform="s#^payload\$#$unsafe_name#" payload
    else
        tar -P -C "$unsafe_source" -czf "$unsafe_output" \
            -s "#^payload\$#$unsafe_name#" payload
    fi
}

for unsafe_kind in parent absolute; do
    unsafe_archive=$work_dir/unsafe-archive-$unsafe_kind
    cp -R "$valid" "$unsafe_archive"
    make_unsafe_archive "$unsafe_kind" "$unsafe_archive/4fdata.tar.gz"
    (
        cd "$unsafe_archive"
        sha256sum database.sql 4fdata.tar.gz export.tar.gz >SHA256SUMS
    )
    if sh "$verifier" "$unsafe_archive" estab >/dev/null 2>&1; then
        printf 'Backup verifier test: %s archive path accepted\n' \
            "$unsafe_kind" >&2
        exit 1
    fi
done

wrong_database=$work_dir/wrong-database
cp -R "$valid" "$wrong_database"
sed 's/USE `estab`;/USE `other`;/' "$valid/database.sql" \
    >"$wrong_database/database.sql"
(cd "$wrong_database" && sha256sum database.sql 4fdata.tar.gz export.tar.gz >SHA256SUMS)
if sh "$verifier" "$wrong_database" estab >/dev/null 2>&1; then
    printf 'Backup verifier test: mismatched database names accepted\n' >&2
    exit 1
fi

printf 'Backup verifier tests: OK\n'
