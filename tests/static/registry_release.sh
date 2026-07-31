#!/bin/sh

set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd -P)
verifier=$repo_root/deploy/registry/verify-release.sh
deployer=$repo_root/deploy/registry/deploy.sh
offline_helper=$repo_root/deploy/registry/offline-images.sh

[ -x "$verifier" ] || {
    echo "Registry release test: verifier is not executable" >&2
    exit 1
}
[ -x "$deployer" ] || {
    echo "Registry release test: deployer is not executable" >&2
    exit 1
}
[ -x "$offline_helper" ] || {
    echo "Registry release test: offline helper is not executable" >&2
    exit 1
}

temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/estab-release-test.XXXXXX")
XDG_STATE_HOME=$temporary_root/xdg-state
export XDG_STATE_HOME
cleanup()
{
    rm -rf -- "$temporary_root"
}
trap cleanup EXIT HUP INT TERM

good=$temporary_root/good
mkdir -p "$good"
commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
app_digest=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
migrate_digest=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
{
    printf 'Git-Tag: release-1.2.3\n'
    printf 'Git-Commit: %s\n' "$commit"
    printf 'App-Image: ghcr.io/e-stab/estab@sha256:%s\n' "$app_digest"
    printf 'Migrator-Image: ghcr.io/e-stab/estab-migrate@sha256:%s\n' \
        "$migrate_digest"
} >"$good/RELEASE"
{
    printf 'ESTAB_APP_IMAGE=ghcr.io/e-stab/estab@sha256:%s\n' "$app_digest"
    printf 'ESTAB_MIGRATE_IMAGE=ghcr.io/e-stab/estab-migrate@sha256:%s\n' \
        "$migrate_digest"
    printf 'COMPOSE_PROJECT_NAME=estab\n'
    printf 'ESTAB_DB_DATA_SOURCE=estab_db\n'
    printf 'ESTAB_APP_DATA_SOURCE=estab_data\n'
    printf 'ESTAB_EXPORT_DATA_SOURCE=estab_export\n'
    printf 'ESTAB_DB_PASSWORD_SECRET_FILE=./secrets/db_password.txt\n'
    printf 'ESTAB_DB_ROOT_PASSWORD_SECRET_FILE=./secrets/db_root_password.txt\n'
    printf 'ESTAB_ADMIN_PASSWORD_SECRET_FILE=./secrets/admin_password.txt\n'
    printf 'ESTAB_DB_NAME=estab\n'
    printf 'ESTAB_DB_USER=estab\n'
    printf 'ESTAB_ADMIN_USER=estab-admin\n'
    printf 'ESTAB_HTTP_BIND=127.0.0.1\n'
    printf 'ESTAB_HTTP_PORT=8080\n'
    printf 'ESTAB_PUBLIC_URL=/\n'
    printf 'ESTAB_BASE_PATH=\n'
    printf 'ESTAB_AUTHORITY_CODE=EL\n'
    printf 'ESTAB_ALLOW_SELF_REGISTRATION=false\n'
    printf 'ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=false\n'
    printf 'ESTAB_TRUST_PROXY_HEADERS=false\n'
    printf 'ESTAB_TRUSTED_PROXIES=\n'
    printf 'ESTAB_UPLOAD_MAX_BYTES=20971520\n'
    printf 'ESTAB_PDF_ATTACHMENT_MAX_BYTES=52428800\n'
    printf 'TZ=Europe/Berlin\n'
} >"$good/.env"
cp "$good/.env" "$good/.env.example"
printf 'services: {}\n' >"$good/compose.yaml"
cp "$verifier" "$good/verify-release.sh"
cp "$deployer" "$good/deploy.sh"
cp "$offline_helper" "$good/offline-images.sh"
mkdir -p "$good/secrets"
chmod 0700 "$good/secrets"
for secret_name in db_password db_root_password admin_password; do
    printf 'fixture-%s\n' "$secret_name" >"$good/secrets/$secret_name.txt"
    chmod 0600 "$good/secrets/$secret_name.txt"
done
for operator in backup.sh verify-backup.sh restore.sh; do
    printf '#!/bin/sh\nexit 0\n' >"$good/$operator"
    chmod 0755 "$good/$operator"
done
for document in \
    README.md \
    RELEASE-EVIDENCE.md \
    BACKUP-UND-WIEDERHERSTELLUNG.md \
    LICENSE \
    THIRD_PARTY_NOTICES.md
do
    printf 'bound release fixture: %s\n' "$document" >"$good/$document"
done

seal_fixture()
{
    sealed_fixture=$1
    if command -v sha256sum >/dev/null 2>&1; then
        (
            cd "$sealed_fixture"
            sha256sum \
                RELEASE \
                .env.example \
                compose.yaml \
                verify-release.sh \
                deploy.sh \
                offline-images.sh \
                backup.sh \
                verify-backup.sh \
                restore.sh \
                README.md \
                RELEASE-EVIDENCE.md \
                BACKUP-UND-WIEDERHERSTELLUNG.md \
                LICENSE \
                THIRD_PARTY_NOTICES.md \
                >SHA256SUMS
        )
    else
        (
            cd "$sealed_fixture"
            shasum -a 256 \
                RELEASE \
                .env.example \
                compose.yaml \
                verify-release.sh \
                deploy.sh \
                offline-images.sh \
                backup.sh \
                verify-backup.sh \
                restore.sh \
                README.md \
                RELEASE-EVIDENCE.md \
                BACKUP-UND-WIEDERHERSTELLUNG.md \
                LICENSE \
                THIRD_PARTY_NOTICES.md \
                >SHA256SUMS
        )
    fi
}
seal_fixture "$good"

"$verifier" "$good" >/dev/null

if ESTAB_DB_DATA_SOURCE=/tmp/unverified \
    ESTAB_CONTAINER_CLI=docker \
    "$good/deploy.sh" check \
    >"$temporary_root/runtime-override.stdout" \
    2>"$temporary_root/runtime-override.stderr"; then
    echo "Registry release test: process environment overrode runtime storage" >&2
    exit 1
fi
grep -Fq 'Compose runtime overrides must not be set' \
    "$temporary_root/runtime-override.stderr"

expect_failure()
{
    fixture_name=$1
    expected_message=$2
    if "$verifier" "$temporary_root/$fixture_name" \
        >"$temporary_root/$fixture_name.stdout" \
        2>"$temporary_root/$fixture_name.stderr"; then
        echo "Registry release test: unsafe fixture passed: $fixture_name" >&2
        exit 1
    fi
    grep -Fq "$expected_message" "$temporary_root/$fixture_name.stderr" || {
        echo "Registry release test: wrong failure for $fixture_name" >&2
        cat "$temporary_root/$fixture_name.stderr" >&2
        exit 1
    }
}

cp -R "$good" "$temporary_root/mutable"
sed "s#@sha256:$app_digest#:latest#" \
    "$good/RELEASE" >"$temporary_root/mutable/RELEASE"
sed "s#@sha256:$app_digest#:latest#" \
    "$good/.env" >"$temporary_root/mutable/.env"
cp "$temporary_root/mutable/.env" "$temporary_root/mutable/.env.example"
seal_fixture "$temporary_root/mutable"
expect_failure mutable 'exact sha256 digest'

cp -R "$good" "$temporary_root/mismatch"
sed "s/$app_digest/$migrate_digest/" \
    "$good/.env" >"$temporary_root/mismatch/.env"
expect_failure mismatch 'ESTAB_APP_IMAGE differs'

cp -R "$good" "$temporary_root/duplicate"
printf 'Git-Tag: second-tag\n' >>"$temporary_root/duplicate/RELEASE"
seal_fixture "$temporary_root/duplicate"
expect_failure duplicate 'exactly the four canonical identity records'

cp -R "$good" "$temporary_root/noncanonical"
sed 's#ghcr.io/e-stab/estab@#example.invalid/estab@#' \
    "$good/RELEASE" >"$temporary_root/noncanonical/RELEASE"
sed 's#ghcr.io/e-stab/estab@#example.invalid/estab@#' \
    "$good/.env" >"$temporary_root/noncanonical/.env"
cp "$temporary_root/noncanonical/.env" \
    "$temporary_root/noncanonical/.env.example"
