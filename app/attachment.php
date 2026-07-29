<?php

/**
 * Concurrency-safe persistence boundary for attachment reservations/uploads.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/incident.php';

final class EstabAttachmentDatabaseException extends RuntimeException
{
}

function estab_attachment_table(string $table): string
{
    return estab_auth_table($table);
}

function estab_attachment_validate_prefix(string $prefix): string
{
    $prefix = strtoupper(trim($prefix));
    if (preg_match('/\A[A-Z0-9_-]{1,24}\z/D', $prefix) !== 1) {
        throw new InvalidArgumentException('Invalid attachment filename prefix');
    }
    return $prefix;
}

function estab_attachment_validate_session_id(string $sessionId): string
{
    if (preg_match('/\A[A-Za-z0-9,_-]{1,128}\z/D', $sessionId) !== 1) {
        throw new InvalidArgumentException('Invalid attachment session id');
    }
    return $sessionId;
}

function estab_attachment_validate_reservation_name(string $filename, ?string $prefix = null): string
{
    $filename = trim($filename);
    if (preg_match('/\A[A-Za-z0-9_-]{2,255}\z/D', $filename) !== 1) {
        throw new InvalidArgumentException('Invalid attachment reservation name');
    }
    if ($prefix !== null) {
        $prefix = estab_attachment_validate_prefix($prefix);
        if (preg_match('/\A' . preg_quote($prefix, '/') . '[0-9]{4,}\z/D', $filename) !== 1) {
            throw new InvalidArgumentException('Attachment reservation does not match its prefix');
        }
    }
    return $filename;
}

/** Deterministically format the next EL0001-style filename. */
function estab_attachment_next_name(string $prefix, int $highest, int $width = 4): string
{
    $prefix = estab_attachment_validate_prefix($prefix);
    if ($highest < 0 || $highest === PHP_INT_MAX || $width < 4 || $width > 12) {
        throw new InvalidArgumentException('Invalid attachment sequence parameters');
    }
    $next = $highest + 1;
    $number = str_pad((string) $next, $width, '0', STR_PAD_LEFT);
    $filename = $prefix . $number;
    if (strlen($filename) > 255) {
        throw new OverflowException('Attachment filename is too long');
    }
    return $filename;
}

function estab_attachment_allowed_extensions(): array
{
    return [
        'jpg', 'tif', 'gif', 'avi', 'png', 'bmp', 'zip',
        'pdf', 'doc', 'xls', 'odt', 'txt', 'xia',
    ];
}

function estab_attachment_extension_is_allowed(string $extension): bool
{
    return in_array(strtolower($extension), estab_attachment_allowed_extensions(), true);
}

function estab_attachment_database_error_is_retryable(int $code): bool
{
    return in_array($code, [1062, 1205, 1213], true);
}

function estab_attachment_text_is_valid(string $value, int $maxLength, bool $allowEmpty): bool
{
    $length = estab_auth_text_length($value);
    return $length >= ($allowEmpty ? 0 : 1)
        && $length <= $maxLength
        && preg_match('/\p{C}/u', $value) !== 1;
}

/** Convert the legacy ddHHmmMONYYYY timestamp into a strict SQL datetime. */
function estab_attachment_parse_tactical_time(string $value): ?string
{
    $value = trim($value);
    if (!preg_match('/\A(\d{2})(\d{2})(\d{2})([A-Za-z]{3})(\d{4})\z/D', $value, $parts)) {
        return null;
    }
    $months = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'may' => 5, 'mai' => 5, 'jun' => 6, 'jul' => 7,
        'aug' => 8, 'sep' => 9, 'oct' => 10, 'okt' => 10,
        'nov' => 11, 'dec' => 12, 'dez' => 12,
    ];
    $day = (int) $parts[1];
    $hour = (int) $parts[2];
    $minute = (int) $parts[3];
    $month = $months[strtolower($parts[4])] ?? 0;
    $year = (int) $parts[5];
    if ($hour > 23 || $minute > 59 || $year < 1000 || !checkdate($month, $day, $year)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);
}

