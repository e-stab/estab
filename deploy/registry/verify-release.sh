#!/bin/sh

set -eu

die()
{
    printf 'eStab release verification: %s\n' "$*" >&2
    exit 1
}

usage()
{
    printf 'Usage: %s [--inspect-images] [RELEASE_DIRECTORY]\n' "$0" >&2
    exit 64
}

inspect_images=0
case "${1:-}" in
    --inspect-images)
        inspect_images=1
        shift
        ;;
    --help|-h)
        usage
        ;;
    --*)
        usage
        ;;
esac
[ "$#" -le 1 ] || usage

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
release_dir=${1:-$script_dir}
[ -d "$release_dir" ] || die "release directory does not exist: $release_dir"
release_dir=$(CDPATH= cd -- "$release_dir" && pwd -P) ||
    die "cannot resolve release directory: $release_dir"

release_file=$release_dir/RELEASE
environment_file=$release_dir/.env
environment_example=$release_dir/.env.example
checksum_manifest=$release_dir/SHA256SUMS
for required_file in \
    "$release_file" \
    "$environment_file" \
    "$environment_example" \
    "$checksum_manifest"
do
    [ -f "$required_file" ] && [ ! -L "$required_file" ] &&
        [ -r "$required_file" ] ||
        die "required regular file is missing or unreadable: $required_file"
done

manifest_names=$(
    awk '
      {
        digest = substr($0, 1, 64)
        separator = substr($0, 65, 2)
        name = substr($0, 67)
        if (length(digest) != 64 || digest ~ /[^0-9a-f]/ ||
            (separator != "  " && separator != " *") ||
            name == "" || name == "." || name == ".." ||
            index(name, "/") != 0 || index(name, "\\") != 0 ||
            name ~ /[\r\n]/ || seen[name]++) {
          exit 1
        }
        print name
      }
    ' "$checksum_manifest"
) || die "SHA256SUMS has an unsafe or ambiguous format"
for bound_name in \
    RELEASE \
    .env.example \
    compose.yaml \
    README.md \
    BACKUP-UND-WIEDERHERSTELLUNG.md \
    LICENSE \
    THIRD_PARTY_NOTICES.md \
    deploy.sh \
    verify-release.sh \
    backup.sh \
    verify-backup.sh \
    restore.sh
do
    printf '%s\n' "$manifest_names" | grep -Fqx "$bound_name" ||
        die "SHA256SUMS does not bind required release file: $bound_name"
done
while IFS= read -r bound_name; do
    bound_file=$release_dir/$bound_name
    [ -f "$bound_file" ] && [ ! -L "$bound_file" ] &&
        [ -r "$bound_file" ] ||
        die "bound release entry is not a readable regular file: $bound_name"
done <<EOF
$manifest_names
EOF
for executable_name in \
    deploy.sh \
    verify-release.sh \
    backup.sh \
    verify-backup.sh \
    restore.sh
do
    [ -x "$release_dir/$executable_name" ] ||
        die "release operator is not executable: $executable_name"
done
for nonempty_name in \
    README.md \
    BACKUP-UND-WIEDERHERSTELLUNG.md \
    LICENSE \
    THIRD_PARTY_NOTICES.md
do
    [ -s "$release_dir/$nonempty_name" ] ||
        die "required release documentation is empty: $nonempty_name"
done
if command -v sha256sum >/dev/null 2>&1; then
    (cd "$release_dir" && sha256sum -c SHA256SUMS >/dev/null) ||
        die "a release-bundle checksum differs from SHA256SUMS"
elif command -v shasum >/dev/null 2>&1; then
    (cd "$release_dir" && shasum -a 256 -c SHA256SUMS >/dev/null) ||
        die "a release-bundle checksum differs from SHA256SUMS"
else
    die "sha256sum or shasum is required"
fi

if ! awk '
  NR == 1 && index($0, "Git-Tag: ") == 1 { next }
  NR == 2 && index($0, "Git-Commit: ") == 1 { next }
  NR == 3 && index($0, "App-Image: ") == 1 { next }
  NR == 4 && index($0, "Migrator-Image: ") == 1 { next }
  { exit 1 }
  END { if (NR != 4) exit 1 }
