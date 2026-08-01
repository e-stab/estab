<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dv_operations.php';
require_once __DIR__ . '/incident.php';
require_once __DIR__ . '/logbook_numbering.php';

const ESTAB_LOGBOOK_TITLE_MAX_LENGTH = 255;
const ESTAB_LOGBOOK_TEXT_MAX_LENGTH = 10000;
const ESTAB_LOGBOOK_ASSIGNMENT_MAX_LENGTH = 255;
const ESTAB_LOGBOOK_BOOK_NUMBER_MAX = 4294967295;
const ESTAB_LOGBOOK_REFERENCE_DEPTH_MAX = 25;

/** @return array<string, string> */
function estab_logbook_entry_types(): array
{
    return [
        'ohne' => 'Ohne Kennzeichnung',
        'A' => 'A - Aufgabe',
        'B' => 'B - Befehl / Auftrag',
        'E' => 'E - Erledigung',
        'K' => 'K - Kräfteanforderung',
        'W' => 'W - sehr wichtig',
        'korrektur' => 'Korrektur',
    ];
}

/** @return array<string, string> */
function estab_logbook_ttb_entry_types(): array
{
    return [
        'betrieb_personal' => 'Betrieb, Personal oder Dienstübergabe',
        'kanal' => 'Kanal, Rufgruppe oder Bedienung',
        'nachricht' => 'Nachricht von / an',
        'betriebsereignis' => 'Betriebsablauf, Ereignis oder Störung',
        'quittung' => 'Quittung, Empfänger oder Aushändigung',
        'korrektur' => 'Korrektur',
    ];
}

/**
 * Return only TTB classifications that a person may enter directly.
 *
 * The canonical `nachricht` classification remains in the complete type map
 * so historic and automatically generated rows keep their official label. It
 * is reserved for the linked evidence produced by the message workflow and
 * therefore must never be accepted from a manual form or write API.
 *
 * @return array<string, string>
 */
function estab_logbook_ttb_manual_entry_types(): array
{
    $types = estab_logbook_ttb_entry_types();
    unset($types['nachricht']);
    return $types;
}

/** @return array<string, string> */
function estab_logbook_ttb_content_fields(): array
{
    return [
        'personnel_duty' => 'Betrieb / Personal / Dienst',
        'channel' => 'Kanal / Rufgruppe / Bedienung',
        'message_route' => 'Nachricht von / an',
        'operations' => 'Betriebsablauf / Ereignis / Störung',
        'receipt' => 'Quittung / Empfänger / Aushändigung',
    ];
}

/** Map old browser clients onto the classifications used by Fb Fü 2. */
function estab_logbook_normalize_etb_type(string $value): string
{
    return match ($value) {
        'ereignis', 'information', 'lagebesprechung', 'entscheidung' => 'ohne',
        'auftrag' => 'B',
        default => $value,
    };
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

/**
 * Parse one optional incident-local ETB book number.
 *
 * New references use the exact decimal representation printed in the ETB.
 * Historic free-text references remain stored and readable but cannot be
 * submitted again through the current write API.
 */
function estab_logbook_etb_reference_number(
    mixed $value,
    bool $optional = true
): ?int {
    if ($value === null || $value === '') {
        if ($optional) {
            return null;
        }
        throw new EstabIncidentInputException(
            'Eine ETB-Startnummer ist erforderlich.'
        );
    }
    if (is_int($value)) {
        $candidate = (string) $value;
    } elseif (is_string($value)) {
        $candidate = trim($value);
    } else {
        $candidate = '';
    }
    if (
        preg_match('/\A[1-9][0-9]{0,9}\z/D', $candidate) !== 1
        || (int) $candidate > ESTAB_LOGBOOK_BOOK_NUMBER_MAX
        || (string) ((int) $candidate) !== $candidate
    ) {
        throw new EstabIncidentInputException(
            'Die Referenz auf ETB-Nr. muss eine positive lokale ETB-Nummer sein.'
        );
    }
    return (int) $candidate;
}

/** Return a canonical stored reference, ignoring historic free text. */
function estab_logbook_stored_etb_reference_number(mixed $value): ?int
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $candidate = (string) $value;
    try {
        $number = estab_logbook_etb_reference_number($candidate, false);
    } catch (EstabIncidentInputException) {
        return null;
    }
    return (string) $number === $candidate ? $number : null;
}

