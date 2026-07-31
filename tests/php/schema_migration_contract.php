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
$officialMessageFieldsMigration = $read(
    $root . '/docker/db/migrations/98-official-message-form-fields.sql'
);
$messageListSearchMigration = $read(
    $root . '/docker/db/migrations/99-message-list-search.sql'
);
$sessionPresenceMigration = $read(
    $root . '/docker/db/migrations/100-session-presence.sql'
);
$logbookRulesMigration = $read(
    $root . '/docker/db/migrations/110-etb-tbb-rules.sql'
);
$logbookShiftMigration = $read(
    $root . '/docker/db/migrations/111-logbook-shift-assignment.sql'
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
$officialMessageFieldsSql = $normaliseSql($officialMessageFieldsMigration);
$messageListSearchSql = $normaliseSql($messageListSearchMigration);
$sessionPresenceSql = $normaliseSql($sessionPresenceMigration);
$logbookRulesSql = $normaliseSql($logbookRulesMigration);
$logbookShiftSql = $normaliseSql($logbookShiftMigration);
$baselineSql = $normaliseSql($baseline);
$verifySql = $normaliseSql($verify);
$readinessSql = $normaliseSql(estab_readiness_schema_query());
$etbInsertTriggerStart = strpos(
    $logbookShiftSql,
    'CREATE TRIGGER `estab_etb_bi_einsatz`'
);
$etbAssigneePolicyStart = strpos(
    $logbookShiftSql,
    'IF NEW.`estab_assignee_assignment_id` IS NULL',
    is_int($etbInsertTriggerStart) ? $etbInsertTriggerStart : 0
);
$etbAssigneePolicyEnd = strpos(
    $logbookShiftSql,
    'IF NEW.`estab_message_id` IS NOT NULL',
    is_int($etbAssigneePolicyStart) ? $etbAssigneePolicyStart : 0
);
$etbAssigneePolicySql = is_int($etbAssigneePolicyStart)
    && is_int($etbAssigneePolicyEnd)
    ? substr(
        $logbookShiftSql,
        $etbAssigneePolicyStart,
        $etbAssigneePolicyEnd - $etbAssigneePolicyStart
    )
    : '';
$sqlParenthesesAreBalanced = static function (string $sql): bool {
    $depth = 0;
    $quote = null;
    $length = strlen($sql);
    for ($offset = 0; $offset < $length; $offset++) {
        $character = $sql[$offset];
        if ($quote !== null) {
            if ($character === $quote) {
                if ($offset + 1 < $length && $sql[$offset + 1] === $quote) {
                    $offset++;
                    continue;
                }
                $quote = null;
            }
            continue;
        }
        if ($character === "'" || $character === '"' || $character === '`') {
            $quote = $character;
            continue;
        }
        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
            if ($depth < 0) {
                return false;
            }
        }
    }
    return $quote === null && $depth === 0;
};
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
    $sqlParenthesesAreBalanced($verifySql)
        && $sqlParenthesesAreBalanced($readinessSql),
    'Runtime schema verification SQL has unbalanced parentheses or quotes'
);
$assert(
    $etbAssigneePolicySql !== ''
        && !str_contains($etbAssigneePolicySql, 'account.`aktiv` = 1')
        && str_contains(
            $etbAssigneePolicySql,
            'account.`estab_gesperrt` = 0'
        ),
    'Migration 111 treats session presence as ETB assignee validity'
);
$assert(
    preg_match(
        "/\(\(SELECT COUNT\(\*\) FROM information_schema\.tables "
            . ".*?table_name = 'nv_logbuch_koepfe'.*?\) = 1 AND "
            . "\(SELECT COUNT\(\*\) FROM information_schema\.columns "
            . ".*?\) = 12 AND \(SELECT COUNT\(\*\) FROM "
            . "information_schema\.columns .*?\) = 6\) "
            . "AS `logbook_schema_ok`/s",
        $verifySql
    ) === 1,
    'verify.sql closes logbook_schema_ok before the migration-111 columns'
);
$assert(
    preg_match(
        "/\(\(SELECT COUNT\(\*\) FROM information_schema\.statistics "
            . ".*?\) = 11 AND \(SELECT COUNT\(\*\) FROM "
            . "information_schema\.statistics .*?\) = 9 AND "
            . "\(SELECT COUNT\(\*\) FROM "
            . "information_schema\.referential_constraints .*?\) = 3 AND "
            . "\(SELECT COUNT\(\*\) FROM "
            . "information_schema\.referential_constraints AS relation "
            . ".*?information_schema\.key_column_usage AS key_column "
            . ".*?\) = 5\) AS `logbook_indexes_ok`/s",
        $verifySql
    ) === 1,
    'verify.sql closes logbook_indexes_ok before the migration-111 keys'
);
$assert(
    str_contains($verify, 'runtime_code_widths_ok')
    && str_contains($verify, 'official_message_fields_ok')
    && str_contains($verify, 'message_list_indexes_ok')
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
$officialMessageFieldDefinitions = [
    [
        'baseline' => '`11_rufnummer` VARCHAR(128) NOT NULL DEFAULT \'\'',
        'migration' => 'ADD COLUMN `11_rufnummer` VARCHAR(128)',
        'ownership' => 'estab:migration:98:message-counterparty-number:v1',
    ],
    [
        'baseline' => '`12_betreff` VARCHAR(255) NOT NULL DEFAULT \'\'',
        'migration' => 'ADD COLUMN `12_betreff` VARCHAR(255)',
        'ownership' => 'estab:migration:98:message-subject:v1',
    ],
];
foreach ($officialMessageFieldDefinitions as $fieldDefinition) {
    $assert(
        str_contains($baseline, $fieldDefinition['baseline'])
            && str_contains($baseline, $fieldDefinition['ownership']),
        'Fresh schema omits canonical official message field '
            . $fieldDefinition['baseline']
    );
    $assert(
        str_contains(
            $officialMessageFieldsMigration,
            $fieldDefinition['migration']
        )
            && str_contains(
                $officialMessageFieldsMigration,
                $fieldDefinition['ownership']
            ),
        'Migration 98 omits canonical official message field '
            . $fieldDefinition['migration']
    );
}
$officialMessageMigrationFragments = [
    "table_name = 'nv_nachrichten' AND table_type = 'BASE TABLE' "
        . "AND engine = 'InnoDB' AND table_collation = 'utf8mb4_unicode_ci'"
        => 'Migration 98 does not require the canonical message table',
    'Official message fields migration blocked: foreign counterparty-number column collision'
        => 'Migration 98 does not reject a foreign counterparty-number column',
    'Official message fields migration blocked: foreign subject column collision'
        => 'Migration 98 does not reject a foreign subject column',
    'Official message fields migration blocked: required legacy form column is missing'
        => 'Migration 98 does not fail explicitly on a damaged legacy form schema',
    'CREATE PROCEDURE estab_migrate_98_add_counterparty_number()'
        => 'Migration 98 has no resumable counterparty-number phase',
    'CREATE PROCEDURE estab_migrate_98_add_subject()'
        => 'Migration 98 has no resumable subject phase',
    'CREATE PROCEDURE estab_migrate_98_validate()'
        => 'Migration 98 has no final schema validator',
    'Historical messages keep'
        => 'Migration 98 no longer documents its no-rewrite policy',
];
foreach ($officialMessageMigrationFragments as $fragment => $message) {
    $assert(str_contains($officialMessageFieldsSql, $fragment), $message);
}
$assert(
    substr_count(
        $officialMessageFieldsMigration,
        'AFTER `10_anschrift`'
    ) === 2
        && substr_count(
            $officialMessageFieldsMigration,
            'AFTER `11_gesprnotiz`'
        ) === 2
        && str_contains(
            $officialMessageFieldsMigration,
            'MODIFY COLUMN `11_rufnummer` VARCHAR(128)'
        )
        && str_contains(
            $officialMessageFieldsMigration,
            'MODIFY COLUMN `12_betreff` VARCHAR(255)'
        ),
    'Migration 98 does not converge missing and existing fields to baseline order'
);
$assert(
    str_contains(
        $officialMessageFieldsSql,
        "number_column.ordinal_position = address_column.ordinal_position + 1"
    )
        && str_contains(
            $officialMessageFieldsSql,
            "note_column.ordinal_position = number_column.ordinal_position + 1"
        )
        && str_contains(
            $officialMessageFieldsSql,
            "subject_column.ordinal_position = note_column.ordinal_position + 1"
        )
        && str_contains(
            $officialMessageFieldsSql,
            "attachment_column.ordinal_position = subject_column.ordinal_position + 1"
        )
        && str_contains(
            $officialMessageFieldsMigration,
            'physical column order differs from baseline'
        ),
    'Migration 98 does not validate the physical field order against the baseline'
);
$officialMessageRuntimeOrderFragments = [
    "column_name ORDER BY ordinal_position SEPARATOR ','",
    "MAX(ordinal_position) - MIN(ordinal_position)",
    "10_anschrift,11_rufnummer,11_gesprnotiz,12_betreff,12_anhang:4",
];
foreach ([
    'verify.sql' => $verifySql,
    'runtime readiness' => $readinessSql,
] as $contractName => $runtimeSchemaContract) {
    foreach ($officialMessageRuntimeOrderFragments as $fragment) {
        $assert(
            str_contains($runtimeSchemaContract, $fragment),
            $contractName . ' does not fail closed on official message field '
                . 'order drift: ' . $fragment
        );
    }
}
$logbookMigrationFragments = [
    'Logbook rules migration blocked: foreign logbook-head table collision'
        => 'Migration 110 does not reject a foreign head-table collision',
    'Logbook rules migration blocked: foreign ETB book-number column collision'
        => 'Migration 110 does not reject a foreign ETB-number collision',
    'Logbook rules migration blocked: foreign or partial TTB column collision'
        => 'Migration 110 does not reject a foreign/partial TTB schema',
    'Logbook rules migration blocked: foreign logbook index collision'
        => 'Migration 110 does not reject foreign logbook indexes',
    'Logbook rules migration blocked: foreign trigger collision'
        => 'Migration 110 does not reject foreign trigger definitions',
    'CREATE TABLE IF NOT EXISTS `nv_logbuch_koepfe`'
        => 'Migration 110 does not create per-incident book heads',
    'estab:migration:110:logbook-heads:v1'
        => 'Migration 110 does not own its book-head table',
    'CREATE TRIGGER `estab_einsaetze_ai_logbook_heads` AFTER INSERT ON `nv_einsaetze`'
        => 'Migration 110 does not create both book heads with each incident',
    "(NEW.`einsatz_id`, 'ETB', 1), (NEW.`einsatz_id`, 'TTB', 1)"
        => 'Migration 110 does not initialise both empty books at number one',
    'ROW_NUMBER() OVER ( PARTITION BY `einsatz_id` ORDER BY `estab_recorded_at`, `etb_lfd-nr` )'
        => 'Migration 110 does not deterministically number historic ETB rows',
    'ROW_NUMBER() OVER ( PARTITION BY `einsatz_id` ORDER BY `estab_recorded_at`, `tbb_lfd-nr` )'
        => 'Migration 110 does not deterministically number historic TTB rows',
    'ADD UNIQUE INDEX `uq_etb_einsatz_book_lfd` (`einsatz_id`, `estab_book_lfd`)'
        => 'Migration 110 does not enforce incident-local ETB numbers',
    'ADD UNIQUE INDEX `uq_etb_attachment_id` (`estab_attachment_id`)'
        => 'Migration 110 does not assign an attachment to at most one ETB row',
    'Logbook rules migration blocked: duplicate ETB attachment link'
        => 'Migration 110 does not block ambiguous historic ETB attachment links',
    'ADD UNIQUE INDEX `uq_tbb_einsatz_book_lfd` (`einsatz_id`, `estab_book_lfd`)'
        => 'Migration 110 does not enforce incident-local TTB numbers',
    '`next_lfd` = LAST_INSERT_ID(`next_lfd` + 1)'
        => 'Migration 110 does not atomically allocate numbers under a head-row write lock',
    'ETB book head is missing'
        => 'Migration 110 does not fail closed when the ETB head is missing',
    'TTB book head is missing'
        => 'Migration 110 does not fail closed when the TTB head is missing',
    'ETB entry type is not permitted'
        => 'Migration 110 does not validate canonical ETB entry kinds',
    'TTB entry type is not permitted'
        => 'Migration 110 does not validate canonical TTB entry kinds',
    'TTB entry requires at least one content area'
        => 'Migration 110 accepts empty operational TTB entries',
    'TTB message link targets another incident'
        => 'Migration 110 does not enforce incident-safe TTB message links',
    'TTB message link requires canonical message entry'
        => 'Migration 110 lets non-message TTB rows link a message',
    'TTB message link requires system-generated evidence'
        => 'Migration 110 does not protect the system-generated message marker',
    'ADD UNIQUE INDEX `idx_tbb_message` (`einsatz_id`, `estab_message_id`)'
        => 'Migration 110 does not make message evidence incident-locally unique',
    'ADD CONSTRAINT `fk_tbb_message`'
        => 'Migration 110 does not constrain TTB message links',
    'TTB entries are append-only; write a correction'
        => 'Migration 110 does not make TTB updates append-only',
    'TTB entries are protected by retention policy'
        => 'Migration 110 does not protect TTB rows from deletion',
    'Logbook rules migration failed: operational locking reads are incomplete'
        => 'Migration 110 does not validate snapshot-safe operational reads',
    "shift_row.`status` IN ('GEPLANT','AKTIV')"
        => 'Migration 110 does not permit a genuine active-shift extension',
    'Active duty shift function was already assigned'
        => 'Migration 110 permits active-shift replacement or reoccupation',
    "BINARY NEW.`funktion` <> BINARY 'A/W'"
        => 'Migration 110 blocks legitimate multi-staffed active A/W extension',
    "SET `estab_entry_type` = 'legacy_import'"
        => 'Migration 110 does not mark imported TTB evidence honestly',
    "CONCAT('Betriebsvorgang: ', `tbb_aktion`)"
        => 'Migration 110 does not retain historic TTB action text',
    "CONCAT('Bemerkung: ', `tbb_bemerk`)"
        => 'Migration 110 does not retain historic TTB remark text',
    'DATE_ADD(`estab_closed_at`, INTERVAL 10 YEAR)'
        => 'Migration 110 does not establish the ten-year retention floor',
    'Formal incident close is irreversible'
        => 'Migration 110 does not restore the irreversible-close rule',
    'Formal incident close evidence is immutable'
        => 'Migration 110 does not restore immutable close evidence',
    'Active incident must be deactivated before close'
        => 'Migration 110 does not restore the active-close guard',
    'CREATE PROCEDURE estab_migrate_110_validate()'
        => 'Migration 110 has no final canonical validator',
];
foreach ($logbookMigrationFragments as $fragment => $message) {
    $assert(str_contains($logbookRulesSql, $fragment), $message);
}
$assert(
    substr_count(
        $logbookRulesSql,
        'WHERE `singleton_id` = 1 FOR UPDATE;'
    ) >= 3,
    'Migration 110 does not replace all three operational functions with locking reads'
);
$triggerMatch = [];
$assert(
    preg_match(
        '/CREATE TRIGGER `estab_etb_bi_einsatz` .*? END\/\//s',
        $logbookRulesSql,
        $triggerMatch
    ) === 1
        && !str_contains($triggerMatch[0] ?? '', 'INSERT INTO `nv_logbuch_koepfe`'),
    'ETB insert trigger still creates a head instead of requiring the incident head'
);
$triggerMatch = [];
$assert(
    preg_match(
        '/CREATE TRIGGER `estab_tbb_bi_einsatz` .*? END\/\//s',
        $logbookRulesSql,
        $triggerMatch
    ) === 1
        && !str_contains($triggerMatch[0] ?? '', 'INSERT INTO `nv_logbuch_koepfe`'),
    'TTB insert trigger still creates a head instead of requiring the incident head'
);
$tbbInsertTrigger = $triggerMatch[0] ?? '';
$assert(
    str_contains(
        $tbbInsertTrigger,
        "BINARY NEW.`estab_entry_type` <> BINARY 'nachricht'"
    )
        && str_contains(
            $tbbInsertTrigger,
            "BINARY COALESCE(NEW.`tbb_kuerzel`, '') <> BINARY 'system'"
        )
        && str_contains(
            $tbbInsertTrigger,
            "BINARY COALESCE(NEW.`tbb_benutzer`, '') <> BINARY 'eStab-System'"
        ),
    'TTB insert trigger does not authenticate canonical message evidence markers'
);
$assert(
    str_contains($logbookRulesSql, "BINARY NEW.`estab_event_type` = BINARY 'ohne'")
        && str_contains($logbookRulesSql, "BINARY NEW.`estab_event_type` = BINARY 'A'")
        && str_contains($logbookRulesSql, "BINARY NEW.`estab_event_type` = BINARY 'B'")
        && str_contains($logbookRulesSql, "BINARY NEW.`estab_event_type` = BINARY 'E'")
        && str_contains($logbookRulesSql, "BINARY NEW.`estab_event_type` = BINARY 'K'")
        && str_contains($logbookRulesSql, "BINARY NEW.`estab_event_type` = BINARY 'W'")
        && str_contains($logbookRulesSql, "BINARY NEW.`estab_event_type` = BINARY 'korrektur'"),
    'Migration 110 does not enforce all official ETB entry kinds exactly'
);
$assert(
    str_contains($logbookRulesSql, "BINARY NEW.`estab_entry_type` = BINARY 'betrieb_personal'")
        && str_contains($logbookRulesSql, "BINARY NEW.`estab_entry_type` = BINARY 'kanal'")
        && str_contains($logbookRulesSql, "BINARY NEW.`estab_entry_type` = BINARY 'nachricht'")
        && str_contains($logbookRulesSql, "BINARY NEW.`estab_entry_type` = BINARY 'betriebsereignis'")
        && str_contains($logbookRulesSql, "BINARY NEW.`estab_entry_type` = BINARY 'quittung'")
        && str_contains($logbookRulesSql, "BINARY NEW.`estab_entry_type` = BINARY 'korrektur'"),
    'Migration 110 does not enforce all official TTB entry kinds exactly'
);
$assert(
    str_contains(
        $logbookRulesMigration,
        'OR BINARY `estab_entry_type` NOT IN ('
    )
        && str_contains(
            $logbookRulesMigration,
            "BINARY `estab_entry_type` <> BINARY 'legacy_import'"
        )
        && str_contains(
            $verify,
            'OR BINARY `estab_entry_type` NOT IN ('
        )
        && str_contains(
            $verify,
            "BINARY `estab_entry_type` <> BINARY 'legacy_import'"
        )
        && str_contains(
            $readiness,
            'OR BINARY estab_entry_type NOT IN ('
        )
        && str_contains(
            $readiness,
            "BINARY estab_entry_type <> BINARY 'legacy_import'"
        )
        && !str_contains(
            $logbookRulesMigration,
            'OR `estab_entry_type` NOT IN ('
        )
        && !str_contains($verify, 'OR `estab_entry_type` NOT IN (')
        && !str_contains($readiness, 'OR estab_entry_type NOT IN ('),
    'Migration, verification, or readiness accepts case-variant TTB types'
);
$logbookShiftMigrationFragments = [
    'Historic rows deliberately stay NULL'
        => 'Migration 111 no longer documents the no-invention history policy',
    'CREATE PROCEDURE estab_migrate_111_preflight()'
        => 'Migration 111 has no collision preflight',
    'Logbook shift migration blocked: foreign column collision'
        => 'Migration 111 does not reject foreign columns',
    'Logbook shift migration blocked: foreign index collision'
        => 'Migration 111 does not reject foreign indexes',
    'Logbook shift migration blocked: foreign constraint collision'
        => 'Migration 111 does not reject foreign constraints',
    'Logbook shift migration blocked: foreign trigger collision'
        => 'Migration 111 does not reject foreign insert triggers',
    'CREATE PROCEDURE estab_migrate_111_add_columns()'
        => 'Migration 111 has no resumable column phase',
    'CREATE PROCEDURE estab_migrate_111_add_indexes()'
        => 'Migration 111 has no resumable index phase',
    'CREATE PROCEDURE estab_migrate_111_add_foreign_keys()'
        => 'Migration 111 has no resumable foreign-key phase',
    'CREATE PROCEDURE estab_migrate_111_validate()'
        => 'Migration 111 has no final validator',
    'estab:migration:111:etb-shift:v1'
        => 'Migration 111 does not own the ETB shift column',
    'estab:migration:111:etb-writer:v1'
        => 'Migration 111 does not own the ETB writer column',
    'estab:migration:111:etb-assignee:v1'
        => 'Migration 111 does not own the ETB assignee column',
    'estab:migration:111:etb-assignment-snapshot:v1'
        => 'Migration 111 does not own the ETB assignment snapshot',
    'estab:migration:111:tbb-shift:v1'
        => 'Migration 111 does not own the TTB shift column',
    'estab:migration:111:tbb-writer:v1'
        => 'Migration 111 does not own the TTB writer column',
    'ADD INDEX `idx_etb_einsatz_shift_book` (`einsatz_id`, `estab_shift_id`, `estab_book_lfd`)'
        => 'Migration 111 does not add the canonical ETB shift index',
    'ADD INDEX `idx_etb_writer_assignment` (`estab_writer_assignment_id`)'
        => 'Migration 111 does not index ETB writer assignments',
    'ADD INDEX `idx_etb_assignee_assignment` (`estab_assignee_assignment_id`)'
        => 'Migration 111 does not index ETB assignee assignments',
    'ADD INDEX `idx_tbb_einsatz_shift_book` (`einsatz_id`, `estab_shift_id`, `estab_book_lfd`)'
        => 'Migration 111 does not add the canonical TTB shift index',
    'ADD INDEX `idx_tbb_writer_assignment` (`estab_writer_assignment_id`)'
        => 'Migration 111 does not index TTB writer assignments',
    'ADD CONSTRAINT `fk_etb_shift` FOREIGN KEY (`estab_shift_id`) REFERENCES `nv_dienstschichten` (`dienstschicht_id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
        => 'Migration 111 does not constrain the ETB shift canonically',
    'ADD CONSTRAINT `fk_etb_writer_assignment` FOREIGN KEY (`estab_writer_assignment_id`) REFERENCES `nv_dienstbesetzungen` (`dienstbesetzung_id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
        => 'Migration 111 does not constrain the ETB writer canonically',
    'ADD CONSTRAINT `fk_etb_assignee_assignment` FOREIGN KEY (`estab_assignee_assignment_id`) REFERENCES `nv_dienstbesetzungen` (`dienstbesetzung_id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
        => 'Migration 111 does not constrain the ETB assignee canonically',
    'ADD CONSTRAINT `fk_tbb_shift` FOREIGN KEY (`estab_shift_id`) REFERENCES `nv_dienstschichten` (`dienstschicht_id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
        => 'Migration 111 does not constrain the TTB shift canonically',
    'ADD CONSTRAINT `fk_tbb_writer_assignment` FOREIGN KEY (`estab_writer_assignment_id`) REFERENCES `nv_dienstbesetzungen` (`dienstbesetzung_id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
        => 'Migration 111 does not constrain the TTB writer canonically',
    'ETB entry requires a duty shift'
        => 'Migration 111 accepts ETB rows without a duty shift',
    'ETB duty shift targets another incident'
        => 'Migration 111 accepts an ETB shift from another incident',
    'Manual ETB entry requires its duty assignment'
        => 'Migration 111 accepts manual ETB rows without a writer assignment',
    'ETB writer does not belong to its duty shift'
        => 'Migration 111 accepts an ETB writer from another shift',
    'ETB writer identity or status is invalid'
        => 'Migration 111 trusts an inactive or mismatched ETB writer',
    'ETB assignee does not belong to its duty shift'
        => 'Migration 111 accepts an ETB assignee from another shift',
    'ETB assignee identity or status is invalid'
        => 'Migration 111 trusts an unaccepted or blocked ETB assignee',
    'ETB assignee snapshot requires an assignment'
        => 'Migration 111 trusts an unbound browser assignee snapshot',
    'SET NEW.`estab_assignment` = assignment_snapshot'
        => 'Migration 111 does not replace browser text with the canonical snapshot',
    'ETB reference must be a canonical local number'
        => 'Migration 111 accepts browser free text as a new ETB reference',
    'ETB reference target is not an earlier incident entry'
        => 'Migration 111 accepts a missing or foreign ETB reference target',
    'ETB correction requires canonical local reference'
        => 'Migration 111 stores a correction reference as a global key',
    'TTB entry requires a duty shift'
        => 'Migration 111 accepts TTB rows without a duty shift',
    'TTB duty shift targets another incident'
        => 'Migration 111 accepts a TTB shift from another incident',
    'Manual TTB entry requires its duty assignment'
        => 'Migration 111 accepts manual TTB rows without a writer assignment',
    'TTB writer does not belong to its duty shift'
        => 'Migration 111 accepts a TTB writer from another shift',
    'TTB writer identity or status is invalid'
        => 'Migration 111 trusts an inactive, mismatched, or non-A/W TTB writer',
    'TTB message entry requires canonical message link'
        => 'Migration 111 accepts a new unlinked TTB message record',
    'estab_log111_handover_insert_time'
        => 'Migration 111 has no completed-handover time guard',
    'Duty handover completion times are inconsistent'
        => 'Migration 111 accepts contradictory completed-handover times',
    'estab_log111_handover_confirm_time'
        => 'Migration 111 has no handover-confirmation time guard',
    'Duty handover confirmation times are inconsistent'
        => 'Migration 111 accepts contradictory confirmation times',
];
foreach ($logbookShiftMigrationFragments as $fragment => $message) {
    $assert(str_contains($logbookShiftSql, $fragment), $message);
}
$assert(
    substr_count($logbookShiftSql, 'BIGINT UNSIGNED NULL DEFAULT NULL') >= 5
        && str_contains(
            $logbookShiftSql,
            '`estab_assignment` VARCHAR(255) CHARACTER SET utf8mb4 '
                . 'COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL'
        ),
    'Migration 111 does not keep all six provenance columns nullable for history'
);
$assert(
    preg_match(
        '/UPDATE `nv_(?:etb|tbb)` SET `estab_(?:shift|writer|assignee|assignment)/',
        $logbookShiftSql
    ) !== 1,
    'Migration 111 invents shift or assignment provenance for historical rows'
);
$shiftTbbTriggerMatch = [];
$assert(
    preg_match(
        '/CREATE TRIGGER `estab_tbb_bi_einsatz` .*? END\/\//s',
        $logbookShiftSql,
        $shiftTbbTriggerMatch
    ) === 1
        && str_contains(
            $shiftTbbTriggerMatch[0] ?? '',
            "BINARY NEW.`estab_entry_type` = BINARY 'nachricht'"
        )
        && str_contains(
            $shiftTbbTriggerMatch[0] ?? '',
            'NEW.`estab_message_id` IS NULL'
        )
        && str_contains(
            $shiftTbbTriggerMatch[0] ?? '',
            'TTB message entry requires canonical message link'
        )
        && !str_contains(
            $logbookShiftSql,
            "UPDATE `nv_tbb` SET `estab_entry_type`"
        ),
    'Migration 111 does not reserve linked message evidence prospectively '
        . 'while preserving historic TTB classifications'
);
$assert(
    str_contains($logbookShiftSql, 'information_schema.key_column_usage')
        && str_contains($logbookShiftSql, 'referenced_column_name'),
    'Migration 111 does not compare foreign-key column mappings canonically'
);
foreach ([
    'verify.sql' => $verifySql,
    'runtime readiness' => $readinessSql,
] as $contractName => $runtimeSchemaContract) {
    foreach ([
        'nv_logbuch_koepfe',
        'estab_book_lfd',
        'estab_personnel_duty',
        'estab_message_route',
        'estab_message_id',
        'estab_operations',
        'uq_etb_einsatz_book_lfd',
        'uq_etb_attachment_id',
        'uq_tbb_einsatz_book_lfd',
        'idx_tbb_einsatz_event_time',
        'idx_tbb_message',
        'fk_tbb_message',
        'fk_tbb_correction',
        '110-etb-tbb-rules.sql',
        'estab_shift_id',
        'estab_writer_assignment_id',
        'estab_assignee_assignment_id',
        'estab_assignment',
        'idx_etb_einsatz_shift_book',
        'idx_etb_writer_assignment',
        'idx_etb_assignee_assignment',
        'idx_tbb_einsatz_shift_book',
        'idx_tbb_writer_assignment',
        'fk_etb_shift',
        'fk_etb_writer_assignment',
        'fk_etb_assignee_assignment',
        'fk_tbb_shift',
        'fk_tbb_writer_assignment',
        'ETB entry requires a duty shift',
        'TTB entry requires a duty shift',
        'ETB writer identity or status is invalid',
        'TTB writer identity or status is invalid',
        'TTB message entry requires canonical message link',
        'estab_log111_handover_insert_time',
        'Duty handover completion times are inconsistent',
        'estab_log111_handover_confirm_time',
        'Duty handover confirmation times are inconsistent',
        'Active shift ETB writer change requires confirmed handover',
        'ETB reference must be a canonical local number',
        'ETB correction requires canonical local reference',
        'SET NEW.`estab_assignment` = assignment_snapshot',
        '111-logbook-shift-assignment.sql',
    ] as $fragment) {
        $assert(
            str_contains($runtimeSchemaContract, $fragment),
            $contractName . ' does not enforce logbook rule: ' . $fragment
        );
    }
    $assert(
        str_contains($runtimeSchemaContract, "routine_definition LIKE '%FOR UPDATE%'"),
        $contractName . ' does not require snapshot-safe operational functions'
    );
    $assert(
        str_contains($runtimeSchemaContract, 'estab_dv94_hat_insert')
            && str_contains(
                $runtimeSchemaContract,
                'Active duty shift function was already assigned'
            )
            && str_contains($runtimeSchemaContract, 'estab_dv94_hat_update')
            && str_contains(
                $runtimeSchemaContract,
                'Active shift ETB writer change requires confirmed handover'
            ),
        $contractName . ' does not require the active-shift extension boundary'
    );
}
$assert(
    preg_match(
        "/index_name = 'idx_tbb_message'\\s+AND non_unique = 0"
            . ".*?seq_in_index = 1 AND column_name = 'einsatz_id'"
            . ".*?seq_in_index = 2 AND column_name = 'estab_message_id'/s",
        $verifySql,
    ) === 1
        && str_contains(
            $verifySql,
            "BINARY COALESCE(`tbb_kuerzel`, '') <> BINARY 'system'"
        )
        && str_contains(
            $verifySql,
            "BINARY COALESCE(`tbb_benutzer`, '') <> BINARY 'eStab-System'"
        ),
    'verify.sql does not validate unique system-generated TTB message evidence'
);
$assert(
    str_contains($readinessSql, 'AND non_unique = 0 AND ((seq_in_index = 1 ')
        && str_contains(
            $readinessSql,
            "BINARY COALESCE(tbb_kuerzel,'') <> BINARY 'system'"
        )
        && str_contains(
            $readinessSql,
            "BINARY COALESCE(tbb_benutzer,'') <> BINARY 'eStab-System'"
        ),
    'Runtime readiness does not validate unique system-generated TTB message evidence'
);
$assert(
    preg_match(
        "/index_name = 'idx_tbb_message'.*?"
            . "column_name = 'estab_message_id'\)\)\)\)\) = 11\) AND "
            . "\(\(SELECT COUNT\(\*\) FROM information_schema\.statistics "
            . ".*?index_name = 'idx_etb_einsatz_shift_book'/s",
        $readinessSql,
    ) === 1
        && preg_match(
            "/BINARY COALESCE\(tbb_benutzer,''\) <> BINARY 'eStab-System'"
                . "\)\)\) = 0\) AND \(\(SELECT COUNT\(\*\) FROM nv_tbb "
                . "AS entry_row/s",
            $readinessSql,
        ) === 1,
    'Runtime readiness accidentally nests independent ETB/TBB schema checks'
);
$assert(
    !str_contains($officialMessageFieldsSql, 'UPDATE `nv_nachrichten`'),
    'Migration 98 rewrites historical message data'
);
$messageListSearchColumns = [
    '05_gegenstelle',
    '10_anschrift',
    '11_rufnummer',
    '12_betreff',
    '12_inhalt',
    '13_abseinheit',
    '14_funktion',
];
$messageListSearchOrder = implode(',', $messageListSearchColumns);
$assert(
    str_contains(
        $baselineSql,
        'FULLTEXT KEY `ft_nachrichten_inhalt` (`12_inhalt`)'
    )
        && !str_contains($baseline, 'ft_nachrichten_suche'),
    'Immutable fresh baseline no longer exposes the released legacy search index'
);
$assert(
    !str_contains($baseline, 'idx_nachrichten_einsatz_status_zeit')
        && !str_contains(
            $baseline,
            'idx_nachrichten_einsatz_richtung_nummer'
        ),
    'Fresh baseline declares incident indexes before migration 50 adds einsatz_id'
);
$messageListMigrationFragments = [
    'Message-list index migration blocked: foreign legacy full-text index collision'
        => 'Migration 99 does not reject a foreign legacy full-text index',
    'Message-list index migration blocked: foreign search full-text index collision'
        => 'Migration 99 does not reject a foreign search full-text index',
    'Message-list index migration blocked: foreign status-time index collision'
        => 'Migration 99 does not reject a foreign status-time index',
    'Message-list index migration blocked: foreign direction-number index collision'
        => 'Migration 99 does not reject a foreign direction-number index',
    'CREATE PROCEDURE estab_migrate_99_add_search()'
        => 'Migration 99 has no resumable full-text phase',
    'CREATE PROCEDURE estab_migrate_99_drop_legacy_search()'
        => 'Migration 99 has no resumable legacy-index removal phase',
    'CREATE PROCEDURE estab_migrate_99_add_status_time()'
        => 'Migration 99 has no resumable status/time-index phase',
    'CREATE PROCEDURE estab_migrate_99_add_direction_number()'
        => 'Migration 99 has no resumable direction/number-index phase',
    'CREATE PROCEDURE estab_migrate_99_validate()'
        => 'Migration 99 has no final schema validator',
    'DROP INDEX `ft_nachrichten_inhalt`'
        => 'Migration 99 does not remove the released single-column index',
    'ADD FULLTEXT INDEX `ft_nachrichten_suche`'
        => 'Migration 99 does not create the canonical full-text index',
    'ADD INDEX `idx_nachrichten_einsatz_status_zeit`'
        => 'Migration 99 does not create the incident/status/time index',
    'ADD INDEX `idx_nachrichten_einsatz_richtung_nummer`'
        => 'Migration 99 does not create the incident/direction/number index',
];
foreach ($messageListMigrationFragments as $fragment => $message) {
    $assert(str_contains($messageListSearchSql, $fragment), $message);
}
$assert(
    strpos(
        $messageListSearchMigration,
        'ADD FULLTEXT INDEX `ft_nachrichten_suche`'
    ) < strpos(
        $messageListSearchMigration,
        'DROP INDEX `ft_nachrichten_inhalt`'
    ),
    'Migration 99 drops the released full-text index before its replacement exists'
);
foreach ([
    'verify.sql' => $verifySql,
    'runtime readiness' => $readinessSql,
] as $contractName => $runtimeSchemaContract) {
    foreach ([
        'ft_nachrichten_inhalt',
        'ft_nachrichten_suche',
        $messageListSearchOrder,
        'idx_nachrichten_einsatz_status_zeit',
        'einsatz_id,x00_status,12_abfzeit,00_lfd',
        'idx_nachrichten_einsatz_richtung_nummer',
        'einsatz_id,04_richtung,04_nummer,00_lfd',
    ] as $fragment) {
        $assert(
            str_contains($runtimeSchemaContract, $fragment),
            $contractName . ' does not enforce message-list index definition: '
                . $fragment
        );
    }
}
$sessionPresenceFragments = [
    'Session-presence migration blocked: user table is missing or incompatible'
        => 'Migration 100 does not require the canonical user table',
    'Session-presence migration blocked: foreign activity column collision'
        => 'Migration 100 does not reject a foreign activity column',
    'Session-presence migration blocked: foreign presence index collision'
        => 'Migration 100 does not reject a foreign presence index',
    'CREATE PROCEDURE estab_migrate_100_add_activity()'
        => 'Migration 100 has no resumable activity-column phase',
    'CREATE PROCEDURE estab_migrate_100_add_index()'
        => 'Migration 100 has no resumable presence-index phase',
    'CREATE PROCEDURE estab_migrate_100_validate()'
        => 'Migration 100 has no final schema validator',
    'estab:migration:100:last-browser-activity-utc:v1'
        => 'Migration 100 does not own its UTC activity column',
    'ADD INDEX `idx_benutzer_presence`'
        => 'Migration 100 does not create the canonical presence index',
    "SET `aktiv` = 0, `sid` = '', `ip` = '', `fwdip` = ''"
        => 'Migration 100 does not revoke unprovable legacy sessions',
];
foreach ($sessionPresenceFragments as $fragment => $message) {
    $assert(str_contains($sessionPresenceSql, $fragment), $message);
}
foreach ([
    'verify.sql' => $verifySql,
    'runtime readiness' => $readinessSql,
] as $contractName => $runtimeSchemaContract) {
    foreach ([
        'estab_letzte_aktivitaet',
        'idx_benutzer_presence',
        'aktiv,estab_gesperrt,estab_letzte_aktivitaet',
        'estab:migration:100:last-browser-activity-utc:v1',
    ] as $fragment) {
        $assert(
            str_contains($runtimeSchemaContract, $fragment),
            $contractName . ' does not enforce session presence: ' . $fragment
        );
    }
}
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
    '98-official-message-form-fields.sql',
    '99-message-list-search.sql',
    '100-session-presence.sql',
    'incident command-post name migration was not canonical or invented history',
    'message-list search indexes were not canonical after migration',
    'partial message-list index migration did not resume canonically',
    'blocked message-list search-index collision was changed or recorded',
    '110-etb-tbb-rules.sql',
    'incident-local ETB/TBB numbering and append-only TTB rules are not canonical',
    'partial logbook migration did not restore empty pre-existing book heads canonically',
    'blocked logbook column collision was changed or recorded',
    '111-logbook-shift-assignment.sql',
    'historical logbook rows did not retain unknown shift provenance',
    'partial logbook shift migration did not resume canonically',
    'blocked logbook shift collision was changed or recorded',
    'ETB entry without a duty shift was not rejected explicitly',
    'TTB entry without a duty shift was not rejected explicitly',
    'ETB duty shift from another incident was not rejected explicitly',
    'TTB duty shift from another incident was not rejected explicitly',
    'ETB writer from another duty shift was not rejected explicitly',
    'TTB writer from another duty shift was not rejected explicitly',
    'ETB assignee from another duty shift was not rejected explicitly',
    'canonical ETB assignment snapshot was not generated by the database',
    'browser ETB assignment text was accepted without an assignment',
    'valid same-shift system and manual logbook entries were not accepted',
    'concurrent ETB/TBB inserts did not allocate complete unique local numbers',
    'new incident did not receive both empty book heads before first entry',
    'first concurrent ETB/TBB entries did not use pre-created book heads',
    'missing ETB head was not rejected explicitly',
    'missing TTB head was not rejected explicitly',
    'MariaDB default snapshot isolation is not enabled for concurrency tests',
    'assert_equal "17"',
] as $marker) {
    $assert(
        str_contains($schemaIntegration, $marker),
        'Schema integration omits ETB migration evidence: ' . $marker
    );
}

