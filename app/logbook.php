<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dv_operations.php';
require_once __DIR__ . '/incident.php';

const ESTAB_LOGBOOK_TITLE_MAX_LENGTH = 255;
const ESTAB_LOGBOOK_TEXT_MAX_LENGTH = 10000;
const ESTAB_LOGBOOK_REFERENCE_MAX_LENGTH = 255;

/** @return array<string, string> */
function estab_logbook_entry_types(): array
{
    return [
        'ereignis' => 'Ereignis',
        'entscheidung' => 'Entscheidung',
        'lagebesprechung' => 'Lagebesprechung',
        'auftrag' => 'Auftrag',
        'information' => 'Information',
        'korrektur' => 'Korrektur',
    ];
}

/** Accept browser datetime-local and already normalised database timestamps. */
function estab_logbook_event_time(mixed $value): ?string
{
    if (!is_string($value)) {
        throw new EstabIncidentInputException('Ereigniszeit ist ungültig.');
    }
    $candidate = trim($value);
    if (
        preg_match(
            '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',
            $candidate
        ) === 1
    ) {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $candidate,
            new DateTimeZone(date_default_timezone_get())
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date instanceof DateTimeImmutable
            && ($errors === false || (
                (int) ($errors['warning_count'] ?? 0) === 0
                && (int) ($errors['error_count'] ?? 0) === 0
            ))
            && $date->format('Y-m-d H:i:s') === $candidate
        ) {
            return $candidate;
        }
        throw new EstabIncidentInputException('Ereigniszeit ist ungültig.');
    }
    return estab_incident_datetime($candidate, 'Ereigniszeit', true);
}

/** Return the global active incident for ETB/TBB display, or null. */
function estab_logbook_active_incident(array $databaseConfig): ?array
{
    $connection = estab_auth_connect($databaseConfig);
    try {
        return estab_incident_active($connection);
    } finally {
        estab_auth_close($connection);
    }
}

/**
 * Read only logbook rows belonging to the global active incident.
 *
 * @return list<array<string, mixed>>
 */
function estab_logbook_entries(
    array $databaseConfig,
    string $table,
    string $kind
): array {
    if (!in_array($kind, ['etb', 'tbb'], true)) {
        throw new InvalidArgumentException('Invalid logbook kind');
    }
    $connection = estab_auth_connect($databaseConfig);
    try {
        $incident = estab_incident_active($connection);
        if ($incident === null) {
            return [];
        }
        $orderColumn = $kind . '_lfd-nr';
        $orderSql = $kind === 'etb'
            ? '`estab_event_time` DESC, `' . $orderColumn . '` DESC'
            : '`' . $orderColumn . '` DESC';
        $statement = $connection->prepare(
            'SELECT * FROM ' . estab_auth_table($table)
            . ' WHERE `einsatz_id` = ?'
            . ' ORDER BY ' . $orderSql
        );
        if (!$statement) {
            throw new RuntimeException('Could not prepare logbook listing');
        }
        try {
            $incidentId = (int) $incident['active_einsatz_id'];
            $statement->bind_param('i', $incidentId);
            if (!$statement->execute()) {
                throw new RuntimeException('Could not execute logbook listing');
            }
            $result = $statement->get_result();
            try {
                return $result->fetch_all(MYSQLI_ASSOC);
            } finally {
                $result->free();
            }
        } finally {
            $statement->close();
        }
    } finally {
        estab_auth_close($connection);
    }
}

/**
 * Validate and normalize the initial operation title.
 *
 * VARCHAR limits are measured as Unicode characters. Control characters are
 * rejected because both values are rendered as single-line labels.
 */