' "$release_file"; then
    die "RELEASE must contain exactly the four canonical identity records"
fi

release_value()
{
    release_label=$1
    awk -v label="$release_label" '
      index($0, label ": ") == 1 {
        count++
        value = substr($0, length(label) + 3)
      }
      END {
        if (count != 1 || value == "" || index(value, "\r") != 0) {
          exit 1
        }
        print value
      }
    ' "$release_file"
}

environment_value()
{
    environment_name=$1
    inspected_environment_file=$2
    awk -v name="$environment_name" '
      index($0, name "=") == 1 {
        count++
        value = substr($0, length(name) + 2)
      }
      END {
        if (count != 1 || value == "" || index(value, "\r") != 0) {
          exit 1
        }
        print value
      }
    ' "$inspected_environment_file"
}

if ! release_tag=$(release_value Git-Tag); then
    die "RELEASE must contain exactly one non-empty Git-Tag"
fi
if ! release_commit=$(release_value Git-Commit); then
    die "RELEASE must contain exactly one non-empty Git-Commit"
fi
if ! release_app_image=$(release_value App-Image); then
    die "RELEASE must contain exactly one non-empty App-Image"
fi
if ! release_migrate_image=$(release_value Migrator-Image); then
    die "RELEASE must contain exactly one non-empty Migrator-Image"
fi

case "$release_tag" in
    latest|'') die "mutable or empty release tag is forbidden" ;;
    *[!a-z0-9._-]*)
        die "Git-Tag is not a lowercase release identifier"
        ;;
esac
case "$release_tag" in
    [a-z0-9]*) ;;
    *) die "Git-Tag must start with a lowercase letter or digit" ;;
esac
[ "${#release_tag}" -le 128 ] ||
    die "Git-Tag exceeds the OCI tag length"

case "$release_commit" in
    *[!0-9a-f]*|'') die "Git-Commit is not a lowercase hexadecimal SHA" ;;
esac
[ "${#release_commit}" -eq 40 ] ||
    die "Git-Commit must contain exactly 40 hexadecimal characters"

validate_image()
{
    image_role=$1
    image_reference=$2
    image_repository=$3
    image_prefix="${image_repository}@sha256:"
    case "$image_reference" in
        "$image_prefix"*) image_digest=${image_reference#"$image_prefix"} ;;
        *)
            die "$image_role image must use the canonical repository and an exact sha256 digest"
            ;;
    esac
    case "$image_digest" in
        *[!0-9a-f]*|'') die "$image_role image digest is not lowercase hexadecimal" ;;
    esac
    [ "${#image_digest}" -eq 64 ] ||
        die "$image_role image digest must contain exactly 64 hexadecimal characters"
}

validate_image app "$release_app_image" ghcr.io/e-stab/estab
validate_image migrator "$release_migrate_image" ghcr.io/e-stab/estab-migrate

if ! environment_app_image=$(environment_value \
    ESTAB_APP_IMAGE "$environment_file"); then
    die ".env must contain exactly one non-empty ESTAB_APP_IMAGE"
fi
if ! environment_migrate_image=$(environment_value \
    ESTAB_MIGRATE_IMAGE "$environment_file"); then
    die ".env must contain exactly one non-empty ESTAB_MIGRATE_IMAGE"
fi
if ! example_app_image=$(environment_value \
    ESTAB_APP_IMAGE "$environment_example"); then
    die ".env.example must contain exactly one non-empty ESTAB_APP_IMAGE"
fi
if ! example_migrate_image=$(environment_value \
    ESTAB_MIGRATE_IMAGE "$environment_example"); then
    die ".env.example must contain exactly one non-empty ESTAB_MIGRATE_IMAGE"
fi
if ! environment_project=$(environment_value \
    COMPOSE_PROJECT_NAME "$environment_file"); then
    die ".env must contain exactly one non-empty COMPOSE_PROJECT_NAME"
fi
if ! example_project=$(environment_value \
    COMPOSE_PROJECT_NAME "$environment_example"); then
    die ".env.example must contain exactly one non-empty COMPOSE_PROJECT_NAME"
