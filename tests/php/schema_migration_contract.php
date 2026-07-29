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

$compose = $read($root . '/compose.yaml');
$migratorImage = $read($root . '/docker/db/Dockerfile.migrate');
$runner = $read($root . '/docker/db/migrate.sh');
$runtimeMigration = $read($root . '/docker/db/migrations/30-runtime-schema.sql');
$standardMatrixMigration = $read(
    $root . '/docker/db/migrations/40-recipient-matrix-standard.sql'
);
$incidentMigration = $read(
    $root . '/docker/db/migrations/50-global-incidents.sql'
);
$userBlockingMigration = $read(
    $root . '/docker/db/migrations/70-user-account-blocking.sql'
);
$baseline = $read($root . '/docker/db/init/10-schema.sql');
$verify = $read($root . '/docker/db/verify.sql');
$health = $read($root . '/health.php');
$readiness = $read($root . '/app/readiness.php');
$systemStatus = $read($root . '/4fadm/system_status.php');
$schemaIntegration = $read($root . '/tests/integration/schema_migrator.sh');

$assert(
    preg_match('/^\s{2}migrate:\R/m', $compose) === 1,
    'Compose migration service is missing'
);
$assert(
    str_contains($compose, 'condition: service_completed_successfully'),
    'Application is not gated by successful schema migration'
);
$assert(
    str_contains($compose, 'docker/db/Dockerfile.migrate'),
    'Migration image build is not configured'
);
$assert(
    str_contains($migratorImage, 'COPY docker/db/migrate.sh')
    && str_contains($migratorImage, 'COPY docker/db/init/10-schema.sql')
    && str_contains($migratorImage, 'COPY docker/db/migrations/')
    && str_contains($migratorImage, 'COPY docker/db/verify.sql'),
    'Migration image does not contain baseline, runner, versioned SQL, and verification'
);
$assert(
    !str_contains($compose, '/docker-entrypoint-initdb.d/10-schema.sql')
    && str_contains($runner, 'ESTAB_SCHEMA_BASELINE_FILE')
    && str_contains($runner, 'Fresh schema initialization blocked: partial nv_* tables already exist')
    && str_contains($runner, 'sha256sum "$ESTAB_SCHEMA_BASELINE_FILE"')
    && str_contains($runner, 'CREATE TABLE IF NOT EXISTS estab_schema_baselines')
    && str_contains($runner, 'Retrying interrupted fresh schema baseline')
    && str_contains($runner, 'Checksum mismatch for fresh schema baseline')
    && str_contains($runner, 'expected 14 runtime tables')
    && str_contains($runner, 'database_apply < "$ESTAB_SCHEMA_BASELINE_FILE"'),
    'Fresh installation lacks a checksum-ledgered, retryable embedded baseline'
);
$assert(
    str_contains($schemaIntegration, 'estab_baseline_retry_test_')
    && str_contains(
        $schemaIntegration,
        "('10-schema.sql', '\$baseline_checksum', 'applying'"
    )
    && str_contains($schemaIntegration, 'interrupted baseline was not retried and recorded')
    && str_contains($schemaIntegration, 'untracked partial namespace was accepted')
    && str_contains($schemaIntegration, 'partial namespace guard modified the blocked database'),
    'Integration tests do not prove baseline retry and the untracked-partial guard'
);
$assert(
    str_contains($runner, '--defaults-extra-file="$client_defaults"'),
    'MariaDB client does not use a private option file'
);
$assert(
    !str_contains($runner, '--password='),
    'Migration runner exposes the database password in process arguments'
);
$assert(
    str_contains($runner, 'sha256sum "$migration_path"'),
    'Migration files are not checksummed'
);
$assert(
    str_contains($runner, 'sort -V'),
    'Migration files are not ordered by numeric filename version'
);
foreach (['version', 'checksum', 'state', 'run_id', 'applied_at'] as $column) {
    $assert(
        str_contains($runner, $column),
        'Migration ledger is missing ' . $column
    );
}
$assert(
    str_contains($runner, 'Checksum mismatch for applied migration'),
    'Changed applied migrations do not fail closed'
);
$assert(
    str_contains($runner, 'Post-migration schema verification failed')
    && str_contains($runner, 'ESTAB_SCHEMA_VERIFY_FILE'),
    'Migration runner does not gate completion on full schema verification'
);
$assert(
    str_contains($runtimeMigration, 'duplicate nv_anhang.filename'),
    'Runtime migration does not explicitly reject duplicate attachment names'
);
$assert(
    str_contains($runtimeMigration, 'CREATE UNIQUE INDEX `uq_anhang_filename`'),
    'Runtime migration does not enforce unique attachment filenames'
);
$assert(
    str_contains($runtimeMigration, 'CREATE TABLE IF NOT EXISTS `nv_etbtitel`')
    && str_contains($runtimeMigration, 'CREATE TABLE IF NOT EXISTS `nv_tbbtitel`'),
    'Runtime migration does not supply lazily created ETB/TBB title tables'
);
$assert(
    str_contains($runtimeMigration, 'information_schema.tables')
    && str_contains($runtimeMigration, "LEFT(`table_name`, 3) = 'nv_'")
    && str_contains($runtimeMigration, 'ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4'),
    'Runtime migration does not discover and normalise historic eStab tables'
);
$assert(
    !str_contains($baseline, 'nv_empfmtx_standard')
        && str_contains(
            $standardMatrixMigration,
            'CREATE TABLE `nv_empfmtx_standard`'
        )
        && !str_contains(
            $standardMatrixMigration,
            'CREATE TABLE IF NOT EXISTS `nv_empfmtx_standard`'
        )
        && str_contains($standardMatrixMigration, '`mtx_rc2` BINARY(1)')
        && str_contains($standardMatrixMigration, '`mtx_auto` BINARY(1)')
        && str_contains(
            $standardMatrixMigration,
            'UNIQUE KEY `uq_empfmtx_standard_position` (`mtx_x`, `mtx_y`)'
        ),
    'Single standard matrix is not supplied through an additive versioned schema'
);
$assert(
    str_contains($standardMatrixMigration, 'estab_migrate_40_preflight')
        && str_contains(
            $standardMatrixMigration,
            'Standard matrix migration blocked: pre-existing nv_empfmtx_standard table'
        )
        && str_contains(
            $standardMatrixMigration,
            'estab:migration:40-recipient-matrix-standard:v1'
        )
        && str_contains(
            $standardMatrixMigration,
            'Standard matrix migration blocked: owned table content is not resumable'
        )
        && !str_contains($standardMatrixMigration, 'DELETE FROM `nv_empfmtx_standard`')
        && str_contains($standardMatrixMigration, 'START TRANSACTION')
        && str_contains($standardMatrixMigration, 'INSERT INTO `nv_empfmtx_standard`')
        && str_contains($standardMatrixMigration, "(3,1,'cb','S2', 'Stab','ro','t','f')")
        && str_contains($standardMatrixMigration, 'COMMIT'),
    'Standard matrix migration does not fail closed and seed the historical preset'
);
$assert(
    str_contains($schemaIntegration, 'recipient-matrix-standard.txt')
        && str_contains($schemaIntegration, 'preserve-this-table')
        && str_contains(
            $schemaIntegration,
            'empty migration-owned standard matrix was not safely resumed'
        )
        && str_contains(
            $schemaIntegration,
            'modified migration-owned standard matrix was overwritten'
        )
        && str_contains(
            $schemaIntegration,
            'standard recipient matrix differs from the historical 20-cell fixture'
        ),
    'Schema integration omits collision and exact standard-matrix evidence'
);
foreach ([
    'idx_benutzer_funktion_aktiv',
    'idx_anhang_filename_status',
    'idx_anhang_id',
    'idx_anhang_md5hash',
] as $indexName) {
    $assert(
        str_contains($runtimeMigration, $indexName)
        && str_contains($verify, $indexName)
        && str_contains($readiness, $indexName),
        'Migration, verification, and readiness do not agree on ' . $indexName
    );
}