/** Validate the bounded traversal depth used by the read-only evaluation. */
function estab_logbook_reference_depth(mixed $value): int
{
    $candidate = $value === null || $value === '' ? '5' : $value;
    if (
        (!is_int($candidate) && !is_string($candidate))
        || preg_match('/\A[1-9][0-9]*\z/D', (string) $candidate) !== 1
    ) {
        throw new EstabIncidentInputException(
            'Die Referenztiefe ist ungültig.'
        );
    }
    $depth = (int) $candidate;
    if ($depth < 1 || $depth > ESTAB_LOGBOOK_REFERENCE_DEPTH_MAX) {
        throw new EstabIncidentInputException(
            'Die Referenztiefe muss zwischen 1 und '
                . ESTAB_LOGBOOK_REFERENCE_DEPTH_MAX . ' liegen.'
        );
    }
    return $depth;
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
 * Return completed incident attachments that do not already belong to an ETB
 * entry. The final number is allocated together with the immutable entry.
 *
 * @return list<array<string,mixed>>
 */
function estab_logbook_available_etb_attachments(
    array $databaseConfig,
    string $attachmentTable,
    string $etbTable
): array {
    $connection = estab_auth_connect($databaseConfig);
    try {
        $incident = estab_incident_active($connection);
        if ($incident === null) {
            return [];
        }
        $statement = $connection->prepare(
            'SELECT attachment_row.`lfd-nr`, attachment_row.`filename`,'
                . ' attachment_row.`fileext`, attachment_row.`org_filename`,'
                . ' attachment_row.`comment`, attachment_row.`date`'
                . ' FROM ' . estab_auth_table($attachmentTable)
                . ' AS attachment_row'
                . ' WHERE attachment_row.`einsatz_id` = ?'
                . ' AND attachment_row.`status` = 1'
                . ' AND NOT EXISTS ('
                . ' SELECT 1 FROM ' . estab_auth_table($etbTable)
                . ' AS etb_row'
                . ' WHERE etb_row.`estab_attachment_id` ='
                . ' attachment_row.`lfd-nr`'
                . ' )'
                . ' ORDER BY attachment_row.`date`, attachment_row.`lfd-nr`'
        );
        if (!$statement) {
            throw new RuntimeException(
                'Could not prepare available ETB attachment lookup'
            );
        }
        try {
            $incidentId = (int) $incident['active_einsatz_id'];
            $statement->bind_param('i', $incidentId);
            if (!$statement->execute()) {
                throw new RuntimeException(
                    'Could not execute available ETB attachment lookup'
                );
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

/** Build the immutable, human-readable ETB assignment snapshot. */
function estab_logbook_assignment_snapshot(array $assignment): string
{
    $function = trim((string) ($assignment['funktion'] ?? ''));
    $role = trim((string) ($assignment['rolle'] ?? ''));
    $user = trim((string) ($assignment['benutzer'] ?? ''));
    $code = trim((string) ($assignment['benutzer_kuerzel'] ?? ''));
    $snapshot = $function . ' (' . $role . '): ' . $user . ' [' . $code . ']';
    $length = estab_auth_text_length($snapshot);
    if (
        $function === ''
        || $role === ''
        || $user === ''
        || $code === ''
        || $length < 1
        || $length > ESTAB_LOGBOOK_ASSIGNMENT_MAX_LENGTH
        || preg_match('/\p{C}/u', $snapshot) === 1
    ) {
        throw new RuntimeException(
            'Dienstbesetzung kann nicht lesbar dargestellt werden.'
        );
    }
    return $snapshot;
}

/**
 * Return historical duty assignments for the optional ETB workflow metadata.
 *
 * Duty shifts no longer grant ETB rights and therefore do not have to be
 * active.  The browser receives display values only; the chosen primary key
 * is revalidated and locked in the write transaction.
 *
 * @return list<array<string,mixed>>
 */
function estab_logbook_active_assignment_options(
    array $databaseConfig
): array {
    $connection = estab_auth_connect($databaseConfig);
    try {
        $incident = estab_incident_active($connection);
        if ($incident === null) {
            return [];
        }
        $statement = $connection->prepare(
            'SELECT assignment.`dienstbesetzung_id`,'
                . ' assignment.`dienstschicht_id`, assignment.`funktion`,'
                . ' assignment.`rolle`, assignment.`benutzer_kuerzel`,'
                . ' account.`benutzer`, duty_shift.`nummer` AS `schicht_nummer`,'
                . ' duty_shift.`bezeichnung` AS `schicht_bezeichnung`'
                . ' FROM `nv_dienstbesetzungen` AS assignment'
                . ' JOIN `nv_dienstschichten` AS duty_shift'
                . ' ON duty_shift.`dienstschicht_id` ='
                . ' assignment.`dienstschicht_id`'
                . ' JOIN `nv_benutzer` AS account'
                . ' ON BINARY account.`kuerzel` ='
                . ' BINARY assignment.`benutzer_kuerzel`'
                . ' WHERE duty_shift.`einsatz_id` = ?'
                . " AND assignment.`status` <> 'ZURUECKGEZOGEN'"
                . ' ORDER BY duty_shift.`nummer` DESC,'
                . ' assignment.`funktion`, account.`benutzer`,'
                . ' assignment.`dienstbesetzung_id`'
        );
        if (!$statement) {
            throw new RuntimeException(
                'Zuordnungsoptionen konnten nicht vorbereitet werden.'
            );
        }
        try {
            $incidentId = (int) $incident['active_einsatz_id'];
            $statement->bind_param('i', $incidentId);
            if (!$statement->execute()) {
                throw new RuntimeException(
                    'Zuordnungsoptionen konnten nicht gelesen werden.'
                );
            }
            $result = $statement->get_result();
            $options = [];
            while (($row = $result->fetch_assoc()) !== null) {
                $row['estab_assignment'] =
                    estab_logbook_assignment_snapshot($row);
                $options[] = $row;
            }
            $result->free();
            return $options;
        } finally {
            $statement->close();
        }
    } finally {
        estab_auth_close($connection);
    }
}

/**
 * Validate the fixed account function used for a manual logbook entry.
 *
 * The caller has already proved the active incident and the required
 * capability.  New entries deliberately carry no legacy duty-shift
 * provenance because duty shifts are optional and never grant write rights.
 *
 * @return array{shift_id:?int,writer_assignment_id:?int}
 */
function estab_logbook_manual_writer_context(
    mysqli $connection,
    int $incidentId,
    array $identity,
    string $kind
): array {
    if (!in_array($kind, ['etb', 'tbb'], true)) {
        throw new InvalidArgumentException('Invalid logbook kind');
    }
    estab_incident_positive_id($incidentId);
    if (!estab_logbook_is_designated_writer(
        $connection,
        $incidentId,
        $identity,
        $kind
    )) {
        throw new EstabDvPermissionException(
            $kind === 'etb'
                ? 'ETB-Einträge dürfen nur Konten mit der festen Funktion '
                    . 'ETB oder S2 und der Rolle Stab speichern.'
                : 'TBB-Einträge dürfen nur Fernmelder-Konten speichern.'
        );
    }
    return [
        'shift_id' => null,
        'writer_assignment_id' => null,
    ];
}

/**
 * Lock and validate one optional legacy ETB target assignment.
 *
 * The assignment only describes who an entry concerns.  It may come from any
 * non-withdrawn historical duty shift of the incident and never affects the
 * writer's authorization.
 *
 * @return array{assignment_id:int,snapshot:string}|null
 */
function estab_logbook_etb_assignee_context(
    mysqli $connection,
    int $incidentId,
    ?int $shiftId,
    ?int $assignmentId
): ?array {
    if ($assignmentId === null) {
        return null;
    }
    $assignmentId = estab_incident_positive_id(
        $assignmentId,
        'Zuordnung'
    );
    $statement = $connection->prepare(
        'SELECT assignment.`dienstbesetzung_id`,'
            . ' assignment.`funktion`, assignment.`rolle`,'
            . ' assignment.`benutzer_kuerzel`, account.`benutzer`'
            . ' FROM `nv_dienstbesetzungen` AS assignment'
            . ' JOIN `nv_dienstschichten` AS duty_shift'
            . ' ON duty_shift.`dienstschicht_id` ='
            . ' assignment.`dienstschicht_id`'
            . ' JOIN `nv_benutzer` AS account'
            . ' ON BINARY account.`kuerzel` ='
            . ' BINARY assignment.`benutzer_kuerzel`'
            . ' WHERE assignment.`dienstbesetzung_id` = ?'
            . ' AND duty_shift.`einsatz_id` = ?'
            . " AND assignment.`status` <> 'ZURUECKGEZOGEN'"
            . ' LIMIT 1 FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException(
            'ETB-Zuordnung konnte nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('ii', $assignmentId, $incidentId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (!is_array($row)) {
        throw new EstabIncidentConflictException(
            'Die ausgewählte Zuordnung gehört nicht mehr als verwendbare '
                . 'Dienstbesetzung zu diesem Einsatz.'
        );
    }
    $lockedAssignmentId = (int) (
        $row['dienstbesetzung_id'] ?? 0
    );
    if ($lockedAssignmentId !== $assignmentId) {
        throw new EstabIncidentConflictException(
            'Die ausgewählte Zuordnung wurde zwischenzeitlich geändert.'
        );
    }
    return [
        'assignment_id' => $lockedAssignmentId,
        'snapshot' => estab_logbook_assignment_snapshot($row),
    ];
}

/** Check the fixed account function/role that grants logbook authorship. */
function estab_logbook_is_designated_writer(
    mysqli $connection,
    int $incidentId,
    array $identity,
    string $kind
): bool {
    if (!in_array($kind, ['etb', 'tbb'], true)) {
        return false;
    }
    try {
        estab_incident_positive_id($incidentId);
    } catch (EstabIncidentInputException) {
        return false;
    }
    $function = trim((string) ($identity['funktion'] ?? ''));
    $role = trim((string) ($identity['rolle'] ?? ''));
    return $kind === 'etb'
        ? $role === 'Stab' && in_array($function, ['ETB', 'S2'], true)
        : $role === 'Fernmelder' && $function === 'A/W';
}

/**
 * Read only logbook rows belonging to the global active incident.
 *
 * @return list<array<string, mixed>>
 */
function estab_logbook_entries(
    array $databaseConfig,
    string $table,
    string $kind,
    array $filters = []
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
        // The official sequence is allocated on insertion and can never be
        // reordered by a subsequently corrected/backdated event timestamp.
        $orderSql = '`estab_book_lfd` DESC';
        $where = ['entry_row.`einsatz_id` = ?'];
        $parameters = [(int) $incident['active_einsatz_id']];
        if ($kind === 'etb') {
            $query = isset($filters['query']) && is_string($filters['query'])
                ? trim($filters['query'])
                : '';
            $type = isset($filters['type']) && is_string($filters['type'])
                ? trim($filters['type'])
                : '';
            $reference = isset($filters['reference'])
                && is_string($filters['reference'])
                ? trim($filters['reference'])
                : '';
            $assignment = isset($filters['assignment'])
                && is_string($filters['assignment'])
                ? trim($filters['assignment'])
                : '';
            foreach (
                [
                    'Suchbegriff' => [$query, 200],
                    'Bezugsfilter' => [$reference, 100],
                    'Zuordnungsfilter' => [$assignment, 200],
                ] as $label => [$value, $maximum]
            ) {
                $length = estab_auth_text_length($value);
                if (
                    $length < 0
                    || $length > $maximum
                    || preg_match('/\p{C}/u', $value) === 1
                ) {
                    throw new EstabIncidentInputException(
                        $label . ' ist ungültig.'
                    );
                }
            }
            $allowedSearchTypes = array_keys(estab_logbook_entry_types());
            $allowedSearchTypes[] = 'legacy_import';
            if ($type !== '' && !in_array($type, $allowedSearchTypes, true)) {
                throw new EstabIncidentInputException(
                    'Der ETB-Artfilter ist ungültig.'
                );
            }
            if ($query !== '') {
                $pattern = '%' . $query . '%';
                $where[] = '('
                    . 'entry_row.`etb_aktion` LIKE ?'
                    . ' OR entry_row.`etb_bemerk` LIKE ?'
                    . ' OR entry_row.`estab_reference` LIKE ?'
                    . ' OR entry_row.`estab_assignment` LIKE ?'
                    . ' OR entry_row.`etb_benutzer` LIKE ?'
                    . ' OR entry_row.`etb_kuerzel` LIKE ?'
                    . ' OR attachment_row.`filename` LIKE ?'
                    . ' OR attachment_row.`org_filename` LIKE ?'
                    . ')';
                array_push(
                    $parameters,
                    $pattern,
                    $pattern,
                    $pattern,
                    $pattern,
                    $pattern,
                    $pattern,
                    $pattern,
                    $pattern
                );
            }
            if ($type !== '') {
                $legacyTypes = match ($type) {
                    'ohne' => [
                        'ohne',
                        'ereignis',
                        'entscheidung',
                        'lagebesprechung',
                        'information',
                    ],
                    'B' => ['B', 'auftrag'],
                    default => [$type],
                };
                $where[] = 'entry_row.`estab_event_type` IN ('
                    . implode(', ', array_fill(0, count($legacyTypes), '?'))
                    . ')';
                array_push($parameters, ...$legacyTypes);
            }
            if ($assignment !== '') {
                $where[] = 'entry_row.`estab_assignment` LIKE ?';
                $parameters[] = '%' . $assignment . '%';
            }
            if ($reference !== '') {
                $attachmentNumber =
                    estab_logbook_parse_etb_attachment_number($reference);
                if ($attachmentNumber !== null) {
                    if (
                        $attachmentNumber['incident_id']
                            !== (int) $incident['active_einsatz_id']
                        || $attachmentNumber['unit_number'] !== 1
                    ) {
                        $where[] = '1 = 0';
                    } else {
                        $where[] = 'entry_row.`estab_book_lfd` = ?'
                            . ' AND entry_row.`estab_attachment_id` IS NOT NULL';
                        $parameters[] = $attachmentNumber['entry_number'];
                    }
                } elseif (
                    preg_match('/\A[1-9][0-9]{0,9}\z/D', $reference) === 1
                ) {
                    $referenceNumber = (int) $reference;
                    $where[] = '('
                        . 'entry_row.`estab_book_lfd` = ?'
                        . ' OR entry_row.`estab_message_id` = ?'
                        . ' OR entry_row.`estab_attachment_id` = ?'
                        . ' OR BINARY entry_row.`estab_reference` = BINARY ?'
                        . ' OR entry_row.`estab_correction_of` = ('
                        . '   SELECT original.`etb_lfd-nr` FROM '
                        . estab_auth_table($table) . ' AS original'
                        . '   WHERE original.`einsatz_id` = entry_row.`einsatz_id`'
                        . '     AND original.`estab_book_lfd` = ? LIMIT 1'
                        . ' )'
                        . ')';
                    array_push(
                        $parameters,
                        $referenceNumber,
                        $referenceNumber,
                        $referenceNumber,
                        (string) $referenceNumber,
                        $referenceNumber
                    );
                } else {
                    $where[] = 'entry_row.`estab_reference` LIKE ?';
                    $parameters[] = '%' . $reference . '%';
                }
            }
        }
        $select = 'SELECT entry_row.*';
        $join = '';
        if ($kind === 'etb') {
            $select .= ', attachment_row.`filename`'
                . ' AS `estab_attachment_filename`,'
                . ' attachment_row.`fileext`'
                . ' AS `estab_attachment_extension`,'
                . ' attachment_row.`org_filename`'
                . ' AS `estab_attachment_original_name`,'
                . ' correction_row.`estab_book_lfd`'
                . ' AS `estab_correction_book_lfd`';
            $join = ' LEFT JOIN `nv_anhang` AS attachment_row'
                . ' ON attachment_row.`lfd-nr` ='
                . ' entry_row.`estab_attachment_id`'
                . ' LEFT JOIN ' . estab_auth_table($table)
                . ' AS correction_row'
                . ' ON correction_row.`etb_lfd-nr` ='
                . ' entry_row.`estab_correction_of`'
                . ' AND correction_row.`einsatz_id` = entry_row.`einsatz_id`';
        }
        $statement = $connection->prepare(
            $select . ' FROM ' . estab_auth_table($table)
            . ' AS entry_row' . $join
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY entry_row.' . $orderSql
        );
        if (!$statement) {
            throw new RuntimeException('Could not prepare logbook listing');
        }
        try {
            if (!$statement->execute($parameters)) {
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
 * Traverse canonical ETB references without modifying evidence.
 *
 * Forward traversal is breadth-first and preserves every branch. Backward
 * traversal follows the single reference stored by each entry. Historic
 * free-text values remain in the returned rows but are not treated as graph
 * edges because their meaning cannot be inferred safely.
 *
 * @param list<array<string,mixed>> $rows
 * @return array{
 *   rows:list<array<string,mixed>>,
 *   start_number:int,
 *   direction:string,
 *   max_depth:int,
 *   truncated:bool
 * }
 */
function estab_logbook_etb_reference_graph(
    array $rows,
    int $startNumber,
    string $direction,
    int $maxDepth
): array {
    $startNumber = estab_logbook_etb_reference_number(
        $startNumber,
        false
    );
    $maxDepth = estab_logbook_reference_depth($maxDepth);
    if (!in_array($direction, ['forward', 'backward'], true)) {
        throw new EstabIncidentInputException(
            'Die Referenzrichtung ist ungültig.'
        );
    }

    $byNumber = [];
    $forward = [];
    foreach ($rows as $row) {
        $number = (int) ($row['estab_book_lfd'] ?? 0);
        if ($number < 1 || $number > ESTAB_LOGBOOK_BOOK_NUMBER_MAX) {
            continue;
        }
        if (isset($byNumber[$number])) {
            throw new RuntimeException(
                'Die lokale ETB-Nummer ist nicht eindeutig.'
            );
        }
        $byNumber[$number] = $row;
        $target = estab_logbook_stored_etb_reference_number(
            $row['estab_reference'] ?? null
        );
        if ($target !== null) {
            $forward[$target][] = $number;
        }
    }
    if (!isset($byNumber[$startNumber])) {
        throw new EstabIncidentConflictException(
            'Die ETB-Startnummer gehört nicht zum aktiven Einsatz.'
        );
    }
    foreach ($forward as &$children) {
        sort($children, SORT_NUMERIC);
    }
    unset($children);

    $queue = [[$startNumber, 0, null]];
    $queueIndex = 0;
    $visited = [];
    $result = [];
    $truncated = false;
    while (isset($queue[$queueIndex])) {
        [$number, $depth, $via] = $queue[$queueIndex];
        $queueIndex++;
        if (isset($visited[$number])) {
            continue;
        }
        $visited[$number] = true;
        $row = $byNumber[$number];
        $row['estab_reference_depth'] = $depth;
        $row['estab_reference_via'] = $via;
        $result[] = $row;

        $neighbours = [];
        if ($direction === 'forward') {
            $neighbours = $forward[$number] ?? [];
        } else {
            $target = estab_logbook_stored_etb_reference_number(
                $row['estab_reference'] ?? null
            );
            if ($target !== null && isset($byNumber[$target])) {
                $neighbours[] = $target;
            }
        }
        $unvisited = array_values(array_filter(
            $neighbours,
            static fn (int $candidate): bool => !isset($visited[$candidate])
        ));
        if ($depth >= $maxDepth) {
            $truncated = $truncated || $unvisited !== [];
            continue;
        }
        foreach ($unvisited as $neighbour) {
            $queue[] = [$neighbour, $depth + 1, $number];
        }
    }

    return [
        'rows' => $result,
        'start_number' => $startNumber,
        'direction' => $direction,
        'max_depth' => $maxDepth,
        'truncated' => $truncated,
    ];
}

/** Evaluate references only inside the global active incident. */
function estab_logbook_etb_reference_evaluation(
    array $databaseConfig,
    string $table,
    mixed $startNumber,
    mixed $direction,
    mixed $maxDepth
): array {
    $start = estab_logbook_etb_reference_number($startNumber, false);
    $selectedDirection = is_string($direction) ? $direction : '';
    if (!in_array($selectedDirection, ['forward', 'backward'], true)) {
        throw new EstabIncidentInputException(
            'Die Referenzrichtung ist ungültig.'
        );
    }
    $depth = estab_logbook_reference_depth($maxDepth);
    return estab_logbook_etb_reference_graph(
        estab_logbook_entries($databaseConfig, $table, 'etb'),
        $start,
        $selectedDirection,
        $depth
    );
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
function estab_logbook_validate_entry(array $input, string $kind = 'etb'): array
{
    if (!in_array($kind, ['etb', 'tbb'], true)) {
        throw new InvalidArgumentException('Invalid logbook kind');
    }
    $event = isset($input['event']) && is_string($input['event'])
        ? trim($input['event'])
        : '';
    $comment = isset($input['comment']) && is_string($input['comment'])
        ? trim($input['comment'])
        : '';
    $errors = [];
    $eventTypeField = $kind === 'etb' ? 'event_type' : 'entry_type';
    $eventType = isset($input[$eventTypeField])
        && is_string($input[$eventTypeField])
        ? trim($input[$eventTypeField])
        : ($kind === 'etb' ? 'ohne' : 'betriebsereignis');
    if ($kind === 'etb') {
        $eventType = estab_logbook_normalize_etb_type($eventType);
    }
    $allowedTypes = $kind === 'etb'
        ? estab_logbook_entry_types()
        : estab_logbook_ttb_manual_entry_types();
    if (!array_key_exists($eventType, $allowedTypes)) {
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

    $assigneeAssignmentId = null;
    $assigneeCandidate = $input['assignee_assignment_id'] ?? null;
    if ($assigneeCandidate !== null && $assigneeCandidate !== '') {
        if ($kind !== 'etb') {
            $errors[] = 'assignee_assignment_id';
        } else {
            try {
                $assigneeAssignmentId = estab_incident_positive_id(
                    $assigneeCandidate,
                    'Zuordnung'
                );
            } catch (EstabIncidentInputException) {
                $errors[] = 'assignee_assignment_id';
            }
        }
    }

    // Internal message-to-TBB links are generated exactly once by the
    // transactional receipt/transmission workflow. A manual link could claim
    // a transport that has not happened yet and steal the printed TBB number.
    if ($kind === 'tbb' && $messageId !== null) {
        $errors[] = 'message_id';
    }

    if (
        ($eventType === 'korrektur' && $correctionOf === null)
        || ($eventType !== 'korrektur' && $correctionOf !== null)
    ) {
        $errors[] = 'correction_of';
    }

    $reference = null;
    $referenceCandidate = $input['reference'] ?? null;
    if ($referenceCandidate !== null && $referenceCandidate !== '') {
        if ($kind !== 'etb') {
            $errors[] = 'reference';
        } else {
            try {
                $reference = (string) estab_logbook_etb_reference_number(
                    $referenceCandidate,
                    false
                );
            } catch (EstabIncidentInputException) {
                $errors[] = 'reference';
            }
        }
    }

    $ttbContent = [];
    if ($kind === 'tbb') {
        foreach (estab_logbook_ttb_content_fields() as $field => $label) {
            unset($label);
            $value = isset($input[$field]) && is_string($input[$field])
                ? trim($input[$field])
                : '';
            $length = estab_auth_text_length($value);
            if (
                $length > ESTAB_LOGBOOK_TEXT_MAX_LENGTH
                || strlen($value) > 65535
                || str_contains($value, "\0")
            ) {
                $errors[] = $field;
            }
            $ttbContent[$field] = $value;
        }
        // Compatibility for older clients: place their one generic event in
        // the selected official content area instead of discarding it.
        if (!in_array(true, array_map(
            static fn (string $value): bool => $value !== '',
            $ttbContent
        ), true) && $event !== '') {
            $legacyTarget = match ($eventType) {
                'betrieb_personal' => 'personnel_duty',
                'kanal' => 'channel',
                'quittung' => 'receipt',
                default => 'operations',
            };
            $ttbContent[$legacyTarget] = $event;
        }
        if (!in_array(true, array_map(
            static fn (string $value): bool => $value !== '',
            $ttbContent
        ), true)) {
            $errors[] = 'ttb_content';
        }
        $summaryParts = [];
        foreach (estab_logbook_ttb_content_fields() as $field => $label) {
            if (($ttbContent[$field] ?? '') !== '') {
                $summaryParts[] = $label . ': ' . $ttbContent[$field];
            }
        }
        $event = implode("\n", $summaryParts);
    }

    $eventLength = estab_auth_text_length($event);
    if (
        $eventLength < 1
        || ($kind === 'etb' && $eventLength > ESTAB_LOGBOOK_TEXT_MAX_LENGTH)
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
    if ($kind === 'tbb' && $eventType === 'korrektur' && $comment === '') {
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
            'reference' => $reference,
            'correction_of' => $correctionOf,
            'assignee_assignment_id' => $assigneeAssignmentId,
            'personnel_duty' => $ttbContent['personnel_duty'] ?? null,
            'channel' => $ttbContent['channel'] ?? null,
            'message_route' => $ttbContent['message_route'] ?? null,
            'operations' => $ttbContent['operations'] ?? null,
            'receipt' => $ttbContent['receipt'] ?? null,
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

/** Lock one target addressed by its incident-local ETB book number. */
function estab_logbook_etb_reference_target(
    mysqli $connection,
    int $incidentId,
    string $table,
    int $bookNumber
): array {
    $statement = $connection->prepare(
        'SELECT `etb_lfd-nr`, `einsatz_id`, `estab_book_lfd`'
            . ' FROM ' . estab_auth_table($table)
            . ' WHERE `einsatz_id` = ? AND `estab_book_lfd` = ?'
            . ' LIMIT 1 FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException(
            'ETB-Referenz konnte nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('ii', $incidentId, $bookNumber);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'ETB-Referenz konnte nicht geprüft werden.'
            );
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
    } finally {
        $statement->close();
    }
    if (!is_array($row)) {
        throw new EstabIncidentConflictException(
            'Die Referenz auf ETB-Nr. ' . $bookNumber
                . ' bezeichnet keinen früheren Eintrag des aktiven Einsatzes.'
        );
    }
    return $row;
}

/**
 * Prove that every optional ETB reference belongs to the active incident.
 *
 * This runs inside estab_incident_with_active_write() and locks every target
 * until the ETB insert commits. The database trigger repeats the invariant as
 * a defence-in-depth boundary for writes that bypass this application API.
 *
 * Corrections always point directly to an immutable original entry. Their
 * public reference is derived from that original's local book number; the
 * browser cannot substitute a global primary key or another local target.
 *
 * @return array<string,mixed> Canonicalized entry ready for insertion.
 */
function estab_logbook_validate_references(
    mysqli $connection,
    int $incidentId,
    string $table,
    array $entry,
    string $kind = 'etb'
): array {
    if (!in_array($kind, ['etb', 'tbb'], true)) {
        throw new InvalidArgumentException('Invalid logbook kind');
    }
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
    if ($kind === 'etb' && $attachmentId !== null) {
        $attachmentId = estab_incident_positive_id(
            $attachmentId,
            'Anhangs-ID'
        );
        $attachment = estab_logbook_reference_row(
            $connection,
            'SELECT `einsatz_id`, `status` FROM `nv_anhang`'
                . ' WHERE `lfd-nr` = ? LIMIT 1 FOR UPDATE',
            $attachmentId
        );
        if (
            !is_array($attachment)
            || (int) ($attachment['einsatz_id'] ?? 0) !== $incidentId
            || (int) ($attachment['status'] ?? 0) !== 1
        ) {
            throw new EstabIncidentConflictException(
                'Der referenzierte Anhang ist nicht abgeschlossen oder gehört '
                    . 'nicht zum aktiven Einsatz.'
            );
        }
        $existingAttachmentLink = estab_logbook_reference_row(
            $connection,
            'SELECT `etb_lfd-nr` FROM ' . estab_auth_table($table)
                . ' WHERE `estab_attachment_id` = ? LIMIT 1 FOR UPDATE',
            $attachmentId
        );
        if (is_array($existingAttachmentLink)) {
            throw new EstabIncidentConflictException(
                'Der Anhang besitzt bereits eine ETB-Anlagennummer. '
                    . 'Verweisen Sie bei Bedarf über die ETB-Nr. auf '
                    . 'den bestehenden ETB-Eintrag.'
            );
        }
    }

    $correctionOf = $entry['correction_of'] ?? null;
    if ($correctionOf === null) {
        if ($kind === 'etb' && ($entry['reference'] ?? null) !== null) {
            $bookNumber = estab_logbook_etb_reference_number(
                $entry['reference'],
                false
            );
            estab_logbook_etb_reference_target(
                $connection,
                $incidentId,
                $table,
                $bookNumber
            );
            $entry['reference'] = (string) $bookNumber;
        }
        return $entry;
    }
    $correctionOf = estab_incident_positive_id(
        $correctionOf,
        'Korrekturbezug'
    );
    $idColumn = $kind === 'etb' ? 'etb_lfd-nr' : 'tbb_lfd-nr';
    $typeColumn = $kind === 'etb' ? 'estab_event_type' : 'estab_entry_type';
    $original = estab_logbook_reference_row(
        $connection,
        'SELECT `einsatz_id`, `' . $typeColumn . '` AS `entry_type`,'
            . ' `estab_correction_of`, `estab_book_lfd`'
            . ' FROM ' . estab_auth_table($table)
            . ' WHERE `' . $idColumn . '` = ? LIMIT 1 FOR UPDATE',
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
        ($original['entry_type'] ?? null) === 'korrektur'
        || ($original['estab_correction_of'] ?? null) !== null
    ) {
        throw new EstabIncidentConflictException(
            'Eine Korrektur muss direkt auf den ursprünglichen Logbucheintrag '
            . 'verweisen.'
        );
    }
    if ($kind === 'etb') {
        $originalBookNumber = (int) ($original['estab_book_lfd'] ?? 0);
        if ($originalBookNumber < 1) {
            throw new EstabIncidentConflictException(
                'Der ursprüngliche ETB-Eintrag besitzt keine lokale Nummer.'
            );
        }
        $entry['reference'] = (string) $originalBookNumber;
    }
    return $entry;
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
    $validation = estab_logbook_validate_entry($entry, $kind);
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
                $writerContext = estab_logbook_manual_writer_context(
                    $connection,
                    $incidentId,
                    $identity,
                    $kind
                );
                $shiftId = $writerContext['shift_id'];
                $writerAssignmentId =
                    $writerContext['writer_assignment_id'];
                $assignee = $kind === 'etb'
                    ? estab_logbook_etb_assignee_context(
                        $connection,
                        $incidentId,
                        $shiftId,
                        $entry['assignee_assignment_id'] ?? null
                    )
                    : null;
                $entry = estab_logbook_validate_references(
                    $connection,
                    $incidentId,
                    $table,
                    $entry,
                    $kind
                );
                $sql = 'INSERT INTO ' . estab_auth_table($table)
                    . ' (`einsatz_id`, `' . $prefix . 'time`,'
                    . ' `' . $prefix . 'aktion`, `' . $prefix . 'bemerk`,'
                    . ' `' . $prefix . 'funktion`, `' . $prefix . 'kuerzel`,'
                    . ' `' . $prefix . 'benutzer`'
                    . ($kind === 'etb'
                        ? ', `estab_event_time`, `estab_event_type`,'
                            . ' `estab_message_id`, `estab_attachment_id`,'
                            . ' `estab_reference`, `estab_correction_of`,'
                            . ' `estab_shift_id`,'
                            . ' `estab_writer_assignment_id`,'
                            . ' `estab_assignee_assignment_id`,'
                            . ' `estab_assignment`'
                        : ', `estab_event_time`, `estab_entry_type`,'
                            . ' `estab_message_id`,'
                            . ' `estab_personnel_duty`, `estab_channel`,'
                            . ' `estab_message_route`, `estab_operations`,'
                            . ' `estab_receipt`, `estab_correction_of`,'
                            . ' `estab_shift_id`,'
                            . ' `estab_writer_assignment_id`')
                    . ')'
                    . ($kind === 'etb'
                        ? ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        : ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
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
                        $assigneeAssignmentId =
                            $assignee['assignment_id'] ?? null;
                        $assignmentSnapshot = $assignee['snapshot'] ?? null;
                        $types = 'i' . str_repeat('s', 8) . 'iisiiiis';
                        $statement->bind_param(
                            $types,
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
                            $correctionOf,
                            $shiftId,
                            $writerAssignmentId,
                            $assigneeAssignmentId,
                            $assignmentSnapshot
                        );
                    } else {
                        $eventTime = (string) ($entry['event_time'] ?? '');
                        $entryType = (string) ($entry['event_type'] ?? '');
                        $messageId = $entry['message_id'] ?? null;
                        $personnelDuty = (string) ($entry['personnel_duty'] ?? '');
                        $channel = (string) ($entry['channel'] ?? '');
                        $messageRoute = (string) ($entry['message_route'] ?? '');
                        $operations = (string) ($entry['operations'] ?? '');
                        $receipt = (string) ($entry['receipt'] ?? '');
                        $correctionOf = $entry['correction_of'] ?? null;
                        $types = 'i' . str_repeat('s', 8) . 'i'
                            . str_repeat('s', 5) . 'iii';
                        $statement->bind_param(
                            $types,
                            $incidentId,
                            $eventTime,
                            $event,
                            $comment,
                            $function,
                            $code,
                            $user,
                            $eventTime,
                            $entryType,
                            $messageId,
                            $personnelDuty,
                            $channel,
                            $messageRoute,
                            $operations,
                            $receipt,
                            $correctionOf,
                            $shiftId,
                            $writerAssignmentId
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
