<?php

declare(strict_types=1);

const ESTAB_EXPORT_MANIFEST_MAX_BYTES = 2097152;

final class EstabExportNotFoundException extends RuntimeException
{
}

final class EstabExportUnsafePathException extends RuntimeException
{
}

/** Quote a database identifier obtained from MariaDB's own catalogue. */
function estab_export_quote_identifier(string $identifier): string
{
    if ($identifier === '' || strlen($identifier) > 64 || str_contains($identifier, "\0")) {
        throw new InvalidArgumentException('Invalid database identifier');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/** Map a table name to a portable, collision-resistant CSV filename. */
function estab_export_filename(string $table): string
{
    if (preg_match('/\A[A-Za-z0-9_.-]+\z/D', $table) === 1) {
        return $table . '.csv';
    }
    return 'table-' . bin2hex($table) . '.csv';
}

/** Validate the symbolic identifier generated for one completed export run. */
function estab_export_validate_run_id(mixed $runId): string
{
    if (
        !is_string($runId)
        || preg_match(
            '/\Aestab-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\z/D',
            $runId
        ) !== 1
    ) {
        throw new InvalidArgumentException('Invalid export run identifier');
    }

    $timestamp = substr($runId, 6, 15);
    $createdAt = DateTimeImmutable::createFromFormat('!Ymd-His', $timestamp);
    if (
        !$createdAt instanceof DateTimeImmutable
        || $createdAt->format('Ymd-His') !== $timestamp
    ) {
        throw new InvalidArgumentException('Invalid export run timestamp');
    }
    return $runId;
}

/** Resolve the private export root without accepting aliases or missing paths. */
function estab_export_existing_base_directory(string $baseDirectory): string
{
    if ($baseDirectory === '' || str_contains($baseDirectory, "\0")) {
        throw new InvalidArgumentException('Invalid export base directory');
    }
    $realBase = realpath($baseDirectory);
    if (
        $realBase === false
        || !is_dir($realBase)
        || !is_readable($realBase)
    ) {
        throw new RuntimeException('Export base directory is not readable');
    }
    return $realBase;
}

/** Return lstat metadata only for a regular file without following a link. */
function estab_export_lstat_regular_file(string $path): ?array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (
        !is_array($stat)
        || !isset($stat['mode'], $stat['dev'], $stat['ino'], $stat['size'])
        || (($stat['mode'] & 0170000) !== 0100000)
        || !is_int($stat['size'])
        || $stat['size'] < 0
    ) {
        return null;
    }
    return $stat;
}

/** Return lstat metadata only for a directory without following a link. */
function estab_export_lstat_directory(string $path): ?array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (
        !is_array($stat)
        || !isset($stat['mode'], $stat['dev'], $stat['ino'])
        || (($stat['mode'] & 0170000) !== 0040000)
    ) {
        return null;
    }
    return $stat;
}

/** Compare a previously inspected path with an opened or re-inspected inode. */
function estab_export_same_inode(array $expected, array $actual): bool
{
    return isset(
        $expected['dev'],
        $expected['ino'],
        $actual['dev'],
        $actual['ino']
    )
        && (string) $expected['dev'] === (string) $actual['dev']
        && (string) $expected['ino'] === (string) $actual['ino'];
}

/** Resolve a run directory only when it is a direct, non-symlink child. */
function estab_export_resolve_run_directory(
    string $realBase,
    string $runId
): ?string {
    $path = $realBase . DIRECTORY_SEPARATOR . $runId;
    if (@lstat($path) === false) {
        return null;
    }
    if (is_link($path) || !is_dir($path)) {
        throw new EstabExportUnsafePathException('Unsafe export run directory');
    }
    $realPath = realpath($path);
    if (
        $realPath === false
        || dirname($realPath) !== $realBase
        || basename($realPath) !== $runId
    ) {
        throw new EstabExportUnsafePathException('Export run directory escapes its root');
    }
    return $realPath;
}

/**
 * Return every direct regular file in a run directory.
 *
 * Generated exports are deliberately flat. Refusing links and nested
 * directories keeps administrative deletion from becoming a recursive
 * filesystem operation.
 */
