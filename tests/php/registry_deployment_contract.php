<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $path): string {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $contents;
};

$sourceCompose = $read($root . '/compose.yaml');
$sourceEnvironment = $read($root . '/.env.example');
$registryCompose = $read($root . '/deploy/registry/compose.yaml');
$registryEnvironment = $read($root . '/deploy/registry/.env.example');
$registryReadme = $read($root . '/deploy/registry/README.md');
$workflow = $read($root . '/.github/workflows/publish-images.yml');
$ciWorkflow = $read($root . '/.github/workflows/ci.yml');
$integration = $read($root . '/tests/integration/registry_compose.sh');
$restoreRoundtrip = $read($root . '/tests/integration/restore_roundtrip.sh');
$backupRunbook = $read($root . '/docs/BACKUP-UND-WIEDERHERSTELLUNG.md');
$backupOperator = $read($root . '/deploy/registry/backup.sh');
$backupVerifier = $read($root . '/deploy/registry/verify-backup.sh');
$restoreOperator = $read($root . '/deploy/registry/restore.sh');
$restoreStaticTest = $read($root . '/tests/static/restore_operator.sh');
$backupOperatorStaticTest = $read($root . '/tests/static/backup_operator.sh');
$backupVerifierStaticTest = $read($root . '/tests/static/backup_verifier.sh');
$releaseVerifier = $read($root . '/deploy/registry/verify-release.sh');
$deploymentOperator = $read($root . '/deploy/registry/deploy.sh');
$offlineHelper = $read($root . '/deploy/registry/offline-images.sh');
$releaseEvidenceGuide = $read($root . '/deploy/registry/RELEASE-EVIDENCE.md');
$offlineStaticTest = $read($root . '/tests/static/offline_images.sh');
$staticRunner = $read($root . '/tests/static/run.sh');
$trivyIgnore = $read($root . '/.trivyignore.yaml');
$ci = $read($root . '/tests/integration/ci.sh');
$candidateVerifier = $read($root . '/tests/integration/verify_release_candidate.sh');
$appDockerfile = $read($root . '/Dockerfile');
$migrateDockerfile = $read($root . '/docker/db/Dockerfile.migrate');

$assert(
    str_starts_with($registryCompose, "name: \${COMPOSE_PROJECT_NAME:-estab}\n")
    && preg_match('/^COMPOSE_PROJECT_NAME=estab$/m', $registryEnvironment) === 1
    && !str_contains($registryCompose, 'build:')
    && !str_contains($registryCompose, '/docker-entrypoint-initdb.d/')
    && str_contains($registryCompose, '${ESTAB_APP_IMAGE:?')
    && str_contains($registryCompose, '${ESTAB_MIGRATE_IMAGE:?')
    && !str_contains($registryCompose, ':latest')
    && preg_match('/^ESTAB_APP_IMAGE=\R/m', $registryEnvironment) === 1
    && preg_match('/^ESTAB_MIGRATE_IMAGE=\R/m', $registryEnvironment) === 1
    && !str_contains($registryEnvironment, ':latest'),
    'Registry deployment is not pull-only or permits implicit image selection'
);
$assert(
    str_contains(
        $registryCompose,
        'mariadb:11.8.8@sha256:efb4959ef2c835cd735dbc388eb9ad6aab0c78dd64febcd51bc17481111890c4'
    )
    && str_contains(
        $sourceCompose,
        'mariadb:11.8.8@sha256:efb4959ef2c835cd735dbc388eb9ad6aab0c78dd64febcd51bc17481111890c4'
    )
    && str_contains(
        $migrateDockerfile,
        'mariadb:11.8.8@sha256:efb4959ef2c835cd735dbc388eb9ad6aab0c78dd64febcd51bc17481111890c4'
    ),
    'Source, registry, and migrator do not pin the same MariaDB index digest'
);
$assert(
    !str_contains($sourceCompose, 'innodb_snapshot_isolation')
    && !str_contains($registryCompose, 'innodb_snapshot_isolation'),
    'Source or registry deployment overrides MariaDB snapshot-isolation defaults'
);
$assert(
    str_contains(
        $appDockerfile,
        'php:8.5.8-apache-trixie@sha256:eacc0d98992683cb46e4f8f44b2418a0323855dc8b59d32dc54f7a9b90a966dd'
    ),
    'Application base does not pin the verified PHP multi-architecture index'
);
$assert(
    str_contains($ciWorkflow, 'Validate GitHub Actions workflows')
    && str_contains(
        $ciWorkflow,
        'docker.io/rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667'
    )
    && str_contains($ciWorkflow, '--volume "$GITHUB_WORKSPACE:/repo:ro"')
    && str_contains($ciWorkflow, '--workdir /repo'),
    'CI does not validate every workflow with the pinned read-only actionlint image'
);
$assert(
    substr_count($registryCompose, 'condition: service_healthy') === 2
    && str_contains($registryCompose, 'condition: service_completed_successfully'),
    'Registry application start is not gated by healthy DB and successful migration'
);
foreach ([
    '${ESTAB_DB_DATA_SOURCE:-estab_db}:/var/lib/mysql:Z',
    '${ESTAB_APP_DATA_SOURCE:-estab_data}:/var/www/html/4fdata:z',
    '${ESTAB_EXPORT_DATA_SOURCE:-estab_export}:/var/lib/estab/export:z',
    'estab_auth:/run/estab-auth:ro,z',
] as $volumeMapping) {
    $assert(
        str_contains($registryCompose, $volumeMapping),
        'Registry deployment is missing persistent mapping ' . $volumeMapping
    );
}
foreach ([
    'estab_db_password' => 'db_password.txt',
    'estab_db_root_password' => 'db_root_password.txt',
    'estab_admin_password' => 'admin_password.txt',
] as $secretName => $secretFile) {
    $assert(
        str_contains($registryCompose, $secretName)
        && str_contains($registryEnvironment, $secretFile),
        'Registry deployment is missing file-backed secret ' . $secretName
    );
}
$assert(
    substr_count(
        $registryCompose,
        '${ESTAB_DB_PASSWORD_SECRET_FILE:-./secrets/db_password.txt}:/run/secrets/estab_db_password:ro,z'
    ) === 2
    && substr_count(
        $registryCompose,
        '${ESTAB_DB_ROOT_PASSWORD_SECRET_FILE:-./secrets/db_root_password.txt}:/run/secrets/estab_db_root_password:ro,z'
    ) === 2
    && substr_count(
        $registryCompose,
        '${ESTAB_ADMIN_PASSWORD_SECRET_FILE:-./secrets/admin_password.txt}:/run/secrets/estab_admin_password:ro,Z'
    ) === 1
    && !str_contains($registryCompose, 'create_host_path:')
    && !str_contains($registryCompose, 'selinux:')
    && !str_contains($registryCompose, "\nsecrets:")
    && !str_contains($registryCompose, "\n    secrets:")
    && !str_contains($registryCompose, 'label=disable')
    && !str_contains($registryCompose, 'privileged:'),
    'Registry file secrets and data mounts are not safely share-labelled for Docker and Podman on SELinux'
);
foreach ([
    'ESTAB_DB_DATA_SOURCE',
    'ESTAB_APP_DATA_SOURCE',
    'ESTAB_EXPORT_DATA_SOURCE',
] as $environmentName) {
    $assert(
        str_contains($registryCompose, $environmentName)
        && str_contains($registryEnvironment, $environmentName),
        'Registry deployment lacks storage override ' . $environmentName
    );
}
foreach ([
    'ESTAB_DB_NAME',
    'ESTAB_DB_USER',
    'ESTAB_ADMIN_USER',
    'ESTAB_PUBLIC_URL',
    'ESTAB_BASE_PATH',
    'ESTAB_ALLOW_SELF_REGISTRATION',
    'ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF',
    'ESTAB_TRUST_PROXY_HEADERS',
    'ESTAB_TRUSTED_PROXIES',
    'ESTAB_UPLOAD_MAX_BYTES',
    'ESTAB_PDF_ATTACHMENT_MAX_BYTES',
] as $environmentName) {
    $assert(
        str_contains($sourceCompose, $environmentName)
        && str_contains($sourceEnvironment, $environmentName)
        && str_contains($registryCompose, $environmentName)
        && str_contains($registryEnvironment, $environmentName),
        'Registry deployment drifted on ' . $environmentName
    );
}
$assert(
    !str_contains($sourceCompose, 'ESTAB_REVIEW_OUTGOING_MESSAGES')
    && !str_contains($registryCompose, 'ESTAB_REVIEW_OUTGOING_MESSAGES')
    && !str_contains($sourceEnvironment, 'ESTAB_REVIEW_OUTGOING_MESSAGES')
    && !str_contains($registryEnvironment, 'ESTAB_REVIEW_OUTGOING_MESSAGES'),
    'Deployment still exposes an unsafe outgoing-review bypass'
);
$selfRegistrationComposeSetting =
    'ESTAB_ALLOW_SELF_REGISTRATION: ${ESTAB_ALLOW_SELF_REGISTRATION:-false}';
