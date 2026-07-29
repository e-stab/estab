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

/**
 * Encode one database value for fputcsv without enabling spreadsheet formulas.
 *
 * The apostrophe is the established spreadsheet text marker. It becomes part
 * of the exported CSV value intentionally; consumers that need the exact
 * database bytes can remove precisely this marker after consulting the
 * manifest format. NULL keeps its distinct historical \N representation.
 * Unsigned numeric values remain numeric; a leading plus or minus is guarded
 * like every other configured formula trigger.
 */
function estab_export_csv_cell(mixed $value): string
{
    if ($value === null) {
        return '\\N';
    }

    $text = (string) $value;
    $formulaLike =
        preg_match('/\A[ \t\r\n\v\f]*[=+\-@]/D', $text) === 1;
    if (
        !$formulaLike
        && preg_match('/\A(?:[\p{Z}\s])*[=+\-@]/uD', $text) === 1
    ) {
        $formulaLike = true;
    }

    return $formulaLike ? "'" . $text : $text;
}

/** Apply the same formula neutralisation to database-provided CSV headers. */
function estab_export_csv_headers(array $fields): array
{
    return array_map(
        static fn (object $field): string =>
            estab_export_csv_cell((string) $field->name),
        $fields
    );
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
        || (
            array_key_exists('spreadsheet_formula_prefix', $manifest)
            && $manifest['spreadsheet_formula_prefix'] !== "'"
        )
        || (
            array_key_exists('spreadsheet_formula_triggers', $manifest)
            && $manifest['spreadsheet_formula_triggers'] !== '=+-@'
        )
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

/** Create the export root when needed and return its canonical writable path. */
function estab_export_writable_base_directory(string $baseDirectory): string
{
    if ($baseDirectory === '' || str_contains($baseDirectory, "\0")) {
        throw new InvalidArgumentException('Invalid export base directory');
    }
    if (
        !is_dir($baseDirectory)
        && !mkdir($baseDirectory, 0770, true)
        && !is_dir($baseDirectory)
    ) {
        throw new RuntimeException('Could not create export base directory');
    }
    $realBase = realpath($baseDirectory);
    if (
        $realBase === false
        || estab_export_lstat_directory($realBase) === null
        || !is_writable($realBase)
    ) {
        throw new RuntimeException('Export base directory is not writable');
    }
    return $realBase;
}

/** Derive every owned path from a validated base and run identifier. */
function estab_export_staging_paths(string $realBase, string $runId): array
{
    $runId = estab_export_validate_run_id($runId);
    return [
        'base' => $realBase,
        'run_id' => $runId,
        'staging_directory' => $realBase . DIRECTORY_SEPARATOR
            . '.estab-staging-' . $runId,
        'reservation_path' => $realBase . DIRECTORY_SEPARATOR
            . '.estab-reservation-' . $runId,
        'final_directory' => $realBase . DIRECTORY_SEPARATOR . $runId,
        'staged_archive_name' => '.estab-archive-' . $runId . '.zip',
        'final_archive' => $realBase . DIRECTORY_SEPARATOR . $runId . '.zip',
    ];
}

/** Remove one reservation only when it is still the inode we created. */
function estab_export_unlink_owned_reservation(
    string $path,
    array $expectedStat
): void {
    $currentStat = estab_export_lstat_regular_file($path);
    if ($currentStat === null) {
        if (@lstat($path) === false) {
            return;
        }
        throw new EstabExportUnsafePathException(
            'Export reservation is no longer a regular file'
        );
    }
    if (!estab_export_same_inode($expectedStat, $currentStat)) {
        throw new EstabExportUnsafePathException(
            'Export reservation changed during use'
        );
    }
    if (!unlink($path)) {
        throw new RuntimeException('Could not remove export reservation');
    }
}

/**
 * Allocate a mode-0700 staging directory hidden from the published run list.
 *
 * The exclusive reservation serialises the same random run identifier across
 * concurrent application workers. A supplied token factory is intentionally
 * supported so failure and collision handling can be proven without a live DB.
 */
function estab_export_create_staging_scope(
    string $baseDirectory,
    ?DateTimeImmutable $now = null,
    ?callable $tokenFactory = null
): array {
    $realBase = estab_export_writable_base_directory($baseDirectory);
    $timestamp = ($now ?? new DateTimeImmutable('now'))->format('Ymd-His');
    $makeToken = $tokenFactory
        ?? static fn (int $attempt): string => bin2hex(random_bytes(4));

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $token = $makeToken($attempt);
        if (
            !is_string($token)
            || preg_match('/\A[a-f0-9]{8}\z/D', $token) !== 1
        ) {
            throw new InvalidArgumentException(
                'Export token factory returned an unsafe token'
            );
        }

        $paths = estab_export_staging_paths(
            $realBase,
            'estab-' . $timestamp . '-' . $token
        );
        $reservationHandle = @fopen($paths['reservation_path'], 'xb');
        if ($reservationHandle === false) {
            continue;
        }

        $openedStat = fstat($reservationHandle);
        $reservationStat = estab_export_lstat_regular_file(
            $paths['reservation_path']
        );
        if (
            !is_array($openedStat)
            || $reservationStat === null
            || !estab_export_same_inode($reservationStat, $openedStat)
        ) {
            fclose($reservationHandle);
            if (is_array($reservationStat)) {
                estab_export_unlink_owned_reservation(
                    $paths['reservation_path'],
                    $reservationStat
                );
            }
            throw new EstabExportUnsafePathException(
                'Export reservation changed during allocation'
            );
        }
        try {
            $reservationBytes = $paths['run_id'] . "\n";
            $written = fwrite($reservationHandle, $reservationBytes);
            if (
                $written !== strlen($reservationBytes)
                || !fflush($reservationHandle)
                || !chmod($paths['reservation_path'], 0600)
            ) {
                throw new RuntimeException(
                    'Could not initialise export reservation'
                );
            }
            $currentReservationStat = estab_export_lstat_regular_file(
                $paths['reservation_path']
            );
            if (
                $currentReservationStat === null
                || !estab_export_same_inode(
                    $reservationStat,
                    $currentReservationStat
                )
            ) {
                throw new EstabExportUnsafePathException(
                    'Export reservation changed during allocation'
                );
            }
        } catch (Throwable $exception) {
            fclose($reservationHandle);
            estab_export_unlink_owned_reservation(
                $paths['reservation_path'],
                $reservationStat
            );
            throw $exception;
        }
        fclose($reservationHandle);

        if (
            @lstat($paths['final_directory']) !== false
            || @lstat($paths['final_archive']) !== false
            || @lstat($paths['staging_directory']) !== false
        ) {
            estab_export_unlink_owned_reservation(
                $paths['reservation_path'],
                $reservationStat
            );
            continue;
        }

        if (!@mkdir($paths['staging_directory'], 0700)) {
            estab_export_unlink_owned_reservation(
                $paths['reservation_path'],
                $reservationStat
            );
            continue;
        }
        $directoryStat = estab_export_lstat_directory(
            $paths['staging_directory']
        );
        if (
            $directoryStat === null
            || realpath($paths['staging_directory'])
                !== $paths['staging_directory']
            || dirname($paths['staging_directory']) !== $realBase
        ) {
            @rmdir($paths['staging_directory']);
            estab_export_unlink_owned_reservation(
                $paths['reservation_path'],
                $reservationStat
            );
            throw new EstabExportUnsafePathException(
                'Could not establish a private export staging directory'
            );
        }
        if (
            @lstat($paths['final_directory']) !== false
            || @lstat($paths['final_archive']) !== false
        ) {
            if (!rmdir($paths['staging_directory'])) {
                throw new RuntimeException(
                    'Could not release colliding export staging directory'
                );
            }
            estab_export_unlink_owned_reservation(
                $paths['reservation_path'],
                $reservationStat
            );
            continue;
        }

        return $paths + [
            'directory_stat' => $directoryStat,
            'reservation_stat' => $reservationStat,
            'archive_stat' => null,
            'directory_published' => false,
            'archive_published' => false,
            'committed' => false,
            'reservation_released' => false,
        ];
    }

    throw new RuntimeException('Could not allocate an export staging scope');
}

