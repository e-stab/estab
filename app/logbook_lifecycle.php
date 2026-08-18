<?php

declare(strict_types=1);

require_once __DIR__ . '/permission_mode.php';

/**
 * System-generated ETB/TBB lifecycle records.
 *
 * Every function in this file participates in the caller's existing
 * transaction. It deliberately performs no commit or rollback: duty-shift
 * events, book entries and incident close must either all succeed or all fail.
 */

/** @return array<string,string> field => operator-facing label */
function estab_logbook_lifecycle_missing_header(array $incident): array
{
    $required = [
        'kennung' => 'Einsatzkennung',
        'name' => 'genaue Einsatzbezeichnung',
        'beginn' => 'Einsatzbeginn',
        'organisation' => 'Bedarfsträger',
        'fuehrungsstellenname' => 'Name der Führungsstelle',
        'einsatzleitung' => 'verantwortliche Einsatz-/Führungsleitung',
        'beschreibung' => 'Einsatzauftrag und Ausgangslage',
    ];
    $missing = [];
    foreach ($required as $field => $label) {
        $value = $incident[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            $missing[$field] = $label;
        }
    }
    return $missing;
}

/** @return list<array<string,mixed>> */
function estab_logbook_lifecycle_roster(
    mysqli $connection,
    int $shiftId,
    ?string $requiredStatus = null
): array {
    if (
        $requiredStatus !== null
        && !in_array(
            $requiredStatus,
            ['ZUGEWIESEN', 'ANGENOMMEN', 'ABGELOEST', 'ZURUECKGEZOGEN'],
            true
        )
    ) {
        throw new InvalidArgumentException('Invalid roster status');
    }
    $sql = 'SELECT assignment.`dienstbesetzung_id`,'
        . ' assignment.`benutzer_kuerzel`, assignment.`funktion`,'
        . ' assignment.`rolle`, assignment.`status`, account.`benutzer`'
        . ' FROM `nv_dienstbesetzungen` AS assignment'
        . ' JOIN `nv_benutzer` AS account'
        . ' ON BINARY account.`kuerzel` ='
        . ' BINARY assignment.`benutzer_kuerzel`'
        . ' WHERE assignment.`dienstschicht_id` = ?';
    if ($requiredStatus !== null) {
        $sql .= ' AND assignment.`status` = ?';
    }
    $sql .= ' ORDER BY assignment.`funktion`, assignment.`dienstbesetzung_id`';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Logbuchbesetzung konnte nicht gelesen werden.');
    }
    try {
        if ($requiredStatus === null) {
            $statement->bind_param('i', $shiftId);
        } else {
            $statement->bind_param('is', $shiftId, $requiredStatus);
        }
        if (!$statement->execute()) {
            throw new RuntimeException('Logbuchbesetzung konnte nicht geprüft werden.');
        }
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    } finally {
        $statement->close();
    }
}

/** @param list<array<string,mixed>> $roster */
function estab_logbook_lifecycle_roster_text(array $roster): string
{
    $parts = [];
    foreach ($roster as $row) {
        $name = trim((string) ($row['benutzer'] ?? ''));
        $code = trim((string) ($row['benutzer_kuerzel'] ?? ''));
        $function = trim((string) ($row['funktion'] ?? ''));
        $role = trim((string) ($row['rolle'] ?? ''));
        if ($function === '' || $code === '') {
            continue;
        }
        $parts[] = $function . ($role === '' ? '' : ' (' . $role . ')')
            . ': ' . ($name === '' ? $code : $name . ' [' . $code . ']');
    }
    return $parts === [] ? 'keine dokumentierte Besetzung' : implode('; ', $parts);
}

/** @param list<array<string,mixed>> $roster */
function estab_logbook_lifecycle_function_text(
    array $roster,
    array $functions
): string {
    $matches = array_values(array_filter(
        $roster,
        static fn (array $row): bool => in_array(
            (string) ($row['funktion'] ?? ''),
            $functions,
            true
        )
    ));
    return estab_logbook_lifecycle_roster_text($matches);
}

/**
 * Name the one person who is allowed to continue the selected book.
 *
 * An explicit ETB assignment takes precedence over S2; for the TBB the first
 * accepted A/W assignment is deterministic and matches the write guard.
 *
 * @param list<array<string,mixed>> $roster
 */
function estab_logbook_lifecycle_writer_text(
    array $roster,
    string $kind
): string {
    if (!in_array($kind, ['etb', 'tbb'], true)) {
        throw new InvalidArgumentException('Invalid logbook kind');
    }
    $matches = array_values(array_filter(
        $roster,
        static function (array $row) use ($kind): bool {
            $function = (string) ($row['funktion'] ?? '');
            return $kind === 'etb'
                ? in_array($function, ['ETB', 'S2'], true)
                : $function === 'A/W';
        }
    ));
    usort(
        $matches,
        static function (array $left, array $right) use ($kind): int {
            if ($kind === 'etb') {
                $leftPriority = ($left['funktion'] ?? '') === 'ETB' ? 0 : 1;
                $rightPriority = ($right['funktion'] ?? '') === 'ETB' ? 0 : 1;
                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }
            }
            return (int) ($left['dienstbesetzung_id'] ?? PHP_INT_MAX)
                <=> (int) ($right['dienstbesetzung_id'] ?? PHP_INT_MAX);
        }
    );
    return $matches === []
        ? 'keine dokumentierte Logbuchführung'
        : estab_logbook_lifecycle_roster_text([$matches[0]]);
}

