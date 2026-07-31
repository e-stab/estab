<?php

declare(strict_types=1);

/**
 * DV 1-101 organisational domain.
 *
 * This boundary keeps command-post duty assignments, S6 telecommunications
 * plans and messenger jobs incident scoped and auditable. Callers never pass a
 * role decision directly: every function/role pair is resolved from the
 * authoritative recipient matrix and every operational write locks the global
 * active incident for the complete transaction.
 */

require_once __DIR__ . '/assignment.php';
require_once __DIR__ . '/dynamic_schema.php';
require_once __DIR__ . '/incident.php';
require_once __DIR__ . '/logbook_lifecycle.php';

const ESTAB_DV_REQUIRED_HATS = ['S2', 'Si', 'S6', 'LdF', 'A/W'];
const ESTAB_DV_MEDIA = ['Fe', 'Fu', 'Me', 'FAX', 'FS', '@'];

final class EstabDvInputException extends InvalidArgumentException
{
}

final class EstabDvConflictException extends RuntimeException
{
}

final class EstabDvPermissionException extends RuntimeException
{
}

function estab_dv_positive_id(mixed $value, string $label): int
{
    if (
        is_int($value)
        && $value > 0
    ) {
        return $value;
    }
    if (
        !is_string($value)
        || preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1
    ) {
        throw new EstabDvInputException($label . ' ist ungültig.');
    }
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($number) || $number < 1) {
        throw new EstabDvInputException($label . ' ist ungültig.');
    }
    return $number;
}

function estab_dv_code(mixed $value, string $label = 'Kürzel'): string
{
    if (!is_string($value)) {
        throw new EstabDvInputException($label . ' ist ungültig.');
    }
    $code = strtolower(trim($value));
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1) {
        throw new EstabDvInputException($label . ' ist ungültig.');
    }
    return $code;
}

function estab_dv_text(
    mixed $value,
    string $label,
    int $maximum,
    bool $allowEmpty = false
): string {
    if (
        !is_string($value)
        || preg_match('//u', $value) !== 1
        || preg_match('/[\p{C}<>]/u', $value) === 1
    ) {
        throw new EstabDvInputException($label . ' ist ungültig.');
    }
    $text = trim($value);
    $length = estab_auth_text_length($text);
    if ($length > $maximum || (!$allowEmpty && $length < 1)) {
        throw new EstabDvInputException($label . ' ist ungültig.');
    }
    return $text;
}

function estab_dv_datetime(
    mixed $value,
    string $label,
    bool $allowEmpty = false
): ?string {
    if ($allowEmpty && ($value === null || $value === '')) {
        return null;
    }
    if (
        !is_string($value)
        || preg_match(
            '/\A(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?\z/D',
            $value,
            $matches
        ) !== 1
        || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        || (int) $matches[4] > 23
        || (int) $matches[5] > 59
        || (isset($matches[6]) && (int) $matches[6] > 59)
    ) {
        throw new EstabDvInputException($label . ' ist ungültig.');
    }
    return sprintf(
        '%04d-%02d-%02d %02d:%02d:%02d',
        (int) $matches[1],
        (int) $matches[2],
        (int) $matches[3],
        (int) $matches[4],
        (int) $matches[5],
        isset($matches[6]) ? (int) $matches[6] : 0
    );
}

/** Read the MariaDB clock once for one atomic duty-shift transition. */
function estab_dv_database_now(mysqli $connection): string
{
    $result = $connection->query(
        "SELECT DATE_FORMAT(NOW(6), '%Y-%m-%d %H:%i:%s.%f') AS `recorded_at`"
    );
    if (!$result) {
        throw new RuntimeException('Zeitpunkt der Dienstübergabe konnte nicht gelesen werden.');
    }
    try {
        $row = $result->fetch_assoc();
    } finally {
        $result->free();
    }
    $value = is_array($row) ? ($row['recorded_at'] ?? null) : null;
    if (
        !is_string($value)
        || preg_match(
            '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/D',
            $value
        ) !== 1
    ) {
        throw new RuntimeException('Zeitpunkt der Dienstübergabe ist ungültig.');
    }
    return $value;
}

function estab_dv_actor(mixed $value): string
{
    return estab_dv_text($value, 'Akteur', 128);
}

/** @return array<string,string> */
function estab_dv_function_roles(
    mysqli $connection,
    string $matrixTable = 'nv_empfmtx'
): array {
    $roles = estab_assignment_function_roles($connection, $matrixTable);
    $statement = $connection->prepare(
        'SELECT `funktion`, `rolle` FROM `nv_funktionsfaehigkeiten`'
        . ' ORDER BY `funktion`, `faehigkeit`'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Fachfunktionen konnten nicht vorbereitet werden.'
        );
    }
    try {
        $statement->execute();
        $result = $statement->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $function = (string) ($row['funktion'] ?? '');
            $role = (string) ($row['rolle'] ?? '');
            if (
                isset($roles[$function])
                && !hash_equals((string) $roles[$function], $role)
            ) {
                throw new RuntimeException(
                    'Empfängermatrix und Fachfunktionskatalog widersprechen '
                    . 'sich.'
                );
            }
            $roles[$function] = $role;
        }
        $result->free();
    } finally {
        $statement->close();
    }
    ksort($roles, SORT_NATURAL | SORT_FLAG_CASE);
    return $roles;
}

function estab_dv_role_for_function(array $roles, mixed $functionValue): array
{
    if (!is_string($functionValue)) {
        throw new EstabDvInputException('Funktion ist ungültig.');
    }
    $function = trim($functionValue);
    $role = $roles[$function] ?? null;
    if (
        !is_string($role)
        || !in_array($role, ['Stab', 'FB', 'Fernmelder'], true)
    ) {
        throw new EstabDvInputException(
            'Die Funktion gehört nicht zur aktiven Empfängermatrix.'
        );
    }
    return ['funktion' => $function, 'rolle' => $role];
}

/**
 * Resolve one personal assignment from authoritative database state and
 * reconcile its legacy staff tables before the transactional state change.
 *
 * DDL commits implicitly, so this check intentionally runs before the domain
 * transaction.  Accept/select repeat the exact assignment, account, incident,
 * function and role predicates under row locks afterwards.
 *
 * @param string|list<string> $shiftStatus one or more permitted shift states
 * @return array{funktion:string,rolle:string,benutzer_kuerzel:string,
 *   schicht_status:string}
 */
function estab_dv_prepare_assignment_schema(
    mysqli $connection,
    int $incidentId,
    int $assignmentId,
    string $userCode,
    string $assignmentStatus,
    string|array $shiftStatus,
    bool $requireActiveAccount,
    string $matrixTable = 'nv_empfmtx',
    string $userTablePrefix = 'usr_'
): array {
    $shiftStatuses = is_string($shiftStatus)
        ? [$shiftStatus]
        : array_values($shiftStatus);
    if (
        !in_array(
            $assignmentStatus,
            ['ZUGEWIESEN', 'ANGENOMMEN'],
            true
        )
        || $shiftStatuses === []
        || count(array_unique($shiftStatuses)) !== count($shiftStatuses)
        || array_diff($shiftStatuses, ['GEPLANT', 'AKTIV']) !== []
    ) {
        throw new LogicException(
            'Ungültige Vorbedingung für dynamische Funktionstabellen.'
        );
    }
    $statement = $connection->prepare(
        'SELECT assignment.`benutzer_kuerzel`, assignment.`funktion`,'
        . ' assignment.`rolle`, assignment.`status`,'
        . ' shift_row.`status` AS `schicht_status`,'
        . ' account.`aktiv` AS `benutzer_aktiv`,'
        . ' account.`estab_gesperrt` AS `benutzer_gesperrt`'
        . ' FROM `nv_dienstbesetzungen` AS assignment'
        . ' JOIN `nv_dienstschichten` AS shift_row'
        . ' ON shift_row.`dienstschicht_id` ='
        . ' assignment.`dienstschicht_id`'
        . ' JOIN `nv_benutzer` AS account'
        . ' ON BINARY account.`kuerzel` ='
        . ' BINARY assignment.`benutzer_kuerzel`'
        . ' WHERE assignment.`dienstbesetzung_id` = ?'
        . ' AND shift_row.`einsatz_id` = ? LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Dienstbesetzung konnte nicht für den Funktionswechsel geprüft '
            . 'werden.'
        );
    }
    try {
        $statement->bind_param('ii', $assignmentId, $incidentId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (
        !is_array($row)
        || !hash_equals((string) $row['benutzer_kuerzel'], $userCode)
    ) {
        throw new EstabDvPermissionException(
            'Diese Funktionsbesetzung gehört nicht zu Ihrem Konto.'
        );
    }
    if (
        !hash_equals($assignmentStatus, (string) $row['status'])
        || !in_array(
            (string) $row['schicht_status'],
            $shiftStatuses,
            true
        )
    ) {
        throw new EstabDvConflictException(
            'Die Funktionsbesetzung befindet sich nicht mehr im erwarteten '
            . 'Zustand.'
        );
    }
    if (
        (int) $row['benutzer_gesperrt'] === 1
        || ($requireActiveAccount && (int) $row['benutzer_aktiv'] !== 1)
    ) {
        throw new EstabDvPermissionException(
            'Das Benutzerkonto ist für diesen Funktionswechsel nicht aktiv.'
        );
    }

    $canonical = estab_dv_role_for_function(
        estab_dv_function_roles($connection, $matrixTable),
        (string) $row['funktion']
    );
    if (!hash_equals($canonical['rolle'], (string) $row['rolle'])) {
        throw new EstabDvPermissionException(
            'Die Funktionsbesetzung gehört nicht zum freigegebenen '
            . 'Fachfunktionskatalog.'
        );
    }
    estab_dynamic_schema_reconcile_hat(
        $connection,
        $userTablePrefix,
        $canonical['funktion'],
        $userCode,
        $canonical['rolle']
    );
    return [
        'funktion' => $canonical['funktion'],
        'rolle' => $canonical['rolle'],
        'benutzer_kuerzel' => $userCode,
        'schicht_status' => (string) $row['schicht_status'],
    ];
}

function estab_dv_audit(
    mysqli $connection,
    string $protocolTable,
    int $incidentId,
    string $event,
    array $details
): void {
    $payload = json_encode(
        ['version' => 1] + $details,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($payload)) {
        throw new RuntimeException('DV-Audit konnte nicht erzeugt werden.');
    }
    $objectType = match ($event) {
        'DV Dienstschicht' => 'DIENSTSCHICHT',
        'DV Besetzung' => 'DIENSTBESETZUNG',
        'DV Übergabe' => 'DIENSTUEBERGABE',
        'DV Fernmeldeplan' => 'FERNMELDEPLAN',
        'DV Melder' => 'MELDERAUFTRAG',
        default => throw new InvalidArgumentException('Unbekannter DV-Ereignistyp.'),
    };
    $objectId = match ($objectType) {
        'DIENSTSCHICHT' => $details['shift_id'] ?? null,
        'DIENSTBESETZUNG' => $details['assignment_id'] ?? null,
        'DIENSTUEBERGABE' =>
            $details['handover_id']
                ?? $details['handover_request_id']
                ?? null,
        'FERNMELDEPLAN' => $details['plan_id'] ?? null,
        'MELDERAUFTRAG' => $details['job_id'] ?? null,
    };
    $action = $details['action'] ?? null;
    $actor = $details['actor']
        ?? $details['outgoing_actor']
        ?? $details['target']
        ?? 'system';
    $actorFunction = $details['actor_function']
        ?? (
            $objectType === 'FERNMELDEPLAN'
                ? 'S6'
                : ($details['function'] ?? $details['new_function'] ?? null)
        );
    if (
        !is_int($objectId)
        || $objectId < 1
        || !is_string($action)
        || preg_match('/\A[a-z][a-z0-9_]{2,63}\z/D', $action) !== 1
        || !is_string($actor)
    ) {
        throw new RuntimeException('DV-Audit enthält keinen eindeutigen Objektbezug.');
    }
    estab_dv_event_append(
        $connection,
        $incidentId,
        $objectType,
        $objectId,
        $action,
        $actor,
        is_string($actorFunction) ? $actorFunction : null,
        $details
    );
    $statement = $connection->prepare(
        'INSERT INTO ' . estab_auth_table($protocolTable)
        . ' (`einsatz_id`, `p_was`, `p_ereignis`) VALUES (?, ?, ?)'
    );
    if (!$statement) {
        throw new RuntimeException('DV-Audit konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('iss', $incidentId, $event, $payload);
        if (!$statement->execute()) {
            throw new RuntimeException('DV-Audit konnte nicht gespeichert werden.');
        }
    } finally {
        $statement->close();
    }
}

/**
 * Append one cryptographically linked command-post event.
 *
 * The caller owns the surrounding incident transaction. Locking the per-
 * incident head serialises concurrent object workflows without a global lock.
 */
function estab_dv_event_append(
    mysqli $connection,
    int $incidentId,
    string $objectType,
    int $objectId,
    string $action,
    string $actor,
    ?string $actorFunction,
    array $details
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $objectId = estab_dv_positive_id($objectId, 'Betriebsobjekt');
    if (!in_array(
        $objectType,
        [
            'DIENSTSCHICHT',
            'DIENSTBESETZUNG',
            'DIENSTUEBERGABE',
            'FERNMELDEPLAN',
            'MELDERAUFTRAG',
        ],
        true
    )) {
        throw new InvalidArgumentException('Ungültiger Betriebsereignistyp.');
    }
    if (preg_match('/\A[a-z][a-z0-9_]{2,63}\z/D', $action) !== 1) {
        throw new InvalidArgumentException('Ungültige Betriebsereignisaktion.');
    }
    $actor = estab_dv_actor($actor);
    if (
        $actorFunction !== null
        && preg_match('/\A(?:A\/W|[A-Za-z0-9_]{1,6})\z/D', $actorFunction) !== 1
    ) {
        throw new InvalidArgumentException('Ungültige Akteursfunktion.');
    }
    $detailsJson = json_encode(
        $details,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($detailsJson)) {
        throw new RuntimeException('Betriebsereignisdetails konnten nicht erzeugt werden.');
    }
    $seed = str_repeat('0', 64);
    $insertHead = $connection->prepare(
        'INSERT IGNORE INTO `nv_betriebsereignis_kopf`'
        . ' (`einsatz_id`, `letzte_sequenz`, `letzter_hash`) VALUES (?, 0, ?)'
    );
    if (!$insertHead) {
        throw new RuntimeException('Betriebsereigniskopf konnte nicht vorbereitet werden.');
    }
    try {
        $insertHead->bind_param('is', $incidentId, $seed);
        if (!$insertHead->execute()) {
            throw new RuntimeException('Betriebsereigniskopf konnte nicht angelegt werden.');
        }
    } finally {
        $insertHead->close();
    }
    $head = $connection->prepare(
        'SELECT `letzte_sequenz`, `letzter_hash`'
        . ' FROM `nv_betriebsereignis_kopf`'
        . ' WHERE `einsatz_id` = ? FOR UPDATE'
    );
    if (!$head) {
        throw new RuntimeException('Betriebsereigniskopf konnte nicht gesperrt werden.');
    }
    try {
        $head->bind_param('i', $incidentId);
        $head->execute();
        $headRow = $head->get_result()->fetch_assoc();
    } finally {
        $head->close();
    }
    if (
        !is_array($headRow)
        || preg_match('/\A[0-9a-f]{64}\z/D', (string) $headRow['letzter_hash']) !== 1
    ) {
        throw new RuntimeException('Betriebsereigniskopf ist inkonsistent.');
    }
    $sequence = (int) $headRow['letzte_sequenz'] + 1;
    $previousHash = (string) $headRow['letzter_hash'];
    $eventTime = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
    $eventHash = hash(
        'sha256',
        implode('|', [
            (string) $incidentId,
            (string) $sequence,
            $objectType,
            (string) $objectId,
            $action,
            $actor,
            $actorFunction ?? '',
            $eventTime,
            $detailsJson,
            $previousHash,
        ])
    );
    $insertEvent = $connection->prepare(
        'INSERT INTO `nv_betriebsereignisse`'
        . ' (`einsatz_id`, `sequenz`, `objekttyp`, `objekt_id`, `aktion`,'
        . ' `akteur_kuerzel`, `akteur_funktion`, `ereigniszeit`, `details`,'
        . ' `vorheriger_hash`, `ereignis_hash`)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$insertEvent) {
        throw new RuntimeException('Betriebsereignis konnte nicht vorbereitet werden.');
    }
    try {
        $insertEvent->bind_param(
            'iisisssssss',
            $incidentId,
            $sequence,
            $objectType,
            $objectId,
            $action,
            $actor,
            $actorFunction,
            $eventTime,
            $detailsJson,
            $previousHash,
            $eventHash
        );
        if (!$insertEvent->execute()) {
            throw new RuntimeException('Betriebsereignis konnte nicht gespeichert werden.');
        }
        $eventId = (int) $connection->insert_id;
    } finally {
        $insertEvent->close();
    }
    $updateHead = $connection->prepare(
        'UPDATE `nv_betriebsereignis_kopf`'
        . ' SET `letzte_sequenz` = ?, `letzter_hash` = ?'
        . ' WHERE `einsatz_id` = ? AND `letzte_sequenz` = ?'
        . ' AND BINARY `letzter_hash` = BINARY ?'
    );
    if (!$updateHead) {
        throw new RuntimeException('Betriebsereigniskopf konnte nicht fortgeschrieben werden.');
    }
    try {
        $previousSequence = $sequence - 1;
        $updateHead->bind_param(
            'isiis',
            $sequence,
            $eventHash,
            $incidentId,
            $previousSequence,
            $previousHash
        );
        if (!$updateHead->execute() || $updateHead->affected_rows !== 1) {
            throw new RuntimeException(
                'Betriebsereigniskette wurde gleichzeitig verändert.'
            );
        }
    } finally {
        $updateHead->close();
    }
    return [
        'betriebsereignis_id' => $eventId,
        'sequenz' => $sequence,
        'ereignis_hash' => $eventHash,
    ];
}

function estab_dv_require_incident(
    mysqli $connection,
    int $incidentId,
    bool $forUpdate = true
): array {
    $status = estab_incident_require_active($connection, $forUpdate);
    if ((int) $status['active_einsatz_id'] !== $incidentId) {
        throw new EstabDvConflictException(
            'Der ausgewählte Einsatz ist nicht mehr aktiv.'
        );
    }
    return $status;
}

/** Return shifts together with all historic and current function hats. */
function estab_dv_shift_list(mysqli $connection, int $incidentId): array
{
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT s.*, b.`dienstbesetzung_id`, b.`benutzer_kuerzel`,'
        . ' b.`funktion`, b.`rolle`, b.`status` AS `besetzung_status`,'
        . ' b.`zugewiesen_am`, b.`angenommen_am`, b.`abgeloest_am`,'
        . ' u.`benutzer`, u.`aktiv` AS `benutzer_aktiv`,'
        . ' u.`estab_gesperrt` AS `benutzer_gesperrt`'
        . ' FROM `nv_dienstschichten` AS s'
        . ' LEFT JOIN `nv_dienstbesetzungen` AS b'
        . ' ON b.`dienstschicht_id` = s.`dienstschicht_id`'
        . ' LEFT JOIN `nv_benutzer` AS u'
        . ' ON u.`kuerzel` = b.`benutzer_kuerzel`'
        . ' WHERE s.`einsatz_id` = ?'
        . ' ORDER BY s.`nummer` DESC, b.`funktion`, b.`dienstbesetzung_id`'
    );
    if (!$statement) {
        throw new RuntimeException('Dienstschichten konnten nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('i', $incidentId);
        if (!$statement->execute()) {
            throw new RuntimeException('Dienstschichten konnten nicht gelesen werden.');
        }
        $result = $statement->get_result();
        $shifts = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $shiftId = (int) $row['dienstschicht_id'];
            if (!isset($shifts[$shiftId])) {
                $shifts[$shiftId] = [
                    'dienstschicht_id' => $shiftId,
                    'einsatz_id' => (int) $row['einsatz_id'],
                    'nummer' => (int) $row['nummer'],
                    'bezeichnung' => (string) $row['bezeichnung'],
                    'status' => (string) $row['status'],
                    'vorgaenger_id' => $row['vorgaenger_id'] === null
                        ? null
                        : (int) $row['vorgaenger_id'],
                    'erstellt_am' => (string) $row['erstellt_am'],
                    'aktiviert_am' => $row['aktiviert_am'],
                    'beendet_am' => $row['beendet_am'],
                    'besetzungen' => [],
                ];
            }
            if ($row['dienstbesetzung_id'] !== null) {
                $shifts[$shiftId]['besetzungen'][] = [
                    'dienstbesetzung_id' => (int) $row['dienstbesetzung_id'],
                    'benutzer_kuerzel' => (string) $row['benutzer_kuerzel'],
                    'benutzer' => (string) ($row['benutzer'] ?? ''),
                    'benutzer_aktiv' =>
                        (int) ($row['benutzer_aktiv'] ?? 0),
                    'benutzer_gesperrt' =>
                        (int) ($row['benutzer_gesperrt'] ?? 1),
                    'funktion' => (string) $row['funktion'],
                    'rolle' => (string) $row['rolle'],
                    'status' => (string) $row['besetzung_status'],
                    'zugewiesen_am' => (string) $row['zugewiesen_am'],
                    'angenommen_am' => $row['angenommen_am'],
                    'abgeloest_am' => $row['abgeloest_am'],
                ];
            }
        }
        $result->free();
        return array_values($shifts);
    } finally {
        $statement->close();
    }
}

/**
 * Return the non-personal summary of the currently active duty shift.
 *
 * The command-post bootstrap may show this before a user selects a hat. It
 * therefore deliberately excludes every assignment and account column.
 */
function estab_dv_active_shift_summary(
    mysqli $connection,
    int $incidentId
): ?array {
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT `dienstschicht_id`, `einsatz_id`, `nummer`, `bezeichnung`,'
        . ' `status`, `vorgaenger_id`, `erstellt_am`, `aktiviert_am`,'
        . ' `beendet_am` FROM `nv_dienstschichten`'
        . ' WHERE `einsatz_id` = ? AND `status` = \'AKTIV\''
        . ' LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Aktive Dienstschicht konnte nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('i', $incidentId);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Aktive Dienstschicht konnte nicht gelesen werden.'
            );
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    } finally {
        $statement->close();
    }
}

