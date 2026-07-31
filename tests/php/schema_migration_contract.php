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
$incidentPrepareMigration = $read(
    $root . '/docker/db/migrations/45-global-incidents-prepare.sql'
);
$incidentMigration = $read(
    $root . '/docker/db/migrations/50-global-incidents.sql'
);
$incidentFinishMigration = $read(
    $root . '/docker/db/migrations/55-global-incidents-finish.sql'
);
$userBlockingMigration = $read(
    $root . '/docker/db/migrations/70-user-account-blocking.sql'
);
$dvEvidenceMigration = $read(
    $root . '/docker/db/migrations/80-dv-evidence-retention.sql'
);
$dvOperationsMigration = $read(
    $root . '/docker/db/migrations/94-dv-organisational-controls.sql'
);
$attachmentIntegrityMigration = $read(
    $root . '/docker/db/migrations/95-attachment-ingest-integrity.sql'
);
$etbDutyMigration = $read(
    $root . '/docker/db/migrations/96-etb-duty-function.sql'
);
$commandPostMigration = $read(
    $root . '/docker/db/migrations/97-incident-command-post-name.sql'
);
$baseline = $read($root . '/docker/db/init/10-schema.sql');
$verify = $read($root . '/docker/db/verify.sql');
$health = $read($root . '/health.php');
$readiness = $read($root . '/app/readiness.php');
$systemStatus = $read($root . '/4fadm/system_status.php');
$schemaIntegration = $read($root . '/tests/integration/schema_migrator.sh');
require_once $root . '/app/readiness.php';

$normaliseSql = static function (string $sql): string {
    $normalised = preg_replace('/\s+/', ' ', trim($sql));
    if (!is_string($normalised)) {
        throw new RuntimeException('Could not normalise SQL contract source');
    }
    return $normalised;
};
$etbDutySql = $normaliseSql($etbDutyMigration);
$commandPostSql = $normaliseSql($commandPostMigration);
$verifySql = $normaliseSql($verify);
$readinessSql = $normaliseSql(estab_readiness_schema_query());
$oldCapabilityEnum = "enum('LAGE_DOKUMENTATION','SICHTUNG',"
    . "'FERNMELDEPLANUNG','FERNMELDEBETRIEB','BEFOERDERUNG')";
$newCapabilityEnum = "enum('LAGE_DOKUMENTATION','EINSATZTAGEBUCH',"
    . "'SICHTUNG','FERNMELDEPLANUNG','FERNMELDEBETRIEB','BEFOERDERUNG')";
$oldCapabilityEnumLiteral = "'"
    . str_replace("'", "''", $oldCapabilityEnum)
    . "'";
