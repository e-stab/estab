<?php

declare(strict_types=1);

/**
 * Read and present the immutable workflow history of one message.
 *
 * The event table remains the only persistence layer.  This module deliberately
 * derives its durations from the database-owned recorded_at timestamp; the
 * fachlich editable occurred_at value is evidence, but never a stopwatch.
 */

require_once __DIR__ . '/message_evidence.php';

const ESTAB_MESSAGE_TIMELINE_MAX_EVENTS = 512;

final class EstabMessageTimelineInputException extends InvalidArgumentException
{
}

final class EstabMessageTimelineEvidenceException extends RuntimeException
{
}

/** Validate a positive database identifier without silently coercing text. */
function estab_message_timeline_positive_id(mixed $value, string $label): int
{
    if (
        is_int($value)
        && $value > 0
    ) {
        return $value;
    }
    if (
        is_string($value)
        && preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) === 1
        && (int) $value > 0
        && (string) ((int) $value) === $value
    ) {
        return (int) $value;
    }
    throw new EstabMessageTimelineInputException($label . ' ist ungültig.');
}

/** Normalize a message status while keeping null distinct for first events. */
function estab_message_timeline_status(mixed $value, bool $nullable = false): ?int
{
    if ($nullable && $value === null) {
        return null;
    }
    if (is_int($value)) {
        $status = $value;
    } elseif (
        is_string($value)
        && preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/D', $value) === 1
    ) {
        $status = (int) $value;
    } else {
        throw new EstabMessageTimelineInputException(
            'Der Nachrichtenstatus ist ungültig.'
        );
    }
    if (!in_array($status, [0, 1, 2, 4, 8, 10], true)) {
        throw new EstabMessageTimelineInputException(
            'Der Nachrichtenstatus ist unbekannt.'
        );
    }
    return $status;
}

/** Return one canonical DATETIME(6), independent of the fachliche event time. */
function estab_message_timeline_datetime(mixed $value): string
{
    if (!is_string($value)) {
        throw new EstabMessageTimelineInputException(
            'Die Erfassungszeit des Nachrichtenereignisses ist ungültig.'
        );
    }
    $candidate = trim($value);
    foreach (['Y-m-d H:i:s.u', 'Y-m-d H:i:s'] as $format) {
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
    throw new EstabMessageTimelineInputException(
        'Die Erfassungszeit des Nachrichtenereignisses ist ungültig.'
    );
}

/** Difference between two recorded_at values, rounded down to whole seconds. */
function estab_message_timeline_duration_seconds(
    ?string $enteredAt,
    ?string $leftAt
): ?int {
    if ($enteredAt === null || $leftAt === null) {
        return null;
    }
    $entered = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s.u',
        estab_message_timeline_datetime($enteredAt)
    );
    $left = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s.u',
        estab_message_timeline_datetime($leftAt)
    );
    if (!$entered instanceof DateTimeImmutable || !$left instanceof DateTimeImmutable) {
        return null;
    }
    $enteredMicros = ((int) $entered->format('U')) * 1000000
        + (int) $entered->format('u');
    $leftMicros = ((int) $left->format('U')) * 1000000
        + (int) $left->format('u');
    if ($leftMicros < $enteredMicros) {
        return null;
    }
    return intdiv($leftMicros - $enteredMicros, 1000000);
}

/** Human-readable duration without hiding its exact seconds data attribute. */
function estab_message_timeline_duration_label(?int $seconds): string
{
    if ($seconds === null) {
        return 'Erfassungsbeginn nicht dokumentiert';
    }
    if ($seconds < 0) {
        throw new EstabMessageTimelineInputException('Die Laufzeit ist ungültig.');
    }
    if ($seconds < 60) {
        return $seconds === 1 ? '1 Sekunde' : $seconds . ' Sekunden';
    }
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remaining = $seconds % 60;
    $parts = [];
    if ($days > 0) {
        $parts[] = $days === 1 ? '1 Tag' : $days . ' Tage';
    }
    if ($hours > 0) {
        $parts[] = $hours . ' Std.';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' Min.';
    }
    if ($remaining > 0 && $days === 0) {
        $parts[] = $remaining . ' Sek.';
    }
    return implode(' ', $parts);
}