function estab_logbook_validate_title(array $input): array
{
    $values = [];
    $errors = [];
    foreach (['einsatz', 'ort'] as $field) {
        $value = isset($input[$field]) && is_string($input[$field])
            ? trim($input[$field])
            : '';
        $length = estab_auth_text_length($value);
        if (
            $length < 1
            || $length > ESTAB_LOGBOOK_TITLE_MAX_LENGTH
            || preg_match('/\p{C}/u', $value) === 1
        ) {
            $errors[] = $field;
        }
        $values[$field] = $value;
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
        'data' => $values,
    ];
}

/**
 * Validate a logbook event and optional comment before writing a TEXT column.
 */
function estab_logbook_validate_entry(array $input): array
{
    $event = isset($input['event']) && is_string($input['event'])
        ? trim($input['event'])
        : '';
    $comment = isset($input['comment']) && is_string($input['comment'])
        ? trim($input['comment'])
        : '';
    $errors = [];
    $eventType = isset($input['event_type']) && is_string($input['event_type'])
        ? trim($input['event_type'])
        : 'ereignis';
    if (!array_key_exists($eventType, estab_logbook_entry_types())) {
        $errors[] = 'event_type';
    }

    try {
        $eventTime = estab_logbook_event_time(
            $input['event_time'] ?? date('Y-m-d\TH:i'),
        );
    } catch (EstabIncidentInputException) {
        $eventTime = null;
        $errors[] = 'event_time';
    }

    $messageId = null;
    $attachmentId = null;
    $correctionOf = null;
    foreach ([
        'message_id' => &$messageId,
        'attachment_id' => &$attachmentId,
        'correction_of' => &$correctionOf,
    ] as $field => &$target) {
        $candidate = $input[$field] ?? null;
        if ($candidate === null || $candidate === '') {
            $target = null;
            continue;
        }
        try {
            $target = estab_incident_positive_id($candidate, $field);
        } catch (EstabIncidentInputException) {
            $target = null;
            $errors[] = $field;
        }
    }
    unset($target);

    if (
        ($eventType === 'korrektur' && $correctionOf === null)
        || ($eventType !== 'korrektur' && $correctionOf !== null)
    ) {
        $errors[] = 'correction_of';
    }

    $reference = isset($input['reference']) && is_string($input['reference'])
        ? trim($input['reference'])
        : '';
    $referenceLength = estab_auth_text_length($reference);
    if (
        $referenceLength < 0
        || $referenceLength > ESTAB_LOGBOOK_REFERENCE_MAX_LENGTH
        || preg_match('/\p{C}/u', $reference) === 1
    ) {
        $errors[] = 'reference';
    }

    $eventLength = estab_auth_text_length($event);
    if (
        $eventLength < 1
        || $eventLength > ESTAB_LOGBOOK_TEXT_MAX_LENGTH
        || strlen($event) > 65535
        || str_contains($event, "\0")
    ) {
        $errors[] = 'event';
    }

    $commentLength = estab_auth_text_length($comment);
    if (
        $commentLength < 0
        || $commentLength > ESTAB_LOGBOOK_TEXT_MAX_LENGTH
        || strlen($comment) > 65535
        || str_contains($comment, "\0")
    ) {
        $errors[] = 'comment';
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
        'data' => [
            'event' => $event,
            'comment' => $comment,
            'event_time' => $eventTime,
            'event_type' => $eventType,
            'message_id' => $messageId,
            'attachment_id' => $attachmentId,
            'reference' => $reference === '' ? null : $reference,
            'correction_of' => $correctionOf,
        ],
    ];
}

/**
 * Lock one referenced operational record and return its ownership metadata.
 *
 * The SQL is assembled exclusively by estab_logbook_validate_references()
 * from fixed table/column names (or an identifier accepted by
 * estab_auth_table()). Values remain bound parameters.
 *
 * @return array<string, mixed>|null
 */
function estab_logbook_reference_row(
    mysqli $connection,
    string $sql,
    int $referenceId
): ?array {
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Logbuchbezug konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('i', $referenceId);
        if (!$statement->execute()) {
            throw new RuntimeException('Logbuchbezug konnte nicht geprüft werden.');
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    } finally {
        $statement->close();
    }
}

