<?php

declare(strict_types=1);

/**
 * Durable attachment-integrity boundary.
 *
 * Migration 95 marks rows that already existed at upgrade time as legacy.
 * Every reservation created afterwards is integrity-required and may only
 * become final after SHA-256, byte length and capture time were persisted.
 */

require_once __DIR__ . '/file_access.php';

final class EstabAttachmentIntegrityException extends RuntimeException
{
}

/** Return one strict unsigned integer read from MariaDB. */
function estab_attachment_integrity_size(mixed $value): int
{
    if (
        is_int($value)
        && $value >= 0
    ) {
        return $value;
    }
    if (
        !is_string($value)
        || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
        || strlen($value) > strlen((string) PHP_INT_MAX)
    ) {
        throw new EstabAttachmentIntegrityException(
            'Die gespeicherte Anhanggröße ist ungültig.'
        );
    }
    $size = (int) $value;
    if ($size < 0 || (string) $size !== $value) {
        throw new EstabAttachmentIntegrityException(
            'Die gespeicherte Anhanggröße ist ungültig.'
        );
    }
    return $size;
}

/** Validate a lower-case SHA-256 digest without silently normalising it. */
function estab_attachment_integrity_sha256(mixed $value): string
{
    if (
        !is_string($value)
        || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1
    ) {
        throw new EstabAttachmentIntegrityException(
            'Der gespeicherte SHA-256-Anhangnachweis ist ungültig.'
        );
    }
    return $value;
}

/**
 * Validate the immutable database evidence without touching file bytes.
 *
 * @return array{
 *   state:'verified'|'legacy_unverifiable',
 *   statement:string,
 *   expected_size:?int,
 *   expected_sha256:?string
 * }
 */
function estab_attachment_integrity_evidence(array $row): array
{
    $required = $row['integrity_required'] ?? null;
    if (!in_array($required, [0, 1, '0', '1'], true)) {
        throw new EstabAttachmentIntegrityException(
            'Der Integritätsstatus des Anhangs ist ungültig.'
        );
    }
    if ((int) $required === 0) {
        if (
            ($row['ingest_sha256'] ?? null) !== null
            || ($row['ingest_size'] ?? null) !== null
            || ($row['integrity_captured_at'] ?? null) !== null
        ) {
            throw new EstabAttachmentIntegrityException(
                'Ein Legacy-Anhang enthält einen unzulässigen '
                    . 'nachträglichen Integritätsnachweis.'
            );
        }
        return [
            'state' => 'legacy_unverifiable',
            'statement' => 'Integrität beim Eingang nicht belegbar',
            'expected_size' => null,
            'expected_sha256' => null,
        ];
    }

    $expectedHash = estab_attachment_integrity_sha256(
        $row['ingest_sha256'] ?? null
    );
    $expectedSize = estab_attachment_integrity_size(
        $row['ingest_size'] ?? null
    );
    $capturedAt = $row['integrity_captured_at'] ?? null;
    if (
        !is_string($capturedAt)
        || preg_match(
            '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} '
                . '[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?\z/D',
            $capturedAt
        ) !== 1
    ) {
        throw new EstabAttachmentIntegrityException(
            'Der Erfassungszeitpunkt des Anhangnachweises ist ungültig.'
        );
    }
    return [
        'state' => 'verified',
        'statement' => 'SHA-256 und Größe entsprechen dem Eingangsnachweis',
        'expected_size' => $expectedSize,
        'expected_sha256' => $expectedHash,
    ];
}

/**
 * Hash one already-open regular stream and leave it rewound for delivery.
 *
 * @return array{size:int,sha256:string}
 */