/** Treat the historical binary flag without accepting arbitrary truthiness. */
function estab_message_timeline_is_conversation_note(mixed $value): bool
{
    return $value === true
        || $value === 1
        || $value === '1'
        || $value === 't';
}

/** @return array{key:string,label:string,terminal:bool} */
function estab_message_timeline_station(
    string $direction,
    string $kind,
    int $status
): array {
    return match ($status) {
        0 => [
            'key' => $direction === 'E' ? 'incoming-registration' : 'author',
            'label' => $direction === 'E'
                ? 'Fernmelder · Eingang'
                : ($kind === 'conversation-note'
                    ? 'Verfasser · Gesprächsnotiz'
                    : 'Verfasser'),
            'terminal' => false,
        ],
        1 => ['key' => 'ldf', 'label' => 'LdF', 'terminal' => false],
        2 => [
            'key' => 'telecommunications',
            'label' => 'Fernmelder',
            'terminal' => false,
        ],
        4 => ['key' => 'review', 'label' => 'Sichter', 'terminal' => false],
        8 => [
            'key' => 'completed',
            'label' => $direction === 'E'
                ? 'Empfänger · abgeschlossen'
                : 'Abgeschlossen',
            'terminal' => true,
        ],
        10 => [
            'key' => 'author-correction',
            'label' => 'Verfasser · Korrektur',
            'terminal' => false,
        ],
        default => throw new EstabMessageTimelineInputException(
            'Der Nachrichtenstatus ist unbekannt.'
        ),
    };
}

/** @return array{key:string,label:string,terminal:bool} */
function estab_message_timeline_initial_station(string $direction, string $kind): array
{
    return [
        'key' => $direction === 'E' ? 'incoming-registration' : 'author',
        'label' => $direction === 'E'
            ? 'Fernmelder · Eingang'
            : ($kind === 'conversation-note'
                ? 'Verfasser · Gesprächsnotiz'
                : 'Verfasser'),
        'terminal' => false,
    ];
}

/** @return list<int> */
function estab_message_timeline_future_statuses(
    string $direction,
    int $status
): array {
    if ($direction === 'E') {
        return match ($status) {
            0 => [1, 4, 8],
            1 => [4, 8],
            4 => [8],
            8 => [],
            default => [],
        };
    }
    return match ($status) {
        0, 10 => [4, 1, 2, 8],
        4 => [1, 2, 8],
        1 => [2, 8],
        2 => [8],
        8 => [],
        default => [],
    };
}

/** Return metadata for the three explicit workflow return events. */
function estab_message_timeline_return_metadata(
    string $eventType,
    mixed $snapshot
): ?array {
    $definition = match ($eventType) {
        'si_returned' => ['Sichter', ['reason']],
        'aw_transport_returned' => [
            'Fernmelder',
            ['transport_return_reason'],
        ],
        // Reserved now so a future LdF return becomes visible without changing
        // the read model or losing an already persisted round trip.
        'ldf_returned' => ['LdF', ['return_reason', 'reason']],
        default => null,
    };
    if ($definition === null) {
        return null;
    }
    if (is_string($snapshot)) {
        try {
            $snapshot = json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $snapshot = null;
        }
    }
    $reason = null;
    if (is_array($snapshot)) {
        foreach ($definition[1] as $key) {
            $candidate = $snapshot[$key] ?? null;
            if (!is_string($candidate) || preg_match('//u', $candidate) !== 1) {
                continue;
            }
            $candidate = trim($candidate);
            $candidateWithoutWhitespace = str_replace(
                ["\t", "\r", "\n"],
                '',
                $candidate
            );
            $length = function_exists('mb_strlen')
                ? mb_strlen($candidate, 'UTF-8')
                : strlen($candidate);
            if (
                $candidate !== ''
                && $length <= 2000
                && preg_match('/\p{C}/u', $candidateWithoutWhitespace) !== 1
            ) {
                $reason = $candidate;
                break;
            }
        }
    }
    return [
        'event_type' => $eventType,
        'from_label' => $definition[0],
        'reason' => $reason,
    ];
}