function estab_logbook_lifecycle_assert_empty_books(
    mysqli $connection,
    int $incidentId
): void {
    $statement = $connection->prepare(
        'SELECT (SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?)'
        . ' + (SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?) AS `entries`'
    );
    if (!$statement) {
        throw new RuntimeException('Logbucheröffnung konnte nicht geprüft werden.');
    }
    try {
        $statement->bind_param('ii', $incidentId, $incidentId);
        $statement->execute();
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
    } finally {
        $statement->close();
    }
    if ((int) ($row['entries'] ?? 0) !== 0) {
        throw new RuntimeException(
            'ETB und TBB können nicht erneut eröffnet werden, weil bereits '
                . 'Einträge vorhanden sind.'
        );
    }
}

/** Read the authoritative mode for one incident inside the caller's lock. */
function estab_logbook_lifecycle_permission_mode(
    mysqli $connection,
    int $incidentId
): string {
    if ($incidentId < 1) {
        throw new InvalidArgumentException('Logbucheinsatz ist ungültig.');
    }
    $statement = $connection->prepare(
        'SELECT `estab_permission_mode` FROM `nv_einsaetze`'
        . ' WHERE `einsatz_id` = ? LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Berechtigungsmodus des Logbucheinsatzes konnte nicht gelesen werden.'
        );
    }
    try {
        $statement->bind_param('i', $incidentId);
        $statement->execute();
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
    } finally {
        $statement->close();
    }
    if (!is_array($row)) {
        throw new RuntimeException('Logbucheinsatz ist nicht vorhanden.');
    }
    try {
        return estab_permission_mode($row['estab_permission_mode'] ?? null);
    } catch (InvalidArgumentException $exception) {
        throw new RuntimeException(
            'Berechtigungsmodus des Logbucheinsatzes ist ungültig.',
            previous: $exception
        );
    }
}

/**
 * Resolve the active shift required by a STRICT automatic book entry.
 *
 * LOOSE deliberately returns NULL: its books remain usable without a formal
 * duty shift. The caller already holds the active-incident transaction lock,
 * and STRICT additionally locks the selected active shift row here.
 */
function estab_logbook_lifecycle_active_shift_id(
    mysqli $connection,
    int $incidentId
): ?int {
    if (
        estab_logbook_lifecycle_permission_mode($connection, $incidentId)
            === ESTAB_PERMISSION_MODE_LOOSE
    ) {
        return null;
    }
    $statement = $connection->prepare(
        'SELECT `dienstschicht_id` FROM `nv_dienstschichten`'
        . " WHERE `einsatz_id` = ? AND `status` = 'AKTIV'"
        . ' LIMIT 1 FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Aktive Dienstschicht konnte nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('i', $incidentId);
        $statement->execute();
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
    } finally {
        $statement->close();
    }
    $shiftId = (int) ($row['dienstschicht_id'] ?? 0);
    if ($shiftId < 1) {
        throw new RuntimeException(
            'Ein automatischer Logbucheintrag benötigt im strengen Modus '
                . 'eine aktive Dienstschicht.'
        );
    }
    return $shiftId;
}

/**
 * Open both books exactly once when an incident has no historical entries.
 *
 * Existing deployments can already contain one or both legacy books without
 * a canonical opening row. Such evidence must not be reordered or rewritten;
 * in that case the caller leaves the history untouched. LOOSE may open without
 * a formal shift. STRICT opens only together with the first activated shift.
 */
function estab_logbook_lifecycle_open_books_if_empty(
    mysqli $connection,
    array $incident,
    ?int $shiftId = null
): bool {
    $incidentId = (int) ($incident['active_einsatz_id'] ?? 0);
    if ($incidentId < 1) {
        throw new RuntimeException('Logbucheröffnung hat keinen aktiven Einsatz.');
    }
    $permissionMode = estab_logbook_lifecycle_permission_mode(
        $connection,
        $incidentId
    );
    if (
        $permissionMode === ESTAB_PERMISSION_MODE_STRICT
        && $shiftId === null
    ) {
        return false;
    }
    $statement = $connection->prepare(
        'SELECT (SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` = ?)'
        . ' + (SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` = ?) AS `entries`'
    );
    if (!$statement) {
        throw new RuntimeException('Logbuchbestand konnte nicht geprüft werden.');
    }
    try {
        $statement->bind_param('ii', $incidentId, $incidentId);
        $statement->execute();
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
    } finally {
        $statement->close();
    }
    if ((int) ($row['entries'] ?? 0) !== 0) {
        return false;
    }
    estab_logbook_lifecycle_open_books($connection, $incident, $shiftId);
    return true;
}

/**
 * Bind one automatic logbook insert to the exact incident and book.
 *
 * The connection-local marker is deliberately short-lived and is always
 * cleared. The database trigger still validates the complete row; this marker
 * prevents ordinary application inserts from impersonating lifecycle output.
 */
