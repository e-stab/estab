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
require_once __DIR__ . '/attachment_integrity.php';
require_once __DIR__ . '/logbook_lifecycle.php';

const ESTAB_INCIDENT_IDENTIFIER_MAX_LENGTH = 64;
const ESTAB_INCIDENT_NAME_MAX_LENGTH = 255;
const ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH = 128;
const ESTAB_INCIDENT_SINGLE_LINE_MAX_LENGTH = 255;
const ESTAB_INCIDENT_DESCRIPTION_MAX_LENGTH = 10000;
const ESTAB_INCIDENT_METADATA_MAX_BYTES = 65535;
const ESTAB_INCIDENT_CLOSE_NOTE_MAX_LENGTH = 10000;
const ESTAB_INCIDENT_LEGAL_HOLD_REASON_MAX_LENGTH = 1000;

final class EstabIncidentInputException extends InvalidArgumentException
{
}

final class EstabIncidentNotFoundException extends RuntimeException
{
}

class EstabIncidentConflictException extends RuntimeException
{
}

final class EstabIncidentConfigurationException extends EstabIncidentConflictException
{
}

final class EstabNoActiveIncidentException extends RuntimeException
{
}

final class EstabIncidentCloseBlockedException extends EstabIncidentConflictException
{
    /** @param array<string, int|bool> $preflight */
    public function __construct(
        string $message,
        public readonly array $preflight
    ) {
        parent::__construct($message);
    }
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
    $text = preg_replace(
        '/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u',
        '',
        $value
    );
    if (!is_string($text)) {
        throw new EstabIncidentInputException($label . ' muss gültiger Text sein.');
    }
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
 * Return the authoritative command-post name stored on an incident.
 *
 * Historical incidents created before migration 97 may deliberately contain
 * NULL. They must be completed by an administrator; neither organisation,
 * incident name nor a current environment value is a safe substitute.
 */
function estab_incident_command_post_name(array $incident): string
{
    try {
        return estab_incident_text(
            $incident['fuehrungsstellenname'] ?? null,
            'Name der Führungsstelle',
            ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH,
            true
        );
    } catch (EstabIncidentInputException $exception) {
        throw new EstabIncidentConfigurationException(
            'Für diesen Einsatz ist noch kein gültiger Führungsstellenname '
            . 'festgelegt.',
            0,
            $exception
        );
    }
}

/**
 * Validate and durably lock the active incident's command-post identity.
 *
 * Migration 97 installs the SQL function used here and by the legacy table
 * triggers. Its UPDATE participates in the caller's transaction, so a failed
 * operational write rolls the first-write marker back as well.
 */
function estab_incident_lock_command_post_for_write(
    mysqli $connection,
    array &$incident
): string {
    $name = estab_incident_command_post_name($incident);
    $incidentId = estab_incident_positive_id(
        $incident['active_einsatz_id'] ?? null
    );
    $statement = $connection->prepare(
        'SELECT estab_incident_command_post_for_write(?)'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Führungsstellenidentität konnte nicht gesperrt werden.'
        );
    }
    try {
        $statement->bind_param('i', $incidentId);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Führungsstellenidentität konnte nicht gesperrt werden.'
            );
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
    } finally {
        $statement->close();
    }
    if ((int) ($row[0] ?? 0) !== $incidentId) {
        throw new RuntimeException(
            'Führungsstellenidentität wurde nicht verbindlich gesperrt.'
        );
    }
    $incident['fuehrungsstellenname_gesperrt'] = 1;
    return $name;
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
            $input['organisation'] ?? null,
            'Bedarfsträger',
            ESTAB_INCIDENT_SINGLE_LINE_MAX_LENGTH,
            true
        ),
        'fuehrungsstellenname' => estab_incident_text(
            $input['fuehrungsstellenname'] ?? null,
            'Name der Führungsstelle',
            ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH,
            true
        ),
        'einsatzleitung' => estab_incident_text(
            $input['einsatzleitung'] ?? null,
            'Verantwortliche Einsatz-/Führungsleitung',
            ESTAB_INCIDENT_SINGLE_LINE_MAX_LENGTH,
            true
        ),
        'beschreibung' => estab_incident_text(
            $input['beschreibung'] ?? null,
            'Einsatzauftrag und Ausgangslage',
            ESTAB_INCIDENT_DESCRIPTION_MAX_LENGTH,
            true
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
        . ' e.`ort`, e.`organisation`, e.`fuehrungsstellenname`,'
        . ' e.`fuehrungsstellenname_gesperrt`,'
        . ' e.`einsatzleitung`, e.`beschreibung`,'
        . ' e.`metadaten`, e.`estab_status`, e.`estab_closed_at`,'
        . ' e.`estab_closed_by`, e.`estab_close_note`,'
        . ' e.`estab_retain_until`, e.`estab_legal_hold`,'
        . ' e.`estab_legal_hold_reason`, e.`estab_legal_hold_at`,'
        . ' e.`estab_legal_hold_by`'
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
    if (
        $row['active_einsatz_id'] !== null
        && ($row['estab_status'] ?? null) !== 'open'
    ) {
        throw new RuntimeException('Ein abgeschlossener Einsatz ist als aktiv markiert.');
    }
    $row['revision'] = estab_incident_revision((string) $row['revision']);
    if ($row['active_einsatz_id'] !== null) {
        $row['active_einsatz_id'] = estab_incident_positive_id(
            (string) $row['active_einsatz_id']
        );
        $row['fuehrungsstellenname_gesperrt'] =
            (int) ($row['fuehrungsstellenname_gesperrt'] ?? -1);
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
        estab_incident_lock_command_post_for_write($connection, $incident);
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
        . ' e.`ort`, e.`organisation`, e.`fuehrungsstellenname`,'
        . ' e.`fuehrungsstellenname_gesperrt`,'
        . ' e.`einsatzleitung`, e.`beschreibung`,'
        . ' e.`metadaten`, e.`erstellt_am`, e.`erstellt_von`,'
        . ' e.`estab_status`, e.`estab_closed_at`, e.`estab_closed_by`,'
        . ' e.`estab_close_note`, e.`estab_retain_until`,'
        . ' e.`estab_legal_hold`, e.`estab_legal_hold_reason`,'
        . ' e.`estab_legal_hold_at`, e.`estab_legal_hold_by`,'
        . ' CASE WHEN s.`active_einsatz_id` = e.`einsatz_id` THEN 1 ELSE 0 END'
        . ' AS `ist_aktiv`,'
        . ' CASE WHEN'
        . ' EXISTS(SELECT 1 FROM `nv_etb` AS etb_row'
        . '   WHERE etb_row.`einsatz_id` = e.`einsatz_id`)'
        . ' OR EXISTS(SELECT 1 FROM `nv_tbb` AS tbb_row'
        . '   WHERE tbb_row.`einsatz_id` = e.`einsatz_id`)'
        . ' THEN 1 ELSE 0 END AS `logbuchkopf_gesperrt`'
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
        $row['fuehrungsstellenname_gesperrt'] =
            (int) ($row['fuehrungsstellenname_gesperrt'] ?? -1);
        $row['logbuchkopf_gesperrt'] =
            (int) ($row['logbuchkopf_gesperrt'] ?? 1) === 1;
        $row['estab_legal_hold'] = (int) ($row['estab_legal_hold'] ?? 0) === 1;
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
        . ' e.`ort`, e.`organisation`, e.`fuehrungsstellenname`,'
        . ' e.`fuehrungsstellenname_gesperrt`,'
        . ' e.`einsatzleitung`, e.`beschreibung`,'
        . ' e.`metadaten`, e.`erstellt_am`, e.`erstellt_von`,'
        . ' e.`estab_status`, e.`estab_closed_at`, e.`estab_closed_by`,'
        . ' e.`estab_close_note`, e.`estab_retain_until`,'
        . ' e.`estab_legal_hold`, e.`estab_legal_hold_reason`,'
        . ' e.`estab_legal_hold_at`, e.`estab_legal_hold_by`,'
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
    $row['fuehrungsstellenname_gesperrt'] =
        (int) ($row['fuehrungsstellenname_gesperrt'] ?? -1);
    $row['estab_legal_hold'] = (int) ($row['estab_legal_hold'] ?? 0) === 1;
    return $row;
}

/** Lock and return one incident inside an existing transaction. */
function estab_incident_fetch_for_update(mysqli $connection, int $incidentId): array
{
    $statement = $connection->prepare(
        'SELECT `einsatz_id`, `kennung`, `name`, `beginn`, `ende`, `ort`,'
        . ' `organisation`, `fuehrungsstellenname`,'
        . ' `fuehrungsstellenname_gesperrt`, `einsatzleitung`, `beschreibung`,'
        . ' `estab_status`, `estab_closed_at`, `estab_closed_by`,'
        . ' `estab_close_note`, `estab_retain_until`, `estab_legal_hold`,'
        . ' `estab_legal_hold_reason`, `estab_legal_hold_at`,'
        . ' `estab_legal_hold_by`'
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
    $row['fuehrungsstellenname_gesperrt'] =
        (int) ($row['fuehrungsstellenname_gesperrt'] ?? -1);
    return $row;
}

/** Return whether immutable or operational data already exists for an incident. */
function estab_incident_has_operational_data(
    mysqli $connection,
    int $incidentId
): bool {
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT ('
        . ' EXISTS(SELECT 1 FROM `nv_nachrichten` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_anhang` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_etb` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_tbb` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_ubb` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_protokoll` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_bhp50` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_komplan` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_etbtitel` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_tbbtitel` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_dienstschichten` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_dienstuebergaben` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_dienstuebergabe_anfragen`'
        . ' WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_fernmeldeplaene` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_melderauftraege` WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_betriebsereignisse`'
        . ' WHERE `einsatz_id` = ?)'
        . ' OR EXISTS(SELECT 1 FROM `nv_betriebsereignis_kopf`'
        . ' WHERE `einsatz_id` = ?)'
        . ')'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Einsatzdaten konnten nicht zur Änderung geprüft werden.'
        );
    }
    try {
        $statement->bind_param(
            'iiiiiiiiiiiiiiiii',
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId
        );
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Einsatzdaten konnten nicht zur Änderung geprüft werden.'
            );
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
    } finally {
        $statement->close();
    }
    return (int) ($row[0] ?? 0) === 1;
}