/** Whether a known event uses its closed direction/status transition. */
function estab_message_timeline_known_event_valid(
    string $eventType,
    string $direction,
    string $kind,
    ?int $fromStatus,
    int $toStatus
): ?bool {
    return match ($eventType) {
        'legacy_import' => $fromStatus === null,
        'created' => $fromStatus === null
            && $toStatus === ($direction === 'E' ? 1 : 4)
            && $kind !== 'conversation-note',
        'conversation_note_created' => $direction === 'A'
            && $kind === 'conversation-note'
            && $fromStatus === null
            && $toStatus === 4,
        'si_returned' => $direction === 'A'
            && $fromStatus === 4
            && $toStatus === 10,
        'author_resubmitted' => $direction === 'A'
            && $fromStatus === 10
            && $toStatus === 4,
        'si_approved' => $direction === 'A'
            && $fromStatus === 4
            && $toStatus === 1,
        'ldf_dispatched' => $fromStatus === 1
            && $toStatus === ($direction === 'E' ? 4 : 2),
        'aw_transport_returned' => $direction === 'A'
            && $fromStatus === 2
            && $toStatus === 1,
        'ldf_returned' => $direction === 'A'
            && $fromStatus === 1
            && $toStatus === 10,
        'aw_transported' => $direction === 'A'
            && $fromStatus === 2
            && $toStatus === 8,
        'incoming_routed' => $direction === 'E'
            && $fromStatus === 4
            && $toStatus === 8,
        default => null,
    };
}

/** Build one stable visit record used by both operational views. */
function estab_message_timeline_visit(
    array $station,
    string $state,
    ?string $enteredAt,
    ?string $leftAt,
    ?array $returnMetadata = null,
    ?string $eventType = null
): array {
    $duration = estab_message_timeline_duration_seconds($enteredAt, $leftAt);
    return [
        'station' => $station['key'],
        'label' => $station['label'],
        'terminal' => $station['terminal'],
        'state' => $state,
        'entered_at' => $enteredAt,
        'left_at' => $leftAt,
        'duration_seconds' => $duration,
        'duration_label' => estab_message_timeline_duration_label($duration),
        'return' => $returnMetadata,
        'event_type' => $eventType,
    ];
}

/**
 * Pure read-model builder. Every repeated station remains an individual visit.
 *
 * @param array<string,mixed> $message
 * @param list<array<string,mixed>> $events
 * @return array<string,mixed>
 */
