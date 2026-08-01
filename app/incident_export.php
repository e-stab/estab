<?php

declare(strict_types=1);

/**
 * Read-only incident dossier boundary.
 *
 * Every database query is scoped to one explicitly selected incident.  The
 * controller may therefore export the active incident as well as a closed,
 * historical incident without changing global state.
 */

require_once __DIR__ . '/incident.php';
require_once __DIR__ . '/message_evidence.php';
require_once __DIR__ . '/incident_pdf.php';
require_once __DIR__ . '/file_access.php';
require_once __DIR__ . '/attachment_integrity.php';

final class EstabIncidentExportInputException extends InvalidArgumentException
{
}

final class EstabIncidentExportDataException extends RuntimeException
{
}

/**
 * Build the immutable ETB number assigned to every linked digital attachment.
 *
 * @param list<array<string,mixed>> $rows
 * @return array<int,list<string>> map keyed by the technical attachment ID
 */
function estab_incident_export_etb_attachment_number_map(
    int $incidentId,
    array $rows
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $numbers = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new EstabIncidentExportDataException(
                'Die ETB-Anlagenzuordnung ist ungültig.'
            );
        }
        $attachmentId = $row['estab_attachment_id'] ?? null;
        if ($attachmentId === null || $attachmentId === '') {
            continue;
        }
        try {
            $attachmentId = estab_incident_positive_id(
                $attachmentId,
                'Anhangs-ID'
            );
            $entryNumber = estab_incident_positive_id(
                $row['estab_book_lfd'] ?? null,
                'ETB-Nummer'
            );
        } catch (EstabIncidentInputException $exception) {
            throw new EstabIncidentExportDataException(
                'Die ETB-Anlagenzuordnung enthält ungültige Nummern.',
                0,
                $exception
            );
        }
        if (isset($numbers[$attachmentId])) {
            throw new EstabIncidentExportDataException(
                'Ein Anhang ist mehreren ETB-Einträgen zugeordnet und besitzt '
                    . 'dadurch keine eindeutige ETB-Anlagennummer.'
            );
        }
        $numbers[$attachmentId] = [
            estab_logbook_etb_attachment_number(
                $incidentId,
                $entryNumber
            ),
        ];
    }

    return $numbers;
}

/**
 * Validate the nine user-selectable dossier sections.
 *
 * @return list<string>
 */
function estab_incident_export_sections(mixed $input): array
{
    if (!is_array($input)) {
        throw new EstabIncidentExportInputException(
            'Die Auswahl für den PDF-Export ist ungültig.'
        );
    }

    $mapping = [
        'include_etb' => 'etb',
        'include_ttb' => 'ttb',
        'include_messages' => 'messages',
        'include_attachments' => 'attachments',
        'include_message_evidence' => 'message_evidence',
        'include_duty' => 'duty',
        'include_s6_plans' => 's6_plans',
        'include_courier' => 'courier',
        'include_operations_evidence' => 'operations_evidence',
    ];
    $selected = [];
    foreach ($mapping as $field => $section) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        if ($input[$field] !== '1') {
            throw new EstabIncidentExportInputException(
                'Die Auswahl für den PDF-Export ist ungültig.'
            );
        }
        $selected[] = $section;
    }

    if ($selected === []) {
        throw new EstabIncidentExportInputException(
            'Wählen Sie mindestens einen Inhalt für das Einsatzdossier aus.'
        );
    }
    if (
        in_array('attachments', $selected, true)
        && !in_array('messages', $selected, true)
    ) {
        throw new EstabIncidentExportInputException(
            'Originalanhänge können nur zusammen mit den Nachrichtenvordrucken '
                . 'exportiert werden.'
        );
    }
    return $selected;
}

/**
 * Parse the operator-selected ETB/TBB output scope without numeric coercion.
 *
 * @return array{mode:'all'|'shift',shift_id:?int}
 */
function estab_incident_export_logbook_scope(mixed $value): array
{
    if ($value === 'all') {
        return [
            'mode' => 'all',
            'shift_id' => null,
        ];
    }
    if (
        !is_string($value)
        || preg_match('/\Ashift:([1-9][0-9]*)\z/D', $value, $match) !== 1
    ) {
        throw new EstabIncidentExportInputException(
            'Die Logbuchauswahl ist ungültig. Wählen Sie das Gesamtbuch oder '
                . 'eine Dienstschicht aus.'
        );
    }

    try {
        $shiftId = estab_incident_positive_id(
            $match[1],
            'Dienstschicht-ID'
        );
    } catch (EstabIncidentInputException $exception) {
        throw new EstabIncidentExportInputException(
            'Die gewählte Dienstschicht ist ungültig.',
            0,
            $exception
        );
    }

    return [
        'mode' => 'shift',
        'shift_id' => $shiftId,
    ];
}

/**
 * Return all shifts which may be offered by the administrative export UI.
 * The export boundary validates the selected incident again inside its
 * consistent read-only snapshot; these rows are display options only.
 *
 * @return list<array<string,mixed>>
 */
function estab_incident_export_shift_options(mysqli $connection): array
{
    $result = $connection->query(
        'SELECT s.`dienstschicht_id`, s.`einsatz_id`, s.`nummer`,'
            . ' s.`bezeichnung`, s.`status`, s.`erstellt_am`,'
            . ' s.`aktiviert_am`, s.`beendet_am`,'
            . ' e.`kennung` AS `einsatz_kennung`,'
            . ' e.`name` AS `einsatz_name`'
            . ' FROM `nv_dienstschichten` AS s'
            . ' JOIN `nv_einsaetze` AS e ON e.`einsatz_id` = s.`einsatz_id`'
            . ' ORDER BY e.`einsatz_id` DESC, s.`nummer`, s.`dienstschicht_id`'
    );
    if (!$result instanceof mysqli_result) {
        throw new EstabIncidentExportDataException(
            'Die Dienstschichten für die Logbuchauswahl konnten nicht gelesen '
                . 'werden.'
        );
    }
    try {
        return $result->fetch_all(MYSQLI_ASSOC);
    } finally {
        $result->free();
    }
}

/**
 * Resolve one scope inside the dossier snapshot and prove incident ownership.
 *
 * @param array{mode:'all'|'shift',shift_id:?int} $scope
 * @return array<string,mixed>
 */