/** Reject any caller-modified scope before a cleanup or publication mutation. */
function estab_export_validate_staging_scope(array $scope): array
{
    if (
        !isset($scope['base'], $scope['run_id'])
        || !is_string($scope['base'])
        || !is_string($scope['run_id'])
    ) {
        throw new EstabExportUnsafePathException(
            'Incomplete export staging scope'
        );
    }
    $realBase = estab_export_existing_base_directory($scope['base']);
    if ($realBase !== $scope['base']) {
        throw new EstabExportUnsafePathException(
            'Export staging base changed'
        );
    }
    $expected = estab_export_staging_paths($realBase, $scope['run_id']);
    foreach ($expected as $key => $value) {
        if (($scope[$key] ?? null) !== $value) {
            throw new EstabExportUnsafePathException(
                'Export staging scope contains an unsafe path'
            );
        }
    }
    if (
        !is_array($scope['directory_stat'] ?? null)
        || !is_array($scope['reservation_stat'] ?? null)
    ) {
        throw new EstabExportUnsafePathException(
            'Export staging scope lacks inode ownership'
        );
    }
    return $expected;
}

/** Re-inspect one owned staging/final directory without following aliases. */
function estab_export_owned_scope_directory(
    string $path,
    array $scope
): ?array {
    $currentStat = estab_export_lstat_directory($path);
    if ($currentStat === null) {
        if (@lstat($path) === false) {
            return null;
        }
        throw new EstabExportUnsafePathException(
            'Owned export path is no longer a directory'
        );
    }
    if (!estab_export_same_inode($scope['directory_stat'], $currentStat)) {
        throw new EstabExportUnsafePathException(
            'Owned export directory changed inode'
        );
    }
    return $currentStat;
}