function estab_logbook_lifecycle_with_system_write_context(
    mysqli $connection,
    int $incidentId,
    string $book,
    callable $operation
): mixed {
    if ($incidentId < 1 || !in_array($book, ['ETB', 'TTB'], true)) {
        throw new InvalidArgumentException(
            'Ungültiger automatischer Logbuchkontext.'
        );
    }
    $stateResult = $connection->query(
        'SELECT @estab_logbook_system_write_incident_id AS `incident_id`,'
        . ' @estab_logbook_system_write_book AS `book`'
    );
    if (!$stateResult) {
        throw new RuntimeException(
            'Automatischer Logbuchkontext konnte nicht geprüft werden.'
        );
    }
    try {
        $state = $stateResult->fetch_assoc();
    } finally {
        $stateResult->free();
    }
    if (
        !is_array($state)
        || ($state['incident_id'] ?? null) !== null
        || ($state['book'] ?? null) !== null
    ) {
        if (!$connection->query(
            'SET @estab_logbook_system_write_incident_id = NULL,'
            . ' @estab_logbook_system_write_book = NULL'
        )) {
            throw new RuntimeException(
                'Verbliebener automatischer Logbuchkontext konnte nicht '
                . 'verworfen werden.'
            );
        }
        throw new LogicException(
            'Ein verschachtelter oder verbliebener automatischer '
            . 'Logbuchkontext wurde verworfen.'
        );
    }
    if (!$connection->query(
        'SET @estab_logbook_system_write_incident_id = ' . $incidentId
        . ", @estab_logbook_system_write_book = '" . $book . "'"
    )) {
        throw new RuntimeException(
            'Automatischer Logbuchkontext konnte nicht gesetzt werden.'
        );
    }
    try {
        return $operation();
    } finally {
        if (!$connection->query(
            'SET @estab_logbook_system_write_incident_id = NULL,'
            . ' @estab_logbook_system_write_book = NULL'
        )) {
            throw new RuntimeException(
                'Automatischer Logbuchkontext konnte nicht zurückgesetzt '
                . 'werden.'
            );
        }
    }
}

function estab_logbook_lifecycle_insert_etb(
    mysqli $connection,
    int $incidentId,
    string $eventTime,
    string $event,
    string $comment = '',
    string $type = 'ohne',
    ?int $shiftId = null
): int {
    $shiftId ??= estab_logbook_lifecycle_active_shift_id(
        $connection,
        $incidentId
    );
    $statement = $connection->prepare(
        'INSERT INTO `nv_etb`'
        . ' (`einsatz_id`, `estab_shift_id`, `etb_time`, `etb_aktion`,'
        . ' `etb_bemerk`,'
        . ' `etb_funktion`, `etb_kuerzel`, `etb_benutzer`,'
        . ' `estab_event_time`, `estab_event_type`)'
        . " VALUES (?, ?, ?, ?, ?, '', 'system', 'eStab-System', ?, ?)"
    );
    if (!$statement) {
        throw new RuntimeException('Automatischer ETB-Eintrag konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param(
            'iisssss',
            $incidentId,
            $shiftId,
            $eventTime,
            $event,
            $comment,
            $eventTime,
            $type
        );
        return estab_logbook_lifecycle_with_system_write_context(
            $connection,
            $incidentId,
            'ETB',
            static function () use ($statement, $connection): int {
                if (!$statement->execute()) {
                    throw new RuntimeException(
                        'Automatischer ETB-Eintrag konnte nicht gespeichert '
                        . 'werden.'
                    );
                }
                // Preserve LAST_INSERT_ID before the marker cleanup query.
                return (int) $connection->insert_id;
            }
        );
    } finally {
        $statement->close();
    }
}

/**
 * @param array{personnel_duty?:string,channel?:string,message_route?:string,
 *   operations?:string,receipt?:string} $content
 */
function estab_logbook_lifecycle_insert_ttb_record(
    mysqli $connection,
    int $incidentId,
    string $eventTime,
    string $entryType,
    array $content,
    string $comment = '',
    ?int $messageId = null,
    ?int $correctionOf = null,
    ?int $shiftId = null
): int {
    $shiftId ??= estab_logbook_lifecycle_active_shift_id(
        $connection,
        $incidentId
    );
    $labels = [
        'personnel_duty' => 'Betrieb / Personal / Dienst',
        'channel' => 'Kanal / Rufgruppe / Bedienung',
        'message_route' => 'Nachricht von / an',
        'operations' => 'Betriebsablauf / Ereignis / Störung',
        'receipt' => 'Quittung / Empfänger / Aushändigung',
    ];
    $values = [];
    $summaryParts = [];
    foreach ($labels as $field => $label) {
        $value = trim((string) ($content[$field] ?? ''));
        $values[$field] = $value;
        if ($value !== '') {
            $summaryParts[] = $label . ': ' . $value;
        }
    }
    if ($summaryParts === []) {
        throw new InvalidArgumentException('Automatischer TBB-Eintrag ist leer.');
    }
    $summary = implode("\n", $summaryParts);
    $statement = $connection->prepare(
        'INSERT INTO `nv_tbb`'
        . ' (`einsatz_id`, `estab_shift_id`, `tbb_time`, `tbb_aktion`,'
        . ' `tbb_bemerk`,'
        . ' `tbb_funktion`, `tbb_kuerzel`, `tbb_benutzer`,'
        . ' `estab_event_time`, `estab_entry_type`, `estab_message_id`,'
        . ' `estab_correction_of`,'
        . ' `estab_personnel_duty`, `estab_channel`,'
        . ' `estab_message_route`, `estab_operations`, `estab_receipt`)'
        . " VALUES (?, ?, ?, ?, ?, '', 'system', 'eStab-System',"
        . ' ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$statement) {
        throw new RuntimeException('Automatischer TBB-Eintrag konnte nicht vorbereitet werden.');
    }
    try {
        $types = 'ii' . str_repeat('s', 5) . 'ii' . str_repeat('s', 5);
        $statement->bind_param(
            $types,
            $incidentId,
            $shiftId,
            $eventTime,
            $summary,
            $comment,
            $eventTime,
            $entryType,
            $messageId,
            $correctionOf,
            $values['personnel_duty'],
            $values['channel'],
            $values['message_route'],
            $values['operations'],
            $values['receipt']
        );
        return estab_logbook_lifecycle_with_system_write_context(
            $connection,
            $incidentId,
            'TTB',
            static function () use ($statement, $connection): int {
                if (!$statement->execute()) {
                    throw new RuntimeException(
                        'Automatischer TBB-Eintrag konnte nicht gespeichert '
                        . 'werden.'
                    );
                }
                // Preserve LAST_INSERT_ID before the marker cleanup query.
                return (int) $connection->insert_id;
            }
        );
    } finally {
        $statement->close();
    }
}

