<?php

declare(strict_types=1);

/**
 * Append-only, hash-linked evidence for every message workflow transition.
 *
 * This module never begins or commits a transaction.  Callers must append the
 * event on the same mysqli handle and inside the same transaction that locks
 * and changes nv_nachrichten.  A failed evidence insert must therefore roll
 * back the domain change as one unit.
 */

require_once __DIR__ . '/incident.php';

const ESTAB_MESSAGE_EVIDENCE_SNAPSHOT_MAX_BYTES = 1048576;

final class EstabMessageEvidenceInputException extends InvalidArgumentException
{
}

/** Validate a compact, stable event name used by exports and audit tooling. */
function estab_message_evidence_event_type(mixed $value): string
{
    if (
        !is_string($value)
        || preg_match('/\A[a-z][a-z0-9_]{2,31}\z/D', $value) !== 1
    ) {
        throw new EstabMessageEvidenceInputException(
            'Ungültiger Typ des Nachrichtenereignisses.'
        );
    }
    return $value;
}

/** Validate one actor field without accepting control characters. */
function estab_message_evidence_actor_field(
    mixed $value,
    string $label,
    int $maximum,
    bool $required = true
): string {
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        throw new EstabMessageEvidenceInputException($label . ' ist ungültig.');
    }
    $text = trim($value);
    $length = estab_auth_text_length($text);
    if (
        ($required && $text === '')
        || $length < 0
        || $length > $maximum
        || preg_match('/\p{C}/u', $text) === 1
    ) {
        throw new EstabMessageEvidenceInputException($label . ' ist ungültig.');
    }
    return $text;
}

/**
 * Recursively sort object keys so semantically equal snapshots hash equally.
 *
 * Lists preserve their order.  Objects are represented by associative arrays
 * because workflow callers already use database-field arrays.
 */
function estab_message_evidence_canonical_value(mixed $value): mixed
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            return array_map('estab_message_evidence_canonical_value', $value);
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) && !is_int($key)) {
                throw new EstabMessageEvidenceInputException(
                    'Der Feldnachweis enthält einen ungültigen Schlüssel.'
                );
            }
            $normalized[(string) $key] =
                estab_message_evidence_canonical_value($item);
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }
    if (
        $value === null
        || is_bool($value)
        || is_int($value)
        || is_float($value)
    ) {
        if (is_float($value) && !is_finite($value)) {
            throw new EstabMessageEvidenceInputException(
                'Der Feldnachweis enthält eine ungültige Zahl.'
            );
        }
        return $value;
    }
    if (is_string($value) && preg_match('//u', $value) === 1) {
        return $value;
    }
    throw new EstabMessageEvidenceInputException(
        'Der Feldnachweis enthält einen nicht unterstützten Wert.'
    );
}

/** Encode a deterministic UTF-8 JSON object and enforce the storage boundary. */
function estab_message_evidence_snapshot(array $snapshot): string
{
    $canonical = estab_message_evidence_canonical_value($snapshot);
    try {
        $json = json_encode(
            $canonical,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
        );
    } catch (JsonException $exception) {
        throw new EstabMessageEvidenceInputException(
            'Der Feldnachweis kann nicht serialisiert werden.',
            0,
            $exception
        );
    }
    if (strlen($json) > ESTAB_MESSAGE_EVIDENCE_SNAPSHOT_MAX_BYTES) {
        throw new EstabMessageEvidenceInputException(
            'Der Feldnachweis ist größer als 1 MiB.'
        );
    }
    return $json;
}

/**
 * Canonical, immutable four-part-form state captured by every terminal event.
 *
 * This deliberately excludes mutable organisation/technical metadata:
 * `20_master_katego`, operator locks, print flags/timestamps and `99_lstacc`.
 * Recipient read/done state lives in separate tables and is not part of the
 * form. Everything that documents receipt, disposition, transport, content,
 * distribution, review and completion remains comparison-relevant.
 *
 * @return array<string, int|string|null>
 */