seal_fixture "$temporary_root/noncanonical"
expect_failure noncanonical 'canonical repository'

cp -R "$good" "$temporary_root/tampered"
printf '\n' >>"$temporary_root/tampered/RELEASE"
expect_failure tampered 'checksum differs'

cp -R "$good" "$temporary_root/symlinked"
rm -f -- "$temporary_root/symlinked/README.md"
ln -s "$good/README.md" "$temporary_root/symlinked/README.md"
expect_failure symlinked 'not a readable regular file'

cp -R "$good" "$temporary_root/nonexecutable"
chmod 0644 "$temporary_root/nonexecutable/restore.sh"
expect_failure nonexecutable 'release operator is not executable'

cp -R "$good" "$temporary_root/missing-evidence-guide"
rm -f -- "$temporary_root/missing-evidence-guide/RELEASE-EVIDENCE.md"
expect_failure missing-evidence-guide \
    'bound release entry is not a readable regular file'

cp -R "$good" "$temporary_root/nonexecutable-offline-helper"
chmod 0644 "$temporary_root/nonexecutable-offline-helper/offline-images.sh"
expect_failure nonexecutable-offline-helper \
    'release operator is not executable'

cp -R "$good" "$temporary_root/shadowed"
printf ' export ESTAB_APP_IMAGE=ghcr.io/e-stab/estab:latest\n' \
    >>"$temporary_root/shadowed/.env"
expect_failure shadowed 'protected image and Compose assignments'

fake_bin=$temporary_root/bin
mkdir "$fake_bin"
cat >"$fake_bin/getfacl" <<'EOF'
#!/bin/sh
set -eu
acl_target=
for acl_argument in "$@"; do
    acl_target=$acl_argument
done
case "${TEST_GETFACL_MODE:-plain}" in
    plain)
        if [ -d "$acl_target" ]; then
            printf 'user::rwx\ngroup::---\nother::---\n'
        else
            printf 'user::rw-\ngroup::---\nother::---\n'
        fi
        ;;
    readonly)
        printf 'user::r--\ngroup::---\nother::---\n'
        ;;
    extended)
        printf 'user::rw-\nuser:4242:r--\ngroup::---\nmask::r--\nother::---\n'
        ;;
    unavailable)
        exit 2
        ;;
    *)
        exit 64
        ;;
esac
EOF
cat >"$fake_bin/uname" <<'EOF'
#!/bin/sh
set -eu
[ "$#" -eq 1 ] && [ "$1" = -s ]
printf '%s\n' "${TEST_UNAME_SYSTEM:-Linux}"
EOF
cat >"$fake_bin/docker" <<'EOF'
#!/bin/sh
set -eu
case "$1" in
    compose)
        if [ "${2:-}" = version ]; then
            exit 0
        fi
        compose_action=
        for compose_argument in "$@"; do
            case "$compose_argument" in
                config|ps|pull|up)
                    compose_action=$compose_argument
                    break
                    ;;
            esac
        done
        case "$compose_action" in
            config) exit 0 ;;
            ps)
                [ "${TEST_COMPOSE_PS_UNSUPPORTED:-0}" -eq 0 ] || exit 2
                exit 0
                ;;
            pull|up)
                [ -z "${STORAGE_DEPLOY_EVENTS:-}" ] ||
                    printf '%s\n' "$compose_action" >>"$STORAGE_DEPLOY_EVENTS"
                exit 91
                ;;
            *) exit 1 ;;
        esac
        ;;
    image)
        [ "$2" = inspect ]
        case "$4" in
            *org.opencontainers.image.version*) printf '%s\n' release-1.2.3 ;;
            *org.opencontainers.image.revision*)
                printf '%s\n' aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
                ;;
            *) exit 1 ;;
        esac
        ;;
    ps)
        [ "${TEST_ENGINE_PS_UNSUPPORTED:-0}" -eq 0 ] || exit 2
        exit 0
        ;;
    *)
        exit 1
        ;;
esac
EOF
chmod 0755 "$fake_bin/docker" "$fake_bin/getfacl" "$fake_bin/uname"

PATH="$fake_bin:$PATH" ESTAB_CONTAINER_CLI=docker \
    "$verifier" --inspect-images "$good" >/dev/null

expect_deploy_check_failure()
{
    unsafe_deploy_fixture=$1
    unsafe_deploy_message=$2
    unsafe_deploy_events=$temporary_root/$unsafe_deploy_fixture.deploy.events
    rm -f -- "$unsafe_deploy_events"
    if PATH="$fake_bin:$PATH" ESTAB_CONTAINER_CLI=docker \
        STORAGE_DEPLOY_EVENTS="$unsafe_deploy_events" \
        "$temporary_root/$unsafe_deploy_fixture/deploy.sh" up \
        >"$temporary_root/$unsafe_deploy_fixture.deploy.stdout" \
        2>"$temporary_root/$unsafe_deploy_fixture.deploy.stderr"; then
        echo "Registry release test: unsafe deployment storage passed: $unsafe_deploy_fixture" >&2
        exit 1
    fi
    grep -Fq "$unsafe_deploy_message" \
        "$temporary_root/$unsafe_deploy_fixture.deploy.stderr" || {
        echo "Registry release test: wrong deployment storage failure: $unsafe_deploy_fixture" >&2
        cat "$temporary_root/$unsafe_deploy_fixture.deploy.stderr" >&2
        exit 1
    }
    if [ -s "$unsafe_deploy_events" ]; then
        echo "Registry release test: unsafe storage reached pull/up: $unsafe_deploy_fixture" >&2
        cat "$unsafe_deploy_events" >&2
        exit 1
    fi
}

cp -R "$good" "$temporary_root/safe-bind-storage"
mkdir -p \
    "$temporary_root/safe-bind-storage/data/db" \
    "$temporary_root/safe-bind-storage/data/4fdata" \
    "$temporary_root/safe-bind-storage/data/export"
chmod 0700 \
    "$temporary_root/safe-bind-storage/data/db" \
    "$temporary_root/safe-bind-storage/data/4fdata" \
    "$temporary_root/safe-bind-storage/data/export"
sed \
    -e 's#ESTAB_DB_DATA_SOURCE=estab_db#ESTAB_DB_DATA_SOURCE=./data/db#' \
    -e 's#ESTAB_APP_DATA_SOURCE=estab_data#ESTAB_APP_DATA_SOURCE=./data/4fdata#' \
    -e 's#ESTAB_EXPORT_DATA_SOURCE=estab_export#ESTAB_EXPORT_DATA_SOURCE=./data/export#' \
    "$good/.env" >"$temporary_root/safe-bind-storage/.env"
PATH="$fake_bin:$PATH" ESTAB_CONTAINER_CLI=docker \
    "$temporary_root/safe-bind-storage/deploy.sh" check >/dev/null

chmod 0750 "$temporary_root/safe-bind-storage/data/4fdata"
if PATH="$fake_bin:$PATH" ESTAB_CONTAINER_CLI=docker \
    "$temporary_root/safe-bind-storage/deploy.sh" check \
    >"$temporary_root/bind-mode.stdout" \
    2>"$temporary_root/bind-mode.stderr"; then
    echo "Registry release test: access-granting bind storage mode passed" >&2
    exit 1
fi
grep -Fq 'bind storage must be mode 0700' \
    "$temporary_root/bind-mode.stderr"
chmod 0700 "$temporary_root/safe-bind-storage/data/4fdata"

if PATH="$fake_bin:$PATH" ESTAB_CONTAINER_CLI=docker \
    TEST_ENGINE_PS_UNSUPPORTED=1 \
    "$good/deploy.sh" check \
    >"$temporary_root/compose-capability.stdout" \
    2>"$temporary_root/compose-capability.stderr"; then
    echo "Registry release test: missing engine inventory capability passed" >&2
    exit 1
fi
grep -Fq 'required non-mutating labelled inventory query' \
    "$temporary_root/compose-capability.stderr"