function estab_attachment_validate_sql_datetime(string $value): bool
{
    if (preg_match('/\A([0-9]{4})-/', $value, $parts) !== 1 || (int) $parts[1] < 1000) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d H:i:s') === $value;
}

/**
 * Validate metadata and bind it to the server-side reservation/session user.
 */
function estab_attachment_validate_metadata(
    array $data,
    string $expectedReservation,
    string $sessionCode
): array {
    $expectedReservation = estab_attachment_validate_reservation_name($expectedReservation);

    $storedFilename = isset($data['filename']) && is_string($data['filename'])
        ? basename(str_replace('\\', '/', trim($data['filename'])))
        : '';
    $base = pathinfo($storedFilename, PATHINFO_FILENAME);
    $extension = strtolower(pathinfo($storedFilename, PATHINFO_EXTENSION));
    if (
        $base !== $expectedReservation
        || preg_match('/\A[a-z0-9]{1,16}\z/D', $extension) !== 1
        || !estab_attachment_extension_is_allowed($extension)
    ) {
        throw new InvalidArgumentException('Uploaded filename does not match its reservation');
    }

    $original = isset($data['org_filename']) && is_string($data['org_filename'])
        ? basename(str_replace('\\', '/', trim($data['org_filename'])))
        : '';
    if (!estab_attachment_text_is_valid($original, 255, false)) {
        throw new InvalidArgumentException('Invalid original attachment filename');
    }

    $comment = isset($data['comment']) && is_string($data['comment']) ? trim($data['comment']) : '';
    if (!estab_attachment_text_is_valid($comment, 255, true)) {
        throw new InvalidArgumentException('Invalid attachment comment');
    }

    $sessionCode = strtolower(trim($sessionCode));
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $sessionCode) !== 1) {
        throw new InvalidArgumentException('Invalid attachment user code');
    }

    $timestamp = isset($data['time']) && is_string($data['time']) ? trim($data['time']) : '';
    if (!estab_attachment_validate_sql_datetime($timestamp)) {
        throw new InvalidArgumentException('Invalid attachment timestamp');
    }

    $md5 = isset($data['md5hash']) && is_string($data['md5hash'])
        ? strtolower(trim($data['md5hash']))
        : '';
    if (preg_match('/\A[a-f0-9]{32}\z/D', $md5) !== 1) {
        throw new InvalidArgumentException('Invalid attachment digest');
    }

    return [
        'filename' => $expectedReservation,
        'fileext' => $extension,
        'org_filename' => $original,
        'comment' => $comment,
        'md5hash' => $md5,
        'kuerzel' => $sessionCode,
        'date' => $timestamp,
    ];
}

function estab_attachment_html(mixed $value): string
{
    return estab_auth_html($value);
}

function estab_attachment_statement_error(mysqli_stmt $statement, string $message): never
{
    throw new EstabAttachmentDatabaseException($message, $statement->errno);
}

function estab_attachment_statement_result(
    mysqli_stmt $statement,
    mysqli $connection,
    string $message
): mysqli_result {
    $result = $statement->get_result();
    if (!$result instanceof mysqli_result) {
        throw new EstabAttachmentDatabaseException(
            $message,
            $statement->errno ?: $connection->errno
        );
    }
    return $result;
}

function estab_attachment_statement_row(
    mysqli_stmt $statement,
    mysqli $connection,
    string $message
): ?array {
    $result = estab_attachment_statement_result($statement, $connection, $message);
    try {
        $row = $result->fetch_assoc();
        if ($row === false) {
            throw new EstabAttachmentDatabaseException(
                $message,
                $statement->errno ?: $connection->errno
            );
        }
        return $row;
    } finally {
        $result->free();
    }
}

/** Execute one fixed transaction-control statement through mysqli prepare. */
function estab_attachment_transaction_control(
    mysqli $connection,
    string $statementSql
): void {
    if (!in_array($statementSql, [
        'SAVEPOINT estab_attachment_before_claim',
        'ROLLBACK TO SAVEPOINT estab_attachment_before_claim',
    ], true)) {
        throw new LogicException('Invalid attachment transaction control');
    }
    $statement = $connection->prepare($statementSql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException(
            'Could not prepare upload transaction control',
            $connection->errno
        );
    }
    try {
        if (!$statement->execute()) {
            estab_attachment_statement_error(
                $statement,
                'Could not execute upload transaction control'
            );
        }
    } finally {
        $statement->close();
    }
}