function estab_dv_create_shift(
    mysqli $connection,
    int $incidentId,
    mixed $labelValue,
    mixed $predecessorValue,
    string $actor,
    string $protocolTable = 'nv_protokoll'
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $label = estab_dv_text($labelValue, 'Schichtbezeichnung', 100);
    $actor = estab_dv_actor($actor);
    $predecessorId = $predecessorValue === null || $predecessorValue === ''
        ? null
        : estab_dv_positive_id($predecessorValue, 'Vorgängerschicht');

    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $label,
            $predecessorId,
            $actor,
            $protocolTable
        ): array {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException(
                    'Der ausgewählte Einsatz ist nicht mehr aktiv.'
                );
            }
            if ($predecessorId !== null) {
                $check = $connection->prepare(
                    'SELECT `status` FROM `nv_dienstschichten`'
                    . ' WHERE `dienstschicht_id` = ? AND `einsatz_id` = ?'
                    . ' FOR UPDATE'
                );
                if (!$check) {
                    throw new RuntimeException('Vorgängerschicht konnte nicht geprüft werden.');
                }
                try {
                    $check->bind_param('ii', $predecessorId, $incidentId);
                    $check->execute();
                    $row = $check->get_result()->fetch_assoc();
                    if (
                        !is_array($row)
                        || !in_array((string) $row['status'], ['AKTIV', 'GEPLANT'], true)
                    ) {
                        throw new EstabDvConflictException(
                            'Die Vorgängerschicht ist nicht übergabefähig.'
                        );
                    }
                } finally {
                    $check->close();
                }
            }
            $numberResult = $connection->query(
                'SELECT COALESCE(MAX(`nummer`), 0) + 1 AS `nummer`'
                . ' FROM `nv_dienstschichten`'
                . ' WHERE `einsatz_id` = ' . $incidentId
                . ' FOR UPDATE'
            );
            if (!$numberResult) {
                throw new RuntimeException('Schichtnummer konnte nicht reserviert werden.');
            }
            $number = (int) ($numberResult->fetch_assoc()['nummer'] ?? 0);
            $numberResult->free();
            $insert = $connection->prepare(
                'INSERT INTO `nv_dienstschichten`'
                . ' (`einsatz_id`, `nummer`, `bezeichnung`, `vorgaenger_id`,'
                . ' `erstellt_von`) VALUES (?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                throw new RuntimeException('Dienstschicht konnte nicht vorbereitet werden.');
            }
            try {
                $insert->bind_param(
                    'iisis',
                    $incidentId,
                    $number,
                    $label,
                    $predecessorId,
                    $actor
                );
                if (!$insert->execute()) {
                    throw new RuntimeException('Dienstschicht konnte nicht angelegt werden.');
                }
                $shiftId = (int) $connection->insert_id;
            } finally {
                $insert->close();
            }
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Dienstschicht',
                [
                    'action' => 'shift_created',
                    'shift_id' => $shiftId,
                    'number' => $number,
                    'label' => $label,
                    'predecessor_id' => $predecessorId,
                    'actor' => $actor,
                ]
            );
            return ['dienstschicht_id' => $shiftId, 'nummer' => $number];
        }
    );
}

function estab_dv_assign_hat(
    mysqli $connection,
    int $incidentId,
    int $shiftId,
    mixed $userCodeValue,
    mixed $functionValue,
    string $actor,
    string $matrixTable = 'nv_empfmtx',
    string $protocolTable = 'nv_protokoll'
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $shiftId = estab_dv_positive_id($shiftId, 'Dienstschicht');
    $userCode = estab_dv_code($userCodeValue);
    $actor = estab_dv_actor($actor);

    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $shiftId,
            $userCode,
            $functionValue,
            $actor,
            $matrixTable,
            $protocolTable
        ): array {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException('Der Einsatz ist nicht mehr aktiv.');
            }
            $assignment = estab_dv_role_for_function(
                estab_dv_function_roles($connection, $matrixTable),
                $functionValue
            );
            $shift = $connection->prepare(
                'SELECT `status` FROM `nv_dienstschichten`'
                . ' WHERE `dienstschicht_id` = ? AND `einsatz_id` = ? FOR UPDATE'
            );
            if (!$shift) {
                throw new RuntimeException('Dienstschicht konnte nicht gesperrt werden.');
            }
            try {
                $shift->bind_param('ii', $shiftId, $incidentId);
                $shift->execute();
                $shiftRow = $shift->get_result()->fetch_assoc();
            } finally {
                $shift->close();
            }
            $shiftStatus = (string) ($shiftRow['status'] ?? '');
            if (!in_array($shiftStatus, ['GEPLANT', 'AKTIV'], true)) {
                throw new EstabDvConflictException(
                    'Besetzungen können nur einer geplanten oder aktiven '
                    . 'Schicht zugewiesen werden.'
                );
            }
            if ($shiftStatus === 'AKTIV' && $assignment['funktion'] === 'ETB') {
                $currentWriter = $connection->prepare(
                    'SELECT assignment.`dienstbesetzung_id`'
                    . ' FROM `nv_dienstbesetzungen` AS assignment'
                    . ' WHERE assignment.`dienstschicht_id` = ?'
                    . " AND assignment.`status` = 'ANGENOMMEN'"
                    . " AND assignment.`funktion` IN ('ETB','S2')"
                    . ' ORDER BY CASE assignment.`funktion`'
                    . " WHEN 'ETB' THEN 0 ELSE 1 END,"
                    . ' assignment.`dienstbesetzung_id` LIMIT 1 FOR UPDATE'
                );
                if (!$currentWriter) {
                    throw new RuntimeException(
                        'Bestehende ETB-Führung konnte nicht geprüft werden.'
                    );
                }
                try {
                    $currentWriter->bind_param('i', $shiftId);
                    $currentWriter->execute();
                    $wouldReplaceWriter =
                        $currentWriter->get_result()->fetch_row() !== null;
                } finally {
                    $currentWriter->close();
                }
                if ($wouldReplaceWriter) {
                    throw new EstabDvConflictException(
                        'Die ETB-Funktion kann in der aktiven Schicht nicht '
                        . 'zugewiesen werden, weil bereits eine ETB-Führung '
                        . 'bestimmt ist. Ein Wechsel ist ausschließlich über '
                        . 'eine dokumentierte und bestätigte Schichtübergabe '
                        . 'zulässig.'
                    );
                }
            }
            if ($shiftStatus === 'AKTIV' && $assignment['funktion'] !== 'A/W') {
                $occupied = $connection->prepare(
                    'SELECT 1 FROM `nv_dienstbesetzungen`'
                    . ' WHERE `dienstschicht_id` = ?'
                    . ' AND BINARY `funktion` = BINARY ?'
                    . " AND `status` IN ('ZUGEWIESEN','ANGENOMMEN')"
                    . ' LIMIT 1 FOR UPDATE'
                );
                if (!$occupied) {
                    throw new RuntimeException(
                        'Bestehende Funktionsbesetzung konnte nicht geprüft '
                        . 'werden.'
                    );
                }
                try {
                    $occupied->bind_param(
                        'is',
                        $shiftId,
                        $assignment['funktion']
                    );
                    $occupied->execute();
                    $alreadyOccupied =
                        $occupied->get_result()->fetch_row() !== null;
                } finally {
                    $occupied->close();
                }
                if ($alreadyOccupied) {
                    throw new EstabDvConflictException(
                        'Diese Funktion ist in der aktiven Schicht bereits '
                        . 'besetzt. Ein Austausch ist ausschließlich über '
                        . 'eine geordnete Schichtübergabe möglich.'
                    );
                }
            }
            $user = $connection->prepare(
                'SELECT `estab_gesperrt` FROM `nv_benutzer`'
                . ' WHERE `kuerzel` = ? FOR UPDATE'
            );
            if (!$user) {
                throw new RuntimeException('Benutzerkonto konnte nicht geprüft werden.');
            }
            try {
                $user->bind_param('s', $userCode);
                $user->execute();
                $userRow = $user->get_result()->fetch_assoc();
            } finally {
                $user->close();
            }
            if (!is_array($userRow) || (int) $userRow['estab_gesperrt'] === 1) {
                throw new EstabDvConflictException(
                    'Das Benutzerkonto ist nicht verfügbar.'
                );
            }
            $insert = $connection->prepare(
                'INSERT INTO `nv_dienstbesetzungen`'
                . ' (`dienstschicht_id`, `benutzer_kuerzel`, `funktion`,'
                . ' `rolle`, `zugewiesen_von`) VALUES (?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                throw new RuntimeException('Funktionsbesetzung konnte nicht vorbereitet werden.');
            }
            try {
                $insert->bind_param(
                    'issss',
                    $shiftId,
                    $userCode,
                    $assignment['funktion'],
                    $assignment['rolle'],
                    $actor
                );
                try {
                    $executed = $insert->execute();
                } catch (mysqli_sql_exception $exception) {
                    if ((int) $exception->getCode() === 1062) {
                        throw new EstabDvConflictException(
                            'Diese Funktion ist in der Schicht bereits besetzt '
                            . 'oder dieselbe Person wurde ihr schon zugewiesen.'
                        );
                    }
                    throw new RuntimeException(
                        'Funktionsbesetzung konnte nicht gespeichert werden.',
                        0,
                        $exception
                    );
                }
                if (!$executed) {
                    if ($insert->errno === 1062) {
                        throw new EstabDvConflictException(
                            'Diese Funktion ist in der Schicht bereits besetzt '
                            . 'oder dieselbe Person wurde ihr schon zugewiesen.'
                        );
                    }
                    throw new RuntimeException(
                        'Funktionsbesetzung konnte nicht gespeichert werden.'
                    );
                }
                $assignmentId = (int) $connection->insert_id;
            } finally {
                $insert->close();
            }
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Besetzung',
                [
                    'action' => 'hat_assigned',
                    'assignment_id' => $assignmentId,
                    'shift_id' => $shiftId,
                    'target' => $userCode,
                    'function' => $assignment['funktion'],
                    'role' => $assignment['rolle'],
                    'actor' => $actor,
                    'shift_status' => $shiftStatus,
                    'active_shift_extension' => $shiftStatus === 'AKTIV',
                ]
            );
            return [
                'dienstbesetzung_id' => $assignmentId,
                'funktion' => $assignment['funktion'],
                'rolle' => $assignment['rolle'],
                'schicht_status' => $shiftStatus,
                'active_shift_extension' => $shiftStatus === 'AKTIV',
            ];
        }
    );
}

function estab_dv_accept_hat(
    mysqli $connection,
    int $incidentId,
    int $assignmentId,
    string $userCode,
    string $protocolTable = 'nv_protokoll',
    string $matrixTable = 'nv_empfmtx',
    string $userTablePrefix = 'usr_'
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $assignmentId = estab_dv_positive_id($assignmentId, 'Dienstbesetzung');
    $userCode = estab_dv_code($userCode);
    $prepared = estab_dv_prepare_assignment_schema(
        $connection,
        $incidentId,
        $assignmentId,
        $userCode,
        'ZUGEWIESEN',
        ['GEPLANT', 'AKTIV'],
        false,
        $matrixTable,
        $userTablePrefix
    );

    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $assignmentId,
            $userCode,
            $prepared,
            $protocolTable
        ): array {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException('Der Einsatz ist nicht mehr aktiv.');
            }
            $select = $connection->prepare(
                'SELECT b.`dienstschicht_id`, b.`benutzer_kuerzel`,'
                . ' b.`funktion`, b.`rolle`,'
                . ' b.`status`, s.`status` AS `schicht_status`,'
                . ' s.`nummer` AS `schicht_nummer`,'
                . ' s.`bezeichnung` AS `schicht_bezeichnung`,'
                . ' u.`benutzer` AS `benutzer_name`,'
                . ' u.`aktiv` AS `benutzer_aktiv`,'
                . ' u.`estab_gesperrt` AS `benutzer_gesperrt`'
                . ' FROM `nv_dienstbesetzungen` AS b'
                . ' JOIN `nv_dienstschichten` AS s'
                . ' ON s.`dienstschicht_id` = b.`dienstschicht_id`'
                . ' JOIN `nv_benutzer` AS u'
                . ' ON BINARY u.`kuerzel` = BINARY b.`benutzer_kuerzel`'
                . ' WHERE b.`dienstbesetzung_id` = ?'
                . ' AND s.`einsatz_id` = ?'
                . ' AND BINARY b.`funktion` = BINARY ?'
                . ' AND BINARY b.`rolle` = BINARY ? FOR UPDATE'
            );
            if (!$select) {
                throw new RuntimeException('Dienstbesetzung konnte nicht geprüft werden.');
            }
            try {
                $select->bind_param(
                    'iiss',
                    $assignmentId,
                    $incidentId,
                    $prepared['funktion'],
                    $prepared['rolle']
                );
                $select->execute();
                $row = $select->get_result()->fetch_assoc();
            } finally {
                $select->close();
            }
            if (
                !is_array($row)
                || !hash_equals((string) $row['benutzer_kuerzel'], $userCode)
            ) {
                throw new EstabDvPermissionException(
                    'Diese Funktionsbesetzung gehört nicht zu Ihrem Konto.'
                );
            }
            if (
                $row['status'] !== 'ZUGEWIESEN'
                || !hash_equals(
                    (string) $prepared['schicht_status'],
                    (string) $row['schicht_status']
                )
                || !in_array(
                    (string) $row['schicht_status'],
                    ['GEPLANT', 'AKTIV'],
                    true
                )
            ) {
                throw new EstabDvConflictException(
                    'Die Funktionsbesetzung kann nicht mehr angenommen werden.'
                );
            }
            if ((int) $row['benutzer_gesperrt'] === 1) {
                throw new EstabDvPermissionException(
                    'Ein gesperrtes Konto darf keine Dienstfunktion '
                    . 'annehmen.'
                );
            }
            if (
                (string) $row['schicht_status'] === 'AKTIV'
                && (string) $row['funktion'] === 'ETB'
            ) {
                $currentWriter = $connection->prepare(
                    'SELECT assignment.`dienstbesetzung_id`'
                    . ' FROM `nv_dienstbesetzungen` AS assignment'
                    . ' WHERE assignment.`dienstschicht_id` = ?'
                    . " AND assignment.`status` = 'ANGENOMMEN'"
                    . " AND assignment.`funktion` IN ('ETB','S2')"
                    . ' AND assignment.`dienstbesetzung_id` <> ?'
                    . ' ORDER BY CASE assignment.`funktion`'
                    . " WHEN 'ETB' THEN 0 ELSE 1 END,"
                    . ' assignment.`dienstbesetzung_id` LIMIT 1 FOR UPDATE'
                );
                if (!$currentWriter) {
                    throw new RuntimeException(
                        'Bestehende ETB-Führung konnte nicht geprüft werden.'
                    );
                }
                try {
                    $shiftId = (int) $row['dienstschicht_id'];
                    $currentWriter->bind_param(
                        'ii',
                        $shiftId,
                        $assignmentId
                    );
                    $currentWriter->execute();
                    $wouldReplaceWriter =
                        $currentWriter->get_result()->fetch_row() !== null;
                } finally {
                    $currentWriter->close();
                }
                if ($wouldReplaceWriter) {
                    throw new EstabDvConflictException(
                        'Die ETB-Funktion kann in der aktiven Schicht nicht '
                        . 'angenommen werden, weil bereits eine ETB-Führung '
                        . 'bestimmt ist. Ein Wechsel ist ausschließlich über '
                        . 'eine dokumentierte und bestätigte Schichtübergabe '
                        . 'zulässig.'
                    );
                }
            }
            $acceptedAt = date('Y-m-d H:i:s');
            $update = $connection->prepare(
                "UPDATE `nv_dienstbesetzungen` SET `status` = 'ANGENOMMEN',"
                . ' `angenommen_am` = ?'
                . " WHERE `dienstbesetzung_id` = ? AND `status` = 'ZUGEWIESEN'"
            );
            if (!$update) {
                throw new RuntimeException('Annahme konnte nicht vorbereitet werden.');
            }
            try {
                $update->bind_param('si', $acceptedAt, $assignmentId);
                if (!$update->execute() || $update->affected_rows !== 1) {
                    throw new EstabDvConflictException(
                        'Die Besetzung wurde zwischenzeitlich geändert.'
                    );
                }
            } finally {
                $update->close();
            }
            $activeShiftExtension =
                (string) $row['schicht_status'] === 'AKTIV';
            if ($activeShiftExtension) {
                estab_logbook_lifecycle_shift_extension(
                    $connection,
                    $incidentId,
                    (int) $row['dienstschicht_id'],
                    (int) $row['schicht_nummer'],
                    (string) $row['schicht_bezeichnung'],
                    $acceptedAt,
                    (string) $row['benutzer_name'],
                    $userCode,
                    (string) $row['funktion'],
                    (string) $row['rolle']
                );
            }
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Besetzung',
                [
                    'action' => 'hat_accepted',
                    'assignment_id' => $assignmentId,
                    'target' => $userCode,
                    'function' => (string) $row['funktion'],
                    'role' => (string) $row['rolle'],
                    'shift_status' => (string) $row['schicht_status'],
                    'active_shift_extension' => $activeShiftExtension,
                ]
            );
            return [
                'dienstbesetzung_id' => $assignmentId,
                'funktion' => (string) $row['funktion'],
                'rolle' => (string) $row['rolle'],
                'schicht_status' => (string) $row['schicht_status'],
                'active_shift_extension' => $activeShiftExtension,
            ];
        }
    );
}