compose_override_events=$temporary_root/compose-remove-orphans.events
if PATH="$fake_bin:$PATH" ESTAB_CONTAINER_CLI=docker \
    STORAGE_DEPLOY_EVENTS="$compose_override_events" \
    COMPOSE_REMOVE_ORPHANS=1 \
    "$good/deploy.sh" up \
    >"$temporary_root/compose-remove-orphans.stdout" \
    2>"$temporary_root/compose-remove-orphans.stderr"; then
    echo "Registry release test: inherited COMPOSE_REMOVE_ORPHANS passed" >&2
    exit 1
fi
grep -Fq 'COMPOSE_* process overrides are forbidden' \
    "$temporary_root/compose-remove-orphans.stderr"
[ ! -s "$compose_override_events" ] || {
    echo "Registry release test: inherited COMPOSE_REMOVE_ORPHANS reached pull/up" >&2
    exit 1
}

cp -R "$good" "$temporary_root/permissive-secret"
chmod 0640 "$temporary_root/permissive-secret/secrets/db_password.txt"
expect_deploy_check_failure permissive-secret \
    'local secret source grants group or world permissions'

cp -R "$good" "$temporary_root/executable-secret"
chmod 0700 "$temporary_root/executable-secret/secrets/db_password.txt"
expect_deploy_check_failure executable-secret \
    'local secret source must be owner-readable and non-executable'

cp -R "$good" "$temporary_root/symlink-secret"
rm -f -- "$temporary_root/symlink-secret/secrets/admin_password.txt"
ln -s "$good/secrets/admin_password.txt" \
    "$temporary_root/symlink-secret/secrets/admin_password.txt"
expect_deploy_check_failure symlink-secret \
    'local secret source must be a readable regular, non-symlink file'

cp -R "$good" "$temporary_root/interpolated-storage"
mkdir -p "$temporary_root/interpolated-storage/data/\${STORAGE_ESCAPE}"
sed \
    's#ESTAB_APP_DATA_SOURCE=estab_data#ESTAB_APP_DATA_SOURCE=./data/${STORAGE_ESCAPE}#' \
    "$good/.env" >"$temporary_root/interpolated-storage/.env"
expect_deploy_check_failure interpolated-storage \
    '.env value contains whitespace or Compose-sensitive syntax'

canonical_interpolation_root=$temporary_root/'canonical-$STORAGE_ESCAPE'
cp -R "$good" "$canonical_interpolation_root"
mkdir -p \
    "$canonical_interpolation_root/data/db" \
    "$canonical_interpolation_root/data/4fdata" \
    "$canonical_interpolation_root/data/export"
sed \
    -e 's#ESTAB_DB_DATA_SOURCE=estab_db#ESTAB_DB_DATA_SOURCE=./data/db#' \
    -e 's#ESTAB_APP_DATA_SOURCE=estab_data#ESTAB_APP_DATA_SOURCE=./data/4fdata#' \
    -e 's#ESTAB_EXPORT_DATA_SOURCE=estab_export#ESTAB_EXPORT_DATA_SOURCE=./data/export#' \
    "$good/.env" >"$canonical_interpolation_root/.env"
if PATH="$fake_bin:$PATH" ESTAB_CONTAINER_CLI=docker \
    "$canonical_interpolation_root/deploy.sh" check \
    >"$temporary_root/canonical-interpolation.stdout" \
    2>"$temporary_root/canonical-interpolation.stderr"; then
    echo "Registry release test: Compose-sensitive canonical storage passed" >&2
    exit 1
fi
grep -Fq 'canonical database host storage path contains unsupported Compose syntax' \
    "$temporary_root/canonical-interpolation.stderr"

cp -R "$good" "$temporary_root/unknown-environment"
printf 'UNVERIFIED_COMPOSE_VALUE=unsafe\n' \
    >>"$temporary_root/unknown-environment/.env"
expect_deploy_check_failure unknown-environment \
    '.env must contain exactly the 24 supported canonical assignments'

if PATH="$fake_bin:$PATH" \
    TEST_GETFACL_MODE=extended \
    ESTAB_CONTAINER_CLI=docker \
    "$good/deploy.sh" check \
    >"$temporary_root/extended-acl.stdout" \
    2>"$temporary_root/extended-acl.stderr"; then
    echo "Registry release test: extended POSIX ACL passed" >&2
    exit 1
fi
grep -Fq 'extended or access-granting POSIX ACL' \
    "$temporary_root/extended-acl.stderr"

if PATH="$fake_bin:$PATH" \
    TEST_GETFACL_MODE=unavailable \
    ESTAB_CONTAINER_CLI=docker \
    "$good/deploy.sh" check \
    >"$temporary_root/unavailable-acl.stdout" \
    2>"$temporary_root/unavailable-acl.stderr"; then
    echo "Registry release test: unavailable ACL inspection passed" >&2
    exit 1
fi
grep -Fq 'cannot inspect POSIX ACLs' \
    "$temporary_root/unavailable-acl.stderr"

freebsd_acl_bin=$temporary_root/freebsd-acl-bin
mkdir "$freebsd_acl_bin"
cat >"$freebsd_acl_bin/uname" <<'EOF'
#!/bin/sh
set -eu
[ "$#" -eq 1 ] && [ "$1" = -s ]
printf 'FreeBSD\n'
EOF
cat >"$freebsd_acl_bin/getfacl" <<'EOF'
#!/bin/sh
set -eu
: "${TEST_BSD_GETFACL_CALLED:?}"
: >"$TEST_BSD_GETFACL_CALLED"
exit 2
EOF
cat >"$freebsd_acl_bin/ls" <<'EOF'
#!/bin/sh
set -eu
[ "$#" -eq 2 ] && [ "$1" = -ld ]
if [ -d "$2" ]; then
    printf 'drwx------\n'
else
    printf '%s\n' '-rw-------'
fi
EOF
chmod 0755 \
    "$freebsd_acl_bin/uname" \
    "$freebsd_acl_bin/getfacl" \
    "$freebsd_acl_bin/ls"
freebsd_getfacl_called=$temporary_root/freebsd-getfacl-called
PATH="$freebsd_acl_bin:$fake_bin:$PATH" \
    TEST_BSD_GETFACL_CALLED="$freebsd_getfacl_called" \
    ESTAB_CONTAINER_CLI=docker \
    "$good/deploy.sh" check >/dev/null
[ ! -e "$freebsd_getfacl_called" ] || {
    echo "Registry release test: BSD deployment used GNU-only getfacl options" >&2
    exit 1
}

synology_bin=$temporary_root/synology-bin
mkdir "$synology_bin"
cat >"$synology_bin/synoacltool" <<'EOF'
#!/bin/sh
set -eu
case "${TEST_SYNOACL_MODE:-linux}" in
    linux)
        printf "%s\n" "It's Linux mode" >&2
        exit 1
        ;;
    extended)
        printf '%s\n' 'ACL version: 1' \
            '[0] user:fixture:allow:rwxpdDaARWc--:fd--'
        ;;
    error)
        printf '%s\n' 'unexpected Synology ACL error' >&2
        exit 2
        ;;
    *)
        exit 64
        ;;
esac
EOF
chmod 0755 "$synology_bin/synoacltool"
PATH="$synology_bin:$fake_bin:$PATH" \
    TEST_SYNOACL_MODE=linux \
    TEST_GETFACL_MODE=unavailable \
    ESTAB_CONTAINER_CLI=docker \
    "$good/deploy.sh" check >/dev/null
if PATH="$synology_bin:$fake_bin:$PATH" \
    TEST_SYNOACL_MODE=extended \
    ESTAB_CONTAINER_CLI=docker \
    "$good/deploy.sh" check \
    >"$temporary_root/synology-acl.stdout" \
    2>"$temporary_root/synology-acl.stderr"; then
    echo "Registry release test: Synology DSM ACL passed" >&2
    exit 1
fi
grep -Fq 'has a Synology DSM ACL' \
    "$temporary_root/synology-acl.stderr"