function estab_attachment_connection(array $databaseConfig): mysqli
{
    return estab_auth_connect($databaseConfig);
}

function estab_attachment_close(mysqli $connection): void
{
    estab_auth_close($connection);
}

/** Release an unclaimed reservation after the incident row is locked. */
function estab_attachment_release_unclaimed_for_incident(
    mysqli $connection,
    string $table,
    string $sessionId,
    int $incidentId
): void {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    $incidentId = estab_incident_positive_id($incidentId);
    $sql = 'UPDATE ' . estab_attachment_table($table)
        . " SET `status` = 4, `id` = ''"
        . ' WHERE `id` = ? AND `status` = 8 AND `einsatz_id` = ?';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare reservation release', $connection->errno);
    }
    try {
        $statement->bind_param('si', $sessionId, $incidentId);
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not release old reservation');
        }
    } finally {
        $statement->close();
    }
}

/** Release this session's active-incident reservation atomically. */
function estab_attachment_release_unclaimed(
    mysqli $connection,
    string $table,
    string $sessionId
): void {
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $sessionId
        ): void {
            estab_attachment_release_unclaimed_for_incident(
                $connection,
                $table,
                $sessionId,
                (int) $incident['active_einsatz_id']
            );
        }
    );
}

/**
 * Atomically reserve a reusable or next sequential filename.
 *
 * The unique filename index is the final concurrency guard. Duplicate keys,
 * deadlocks and lock timeouts are retried from a fresh transaction.
 *
 * @param null|callable(int, int): void $retryObserver Receives attempt and
 *     database error code immediately before a retry. Production callers
 *     normally leave this unset; integration tests use it as evidence that
 *     the real rollback/retry branch ran.
 */
