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
$baseline = $read($root . '/docker/db/init/10-schema.sql');
$verify = $read($root . '/docker/db/verify.sql');
$health = $read($root . '/health.php');

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
    && str_contains($migratorImage, 'COPY docker/db/migrations/')
    && str_contains($migratorImage, 'COPY docker/db/verify.sql'),
    'Migration image does not contain runner, versioned SQL, and verification'
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
foreach ([
    'idx_benutzer_funktion_aktiv',
    'idx_anhang_filename_status',
    'idx_anhang_id',
    'idx_anhang_md5hash',
] as $indexName) {
    $assert(
        str_contains($runtimeMigration, $indexName)
        && str_contains($verify, $indexName)
        && str_contains($health, $indexName),
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
    && str_contains($verify, 'schema_migrations_ok'),
    'Database verification omits runtime widths, indexes, or migration ledger'
);
$assert(
    str_contains($health, "'20-nullable-dates.sql','30-runtime-schema.sql'")
    && str_contains($health, "'15_quitzeichen','x03_sperruser'")
    && str_contains($health, "column_name = 'fileext'")
    && str_contains($health, "column_name = 'id'"),
    'Readiness does not gate on migrations, runtime codes, or attachment widths'
);

echo "schema migration contract: OK ({$assertions} assertions)\n";