if [ "$(uname -s)" = Darwin ] &&
    chmod +a 'everyone allow read' \
        "$good/secrets/db_password.txt" 2>/dev/null; then
    mac_acl_bin=$temporary_root/mac-acl-bin
    mkdir "$mac_acl_bin"
    cp "$fake_bin/docker" "$mac_acl_bin/docker"
    chmod 0755 "$mac_acl_bin/docker"
    if PATH="$mac_acl_bin:/usr/bin:/bin:/usr/sbin:/sbin" \
        ESTAB_CONTAINER_CLI=docker \
        "$good/deploy.sh" check \
        >"$temporary_root/mac-acl.stdout" \
        2>"$temporary_root/mac-acl.stderr"; then
        echo "Registry release test: real macOS ACL passed" >&2
        exit 1
    fi
    grep -Fq 'extended macOS ACL' "$temporary_root/mac-acl.stderr"
    chmod -N "$good/secrets/db_password.txt"
fi

gnu_stat_bin=$temporary_root/stat-gnu-bin
bsd_stat_bin=$temporary_root/stat-bsd-bin
mkdir "$gnu_stat_bin" "$bsd_stat_bin"
cat >"$gnu_stat_bin/stat" <<'EOF'
#!/bin/sh
set -eu
[ "$1" = -c ] || exit 2
stat_target=$3
case "$2" in
    %u) printf '%s\n' "$TEST_STAT_OWNER" ;;
    %a)
        if [ -d "$stat_target" ]; then
            printf '700\n'
        elif case "$stat_target" in
            */snapshots/snapshot-*/*) true ;;
            *) false ;;
        esac; then
            printf '400\n'
        else
            printf '%s\n' "${TEST_STAT_MODE:-600}"
        fi
        ;;
    *) exit 2 ;;
esac
EOF
cat >"$bsd_stat_bin/stat" <<'EOF'
#!/bin/sh
set -eu
case "$1" in
    -c) exit 2 ;;
    -f)
        stat_target=$3
        case "$2" in
            %u) printf '%s\n' "$TEST_STAT_OWNER" ;;
            %Lp)
                if [ -d "$stat_target" ]; then
                    printf '700\n'
                elif case "$stat_target" in
                    */snapshots/snapshot-*/*) true ;;
                    *) false ;;
                esac; then
                    printf '400\n'
                else
                    printf '%s\n' "${TEST_STAT_MODE:-600}"
                fi
                ;;
            *) exit 2 ;;
        esac
        ;;
    *) exit 2 ;;
esac
EOF
chmod 0755 "$gnu_stat_bin/stat" "$bsd_stat_bin/stat"
test_operator_uid=$(id -u)
PATH="$gnu_stat_bin:$fake_bin:$PATH" \
    TEST_STAT_OWNER="$test_operator_uid" \
    ESTAB_CONTAINER_CLI=docker \
    "$good/deploy.sh" check >/dev/null
PATH="$bsd_stat_bin:$fake_bin:$PATH" \
    TEST_STAT_OWNER="$test_operator_uid" \
    ESTAB_CONTAINER_CLI=docker \
    "$good/deploy.sh" check >/dev/null
if PATH="$gnu_stat_bin:$fake_bin:$PATH" \
    TEST_STAT_OWNER=424242 \
    ESTAB_CONTAINER_CLI=docker \
    "$good/deploy.sh" check \
    >"$temporary_root/secret-owner.stdout" \
    2>"$temporary_root/secret-owner.stderr"; then
    echo "Registry release test: unsafe secret owner passed" >&2
    exit 1
fi
grep -Fq 'owner is neither root nor the deployment operator' \
    "$temporary_root/secret-owner.stderr"

cp -R "$good" "$temporary_root/root-storage"
sed 's#ESTAB_DB_DATA_SOURCE=estab_db#ESTAB_DB_DATA_SOURCE=/#' \
    "$good/.env" >"$temporary_root/root-storage/.env"
expect_deploy_check_failure root-storage \
    'filesystem root is forbidden as productive database storage'

cp -R "$good" "$temporary_root/broad-storage"
sed 's#ESTAB_DB_DATA_SOURCE=estab_db#ESTAB_DB_DATA_SOURCE=/usr#' \
    "$good/.env" >"$temporary_root/broad-storage/.env"
expect_deploy_check_failure broad-storage \
    'broad top-level host directory is forbidden as productive database storage'

cp -R "$good" "$temporary_root/equal-storage"
mkdir -p "$temporary_root/equal-storage/data/shared"
chmod 0700 "$temporary_root/equal-storage/data/shared"
sed \
    -e 's#ESTAB_APP_DATA_SOURCE=estab_data#ESTAB_APP_DATA_SOURCE=./data/shared#' \
    -e 's#ESTAB_EXPORT_DATA_SOURCE=estab_export#ESTAB_EXPORT_DATA_SOURCE=./data/shared#' \
    "$good/.env" >"$temporary_root/equal-storage/.env"
expect_deploy_check_failure equal-storage \
    'productive storage sources are equal, nested, overlapping, or unsafe'

cp -R "$good" "$temporary_root/nested-storage"
mkdir -p \
    "$temporary_root/nested-storage/data/db" \
    "$temporary_root/nested-storage/data/db/4fdata"
chmod 0700 \
    "$temporary_root/nested-storage/data/db" \
    "$temporary_root/nested-storage/data/db/4fdata"
sed \
    -e 's#ESTAB_DB_DATA_SOURCE=estab_db#ESTAB_DB_DATA_SOURCE=./data/db#' \
    -e 's#ESTAB_APP_DATA_SOURCE=estab_data#ESTAB_APP_DATA_SOURCE=./data/db/4fdata#' \
    "$good/.env" >"$temporary_root/nested-storage/.env"
expect_deploy_check_failure nested-storage \
    'productive storage sources are equal, nested, overlapping, or unsafe'

cp -R "$good" "$temporary_root/auth-alias-storage"
sed 's#ESTAB_APP_DATA_SOURCE=estab_data#ESTAB_APP_DATA_SOURCE=estab_auth#' \
    "$good/.env" >"$temporary_root/auth-alias-storage/.env"
expect_deploy_check_failure auth-alias-storage \
    'application storage must use its dedicated named volume estab_data'

sed 's/printf.*release-1.2.3/printf '"'"'%s\\n'"'"' wrong-release/' \
    "$fake_bin/docker" >"$fake_bin/docker.bad"
mv "$fake_bin/docker.bad" "$fake_bin/docker"
chmod 0755 "$fake_bin/docker"
if PATH="$fake_bin:$PATH" ESTAB_CONTAINER_CLI=docker \
    "$verifier" --inspect-images "$good" \
    >"$temporary_root/labels.stdout" 2>"$temporary_root/labels.stderr"; then
    echo "Registry release test: mismatched image label passed" >&2
    exit 1
fi
grep -Fq 'image version label does not match Git-Tag' \
    "$temporary_root/labels.stderr"

deploy_fake_dir=$temporary_root/deploy-bin
mkdir "$deploy_fake_dir"
deploy_fake=$deploy_fake_dir/docker
deploy_state=$temporary_root/deploy-state
mkdir "$deploy_state"
cat >"$deploy_fake" <<'EOF'
#!/bin/sh
set -eu
printf '%s\n' "$*" >>"$DEPLOY_STATE/events"
case "$1" in
  compose)
    if [ "${2:-}" = version ]; then
      exit 0
    fi
    compose_action=
    for compose_argument in "$@"; do
      case "$compose_argument" in
        config|ps|pull|up)
          compose_action=$compose_argument
          break
          ;;
      esac
    done
    case "$compose_action" in
      config|ps|pull) exit 0 ;;
      up)
        : >"$DEPLOY_STATE/unsafe-up"
        exit 0
        ;;
      *) exit 1 ;;
    esac
    ;;
  image)
    [ "$2" = inspect ]
    case "$4" in
      *org.opencontainers.image.version*) printf '%s\n' release-1.2.3 ;;
      *org.opencontainers.image.revision*)
        printf '%s\n' aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
        ;;
      *'{{.Id}}'*)
        printf 'sha256:1111111111111111111111111111111111111111111111111111111111111111\n'
        ;;
      *) exit 1 ;;
    esac
    ;;
  run)
    exit 125
    ;;
  ps)
    exit 0
    ;;
  inspect)
    [ "$2" = --format ]
    case "$3" in
      '{{.Id}}')
        printf '5555555555555555555555555555555555555555555555555555555555555555\n'
        ;;
      '{{ index .Config.Labels "org.e-stab.compose-project" }}')
        printf 'estab\n'
        ;;
      '{{ index .Config.Labels "org.e-stab.maintenance-operation" }}')
        printf 'backup\n'
        ;;
      '{{ index .Config.Labels "org.e-stab.maintenance-owner" }}')
        printf 'backup-999999-stale\n'
        ;;
      '{{ index .Config.Labels "org.e-stab.maintenance-started-utc" }}')
        printf '2026-07-31T08:00:00Z\n'
        ;;
      '{{.State.Status}}')
        printf 'exited\n'
        ;;
      *) exit 1 ;;
    esac
    ;;
  *) exit 1 ;;
esac
EOF
chmod 0755 "$deploy_fake"
release_two=$temporary_root/release-two
cp -R "$good" "$release_two"
if PATH="$deploy_fake_dir:$fake_bin:$PATH" \
    ESTAB_CONTAINER_CLI=docker \
    DEPLOY_STATE="$deploy_state" \
    "$release_two/deploy.sh" up \
    >"$temporary_root/deploy-lock.stdout" \
    2>"$temporary_root/deploy-lock.stderr"; then
    echo "Registry release test: deploy ignored another directory's project lock" >&2
    exit 1
fi
grep -Fq 'operation=backup' "$temporary_root/deploy-lock.stderr" || {
    echo "Registry release test: wrong competing-lock diagnostic" >&2
    cat "$temporary_root/deploy-lock.stderr" >&2
    exit 1
}
[ ! -e "$deploy_state/unsafe-up" ]

upgrade_fake_dir=$temporary_root/upgrade-bin
mkdir "$upgrade_fake_dir"
upgrade_fake=$upgrade_fake_dir/docker
cat >"$upgrade_fake" <<'EOF'
#!/bin/sh
set -eu

lock_id=9999999999999999999999999999999999999999999999999999999999999999
image_id=1111111111111111111111111111111111111111111111111111111111111111
db_id=2222222222222222222222222222222222222222222222222222222222222222
app_id=3333333333333333333333333333333333333333333333333333333333333333
migrate_id=4444444444444444444444444444444444444444444444444444444444444444
auth_id=5555555555555555555555555555555555555555555555555555555555555555

last_argument=
for current_argument in "$@"; do
    last_argument=$current_argument
done
printf '%s\n' "$*" >>"$UPGRADE_STATE/events"

case "$1" in
    compose)
        if [ "${2:-}" = version ]; then
            exit 0
        fi
        compose_action=
        compose_service=
        compose_environment=
        force_recreate=false
        previous_compose_argument=
        for compose_argument in "$@"; do
            if [ "$previous_compose_argument" = --env-file ]; then
                case "$compose_argument" in
                    */snapshots/snapshot-*/.env)
                        compose_environment=$compose_argument
                        ;;
                esac
            fi
            case "$compose_argument" in
                config|ps|pull|up|logs)
                    [ -n "$compose_action" ] ||
                        compose_action=$compose_argument
                    ;;
                    db|app|migrate|admin-auth-init)
                        compose_service=$compose_argument
                        ;;
                    --force-recreate)
                        force_recreate=true
                        ;;
            esac
            previous_compose_argument=$compose_argument
        done
        case "$compose_action" in
            config|pull|logs)
                exit 0
                ;;
            up)
                [ "$force_recreate" = true ] || exit 93
                [ -n "$compose_environment" ] || exit 94
                dirname -- "$compose_environment" \
                    >"$UPGRADE_STATE/snapshot-directory"
                : >"$UPGRADE_STATE/up"
                exit 0
                ;;
            ps)
                [ -n "$compose_service" ] || exit 0
                if [ -e "$UPGRADE_STATE/up" ]; then
                    case "$compose_service" in
                        db) printf '%s\n' "$db_id" ;;
                        app) printf '%s\n' "$app_id" ;;
                        migrate) printf '%s\n' "$migrate_id" ;;
                        admin-auth-init) printf '%s\n' "$auth_id" ;;
                    esac
                    exit 0
                fi
                case "${UPGRADE_SCENARIO:-fresh}" in
                    fresh)
                        exit 0
                        ;;
                    partial)
                        [ "$compose_service" != db ] ||
                            printf '%s\n' "$db_id"
                        ;;
                    multiple-db)
                        case "$compose_service" in
                            db) printf '%s\n%s\n' "$db_id" "$migrate_id" ;;
                            app) printf '%s\n' "$app_id" ;;
                        esac
                        ;;
                    *)
                        case "$compose_service" in
                            db) printf '%s\n' "$db_id" ;;
                            app) printf '%s\n' "$app_id" ;;
                        esac
                        ;;
                esac
                exit 0
                ;;
            *)
                exit 2
                ;;
        esac
        ;;
    image)
        [ "$2" = inspect ]
        case "$4" in
            *org.opencontainers.image.version*)
                printf '%s\n' release-1.2.3
                ;;
            *org.opencontainers.image.revision*)
                printf '%s\n' aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
                ;;
            *'{{.Id}}'*)
                printf 'sha256:%s\n' "$image_id"
                ;;
            *)
                exit 2
                ;;
        esac
        ;;
    run)
        for run_argument in "$@"; do
            case "$run_argument" in
                org.e-stab.maintenance-owner=*)
                    printf '%s\n' \
                        "${run_argument#org.e-stab.maintenance-owner=}" \
                        >"$UPGRADE_STATE/owner"
                    ;;
            esac
        done
        : >"$UPGRADE_STATE/lock"
        printf '%s\n' "$lock_id"
        ;;
    inspect)
        [ "$2" = --format ]
        inspect_template=$3
        inspect_target=$last_argument
        case "$inspect_template" in
            *'.Mounts'*)
                snapshot_directory=$(cat \
                    "$UPGRADE_STATE/snapshot-directory" 2>/dev/null || :)
                snapshot_mount_directory=$snapshot_directory
                if [ "${UPGRADE_ALIAS_SECRETS:-0}" -eq 1 ] &&
                    [ -n "$snapshot_mount_directory" ]; then
                    snapshot_mount_directory=${UPGRADE_MOUNT_ALIAS_PREFIX:?}$snapshot_mount_directory
                fi
                productive_db_source=${UPGRADE_BIND_ROOT:-}
                productive_app_source=${UPGRADE_BIND_ROOT:-}
                productive_export_source=${UPGRADE_BIND_ROOT:-}
                if [ -n "$productive_db_source" ]; then
                    productive_db_source=$productive_db_source/db
                    productive_app_source=$productive_app_source/4fdata
                    productive_export_source=$productive_export_source/export
                    if [ "${UPGRADE_ALIAS_PRODUCTIVE:-0}" -eq 1 ]; then
                        productive_db_source=${UPGRADE_MOUNT_ALIAS_PREFIX:?}$productive_db_source
                        productive_app_source=${UPGRADE_MOUNT_ALIAS_PREFIX:?}$productive_app_source
                        productive_export_source=${UPGRADE_MOUNT_ALIAS_PREFIX:?}$productive_export_source
                    fi
                fi
                case "$inspect_target" in
                    "$db_id")
                        if [ -n "$productive_db_source" ]; then
                            printf 'bind\t%s\t\t/var/lib/mysql\ttrue\n' \
                                "$productive_db_source"
                        else
                            case "${UPGRADE_SCENARIO:-fresh}" in
                                type-mismatch)
                                    printf 'bind\t/host/db\t\t/var/lib/mysql\ttrue\n'
                                    ;;
                                unsafe-volume-source)
                                    printf 'volume\t/\testab_estab_db\t/var/lib/mysql\ttrue\n'
                                    ;;
                                engine-source-change)
                                    printf 'volume\t/opaque/provider/db\testab_estab_db\t/var/lib/mysql\ttrue\n'
                                    ;;
                                *)
                                    printf 'volume\t/var/lib/docker/volumes/estab_estab_db/_data\testab_estab_db\t/var/lib/mysql\ttrue\n'
                                    ;;
                            esac
                        fi
                        [ -n "$snapshot_mount_directory" ] &&
                            printf 'bind\t%s/secrets/db_password.txt\t\t/run/secrets/estab_db_password\tfalse\n' \
                                "$snapshot_mount_directory"
                        [ -n "$snapshot_mount_directory" ] &&
                            printf 'bind\t%s/secrets/db_root_password.txt\t\t/run/secrets/estab_db_root_password\tfalse\n' \
                                "$snapshot_mount_directory"
                        ;;
                    "$app_id")
                        if [ -n "$productive_app_source" ]; then
                            printf 'bind\t%s\t\t/var/www/html/4fdata\ttrue\n' \
                                "$productive_app_source"
                            printf 'bind\t%s\t\t/var/lib/estab/export\ttrue\n' \
                                "$productive_export_source"
                        else
                            case "${UPGRADE_SCENARIO:-fresh}" in
                                name-mismatch)
                                    printf 'volume\t/var/lib/docker/volumes/other_estab_data/_data\tother_estab_data\t/var/www/html/4fdata\ttrue\n'
                                    ;;
                                engine-source-change)
                                    printf 'volume\t/opaque/provider/data\testab_estab_data\t/var/www/html/4fdata\ttrue\n'
                                    ;;
                                target-mismatch)
                                    printf 'volume\t/var/lib/docker/volumes/estab_estab_data/_data\testab_estab_data\t/var/www/html/wrong\ttrue\n'
                                    ;;
                                duplicate-mount)
                                    printf 'volume\t/var/lib/docker/volumes/estab_estab_data/_data\testab_estab_data\t/var/www/html/4fdata\ttrue\n'
                                    printf 'volume\t/var/lib/docker/volumes/estab_estab_data/_data\testab_estab_data\t/var/www/html/4fdata\ttrue\n'
                                    ;;
                                nested-mount)
                                    printf 'volume\t/var/lib/docker/volumes/estab_estab_data/_data\testab_estab_data\t/var/www/html/4fdata\ttrue\n'
                                    printf 'bind\t/host/shadow\t\t/var/www/html/4fdata/shadow\ttrue\n'
                                    ;;
                                *)
                                    printf 'volume\t/var/lib/docker/volumes/estab_estab_data/_data\testab_estab_data\t/var/www/html/4fdata\ttrue\n'
                                    ;;
                            esac
                            case "${UPGRADE_SCENARIO:-fresh}" in
                                readonly-export)
                                    printf 'volume\t/var/lib/docker/volumes/estab_estab_export/_data\testab_estab_export\t/var/lib/estab/export\tfalse\n'
                                    ;;
                                engine-source-change)
                                    printf 'volume\t/opaque/provider/export\testab_estab_export\t/var/lib/estab/export\ttrue\n'
                                    ;;
                                *)
                                    printf 'volume\t/var/lib/docker/volumes/estab_estab_export/_data\testab_estab_export\t/var/lib/estab/export\ttrue\n'
                                    ;;
                            esac
                        fi
                        printf 'volume\t/var/lib/docker/volumes/estab_estab_auth/_data\testab_estab_auth\t/run/estab-auth\tfalse\n'
                        [ -n "$snapshot_mount_directory" ] &&
                            printf 'bind\t%s/secrets/db_password.txt\t\t/run/secrets/estab_db_password\tfalse\n' \
                                "$snapshot_mount_directory"
                        ;;
                    "$migrate_id")
                        printf 'volume\t/var/lib/docker/volumes/migrate/_data\tmigrate\t/var/lib/mysql\ttrue\n'
                        [ -n "$snapshot_mount_directory" ] &&
                            printf 'bind\t%s/secrets/db_root_password.txt\t\t/run/secrets/estab_db_root_password\tfalse\n' \
                                "$snapshot_mount_directory"
                        ;;
                    "$auth_id")
                        printf 'volume\t/var/lib/docker/volumes/estab_estab_auth/_data\testab_estab_auth\t/var/lib/estab/auth\ttrue\n'
                        [ -n "$snapshot_mount_directory" ] &&
                            printf 'bind\t%s/secrets/admin_password.txt\t\t/run/secrets/estab_admin_password\tfalse\n' \
                                "$snapshot_mount_directory"
                        ;;
                    *)
                        exit 2
                        ;;
                esac
                :
                ;;
            '{{.Id}}')
                case "$inspect_target" in
                    "$db_id"|"$app_id"|"$migrate_id"|"$auth_id")
                        printf '%s\n' "$inspect_target"
                        ;;
                    *) printf '%s\n' "$lock_id" ;;
                esac
                ;;
            '{{.Name}}')
                printf '/estab-maintenance-lock-estab\n'
                ;;
            '{{ index .Config.Labels "org.e-stab.maintenance-lock" }}')
                printf 'true\n'
                ;;
            '{{ index .Config.Labels "org.e-stab.compose-project" }}')
                printf 'estab\n'
                ;;
            '{{ index .Config.Labels "com.docker.compose.project" }}')
                printf 'estab\n'
                ;;
            '{{ index .Config.Labels "com.docker.compose.service" }}')
                case "$inspect_target" in
                    "$db_id") printf 'db\n' ;;
                    "$app_id") printf 'app\n' ;;
                    "$migrate_id")
                        if [ "${UPGRADE_SCENARIO:-fresh}" = multiple-db ]; then
                            printf 'db\n'
                        else
                            printf 'migrate\n'
                        fi
                        ;;
                    "$auth_id") printf 'admin-auth-init\n' ;;
                    *) exit 2 ;;
                esac
                ;;
            '{{ index .Config.Labels "org.e-stab.maintenance-operation" }}')
                printf 'deploy\n'
                ;;
            '{{ index .Config.Labels "org.e-stab.maintenance-owner" }}')
                cat "$UPGRADE_STATE/owner"
                ;;
            '{{.State.Status}}')
                printf 'running\n'
                ;;
            '{{.State.Running}}')
                printf 'true\n'
                ;;
            '{{.Image}}')
                printf 'sha256:%s\n' "$image_id"
                ;;
            *'.State.Health'*)
                printf 'running healthy\n'
                ;;
            *'.State.ExitCode'*)
                printf 'exited 0\n'
                ;;
            *)
                exit 2
                ;;
        esac
        ;;
    ps)
        ps_service=
        ps_project=
        for ps_argument in "$@"; do
            case "$ps_argument" in
                label=com.docker.compose.project=*)
                    ps_project=${ps_argument##*=}
                    ;;
                label=com.docker.compose.service=*)
                    ps_service=${ps_argument##*=}
                    ;;
            esac
        done
        [ -n "$ps_project" ] || exit 2
        [ "$ps_project" = estab ] || exit 0
        if [ -e "$UPGRADE_STATE/up" ]; then
            case "$ps_service" in
                db) printf '%s\n' "$db_id" ;;
                app) printf '%s\n' "$app_id" ;;
                migrate) printf '%s\n' "$migrate_id" ;;
                admin-auth-init) printf '%s\n' "$auth_id" ;;
                '')
                    printf '%s\n%s\n%s\n%s\n' \
                        "$db_id" "$app_id" "$migrate_id" "$auth_id"
                    ;;
                *) exit 2 ;;
            esac
            exit 0
        fi
        case "${UPGRADE_SCENARIO:-fresh}:$ps_service" in
            fresh:*) ;;
            partial:db) printf '%s\n' "$db_id" ;;
            partial:app) ;;
            multiple-db:db)
                printf '%s\n%s\n' "$db_id" "$migrate_id"
                ;;
            multiple-db:app) printf '%s\n' "$app_id" ;;
            *:db) printf '%s\n' "$db_id" ;;
            *:app) printf '%s\n' "$app_id" ;;
        esac
        ;;
    container)
        [ "$2" = rm ]
        [ "$3" = --force ]
        [ "$4" = "$lock_id" ]
        rm -f -- "$UPGRADE_STATE/lock"
        ;;
    *)
        exit 2
        ;;