function estab_incident_export_resolve_logbook_scope(
    mysqli $connection,
    int $incidentId,
    array $scope
): array {
    if (($scope['mode'] ?? null) === 'all') {
        return [
            'mode' => 'all',
            'shift_id' => null,
            'number' => null,
            'name' => null,
            'status' => null,
            'created_at' => null,
            'activated_at' => null,
            'ended_at' => null,
            'display_label' => 'Gesamtbuch (alle Dienstschichten)',
        ];
    }

    $shiftId = $scope['shift_id'] ?? null;
    if (!is_int($shiftId) || $shiftId < 1) {
        throw new EstabIncidentExportInputException(
            'Die gewählte Dienstschicht ist ungültig.'
        );
    }
    $statement = $connection->prepare(
        'SELECT `dienstschicht_id`, `nummer`, `bezeichnung`, `status`,'
            . ' `erstellt_am`, `aktiviert_am`, `beendet_am`'
            . ' FROM `nv_dienstschichten`'
            . ' WHERE `einsatz_id` = ? AND `dienstschicht_id` = ?'
            . ' LIMIT 1'
    );
    if (!$statement) {
        throw new EstabIncidentExportDataException(
            'Die gewählte Dienstschicht konnte nicht geprüft werden.'
        );
    }
    try {
        $statement->bind_param('ii', $incidentId, $shiftId);
        if (!$statement->execute()) {
            throw new EstabIncidentExportDataException(
                'Die gewählte Dienstschicht konnte nicht geprüft werden.'
            );
        }
        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            throw new EstabIncidentExportDataException(
                'Die gewählte Dienstschicht konnte nicht geprüft werden.'
            );
        }
        try {
            $row = $result->fetch_assoc();
        } finally {
            $result->free();
        }
    } finally {
        $statement->close();
    }
    if (!is_array($row)) {
        throw new EstabIncidentExportInputException(
            'Die gewählte Dienstschicht gehört nicht zum ausgewählten Einsatz.'
        );
    }

    $number = (int) ($row['nummer'] ?? 0);
    $name = trim((string) ($row['bezeichnung'] ?? ''));
    $status = trim((string) ($row['status'] ?? ''));
    return [
        'mode' => 'shift',
        'shift_id' => (int) $row['dienstschicht_id'],
        'number' => $number,
        'name' => $name,
        'status' => $status,
        'created_at' => $row['erstellt_am'] ?? null,
        'activated_at' => $row['aktiviert_am'] ?? null,
        'ended_at' => $row['beendet_am'] ?? null,
        'display_label' => 'Nur Dienstschicht ' . $number
            . ($name !== '' ? ' · ' . $name : '')
            . ($status !== '' ? ' (' . $status . ')' : ''),
    ];
}

/**
 * Parse the semicolon-separated legacy message attachment field.
 *
 * @return list<string>
 */
function estab_incident_export_message_attachments(mixed $value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (!is_string($value) || strlen($value) > 65535) {
        throw new EstabIncidentExportDataException(
            'Eine Nachricht enthält eine ungültige Anhangliste.'
        );
    }

    $names = [];
    foreach (explode(';', $value) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        try {
            $name = estab_file_validate_name('attachment', $candidate);
        } catch (InvalidArgumentException $exception) {
            throw new EstabIncidentExportDataException(
                'Eine Nachricht verweist auf einen ungültigen Anhang.',
                0,
                $exception
            );
        }
        $names[$name] = true;
    }
    return array_keys($names);
}

/** Return a portable, non-secret download filename. */
function estab_incident_export_filename(
    array $incident,
    ?DateTimeImmutable $generatedAt = null
): string {
    $id = estab_incident_positive_id($incident['einsatz_id'] ?? null);
    $identifier = strtoupper(trim((string) ($incident['kennung'] ?? '')));
    if (
        preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{1,63}\z/D', $identifier) !== 1
    ) {
        throw new EstabIncidentExportDataException(
            'Die Einsatzkennung kann nicht als Dateiname verwendet werden.'
        );
    }
    $portable = strtolower(str_replace(
        ['/', '_', '.'],
        '-',
        $identifier
    ));
    $portable = preg_replace('/-+/', '-', $portable) ?? '';
    $generatedAt ??= new DateTimeImmutable('now');

    return sprintf(
        'estab-einsatz-%d-%s-%s.pdf',
        $id,
        $portable,
        $generatedAt->format('Ymd-His')
    );
}

/** Build a unique embedded-file name within FPDF's portable 181-byte limit. */
function estab_incident_export_embedded_name(
    int $position,
    string $storedName
): string {
    if ($position < 1 || $position > ESTAB_INCIDENT_PDF_MAX_ATTACHMENTS) {
        throw new EstabIncidentExportDataException(
            'Die Position eines Einsatzanhangs ist ungültig.'
        );
    }
    try {
        $storedName = estab_file_validate_name('attachment', $storedName);
    } catch (InvalidArgumentException $exception) {
        throw new EstabIncidentExportDataException(
            'Der gespeicherte Name eines Einsatzanhangs ist ungültig.',
            0,
            $exception
        );
    }

    $extension = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
    $base = pathinfo($storedName, PATHINFO_FILENAME);
    $prefix = sprintf('Anlage-%04d-', $position);
    $digest = substr(hash('sha256', $storedName), 0, 12);
    $suffix = '-' . $digest . '.' . $extension;
    $available = 181 - strlen($prefix) - strlen($suffix);
    $base = substr($base, 0, max(1, $available));

    return estab_incident_pdf_attachment_name($prefix . $base . $suffix);
}

/**
 * Execute one prepared incident-scoped SELECT and return all rows.
 *
 * @return list<array<string,mixed>>
 */
function estab_incident_export_rows(
    mysqli $connection,
    string $sql,
    int $incidentId,
    string $failure
): array {
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabIncidentExportDataException($failure);
    }
    try {
        $statement->bind_param('i', $incidentId);
        if (!$statement->execute()) {
            throw new EstabIncidentExportDataException($failure);
        }
        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            throw new EstabIncidentExportDataException($failure);
        }
        try {
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
    } finally {
        $statement->close();
    }
}

/**
 * Execute an incident-scoped ETB/TBB SELECT, optionally narrowed to one shift.
 * A filtered query always binds both the incident and shift identifiers so a
 * caller can never select a foreign shift by identifier alone.
 *
 * @return list<array<string,mixed>>
 */
function estab_incident_export_logbook_rows(
    mysqli $connection,
    string $sql,
    int $incidentId,
    ?int $shiftId,
    string $failure
): array {
    if ($shiftId === null) {
        return estab_incident_export_rows(
            $connection,
            $sql,
            $incidentId,
            $failure
        );
    }

    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabIncidentExportDataException($failure);
    }
    try {
        $statement->bind_param('ii', $incidentId, $shiftId);
        if (!$statement->execute()) {
            throw new EstabIncidentExportDataException($failure);
        }
        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            throw new EstabIncidentExportDataException($failure);
        }
        try {
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
    } finally {
        $statement->close();
    }
}

/**
 * Read the complete active recipient matrix used by the message-form template.
 *
 * @return array<int,array<int,array{fkt:string}>>
 */
function estab_incident_export_recipient_matrix(mysqli $connection): array
{
    try {
        return estab_generated_form_recipient_matrix($connection);
    } catch (RuntimeException | InvalidArgumentException $exception) {
        throw new EstabIncidentExportDataException(
            'Die Empfängermatrix ist für den PDF-Export ungültig.',
            0,
            $exception
        );
    }
}

/** Normalize a MariaDB DATETIME(6) value used in an evidence hash. */
function estab_incident_export_evidence_datetime(mixed $value): string
{
    if (
        !is_string($value)
        || preg_match(
            '/\A(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})'
                . '(?:\.([0-9]{1,6}))?\z/D',
            $value,
            $matches
        ) !== 1
    ) {
        throw new EstabIncidentExportDataException(
            'Ein Nachweis enthält eine ungültige Zeitangabe.'
        );
    }
    return $matches[1] . '.' . str_pad(
        (string) ($matches[2] ?? ''),
        6,
        '0',
        STR_PAD_RIGHT
    );
}