function estab_message_timeline_build(
    array $message,
    array $events,
    ?string $databaseNow = null
): array {
    if (count($events) > ESTAB_MESSAGE_TIMELINE_MAX_EVENTS) {
        throw new EstabMessageTimelineEvidenceException(
            'Der Nachrichtenverlauf überschreitet die sichere Anzeigegrenze.'
        );
    }
    $direction = (string) ($message['04_richtung'] ?? '');
    if (!in_array($direction, ['E', 'A'], true)) {
        throw new EstabMessageTimelineInputException(
            'Die Nachrichtenrichtung ist ungültig.'
        );
    }
    $kind = estab_message_timeline_is_conversation_note(
        $message['11_gesprnotiz'] ?? null
    ) ? 'conversation-note' : ($direction === 'E' ? 'incoming' : 'outgoing');
    if ($kind === 'conversation-note' && $direction !== 'A') {
        // Historical incoming conversation notes are readable, but their old
        // shortcut cannot be presented as the current prescribed route.
        $kind = 'legacy-conversation-note';
    }
    $currentStatus = estab_message_timeline_status(
        $message['x00_status'] ?? null
    );
    if ($databaseNow !== null) {
        $databaseNow = estab_message_timeline_datetime($databaseNow);
    }

    $visits = [];
    $reconstructable = true;
    $historyNote = null;
    $previousEventId = 0;
    $previousRecordedAt = null;
    $lastStatus = null;

    foreach ($events as $index => $event) {
        if (!is_array($event)) {
            throw new EstabMessageTimelineInputException(
                'Ein Nachrichtenereignis ist ungültig.'
            );
        }
        $eventId = estab_message_timeline_positive_id(
            $event['event_id'] ?? null,
            'Ereignis-ID'
        );
        if ($eventId <= $previousEventId) {
            throw new EstabMessageTimelineEvidenceException(
                'Die Nachrichtenereignisse sind nicht eindeutig geordnet.'
            );
        }
        $previousEventId = $eventId;
        $eventType = (string) ($event['event_type'] ?? '');
        if (preg_match('/\A[a-z][a-z0-9_]{2,31}\z/D', $eventType) !== 1) {
            throw new EstabMessageTimelineInputException(
                'Ein Nachrichtenereignistyp ist ungültig.'
            );
        }
        $fromStatus = estab_message_timeline_status(
            $event['from_status'] ?? null,
            true
        );
        $toStatus = estab_message_timeline_status($event['to_status'] ?? null);
        $recordedAt = estab_message_timeline_datetime(
            $event['recorded_at'] ?? null
        );
        if (
            $previousRecordedAt !== null
            && estab_message_timeline_duration_seconds(
                $previousRecordedAt,
                $recordedAt
            ) === null
        ) {
            throw new EstabMessageTimelineEvidenceException(
                'Die Erfassungszeiten des Nachrichtenverlaufs sind nicht monoton.'
            );
        }
        $previousRecordedAt = $recordedAt;
        if ($index > 0 && $fromStatus !== $lastStatus) {
            throw new EstabMessageTimelineEvidenceException(
                'Die Statusfolge des Nachrichtenverlaufs ist unterbrochen.'
            );
        }
        $knownValid = estab_message_timeline_known_event_valid(
            $eventType,
            $direction,
            $kind,
            $fromStatus,
            $toStatus
        );
        if ($knownValid === false) {
            throw new EstabMessageTimelineEvidenceException(
                'Ein Nachrichtenereignis passt nicht zu seinem Statusübergang.'
            );
        }
        if ($knownValid === null) {
            $reconstructable = false;
            $historyNote = 'Mindestens ein unbekannter historischer Übergang '
                . 'kann nicht vollständig benannt werden.';
        }

        if ($index === 0) {
            if ($fromStatus !== null) {
                throw new EstabMessageTimelineEvidenceException(
                    'Der Nachrichtenverlauf besitzt keinen eindeutigen Anfang.'
                );
            }
            if ($eventType === 'legacy_import') {
                $reconstructable = false;
                $historyNote = 'Historischer Bestand: Der Ablauf vor der '
                    . 'Übernahme ist nicht vollständig rekonstruierbar.';
                $legacyStation = [
                    'key' => 'legacy-import',
                    'label' => 'Historischer Bestand',
                    'terminal' => false,
                ];
                $visits[] = estab_message_timeline_visit(
                    $legacyStation,
                    'completed',
                    null,
                    $recordedAt,
                    null,
                    $eventType
                );
            } else {
                $visits[] = estab_message_timeline_visit(
                    estab_message_timeline_initial_station($direction, $kind),
                    'completed',
                    null,
                    $recordedAt,
                    null,
                    $eventType
                );
            }
        } else {
            $openIndex = count($visits) - 1;
            if ($openIndex < 0 || $visits[$openIndex]['left_at'] !== null) {
                throw new EstabMessageTimelineEvidenceException(
                    'Die aktuelle Station des Nachrichtenverlaufs fehlt.'
                );
            }
            $visits[$openIndex]['left_at'] = $recordedAt;
            $visits[$openIndex]['state'] = 'completed';
            $visits[$openIndex]['duration_seconds'] =
                estab_message_timeline_duration_seconds(
                    $visits[$openIndex]['entered_at'],
                    $recordedAt
                );
            $visits[$openIndex]['duration_label'] =
                estab_message_timeline_duration_label(
                    $visits[$openIndex]['duration_seconds']
                );
        }

        $visits[] = estab_message_timeline_visit(
            estab_message_timeline_station($direction, $kind, $toStatus),
            'current',
            $eventType === 'legacy_import' ? null : $recordedAt,
            null,
            estab_message_timeline_return_metadata(
                $eventType,
                $event['field_snapshot'] ?? null
            ),
            $eventType
        );
        $lastStatus = $toStatus;
    }

    if (
        $databaseNow !== null
        && $previousRecordedAt !== null
        && estab_message_timeline_duration_seconds(
            $previousRecordedAt,
            $databaseNow
        ) === null
    ) {
        throw new EstabMessageTimelineEvidenceException(
            'Die aktuelle Datenbankzeit liegt vor dem letzten Nachrichtenereignis.'
        );
    }

    if ($events === []) {
        $reconstructable = false;
        $historyNote = 'Für diese Nachricht liegt kein rekonstruierbarer '
            . 'Ereignisverlauf vor.';
        $visits[] = estab_message_timeline_visit(
            estab_message_timeline_station($direction, $kind, $currentStatus),
            'current',
            null,
            null
        );
        $lastStatus = $currentStatus;
    }
    if ($lastStatus !== $currentStatus) {
        throw new EstabMessageTimelineEvidenceException(
            'Der Ereignisverlauf stimmt nicht mit dem Nachrichtenstatus überein.'
        );
    }

    $currentIndex = count($visits) - 1;
    if ($currentIndex >= 0) {
        $visits[$currentIndex]['state'] = 'current';
        if ($currentStatus !== 8 && $databaseNow !== null) {
            $visits[$currentIndex]['duration_seconds'] =
                estab_message_timeline_duration_seconds(
                    $visits[$currentIndex]['entered_at'],
                    $databaseNow
                );
            $visits[$currentIndex]['duration_label'] =
                estab_message_timeline_duration_label(
                    $visits[$currentIndex]['duration_seconds']
                );
        } elseif ($currentStatus === 8) {
            $visits[$currentIndex]['duration_seconds'] = null;
            $visits[$currentIndex]['duration_label'] = 'Abgeschlossen';
        }
    }

    $future = [];
    foreach (
        estab_message_timeline_future_statuses($direction, $currentStatus)
        as $futureStatus
    ) {
        $station = estab_message_timeline_station(
            $direction,
            $kind,
            $futureStatus
        );
        $future[] = [
            'station' => $station['key'],
            'label' => $station['label'],
            'terminal' => $station['terminal'],
            'state' => 'pending',
            'entered_at' => null,
            'left_at' => null,
            'duration_seconds' => null,
            'duration_label' => 'Noch nicht erreicht',
            'return' => null,
            'event_type' => null,
        ];
    }

    return [
        'direction' => $direction,
        'kind' => $kind,
        'status' => $currentStatus,
        'complete' => $currentStatus === 8,
        'reconstructable' => $reconstructable,
        'history_note' => $historyNote,
        'database_now' => $databaseNow,
        'visits' => $visits,
        'future' => $future,
    ];
}

