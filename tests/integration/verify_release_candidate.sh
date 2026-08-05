#!/usr/bin/env bash

set -Eeuo pipefail

if (($# != 4)); then
    echo "Usage: $0 ARCH APP_IMAGE@sha256:... MIGRATE_IMAGE@sha256:... EVIDENCE_DIRECTORY" >&2
    exit 2
fi

expected_arch=$1
app_reference=$2
migrate_reference=$3
evidence_dir=$4

case "$expected_arch" in
    amd64 | arm64) ;;
    *)
        echo "Candidate verification: architecture must be amd64 or arm64" >&2
        exit 2
        ;;
esac

for required_command in docker jq gh sha256sum; do
    command -v "$required_command" >/dev/null 2>&1 || {
        echo "Candidate verification: ${required_command} is required" >&2
        exit 1
    }
done
docker buildx version >/dev/null

if [[ ${GITHUB_REPOSITORY:-} != "e-stab/estab" ]] ||
    [[ ! ${GITHUB_SHA:-} =~ ^[a-f0-9]{40}$ ]]; then
    echo "Candidate verification: trusted repository and 40-character source commit are required" >&2
    exit 2
fi

reference_pattern='^ghcr\.io/e-stab/[a-z0-9._-]+@sha256:[a-f0-9]{64}$'
for image_reference in "$app_reference" "$migrate_reference"; do
    if [[ ! $image_reference =~ $reference_pattern ]]; then
        echo "Candidate verification: image references must use exact e-stab SHA-256 digests" >&2
        exit 2
    fi
done
if [[ ! $app_reference =~ ^ghcr\.io/e-stab/estab@sha256:[a-f0-9]{64}$ ]] ||
    [[ ! $migrate_reference =~ ^ghcr\.io/e-stab/estab-migrate@sha256:[a-f0-9]{64}$ ]]; then
    echo "Candidate verification: application or migration repository is incorrect" >&2
    exit 2