/** Recompute one message-event hash exactly like migration 80. */
function estab_incident_export_message_event_hash(
    int $incidentId,
    array $event
): string {
    $fromStatus = $event['from_status'] ?? null;
    $toStatus = $event['to_status'] ?? null;
    return hash('sha256', implode("\n", [
        'estab-message-event-v1',
        (string) $incidentId,
        (string) ((int) ($event['message_id'] ?? 0)),
        (string) ($event['event_type'] ?? ''),
        estab_incident_export_evidence_datetime(
            $event['occurred_at'] ?? null
        ),
        estab_incident_export_evidence_datetime(
            $event['recorded_at'] ?? null
        ),
        (string) ($event['actor_user'] ?? ''),
        (string) ($event['actor_code'] ?? ''),
        (string) ($event['actor_function'] ?? ''),
        $fromStatus === null ? 'null' : (string) ((int) $fromStatus),
        $toStatus === null ? 'null' : (string) ((int) $toStatus),
        (string) ($event['snapshot_sha256'] ?? ''),
        (string) ($event['previous_event_sha256'] ?? ''),
    ]));
}

/**
 * Recompute snapshot hashes, event links, event hashes and persisted heads
 * exclusively from the rows loaded inside the export snapshot.
 *
 * @param list<array<string,mixed>> $messages
 * @param list<array<string,mixed>> $events
 * @param list<array<string,mixed>> $heads
 * @return array{
 *   valid:bool,
 *   event_count:int,
 *   message_count:int,
 *   broken_event_id:?int,
 *   head_mismatches:int,
 *   head_count:int,
 *   head_set_sha256:string,
 *   terminal_count:int,
 *   terminal_mismatches:int,
 *   terminal_unverifiable:int,
 *   terminal_binding_complete:bool
 * }
 */
function estab_incident_export_message_evidence_status(
    int $incidentId,
    array $messages,
    array $events,
    array $heads
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $knownMessages = [];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            throw new EstabIncidentExportDataException(
                'Ein Nachrichtendatensatz des Nachweises ist ungültig.'
            );
        }
        $messageId = estab_incident_positive_id(
            (int) ($message['00_lfd'] ?? 0),
            'Nachrichten-ID'
        );
        if (isset($knownMessages[$messageId])) {
            throw new EstabIncidentExportDataException(
                'Die Nachrichtenliste des Exports ist nicht eindeutig.'
            );
        }
        if ((int) ($message['einsatz_id'] ?? 0) !== $incidentId) {
            throw new EstabIncidentExportDataException(
                'Ein Nachrichtendatensatz gehört zu einem anderen Einsatz.'
            );
        }
        $knownMessages[$messageId] = $message;
    }

    $previousByMessage = [];
    $countByMessage = [];
    $brokenEventId = null;
    $terminalCount = 0;
    $terminalMismatches = 0;
    $terminalUnverifiable = 0;
    $terminalByMessage = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            throw new EstabIncidentExportDataException(
                'Ein Nachrichtennachweis ist ungültig.'
            );
        }
        $eventId = (int) ($event['event_id'] ?? 0);
        $messageId = (int) ($event['message_id'] ?? 0);
        $storedSnapshot = (string) ($event['field_snapshot'] ?? '');
        $storedSnapshotHash = (string) (
            $event['snapshot_sha256'] ?? ''
        );
        $storedPrevious = $event['previous_event_sha256'] === null
            ? null
            : (string) ($event['previous_event_sha256'] ?? '');
        $expectedPrevious = $previousByMessage[$messageId] ?? null;
        $expectedEventHash =
            estab_incident_export_message_event_hash($incidentId, $event);
        $validEvent = $eventId > 0
            && isset($knownMessages[$messageId])
            && hash_equals(
                $storedSnapshotHash,
                hash('sha256', $storedSnapshot)
            )
            && $storedPrevious === $expectedPrevious
            && hash_equals(
                (string) ($event['event_sha256'] ?? ''),
                $expectedEventHash
            );
        if (!$validEvent && $brokenEventId === null) {
            $brokenEventId = $eventId > 0 ? $eventId : null;
        }
        if ((int) ($event['to_status'] ?? -1) === 8) {
            $terminalByMessage[$messageId] = true;
            try {
                $snapshotValue = json_decode(
                    $storedSnapshot,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                $snapshotValue = null;
            }
            $terminalMessage = is_array($snapshotValue)
                ? ($snapshotValue['terminal_message'] ?? null)
                : null;
            $terminalHash = is_array($snapshotValue)
                ? ($snapshotValue['terminal_snapshot_sha256'] ?? null)
                : null;
            $terminalVersion = is_array($snapshotValue)
                ? estab_message_terminal_snapshot_stored_version(
                    $snapshotValue
                )
                : null;
            if (
                (string) ($event['event_type'] ?? '') === 'legacy_import'
                && (!is_array($terminalMessage) || !is_string($terminalHash))
            ) {
                $terminalUnverifiable++;
            } else {
                $terminalCount++;
                $liveMessage = $knownMessages[$messageId] ?? null;
                if (
                    !is_array($liveMessage)
                    || !is_array($terminalMessage)
                    || !is_string($terminalHash)
                    || !is_int($terminalVersion)
                    || !estab_message_terminal_snapshot_matches_live(
                        $terminalMessage,
                        $terminalHash,
                        $liveMessage,
                        $terminalVersion
                    )
                ) {
                    $terminalMismatches++;
                    if ($brokenEventId === null) {
                        $brokenEventId = $eventId > 0 ? $eventId : null;
                    }
                }
            }
        }
        if ($messageId > 0) {
            $previousByMessage[$messageId] =
                (string) ($event['event_sha256'] ?? '');
            $countByMessage[$messageId] =
                ($countByMessage[$messageId] ?? 0) + 1;
        }
    }

    $headMismatches = 0;
    $seenHeads = [];
    $headLines = [];
    foreach ($heads as $head) {
        if (!is_array($head)) {
            throw new EstabIncidentExportDataException(
                'Ein Nachrichtennachweiskopf ist ungültig.'
            );
        }
        $messageId = (int) ($head['message_id'] ?? 0);
        if (isset($seenHeads[$messageId])) {
            $headMismatches++;
        }
        $seenHeads[$messageId] = true;
        $eventCount = (int) ($head['event_count'] ?? -1);
        $lastHash = (string) ($head['last_event_sha256'] ?? '');
        if (
            !isset($knownMessages[$messageId])
            || !isset($countByMessage[$messageId])
            || $eventCount !== $countByMessage[$messageId]
            || !hash_equals(
                $lastHash,
                (string) ($previousByMessage[$messageId] ?? '')
            )
        ) {
            $headMismatches++;
        }
        $headLines[$messageId] = implode('|', [
            (string) $messageId,
            (string) $eventCount,
            $lastHash,
        ]);
    }
    foreach (array_keys($knownMessages) as $messageId) {
        if (!isset($seenHeads[$messageId])) {
            $headMismatches++;
        }
        if (
            (int) ($knownMessages[$messageId]['x00_status'] ?? 0) === 8
            && !isset($terminalByMessage[$messageId])
        ) {
            $terminalMismatches++;
        }
    }
    foreach (array_keys($countByMessage) as $messageId) {
        if (!isset($knownMessages[$messageId])) {
            $headMismatches++;
        }
    }
    ksort($headLines, SORT_NUMERIC);

    return [
        'valid' => $brokenEventId === null
            && $headMismatches === 0
            && $terminalMismatches === 0,
        'event_count' => count($events),
        'message_count' => count($knownMessages),
        'broken_event_id' => $brokenEventId,
        'head_mismatches' => $headMismatches,
        'head_count' => count($heads),
        'head_set_sha256' => hash(
            'sha256',
            implode("\n", array_values($headLines))
        ),
        'terminal_count' => $terminalCount,
        'terminal_mismatches' => $terminalMismatches,
        'terminal_unverifiable' => $terminalUnverifiable,
        'terminal_binding_complete' => $terminalMismatches === 0
            && $terminalUnverifiable === 0,
    ];
}