$codeColumns = [
    '01_zeichen',
    '02_zeichen',
    '03_zeichen',
    '14_zeichen',
    '15_quitzeichen',
    'x03_sperruser',
];
foreach ($codeColumns as $column) {
    $definition = '`' . $column . '` VARCHAR(6) NOT NULL DEFAULT \'\'';
    $assert(
        str_contains($runtimeMigration, 'MODIFY COLUMN ' . $definition),
        'Runtime migration does not widen ' . $column
    );
    $assert(
        str_contains($baseline, $definition),
        'Fresh schema does not define six-character ' . $column
    );
}

foreach ([
    '`kuerzel` VARCHAR(6)',
    '`password` VARCHAR(255)',
    '`ip` VARCHAR(45)',
    '`fwdip` VARCHAR(45)',
] as $definition) {
    $assert(
        str_contains($runtimeMigration, $definition),
        'Runtime user schema is missing ' . $definition
    );
}
$assert(
    str_contains($verify, 'runtime_code_widths_ok')
    && str_contains($verify, 'runtime_attachment_indexes_ok')
    && str_contains($verify, 'standard_matrix_row_count_ok')
    && str_contains($verify, 'matrix_flag_targets_ok')
    && str_contains($verify, 'standard_matrix_flag_targets_ok')
    && str_contains($verify, "`mtx_auto` IN ('t', '1')")
    && str_contains($verify, 'schema_migrations_ok'),
    'Database verification omits runtime widths, indexes, standard matrix, or migration ledger'
);
$assert(
    str_contains($incidentMigration, 'CREATE TABLE IF NOT EXISTS `nv_einsaetze`')
        && str_contains($incidentMigration, 'CREATE TABLE IF NOT EXISTS `nv_einsatz_status`')
        && str_contains($incidentMigration, 'CREATE TABLE IF NOT EXISTS `nv_einsatz_ereignisse`')
        && str_contains($incidentMigration, "'LEGACY-IMPORT'")
        && str_contains($incidentMigration, 'estab_incident_for_insert')
        && str_contains($incidentMigration, 'estab_incident_for_update')
        && str_contains($incidentMigration, 'estab_incident_for_delete'),
    'Global incident migration omits model, legacy boundary, or write guards'
);
$assert(
    str_contains($incidentMigration, 'required_operational_tables')
        && str_contains($incidentMigration, "table_type = 'BASE TABLE'")
        && str_contains(
            $incidentMigration,
            'Incident migration blocked: required operational table is missing'
        )
        && str_contains($schemaIntegration, 'estab_incident_guard_test_')
        && str_contains(
            $schemaIntegration,
            'blocked incomplete incident runtime was mutated or recorded'
        ),
    'Incident migration does not reject an incomplete legacy runtime before domain DDL'
);
$assert(
    str_contains($incidentMigration, '`99_lstacc` = `99_lstacc`')
        && str_contains($incidentMigration, '`sich1_zeit` = `sich1_zeit`')
        && str_contains(
            $schemaIntegration,
            'incident backfill changed a historic message last-access timestamp'
        )
        && str_contains(
            $schemaIntegration,
            'incident backfill changed a historic BHP-50 timestamp'
        ),
    'Incident migration can overwrite legacy ON UPDATE timestamps during backfill'
);
$assert(
    str_contains($userBlockingMigration, '`estab_gesperrt`')
        && str_contains($userBlockingMigration, 'TINYINT UNSIGNED NOT NULL DEFAULT 0')
        && str_contains($verify, 'user_blocking_schema_ok')
        && str_contains($readiness, "column_name = 'estab_gesperrt'"),
    'User-account blocking migration is not part of the runtime schema gate'
);
$assert(
    str_contains($verify, 'active_user_assignments_valid_ok')
        && str_contains($verify, 'assignment_user.`aktiv` = 1')
        && str_contains($verify, 'BINARY assignment_matrix.`mtx_fkt`')
        && str_contains($readiness, 'assignment_user.aktiv = 1')
        && str_contains($readiness, 'BINARY assignment_matrix.mtx_fkt'),
    'Readiness does not reject active accounts with a stale function/role pair'
);
$assert(
    str_contains($verify, 'incident_schema_ok')
        && str_contains($verify, 'incident_status_ok')
        && str_contains($verify, 'incident_trigger_boundary_ok')
        && str_contains($verify, 'incident_assignment_ok')
        && str_contains($readiness, "'50-global-incidents.sql'")
        && str_contains($readiness, "'70-user-account-blocking.sql'")
        && str_contains($verify, "'50-global-incidents.sql'")
        && str_contains($verify, "'70-user-account-blocking.sql'")
        && str_contains($verify, 'estab_schema_migrations`) = 5')
        && str_contains($readiness, 'estab_schema_migrations) = 5'),
    'Migration ledger/readiness does not require all five release migrations'
);
$assert(
    str_contains($readiness, "require_once __DIR__ . '/bootstrap.php'")
    && str_contains(
        $readiness,
        "'20-nullable-dates.sql','30-runtime-schema.sql',"
    )
    && str_contains($readiness, "'40-recipient-matrix-standard.sql'")
    && str_contains($readiness, 'FROM nv_empfmtx_standard')
    && str_contains($readiness, "'15_quitzeichen','x03_sperruser'")
    && str_contains($readiness, "column_name = 'fileext'")
    && str_contains($readiness, "column_name = 'id'")
    && str_contains($health, 'estab_readiness_report()')
    && str_contains($systemStatus, 'estab_readiness_report()')
    && str_contains($systemStatus, '$overallReady = $readiness[\'ready\']')
    && str_contains($readiness, 'estab_validate_runtime_configuration()')
    && str_contains($systemStatus, 'Laufzeitkonfiguration')
    && str_contains($systemStatus, 'Schema, Matrix und Migrationen'),
    'Readiness does not gate on configuration, migrations, matrices, runtime codes, or attachment widths'
);

echo "schema migration contract: OK ({$assertions} assertions)\n";
