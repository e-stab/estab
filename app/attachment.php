<?php

/**
 * Concurrency-safe persistence boundary for attachment reservations/uploads.
 */

require_once __DIR__ . '/auth.php';

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

function estab_attachment_connection(array $databaseConfig): mysqli
{
    return estab_auth_connect($databaseConfig);
}

function estab_attachment_close(mysqli $connection): void
{
    estab_auth_close($connection);
}

/** Release this session's unclaimed reservation before displaying a new form. */
function estab_attachment_release_unclaimed(
    mysqli $connection,
    string $table,
    string $sessionId
): void {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    $sql = 'UPDATE ' . estab_attachment_table($table)
        . " SET `status` = 4, `id` = '' WHERE `id` = ? AND `status` = 8";
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare reservation release', $connection->errno);
    }
    try {
        $statement->bind_param('s', $sessionId);
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not release old reservation');
        }
    } finally {
        $statement->close();
    }
}

/**
 * Atomically reserve a reusable or next sequential filename.
 *
 * The unique filename index is the final concurrency guard. Duplicate keys,
 * deadlocks and lock timeouts are retried from a fresh transaction.
 */
function estab_attachment_reserve(
    mysqli $connection,
    string $table,
    string $prefix,
    string $sessionId,
    int $width = 4,
    int $maxAttempts = 8
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
            estab_attachment_release_unclaimed($connection, $table, $sessionId);

            $reuseSql = 'SELECT `filename` FROM ' . $quotedTable
                . ' WHERE `status` = 4 AND `filename` REGEXP BINARY ?'
                . ' ORDER BY CAST(SUBSTRING(`filename`, ?) AS UNSIGNED), `filename` LIMIT 1 FOR UPDATE';
            $reuse = $connection->prepare($reuseSql);
            if (!$reuse) {
                throw new EstabAttachmentDatabaseException('Could not prepare reusable reservation lookup', $connection->errno);
            }
            try {
                $reuse->bind_param('si', $pattern, $substringOffset);
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
                    . ' SET `status` = 8, `id` = ? WHERE `filename` = ? AND `status` = 4';
                $update = $connection->prepare($updateSql);
                if (!$update) {
                    throw new EstabAttachmentDatabaseException('Could not prepare reusable reservation', $connection->errno);
                }
                try {
                    $update->bind_param('ss', $sessionId, $candidate);
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

            $insertSql = 'INSERT INTO ' . $quotedTable . ' (`filename`, `status`, `id`) VALUES (?, 8, ?)';
            $insert = $connection->prepare($insertSql);
            if (!$insert) {
                throw new EstabAttachmentDatabaseException('Could not prepare filename reservation', $connection->errno);
            }
            try {
                $insert->bind_param('ss', $candidate, $sessionId);
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
    $sql = 'UPDATE ' . estab_attachment_table($table)
        . ' SET `status` = 2 WHERE `filename` = ? AND `status` = 8 AND `id` = ?';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare reservation claim', $connection->errno);
    }
    try {
        $statement->bind_param('ss', $filename, $sessionId);
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not claim reservation');
        }
        return $statement->affected_rows === 1;
    } finally {
        $statement->close();
    }
}

/** Release only this session's unfinished reservation/claim. */
function estab_attachment_release(
    mysqli $connection,
    string $table,
    string $sessionId,
    ?string $filename = null
): void {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    if ($filename === null) {
        $sql = 'UPDATE ' . estab_attachment_table($table)
            . " SET `status` = 4, `id` = '' WHERE `id` = ? AND `status` IN (2, 8)";
        $statement = $connection->prepare($sql);
        if (!$statement) {
            throw new EstabAttachmentDatabaseException('Could not prepare reservation cancellation', $connection->errno);
        }
        try {
            $statement->bind_param('s', $sessionId);
            if (!$statement->execute()) {
                estab_attachment_statement_error($statement, 'Could not cancel reservations');
            }
        } finally {
            $statement->close();
        }
        return;
    }

    $filename = estab_attachment_validate_reservation_name($filename);
    $sql = 'UPDATE ' . estab_attachment_table($table)
        . " SET `status` = 4, `id` = '' WHERE `filename` = ? AND `id` = ? AND `status` IN (2, 8)";
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare reservation release', $connection->errno);
    }
    try {
        $statement->bind_param('ss', $filename, $sessionId);
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not release reservation');
        }
    } finally {
        $statement->close();
    }
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
    $sql = 'UPDATE ' . estab_attachment_table($table)
        . ' SET `fileext` = ?, `org_filename` = ?, `comment` = ?, `md5hash` = ?,'
        . " `kuerzel` = ?, `date` = ?, `status` = 1, `id` = ''"
        . ' WHERE `filename` = ? AND `status` = 2 AND `id` = ?';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare attachment finalisation', $connection->errno);
    }
    $extension = (string) $metadata['fileext'];
    $original = (string) $metadata['org_filename'];
    $comment = (string) $metadata['comment'];
    $md5 = (string) $metadata['md5hash'];
    $code = (string) $metadata['kuerzel'];
    $date = (string) $metadata['date'];
    try {
        $statement->bind_param(
            'ssssssss',
            $extension,
            $original,
            $comment,
            $md5,
            $code,
            $date,
            $filename,
            $sessionId
        );
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not finalise attachment');
        }
        return $statement->affected_rows === 1;
    } finally {
        $statement->close();
    }
}

function estab_attachment_list(mysqli $connection, string $table): array
{
    $sql = 'SELECT `filename`, `fileext`, `org_filename`, `comment`, `md5hash`, `date`, `kuerzel`'
        . ' FROM ' . estab_attachment_table($table) . ' WHERE `status` = 1 ORDER BY `filename` DESC';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare attachment listing', $connection->errno);
    }
    try {
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

function estab_attachment_find(mysqli $connection, string $table, string $filename): ?array
{
    $filename = estab_attachment_validate_reservation_name($filename);
    $sql = 'SELECT `filename`, `fileext`, `org_filename`, `comment`, `md5hash`, `date`, `kuerzel`'
        . ' FROM ' . estab_attachment_table($table)
        . ' WHERE `filename` = ? AND `status` = 1 LIMIT 1';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare attachment lookup', $connection->errno);
    }
    try {
        $statement->bind_param('s', $filename);
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not find attachment');
        }
        $row = estab_attachment_statement_row(
            $statement,
            $connection,
            'Could not read attachment lookup result'
        );
        return is_array($row) ? $row : null;
    } finally {
        $statement->close();
    }
}

/** Prepared audit insert for attachment-influenced values. */
function estab_attachment_log(
    mysqli $connection,
    string $protocolTable,
    string $event,
    string $details
): void {
    if (!estab_attachment_text_is_valid($event, 30, false)) {
        throw new InvalidArgumentException('Invalid attachment audit event');
    }
    if (!estab_attachment_text_is_valid($details, 65535, true)) {
        throw new InvalidArgumentException('Invalid attachment audit details');
    }
    $timestamp = date('Y-m-d H:i:s');
    $sql = 'INSERT INTO ' . estab_attachment_table($protocolTable)
        . ' (`p_zeit`, `p_was`, `p_ereignis`) VALUES (?, ?, ?)';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare attachment audit insert', $connection->errno);
    }
    try {
        $statement->bind_param('sss', $timestamp, $event, $details);
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not write attachment audit event');
        }
    } finally {
        $statement->close();
    }
}
