<?php

declare(strict_types=1);

/**
 * Global incident boundary.
 *
 * Operational writers must use estab_incident_with_active_write(). It locks
 * the singleton status row for the complete transaction, so an administrator
 * cannot switch the active incident between a domain write and its audit/
 * dependent writes. Database triggers installed by migration 50 provide the
 * final fail-closed boundary for legacy writers that have not yet moved to
 * this API.
 */

require_once __DIR__ . '/auth.php';

const ESTAB_INCIDENT_IDENTIFIER_MAX_LENGTH = 64;
const ESTAB_INCIDENT_NAME_MAX_LENGTH = 255;
const ESTAB_INCIDENT_SINGLE_LINE_MAX_LENGTH = 255;
const ESTAB_INCIDENT_DESCRIPTION_MAX_LENGTH = 10000;
const ESTAB_INCIDENT_METADATA_MAX_BYTES = 65535;

final class EstabIncidentInputException extends InvalidArgumentException
{
}

final class EstabIncidentNotFoundException extends RuntimeException
{
}

final class EstabIncidentConflictException extends RuntimeException
{
}

final class EstabNoActiveIncidentException extends RuntimeException
{
}

/**
 * Parse a canonical positive decimal identifier without PHP's numeric
 * coercions.
 */
function estab_incident_positive_id(mixed $value, string $field = 'Einsatz-ID'): int
{
    if (!is_string($value) && !is_int($value)) {
        throw new EstabIncidentInputException($field . ' ist ungültig.');
    }
    $candidate = (string) $value;
    if (preg_match('/\A[1-9][0-9]*\z/D', $candidate) !== 1) {
        throw new EstabIncidentInputException($field . ' ist ungültig.');
    }
    $parsed = filter_var($candidate, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
    ]);
    if (!is_int($parsed)) {
        throw new EstabIncidentInputException($field . ' ist ungültig.');
    }
    return $parsed;
}

/** Parse the optimistic status revision used by all admin state changes. */
function estab_incident_revision(mixed $value): int
{
    if (!is_string($value) && !is_int($value)) {
        throw new EstabIncidentInputException('Statusversion ist ungültig.');
    }
    $candidate = (string) $value;
    if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $candidate) !== 1) {
        throw new EstabIncidentInputException('Statusversion ist ungültig.');
    }
    $parsed = filter_var($candidate, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
    ]);
    if (!is_int($parsed)) {
        throw new EstabIncidentInputException('Statusversion ist ungültig.');
    }
    return $parsed;
}

/**
 * Normalize the operator-facing stable identifier.
 *
 * LEGACY-* is reserved for migration-owned incidents and can never be created
 * through the application.
 */
function estab_incident_identifier(mixed $value): string
{
    if (!is_string($value)) {
        throw new EstabIncidentInputException('Die Einsatzkennung ist ungültig.');
    }
    $identifier = strtoupper(trim($value));
    if (
        strlen($identifier) < 3
        || strlen($identifier) > ESTAB_INCIDENT_IDENTIFIER_MAX_LENGTH
        || preg_match('/\A[A-Z0-9][A-Z0-9._\/-]*\z/D', $identifier) !== 1
        || str_starts_with($identifier, 'LEGACY-')
    ) {
        throw new EstabIncidentInputException(
            'Die Kennung muss 3 bis 64 Zeichen aus A–Z, 0–9, Punkt, '
            . 'Schrägstrich, Unterstrich oder Bindestrich enthalten.'
        );
    }
    return $identifier;
}

/** Validate the Basic-Auth or service identity stored in the incident audit. */
function estab_incident_actor(mixed $value): string
{
    if (!is_string($value)) {
        throw new EstabIncidentInputException('Administrative Identität fehlt.');
    }
    $actor = trim($value);
    if (
        $actor === ''
        || strlen($actor) > 128
        || preg_match('/[\p{C}]/u', $actor) === 1
    ) {
        throw new EstabIncidentInputException('Administrative Identität ist ungültig.');
    }
    return $actor;
}