fi
if [[ $evidence_dir != /* || $evidence_dir == "/" ]]; then
    echo "Candidate verification: evidence directory must be a scoped absolute path" >&2
    exit 2
fi

install -d -m 0755 "$evidence_dir"

docker_server_arch=$(docker version --format '{{.Server.Arch}}')
case "$expected_arch:$docker_server_arch" in
    amd64:amd64 | amd64:x86_64 | arm64:arm64 | arm64:aarch64) ;;
    *)
        echo "Candidate verification: Docker server architecture ${docker_server_arch} does not match ${expected_arch}" >&2
        exit 1
        ;;
esac

verify_index_and_native_image() {
    local label=$1
    local image_reference=$2
    local image_repository=${image_reference%@*}
    local expected_digest=${image_reference##*@}
    local manifest
    local manifest_file="$evidence_dir/${label}-index.json"
    local actual_digest
    local platform_count
    local native_descriptor
    local native_platform
    local image_id
    local native_manifest
    local native_manifest_file="$evidence_dir/${label}-native-manifest.json"
    local native_manifest_digest
    local native_config_digest
    local image_license
    local sbom
    local provenance

    manifest=$(docker buildx imagetools inspect "$image_reference" --raw)
    printf '%s' "$manifest" >"$manifest_file"
    actual_digest="sha256:$(sha256sum "$manifest_file" | awk '{ print $1 }')"
    if [[ $actual_digest != "$expected_digest" ]]; then
        echo "Candidate verification: ${label} index payload does not match requested digest" >&2
        exit 1
    fi

    jq -e '
      .schemaVersion == 2
      and (.manifests | type == "array")
      and
      ([.manifests[] | select(
        .platform.os == "linux" and .platform.architecture == "amd64"
      )] | length) == 1
      and
      ([.manifests[] | select(
        .platform.os == "linux" and .platform.architecture == "arm64"
      )] | length) == 1
    ' "$manifest_file" >/dev/null

    platform_count=$(jq -r --arg arch "$expected_arch" '
      [.manifests[] | select(
        .platform.os == "linux" and .platform.architecture == $arch
      )] | length
    ' "$manifest_file")
    if [[ $platform_count != 1 ]]; then
        echo "Candidate verification: ${label} lacks exactly one linux/${expected_arch} image" >&2
        exit 1
    fi
    native_descriptor=$(jq -r --arg arch "$expected_arch" '
      .manifests[] | select(
        .platform.os == "linux" and .platform.architecture == $arch
      ) | .digest
    ' "$manifest_file")
    if [[ ! $native_descriptor =~ ^sha256:[a-f0-9]{64}$ ]]; then
        echo "Candidate verification: ${label} native descriptor is invalid" >&2
        exit 1
    fi
    native_manifest=$(docker buildx imagetools inspect \
        "$image_repository@$native_descriptor" --raw)
    printf '%s' "$native_manifest" >"$native_manifest_file"
    native_manifest_digest="sha256:$(sha256sum "$native_manifest_file" |
        awk '{ print $1 }')"
    if [[ $native_manifest_digest != "$native_descriptor" ]]; then
        echo "Candidate verification: ${label} native manifest does not match its index descriptor" >&2
        exit 1
    fi
    native_config_digest=$(jq -r '
      select(.schemaVersion == 2)
      | select(.layers | type == "array" and length > 0)
      | .config.digest
    ' "$native_manifest_file")
    if [[ ! $native_config_digest =~ ^sha256:[a-f0-9]{64}$ ]]; then
        echo "Candidate verification: ${label} native config digest is invalid" >&2
        exit 1
    fi

    sbom=$(docker buildx imagetools inspect "$image_reference" \
        --format "{{ json (index .SBOM \"linux/$expected_arch\").SPDX }}")
    printf '%s' "$sbom" >"$evidence_dir/${label}-sbom.json"
    jq -e '
      .SPDXID == "SPDXRef-DOCUMENT"
      and (.spdxVersion | startswith("SPDX-"))
      and (.packages | type == "array" and length > 0)
    ' "$evidence_dir/${label}-sbom.json" >/dev/null
    provenance=$(docker buildx imagetools inspect "$image_reference" \
        --format "{{ json (index .Provenance \"linux/$expected_arch\").SLSA }}")
    printf '%s' "$provenance" >"$evidence_dir/${label}-provenance.json"
    jq -e --arg platform "linux/$expected_arch" '
      (.buildType | type == "string" and length > 0)
      and (.builder.id | type == "string")
      and .invocation.environment.platform == $platform
    ' "$evidence_dir/${label}-provenance.json" >/dev/null

    docker pull "$image_reference"
    native_platform=$(docker image inspect \
        --format '{{.Os}}/{{.Architecture}}' "$image_reference")
    if [[ $native_platform != "linux/$expected_arch" ]]; then
        echo "Candidate verification: ${label} pulled ${native_platform}, expected linux/${expected_arch}" >&2
        exit 1
    fi
    image_id=$(docker image inspect --format '{{.Id}}' "$image_reference")
    if [[ $image_id != "$native_config_digest" ]]; then
        echo "Candidate verification: ${label} local image is not the indexed native config" >&2
        exit 1
    fi
    image_license=$(docker image inspect --format \
        '{{ index .Config.Labels "org.opencontainers.image.licenses" }}' \
        "$image_reference")
    if [[ $image_license != GPL-3.0-only ]]; then
        echo "Candidate verification: ${label} image license is not GPL-3.0-only" >&2
        exit 1
    fi

    gh attestation verify "oci://$image_reference" \
        --repo "$GITHUB_REPOSITORY" \
        --bundle-from-oci \
        --source-digest "$GITHUB_SHA" \
        --format=json \
        >"$evidence_dir/${label}-attestation.json"
    jq -e 'type == "array" and length > 0' \
        "$evidence_dir/${label}-attestation.json" >/dev/null
    {
        printf 'reference=%s\n' "$image_reference"
        printf 'index_digest=%s\n' "$expected_digest"
        printf 'native_descriptor=%s\n' "$native_descriptor"
        printf 'native_config_digest=%s\n' "$native_config_digest"
        printf 'native_platform=%s\n' "$native_platform"
        printf 'local_image_id=%s\n' "$image_id"
        printf 'image_license=%s\n' "$image_license"
    } >"$evidence_dir/${label}-image.env"
}

verify_index_and_native_image app "$app_reference"
verify_index_and_native_image migrate "$migrate_reference"

{
    printf 'expected_architecture=%s\n' "$expected_arch"
    printf 'docker_server_architecture=%s\n' "$docker_server_arch"
    printf 'git_commit=%s\n' "$GITHUB_SHA"
    printf 'workflow_run_id=%s\n' "${GITHUB_RUN_ID:-unknown}"
    printf 'workflow_run_attempt=%s\n' "${GITHUB_RUN_ATTEMPT:-unknown}"
} >"$evidence_dir/candidate.env"

echo "Candidate verification: exact linux/${expected_arch} images and attestations verified"