/**
 * Prove that every optional ETB reference belongs to the active incident.
 *
 * This runs inside estab_incident_with_active_write() and locks every target
 * until the ETB insert commits. The database trigger repeats the invariant as
 * a defence-in-depth boundary for writes that bypass this application API.
 *
 * Corrections always point directly to an immutable original entry. Pointing
 * to another correction would create an ambiguous correction chain.
 */
function estab_logbook_validate_references(
    mysqli $connection,
    int $incidentId,
    string $table,
    array $entry
): void {
    $incidentId = estab_incident_positive_id($incidentId);
    $messageId = $entry['message_id'] ?? null;
    if ($messageId !== null) {
        $messageId = estab_incident_positive_id($messageId, 'Nachrichten-ID');
        $message = estab_logbook_reference_row(
            $connection,
            'SELECT `einsatz_id` FROM `nv_nachrichten`'
                . ' WHERE `00_lfd` = ? LIMIT 1 FOR UPDATE',
            $messageId
        );
        if (
            !is_array($message)
            || (int) ($message['einsatz_id'] ?? 0) !== $incidentId
        ) {
            throw new EstabIncidentConflictException(
                'Die referenzierte Nachricht gehört nicht zum aktiven Einsatz.'
            );
        }
    }

    $attachmentId = $entry['attachment_id'] ?? null;
    if ($attachmentId !== null) {
        $attachmentId = estab_incident_positive_id(
            $attachmentId,
            'Anhangs-ID'
        );
        $attachment = estab_logbook_reference_row(
            $connection,
            'SELECT `einsatz_id` FROM `nv_anhang`'
                . ' WHERE `lfd-nr` = ? LIMIT 1 FOR UPDATE',
            $attachmentId
        );
        if (
            !is_array($attachment)
            || (int) ($attachment['einsatz_id'] ?? 0) !== $incidentId
        ) {
            throw new EstabIncidentConflictException(
                'Der referenzierte Anhang gehört nicht zum aktiven Einsatz.'
            );
        }
    }

    $correctionOf = $entry['correction_of'] ?? null;
    if ($correctionOf === null) {
        return;
    }
    $correctionOf = estab_incident_positive_id(
        $correctionOf,
        'Korrekturbezug'
    );
    $original = estab_logbook_reference_row(
        $connection,
        'SELECT `einsatz_id`, `estab_event_type`, `estab_correction_of`'
            . ' FROM ' . estab_auth_table($table)
            . ' WHERE `etb_lfd-nr` = ? LIMIT 1 FOR UPDATE',
        $correctionOf
    );
    if (
        !is_array($original)
        || (int) ($original['einsatz_id'] ?? 0) !== $incidentId
    ) {
        throw new EstabIncidentConflictException(
            'Der Korrekturbezug gehört nicht zum aktiven Einsatz.'
        );
    }
    if (
        ($original['estab_event_type'] ?? null) === 'korrektur'
        || ($original['estab_correction_of'] ?? null) !== null
    ) {
        throw new EstabIncidentConflictException(
            'Eine Korrektur muss direkt auf den ursprünglichen ETB-Eintrag '
            . 'verweisen.'
        );
    }
}

/** Test whether a configured table exists without interpolating its name. */
function estab_logbook_table_exists(array $databaseConfig, string $table): bool
{
    // Apply the same identifier policy as every query, even though this
    // lookup binds the name as data.
    estab_auth_table($table);
    $connection = estab_auth_connect($databaseConfig);
    try {
        $statement = $connection->prepare(
            'SELECT 1 FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        if (!$statement) {
            throw new RuntimeException('Could not prepare logbook table lookup');
        }
        try {
            $statement->bind_param('s', $table);
            if (!$statement->execute()) {
                throw new RuntimeException('Could not execute logbook table lookup');
            }
            $result = $statement->get_result();
            $exists = $result->fetch_row() !== null;
            $result->free();
            return $exists;
        } finally {
            $statement->close();
        }
    } finally {
        estab_auth_close($connection);
    }
}