foreach ([
    'source compose' => $compose,
    'verify.sql' => $verify,
    'runtime readiness' => $readiness,
] as $contractName => $contractSource) {
    $assert(
        !str_contains($contractSource, 'innodb_snapshot_isolation'),
        $contractName . ' overrides MariaDB snapshot-isolation defaults'
    );
}
$assert(
    !str_contains($schemaIntegration, 'innodb_snapshot_isolation=OFF')
        && str_contains(
            $schemaIntegration,
            '@@GLOBAL.innodb_snapshot_isolation'
        ),
    'Schema integration overrides rather than verifies default snapshot isolation'
);

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
            '(SELECT COUNT(*) FROM `estab_schema_migrations`) = 17'
        )
        && str_contains($verifySql, "'96-etb-duty-function.sql'")
        && str_contains(
            $verifySql,
            "'97-incident-command-post-name.sql'"
        )
        && str_contains(
            $verifySql,
            "'98-official-message-form-fields.sql'"
        )
        && str_contains($verifySql, "'99-message-list-search.sql'")
        && str_contains($verifySql, "'100-session-presence.sql'")
        && str_contains($verifySql, "'110-etb-tbb-rules.sql'")
        && str_contains(
            $verifySql,
            "'111-logbook-shift-assignment.sql'"
        )
        && str_contains($verifySql, ") = 17) AS `schema_migrations_ok`"),
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
            '(SELECT COUNT(*) FROM estab_schema_migrations) = 17'
        )
        && str_contains($readinessSql, "'96-etb-duty-function.sql'")
        && str_contains(
            $readinessSql,
            "'97-incident-command-post-name.sql'"
        )
        && str_contains(
            $readinessSql,
            "'98-official-message-form-fields.sql'"
        )
        && str_contains($readinessSql, "'99-message-list-search.sql'")
        && str_contains($readinessSql, "'100-session-presence.sql'")
        && str_contains($readinessSql, "'110-etb-tbb-rules.sql'")
        && str_contains(
            $readinessSql,
            "'111-logbook-shift-assignment.sql'"
        )
        && str_contains(
            $readinessSql,
            "checksum REGEXP BINARY '^[0-9a-f]{64}$') = 17"
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
        && str_contains(
            $readiness,
            "'98-official-message-form-fields.sql'"
        )
        && str_contains(
            $readiness,
            "'99-message-list-search.sql'"
        )
        && str_contains(
            $readiness,
            "'100-session-presence.sql'"
        )
        && str_contains(
            $readiness,
            "'110-etb-tbb-rules.sql'"
        )
        && str_contains(
            $readiness,
            "'111-logbook-shift-assignment.sql'"
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
        && str_contains($verify, "'98-official-message-form-fields.sql'")
        && str_contains($verify, "'99-message-list-search.sql'")
        && str_contains($verify, "'100-session-presence.sql'")
        && str_contains($verify, "'110-etb-tbb-rules.sql'")
        && str_contains($verify, "'111-logbook-shift-assignment.sql'")
        && str_contains($verify, 'estab_schema_migrations`) = 17')
        && str_contains($readiness, 'estab_schema_migrations) = 17'),
    'Migration ledger/readiness does not require all seventeen release migrations'
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