function estab_message_terminal_snapshot(array $message): array
{
    $integerFields = [
        'einsatz_id',
        '00_lfd',
        '04_nummer',
        'estab_fernmeldeplan_eintrag_id',
        'x00_status',
    ];
    $textFields = [
        '01_medium', '01_datum', '01_zeichen',
        '02_zeit', '02_zeichen',
        '03_datum', '03_zeichen',
        '04_richtung',
        '05_gegenstelle', '06_befweg', '06_befwegausw',
        '07_durchspruch', '08_befhinweis', '08_befhinwausw',
        '09_vorrangstufe', '10_anschrift', '11_gesprnotiz',
        '12_anhang', '12_inhalt', '12_abfzeit',
        '13_abseinheit', '14_zeichen', '14_funktion',
        '15_quitdatum', '15_quitzeichen',
        '16_empf', '17_vermerke',
        'x01_abschluss',
    ];

    $snapshot = [];
    foreach ($integerFields as $field) {
        $value = $message[$field] ?? null;
        $snapshot[$field] = $value === null ? null : (int) $value;
    }
    foreach ($textFields as $field) {
        $value = $message[$field] ?? null;
        $snapshot[$field] = $value === null ? null : (string) $value;
    }
    return estab_message_evidence_canonical_value($snapshot);
}

/** Stable digest used by terminal events, verification and exports. */
function estab_message_terminal_snapshot_sha256(array $message): string
{
    return hash(
        'sha256',
        estab_message_evidence_snapshot(
            estab_message_terminal_snapshot($message)
        )
    );
}

/** Require the caller's mysqli handle to be inside an active transaction. */
function estab_message_evidence_require_transaction(mysqli $connection): void
{
    $result = $connection->query(
        'SELECT @@in_transaction AS `estab_in_transaction`'
    );
    if (!$result) {
        throw new RuntimeException('Nachweistransaktion konnte nicht geprüft werden.');
    }
    try {
        $row = $result->fetch_assoc();
    } finally {
        $result->free();
    }
    if (!is_array($row) || (int) ($row['estab_in_transaction'] ?? 0) !== 1) {
        throw new LogicException(
            'Nachrichtenereignisse müssen in derselben Transaktion wie '
            . 'die fachliche Änderung geschrieben werden.'
        );
    }
}

/** Read the database clock once so event hash and persisted time are identical. */
function estab_message_evidence_database_now(mysqli $connection): string
{
    $result = $connection->query(
        "SELECT DATE_FORMAT(NOW(6), '%Y-%m-%d %H:%i:%s.%f') AS `recorded_at`"
    );
    if (!$result) {
        throw new RuntimeException('Nachweiszeit konnte nicht gelesen werden.');
    }
    try {
        $row = $result->fetch_assoc();
    } finally {
        $result->free();
    }
    $value = is_array($row) ? ($row['recorded_at'] ?? null) : null;
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Nachweiszeit ist ungültig.');
    }
    return $value;
}

/** Canonical DATETIME(6) representation shared with the database hash function. */
function estab_message_evidence_datetime(mixed $value, string $label): string
{
    if (!is_string($value)) {
        throw new EstabMessageEvidenceInputException($label . ' ist ungültig.');
    }
    $candidate = trim($value);
    foreach ([
        'Y-m-d H:i:s.u',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i',
    ] as $format) {
        $date = DateTimeImmutable::createFromFormat(
            '!' . $format,
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
            && $date->format($format) === $candidate
        ) {
            return $date->format('Y-m-d H:i:s.u');
        }
    }
    throw new EstabMessageEvidenceInputException($label . ' ist ungültig.');
}