function estab_attachment_integrity_measure_stream(mixed $stream): array
{
    if (
        !is_resource($stream)
        || get_resource_type($stream) !== 'stream'
        || !rewind($stream)
    ) {
        throw new EstabAttachmentIntegrityException(
            'Der Anhangdatenstrom ist ungültig.'
        );
    }
    $before = fstat($stream);
    if (
        !is_array($before)
        || (((int) ($before['mode'] ?? 0)) & 0170000) !== 0100000
        || (int) ($before['size'] ?? -1) < 0
    ) {
        throw new EstabAttachmentIntegrityException(
            'Der Anhangdatenstrom ist keine reguläre Datei.'
        );
    }

    $context = hash_init('sha256');
    $read = hash_update_stream($context, $stream);
    if (!is_int($read) || $read !== (int) $before['size']) {
        throw new EstabAttachmentIntegrityException(
            'Der Anhangdatenstrom konnte nicht vollständig geprüft werden.'
        );
    }
    $digest = hash_final($context);
    $after = fstat($stream);
    if (
        !is_array($after)
        || (string) ($after['dev'] ?? '') !== (string) ($before['dev'] ?? null)
        || (string) ($after['ino'] ?? '') !== (string) ($before['ino'] ?? null)
        || (int) ($after['size'] ?? -1) !== (int) $before['size']
        || !rewind($stream)
    ) {
        throw new EstabAttachmentIntegrityException(
            'Der Anhangdatenstrom wurde während der Prüfung verändert.'
        );
    }
    return [
        'size' => (int) $before['size'],
        'sha256' => $digest,
    ];
}

/**
 * Read and hash one stable regular file without following a final symlink.
 *
 * @return array{size:int,sha256:string}
 */
function estab_attachment_integrity_measure_file(string $path): array
{
    if ($path === '' || str_contains($path, "\0")) {
        throw new EstabAttachmentIntegrityException(
            'Der Anhangpfad ist ungültig.'
        );
    }
    clearstatcache(true, $path);
    $before = @lstat($path);
    if (
        !is_array($before)
        || (((int) ($before['mode'] ?? 0)) & 0170000) !== 0100000
        || (int) ($before['size'] ?? -1) < 0
    ) {
        throw new EstabAttachmentIntegrityException(
            'Der Anhang fehlt oder ist keine reguläre Datei.'
        );
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new EstabAttachmentIntegrityException(
            'Der Anhang kann nicht zur Integritätsprüfung geöffnet werden.'
        );
    }
    try {
        $opened = fstat($handle);
        if (
            !is_array($opened)
            || (string) ($opened['dev'] ?? '') !== (string) ($before['dev'] ?? null)
            || (string) ($opened['ino'] ?? '') !== (string) ($before['ino'] ?? null)
            || (int) ($opened['size'] ?? -1) !== (int) $before['size']
        ) {
            throw new EstabAttachmentIntegrityException(
                'Der Anhang wurde während des Öffnens verändert.'
            );
        }
        $context = hash_init('sha256');
        $read = hash_update_stream($context, $handle);
        if (!is_int($read) || $read !== (int) $opened['size']) {
            throw new EstabAttachmentIntegrityException(
                'Der Anhang konnte nicht vollständig geprüft werden.'
            );
        }
        $digest = hash_final($context);
        $after = fstat($handle);
        clearstatcache(true, $path);
        $current = @lstat($path);
        if (
            !is_array($after)
            || !is_array($current)
            || (string) ($after['dev'] ?? '') !== (string) ($opened['dev'] ?? null)
            || (string) ($after['ino'] ?? '') !== (string) ($opened['ino'] ?? null)
            || (int) ($after['size'] ?? -1) !== (int) $opened['size']
            || (string) ($current['dev'] ?? '') !== (string) ($opened['dev'] ?? null)
            || (string) ($current['ino'] ?? '') !== (string) ($opened['ino'] ?? null)
            || (int) ($current['size'] ?? -1) !== (int) $opened['size']
        ) {
            throw new EstabAttachmentIntegrityException(
                'Der Anhang wurde während der Integritätsprüfung verändert.'
            );
        }
    } finally {
        fclose($handle);
    }

    return [
        'size' => (int) $before['size'],
        'sha256' => $digest,
    ];
}