function estab_export_flat_run_files(
    string $realBase,
    string $runId
): ?array {
    $runDirectory = estab_export_resolve_run_directory($realBase, $runId);
    if ($runDirectory === null) {
        return null;
    }
    $entries = scandir($runDirectory);
    if (!is_array($entries)) {
        throw new RuntimeException('Could not inspect export run directory');
    }

    $files = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $runDirectory . DIRECTORY_SEPARATOR . $entry;
        if (is_link($path) || !is_file($path)) {
            throw new EstabExportUnsafePathException('Export run is not a flat file set');
        }
        $realPath = realpath($path);
        if ($realPath === false || dirname($realPath) !== $runDirectory) {
            throw new EstabExportUnsafePathException('Export file escapes its run directory');
        }
        $files[] = $realPath;
    }
    sort($files, SORT_STRING);
    return $files;
}

/** Read and strictly normalise a bounded manifest for display. */
function estab_export_read_manifest(string $realBase, string $runId): ?array
{
    try {
        $runDirectory = estab_export_resolve_run_directory($realBase, $runId);
    } catch (EstabExportUnsafePathException) {
        return null;
    }
    if ($runDirectory === null) {
        return null;
    }
    $runDirectoryStat = estab_export_lstat_directory($runDirectory);
    if ($runDirectoryStat === null) {
        return null;
    }

    $manifestPath = $runDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifestStat = estab_export_lstat_regular_file($manifestPath);
    if ($manifestStat === null) {
        return null;
    }
    $realManifest = realpath($manifestPath);
    if (
        $realManifest === false
        || dirname($realManifest) !== $runDirectory
        || $manifestStat['size'] < 1
        || $manifestStat['size'] > ESTAB_EXPORT_MANIFEST_MAX_BYTES
    ) {
        return null;
    }

    $handle = @fopen($manifestPath, 'rb');
    if ($handle === false) {
        return null;
    }
    try {
        $openedStat = fstat($handle);
        $currentManifestStat = estab_export_lstat_regular_file($manifestPath);
        $currentRunDirectoryStat = estab_export_lstat_directory($runDirectory);
        if (
            !is_array($openedStat)
            || !isset($openedStat['mode'], $openedStat['size'])
            || (($openedStat['mode'] & 0170000) !== 0100000)
            || !is_int($openedStat['size'])
            || $openedStat['size'] < 1
            || $openedStat['size'] > ESTAB_EXPORT_MANIFEST_MAX_BYTES
            || $currentManifestStat === null
            || $currentRunDirectoryStat === null
            || !estab_export_same_inode($manifestStat, $openedStat)
            || !estab_export_same_inode($manifestStat, $currentManifestStat)
            || !estab_export_same_inode(
                $runDirectoryStat,
                $currentRunDirectoryStat
            )
        ) {
            return null;
        }
        $json = stream_get_contents(
            $handle,
            ESTAB_EXPORT_MANIFEST_MAX_BYTES + 1
        );
    } finally {
        fclose($handle);
    }
    if (
        !is_string($json)
        || strlen($json) !== $manifestStat['size']
        || strlen($json) > ESTAB_EXPORT_MANIFEST_MAX_BYTES
    ) {
        return null;
    }
    try {
        $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (
        !is_array($manifest)
        || ($manifest['format'] ?? null) !== 1
        || !is_string($manifest['created_at'] ?? null)
        || !is_string($manifest['database'] ?? null)
        || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $manifest['database']) !== 1
        || !is_array($manifest['tables'] ?? null)
        || !array_is_list($manifest['tables'])
        || count($manifest['tables']) > 4096
        || ($manifest['null_value'] ?? null) !== '\\N'
        || ($manifest['delimiter'] ?? null) !== ';'
    ) {
        return null;
    }

    if (
        preg_match(
            '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T'
            . '[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}\z/D',
            $manifest['created_at']
        ) !== 1
    ) {
        return null;
    }
    $createdAt = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:sP',
        $manifest['created_at']
    );
    if (
        !$createdAt instanceof DateTimeImmutable
        || $createdAt->format(DateTimeInterface::ATOM)
            !== $manifest['created_at']
    ) {
        return null;
    }

    $tables = [];
    $seenTables = [];
    $seenFiles = [];
    $totalRows = 0;
    foreach ($manifest['tables'] as $table) {
        if (
            !is_array($table)
            || !is_string($table['table'] ?? null)
            || $table['table'] === ''
            || strlen($table['table']) > 64
            || str_contains($table['table'], "\0")
            || !is_string($table['file'] ?? null)
            || strlen($table['file']) > 255
            || !is_int($table['rows'] ?? null)
            || $table['rows'] < 0
            || !is_string($table['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $table['sha256']) !== 1
            || $table['file'] !== estab_export_filename($table['table'])
            || $table['rows'] > PHP_INT_MAX - $totalRows
            || isset($seenTables[$table['table']])
            || isset($seenFiles[$table['file']])
        ) {
            return null;
        }
        $seenTables[$table['table']] = true;
        $seenFiles[$table['file']] = true;
        $totalRows += $table['rows'];
        $tables[] = [
            'table' => $table['table'],
            'file' => $table['file'],
            'rows' => $table['rows'],
            'sha256' => $table['sha256'],
        ];
    }

    return [
        'created_at' => $createdAt->format(DateTimeInterface::ATOM),
        'database' => $manifest['database'],
        'table_count' => count($tables),
        'rows' => $totalRows,
        'tables' => $tables,
    ];
}

/** Resolve one published ZIP without following filesystem links. */
function estab_export_resolve_archive_path(
    string $baseDirectory,
    mixed $runId
): string {
    $runId = estab_export_validate_run_id($runId);
    $realBase = estab_export_existing_base_directory($baseDirectory);
    $archiveName = $runId . '.zip';
    $path = $realBase . DIRECTORY_SEPARATOR . $archiveName;
    if (is_link($path) || !is_file($path) || !is_readable($path)) {
        throw new EstabExportNotFoundException('Export archive not found');
    }
    $realPath = realpath($path);
    if (
        $realPath === false
        || dirname($realPath) !== $realBase
        || basename($realPath) !== $archiveName
    ) {
        throw new EstabExportNotFoundException('Export archive not found');
    }
    return $realPath;
}

/** Return all atomically published archives, newest first. */
function estab_export_list_runs(string $baseDirectory): array
{
    $realBase = estab_export_existing_base_directory($baseDirectory);
    $entries = scandir($realBase);
    if (!is_array($entries)) {
        throw new RuntimeException('Could not list export archives');
    }

    $runs = [];
    foreach ($entries as $entry) {
        if (
            preg_match(
                '/\A(estab-[0-9]{8}-[0-9]{6}-[a-f0-9]{8})\.zip\z/D',
                $entry,
                $matches
            ) !== 1
        ) {
            continue;
        }
        $runId = $matches[1];
        try {
            $runId = estab_export_validate_run_id($runId);
            $archivePath = estab_export_resolve_archive_path($realBase, $runId);
        } catch (InvalidArgumentException | EstabExportNotFoundException) {
            continue;
        }
        $archiveSize = filesize($archivePath);
        if (!is_int($archiveSize) || $archiveSize < 0) {
            continue;
        }

        $timestamp = substr($runId, 6, 15);
        $createdAt = DateTimeImmutable::createFromFormat('!Ymd-His', $timestamp);
        if (!$createdAt instanceof DateTimeImmutable) {
            continue;
        }
        $safeToDelete = true;
        try {
            estab_export_flat_run_files($realBase, $runId);
        } catch (Throwable) {
            $safeToDelete = false;
        }
        try {
            $manifest = estab_export_read_manifest($realBase, $runId);
        } catch (Throwable) {
            $manifest = null;
        }

        $runs[] = [
            'id' => $runId,
            'archive' => $entry,
            'archive_size' => $archiveSize,
            'created_at' => $createdAt->format(DateTimeInterface::ATOM),
            'manifest' => $manifest,
            'safe_to_delete' => $safeToDelete,
        ];
    }

    usort(
        $runs,
        static fn (array $left, array $right): int =>
            strcmp((string) $right['id'], (string) $left['id'])
    );
    return $runs;
}

/** Open a verified archive before the controller emits download headers. */
function estab_export_open_archive(string $baseDirectory, mixed $runId): array
{
    $runId = estab_export_validate_run_id($runId);
    $path = estab_export_resolve_archive_path($baseDirectory, $runId);
    $pathStat = estab_export_lstat_regular_file($path);
    if ($pathStat === null) {
        throw new EstabExportNotFoundException('Export archive not found');
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not open export archive');
    }
    $stat = fstat($handle);
    $currentPathStat = estab_export_lstat_regular_file($path);
    if (
        !is_array($stat)
        || !isset($stat['mode'], $stat['size'])
        || (($stat['mode'] & 0170000) !== 0100000)
        || !is_int($stat['size'])
        || $stat['size'] < 0
        || $currentPathStat === null
        || !estab_export_same_inode($pathStat, $stat)
        || !estab_export_same_inode($pathStat, $currentPathStat)
    ) {
        fclose($handle);
        throw new EstabExportNotFoundException('Export archive not found');
    }
    return [
        'handle' => $handle,
        'filename' => $runId . '.zip',
        'size' => $stat['size'],
    ];
}

/**
 * Delete exactly one published archive and its flat sibling directory.
 *
 * The archive remains in place until every run file and the directory have
 * been removed. A failed cleanup therefore stays visible and retryable.
 */
function estab_export_delete_run(string $baseDirectory, mixed $runId): array
{
    $runId = estab_export_validate_run_id($runId);
    $realBase = estab_export_existing_base_directory($baseDirectory);
    if (!is_writable($realBase)) {
        throw new RuntimeException('Export base directory is not writable');
    }
    $archivePath = estab_export_resolve_archive_path($realBase, $runId);
    $files = estab_export_flat_run_files($realBase, $runId);
    $runDirectory = $files === null
        ? null
        : $realBase . DIRECTORY_SEPARATOR . $runId;
    $runDirectoryStat = $runDirectory === null
        ? null
        : estab_export_lstat_directory($runDirectory);
    if ($runDirectory !== null && $runDirectoryStat === null) {
        throw new EstabExportUnsafePathException(
            'Export run directory changed during inspection'
        );
    }
    if ($runDirectory !== null && !is_writable($runDirectory)) {
        throw new RuntimeException('Export run directory is not writable');
    }

    $removedBytes = filesize($archivePath);
    $removedBytes = is_int($removedBytes) && $removedBytes >= 0
        ? $removedBytes
        : 0;
    foreach ($files ?? [] as $file) {
        $currentRunDirectoryStat = estab_export_lstat_directory(
            (string) $runDirectory
        );
        $fileStat = estab_export_lstat_regular_file($file);
        $realFile = realpath($file);
        if (
            $currentRunDirectoryStat === null
            || $runDirectoryStat === null
            || !estab_export_same_inode(
                $runDirectoryStat,
                $currentRunDirectoryStat
            )
            || $fileStat === null
            || $realFile === false
            || dirname($realFile) !== $runDirectory
        ) {
            throw new EstabExportUnsafePathException(
                'Export run changed during deletion'
            );
        }
        $size = $fileStat['size'];
        if (is_int($size) && $size > 0 && $removedBytes <= PHP_INT_MAX - $size) {
            $removedBytes += $size;
        }
        if (!unlink($file)) {
            throw new RuntimeException('Could not delete an export file');
        }
    }
    if ($runDirectory !== null) {
        $currentRunDirectoryStat = estab_export_lstat_directory($runDirectory);
        if (
            $runDirectoryStat === null
            || $currentRunDirectoryStat === null
            || !estab_export_same_inode(
                $runDirectoryStat,
                $currentRunDirectoryStat
            )
        ) {
            throw new EstabExportUnsafePathException(
                'Export run directory changed during deletion'
            );
        }
        if (!rmdir($runDirectory)) {
            throw new RuntimeException('Could not delete export run directory');
        }
    }
    if (!unlink($archivePath)) {
        throw new RuntimeException('Could not delete export archive');
    }

    return [
        'id' => $runId,
        'removed_bytes' => $removedBytes,
    ];
}

/** Create a private directory for one immutable export run. */
function estab_export_create_run_directory(string $baseDirectory, ?DateTimeImmutable $now = null): string
{
    if ($baseDirectory === '' || str_contains($baseDirectory, "\0")) {
        throw new InvalidArgumentException('Invalid export base directory');
    }
    if (!is_dir($baseDirectory) && !mkdir($baseDirectory, 0770, true) && !is_dir($baseDirectory)) {
        throw new RuntimeException('Could not create export base directory');
    }
    $realBase = realpath($baseDirectory);
    if ($realBase === false || !is_writable($realBase)) {
        throw new RuntimeException('Export base directory is not writable');
    }

    $timestamp = ($now ?? new DateTimeImmutable('now'))->format('Ymd-His');
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $directory = $realBase . DIRECTORY_SEPARATOR . 'estab-' . $timestamp . '-' . bin2hex(random_bytes(4));
        if (@mkdir($directory, 0770)) {
            return $directory;
        }
    }
    throw new RuntimeException('Could not allocate an export directory');
}