esac
EOF
chmod 0755 "$upgrade_fake"
cp "$upgrade_fake" "$upgrade_fake_dir/podman"
chmod 0755 "$upgrade_fake_dir/podman"

upgrade_macos_acl_bin=$temporary_root/upgrade-macos-acl-bin
mkdir "$upgrade_macos_acl_bin"
cat >"$upgrade_macos_acl_bin/ls" <<'EOF'
#!/bin/sh
set -eu

[ "${TEST_UNAME_SYSTEM:-}" = Darwin ]
[ "$#" -eq 2 ] && [ "$1" = -lde ]
acl_target=$2
if [ -d "$acl_target" ]; then
    printf '%s\n' 'drwx------ fixture'
elif [ -f "$acl_target" ]; then
    printf '%s\n' '-rw------- fixture'
else
    exit 1
fi
EOF
chmod 0755 "$upgrade_macos_acl_bin/ls"

run_upgrade_success()
{
    upgrade_scenario=$1
    snapshot_expectation=$2
    upgrade_fixture=${3:-$good}
    upgrade_test_cli=${4:-docker}
    upgrade_test_system=${5:-Linux}
    upgrade_alias_prefix=${6:-}
    upgrade_alias_productive=${7:-0}
    upgrade_alias_secrets=${8:-0}
    upgrade_bind_root=${9:-}
    upgrade_state=$temporary_root/upgrade-$upgrade_scenario
    upgrade_test_path=$upgrade_fake_dir:$fake_bin:$PATH
    [ "$upgrade_test_system" != Darwin ] ||
        upgrade_test_path=$upgrade_macos_acl_bin:$upgrade_test_path
    mkdir "$upgrade_state"
    snapshots_before=$(find "$XDG_STATE_HOME/estab-deploy/estab/snapshots" \
        -mindepth 1 -maxdepth 1 -type d -name 'snapshot-*' \
        2>/dev/null | LC_ALL=C awk 'END { print NR + 0 }')
    if ! PATH="$upgrade_test_path" \
        ESTAB_CONTAINER_CLI="$upgrade_test_cli" \
        TEST_UNAME_SYSTEM="$upgrade_test_system" \
        UPGRADE_MOUNT_ALIAS_PREFIX="$upgrade_alias_prefix" \
        UPGRADE_ALIAS_PRODUCTIVE="$upgrade_alias_productive" \
        UPGRADE_ALIAS_SECRETS="$upgrade_alias_secrets" \
        UPGRADE_BIND_ROOT="$upgrade_bind_root" \
        UPGRADE_SCENARIO="$upgrade_scenario" \
        UPGRADE_STATE="$upgrade_state" \
        "$upgrade_fixture/deploy.sh" up \
        >"$upgrade_state/stdout" 2>"$upgrade_state/stderr"; then
        echo "Registry release test: safe upgrade failed: $upgrade_scenario" >&2
        cat "$upgrade_state/stderr" >&2
        [ ! -f "$upgrade_state/events" ] ||
            cat "$upgrade_state/events" >&2
        exit 1
    fi
    grep -Fq 'eStab deployment: ready' "$upgrade_state/stdout"
    [ -e "$upgrade_state/up" ]
    [ ! -e "$upgrade_state/lock" ]
    snapshot_environment=$(LC_ALL=C awk '
      {
        for (position = 1; position <= NF; position++) {
          if ($position == "--env-file") {
            paths[$(position + 1)] = 1
          }
        }
      }
      END {
        for (path in paths) {
          print path
        }
      }
    ' "$upgrade_state/events")
    [ "$(printf '%s\n' "$snapshot_environment" |
        LC_ALL=C awk 'NF { count++ } END { print count + 0 }')" -eq 1 ]
    xdg_state_canonical=$(CDPATH= cd -- "$XDG_STATE_HOME" && pwd -P)
    case "$snapshot_environment" in
        "$xdg_state_canonical"/estab-deploy/estab/snapshots/snapshot-*/.env) ;;
        *)
            echo "Registry release test: Compose did not use a private environment snapshot" >&2
            printf '%s\n' "$snapshot_environment" >&2
            exit 1
            ;;
    esac
    snapshot_directory=${snapshot_environment%/.env}
    [ -f "$snapshot_directory/compose.yaml" ]
    [ -f "$snapshot_directory/SNAPSHOT" ]
    [ -f "$snapshot_directory/secrets/db_password.txt" ]
    [ -f "$snapshot_directory/secrets/db_root_password.txt" ]
    [ -f "$snapshot_directory/secrets/admin_password.txt" ]
    cmp "$upgrade_fixture/secrets/db_password.txt" \
        "$snapshot_directory/secrets/db_password.txt"
    cmp "$upgrade_fixture/secrets/db_root_password.txt" \
        "$snapshot_directory/secrets/db_root_password.txt"
    cmp "$upgrade_fixture/secrets/admin_password.txt" \
        "$snapshot_directory/secrets/admin_password.txt"
    grep -Fq \
        "ESTAB_ADMIN_PASSWORD_SECRET_FILE=$snapshot_directory/secrets/admin_password.txt" \
        "$snapshot_environment"
    if grep -Fq -- "--env-file $upgrade_fixture/.env" \
        "$upgrade_state/events"; then
        echo "Registry release test: Compose consumed the mutable release .env" >&2
        exit 1
    fi
    snapshots_after=$(find "$XDG_STATE_HOME/estab-deploy/estab/snapshots" \
        -mindepth 1 -maxdepth 1 -type d -name 'snapshot-*' |
        LC_ALL=C awk 'END { print NR + 0 }')
    case "$snapshot_expectation" in
        new)
            [ "$snapshots_after" -eq $((snapshots_before + 1)) ]
            ;;
        reused)
            [ "$snapshots_after" -eq "$snapshots_before" ]
            ;;
        replaced)
            [ "$snapshots_after" -eq "$snapshots_before" ]
            [ "$snapshot_directory" != "$expected_previous_snapshot" ]
            [ ! -e "$expected_previous_snapshot" ]
            ;;
        *)
            echo "Registry release test: invalid snapshot expectation" >&2
            exit 1
            ;;
    esac
}