/**
 * Compare one measured byte sequence with its validated database evidence.
 *
 * @param array{
 *   state:'verified'|'legacy_unverifiable',
 *   statement:string,
 *   expected_size:?int,
 *   expected_sha256:?string
 * } $evidence
 * @param array{size:int,sha256:string} $actual
 * @return array{
 *   state:'verified'|'legacy_unverifiable',
 *   statement:string,
 *   size:?int,
 *   sha256:?string
 * }
 */
function estab_attachment_integrity_verify_measurement(
    array $evidence,
    array $actual
): array {
    if ($evidence['state'] === 'legacy_unverifiable') {
        return [
            'state' => 'legacy_unverifiable',
            'statement' => $evidence['statement'],
            'size' => null,
            'sha256' => null,
        ];
    }
    if (
        $actual['size'] !== $evidence['expected_size']
        || !hash_equals(
            (string) $evidence['expected_sha256'],
            $actual['sha256']
        )
    ) {
        throw new EstabAttachmentIntegrityException(
            'Der Anhang stimmt nicht mit seinem beim Eingang gesicherten '
                . 'SHA-256-/Größennachweis überein.'
        );
    }
    return [
        'state' => 'verified',
        'statement' => $evidence['statement'],
        'size' => $evidence['expected_size'],
        'sha256' => $evidence['expected_sha256'],
    ];
}

/**
 * Validate a database row and compare required evidence with the live file.
 *
 * Legacy rows deliberately return no digest: hashing their current bytes
 * cannot prove which bytes originally entered eStab.
 *
 * @return array{
 *   state:'verified'|'legacy_unverifiable',
 *   statement:string,
 *   size:?int,
 *   sha256:?string
 * }
 */
function estab_attachment_integrity_verify_file(
    array $row,
    string $path
): array {
    $evidence = estab_attachment_integrity_evidence($row);
    $actual = estab_attachment_integrity_measure_file($path);
    return estab_attachment_integrity_verify_measurement($evidence, $actual);
}

/**
 * Create a private byte snapshot, verify that exact handle and return it
 * rewound for delivery.
 *
 * The authorized source path is opened exactly once. A shared lock covers the
 * copy, and only the private snapshot whose bytes were hashed is returned.
 * Thus a later pathname replacement or in-place source modification cannot
 * change the response body after verification.
 *
 * @return array{
 *   stream:resource,
 *   content_size:int,
 *   state:'verified'|'legacy_unverifiable',
 *   statement:string,
 *   sha256:?string
 * }
 */
function estab_attachment_integrity_open_snapshot(
    array $row,
    string $root,
    string $filename
): array {
    $filename = estab_file_validate_name('attachment', $filename);
    $evidence = estab_attachment_integrity_evidence($row);
    $source = estab_file_open($root, 'attachment', $filename);
    $snapshot = null;
    $locked = false;
    try {
        $locked = @flock($source, LOCK_SH);
        if (!$locked || !rewind($source)) {
            throw new EstabAttachmentIntegrityException(
                'Der Anhang konnte nicht für die Auslieferung gesperrt werden.'
            );
        }
        $sourceBefore = fstat($source);
        if (
            !is_array($sourceBefore)
            || (((int) ($sourceBefore['mode'] ?? 0)) & 0170000) !== 0100000
            || (int) ($sourceBefore['size'] ?? -1) < 0
        ) {
            throw new EstabAttachmentIntegrityException(
                'Der Anhang ist keine reguläre Datei.'
            );
        }

        $snapshot = tmpfile();
        if (!is_resource($snapshot)) {
            throw new EstabAttachmentIntegrityException(
                'Der Anhang konnte nicht sicher bereitgestellt werden.'
            );
        }
        $copied = stream_copy_to_stream($source, $snapshot);
        $sourceAfter = fstat($source);
        if (
            !is_int($copied)
            || $copied !== (int) $sourceBefore['size']
            || !is_array($sourceAfter)
            || (string) ($sourceAfter['dev'] ?? '')
                !== (string) ($sourceBefore['dev'] ?? null)
            || (string) ($sourceAfter['ino'] ?? '')
                !== (string) ($sourceBefore['ino'] ?? null)
            || (int) ($sourceAfter['size'] ?? -1)
                !== (int) $sourceBefore['size']
            || !fflush($snapshot)
        ) {
            throw new EstabAttachmentIntegrityException(
                'Der Anhang wurde während der Bereitstellung verändert.'
            );
        }

        $actual = estab_attachment_integrity_measure_stream($snapshot);
        $status = estab_attachment_integrity_verify_measurement(
            $evidence,
            $actual
        );
    } catch (Throwable $exception) {
        if (is_resource($snapshot)) {
            fclose($snapshot);
        }
        throw $exception;
    } finally {
        if ($locked) {
            @flock($source, LOCK_UN);
        }
        fclose($source);
    }

    return [
        'stream' => $snapshot,
        'content_size' => $actual['size'],
        'state' => $status['state'],
        'statement' => $status['statement'],
        'sha256' => $status['sha256'],
    ];
}