/** Exact v1 event hash payload; mirrors estab_message_event_hash() in MariaDB. */
function estab_message_evidence_event_hash(
    int $incidentId,
    int $messageId,
    string $eventType,
    string $occurredAt,
    string $recordedAt,
    string $actorUser,
    string $actorCode,
    string $actorFunction,
    ?int $fromStatus,
    ?int $toStatus,
    string $snapshotHash,
    ?string $previousHash
): string {
    return hash('sha256', implode("\n", [
        'estab-message-event-v1',
        (string) $incidentId,
        (string) $messageId,
        $eventType,
        estab_message_evidence_datetime($occurredAt, 'Ereigniszeit'),
        estab_message_evidence_datetime($recordedAt, 'Erfassungszeit'),
        $actorUser,
        $actorCode,
        $actorFunction,
        $fromStatus === null ? 'null' : (string) $fromStatus,
        $toStatus === null ? 'null' : (string) $toStatus,
        $snapshotHash,
        $previousHash ?? '',
    ]));
}

/**
 * Append one structured event using the caller's existing transaction.
 *
 * Required caller invariant: nv_nachrichten.message_id is already locked
 * FOR UPDATE (or was inserted in this transaction).  That serialises the
 * per-message previous-event lookup and makes the hash chain deterministic.
 *
 * @param array{benutzer?:mixed,kuerzel?:mixed,funktion?:mixed} $actor
 * @param array<string, mixed> $fieldSnapshot
 */
function estab_message_event_append(
    mysqli $connection,
    int $incidentId,
    int $messageId,
    string $eventType,
    array $actor,
    ?int $fromStatus,
    ?int $toStatus,
    array $fieldSnapshot,
    ?string $occurredAt = null
): int {
    $incidentId = estab_incident_positive_id($incidentId);
    $messageId = estab_incident_positive_id($messageId, 'Nachrichten-ID');
    $eventType = estab_message_evidence_event_type($eventType);
    if (
        ($fromStatus !== null && ($fromStatus < 0 || $fromStatus > 32767))
        || ($toStatus !== null && ($toStatus < 0 || $toStatus > 32767))
    ) {
        throw new EstabMessageEvidenceInputException(
            'Der Nachrichtenstatus ist ungültig.'
        );
    }
    estab_message_evidence_require_transaction($connection);
    if ($toStatus === 8) {
        $terminalStatement = $connection->prepare(
            'SELECT * FROM `nv_nachrichten`'
                . ' WHERE `00_lfd` = ? AND `einsatz_id` = ? FOR UPDATE'
        );
        if (!$terminalStatement) {
            throw new RuntimeException(
                'Abgeschlossener Nachrichtennachweis konnte nicht vorbereitet werden.'
            );
        }
        try {
            $terminalStatement->bind_param('ii', $messageId, $incidentId);
            if (!$terminalStatement->execute()) {
                throw new RuntimeException(
                    'Abgeschlossener Nachrichtennachweis konnte nicht gelesen werden.'
                );
            }
            $terminalMessage = $terminalStatement
                ->get_result()
                ->fetch_assoc();
        } finally {
            $terminalStatement->close();
        }
        if (
            !is_array($terminalMessage)
            || (int) ($terminalMessage['x00_status'] ?? 0) !== 8
            || !in_array(
                (string) ($terminalMessage['x01_abschluss'] ?? ''),
                ['t', '1'],
                true
            )
        ) {
            throw new EstabMessageEvidenceInputException(
                'Terminalnachweis erfordert eine abgeschlossene Nachricht.'
            );
        }
        // Always replace caller values with the locked canonical row. This
        // makes the low-level append API as safe as the repository adapter.
        $fieldSnapshot['terminal_message'] =
            estab_message_terminal_snapshot($terminalMessage);
        $fieldSnapshot['terminal_snapshot_sha256'] =
            estab_message_terminal_snapshot_sha256($terminalMessage);
    }
    $actorUser = estab_message_evidence_actor_field(
        $actor['benutzer'] ?? null,
        'Benutzer',
        128
    );
    $actorCode = estab_message_evidence_actor_field(
        $actor['kuerzel'] ?? '',
        'Kürzel',
        6,
        false
    );
    $actorFunction = estab_message_evidence_actor_field(
        $actor['funktion'] ?? null,
        'Funktion',
        32
    );
    $snapshotJson = estab_message_evidence_snapshot($fieldSnapshot);
    $snapshotHash = hash('sha256', $snapshotJson);

    $recordedAt = estab_message_evidence_datetime(
        estab_message_evidence_database_now($connection),
        'Erfassungszeit'
    );
    $occurredAt = $occurredAt === null
        ? $recordedAt
        : estab_message_evidence_datetime($occurredAt, 'Ereigniszeit');

    $previousStatement = $connection->prepare(
        'SELECT `event_sha256` FROM `nv_nachrichten_ereignisse`'
        . ' WHERE `message_id` = ?'
        . ' ORDER BY `event_id` DESC LIMIT 1 FOR UPDATE'
    );
    if (!$previousStatement) {
        throw new RuntimeException('Vorgängernachweis konnte nicht vorbereitet werden.');
    }
    try {
        $previousStatement->bind_param('i', $messageId);
        if (!$previousStatement->execute()) {
            throw new RuntimeException('Vorgängernachweis konnte nicht gelesen werden.');
        }
        $result = $previousStatement->get_result();
        $previousRow = $result->fetch_assoc();
        $result->free();
    } finally {
        $previousStatement->close();
    }
    $previousHash = is_array($previousRow)
        && is_string($previousRow['event_sha256'] ?? null)
        ? $previousRow['event_sha256']
        : null;

    $eventHash = estab_message_evidence_event_hash(
        $incidentId,
        $messageId,
        $eventType,
        $occurredAt,
        $recordedAt,
        $actorUser,
        $actorCode,
        $actorFunction,
        $fromStatus,
        $toStatus,
        $snapshotHash,
        $previousHash
    );

    $statement = $connection->prepare(
        'INSERT INTO `nv_nachrichten_ereignisse`'
        . ' (`einsatz_id`, `message_id`, `event_type`, `occurred_at`,'
        . ' `recorded_at`, `actor_user`, `actor_code`, `actor_function`,'
        . ' `from_status`, `to_status`, `field_snapshot`, `snapshot_sha256`,'
        . ' `previous_event_sha256`, `event_sha256`)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$statement) {
        throw new RuntimeException('Nachrichtenereignis konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param(
            'iissssssiissss',
            $incidentId,
            $messageId,
            $eventType,
            $occurredAt,
            $recordedAt,
            $actorUser,
            $actorCode,
            $actorFunction,
            $fromStatus,
            $toStatus,
            $snapshotJson,
            $snapshotHash,
            $previousHash,
            $eventHash
        );
        if (!$statement->execute()) {
            throw new RuntimeException('Nachrichtenereignis konnte nicht gespeichert werden.');
        }
        $eventId = (int) $connection->insert_id;
    } finally {
        $statement->close();
    }
    if ($eventId < 1) {
        throw new RuntimeException('Nachrichtenereignis-ID konnte nicht ermittelt werden.');
    }
    return $eventId;
}