run_upgrade_failure()
{
    upgrade_scenario=$1
    upgrade_message=$2
    upgrade_fixture=${3:-$good}
    upgrade_test_cli=${4:-docker}
    upgrade_test_system=${5:-Linux}
    upgrade_alias_prefix=${6:-}
    upgrade_alias_productive=${7:-0}
    upgrade_alias_secrets=${8:-0}
    upgrade_bind_root=${9:-}
    upgrade_state=$temporary_root/upgrade-$upgrade_scenario
    upgrade_test_path=$upgrade_fake_dir:$fake_bin:$PATH
    [ "$upgrade_test_system" != Darwin ] ||
        upgrade_test_path=$upgrade_macos_acl_bin:$upgrade_test_path
    mkdir "$upgrade_state"
    snapshots_before=$(find "$XDG_STATE_HOME/estab-deploy/estab/snapshots" \
        -mindepth 1 -maxdepth 1 -type d -name 'snapshot-*' \
        2>/dev/null | LC_ALL=C awk 'END { print NR + 0 }')
    if PATH="$upgrade_test_path" \
        ESTAB_CONTAINER_CLI="$upgrade_test_cli" \
        TEST_UNAME_SYSTEM="$upgrade_test_system" \
        UPGRADE_MOUNT_ALIAS_PREFIX="$upgrade_alias_prefix" \
        UPGRADE_ALIAS_PRODUCTIVE="$upgrade_alias_productive" \
        UPGRADE_ALIAS_SECRETS="$upgrade_alias_secrets" \
        UPGRADE_BIND_ROOT="$upgrade_bind_root" \
        UPGRADE_SCENARIO="$upgrade_scenario" \
        UPGRADE_STATE="$upgrade_state" \
        "$upgrade_fixture/deploy.sh" up \
        >"$upgrade_state/stdout" 2>"$upgrade_state/stderr"; then
        echo "Registry release test: unsafe upgrade passed: $upgrade_scenario" >&2
        exit 1
    fi
    grep -Fq "$upgrade_message" "$upgrade_state/stderr" || {
        echo "Registry release test: wrong upgrade failure: $upgrade_scenario" >&2
        cat "$upgrade_state/stderr" >&2
        exit 1
    }
    if [ -e "$upgrade_state/up" ]; then
        echo "Registry release test: unsafe upgrade reached Compose up: $upgrade_scenario" >&2
        exit 1
    fi
    [ ! -e "$upgrade_state/lock" ]
    snapshots_after=$(find "$XDG_STATE_HOME/estab-deploy/estab/snapshots" \
        -mindepth 1 -maxdepth 1 -type d -name 'snapshot-*' \
        2>/dev/null | LC_ALL=C awk 'END { print NR + 0 }')
    [ "$snapshots_after" -eq "$snapshots_before" ]
}