function estab_dv_shift_required_hats(
    mysqli $connection,
    int $shiftId
): array {
    $shiftId = estab_dv_positive_id($shiftId, 'Dienstschicht');
    $statement = $connection->prepare(
        'SELECT assignment.`funktion`'
        . ' FROM `nv_dienstbesetzungen` AS assignment'
        . ' JOIN `nv_benutzer` AS account'
        . ' ON BINARY account.`kuerzel` ='
        . ' BINARY assignment.`benutzer_kuerzel`'
        . ' WHERE assignment.`dienstschicht_id` = ?'
        . " AND assignment.`status` = 'ANGENOMMEN'"
        . ' AND account.`estab_gesperrt` = 0'
    );
    if (!$statement) {
        throw new RuntimeException('Pflichtbesetzungen konnten nicht geprüft werden.');
    }
    try {
        $statement->bind_param('i', $shiftId);
        $statement->execute();
        $result = $statement->get_result();
        $accepted = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $accepted[] = (string) $row['funktion'];
        }
        $result->free();
    } finally {
        $statement->close();
    }
    return array_values(array_diff(ESTAB_DV_REQUIRED_HATS, $accepted));
}

function estab_dv_activate_initial_shift(
    mysqli $connection,
    int $incidentId,
    int $shiftId,
    string $actor,
    string $protocolTable = 'nv_protokoll'
): void {
    $incidentId = estab_incident_positive_id($incidentId);
    $shiftId = estab_dv_positive_id($shiftId, 'Dienstschicht');
    $actor = estab_dv_actor($actor);
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $shiftId,
            $actor,
            $protocolTable
        ): void {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException('Der Einsatz ist nicht mehr aktiv.');
            }
            $active = $connection->prepare(
                'SELECT COUNT(*) AS `anzahl` FROM `nv_dienstschichten`'
                . ' WHERE `einsatz_id` = ?'
                . ' AND `aktiviert_am` IS NOT NULL FOR UPDATE'
            );
            if (!$active) {
                throw new RuntimeException('Aktive Schicht konnte nicht geprüft werden.');
            }
            try {
                $active->bind_param('i', $incidentId);
                $active->execute();
                $activeCount = (int) ($active->get_result()->fetch_assoc()['anzahl'] ?? 0);
            } finally {
                $active->close();
            }
            if ($activeCount !== 0) {
                throw new EstabDvConflictException(
                    'Für diesen Einsatz wurde bereits eine Schicht aktiviert; '
                    . 'verwenden Sie die persönlich bestätigte Übergabe.'
                );
            }
            $missing = estab_dv_shift_required_hats($connection, $shiftId);
            if ($missing !== []) {
                throw new EstabDvConflictException(
                    'Pflichtfunktionen sind nicht angenommen: ' . implode(', ', $missing)
                );
            }
            $missingHeader = estab_logbook_lifecycle_missing_header($incident);
            if ($missingHeader !== []) {
                throw new EstabDvConflictException(
                    'Die Dienstschicht kann erst nach Vervollständigung der '
                    . 'Logbuch-Stammdaten aktiviert werden. Es fehlen: '
                    . implode(', ', array_values($missingHeader))
                );
            }
            $update = $connection->prepare(
                "UPDATE `nv_dienstschichten` SET `status` = 'AKTIV',"
                . ' `aktiviert_am` = NOW(6)'
                . " WHERE `dienstschicht_id` = ? AND `einsatz_id` = ?"
                . " AND `status` = 'GEPLANT' AND `vorgaenger_id` IS NULL"
            );
            if (!$update) {
                throw new RuntimeException('Schichtaktivierung konnte nicht vorbereitet werden.');
            }
            try {
                $update->bind_param('ii', $shiftId, $incidentId);
                if (!$update->execute() || $update->affected_rows !== 1) {
                    throw new EstabDvConflictException(
                        'Die Schicht wurde zwischenzeitlich geändert.'
                    );
                }
            } finally {
                $update->close();
            }
            estab_logbook_lifecycle_open_books(
                $connection,
                $incident,
                $shiftId
            );
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Dienstschicht',
                [
                    'action' => 'shift_activated',
                    'shift_id' => $shiftId,
                    'actor' => $actor,
                ]
            );
        }
    );
}

function estab_dv_initiate_handover_shift(
    mysqli $connection,
    int $incidentId,
    int $fromShiftId,
    int $toShiftId,
    mixed $summaryValue,
    int $outgoingAssignmentId,
    array $outgoingIdentity,
    string $adminActor,
    string $protocolTable = 'nv_protokoll'
): int {
    $incidentId = estab_incident_positive_id($incidentId);
    $fromShiftId = estab_dv_positive_id(
        $fromShiftId,
        'Abgebende Schicht'
    );
    $toShiftId = estab_dv_positive_id(
        $toShiftId,
        'Übernehmende Schicht'
    );
    if ($fromShiftId === $toShiftId) {
        throw new EstabDvInputException(
            'Eine Schicht kann nicht an sich selbst übergeben.'
        );
    }
    $summary = estab_dv_text(
        $summaryValue,
        'Übergabezusammenfassung',
        10000
    );
    $outgoingAssignmentId = estab_dv_positive_id(
        $outgoingAssignmentId,
        'Persönlich übergebende Dienstbesetzung'
    );
    $outgoingShape = estab_auth_session_identity_shape([
        'vStab_benutzer' => $outgoingIdentity['benutzer'] ?? null,
        'vStab_kuerzel' => $outgoingIdentity['kuerzel'] ?? null,
        'vStab_funktion' => $outgoingIdentity['funktion'] ?? null,
        'vStab_rolle' => $outgoingIdentity['rolle'] ?? null,
    ]);
    if ($outgoingShape === null) {
        throw new EstabDvPermissionException(
            'Die Übergabe muss durch eine persönlich angemeldete Person der '
            . 'aktiven Schicht angefordert werden.'
        );
    }
    $adminActor = estab_dv_actor($adminActor);

    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $fromShiftId,
            $toShiftId,
            $summary,
            $outgoingAssignmentId,
            $outgoingShape,
            $adminActor,
            $protocolTable
        ): int {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException(
                    'Der Einsatz ist nicht mehr aktiv.'
                );
            }
            $select = $connection->prepare(
                'SELECT `dienstschicht_id`, `status`, `vorgaenger_id`'
                . ' FROM `nv_dienstschichten`'
                . ' WHERE `einsatz_id` = ?'
                . ' AND `dienstschicht_id` IN (?, ?) FOR UPDATE'
            );
            if (!$select) {
                throw new RuntimeException(
                    'Übergabeschichten konnten nicht gesperrt werden.'
                );
            }
            try {
                $select->bind_param(
                    'iii',
                    $incidentId,
                    $fromShiftId,
                    $toShiftId
                );
                $select->execute();
                $result = $select->get_result();
                $rows = [];
                while (($row = $result->fetch_assoc()) !== null) {
                    $rows[(int) $row['dienstschicht_id']] = $row;
                }
                $result->free();
            } finally {
                $select->close();
            }
            if (
                ($rows[$fromShiftId]['status'] ?? null) !== 'AKTIV'
                || ($rows[$toShiftId]['status'] ?? null) !== 'GEPLANT'
                || (int) ($rows[$toShiftId]['vorgaenger_id'] ?? 0)
                    !== $fromShiftId
            ) {
                throw new EstabDvConflictException(
                    'Die ausgewählten Schichten bilden keine gültige '
                    . 'Übergabe.'
                );
            }
            $outgoingConfirmation = $connection->prepare(
                'SELECT assignment.`funktion`, assignment.`rolle`,'
                . ' account.`benutzer` FROM `nv_dienstbesetzungen` AS assignment'
                . ' JOIN `nv_benutzer` AS account'
                . ' ON BINARY account.`kuerzel` ='
                . ' BINARY assignment.`benutzer_kuerzel`'
                . ' WHERE assignment.`dienstbesetzung_id` = ?'
                . ' AND assignment.`dienstschicht_id` = ?'
                . " AND assignment.`status` = 'ANGENOMMEN'"
                . ' AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
                . ' AND BINARY assignment.`funktion` = BINARY ?'
                . ' AND BINARY assignment.`rolle` = BINARY ?'
                . ' AND account.`aktiv` = 1 AND account.`estab_gesperrt` = 0'
                . ' LIMIT 1 FOR UPDATE'
            );
            if (!$outgoingConfirmation) {
                throw new RuntimeException(
                    'Persönlich übergebende Besetzung konnte nicht geprüft werden.'
                );
            }
            try {
                $outgoingConfirmation->bind_param(
                    'iisss',
                    $outgoingAssignmentId,
                    $fromShiftId,
                    $outgoingShape['kuerzel'],
                    $outgoingShape['funktion'],
                    $outgoingShape['rolle']
                );
                $outgoingConfirmation->execute();
                $outgoingAssignment = $outgoingConfirmation
                    ->get_result()
                    ->fetch_assoc();
            } finally {
                $outgoingConfirmation->close();
            }
            if (!is_array($outgoingAssignment)) {
                throw new EstabDvPermissionException(
                    'Nur eine persönlich angemeldete und angenommene '
                    . 'Dienstfunktion der aktiven Schicht darf übergeben.'
                );
            }
            $missing = estab_dv_shift_required_hats(
                $connection,
                $toShiftId
            );
            if ($missing !== []) {
                throw new EstabDvConflictException(
                    'Die übernehmende Schicht hat nicht alle '
                    . 'Pflichtfunktionen angenommen: '
                    . implode(', ', $missing)
                );
            }
            $openMessenger = $connection->prepare(
                'SELECT `melderauftrag_id` FROM `nv_melderauftraege`'
                . ' WHERE `einsatz_id` = ?'
                . " AND `status` NOT IN ('GEMELDET','ABGEBROCHEN')"
                . ' ORDER BY `melderauftrag_id` LIMIT 1 FOR UPDATE'
            );
            if (!$openMessenger) {
                throw new RuntimeException(
                    'Offene Melderaufträge konnten nicht geprüft werden.'
                );
            }
            try {
                $openMessenger->bind_param('i', $incidentId);
                $openMessenger->execute();
                $hasOpenMessenger =
                    $openMessenger->get_result()->fetch_row() !== null;
            } finally {
                $openMessenger->close();
            }
            if ($hasOpenMessenger) {
                throw new EstabDvConflictException(
                    'Eine Schichtübergabe ist erst möglich, wenn alle '
                    . 'Melderaufträge zurückgemeldet oder nachvollziehbar '
                    . 'abgebrochen sind.'
                );
            }
            $insert = $connection->prepare(
                'INSERT INTO `nv_dienstuebergabe_anfragen`'
                . ' (`einsatz_id`, `von_dienstschicht_id`,'
                . ' `an_dienstschicht_id`, `zusammenfassung`,'
                . ' `initiiert_von`) VALUES (?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                throw new RuntimeException(
                    'Übergabeanforderung konnte nicht vorbereitet werden.'
                );
            }
            try {
                $insert->bind_param(
                    'iiiss',
                    $incidentId,
                    $fromShiftId,
                    $toShiftId,
                    $summary,
                    $outgoingShape['kuerzel']
                );
                try {
                    $insert->execute();
                } catch (mysqli_sql_exception $exception) {
                    if ((int) $exception->getCode() === 1062) {
                        throw new EstabDvConflictException(
                            'Für eine der Schichten besteht bereits eine '
                            . 'offene Übergabeanforderung.'
                        );
                    }
                    throw $exception;
                }
                $requestId = (int) $connection->insert_id;
            } finally {
                $insert->close();
            }
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Übergabe',
                [
                    'action' => 'shift_handover_initiated',
                    'handover_request_id' => $requestId,
                    'from_shift_id' => $fromShiftId,
                    'to_shift_id' => $toShiftId,
                    'actor' => $adminActor,
                    'admin_actor' => $adminActor,
                    'outgoing_assignment_id' => $outgoingAssignmentId,
                    'outgoing_person' => (string) $outgoingAssignment['benutzer'],
                    'outgoing_code' => $outgoingShape['kuerzel'],
                    'outgoing_function' => $outgoingShape['funktion'],
                    'outgoing_role' => $outgoingShape['rolle'],
                    'confirmation_pending' => true,
                ]
            );
            return $requestId;
        }
    );
}

/** @return list<array<string,mixed>> */
function estab_dv_handover_requests(
    mysqli $connection,
    int $incidentId
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT request.*, old_shift.`nummer` AS `von_nummer`,'
        . ' old_shift.`bezeichnung` AS `von_bezeichnung`,'
        . ' new_shift.`nummer` AS `an_nummer`,'
        . ' new_shift.`bezeichnung` AS `an_bezeichnung`'
        . ' FROM `nv_dienstuebergabe_anfragen` AS request'
        . ' JOIN `nv_dienstschichten` AS old_shift'
        . ' ON old_shift.`dienstschicht_id` ='
        . ' request.`von_dienstschicht_id`'
        . ' JOIN `nv_dienstschichten` AS new_shift'
        . ' ON new_shift.`dienstschicht_id` ='
        . ' request.`an_dienstschicht_id`'
        . ' WHERE request.`einsatz_id` = ?'
        . ' ORDER BY request.`initiiert_am` DESC,'
        . ' request.`dienstuebergabe_anfrage_id` DESC'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Übergabeanforderungen konnten nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('i', $incidentId);
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    } finally {
        $statement->close();
    }
}

/**
 * Return only open handovers that this account can personally confirm.
 *
 * This is the deliberately narrow pre-hat read used by the command-post
 * bootstrap. It must not expose summaries for unrelated shifts before the
 * user has selected an active duty assignment.
 *
 * @return list<array<string,mixed>>
 */
function estab_dv_user_handover_requests(
    mysqli $connection,
    int $incidentId,
    string $userCode
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $userCode = estab_dv_code($userCode);
    $statement = $connection->prepare(
        'SELECT request.*, old_shift.`nummer` AS `von_nummer`,'
        . ' old_shift.`bezeichnung` AS `von_bezeichnung`,'
        . ' new_shift.`nummer` AS `an_nummer`,'
        . ' new_shift.`bezeichnung` AS `an_bezeichnung`'
        . ' FROM `nv_dienstuebergabe_anfragen` AS request'
        . ' JOIN `nv_dienstschichten` AS old_shift'
        . ' ON old_shift.`dienstschicht_id` ='
        . ' request.`von_dienstschicht_id`'
        . ' JOIN `nv_dienstschichten` AS new_shift'
        . ' ON new_shift.`dienstschicht_id` ='
        . ' request.`an_dienstschicht_id`'
        . ' WHERE request.`einsatz_id` = ?'
        . " AND request.`status` = 'INITIIERT'"
        . ' AND EXISTS ('
        . ' SELECT 1 FROM `nv_dienstbesetzungen` AS assignment'
        . ' WHERE assignment.`dienstschicht_id` ='
        . ' request.`an_dienstschicht_id`'
        . ' AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
        . " AND assignment.`status` = 'ANGENOMMEN'"
        . ' )'
        . ' ORDER BY request.`initiiert_am` DESC,'
        . ' request.`dienstuebergabe_anfrage_id` DESC'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Persönliche Übergabeanforderungen konnten nicht vorbereitet '
            . 'werden.'
        );
    }
    try {
        $statement->bind_param('is', $incidentId, $userCode);
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    } finally {
        $statement->close();
    }
}

function estab_dv_cancel_handover_request(
    mysqli $connection,
    int $incidentId,
    int $handoverRequestId,
    mixed $reasonValue,
    string $adminActor,
    string $protocolTable = 'nv_protokoll'
): void {
    $incidentId = estab_incident_positive_id($incidentId);
    $handoverRequestId = estab_dv_positive_id(
        $handoverRequestId,
        'Übergabeanforderung'
    );
    $reason = estab_dv_text(
        $reasonValue,
        'Stornierungsgrund',
        10000
    );
    $adminActor = estab_dv_actor($adminActor);
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $handoverRequestId,
            $reason,
            $adminActor,
            $protocolTable
        ): void {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException(
                    'Der Einsatz ist nicht mehr aktiv.'
                );
            }
            $update = $connection->prepare(
                'UPDATE `nv_dienstuebergabe_anfragen`'
                . " SET `status` = 'STORNIERT', `storniert_am` = NOW(6),"
                . ' `storniert_von` = ?, `stornierungsgrund` = ?'
                . ' WHERE `dienstuebergabe_anfrage_id` = ?'
                . ' AND `einsatz_id` = ?'
                . " AND `status` = 'INITIIERT'"
            );
            if (!$update) {
                throw new RuntimeException(
                    'Übergabeanforderung konnte nicht storniert werden.'
                );
            }
            try {
                $update->bind_param(
                    'ssii',
                    $adminActor,
                    $reason,
                    $handoverRequestId,
                    $incidentId
                );
                if (!$update->execute() || $update->affected_rows !== 1) {
                    throw new EstabDvConflictException(
                        'Nur eine noch unbestätigte Übergabeanforderung kann '
                        . 'storniert werden.'
                    );
                }
            } finally {
                $update->close();
            }
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Übergabe',
                [
                    'action' => 'shift_handover_cancelled',
                    'handover_request_id' => $handoverRequestId,
                    'actor' => $adminActor,
                    'cancellation_reason' => $reason,
                ]
            );
        }
    );
}

