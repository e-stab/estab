#!/bin/sh

set -eu

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd -P)
offline_helper=$repo_root/deploy/registry/offline-images.sh

[ -x "$offline_helper" ] || {
    echo "Offline image test: helper is not executable" >&2
    exit 1
}

temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/estab-offline-test.XXXXXX")
temporary_root=$(CDPATH='' cd -- "$temporary_root" && pwd -P)
cleanup()
{
    rm -rf -- "$temporary_root"
}
trap cleanup EXIT HUP INT TERM

bundle=$temporary_root/bundle
fake_bin=$temporary_root/bin
state=$temporary_root/state
mkdir "$bundle" "$fake_bin" "$state"
cp "$offline_helper" "$bundle/offline-images.sh"
chmod 0755 "$bundle/offline-images.sh"

commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
app_digest=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
migrate_digest=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
database_digest=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee
{
    printf 'Git-Tag: release-1.2.3\n'
    printf 'Git-Commit: %s\n' "$commit"
    printf 'App-Image: ghcr.io/e-stab/estab@sha256:%s\n' "$app_digest"
    printf 'Migrator-Image: ghcr.io/e-stab/estab-migrate@sha256:%s\n' \
        "$migrate_digest"
} >"$bundle/RELEASE"
{
    printf 'services:\n'
    printf '  db:\n'
    printf '    image: mariadb:11.8.8@sha256:%s\n' "$database_digest"
    printf '  app:\n'
    printf '    image: fixture.invalid/app\n'
} >"$bundle/compose.yaml"
{
    printf '#!/bin/sh\n'
    printf 'set -eu\n'
    printf '[ "$#" -eq 1 ]\n'
    printf '[ "$1" = "$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)" ]\n'
} >"$bundle/verify-release.sh"
chmod 0755 "$bundle/verify-release.sh"

cat >"$fake_bin/skopeo" <<'EOF'
#!/bin/sh
set -eu

: "${OFFLINE_TEST_STATE:?}"
: "${OFFLINE_APP_DIGEST:?}"
: "${OFFLINE_MIGRATE_DIGEST:?}"
: "${OFFLINE_DATABASE_DIGEST:?}"

command_name=$1
shift
printf '%s %s\n' "$command_name" "$*" >>"$OFFLINE_TEST_STATE/events"

registry_state_file()
{
    registry_reference=$1
    registry_key=$(printf '%s' "$registry_reference" |
        tr '/:@' '____')
    printf '%s/registry-%s\n' "$OFFLINE_TEST_STATE" "$registry_key"
}

transport_role()
{
    inspected_value=$1
    case "$inspected_value" in
        *estab-db*|*database*|*mariadb*) printf 'database\n' ;;
        *estab-migrate*|*migrate*) printf 'migrate\n' ;;
        *) printf 'app\n' ;;
    esac
}

