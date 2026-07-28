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

try {
    $run = estab_export_create_run_directory(
        $base,
        new DateTimeImmutable('2026-07-22 12:00:00')
    );
    export_assert(is_dir($run), 'private run directory created');
    export_assert(
        str_starts_with(basename($run), 'estab-20260722-120000-'),
        'timestamped run name'
    );
    rmdir($run);

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

    file_put_contents($outside, 'outside-sentinel');
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

$controllerSource = file_get_contents(__DIR__ . '/../../4fadm/export.php');
export_assert(is_string($controllerSource), 'export controller source readable');
export_assert(
    str_contains($controllerSource, "adminAction === 'create_export'")
        && str_contains($controllerSource, "adminAction === 'delete_export'"),
    'controller uses an explicit administrative action allowlist'
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

printf("export security: OK (%d assertions)\n", $assertions);