function estab_dv_confirm_handover_shift(
    mysqli $connection,
    int $incidentId,
    int $handoverRequestId,
    int $confirmingAssignmentId,
    array $identity,
    string $protocolTable = 'nv_protokoll'
): void {
    $incidentId = estab_incident_positive_id($incidentId);
    $handoverRequestId = estab_dv_positive_id(
        $handoverRequestId,
        'Übergabeanforderung'
    );
    $confirmingAssignmentId = estab_dv_positive_id(
        $confirmingAssignmentId,
        'Bestätigende Dienstbesetzung'
    );
    $shape = estab_auth_session_identity_shape([
        'vStab_benutzer' => $identity['benutzer'] ?? null,
        'vStab_kuerzel' => $identity['kuerzel'] ?? null,
        'vStab_funktion' => $identity['funktion'] ?? null,
        'vStab_rolle' => $identity['rolle'] ?? null,
    ]);
    if ($shape === null) {
        throw new EstabDvPermissionException('Anmeldung erforderlich.');
    }

    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $handoverRequestId,
            $confirmingAssignmentId,
            $shape,
            $protocolTable
        ): void {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException('Der Einsatz ist nicht mehr aktiv.');
            }
            $requestStatement = $connection->prepare(
                'SELECT `von_dienstschicht_id`, `an_dienstschicht_id`,'
                . ' `zusammenfassung`, `initiiert_von`, `status`,'
                . " DATE_FORMAT(`initiiert_am`, '%Y-%m-%d %H:%i:%s.%f')"
                . ' AS `initiiert_am`'
                . ' FROM `nv_dienstuebergabe_anfragen`'
                . ' WHERE `dienstuebergabe_anfrage_id` = ?'
                . ' AND `einsatz_id` = ? FOR UPDATE'
            );
            if (!$requestStatement) {
                throw new RuntimeException(
                    'Übergabeanforderung konnte nicht geprüft werden.'
                );
            }
            try {
                $requestStatement->bind_param(
                    'ii',
                    $handoverRequestId,
                    $incidentId
                );
                $requestStatement->execute();
                $request = $requestStatement->get_result()->fetch_assoc();
            } finally {
                $requestStatement->close();
            }
            if (
                !is_array($request)
                || $request['status'] !== 'INITIIERT'
            ) {
                throw new EstabDvConflictException(
                    'Die Übergabeanforderung ist nicht mehr offen.'
                );
            }
            $fromShiftId = (int) $request['von_dienstschicht_id'];
            $toShiftId = (int) $request['an_dienstschicht_id'];
            $summary = (string) $request['zusammenfassung'];
            $outgoingActor = (string) $request['initiiert_von'];
            $initiatedAt = (string) $request['initiiert_am'];
            $incomingActor = $shape['kuerzel'];
            $select = $connection->prepare(
                'SELECT `dienstschicht_id`, `status`, `vorgaenger_id`'
                . ' FROM `nv_dienstschichten`'
                . ' WHERE `einsatz_id` = ?'
                . ' AND `dienstschicht_id` IN (?, ?) FOR UPDATE'
            );
            if (!$select) {
                throw new RuntimeException('Übergabeschichten konnten nicht gesperrt werden.');
            }
            try {
                $select->bind_param('iii', $incidentId, $fromShiftId, $toShiftId);
                $select->execute();
                $result = $select->get_result();
                $rows = [];
                while (($row = $result->fetch_assoc()) !== null) {
                    $rows[(int) $row['dienstschicht_id']] = $row;
                }
                $result->free();
            } finally {
                $select->close();
            }
            if (
                ($rows[$fromShiftId]['status'] ?? null) !== 'AKTIV'
                || ($rows[$toShiftId]['status'] ?? null) !== 'GEPLANT'
                || (int) ($rows[$toShiftId]['vorgaenger_id'] ?? 0) !== $fromShiftId
            ) {
                throw new EstabDvConflictException(
                    'Die ausgewählten Schichten bilden keine gültige Übergabe.'
                );
            }
            $outgoingConfirmation = $connection->prepare(
                'SELECT 1 FROM `nv_dienstbesetzungen` AS assignment'
                . ' JOIN `nv_benutzer` AS account'
                . ' ON BINARY account.`kuerzel` ='
                . ' BINARY assignment.`benutzer_kuerzel`'
                . ' WHERE assignment.`dienstschicht_id` = ?'
                . " AND assignment.`status` = 'ANGENOMMEN'"
                . ' AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
                . ' AND account.`aktiv` = 1 AND account.`estab_gesperrt` = 0'
                . ' LIMIT 1 FOR UPDATE'
            );
            if (!$outgoingConfirmation) {
                throw new RuntimeException(
                    'Persönliche Übergabe konnte nicht geprüft werden.'
                );
            }
            try {
                $outgoingConfirmation->bind_param(
                    'is',
                    $fromShiftId,
                    $outgoingActor
                );
                $outgoingConfirmation->execute();
                $outgoingStillAssigned = $outgoingConfirmation
                    ->get_result()
                    ->fetch_row() !== null;
            } finally {
                $outgoingConfirmation->close();
            }
            if (!$outgoingStillAssigned) {
                throw new EstabDvPermissionException(
                    'Die persönlich übergebende Person hat keine angenommene '
                    . 'Funktion mehr in der aktiven Schicht.'
                );
            }
            $missing = estab_dv_shift_required_hats($connection, $toShiftId);
            if ($missing !== []) {
                throw new EstabDvConflictException(
                    'Die übernehmende Schicht hat nicht alle Pflichtfunktionen angenommen: '
                    . implode(', ', $missing)
                );
            }
            $openMessenger = $connection->prepare(
                'SELECT `melderauftrag_id` FROM `nv_melderauftraege`'
                . ' WHERE `einsatz_id` = ?'
                . " AND `status` NOT IN ('GEMELDET','ABGEBROCHEN')"
                . ' ORDER BY `melderauftrag_id` LIMIT 1 FOR UPDATE'
            );
            if (!$openMessenger) {
                throw new RuntimeException(
                    'Offene Melderaufträge konnten nicht geprüft werden.'
                );
            }
            try {
                $openMessenger->bind_param('i', $incidentId);
                $openMessenger->execute();
                $hasOpenMessenger =
                    $openMessenger->get_result()->fetch_row() !== null;
            } finally {
                $openMessenger->close();
            }
            if ($hasOpenMessenger) {
                throw new EstabDvConflictException(
                    'Eine Schichtübergabe ist erst möglich, wenn alle '
                    . 'Melderaufträge zurückgemeldet oder nachvollziehbar '
                    . 'abgebrochen sind.'
                );
            }
            $incomingConfirmation = $connection->prepare(
                'SELECT 1 FROM `nv_dienstbesetzungen` AS assignment'
                . ' JOIN `nv_benutzer` AS account'
                . ' ON BINARY account.`kuerzel` ='
                . ' BINARY assignment.`benutzer_kuerzel`'
                . ' WHERE assignment.`dienstbesetzung_id` = ?'
                . ' AND assignment.`dienstschicht_id` = ?'
                . " AND assignment.`status` = 'ANGENOMMEN'"
                . ' AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
                . ' AND account.`aktiv` = 1 AND account.`estab_gesperrt` = 0'
                . ' LIMIT 1 FOR UPDATE'
            );
            if (!$incomingConfirmation) {
                throw new RuntimeException(
                    'Übernahmebestätigung konnte nicht geprüft werden.'
                );
            }
            try {
                $incomingConfirmation->bind_param(
                    'iis',
                    $confirmingAssignmentId,
                    $toShiftId,
                    $incomingActor
                );
                $incomingConfirmation->execute();
                $incomingConfirmed = $incomingConfirmation
                    ->get_result()
                    ->fetch_row() !== null;
            } finally {
                $incomingConfirmation->close();
            }
            if (!$incomingConfirmed) {
                throw new EstabDvPermissionException(
                    'Die Übernahme darf nur ein Konto mit angenommener '
                    . 'Funktion in der Nachfolgeschicht bestätigen.'
                );
            }
            $oldHatStatement = $connection->prepare(
                'SELECT `dienstbesetzung_id`, `benutzer_kuerzel`, `funktion`,'
                . ' `rolle` FROM `nv_dienstbesetzungen`'
                . " WHERE `dienstschicht_id` = ? AND `status` = 'ANGENOMMEN'"
                . ' ORDER BY `dienstbesetzung_id` FOR UPDATE'
            );
            if (!$oldHatStatement) {
                throw new RuntimeException('Abzulösende Besetzungen konnten nicht gelesen werden.');
            }
            try {
                $oldHatStatement->bind_param('i', $fromShiftId);
                $oldHatStatement->execute();
                $oldHatResult = $oldHatStatement->get_result();
                $oldHats = $oldHatResult->fetch_all(MYSQLI_ASSOC);
                $oldHatResult->free();
            } finally {
                $oldHatStatement->close();
            }
            $newHatStatement = $connection->prepare(
                'SELECT assignment.`dienstbesetzung_id`,'
                . ' assignment.`benutzer_kuerzel`, assignment.`funktion`,'
                . ' assignment.`rolle`'
                . ' FROM `nv_dienstbesetzungen` AS assignment'
                . ' JOIN `nv_benutzer` AS account'
                . ' ON BINARY account.`kuerzel` ='
                . ' BINARY assignment.`benutzer_kuerzel`'
                . ' WHERE assignment.`dienstschicht_id` = ?'
                . " AND assignment.`status` = 'ANGENOMMEN'"
                . ' AND account.`estab_gesperrt` = 0'
                . ' ORDER BY assignment.`dienstbesetzung_id` FOR UPDATE'
            );
            if (!$newHatStatement) {
                throw new RuntimeException(
                    'Nachfolgende Besetzungen konnten nicht gelesen werden.'
                );
            }
            try {
                $newHatStatement->bind_param('i', $toShiftId);
                $newHatStatement->execute();
                $newHatResult = $newHatStatement->get_result();
                $newHats = $newHatResult->fetch_all(MYSQLI_ASSOC);
                $newHatResult->free();
            } finally {
                $newHatStatement->close();
            }
            $successorHats = [];
            foreach ($newHats as $newHat) {
                $key = (string) $newHat['funktion']
                    . "\0"
                    . (string) $newHat['rolle'];
                $successorHats[$key][] = $newHat;
            }
            $confirmedAt = estab_dv_database_now($connection);
            $close = $connection->prepare(
                "UPDATE `nv_dienstschichten` SET `status` = 'UEBERGEBEN',"
                . ' `beendet_am` = ?'
                . " WHERE `dienstschicht_id` = ? AND `status` = 'AKTIV'"
            );
            $activate = $connection->prepare(
                "UPDATE `nv_dienstschichten` SET `status` = 'AKTIV',"
                . ' `aktiviert_am` = ?'
                . " WHERE `dienstschicht_id` = ? AND `status` = 'GEPLANT'"
            );
            if (!$close || !$activate) {
                $close?->close();
                $activate?->close();
                throw new RuntimeException('Schichtübergabe konnte nicht vorbereitet werden.');
            }
            try {
                $close->bind_param('si', $confirmedAt, $fromShiftId);
                $activate->bind_param('si', $confirmedAt, $toShiftId);
                if (
                    !$close->execute()
                    || $close->affected_rows !== 1
                    || !$activate->execute()
                    || $activate->affected_rows !== 1
                ) {
                    throw new EstabDvConflictException(
                        'Die Schichten wurden zwischenzeitlich geändert.'
                    );
                }
            } finally {
                $close->close();
                $activate->close();
            }
            $relieve = $connection->prepare(
                "UPDATE `nv_dienstbesetzungen` SET `status` = 'ABGELOEST',"
                . ' `abgeloest_am` = ?, `nachfolger_id` = ?'
                . ' WHERE `dienstbesetzung_id` = ?'
                . " AND `status` = 'ANGENOMMEN'"
            );
            if (!$relieve) {
                throw new RuntimeException('Alte Besetzungen konnten nicht abgelöst werden.');
            }
            try {
                foreach ($oldHats as &$oldHat) {
                    $key = (string) $oldHat['funktion']
                        . "\0"
                        . (string) $oldHat['rolle'];
                    $candidates = $successorHats[$key] ?? [];
                    $successor = $candidates[0] ?? null;
                    foreach ($candidates as $candidate) {
                        if (
                            hash_equals(
                                (string) $oldHat['benutzer_kuerzel'],
                                (string) $candidate['benutzer_kuerzel']
                            )
                        ) {
                            $successor = $candidate;
                            break;
                        }
                    }
                    $successorId = is_array($successor)
                        ? (int) $successor['dienstbesetzung_id']
                        : null;
                    $assignmentId = (int) $oldHat['dienstbesetzung_id'];
                    $relieve->bind_param(
                        'sii',
                        $confirmedAt,
                        $successorId,
                        $assignmentId
                    );
                    if (
                        !$relieve->execute()
                        || $relieve->affected_rows !== 1
                    ) {
                        throw new RuntimeException(
                            'Alte Besetzungen konnten nicht abgelöst werden.'
                        );
                    }
                    $oldHat['successor_assignment_id'] = $successorId;
                    $oldHat['successor_target'] = is_array($successor)
                        ? (string) $successor['benutzer_kuerzel']
                        : null;
                }
                unset($oldHat);
            } finally {
                $relieve->close();
            }
            $pendingHatStatement = $connection->prepare(
                'SELECT `dienstbesetzung_id`, `benutzer_kuerzel`,'
                . ' `funktion`, `rolle` FROM `nv_dienstbesetzungen`'
                . ' WHERE `dienstschicht_id` = ?'
                . " AND `status` = 'ZUGEWIESEN'"
                . ' ORDER BY `dienstbesetzung_id` FOR UPDATE'
            );
            if (!$pendingHatStatement) {
                throw new RuntimeException(
                    'Nicht übernommene Altbesetzungen konnten nicht gelesen werden.'
                );
            }
            try {
                $pendingHatStatement->bind_param('i', $fromShiftId);
                $pendingHatStatement->execute();
                $pendingHatResult = $pendingHatStatement->get_result();
                $pendingOldHats = $pendingHatResult->fetch_all(MYSQLI_ASSOC);
                $pendingHatResult->free();
            } finally {
                $pendingHatStatement->close();
            }
            if ($pendingOldHats !== []) {
                $withdrawPending = $connection->prepare(
                    "UPDATE `nv_dienstbesetzungen`"
                    . " SET `status` = 'ZURUECKGEZOGEN',"
                    . ' `abgeloest_am` = ?'
                    . ' WHERE `dienstschicht_id` = ?'
                    . " AND `status` = 'ZUGEWIESEN'"
                );
                if (!$withdrawPending) {
                    throw new RuntimeException(
                        'Nicht übernommene Altbesetzungen konnten nicht beendet werden.'
                    );
                }
                try {
                    $withdrawPending->bind_param(
                        'si',
                        $confirmedAt,
                        $fromShiftId
                    );
                    if (
                        !$withdrawPending->execute()
                        || $withdrawPending->affected_rows
                            !== count($pendingOldHats)
                    ) {
                        throw new RuntimeException(
                            'Nicht übernommene Altbesetzungen konnten nicht beendet werden.'
                        );
                    }
                } finally {
                    $withdrawPending->close();
                }
            }
            foreach ($oldHats as $oldHat) {
                estab_dv_audit(
                    $connection,
                    $protocolTable,
                    $incidentId,
                    'DV Besetzung',
                    [
                        'action' => 'hat_relieved_by_handover',
                        'assignment_id' => (int) $oldHat['dienstbesetzung_id'],
                        'target' => (string) $oldHat['benutzer_kuerzel'],
                        'function' => (string) $oldHat['funktion'],
                        'role' => (string) $oldHat['rolle'],
                        'actor' => $outgoingActor,
                        'to_shift_id' => $toShiftId,
                        'successor_assignment_id' =>
                            $oldHat['successor_assignment_id'],
                        'successor_target' =>
                            $oldHat['successor_target'],
                    ]
                );
            }
            foreach ($pendingOldHats as $pendingOldHat) {
                estab_dv_audit(
                    $connection,
                    $protocolTable,
                    $incidentId,
                    'DV Besetzung',
                    [
                        'action' => 'hat_withdrawn_by_handover',
                        'assignment_id' =>
                            (int) $pendingOldHat['dienstbesetzung_id'],
                        'target' =>
                            (string) $pendingOldHat['benutzer_kuerzel'],
                        'function' => (string) $pendingOldHat['funktion'],
                        'role' => (string) $pendingOldHat['rolle'],
                        'actor' => $outgoingActor,
                        'to_shift_id' => $toShiftId,
                    ]
                );
            }
            $insert = $connection->prepare(
                'INSERT INTO `nv_dienstuebergaben`'
                . ' (`einsatz_id`, `von_dienstschicht_id`,'
                . ' `an_dienstschicht_id`, `zusammenfassung`,'
                . ' `uebergeben_am`, `uebergeben_von`, `angenommen_von`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                throw new RuntimeException('Übergabenachweis konnte nicht vorbereitet werden.');
            }
            try {
                $insert->bind_param(
                    'iiissss',
                    $incidentId,
                    $fromShiftId,
                    $toShiftId,
                    $summary,
                    $confirmedAt,
                    $outgoingActor,
                    $incomingActor
                );
                if (!$insert->execute()) {
                    throw new RuntimeException('Übergabenachweis konnte nicht gespeichert werden.');
                }
                $handoverId = (int) $connection->insert_id;
            } finally {
                $insert->close();
            }
            $confirmRequest = $connection->prepare(
                'UPDATE `nv_dienstuebergabe_anfragen`'
                . " SET `status` = 'BESTAETIGT',"
                . ' `bestaetigt_am` = ?, `bestaetigt_von` = ?,'
                . ' `bestaetigt_mit_besetzung_id` = ?,'
                . ' `dienstuebergabe_id` = ?'
                . ' WHERE `dienstuebergabe_anfrage_id` = ?'
                . " AND `status` = 'INITIIERT'"
            );
            if (!$confirmRequest) {
                throw new RuntimeException(
                    'Übergabeanforderung konnte nicht bestätigt werden.'
                );
            }
            try {
                $confirmRequest->bind_param(
                    'ssiii',
                    $confirmedAt,
                    $incomingActor,
                    $confirmingAssignmentId,
                    $handoverId,
                    $handoverRequestId
                );
                if (
                    !$confirmRequest->execute()
                    || $confirmRequest->affected_rows !== 1
                ) {
                    throw new EstabDvConflictException(
                        'Die Übergabeanforderung wurde zwischenzeitlich '
                        . 'geändert.'
                    );
                }
            } finally {
                $confirmRequest->close();
            }
            estab_logbook_lifecycle_handover(
                $connection,
                $incidentId,
                $fromShiftId,
                $toShiftId,
                $summary,
                $outgoingActor,
                $incomingActor,
                $initiatedAt,
                $confirmedAt
            );
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Übergabe',
                [
                    'action' => 'shift_handed_over',
                    'handover_id' => $handoverId,
                    'handover_request_id' => $handoverRequestId,
                    'from_shift_id' => $fromShiftId,
                    'to_shift_id' => $toShiftId,
                    'actor' => $outgoingActor,
                    'admin_actor' => $outgoingActor,
                    'confirmed_by_successor' => $incomingActor,
                    'handed_over_at' => $initiatedAt,
                    'taken_over_at' => $confirmedAt,
                ]
            );
        }
    );
}

/**
 * Close an abandoned planned shift or the final active shift.
 *
 * A planned shift may be abandoned after cancelling its handover request. The
 * final active shift may close only when the complete incident-close
 * preflight is clean apart from that shift and its own accepted hats.
 */