/**
 * Build the honest pre-persistence route shown while a new form is still a
 * draft. There is no elapsed time until the first successful submission.
 */
function estab_message_timeline_for_draft(
    string $direction,
    bool $conversationNote = false
): array {
    if (!in_array($direction, ['E', 'A'], true)) {
        throw new EstabMessageTimelineInputException(
            'Die Nachrichtenrichtung ist ungültig.'
        );
    }
    if ($conversationNote && $direction !== 'A') {
        throw new EstabMessageTimelineInputException(
            'Eine Gesprächsnotiz muss den Ausgangsweg verwenden.'
        );
    }
    $kind = $conversationNote
        ? 'conversation-note'
        : ($direction === 'E' ? 'incoming' : 'outgoing');
    $initial = estab_message_timeline_visit(
        estab_message_timeline_station($direction, $kind, 0),
        'current',
        null,
        null
    );
    $initial['duration_label'] = 'Noch nicht eingereicht';
    $future = [];
    foreach (estab_message_timeline_future_statuses($direction, 0) as $status) {
        $station = estab_message_timeline_station($direction, $kind, $status);
        $future[] = [
            'station' => $station['key'],
            'label' => $station['label'],
            'terminal' => $station['terminal'],
            'state' => 'pending',
            'entered_at' => null,
            'left_at' => null,
            'duration_seconds' => null,
            'duration_label' => 'Noch nicht erreicht',
            'return' => null,
            'event_type' => null,
        ];
    }
    return [
        'direction' => $direction,
        'kind' => $kind,
        'status' => 0,
        'complete' => false,
        'draft' => true,
        'reconstructable' => true,
        'history_note' => null,
        'database_now' => null,
        'visits' => [$initial],
        'future' => $future,
    ];
}

/**
 * Read and verify one bounded, message-scoped hash chain.
 *
 * @return array{events:list<array<string,mixed>>,database_now:string}
 */