$newCapabilityEnumLiteral = "'"
    . str_replace("'", "''", $newCapabilityEnum)
    . "'";

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
    && str_contains(
        $verify,
        "BINARY `mtx_fkt` = BINARY 'S2'"
    )
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
    str_contains($incidentPrepareMigration, 'estab_migrate_45_prepare_preflight')
        && str_contains($incidentPrepareMigration, 'required_operational_tables')
        && str_contains($incidentPrepareMigration, "table_type = 'BASE TABLE'")
        && str_contains(
            $incidentPrepareMigration,
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
    hash('sha256', $incidentMigration)
        === '6732e9c87f0532fce41ee9a58658bf4888fdf7c2ced1ed6bad75a756d6e08edf',
    'Published global incident migration was changed instead of extended'
);
$assert(
    str_contains($incidentPrepareMigration, 'estab_migrate_45_prepare_validate')
        && str_contains($incidentPrepareMigration, "column_name = '99_lstacc'")
        && str_contains($incidentPrepareMigration, "column_name = 'sich1_zeit'")
        && str_contains($incidentPrepareMigration, "extra = ''")
        && str_contains(
            $incidentPrepareMigration,
            'TIMESTAMP NULL DEFAULT NULL'
        )
        && str_contains($incidentFinishMigration, 'estab_migrate_55_finish_preflight')
        && str_contains($incidentFinishMigration, 'estab_migrate_55_finish_validate')
        && str_contains(
            $incidentFinishMigration,
            'ON UPDATE CURRENT_TIMESTAMP'
        )
        && str_contains(
            $incidentFinishMigration,
            "LOWER(extra) = 'on update current_timestamp()'"
        )
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
    str_contains(
        $dvEvidenceMigration,
        'CREATE TABLE IF NOT EXISTS `nv_nachrichten_ereignisse`'
    )
        && str_contains(
            $dvEvidenceMigration,
            'CREATE TABLE IF NOT EXISTS `nv_nachrichten_nachweiskopf`'
        )
        && str_contains(
            $dvEvidenceMigration,
            'CREATE FUNCTION estab_message_event_hash'
        )
        && str_contains(
            $dvEvidenceMigration,
            'estab_message_events_bu_append_only'
        )
        && str_contains(
            $dvEvidenceMigration,
            'estab_incident_events_bd_append_only'
        )
        && str_contains($dvEvidenceMigration, '`estab_retain_until`')
        && str_contains($dvEvidenceMigration, '`estab_legal_hold`')
        && str_contains($dvEvidenceMigration, '`estab_event_time`')
        && str_contains($dvEvidenceMigration, '`estab_recorded_at`')
        && str_contains($verify, 'dv_evidence_schema_ok')
        && str_contains($verify, 'dv_evidence_boundary_ok')
        && str_contains(
            $readiness,
            "'80-dv-evidence-retention.sql'"
        ),
    'DV evidence, append-only ETB, closure, or retention is outside the schema gate'
);
$assert(
    str_contains(
        $dvOperationsMigration,
        'CREATE TABLE IF NOT EXISTS `nv_dienstschichten`'
    )
        && str_contains(
            $dvOperationsMigration,
            'CREATE TABLE IF NOT EXISTS `nv_dienstbesetzungen`'
        )
        && str_contains(
            $dvOperationsMigration,
            'CREATE TABLE IF NOT EXISTS `nv_dienstuebergaben`'
        )
        && str_contains(
            $dvOperationsMigration,
            'CREATE TABLE IF NOT EXISTS `nv_fernmeldeplaene`'
        )
        && str_contains(
            $dvOperationsMigration,
            'CREATE TABLE IF NOT EXISTS `nv_fernmeldeplan_eintraege`'
        )
        && str_contains(
            $dvOperationsMigration,
            'CREATE TABLE IF NOT EXISTS `nv_melderauftraege`'
        )
        && str_contains(
            $dvOperationsMigration,
            '`estab_fernmeldeplan_eintrag_id`'
        )
        && str_contains(
            $dvOperationsMigration,
            'estab_dv94_message_route_update'
        )
        && str_contains(
            $dvOperationsMigration,
            'estab_dv94_event_update'
        )
        && str_contains(
            $dvOperationsMigration,
            'estab_dv94_event_head_update'
        )
        && str_contains($verify, 'dv_organisation_schema_ok')
        && str_contains($verify, 'dv_organisation_boundary_ok')
        && str_contains(
            $readiness,
            "'94-dv-organisational-controls.sql'"
        ),
    'DV duty, S6, messenger, route, or operational event schema is outside the gate'
);
$etbMigrationFragments = [
    "table_name = 'nv_funktionsfaehigkeiten' AND table_type = 'BASE TABLE' "
        . "AND engine = 'InnoDB' AND table_collation = "
        . "'utf8mb4_unicode_ci' AND table_comment = "
        . "'estab:migration:94-dv-organisational-controls:v1'"
        => 'Migration 96 does not prove ownership of the capability table',
    'IF total_columns <> 4 OR exact_columns <> 4'
        => 'Migration 96 does not require exactly four canonical columns',
    "column_name = 'funktion' AND ordinal_position = 1 "
        . "AND data_type = 'varchar' AND column_type = 'varchar(6)' "
        . "AND character_maximum_length = 6 AND character_set_name = "
        . "'utf8mb4' AND collation_name = 'utf8mb4_unicode_ci' "
        . "AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''"
        => 'Migration 96 does not require the exact function column',
    "column_name = 'rolle' AND ordinal_position = 2 "
        . "AND data_type = 'enum' AND column_type = "
        . "'enum(''Stab'',''FB'',''Fernmelder'')' "
        . "AND character_set_name = 'utf8mb4' "
        . "AND collation_name = 'utf8mb4_unicode_ci' "
        . "AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''"
        => 'Migration 96 does not require the exact role column',
    "column_name = 'faehigkeit' AND ordinal_position = 3 "
        . "AND data_type = 'enum' AND character_set_name = 'utf8mb4' "
        . "AND collation_name = 'utf8mb4_unicode_ci' "
        . "AND is_nullable = 'NO' AND column_default IS NULL AND extra = '' "
        . "AND column_type IN ( {$oldCapabilityEnumLiteral}, "
        . "{$newCapabilityEnumLiteral} )"
        => 'Migration 96 does not limit capability to the old/new exact ENUM',
    "column_name = 'bezeichnung' AND ordinal_position = 4 "
        . "AND data_type = 'varchar' AND column_type = 'varchar(100)' "
        . "AND character_maximum_length = 100 AND character_set_name = "
        . "'utf8mb4' AND collation_name = 'utf8mb4_unicode_ci' "
        . "AND is_nullable = 'NO' AND column_default IS NULL AND extra = ''"
        => 'Migration 96 does not require the exact description column',
    'IF total_primary_parts <> 2 OR exact_primary_parts <> 2'
        => 'Migration 96 does not require the exact two-column primary key',
    "(seq_in_index = 1 AND column_name = 'funktion') OR "
        . "(seq_in_index = 2 AND column_name = 'faehigkeit')"
        => 'Migration 96 does not bind the key to function and capability',
    "index_name = 'uq_funktionsfaehigkeit_eindeutig' "
        . "AND non_unique = 0 AND seq_in_index = 1 "
        . "AND column_name = 'faehigkeit'"
        => 'Migration 96 cannot recognise the old global unique key exactly',
    'total_index_parts <> 2 + expected_unique_parts'
        => 'Migration 96 does not reject foreign capability indexes',
    "column_type = {$oldCapabilityEnumLiteral}"
        => 'Migration 96 no longer recognises the exact predecessor ENUM',
    "column_type = {$newCapabilityEnumLiteral}"
        => 'Migration 96 does not require the exact final ENUM',
    'total_rows = 5 AND base_rows = 5'
        => 'Migration 96 does not recognise the exact five-row predecessor',
    'total_rows = 7 AND final_rows = 7'
        => 'Migration 96 does not recognise the exact seven-row final state',
    "('S2', 'Stab', 'LAGE_DOKUMENTATION', 'Lage und Dokumentation')"
        => 'Migration 96 omits the canonical S2 documentation capability',
    "('S2', 'Stab', 'EINSATZTAGEBUCH', 'Einsatztagebuchführung')"
        => 'Migration 96 omits the S2 ETB capability',
    "('ETB', 'Stab', 'EINSATZTAGEBUCH', 'Einsatztagebuchführung')"
        => 'Migration 96 omits the dedicated ETB duty capability',
    "('Si', 'Stab', 'SICHTUNG', 'Sichter')"
        => 'Migration 96 omits the canonical Si capability',
    "('S6', 'Stab', 'FERNMELDEPLANUNG', 'Fernmeldeplanung')"
        => 'Migration 96 omits the canonical S6 capability',
    "('LdF', 'Fernmelder', 'FERNMELDEBETRIEB', "
        . "'Leiter der Fernmeldezentrale')"
        => 'Migration 96 omits the canonical LdF capability',
    "('A/W', 'Fernmelder', 'BEFOERDERUNG', 'Aufnahme und Weitergabe')"
        => 'Migration 96 omits the canonical A/W capability',
    'IF known_state <> 1'
        => 'Migration 96 does not reject every non-canonical prefix',
    'DROP INDEX `uq_funktionsfaehigkeit_eindeutig`'
        => 'Migration 96 does not remove global capability uniqueness',
    'CREATE PROCEDURE estab_migrate_96_extend_enum()'
        => 'Migration 96 does not isolate its resumable ENUM phase',
    'ON DUPLICATE KEY UPDATE `rolle` = VALUES(`rolle`), '
        . '`bezeichnung` = VALUES(`bezeichnung`)'
        => 'Migration 96 does not resume its exact two-row seed idempotently',
    'CREATE PROCEDURE estab_migrate_96_validate()'
        => 'Migration 96 has no final catalogue validator',
    'SELECT COUNT(*) FROM `nv_funktionsfaehigkeiten` ) <> 7'
        => 'Migration 96 does not validate the final row count',
    "index_name <> 'PRIMARY'"
        => 'Migration 96 does not reject the obsolete or foreign final index',
];
foreach ($etbMigrationFragments as $fragment => $message) {
    $assert(str_contains($etbDutySql, $fragment), $message);
}