case "$command_name" in
    copy)
        saw_all=0
        saw_preserve=0
        source_transport=
        destination_transport=
        for copy_argument in "$@"; do
            case "$copy_argument" in
                --all) saw_all=1 ;;
                --preserve-digests) saw_preserve=1 ;;
                --*) exit 2 ;;
                *)
                    if [ -z "$source_transport" ]; then
                        source_transport=$copy_argument
                    elif [ -z "$destination_transport" ]; then
                        destination_transport=$copy_argument
                    else
                        exit 2
                    fi
                    ;;
            esac
        done
        [ "$saw_all" -eq 1 ]
        [ "$saw_preserve" -eq 1 ]
        [ -n "$source_transport" ]
        [ -n "$destination_transport" ]
        case "$destination_transport" in
            oci-archive:*)
                if [ -n "${OFFLINE_EXPECT_RESERVED_TARGET:-}" ]; then
                    [ -d "$OFFLINE_EXPECT_RESERVED_TARGET" ]
                    [ -f "$OFFLINE_EXPECT_RESERVED_TARGET/.estab-export-in-progress" ]
                fi
                archive_value=${destination_transport#oci-archive:}
                archive_path=${archive_value%:*}
                case "$archive_path" in
                    *database.oci.tar) role=database ;;
                    *migrate.oci.tar) role=migrate ;;
                    *app.oci.tar) role=app ;;
                    *) exit 2 ;;
                esac
                if [ "${OFFLINE_SIGNAL_EXPORT_ROLE:-}" = "$role" ]; then
                    kill -HUP "$PPID"
                    sleep 1
                fi
                [ "${OFFLINE_FAIL_EXPORT_ROLE:-}" != "$role" ] || exit 70
                printf 'fixture-role=%s\n' "$role" >"$archive_path"
                ;;
            docker://*)
                role=$(transport_role "$source_transport")
                if [ "${OFFLINE_FAIL_FINAL_ROLE:-}" = "$role" ]; then
                    case "$destination_transport" in
                        *:release-1.2.3) exit 70 ;;
                    esac
                fi
                destination_reference=${destination_transport#docker://}
                registry_file=$(registry_state_file "$destination_reference")
                printf '%s\n' "$role" >"$registry_file"
                if [ -n "${OFFLINE_RACE_EVIDENCE_TARGET:-}" ]; then
                    mkdir -p -- "$OFFLINE_RACE_EVIDENCE_TARGET"
                fi
                ;;
            *) exit 2 ;;
        esac
        ;;
    inspect)
        [ "$1" = --raw ]
        transport=$2
        case "$transport" in
            docker://*)
                registry_reference=${transport#docker://}
                case "$registry_reference" in
                    *@sha256:*)
                        role=$(transport_role "$registry_reference")
                        ;;
                    *)
                        registry_file=$(registry_state_file \
                            "$registry_reference")
                        if [ ! -f "$registry_file" ]; then
                            printf 'manifest unknown\n' >&2
                            exit 1
                        fi
                        role=$(cat "$registry_file")
                        ;;
                esac
                ;;
            *)
                role=$(transport_role "$transport")
                ;;
        esac
        if [ "${OFFLINE_BAD_ARCH:-0}" -eq 1 ]; then
            printf '{"schemaVersion":2,"role":"%s","manifests":[{"platform":{"os":"linux","architecture":"amd64"}}]}\n' \
                "$role"
        else
            printf '{"schemaVersion":2,"role":"%s","manifests":[{"platform":{"os":"linux","architecture":"amd64"}},{"platform":{"os":"linux","architecture":"arm64"}}]}\n' \
                "$role"
        fi
        ;;
    manifest-digest)
        manifest_file=$1
        if [ "${OFFLINE_BAD_DIGEST:-0}" -eq 1 ]; then
            printf 'sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd\n'
        elif grep -Fq '"role":"bad"' "$manifest_file"; then
            printf 'sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd\n'
        elif grep -Fq '"role":"database"' "$manifest_file"; then
            if [ "${OFFLINE_BAD_DATABASE_DIGEST:-0}" -eq 1 ]; then
                printf 'sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd\n'
            else
                printf '%s\n' "$OFFLINE_DATABASE_DIGEST"
            fi
        elif grep -Fq '"role":"migrate"' "$manifest_file"; then
            printf '%s\n' "$OFFLINE_MIGRATE_DIGEST"
        else
            printf '%s\n' "$OFFLINE_APP_DIGEST"
        fi
        ;;
    *)
        exit 2
        ;;
esac
EOF

cat >"$fake_bin/jq" <<'EOF'
#!/bin/sh
set -eu

manifest_file=
for jq_argument in "$@"; do
    manifest_file=$jq_argument
done
[ -f "$manifest_file" ]
grep -Fq '"schemaVersion":2' "$manifest_file"
[ "$(grep -o '"architecture":"amd64"' "$manifest_file" | wc -l | tr -d ' ')" -eq 1 ]
[ "$(grep -o '"architecture":"arm64"' "$manifest_file" | wc -l | tr -d ' ')" -eq 1 ]
EOF
chmod 0755 "$fake_bin/skopeo" "$fake_bin/jq"

run_helper()
{
    PATH="$fake_bin:$PATH" \
        OFFLINE_TEST_STATE="$state" \
        OFFLINE_APP_DIGEST="sha256:$app_digest" \
        OFFLINE_MIGRATE_DIGEST="sha256:$migrate_digest" \
        OFFLINE_DATABASE_DIGEST="sha256:$database_digest" \
        "$bundle/offline-images.sh" "$@"
}