/** Validate one UTF-8 form field against its database and display boundary. */
function estab_incident_text(
    mixed $value,
    string $label,
    int $maximum,
    bool $required
): string {
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        throw new EstabIncidentInputException($label . ' muss gültiger Text sein.');
    }
    $text = trim($value);
    $length = estab_auth_text_length($text);
    if (
        ($required && $text === '')
        || $length < 0
        || $length > $maximum
        || str_contains($text, "\0")
    ) {
        throw new EstabIncidentInputException($label . ' ist leer oder zu lang.');
    }
    if ($maximum <= ESTAB_INCIDENT_SINGLE_LINE_MAX_LENGTH) {
        if (preg_match('/\p{C}/u', $text) === 1) {
            throw new EstabIncidentInputException(
                $label . ' darf keine Steuerzeichen enthalten.'
            );
        }
    } elseif (
        preg_match(
            '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}'
            . '\x{007F}-\x{009F}]/u',
            $text
        ) === 1
    ) {
        throw new EstabIncidentInputException(
            $label . ' enthält unzulässige Steuerzeichen.'
        );
    }
    return $text;
}

/**
 * Parse a browser datetime-local value without silently normalising an
 * impossible calendar value.
 */
function estab_incident_datetime(mixed $value, string $label, bool $required): ?string
{
    if ($value === null || $value === '') {
        if ($required) {
            throw new EstabIncidentInputException($label . ' fehlt.');
        }
        return null;
    }
    if (!is_string($value)) {
        throw new EstabIncidentInputException($label . ' ist ungültig.');
    }
    $candidate = trim($value);
    $format = 'Y-m-d\TH:i';
    $date = DateTimeImmutable::createFromFormat(
        '!' . $format,
        $candidate,
        new DateTimeZone(date_default_timezone_get())
    );
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$date instanceof DateTimeImmutable
        || ($errors !== false && (
            (int) ($errors['warning_count'] ?? 0) !== 0
            || (int) ($errors['error_count'] ?? 0) !== 0
        ))
        || $date->format($format) !== $candidate
    ) {
        throw new EstabIncidentInputException($label . ' ist ungültig.');
    }
    return $date->format('Y-m-d H:i:s');
}