function estab_attachment_reserve(
    mysqli $connection,
    string $table,
    string $prefix,
    string $sessionId,
    int $width = 4,
    int $maxAttempts = 8,
    ?callable $retryObserver = null
): string {
    $quotedTable = estab_attachment_table($table);
    $prefix = estab_attachment_validate_prefix($prefix);
    $sessionId = estab_attachment_validate_session_id($sessionId);
    if ($width < 4 || $width > 12) {
        throw new InvalidArgumentException('Invalid attachment sequence width');
    }
    if ($maxAttempts < 1 || $maxAttempts > 50) {
        throw new InvalidArgumentException('Invalid reservation retry count');
    }
    $pattern = '^' . $prefix . '[0-9]{' . $width . ',}$';
    $substringOffset = strlen($prefix) + 1;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        if (!$connection->begin_transaction()) {
            throw new EstabAttachmentDatabaseException('Could not start reservation transaction', $connection->errno);
        }
        try {
            $incident = estab_incident_require_active($connection, true);
            $incidentId = (int) $incident['active_einsatz_id'];
            estab_attachment_release_unclaimed_for_incident(
                $connection,
                $table,
                $sessionId,
                $incidentId
            );

            $reuseSql = 'SELECT `filename` FROM ' . $quotedTable
                . ' WHERE `status` = 4 AND `einsatz_id` = ?'
                . ' AND `filename` REGEXP BINARY ?'
                . ' ORDER BY CAST(SUBSTRING(`filename`, ?) AS UNSIGNED), `filename` LIMIT 1 FOR UPDATE';
            $reuse = $connection->prepare($reuseSql);
            if (!$reuse) {
                throw new EstabAttachmentDatabaseException('Could not prepare reusable reservation lookup', $connection->errno);
            }
            try {
                $reuse->bind_param('isi', $incidentId, $pattern, $substringOffset);
                if (!$reuse->execute()) {
                    estab_attachment_statement_error($reuse, 'Could not find reusable reservation');
                }
                $row = estab_attachment_statement_row(
                    $reuse,
                    $connection,
                    'Could not read reusable reservation result'
                );
            } finally {
                $reuse->close();
            }

            if (is_array($row ?? null)) {
                $candidate = estab_attachment_validate_reservation_name((string) $row['filename'], $prefix);
                $updateSql = 'UPDATE ' . $quotedTable
                    . ' SET `status` = 8, `id` = ?'
                    . ' WHERE `filename` = ? AND `status` = 4'
                    . ' AND `einsatz_id` = ?';
                $update = $connection->prepare($updateSql);
                if (!$update) {
                    throw new EstabAttachmentDatabaseException('Could not prepare reusable reservation', $connection->errno);
                }
                try {
                    $update->bind_param('ssi', $sessionId, $candidate, $incidentId);
                    if (!$update->execute()) {
                        estab_attachment_statement_error($update, 'Could not reserve reusable filename');
                    }
                    $reserved = $update->affected_rows === 1;
                } finally {
                    $update->close();
                }
                if ($reserved) {
                    if (!$connection->commit()) {
                        throw new EstabAttachmentDatabaseException('Could not commit reusable reservation', $connection->errno);
                    }
                    return $candidate;
                }
                throw new EstabAttachmentDatabaseException('Reusable reservation changed concurrently', 1213);
            }

            $highestSql = 'SELECT `filename` FROM ' . $quotedTable
                . ' WHERE `filename` REGEXP BINARY ?'
                . ' ORDER BY CAST(SUBSTRING(`filename`, ?) AS UNSIGNED) DESC LIMIT 1 FOR UPDATE';
            $highestStatement = $connection->prepare($highestSql);
            if (!$highestStatement) {
                throw new EstabAttachmentDatabaseException('Could not prepare filename sequence lookup', $connection->errno);
            }
            try {
                $highestStatement->bind_param('si', $pattern, $substringOffset);
                if (!$highestStatement->execute()) {
                    estab_attachment_statement_error($highestStatement, 'Could not read filename sequence');
                }
                $highestRow = estab_attachment_statement_row(
                    $highestStatement,
                    $connection,
                    'Could not read filename sequence result'
                );
            } finally {
                $highestStatement->close();
            }

            $highest = 0;
            if (is_array($highestRow ?? null)) {
                $highestName = estab_attachment_validate_reservation_name((string) $highestRow['filename'], $prefix);
                $highest = (int) substr($highestName, strlen($prefix));
            }
            $candidate = estab_attachment_next_name($prefix, $highest, $width);

            $insertSql = 'INSERT INTO ' . $quotedTable
                . ' (`einsatz_id`, `filename`, `status`, `id`)'
                . ' VALUES (?, ?, 8, ?)';
            $insert = $connection->prepare($insertSql);
            if (!$insert) {
                throw new EstabAttachmentDatabaseException('Could not prepare filename reservation', $connection->errno);
            }
            try {
                $insert->bind_param('iss', $incidentId, $candidate, $sessionId);
                if (!$insert->execute()) {
                    estab_attachment_statement_error($insert, 'Could not reserve next filename');
                }
            } finally {
                $insert->close();
            }
            if (!$connection->commit()) {
                throw new EstabAttachmentDatabaseException('Could not commit filename reservation', $connection->errno);
            }
            return $candidate;
        } catch (Throwable $exception) {
            $connection->rollback();
            if (
                ($exception instanceof EstabAttachmentDatabaseException
                    || $exception instanceof mysqli_sql_exception)
                && estab_attachment_database_error_is_retryable($exception->getCode())
                && $attempt < $maxAttempts
            ) {
                if ($retryObserver !== null) {
                    $retryObserver($attempt, (int) $exception->getCode());
                }
                continue;
            }
            throw $exception;
        }
    }
    throw new EstabAttachmentDatabaseException('Attachment reservation attempts exhausted');
}