/**
 * Recompute the command-post event chain and compare it with its persisted
 * incident head.
 *
 * @param list<array<string,mixed>> $events
 * @param list<array<string,mixed>> $heads
 * @return array{
 *   valid:bool,
 *   event_count:int,
 *   failed_sequence:?int,
 *   calculated_head_sha256:string,
 *   stored_sequence:int,
 *   stored_head_sha256:string
 * }
 */
function estab_incident_export_operations_evidence_status(
    int $incidentId,
    array $events,
    array $heads
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    if (count($heads) > 1) {
        throw new EstabIncidentExportDataException(
            'Der Betriebsnachweiskopf ist nicht eindeutig.'
        );
    }
    $previousHash = str_repeat('0', 64);
    $expectedSequence = 1;
    $failedSequence = null;
    foreach ($events as $event) {
        if (!is_array($event)) {
            throw new EstabIncidentExportDataException(
                'Ein Betriebsereignis ist ungültig.'
            );
        }
        $sequence = (int) ($event['sequenz'] ?? 0);
        $expectedHash = hash('sha256', implode('|', [
            (string) $incidentId,
            (string) $sequence,
            (string) ($event['objekttyp'] ?? ''),
            (string) ((int) ($event['objekt_id'] ?? 0)),
            (string) ($event['aktion'] ?? ''),
            (string) ($event['akteur_kuerzel'] ?? ''),
            (string) ($event['akteur_funktion'] ?? ''),
            estab_incident_export_evidence_datetime(
                $event['ereigniszeit'] ?? null
            ),
            (string) ($event['details_json'] ?? ''),
            $previousHash,
        ]));
        if (
            $failedSequence === null
            && (
                $sequence !== $expectedSequence
                || !hash_equals(
                    $previousHash,
                    (string) ($event['vorheriger_hash'] ?? '')
                )
                || !hash_equals(
                    $expectedHash,
                    (string) ($event['ereignis_hash'] ?? '')
                )
            )
        ) {
            $failedSequence = $sequence;
        }
        $previousHash = $expectedHash;
        $expectedSequence++;
    }

    $head = $heads[0] ?? null;
    $storedSequence = is_array($head)
        ? (int) ($head['letzte_sequenz'] ?? -1)
        : 0;
    $storedHash = is_array($head)
        ? (string) ($head['letzter_hash'] ?? '')
        : str_repeat('0', 64);
    $headValid = $events === []
        ? (
            !is_array($head)
            || (
                $storedSequence === 0
                && hash_equals($previousHash, $storedHash)
            )
        )
        : (
            is_array($head)
            && $storedSequence === count($events)
            && hash_equals($previousHash, $storedHash)
        );
    if (!$headValid && $failedSequence === null) {
        $failedSequence = count($events);
    }

    return [
        'valid' => $failedSequence === null && $headValid,
        'event_count' => count($events),
        'failed_sequence' => $failedSequence,
        'calculated_head_sha256' => $previousHash,
        'stored_sequence' => $storedSequence,
        'stored_head_sha256' => $storedHash,
    ];
}

/**
 * Load the complete, immutable source bundle for one dossier.
 *
 * Missing files are a hard error when originals were requested.  The export
 * must never look complete after silently omitting an attachment.
 *
 * @return array{
 *   incident:array<string,mixed>,
 *   sections:list<string>,
 *   logbook_scope:array<string,mixed>,
 *   etb:list<array<string,mixed>>,
 *   ttb:list<array<string,mixed>>,
 *   messages:list<array<string,mixed>>,
 *   message_events:list<array<string,mixed>>,
 *   message_evidence_heads:list<array<string,mixed>>,
 *   message_evidence_status:array<string,mixed>,
 *   duty_shifts:list<array<string,mixed>>,
 *   duty_assignments:list<array<string,mixed>>,
 *   duty_handovers:list<array<string,mixed>>,
 *   s6_plans:list<array<string,mixed>>,
 *   s6_plan_entries:list<array<string,mixed>>,
 *   courier_orders:list<array<string,mixed>>,
 *   operations_events:list<array<string,mixed>>,
 *   operations_evidence_heads:list<array<string,mixed>>,
 *   operations_evidence_status:array<string,mixed>,
 *   recipient_matrix:array<int,array<int,array{fkt:string}>>,
 *   attachment_names_by_message:array<int,list<string>>,
 *   attachments:list<array<string,mixed>>,
 *   counts:array<string,int>
 * }
 */