/** Release the exclusive run reservation without touching a replacement. */
function estab_export_release_staging_reservation(array &$scope): void
{
    $paths = estab_export_validate_staging_scope($scope);
    if (($scope['reservation_released'] ?? false) === true) {
        return;
    }
    estab_export_unlink_owned_reservation(
        $paths['reservation_path'],
        $scope['reservation_stat']
    );
    $scope['reservation_released'] = true;
}

/**
 * Remove only the owned flat staging/final scope after a failed transaction.
 *
 * Direct symlinks are unlinked without following their targets. Nested
 * directories and inode replacements are refused instead of recursing into an
 * attacker-controlled tree.
 */
function estab_export_cleanup_staging_scope(array &$scope): void
{
    $paths = estab_export_validate_staging_scope($scope);

    if (($scope['archive_published'] ?? false) === true) {
        $archiveStat = estab_export_lstat_regular_file(
            $paths['final_archive']
        );
        if (
            $archiveStat !== null
            && is_array($scope['archive_stat'] ?? null)
            && estab_export_same_inode($scope['archive_stat'], $archiveStat)
        ) {
            if (!unlink($paths['final_archive'])) {
                throw new RuntimeException(
                    'Could not roll back export archive'
                );
            }
        } elseif (@lstat($paths['final_archive']) !== false) {
            throw new EstabExportUnsafePathException(
                'Published export archive changed during rollback'
            );
        }
    }

    $ownedDirectories = [$paths['staging_directory']];
    if (($scope['directory_published'] ?? false) === true) {
        $ownedDirectories[] = $paths['final_directory'];
    }
    foreach (array_unique($ownedDirectories) as $directory) {
        $directoryStat = estab_export_owned_scope_directory(
            $directory,
            $scope
        );
        if ($directoryStat === null) {
            continue;
        }
        $entries = scandir($directory);
        if (!is_array($entries)) {
            throw new RuntimeException(
                'Could not inspect failed export staging directory'
            );
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (
                $entry === ''
                || str_contains($entry, "\0")
                || basename($entry) !== $entry
            ) {
                throw new EstabExportUnsafePathException(
                    'Unsafe export staging entry'
                );
            }
            $currentDirectoryStat = estab_export_owned_scope_directory(
                $directory,
                $scope
            );
            if (
                $currentDirectoryStat === null
                || !estab_export_same_inode(
                    $directoryStat,
                    $currentDirectoryStat
                )
            ) {
                throw new EstabExportUnsafePathException(
                    'Export staging directory changed during cleanup'
                );
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            $entryStat = @lstat($path);
            if (!is_array($entryStat) || !isset($entryStat['mode'])) {
                throw new EstabExportUnsafePathException(
                    'Export staging entry changed during cleanup'
                );
            }
            $type = $entryStat['mode'] & 0170000;
            if ($type === 0040000) {
                throw new EstabExportUnsafePathException(
                    'Nested export staging directory refused'
                );
            }
            if ($type !== 0100000 && $type !== 0120000) {
                throw new EstabExportUnsafePathException(
                    'Special export staging file refused'
                );
            }
            if (!unlink($path)) {
                throw new RuntimeException(
                    'Could not remove failed export staging entry'
                );
            }
        }
        $currentDirectoryStat = estab_export_owned_scope_directory(
            $directory,
            $scope
        );
        if (
            $currentDirectoryStat === null
            || !estab_export_same_inode(
                $directoryStat,
                $currentDirectoryStat
            )
            || !rmdir($directory)
        ) {
            throw new RuntimeException(
                'Could not remove failed export staging directory'
            );
        }
    }

    estab_export_release_staging_reservation($scope);
}

/** Validate one generated flat filename before publishing the staging scope. */
function estab_export_validate_staged_filename(mixed $filename): string
{
    if (
        !is_string($filename)
        || $filename === ''
        || strlen($filename) > 255
        || $filename[0] === '.'
        || str_contains($filename, "\0")
        || basename($filename) !== $filename
        || preg_match('/\A[A-Za-z0-9_.-]+\z/D', $filename) !== 1
    ) {
        throw new EstabExportUnsafePathException(
            'Unsafe generated export filename'
        );
    }
    return $filename;
}

/**
 * Publish a completed flat stage.
 *
 * The run directory moves first but remains absent from the public list.
 * Creating the sibling ZIP hard link is the atomic commit marker used by
 * estab_export_list_runs(); link() cannot overwrite an existing export.
 */
function estab_export_publish_staging_scope(
    array &$scope,
    array $generatedFiles,
    ?callable $phaseHook = null
): array {
    $paths = estab_export_validate_staging_scope($scope);
    $directoryStat = estab_export_owned_scope_directory(
        $paths['staging_directory'],
        $scope
    );
    if ($directoryStat === null) {
        throw new EstabExportUnsafePathException(
            'Export staging directory disappeared'
        );
    }

    $expected = [];
    foreach ($generatedFiles as $filename) {
        $filename = estab_export_validate_staged_filename($filename);
        if (isset($expected[$filename])) {
            throw new EstabExportUnsafePathException(
                'Duplicate generated export filename'
            );
        }
        $expected[$filename] = true;
    }
    if (!isset($expected['manifest.json'])) {
        throw new EstabExportUnsafePathException(
            'Export stage has no manifest'
        );
    }
    $expected[$paths['staged_archive_name']] = true;
    $expectedNames = array_keys($expected);
    sort($expectedNames, SORT_STRING);

    $entries = scandir($paths['staging_directory']);
    if (!is_array($entries)) {
        throw new RuntimeException('Could not inspect completed export stage');
    }
    $actualNames = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $actualNames[] = $entry;
        $path = $paths['staging_directory']
            . DIRECTORY_SEPARATOR . $entry;
        $fileStat = estab_export_lstat_regular_file($path);
        $realPath = realpath($path);
        if (
            $fileStat === null
            || $realPath === false
            || dirname($realPath) !== $paths['staging_directory']
            || $fileStat['size'] < 1
        ) {
            throw new EstabExportUnsafePathException(
                'Export stage is not a flat non-empty regular file set'
            );
        }
    }
    sort($actualNames, SORT_STRING);
    if ($actualNames !== $expectedNames) {
        throw new EstabExportUnsafePathException(
            'Export stage contains missing or unexpected files'
        );
    }

    $stagedArchive = $paths['staging_directory']
        . DIRECTORY_SEPARATOR . $paths['staged_archive_name'];
    $archiveStat = estab_export_lstat_regular_file($stagedArchive);
    if ($archiveStat === null || $archiveStat['size'] < 1) {
        throw new EstabExportUnsafePathException(
            'Completed export archive is missing'
        );
    }
    $scope['archive_stat'] = $archiveStat;

    if ($phaseHook !== null) {
        $phaseHook('before_directory_publish', $scope);
    }
    if (
        @lstat($paths['final_directory']) !== false
        || @lstat($paths['final_archive']) !== false
    ) {
        throw new RuntimeException(
            'Refusing to overwrite an existing export'
        );
    }
    if (!rename($paths['staging_directory'], $paths['final_directory'])) {
        throw new RuntimeException('Could not publish export run directory');
    }
    $scope['directory_published'] = true;

    if ($phaseHook !== null) {
        $phaseHook('after_directory_publish', $scope);
    }
    $publishedDirectoryStat = estab_export_owned_scope_directory(
        $paths['final_directory'],
        $scope
    );
    if (
        $publishedDirectoryStat === null
        || !estab_export_same_inode($directoryStat, $publishedDirectoryStat)
    ) {
        throw new EstabExportUnsafePathException(
            'Export run directory changed before commit'
        );
    }
    $publishedArchiveSource = $paths['final_directory']
        . DIRECTORY_SEPARATOR . $paths['staged_archive_name'];
    if (@lstat($paths['final_archive']) !== false) {
        throw new RuntimeException(
            'Refusing to overwrite an existing export archive'
        );
    }
    if (!link($publishedArchiveSource, $paths['final_archive'])) {
        throw new RuntimeException('Could not atomically publish export archive');
    }
    $scope['archive_published'] = true;
    $publishedArchiveStat = estab_export_lstat_regular_file(
        $paths['final_archive']
    );
    if (
        $publishedArchiveStat === null
        || !estab_export_same_inode($archiveStat, $publishedArchiveStat)
    ) {
        throw new EstabExportUnsafePathException(
            'Published export archive changed at commit'
        );
    }

    // The external ZIP is now the atomic publication marker. Removing its
    // private staging hard link is housekeeping and cannot invalidate it.
    if (!@unlink($publishedArchiveSource)) {
        error_log(
            'eStab export retained a private archive hard link: '
            . $scope['run_id']
        );
    }
    $scope['committed'] = true;
    try {
        estab_export_release_staging_reservation($scope);
    } catch (Throwable $exception) {
        error_log(
            'eStab export reservation cleanup failed after commit: '
            . $exception->getMessage()
        );
    }

    return [
        'directory' => $paths['final_directory'],
        'archive' => $paths['final_archive'],
    ];
}