/**
 * Append the LdF-confirmed sender/route as a correction to the immutable
 * receipt row. The original TBB number stays the number printed on the
 * message; the new local number documents who clarified what and when.
 *
 * @param array{medium:string,sender:string} $before
 * @param array{medium:string,sender:string} $after
 */
function estab_logbook_lifecycle_message_transport_correction(
    mysqli $connection,
    int $incidentId,
    int $messageId,
    string $eventTime,
    string $actor,
    array $before,
    array $after,
    string $reason = ''
): ?int {
    $changes = [];
    if (!hash_equals($before['medium'], $after['medium'])) {
        $changes[] = 'Eingangsweg von ' . $before['medium']
            . ' auf ' . $after['medium'];
    }
    if (!hash_equals($before['sender'], $after['sender'])) {
        $changes[] = 'Absender von ' . $before['sender']
            . ' auf ' . $after['sender'];
    }
    if ($changes === []) {
        return null;
    }

    $statement = $connection->prepare(
        'SELECT `tbb_lfd-nr`, `estab_book_lfd` FROM `nv_tbb`'
        . ' WHERE `einsatz_id` = ? AND `estab_message_id` = ?'
        . " AND BINARY `estab_entry_type` = BINARY 'nachricht'"
        . ' ORDER BY `estab_book_lfd`, `tbb_lfd-nr` LIMIT 1 FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Ursprünglicher TBB-Nachrichtennachweis konnte nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('ii', $incidentId, $messageId);
        $statement->execute();
        $result = $statement->get_result();
        $original = $result->fetch_assoc();
        $result->free();
    } finally {
        $statement->close();
    }
    if (!is_array($original)) {
        throw new RuntimeException(
            'Ursprünglicher TBB-Nachrichtennachweis fehlt.'
        );
    }
    $originalId = (int) ($original['tbb_lfd-nr'] ?? 0);
    $originalLocal = (int) ($original['estab_book_lfd'] ?? 0);
    if ($originalId < 1 || $originalLocal < 1) {
        throw new RuntimeException(
            'Ursprünglicher TBB-Nachrichtennachweis ist ungültig.'
        );
    }

    $reason = trim($reason);
    $comment = 'Nachtrag zu TBB-Nr. ' . $originalLocal
        . ' nach verbindlicher LdF-Prüfung.';
    if ($reason !== '') {
        $comment .= ' Begründung: ' . $reason;
    }
    return estab_logbook_lifecycle_insert_ttb_record(
        $connection,
        $incidentId,
        $eventTime,
        'korrektur',
        [
            'channel' => 'Bestätigter Eingangsweg: ' . $after['medium'],
            'message_route' => 'Bestätigter Absender: ' . $after['sender'],
            'operations' => 'LdF-Nachtrag: ' . implode('; ', $changes) . '.',
            'receipt' => 'Verbindlich bestätigt durch LdF ' . $actor . '.',
        ],
        $comment,
        null,
        $originalId
    );
}

function estab_logbook_lifecycle_insert_ttb(
    mysqli $connection,
    int $incidentId,
    string $eventTime,
    string $personnelDuty,
    string $operations = '',
    string $comment = '',
    string $entryType = 'betrieb_personal',
    ?int $shiftId = null
): int {
    return estab_logbook_lifecycle_insert_ttb_record(
        $connection,
        $incidentId,
        $eventTime,
        $entryType,
        [
            'personnel_duty' => $personnelDuty,
            'operations' => $operations,
        ],
        $comment,
        null,
        null,
        $shiftId
    );
}