$assert(
    str_contains($sourceCompose, $selfRegistrationComposeSetting)
    && str_contains($registryCompose, $selfRegistrationComposeSetting)
    && preg_match(
        '/^ESTAB_ALLOW_SELF_REGISTRATION=false$/m',
        $sourceEnvironment
    ) === 1
    && preg_match(
        '/^ESTAB_ALLOW_SELF_REGISTRATION=false$/m',
        $registryEnvironment
    ) === 1,
    'Self-registration does not keep the published secure false default'
);
$assert(
    str_contains($registryCompose, 'database:')
    && str_contains($registryCompose, 'internal: true')
    && !preg_match('/^\s{4}ports:\R(?:\s{6}.+\R)+\s{4}networks:\R\s{6}- database/m', $registryCompose),
    'Registry database network is not private'
);
$assert(
    str_contains($registryCompose, '${ESTAB_HTTP_BIND:-127.0.0.1}')
    && str_contains($registryReadme, 'ESTAB_HTTP_BIND=0.0.0.0')
    && str_contains($registryReadme, 'TLS')
    && str_contains($registryReadme, 'Firewall'),
    'Registry bundle does not keep a safe default and explain NAS exposure'
);
$assert(
    str_contains($registryReadme, 'linux/amd64')
    && str_contains($registryReadme, 'linux/arm64')
    && str_contains($registryReadme, 'Synology Container Manager')
    && str_contains($registryReadme, 'sha256')
    && str_contains($registryReadme, 'Vollbackup')
    && str_contains($registryReadme, 'sh ./verify-release.sh')
    && str_contains($registryReadme, 'ESTAB_APP_IMAGE')
    && str_contains($registryReadme, 'ESTAB_MIGRATE_IMAGE')
    && str_contains($registryReadme, 'Die Projektlizenz liegt als `LICENSE` vor')
    && str_contains($registryReadme, '`THIRD_PARTY_NOTICES.md` geprüft')
    && str_contains($registryReadme, 'skopeo copy --all --preserve-digests'),
    'Registry runbook omits architecture, licensing, Synology, digest, backup, or verification'
);
$assert(
    str_contains($workflow, "on:\n  workflow_dispatch:")
    && !str_contains($workflow, "\n  push:\n")
    && !str_contains($workflow, "\n  pull_request:\n")
    && str_contains($workflow, 'redistribution_cleared')
    && str_contains($workflow, 'ESTAB_CONTAINER_PUBLISH_ENABLED')
    && str_contains($workflow, 'ESTAB_CONTAINER_PUBLISH_REVIEWER_CONFIGURED')
    && str_contains($workflow, 'environment: container-publish')
    && str_contains($workflow, 'Rights and license review has not been confirmed.')
    && str_contains(
        $workflow,
        'for redistribution_file in LICENSE THIRD_PARTY_NOTICES.md'
    )
    && str_contains(
        $workflow,
        'Required redistribution artifact is missing or empty'
    ),
    'Image publishing is not explicitly gated by rights, reviewer, and concrete license artifacts'
);
$assert(
    str_contains($workflow, 'group: publish-images')
    && !str_contains($workflow, 'github.ref }}')
    && str_contains($workflow, 'RELEASE_REF_TYPE')
    && str_contains($workflow, 'RELEASE_REF_NAME')
    && str_contains($workflow, 'e-stab/estab')
    && str_contains($workflow, 'Refuse an existing GitHub release')
    && str_contains($workflow, 'tools/verify-github-release-policy.sh')
    && str_contains($workflow, 'The mutable latest tag is deliberately unsupported.')
    && !str_contains($workflow, 'publish_latest'),
    'Publishing is not globally serialized, Git-tag-bound, immutable, or latest-free'
);
$assert(
    str_contains($workflow, 'packages: write')
    && str_contains($workflow, 'docker/login-action@371161bbe7024a29a25c5e19bfcbc0804fe9ad2c')
    && str_contains($workflow, 'docker/setup-qemu-action@96fe6ef7f33517b61c61be40b68a1882f3264fb8')
    && str_contains($workflow, 'docker/setup-buildx-action@bb05f3f5519dd87d3ba754cc423b652a5edd6d2c')
    && str_contains($workflow, 'docker/build-push-action@53b7df96c91f9c12dcc8a07bcb9ccacbed38856a')
    && str_contains($workflow, 'actions/attest@f7c74d28b9d84cb8768d0b8ca14a4bac6ef463e6')
    && str_contains(
        $workflow,
        'tonistiigi/binfmt:latest@sha256:400a4873b838d1b89194d982c45e5fb3cda4593fbfd7e08a02e76b03b21166f0'
    )
    && str_contains(
        $workflow,
        'moby/buildkit:buildx-stable-1@sha256:2f5adac4ecd194d9f8c10b7b5d7bceb5186853db1b26e5abd3a657af0b7e26ec'
    )
    && str_contains($workflow, 'platforms: arm64')
    && str_contains($workflow, 'version: v0.35.0')
    && !str_contains($workflow, 'docker/metadata-action@'),
    'Publish workflow lacks pinned actions, binfmt, BuildKit, or attestations'
);
preg_match_all('/^\s*uses:\s*[^@\s]+@([^\s#]+)/m', $workflow, $actionReferences);
$actionsPinned = ($actionReferences[1] ?? []) !== [];
foreach ($actionReferences[1] ?? [] as $actionReference) {
    $actionsPinned = $actionsPinned
        && preg_match('/\A[0-9a-f]{40}\z/D', $actionReference) === 1;
}
$assert(
    $actionsPinned,
    'One or more publish workflow actions use a mutable reference'
);
$assert(
    str_contains(
        $ciWorkflow,
        'php:8.5.8-cli-trixie@sha256:58b996c35ce0511cdbaa1fc0476a194fd0221097d721ff7df5af0b6f1a3d0202'
    )
    && str_contains(
        $ciWorkflow,
        'actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803'
    )
    && str_contains(
        $ciWorkflow,
        'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a'
    )
    && str_contains($ciWorkflow, 'cron: "23 3 * * 1"')
    && str_contains($ciWorkflow, 'if: always()')
    && str_contains($ciWorkflow, 'name: compose-evidence-${{ matrix.arch }}-')
    && !preg_match('/uses:\s*[^@\s]+@v\d+/m', $ciWorkflow),
    'Standard CI lacks pinned references, scheduled drift checks, or retained evidence'
);
$assert(
    str_contains($ciWorkflow, 'runner: ubuntu-24.04')
    && str_contains($ciWorkflow, 'runner: ubuntu-24.04-arm')
    && str_contains($ciWorkflow, 'browser: required')
    && str_contains($ciWorkflow, 'browser: skip')
    && str_contains($ciWorkflow, 'Fresh Docker Compose integration (${{ matrix.arch }})')
    && str_contains($workflow, 'runner: ubuntu-24.04')
    && str_contains($workflow, 'runner: ubuntu-24.04-arm')
    && str_contains($workflow, 'Verify exact release digests (${{ matrix.arch }})')
    && str_contains($workflow, 'ESTAB_BROWSER_TEST: ${{ matrix.browser }}'),
    'CI or release verification does not execute the container suite natively on amd64 and arm64'
);
$assert(
    str_contains($workflow, 'platforms: linux/amd64,linux/arm64')
    && str_contains($workflow, 'provenance: mode=max')
    && str_contains($workflow, 'sbom: true')
    && substr_count(
        $workflow,
        'docker/build-push-action@53b7df96c91f9c12dcc8a07bcb9ccacbed38856a'
    ) === 2
    && !str_contains($workflow, 'push: false')
    && !preg_match('/^\s+push:\s+true\s*$/m', $workflow)
    && substr_count(
        $workflow,
        'push-by-digest=true,name-canonical=true,push=true'
    ) === 2
    && !preg_match('/^\s+tags:/m', $workflow)
    && str_contains($workflow, 'name: Build digest-only image pair')
    && !str_contains($workflow, 'CANDIDATE_IMAGE_TAG')
    && !str_contains($workflow, 'candidate_tag:')
    && str_contains($workflow, 'registry_publication=digest-only')
    && str_contains($workflow, 'APP_DIGEST: ${{ needs.stage.outputs.app_digest }}')
    && str_contains($workflow, 'MIGRATE_DIGEST: ${{ needs.stage.outputs.migrate_digest }}')
    && str_contains($workflow, 'ESTAB_PREBUILT_APP_IMAGE:')
    && str_contains($workflow, 'ESTAB_PREBUILT_MIGRATE_IMAGE:')
    && str_contains($workflow, 'bash tests/integration/ci.sh')
    && str_contains($workflow, 'gh attestation verify')
    && str_contains($workflow, '--bundle-from-oci')
    && str_contains($workflow, '--source-digest "$GITHUB_SHA"')
    && str_contains($workflow, 'index .SBOM')
    && str_contains($workflow, 'index .Provenance')
    && str_contains($workflow, 'SPDXRef-DOCUMENT')
    && str_contains($workflow, '.packages | type == "array" and length > 0')
    && str_contains($workflow, '.invocation.environment.platform == $platform')
    && str_contains($workflow, 'create-storage-record: false')
    && str_contains($workflow, 'Upload successful architecture evidence')
    && str_contains($workflow, 'name: publish-evidence-${{ matrix.arch }}-')
    && str_contains($workflow, 'Upload failure diagnostics')
    && str_contains($workflow, 'retention-days: 90')
    && str_contains($workflow, 'retention-days: 7'),
    'Publish workflow omits digest-only builds, exact-digest native gates, or retained evidence'
);
$assert(
    is_executable($root . '/tests/integration/verify_release_candidate.sh')
    && str_contains($candidateVerifier, 'amd64 | arm64')
    && str_contains($candidateVerifier, 'actual_digest="sha256:')
    && str_contains($candidateVerifier, 'native_manifest_digest="sha256:')
    && str_contains($candidateVerifier, 'local image is not the indexed native config')
    && str_contains($candidateVerifier, '.SBOM')
    && str_contains($candidateVerifier, '.Provenance')
    && str_contains(
        $candidateVerifier,
        'org.opencontainers.image.licenses'
    )
    && str_contains($candidateVerifier, 'image_license != GPL-3.0-only')
    && str_contains($candidateVerifier, '--bundle-from-oci')
    && str_contains($candidateVerifier, '--source-digest "$GITHUB_SHA"')
    && str_contains($ci, 'prebuilt_app_image=${ESTAB_PREBUILT_APP_IMAGE:-}')
    && str_contains($ci, 'prebuilt_migrate_image=${ESTAB_PREBUILT_MIGRATE_IMAGE:-}')
    && str_contains($ci, 'prebuilt images must use exact sha256 digest references')
    && str_contains($ci, 'verify_prebuilt_runtime_images initial')
    && str_contains($ci, 'verify_prebuilt_runtime_images final'),
    'Exact candidate verifier does not bind index, platform manifest, config, attestation, and runtime IDs'
);
$trivyAction = 'aquasecurity/trivy-action@ed142fd0673e97e23eac54620cfb913e5ce36c25';
$assert(
    substr_count($ciWorkflow, $trivyAction) === 3
    && substr_count($workflow, $trivyAction) === 3
    && substr_count($ciWorkflow, 'severity: HIGH,CRITICAL') === 3
    && substr_count($workflow, 'severity: HIGH,CRITICAL') === 3
    && substr_count($ciWorkflow, 'trivyignores: .trivyignore.yaml') === 1
    && substr_count($workflow, 'trivyignores: .trivyignore.yaml') === 1
    && str_contains($ciWorkflow, 'version: v0.70.0')
    && str_contains($workflow, 'version: v0.70.0')
    && str_contains($appDockerfile, 'apt-get purge -y --auto-remove')
    && str_contains($migrateDockerfile, 'rm -f /usr/local/bin/gosu')
    && substr_count($trivyIgnore, 'paths: [usr/local/bin/gosu]') === 15
    && substr_count($trivyIgnore, 'expired_at: 2026-10-31') === 15,
    'Native CI or release verification lacks the pinned expiring high/critical image vulnerability gate'
);
$assert(
    str_contains($workflow, 'APP_IMAGE: ghcr.io/e-stab/estab')
    && str_contains($workflow, 'MIGRATE_IMAGE: ghcr.io/e-stab/estab-migrate')
    && str_contains($workflow, 'org.opencontainers.image.version=')
    && substr_count(
        $workflow,
        'org.opencontainers.image.licenses=GPL-3.0-only'
    ) === 2,
    'Publish workflow omits an image or its verified GPL license label'
);
$releaseDraftPosition = strpos(
    $workflow,
    'create_request="$RUNNER_TEMP/create-release.json"'
);
$releaseUploadPosition = strpos(
    $workflow,
    'upload_owned_release_asset "$BUNDLE_PATH"'
);
$releaseDownloadPosition = strpos(
    $workflow,
    '"$verification_dir/$BUNDLE_NAME.tar.gz"'
);
$permanentEvidencePosition = strpos(
    $workflow,
    'evidence_name="$BUNDLE_NAME-evidence"'
);
$publicationEvidencePosition = strpos($workflow, 'name: publication-evidence-${{ github.run_id }}-');
$releasePublishPosition = strpos(
    $workflow,
    'publish_request="$RUNNER_TEMP/publish-owned-release.json"'
);
$releaseCleanupPosition = strpos(
    $workflow,
    "name: Delete only this run's still-private draft after failure"
);
$assert(
    str_contains($workflow, 'contents: write')
    && str_contains($workflow, 'name: Publish verified digests and installation bundle')
    && str_contains($workflow, '- verify_candidate')
    && str_contains($workflow, 'Build immutable installation bundle')
    && str_contains($workflow, 'deploy/registry/backup.sh')
    && str_contains($workflow, 'deploy/registry/deploy.sh')
    && str_contains($workflow, 'deploy/registry/restore.sh')
    && str_contains($workflow, 'deploy/registry/verify-backup.sh')
    && str_contains($workflow, 'deploy/registry/verify-release.sh')
    && str_contains($workflow, 'deploy/registry/offline-images.sh')
    && str_contains($workflow, 'deploy/registry/RELEASE-EVIDENCE.md')
    && str_contains($workflow, 'cp LICENSE THIRD_PARTY_NOTICES.md "$bundle_root/"')
    && str_contains(
        $workflow,
        's#../../docs/BACKUP-UND-WIEDERHERSTELLUNG.md#BACKUP-UND-WIEDERHERSTELLUNG.md#g'
    )
    && str_contains(
        $workflow,
        's#deploy/registry/backup.sh#./backup.sh#g'
    )
    && str_contains(
        $workflow,
        's#deploy/registry/verify-backup.sh#./verify-backup.sh#g'
    )
    && str_contains(
        $workflow,
        's#deploy/registry/restore.sh#./restore.sh#g'
    )
    && str_contains($workflow, 'ESTAB_APP_IMAGE=" app')
    && str_contains($workflow, 'ESTAB_MIGRATE_IMAGE=" migrate')
    && str_contains($workflow, 'App-Image: %s@%s')
    && str_contains($workflow, 'Migrator-Image: %s@%s')
    && str_contains($workflow, 'sha256sum -- * .env.example')
    && str_contains($workflow, 'sha256sum "$bundle_name.tar.gz"')
    && !str_contains($workflow, 'sha256sum "$RUNNER_TEMP/$bundle_name.tar.gz"')
    && str_contains($workflow, '"repos/$GITHUB_REPOSITORY/releases"')
    && str_contains($workflow, 'draft: true')
    && substr_count($workflow, 'make_latest: "false"') === 2
    && str_contains(
        $workflow,
        'https://uploads.github.com/repos/$GITHUB_REPOSITORY/releases/$owned_release_id/assets?name=$asset_name'
    )
    && str_contains(
        $workflow,
        'https://api.github.com/repos/$GITHUB_REPOSITORY/releases/assets/$asset_id'
    )
    && !str_contains($workflow, 'docker buildx imagetools create')
    && !str_contains($workflow, 'gh release create ')
    && !str_contains($workflow, 'gh release upload ')
    && !str_contains($workflow, 'gh release download ')
    && !str_contains($workflow, 'gh release edit ')
    && str_contains($workflow, 'id: release_draft')
    && str_contains(
        $workflow,
        'OWNED_RELEASE_ID: ${{ steps.release_draft.outputs.release_id }}'
    )
    && substr_count(
        $workflow,
        '"repos/$GITHUB_REPOSITORY/releases/$OWNED_RELEASE_ID"'
    ) >= 4
    && str_contains($workflow, 'gh api --method PATCH')
    && str_contains($workflow, 'expected_release_assets')
    && str_contains($workflow, 'owned_release_identity')
    && str_contains($workflow, '.digest == $digest')
    && str_contains($workflow, '.size == $size')
    && str_contains($workflow, '.id == $id')
    && str_contains(
        $workflow,
        "if: \${{ failure() && steps.release_draft.outputs.release_id != '' }}"
    )
    && str_contains($workflow, '.draft == true')
    && str_contains($workflow, '.immutable == false')
    && str_contains($workflow, '.published_at == null')
    && str_contains(
        $workflow,
        '"repos/$GITHUB_REPOSITORY/releases/$owned_release_id"'
    )
    && !str_contains($workflow, 'gh release delete "$RELEASE_IMAGE_TAG"')
    && !str_contains($workflow, 'promotion_started')
    && str_contains($workflow, 'Create and verify the complete release draft')
    && str_contains($workflow, 'name: publication-evidence-${{ github.run_id }}-')
    && str_contains($workflow, '"$BUNDLE_NAME.tar.gz.sha256"')
    && str_contains($workflow, 'Refusing unsafe release asset: $asset_path')
    && $releaseDraftPosition !== false
    && $releaseUploadPosition !== false
    && $releaseDownloadPosition !== false
    && $permanentEvidencePosition !== false
    && $publicationEvidencePosition !== false
    && $releasePublishPosition !== false
    && $releaseCleanupPosition !== false
    && $releaseDraftPosition < $releaseUploadPosition
    && $releaseUploadPosition < $releaseDownloadPosition
    && $releaseDownloadPosition < $permanentEvidencePosition
    && $permanentEvidencePosition < $publicationEvidencePosition
    && $publicationEvidencePosition < $releasePublishPosition
    && $releasePublishPosition < $releaseCleanupPosition,
    'Publish workflow does not verify hidden draft assets before final visibility'
);
$assert(
    str_contains($workflow, 'actions: read')
    && str_contains($workflow, 'Download and validate native release evidence')
    && str_contains($workflow, 'gh run download "$GITHUB_RUN_ID"')
    && str_contains(
        $workflow,
        'artifact_name="publish-evidence-${architecture}-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"'
    )
    && str_contains($workflow, 'verify_architecture_evidence amd64')
    && str_contains($workflow, 'verify_architecture_evidence arm64')
    && str_contains($workflow, 'gh attestation trusted-root')
    && substr_count(
        $workflow,
        '$GITHUB_REPOSITORY/.github/workflows/publish-images.yml'
    ) >= 4
    && str_contains($workflow, '--format=json >"$online_result"')
    && str_contains($workflow, "jq -c '.[].attestation'")
    && str_contains($workflow, '--bundle "$bundle"')
    && str_contains($workflow, '--custom-trusted-root')
    && str_contains($workflow, '--format=json >"$offline_result"')
    && str_contains($workflow, 'Format: estab-release-evidence-v1')
    && str_contains($workflow, 'find . -type f')
    && str_contains($workflow, 'evidence_name="$BUNDLE_NAME-evidence"')
    && str_contains(
        $workflow,
        'sha256sum --check $BUNDLE_NAME-evidence.tar.gz.sha256'
    )
    && str_contains(
        $workflow,
        'upload_owned_release_asset "$evidence_path"'
    )
    && str_contains(
        $workflow,
        'upload_owned_release_asset "$evidence_checksum_path"'
    )
    && str_contains($workflow, 'sha256sum --check "$evidence_name.tar.gz.sha256"')
    && str_contains($workflow, 'test -s trust/app-online-verification.json')
    && str_contains($workflow, 'test -s trust/migrate-online-verification.json')
    && str_contains($workflow, 'test -s architectures/amd64/app-sbom.json')
    && str_contains($workflow, 'test -s architectures/arm64/app-sbom.json')
    && str_contains(
        $workflow,
        ') == $expected_assets[0]'
    )
    && substr_count(
        $workflow,
        'secrets.ESTAB_RELEASE_POLICY_TOKEN'
    ) === 3
    && str_contains($workflow, 'vars.ESTAB_RELEASE_TAG_RULESET_ID')
    && substr_count(
        $workflow,
        'tools/verify-github-release-policy.sh'
    ) === 4
    && !str_contains($workflow, 'ESTAB_IMMUTABLE_RELEASES_READ_TOKEN')
    && !str_contains($workflow, 'IMMUTABLE_RELEASES_READ_TOKEN')
    && str_contains($workflow, ".immutable == true")
    && str_contains($workflow, 'published_state_verified=false')
    && str_contains(
        $workflow,
        'Published release did not become immutable with the exact assets.'
    )
    && str_contains($workflow, 'gh release verify')
    && str_contains($workflow, 'gh release verify-asset')
    && str_contains($workflow, 'for verified_asset in')
    && str_contains($workflow, '--format json')
    && str_contains($workflow, 'github-policy-after-publish.json')
    && str_contains($workflow, 'github-publication-policy-')
    && str_contains($workflow, 'Dauerhafte Evidence'),
    'Release evidence is not preserved, checksummed, attestation-bound, immutable, and release-visible'
);
$assert(
    is_executable($root . '/deploy/registry/offline-images.sh')
    && is_executable($root . '/tests/static/offline_images.sh')
    && str_contains($offlineHelper, '"$release_verifier" "$script_dir"')
    && substr_count(
        $offlineHelper,
        'skopeo copy --all --preserve-digests'
    ) === 3
    && str_contains($offlineHelper, 'oci-archive:')
    && !str_contains($offlineHelper, 'docker-archive:')
    && str_contains($offlineHelper, 'skopeo manifest-digest')
    && str_contains($offlineHelper, 'platform.architecture == "amd64"')
    && str_contains($offlineHelper, 'platform.architecture == "arm64"')
    && str_contains($offlineHelper, 'actual_digest=$(manifest_digest')
    && str_contains($offlineHelper, '[ "$actual_digest" = "$expected_digest" ]')
    && str_contains($offlineHelper, 'OFFLINE-IMAGES')
    && str_contains($offlineHelper, 'check-mirror:2')
    && !str_contains($offlineHelper, 'mirror:3')
    && str_contains($offlineHelper, 'Compose must contain exactly one image for services.db')
    && str_contains(
        $offlineHelper,
        'database_reference=docker.io/library/mariadb@$database_digest'
    )
    && str_contains($offlineHelper, 'database.oci.tar')
    && str_contains($offlineHelper, 'mirror_database_repository=$verified_prefix/estab-db')
    && str_contains($offlineHelper, 'archive directory contains an unbound entry')
    && str_contains(
        $offlineHelper,
        'archive directory must contain exactly the canonical five files'
    )
    && str_contains($offlineHelper, 'must be an absolute path')
    && str_contains($offlineHelper, 'must not contain control characters')
    && str_contains($offlineHelper, 'contains an unsafe path component')
    && str_contains($offlineHelper, "must not contain ':' because OCI transport paths end at the first colon")
    && str_contains(
        $offlineHelper,
        'registry prefix must start with an explicit registry host'
    )
    && !str_contains($offlineHelper, 'copy_tag_if_absent')
    && !str_contains($offlineHelper, 'staging_tag=')
    && str_contains($offlineHelper, 'mkdir -m 0700 "$archive_directory"')
    && str_contains($offlineHelper, '.estab-export-in-progress')
    && str_contains(
        $offlineHelper,
        'incomplete reserved archive retained for inspection'
    )
    && str_contains($releaseEvidenceGuide, 'Release technisch prüfen')
    && str_contains($releaseEvidenceGuide, 'Admin-Workstation')
    && str_contains($releaseEvidenceGuide, 'gh attestation verify')
    && str_contains($releaseEvidenceGuide, '--signer-workflow')
    && str_contains(
        $releaseEvidenceGuide,
        'skopeo copy --all --preserve-digests'
    )
    && str_contains($releaseEvidenceGuide, 'services.db.image')
    && str_contains($releaseEvidenceGuide, 'database.oci.tar')
    && str_contains($releaseEvidenceGuide, 'Abbruchkriterium')
    && str_contains($offlineStaticTest, 'OFFLINE_BAD_DIGEST=1')
    && str_contains($offlineStaticTest, 'OFFLINE_BAD_DATABASE_DIGEST=1')
    && str_contains($offlineStaticTest, 'OFFLINE_BAD_ARCH=1')
    && str_contains($offlineStaticTest, 'duplicate-database')
    && str_contains($offlineStaticTest, 'extra-entry')
    && str_contains($offlineStaticTest, 'missing-database')
    && str_contains($offlineStaticTest, 'control-target')
    && str_contains($offlineStaticTest, 'colon-transport')
    && str_contains($offlineStaticTest, 'hostless-prefix')
    && str_contains($offlineStaticTest, 'mirror verification trusted a mutable tag')
    && str_contains($offlineStaticTest, 'failed export unexpectedly succeeded')
    && str_contains($offlineStaticTest, 'public-export-parent')
    && str_contains($offlineStaticTest, 'helper still mutates registry tags')
    && str_contains($staticRunner, 'tests/static/offline_images.sh'),
    'Offline multi-architecture export and registry recovery are not fail-closed or tested'
);
foreach ([$appDockerfile, $migrateDockerfile] as $dockerfile) {
    $assert(
        str_contains($dockerfile, 'org.opencontainers.image.source="https://github.com/e-stab/estab"')
        && str_contains(
            $dockerfile,
            'org.opencontainers.image.licenses="GPL-3.0-only"'
        )
        && str_contains(
            $dockerfile,
            'COPY --chmod=0444 LICENSE /usr/share/licenses/estab/LICENSE'
        ),
        'Published image lacks its OCI source/license metadata or license text'
    );
}
$assert(
    str_contains($integration, 'compose up --detach --pull never')
    && str_contains($integration, 'migrate_status')
    && str_contains($integration, 'exited 0')
    && str_contains($integration, "image inspect --format '{{.Id}}'")
    && str_contains($integration, "inspect --format '{{.Image}}'")
    && str_contains($integration, '/health.php')
    && str_contains($integration, 'compose down --volumes')
    && !str_contains(
        $integration,
        'compose down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1 || true'
    )
    && str_contains($integration, 'remaining_containers')
    && str_contains($integration, 'remaining_volumes')
    && str_contains($integration, 'resources remain for')
    && str_contains($ci, 'tests/integration/registry_compose.sh'),
    'Complete CI does not prove execution and cleanup of the pull-only deployment'
);
$assert(
    str_contains($integration, '.estab-registry-bind.XXXXXX')
    && str_contains($integration, 'verify_default_project_stability')
    && str_contains($integration, 'project-name-release-one')
    && str_contains($integration, 'project-name-release-two')
    && str_contains($integration, 'estab_estab_db')
    && str_contains($integration, 'estab_estab_auth')
    && str_contains($integration, '.estab-ci-bind-storage')
    && str_contains($integration, 'ESTAB_DB_DATA_SOURCE=$bind_db')
    && str_contains($integration, 'ESTAB_APP_DATA_SOURCE=$bind_data')
    && str_contains($integration, 'ESTAB_EXPORT_DATA_SOURCE=$bind_export')
    && str_contains($integration, 'assert_bind_mount')
    && str_contains($integration, '.Type .Source .Destination'),
    'Registry integration does not create, guard, and inspect real host bind mounts'
);
$assert(
    str_contains($integration, 'production_backup=$backup_parent/production-v3')
    && str_contains($integration, 'sh "$backup_operator" "$production_backup"')
    && str_contains($integration, "'estab-full-backup-v3'")
    && substr_count(
        $integration,
        'sh "$backup_verifier" "$production_backup" "${ESTAB_DB_NAME:-estab}"'
    ) >= 2
    && str_contains($integration, 'restore_operator=$repo_root/deploy/registry/restore.sh')
    && str_contains($integration, 'sh "$restore_operator"')
    && str_contains($integration, '--confirm-project "${bind_project}_wrong"')
    && str_contains($integration, '--confirm-project "$bind_project"')
    && str_contains($integration, 'corrupting controlled restore fixtures')
    && str_contains($integration, '.restore-stale')
    && str_contains($integration, 'ESTAB_REGISTRY_BIND_')
    && str_contains($integration, '.estab-bind-data-marker')
    && str_contains($integration, '.estab-bind-export-marker')
    && str_contains($integration, 'database_marker_count')
    && str_contains($integration, 'restored marker content differs')
    && str_contains($integration, 'portable_backup=$backup_parent/named-to-bind-v3')
    && str_contains($integration, 'source backup is not fully named-volume based')
    && str_contains($integration, '--remap-project "$project=$bind_project"')
    && substr_count(
        $integration,
        '--remap-mount-type database:volume=bind'
    ) >= 1
    && str_contains($integration, 'missing mount-type remaps were accepted')
    && str_contains($integration, 'portable database marker differs')
    && str_contains($integration, 'portable file marker checksum differs')
    && str_contains($integration, 'project_resources_empty "$project"'),
    'Registry integration does not prove an exact database/file bind backup and restore'
);
$assert(
    str_contains($restoreRoundtrip, 'storage_mode=${ESTAB_RESTORE_STORAGE_MODE:-named}')
    && str_contains($restoreRoundtrip, '${COMPOSE_PROJECT_NAME}_estab_db')
    && str_contains($restoreRoundtrip, '${COMPOSE_PROJECT_NAME}_estab_data')
    && str_contains($restoreRoundtrip, '${COMPOSE_PROJECT_NAME}_estab_export')
    && str_contains($restoreRoundtrip, 'ESTAB_COMPOSE_FILE')
    && str_contains($restoreRoundtrip, 'validate_bind_storage')
    && str_contains($restoreRoundtrip, 'bind storage guard does not match the CI project')
    && str_contains($restoreRoundtrip, 'bind sources do not match the guarded storage root')
    && str_contains(
        $restoreRoundtrip,
        'find /var/lib/mysql -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +'
    )
    && str_contains(
        $restoreRoundtrip,
        'find /var/www/html/4fdata -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +'
    )
    && str_contains(
        $restoreRoundtrip,
        'find /var/lib/estab/export -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +'
    ),
    'Restore roundtrip does not fail closed before clearing guarded bind storage'
);
$assert(
    str_contains($integration, 'remaining_networks')
    && str_contains($integration, 'temporary bind root remains')
    && str_contains($integration, 'test_completed=1')
    && str_contains($ci, 'ESTAB_REGISTRY_TEMP_PARENT')
    && str_contains($ci, 'validating pull-only registry deployment and bind restore'),
    'Registry bind roundtrip lacks complete resource cleanup or CI wiring'
);
$assert(
    is_executable($root . '/deploy/registry/deploy.sh')
    && is_executable($root . '/deploy/registry/verify-release.sh')
    && str_contains($deploymentOperator, 'Usage: %s check|pull|up')
    && str_contains(
        $deploymentOperator,
        'ESTAB_CONTAINER_CLI=$container_cli "$verify_release" "$script_dir"'
    )
    && str_contains($deploymentOperator, 'compose pull')
    && str_contains(
        $deploymentOperator,
        '"$verify_release" --inspect-images "$script_dir"'
    )
    && str_contains($deploymentOperator, 'compose up --detach')
    && str_contains(
        $deploymentOperator,
        '--env-file "$active_compose_environment"'
    )
    && str_contains($deploymentOperator, '-f "$active_compose_file"')
    && str_contains($deploymentOperator, 'create_runtime_snapshot')
    && str_contains($deploymentOperator, 'snapshot-$snapshot_identity')
    && str_contains($deploymentOperator, 'verify_runtime_secret_snapshot')
    && str_contains($deploymentOperator, 'prune_unreferenced_runtime_snapshots')
    && str_contains(
        $deploymentOperator,
        'retaining referenced private snapshot'
    )
    && str_contains($deploymentOperator, 'verify_no_extended_acl')
    && str_contains($deploymentOperator, "It's Linux mode")
    && str_contains($deploymentOperator, 'compose.override.yaml')
    && str_contains($deploymentOperator, 'running\ healthy')
    && str_contains($deploymentOperator, 'migrate_state')
    && str_contains($deploymentOperator, 'admin_auth_state')
    && str_contains($deploymentOperator, 'admin-auth-init ($admin_auth_state)')
    && str_contains(
        $deploymentOperator,
        'COMPOSE_* process overrides are forbidden'
    )
    && str_contains(
        $deploymentOperator,
        'Compose runtime overrides must not be set in the process environment'
    )
    && str_contains($deploymentOperator, '${ESTAB_DB_DATA_SOURCE+x}')
    && str_contains($deploymentOperator, 'estab-maintenance-lock-$maintenance_lock_project')
    && str_contains($deploymentOperator, 'org.e-stab.maintenance-operation=deploy')
    && str_contains($deploymentOperator, '"$container_cli" run --detach')
    && str_contains($deploymentOperator, '--network none')
    && str_contains($deploymentOperator, '--restart no')
    && str_contains($deploymentOperator, '"$container_cli" container rm --force')
    && str_contains($deploymentOperator, 'maintenance_lock_is_owned')
    && str_contains($deploymentOperator, 'lost before Compose up')
    && str_contains(
        $releaseVerifier,
        'validate_image app "$release_app_image" ghcr.io/e-stab/estab'
    )
    && str_contains(
        $releaseVerifier,
        'validate_image migrator "$release_migrate_image" ghcr.io/e-stab/estab-migrate'
    )
    && str_contains($releaseVerifier, 'ESTAB_APP_IMAGE differs from the verified release record')
    && str_contains($releaseVerifier, 'bound .env.example differs from the verified app image')
    && str_contains($releaseVerifier, 'protected image and Compose assignments must occur exactly once')
    && str_contains($releaseVerifier, 'Compose-canonical lowercase')
    && str_contains($releaseVerifier, 'org.opencontainers.image.version')
    && str_contains($releaseVerifier, 'org.opencontainers.image.revision')
    && str_contains($releaseVerifier, 'org.opencontainers.image.licenses')
    && str_contains($releaseVerifier, 'image_license" = GPL-3.0-only')
    && str_contains($releaseVerifier, 'THIRD_PARTY_NOTICES.md')
    && str_contains($releaseVerifier, 'restore.sh')
    && str_contains($releaseVerifier, 'RELEASE-EVIDENCE.md')
    && str_contains($releaseVerifier, 'offline-images.sh')
    && str_contains($releaseVerifier, 'bound release entry is not a readable regular file')
    && str_contains($registryReadme, 'sh ./deploy.sh check')
    && str_contains($registryReadme, 'sh ./deploy.sh pull')
    && str_contains($registryReadme, 'sh ./deploy.sh up')
    && str_contains(
        $registryReadme,
        'Die Zeilen `ESTAB_APP_IMAGE` und `ESTAB_MIGRATE_IMAGE`'
    )
    && str_contains($staticRunner, 'tests/static/registry_release.sh'),
    'Release deployment is not digest-bound, self-verifying, and admin-init-gated'
);
$assert(
    is_executable($root . '/deploy/registry/backup.sh')
    && str_contains($backupOperator, 'ABSOLUTE_BACKUP_DIRECTORY')
    && str_contains($backupOperator, 'backup target already exists')
    && str_contains($backupOperator, 'mktemp -d "${staging_prefix}XXXXXX"')
    && str_contains($backupOperator, '"$container_cli" stop "$app_container"')
    && str_contains($backupOperator, '"$container_cli" start "$app_container"')
    && str_contains($backupOperator, 'mariadb-dump')
    && str_contains($backupOperator, '/var/www/html/4fdata')
    && str_contains($backupOperator, '/var/lib/estab/export')
    && str_contains($backupOperator, '--volumes-from "${app_container}:z"')
    && !str_contains($backupOperator, '--volumes-from "$app_container"')
    && str_contains($backupOperator, 'backup-format.txt')
    && str_contains($backupOperator, 'estab-full-backup-v3')
    && str_contains($backupOperator, 'storage-sources.txt')
    && str_contains($backupOperator, 'image-references.txt')
    && str_contains($backupOperator, 'release-identity.txt')
    && str_contains($backupOperator, 'image_digest_hex=${image_digest#sha256:}')
    && str_contains($backupOperator, '*[!0123456789abcdef]*')
    && str_contains($backupOperator, '[ "${#image_digest_hex}" -eq 64 ]')
    && str_contains($backupOperator, 'image_digest="sha256:$image_digest_hex"')
    && str_contains($backupOperator, 'sh "$backup_verifier" "$staging_dir" "$database_name"')
    && str_contains($backupOperator, 'publication_lock="${canonical_parent%/}/.estab-backup.lock"')
    && str_contains($backupOperator, 'estab-maintenance-lock-$maintenance_lock_project')
    && str_contains($backupOperator, 'org.e-stab.maintenance-operation=backup')
    && str_contains($backupOperator, '"$container_cli" run --detach')
    && str_contains($backupOperator, '--network none')
    && str_contains($backupOperator, '--restart no')
    && str_contains($backupOperator, '"$container_cli" container rm --force')
    && str_contains($backupOperator, 'maintenance_lock_is_owned')
    && str_contains($backupOperator, 'lost before the application stop')
    && str_contains($backupOperator, 'lost before backup publication')
    && str_contains($backupOperator, 'verify_storage_source_separation')
    && str_contains($backupOperator, 'verify_operator_path_separation "$canonical_parent"')
    && str_contains($backupOperator, 'backup parent overlaps a productive storage source')
    && str_contains($backupOperator, 'productive storage sources are equal, nested, overlapping')
    && str_contains($backupOperator, 'index($1, destination "/") == 1')
    && str_contains($backupOperator, 'if ! mkdir "$publication_lock"; then')
    && str_contains($backupOperator, 'wait_for_healthy app "$app_container"')
    && str_contains($backupOperator, 'wait_for_healthy db "$db_container"')
    && str_contains($backupOperator, 'restart_application')
    && str_contains($backupOperator, 'if ! "$mkdir_cli" "$backup_target"; then')
    && str_contains($backupOperator, 'publication_target_reserved=1')
    && str_contains($backupOperator, 'target-owner.txt')
    && str_contains($backupOperator, '"$move_cli" "$publication_source" "$publication_destination"')
    && !str_contains($backupOperator, '-nT')
    && str_contains($backupOperator, 'atomic no-clobber reservation failed')
    && str_contains($backupOperator, 'reserved no-clobber backup publication could not be proven')
    && str_contains($backupOperator, "trap 'cleanup 129' HUP")
    && str_contains($backupOperator, "trap 'cleanup 130' INT")
    && str_contains($backupOperator, "trap 'cleanup 143' TERM")
    && str_contains($backupOperatorStaticTest, 'v3 manifest does not bind every expected file')
    && str_contains($backupOperatorStaticTest, 'release-identity.txt')
    && str_contains($backupOperatorStaticTest, 'concurrent target creation was overwritten')
    && str_contains($backupOperatorStaticTest, 'foreign target must remain untouched')
    && str_contains($backupOperatorStaticTest, '${app_id}:z')
    && str_contains($staticRunner, 'tests/static/backup_operator.sh'),
    'Operator backup does not fail closed, capture every data source, bind metadata, and publish without clobbering'
);
$assert(
    is_executable($root . '/deploy/registry/restore.sh')
    && str_contains(
        $restoreOperator,
        'Usage: $0 --confirm-project TARGET_PROJECT'
    )
    && str_contains($restoreOperator, '[--remap-project SOURCE=TARGET]')
    && str_contains($restoreOperator, '[--remap-mount-type ROLE:SOURCE=TARGET]')
    && str_contains($restoreOperator, '[--remap-storage ROLE:SOURCE=TARGET]')
    && str_contains($restoreOperator, '[--remap-volume ROLE:SOURCE=TARGET]')
    && str_contains($restoreOperator, '[--allow-runtime-image-id-change]')
    && str_contains($restoreOperator, 'production restore requires the fully bound backup format 2 or 3')
    && str_contains($restoreOperator, 'provide the exact explicit --remap-project SOURCE=TARGET')
    && str_contains($restoreOperator, 'runtime storage mounts do not match')
    && str_contains($restoreOperator, 'runtime image IDs differ from the backup')
    && str_contains($restoreOperator, 'Config.Image references differ from the backup release identity')
    && str_contains($restoreOperator, 'same canonical @sha256 manifest identity')
    && str_contains($restoreOperator, 'mutable or local tags are forbidden')
    && str_contains($restoreOperator, 'format-2 backups cannot authorize runtime image ID changes')
    && str_contains($restoreOperator, 'ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS')
    && substr_count($restoreOperator, 'verify_backup ||') >= 5
    && str_contains($restoreOperator, 'stop_restore_app_at_boundary')
    && str_contains(
        $restoreOperator,
        '"$container_cli" stop "$restore_app_container"'
    )
    && str_contains($restoreOperator, 'destructive_started=1')
    && str_contains(
        $restoreOperator,
        'find /var/www/html/4fdata -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +'
    )
    && str_contains(
        $restoreOperator,
        'find /var/lib/estab/export -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +'
    )
    && str_contains(
        $restoreOperator,
        '"$container_cli" start --attach "$migrate_container"'
    )
    && str_contains($restoreOperator, '--volumes-from "${app_container}:z"')
    && !str_contains($restoreOperator, '--volumes-from "$app_container"')
    && substr_count(
        $restoreOperator,
        '"$container_cli" run --rm --interactive --network none'
    ) >= 2
    && str_contains($restoreOperator, 'migrate_exit_code')
    && str_contains(
        $restoreOperator,
        '"$container_cli" exec -i "$app_container" estab-healthcheck'
    )
    && str_contains($restoreOperator, 'RECOVERY REQUIRED for project')
    && str_contains(
        $restoreOperator,
        'the exact application container ID, project, stopped flag, and non-running lifecycle status are proven'
    )
    && str_contains($restoreOperator, 'estab-maintenance-lock-$confirmed_project')
    && str_contains($restoreOperator, 'org.e-stab.maintenance-operation=restore')
    && str_contains($restoreOperator, '"$container_cli" run --detach')
    && str_contains($restoreOperator, '"$container_cli" container rm --force')
    && str_contains($restoreOperator, 'preserve_maintenance_lock=1')
    && str_contains($restoreOperator, 'retained fail-closed maintenance lock')
    && str_contains($restoreOperator, 'lost before database import')
    && str_contains($restoreOperator, 'lost before file restore')
    && str_contains($restoreOperator, 'verify_storage_source_separation')
    && str_contains($restoreOperator, 'verify_operator_path_separation "$backup_dir"')
    && str_contains(
        $restoreOperator,
        'die "$separation_label overlaps a productive storage source"'
    )
    && str_contains($restoreOperator, '.Destination .RW')
    && str_contains(
        $restoreOperator,
        'verify_mount_read_write database "$db_container" /var/lib/mysql'
    )
    && str_contains($restoreOperator, 'productive storage mount is not explicitly read/write')
    && str_contains($restoreOperator, 'verify_file_mount_writes')
    && str_contains($restoreOperator, '.estab-restore-write-probe.XXXXXX')
    && str_contains($restoreOperator, '--rm --network none --read-only')
    && str_contains($restoreOperator, 'failed the create/write/delete preflight')
    && str_contains($restoreOperator, 'productive storage sources are equal, nested, overlapping')
    && str_contains($restoreOperator, 'index($1, destination "/") == 1')
    && str_contains($restoreOperator, 'verify_runtime_identity')
    && str_contains($restoreOperator, '"$container_cli" start "$db_container"')
    && !str_contains($restoreOperator, 'compose up -d db')
    && str_contains($restoreOperator, 'configured database name is missing or unsafe')
    && str_contains($restoreOperator, 'single_container admin-auth-init')
    && str_contains($restoreOperator, 'admin_auth_exit_code')
    && substr_count($restoreOperator, 'verify_admin_auth_state') >= 3
    && str_contains($restoreOperator, 'admin authentication initializer does not use the current verified app image')
    && str_contains($restoreStaticTest, 'FAKE_READ_ONLY_MOUNT=4fdata')
    && str_contains($restoreStaticTest, 'FAKE_READ_ONLY_MOUNT=database')
    && str_contains($restoreStaticTest, 'FAKE_UNWRITABLE_MOUNTS=1')
    && str_contains($restoreStaticTest, 'FAKE_ADMIN_AUTH_EXIT_CODE=42')
    && str_contains($restoreStaticTest, 'Named-to-Bind change without type remaps accepted')
    && str_contains($restoreStaticTest, 'incorrect mount-type remap accepted')
    && str_contains($restoreStaticTest, 'partial mount-type remaps accepted')
    && str_contains($restoreStaticTest, 'mutable release references authorized a runtime-ID change')
    && str_contains($restoreStaticTest, '--allow-runtime-image-id-change')
    && str_contains($restoreStaticTest, 'database_operation_count')
    && str_contains($staticRunner, 'tests/static/restore_operator.sh'),
    'Operator restore is not explicitly confirmed, metadata-bound, fail-closed, and health-gated'
);
$assert(
    str_contains($backupVerifier, 'sha256sum -c SHA256SUMS')
    && str_contains($backupVerifier, 'shasum -a 256 -c SHA256SUMS')
    && str_contains($backupVerifier, 'gzip -t "$archive"')
    && substr_count($backupVerifier, 'tar -P -tzf "$archive"') >= 1
    && str_contains($backupVerifier, 'tar -P -tvzf "$archive"')
    && str_contains($backupVerifier, "'-- MariaDB dump'")
    && str_contains($backupVerifier, "'-- Dump completed on '")
    && str_contains($backupVerifier, 'expected_manifest_names=')
    && str_contains($backupVerifier, '$created_database" != "$selected_database')
    && str_contains($backupVerifier, '$created_database" != "$expected_database')
    && str_contains($backupVerifier, 'BACKUP_DIRECTORY EXPECTED_DATABASE')
    && str_contains($backupVerifier, 'estab-full-backup-v2')
    && str_contains($backupVerifier, 'estab-full-backup-v3')
    && str_contains($backupVerifier, 'backup-created-utc.txt')
    && str_contains($backupVerifier, 'storage-sources.txt')
    && str_contains($backupVerifier, 'image-references.txt')
    && str_contains($backupVerifier, 'release-identity.txt')
    && str_contains($backupVerifier, 'canonical release identity is invalid')
    && str_contains($backupVerifier, 'format %s contains an unbound or missing directory entry')
    && str_contains($backupVerifierStaticTest, 'estab-full-backup-v3')
    && str_contains($backupVerifierStaticTest, 'unbound format-3 release identity accepted')
    && str_contains($backupRunbook, 'deploy/registry/backup.sh')
    && str_contains($backupRunbook, 'deploy/registry/restore.sh')
    && str_contains($backupRunbook, '--confirm-project estab')
    && str_contains($backupRunbook, 'SHA256SUMS')
    && str_contains($backupRunbook, '`estab-maintenance-lock-<Projektname>`')
    && str_contains($backupRunbook, '`--allow-runtime-image-id-change`')
    && str_contains($backupRunbook, '`--remap-mount-type`')
    && str_contains($backupRunbook, 'Restore-Probe')
    && str_contains($backupRunbook, '`RECOVERY REQUIRED`'),
    'Restore runbook lacks the guarded operator and repeated verification boundaries'
);

echo "registry deployment contract: OK ({$assertions} assertions)\n";