/** Validate an optional JSON object for deployment-specific metadata. */
function estab_incident_metadata(mixed $value): string
{
    if ($value === null || $value === '') {
        return '{}';
    }
    if (!is_string($value) || strlen($value) > ESTAB_INCIDENT_METADATA_MAX_BYTES) {
        throw new EstabIncidentInputException('Weitere Metadaten sind ungültig.');
    }
    try {
        $decoded = json_decode($value, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new EstabIncidentInputException(
            'Weitere Metadaten müssen ein gültiges JSON-Objekt sein.'
        );
    }
    if (!$decoded instanceof stdClass) {
        throw new EstabIncidentInputException(
            'Weitere Metadaten müssen als JSON-Objekt angegeben werden.'
        );
    }
    $encoded = json_encode(
        $decoded,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (strlen($encoded) > ESTAB_INCIDENT_METADATA_MAX_BYTES) {
        throw new EstabIncidentInputException('Weitere Metadaten sind zu groß.');
    }
    return $encoded;
}

/** Validate and normalize all incident creation fields. */
function estab_incident_validate_create(array $input): array
{
    $start = estab_incident_datetime($input['beginn'] ?? null, 'Beginn', true);
    $end = estab_incident_datetime($input['ende'] ?? null, 'Ende', false);
    if ($end !== null && strcmp($end, (string) $start) < 0) {
        throw new EstabIncidentInputException(
            'Das Einsatzende darf nicht vor dem Beginn liegen.'
        );
    }

    return [
        'kennung' => estab_incident_identifier($input['kennung'] ?? null),
        'name' => estab_incident_text(
            $input['name'] ?? null,
            'Einsatzname',
            ESTAB_INCIDENT_NAME_MAX_LENGTH,
            true
        ),
        'beginn' => $start,
        'ende' => $end,
        'ort' => estab_incident_text(
            $input['ort'] ?? '',
            'Ort',
            ESTAB_INCIDENT_SINGLE_LINE_MAX_LENGTH,
            false
        ),
        'organisation' => estab_incident_text(
            $input['organisation'] ?? '',
            'Organisation',
            ESTAB_INCIDENT_SINGLE_LINE_MAX_LENGTH,
            false
        ),
        'einsatzleitung' => estab_incident_text(
            $input['einsatzleitung'] ?? '',
            'Einsatzleitung',
            ESTAB_INCIDENT_SINGLE_LINE_MAX_LENGTH,
            false
        ),
        'beschreibung' => estab_incident_text(
            $input['beschreibung'] ?? '',
            'Beschreibung',
            ESTAB_INCIDENT_DESCRIPTION_MAX_LENGTH,
            false
        ),
        'metadaten' => estab_incident_metadata($input['metadaten'] ?? ''),
    ];
}

/** Append an immutable lifecycle audit event inside the caller's transaction. */
function estab_incident_audit(
    mysqli $connection,
    int $incidentId,
    string $action,
    string $actor,
    ?int $statusRevision,
    array $details = []
): void {
    if (preg_match('/\A[a-z_]{3,32}\z/D', $action) !== 1) {
        throw new LogicException('Invalid incident audit action');
    }
    $detailsJson = json_encode(
        $details,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $statement = $connection->prepare(
        'INSERT INTO `nv_einsatz_ereignisse`'
        . ' (`einsatz_id`, `aktion`, `zeitpunkt`, `akteur`,'
        . ' `status_revision`, `details`)'
        . ' VALUES (?, ?, NOW(6), ?, ?, ?)'
    );
    if (!$statement) {
        throw new RuntimeException('Einsatzprotokoll konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param(
            'issis',
            $incidentId,
            $action,
            $actor,
            $statusRevision,
            $detailsJson
        );
        if (!$statement->execute()) {
            throw new RuntimeException('Einsatzprotokoll konnte nicht geschrieben werden.');
        }
    } finally {
        $statement->close();
    }
}

/**
 * Read the singleton status and optional active incident.
 *
 * FOR UPDATE is used by every state change and by the complete operational
 * write transaction. A missing singleton is a schema failure, never "no
 * incident".
 */
function estab_incident_status(mysqli $connection, bool $forUpdate = false): array
{
    $sql = 'SELECT s.`active_einsatz_id`, s.`revision`, s.`geaendert_am`,'
        . ' s.`geaendert_von`, e.`kennung`, e.`name`, e.`beginn`, e.`ende`,'
        . ' e.`ort`, e.`organisation`, e.`einsatzleitung`, e.`beschreibung`,'
        . ' e.`metadaten`'
        . ' FROM `nv_einsatz_status` AS s'
        . ' LEFT JOIN `nv_einsaetze` AS e'
        . ' ON e.`einsatz_id` = s.`active_einsatz_id`'
        . ' WHERE s.`singleton_id` = 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $result = $connection->query($sql);
    if (!$result) {
        throw new RuntimeException('Aktiver Einsatz konnte nicht gelesen werden.');
    }
    try {
        $row = $result->fetch_assoc();
    } finally {
        $result->free();
    }
    if (!is_array($row)) {
        throw new RuntimeException('Der globale Einsatzstatus fehlt.');
    }
    if ($row['active_einsatz_id'] !== null && $row['kennung'] === null) {
        throw new RuntimeException('Der globale Einsatzstatus ist inkonsistent.');
    }
    $row['revision'] = estab_incident_revision((string) $row['revision']);
    if ($row['active_einsatz_id'] !== null) {
        $row['active_einsatz_id'] = estab_incident_positive_id(
            (string) $row['active_einsatz_id']
        );
    }
    return $row;
}

/** Return the active incident or fail with the input-lock exception. */
function estab_incident_require_active(
    mysqli $connection,
    bool $forUpdate = false
): array {
    $status = estab_incident_status($connection, $forUpdate);
    if ($status['active_einsatz_id'] === null) {
        throw new EstabNoActiveIncidentException(
            'Kein Einsatz ist aktiv. Eingaben sind gesperrt.'
        );
    }
    return $status;
}

/** Return null instead of throwing when there is currently no active incident. */
function estab_incident_active(mysqli $connection): ?array
{
    $status = estab_incident_status($connection);
    return $status['active_einsatz_id'] === null ? null : $status;
}

/**
 * Execute a complete operational write while the active incident cannot
 * change. The callback receives the authoritative status including
 * active_einsatz_id and must use the same mysqli connection.
 */
function estab_incident_with_active_write(
    mysqli $connection,
    callable $operation
): mixed {
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Einsatztransaktion konnte nicht begonnen werden.');
    }
    try {
        $incident = estab_incident_require_active($connection, true);
        $result = $operation($incident);
        if (!$connection->commit()) {
            throw new RuntimeException('Einsatztransaktion konnte nicht gespeichert werden.');
        }
        return $result;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/** List all incidents and mark the one referenced by the singleton status. */
function estab_incident_list(mysqli $connection): array
{
    $result = $connection->query(
        'SELECT e.`einsatz_id`, e.`kennung`, e.`name`, e.`beginn`, e.`ende`,'
        . ' e.`ort`, e.`organisation`, e.`einsatzleitung`, e.`beschreibung`,'
        . ' e.`metadaten`, e.`erstellt_am`, e.`erstellt_von`,'
        . ' CASE WHEN s.`active_einsatz_id` = e.`einsatz_id` THEN 1 ELSE 0 END'
        . ' AS `ist_aktiv`'
        . ' FROM `nv_einsaetze` AS e'
        . ' CROSS JOIN `nv_einsatz_status` AS s'
        . ' WHERE s.`singleton_id` = 1'
        . ' ORDER BY `ist_aktiv` DESC, e.`beginn` DESC, e.`einsatz_id` DESC'
    );
    if (!$result) {
        throw new RuntimeException('Einsätze konnten nicht gelesen werden.');
    }
    try {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
    } finally {
        $result->free();
    }
    foreach ($rows as &$row) {
        $row['einsatz_id'] = estab_incident_positive_id((string) $row['einsatz_id']);
        $row['ist_aktiv'] = (int) $row['ist_aktiv'] === 1;
    }
    unset($row);
    return $rows;
}

/** Find one incident for read-only display and export selection. */
function estab_incident_find(mysqli $connection, int $incidentId): ?array
{
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT e.`einsatz_id`, e.`kennung`, e.`name`, e.`beginn`, e.`ende`,'
        . ' e.`ort`, e.`organisation`, e.`einsatzleitung`, e.`beschreibung`,'
        . ' e.`metadaten`, e.`erstellt_am`, e.`erstellt_von`,'
        . ' CASE WHEN s.`active_einsatz_id` = e.`einsatz_id` THEN 1 ELSE 0 END'
        . ' AS `ist_aktiv`'
        . ' FROM `nv_einsaetze` AS e'
        . ' CROSS JOIN `nv_einsatz_status` AS s'
        . ' WHERE s.`singleton_id` = 1 AND e.`einsatz_id` = ? LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException('Einsatz konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('i', $incidentId);
        if (!$statement->execute()) {
            throw new RuntimeException('Einsatz konnte nicht gelesen werden.');
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
    } finally {
        $statement->close();
    }
    if (!is_array($row)) {
        return null;
    }
    $row['einsatz_id'] = estab_incident_positive_id((string) $row['einsatz_id']);
    $row['ist_aktiv'] = (int) $row['ist_aktiv'] === 1;
    return $row;
}

/** Lock and return one incident inside an existing transaction. */
function estab_incident_fetch_for_update(mysqli $connection, int $incidentId): array
{
    $statement = $connection->prepare(
        'SELECT `einsatz_id`, `kennung`, `name`, `beginn`, `ende`'
        . ' FROM `nv_einsaetze` WHERE `einsatz_id` = ? FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException('Einsatz konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('i', $incidentId);
        if (!$statement->execute()) {
            throw new RuntimeException('Einsatz konnte nicht gelesen werden.');
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
    } finally {
        $statement->close();
    }
    if (!is_array($row)) {
        throw new EstabIncidentNotFoundException('Der Einsatz wurde nicht gefunden.');
    }
    return $row;
}

/**
 * Activate an incident after the status row has already been locked.
 *
 * The submitted revision prevents a stale browser tab from replacing a newer
 * operator decision after it waited for the lock.
 */
function estab_incident_activate_locked(
    mysqli $connection,
    array $status,
    int $incidentId,
    int $expectedRevision,
    string $actor
): array {
    if ((int) $status['revision'] !== $expectedRevision) {
        throw new EstabIncidentConflictException(
            'Der Einsatzstatus wurde zwischenzeitlich geändert.'
        );
    }
    $target = estab_incident_fetch_for_update($connection, $incidentId);
    if (
        is_string($target['ende'] ?? null)
        && $target['ende'] !== ''
        && strcmp($target['ende'], date('Y-m-d H:i:s')) < 0
    ) {
        throw new EstabIncidentConflictException(
            'Ein bereits beendeter Einsatz kann nicht aktiviert werden.'
        );
    }
    if ((int) ($status['active_einsatz_id'] ?? 0) === $incidentId) {
        return $status;
    }

    $nextRevision = $expectedRevision + 1;
    $statement = $connection->prepare(
        'UPDATE `nv_einsatz_status`'
        . ' SET `active_einsatz_id` = ?, `revision` = ?,'
        . ' `geaendert_am` = NOW(6), `geaendert_von` = ?'
        . ' WHERE `singleton_id` = 1 AND `revision` = ?'
    );
    if (!$statement) {
        throw new RuntimeException('Aktivierung konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param(
            'iisi',
            $incidentId,
            $nextRevision,
            $actor,
            $expectedRevision
        );
        if (!$statement->execute()) {
            throw new RuntimeException('Aktivierung konnte nicht gespeichert werden.');
        }
        if ($statement->affected_rows !== 1) {
            throw new EstabIncidentConflictException(
                'Der Einsatzstatus wurde zwischenzeitlich geändert.'
            );
        }
    } finally {
        $statement->close();
    }
    estab_incident_audit(
        $connection,
        $incidentId,
        'aktiviert',
        $actor,
        $nextRevision,
        ['vorheriger_einsatz_id' => $status['active_einsatz_id']]
    );
    return estab_incident_status($connection);
}

/** Create one immutable incident identity, optionally activating it atomically. */
function estab_incident_create(
    mysqli $connection,
    array $input,
    string $actor,
    bool $activate,
    ?int $expectedRevision = null
): array {
    $data = estab_incident_validate_create($input);
    $actor = estab_incident_actor($actor);
    if ($activate && $expectedRevision === null) {
        throw new EstabIncidentInputException(
            'Für die sofortige Aktivierung fehlt die Statusversion.'
        );
    }
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Einsatz konnte nicht angelegt werden.');
    }
    try {
        $status = $activate
            ? estab_incident_status($connection, true)
            : null;
        if (
            $activate
            && (int) $status['revision'] !== $expectedRevision
        ) {
            throw new EstabIncidentConflictException(
                'Der Einsatzstatus wurde zwischenzeitlich geändert.'
            );
        }

        $statement = $connection->prepare(
            'INSERT INTO `nv_einsaetze`'
            . ' (`kennung`, `name`, `beginn`, `ende`, `ort`, `organisation`,'
            . ' `einsatzleitung`, `beschreibung`, `metadaten`,'
            . ' `erstellt_am`, `erstellt_von`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(6), ?)'
        );
        if (!$statement) {
            throw new RuntimeException('Einsatz konnte nicht vorbereitet werden.');
        }
        try {
            $statement->bind_param(
                'ssssssssss',
                $data['kennung'],
                $data['name'],
                $data['beginn'],
                $data['ende'],
                $data['ort'],
                $data['organisation'],
                $data['einsatzleitung'],
                $data['beschreibung'],
                $data['metadaten'],
                $actor
            );
            if (!$statement->execute()) {
                if ((int) $statement->errno === 1062) {
                    throw new EstabIncidentConflictException(
                        'Diese Einsatzkennung ist bereits vorhanden.'
                    );
                }
                throw new RuntimeException('Einsatz konnte nicht angelegt werden.');
            }
            $incidentId = (int) $connection->insert_id;
        } finally {
            $statement->close();
        }
        if ($incidentId < 1) {
            throw new RuntimeException('Einsatzkennung konnte nicht ermittelt werden.');
        }

        estab_incident_audit(
            $connection,
            $incidentId,
            'angelegt',
            $actor,
            null,
            ['kennung' => $data['kennung']]
        );
        if ($activate) {
            $status = estab_incident_activate_locked(
                $connection,
                $status,
                $incidentId,
                (int) $expectedRevision,
                $actor
            );
        }
        if (!$connection->commit()) {
            throw new RuntimeException('Einsatz konnte nicht gespeichert werden.');
        }
        return [
            'einsatz_id' => $incidentId,
            'kennung' => $data['kennung'],
            'name' => $data['name'],
            'aktiv' => $activate,
            'status_revision' => $activate ? $status['revision'] : null,
        ];
    } catch (mysqli_sql_exception $exception) {
        $connection->rollback();
        if ($exception->getCode() === 1062) {
            throw new EstabIncidentConflictException(
                'Diese Einsatzkennung ist bereits vorhanden.',
                0,
                $exception
            );
        }
        throw $exception;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/** Activate exactly one existing incident in a serialized transaction. */
function estab_incident_activate(
    mysqli $connection,
    int $incidentId,
    int $expectedRevision,
    string $actor
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $expectedRevision = estab_incident_revision($expectedRevision);
    $actor = estab_incident_actor($actor);
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Aktivierung konnte nicht begonnen werden.');
    }
    try {
        $status = estab_incident_status($connection, true);
        $status = estab_incident_activate_locked(
            $connection,
            $status,
            $incidentId,
            $expectedRevision,
            $actor
        );
        if (!$connection->commit()) {
            throw new RuntimeException('Aktivierung konnte nicht gespeichert werden.');
        }
        return $status;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/**
 * Deactivate only the incident and revision that the administrator actually
 * saw. A stale form can therefore never deactivate a newer incident.
 */
function estab_incident_deactivate(
    mysqli $connection,
    int $expectedIncidentId,
    int $expectedRevision,
    string $actor
): array {
    $expectedIncidentId = estab_incident_positive_id($expectedIncidentId);
    $expectedRevision = estab_incident_revision($expectedRevision);
    $actor = estab_incident_actor($actor);
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Deaktivierung konnte nicht begonnen werden.');
    }
    try {
        $status = estab_incident_status($connection, true);
        if (
            (int) $status['revision'] !== $expectedRevision
            || (int) ($status['active_einsatz_id'] ?? 0) !== $expectedIncidentId
        ) {
            throw new EstabIncidentConflictException(
                'Der aktive Einsatz wurde zwischenzeitlich geändert.'
            );
        }
        $nextRevision = $expectedRevision + 1;
        $statement = $connection->prepare(
            'UPDATE `nv_einsatz_status`'
            . ' SET `active_einsatz_id` = NULL, `revision` = ?,'
            . ' `geaendert_am` = NOW(6), `geaendert_von` = ?'
            . ' WHERE `singleton_id` = 1'
            . ' AND `active_einsatz_id` = ? AND `revision` = ?'
        );
        if (!$statement) {
            throw new RuntimeException('Deaktivierung konnte nicht vorbereitet werden.');
        }
        try {
            $statement->bind_param(
                'isii',
                $nextRevision,
                $actor,
                $expectedIncidentId,
                $expectedRevision
            );
            if (!$statement->execute()) {
                throw new RuntimeException(
                    'Deaktivierung konnte nicht gespeichert werden.'
                );
            }
            if ($statement->affected_rows !== 1) {
                throw new EstabIncidentConflictException(
                    'Der aktive Einsatz wurde zwischenzeitlich geändert.'
                );
            }
        } finally {
            $statement->close();
        }
        estab_incident_audit(
            $connection,
            $expectedIncidentId,
            'deaktiviert',
            $actor,
            $nextRevision
        );
        $status = estab_incident_status($connection);
        if (!$connection->commit()) {
            throw new RuntimeException('Deaktivierung konnte nicht gespeichert werden.');
        }
        return $status;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}