/**
 * Load and verify every completed attachment, optionally for one incident.
 *
 * @return array{
 *   total:int,
 *   verified:int,
 *   legacy_unverifiable:int,
 *   integrity_errors:int,
 *   statement:string
 * }
 */
function estab_attachment_integrity_summary(
    mysqli $connection,
    string $attachmentRoot,
    ?int $incidentId = null,
    bool $failOnRequiredError = true
): array {
    $sql = 'SELECT `lfd-nr`, `einsatz_id`, `filename`, `fileext`,'
        . ' `integrity_required`, `ingest_sha256`, `ingest_size`,'
        . ' `integrity_captured_at` FROM `nv_anhang` WHERE `status` = 1';
    if ($incidentId !== null) {
        if ($incidentId < 1) {
            throw new InvalidArgumentException('Invalid incident id');
        }
        $sql .= ' AND `einsatz_id` = ?';
        $statement = $connection->prepare($sql . ' ORDER BY `lfd-nr`');
        if (!$statement) {
            throw new EstabAttachmentIntegrityException(
                'Die Anhangnachweise konnten nicht vorbereitet werden.'
            );
        }
        $statement->bind_param('i', $incidentId);
    } else {
        $statement = $connection->prepare($sql . ' ORDER BY `lfd-nr`');
        if (!$statement) {
            throw new EstabAttachmentIntegrityException(
                'Die Anhangnachweise konnten nicht vorbereitet werden.'
            );
        }
    }

    try {
        if (!$statement->execute()) {
            throw new EstabAttachmentIntegrityException(
                'Die Anhangnachweise konnten nicht gelesen werden.'
            );
        }
        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            throw new EstabAttachmentIntegrityException(
                'Die Anhangnachweise konnten nicht ausgewertet werden.'
            );
        }
        try {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
    } finally {
        $statement->close();
    }

    $summary = [
        'total' => count($rows),
        'verified' => 0,
        'legacy_unverifiable' => 0,
        'integrity_errors' => 0,
        'statement' => 'Integrität beim Eingang nicht belegbar',
    ];
    foreach ($rows as $row) {
        $storedName = (string) ($row['filename'] ?? '')
            . '.' . strtolower((string) ($row['fileext'] ?? ''));
        try {
            $storedName = estab_file_validate_name(
                'attachment',
                $storedName
            );
            $path = estab_file_resolve(
                $attachmentRoot,
                'attachment',
                $storedName
            );
            $status = estab_attachment_integrity_verify_file($row, $path);
            if ($status['state'] === 'verified') {
                $summary['verified']++;
            } else {
                $summary['legacy_unverifiable']++;
            }
        } catch (Throwable $exception) {
            if ($failOnRequiredError) {
                throw new EstabAttachmentIntegrityException(
                    'Integritätsprüfung für Anhang ' . $storedName
                        . ' fehlgeschlagen: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
            $summary['integrity_errors']++;
        }
    }
    return $summary;
}