/** Create a missing legacy title table using the modern storage defaults. */
function estab_logbook_create_title_table(array $databaseConfig, string $table): void
{
    $connection = estab_auth_connect($databaseConfig);
    try {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . estab_auth_table($table) . ' ('
            . ' `lfd-nr` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `einsatz` VARCHAR(255) NOT NULL,'
            . ' `ort` VARCHAR(255) NOT NULL,'
            . ' PRIMARY KEY (`lfd-nr`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        if (!$connection->query($sql)) {
            throw new RuntimeException('Could not create logbook title table');
        }
    } finally {
        estab_auth_close($connection);
    }
}

/**
 * Store the title only when the table is still empty.
 *
 * This makes repeated submissions and integration-test reruns harmless.
 */
function estab_logbook_insert_title(
    array $databaseConfig,
    string $table,
    array $title
): void {
    $connection = estab_auth_connect($databaseConfig);
    try {
        $quotedTable = estab_auth_table($table);
        $sql = 'INSERT INTO ' . $quotedTable . ' (`einsatz`, `ort`)'
            . ' SELECT ?, ? FROM DUAL'
            . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $quotedTable . ' LIMIT 1)';
        $statement = $connection->prepare($sql);
        if (!$statement) {
            throw new RuntimeException('Could not prepare logbook title insert');
        }
        try {
            $operation = (string) ($title['einsatz'] ?? '');
            $location = (string) ($title['ort'] ?? '');
            $statement->bind_param('ss', $operation, $location);
            if (!$statement->execute()) {
                throw new RuntimeException('Could not execute logbook title insert');
            }
        } finally {
            $statement->close();
        }
    } finally {
        estab_auth_close($connection);
    }
}

/** Insert one ETB or TBB record through a prepared mysqli statement. */
function estab_logbook_insert_entry(
    array $databaseConfig,
    string $table,
    string $kind,
    array $entry,
    array $identity
): int {
    if (!in_array($kind, ['etb', 'tbb'], true)) {
        throw new InvalidArgumentException('Invalid logbook kind');
    }
    $validation = estab_logbook_validate_entry($entry);
    if (!$validation['valid']) {
        throw new InvalidArgumentException('Invalid logbook entry');
    }
    $entry = $validation['data'];

    $connection = estab_auth_connect($databaseConfig);
    try {
        return estab_incident_with_active_write(
            $connection,
            static function (array $incident) use (
                $connection,
                $table,
                $kind,
                $entry,
                $identity
            ): int {
                $prefix = $kind . '_';
                $incidentId = (int) $incident['active_einsatz_id'];
                estab_dv_require_active_capability_for_operational_write(
                    $connection,
                    $incidentId,
                    $identity,
                    $kind === 'etb'
                        ? 'EINSATZTAGEBUCH'
                        : 'BEFOERDERUNG'
                );
                if ($kind === 'etb') {
                    estab_logbook_validate_references(
                        $connection,
                        $incidentId,
                        $table,
                        $entry
                    );
                }
                $sql = 'INSERT INTO ' . estab_auth_table($table)
                    . ' (`einsatz_id`, `' . $prefix . 'time`,'
                    . ' `' . $prefix . 'aktion`, `' . $prefix . 'bemerk`,'
                    . ' `' . $prefix . 'funktion`, `' . $prefix . 'kuerzel`,'
                    . ' `' . $prefix . 'benutzer`'
                    . ($kind === 'etb'
                        ? ', `estab_event_time`, `estab_event_type`,'
                            . ' `estab_message_id`, `estab_attachment_id`,'
                            . ' `estab_reference`, `estab_correction_of`'
                        : '')
                    . ')'
                    . ($kind === 'etb'
                        ? ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        : ' VALUES (?, NOW(), ?, ?, ?, ?, ?)');
                $statement = $connection->prepare($sql);
                if (!$statement) {
                    throw new RuntimeException(
                        'Could not prepare logbook entry insert'
                    );
                }
                try {
                    $event = (string) ($entry['event'] ?? '');
                    $comment = (string) ($entry['comment'] ?? '');
                    $function = (string) ($identity['funktion'] ?? '');
                    $code = (string) ($identity['kuerzel'] ?? '');
                    $user = (string) ($identity['benutzer'] ?? '');
                    if ($kind === 'etb') {
                        $eventTime = (string) ($entry['event_time'] ?? '');
                        $eventType = (string) ($entry['event_type'] ?? '');
                        $messageId = $entry['message_id'] ?? null;
                        $attachmentId = $entry['attachment_id'] ?? null;
                        $reference = $entry['reference'] ?? null;
                        $correctionOf = $entry['correction_of'] ?? null;
                        $statement->bind_param(
                            'issssssssiisi',
                            $incidentId,
                            $eventTime,
                            $event,
                            $comment,
                            $function,
                            $code,
                            $user,
                            $eventTime,
                            $eventType,
                            $messageId,
                            $attachmentId,
                            $reference,
                            $correctionOf
                        );
                    } else {
                        $statement->bind_param(
                            'isssss',
                            $incidentId,
                            $event,
                            $comment,
                            $function,
                            $code,
                            $user
                        );
                    }
                    if (!$statement->execute()) {
                        throw new RuntimeException(
                            'Could not execute logbook entry insert'
                        );
                    }
                    $entryId = (int) $connection->insert_id;
                    if ($entryId < 1) {
                        throw new RuntimeException(
                            'Could not determine logbook entry ID'
                        );
                    }
                    return $entryId;
                } finally {
                    $statement->close();
                }
            }
        );
    } finally {
        estab_auth_close($connection);
    }
}