expect_failure()
{
    case_name=$1
    expected_message=$2
    shift 2
    if run_helper "$@" >"$state/$case_name.stdout" 2>"$state/$case_name.stderr"; then
        echo "Offline image test: unsafe case passed: $case_name" >&2
        exit 1
    fi
    grep -Fq "$expected_message" "$state/$case_name.stderr" || {
        echo "Offline image test: wrong failure for $case_name" >&2
        sed -n '1,120p' "$state/$case_name.stderr" >&2
        exit 1
    }
}

archive=$temporary_root/offline-images
PATH="$fake_bin:$PATH" \
    OFFLINE_TEST_STATE="$state" \
    OFFLINE_APP_DIGEST="sha256:$app_digest" \
    OFFLINE_MIGRATE_DIGEST="sha256:$migrate_digest" \
    OFFLINE_DATABASE_DIGEST="sha256:$database_digest" \
    OFFLINE_EXPECT_RESERVED_TARGET="$archive" \
    "$bundle/offline-images.sh" export "$archive" >/dev/null
for archive_file in \
    OFFLINE-IMAGES \
    SHA256SUMS \
    app.oci.tar \
    database.oci.tar \
    migrate.oci.tar
do
    [ -s "$archive/$archive_file" ]
done
grep -Fqx "Git-Commit: $commit" "$archive/OFFLINE-IMAGES"
grep -Fqx \
    "App-Image: ghcr.io/e-stab/estab@sha256:$app_digest" \
    "$archive/OFFLINE-IMAGES"
grep -Fqx \
    "Migrator-Image: ghcr.io/e-stab/estab-migrate@sha256:$migrate_digest" \
    "$archive/OFFLINE-IMAGES"
grep -Fqx \
    "Database-Compose-Image: mariadb:11.8.8@sha256:$database_digest" \
    "$archive/OFFLINE-IMAGES"
grep -Fqx \
    "Database-Image: docker.io/library/mariadb@sha256:$database_digest" \
    "$archive/OFFLINE-IMAGES"
run_helper verify "$archive" >/dev/null

[ "$(grep -c '^copy --all --preserve-digests ' "$state/events")" -eq 3 ]
grep -Fq \
    "copy --all --preserve-digests docker://ghcr.io/e-stab/estab@sha256:$app_digest" \
    "$state/events"
grep -Fq \
    "copy --all --preserve-digests docker://ghcr.io/e-stab/estab-migrate@sha256:$migrate_digest" \
    "$state/events"
grep -Fq \
    "copy --all --preserve-digests docker://docker.io/library/mariadb@sha256:$database_digest" \
    "$state/events"

mirror_prefix=registry.example.org/estab-dr
run_helper check-mirror "$archive" "$mirror_prefix" >/dev/null
grep -Fq \
    "inspect --raw docker://$mirror_prefix/estab@sha256:$app_digest" \
    "$state/events"
grep -Fq \
    "inspect --raw docker://$mirror_prefix/estab-migrate@sha256:$migrate_digest" \
    "$state/events"
grep -Fq \
    "inspect --raw docker://$mirror_prefix/estab-db@sha256:$database_digest" \
    "$state/events"
if grep -Eq \
    "inspect --raw docker://$mirror_prefix/(estab|estab-migrate|estab-db):release-" \
    "$state/events"; then
    echo "Offline image test: mirror verification trusted a mutable tag" >&2
    exit 1
fi

partial_export=$temporary_root/partial-export
if PATH="$fake_bin:$PATH" \
    OFFLINE_TEST_STATE="$state" \
    OFFLINE_APP_DIGEST="sha256:$app_digest" \
    OFFLINE_MIGRATE_DIGEST="sha256:$migrate_digest" \
    OFFLINE_DATABASE_DIGEST="sha256:$database_digest" \
    OFFLINE_FAIL_EXPORT_ROLE=migrate \
    "$bundle/offline-images.sh" export "$partial_export" \
    >"$state/partial-export.stdout" 2>"$state/partial-export.stderr"; then
    echo "Offline image test: failed export unexpectedly succeeded" >&2
    exit 1
fi
grep -Fq 'incomplete reserved archive retained for inspection' \
    "$state/partial-export.stderr"