/** Atomically claim an owned active reservation before moving upload bytes. */
function estab_attachment_claim(
    mysqli $connection,
    string $table,
    string $filename,
    string $sessionId
): bool {
    $filename = estab_attachment_validate_reservation_name($filename);
    $sessionId = estab_attachment_validate_session_id($sessionId);
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $filename,
            $sessionId
        ): bool {
            $incidentId = (int) $incident['active_einsatz_id'];
            $sql = 'UPDATE ' . estab_attachment_table($table)
                . ' SET `status` = 2'
                . ' WHERE `filename` = ? AND `status` = 8 AND `id` = ?'
                . ' AND `einsatz_id` = ?';
            $statement = $connection->prepare($sql);
            if (!$statement) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare reservation claim',
                    $connection->errno
                );
            }
            try {
                $statement->bind_param(
                    'ssi',
                    $filename,
                    $sessionId,
                    $incidentId
                );
                if (!$statement->execute()) {
                    estab_attachment_statement_error(
                        $statement,
                        'Could not claim reservation'
                    );
                }
                return $statement->affected_rows === 1;
            } finally {
                $statement->close();
            }
        }
    );
}

/** Release only this session's unfinished reservation/claim. */
function estab_attachment_release(
    mysqli $connection,
    string $table,
    string $sessionId,
    ?string $filename = null
): void {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    if ($filename !== null) {
        $filename = estab_attachment_validate_reservation_name($filename);
    }
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $sessionId,
            $filename
        ): void {
            $incidentId = (int) $incident['active_einsatz_id'];
            if ($filename === null) {
                $sql = 'UPDATE ' . estab_attachment_table($table)
                    . " SET `status` = 4, `id` = ''"
                    . ' WHERE `id` = ? AND `status` IN (2, 8)'
                    . ' AND `einsatz_id` = ?';
                $statement = $connection->prepare($sql);
                if (!$statement) {
                    throw new EstabAttachmentDatabaseException(
                        'Could not prepare reservation cancellation',
                        $connection->errno
                    );
                }
                try {
                    $statement->bind_param('si', $sessionId, $incidentId);
                    if (!$statement->execute()) {
                        estab_attachment_statement_error(
                            $statement,
                            'Could not cancel reservations'
                        );
                    }
                } finally {
                    $statement->close();
                }
                return;
            }

            $sql = 'UPDATE ' . estab_attachment_table($table)
                . " SET `status` = 4, `id` = ''"
                . ' WHERE `filename` = ? AND `id` = ?'
                . ' AND `status` IN (2, 8) AND `einsatz_id` = ?';
            $statement = $connection->prepare($sql);
            if (!$statement) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare reservation release',
                    $connection->errno
                );
            }
            try {
                $statement->bind_param(
                    'ssi',
                    $filename,
                    $sessionId,
                    $incidentId
                );
                if (!$statement->execute()) {
                    estab_attachment_statement_error(
                        $statement,
                        'Could not release reservation'
                    );
                }
            } finally {
                $statement->close();
            }
        }
    );
}

/** Finalise only the current session's claimed reservation. */
function estab_attachment_finalize(
    mysqli $connection,
    string $table,
    string $sessionId,
    array $metadata
): bool {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    $filename = estab_attachment_validate_reservation_name((string) ($metadata['filename'] ?? ''));
    $extension = (string) $metadata['fileext'];
    $original = (string) $metadata['org_filename'];
    $comment = (string) $metadata['comment'];
    $md5 = (string) $metadata['md5hash'];
    $code = (string) $metadata['kuerzel'];
    $date = (string) $metadata['date'];
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $extension,
            $original,
            $comment,
            $md5,
            $code,
            $date,
            $filename,
            $sessionId
        ): bool {
            $incidentId = (int) $incident['active_einsatz_id'];
            $sql = 'UPDATE ' . estab_attachment_table($table)
                . ' SET `fileext` = ?, `org_filename` = ?, `comment` = ?,'
                . ' `md5hash` = ?,'
                . " `kuerzel` = ?, `date` = ?, `status` = 1, `id` = ''"
                . ' WHERE `filename` = ? AND `status` = 2 AND `id` = ?'
                . ' AND `einsatz_id` = ?';
            $statement = $connection->prepare($sql);
            if (!$statement) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare attachment finalisation',
                    $connection->errno
                );
            }
            try {
                $statement->bind_param(
                    'ssssssssi',
                    $extension,
                    $original,
                    $comment,
                    $md5,
                    $code,
                    $date,
                    $filename,
                    $sessionId,
                    $incidentId
                );
                if (!$statement->execute()) {
                    estab_attachment_statement_error(
                        $statement,
                        'Could not finalise attachment'
                    );
                }
                return $statement->affected_rows === 1;
            } finally {
                $statement->close();
            }
        }
    );
}