function estab_incident_export_load(
    mysqli $connection,
    int $incidentId,
    array $sections,
    string $attachmentRoot,
    mixed $logbookScope = 'all'
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $sections = estab_incident_export_sections(array_combine(
        array_map(
            static fn (string $section): string => 'include_' . $section,
            $sections
        ),
        array_fill(0, count($sections), '1')
    ) ?: []);
    $incident = estab_incident_find($connection, $incidentId);
    if (!is_array($incident)) {
        throw new EstabIncidentNotFoundException(
            'Der ausgewählte Einsatz wurde nicht gefunden.'
        );
    }
    $parsedLogbookScope = estab_incident_export_logbook_scope($logbookScope);
    $resolvedLogbookScope = estab_incident_export_resolve_logbook_scope(
        $connection,
        $incidentId,
        $parsedLogbookScope
    );
    $shiftId = ($resolvedLogbookScope['mode'] ?? null) === 'shift'
        ? (int) ($resolvedLogbookScope['shift_id'] ?? 0)
        : null;

    $etb = [];
    if (in_array('etb', $sections, true)) {
        $etb = estab_incident_export_logbook_rows(
            $connection,
            'SELECT entry_row.`etb_lfd-nr`, entry_row.`estab_book_lfd`,'
                . ' entry_row.`etb_time`, entry_row.`estab_event_time`,'
                . ' entry_row.`estab_recorded_at`,'
                . ' entry_row.`estab_event_type`,'
                . ' entry_row.`estab_message_id`,'
                . ' entry_row.`estab_attachment_id`,'
                . ' entry_row.`estab_reference`,'
                . ' entry_row.`estab_correction_of`,'
                . ' correction_row.`estab_book_lfd`'
                . ' AS `estab_correction_book_lfd`,'
                . ' entry_row.`estab_shift_id`,'
                . ' entry_row.`estab_assignment`,'
                . ' entry_row.`etb_aktion`, entry_row.`etb_bemerk`,'
                . ' entry_row.`etb_benutzer`, entry_row.`etb_kuerzel`,'
                . ' entry_row.`etb_funktion`'
                . ' FROM `nv_etb` AS entry_row'
                . ' LEFT JOIN `nv_etb` AS correction_row'
                . ' ON correction_row.`etb_lfd-nr` ='
                . ' entry_row.`estab_correction_of`'
                . ' AND correction_row.`einsatz_id` = entry_row.`einsatz_id`'
                . ' WHERE entry_row.`einsatz_id` = ?'
                . ($shiftId === null
                    ? ''
                    : ' AND entry_row.`estab_shift_id` = ?')
                . ' ORDER BY entry_row.`estab_book_lfd`',
            $incidentId,
            $shiftId,
            'Das Einsatztagebuch konnte nicht gelesen werden.'
        );
    }

    $ttb = [];
    if (in_array('ttb', $sections, true)) {
        $ttb = estab_incident_export_logbook_rows(
            $connection,
            'SELECT entry_row.`tbb_lfd-nr`, entry_row.`estab_book_lfd`,'
                . ' entry_row.`tbb_time`, entry_row.`estab_event_time`,'
                . ' entry_row.`estab_recorded_at`,'
                . ' entry_row.`estab_entry_type`,'
                . ' entry_row.`estab_message_id`,'
                . ' entry_row.`estab_shift_id`,'
                . ' entry_row.`estab_personnel_duty`,'
                . ' entry_row.`estab_channel`,'
                . ' entry_row.`estab_message_route`,'
                . ' entry_row.`estab_operations`,'
                . ' entry_row.`estab_receipt`,'
                . ' entry_row.`estab_correction_of`,'
                . ' correction_row.`estab_book_lfd`'
                . ' AS `estab_correction_book_lfd`,'
                . ' entry_row.`tbb_aktion`, entry_row.`tbb_bemerk`,'
                . ' entry_row.`tbb_benutzer`, entry_row.`tbb_kuerzel`,'
                . ' entry_row.`tbb_funktion`'
                . ' FROM `nv_tbb` AS entry_row'
                . ' LEFT JOIN `nv_tbb` AS correction_row'
                . ' ON correction_row.`tbb_lfd-nr` ='
                . ' entry_row.`estab_correction_of`'
                . ' AND correction_row.`einsatz_id` = entry_row.`einsatz_id`'
                . ' WHERE entry_row.`einsatz_id` = ?'
                . ($shiftId === null
                    ? ''
                    : ' AND entry_row.`estab_shift_id` = ?')
                . ' ORDER BY entry_row.`estab_book_lfd`',
            $incidentId,
            $shiftId,
            'Das Technische Betriebsbuch konnte nicht gelesen werden.'
        );
    }

    $messages = [];
    $recipientMatrix = [];
    $attachmentNamesByMessage = [];
    if (in_array('messages', $sections, true)) {
        $recipientMatrix = estab_incident_export_recipient_matrix($connection);
        $messages = estab_incident_export_rows(
            $connection,
            'SELECT `00_lfd`, `einsatz_id`, `01_medium`, `01_datum`,'
                . ' `01_zeichen`,'
                . ' `02_zeit`, `02_zeichen`, `03_datum`, `03_zeichen`,'
                . ' `04_richtung`, `04_nummer`, `05_gegenstelle`,'
                . ' `06_befweg`, `06_befwegausw`, `07_durchspruch`,'
                . ' `08_befhinweis`, `08_befhinwausw`, `09_vorrangstufe`,'
                . ' `10_anschrift`, `11_rufnummer`, `11_gesprnotiz`,'
                . ' `12_anhang`, `12_betreff`, `12_inhalt`, `12_abfzeit`,'
                . ' `13_abseinheit`, `14_zeichen`, `14_funktion`,'
                . ' `15_quitdatum`, `15_quitzeichen`, `16_empf`,'
                . ' `17_vermerke`, `x00_status`, `x01_abschluss`,'
                . ' `x04_druck`, `x05_druck_d`, `99_lstacc`,'
                . ' (SELECT ttb_row.`estab_book_lfd` FROM `nv_tbb` AS ttb_row'
                . ' WHERE ttb_row.`einsatz_id` = `nv_nachrichten`.`einsatz_id`'
                . ' AND ttb_row.`estab_message_id` = `nv_nachrichten`.`00_lfd`'
                . " AND BINARY ttb_row.`estab_entry_type` = BINARY 'nachricht'"
                . ' ORDER BY ttb_row.`estab_book_lfd`,'
                . ' ttb_row.`tbb_lfd-nr` LIMIT 1)'
                . ' AS `estab_ttb_lfd`,'
                . ' `estab_fernmeldeplan_eintrag_id`'
                . ' FROM `nv_nachrichten` WHERE `einsatz_id` = ?'
                . ' ORDER BY COALESCE(`01_datum`, `12_abfzeit`), `00_lfd`',
            $incidentId,
            'Die Nachrichtenvordrucke konnten nicht gelesen werden.'
        );
        foreach ($messages as $message) {
            $messageId = (int) ($message['00_lfd'] ?? 0);
            if ($messageId < 1) {
                throw new EstabIncidentExportDataException(
                    'Ein Nachrichtendatensatz hat keine gültige Kennung.'
                );
            }
            $attachmentNamesByMessage[$messageId] =
                estab_incident_export_message_attachments(
                    $message['12_anhang'] ?? null
                );
        }
    }

    $messageVerificationRows = [];
    if ($messages !== []) {
        $messageVerificationRows = $messages;
    } else {
        $messageVerificationRows = estab_incident_export_rows(
            $connection,
            'SELECT `00_lfd`, `einsatz_id`, `01_medium`, `01_datum`,'
                . ' `01_zeichen`, `02_zeit`, `02_zeichen`, `03_datum`,'
                . ' `03_zeichen`, `04_richtung`, `04_nummer`,'
                . ' `05_gegenstelle`, `06_befweg`, `06_befwegausw`,'
                . ' `07_durchspruch`, `08_befhinweis`, `08_befhinwausw`,'
                . ' `09_vorrangstufe`, `10_anschrift`, `11_rufnummer`,'
                . ' `11_gesprnotiz`, `12_anhang`, `12_betreff`,'
                . ' `12_inhalt`, `12_abfzeit`,'
                . ' `13_abseinheit`, `14_zeichen`, `14_funktion`,'
                . ' `15_quitdatum`, `15_quitzeichen`, `16_empf`,'
                . ' `17_vermerke`, `x00_status`, `x01_abschluss`,'
                . ' `estab_fernmeldeplan_eintrag_id`'
                . ' FROM `nv_nachrichten` WHERE `einsatz_id` = ?'
                . ' ORDER BY `00_lfd`',
            $incidentId,
            'Die Nachrichten für den Nachweis konnten nicht '
                . 'gelesen werden.'
        );
    }

    $messageEvents = estab_incident_export_rows(
        $connection,
        'SELECT `event_id`, `einsatz_id`, `message_id`, `event_type`,'
            . " DATE_FORMAT(`occurred_at`, '%Y-%m-%d %H:%i:%s.%f')"
            . ' AS `occurred_at`,'
            . " DATE_FORMAT(`recorded_at`, '%Y-%m-%d %H:%i:%s.%f')"
            . ' AS `recorded_at`,'
            . ' `actor_user`, `actor_code`, `actor_function`,'
            . ' `from_status`, `to_status`, `field_snapshot`,'
            . ' `snapshot_sha256`, `previous_event_sha256`, `event_sha256`'
            . ' FROM `nv_nachrichten_ereignisse`'
            . ' WHERE `einsatz_id` = ? ORDER BY `message_id`, `event_id`',
        $incidentId,
        'Die Nachrichtennachweise konnten nicht gelesen werden.'
    );
    $messageEvidenceHeads = estab_incident_export_rows(
        $connection,
        'SELECT `message_id`, `einsatz_id`, `event_count`,'
            . ' `last_event_sha256`,'
            . " DATE_FORMAT(`updated_at`, '%Y-%m-%d %H:%i:%s.%f')"
            . ' AS `updated_at`'
            . ' FROM `nv_nachrichten_nachweiskopf`'
            . ' WHERE `einsatz_id` = ? ORDER BY `message_id`',
        $incidentId,
        'Die Nachrichtennachweisköpfe konnten nicht gelesen werden.'
    );
    $messageEvidenceStatus =
        estab_incident_export_message_evidence_status(
            $incidentId,
            $messageVerificationRows,
            $messageEvents,
            $messageEvidenceHeads
        );

    $attachments = [];
    if (in_array('attachments', $sections, true)) {
        $etbAttachmentLinks = in_array('etb', $sections, true)
            && $shiftId === null
            ? $etb
            : estab_incident_export_rows(
                $connection,
                'SELECT `estab_book_lfd`, `estab_attachment_id`'
                    . ' FROM `nv_etb` WHERE `einsatz_id` = ?'
                    . ' AND `estab_attachment_id` IS NOT NULL'
                    . ' ORDER BY `estab_book_lfd`',
                $incidentId,
                'Die ETB-Anlagenzuordnungen konnten nicht gelesen werden.'
            );
        $etbAttachmentNumbers =
            estab_incident_export_etb_attachment_number_map(
                $incidentId,
                $etbAttachmentLinks
            );
        $attachmentRows = estab_incident_export_rows(
            $connection,
            'SELECT `lfd-nr`, `filename`, `fileext`, `org_filename`,'
                . ' `comment`, `date`, `kuerzel`, `integrity_required`,'
                . ' `ingest_sha256`, `ingest_size`,'
                . ' `integrity_captured_at`'
                . ' FROM `nv_anhang`'
                . ' WHERE `einsatz_id` = ? AND `status` = 1'
                . ' ORDER BY `filename`, `lfd-nr`',
            $incidentId,
            'Die Anhänge konnten nicht gelesen werden.'
        );

        $messageIdsByName = [];
        foreach ($attachmentNamesByMessage as $messageId => $names) {
            foreach ($names as $name) {
                $messageIdsByName[$name][] = $messageId;
            }
        }

        foreach ($attachmentRows as $position => $attachment) {
            $attachmentId = (int) ($attachment['lfd-nr'] ?? 0);
            if ($attachmentId < 1) {
                throw new EstabIncidentExportDataException(
                    'Ein Einsatzanhang besitzt keine gültige Identität.'
                );
            }
            $base = (string) ($attachment['filename'] ?? '');
            $extension = strtolower((string) ($attachment['fileext'] ?? ''));
            $storedName = $base . '.' . $extension;
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
            } catch (InvalidArgumentException | RuntimeException $exception) {
                throw new EstabIncidentExportDataException(
                    'Ein abgeschlossener Einsatzanhang fehlt oder ist unsicher: '
                        . $storedName,
                    0,
                    $exception
                );
            }

            $original = trim((string) ($attachment['org_filename'] ?? ''));
            $comment = trim((string) ($attachment['comment'] ?? ''));
            try {
                $integrity = estab_attachment_integrity_verify_file(
                    $attachment,
                    $path
                );
            } catch (EstabAttachmentIntegrityException $exception) {
                throw new EstabIncidentExportDataException(
                    'Der Einsatzanhang ' . $storedName
                        . ' hat keinen gültigen Eingangsnachweis: '
                        . $exception->getMessage(),
                    0,
                    $exception
                );
            }
            $etbNumbers = $etbAttachmentNumbers[$attachmentId] ?? [];
            $embeddedSourceName = $storedName;
            if (count($etbNumbers) === 1) {
                $embeddedSourceName = str_replace(
                    ' ',
                    '-',
                    $etbNumbers[0]
                ) . '--' . $storedName;
            }
            $embeddedName = estab_incident_export_embedded_name(
                $position + 1,
                $embeddedSourceName
            );
            $displayPrefix = $etbNumbers !== []
                ? implode(', ', $etbNumbers) . ' · '
                : '';
            $attachments[] = [
                'path' => $path,
                'stored_name' => $storedName,
                'embedded_name' => $embeddedName,
                'display_name' => $displayPrefix . ($original !== ''
                    ? $storedName . ' · ' . $original
                    : $storedName),
                'description' => $comment !== ''
                    ? $comment
                    : (
                        $integrity['state'] === 'verified'
                            ? 'Anhang aus eStab; Eingangsintegrität belegt'
                            : 'Legacy-Anhang aus eStab; Integrität beim '
                                . 'Eingang nicht belegbar'
                    ),
                'mime' => estab_file_content_type($path),
                'integrity_state' => $integrity['state'],
                'integrity_statement' => $integrity['statement'],
                'expected_sha256' => $integrity['sha256'],
                'expected_size' => $integrity['size'],
                'message_ids' => array_values(array_unique(
                    $messageIdsByName[$storedName] ?? []
                )),
                'etb_attachment_numbers' => $etbNumbers,
            ];
        }

        $knownNames = array_fill_keys(
            array_column($attachments, 'stored_name'),
            true
        );
        foreach ($messageIdsByName as $name => $messageIds) {
            if (!isset($knownNames[$name])) {
                throw new EstabIncidentExportDataException(
                    'Ein Nachrichtenvordruck verweist auf einen nicht '
                        . 'abgeschlossenen Einsatzanhang: ' . $name
                );
            }
        }
    }

    $dutyShifts = [];
    $dutyAssignments = [];
    $dutyHandovers = [];
    $dutyHandoverRequests = [];
    if (in_array('duty', $sections, true)) {
        $dutyShifts = estab_incident_export_rows(
            $connection,
            'SELECT `dienstschicht_id`, `einsatz_id`, `nummer`,'
                . ' `bezeichnung`, `status`, `vorgaenger_id`, `erstellt_am`,'
                . ' `erstellt_von`, `aktiviert_am`, `beendet_am`'
                . ' FROM `nv_dienstschichten` WHERE `einsatz_id` = ?'
                . ' ORDER BY `nummer`, `dienstschicht_id`',
            $incidentId,
            'Die Dienstschichten konnten nicht gelesen werden.'
        );
        $dutyAssignments = estab_incident_export_rows(
            $connection,
            'SELECT b.`dienstbesetzung_id`, b.`dienstschicht_id`,'
                . ' s.`nummer` AS `dienstschicht_nummer`,'
                . ' b.`benutzer_kuerzel`, b.`funktion`, b.`rolle`,'
                . ' b.`status`, b.`zugewiesen_am`, b.`zugewiesen_von`,'
                . ' b.`angenommen_am`, b.`abgeloest_am`, b.`nachfolger_id`'
                . ' FROM `nv_dienstbesetzungen` AS b'
                . ' JOIN `nv_dienstschichten` AS s'
                . ' ON s.`dienstschicht_id` = b.`dienstschicht_id`'
                . ' WHERE s.`einsatz_id` = ?'
                . ' ORDER BY s.`nummer`, b.`dienstbesetzung_id`',
            $incidentId,
            'Die Dienstbesetzungen konnten nicht gelesen werden.'
        );
        $dutyHandovers = estab_incident_export_rows(
            $connection,
            'SELECT `dienstuebergabe_id`, `einsatz_id`,'
                . ' `von_dienstschicht_id`, `an_dienstschicht_id`,'
                . ' `zusammenfassung`, `uebergeben_am`, `uebergeben_von`,'
                . ' `angenommen_von` FROM `nv_dienstuebergaben`'
                . ' WHERE `einsatz_id` = ?'
                . ' ORDER BY `uebergeben_am`, `dienstuebergabe_id`',
            $incidentId,
            'Die Dienstübergaben konnten nicht gelesen werden.'
        );
        $dutyHandoverRequests = estab_incident_export_rows(
            $connection,
            'SELECT `dienstuebergabe_anfrage_id`, `einsatz_id`,'
                . ' `von_dienstschicht_id`, `an_dienstschicht_id`,'
                . ' `zusammenfassung`, `status`, `initiiert_am`,'
                . ' `initiiert_von`, `bestaetigt_am`, `bestaetigt_von`,'
                . ' `bestaetigt_mit_besetzung_id`, `dienstuebergabe_id`,'
                . ' `storniert_am`, `storniert_von`, `stornierungsgrund`'
                . ' FROM `nv_dienstuebergabe_anfragen`'
                . ' WHERE `einsatz_id` = ?'
                . ' ORDER BY `initiiert_am`,'
                . ' `dienstuebergabe_anfrage_id`',
            $incidentId,
            'Die Übergabeanforderungen konnten nicht gelesen werden.'
        );
    }

    $s6Plans = [];
    $s6PlanEntries = [];
    if (in_array('s6_plans', $sections, true)) {
        $s6Plans = estab_incident_export_rows(
            $connection,
            'SELECT `fernmeldeplan_id`, `einsatz_id`, `version`, `status`,'
                . ' `einsatzbezeichnung`, `herkunft`, `gueltig_ab`,'
                . ' `gueltig_bis`, `betriebsleitung`, `bemerkungen`,'
                . ' `erstellt_am`, `erstellt_von`, `freigegeben_am`,'
                . ' `freigegeben_von` FROM `nv_fernmeldeplaene`'
                . ' WHERE `einsatz_id` = ?'
                . ' ORDER BY `version`, `fernmeldeplan_id`',
            $incidentId,
            'Die S6-Fernmeldeplanversionen konnten nicht gelesen werden.'
        );
        $s6PlanEntries = estab_incident_export_rows(
            $connection,
            'SELECT e.`fernmeldeplan_eintrag_id`, e.`fernmeldeplan_id`,'
                . ' p.`version` AS `plan_version`, e.`sortierung`,'
                . ' e.`betriebsstelle`, e.`rufname`, e.`medium`,'
                . ' e.`kanal`, e.`bandlage`, e.`verkehrsform`,'
                . ' e.`besondere_vermerke`, e.`bemerkungen`'
                . ' FROM `nv_fernmeldeplan_eintraege` AS e'
                . ' JOIN `nv_fernmeldeplaene` AS p'
                . ' ON p.`fernmeldeplan_id` = e.`fernmeldeplan_id`'
                . ' WHERE p.`einsatz_id` = ?'
                . ' ORDER BY p.`version`, e.`sortierung`,'
                . ' e.`fernmeldeplan_eintrag_id`',
            $incidentId,
            'Die S6-Fernmeldeplaneinträge konnten nicht gelesen werden.'
        );
    }

    $courierOrders = [];
    if (in_array('courier', $sections, true)) {
        $courierOrders = estab_incident_export_rows(
            $connection,
            'SELECT `melderauftrag_id`, `einsatz_id`, `nachricht_id`,'
                . ' `melder_kuerzel`, `ziel`, `status`, `beauftragt_am`,'
                . ' `beauftragt_von`, `uebernommen_am`,'
                . ' `tatsaechlicher_empfaenger`, `uebergeben_am`,'
                . ' `ruecknachricht_vorhanden`, `ruecknachricht`,'
                . ' `rueckweg_am`, `zurueck_am`,'
                . ' `abschlussvermerk`, `gemeldet_am`, `gemeldet_an`,'
                . ' `abgebrochen_am`, `abbruchgrund`'
                . ' FROM `nv_melderauftraege` WHERE `einsatz_id` = ?'
                . ' ORDER BY `beauftragt_am`, `melderauftrag_id`',
            $incidentId,
            'Die Melderaufträge konnten nicht gelesen werden.'
        );
    }

    $operationsEvents = estab_incident_export_rows(
        $connection,
        'SELECT `betriebsereignis_id`, `einsatz_id`, `sequenz`,'
            . ' `objekttyp`, `objekt_id`, `aktion`, `akteur_kuerzel`,'
            . ' `akteur_funktion`,'
            . " DATE_FORMAT(`ereigniszeit`, '%Y-%m-%d %H:%i:%s.%f')"
            . ' AS `ereigniszeit`, CAST(`details` AS CHAR) AS `details_json`,'
            . ' `vorheriger_hash`, `ereignis_hash`'
            . ' FROM `nv_betriebsereignisse` WHERE `einsatz_id` = ?'
            . ' ORDER BY `sequenz`, `betriebsereignis_id`',
        $incidentId,
        'Die Betriebsereignisse konnten nicht gelesen werden.'
    );
    $operationsEvidenceHeads = estab_incident_export_rows(
        $connection,
        'SELECT `einsatz_id`, `letzte_sequenz`, `letzter_hash`'
            . ' FROM `nv_betriebsereignis_kopf` WHERE `einsatz_id` = ?',
        $incidentId,
        'Der Betriebsnachweiskopf konnte nicht gelesen werden.'
    );
    $operationsEvidenceStatus =
        estab_incident_export_operations_evidence_status(
            $incidentId,
            $operationsEvents,
            $operationsEvidenceHeads
        );

    return [
        'incident' => $incident,
        'sections' => $sections,
        'logbook_scope' => $resolvedLogbookScope,
        'etb' => $etb,
        'ttb' => $ttb,
        'messages' => $messages,
        'message_events' => $messageEvents,
        'message_evidence_heads' => $messageEvidenceHeads,
        'message_evidence_status' => $messageEvidenceStatus,
        'duty_shifts' => $dutyShifts,
        'duty_assignments' => $dutyAssignments,
        'duty_handovers' => $dutyHandovers,
        'duty_handover_requests' => $dutyHandoverRequests,
        's6_plans' => $s6Plans,
        's6_plan_entries' => $s6PlanEntries,
        'courier_orders' => $courierOrders,
        'operations_events' => $operationsEvents,
        'operations_evidence_heads' => $operationsEvidenceHeads,
        'operations_evidence_status' => $operationsEvidenceStatus,
        'recipient_matrix' => $recipientMatrix,
        'attachment_names_by_message' => $attachmentNamesByMessage,
        'attachments' => $attachments,
        'counts' => [
            'etb' => count($etb),
            'ttb' => count($ttb),
            'messages' => count($messages),
            'attachments' => count($attachments),
            'attachments_verified' => count(array_filter(
                $attachments,
                static fn (array $attachment): bool =>
                    ($attachment['integrity_state'] ?? null) === 'verified'
            )),
            'attachments_legacy' => count(array_filter(
                $attachments,
                static fn (array $attachment): bool =>
                    ($attachment['integrity_state'] ?? null)
                        === 'legacy_unverifiable'
            )),
            'message_evidence' => count($messageEvents),
            'message_evidence_heads' => count($messageEvidenceHeads),
            'duty' => count($dutyShifts)
                + count($dutyAssignments)
                + count($dutyHandovers)
                + count($dutyHandoverRequests),
            'duty_shifts' => count($dutyShifts),
            'duty_assignments' => count($dutyAssignments),
            'duty_handovers' => count($dutyHandovers),
            'duty_handover_requests' => count($dutyHandoverRequests),
            's6_plans' => count($s6Plans),
            's6_plan_entries' => count($s6PlanEntries),
            'courier' => count($courierOrders),
            'operations_evidence' => count($operationsEvents),
        ],
    ];
}

