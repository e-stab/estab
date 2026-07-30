<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/export.php';

$assertions = 0;
function export_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "export security: FAIL: {$message}\n");
        exit(1);
    }
}

export_assert(estab_export_quote_identifier('nv_nachrichten') === '`nv_nachrichten`', 'normal identifier quoted');
export_assert(estab_export_quote_identifier('odd`name') === '`odd``name`', 'backtick escaped');
export_assert(estab_export_filename('nv_nachrichten') === 'nv_nachrichten.csv', 'portable table filename retained');
export_assert(estab_export_filename('../escape') === 'table-' . bin2hex('../escape') . '.csv', 'unsafe filename encoded');
export_assert(estab_export_csv_cell(null) === '\\N', 'NULL marker retained');
foreach ([
    0 => '0',
    42 => '42',
    '12.50' => '12.50',
    " \t1.25e2 " => " \t1.25e2 ",
    'Ärztliche Leitung' => 'Ärztliche Leitung',
    'Text;mit;Semikolon' => 'Text;mit;Semikolon',
    "'=bereits-neutral" => "'=bereits-neutral",
] as $input => $expected) {
    export_assert(
        estab_export_csv_cell($input) === $expected,
        'normal UTF-8, delimiter, apostrophe or numeric CSV value retained'
    );
}
foreach ([
    '=1+1',
    '+123',
    '-123',
    ' +SUM(A1:A2)',
    "\t-2+3",
    '@cmd',
    "\u{00A0}=HYPERLINK(\"https://invalid\")",
    "\u{2003}+123",
    "\u{202F}-123",
] as $formulaLike) {
    export_assert(
        estab_export_csv_cell($formulaLike) === "'" . $formulaLike,
        'formula-like CSV value neutralised'
    );
}
$neutralisedHeaders = estab_export_csv_headers([
    (object) ['name' => '=formula'],
    (object) ['name' => 'normal'],
    (object) ['name' => '-12'],
]);
export_assert(
    $neutralisedHeaders === ["'=formula", 'normal', "'-12"],
    'database-provided CSV headers use the same formula guard'
);
$csvStream = fopen('php://temp', 'w+b');
export_assert($csvStream !== false, 'CSV semantics test stream opened');
if ($csvStream !== false) {
    fputcsv(
        $csvStream,
        estab_export_csv_headers([
            (object) ['name' => '=header'],
            (object) ['name' => 'normal'],
        ]),
        ';',
        '"',
        '',
        "\r\n"
    );
    fputcsv(
        $csvStream,
        array_map(
            'estab_export_csv_cell',
            ['=1+1', 'Text;mit;Semikolon', null, '-42']
        ),
        ';',
        '"',
        '',
        "\r\n"
    );
    rewind($csvStream);
    export_assert(
        stream_get_contents($csvStream)
            === "'=header;normal\r\n"
                . "'=1+1;\"Text;mit;Semikolon\";\\N;'-42\r\n",
        'formula guard preserves semicolon, CRLF, quoting and NULL semantics'
    );
    fclose($csvStream);
}