function estab_dv_close_shift(
    mysqli $connection,
    int $incidentId,
    int $shiftId,
    string $actor,
    string $protocolTable = 'nv_protokoll',
    ?string $attachmentRoot = null
): void {
    $incidentId = estab_incident_positive_id($incidentId);
    $shiftId = estab_dv_positive_id($shiftId, 'Dienstschicht');
    $actor = estab_dv_actor($actor);
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $shiftId,
            $actor,
            $protocolTable,
            $attachmentRoot
        ): void {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException('Der Einsatz ist nicht mehr aktiv.');
            }
            $shift = $connection->prepare(
                'SELECT `status` FROM `nv_dienstschichten`'
                . ' WHERE `dienstschicht_id` = ? AND `einsatz_id` = ? FOR UPDATE'
            );
            if (!$shift) {
                throw new RuntimeException('Dienstschicht konnte nicht gesperrt werden.');
            }
            try {
                $shift->bind_param('ii', $shiftId, $incidentId);
                $shift->execute();
                $shiftRow = $shift->get_result()->fetch_assoc();
            } finally {
                $shift->close();
            }
            $shiftStatus = (string) ($shiftRow['status'] ?? '');
            if (!in_array($shiftStatus, ['GEPLANT', 'AKTIV'], true)) {
                throw new EstabDvConflictException(
                    'Nur eine geplante oder aktive Schicht kann geschlossen werden.'
                );
            }
            if ($shiftStatus === 'GEPLANT') {
                $pendingRequest = $connection->prepare(
                    'SELECT 1 FROM `nv_dienstuebergabe_anfragen`'
                    . " WHERE `status` = 'INITIIERT'"
                    . ' AND (`von_dienstschicht_id` = ?'
                    . ' OR `an_dienstschicht_id` = ?)'
                    . ' LIMIT 1 FOR UPDATE'
                );
                if (!$pendingRequest) {
                    throw new RuntimeException(
                        'Offene Übergabeanforderung konnte nicht geprüft '
                        . 'werden.'
                    );
                }
                try {
                    $pendingRequest->bind_param('ii', $shiftId, $shiftId);
                    $pendingRequest->execute();
                    $isPending =
                        $pendingRequest->get_result()->fetch_row() !== null;
                } finally {
                    $pendingRequest->close();
                }
                if ($isPending) {
                    throw new EstabDvConflictException(
                        'Stornieren Sie zuerst die offene '
                        . 'Übergabeanforderung.'
                    );
                }
            }
            if ($shiftStatus === 'AKTIV') {
                $followUp = $connection->prepare(
                    'SELECT'
                    . ' (SELECT COUNT(*) FROM `nv_dienstschichten`'
                    . '   WHERE `einsatz_id` = ?'
                    . '     AND `dienstschicht_id` <> ?'
                    . "     AND `status` = 'GEPLANT')"
                    . ' +'
                    . ' (SELECT COUNT(*)'
                    . '    FROM `nv_dienstuebergabe_anfragen`'
                    . '   WHERE `einsatz_id` = ?'
                    . "     AND `status` = 'INITIIERT') AS `anzahl`"
                );
                if (!$followUp) {
                    throw new RuntimeException(
                        'Folge- und Übergabebetrieb konnte nicht geprüft '
                        . 'werden.'
                    );
                }
                try {
                    $followUp->bind_param(
                        'iii',
                        $incidentId,
                        $shiftId,
                        $incidentId
                    );
                    $followUp->execute();
                    $followUpCount = (int) (
                        $followUp->get_result()->fetch_assoc()['anzahl'] ?? 0
                    );
                } finally {
                    $followUp->close();
                }
                if ($followUpCount !== 0) {
                    throw new EstabDvConflictException(
                        'Die aktive Schicht kann nicht geschlossen werden, '
                        . 'solange eine Nachfolgeschicht oder '
                        . 'Übergabeanforderung besteht. Schließen Sie die '
                        . 'Planung oder führen Sie die Übergabe aus.'
                    );
                }
                $openMessenger = $connection->prepare(
                    'SELECT COUNT(*) AS `anzahl` FROM `nv_melderauftraege`'
                    . ' WHERE `einsatz_id` = ?'
                    . " AND `status` NOT IN ('GEMELDET','ABGEBROCHEN') FOR UPDATE"
                );
                if (!$openMessenger) {
                    throw new RuntimeException('Melderstatus konnte nicht geprüft werden.');
                }
                try {
                    $openMessenger->bind_param('i', $incidentId);
                    $openMessenger->execute();
                    $openCount = (int) (
                        $openMessenger->get_result()->fetch_assoc()['anzahl'] ?? 0
                    );
                } finally {
                    $openMessenger->close();
                }
                if ($openCount !== 0) {
                    throw new EstabDvConflictException(
                        'Die Schicht kann mit offenen Melderaufträgen nicht schließen.'
                    );
                }
                $preflight = estab_incident_close_preflight(
                    $connection,
                    $incidentId,
                    $attachmentRoot,
                    $shiftId
                );
                if (!$preflight['closable']) {
                    $labels = [
                        'open_messages' => 'offene Nachrichten',
                        'locked_messages' => 'gesperrte Nachrichten',
                        'incomplete_attachments' =>
                            'unfertige Anhangvorgänge',
                        'attachment_integrity_errors' =>
                            'Anhang-Integritätsfehler',
                        'evidence_errors' => 'Nachweisfehler',
                        'offene_schichten' => 'weitere offene Schichten',
                        'offene_besetzungen' =>
                            'weitere offene Besetzungen',
                        'offene_melderauftraege' =>
                            'offene Melderaufträge',
                        'offene_fernmeldeplanentwuerfe' =>
                            'offene Fernmeldeplanentwürfe',
                        'offene_uebergabeanforderungen' =>
                            'offene Übergabeanforderungen',
                    ];
                    $open = [];
                    if (!$preflight['logbuecher_eroeffnet']) {
                        $open[] = 'ETB/TBB nicht eröffnet';
                    }
                    foreach ($labels as $key => $label) {
                        $count = (int) ($preflight[$key] ?? 0);
                        if ($count > 0) {
                            $open[] = $label . ': ' . $count;
                        }
                    }
                    throw new EstabDvConflictException(
                        'Die letzte aktive Schicht kann erst nach Abschluss '
                        . 'aller fachlichen Vorgänge geschlossen werden'
                        . ($open === []
                            ? '.'
                            : ': ' . implode(', ', $open) . '.')
                    );
                }
            }
            $hatStatement = $connection->prepare(
                'SELECT `dienstbesetzung_id`, `benutzer_kuerzel`, `funktion`,'
                . ' `rolle`, `status` FROM `nv_dienstbesetzungen`'
                . ' WHERE `dienstschicht_id` = ?'
                . " AND `status` IN ('ZUGEWIESEN','ANGENOMMEN')"
                . ' ORDER BY `dienstbesetzung_id` FOR UPDATE'
            );
            if (!$hatStatement) {
                throw new RuntimeException('Schichtbesetzungen konnten nicht gelesen werden.');
            }
            try {
                $hatStatement->bind_param('i', $shiftId);
                $hatStatement->execute();
                $hatResult = $hatStatement->get_result();
                $hats = $hatResult->fetch_all(MYSQLI_ASSOC);
                $hatResult->free();
            } finally {
                $hatStatement->close();
            }
            $closeHats = $connection->prepare(
                "UPDATE `nv_dienstbesetzungen` SET `status` ="
                . " CASE WHEN `status` = 'ANGENOMMEN'"
                . " THEN 'ABGELOEST' ELSE 'ZURUECKGEZOGEN' END,"
                . ' `abgeloest_am` = NOW(6)'
                . ' WHERE `dienstschicht_id` = ?'
                . " AND `status` IN ('ZUGEWIESEN','ANGENOMMEN')"
            );
            if (!$closeHats) {
                throw new RuntimeException('Schichtbesetzungen konnten nicht beendet werden.');
            }
            try {
                $closeHats->bind_param('i', $shiftId);
                if (!$closeHats->execute()) {
                    throw new RuntimeException('Schichtbesetzungen konnten nicht beendet werden.');
                }
            } finally {
                $closeHats->close();
            }
            foreach ($hats as $hat) {
                estab_dv_audit(
                    $connection,
                    $protocolTable,
                    $incidentId,
                    'DV Besetzung',
                    [
                        'action' => (string) $hat['status'] === 'ANGENOMMEN'
                            ? 'hat_relieved_at_shift_close'
                            : 'hat_withdrawn_at_shift_close',
                        'assignment_id' => (int) $hat['dienstbesetzung_id'],
                        'target' => (string) $hat['benutzer_kuerzel'],
                        'function' => (string) $hat['funktion'],
                        'role' => (string) $hat['rolle'],
                        'actor' => $actor,
                        'shift_id' => $shiftId,
                    ]
                );
            }
            $closeShift = $connection->prepare(
                "UPDATE `nv_dienstschichten` SET `status` = 'GESCHLOSSEN',"
                . ' `beendet_am` = NOW(6)'
                . ' WHERE `dienstschicht_id` = ?'
                . " AND `status` IN ('GEPLANT','AKTIV')"
            );
            if (!$closeShift) {
                throw new RuntimeException('Schichtschluss konnte nicht vorbereitet werden.');
            }
            try {
                $closeShift->bind_param('i', $shiftId);
                if (!$closeShift->execute() || $closeShift->affected_rows !== 1) {
                    throw new EstabDvConflictException(
                        'Die Schicht wurde zwischenzeitlich geändert.'
                    );
                }
            } finally {
                $closeShift->close();
            }
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Dienstschicht',
                [
                    'action' => 'shift_closed',
                    'shift_id' => $shiftId,
                    'old_status' => $shiftStatus,
                    'actor' => $actor,
                ]
            );
        }
    );
}

/** @return list<array<string,mixed>> */
function estab_dv_user_hats(
    mysqli $connection,
    int $incidentId,
    string $userCode,
    bool $activeShiftOnly = false
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $userCode = estab_dv_code($userCode);
    $statusClause = $activeShiftOnly
        ? " AND s.`status` = 'AKTIV' AND b.`status` = 'ANGENOMMEN'"
        : " AND s.`status` IN ('GEPLANT','AKTIV')"
            . " AND b.`status` IN ('ZUGEWIESEN','ANGENOMMEN')";
    $statement = $connection->prepare(
        'SELECT b.`dienstbesetzung_id`, b.`funktion`, b.`rolle`, b.`status`,'
        . ' s.`dienstschicht_id`, s.`nummer`, s.`bezeichnung`,'
        . ' s.`status` AS `schicht_status`'
        . ' FROM `nv_dienstbesetzungen` AS b'
        . ' JOIN `nv_dienstschichten` AS s'
        . ' ON s.`dienstschicht_id` = b.`dienstschicht_id`'
        . ' WHERE s.`einsatz_id` = ? AND b.`benutzer_kuerzel` = ?'
        . $statusClause
        . ' ORDER BY s.`nummer` DESC, b.`funktion`'
    );
    if (!$statement) {
        throw new RuntimeException('Eigene Dienstbesetzungen konnten nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('is', $incidentId, $userCode);
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    } finally {
        $statement->close();
    }
}

function estab_dv_user_has_active_hat(
    mysqli $connection,
    int $incidentId,
    string $userCode,
    string $function
): bool {
    $userCode = estab_dv_code($userCode);
    $statement = $connection->prepare(
        'SELECT 1 FROM `nv_dienstbesetzungen` AS b'
        . ' JOIN `nv_dienstschichten` AS s'
        . ' ON s.`dienstschicht_id` = b.`dienstschicht_id`'
        . " WHERE s.`einsatz_id` = ? AND s.`status` = 'AKTIV'"
        . " AND b.`status` = 'ANGENOMMEN'"
        . ' AND b.`benutzer_kuerzel` = ? AND BINARY b.`funktion` = BINARY ?'
        . ' LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException('Dienstfunktion konnte nicht geprüft werden.');
    }
    try {
        $statement->bind_param('iss', $incidentId, $userCode, $function);
        $statement->execute();
        $result = $statement->get_result();
        $found = $result->fetch_row() !== null;
        $result->free();
        return $found;
    } finally {
        $statement->close();
    }
}

/**
 * A dispatched messenger is unavailable for every unrelated operational task.
 *
 * DV 1-101 treats the messenger as bound to the transport from personal
 * acceptance until the return to the command post. Locking the matching row
 * makes the check serialize with the lifecycle transition: a concurrent
 * return either completes first and releases the account, or the unrelated
 * write is rejected while the messenger is still away.
 */
function estab_dv_require_messenger_available_for_operational_write(
    mysqli $connection,
    int $incidentId,
    string $userCode
): void {
    $incidentId = estab_incident_positive_id($incidentId);
    $userCode = estab_dv_code($userCode);
    $statement = $connection->prepare(
        'SELECT `melderauftrag_id`, `status` FROM `nv_melderauftraege`'
        . ' WHERE `einsatz_id` = ? AND BINARY `melder_kuerzel` = BINARY ?'
        . " AND `status` IN ('UEBERNOMMEN','UEBERGEBEN','RUECKWEG')"
        . ' FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException('Melderverfügbarkeit konnte nicht geprüft werden.');
    }
    try {
        $statement->bind_param('is', $incidentId, $userCode);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (is_array($row)) {
        throw new EstabDvPermissionException(
            'Während eines übernommenen Melderauftrags sind keine anderen '
            . 'operativen Eingaben möglich. Melden Sie sich nach der '
            . 'Rückkehr zuerst in der Führungsstelle zurück.'
        );
    }
}

/**
 * Enforce the duty-hat boundary at every normal operational write.
 *
 * The exact assignment selected in the authenticated session must belong to
 * the same active incident and shift, remain personally accepted, match the
 * complete account/function/role identity and belong to an active, unblocked
 * account. A merely matching primary account tuple is deliberately
 * insufficient. Reads, login, duty lifecycle and independently authenticated
 * administration intentionally do not call this guard.
 */
function estab_dv_require_active_hat_for_operational_write(
    mysqli $connection,
    int $incidentId,
    array $identity
): void {
    $incidentId = estab_incident_positive_id($incidentId);
    $shape = estab_auth_session_identity_shape([
        'vStab_benutzer' => $identity['benutzer'] ?? null,
        'vStab_kuerzel' => $identity['kuerzel'] ?? null,
        'vStab_funktion' => $identity['funktion'] ?? null,
        'vStab_rolle' => $identity['rolle'] ?? null,
    ]);
    if ($shape === null) {
        throw new EstabDvPermissionException('Anmeldung erforderlich.');
    }
    $assignmentValue = $identity['duty_assignment_id'] ?? null;
    if (is_int($assignmentValue) && $assignmentValue > 0) {
        $assignmentId = $assignmentValue;
    } elseif (
        is_string($assignmentValue)
        && preg_match('/\A[1-9][0-9]{0,18}\z/D', $assignmentValue) === 1
    ) {
        $parsedAssignment = filter_var(
            $assignmentValue,
            FILTER_VALIDATE_INT
        );
        $assignmentId = is_int($parsedAssignment)
            && $parsedAssignment > 0
            ? $parsedAssignment
            : 0;
    } else {
        $assignmentId = 0;
    }
    if ($assignmentId < 1) {
        throw new EstabDvPermissionException(
            'Wählen Sie vor dieser Eingabe eine persönlich angenommene '
            . 'Dienstfunktion aus.'
        );
    }

    $statement = $connection->prepare(
        'SELECT 1 FROM `nv_dienstbesetzungen` AS assignment'
        . ' JOIN `nv_dienstschichten` AS duty_shift'
        . ' ON duty_shift.`dienstschicht_id`'
        . ' = assignment.`dienstschicht_id`'
        . ' JOIN `nv_einsatz_status` AS active_incident'
        . ' ON active_incident.`singleton_id` = 1'
        . ' AND active_incident.`active_einsatz_id`'
        . ' = duty_shift.`einsatz_id`'
        . ' JOIN `nv_einsaetze` AS incident'
        . ' ON incident.`einsatz_id` = duty_shift.`einsatz_id`'
        . ' JOIN `nv_benutzer` AS account'
        . ' ON BINARY account.`kuerzel`'
        . ' = BINARY assignment.`benutzer_kuerzel`'
        . ' WHERE assignment.`dienstbesetzung_id` = ?'
        . ' AND duty_shift.`einsatz_id` = ?'
        . " AND duty_shift.`status` = 'AKTIV'"
        . " AND assignment.`status` = 'ANGENOMMEN'"
        . " AND incident.`estab_status` = 'open'"
        . ' AND BINARY account.`benutzer` = BINARY ?'
        . ' AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
        . ' AND BINARY assignment.`funktion` = BINARY ?'
        . ' AND BINARY assignment.`rolle` = BINARY ?'
        . ' AND account.`aktiv` = 1'
        . ' AND account.`estab_gesperrt` = 0'
        . ' LIMIT 1 FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Ausgewählte Dienstbesetzung konnte nicht geprüft werden.'
        );
    }
    try {
        $statement->bind_param(
            'iissss',
            $assignmentId,
            $incidentId,
            $shape['benutzer'],
            $shape['kuerzel'],
            $shape['funktion'],
            $shape['rolle']
        );
        $statement->execute();
        $valid = $statement->get_result()->fetch_row() !== null;
    } finally {
        $statement->close();
    }
    if (!$valid) {
        throw new EstabDvPermissionException(
            'Die ausgewählte Dienstfunktion ist für diese Eingabe nicht mehr '
            . 'aktiv, angenommen oder Ihrem Konto zugeordnet.'
        );
    }
    estab_dv_require_messenger_available_for_operational_write(
        $connection,
        $incidentId,
        $shape['kuerzel']
    );
}

/**
 * Require a capability assigned to the exact active function/role tuple.
 *
 * The active-hat guard binds the account to its personally accepted duty;
 * the capability map then applies the Fachzuständigkeit of that exact hat.
 */
function estab_dv_require_active_capability_for_operational_write(
    mysqli $connection,
    int $incidentId,
    array $identity,
    string $capability
): void {
    $incidentId = estab_incident_positive_id($incidentId);
    if (preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/D', $capability) !== 1) {
        throw new InvalidArgumentException('Ungültige Funktionsfähigkeit.');
    }
    estab_dv_require_active_hat_for_operational_write(
        $connection,
        $incidentId,
        $identity
    );
    $shape = estab_auth_session_identity_shape([
        'vStab_benutzer' => $identity['benutzer'] ?? null,
        'vStab_kuerzel' => $identity['kuerzel'] ?? null,
        'vStab_funktion' => $identity['funktion'] ?? null,
        'vStab_rolle' => $identity['rolle'] ?? null,
    ]);
    if ($shape === null) {
        throw new EstabDvPermissionException('Anmeldung erforderlich.');
    }
    $statement = $connection->prepare(
        'SELECT 1 FROM `nv_funktionsfaehigkeiten`'
        . ' WHERE BINARY `funktion` = BINARY ?'
        . ' AND BINARY `rolle` = BINARY ?'
        . ' AND BINARY `faehigkeit` = BINARY ? LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Funktionsfähigkeit konnte nicht geprüft werden.'
        );
    }
    try {
        $statement->bind_param(
            'sss',
            $shape['funktion'],
            $shape['rolle'],
            $capability
        );
        $statement->execute();
        $allowed = $statement->get_result()->fetch_row() !== null;
    } finally {
        $statement->close();
    }
    if (!$allowed) {
        throw new EstabDvPermissionException(
            'Die aktive Dienstfunktion besitzt nicht die erforderliche '
            . 'Fachzuständigkeit ' . $capability . '.'
        );
    }
}