run_upgrade_runtime_failure()
{
    upgrade_scenario=$1
    upgrade_message=$2
    upgrade_fixture=$3
    upgrade_test_cli=$4
    upgrade_test_system=$5
    upgrade_alias_prefix=$6
    upgrade_alias_productive=$7
    upgrade_alias_secrets=$8
    upgrade_bind_root=$9
    upgrade_state=$temporary_root/upgrade-$upgrade_scenario
    upgrade_test_path=$upgrade_fake_dir:$fake_bin:$PATH
    [ "$upgrade_test_system" != Darwin ] ||
        upgrade_test_path=$upgrade_macos_acl_bin:$upgrade_test_path
    mkdir "$upgrade_state"
    if PATH="$upgrade_test_path" \
        ESTAB_CONTAINER_CLI="$upgrade_test_cli" \
        TEST_UNAME_SYSTEM="$upgrade_test_system" \
        UPGRADE_MOUNT_ALIAS_PREFIX="$upgrade_alias_prefix" \
        UPGRADE_ALIAS_PRODUCTIVE="$upgrade_alias_productive" \
        UPGRADE_ALIAS_SECRETS="$upgrade_alias_secrets" \
        UPGRADE_BIND_ROOT="$upgrade_bind_root" \
        UPGRADE_SCENARIO="$upgrade_scenario" \
        UPGRADE_STATE="$upgrade_state" \
        "$upgrade_fixture/deploy.sh" up \
        >"$upgrade_state/stdout" 2>"$upgrade_state/stderr"; then
        echo "Registry release test: unsafe runtime mount passed: $upgrade_scenario" >&2
        exit 1
    fi
    grep -Fq "$upgrade_message" "$upgrade_state/stderr" || {
        echo "Registry release test: wrong runtime mount failure: $upgrade_scenario" >&2
        cat "$upgrade_state/stderr" >&2
        exit 1
    }
    [ -e "$upgrade_state/up" ]
    [ ! -e "$upgrade_state/lock" ]
    if grep -Fq 'eStab deployment: ready' "$upgrade_state/stdout"; then
        echo "Registry release test: unsafe runtime mount became ready: $upgrade_scenario" >&2
        exit 1
    fi
}

