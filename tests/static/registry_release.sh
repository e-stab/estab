#!/bin/sh

set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd -P)
verifier=$repo_root/deploy/registry/verify-release.sh
deployer=$repo_root/deploy/registry/deploy.sh

[ -x "$verifier" ] || {
    echo "Registry release test: verifier is not executable" >&2
    exit 1
}
[ -x "$deployer" ] || {
    echo "Registry release test: deployer is not executable" >&2
    exit 1
}

temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/estab-release-test.XXXXXX")
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
} >"$good/.env"
cp "$good/.env" "$good/.env.example"
printf 'services: {}\n' >"$good/compose.yaml"
cp "$verifier" "$good/verify-release.sh"
cp "$deployer" "$good/deploy.sh"
for operator in backup.sh verify-backup.sh restore.sh; do
    printf '#!/bin/sh\nexit 0\n' >"$good/$operator"
    chmod 0755 "$good/$operator"
done
for document in \
    README.md \
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
                backup.sh \
                verify-backup.sh \
                restore.sh \
                README.md \
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
                backup.sh \
                verify-backup.sh \
                restore.sh \
                README.md \
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

cp -R "$good" "$temporary_root/shadowed"
printf ' export ESTAB_APP_IMAGE=ghcr.io/e-stab/estab:latest\n' \
    >>"$temporary_root/shadowed/.env"
expect_failure shadowed 'protected image and Compose assignments'

fake_bin=$temporary_root/bin
mkdir "$fake_bin"
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
            compose_action=$compose_argument
        done
        case "$compose_action" in
            config) ;;
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
    *)
        exit 1
        ;;
esac
EOF
chmod 0755 "$fake_bin/docker"

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
sed \
    -e 's#ESTAB_DB_DATA_SOURCE=estab_db#ESTAB_DB_DATA_SOURCE=./data/db#' \
    -e 's#ESTAB_APP_DATA_SOURCE=estab_data#ESTAB_APP_DATA_SOURCE=./data/4fdata#' \
    -e 's#ESTAB_EXPORT_DATA_SOURCE=estab_export#ESTAB_EXPORT_DATA_SOURCE=./data/export#' \
    "$good/.env" >"$temporary_root/safe-bind-storage/.env"
PATH="$fake_bin:$PATH" ESTAB_CONTAINER_CLI=docker \
    "$temporary_root/safe-bind-storage/deploy.sh" check >/dev/null

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
      compose_action=$compose_argument
    done
    case "$compose_action" in
      config|pull) exit 0 ;;
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
if PATH="$deploy_fake_dir:$PATH" \
    ESTAB_CONTAINER_CLI=docker \
    DEPLOY_STATE="$deploy_state" \
    "$release_two/deploy.sh" up \
    >"$temporary_root/deploy-lock.stdout" \
    2>"$temporary_root/deploy-lock.stderr"; then
    echo "Registry release test: deploy ignored another directory's project lock" >&2
    exit 1
fi
grep -Fq 'operation=backup' "$temporary_root/deploy-lock.stderr"
[ ! -e "$deploy_state/unsafe-up" ]

grep -Fq 'compose pull' "$deployer"
grep -Fq 'compose up --detach' "$deployer"
grep -Fq '"$verify_release" --inspect-images' "$deployer"
grep -Fq 'exited\ 0' "$deployer"
grep -Fq 'running\ healthy' "$deployer"
grep -Fq 'admin-auth-init' "$deployer"
grep -Fq 'COMPOSE_ENV_FILES' "$deployer"
grep -Fq 'estab-maintenance-lock-' "$deployer"
grep -Fq 'container rm --force' "$deployer"
grep -Fq 'verify_storage_configuration' "$deployer"
grep -Fq 'productive storage sources are equal, nested, overlapping, or unsafe' \
    "$deployer"

echo "registry release test: OK"