/** Write one result set as UTF-8 RFC-4180-style semicolon CSV. */
function estab_export_table(mysqli $connection, string $table, string $directory): array
{
    $result = $connection->query('SELECT * FROM ' . estab_export_quote_identifier($table), MYSQLI_USE_RESULT);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Could not read table ' . $table);
    }

    $filename = estab_export_filename($table);
    $finalPath = $directory . DIRECTORY_SEPARATOR . $filename;
    $temporaryPath = $finalPath . '.part-' . bin2hex(random_bytes(4));
    $handle = fopen($temporaryPath, 'xb');
    if ($handle === false) {
        $result->free();
        throw new RuntimeException('Could not create export file for ' . $table);
    }

    $rows = 0;
    try {
        try {
            $headers = array_map(
                static fn (object $field): string => (string) $field->name,
                $result->fetch_fields()
            );
            if (fputcsv($handle, $headers, ';', '"', '', "\r\n") === false) {
                throw new RuntimeException('Could not write CSV header for ' . $table);
            }
            while (($row = $result->fetch_row()) !== null) {
                $values = array_map(
                    static fn (mixed $value): string => $value === null ? '\\N' : (string) $value,
                    $row
                );
                if (fputcsv($handle, $values, ';', '"', '', "\r\n") === false) {
                    throw new RuntimeException('Could not write CSV data for ' . $table);
                }
                $rows++;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush CSV data for ' . $table);
            }
        } finally {
            $result->free();
            fclose($handle);
        }
    } catch (Throwable $exception) {
        @unlink($temporaryPath);
        throw $exception;
    }

    if (!chmod($temporaryPath, 0640) || !rename($temporaryPath, $finalPath)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Could not publish CSV file for ' . $table);
    }

    $hash = hash_file('sha256', $finalPath);
    if (!is_string($hash)) {
        throw new RuntimeException('Could not hash CSV file for ' . $table);
    }
    return [
        'table' => $table,
        'file' => $filename,
        'rows' => $rows,
        'sha256' => $hash,
    ];
}