run_upgrade_success fresh new
run_upgrade_success matching reused
run_upgrade_success engine-source-change reused
run_upgrade_failure partial \
    'must contain exactly one database and one application container'
run_upgrade_failure multiple-db \
    'must contain exactly one database and one application container'
run_upgrade_failure type-mismatch \
    'existing database productive mount does not match'
run_upgrade_failure unsafe-volume-source \
    'existing database productive mount does not match'
run_upgrade_failure name-mismatch \
    'existing application productive mount does not match'
run_upgrade_failure target-mismatch \
    'existing application productive mount does not match'
run_upgrade_failure readonly-export \
    'existing export productive mount does not match'
run_upgrade_failure duplicate-mount \
    'existing application productive mount does not match'
run_upgrade_failure nested-mount \
    'existing application productive mount does not match'

expected_previous_snapshot=$snapshot_directory
printf 'rotated-test-database-password\n' \
    >"$good/secrets/db_password.txt"
run_upgrade_success secret-rotation replaced
private_secret_file_count=$(find \
    "$XDG_STATE_HOME/estab-deploy/estab/snapshots" \
    -path '*/secrets/*.txt' -type f |
    LC_ALL=C awk 'END { print NR + 0 }')
[ "$private_secret_file_count" -eq 3 ]
run_upgrade_success rotated-reuse reused

upgrade_bind_fixture=$temporary_root/upgrade-bind
cp -R "$good" "$upgrade_bind_fixture"
mkdir -p \
    "$upgrade_bind_fixture/data/db" \
    "$upgrade_bind_fixture/data/4fdata" \
    "$upgrade_bind_fixture/data/export"
chmod 0700 \
    "$upgrade_bind_fixture/data/db" \
    "$upgrade_bind_fixture/data/4fdata" \
    "$upgrade_bind_fixture/data/export"
sed \
    -e 's#ESTAB_DB_DATA_SOURCE=estab_db#ESTAB_DB_DATA_SOURCE=./data/db#' \
    -e 's#ESTAB_APP_DATA_SOURCE=estab_data#ESTAB_APP_DATA_SOURCE=./data/4fdata#' \
    -e 's#ESTAB_EXPORT_DATA_SOURCE=estab_export#ESTAB_EXPORT_DATA_SOURCE=./data/export#' \
    "$good/.env" >"$upgrade_bind_fixture/.env"
upgrade_bind_root=$(CDPATH= cd -- "$upgrade_bind_fixture/data" && pwd -P)

# Docker Desktop reports both private snapshot files and productive host binds
# through either of these two VM aliases. Both are accepted only for Docker
# on Darwin; the locked canonical host paths remain the source of truth.
expected_previous_snapshot=$snapshot_directory
run_upgrade_success docker-desktop-host-mnt replaced \
    "$upgrade_bind_fixture" docker Darwin /host_mnt 1 1 \
    "$upgrade_bind_root"
run_upgrade_success docker-desktop-run-desktop-mnt reused \
    "$upgrade_bind_fixture" docker Darwin /run/desktop/mnt/host 1 1 \
    "$upgrade_bind_root"

# The same aliases must never broaden mount matching on a Linux Docker host or
# through Podman, even if uname is Darwin. Productive mounts fail before up.
run_upgrade_failure linux-host-mnt-productive \
    'existing database productive mount does not match' \
    "$upgrade_bind_fixture" docker Linux /host_mnt 1 1 \
    "$upgrade_bind_root"
run_upgrade_failure podman-run-desktop-mnt-productive \
    'existing database productive mount does not match' \
    "$upgrade_bind_fixture" podman Darwin /run/desktop/mnt/host 1 1 \
    "$upgrade_bind_root"

# Exercise the independent private-secret verifier after a successful fake
# Compose up while productive bind sources remain exact and unaliased.
run_upgrade_runtime_failure linux-host-mnt-secret \
    'database secret mount does not use the current private read-only deployment snapshot' \
    "$upgrade_bind_fixture" docker Linux /host_mnt 0 1 \
    "$upgrade_bind_root"
run_upgrade_runtime_failure podman-run-desktop-mnt-secret \
    'database secret mount does not use the current private read-only deployment snapshot' \
    "$upgrade_bind_fixture" podman Darwin /run/desktop/mnt/host 0 1 \
    "$upgrade_bind_root"

grep -Fq 'compose pull' "$deployer"
grep -Fq 'compose up --detach' "$deployer"
grep -Fq 'compose up --detach --force-recreate' "$deployer"
grep -Fq '"$verify_release" --inspect-images' "$deployer"
grep -Fq 'exited\ 0' "$deployer"
grep -Fq 'running\ healthy' "$deployer"
grep -Fq 'admin-auth-init' "$deployer"
grep -Fq 'COMPOSE_* process overrides are forbidden' "$deployer"
grep -Fq 'estab-maintenance-lock-' "$deployer"
grep -Fq 'container rm --force' "$deployer"
grep -Fq 'verify_storage_configuration' "$deployer"
grep -Fq 'state_base=/var/lib/estab-deploy' "$deployer"
grep -Fq 'state_parent=${XDG_STATE_HOME:-}' "$deployer"
grep -Fq 'productive storage sources are equal, nested, overlapping, or unsafe' \
    "$deployer"
grep -Fq "stat -c '%u'" "$deployer"
grep -Fq "stat -f '%u'" "$deployer"
grep -Fq 'verify_secret_configuration' "$deployer"
grep -Fq 'verify_compose_capabilities' "$deployer"
grep -Fq 'verify_existing_runtime_storage' "$deployer"
grep -Fq 'bind source or volume name, safe engine source' "$deployer"

echo "registry release test: OK"
