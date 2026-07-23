<?php

declare(strict_types=1);

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
    $directory = estab_export_create_run_directory($baseDirectory);
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
        'created_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
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