/**
 * Set or correct the command-post name while preserving historical identity.
 *
 * A migrated NULL value may be confirmed once even when data already exists.
 * A populated value becomes immutable as soon as any operational record has
 * been written. The expected previous value prevents stale admin forms from
 * overwriting a concurrent correction.
 */
function estab_incident_update_command_post_name(
    mysqli $connection,
    int $incidentId,
    mixed $value,
    mixed $expectedValue,
    string $actor
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $name = estab_incident_text(
        $value,
        'Name der Führungsstelle',
        ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH,
        true
    );
    if (!is_string($expectedValue)) {
        throw new EstabIncidentInputException(
            'Bisheriger Führungsstellenname ist ungültig.'
        );
    }
    $expected = estab_incident_text(
        $expectedValue,
        'Bisheriger Führungsstellenname',
        ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH,
        false
    );
    $actor = estab_incident_actor($actor);

    if (!$connection->begin_transaction()) {
        throw new RuntimeException(
            'Führungsstellenname konnte nicht geändert werden.'
        );
    }
    try {
        $incident = estab_incident_fetch_for_update($connection, $incidentId);
        if (($incident['estab_status'] ?? null) !== 'open') {
            throw new EstabIncidentConflictException(
                'Ein formal abgeschlossener Einsatz kann nicht geändert werden.'
            );
        }
        $currentRaw = $incident['fuehrungsstellenname'] ?? null;
        if ($currentRaw !== null && !is_string($currentRaw)) {
            throw new EstabIncidentConfigurationException(
                'Der gespeicherte Führungsstellenname ist ungültig.'
            );
        }
        $current = $currentRaw === null
            ? ''
            : estab_incident_command_post_name($incident);
        if (
            ($currentRaw === null && $expected !== '')
            || (
                is_string($currentRaw)
                && !hash_equals($currentRaw, $expected)
            )
        ) {
            throw new EstabIncidentConflictException(
                'Der Führungsstellenname wurde zwischenzeitlich geändert.'
            );
        }
        $lockedRaw = $incident['fuehrungsstellenname_gesperrt'] ?? null;
        if (!in_array((string) $lockedRaw, ['0', '1'], true)) {
            throw new EstabIncidentConfigurationException(
                'Die Sperre des Führungsstellennamens ist ungültig.'
            );
        }
        $locked = (int) $lockedRaw === 1;
        if (is_string($currentRaw) && hash_equals($currentRaw, $name)) {
            if (!$connection->commit()) {
                throw new RuntimeException(
                    'Führungsstellenname konnte nicht bestätigt werden.'
                );
            }
            $incident['fuehrungsstellenname'] = $name;
            return $incident;
        }
        if ($locked) {
            throw new EstabIncidentConflictException(
                'Der Führungsstellenname ist nach der ersten operativen '
                . 'Eintragung unveränderlich.'
            );
        }
        $hasOperationalData = estab_incident_has_operational_data(
            $connection,
            $incidentId
        );
        if (is_string($currentRaw) && $hasOperationalData) {
            throw new EstabIncidentConfigurationException(
                'Die dauerhafte Sperre des Führungsstellennamens fehlt.'
            );
        }
        $nextLocked = $currentRaw === null && $hasOperationalData ? 1 : 0;

        $statement = $connection->prepare(
            'UPDATE `nv_einsaetze` SET `fuehrungsstellenname` = ?,'
            . ' `fuehrungsstellenname_gesperrt` = ?'
            . ' WHERE `einsatz_id` = ? AND `estab_status` = ?'
            . ' AND `fuehrungsstellenname_gesperrt` = 0'
            . ' AND ((? = 1 AND `fuehrungsstellenname` IS NULL)'
            . ' OR (? = 0 AND BINARY `fuehrungsstellenname` = BINARY ?))'
        );
        if (!$statement) {
            throw new RuntimeException(
                'Führungsstellenname konnte nicht vorbereitet werden.'
            );
        }
        $open = 'open';
        $expectedNull = $currentRaw === null ? 1 : 0;
        if (
            !$connection->query(
                'SET @estab_command_post_admin_write_id = ' . $incidentId
            )
        ) {
            throw new RuntimeException(
                'Führungsstellenänderung konnte nicht autorisiert werden.'
            );
        }
        try {
            $statement->bind_param(
                'siisiis',
                $name,
                $nextLocked,
                $incidentId,
                $open,
                $expectedNull,
                $expectedNull,
                $expected
            );
            if (
                !$statement->execute()
                || $statement->affected_rows !== 1
            ) {
                throw new EstabIncidentConflictException(
                    'Der Führungsstellenname wurde zwischenzeitlich geändert.'
                );
            }
        } finally {
            $statement->close();
            $connection->query(
                'SET @estab_command_post_admin_write_id = NULL'
            );
        }
        estab_incident_audit(
            $connection,
            $incidentId,
            'fuehrungsstelle_geaendert',
            $actor,
            null,
            [
                'vorher' => $currentRaw,
                'nachher' => $name,
                'erstbestaetigung' => $currentRaw === null,
                'dauerhaft_gesperrt' => $nextLocked === 1,
            ]
        );
        if (!$connection->commit()) {
            throw new RuntimeException(
                'Führungsstellenname konnte nicht gespeichert werden.'
            );
        }
        $incident['fuehrungsstellenname'] = $name;
        $incident['fuehrungsstellenname_gesperrt'] = $nextLocked;
        return $incident;
    } catch (Throwable $exception) {
        $connection->query(
            'SET @estab_command_post_admin_write_id = NULL'
        );
        $connection->rollback();
        throw $exception;
    }
}