foreach (['', str_repeat('a', 65), "bad\0name"] as $invalid) {
    $rejected = false;
    try {
        estab_export_quote_identifier($invalid);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    export_assert($rejected, 'invalid identifier rejected');
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'estab-export-test-' . bin2hex(random_bytes(6));
$outside = $base . '-outside.txt';

function export_test_remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        export_test_remove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function export_test_create_run(
    string $base,
    string $runId,
    string $table,
    int $rows,
    string $archiveBytes
): void {
    $directory = $base . DIRECTORY_SEPARATOR . $runId;
    if (!mkdir($directory, 0770, true)) {
        throw new RuntimeException('Could not create export test run');
    }
    $csv = "id;value\r\n1;fixture\r\n";
    file_put_contents($directory . DIRECTORY_SEPARATOR . $table . '.csv', $csv);
    $timestamp = DateTimeImmutable::createFromFormat(
        '!Ymd-His',
        substr($runId, 6, 15)
    );
    if (!$timestamp instanceof DateTimeImmutable) {
        throw new RuntimeException('Invalid export test timestamp');
    }
    $manifest = [
        'format' => 1,
        'created_at' => $timestamp->format(DateTimeInterface::ATOM),
        'database' => 'estab',
        'null_value' => '\\N',
        'delimiter' => ';',
        'tables' => [[
            'table' => $table,
            'file' => $table . '.csv',
            'rows' => $rows,
            'sha256' => hash('sha256', $csv),
        ]],
    ];
    file_put_contents(
        $directory . DIRECTORY_SEPARATOR . 'manifest.json',
        json_encode($manifest, JSON_THROW_ON_ERROR)
    );
    file_put_contents(
        $base . DIRECTORY_SEPARATOR . $runId . '.zip',
        $archiveBytes
    );
}

function export_test_stage_result(
    array $scope,
    string $csv = "id;value\r\n1;fixture\r\n",
    string $archive = "PK\x03\x04staged"
): array {
    file_put_contents(
        $scope['staging_directory'] . DIRECTORY_SEPARATOR . 'nv_test.csv',
        $csv
    );
    file_put_contents(
        $scope['staging_directory'] . DIRECTORY_SEPARATOR . 'manifest.json',
        '{"format":1}'
    );
    file_put_contents(
        $scope['staging_directory'] . DIRECTORY_SEPARATOR
            . $scope['staged_archive_name'],
        $archive
    );
    return [
        'files' => ['nv_test.csv', 'manifest.json'],
        'manifest' => ['test' => true],
    ];
}

try {
    $scope = estab_export_create_staging_scope(
        $base,
        new DateTimeImmutable('2026-07-22 11:50:00'),
        static fn (int $attempt): string => '11111111'
    );
    export_assert(
        is_dir($scope['staging_directory']),
        'private staging directory created'
    );
    export_assert(
        basename($scope['staging_directory'])
            === '.estab-staging-estab-20260722-115000-11111111',
        'staging directory name is hidden and strictly derived'
    );
    $scopeMode = fileperms($scope['staging_directory']);
    export_assert(
        is_int($scopeMode) && ($scopeMode & 0777) === 0700,
        'staging directory is private'
    );
    export_assert(
        is_file($scope['reservation_path'])
            && !file_exists($scope['final_directory'])
            && !file_exists($scope['final_archive']),
        'staging allocation does not expose a canonical run'
    );
    file_put_contents($outside, 'outside-sentinel');
    $tamperedScope = $scope;
    $tamperedScope['staging_directory'] = $outside;
    $tamperedScopeRejected = false;
    try {
        estab_export_cleanup_staging_scope($tamperedScope);
    } catch (EstabExportUnsafePathException) {
        $tamperedScopeRejected = true;
    }
    export_assert(
        $tamperedScopeRejected,
        'cleanup rejects a caller-modified staging path'
    );
    export_assert(
        file_get_contents($outside) === 'outside-sentinel'
            && is_dir($scope['staging_directory']),
        'tampered cleanup touches neither outside target nor owned stage'
    );
    estab_export_cleanup_staging_scope($scope);
    export_assert(
        !file_exists($scope['staging_directory'])
            && !file_exists($scope['reservation_path']),
        'empty staging scope cleaned completely'
    );

    $published = estab_export_run_staged(
        $base,
        static fn (array $scope): array =>
            export_test_stage_result($scope),
        new DateTimeImmutable('2026-07-22 11:51:00'),
        null,
        static fn (int $attempt): string => '22222222'
    );
    $publishedId = 'estab-20260722-115100-22222222';
    export_assert(
        $published['directory']
            === $base . DIRECTORY_SEPARATOR . $publishedId
            && $published['archive']
                === $base . DIRECTORY_SEPARATOR . $publishedId . '.zip'
            && ($published['manifest']['test'] ?? false) === true,
        'completed stage returns only canonical published paths'
    );
    export_assert(
        is_dir($published['directory'])
            && is_file($published['archive'])
            && !file_exists(
                $base . DIRECTORY_SEPARATOR
                . '.estab-staging-' . $publishedId
            )
            && !file_exists(
                $base . DIRECTORY_SEPARATOR
                . '.estab-reservation-' . $publishedId
            ),
        'directory and ZIP publish without private leftovers'
    );
    export_assert(
        !file_exists(
            $published['directory'] . DIRECTORY_SEPARATOR
            . '.estab-archive-' . $publishedId . '.zip'
        ),
        'private archive hard link removed after commit'
    );
    estab_export_delete_run($base, $publishedId);

    $failedId = 'estab-20260722-115200-33333333';
    $builderFailed = false;
    try {
        estab_export_run_staged(
            $base,
            static function (array $scope) use ($outside): array {
                file_put_contents(
                    $scope['staging_directory']
                        . DIRECTORY_SEPARATOR . 'partial.csv.part-deadbeef',
                    'partial'
                );
                symlink(
                    $outside,
                    $scope['staging_directory']
                        . DIRECTORY_SEPARATOR . 'outside-link'
                );
                throw new RuntimeException('injected builder failure');
            },
            new DateTimeImmutable('2026-07-22 11:52:00'),
            null,
            static fn (int $attempt): string => '33333333'
        );
    } catch (RuntimeException $exception) {
        $builderFailed = $exception->getMessage()
            === 'injected builder failure';
    }
    export_assert($builderFailed, 'injected builder failure propagated');
    export_assert(
        !file_exists($base . DIRECTORY_SEPARATOR . $failedId)
            && !file_exists($base . DIRECTORY_SEPARATOR . $failedId . '.zip')
            && !file_exists(
                $base . DIRECTORY_SEPARATOR
                . '.estab-staging-' . $failedId
            )
            && !file_exists(
                $base . DIRECTORY_SEPARATOR
                . '.estab-reservation-' . $failedId
            ),
        'Throwable removes partial files, link and private staging scope'
    );
    export_assert(
        file_get_contents($outside) === 'outside-sentinel',
        'failed staging cleanup never follows its symlink'
    );

    $symlinkId = 'estab-20260722-115300-44444444';
    $symlinkStageRejected = false;
    try {
        estab_export_run_staged(
            $base,
            static function (array $scope) use ($outside): array {
                symlink(
                    $outside,
                    $scope['staging_directory']
                        . DIRECTORY_SEPARATOR . 'nv_test.csv'
                );
                file_put_contents(
                    $scope['staging_directory']
                        . DIRECTORY_SEPARATOR . 'manifest.json',
                    '{"format":1}'
                );
                file_put_contents(
                    $scope['staging_directory'] . DIRECTORY_SEPARATOR
                        . $scope['staged_archive_name'],
                    "PK\x03\x04symlink"
                );
                return [
                    'files' => ['nv_test.csv', 'manifest.json'],
                    'manifest' => [],
                ];
            },
            new DateTimeImmutable('2026-07-22 11:53:00'),
            null,
            static fn (int $attempt): string => '44444444'
        );
    } catch (EstabExportUnsafePathException) {
        $symlinkStageRejected = true;
    }
    export_assert(
        $symlinkStageRejected,
        'symlink cannot be published as a generated CSV'
    );
    export_assert(
        !file_exists($base . DIRECTORY_SEPARATOR . $symlinkId)
            && !file_exists(
                $base . DIRECTORY_SEPARATOR
                . '.estab-staging-' . $symlinkId
            )
            && file_get_contents($outside) === 'outside-sentinel',
        'rejected symlink stage is removed without touching its target'
    );

    $postRenameId = 'estab-20260722-115400-55555555';
    $postRenameFailed = false;
    try {
        estab_export_run_staged(
            $base,
            static fn (array $scope): array =>
                export_test_stage_result($scope),
            new DateTimeImmutable('2026-07-22 11:54:00'),
            static function (string $phase, array $scope): void {
                if ($phase === 'after_directory_publish') {
                    throw new RuntimeException(
                        'injected post-rename failure'
                    );
                }
            },
            static fn (int $attempt): string => '55555555'
        );
    } catch (RuntimeException $exception) {
        $postRenameFailed = $exception->getMessage()
            === 'injected post-rename failure';
    }
    export_assert(
        $postRenameFailed,
        'failure between directory move and ZIP commit propagated'
    );
    export_assert(
        !file_exists($base . DIRECTORY_SEPARATOR . $postRenameId)
            && !file_exists(
                $base . DIRECTORY_SEPARATOR . $postRenameId . '.zip'
            )
            && !file_exists(
                $base . DIRECTORY_SEPARATOR
                . '.estab-reservation-' . $postRenameId
            ),
        'post-rename failure rolls the unpublished run back completely'
    );

    $raceId = 'estab-20260722-115500-66666666';
    $raceArchive = $base . DIRECTORY_SEPARATOR . $raceId . '.zip';
    $raceRejected = false;
    try {
        estab_export_run_staged(
            $base,
            static fn (array $scope): array =>
                export_test_stage_result($scope),
            new DateTimeImmutable('2026-07-22 11:55:00'),
            static function (string $phase, array $scope): void {
                if ($phase === 'after_directory_publish') {
                    file_put_contents(
                        $scope['final_archive'],
                        'concurrent-sentinel'
                    );
                }
            },
            static fn (int $attempt): string => '66666666'
        );
    } catch (RuntimeException) {
        $raceRejected = true;
    }
    export_assert(
        $raceRejected,
        'concurrent final archive collision fails closed'
    );
    export_assert(
        !file_exists($base . DIRECTORY_SEPARATOR . $raceId)
            && file_get_contents($raceArchive) === 'concurrent-sentinel',
        'rollback removes only its run directory and preserves foreign archive'
    );
    unlink($raceArchive);

    $unsafeTokenRejected = false;
    try {
        estab_export_create_staging_scope(
            $base,
            new DateTimeImmutable('2026-07-22 11:56:00'),
            static fn (int $attempt): string => '../bad00'
        );
    } catch (InvalidArgumentException) {
        $unsafeTokenRejected = true;
    }
    export_assert(
        $unsafeTokenRejected,
        'traversal-capable staging token rejected before filesystem use'
    );

    $validId = 'estab-20260722-120000-aaaaaaaa';
    export_assert(
        estab_export_validate_run_id($validId) === $validId,
        'canonical run identifier accepted'
    );
    foreach ([
        '',
        '../' . $validId,
        '/' . $validId,
        $validId . '.zip',
        str_replace('-', '\\', $validId),
        "estab-20260722-120000-aaaaaaa\0",
        'estab-20261322-120000-aaaaaaaa',
        'estab-20260722-120000-AAAA0000',
        ['estab-20260722-120000-aaaaaaaa'],
    ] as $invalidId) {
        $rejected = false;
        try {
            estab_export_validate_run_id($invalidId);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        export_assert($rejected, 'invalid run identifier rejected');
    }

    $olderId = 'estab-20260722-120000-aaaaaaaa';
    $newerId = 'estab-20260722-120100-bbbbbbbb';
    $unsafeId = 'estab-20260722-115900-cccccccc';
    $nestedId = 'estab-20260722-115800-dddddddd';
    export_test_create_run($base, $olderId, 'nv_old', 3, "PK\x03\x04older");
    export_test_create_run($base, $newerId, 'nv_new', 7, "PK\x03\x04newer");
    export_test_create_run($base, $unsafeId, 'nv_unsafe', 1, "PK\x03\x04unsafe");
    export_test_create_run($base, $nestedId, 'nv_nested', 1, "PK\x03\x04nested");

    $collisionManifest = file_get_contents(
        $base . DIRECTORY_SEPARATOR . $olderId
            . DIRECTORY_SEPARATOR . 'manifest.json'
    );
    $collisionArchive = file_get_contents(
        $base . DIRECTORY_SEPARATOR . $olderId . '.zip'
    );
    $collisionRejected = false;
    try {
        estab_export_run_staged(
            $base,
            static function (array $scope): array {
                throw new RuntimeException(
                    'builder must not run for an existing export'
                );
            },
            new DateTimeImmutable('2026-07-22 12:00:00'),
            null,
            static fn (int $attempt): string => 'aaaaaaaa'
        );
    } catch (RuntimeException $exception) {
        $collisionRejected = $exception->getMessage()
            === 'Could not allocate an export staging scope';
    }
    export_assert(
        $collisionRejected,
        'existing run identifier cannot be reallocated'
    );
    export_assert(
        file_get_contents(
            $base . DIRECTORY_SEPARATOR . $olderId
                . DIRECTORY_SEPARATOR . 'manifest.json'
        ) === $collisionManifest
            && file_get_contents(
                $base . DIRECTORY_SEPARATOR . $olderId . '.zip'
            ) === $collisionArchive
            && !file_exists(
                $base . DIRECTORY_SEPARATOR
                . '.estab-reservation-' . $olderId
            ),
        'allocation collision leaves existing export byte-for-byte untouched'
    );

    $innerLink = $base . DIRECTORY_SEPARATOR . $unsafeId
        . DIRECTORY_SEPARATOR . 'outside-link';
    export_assert(
        symlink($outside, $innerLink),
        'inner symlink fixture created'
    );
    $nestedDirectory = $base . DIRECTORY_SEPARATOR . $nestedId
        . DIRECTORY_SEPARATOR . 'unexpected-directory';
    export_assert(
        mkdir($nestedDirectory),
        'nested directory fixture created'
    );
    file_put_contents($nestedDirectory . DIRECTORY_SEPARATOR . 'data.txt', 'nested');

    file_put_contents($base . DIRECTORY_SEPARATOR . 'notes.txt', 'ignore');
    file_put_contents(
        $base . DIRECTORY_SEPARATOR . $newerId . '.zip.part-deadbeef',
        'ignore'
    );
    file_put_contents(
        $base . DIRECTORY_SEPARATOR . 'estab-20261322-120000-eeeeeeee.zip',
        'ignore'
    );
    $outsideArchiveLink = $base . DIRECTORY_SEPARATOR
        . 'estab-20260722-120200-eeeeeeee.zip';
    export_assert(
        symlink($outside, $outsideArchiveLink),
        'outside archive symlink fixture created'
    );

    $runs = estab_export_list_runs($base);
    export_assert(count($runs) === 4, 'only canonical regular archives listed');
    export_assert($runs[0]['id'] === $newerId, 'newest export listed first');
    export_assert($runs[1]['id'] === $olderId, 'second export sorted by timestamp');
    export_assert(
        $runs[0]['archive_size'] === strlen("PK\x03\x04newer"),
        'archive size reported'
    );
    export_assert(
        ($runs[0]['manifest']['table_count'] ?? null) === 1
            && ($runs[0]['manifest']['rows'] ?? null) === 7
            && ($runs[0]['manifest']['tables'][0]['table'] ?? null) === 'nv_new',
        'bounded manifest metadata normalised'
    );
    $safety = [];
    foreach ($runs as $listedRun) {
        $safety[$listedRun['id']] = $listedRun['safe_to_delete'];
    }
    export_assert($safety[$newerId] === true, 'normal export is deletable');
    export_assert($safety[$unsafeId] === false, 'inner symlink blocks deletion');
    export_assert($safety[$nestedId] === false, 'nested directory blocks deletion');

    $resolved = estab_export_resolve_archive_path($base, $newerId);
    export_assert(
        $resolved === realpath($base . DIRECTORY_SEPARATOR . $newerId . '.zip'),
        'regular archive resolved inside export root'
    );
    $opened = estab_export_open_archive($base, $newerId);
    $openedBytes = stream_get_contents($opened['handle']);
    fclose($opened['handle']);
    export_assert($opened['filename'] === $newerId . '.zip', 'download filename canonical');
    export_assert($openedBytes === "PK\x03\x04newer", 'opened archive bytes unchanged');
    export_assert($opened['size'] === strlen($openedBytes), 'opened archive size exact');

    $newerManifestPath = $base . DIRECTORY_SEPARATOR . $newerId
        . DIRECTORY_SEPARATOR . 'manifest.json';
    $validManifestJson = file_get_contents($newerManifestPath);
    export_assert(is_string($validManifestJson), 'valid manifest fixture readable');
    $validManifest = json_decode(
        (string) $validManifestJson,
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    $integrityManifest = array_replace($validManifest, [
        'attachment_integrity' => [
            'scheme' => 'sha256-ingest-v1',
            'files_checked' => true,
            'total' => 3,
            'verified' => 2,
            'legacy_unverifiable' => 1,
            'integrity_errors' => 0,
            'statement' => 'Integrität beim Eingang nicht belegbar',
        ],
    ]);
    file_put_contents(
        $newerManifestPath,
        json_encode($integrityManifest, JSON_THROW_ON_ERROR)
    );
    $normalisedIntegrityManifest = estab_export_read_manifest(
        $base,
        $newerId
    );
    export_assert(
        (
            $normalisedIntegrityManifest['attachment_integrity']
                ['legacy_unverifiable'] ?? null
        ) === 1,
        'attachment integrity manifest was not normalised'
    );
    file_put_contents($newerManifestPath, $validManifestJson);
    $invalidManifests = [
        'malformed JSON' => '{',
        'relative date' => json_encode(
            array_replace($validManifest, ['created_at' => 'now']),
            JSON_THROW_ON_ERROR
        ),
        'invalid database' => json_encode(
            array_replace($validManifest, ['database' => '../estab']),
            JSON_THROW_ON_ERROR
        ),
        'wrong format' => json_encode(
            array_replace($validManifest, ['format' => 2]),
            JSON_THROW_ON_ERROR
        ),
        'non-list tables' => json_encode(
            array_replace($validManifest, [
                'tables' => ['unexpected' => $validManifest['tables'][0]],
            ]),
            JSON_THROW_ON_ERROR
        ),
        'negative rows' => json_encode(
            array_replace($validManifest, [
                'tables' => [array_replace(
                    $validManifest['tables'][0],
                    ['rows' => -1]
                )],
            ]),
            JSON_THROW_ON_ERROR
        ),
        'mismatched filename' => json_encode(
            array_replace($validManifest, [
                'tables' => [array_replace(
                    $validManifest['tables'][0],
                    ['file' => '../escape.csv']
                )],
            ]),
            JSON_THROW_ON_ERROR
        ),
        'invalid hash' => json_encode(
            array_replace($validManifest, [
                'tables' => [array_replace(
                    $validManifest['tables'][0],
                    ['sha256' => 'not-a-sha256']
                )],
            ]),
            JSON_THROW_ON_ERROR
        ),
        'invalid formula prefix' => json_encode(
            array_replace($validManifest, [
                'spreadsheet_formula_prefix' => '=',
            ]),
            JSON_THROW_ON_ERROR
        ),
        'invalid formula triggers' => json_encode(
            array_replace($validManifest, [
                'spreadsheet_formula_triggers' => '=@',
            ]),
            JSON_THROW_ON_ERROR
        ),
        'invented attachment integrity evidence' => json_encode(
            array_replace($validManifest, [
                'attachment_integrity' => [
                    'scheme' => 'sha256-ingest-v1',
                    'files_checked' => true,
                    'total' => 1,
                    'verified' => 1,
                    'legacy_unverifiable' => 0,
                    'integrity_errors' => 1,
                    'statement' =>
                        'Integrität beim Eingang nicht belegbar',
                ],
            ]),
            JSON_THROW_ON_ERROR
        ),
    ];
    foreach ($invalidManifests as $description => $invalidManifestJson) {
        file_put_contents($newerManifestPath, $invalidManifestJson);
        export_assert(
            estab_export_read_manifest($base, $newerId) === null,
            $description . ' manifest rejected'
        );
    }
    file_put_contents(
        $newerManifestPath,
        str_repeat('x', ESTAB_EXPORT_MANIFEST_MAX_BYTES + 1)
    );
    export_assert(
        estab_export_read_manifest($base, $newerId) === null,
        'oversized manifest rejected before decoding'
    );
    $runsWithBrokenManifest = estab_export_list_runs($base);
    export_assert(
        count($runsWithBrokenManifest) === 4
            && $runsWithBrokenManifest[0]['manifest'] === null,
        'one broken manifest does not hide healthy export archives'
    );
    file_put_contents($newerManifestPath, $validManifestJson);
    export_assert(
        estab_export_read_manifest($base, $newerId) !== null,
        'restored strict manifest accepted'
    );

    $invalidResolveRejected = false;
    try {
        estab_export_resolve_archive_path($base, '../escape');
    } catch (InvalidArgumentException) {
        $invalidResolveRejected = true;
    }
    export_assert($invalidResolveRejected, 'traversal archive identifier rejected');

    foreach ([
        'estab-20260722-120300-ffffffff',
        'estab-20260722-120200-eeeeeeee',
    ] as $missingId) {
        $notFound = false;
        try {
            estab_export_resolve_archive_path($base, $missingId);
        } catch (EstabExportNotFoundException) {
            $notFound = true;
        }
        export_assert($notFound, 'missing or symlink archive hidden');
    }

    foreach ([$unsafeId, $nestedId] as $unsafeDeleteId) {
        $unsafeRejected = false;
        try {
            estab_export_delete_run($base, $unsafeDeleteId);
        } catch (EstabExportUnsafePathException) {
            $unsafeRejected = true;
        }
        export_assert($unsafeRejected, 'unsafe export tree deletion refused');
        export_assert(
            is_file($base . DIRECTORY_SEPARATOR . $unsafeDeleteId . '.zip'),
            'unsafe export archive remains untouched'
        );
    }
    export_assert(
        file_get_contents($outside) === 'outside-sentinel',
        'outside symlink target remains untouched'
    );

    $deleted = estab_export_delete_run($base, $olderId);
    export_assert($deleted['id'] === $olderId, 'selected export deletion reported');
    export_assert(
        !file_exists($base . DIRECTORY_SEPARATOR . $olderId)
            && !file_exists($base . DIRECTORY_SEPARATOR . $olderId . '.zip'),
        'selected directory and archive removed'
    );
    export_assert(
        is_dir($base . DIRECTORY_SEPARATOR . $newerId)
            && is_file($base . DIRECTORY_SEPARATOR . $newerId . '.zip'),
        'unselected export remains'
    );
    export_assert(
        file_get_contents($outside) === 'outside-sentinel',
        'outside sentinel unchanged after selective deletion'
    );

    $repeatDeleteNotFound = false;
    try {
        estab_export_delete_run($base, $olderId);
    } catch (EstabExportNotFoundException) {
        $repeatDeleteNotFound = true;
    }
    export_assert($repeatDeleteNotFound, 'repeated deletion reports missing export');
} finally {
    export_test_remove($base);
    export_test_remove($outside);
}

if (class_exists(ZipArchive::class)) {
    $zipBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'estab-export-zip-test-' . bin2hex(random_bytes(6));
    try {
        $zipResult = estab_export_run_staged(
            $zipBase,
            static function (array $scope): array {
                $csvPath = $scope['staging_directory']
                    . DIRECTORY_SEPARATOR . 'nv_zip.csv';
                $manifestPath = $scope['staging_directory']
                    . DIRECTORY_SEPARATOR . 'manifest.json';
                file_put_contents($csvPath, "id;value\r\n1;zip\r\n");
                file_put_contents($manifestPath, '{"format":1}');
                $archivePath = $scope['staging_directory']
                    . DIRECTORY_SEPARATOR . $scope['staged_archive_name'];
                $archive = new ZipArchive();
                if (
                    $archive->open(
                        $archivePath,
                        ZipArchive::CREATE | ZipArchive::EXCL
                    ) !== true
                    || !$archive->addFile($csvPath, 'nv_zip.csv')
                    || !$archive->addFile($manifestPath, 'manifest.json')
                    || !$archive->close()
                ) {
                    throw new RuntimeException(
                        'Could not create ZIP transaction fixture'
                    );
                }
                return [
                    'files' => ['nv_zip.csv', 'manifest.json'],
                    'manifest' => ['zip' => true],
                ];
            },
            new DateTimeImmutable('2026-07-22 11:57:00'),
            null,
            static fn (int $attempt): string => '77777777'
        );
        export_assert(
            is_dir($zipResult['directory'])
                && is_file($zipResult['archive']),
            'real ZIP and run directory published together'
        );
        $publishedZip = new ZipArchive();
        export_assert(
            $publishedZip->open($zipResult['archive']) === true
                && $publishedZip->locateName('nv_zip.csv') !== false
                && $publishedZip->locateName('manifest.json') !== false,
            'atomically published ZIP contains CSV and manifest'
        );
        $publishedZip->close();
        $zipId = basename($zipResult['directory']);
        estab_export_delete_run($zipBase, $zipId);
        export_assert(
            estab_export_list_runs($zipBase) === [],
            'real ZIP transaction remains compatible with managed deletion'
        );
    } finally {
        export_test_remove($zipBase);
    }
}

$controllerSource = file_get_contents(__DIR__ . '/../../4fadm/export.php');
export_assert(is_string($controllerSource), 'export controller source readable');
export_assert(
    str_contains($controllerSource, "adminAction === 'create_export'")
        && str_contains($controllerSource, "adminAction === 'delete_export'"),
    'controller uses an explicit administrative action allowlist'
);
export_assert(
    str_contains($controllerSource, "(string) \$conf_4f['ablage_dir']")
        && str_contains(
            $controllerSource,
            'Integrität beim Eingang nicht belegbar'
        ),
    'database export omits attachment verification or legacy disclosure'
);
export_assert(
    str_contains($controllerSource, 'estab_csrf_require_post($_SERVER, $_POST)')
        && str_contains($controllerSource, 'http_response_code(403)'),
    'create and delete mutations require session CSRF'
);
export_assert(
    substr_count($controllerSource, 'true,') >= 2
        && substr_count($controllerSource, '303') >= 2,
    'successful export mutations use POST-redirect-GET'
);
export_assert(
    str_contains($controllerSource, "header('Content-Type: application/zip')")
        && str_contains($controllerSource, "header('Content-Disposition: attachment; filename=\"'")
        && str_contains($controllerSource, 'fpassthru('),
    'controller streams archives with explicit binary download headers'
);
export_assert(
    strpos($controllerSource, 'estab_export_open_archive(')
        < strpos($controllerSource, 'estab_session_ui_start($_SESSION)'),
    'binary download is handled before shared HTML buffering starts'
);
export_assert(
    !str_contains($controllerSource, '<p>Verzeichnis:')
        && !str_contains($controllerSource, '<p>ZIP-Archiv:'),
    'private container export paths are not rendered'
);
export_assert(
    str_contains($controllerSource, "\$_SESSION['estab_export_flash']")
        && str_contains($controllerSource, 'unset($_SESSION[\'estab_export_flash\'])')
        && str_contains($controllerSource, 'export_query_run_id($_GET, $queryKey)'),
    'success feedback is tied to one consumed POST session flash'
);
export_assert(
    str_contains($controllerSource, "'Manifest lesbar'")
        && str_contains($controllerSource, "'Prüfung nötig'"),
    'archive status avoids an unverified completeness claim'
);
export_assert(
    str_contains($controllerSource, ". ' admin=' . \$adminLogIdentity"),
    'successful create and delete events include the validated admin identity'
);
$exportSource = file_get_contents(__DIR__ . '/../../app/export.php');
export_assert(
    is_string($exportSource)
        && str_contains($exportSource, "'spreadsheet_formula_prefix' => \"'\"")
        && str_contains($exportSource, "'spreadsheet_formula_triggers' => '=+-@'"),
    'new manifests document spreadsheet formula neutralisation'
);

printf("export security: OK (%d assertions)\n", $assertions);