/** Return the configured red-copy function, accepting both historic encodings. */
function estab_logbook_redcopy_function(array $databaseConfig, string $matrixTable): ?string
{
    $connection = estab_auth_connect($databaseConfig);
    try {
        $sql = 'SELECT `mtx_fkt` FROM ' . estab_auth_table($matrixTable)
            . " WHERE `mtx_rc2` IN ('t', '1') ORDER BY `mtx_lfd` LIMIT 1";
        $result = $connection->query($sql);
        if (!$result) {
            throw new RuntimeException('Could not read red-copy function');
        }
        $row = $result->fetch_assoc();
        $result->free();
        if (!is_array($row) || !isset($row['mtx_fkt']) || !is_string($row['mtx_fkt'])) {
            return null;
        }
        $function = trim($row['mtx_fkt']);
        return $function === '' ? null : $function;
    } finally {
        estab_auth_close($connection);
    }
}

/** Emit a deliberately small error response and stop request processing. */
function estab_logbook_abort(int $status, string $message): never
{
    if (ob_get_level() > 0) {
        @ob_clean();
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

function estab_logbook_require_csrf(array $server, array $post): void
{
    try {
        estab_csrf_require_post($server, $post);
    } catch (RuntimeException) {
        estab_logbook_abort(403, 'Ungültige oder abgelaufene Formularanforderung.');
    }
}

/** Complete a successful write with Post/Redirect/Get. */
function estab_logbook_redirect(string $path): never
{
    $publicRoot = estab_public_root();
    $valid = !str_contains($path, "\r")
        && !str_contains($path, "\n")
        && str_starts_with($path, $publicRoot);
    if ($publicRoot === '/') {
        $valid = $valid
            && preg_match('~\A/[A-Za-z0-9_./-]+\z/D~', $path) === 1
            && !str_starts_with($path, '//')
            && !str_contains($path, '..');
    }
    if (!$valid) {
        $path = $publicRoot;
    }
    if (ob_get_level() > 0) {
        @ob_clean();
    }
    header('Location: ' . $path, true, 303);
    exit;
}