function estab_message_timeline_read(
    mysqli $connection,
    mixed $incidentId,
    mixed $messageId
): array {
    $incidentId = estab_message_timeline_positive_id($incidentId, 'Einsatz-ID');
    $messageId = estab_message_timeline_positive_id($messageId, 'Nachrichten-ID');
    $limit = ESTAB_MESSAGE_TIMELINE_MAX_EVENTS + 1;
    $statement = $connection->prepare(
        'SELECT event_row.`event_id`, event_row.`einsatz_id`,'
            . ' event_row.`message_id`, event_row.`event_type`,'
            . " DATE_FORMAT(event_row.`occurred_at`, '%Y-%m-%d %H:%i:%s.%f')"
            . ' AS `occurred_at`,'
            . " DATE_FORMAT(event_row.`recorded_at`, '%Y-%m-%d %H:%i:%s.%f')"
            . ' AS `recorded_at`, event_row.`actor_user`,'
            . ' event_row.`actor_code`, event_row.`actor_function`,'
            . ' event_row.`from_status`, event_row.`to_status`,'
            . ' event_row.`field_snapshot`, event_row.`snapshot_sha256`,'
            . ' event_row.`previous_event_sha256`, event_row.`event_sha256`,'
            . ' head_row.`event_count` AS `timeline_head_count`,'
            . ' head_row.`last_event_sha256` AS `timeline_head_sha256`,'
            . " DATE_FORMAT(NOW(6), '%Y-%m-%d %H:%i:%s.%f')"
            . ' AS `timeline_database_now`'
            . ' FROM `nv_nachrichten_ereignisse` AS event_row'
            . ' JOIN `nv_nachrichten_nachweiskopf` AS head_row'
            . ' ON head_row.`einsatz_id` = event_row.`einsatz_id`'
            . ' AND head_row.`message_id` = event_row.`message_id`'
            . ' WHERE event_row.`einsatz_id` = ?'
            . ' AND event_row.`message_id` = ?'
            . ' ORDER BY event_row.`event_id` ASC LIMIT ' . $limit
    );
    if (!$statement) {
        throw new RuntimeException(
            'Der Nachrichtenverlauf konnte nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('ii', $incidentId, $messageId);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Der Nachrichtenverlauf konnte nicht gelesen werden.'
            );
        }
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $statement->close();
    }
    if ($rows === []) {
        throw new EstabMessageTimelineEvidenceException(
            'Für diese Nachricht fehlt der Ereignisnachweis.'
        );
    }
    $headCount = (int) ($rows[0]['timeline_head_count'] ?? -1);
    if (
        $headCount > ESTAB_MESSAGE_TIMELINE_MAX_EVENTS
        || count($rows) > ESTAB_MESSAGE_TIMELINE_MAX_EVENTS
    ) {
        throw new EstabMessageTimelineEvidenceException(
            'Der Nachrichtenverlauf überschreitet die sichere Anzeigegrenze.'
        );
    }
    if ($headCount !== count($rows)) {
        throw new EstabMessageTimelineEvidenceException(
            'Der Nachweiskopf stimmt nicht mit dem Nachrichtenverlauf überein.'
        );
    }

    $events = [];
    $expectedPrevious = null;
    $lastEventId = 0;
    foreach ($rows as $row) {
        $eventId = (int) ($row['event_id'] ?? 0);
        $rowIncidentId = (int) ($row['einsatz_id'] ?? 0);
        $rowMessageId = (int) ($row['message_id'] ?? 0);
        $snapshot = (string) ($row['field_snapshot'] ?? '');
        $snapshotHash = (string) ($row['snapshot_sha256'] ?? '');
        $storedPrevious = $row['previous_event_sha256'] === null
            ? null
            : (string) ($row['previous_event_sha256'] ?? '');
        $fromStatus = $row['from_status'] === null
            ? null
            : (int) $row['from_status'];
        $toStatus = $row['to_status'] === null
            ? null
            : (int) $row['to_status'];
        $expectedHash = estab_message_evidence_event_hash(
            $rowIncidentId,
            $rowMessageId,
            (string) ($row['event_type'] ?? ''),
            (string) ($row['occurred_at'] ?? ''),
            (string) ($row['recorded_at'] ?? ''),
            (string) ($row['actor_user'] ?? ''),
            (string) ($row['actor_code'] ?? ''),
            (string) ($row['actor_function'] ?? ''),
            $fromStatus,
            $toStatus,
            $snapshotHash,
            $storedPrevious
        );
        if (
            $eventId <= $lastEventId
            || $rowIncidentId !== $incidentId
            || $rowMessageId !== $messageId
            || !hash_equals($snapshotHash, hash('sha256', $snapshot))
            || $storedPrevious !== $expectedPrevious
            || !hash_equals(
                (string) ($row['event_sha256'] ?? ''),
                $expectedHash
            )
        ) {
            throw new EstabMessageTimelineEvidenceException(
                'Der Nachrichtenverlauf hat einen ungültigen Nachweis.'
            );
        }
        $lastEventId = $eventId;
        $expectedPrevious = (string) $row['event_sha256'];
        unset(
            $row['timeline_head_count'],
            $row['timeline_head_sha256'],
            $row['timeline_database_now']
        );
        $row['event_id'] = $eventId;
        $row['einsatz_id'] = $rowIncidentId;
        $row['message_id'] = $rowMessageId;
        $row['from_status'] = $fromStatus;
        $row['to_status'] = $toStatus;
        $events[] = $row;
    }
    if (!hash_equals(
        (string) ($rows[0]['timeline_head_sha256'] ?? ''),
        $expectedPrevious ?? ''
    )) {
        throw new EstabMessageTimelineEvidenceException(
            'Der Kettenkopf des Nachrichtenverlaufs ist ungültig.'
        );
    }
    return [
        'events' => $events,
        'database_now' => estab_message_timeline_datetime(
            $rows[0]['timeline_database_now'] ?? null
        ),
    ];
}

