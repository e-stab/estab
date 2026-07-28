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
$backupVerifier = $read($root . '/deploy/registry/verify-backup.sh');
$trivyIgnore = $read($root . '/.trivyignore.yaml');
$ci = $read($root . '/tests/integration/ci.sh');
$appDockerfile = $read($root . '/Dockerfile');
$migrateDockerfile = $read($root . '/docker/db/Dockerfile.migrate');

$assert(
    !str_contains($registryCompose, 'build:')
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
    str_contains(
        $appDockerfile,
        'php:8.5.8-apache-trixie@sha256:eacc0d98992683cb46e4f8f44b2418a0323855dc8b59d32dc54f7a9b90a966dd'
    ),
    'Application base does not pin the verified PHP multi-architecture index'
);
$assert(
    substr_count($registryCompose, 'condition: service_healthy') === 2
    && str_contains($registryCompose, 'condition: service_completed_successfully'),
    'Registry application start is not gated by healthy DB and successful migration'
);
foreach ([
    '${ESTAB_DB_DATA_SOURCE:-estab_db}:/var/lib/mysql',
    '${ESTAB_APP_DATA_SOURCE:-estab_data}:/var/www/html/4fdata',
    '${ESTAB_EXPORT_DATA_SOURCE:-estab_export}:/var/lib/estab/export',
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
    'ESTAB_REVIEW_OUTGOING_MESSAGES',
    'ESTAB_ALLOW_SELF_REGISTRATION',
    'ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF',
    'ESTAB_TRUST_PROXY_HEADERS',
    'ESTAB_UPLOAD_MAX_BYTES',
] as $environmentName) {
    $assert(
        str_contains($sourceCompose, $environmentName)
        && str_contains($sourceEnvironment, $environmentName)
        && str_contains($registryCompose, $environmentName)
        && str_contains($registryEnvironment, $environmentName),
        'Registry deployment drifted on ' . $environmentName
    );
}
$outgoingReviewComposeSetting =
    'ESTAB_REVIEW_OUTGOING_MESSAGES: ${ESTAB_REVIEW_OUTGOING_MESSAGES:-false}';