/** Record a genuine incoming receipt or completed outgoing transmission. */
function estab_logbook_lifecycle_message_transport(
    mysqli $connection,
    int $incidentId,
    int $messageId,
    string $eventTime,
    string $direction
): int {
    if (!in_array($direction, ['E', 'A'], true)) {
        throw new InvalidArgumentException('Ungültige Nachrichtenrichtung.');
    }
    $statement = $connection->prepare(
        'SELECT `04_nummer`, `01_medium`, `01_datum`, `01_zeichen`,'
        . ' `03_datum`, `03_zeichen`,'
        . ' `05_gegenstelle`, `06_befweg`, `06_befwegausw`,'
        . ' `10_anschrift`, `12_betreff`, `13_abseinheit`,'
        . ' `15_quitzeichen` FROM `nv_nachrichten`'
        . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
        . ' AND `04_richtung` = ? FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException('Nachricht konnte für das TBB nicht gelesen werden.');
    }
    try {
        $statement->bind_param('iis', $messageId, $incidentId, $direction);
        $statement->execute();
        $result = $statement->get_result();
        $message = $result->fetch_assoc();
        $result->free();
    } finally {
        $statement->close();
    }
    if (!is_array($message)) {
        throw new RuntimeException('Nachricht gehört nicht zum aktiven Einsatz.');
    }
    $factualMessageTime = trim((string) (
        $direction === 'E'
            ? ($message['01_datum'] ?? '')
            : ($message['03_datum'] ?? '')
    ));
    if ($factualMessageTime !== '') {
        $eventTime = $factualMessageTime;
    }
    $number = (int) ($message['04_nummer'] ?? 0);
    $senderUnit = trim((string) ($message['13_abseinheit'] ?? ''));
    $counterpartyCallsign = trim((string) ($message['05_gegenstelle'] ?? ''));
    $counterparty = trim((string) (
        $direction === 'E'
            ? ($senderUnit !== '' ? $senderUnit : $counterpartyCallsign)
            : $counterpartyCallsign
    ));
    $local = trim((string) (
        $direction === 'E'
            ? ($message['10_anschrift'] ?? '')
            : ($message['13_abseinheit'] ?? '')
    ));
    $selectedRoute = trim((string) ($message['06_befwegausw'] ?? ''));
    $medium = trim((string) ($message['01_medium'] ?? ''));
    $route = trim(implode(' / ', array_filter([
        $selectedRoute !== '' ? $selectedRoute : $medium,
        (string) ($message['06_befweg'] ?? ''),
    ], static fn (string $part): bool => trim($part) !== '')));
    $mark = trim((string) (
        $direction === 'E'
            ? ($message['01_zeichen'] ?? '')
            : ($message['03_zeichen'] ?? '')
    ));
    // Field 16 holds internal distribution tokens ("S2_rt,"). Column 7 of
    // Fb Fü 44 is an official record: it receives the translated recipients
    // with the separate handover entry, never the raw token list.
    $receipt = trim(implode(' / ', array_filter([
        $mark === '' ? '' : 'bearbeitet durch ' . $mark,
        (string) ($message['15_quitzeichen'] ?? ''),
    ], static fn (string $part): bool => trim($part) !== '')));
    return estab_logbook_lifecycle_insert_ttb_record(
        $connection,
        $incidentId,
        $eventTime,
        'nachricht',
        [
            'channel' => $route,
            'message_route' => $direction === 'E'
                ? 'von ' . ($counterparty === '' ? 'nicht angegeben' : $counterparty)
                    . ' an ' . ($local === '' ? 'Führungsstelle' : $local)
                : 'von ' . ($local === '' ? 'Führungsstelle' : $local)
                    . ' an ' . ($counterparty === '' ? 'nicht angegeben' : $counterparty),
            'operations' => ($direction === 'E'
                ? 'Nachricht aufgenommen'
                : 'Nachricht befördert')
                . ($number > 0 ? ' (Meldungsnummer ' . $number . ')' : '')
                . '. Betreff: ' . trim((string) ($message['12_betreff'] ?? '')),
            'receipt' => $receipt,
        ],
        'Automatisch mit dem verbindlichen Nachrichtenworkflow erzeugt.',
        $messageId
    );
}

/**
 * Append the handover of one sighted incoming message to the TBB.
 *
 * Column 7 of Fb Fü 44 asks who received the message and who handed it over.
 * That is unknown while the message is being taken in, and the TBB is
 * append-only: the row written at intake stays exactly as it was booked. The
 * Handbuch ETB/TBB keeps its own entry kind for this event — "Quittung,
 * Empfänger oder Aushändigung" — so the completed sighting appends one. It
 * carries its own TBB number, names the entry it completes and therefore
 * documents the handover without touching the immutable original. The
 * database enforces the same reading: a second row may neither reuse the
 * unique message link nor reference the original unless it declares itself a
 * correction, and this event corrects nothing.
 *
 * Recipients arrive as readable text. Field 16 stores internal matrix tokens
 * such as "S2_rt,"; they name an application feature, not a person, and are
 * translated by the caller before they reach an official column.
 */