/**
 * Complete the mandatory ETB/TBB opening header before either book is opened.
 *
 * Optional access shifts and historical duty shifts do not lock these fields.
 * Once either book contains a row, the factual opening header is immutable.
 */
function estab_incident_update_logbook_header(
    mysqli $connection,
    int $incidentId,
    array $input,
    string $actor
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $next = [
        'organisation' => estab_incident_text(
            $input['organisation'] ?? null,
            'Bedarfsträger',
            ESTAB_INCIDENT_SINGLE_LINE_MAX_LENGTH,
            true
        ),
        'einsatzleitung' => estab_incident_text(
            $input['einsatzleitung'] ?? null,
            'Verantwortliche Einsatz-/Führungsleitung',
            ESTAB_INCIDENT_SINGLE_LINE_MAX_LENGTH,
            true
        ),
        'beschreibung' => estab_incident_text(
            $input['beschreibung'] ?? null,
            'Einsatzauftrag und Ausgangslage',
            ESTAB_INCIDENT_DESCRIPTION_MAX_LENGTH,
            true
        ),
    ];
    $expected = [];
    foreach (array_keys($next) as $field) {
        $key = 'expected_' . $field;
        if (!isset($input[$key]) || !is_string($input[$key])) {
            throw new EstabIncidentInputException(
                'Der bisherige Logbuchkopf ist ungültig.'
            );
        }
        $expected[$field] = $input[$key];
    }
    $actor = estab_incident_actor($actor);
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Logbuch-Stammdaten konnten nicht geändert werden.');
    }
    try {
        $incident = estab_incident_fetch_for_update($connection, $incidentId);
        if (($incident['estab_status'] ?? null) !== 'open') {
            throw new EstabIncidentConflictException(
                'Ein formal abgeschlossener Einsatz kann nicht geändert werden.'
            );
        }
        foreach ($next as $field => $value) {
            $current = (string) ($incident[$field] ?? '');
            if (!hash_equals($current, $expected[$field])) {
                throw new EstabIncidentConflictException(
                    'Die Logbuch-Stammdaten wurden zwischenzeitlich geändert.'
                );
            }
        }
        $bookStatement = $connection->prepare(
            'SELECT `etb_lfd-nr` AS `id` FROM `nv_etb` WHERE `einsatz_id` = ?'
            . ' UNION ALL SELECT `tbb_lfd-nr` FROM `nv_tbb`'
            . ' WHERE `einsatz_id` = ? LIMIT 1'
        );
        if (!$bookStatement) {
            throw new RuntimeException('Logbuch-Stammdaten konnten nicht geprüft werden.');
        }
        try {
            $bookStatement->bind_param('ii', $incidentId, $incidentId);
            $bookStatement->execute();
            $hasRows = $bookStatement->get_result()->fetch_row() !== null;
        } finally {
            $bookStatement->close();
        }
        if ($hasRows) {
            throw new EstabIncidentConflictException(
                'Die Logbuch-Stammdaten sind nach der ersten Eintragung '
                . 'unveränderlich.'
            );
        }
        if (
            hash_equals((string) $incident['organisation'], $next['organisation'])
            && hash_equals(
                (string) $incident['einsatzleitung'],
                $next['einsatzleitung']
            )
            && hash_equals(
                (string) $incident['beschreibung'],
                $next['beschreibung']
            )
        ) {
            $connection->commit();
            return $incident;
        }
        $statement = $connection->prepare(
            'UPDATE `nv_einsaetze` SET `organisation` = ?,'
            . ' `einsatzleitung` = ?, `beschreibung` = ?'
            . " WHERE `einsatz_id` = ? AND `estab_status` = 'open'"
        );
        if (!$statement) {
            throw new RuntimeException('Logbuch-Stammdaten konnten nicht vorbereitet werden.');
        }
        try {
            $statement->bind_param(
                'sssi',
                $next['organisation'],
                $next['einsatzleitung'],
                $next['beschreibung'],
                $incidentId
            );
            if (!$statement->execute() || $statement->affected_rows !== 1) {
                throw new EstabIncidentConflictException(
                    'Die Logbuch-Stammdaten wurden zwischenzeitlich geändert.'
                );
            }
        } finally {
            $statement->close();
        }
        estab_incident_audit(
            $connection,
            $incidentId,
            'logbuchkopf_geaendert',
            $actor,
            null,
            ['vorher' => array_intersect_key($incident, $next), 'nachher' => $next]
        );
        if (!$connection->commit()) {
            throw new RuntimeException('Logbuch-Stammdaten konnten nicht gespeichert werden.');
        }
        return array_replace($incident, $next);
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
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
    if (($target['estab_status'] ?? null) !== 'open') {
        throw new EstabIncidentConflictException(
            'Ein formal abgeschlossener Einsatz kann nicht aktiviert werden.'
        );
    }
    estab_incident_command_post_name($target);
    if ((int) ($status['active_einsatz_id'] ?? 0) === $incidentId) {
        estab_logbook_lifecycle_open_books_if_empty($connection, $status);
        return estab_incident_status($connection);
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
    $activated = estab_incident_status($connection);
    estab_logbook_lifecycle_open_books_if_empty($connection, $activated);
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
            . ' `fuehrungsstellenname`, `einsatzleitung`, `beschreibung`,'
            . ' `metadaten`,'
            . ' `erstellt_am`, `erstellt_von`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(6), ?)'
        );
        if (!$statement) {
            throw new RuntimeException('Einsatz konnte nicht vorbereitet werden.');
        }
        try {
            $statement->bind_param(
                'sssssssssss',
                $data['kennung'],
                $data['name'],
                $data['beginn'],
                $data['ende'],
                $data['ort'],
                $data['organisation'],
                $data['fuehrungsstellenname'],
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
            [
                'kennung' => $data['kennung'],
                'fuehrungsstellenname' => $data['fuehrungsstellenname'],
            ]
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
            'fuehrungsstellenname' => $data['fuehrungsstellenname'],
            'fuehrungsstellenname_gesperrt' => 0,
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

/**
 * Return the blockers that must be cleared before a formal incident close.
 *
 * The caller that intends to close must already hold both the singleton
 * status row and the incident row FOR UPDATE.  This function deliberately
 * performs no transaction management so the preflight and close stay one
 * atomic administrative decision.
 *
 * @return array{
 *   open_messages:int,
 *   locked_messages:int,
 *   incomplete_attachments:int,
 *   attachment_integrity_errors:int,
 *   legacy_attachments_unverifiable:int,
 *   logbuecher_eroeffnet:bool,
 *   evidence_errors:int,
 *   offene_schichten:int,
 *   offene_besetzungen:int,
 *   offene_melderauftraege:int,
 *   offene_fernmeldeplanentwuerfe:int,
 *   offene_uebergabeanforderungen:int,
 *   betriebsereigniskette_gueltig:bool,
 *   closable:bool
 * }
 */
function estab_incident_close_preflight(
    mysqli $connection,
    int $incidentId,
    ?string $attachmentRoot = null,
    ?int $closingShiftId = null
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    if ($closingShiftId !== null) {
        $closingShiftId = estab_incident_positive_id($closingShiftId);
    }
    $statement = $connection->prepare(
        'SELECT'
        . ' (SELECT COUNT(*) FROM `nv_nachrichten`'
        . '   WHERE `einsatz_id` = ? AND `x00_status` <> 8)'
        . ' AS `open_messages`,'
        . ' (SELECT COUNT(*) FROM `nv_nachrichten`'
        . "   WHERE `einsatz_id` = ? AND (`x02_sperre` IN ('t','1')"
        . "     OR `x03_sperruser` <> '')) AS `locked_messages`,"
        . ' (SELECT COUNT(*) FROM `nv_anhang`'
        . '   WHERE `einsatz_id` = ? AND `status` IN (2, 8))'
        . ' AS `incomplete_attachments`,'
        . ' (SELECT COUNT(*) FROM `nv_etb`'
        . '   WHERE `einsatz_id` = ? AND `estab_book_lfd` = 1)'
        . ' AS `etb_opening_rows`,'
        . ' (SELECT COUNT(*) FROM `nv_tbb`'
        . '   WHERE `einsatz_id` = ? AND `estab_book_lfd` = 1)'
        . ' AS `tbb_opening_rows`'
    );
    if (!$statement) {
        throw new RuntimeException('Abschlussprüfung konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param(
            'iiiii',
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId,
            $incidentId
        );
        if (!$statement->execute()) {
            throw new RuntimeException('Abschlussprüfung konnte nicht ausgeführt werden.');
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
    } finally {
        $statement->close();
    }
    if (!is_array($row)) {
        throw new RuntimeException('Abschlussprüfung lieferte kein Ergebnis.');
    }
    $preflight = [
        'open_messages' => (int) ($row['open_messages'] ?? 0),
        'locked_messages' => (int) ($row['locked_messages'] ?? 0),
        'incomplete_attachments' => (int) ($row['incomplete_attachments'] ?? 0),
        'logbuecher_eroeffnet' =>
            (int) ($row['etb_opening_rows'] ?? 0) === 1
            && (int) ($row['tbb_opening_rows'] ?? 0) === 1,
    ];
    if ($attachmentRoot !== null) {
        $attachmentIntegrity = estab_attachment_integrity_summary(
            $connection,
            $attachmentRoot,
            $incidentId,
            false
        );
        $preflight['attachment_integrity_errors'] =
            (int) $attachmentIntegrity['integrity_errors'];
        $preflight['legacy_attachments_unverifiable'] =
            (int) $attachmentIntegrity['legacy_unverifiable'];
    } else {
        // Compatibility for non-web maintenance callers: still reject a
        // malformed required database proof. The production close path always
        // supplies the storage root and additionally verifies the live bytes.
        $integrityStatement = $connection->prepare(
            'SELECT'
                . ' SUM(CASE WHEN `status` = 1'
                . ' AND `integrity_required` = 1'
                . ' AND (`ingest_sha256` IS NULL'
                . " OR `ingest_sha256` NOT REGEXP BINARY '^[0-9a-f]{64}$'"
                . ' OR `ingest_size` IS NULL'
                . ' OR `integrity_captured_at` IS NULL)'
                . ' THEN 1 ELSE 0 END) AS `integrity_errors`,'
                . ' SUM(CASE WHEN `status` = 1'
                . ' AND `integrity_required` = 0'
                . ' THEN 1 ELSE 0 END) AS `legacy_attachments`'
                . ' FROM `nv_anhang` WHERE `einsatz_id` = ?'
        );
        if (!$integrityStatement) {
            throw new RuntimeException(
                'Anhangintegritätsprüfung konnte nicht vorbereitet werden.'
            );
        }
        $integrityRow = null;
        try {
            $integrityStatement->bind_param('i', $incidentId);
            if (!$integrityStatement->execute()) {
                throw new RuntimeException(
                    'Anhangintegritätsprüfung konnte nicht ausgeführt werden.'
                );
            }
            $integrityResult = $integrityStatement->get_result();
            if (!$integrityResult instanceof mysqli_result) {
                throw new RuntimeException(
                    'Anhangintegritätsprüfung lieferte kein Ergebnis.'
                );
            }
            $integrityRow = $integrityResult->fetch_assoc();
            $integrityResult->free();
        } finally {
            $integrityStatement->close();
        }
        if (!is_array($integrityRow)) {
            throw new RuntimeException(
                'Anhangintegritätsprüfung lieferte kein Ergebnis.'
            );
        }
        $preflight['attachment_integrity_errors'] =
            (int) ($integrityRow['integrity_errors'] ?? 0);
        $preflight['legacy_attachments_unverifiable'] =
            (int) ($integrityRow['legacy_attachments'] ?? 0);
    }
    require_once __DIR__ . '/message_evidence.php';
    $evidence = estab_message_evidence_verify($connection, $incidentId);
    $messageEvidenceErrors = $evidence['valid']
        ? 0
        : max(
            1,
            (int) ($evidence['head_mismatches'] ?? 0)
                + (($evidence['broken_event_id'] ?? null) === null ? 0 : 1)
        );
    require_once __DIR__ . '/dv_operations.php';
    $operations = estab_dv_incident_closure_blockers(
        $connection,
        $incidentId,
        $closingShiftId
    );
    $preflight['offene_schichten'] =
        (int) ($operations['offene_schichten'] ?? 0);
    $preflight['offene_besetzungen'] =
        (int) ($operations['offene_besetzungen'] ?? 0);
    $preflight['offene_melderauftraege'] =
        (int) ($operations['offene_melderauftraege'] ?? 0);
    $preflight['offene_fernmeldeplanentwuerfe'] =
        (int) ($operations['offene_fernmeldeplanentwuerfe'] ?? 0);
    $preflight['offene_uebergabeanforderungen'] =
        (int) ($operations['offene_uebergabeanforderungen'] ?? 0);
    $preflight['betriebsereigniskette_gueltig'] =
        (bool) ($operations['betriebsereigniskette_gueltig'] ?? false);
    $preflight['betriebsereignisse'] =
        (int) ($operations['betriebsereignisse'] ?? 0);
    $preflight['evidence_errors'] = $messageEvidenceErrors
        + ($preflight['betriebsereigniskette_gueltig'] ? 0 : 1);
    // Legacy duty shifts, their assignments and their handover requests are
    // retained as historical evidence, but the optional shift concept must
    // never prevent the incident itself from being closed.  Likewise, empty
    // books can receive their first (closing) row during the close transaction.
    $preflight['closable'] = $preflight['open_messages'] === 0
        && $preflight['locked_messages'] === 0
        && $preflight['incomplete_attachments'] === 0
        && $preflight['attachment_integrity_errors'] === 0
        && $preflight['offene_melderauftraege'] === 0
        && $preflight['offene_fernmeldeplanentwuerfe'] === 0
        && $preflight['evidence_errors'] === 0;
    return $preflight;
}

/**
 * Validate the operator-supplied actual end and close note.
 *
 * `ende` may be backdated to the factual end of operations.  The immutable
 * `estab_closed_at` column always records the server time of the administrative
 * close separately.
 *
 * @return array{ende:string, note:string}
 */
function estab_incident_validate_close(array $input, string $incidentStart): array
{
    $end = estab_incident_datetime(
        $input['ende'] ?? date('Y-m-d\TH:i'),
        'Tatsächliches Einsatzende',
        true
    );
    if ($end === null || strcmp($end, $incidentStart) < 0) {
        throw new EstabIncidentInputException(
            'Das tatsächliche Einsatzende darf nicht vor dem Beginn liegen.'
        );
    }
    if (strcmp($end, date('Y-m-d H:i:s', time() + 300)) > 0) {
        throw new EstabIncidentInputException(
            'Das tatsächliche Einsatzende darf nicht in der Zukunft liegen.'
        );
    }
    return [
        'ende' => $end,
        'note' => estab_incident_text(
            $input['close_note'] ?? '',
            'Abschlussvermerk',
            ESTAB_INCIDENT_CLOSE_NOTE_MAX_LENGTH,
            true
        ),
    ];
}

/**
 * Formally and irreversibly close the currently active incident.
 *
 * This is intentionally distinct from estab_incident_deactivate(): pausing
 * permits later activation, while close sets immutable close evidence,
 * establishes at least ten years of retention, and can never be reversed.
 */
function estab_incident_close(
    mysqli $connection,
    int $expectedIncidentId,
    int $expectedRevision,
    string $actor,
    array $input,
    ?string $attachmentRoot = null
): array {
    $expectedIncidentId = estab_incident_positive_id($expectedIncidentId);
    $expectedRevision = estab_incident_revision($expectedRevision);
    $actor = estab_incident_actor($actor);

    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Einsatzabschluss konnte nicht begonnen werden.');
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
        $incident = estab_incident_fetch_for_update($connection, $expectedIncidentId);
        if (($incident['estab_status'] ?? null) !== 'open') {
            throw new EstabIncidentConflictException(
                'Der Einsatz ist bereits formal abgeschlossen.'
            );
        }
        $close = estab_incident_validate_close(
            $input,
            (string) ($incident['beginn'] ?? '')
        );
        $preflight = estab_incident_close_preflight(
            $connection,
            $expectedIncidentId,
            $attachmentRoot
        );
        if (!$preflight['closable']) {
            throw new EstabIncidentCloseBlockedException(
                'Der Einsatz kann erst nach Abschluss aller offenen Vorgänge '
                . 'formal geschlossen werden.',
                $preflight
            );
        }

        // ETB and TBB remain part of the same close transaction. Their final
        // rows are written while the incident is still active; any later
        // failure rolls the rows and the administrative close back together.
        estab_logbook_lifecycle_close_books(
            $connection,
            $incident,
            $close['ende'],
            $close['note'],
            $actor
        );

        $nextRevision = $expectedRevision + 1;
        $statusStatement = $connection->prepare(
            'UPDATE `nv_einsatz_status`'
            . ' SET `active_einsatz_id` = NULL, `revision` = ?,'
            . ' `geaendert_am` = NOW(6), `geaendert_von` = ?'
            . ' WHERE `singleton_id` = 1 AND `active_einsatz_id` = ?'
            . ' AND `revision` = ?'
        );
        if (!$statusStatement) {
            throw new RuntimeException('Einsatzabschluss konnte nicht vorbereitet werden.');
        }
        try {
            $statusStatement->bind_param(
                'isii',
                $nextRevision,
                $actor,
                $expectedIncidentId,
                $expectedRevision
            );
            if (
                !$statusStatement->execute()
                || $statusStatement->affected_rows !== 1
            ) {
                throw new EstabIncidentConflictException(
                    'Der aktive Einsatz wurde zwischenzeitlich geändert.'
                );
            }
        } finally {
            $statusStatement->close();
        }

        $closeStatement = $connection->prepare(
            'UPDATE `nv_einsaetze`'
            . " SET `ende` = ?, `estab_status` = 'closed',"
            . ' `estab_closed_at` = NOW(6), `estab_closed_by` = ?,'
            . ' `estab_close_note` = ?,'
            . ' `estab_retain_until` = DATE_ADD(NOW(6), INTERVAL 10 YEAR)'
            . " WHERE `einsatz_id` = ? AND `estab_status` = 'open'"
        );
        if (!$closeStatement) {
            throw new RuntimeException('Einsatzabschluss konnte nicht vorbereitet werden.');
        }
        try {
            $closeStatement->bind_param(
                'sssi',
                $close['ende'],
                $actor,
                $close['note'],
                $expectedIncidentId
            );
            if (
                !$closeStatement->execute()
                || $closeStatement->affected_rows !== 1
            ) {
                throw new EstabIncidentConflictException(
                    'Der Einsatz wurde zwischenzeitlich abgeschlossen.'
                );
            }
        } finally {
            $closeStatement->close();
        }

        $closed = estab_incident_fetch_for_update($connection, $expectedIncidentId);
        estab_incident_audit(
            $connection,
            $expectedIncidentId,
            'abgeschlossen',
            $actor,
            $nextRevision,
            [
                'tatsaechliches_ende' => $close['ende'],
                'abschlussvermerk' => $close['note'],
                'aufbewahrung_bis' => $closed['estab_retain_until'],
                'preflight' => $preflight,
            ]
        );
        if (!$connection->commit()) {
            throw new RuntimeException('Einsatzabschluss konnte nicht gespeichert werden.');
        }
        return [
            'einsatz_id' => $expectedIncidentId,
            'kennung' => (string) ($incident['kennung'] ?? ''),
            'status' => 'closed',
            'closed_at' => $closed['estab_closed_at'],
            'retain_until' => $closed['estab_retain_until'],
            'status_revision' => $nextRevision,
        ];
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/**
 * Set or release a durable legal hold independently of the ten-year minimum.
 *
 * Releasing a hold never shortens `estab_retain_until`.
 */
function estab_incident_set_legal_hold(
    mysqli $connection,
    int $incidentId,
    bool $enabled,
    mixed $reason,
    string $actor
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $actor = estab_incident_actor($actor);
    $normalizedReason = $enabled
        ? estab_incident_text(
            $reason,
            'Grund der Aufbewahrungssperre',
            ESTAB_INCIDENT_LEGAL_HOLD_REASON_MAX_LENGTH,
            true
        )
        : null;

    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Aufbewahrungssperre konnte nicht begonnen werden.');
    }
    try {
        $incident = estab_incident_fetch_for_update($connection, $incidentId);
        $enabledInt = $enabled ? 1 : 0;
        $statement = $connection->prepare(
            'UPDATE `nv_einsaetze`'
            . ' SET `estab_legal_hold` = ?, `estab_legal_hold_reason` = ?,'
            . ' `estab_legal_hold_at` = NOW(6), `estab_legal_hold_by` = ?'
            . ' WHERE `einsatz_id` = ?'
        );
        if (!$statement) {
            throw new RuntimeException(
                'Aufbewahrungssperre konnte nicht vorbereitet werden.'
            );
        }
        try {
            $statement->bind_param(
                'issi',
                $enabledInt,
                $normalizedReason,
                $actor,
                $incidentId
            );
            if (!$statement->execute() || $statement->affected_rows !== 1) {
                throw new RuntimeException(
                    'Aufbewahrungssperre konnte nicht gespeichert werden.'
                );
            }
        } finally {
            $statement->close();
        }
        estab_incident_audit(
            $connection,
            $incidentId,
            $enabled ? 'legal_hold_gesetzt' : 'legal_hold_geloest',
            $actor,
            null,
            ['grund' => $normalizedReason]
        );
        if (!$connection->commit()) {
            throw new RuntimeException(
                'Aufbewahrungssperre konnte nicht gespeichert werden.'
            );
        }
        $incident['estab_legal_hold'] = $enabled;
        $incident['estab_legal_hold_reason'] = $normalizedReason;
        $incident['estab_legal_hold_by'] = $actor;
        return $incident;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/** Explain whether the retention model permits a later administrative purge. */
function estab_incident_retention_state(array $incident): array
{
    $hold = (bool) ($incident['estab_legal_hold'] ?? false);
    $retainUntil = $incident['estab_retain_until'] ?? null;
    $expired = is_string($retainUntil)
        && $retainUntil !== ''
        && strcmp($retainUntil, date('Y-m-d H:i:s.u')) <= 0;
    return [
        'legal_hold' => $hold,
        'retain_until' => is_string($retainUntil) ? $retainUntil : null,
        'deletion_allowed' => !$hold && $expired,
    ];
}