$commandPostMigrationFragments = [
    "table_name = 'nv_einsaetze' AND table_type = 'BASE TABLE' "
        . "AND engine = 'InnoDB' AND table_collation = "
        . "'utf8mb4_unicode_ci' AND table_comment = "
        . "'estab:migration:50-global-incidents:v1'"
        => 'Migration 97 does not prove ownership of the incident table',
    "column_name = 'fuehrungsstellenname' AND data_type = 'varchar' "
        . "AND column_type = 'varchar(128)' "
        . "AND character_maximum_length = 128 "
        . "AND character_set_name = 'utf8mb4' "
        . "AND collation_name = 'utf8mb4_unicode_ci' "
        . "AND is_nullable = 'YES' AND ( column_default IS NULL "
        . "OR UPPER(column_default) = 'NULL' )"
        => 'Migration 97 does not require the exact command-post column',
    'estab:migration:97:incident-command-post-name:v1'
        => 'Migration 97 does not mark ownership of its column',
    'estab:migration:97:incident-command-post-lock:v1'
        => 'Migration 97 does not own a durable command-post lock column',
    'estab:migration:97:incident-command-post-write-lock:v1'
        => 'Migration 97 does not own its legacy-writer lock function',
    'BINARY `fuehrungsstellenname` <> BINARY TRIM(`fuehrungsstellenname`)'
        => 'Migration 97 still relies on PAD-SPACE command-post comparison',
    'estab_command_post_incident_insert'
        => 'Migration 97 does not protect direct incident inserts',
    'estab_command_post_incident_update'
        => 'Migration 97 does not protect direct name/lock changes',
    'estab_incident_command_post_for_write'
        => 'Migration 97 does not fail closed and lock legacy writes',
    'Command-post name migration blocked: foreign column collision'
        => 'Migration 97 does not reject a foreign column collision',
    'CREATE PROCEDURE estab_migrate_97_add_column()'
        => 'Migration 97 has no resumable column phase',
    'CREATE PROCEDURE estab_migrate_97_validate()'
        => 'Migration 97 has no final validator',
    'SET @estab_command_post_migration_write = 1;'
        => 'Migration 97 cannot resume its marker backfill behind its own trigger',
    'SET @estab_command_post_migration_write = NULL;'
        => 'Migration 97 leaks its privileged marker-backfill session flag',
    'Existing incidents deliberately remain NULL'
        => 'Migration 97 no longer documents the no-invention legacy policy',
];
foreach ($commandPostMigrationFragments as $fragment => $message) {
    $assert(str_contains($commandPostSql, $fragment), $message);
}
$assert(
    !str_contains(
        $commandPostSql,
        'SET `fuehrungsstellenname` ='
    ),
    'Migration 97 invents a command-post name for historical incidents'
);