/**
 * Require the exact duty assignment selected in the authenticated session.
 *
 * A user may hold multiple accepted hats. Privileged actions must therefore
 * bind not only the account code but also the selected assignment id and its
 * exact function/role capability.
 *
 * @return array{benutzer:string,kuerzel:string,funktion:string,rolle:string,
 *   duty_assignment_id:int}
 */
function estab_dv_require_selected_capability(
    mysqli $connection,
    int $incidentId,
    array $identity,
    string $capability
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $assignmentValue = $identity['duty_assignment_id'] ?? null;
    if (
        is_int($assignmentValue)
        && $assignmentValue > 0
    ) {
        $assignmentId = $assignmentValue;
    } elseif (
        is_string($assignmentValue)
        && preg_match('/\A[1-9][0-9]{0,18}\z/D', $assignmentValue) === 1
    ) {
        $parsed = filter_var($assignmentValue, FILTER_VALIDATE_INT);
        $assignmentId = is_int($parsed) && $parsed > 0 ? $parsed : 0;
    } else {
        $assignmentId = 0;
    }
    if ($assignmentId < 1) {
        throw new EstabDvPermissionException(
            'Wählen Sie zuerst die persönlich angenommene Dienstfunktion '
            . 'für diese Aktion aus.'
        );
    }
    $shape = estab_auth_session_identity_shape([
        'vStab_benutzer' => $identity['benutzer'] ?? null,
        'vStab_kuerzel' => $identity['kuerzel'] ?? null,
        'vStab_funktion' => $identity['funktion'] ?? null,
        'vStab_rolle' => $identity['rolle'] ?? null,
    ]);
    if (
        $shape === null
        || preg_match('/\A[A-Z][A-Z0-9_]{2,63}\z/D', $capability) !== 1
    ) {
        throw new EstabDvPermissionException(
            'Die ausgewählte Dienstfunktion ist ungültig.'
        );
    }
    $statement = $connection->prepare(
        'SELECT 1 FROM `nv_dienstbesetzungen` AS assignment'
        . ' JOIN `nv_dienstschichten` AS shift_row'
        . ' ON shift_row.`dienstschicht_id` = assignment.`dienstschicht_id`'
        . ' JOIN `nv_benutzer` AS account'
        . ' ON BINARY account.`kuerzel` = BINARY assignment.`benutzer_kuerzel`'
        . ' JOIN `nv_funktionsfaehigkeiten` AS capability'
        . ' ON BINARY capability.`funktion` = BINARY assignment.`funktion`'
        . ' AND BINARY capability.`rolle` = BINARY assignment.`rolle`'
        . ' WHERE assignment.`dienstbesetzung_id` = ?'
        . ' AND shift_row.`einsatz_id` = ?'
        . " AND shift_row.`status` = 'AKTIV'"
        . " AND assignment.`status` = 'ANGENOMMEN'"
        . ' AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
        . ' AND BINARY assignment.`funktion` = BINARY ?'
        . ' AND BINARY assignment.`rolle` = BINARY ?'
        . ' AND BINARY capability.`faehigkeit` = BINARY ?'
        . ' AND account.`aktiv` = 1 AND account.`estab_gesperrt` = 0'
        . ' LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Ausgewählte Dienstfunktion konnte nicht geprüft werden.'
        );
    }
    try {
        $statement->bind_param(
            'iissss',
            $assignmentId,
            $incidentId,
            $shape['kuerzel'],
            $shape['funktion'],
            $shape['rolle'],
            $capability
        );
        $statement->execute();
        $allowed = $statement->get_result()->fetch_row() !== null;
    } finally {
        $statement->close();
    }
    if (!$allowed) {
        throw new EstabDvPermissionException(
            'Die aktuell ausgewählte Dienstfunktion besitzt nicht die '
            . 'erforderliche Fachzuständigkeit ' . $capability . '.'
        );
    }
    return $shape + ['duty_assignment_id' => $assignmentId];
}

function estab_dv_has_selected_capability(
    mysqli $connection,
    int $incidentId,
    array $identity,
    string $capability
): bool {
    try {
        estab_dv_require_selected_capability(
            $connection,
            $incidentId,
            $identity,
            $capability
        );
        return true;
    } catch (EstabDvPermissionException) {
        return false;
    }
}

/**
 * Select a server-validated accepted function hat for the current PHP session.
 *
 * The account's primary administrative assignment remains untouched. The
 * returned identity is accepted by app/auth.php only while this exact
 * assignment belongs to the active shift and incident.
 */
function estab_dv_select_session_hat(
    mysqli $connection,
    array &$session,
    int $incidentId,
    int $assignmentId,
    string $protocolTable = 'nv_protokoll',
    string $matrixTable = 'nv_empfmtx',
    string $userTablePrefix = 'usr_'
): array {
    $baseIdentity = estab_auth_current_session_identity($session);
    if ($baseIdentity === null) {
        throw new EstabDvPermissionException('Anmeldung erforderlich.');
    }
    $incidentId = estab_incident_positive_id($incidentId);
    $assignmentId = estab_dv_positive_id($assignmentId, 'Dienstbesetzung');
    $prepared = estab_dv_prepare_assignment_schema(
        $connection,
        $incidentId,
        $assignmentId,
        (string) $baseIdentity['kuerzel'],
        'ANGENOMMEN',
        'AKTIV',
        true,
        $matrixTable,
        $userTablePrefix
    );
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Dienstfunktionswechsel konnte nicht begonnen werden.');
    }
    try {
        $incident = estab_dv_require_incident(
            $connection,
            $incidentId,
            true
        );
        estab_incident_lock_command_post_for_write($connection, $incident);
        $statement = $connection->prepare(
            'SELECT b.`dienstbesetzung_id`, b.`benutzer_kuerzel`, b.`funktion`,'
            . ' b.`rolle` FROM `nv_dienstbesetzungen` AS b'
            . ' JOIN `nv_dienstschichten` AS s'
            . ' ON s.`dienstschicht_id` = b.`dienstschicht_id`'
            . ' JOIN `nv_benutzer` AS u'
            . ' ON BINARY u.`kuerzel` = BINARY b.`benutzer_kuerzel`'
            . " WHERE b.`dienstbesetzung_id` = ?"
            . " AND b.`status` = 'ANGENOMMEN'"
            . " AND s.`einsatz_id` = ? AND s.`status` = 'AKTIV'"
            . ' AND BINARY b.`funktion` = BINARY ?'
            . ' AND BINARY b.`rolle` = BINARY ?'
            . ' AND u.`aktiv` = 1 AND u.`estab_gesperrt` = 0'
            . ' LIMIT 1 FOR UPDATE'
        );
        if (!$statement) {
            throw new RuntimeException('Dienstfunktion konnte nicht vorbereitet werden.');
        }
        try {
            $statement->bind_param(
                'iiss',
                $assignmentId,
                $incidentId,
                $prepared['funktion'],
                $prepared['rolle']
            );
            $statement->execute();
            $row = $statement->get_result()->fetch_assoc();
        } finally {
            $statement->close();
        }
        if (
            !is_array($row)
            || !hash_equals(
                (string) $baseIdentity['kuerzel'],
                (string) $row['benutzer_kuerzel']
            )
        ) {
            throw new EstabDvPermissionException(
                'Diese aktive Dienstfunktion gehört nicht zu Ihrem Konto.'
            );
        }
        $oldFunction = (string) $baseIdentity['funktion'];
        estab_dv_audit(
            $connection,
            $protocolTable,
            $incidentId,
            'DV Besetzung',
            [
                'action' => 'active_hat_selected',
                'assignment_id' => $assignmentId,
                'target' => (string) $baseIdentity['kuerzel'],
                'old_function' => $oldFunction,
                'new_function' => (string) $row['funktion'],
                'new_role' => (string) $row['rolle'],
            ]
        );
        if (!$connection->commit()) {
            throw new RuntimeException(
                'Dienstfunktionswechsel konnte nicht gespeichert werden.'
            );
        }
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
    $session['vStab_funktion'] = (string) $row['funktion'];
    $session['vStab_rolle'] = (string) $row['rolle'];
    $session['ROLLE'] = (string) $row['rolle'];
    $session['estab_duty_assignment_id'] = $assignmentId;
    // Second-sighting modes belong to one exact duty function. Carrying a
    // mode across a hat change can suppress the new function's normal queue
    // or route it into a foreign privileged list.
    $session['fm_zweite_sichtung'] = 0;
    $session['si_zweite_sichtung'] = 0;
    return [
        'benutzer' => (string) $baseIdentity['benutzer'],
        'kuerzel' => (string) $baseIdentity['kuerzel'],
        'funktion' => (string) $row['funktion'],
        'rolle' => (string) $row['rolle'],
    ];
}

function estab_dv_create_telecom_plan(
    mysqli $connection,
    int $incidentId,
    array $identity,
    array $input,
    string $protocolTable = 'nv_protokoll'
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $origin = estab_dv_text($input['herkunft'] ?? null, 'Herkunft', 255);
    $validFrom = estab_dv_datetime($input['gueltig_ab'] ?? null, 'Gültigkeitsbeginn');
    $validUntil = estab_dv_datetime(
        $input['gueltig_bis'] ?? null,
        'Gültigkeitsende',
        true
    );
    if ($validUntil !== null && $validUntil <= $validFrom) {
        throw new EstabDvInputException(
            'Das Gültigkeitsende muss nach dem Gültigkeitsbeginn liegen.'
        );
    }
    $operationsLead = estab_dv_text(
        $input['betriebsleitung'] ?? null,
        'Betriebsleitung',
        255
    );
    $notes = estab_dv_text(
        $input['bemerkungen'] ?? '',
        'Bemerkungen',
        10000,
        true
    );
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $identity,
            $origin,
            $validFrom,
            $validUntil,
            $operationsLead,
            $notes,
            $protocolTable
        ): array {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException('Der Einsatz ist nicht mehr aktiv.');
            }
            $selected = estab_dv_require_selected_capability(
                $connection,
                $incidentId,
                $identity,
                'FERNMELDEPLANUNG'
            );
            $userCode = $selected['kuerzel'];
            $versionResult = $connection->query(
                'SELECT COALESCE(MAX(`version`), 0) + 1 AS `version`'
                . ' FROM `nv_fernmeldeplaene`'
                . ' WHERE `einsatz_id` = ' . $incidentId
                . ' FOR UPDATE'
            );
            if (!$versionResult) {
                throw new RuntimeException('Planversion konnte nicht reserviert werden.');
            }
            $version = (int) ($versionResult->fetch_assoc()['version'] ?? 0);
            $versionResult->free();
            $incidentLabel = trim(
                (string) ($incident['kennung'] ?? '')
                . ' · ' . (string) ($incident['name'] ?? '')
            );
            $insert = $connection->prepare(
                'INSERT INTO `nv_fernmeldeplaene`'
                . ' (`einsatz_id`, `version`, `einsatzbezeichnung`, `herkunft`,'
                . ' `gueltig_ab`, `gueltig_bis`, `betriebsleitung`,'
                . ' `bemerkungen`, `erstellt_von`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                throw new RuntimeException('Fernmeldeplan konnte nicht vorbereitet werden.');
            }
            try {
                $insert->bind_param(
                    'iisssssss',
                    $incidentId,
                    $version,
                    $incidentLabel,
                    $origin,
                    $validFrom,
                    $validUntil,
                    $operationsLead,
                    $notes,
                    $userCode
                );
                if (!$insert->execute()) {
                    throw new RuntimeException('Fernmeldeplan konnte nicht angelegt werden.');
                }
                $planId = (int) $connection->insert_id;
            } finally {
                $insert->close();
            }
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Fernmeldeplan',
                [
                    'action' => 'plan_created',
                    'plan_id' => $planId,
                    'plan_version' => $version,
                    'actor' => $userCode,
                ]
            );
            return ['fernmeldeplan_id' => $planId, 'version' => $version];
        }
    );
}

function estab_dv_add_telecom_entry(
    mysqli $connection,
    int $incidentId,
    int $planId,
    array $identity,
    array $input,
    string $protocolTable = 'nv_protokoll'
): int {
    $incidentId = estab_incident_positive_id($incidentId);
    $planId = estab_dv_positive_id($planId, 'Fernmeldeplan');
    $station = estab_dv_text($input['betriebsstelle'] ?? null, 'Betriebsstelle', 255);
    $callSign = estab_dv_text($input['rufname'] ?? null, 'Rufname', 128);
    $mediumValue = $input['medium'] ?? null;
    if (!is_string($mediumValue) || !in_array($mediumValue, ESTAB_DV_MEDIA, true)) {
        throw new EstabDvInputException('Medium ist ungültig.');
    }
    $channel = estab_dv_text($input['kanal'] ?? null, 'Kanal', 64);
    $band = estab_dv_text($input['bandlage'] ?? null, 'Bandlage', 64);
    $traffic = estab_dv_text($input['verkehrsform'] ?? null, 'Verkehrsform', 128);
    $special = estab_dv_text(
        $input['besondere_vermerke'] ?? '',
        'Besondere Vermerke',
        10000,
        true
    );
    $notes = estab_dv_text($input['bemerkungen'] ?? '', 'Bemerkungen', 10000, true);
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $planId,
            $identity,
            $station,
            $callSign,
            $mediumValue,
            $channel,
            $band,
            $traffic,
            $special,
            $notes,
            $protocolTable
        ): int {
            $selected = estab_dv_require_selected_capability(
                $connection,
                $incidentId,
                $identity,
                'FERNMELDEPLANUNG'
            );
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException(
                    'Der Einsatz ist nicht mehr aktiv.'
                );
            }
            $userCode = $selected['kuerzel'];
            $plan = $connection->prepare(
                'SELECT `status` FROM `nv_fernmeldeplaene`'
                . ' WHERE `fernmeldeplan_id` = ? AND `einsatz_id` = ?'
                . ' FOR UPDATE'
            );
            if (!$plan) {
                throw new RuntimeException('Fernmeldeplan konnte nicht geprüft werden.');
            }
            try {
                $plan->bind_param('ii', $planId, $incidentId);
                $plan->execute();
                $planRow = $plan->get_result()->fetch_assoc();
            } finally {
                $plan->close();
            }
            if (!is_array($planRow) || $planRow['status'] !== 'ENTWURF') {
                throw new EstabDvConflictException(
                    'Nur ein Planentwurf darf ergänzt werden.'
                );
            }
            $next = $connection->prepare(
                'SELECT COALESCE(MAX(`sortierung`), 0) + 1 AS `sortierung`'
                . ' FROM `nv_fernmeldeplan_eintraege`'
                . ' WHERE `fernmeldeplan_id` = ? FOR UPDATE'
            );
            if (!$next) {
                throw new RuntimeException('Planposition konnte nicht reserviert werden.');
            }
            try {
                $next->bind_param('i', $planId);
                $next->execute();
                $sort = (int) ($next->get_result()->fetch_assoc()['sortierung'] ?? 0);
            } finally {
                $next->close();
            }
            $insert = $connection->prepare(
                'INSERT INTO `nv_fernmeldeplan_eintraege`'
                . ' (`fernmeldeplan_id`, `sortierung`, `betriebsstelle`,'
                . ' `rufname`, `medium`, `kanal`, `bandlage`, `verkehrsform`,'
                . ' `besondere_vermerke`, `bemerkungen`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                throw new RuntimeException('Planposition konnte nicht vorbereitet werden.');
            }
            try {
                $insert->bind_param(
                    'iissssssss',
                    $planId,
                    $sort,
                    $station,
                    $callSign,
                    $mediumValue,
                    $channel,
                    $band,
                    $traffic,
                    $special,
                    $notes
                );
                if (!$insert->execute()) {
                    throw new RuntimeException('Planposition konnte nicht gespeichert werden.');
                }
                $entryId = (int) $connection->insert_id;
            } finally {
                $insert->close();
            }
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Fernmeldeplan',
                [
                    'action' => 'plan_entry_added',
                    'plan_id' => $planId,
                    'entry_id' => $entryId,
                    'actor' => $userCode,
                ]
            );
            return $entryId;
        }
    );
}

function estab_dv_activate_telecom_plan(
    mysqli $connection,
    int $incidentId,
    int $planId,
    array $identity,
    string $protocolTable = 'nv_protokoll'
): void {
    $incidentId = estab_incident_positive_id($incidentId);
    $planId = estab_dv_positive_id($planId, 'Fernmeldeplan');
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $planId,
            $identity,
            $protocolTable
        ): void {
            $selected = estab_dv_require_selected_capability(
                $connection,
                $incidentId,
                $identity,
                'FERNMELDEPLANUNG'
            );
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException(
                    'Der Einsatz ist nicht mehr aktiv.'
                );
            }
            $userCode = $selected['kuerzel'];
            $select = $connection->prepare(
                'SELECT p.`status`, COUNT(e.`fernmeldeplan_eintrag_id`) AS `eintraege`'
                . ' FROM `nv_fernmeldeplaene` AS p'
                . ' LEFT JOIN `nv_fernmeldeplan_eintraege` AS e'
                . ' ON e.`fernmeldeplan_id` = p.`fernmeldeplan_id`'
                . ' WHERE p.`fernmeldeplan_id` = ? AND p.`einsatz_id` = ?'
                . ' GROUP BY p.`fernmeldeplan_id`, p.`status` FOR UPDATE'
            );
            if (!$select) {
                throw new RuntimeException('Fernmeldeplan konnte nicht geprüft werden.');
            }
            try {
                $select->bind_param('ii', $planId, $incidentId);
                $select->execute();
                $row = $select->get_result()->fetch_assoc();
            } finally {
                $select->close();
            }
            if (
                !is_array($row)
                || $row['status'] !== 'ENTWURF'
                || (int) $row['eintraege'] < 1
            ) {
                throw new EstabDvConflictException(
                    'Nur ein nicht leerer Planentwurf kann freigegeben werden.'
                );
            }
            $previousPlanStatement = $connection->prepare(
                'SELECT `fernmeldeplan_id`, `version`, `erstellt_von`'
                . ' FROM `nv_fernmeldeplaene`'
                . " WHERE `einsatz_id` = ? AND `status` = 'AKTIV' FOR UPDATE"
            );
            if (!$previousPlanStatement) {
                throw new RuntimeException('Bisheriger Fernmeldeplan konnte nicht gelesen werden.');
            }
            try {
                $previousPlanStatement->bind_param('i', $incidentId);
                $previousPlanStatement->execute();
                $previousPlan = $previousPlanStatement
                    ->get_result()
                    ->fetch_assoc();
            } finally {
                $previousPlanStatement->close();
            }
            $supersede = $connection->prepare(
                "UPDATE `nv_fernmeldeplaene` SET `status` = 'ERSETZT'"
                . " WHERE `einsatz_id` = ? AND `status` = 'AKTIV'"
            );
            $activate = $connection->prepare(
                "UPDATE `nv_fernmeldeplaene` SET `status` = 'AKTIV',"
                . ' `freigegeben_am` = NOW(6), `freigegeben_von` = ?'
                . " WHERE `fernmeldeplan_id` = ? AND `einsatz_id` = ?"
                . " AND `status` = 'ENTWURF'"
            );
            if (!$supersede || !$activate) {
                $supersede?->close();
                $activate?->close();
                throw new RuntimeException('Planfreigabe konnte nicht vorbereitet werden.');
            }
            try {
                $supersede->bind_param('i', $incidentId);
                $activate->bind_param('sii', $userCode, $planId, $incidentId);
                if (
                    !$supersede->execute()
                    || !$activate->execute()
                    || $activate->affected_rows !== 1
                ) {
                    throw new EstabDvConflictException(
                        'Der Fernmeldeplan wurde zwischenzeitlich geändert.'
                    );
                }
            } finally {
                $supersede->close();
                $activate->close();
            }
            if (is_array($previousPlan)) {
                estab_dv_audit(
                    $connection,
                    $protocolTable,
                    $incidentId,
                    'DV Fernmeldeplan',
                    [
                        'action' => 'plan_superseded',
                        'plan_id' => (int) $previousPlan['fernmeldeplan_id'],
                        'plan_version' => (int) $previousPlan['version'],
                        'actor' => $userCode,
                        'actor_function' => 'S6',
                        'replacement_plan_id' => $planId,
                    ]
                );
            }
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Fernmeldeplan',
                [
                    'action' => 'plan_activated',
                    'plan_id' => $planId,
                    'actor' => $userCode,
                ]
            );
        }
    );
}