function estab_logbook_lifecycle_message_handover(
    mysqli $connection,
    int $incidentId,
    int $messageId,
    string $eventTime,
    string $reviewer,
    string $recipients
): int {
    $reviewer = trim($reviewer);
    $recipients = trim($recipients);
    if ($recipients === '') {
        throw new InvalidArgumentException(
            'Die Aushändigung der Nachricht nennt keinen Empfänger.'
        );
    }
    if (
        preg_match(
            '~(?:\A|[\s,;])[^\s,;]{1,10}_(?:bl|gn|rt|ge|gb)(?:\z|[\s,;])~Di',
            $recipients
        ) === 1
    ) {
        throw new InvalidArgumentException(
            'Die Quittungsspalte nimmt keine anwendungsinternen '
            . 'Verteilerkennungen auf.'
        );
    }
    $statement = $connection->prepare(
        'SELECT `04_nummer`, `05_gegenstelle`, `12_betreff`, `13_abseinheit`'
        . ' FROM `nv_nachrichten`'
        . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
        . " AND `04_richtung` = 'E' FOR UPDATE"
    );
    if (!$statement) {
        throw new RuntimeException(
            'Nachricht konnte für die TBB-Quittung nicht gelesen werden.'
        );
    }
    try {
        $statement->bind_param('ii', $messageId, $incidentId);
        $statement->execute();
        $result = $statement->get_result();
        $message = $result->fetch_assoc();
        $result->free();
    } finally {
        $statement->close();
    }
    if (!is_array($message)) {
        throw new RuntimeException(
            'Die ausgehändigte Nachricht gehört nicht zum aktiven Einsatz.'
        );
    }
    $number = (int) ($message['04_nummer'] ?? 0);
    $senderUnit = trim((string) ($message['13_abseinheit'] ?? ''));
    $sender = $senderUnit !== ''
        ? $senderUnit
        : trim((string) ($message['05_gegenstelle'] ?? ''));

    // The original intake row keeps the TBB number that is printed on the
    // message. Naming it makes the pair readable on paper; historic incidents
    // without that row still get their receipt.
    $originalStatement = $connection->prepare(
        'SELECT `estab_book_lfd` FROM `nv_tbb`'
        . ' WHERE `einsatz_id` = ? AND `estab_message_id` = ?'
        . " AND BINARY `estab_entry_type` = BINARY 'nachricht'"
        . ' ORDER BY `estab_book_lfd` LIMIT 1 FOR UPDATE'
    );
    if (!$originalStatement) {
        throw new RuntimeException(
            'Ursprünglicher TBB-Nachrichtennachweis konnte nicht gelesen werden.'
        );
    }
    try {
        $originalStatement->bind_param('ii', $incidentId, $messageId);
        $originalStatement->execute();
        $originalResult = $originalStatement->get_result();
        $original = $originalResult->fetch_assoc();
        $originalResult->free();
    } finally {
        $originalStatement->close();
    }
    $originalLocal = is_array($original)
        ? (int) ($original['estab_book_lfd'] ?? 0)
        : 0;
    $comment = 'Automatisch mit dem verbindlichen Nachrichtenworkflow erzeugt.';
    if ($originalLocal > 0) {
        $comment = 'Ergänzung zu TBB-Nr. ' . $originalLocal
            . ' nach abgeschlossener Sichtung. ' . $comment;
    }

    return estab_logbook_lifecycle_insert_ttb_record(
        $connection,
        $incidentId,
        $eventTime,
        'quittung',
        [
            'message_route' => 'von '
                . ($sender === '' ? 'nicht angegeben' : $sender)
                . ' an ' . $recipients,
            'operations' => 'Nachricht nach Sichtung ausgehändigt'
                . ($number > 0 ? ' (Meldungsnummer ' . $number . ')' : '')
                . '. Betreff: ' . trim((string) ($message['12_betreff'] ?? '')),
            'receipt' => 'Ausgehändigt an ' . $recipients
                . ($reviewer === ''
                    ? '.'
                    : '. Quittiert durch ' . $reviewer . '.'),
        ],
        $comment
    );
}

/** Create the first book rows under the incident's mode-specific shift rule. */
function estab_logbook_lifecycle_open_books(
    mysqli $connection,
    array $incident,
    ?int $shiftId = null
): void {
    $incidentId = (int) ($incident['active_einsatz_id'] ?? 0);
    if ($incidentId < 1) {
        throw new RuntimeException('Logbucheröffnung hat keinen aktiven Einsatz.');
    }
    if (
        estab_logbook_lifecycle_permission_mode($connection, $incidentId)
            === ESTAB_PERMISSION_MODE_STRICT
        && $shiftId === null
    ) {
        throw new RuntimeException(
            'Die Logbücher können im strengen Modus erst mit der ersten '
                . 'Dienstschicht eröffnet werden.'
        );
    }
    $missing = estab_logbook_lifecycle_missing_header($incident);
    if ($missing !== []) {
        throw new RuntimeException(
            'Pflichtangaben zur Logbucheröffnung fehlen: '
            . implode(', ', array_values($missing))
        );
    }
    estab_logbook_lifecycle_assert_empty_books($connection, $incidentId);
    $roster = $shiftId === null
        ? []
        : estab_logbook_lifecycle_roster(
            $connection,
            $shiftId,
            'ANGENOMMEN'
        );
    $rosterText = estab_logbook_lifecycle_roster_text($roster);
    $etbWriter = estab_logbook_lifecycle_writer_text($roster, 'etb');
    $ldf = estab_logbook_lifecycle_function_text($roster, ['LdF']);
    $operators = estab_logbook_lifecycle_function_text($roster, ['A/W']);
    $tbbWriter = estab_logbook_lifecycle_writer_text($roster, 'tbb');
    $begin = (string) $incident['beginn'];
    $operation = (string) $incident['kennung'] . ' - ' . (string) $incident['name'];

    estab_logbook_lifecycle_insert_etb(
        $connection,
        $incidentId,
        $begin,
        "Einsatztagebuch eröffnet.\n"
            . 'Einsatz: ' . $operation . ".\n"
            . 'Einsatzbeginn: ' . $begin . ".\n"
            . 'Einsatzauftrag und Ausgangslage: '
            . (string) $incident['beschreibung'] . ".\n"
            . 'Bedarfsträger: ' . (string) $incident['organisation'] . ".\n"
            . 'Verantwortliche Einsatz-/Führungsleitung: '
            . (string) $incident['einsatzleitung'] . ".\n"
            . 'Führungsstellenbesetzung: ' . $rosterText . ".\n"
            . 'ETB-Führung: ' . $etbWriter . '.',
        'Automatisch und atomar mit der Logbucheröffnung erzeugt.',
        'ohne',
        $shiftId
    );
    estab_logbook_lifecycle_insert_ttb(
        $connection,
        $incidentId,
        $begin,
        'Betriebsaufnahme der Fernmeldebetriebsstelle '
            . (string) $incident['fuehrungsstellenname'] . '. '
            . 'LdF: ' . $ldf . '. Betriebspersonal: ' . $operators
            . '. TBB-Führung: ' . $tbbWriter . '.',
        'Fernmeldebetriebsstelle für Einsatz ' . $operation
            . ' einsatz-/betriebsbereit.',
        'Automatisch und atomar mit der Logbucheröffnung erzeugt.',
        'betrieb_personal',
        $shiftId
    );
}