foreach ([
    '96-etb-duty-function.sql',
    'partial ETB duty unique-index phase',
    'partial ETB duty enum phase',
    'completed ETB duty catalogue without ledger',
    'mixed ETB duty catalogue',
    'ETB duty primary-key drift',
    '97-incident-command-post-name.sql',
    'incident command-post name migration was not canonical or invented history',
    'assert_equal "12"',
] as $marker) {
    $assert(
        str_contains($schemaIntegration, $marker),
        'Schema integration omits ETB migration evidence: ' . $marker
    );
}

$finalCapabilityTuples = [
    "('S2', 'Stab', 'LAGE_DOKUMENTATION', 'Lage und Dokumentation')",
    "('S2', 'Stab', 'EINSATZTAGEBUCH', 'Einsatztagebuchführung')",
    "('ETB', 'Stab', 'EINSATZTAGEBUCH', 'Einsatztagebuchführung')",
    "('Si', 'Stab', 'SICHTUNG', 'Sichter')",
    "('S6', 'Stab', 'FERNMELDEPLANUNG', 'Fernmeldeplanung')",
    "('LdF', 'Fernmelder', 'FERNMELDEBETRIEB', "
        . "'Leiter der Fernmeldezentrale')",
    "('A/W', 'Fernmelder', 'BEFOERDERUNG', 'Aufnahme und Weitergabe')",
];
foreach ($finalCapabilityTuples as $tuple) {
    $assert(
        str_contains($verifySql, $tuple),
        'verify.sql omits canonical capability tuple ' . $tuple
    );
    $assert(
        str_contains($readinessSql, str_replace(', ', ',', $tuple)),
        'Runtime readiness omits canonical capability tuple ' . $tuple
    );
}
$assert(
    str_contains(
        $verifySql,
        '(SELECT COUNT(*) FROM `nv_funktionsfaehigkeiten`) = 7'
    )
        && str_contains(
            $verifySql,
            "column_name = 'faehigkeit' AND data_type = 'enum' "
                . "AND column_type = {$newCapabilityEnumLiteral} "
                . "AND is_nullable = 'NO'"
        )
        && str_contains(
            $verifySql,
            "index_name = 'PRIMARY' AND non_unique = 0 AND ( "
                . "(seq_in_index = 1 AND column_name = 'funktion') OR "
                . "(seq_in_index = 2 AND column_name = 'faehigkeit') )"
        )
        && str_contains($verifySql, "index_name <> 'PRIMARY') = 0")
        && str_contains(
            $verifySql,
            '(SELECT COUNT(*) FROM `estab_schema_migrations`) = 12'
        )
        && str_contains($verifySql, "'96-etb-duty-function.sql'")
        && str_contains(
            $verifySql,
            "'97-incident-command-post-name.sql'"
        )
        && str_contains($verifySql, ") = 12) AS `schema_migrations_ok`"),
    'verify.sql does not require the exact final ETB catalogue and ledger'
);
$assert(
    str_contains(
        $readinessSql,
        '(SELECT COUNT(*) FROM nv_funktionsfaehigkeiten) = 7'
    )
        && str_contains(
            $readinessSql,
            "column_name = 'faehigkeit' AND data_type = 'enum' "
                . "AND column_type = {$newCapabilityEnumLiteral} "
                . "AND is_nullable = 'NO'"
        )
        && str_contains(
            $readinessSql,
            "index_name = 'PRIMARY' AND non_unique = 0 AND ("
                . "(seq_in_index = 1 AND column_name = 'funktion') OR "
                . "(seq_in_index = 2 AND column_name = 'faehigkeit'))"
        )
        && str_contains($readinessSql, "index_name <> 'PRIMARY') = 0")
        && str_contains(
            $readinessSql,
            '(SELECT COUNT(*) FROM estab_schema_migrations) = 12'
        )
        && str_contains($readinessSql, "'96-etb-duty-function.sql'")
        && str_contains(
            $readinessSql,
            "'97-incident-command-post-name.sql'"
        )
        && str_contains(
            $readinessSql,
            "checksum REGEXP BINARY '^[0-9a-f]{64}$') = 12"
        ),
    'Runtime readiness does not require the exact final ETB catalogue and ledger'
);
$assert(
    str_contains(
        $attachmentIntegrityMigration,
        'ADD COLUMN IF NOT EXISTS'
    )
        && str_contains(
            $attachmentIntegrityMigration,
            '`ingest_sha256`'
        )
        && str_contains(
            $attachmentIntegrityMigration,
            'estab:migration:95:integrity-required:v1'
        )
        && str_contains(
            $attachmentIntegrityMigration,
            'Attachment integrity migration blocked: foreign column collision'
        )
        && str_contains(
            $attachmentIntegrityMigration,
            'Attachment integrity migration blocked: foreign constraint collision'
        )
        && str_contains(
            $attachmentIntegrityMigration,
            'Attachment integrity migration blocked: foreign trigger collision'
        )
        && str_contains(
            $attachmentIntegrityMigration,
            'estab_migrate_95_add_constraints'
        )
        && str_contains(
            $schemaIntegration,
            'deliberate partial attachment-integrity DDL was not reproduced'
        )
        && str_contains(
            $schemaIntegration,
            'partial attachment-integrity migration did not converge canonically'
        )
        && str_contains(
            $schemaIntegration,
            'blocked attachment-integrity collision was changed or recorded'
        )
        && str_contains(
            $schemaIntegration,
            'partial attachment-integrity trigger phase did not resume'
        )
        && str_contains(
            $attachmentIntegrityMigration,
            'New attachment cannot be marked as unverifiable legacy data'
        )
        && str_contains(
            $attachmentIntegrityMigration,
            'Final attachment integrity evidence is immutable'
        )
        && str_contains($verify, 'attachment_integrity_schema_ok')
        && str_contains(
            $readiness,
            "'95-attachment-ingest-integrity.sql'"
        ),
    'Attachment ingest integrity is outside the checksum-pinned schema gate'
);
$assert(
    str_contains($verify, 'active_user_assignments_valid_ok')
        && str_contains($verify, 'assignment_user.`aktiv` = 1')
        && str_contains(
            $verify,
            "BINARY assignment_user.`funktion` = BINARY 'LdF'"
        )
        && str_contains(
            $verify,
            "BINARY assignment_user.`rolle` = BINARY 'Fernmelder'"
        )
        && str_contains($verify, 'BINARY assignment_matrix.`mtx_fkt`')
        && str_contains($readiness, 'assignment_user.aktiv = 1')
        && str_contains(
            $readiness,
            "BINARY assignment_user.funktion = BINARY 'LdF'"
        )
        && str_contains(
            $readiness,
            "BINARY assignment_user.rolle = BINARY 'Fernmelder'"
        )
        && str_contains($readiness, 'BINARY assignment_matrix.mtx_fkt'),
    'Readiness does not reject active accounts with a stale function/role pair'
);
$assert(
    str_contains($verify, 'incident_schema_ok')
        && str_contains($verify, 'incident_status_ok')
        && str_contains($verify, 'incident_trigger_boundary_ok')
        && str_contains($verify, 'incident_assignment_ok')
        && str_contains($readiness, "'50-global-incidents.sql'")
        && str_contains($readiness, "'45-global-incidents-prepare.sql'")
        && str_contains($readiness, "'55-global-incidents-finish.sql'")
        && str_contains($readiness, "'70-user-account-blocking.sql'")
        && str_contains($readiness, "'80-dv-evidence-retention.sql'")
        && str_contains($readiness, "'94-dv-organisational-controls.sql'")
        && str_contains($readiness, "'95-attachment-ingest-integrity.sql'")
        && str_contains($readiness, "'96-etb-duty-function.sql'")
        && str_contains(
            $readiness,
            "'97-incident-command-post-name.sql'"
        )
        && str_contains($verify, "'50-global-incidents.sql'")
        && str_contains($verify, "'45-global-incidents-prepare.sql'")
        && str_contains($verify, "'55-global-incidents-finish.sql'")
        && str_contains($verify, "'70-user-account-blocking.sql'")
        && str_contains($verify, "'80-dv-evidence-retention.sql'")
        && str_contains($verify, "'94-dv-organisational-controls.sql'")
        && str_contains($verify, "'95-attachment-ingest-integrity.sql'")
        && str_contains($verify, "'96-etb-duty-function.sql'")
        && str_contains($verify, "'97-incident-command-post-name.sql'")
        && str_contains($verify, 'estab_schema_migrations`) = 12')
        && str_contains($readiness, 'estab_schema_migrations) = 12'),
    'Migration ledger/readiness does not require all twelve release migrations'
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