[ -f "$partial_export/.estab-export-in-progress" ]

signal_export=$temporary_root/signal-export
set +e
PATH="$fake_bin:$PATH" \
    OFFLINE_TEST_STATE="$state" \
    OFFLINE_APP_DIGEST="sha256:$app_digest" \
    OFFLINE_MIGRATE_DIGEST="sha256:$migrate_digest" \
    OFFLINE_DATABASE_DIGEST="sha256:$database_digest" \
    OFFLINE_SIGNAL_EXPORT_ROLE=app \
    "$bundle/offline-images.sh" export "$signal_export" \
    >"$state/signal-export.stdout" 2>"$state/signal-export.stderr"
signal_export_status=$?
set -e
[ "$signal_export_status" -eq 129 ] || {
    echo "Offline image test: HUP did not return status 129" >&2
    exit 1
}
grep -Fq 'incomplete reserved archive retained for inspection' \
    "$state/signal-export.stderr"
[ -f "$signal_export/.estab-export-in-progress" ]

cp -R "$archive" "$temporary_root/tampered"
printf 'tampered\n' >>"$temporary_root/tampered/database.oci.tar"
expect_failure tampered 'offline archive checksum differs' \
    verify "$temporary_root/tampered"

cp -R "$archive" "$temporary_root/extra-entry"
printf 'not bound\n' >"$temporary_root/extra-entry/unbound.txt"
expect_failure extra-entry 'archive directory contains an unbound entry' \
    verify "$temporary_root/extra-entry"

cp -R "$archive" "$temporary_root/missing-database"
rm -f -- "$temporary_root/missing-database/database.oci.tar"
expect_failure missing-database \
    'archive directory must contain exactly the canonical five files' \
    verify "$temporary_root/missing-database"

if PATH="$fake_bin:$PATH" \
    OFFLINE_TEST_STATE="$state" \
    OFFLINE_APP_DIGEST="sha256:$app_digest" \
    OFFLINE_MIGRATE_DIGEST="sha256:$migrate_digest" \
    OFFLINE_DATABASE_DIGEST="sha256:$database_digest" \
    OFFLINE_BAD_DIGEST=1 \
    "$bundle/offline-images.sh" verify "$archive" \
    >"$state/bad-digest.stdout" 2>"$state/bad-digest.stderr"; then
    echo "Offline image test: changed manifest digest passed" >&2
    exit 1
fi
grep -Fq 'digest differs' "$state/bad-digest.stderr"

if PATH="$fake_bin:$PATH" \
    OFFLINE_TEST_STATE="$state" \
    OFFLINE_APP_DIGEST="sha256:$app_digest" \
    OFFLINE_MIGRATE_DIGEST="sha256:$migrate_digest" \
    OFFLINE_DATABASE_DIGEST="sha256:$database_digest" \
    OFFLINE_BAD_DATABASE_DIGEST=1 \
    "$bundle/offline-images.sh" verify "$archive" \
    >"$state/bad-database-digest.stdout" \
    2>"$state/bad-database-digest.stderr"; then
    echo "Offline image test: changed database manifest digest passed" >&2
    exit 1
fi
grep -Fq 'database digest differs' "$state/bad-database-digest.stderr"

if PATH="$fake_bin:$PATH" \
    OFFLINE_TEST_STATE="$state" \
    OFFLINE_APP_DIGEST="sha256:$app_digest" \
    OFFLINE_MIGRATE_DIGEST="sha256:$migrate_digest" \
    OFFLINE_DATABASE_DIGEST="sha256:$database_digest" \
    OFFLINE_BAD_ARCH=1 \
    "$bundle/offline-images.sh" verify "$archive" \
    >"$state/bad-arch.stdout" 2>"$state/bad-arch.stderr"; then
    echo "Offline image test: incomplete architecture index passed" >&2
    exit 1
fi
grep -Fq 'required amd64/arm64 image index' "$state/bad-arch.stderr"