/**
 * Append a personally accepted extension of the active shift.
 *
 * Planned assignments are already collected in opening row 1 or in the
 * documented handover. Only a hat accepted after its shift became active is
 * written separately. Every extension belongs in the ETB; personnel of the
 * telecommunications operating point additionally belongs in the TBB.
 */
function estab_logbook_lifecycle_shift_extension(
    mysqli $connection,
    int $incidentId,
    int $shiftId,
    int $shiftNumber,
    string $shiftLabel,
    string $acceptedAt,
    string $personName,
    string $personCode,
    string $function,
    string $role
): void {
    $person = trim($personName);
    $code = trim($personCode);
    $function = trim($function);
    $role = trim($role);
    if (
        $incidentId < 1
        || $shiftId < 1
        || $shiftNumber < 1
        || $code === ''
        || $function === ''
        || $role === ''
    ) {
        throw new InvalidArgumentException(
            'Die Schichterweiterung ist unvollständig.'
        );
    }
    $personText = $person === '' ? $code : $person . ' [' . $code . ']';
    $shiftText = 'Schicht #' . $shiftNumber;
    $shiftLabel = trim($shiftLabel);
    if ($shiftLabel !== '') {
        $shiftText .= ' (' . $shiftLabel . ')';
    }
    $assignmentText = $function . ' (' . $role . '): ' . $personText;

    $etbEvent = 'Schichtbesetzung erweitert. ' . $shiftText . '. '
        . 'Neue Funktionsbesetzung: ' . $assignmentText . '.';
    $etbComment = 'Persönlich angenommen und atomar mit der '
        . 'Dienstbesetzung dokumentiert.';

    estab_logbook_lifecycle_insert_etb(
        $connection,
        $incidentId,
        $acceptedAt,
        $etbEvent,
        $etbComment,
        'ohne',
        $shiftId
    );

    if (!in_array($function, ['LdF', 'A/W', 'TBB'], true)) {
        return;
    }
    estab_logbook_lifecycle_insert_ttb(
        $connection,
        $incidentId,
        $acceptedAt,
        'Schichtbesetzung der Fernmeldebetriebsstelle erweitert. '
            . $assignmentText . '.',
        $shiftText . '; persönlich angenommen.',
        'Automatisch und atomar mit der Dienstbesetzung dokumentiert.',
        'betrieb_personal',
        $shiftId
    );
}

function estab_logbook_lifecycle_last_sequence(
    mysqli $connection,
    int $incidentId,
    string $table
): int {
    if (!in_array($table, ['nv_etb', 'nv_tbb'], true)) {
        throw new InvalidArgumentException('Invalid logbook table');
    }
    $statement = $connection->prepare(
        'SELECT COALESCE(MAX(`estab_book_lfd`), 0) AS `lfd` FROM `'
        . $table . '` WHERE `einsatz_id` = ?'
    );
    if (!$statement) {
        throw new RuntimeException('Letzte Logbuchnummer konnte nicht gelesen werden.');
    }
    try {
        $statement->bind_param('i', $incidentId);
        $statement->execute();
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        return (int) ($row['lfd'] ?? 0);
    } finally {
        $statement->close();
    }
}

/** Render one immutable MariaDB timestamp for the documentary handover row. */
function estab_logbook_lifecycle_display_timestamp(string $value): string
{
    if (
        preg_match(
            '/\A(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})'
                . '(?:\.\d{1,6})?\z/D',
            $value,
            $matches
        ) !== 1
        || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        || (int) $matches[4] > 23
        || (int) $matches[5] > 59
        || (int) $matches[6] > 59
    ) {
        throw new RuntimeException('Zeitpunkt der Dienstübergabe ist ungültig.');
    }
    return $matches[3] . '.' . $matches[2] . '.' . $matches[1]
        . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6] . ' Uhr';
}