/**
 * Store one browser upload while the global incident cannot change.
 *
 * The callback moves the already validated PHP upload into the persistent
 * directory and returns its metadata. Claim, metadata row, audit row and the
 * active incident are one transaction. On every failure the reservation is
 * released before the incident lock is given up; the caller removes any
 * already moved file in its finally block.
 *
 * @param callable():array<string,mixed> $storeAndDescribe
 * @param callable(array<string,string>):string $auditDetails
 * @return array<string,string>|null
 */
function estab_attachment_store_upload(
    mysqli $connection,
    string $attachmentTable,
    string $protocolTable,
    string $reservation,
    string $sessionId,
    string $sessionCode,
    string $event,
    callable $storeAndDescribe,
    callable $auditDetails
): ?array {
    $reservation = estab_attachment_validate_reservation_name($reservation);
    $sessionId = estab_attachment_validate_session_id($sessionId);
    if (!estab_attachment_text_is_valid($event, 30, false)) {
        throw new InvalidArgumentException('Invalid attachment audit event');
    }
    if (!$connection->begin_transaction()) {
        throw new EstabAttachmentDatabaseException(
            'Could not start atomic upload transaction',
            $connection->errno
        );
    }
    $transactionActive = true;
    try {
        $incident = estab_incident_require_active($connection, true);
        $incidentId = (int) $incident['active_einsatz_id'];
        estab_attachment_transaction_control(
            $connection,
            'SAVEPOINT estab_attachment_before_claim'
        );

        $claim = $connection->prepare(
            'UPDATE ' . estab_attachment_table($attachmentTable)
            . ' SET `status` = 2'
            . ' WHERE `filename` = ? AND `status` = 8 AND `id` = ?'
            . ' AND `einsatz_id` = ?'
        );
        if (!$claim) {
            throw new EstabAttachmentDatabaseException(
                'Could not prepare atomic upload claim',
                $connection->errno
            );
        }
        try {
            $claim->bind_param(
                'ssi',
                $reservation,
                $sessionId,
                $incidentId
            );
            if (!$claim->execute()) {
                estab_attachment_statement_error(
                    $claim,
                    'Could not claim atomic upload reservation'
                );
            }
            $claimed = $claim->affected_rows === 1;
        } finally {
            $claim->close();
        }
        if (!$claimed) {
            $connection->rollback();
            $transactionActive = false;
            return null;
        }

        try {
            $rawMetadata = $storeAndDescribe();
            if (!is_array($rawMetadata)) {
                throw new RuntimeException('Upload callback returned no metadata');
            }
            $metadata = estab_attachment_validate_metadata(
                $rawMetadata,
                $reservation,
                $sessionCode
            );

            $finalize = $connection->prepare(
                'UPDATE ' . estab_attachment_table($attachmentTable)
                . ' SET `fileext` = ?, `org_filename` = ?, `comment` = ?,'
                . ' `md5hash` = ?, `kuerzel` = ?, `date` = ?,'
                . " `status` = 1, `id` = ''"
                . ' WHERE `filename` = ? AND `status` = 2 AND `id` = ?'
                . ' AND `einsatz_id` = ?'
            );
            if (!$finalize) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare atomic upload finalisation',
                    $connection->errno
                );
            }
            try {
                $finalize->bind_param(
                    'ssssssssi',
                    $metadata['fileext'],
                    $metadata['org_filename'],
                    $metadata['comment'],
                    $metadata['md5hash'],
                    $metadata['kuerzel'],
                    $metadata['date'],
                    $metadata['filename'],
                    $sessionId,
                    $incidentId
                );
                if (
                    !$finalize->execute()
                    || $finalize->affected_rows !== 1
                ) {
                    throw new EstabAttachmentDatabaseException(
                        'Could not finalise atomic upload',
                        $finalize->errno
                    );
                }
            } finally {
                $finalize->close();
            }

            $details = $auditDetails($metadata);
            if (
                !is_string($details)
                || !estab_attachment_text_is_valid($details, 65535, true)
            ) {
                throw new InvalidArgumentException(
                    'Invalid atomic upload audit details'
                );
            }
            $audit = $connection->prepare(
                'INSERT INTO ' . estab_attachment_table($protocolTable)
                . ' (`einsatz_id`, `p_zeit`, `p_was`, `p_ereignis`)'
                . ' VALUES (?, NOW(), ?, ?)'
            );
            if (!$audit) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare atomic upload audit',
                    $connection->errno
                );
            }
            try {
                $audit->bind_param('iss', $incidentId, $event, $details);
                if (!$audit->execute()) {
                    estab_attachment_statement_error(
                        $audit,
                        'Could not write atomic upload audit'
                    );
                }
            } finally {
                $audit->close();
            }

            if (!$connection->commit()) {
                throw new EstabAttachmentDatabaseException(
                    'Could not commit atomic upload',
                    $connection->errno
                );
            }
            $transactionActive = false;
            return $metadata;
        } catch (Throwable $exception) {
            if ($transactionActive) {
                try {
                    estab_attachment_transaction_control(
                        $connection,
                        'ROLLBACK TO SAVEPOINT estab_attachment_before_claim'
                    );
                    $release = $connection->prepare(
                        'UPDATE ' . estab_attachment_table($attachmentTable)
                        . " SET `status` = 4, `id` = ''"
                        . ' WHERE `filename` = ? AND `status` = 8'
                        . ' AND `id` = ? AND `einsatz_id` = ?'
                    );
                    if (!$release) {
                        throw new EstabAttachmentDatabaseException(
                            'Could not prepare failed upload release',
                            $connection->errno
                        );
                    }
                    try {
                        $release->bind_param(
                            'ssi',
                            $reservation,
                            $sessionId,
                            $incidentId
                        );
                        if (!$release->execute()) {
                            estab_attachment_statement_error(
                                $release,
                                'Could not release failed upload'
                            );
                        }
                    } finally {
                        $release->close();
                    }
                    if (!$connection->commit()) {
                        throw new EstabAttachmentDatabaseException(
                            'Could not commit failed upload release',
                            $connection->errno
                        );
                    }
                    $transactionActive = false;
                } catch (Throwable $cleanupException) {
                    $connection->rollback();
                    $transactionActive = false;
                    error_log(
                        'eStab atomic upload cleanup failed: '
                        . $cleanupException->getMessage()
                    );
                }
            }
            throw $exception;
        }
    } catch (Throwable $exception) {
        if ($transactionActive) {
            $connection->rollback();
            $transactionActive = false;
        }
        throw $exception;
    } finally {
        if ($transactionActive) {
            $connection->rollback();
        }
    }
}