expect_bundle_failure()
{
    case_name=$1
    expected_message=$2
    tested_bundle=$3
    if PATH="$fake_bin:$PATH" \
        OFFLINE_TEST_STATE="$state" \
        OFFLINE_APP_DIGEST="sha256:$app_digest" \
        OFFLINE_MIGRATE_DIGEST="sha256:$migrate_digest" \
        OFFLINE_DATABASE_DIGEST="sha256:$database_digest" \
        "$tested_bundle/offline-images.sh" verify "$archive" \
        >"$state/$case_name.stdout" 2>"$state/$case_name.stderr"; then
        echo "Offline image test: unsafe bundle passed: $case_name" >&2
        exit 1
    fi
    grep -Fq "$expected_message" "$state/$case_name.stderr" || {
        echo "Offline image test: wrong bundle failure for $case_name" >&2
        sed -n '1,120p' "$state/$case_name.stderr" >&2
        exit 1
    }
}

duplicate_database_bundle=$temporary_root/duplicate-database-bundle
cp -R "$bundle" "$duplicate_database_bundle"
{
    printf 'services:\n'
    printf '  db:\n'
    printf '    image: mariadb:11.8.8@sha256:%s\n' "$database_digest"
    printf '    image: mariadb:11.8.8@sha256:%s\n' "$database_digest"
} >"$duplicate_database_bundle/compose.yaml"
expect_bundle_failure duplicate-database \
    'Compose must contain exactly one image for services.db' \
    "$duplicate_database_bundle"

mutable_database_bundle=$temporary_root/mutable-database-bundle
cp -R "$bundle" "$mutable_database_bundle"
{
    printf 'services:\n'
    printf '  db:\n'
    printf '    image: mariadb:11.8.8\n'
} >"$mutable_database_bundle/compose.yaml"
expect_bundle_failure mutable-database \
    'database image must contain exactly one digest separator' \
    "$mutable_database_bundle"

foreign_database_bundle=$temporary_root/foreign-database-bundle
cp -R "$bundle" "$foreign_database_bundle"
{
    printf 'services:\n'
    printf '  db:\n'
    printf '    image: registry.invalid/mariadb@sha256:%s\n' "$database_digest"
} >"$foreign_database_bundle/compose.yaml"
expect_bundle_failure foreign-database \
    'database image must use the canonical official MariaDB repository' \
    "$foreign_database_bundle"

expect_failure unsafe-prefix \
    'registry prefix is not a canonical lowercase registry path' \
    check-mirror "$archive" 'registry.example.org/Upper'
expect_failure hostless-prefix \
    'registry prefix must start with an explicit registry host' \
    check-mirror "$archive" 'team/estab-dr'
expect_failure invalid-port-prefix \
    'registry prefix contains an invalid registry port' \
    check-mirror "$archive" 'registry.example.org:70000/estab-dr'
expect_failure relative-export 'archive directory must be an absolute path' \
    export relative-output
unsafe_control_target=$(printf '%s/unsafe\ttarget' "$temporary_root")
expect_failure control-target \
    'archive directory must not contain control characters' \
    export "$unsafe_control_target"
mkdir "$temporary_root/unsafe-component"
expect_failure relative-component \
    'archive directory contains an unsafe path component' \
    export "$temporary_root/unsafe-component/../unsafe-output"
colon_parent=$temporary_root/unsafe:transport
mkdir "$colon_parent"
expect_failure colon-transport \
    "archive directory must not contain ':' because OCI transport paths end at the first colon" \
    export "$colon_parent/output"
expect_failure overwrite-export 'archive directory already exists' \
    export "$archive"
public_archive_parent=$temporary_root/public-archive-parent
mkdir "$public_archive_parent"
chmod 0755 "$public_archive_parent"
expect_failure public-export-parent \
    'archive parent must be an owner-only 0700 directory' \
    export "$public_archive_parent/archive"

acl_bin=$temporary_root/acl-bin
mkdir "$acl_bin"
cat >"$acl_bin/uname" <<'EOF'
#!/bin/sh
set -eu
[ "$#" -eq 1 ] && [ "$1" = -s ]
printf 'Linux\n'
EOF
cat >"$acl_bin/getfacl" <<'EOF'
#!/bin/sh
set -eu
printf 'user::rwx\nuser:4242:r-x\ngroup::---\nmask::r-x\nother::---\n'
EOF
chmod 0755 "$acl_bin/uname" "$acl_bin/getfacl"
acl_parent=$temporary_root/acl-parent
mkdir "$acl_parent"
chmod 0700 "$acl_parent"
if PATH="$acl_bin:$fake_bin:$PATH" \
    OFFLINE_TEST_STATE="$state" \
    OFFLINE_APP_DIGEST="sha256:$app_digest" \
    OFFLINE_MIGRATE_DIGEST="sha256:$migrate_digest" \
    OFFLINE_DATABASE_DIGEST="sha256:$database_digest" \
    "$bundle/offline-images.sh" export "$acl_parent/archive" \
    >"$state/acl-parent.stdout" 2>"$state/acl-parent.stderr"; then
    echo "Offline image test: extended parent ACL passed" >&2
    exit 1