$assert(
    str_contains($sourceCompose, $outgoingReviewComposeSetting)
    && str_contains($registryCompose, $outgoingReviewComposeSetting)
    && preg_match(
        '/^ESTAB_REVIEW_OUTGOING_MESSAGES=false$/m',
        $sourceEnvironment
    ) === 1
    && preg_match(
        '/^ESTAB_REVIEW_OUTGOING_MESSAGES=false$/m',
        $registryEnvironment
    ) === 1,
    'Outgoing-message review does not keep the published false default'
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
    && str_contains($registryReadme, '@sha256:')
    && str_contains($registryReadme, 'Vollbackup')
    && str_contains($registryReadme, 'Unvollständigen Publish-Lauf behandeln')
    && str_contains($registryReadme, 'Required Reviewer'),
    'Registry runbook omits architecture, Synology, digest, backup, or release recovery'
);
$assert(
    str_contains($workflow, "on:\n  workflow_dispatch:")
    && !str_contains($workflow, "\n  push:\n")
    && !str_contains($workflow, "\n  pull_request:\n")
    && str_contains($workflow, 'redistribution_cleared')
    && str_contains($workflow, 'ESTAB_CONTAINER_PUBLISH_ENABLED')
    && str_contains($workflow, 'ESTAB_CONTAINER_PUBLISH_REVIEWER_CONFIGURED')
    && str_contains($workflow, 'environment: container-publish')
    && str_contains($workflow, 'Rights and license review has not been confirmed.'),
    'Image publishing is not an explicit rights- and reviewer-gated manual action'
);
$assert(
    str_contains($workflow, 'group: publish-images')
    && !str_contains($workflow, 'github.ref }}')
    && str_contains($workflow, 'RELEASE_REF_TYPE')
    && str_contains($workflow, 'RELEASE_REF_NAME')
    && str_contains($workflow, 'e-stab/estab')
    && str_contains($workflow, 'Refuse existing immutable tags')
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
    && !preg_match('/uses:\s*[^@\s]+@v\d+/m', $ciWorkflow),
    'Standard CI still uses mutable action or PHP CLI references'
);
$assert(
    str_contains($ciWorkflow, 'runner: ubuntu-24.04')
    && str_contains($ciWorkflow, 'runner: ubuntu-24.04-arm')
    && str_contains($ciWorkflow, 'browser: required')
    && str_contains($ciWorkflow, 'browser: skip')
    && str_contains($ciWorkflow, 'Fresh Docker Compose integration (${{ matrix.arch }})')
    && str_contains($workflow, 'runner: ubuntu-24.04')
    && str_contains($workflow, 'runner: ubuntu-24.04-arm')
    && str_contains($workflow, 'Verify release candidate (${{ matrix.arch }})')
    && str_contains($workflow, 'ESTAB_BROWSER_TEST: ${{ matrix.browser }}'),
    'CI or release verification does not execute the container suite natively on amd64 and arm64'
);
$assert(
    str_contains($workflow, 'platforms: linux/amd64,linux/arm64')
    && str_contains($workflow, 'provenance: mode=max')
    && str_contains($workflow, 'sbom: true')
    && substr_count($workflow, 'push: false') === 2
    && substr_count($workflow, 'push: true') === 2
    && str_contains($workflow, 'bash tests/integration/ci.sh')
    && str_contains($workflow, 'gh attestation verify')
    && str_contains($workflow, 'index .SBOM')
    && str_contains($workflow, 'index .Provenance')
    && str_contains($workflow, 'SPDXRef-DOCUMENT')
    && str_contains($workflow, '.packages | type == "array" and length > 0')
    && str_contains($workflow, '.invocation.environment.platform == $platform')
    && str_contains($workflow, 'create-storage-record: false'),
    'Publish workflow omits prebuild, platforms, evidence, or complete release gate'
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
    && !str_contains($workflow, 'org.opencontainers.image.licenses'),
    'Publish workflow omits an image or asserts an unverified license'
);
$assert(
    str_contains($workflow, 'contents: write')
    && str_contains($workflow, 'Build immutable installation bundle')
    && str_contains($workflow, 'deploy/registry/verify-backup.sh')
    && str_contains(
        $workflow,
        's#../../docs/BACKUP-UND-WIEDERHERSTELLUNG.md#BACKUP-UND-WIEDERHERSTELLUNG.md#g'
    )
    && str_contains(
        $workflow,
        's#^backup_verifier=deploy/registry/verify-backup.sh$#backup_verifier=./verify-backup.sh#'
    )
    && str_contains(
        $workflow,
        'https://github.com/e-stab/estab/blob/$GITHUB_SHA/docs/TESTS-UND-MONITORING.md'
    )
    && str_contains($workflow, 'ESTAB_APP_IMAGE=" app')
    && str_contains($workflow, 'ESTAB_MIGRATE_IMAGE=" migrate')
    && str_contains($workflow, 'App-Image: %s@%s')
    && str_contains($workflow, 'Migrator-Image: %s@%s')
    && str_contains($workflow, 'sha256sum -- * .env.example')
    && str_contains($workflow, 'sha256sum "$bundle_name.tar.gz"')
    && !str_contains($workflow, 'sha256sum "$RUNNER_TEMP/$bundle_name.tar.gz"')
    && str_contains($workflow, 'gh release create "$RELEASE_IMAGE_TAG"')
    && str_contains($workflow, 'gh release upload "$RELEASE_IMAGE_TAG"')
    && str_contains($workflow, '"$BUNDLE_NAME.tar.gz.sha256"')
    && str_contains($workflow, 'Refusing to overwrite existing release asset: $asset_name'),
    'Publish workflow does not produce a checksum-bound immutable digest installation bundle'
);
foreach ([$appDockerfile, $migrateDockerfile] as $dockerfile) {
    $assert(
        str_contains($dockerfile, 'org.opencontainers.image.source="https://github.com/e-stab/estab"'),
        'Published image lacks its OCI source label'
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
    && str_contains($integration, '.estab-ci-bind-storage')
    && str_contains($integration, 'ESTAB_DB_DATA_SOURCE=$bind_db')
    && str_contains($integration, 'ESTAB_APP_DATA_SOURCE=$bind_data')
    && str_contains($integration, 'ESTAB_EXPORT_DATA_SOURCE=$bind_export')
    && str_contains($integration, 'assert_bind_mount')
    && str_contains($integration, '.Type .Source .Destination'),
    'Registry integration does not create, guard, and inspect real host bind mounts'
);
$assert(
    str_contains($integration, 'ESTAB_RESTORE_STORAGE_MODE=bind')
    && str_contains($integration, 'tests/integration/restore_roundtrip.sh')
    && str_contains($integration, 'sha256sum --check --strict SHA256SUMS')
    && str_contains($integration, 'ESTAB_REGISTRY_BIND_')
    && str_contains($integration, '.estab-bind-data-marker')
    && str_contains($integration, '.estab-bind-export-marker')
    && str_contains($integration, 'database_marker_count')
    && str_contains($integration, 'restored marker content differs'),
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
    str_contains($backupVerifier, 'sha256sum -c SHA256SUMS')
    && str_contains($backupVerifier, 'shasum -a 256 -c SHA256SUMS')
    && str_contains($backupVerifier, 'gzip -t "$archive"')
    && substr_count($backupVerifier, 'tar -tzf "$archive"') >= 1
    && str_contains($backupVerifier, 'tar -tvzf "$archive"')
    && str_contains($backupVerifier, "'-- MariaDB dump'")
    && str_contains($backupVerifier, "'-- Dump completed on '")
    && str_contains($backupVerifier, 'expected_manifest_names=')
    && str_contains($backupVerifier, '$created_database" != "$selected_database')
    && str_contains($backupRunbook, 'backup_verifier=deploy/registry/verify-backup.sh')
    && substr_count(
        $backupRunbook,
        'if ! sh "$backup_verifier" "$restore_dir"; then'
    ) === 2,
    'Manual restore runbook lacks a mandatory read-only preflight at both destructive boundaries'
);

echo "registry deployment contract: OK ({$assertions} assertions)\n";