function estab_attachment_list(mysqli $connection, string $table): array
{
    $incident = estab_incident_active($connection);
    if ($incident === null) {
        return [];
    }
    $incidentId = (int) $incident['active_einsatz_id'];
    $sql = 'SELECT `filename`, `fileext`, `org_filename`, `comment`, `md5hash`, `date`, `kuerzel`'
        . ' FROM ' . estab_attachment_table($table)
        . ' WHERE `status` = 1 AND `einsatz_id` = ?'
        . ' ORDER BY `filename` DESC';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare attachment listing', $connection->errno);
    }
    try {
        $statement->bind_param('i', $incidentId);
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not list attachments');
        }
        $result = estab_attachment_statement_result(
            $statement,
            $connection,
            'Could not read attachment listing result'
        );
        try {
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
    } finally {
        $statement->close();
    }
}

function estab_attachment_find(
    mysqli $connection,
    string $table,
    string $filename,
    bool $forUpdate = false
): ?array
{
    $filename = estab_attachment_validate_reservation_name($filename);
    $incident = $forUpdate
        ? estab_incident_require_active($connection, true)
        : estab_incident_active($connection);
    if ($incident === null) {
        return null;
    }
    $incidentId = (int) $incident['active_einsatz_id'];
    $sql = 'SELECT `filename`, `fileext`, `org_filename`, `comment`, `md5hash`, `date`, `kuerzel`'
        . ' FROM ' . estab_attachment_table($table)
        . ' WHERE `filename` = ? AND `status` = 1'
        . ' AND `einsatz_id` = ? LIMIT 1';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare attachment lookup', $connection->errno);
    }
    try {
        $statement->bind_param('si', $filename, $incidentId);
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not find attachment');
        }
        $row = estab_attachment_statement_row(
            $statement,
            $connection,
            'Could not read attachment lookup result'
        );
        if (
            !is_array($row)
            || !isset($row['filename'])
            || !is_string($row['filename'])
            || !hash_equals($filename, $row['filename'])
        ) {
            return null;
        }
        return $row;
    } finally {
        $statement->close();
    }
}