/**
 * Recompute every snapshot hash, previous link, event hash and persisted head.
 *
 * @return array{
 *   valid:bool,
 *   event_count:int,
 *   message_count:int,
 *   broken_event_id:?int,
 *   head_mismatches:int,
 *   terminal_mismatches:int,
 *   terminal_unverifiable:int,
 *   terminal_binding_complete:bool
 * }
 */
function estab_message_evidence_verify(
    mysqli $connection,
    int $incidentId
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT `event_id`, `message_id`, `event_type`, `occurred_at`,'
        . ' `recorded_at`, `actor_user`, `actor_code`, `actor_function`,'
        . ' `from_status`, `to_status`, `field_snapshot`, `snapshot_sha256`,'
        . ' `previous_event_sha256`, `event_sha256`'
        . ' FROM `nv_nachrichten_ereignisse`'
        . ' WHERE `einsatz_id` = ? ORDER BY `message_id`, `event_id`'
    );
    if (!$statement) {
        throw new RuntimeException('Nachweiskette konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('i', $incidentId);
        if (!$statement->execute()) {
            throw new RuntimeException('Nachweiskette konnte nicht gelesen werden.');
        }
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } finally {
        $statement->close();
    }

    $previousByMessage = [];
    $countByMessage = [];
    $terminalByMessage = [];
    $legacyUnverifiable = [];
    $terminalMismatches = 0;
    $brokenEventId = null;
    foreach ($rows as $row) {
        $eventId = (int) ($row['event_id'] ?? 0);
        $messageId = (int) ($row['message_id'] ?? 0);
        $snapshot = (string) ($row['field_snapshot'] ?? '');
        $snapshotHash = hash('sha256', $snapshot);
        $storedSnapshotHash = (string) ($row['snapshot_sha256'] ?? '');
        $previous = $previousByMessage[$messageId] ?? null;
        $storedPrevious = is_string($row['previous_event_sha256'] ?? null)
            ? $row['previous_event_sha256']
            : null;
        $expectedEventHash = estab_message_evidence_event_hash(
            $incidentId,
            $messageId,
            (string) ($row['event_type'] ?? ''),
            (string) ($row['occurred_at'] ?? ''),
            (string) ($row['recorded_at'] ?? ''),
            (string) ($row['actor_user'] ?? ''),
            (string) ($row['actor_code'] ?? ''),
            (string) ($row['actor_function'] ?? ''),
            $row['from_status'] === null ? null : (int) $row['from_status'],
            $row['to_status'] === null ? null : (int) $row['to_status'],
            $storedSnapshotHash,
            $storedPrevious
        );
        if (
            $eventId < 1
            || $messageId < 1
            || !hash_equals($storedSnapshotHash, $snapshotHash)
            || $storedPrevious !== $previous
            || !hash_equals(
                (string) ($row['event_sha256'] ?? ''),
                $expectedEventHash
            )
        ) {
            $brokenEventId = $eventId > 0 ? $eventId : null;
            break;
        }
        if ((int) ($row['to_status'] ?? -1) === 8) {
            try {
                $decodedSnapshot = json_decode(
                    $snapshot,
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                $decodedSnapshot = null;
            }
            $terminalMessage = is_array($decodedSnapshot)
                ? ($decodedSnapshot['terminal_message'] ?? null)
                : null;
            $terminalDigest = is_array($decodedSnapshot)
                ? ($decodedSnapshot['terminal_snapshot_sha256'] ?? null)
                : null;
            if (
                (string) ($row['event_type'] ?? '') === 'legacy_import'
                && (!is_array($terminalMessage) || !is_string($terminalDigest))
            ) {
                $legacyUnverifiable[$messageId] = true;
            } elseif (
                !is_array($terminalMessage)
                || !is_string($terminalDigest)
                || preg_match('/\A[0-9a-f]{64}\z/D', $terminalDigest) !== 1
                || !hash_equals(
                    $terminalDigest,
                    estab_message_terminal_snapshot_sha256($terminalMessage)
                )
            ) {
                $terminalMismatches++;
            } else {
                $terminalByMessage[$messageId] = [
                    'snapshot' =>
                        estab_message_terminal_snapshot($terminalMessage),
                    'sha256' => $terminalDigest,
                ];
            }
        }
        $previousByMessage[$messageId] = (string) $row['event_sha256'];
        $countByMessage[$messageId] = ($countByMessage[$messageId] ?? 0) + 1;
    }

    $headStatement = $connection->prepare(
        'SELECT `message_id`, `event_count`, `last_event_sha256`'
        . ' FROM `nv_nachrichten_nachweiskopf` WHERE `einsatz_id` = ?'
        . ' ORDER BY `message_id`'
    );
    if (!$headStatement) {
        throw new RuntimeException('Nachweisköpfe konnten nicht vorbereitet werden.');
    }
    try {
        $headStatement->bind_param('i', $incidentId);
        if (!$headStatement->execute()) {
            throw new RuntimeException('Nachweisköpfe konnten nicht gelesen werden.');
        }
        $headResult = $headStatement->get_result();
        $heads = $headResult->fetch_all(MYSQLI_ASSOC);
        $headResult->free();
    } finally {
        $headStatement->close();
    }
    $headMismatches = 0;
    $seenHeads = [];
    foreach ($heads as $head) {
        $messageId = (int) ($head['message_id'] ?? 0);
        $seenHeads[$messageId] = true;
        if (
            !isset($countByMessage[$messageId])
            || (int) ($head['event_count'] ?? -1) !== $countByMessage[$messageId]
            || !hash_equals(
                (string) ($head['last_event_sha256'] ?? ''),
                (string) ($previousByMessage[$messageId] ?? '')
            )
        ) {
            $headMismatches++;
        }
    }
    foreach (array_keys($countByMessage) as $messageId) {
        if (!isset($seenHeads[$messageId])) {
            $headMismatches++;
        }
    }
    $missingStatement = $connection->prepare(
        'SELECT COUNT(*) AS `missing_count` FROM `nv_nachrichten` AS n'
        . ' LEFT JOIN `nv_nachrichten_nachweiskopf` AS h'
        . ' ON h.`message_id` = n.`00_lfd`'
        . ' WHERE n.`einsatz_id` = ? AND h.`message_id` IS NULL'
    );
    if (!$missingStatement) {
        throw new RuntimeException(
            'Fehlende Nachrichtennachweise konnten nicht vorbereitet werden.'
        );
    }
    try {
        $missingStatement->bind_param('i', $incidentId);
        if (!$missingStatement->execute()) {
            throw new RuntimeException(
                'Fehlende Nachrichtennachweise konnten nicht gelesen werden.'
            );
        }
        $missingResult = $missingStatement->get_result();
        $missingRow = $missingResult->fetch_assoc();
        $missingResult->free();
    } finally {
        $missingStatement->close();
    }
    $headMismatches += (int) ($missingRow['missing_count'] ?? 0);

    // Bind the hash-linked terminal event to the current canonical message
    // row. A direct or forgotten UPDATE can no longer leave a formally valid
    // chain while changing content, routing, recipients or completion data.
    $liveStatement = $connection->prepare(
        'SELECT * FROM `nv_nachrichten` WHERE `einsatz_id` = ?'
    );
    if (!$liveStatement) {
        throw new RuntimeException(
            'Aktuelle Nachrichtendatensätze konnten nicht vorbereitet werden.'
        );
    }
    try {
        $liveStatement->bind_param('i', $incidentId);
        if (!$liveStatement->execute()) {
            throw new RuntimeException(
                'Aktuelle Nachrichtendatensätze konnten nicht gelesen werden.'
            );
        }
        $liveResult = $liveStatement->get_result();
        $liveMessages = [];
        while (($liveMessage = $liveResult->fetch_assoc()) !== null) {
            $liveMessages[(int) ($liveMessage['00_lfd'] ?? 0)] = $liveMessage;
        }
        $liveResult->free();
    } finally {
        $liveStatement->close();
    }
    foreach ($terminalByMessage as $messageId => $terminal) {
        $liveMessage = $liveMessages[$messageId] ?? null;
        if (
            !is_array($liveMessage)
            || !hash_equals(
                (string) $terminal['sha256'],
                estab_message_terminal_snapshot_sha256($liveMessage)
            )
            || estab_message_terminal_snapshot($liveMessage)
                !== $terminal['snapshot']
        ) {
            $terminalMismatches++;
        }
    }
    foreach ($liveMessages as $messageId => $liveMessage) {
        if (
            (int) ($liveMessage['x00_status'] ?? 0) === 8
            && !isset($terminalByMessage[$messageId])
            && !isset($legacyUnverifiable[$messageId])
        ) {
            $terminalMismatches++;
        }
    }
    $terminalUnverifiable = count($legacyUnverifiable);

    return [
        'valid' => $brokenEventId === null
            && $headMismatches === 0
            && $terminalMismatches === 0,
        'event_count' => count($rows),
        'message_count' => count($countByMessage),
        'broken_event_id' => $brokenEventId,
        'head_mismatches' => $headMismatches,
        'terminal_mismatches' => $terminalMismatches,
        'terminal_unverifiable' => $terminalUnverifiable,
        'terminal_binding_complete' => $terminalMismatches === 0
            && $terminalUnverifiable === 0,
    ];
}