/** Return plan headers with their immutable structured routes. */
function estab_dv_telecom_plans(mysqli $connection, int $incidentId): array
{
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT p.*, e.`fernmeldeplan_eintrag_id`, e.`sortierung`,'
        . ' e.`betriebsstelle`, e.`rufname`, e.`medium`, e.`kanal`,'
        . ' e.`bandlage`, e.`verkehrsform`, e.`besondere_vermerke`,'
        . ' e.`bemerkungen` AS `eintrag_bemerkungen`'
        . ' FROM `nv_fernmeldeplaene` AS p'
        . ' LEFT JOIN `nv_fernmeldeplan_eintraege` AS e'
        . ' ON e.`fernmeldeplan_id` = p.`fernmeldeplan_id`'
        . ' WHERE p.`einsatz_id` = ?'
        . ' ORDER BY p.`version` DESC, e.`sortierung`'
    );
    if (!$statement) {
        throw new RuntimeException('Fernmeldepläne konnten nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('i', $incidentId);
        $statement->execute();
        $result = $statement->get_result();
        $plans = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $planId = (int) $row['fernmeldeplan_id'];
            if (!isset($plans[$planId])) {
                $plans[$planId] = [
                    'fernmeldeplan_id' => $planId,
                    'version' => (int) $row['version'],
                    'status' => (string) $row['status'],
                    'einsatzbezeichnung' => (string) $row['einsatzbezeichnung'],
                    'herkunft' => (string) $row['herkunft'],
                    'gueltig_ab' => (string) $row['gueltig_ab'],
                    'gueltig_bis' => $row['gueltig_bis'],
                    'betriebsleitung' => (string) $row['betriebsleitung'],
                    'bemerkungen' => $row['bemerkungen'],
                    'erstellt_von' => (string) $row['erstellt_von'],
                    'freigegeben_von' => $row['freigegeben_von'],
                    'eintraege' => [],
                ];
            }
            if ($row['fernmeldeplan_eintrag_id'] !== null) {
                $plans[$planId]['eintraege'][] = [
                    'fernmeldeplan_eintrag_id' =>
                        (int) $row['fernmeldeplan_eintrag_id'],
                    'sortierung' => (int) $row['sortierung'],
                    'betriebsstelle' => (string) $row['betriebsstelle'],
                    'rufname' => (string) $row['rufname'],
                    'medium' => (string) $row['medium'],
                    'kanal' => (string) $row['kanal'],
                    'bandlage' => (string) $row['bandlage'],
                    'verkehrsform' => (string) $row['verkehrsform'],
                    'besondere_vermerke' => $row['besondere_vermerke'],
                    'bemerkungen' => $row['eintrag_bemerkungen'],
                ];
            }
        }
        $result->free();
        return array_values($plans);
    } finally {
        $statement->close();
    }
}

/**
 * Resolve an outgoing route only through the currently active S6 plan.
 *
 * The returned entry is safe to copy into a message disposition. An unknown
 * entry, other incident, replaced plan or expired plan fails closed.
 */
function estab_dv_resolve_active_route(
    mysqli $connection,
    int $incidentId,
    int $entryId,
    ?string $expectedMedium = null
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $entryId = estab_dv_positive_id($entryId, 'Fernmeldeweg');
    if (
        $expectedMedium !== null
        && !in_array($expectedMedium, ESTAB_DV_MEDIA, true)
    ) {
        throw new EstabDvInputException('Medium ist ungültig.');
    }
    $statement = $connection->prepare(
        'SELECT e.*, p.`version`, p.`gueltig_ab`, p.`gueltig_bis`,'
        . ' p.`betriebsleitung`'
        . ' FROM `nv_fernmeldeplan_eintraege` AS e'
        . ' JOIN `nv_fernmeldeplaene` AS p'
        . ' ON p.`fernmeldeplan_id` = e.`fernmeldeplan_id`'
        . " WHERE e.`fernmeldeplan_eintrag_id` = ? AND p.`einsatz_id` = ?"
        . " AND p.`status` = 'AKTIV' AND p.`gueltig_ab` <= NOW()"
        . ' AND (p.`gueltig_bis` IS NULL OR p.`gueltig_bis` >= NOW())'
        . ' LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException('Fernmeldeweg konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('ii', $entryId, $incidentId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (
        !is_array($row)
        || (
            $expectedMedium !== null
            && !hash_equals($expectedMedium, (string) $row['medium'])
        )
    ) {
        throw new EstabDvConflictException(
            'Der ausgewählte Weg gehört nicht zum gültigen S6-Fernmeldeplan.'
        );
    }
    return $row;
}

/**
 * List only personally accepted A/W operators of the active duty shift.
 *
 * This is the authoritative source for the LdF selection UI. The assignment
 * domain repeats the same rule so a crafted request cannot nominate staff,
 * subject-area or merely logged-in accounts as messengers.
 */
function estab_dv_messenger_candidates(
    mysqli $connection,
    int $incidentId
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT u.`benutzer`, u.`kuerzel`, b.`funktion`, b.`rolle`,'
        . ' b.`dienstbesetzung_id`'
        . ' FROM `nv_dienstschichten` AS s'
        . ' JOIN `nv_dienstbesetzungen` AS b'
        . ' ON b.`dienstschicht_id` = s.`dienstschicht_id`'
        . ' JOIN `nv_benutzer` AS u'
        . ' ON BINARY u.`kuerzel` = BINARY b.`benutzer_kuerzel`'
        . " WHERE s.`einsatz_id` = ? AND s.`status` = 'AKTIV'"
        . " AND b.`status` = 'ANGENOMMEN'"
        . " AND BINARY b.`funktion` = BINARY 'A/W'"
        . " AND BINARY b.`rolle` = BINARY 'Fernmelder'"
        . ' AND u.`aktiv` = 1 AND u.`estab_gesperrt` = 0'
        . ' ORDER BY u.`benutzer`, u.`kuerzel`'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Verfügbare Melder konnten nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('i', $incidentId);
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    } finally {
        $statement->close();
    }
}

/** Return the stable, hashable fachlicher snapshot of one messenger job. */
function estab_dv_messenger_snapshot(array $row): array
{
    $nullableText = static fn (string $key): ?string =>
        !array_key_exists($key, $row) || $row[$key] === null
            ? null
            : (string) $row[$key];
    return [
        'einsatz_id' => (int) ($row['einsatz_id'] ?? 0),
        'nachricht_id' => (int) ($row['nachricht_id'] ?? 0),
        'melder_kuerzel' => (string) ($row['melder_kuerzel'] ?? ''),
        'ziel' => (string) ($row['ziel'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'beauftragt_am' => (string) ($row['beauftragt_am'] ?? ''),
        'beauftragt_von' => (string) ($row['beauftragt_von'] ?? ''),
        'uebernommen_am' => $nullableText('uebernommen_am'),
        'tatsaechlicher_empfaenger' =>
            $nullableText('tatsaechlicher_empfaenger'),
        'uebergeben_am' => $nullableText('uebergeben_am'),
        'ruecknachricht_vorhanden' =>
            !array_key_exists('ruecknachricht_vorhanden', $row)
                || $row['ruecknachricht_vorhanden'] === null
                    ? null
                    : (bool) $row['ruecknachricht_vorhanden'],
        'ruecknachricht' => $nullableText('ruecknachricht'),
        'rueckweg_am' => $nullableText('rueckweg_am'),
        'zurueck_am' => $nullableText('zurueck_am'),
        'abschlussvermerk' => $nullableText('abschlussvermerk'),
        'gemeldet_am' => $nullableText('gemeldet_am'),
        'gemeldet_an' => $nullableText('gemeldet_an'),
        'abgebrochen_am' => $nullableText('abgebrochen_am'),
        'abbruchgrund' => $nullableText('abbruchgrund'),
    ];
}

function estab_dv_messenger_row(
    mysqli $connection,
    int $incidentId,
    int $jobId,
    bool $forUpdate = false
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $jobId = estab_dv_positive_id($jobId, 'Melderauftrag');
    $statement = $connection->prepare(
        'SELECT * FROM `nv_melderauftraege`'
        . ' WHERE `melderauftrag_id` = ? AND `einsatz_id` = ?'
        . ($forUpdate ? ' FOR UPDATE' : '')
    );
    if (!$statement) {
        throw new RuntimeException('Melderauftrag konnte nicht gelesen werden.');
    }
    try {
        $statement->bind_param('ii', $jobId, $incidentId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (!is_array($row)) {
        throw new EstabDvConflictException(
            'Melderauftrag wurde nicht gefunden.'
        );
    }
    return $row;
}

function estab_dv_messenger_transition_evidence(
    string $action,
    array $snapshot
): array {
    $evidence = ['snapshot' => $snapshot];
    if ($action === 'deliver') {
        $evidence['tatsaechlicher_empfaenger'] =
            $snapshot['tatsaechlicher_empfaenger'];
    } elseif ($action === 'return_path') {
        $present = $snapshot['ruecknachricht_vorhanden'] === true;
        $text = (string) ($snapshot['ruecknachricht'] ?? '');
        $evidence['ruecknachricht_vorhanden'] = $present;
        $evidence['ruecknachricht'] = $text;
        $evidence['ruecknachricht_sha256'] =
            $present ? hash('sha256', $text) : null;
    } elseif ($action === 'report') {
        $evidence['gemeldet_an'] = $snapshot['gemeldet_an'];
        $evidence['abschlussvermerk'] = $snapshot['abschlussvermerk'];
    } elseif ($action === 'cancel') {
        $evidence['abbruchgrund'] = $snapshot['abbruchgrund'];
    }
    return $evidence;
}

/**
 * Guard redistribution of an outgoing Me message.
 *
 * A fully reported transport is final. A pre-acceptance cancellation remains
 * as evidence but permits a new disposition; every non-terminal run blocks.
 */
function estab_dv_require_no_open_messenger_for_redispatch(
    mysqli $connection,
    int $incidentId,
    int $messageId
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $messageId = estab_dv_positive_id($messageId, 'Nachricht');
    $statement = $connection->prepare(
        'SELECT `melderauftrag_id`, `status` FROM `nv_melderauftraege`'
        . ' WHERE `einsatz_id` = ? AND `nachricht_id` = ?'
        . ' ORDER BY `melderauftrag_id` FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Bisherige Melderaufträge konnten nicht geprüft werden.'
        );
    }
    try {
        $statement->bind_param('ii', $incidentId, $messageId);
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } finally {
        $statement->close();
    }
    foreach ($rows as $row) {
        if ($row['status'] === 'GEMELDET') {
            throw new EstabDvConflictException(
                'Für diese Nachricht ist die Melderbeförderung bereits '
                . 'vollständig gemeldet und endgültig.'
            );
        }
        if ($row['status'] !== 'ABGEBROCHEN') {
            throw new EstabDvConflictException(
                'Für diese Nachricht besteht bereits ein offener '
                . 'Melderauftrag.'
            );
        }
    }
    return $rows;
}

function estab_dv_assign_messenger(
    mysqli $connection,
    int $incidentId,
    int $messageId,
    mixed $messengerCodeValue,
    mixed $destinationValue,
    array $identity,
    string $protocolTable = 'nv_protokoll'
): int {
    $incidentId = estab_incident_positive_id($incidentId);
    $messageId = estab_dv_positive_id($messageId, 'Nachricht');
    $messengerCode = estab_dv_code($messengerCodeValue, 'Melderkürzel');
    $destination = estab_dv_text($destinationValue, 'Ziel', 255);
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $messageId,
            $messengerCode,
            $destination,
            $identity,
            $protocolTable
        ): int {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException(
                    'Der Einsatz ist nicht mehr aktiv.'
                );
            }
            $selected = estab_dv_require_selected_capability(
                $connection,
                $incidentId,
                $identity,
                'FERNMELDEBETRIEB'
            );
            $actorCode = $selected['kuerzel'];
            $message = $connection->prepare(
                'SELECT `04_richtung`, `06_befwegausw`, `einsatz_id`,'
                . ' `estab_fernmeldeplan_eintrag_id`,'
                . ' `x00_status`, `x01_abschluss`'
                . ' FROM `nv_nachrichten` WHERE `00_lfd` = ? FOR UPDATE'
            );
            if (!$message) {
                throw new RuntimeException('Nachricht konnte nicht geprüft werden.');
            }
            try {
                $message->bind_param('i', $messageId);
                $message->execute();
                $messageRow = $message->get_result()->fetch_assoc();
            } finally {
                $message->close();
            }
            if (
                !is_array($messageRow)
                || (int) $messageRow['einsatz_id'] !== $incidentId
                || $messageRow['04_richtung'] !== 'A'
                || $messageRow['06_befwegausw'] !== 'Me'
                || (int) ($messageRow['estab_fernmeldeplan_eintrag_id'] ?? 0) < 1
                || (int) $messageRow['x00_status'] !== 2
                || !in_array(
                    (string) $messageRow['x01_abschluss'],
                    ['f', '0'],
                    true
                )
            ) {
                throw new EstabDvConflictException(
                    'Ein Melderauftrag benötigt einen Ausgang mit '
                    . 'disponiertem Medium Me und nachgewiesenem S6-Weg.'
                );
            }
            estab_dv_require_no_open_messenger_for_redispatch(
                $connection,
                $incidentId,
                $messageId
            );
            $user = $connection->prepare(
                'SELECT u.`estab_gesperrt`, u.`aktiv`'
                . ' FROM `nv_benutzer` AS u'
                . ' JOIN `nv_dienstbesetzungen` AS b'
                . ' ON BINARY b.`benutzer_kuerzel` = BINARY u.`kuerzel`'
                . ' JOIN `nv_dienstschichten` AS s'
                . ' ON s.`dienstschicht_id` = b.`dienstschicht_id`'
                . ' WHERE BINARY u.`kuerzel` = BINARY ?'
                . ' AND s.`einsatz_id` = ?'
                . " AND s.`status` = 'AKTIV'"
                . " AND b.`status` = 'ANGENOMMEN'"
                . " AND BINARY b.`funktion` = BINARY 'A/W'"
                . " AND BINARY b.`rolle` = BINARY 'Fernmelder'"
                . ' LIMIT 1 FOR UPDATE'
            );
            if (!$user) {
                throw new RuntimeException('Melderkonto konnte nicht geprüft werden.');
            }
            try {
                $user->bind_param('si', $messengerCode, $incidentId);
                $user->execute();
                $userRow = $user->get_result()->fetch_assoc();
            } finally {
                $user->close();
            }
            if (
                !is_array($userRow)
                || (int) $userRow['estab_gesperrt'] === 1
                || (int) $userRow['aktiv'] !== 1
            ) {
                throw new EstabDvConflictException(
                    'Der Melder muss als A/W der aktiven Dienstschicht '
                    . 'persönlich angenommen sowie aktiv und ungesperrt sein.'
                );
            }
            $insert = $connection->prepare(
                'INSERT INTO `nv_melderauftraege`'
                . ' (`einsatz_id`, `nachricht_id`, `melder_kuerzel`, `ziel`,'
                . ' `beauftragt_von`) VALUES (?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                throw new RuntimeException('Melderauftrag konnte nicht vorbereitet werden.');
            }
            try {
                $insert->bind_param(
                    'iisss',
                    $incidentId,
                    $messageId,
                    $messengerCode,
                    $destination,
                    $actorCode
                );
                try {
                    $executed = $insert->execute();
                } catch (mysqli_sql_exception $exception) {
                    if ((int) $exception->getCode() === 1062) {
                        throw new EstabDvConflictException(
                            'Für die Nachricht oder den ausgewählten Melder '
                            . 'besteht bereits ein offener Auftrag.'
                        );
                    }
                    throw new RuntimeException(
                        'Melderauftrag konnte nicht gespeichert werden.',
                        0,
                        $exception
                    );
                }
                if (!$executed) {
                    throw new RuntimeException(
                        'Melderauftrag konnte nicht gespeichert werden.'
                    );
                }
                $jobId = (int) $connection->insert_id;
            } finally {
                $insert->close();
            }
            $jobSnapshot = estab_dv_messenger_snapshot(
                estab_dv_messenger_row(
                    $connection,
                    $incidentId,
                    $jobId,
                    true
                )
            );
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Melder',
                [
                    'action' => 'messenger_assigned',
                    'job_id' => $jobId,
                    'message_id' => $messageId,
                    'messenger' => $messengerCode,
                    'destination' => $destination,
                    'actor' => $actorCode,
                    'snapshot' => $jobSnapshot,
                ]
            );
            return $jobId;
        }
    );
}

/**
 * Advance one messenger job through the prescribed chain of custody.
 *
 * The messenger personally confirms acceptance, delivery, return path and
 * return. The selected active LdF personally records the final report back to
 * the telecommunications centre.
 */