/** Prepared audit insert for attachment-influenced values. */
function estab_attachment_log(
    mysqli $connection,
    string $protocolTable,
    string $event,
    string $details,
    ?string $attachmentTable = null,
    ?string $attachmentFilename = null
): void {
    if (!estab_attachment_text_is_valid($event, 30, false)) {
        throw new InvalidArgumentException('Invalid attachment audit event');
    }
    if (!estab_attachment_text_is_valid($details, 65535, true)) {
        throw new InvalidArgumentException('Invalid attachment audit details');
    }
    if (($attachmentTable === null) !== ($attachmentFilename === null)) {
        throw new InvalidArgumentException('Incomplete attachment audit scope');
    }
    if ($attachmentFilename !== null) {
        $attachmentFilename = estab_attachment_validate_reservation_name(
            $attachmentFilename
        );
    }
    $timestamp = date('Y-m-d H:i:s');
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $protocolTable,
            $event,
            $details,
            $timestamp,
            $attachmentTable,
            $attachmentFilename
        ): void {
            $incidentId = (int) $incident['active_einsatz_id'];
            if ($attachmentTable !== null && $attachmentFilename !== null) {
                $scope = $connection->prepare(
                    'SELECT COUNT(*) AS `scope_count` FROM '
                    . estab_attachment_table($attachmentTable)
                    . ' WHERE `filename` = ? AND `einsatz_id` = ?'
                );
                if (!$scope) {
                    throw new EstabAttachmentDatabaseException(
                        'Could not prepare attachment audit scope',
                        $connection->errno
                    );
                }
                try {
                    $scope->bind_param(
                        'si',
                        $attachmentFilename,
                        $incidentId
                    );
                    if (!$scope->execute()) {
                        estab_attachment_statement_error(
                            $scope,
                            'Could not verify attachment audit scope'
                        );
                    }
                    $row = estab_attachment_statement_row(
                        $scope,
                        $connection,
                        'Could not read attachment audit scope'
                    );
                    if ((int) ($row['scope_count'] ?? 0) !== 1) {
                        throw new EstabAttachmentDatabaseException(
                            'Attachment incident changed before audit'
                        );
                    }
                } finally {
                    $scope->close();
                }
            }

            $sql = 'INSERT INTO ' . estab_attachment_table($protocolTable)
                . ' (`einsatz_id`, `p_zeit`, `p_was`, `p_ereignis`)'
                . ' VALUES (?, ?, ?, ?)';
            $statement = $connection->prepare($sql);
            if (!$statement) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare attachment audit insert',
                    $connection->errno
                );
            }
            try {
                $statement->bind_param(
                    'isss',
                    $incidentId,
                    $timestamp,
                    $event,
                    $details
                );
                if (!$statement->execute()) {
                    estab_attachment_statement_error(
                        $statement,
                        'Could not write attachment audit event'
                    );
                }
            } finally {
                $statement->close();
            }
        }
    );
}