/**
 * Run a filesystem export transaction with injectable build and phase hooks.
 *
 * The builder receives the validated scope and returns a `files` list plus any
 * result metadata. Every Throwable before the ZIP commit marker triggers a
 * bounded cleanup of only that scope.
 */
function estab_export_run_staged(
    string $baseDirectory,
    callable $builder,
    ?DateTimeImmutable $now = null,
    ?callable $phaseHook = null,
    ?callable $tokenFactory = null
): array {
    $scope = estab_export_create_staging_scope(
        $baseDirectory,
        $now,
        $tokenFactory
    );
    try {
        $result = $builder($scope);
        if (!is_array($result) || !is_array($result['files'] ?? null)) {
            throw new UnexpectedValueException(
                'Export staging builder returned an invalid result'
            );
        }
        $published = estab_export_publish_staging_scope(
            $scope,
            $result['files'],
            $phaseHook
        );
        unset($result['files']);
        $result['directory'] = $published['directory'];
        $result['archive'] = $published['archive'];
        return $result;
    } catch (Throwable $exception) {
        if (($scope['committed'] ?? false) !== true) {
            try {
                estab_export_cleanup_staging_scope($scope);
            } catch (Throwable $cleanupException) {
                throw new RuntimeException(
                    'Export failed and its private staging cleanup failed: '
                    . $cleanupException->getMessage(),
                    0,
                    $exception
                );
            }
        }
        throw $exception;
    }
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
            $headers = estab_export_csv_headers($result->fetch_fields());
            if (fputcsv($handle, $headers, ';', '"', '', "\r\n") === false) {
                throw new RuntimeException('Could not write CSV header for ' . $table);
            }
            while (($row = $result->fetch_row()) !== null) {
                $values = array_map(
                    static fn (mixed $value): string =>
                        estab_export_csv_cell($value),
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
    return estab_export_run_staged(
        $baseDirectory,
        static function (array $scope) use ($connection, $createdAt): array {
            $directory = $scope['staging_directory'];
            $catalogue = $connection->query(
                "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"
            );
            if (!$catalogue instanceof mysqli_result) {
                throw new RuntimeException('Could not list database tables');
            }
            $tables = [];
            try {
                while (($row = $catalogue->fetch_row()) !== null) {
                    $tables[] = (string) $row[0];
                }
            } finally {
                $catalogue->free();
            }
            sort($tables, SORT_STRING);

            $exports = [];
            foreach ($tables as $table) {
                $exports[] = estab_export_table(
                    $connection,
                    $table,
                    $directory
                );
            }

            $databaseResult = $connection->query('SELECT DATABASE()');
            if (!$databaseResult instanceof mysqli_result) {
                throw new RuntimeException(
                    'Could not determine the selected database'
                );
            }
            try {
                $databaseRow = $databaseResult->fetch_row();
            } finally {
                $databaseResult->free();
            }

            $manifest = [
                'format' => 1,
                'created_at' => $createdAt->format(
                    DateTimeInterface::ATOM
                ),
                'database' => (string) ($databaseRow[0] ?? ''),
                'null_value' => '\\N',
                'delimiter' => ';',
                // Cells and headers beginning with one of these triggers after
                // whitespace are exported as text by prefixing this apostrophe.
                // Older format-1 manifests omit both keys.
                'spreadsheet_formula_prefix' => "'",
                'spreadsheet_formula_triggers' => '=+-@',
                'tables' => $exports,
            ];
            $manifestJson = json_encode(
                $manifest,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ) . "\n";
            $manifestPath = $directory
                . DIRECTORY_SEPARATOR . 'manifest.json';
            $temporaryManifest = $manifestPath . '.part-'
                . bin2hex(random_bytes(4));
            $manifestBytes = file_put_contents(
                $temporaryManifest,
                $manifestJson,
                LOCK_EX
            );
            if (
                $manifestBytes !== strlen($manifestJson)
                || !chmod($temporaryManifest, 0640)
                || !rename($temporaryManifest, $manifestPath)
            ) {
                throw new RuntimeException(
                    'Could not publish export manifest'
                );
            }

            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException(
                    'ZIP support is required for an export'
                );
            }
            $archivePath = $directory . DIRECTORY_SEPARATOR
                . $scope['staged_archive_name'];
            $archive = new ZipArchive();
            if (
                $archive->open(
                    $archivePath,
                    ZipArchive::CREATE | ZipArchive::EXCL
                ) !== true
            ) {
                throw new RuntimeException(
                    'Could not create export archive'
                );
            }
            foreach (
                array_merge($exports, [['file' => 'manifest.json']])
                as $entry
            ) {
                $file = (string) $entry['file'];
                if (
                    !$archive->addFile(
                        $directory . DIRECTORY_SEPARATOR . $file,
                        $file
                    )
                ) {
                    $archive->close();
                    throw new RuntimeException(
                        'Could not add a file to the export archive'
                    );
                }
            }
            if (!$archive->close() || !chmod($archivePath, 0640)) {
                throw new RuntimeException(
                    'Could not finalise export archive'
                );
            }

            return [
                'files' => array_merge(
                    array_column($exports, 'file'),
                    ['manifest.json']
                ),
                'manifest' => $manifest,
            ];
        },
        $createdAt
    );
}