function estab_dv_transition_messenger(
    mysqli $connection,
    int $incidentId,
    int $jobId,
    string $action,
    array $identity,
    array $input = [],
    string $protocolTable = 'nv_protokoll'
): string {
    $incidentId = estab_incident_positive_id($incidentId);
    $jobId = estab_dv_positive_id($jobId, 'Melderauftrag');
    $transitions = [
        'accept' => ['BEAUFTRAGT', 'UEBERNOMMEN'],
        'deliver' => ['UEBERNOMMEN', 'UEBERGEBEN'],
        'return_path' => ['UEBERGEBEN', 'RUECKWEG'],
        'returned' => ['RUECKWEG', 'ZURUECK'],
        'report' => ['ZURUECK', 'GEMELDET'],
        'cancel' => ['BEAUFTRAGT', 'ABGEBROCHEN'],
    ];
    if (!isset($transitions[$action])) {
        throw new EstabDvInputException('Unbekannte Melderaktion.');
    }
    $recipient = $action === 'deliver'
        ? estab_dv_text(
            $input['tatsaechlicher_empfaenger'] ?? null,
            'Tatsächlicher Empfänger',
            255
        )
        : '';
    $returnMessagePresent = false;
    $returnMessage = '';
    if ($action === 'return_path') {
        $returnDecision = $input['ruecknachricht_vorhanden'] ?? null;
        if (
            !is_string($returnDecision)
            || !in_array($returnDecision, ['ja', 'nein'], true)
        ) {
            throw new EstabDvInputException(
                'Geben Sie ausdrücklich an, ob eine Rücknachricht vorliegt.'
            );
        }
        $returnMessagePresent = $returnDecision === 'ja';
        $returnMessage = estab_dv_text(
            $input['ruecknachricht'] ?? '',
            'Rücknachricht',
            10000,
            true
        );
        if (
            ($returnMessagePresent && $returnMessage === '')
            || (!$returnMessagePresent && $returnMessage !== '')
        ) {
            throw new EstabDvInputException(
                $returnMessagePresent
                    ? 'Die angekündigte Rücknachricht muss eingetragen werden.'
                    : 'Wählen Sie „ja“, wenn Sie eine Rücknachricht eintragen.'
            );
        }
    }
    $report = $action === 'report'
        ? estab_dv_text(
            $input['abschlussvermerk'] ?? null,
            'Abschlussvermerk',
            10000
        )
        : '';
    $cancelReason = $action === 'cancel'
        ? estab_dv_text($input['abbruchgrund'] ?? null, 'Abbruchgrund', 10000)
        : '';

    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $incidentId,
            $jobId,
            $action,
            $identity,
            $transitions,
            $recipient,
            $returnMessagePresent,
            $returnMessage,
            $report,
            $cancelReason,
            $protocolTable
        ): string {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabDvConflictException('Der Einsatz ist nicht mehr aktiv.');
            }
            $select = $connection->prepare(
                'SELECT * FROM `nv_melderauftraege`'
                . ' WHERE `melderauftrag_id` = ? AND `einsatz_id` = ? FOR UPDATE'
            );
            if (!$select) {
                throw new RuntimeException('Melderauftrag konnte nicht gesperrt werden.');
            }
            try {
                $select->bind_param('ii', $jobId, $incidentId);
                $select->execute();
                $row = $select->get_result()->fetch_assoc();
            } finally {
                $select->close();
            }
            if (!is_array($row)) {
                throw new EstabDvConflictException('Melderauftrag wurde nicht gefunden.');
            }
            $requiredCapability = in_array(
                $action,
                ['cancel', 'report'],
                true
            )
                ? 'FERNMELDEBETRIEB'
                : 'BEFOERDERUNG';
            $selected = estab_dv_require_selected_capability(
                $connection,
                $incidentId,
                $identity,
                $requiredCapability
            );
            $actorCode = $selected['kuerzel'];
            $isMessenger = hash_equals((string) $row['melder_kuerzel'], $actorCode);
            if (
                (in_array($action, ['accept', 'deliver', 'return_path', 'returned'], true)
                    && !$isMessenger)
            ) {
                throw new EstabDvPermissionException(
                    'Diese Aktion gehört nicht zu Ihrer aktiven Dienstfunktion.'
                );
            }
            [$expected, $next] = $transitions[$action];
            if (!hash_equals((string) $expected, (string) $row['status'])) {
                throw new EstabDvConflictException(
                    'Der Melderauftrag befindet sich nicht im erwarteten Zustand.'
                );
            }
            $sql = match ($action) {
                'accept' => "UPDATE `nv_melderauftraege`"
                    . " SET `status` = 'UEBERNOMMEN', `uebernommen_am` = NOW(6)"
                    . ' WHERE `melderauftrag_id` = ?',
                'deliver' => "UPDATE `nv_melderauftraege`"
                    . " SET `status` = 'UEBERGEBEN', `uebergeben_am` = NOW(6),"
                    . ' `tatsaechlicher_empfaenger` = ?'
                    . ' WHERE `melderauftrag_id` = ?',
                'return_path' => "UPDATE `nv_melderauftraege`"
                    . " SET `status` = 'RUECKWEG', `rueckweg_am` = NOW(6),"
                    . ' `ruecknachricht_vorhanden` = ?,'
                    . ' `ruecknachricht` = ? WHERE `melderauftrag_id` = ?',
                'returned' => "UPDATE `nv_melderauftraege`"
                    . " SET `status` = 'ZURUECK', `zurueck_am` = NOW(6)"
                    . ' WHERE `melderauftrag_id` = ?',
                'report' => "UPDATE `nv_melderauftraege`"
                    . " SET `status` = 'GEMELDET', `gemeldet_am` = NOW(6),"
                    . ' `gemeldet_an` = ?, `abschlussvermerk` = ?'
                    . ' WHERE `melderauftrag_id` = ?',
                'cancel' => "UPDATE `nv_melderauftraege`"
                    . " SET `status` = 'ABGEBROCHEN', `abgebrochen_am` = NOW(6),"
                    . ' `abbruchgrund` = ? WHERE `melderauftrag_id` = ?',
            };
            $update = $connection->prepare($sql);
            if (!$update) {
                throw new RuntimeException('Melderstatus konnte nicht vorbereitet werden.');
            }
            try {
                match ($action) {
                    'accept', 'returned' => $update->bind_param('i', $jobId),
                    'deliver' => $update->bind_param('si', $recipient, $jobId),
                    'return_path' => $update->bind_param(
                        'isi',
                        $returnMessagePresent,
                        $returnMessage,
                        $jobId
                    ),
                    'report' => $update->bind_param(
                        'ssi',
                        $actorCode,
                        $report,
                        $jobId
                    ),
                    'cancel' => $update->bind_param('si', $cancelReason, $jobId),
                };
                if (!$update->execute() || $update->affected_rows !== 1) {
                    throw new EstabDvConflictException(
                        'Der Melderauftrag wurde zwischenzeitlich geändert.'
                    );
                }
            } finally {
                $update->close();
            }
            $jobSnapshot = estab_dv_messenger_snapshot(
                estab_dv_messenger_row(
                    $connection,
                    $incidentId,
                    $jobId,
                    true
                )
            );
            estab_dv_audit(
                $connection,
                $protocolTable,
                $incidentId,
                'DV Melder',
                [
                    'action' => 'messenger_' . $action,
                    'job_id' => $jobId,
                    'old_status' => (string) $row['status'],
                    'new_status' => $next,
                    'actor' => $actorCode,
                ] + estab_dv_messenger_transition_evidence(
                    $action,
                    $jobSnapshot
                )
            );
            return $next;
        }
    );
}

/**
 * Gate the A/W completion of an outgoing messenger transmission.
 *
 * Exactly one successfully reported assignment must exist and its full
 * return/report chain must be complete. Earlier, immutably retained
 * pre-acceptance cancellations do not invalidate a later successful run.
 */
function estab_dv_require_messenger_reported_for_message(
    mysqli $connection,
    int $incidentId,
    int $messageId
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $messageId = estab_dv_positive_id($messageId, 'Nachricht');
    $statement = $connection->prepare(
        'SELECT `melderauftrag_id`, `status`, `tatsaechlicher_empfaenger`,'
        . ' `uebergeben_am`, `ruecknachricht_vorhanden`, `ruecknachricht`,'
        . ' `rueckweg_am`, `zurueck_am`, `gemeldet_am`,'
        . ' `gemeldet_an`, `abschlussvermerk`'
        . ' FROM `nv_melderauftraege`'
        . " WHERE `einsatz_id` = ? AND `nachricht_id` = ?"
        . " AND `status` = 'GEMELDET' FOR UPDATE"
    );
    if (!$statement) {
        throw new RuntimeException('Meldernachweis konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('ii', $incidentId, $messageId);
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } finally {
        $statement->close();
    }
    if (
        count($rows) !== 1
        || ($rows[0]['status'] ?? null) !== 'GEMELDET'
        || trim((string) ($rows[0]['tatsaechlicher_empfaenger'] ?? '')) === ''
        || $rows[0]['uebergeben_am'] === null
        || $rows[0]['ruecknachricht_vorhanden'] === null
        || (
            (int) $rows[0]['ruecknachricht_vorhanden'] === 1
            && trim((string) ($rows[0]['ruecknachricht'] ?? '')) === ''
        )
        || (
            (int) $rows[0]['ruecknachricht_vorhanden'] === 0
            && trim((string) ($rows[0]['ruecknachricht'] ?? '')) !== ''
        )
        || $rows[0]['rueckweg_am'] === null
        || $rows[0]['zurueck_am'] === null
        || $rows[0]['gemeldet_am'] === null
        || trim((string) ($rows[0]['gemeldet_an'] ?? '')) === ''
        || trim((string) ($rows[0]['abschlussvermerk'] ?? '')) === ''
    ) {
        throw new EstabDvConflictException(
            'Die Melderbeförderung ist erst nach Übergabe, Rückweg, Rückkehr '
            . 'und Abschlussmeldung vollständig.'
        );
    }
    return $rows[0];
}

/** Read incident jobs; non-supervisors can be restricted to their own jobs. */
function estab_dv_messenger_jobs(
    mysqli $connection,
    int $incidentId,
    ?string $messengerCode = null
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $sql = 'SELECT m.*, n.`04_nummer`, n.`10_anschrift`, n.`12_inhalt`,'
        . ' u.`benutzer` AS `melder_name`'
        . ' FROM `nv_melderauftraege` AS m'
        . ' JOIN `nv_nachrichten` AS n ON n.`00_lfd` = m.`nachricht_id`'
        . ' JOIN `nv_benutzer` AS u ON u.`kuerzel` = m.`melder_kuerzel`'
        . ' WHERE m.`einsatz_id` = ?';
    if ($messengerCode !== null) {
        $messengerCode = estab_dv_code($messengerCode);
        $sql .= ' AND m.`melder_kuerzel` = ?';
    }
    $sql .= ' ORDER BY m.`beauftragt_am` DESC, m.`melderauftrag_id` DESC';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Melderaufträge konnten nicht vorbereitet werden.');
    }
    try {
        if ($messengerCode === null) {
            $statement->bind_param('i', $incidentId);
        } else {
            $statement->bind_param('is', $incidentId, $messengerCode);
        }
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    } finally {
        $statement->close();
    }
}

/** Recompute the complete per-incident command-post hash chain. */
function estab_dv_verify_event_chain(
    mysqli $connection,
    int $incidentId
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT `sequenz`, `objekttyp`, `objekt_id`, `aktion`,'
        . ' `akteur_kuerzel`, `akteur_funktion`,'
        . " DATE_FORMAT(`ereigniszeit`, '%Y-%m-%d %H:%i:%s.%f') AS `zeit`,"
        . ' CAST(`details` AS CHAR) AS `details_json`, `vorheriger_hash`,'
        . ' `ereignis_hash` FROM `nv_betriebsereignisse`'
        . ' WHERE `einsatz_id` = ? ORDER BY `sequenz`'
    );
    if (!$statement) {
        throw new RuntimeException('Betriebsereigniskette konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('i', $incidentId);
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } finally {
        $statement->close();
    }
    $previousHash = str_repeat('0', 64);
    $expectedSequence = 1;
    foreach ($rows as $row) {
        $sequence = (int) ($row['sequenz'] ?? 0);
        $detailsJson = (string) ($row['details_json'] ?? '');
        $expectedHash = hash(
            'sha256',
            implode('|', [
                (string) $incidentId,
                (string) $sequence,
                (string) ($row['objekttyp'] ?? ''),
                (string) ((int) ($row['objekt_id'] ?? 0)),
                (string) ($row['aktion'] ?? ''),
                (string) ($row['akteur_kuerzel'] ?? ''),
                (string) ($row['akteur_funktion'] ?? ''),
                (string) ($row['zeit'] ?? ''),
                $detailsJson,
                $previousHash,
            ])
        );
        if (
            $sequence !== $expectedSequence
            || !hash_equals(
                $previousHash,
                (string) ($row['vorheriger_hash'] ?? '')
            )
            || !hash_equals(
                $expectedHash,
                (string) ($row['ereignis_hash'] ?? '')
            )
        ) {
            return [
                'valid' => false,
                'events' => count($rows),
                'failed_sequence' => $sequence,
                'head_hash' => $previousHash,
            ];
        }
        $previousHash = $expectedHash;
        $expectedSequence++;
    }
    $head = $connection->prepare(
        'SELECT `letzte_sequenz`, `letzter_hash`'
        . ' FROM `nv_betriebsereignis_kopf` WHERE `einsatz_id` = ?'
    );
    if (!$head) {
        throw new RuntimeException('Betriebsereigniskopf konnte nicht geprüft werden.');
    }
    try {
        $head->bind_param('i', $incidentId);
        $head->execute();
        $headRow = $head->get_result()->fetch_assoc();
    } finally {
        $head->close();
    }
    $validHead = $rows === []
        ? (
            !is_array($headRow)
            || (
                (int) $headRow['letzte_sequenz'] === 0
                && hash_equals($previousHash, (string) $headRow['letzter_hash'])
            )
        )
        : (
            is_array($headRow)
            && (int) $headRow['letzte_sequenz'] === count($rows)
            && hash_equals($previousHash, (string) $headRow['letzter_hash'])
        );
    return [
        'valid' => $validHead,
        'events' => count($rows),
        'failed_sequence' => $validHead ? null : count($rows),
        'head_hash' => $previousHash,
    ];
}

/**
 * Bind every terminal messenger row to its latest canonical ledger snapshot.
 *
 * The event-chain verifier proves that the stored details were not changed;
 * this second comparison proves that a terminal live row still says exactly
 * what its immutable report/cancellation event recorded.
 */
function estab_dv_verify_messenger_snapshots(
    mysqli $connection,
    int $incidentId
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $jobs = $connection->prepare(
        'SELECT * FROM `nv_melderauftraege`'
        . ' WHERE `einsatz_id` = ?'
        . " AND `status` IN ('GEMELDET','ABGEBROCHEN')"
        . ' ORDER BY `melderauftrag_id`'
    );
    if (!$jobs) {
        throw new RuntimeException(
            'Terminale Melderaufträge konnten nicht geprüft werden.'
        );
    }
    try {
        $jobs->bind_param('i', $incidentId);
        $jobs->execute();
        $result = $jobs->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } finally {
        $jobs->close();
    }
    $event = $connection->prepare(
        'SELECT `aktion`, CAST(`details` AS CHAR) AS `details_json`'
        . ' FROM `nv_betriebsereignisse`'
        . " WHERE `einsatz_id` = ? AND `objekttyp` = 'MELDERAUFTRAG'"
        . ' AND `objekt_id` = ? ORDER BY `sequenz` DESC LIMIT 1'
    );
    if (!$event) {
        throw new RuntimeException(
            'Melder-Betriebsnachweis konnte nicht geprüft werden.'
        );
    }
    try {
        foreach ($rows as $row) {
            $jobId = (int) $row['melderauftrag_id'];
            $event->bind_param('ii', $incidentId, $jobId);
            $event->execute();
            $eventResult = $event->get_result();
            $eventRow = $eventResult->fetch_assoc();
            $eventResult->free();
            $expectedAction = $row['status'] === 'GEMELDET'
                ? 'messenger_report'
                : 'messenger_cancel';
            $details = null;
            if (
                is_array($eventRow)
                && is_string($eventRow['details_json'] ?? null)
            ) {
                try {
                    $decoded = json_decode(
                        $eventRow['details_json'],
                        true,
                        32,
                        JSON_THROW_ON_ERROR
                    );
                    $details = is_array($decoded) ? $decoded : null;
                } catch (JsonException) {
                    $details = null;
                }
            }
            if (
                !is_array($eventRow)
                || !hash_equals(
                    $expectedAction,
                    (string) ($eventRow['aktion'] ?? '')
                )
                || !is_array($details)
                || !is_array($details['snapshot'] ?? null)
                || $details['snapshot'] !== estab_dv_messenger_snapshot($row)
            ) {
                return [
                    'valid' => false,
                    'jobs' => count($rows),
                    'failed_job_id' => $jobId,
                ];
            }
        }
    } finally {
        $event->close();
    }
    return [
        'valid' => true,
        'jobs' => count($rows),
        'failed_job_id' => null,
    ];
}

/**
 * Return organisational blockers for the formal incident-close preflight.
 *
 * The incident domain can merge these counts with open messages, locks and
 * logbook/export requirements without coupling either module to UI state.
 */
function estab_dv_incident_closure_blockers(
    mysqli $connection,
    int $incidentId,
    ?int $closingShiftId = null
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $excludedShiftId = $closingShiftId === null
        ? 0
        : estab_dv_positive_id($closingShiftId, 'Schließende Dienstschicht');
    $statement = $connection->prepare(
        'SELECT'
        . ' (SELECT COUNT(*) FROM `nv_dienstschichten`'
        . '   WHERE `einsatz_id` = ?'
        . '     AND (? = 0 OR `dienstschicht_id` <> ?)'
        . "     AND `status` IN ('GEPLANT','AKTIV')) AS `offene_schichten`,"
        . ' (SELECT COUNT(*) FROM `nv_dienstbesetzungen` AS hat'
        . '   JOIN `nv_dienstschichten` AS shift_row'
        . '     ON shift_row.`dienstschicht_id` = hat.`dienstschicht_id`'
        . '   WHERE shift_row.`einsatz_id` = ?'
        . '     AND (? = 0 OR shift_row.`dienstschicht_id` <> ?)'
        . "     AND hat.`status` IN ('ZUGEWIESEN','ANGENOMMEN'))"
        . '     AS `offene_besetzungen`,'
        . ' (SELECT COUNT(*) FROM `nv_melderauftraege`'
        . '   WHERE `einsatz_id` = ?'
        . "     AND `status` NOT IN ('GEMELDET','ABGEBROCHEN'))"
        . '     AS `offene_melderauftraege`,'
        . ' (SELECT COUNT(*) FROM `nv_fernmeldeplaene`'
        . "   WHERE `einsatz_id` = ? AND `status` = 'ENTWURF')"
        . '     AS `offene_fernmeldeplanentwuerfe`,'
        . ' (SELECT COUNT(*) FROM `nv_dienstuebergabe_anfragen`'
        . '   WHERE `einsatz_id` = ?'
        . "     AND `status` = 'INITIIERT')"
        . '     AS `offene_uebergabeanforderungen`'
    );
    if (!$statement) {
        throw new RuntimeException('DV-Abschlussprüfung konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param(
            'iiiiiiiii',
            $incidentId,
            $excludedShiftId,
            $excludedShiftId,
            $incidentId,
            $excludedShiftId,
            $excludedShiftId,
            $incidentId,
            $incidentId,
            $incidentId
        );
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (!is_array($row)) {
        throw new RuntimeException('DV-Abschlussprüfung lieferte keinen Status.');
    }
    $chain = estab_dv_verify_event_chain($connection, $incidentId);
    $messengerSnapshots =
        estab_dv_verify_messenger_snapshots($connection, $incidentId);
    return [
        'offene_schichten' => (int) $row['offene_schichten'],
        'offene_besetzungen' => (int) $row['offene_besetzungen'],
        'offene_melderauftraege' => (int) $row['offene_melderauftraege'],
        'offene_fernmeldeplanentwuerfe' =>
            (int) $row['offene_fernmeldeplanentwuerfe'],
        'offene_uebergabeanforderungen' =>
            (int) $row['offene_uebergabeanforderungen'],
        'betriebsereigniskette_gueltig' =>
            (bool) $chain['valid'] && (bool) $messengerSnapshots['valid'],
        'meldernachweise_gueltig' => (bool) $messengerSnapshots['valid'],
        'terminale_melderauftraege' => (int) $messengerSnapshots['jobs'],
        'betriebsereignisse' => (int) $chain['events'],
    ];
}