/** Append the same accepted handover to both books inside one transaction. */
function estab_logbook_lifecycle_handover(
    mysqli $connection,
    int $incidentId,
    int $fromShiftId,
    int $toShiftId,
    string $summary,
    string $outgoingActor,
    string $incomingActor,
    string $handedOverAt,
    string $takenOverAt
): void {
    $eventTime = $takenOverAt;
    $lastEtb = estab_logbook_lifecycle_last_sequence(
        $connection,
        $incidentId,
        'nv_etb'
    );
    $lastTtb = estab_logbook_lifecycle_last_sequence(
        $connection,
        $incidentId,
        'nv_tbb'
    );
    // This function runs after the atomic status transition.  Only people who
    // actually held a function belong in the documentary handover record:
    // unaccepted assignments and withdrawn planning rows are not personnel.
    $outgoing = estab_logbook_lifecycle_roster(
        $connection,
        $fromShiftId,
        'ABGELOEST'
    );
    $incoming = estab_logbook_lifecycle_roster(
        $connection,
        $toShiftId,
        'ANGENOMMEN'
    );
    $outgoingText = estab_logbook_lifecycle_roster_text($outgoing);
    $incomingText = estab_logbook_lifecycle_roster_text($incoming);
    $outgoingSigner = estab_logbook_lifecycle_roster_text(array_values(
        array_filter(
            $outgoing,
            static fn (array $row): bool => hash_equals(
                strtolower((string) ($row['benutzer_kuerzel'] ?? '')),
                strtolower($outgoingActor)
            )
        )
    ));
    $incomingSigner = estab_logbook_lifecycle_roster_text(array_values(
        array_filter(
            $incoming,
            static fn (array $row): bool => hash_equals(
                strtolower((string) ($row['benutzer_kuerzel'] ?? '')),
                strtolower($incomingActor)
            )
        )
    ));
    $handedOverDisplay = estab_logbook_lifecycle_display_timestamp(
        $handedOverAt
    );
    $takenOverDisplay = estab_logbook_lifecycle_display_timestamp(
        $takenOverAt
    );
    $common = 'Dienstübergabe. Abgebende Besetzung: ' . $outgoingText
        . '. Übernehmende Besetzung: ' . $incomingText
        . '. Persönlich übergeben von ' . $outgoingSigner
        . ' um ' . $handedOverDisplay
        . '; persönlich übernommen von ' . $incomingSigner
        . ' um ' . $takenOverDisplay . '.';
    estab_logbook_lifecycle_insert_etb(
        $connection,
        $incidentId,
        $eventTime,
        $common . ' Letzte ETB-Nr. vor der Übergabe: ' . $lastEtb . '.',
        $summary,
        'ohne',
        $fromShiftId
    );
    estab_logbook_lifecycle_insert_ttb(
        $connection,
        $incidentId,
        $eventTime,
        $common . ' Letzte TBB-Nr. vor der Übergabe: ' . $lastTtb . '.',
        '',
        $summary,
        'betrieb_personal',
        $fromShiftId
    );
}

/** Append final book rows before the active incident is deactivated. */
function estab_logbook_lifecycle_close_books(
    mysqli $connection,
    array $incident,
    string $actualEnd,
    string $closeNote,
    string $actor
): void {
    $incidentId = (int) ($incident['einsatz_id'] ?? 0);
    if ($incidentId < 1) {
        throw new RuntimeException('Logbuchabschluss hat keinen Einsatz.');
    }
    $shiftStatement = $connection->prepare(
        'SELECT `dienstschicht_id` FROM `nv_dienstschichten`'
        . ' WHERE `einsatz_id` = ? AND `aktiviert_am` IS NOT NULL'
        . ' ORDER BY `nummer` DESC, `dienstschicht_id` DESC LIMIT 1'
    );
    if (!$shiftStatement) {
        throw new RuntimeException('Letzte Dienstschicht konnte nicht gelesen werden.');
    }
    try {
        $shiftStatement->bind_param('i', $incidentId);
        $shiftStatement->execute();
        $result = $shiftStatement->get_result();
        $shift = $result->fetch_assoc();
        $result->free();
    } finally {
        $shiftStatement->close();
    }
    $lastShiftId = is_array($shift)
        ? (int) ($shift['dienstschicht_id'] ?? 0)
        : 0;
    $lastShiftId = $lastShiftId > 0 ? $lastShiftId : null;
    $permissionMode = estab_logbook_lifecycle_permission_mode(
        $connection,
        $incidentId
    );
    if (
        $lastShiftId === null
        && $permissionMode === ESTAB_PERMISSION_MODE_STRICT
    ) {
        throw new RuntimeException(
            'Der Logbuchabschluss benötigt im strengen Modus eine '
                . 'dokumentierte Dienstschicht.'
        );
    }
    $roster = $lastShiftId !== null
        ? estab_logbook_lifecycle_roster(
            $connection,
            $lastShiftId,
            $permissionMode === ESTAB_PERMISSION_MODE_STRICT
                ? 'ABGELOEST'
                : null
        )
        : [];
    $etbWriter = estab_logbook_lifecycle_writer_text($roster, 'etb');
    $tbbWriter = estab_logbook_lifecycle_writer_text($roster, 'tbb');
    $ldf = estab_logbook_lifecycle_function_text($roster, ['LdF']);
    $leader = trim((string) ($incident['einsatzleitung'] ?? ''));
    estab_logbook_lifecycle_insert_etb(
        $connection,
        $incidentId,
        $actualEnd,
        'Einsatztagebuch geschlossen. Tatsächliches Einsatzende: '
            . $actualEnd . '. Verantwortliche Einsatz-/Führungsleitung: '
            . ($leader === '' ? 'nicht dokumentiert' : $leader)
            . '. Letzte ETB-Führung: ' . $etbWriter . '.',
        $closeNote . ' Administrativer Abschluss durch ' . $actor . '.',
        'ohne',
        $lastShiftId
    );
    estab_logbook_lifecycle_insert_ttb(
        $connection,
        $incidentId,
        $actualEnd,
        'Betriebsende der Fernmeldebetriebsstelle. Letzter LdF: ' . $ldf
            . '. Letzte TBB-Führung: ' . $tbbWriter . '.',
        'Technisches Betriebsbuch mit dem Einsatzende geschlossen.',
        $closeNote . ' Administrativer Abschluss durch ' . $actor . '.',
        'betrieb_personal',
        $lastShiftId
    );
}