fi
[ "$environment_app_image" = "$release_app_image" ] ||
    die "ESTAB_APP_IMAGE differs from the verified release record"
[ "$environment_migrate_image" = "$release_migrate_image" ] ||
    die "ESTAB_MIGRATE_IMAGE differs from the verified release record"
[ "$example_app_image" = "$release_app_image" ] ||
    die "bound .env.example differs from the verified app image"
[ "$example_migrate_image" = "$release_migrate_image" ] ||
    die "bound .env.example differs from the verified migrator image"
[ "$environment_project" = "$example_project" ] ||
    die "COMPOSE_PROJECT_NAME differs from the verified release template"
case "$environment_project" in
    ''|[!a-z0-9]*|*[!a-z0-9_-]*)
        die "COMPOSE_PROJECT_NAME must use Compose-canonical lowercase letters, digits, underscores, or hyphens and start alphanumerically"
        ;;
esac
[ "${#environment_project}" -le 128 ] ||
    die "COMPOSE_PROJECT_NAME is too long for the engine-global maintenance lock"

for protected_environment in "$environment_file" "$environment_example"; do
    if ! LC_ALL=C awk \
        -v app="$release_app_image" \
        -v migrate="$release_migrate_image" \
        -v project="$environment_project" '
          $0 == "ESTAB_APP_IMAGE=" app {
            app_exact++
            next
          }
          $0 == "ESTAB_MIGRATE_IMAGE=" migrate {
            migrate_exact++
            next
          }
          $0 == "COMPOSE_PROJECT_NAME=" project {
            project_exact++
            next
          }
          $0 ~ /^[[:space:]]*(export[[:space:]]+)?ESTAB_(APP|MIGRATE)_IMAGE[[:space:]]*=/ {
            exit 1
          }
          $0 ~ /^[[:space:]]*(export[[:space:]]+)?COMPOSE_(FILE|ENV_FILES|DISABLE_ENV_FILE|PROJECT_NAME)[[:space:]]*=/ {
            exit 1
          }
          END {
            if (app_exact != 1 || migrate_exact != 1 || project_exact != 1) {
              exit 1
            }
          }
        ' "$protected_environment"; then
        die "protected image and Compose assignments must occur exactly once in canonical form: $protected_environment"
    fi
done

if [ "$inspect_images" -eq 1 ]; then
    container_cli=${ESTAB_CONTAINER_CLI:-}
    if [ -z "$container_cli" ]; then
        if command -v docker >/dev/null 2>&1 &&
            docker compose version >/dev/null 2>&1; then
            container_cli=docker
        elif command -v podman >/dev/null 2>&1 &&
            podman compose version >/dev/null 2>&1; then
            container_cli=podman
        else
            die "neither Docker Compose nor Podman Compose is available"
        fi
    fi
    case "$container_cli" in
        docker|podman) ;;
        *) die "ESTAB_CONTAINER_CLI must be docker or podman" ;;
    esac
    command -v "$container_cli" >/dev/null 2>&1 ||
        die "container executable is unavailable: $container_cli"

    inspect_label()
    {
        inspected_image=$1
        inspected_label=$2
        "$container_cli" image inspect --format \
            "{{ index .Config.Labels \"$inspected_label\" }}" \
            "$inspected_image" 2>/dev/null
    }

    for inspected_image in "$release_app_image" "$release_migrate_image"; do
        if ! image_version=$(inspect_label \
            "$inspected_image" org.opencontainers.image.version); then
            die "cannot inspect pulled release image: $inspected_image"
        fi
        if ! image_revision=$(inspect_label \
            "$inspected_image" org.opencontainers.image.revision); then
            die "cannot inspect pulled release image revision: $inspected_image"
        fi
        [ "$image_version" = "$release_tag" ] ||
            die "image version label does not match Git-Tag: $inspected_image"
        [ "$image_revision" = "$release_commit" ] ||
            die "image revision label does not match Git-Commit: $inspected_image"
    done
fi

printf 'eStab release verification: OK (%s, %s)\n' \
    "$release_tag" "$release_commit"