fi
grep -Fq 'extended or access-granting POSIX ACL' \
    "$state/acl-parent.stderr"

dsm_bin=$temporary_root/dsm-bin
mkdir "$dsm_bin"
cat >"$dsm_bin/uname" <<'EOF'
#!/bin/sh
set -eu
[ "$#" -eq 1 ] && [ "$1" = -s ]
printf 'Linux\n'
EOF
cat >"$dsm_bin/getfacl" <<'EOF'
#!/bin/sh
exit 99
EOF
cat >"$dsm_bin/synoacltool" <<'EOF'
#!/bin/sh
set -eu
case "${OFFLINE_SYNO_MODE:-linux}" in
    linux)
        printf "%s\n" "It's Linux mode" >&2
        exit 1
        ;;
    extended)
        printf '%s\n' 'ACL version: 1' \
            '[0] user:fixture:allow:rwxpdDaARWc--:fd--'
        ;;
    *) exit 64 ;;
esac
EOF
chmod 0755 "$dsm_bin/uname" "$dsm_bin/getfacl" \
    "$dsm_bin/synoacltool"
dsm_parent=$temporary_root/dsm-parent
mkdir "$dsm_parent"
chmod 0700 "$dsm_parent"
PATH="$dsm_bin:$fake_bin:$PATH" \
    OFFLINE_TEST_STATE="$state" \
    OFFLINE_APP_DIGEST="sha256:$app_digest" \
    OFFLINE_MIGRATE_DIGEST="sha256:$migrate_digest" \
    OFFLINE_DATABASE_DIGEST="sha256:$database_digest" \
    OFFLINE_SYNO_MODE=linux \
    "$bundle/offline-images.sh" export "$dsm_parent/archive" >/dev/null
[ -f "$dsm_parent/archive/SHA256SUMS" ]

dsm_acl_parent=$temporary_root/dsm-acl-parent
mkdir "$dsm_acl_parent"
chmod 0700 "$dsm_acl_parent"
if PATH="$dsm_bin:$fake_bin:$PATH" \
    OFFLINE_TEST_STATE="$state" \
    OFFLINE_APP_DIGEST="sha256:$app_digest" \
    OFFLINE_MIGRATE_DIGEST="sha256:$migrate_digest" \
    OFFLINE_DATABASE_DIGEST="sha256:$database_digest" \
    OFFLINE_SYNO_MODE=extended \
    "$bundle/offline-images.sh" export "$dsm_acl_parent/archive" \
    >"$state/dsm-acl.stdout" 2>"$state/dsm-acl.stderr"; then
    echo "Offline image test: Synology DSM ACL passed" >&2
    exit 1
fi
grep -Fq 'has a Synology DSM ACL' "$state/dsm-acl.stderr"

dangling_export=$temporary_root/dangling-export
ln -s "$temporary_root/does-not-exist" "$dangling_export"
expect_failure dangling-export 'archive directory already exists' \
    export "$dangling_export"

grep -Fq 'skopeo copy --all --preserve-digests' "$offline_helper"
grep -Fq 'skopeo manifest-digest' "$offline_helper"
grep -Fq 'oci-archive:' "$offline_helper"
if grep -Fq 'docker-archive:' "$offline_helper"; then
    echo "Offline image test: helper uses lossy Docker archive transport" >&2
    exit 1
fi
if grep -Fq 'copy_tag_if_absent' "$offline_helper" ||
    grep -Eq '^[[:space:]]*mirror\)' "$offline_helper"; then
    echo "Offline image test: helper still mutates registry tags" >&2
    exit 1
fi

echo "offline image test: OK"