/** Export every base table and return paths plus a machine-readable manifest. */
function estab_export_database(mysqli $connection, string $baseDirectory): array
{
    $createdAt = new DateTimeImmutable('now');
    $directory = estab_export_create_run_directory($baseDirectory, $createdAt);
    $catalogue = $connection->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    if (!$catalogue instanceof mysqli_result) {
        throw new RuntimeException('Could not list database tables');
    }
    $tables = [];
    while (($row = $catalogue->fetch_row()) !== null) {
        $tables[] = (string) $row[0];
    }
    $catalogue->free();
    sort($tables, SORT_STRING);

    $exports = [];
    foreach ($tables as $table) {
        $exports[] = estab_export_table($connection, $table, $directory);
    }

    $databaseResult = $connection->query('SELECT DATABASE()');
    if (!$databaseResult instanceof mysqli_result) {
        throw new RuntimeException('Could not determine the selected database');
    }
    $databaseRow = $databaseResult->fetch_row();
    $databaseResult->free();

    $manifest = [
        'format' => 1,
        'created_at' => $createdAt->format(DateTimeInterface::ATOM),
        'database' => (string) ($databaseRow[0] ?? ''),
        'null_value' => '\\N',
        'delimiter' => ';',
        'tables' => $exports,
    ];
    $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($manifestPath, $manifestJson . "\n", LOCK_EX) === false || !chmod($manifestPath, 0640)) {
        throw new RuntimeException('Could not write export manifest');
    }

    $archivePath = null;
    if (class_exists(ZipArchive::class)) {
        $archivePath = $directory . '.zip';
        $temporaryArchive = $archivePath . '.part-' . bin2hex(random_bytes(4));
        $archive = new ZipArchive();
        if ($archive->open($temporaryArchive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not create export archive');
        }
        foreach (array_merge($exports, [['file' => 'manifest.json']]) as $entry) {
            $file = (string) $entry['file'];
            if (!$archive->addFile($directory . DIRECTORY_SEPARATOR . $file, $file)) {
                $archive->close();
                @unlink($temporaryArchive);
                throw new RuntimeException('Could not add a file to the export archive');
            }
        }
        if (!$archive->close() || !chmod($temporaryArchive, 0640) || !rename($temporaryArchive, $archivePath)) {
            @unlink($temporaryArchive);
            throw new RuntimeException('Could not publish export archive');
        }
    }

    return [
        'directory' => $directory,
        'archive' => $archivePath,
        'manifest' => $manifest,
    ];
}