/** Load and build a timeline for an already authorized message row. */
function estab_message_timeline_for_message(
    mysqli $connection,
    array $message
): array {
    $evidence = estab_message_timeline_read(
        $connection,
        $message['einsatz_id'] ?? null,
        $message['00_lfd'] ?? null
    );
    return estab_message_timeline_build(
        $message,
        $evidence['events'],
        $evidence['database_now']
    );
}

/** Escape all dynamic timeline text at its single HTML output boundary. */
function estab_message_timeline_html(mixed $value): string
{
    return htmlspecialchars(
        is_scalar($value) ? (string) $value : '',
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        'UTF-8'
    );
}

/** Format a canonical stored time for human display. */
function estab_message_timeline_time_label(?string $value): string
{
    if ($value === null) {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s.u',
        estab_message_timeline_datetime($value)
    );
    return $date instanceof DateTimeImmutable
        ? $date->format('d.m.Y · H:i:s')
        : '';
}

/** Render reusable semantic HTML; styling remains with the consuming views. */
function estab_message_timeline_render(
    array $timeline,
    string $domId = 'estab-message-timeline'
): string {
    if (preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $domId) !== 1) {
        throw new EstabMessageTimelineInputException(
            'Die Timeline-ID ist ungültig.'
        );
    }
    $visits = $timeline['visits'] ?? null;
    $future = $timeline['future'] ?? null;
    if (!is_array($visits) || !is_array($future) || $visits === []) {
        throw new EstabMessageTimelineInputException(
            'Der darzustellende Nachrichtenverlauf ist ungültig.'
        );
    }
    $items = array_merge($visits, $future);
    $headingId = $domId . '-title';
    $html = '<section id="' . estab_message_timeline_html($domId) . '"'
        . ' class="estab-message-timeline" data-estab-message-timeline'
        . ' data-estab-timeline-kind="'
        . estab_message_timeline_html($timeline['kind'] ?? '') . '"'
        . ' aria-labelledby="' . estab_message_timeline_html($headingId) . '">';
    $html .= '<header class="estab-message-timeline__header">'
        . '<h2 id="' . estab_message_timeline_html($headingId) . '">'
        . 'Bearbeitungsweg der Meldung</h2>'
        . '<p class="estab-message-timeline__summary">Jede Station und jeder '
        . 'Rücklauf bleiben in ihrer tatsächlichen '
        . 'Reihenfolge sichtbar.</p></header>';
    $historyNote = $timeline['history_note'] ?? null;
    if (is_string($historyNote) && $historyNote !== '') {
        $html .= '<p class="estab-message-timeline__notice" role="note">'
            . estab_message_timeline_html($historyNote) . '</p>';
    }
    $html .= '<ol class="estab-message-timeline__track" tabindex="0"'
        . ' aria-label="Stationen und Laufzeiten der Meldung">';
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            throw new EstabMessageTimelineInputException(
                'Eine Timeline-Station ist ungültig.'
            );
        }
        $state = (string) ($item['state'] ?? '');
        if (!in_array($state, ['completed', 'current', 'pending'], true)) {
            throw new EstabMessageTimelineInputException(
                'Der Timeline-Zustand ist ungültig.'
            );
        }
        $return = is_array($item['return'] ?? null) ? $item['return'] : null;
        $terminal = ($item['terminal'] ?? false) === true;
        $classes = ['estab-message-timeline__step'];
        $classes[] = 'estab-message-timeline__step--' . $state;
        if ($return !== null) {
            $classes[] = 'estab-message-timeline__step--returned';
        }
        if ($terminal && $state !== 'pending') {
            $classes[] = 'estab-message-timeline__step--terminal';
        }
        if (($item['station'] ?? null) === 'legacy-import') {
            $classes[] = 'estab-message-timeline__step--legacy';
        }
        $connectorHtml = '';
        if ($index > 0) {
            $previous = $items[$index - 1];
            $previousDuration = is_array($previous)
                && is_int($previous['duration_seconds'] ?? null)
                ? $previous['duration_seconds']
                : null;
            $connectorClasses = ['estab-message-timeline__connector'];
            if ($return !== null) {
                $connectorClasses[] =
                    'estab-message-timeline__connector--returned';
            }
            $connectorHtml = '<span class="'
                . implode(' ', $connectorClasses) . '"';
            if ($previousDuration !== null) {
                $connectorHtml .= ' data-estab-timeline-duration-seconds="'
                    . $previousDuration . '"';
            }
            if ($return !== null) {
                $connectorHtml .= ' data-estab-timeline-return="'
                    . estab_message_timeline_html($return['event_type'] ?? '')
                    . '"';
            }
            $connectorHtml .= ' aria-hidden="true"></span>';
        }
        $html .= '<li class="' . implode(' ', $classes) . '"'
            . ' data-estab-timeline-station="'
            . estab_message_timeline_html($item['station'] ?? '') . '"'
            . ' data-estab-timeline-state="'
            . estab_message_timeline_html($state) . '"';
        if (is_int($item['duration_seconds'] ?? null)) {
            $html .= ' data-estab-timeline-duration-seconds="'
                . (int) $item['duration_seconds'] . '"';
        }
        if ($return !== null) {
            $html .= ' data-estab-timeline-return="'
                . estab_message_timeline_html($return['event_type'] ?? '') . '"';
        }
        if ($state === 'current') {
            $html .= ' aria-current="step"';
        }
        $html .= '>' . $connectorHtml
            . '<div class="estab-message-timeline__content">'
            . '<h3 class="estab-message-timeline__station">'
            . estab_message_timeline_html($item['label'] ?? '') . '</h3>';
        $stateLabel = $state === 'current'
            ? ($terminal ? 'Abgeschlossen' : 'Aktuell')
            : ($state === 'completed' ? 'Erledigt' : 'Geplant');
        $html .= '<span class="estab-message-timeline__state">'
            . $stateLabel . '</span>';
        $timeValue = $state === 'completed'
            ? ($item['left_at'] ?? null)
            : ($item['entered_at'] ?? null);
        if (is_string($timeValue) && $timeValue !== '') {
            $canonicalTime = estab_message_timeline_datetime($timeValue);
            $html .= '<time class="estab-message-timeline__time" datetime="'
                . estab_message_timeline_html(str_replace(' ', 'T', $canonicalTime))
                . '">' . estab_message_timeline_html(
                    estab_message_timeline_time_label($canonicalTime)
                ) . '</time>';
        }
        if ($state !== 'pending') {
            $durationText = (string) ($item['duration_label'] ?? '');
            if (is_int($item['duration_seconds'] ?? null)) {
                $durationText = 'Dauer an dieser Station: ' . $durationText;
            }
            $html .= '<span class="estab-message-timeline__duration">'
                . estab_message_timeline_html($durationText)
                . '</span>';
        }
        if ($return !== null) {
            $html .= '<p class="estab-message-timeline__reason"><strong>'
                . 'Zurückgegeben von '
                . estab_message_timeline_html($return['from_label'] ?? '')
                . '.</strong>';
            $reason = $return['reason'] ?? null;
            if (is_string($reason) && $reason !== '') {
                $html .= ' Grund: ' . estab_message_timeline_html($reason);
            }
            $html .= '</p>';
        }
        $html .= '</div></li>';
    }
    $html .= '</ol></section>';
    return $html;
}