/**
 * Render a complete PDF from a previously loaded bundle.
 *
 * @return array{
 *   bytes:string,
 *   attachment_count:int,
 *   attachment_bytes:int,
 *   attachment_visible_count:int,
 *   attachment_visible_pages:int,
 *   attachment_rendered_count:int,
 *   attachment_rendered_pages:int,
 *   attachment_information_pages:int,
 *   sha256:string
 * }
 */
function estab_incident_export_pdf(
    array $bundle,
    string $actor,
    int $attachmentByteLimit,
    ?DateTimeImmutable $generatedAt = null
): array {
    $incident = $bundle['incident'] ?? null;
    $sections = $bundle['sections'] ?? null;
    if (!is_array($incident) || !is_array($sections)) {
        throw new EstabIncidentExportDataException(
            'Die PDF-Quelldaten sind unvollständig.'
        );
    }
    $actor = estab_incident_actor($actor);
    $generatedAt ??= new DateTimeImmutable('now');
    $recipientMatrix = $bundle['recipient_matrix'] ?? null;
    if (
        in_array('messages', $sections, true)
        && !is_array($recipientMatrix)
    ) {
        throw new EstabIncidentExportDataException(
            'Die Empfängermatrix des PDF-Exports fehlt.'
        );
    }
    $pdf = new EstabIncidentPdf(
        $incident,
        $attachmentByteLimit,
        is_array($recipientMatrix) ? $recipientMatrix : null
    );

    $embeddedIndex = [];
    if (in_array('attachments', $sections, true)) {
        foreach (($bundle['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) {
                throw new EstabIncidentExportDataException(
                    'Die Anhangdaten des PDF-Exports sind ungültig.'
                );
            }
            $embedded = $pdf->embedAttachment(
                (string) ($attachment['path'] ?? ''),
                (string) ($attachment['embedded_name'] ?? ''),
                (string) ($attachment['mime'] ?? ''),
                (string) ($attachment['description'] ?? ''),
                is_string($attachment['expected_sha256'] ?? null)
                    ? $attachment['expected_sha256']
                    : null,
                is_int($attachment['expected_size'] ?? null)
                    ? $attachment['expected_size']
                    : null
            );
            $embeddedIndex[] = [
                'display_name' => (string) (
                    $attachment['display_name'] ?? ''
                ),
                'stored_name' => $embedded['name'],
                'archive_name' => (string) (
                    $attachment['stored_name'] ?? ''
                ),
                'size' => $embedded['size'],
                'sha256' => $embedded['sha256'],
                'mime' => $embedded['mime'],
                'integrity_state' => (string) (
                    $attachment['integrity_state'] ?? ''
                ),
                'integrity_statement' => (string) (
                    $attachment['integrity_statement'] ?? ''
                ),
                'message_ids' => is_array(
                    $attachment['message_ids'] ?? null
                ) ? $attachment['message_ids'] : [],
                'etb_attachment_numbers' => is_array(
                    $attachment['etb_attachment_numbers'] ?? null
                ) ? $attachment['etb_attachment_numbers'] : [],
            ];
        }
    }

    $pdf->addCover(
        $incident,
        $sections,
        is_array($bundle['counts'] ?? null) ? $bundle['counts'] : [],
        $generatedAt->format('d.m.Y H:i:s T'),
        $actor,
        [
            'message' => is_array(
                $bundle['message_evidence_status'] ?? null
            ) ? $bundle['message_evidence_status'] : [],
            'operations' => is_array(
                $bundle['operations_evidence_status'] ?? null
            ) ? $bundle['operations_evidence_status'] : [],
        ],
        is_array($bundle['logbook_scope'] ?? null)
            ? $bundle['logbook_scope']
            : []
    );
    if (in_array('etb', $sections, true)) {
        $pdf->addLogbook(
            'ETB',
            is_array($bundle['etb'] ?? null) ? $bundle['etb'] : []
        );
    }
    if (in_array('ttb', $sections, true)) {
        $pdf->addLogbook(
            'TBB',
            is_array($bundle['ttb'] ?? null) ? $bundle['ttb'] : []
        );
    }
    if (in_array('messages', $sections, true)) {
        $pdf->addMessages(
            is_array($bundle['messages'] ?? null)
                ? $bundle['messages']
                : [],
            is_array($bundle['attachment_names_by_message'] ?? null)
                ? $bundle['attachment_names_by_message']
                : []
        );
    }
    if (in_array('message_evidence', $sections, true)) {
        $pdf->addMessageEvidence(
            is_array($bundle['message_events'] ?? null)
                ? $bundle['message_events']
                : [],
            is_array($bundle['message_evidence_heads'] ?? null)
                ? $bundle['message_evidence_heads']
                : [],
            is_array($bundle['message_evidence_status'] ?? null)
                ? $bundle['message_evidence_status']
                : []
        );
    }
    if (in_array('duty', $sections, true)) {
        $pdf->addDutyRecords(
            is_array($bundle['duty_shifts'] ?? null)
                ? $bundle['duty_shifts']
                : [],
            is_array($bundle['duty_assignments'] ?? null)
                ? $bundle['duty_assignments']
                : [],
            is_array($bundle['duty_handovers'] ?? null)
                ? $bundle['duty_handovers']
                : [],
            is_array($bundle['duty_handover_requests'] ?? null)
                ? $bundle['duty_handover_requests']
                : []
        );
    }
    if (in_array('s6_plans', $sections, true)) {
        $pdf->addS6Plans(
            is_array($bundle['s6_plans'] ?? null)
                ? $bundle['s6_plans']
                : [],
            is_array($bundle['s6_plan_entries'] ?? null)
                ? $bundle['s6_plan_entries']
                : []
        );
    }
    if (in_array('courier', $sections, true)) {
        $pdf->addCourierOrders(
            is_array($bundle['courier_orders'] ?? null)
                ? $bundle['courier_orders']
                : []
        );
    }
    if (in_array('operations_evidence', $sections, true)) {
        $pdf->addOperationsEvidence(
            is_array($bundle['operations_events'] ?? null)
                ? $bundle['operations_events']
                : [],
            is_array($bundle['operations_evidence_heads'] ?? null)
                ? $bundle['operations_evidence_heads']
                : [],
            is_array($bundle['operations_evidence_status'] ?? null)
                ? $bundle['operations_evidence_status']
                : []
        );
    }
    $attachmentVisibility = [
        'attachment_visible_count' => 0,
        'attachment_visible_pages' => 0,
        'attachment_rendered_count' => 0,
        'attachment_rendered_pages' => 0,
        'attachment_information_pages' => 0,
    ];
    if (in_array('attachments', $sections, true)) {
        $pdf->addAttachmentIndex($embeddedIndex);
        $attachmentVisibility = $pdf->addAttachmentPages($embeddedIndex);
    }

    $bytes = $pdf->Output('', 'S');
    return array_merge([
        'bytes' => $bytes,
        'attachment_count' => $pdf->embeddedAttachmentCount(),
        'attachment_bytes' => $pdf->embeddedAttachmentBytes(),
        'sha256' => hash('sha256', $bytes),
    ], $attachmentVisibility);
}
