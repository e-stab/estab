<?php

declare(strict_types=1);

/**
 * Optional access-shift administration.
 *
 * Access shifts are incident-scoped account groups. They never assign a
 * function, role or operational capability. An account without memberships
 * remains individually admitted; an account with memberships is admitted
 * when at least one of its current memberships belongs to an enabled shift.
 * The durable administrative block on nv_benutzer remains independent and
 * always wins at the authentication boundary.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dv_operations.php';
require_once __DIR__ . '/incident.php';
require_once __DIR__ . '/user_admin.php';

const ESTAB_SHIFT_ACCESS_LABEL_MAX_LENGTH = 100;
const ESTAB_SHIFT_ACCESS_LOCK_TIMEOUT = 10;

final class EstabShiftAccessInputException extends InvalidArgumentException
{
}

final class EstabShiftAccessConflictException extends RuntimeException
{
}

final class EstabShiftAccessBusyException extends RuntimeException
{
}

function estab_shift_access_positive_id(mixed $value, string $label): int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (
        !is_string($value)
        || preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1
    ) {
        throw new EstabShiftAccessInputException($label . ' ist ungültig.');
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($parsed) || $parsed < 1) {
        throw new EstabShiftAccessInputException($label . ' ist ungültig.');
    }
    return $parsed;
}

function estab_shift_access_code(mixed $value): string
{
    if (!is_string($value)) {
        throw new EstabShiftAccessInputException(
            'Das Benutzerkürzel ist ungültig.'
        );
    }
    $code = strtolower(trim($value));
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1) {
        throw new EstabShiftAccessInputException(
            'Das Benutzerkürzel ist ungültig.'
        );
    }
    return $code;
}

function estab_shift_access_actor(mixed $value): string
{
    try {
        return estab_incident_actor($value);
    } catch (EstabIncidentInputException $exception) {
        throw new EstabShiftAccessInputException(
            'Die administrative Identität ist ungültig.',
            previous: $exception
        );
    }
}

function estab_shift_access_label(mixed $value): string
{
    try {
        return estab_incident_text(
            $value,
            'Schichtbezeichnung',
            ESTAB_SHIFT_ACCESS_LABEL_MAX_LENGTH,
            true
        );
    } catch (EstabIncidentInputException $exception) {
        throw new EstabShiftAccessInputException(
            $exception->getMessage(),
            previous: $exception
        );
    }
}

function estab_shift_access_optional_datetime(
    mixed $value,
    string $label
): ?string {
    try {
        return estab_incident_datetime($value, $label, false);
    } catch (EstabIncidentInputException $exception) {
        throw new EstabShiftAccessInputException(
            $exception->getMessage(),
            previous: $exception
        );
    }
}

function estab_shift_access_confirmation_version_value(mixed $value): string
{
    if (
        !is_string($value)
        || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1
    ) {
        throw new EstabShiftAccessInputException(
            'Der bestätigte Stand der Schichtplanung ist ungültig.'
        );
    }
    return $value;
}

/** Return the current database name used to scope advisory locks. */
function estab_shift_access_database(mysqli $connection): string
{
    $result = $connection->query('SELECT DATABASE() AS `database_name`');
    if (!$result) {
        throw new RuntimeException(
            'Die Datenbank für die Schichtsteuerung konnte nicht ermittelt werden.'
        );
    }
    try {
        $row = $result->fetch_assoc();
    } finally {
        $result->free();
    }
    $database = is_array($row) ? ($row['database_name'] ?? null) : null;
    if (!is_string($database) || $database === '') {
        throw new RuntimeException(
            'Die Datenbank für die Schichtsteuerung ist ungültig.'
        );
    }
    return $database;
}

function estab_shift_access_policy_lock_name(string $database): string
{
    return 'estab:access-shift:' . substr(hash('sha256', $database), 0, 44);
}

function estab_shift_access_acquire_policy_lock(
    mysqli $connection,
    string $lockName
): void {
    $statement = $connection->prepare('SELECT GET_LOCK(?, ?)');
    if (!$statement) {
        throw new RuntimeException(
            'Die Schichtsteuerung konnte nicht gesperrt werden.'
        );
    }
    try {
        $timeout = ESTAB_SHIFT_ACCESS_LOCK_TIMEOUT;
        $statement->bind_param('si', $lockName, $timeout);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Die Schichtsteuerung konnte nicht gesperrt werden.'
            );
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
            throw new EstabShiftAccessBusyException(
                'Die Schichtplanung wird gerade geändert. Bitte versuchen '
                . 'Sie es erneut.'
            );
        }
    } finally {
        $statement->close();
    }
}

function estab_shift_access_release_policy_lock(
    mysqli $connection,
    string $lockName
): void {
    $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
    if (!$statement) {
        throw new RuntimeException(
            'Die Sperre der Schichtsteuerung konnte nicht gelöst werden.'
        );
    }
    try {
        $statement->bind_param('s', $lockName);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Die Sperre der Schichtsteuerung konnte nicht gelöst werden.'
            );
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
            throw new RuntimeException(
                'Die Sperre der Schichtsteuerung ging verloren.'
            );
        }
    } finally {
        $statement->close();
    }
}

/**
 * Serialize every roster mutation and every affected login account.
 *
 * The resolver runs while the global access-shift lock is held. Account
 * advisory locks use exactly the login/user-administration namespace, so a
 * concurrent login either completes before a revocation or observes the new
 * access state afterwards.
 */
function estab_shift_access_with_mutation_locks(
    mysqli $connection,
    callable $accountResolver,
    callable $operation
): mixed {
    $database = estab_shift_access_database($connection);
    $policyLock = estab_shift_access_policy_lock_name($database);
    $accountLocks = [];
    $policyLocked = false;
    try {
        estab_shift_access_acquire_policy_lock($connection, $policyLock);
        $policyLocked = true;
        $accountValues = $accountResolver();
        if (!is_array($accountValues)) {
            throw new LogicException(
                'Die Kontenauflösung der Schichtsteuerung ist ungültig.'
            );
        }
        $accountCodes = [];
        foreach ($accountValues as $accountValue) {
            $accountCodes[] = estab_shift_access_code($accountValue);
        }
        $accountCodes = array_values(array_unique($accountCodes));
        sort($accountCodes, SORT_STRING);
        foreach ($accountCodes as $accountCode) {
            $accountLock = estab_user_admin_account_lock_name(
                $database,
                'nv_benutzer',
                $accountCode
            );
            estab_user_admin_acquire_account_lock(
                $connection,
                $accountLock
            );
            $accountLocks[] = $accountLock;
        }
        return $operation();
    } catch (EstabUserAdminBusyException $exception) {
        throw new EstabShiftAccessBusyException(
            'Mindestens ein betroffenes Konto wird gerade verwendet. Bitte '
            . 'versuchen Sie es erneut.',
            previous: $exception
        );
    } finally {
        foreach (array_reverse($accountLocks) as $accountLock) {
            try {
                estab_user_admin_release_account_lock(
                    $connection,
                    $accountLock
                );
            } catch (Throwable $exception) {
                error_log(
                    'eStab access-shift account-lock cleanup failed: '
                    . $exception->getMessage()
                );
            }
        }
        if ($policyLocked) {
            try {
                estab_shift_access_release_policy_lock(
                    $connection,
                    $policyLock
                );
            } catch (Throwable $exception) {
                error_log(
                    'eStab access-shift policy-lock cleanup failed: '
                    . $exception->getMessage()
                );
            }
        }
    }
}

/**
 * Bound list-valued details before writing the legacy TEXT protocol column.
 *
 * The complete list remains in the incident audit and hash-chained operating
 * event.  Count, digest and a small sample keep the compatibility protocol
 * useful without allowing a large roster to exceed its 64-KiB field.
 */
function estab_shift_access_protocol_details(array $details): array
{
    $summary = $details;
    foreach (['members', 'revoked_accounts'] as $field) {
        if (!isset($summary[$field]) || !is_array($summary[$field])) {
            continue;
        }
        $values = array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            $summary[$field]
        ));
        $encoded = json_encode(
            $values,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
        );
        $summary[$field . '_count'] = count($values);
        $summary[$field . '_sha256'] = hash('sha256', $encoded);
        $summary[$field . '_sample'] = array_slice($values, 0, 10);
        unset($summary[$field]);
    }
    return $summary;
}

/** Append both incident and security audit evidence inside the transaction. */
function estab_shift_access_audit(
    mysqli $connection,
    string $protocolTable,
    int $incidentId,
    int $statusRevision,
    string $action,
    string $actor,
    array $details
): void {
    $shiftId = estab_shift_access_positive_id(
        $details['shift_id'] ?? null,
        'Zugangsschicht'
    );
    $payload = [
        'version' => 1,
        'action' => $action,
        'admin' => $actor,
        'incident_id' => $incidentId,
    ] + $details;
    estab_incident_audit(
        $connection,
        $incidentId,
        $action,
        $actor,
        $statusRevision,
        $details
    );
    estab_dv_event_append(
        $connection,
        $incidentId,
        'ZUGANGSSCHICHT',
        $shiftId,
        $action,
        $actor,
        null,
        $payload
    );
    $protocolPayload = [
        'version' => 1,
        'action' => $action,
        'admin' => $actor,
        'incident_id' => $incidentId,
    ] + estab_shift_access_protocol_details($details);
    $encoded = json_encode(
        $protocolPayload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
    );
    estab_auth_log_event(
        $connection,
        $protocolTable,
        'Zugangsschicht',
        $encoded
    );
}

/** Lock and return one shift belonging to the selected active incident. */
function estab_shift_access_shift_for_update(
    mysqli $connection,
    int $incidentId,
    int $shiftId
): array {
    $statement = $connection->prepare(
        'SELECT `zugangsschicht_id`, `einsatz_id`, `bezeichnung`, `beginn`,'
        . ' `ende`, `zugang_aktiv`, `erstellt_am`, `erstellt_von`,'
        . ' `geaendert_am`, `geaendert_von`'
        . ' FROM `nv_zugangsschichten`'
        . ' WHERE `zugangsschicht_id` = ? AND `einsatz_id` = ?'
        . ' FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Die Zugangsschicht konnte nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('ii', $shiftId, $incidentId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (!is_array($row)) {
        throw new EstabShiftAccessConflictException(
            'Die Zugangsschicht wurde nicht gefunden oder gehört nicht mehr '
            . 'zum aktiven Einsatz.'
        );
    }
    return $row;
}

/** Record every roster/status change on the parent shift itself. */
function estab_shift_access_touch_shift(
    mysqli $connection,
    int $incidentId,
    int $shiftId,
    string $actor
): void {
    $statement = $connection->prepare(
        'UPDATE `nv_zugangsschichten`'
        . ' SET `geaendert_am` = NOW(6), `geaendert_von` = ?'
        . ' WHERE `zugangsschicht_id` = ? AND `einsatz_id` = ?'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Der Änderungsnachweis der Zugangsschicht konnte nicht '
            . 'vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('sii', $actor, $shiftId, $incidentId);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Der Änderungsnachweis der Zugangsschicht konnte nicht '
                . 'gespeichert werden.'
            );
        }
    } finally {
        $statement->close();
    }
}

/** Return current member codes; callers use this under the policy lock. */
function estab_shift_access_current_member_codes(
    mysqli $connection,
    int $shiftId
): array {
    $statement = $connection->prepare(
        'SELECT `benutzer_kuerzel`'
        . ' FROM `nv_zugangsschicht_mitglieder`'
        . ' WHERE `zugangsschicht_id` = ? AND `entfernt_am` IS NULL'
        . ' ORDER BY `benutzer_kuerzel`'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Die Schichtmitglieder konnten nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('i', $shiftId);
        $statement->execute();
        $result = $statement->get_result();
        $codes = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $codes[] = (string) $row['benutzer_kuerzel'];
        }
        $result->free();
        return $codes;
    } finally {
        $statement->close();
    }
}

/**
 * Hash the access facts shown by one enable/disable confirmation.
 *
 * Every current membership and every shift status participates because either
 * can change the OR decision for a target member. The target accounts' block
 * and session states participate because those facts change the displayed
 * revocation effect. Labels and planning dates intentionally do not.
 */
function estab_shift_access_confirmation_version(
    array $shifts,
    int $targetShiftId
): string {
    $targetShiftId = estab_shift_access_positive_id(
        $targetShiftId,
        'Zugangsschicht'
    );
    $incidentId = null;
    $policyRows = [];
    $targetAccounts = [];
    $targetFound = false;
    foreach ($shifts as $shift) {
        if (!is_array($shift)) {
            throw new LogicException('Die Schichtübersicht ist ungültig.');
        }
        $shiftId = estab_shift_access_positive_id(
            $shift['zugangsschicht_id'] ?? null,
            'Zugangsschicht'
        );
        $shiftIncidentId = estab_incident_positive_id(
            $shift['einsatz_id'] ?? null
        );
        if ($incidentId === null) {
            $incidentId = $shiftIncidentId;
        } elseif ($incidentId !== $shiftIncidentId) {
            throw new LogicException(
                'Die Schichtübersicht enthält mehrere Einsätze.'
            );
        }
        $members = [];
        foreach (($shift['mitglieder'] ?? []) as $member) {
            if (!is_array($member)) {
                throw new LogicException('Die Schichtzuordnung ist ungültig.');
            }
            $memberId = estab_shift_access_positive_id(
                $member['zugangsschicht_mitglied_id'] ?? null,
                'Schichtzuordnung'
            );
            $memberCode = estab_shift_access_code(
                $member['benutzer_kuerzel'] ?? null
            );
            $members[] = [$memberId, $memberCode];
            if ($shiftId === $targetShiftId) {
                $targetAccounts[] = [
                    $memberId,
                    $memberCode,
                    (int) ($member['estab_gesperrt'] ?? 1) === 1 ? 1 : 0,
                    (int) ($member['session_present'] ?? 0) === 1 ? 1 : 0,
                ];
            }
        }
        usort(
            $members,
            static fn (array $left, array $right): int => $left <=> $right
        );
        $policyRows[] = [
            $shiftId,
            (int) ($shift['zugang_aktiv'] ?? 0) === 1 ? 1 : 0,
            $members,
        ];
        if ($shiftId === $targetShiftId) {
            $targetFound = true;
        }
    }
    if (!$targetFound || $incidentId === null) {
        throw new EstabShiftAccessConflictException(
            'Die ausgewählte Schicht wurde nicht mehr gefunden.'
        );
    }
    usort(
        $policyRows,
        static fn (array $left, array $right): int => $left[0] <=> $right[0]
    );
    usort(
        $targetAccounts,
        static fn (array $left, array $right): int => $left <=> $right
    );
    return hash(
        'sha256',
        json_encode(
            [$incidentId, $targetShiftId, $policyRows, $targetAccounts],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        )
    );
}

/**
 * Resolve memberships and effective shift access for one account/incident.
 *
 * @return array{managed:bool,allowed:bool,memberships:list<array<string,mixed>>}
 */
function estab_shift_access_user_state(
    mysqli $connection,
    int $incidentId,
    string $userCode
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $userCode = estab_shift_access_code($userCode);
    $memberships = estab_shift_access_user_memberships(
        $connection,
        $incidentId,
        $userCode
    );
    $allowed = $memberships === [];
    foreach ($memberships as $membership) {
        if ((int) ($membership['zugang_aktiv'] ?? 0) === 1) {
            $allowed = true;
            break;
        }
    }
    return [
        'managed' => $memberships !== [],
        'allowed' => $allowed,
        'memberships' => $memberships,
    ];
}

/** Revoke one current session only when shift policy now denies the account. */
function estab_shift_access_revoke_if_denied(
    mysqli $connection,
    int $incidentId,
    string $userCode
): bool {
    $state = estab_shift_access_user_state(
        $connection,
        $incidentId,
        $userCode
    );
    if ($state['allowed']) {
        return false;
    }
    $statement = $connection->prepare(
        'SELECT `aktiv`, `sid`, `ip`, `fwdip` FROM `nv_benutzer`'
        . ' WHERE BINARY `kuerzel` = BINARY ? LIMIT 1 FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Das betroffene Konto konnte nicht gesperrt werden.'
        );
    }
    try {
        $statement->bind_param('s', $userCode);
        $statement->execute();
        $account = $statement->get_result()->fetch_assoc();
    } finally {
        $statement->close();
    }
    if (!is_array($account)) {
        throw new EstabShiftAccessConflictException(
            'Das betroffene Benutzerkonto wurde nicht gefunden.'
        );
    }
    $hadSession = (int) ($account['aktiv'] ?? 0) === 1
        || (string) ($account['sid'] ?? '') !== ''
        || (string) ($account['ip'] ?? '') !== ''
        || (string) ($account['fwdip'] ?? '') !== '';
    if (!$hadSession) {
        return false;
    }
    $update = $connection->prepare(
        "UPDATE `nv_benutzer` SET `aktiv` = 0, `sid` = '', `ip` = '',"
        . " `fwdip` = '' WHERE BINARY `kuerzel` = BINARY ?"
    );
    if (!$update) {
        throw new RuntimeException(
            'Die bestehende Sitzung konnte nicht widerrufen werden.'
        );
    }
    try {
        $update->bind_param('s', $userCode);
        if (!$update->execute() || $update->affected_rows !== 1) {
            throw new RuntimeException(
                'Die bestehende Sitzung konnte nicht widerrufen werden.'
            );
        }
    } finally {
        $update->close();
    }
    return true;
}

/** Return every shift with its currently assigned accounts. */
function estab_shift_access_list(
    mysqli $connection,
    int $incidentId
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT shift_row.`zugangsschicht_id`, shift_row.`einsatz_id`,'
        . ' shift_row.`bezeichnung`, shift_row.`beginn`, shift_row.`ende`,'
        . ' shift_row.`zugang_aktiv`, shift_row.`erstellt_am`,'
        . ' shift_row.`erstellt_von`, shift_row.`geaendert_am`,'
        . ' shift_row.`geaendert_von`,'
        . ' membership.`zugangsschicht_mitglied_id`,'
        . ' membership.`benutzer_kuerzel`, membership.`zugeordnet_am`,'
        . ' membership.`zugeordnet_von`, account.`benutzer`,'
        . ' account.`funktion`, account.`rolle`, account.`aktiv`,'
        . ' account.`estab_gesperrt`,'
        . " CASE WHEN account.`aktiv` = 1 OR account.`sid` <> ''"
        . " OR account.`ip` <> '' OR account.`fwdip` <> ''"
        . ' THEN 1 ELSE 0 END AS `session_present`'
        . ' FROM `nv_zugangsschichten` AS shift_row'
        . ' LEFT JOIN `nv_zugangsschicht_mitglieder` AS membership'
        . ' ON membership.`zugangsschicht_id` ='
        . ' shift_row.`zugangsschicht_id`'
        . ' AND membership.`entfernt_am` IS NULL'
        . ' LEFT JOIN `nv_benutzer` AS account'
        . ' ON BINARY account.`kuerzel` ='
        . ' BINARY membership.`benutzer_kuerzel`'
        . ' WHERE shift_row.`einsatz_id` = ?'
        . ' ORDER BY shift_row.`zugang_aktiv` DESC,'
        . ' COALESCE(shift_row.`beginn`, shift_row.`erstellt_am`),'
        . ' shift_row.`zugangsschicht_id`, account.`benutzer`,'
        . ' membership.`benutzer_kuerzel`'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Die Zugangsschichten konnten nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('i', $incidentId);
        $statement->execute();
        $result = $statement->get_result();
        $shifts = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $shiftId = (int) $row['zugangsschicht_id'];
            if (!isset($shifts[$shiftId])) {
                $shifts[$shiftId] = [
                    'zugangsschicht_id' => $shiftId,
                    'einsatz_id' => (int) $row['einsatz_id'],
                    'bezeichnung' => (string) $row['bezeichnung'],
                    'beginn' => $row['beginn'],
                    'ende' => $row['ende'],
                    'zugang_aktiv' => (int) $row['zugang_aktiv'],
                    'erstellt_am' => (string) $row['erstellt_am'],
                    'erstellt_von' => (string) $row['erstellt_von'],
                    'geaendert_am' => (string) $row['geaendert_am'],
                    'geaendert_von' => (string) $row['geaendert_von'],
                    'mitglieder' => [],
                ];
            }
            if ($row['zugangsschicht_mitglied_id'] !== null) {
                $shifts[$shiftId]['mitglieder'][] = [
                    'zugangsschicht_mitglied_id' =>
                        (int) $row['zugangsschicht_mitglied_id'],
                    'benutzer_kuerzel' =>
                        (string) $row['benutzer_kuerzel'],
                    'benutzer' => (string) ($row['benutzer'] ?? ''),
                    'funktion' => (string) ($row['funktion'] ?? ''),
                    'rolle' => (string) ($row['rolle'] ?? ''),
                    'aktiv' => (int) ($row['aktiv'] ?? 0),
                    'session_present' =>
                        (int) ($row['session_present'] ?? 0),
                    'estab_gesperrt' =>
                        (int) ($row['estab_gesperrt'] ?? 1),
                    'zugeordnet_am' => (string) $row['zugeordnet_am'],
                    'zugeordnet_von' => (string) $row['zugeordnet_von'],
                ];
            }
        }
        $result->free();
        return array_values($shifts);
    } finally {
        $statement->close();
    }
}

/** Return current shift memberships for one account. */
function estab_shift_access_user_memberships(
    mysqli $connection,
    int $incidentId,
    string $userCode
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $userCode = estab_shift_access_code($userCode);
    $statement = $connection->prepare(
        'SELECT membership.`zugangsschicht_mitglied_id`,'
        . ' membership.`zugangsschicht_id`, membership.`benutzer_kuerzel`,'
        . ' membership.`zugeordnet_am`, membership.`zugeordnet_von`,'
        . ' shift_row.`bezeichnung`, shift_row.`beginn`, shift_row.`ende`,'
        . ' shift_row.`zugang_aktiv`'
        . ' FROM `nv_zugangsschicht_mitglieder` AS membership'
        . ' JOIN `nv_zugangsschichten` AS shift_row'
        . ' ON shift_row.`zugangsschicht_id` ='
        . ' membership.`zugangsschicht_id`'
        . ' WHERE shift_row.`einsatz_id` = ?'
        . ' AND BINARY membership.`benutzer_kuerzel` = BINARY ?'
        . ' AND membership.`entfernt_am` IS NULL'
        . ' ORDER BY shift_row.`zugang_aktiv` DESC, shift_row.`bezeichnung`,'
        . ' shift_row.`zugangsschicht_id`'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Die Benutzer-Schichtzuordnungen konnten nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('is', $incidentId, $userCode);
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return is_array($rows) ? array_values($rows) : [];
    } finally {
        $statement->close();
    }
}

/** Create a disabled optional access shift for the active incident. */
function estab_shift_access_create(
    mysqli $connection,
    int $incidentId,
    mixed $labelValue,
    mixed $beginValue,
    mixed $endValue,
    string $actor,
    string $protocolTable = 'nv_protokoll'
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $label = estab_shift_access_label($labelValue);
    $begin = estab_shift_access_optional_datetime($beginValue, 'Beginn');
    $end = estab_shift_access_optional_datetime($endValue, 'Ende');
    if ($begin !== null && $end !== null && strcmp($end, $begin) < 0) {
        throw new EstabShiftAccessInputException(
            'Das Schichtende darf nicht vor dem Schichtbeginn liegen.'
        );
    }
    $actor = estab_shift_access_actor($actor);

    return estab_shift_access_with_mutation_locks(
        $connection,
        static fn (): array => [],
        static function () use (
            $connection,
            $incidentId,
            $label,
            $begin,
            $end,
            $actor,
            $protocolTable
        ): array {
            return estab_incident_with_active_write(
                $connection,
                static function (array $incident) use (
                    $connection,
                    $incidentId,
                    $label,
                    $begin,
                    $end,
                    $actor,
                    $protocolTable
                ): array {
                    if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                        throw new EstabShiftAccessConflictException(
                            'Der ausgewählte Einsatz ist nicht mehr aktiv.'
                        );
                    }
                    $duplicate = $connection->prepare(
                        'SELECT `zugangsschicht_id`'
                        . ' FROM `nv_zugangsschichten`'
                        . ' WHERE `einsatz_id` = ? AND `bezeichnung` = ?'
                        . ' LIMIT 1 FOR UPDATE'
                    );
                    if (!$duplicate) {
                        throw new RuntimeException(
                            'Die Schichtbezeichnung konnte nicht geprüft werden.'
                        );
                    }
                    try {
                        $duplicate->bind_param('is', $incidentId, $label);
                        if (!$duplicate->execute()) {
                            throw new RuntimeException(
                                'Die Schichtbezeichnung konnte nicht geprüft werden.'
                            );
                        }
                        $duplicateRow = $duplicate
                            ->get_result()
                            ->fetch_assoc();
                    } finally {
                        $duplicate->close();
                    }
                    if (is_array($duplicateRow)) {
                        throw new EstabShiftAccessConflictException(
                            'Eine Schicht mit dieser Bezeichnung ist im aktiven '
                            . 'Einsatz bereits vorhanden.'
                        );
                    }
                    $insert = $connection->prepare(
                        'INSERT INTO `nv_zugangsschichten`'
                        . ' (`einsatz_id`, `bezeichnung`, `beginn`, `ende`,'
                        . ' `zugang_aktiv`, `erstellt_am`, `erstellt_von`,'
                        . ' `geaendert_am`, `geaendert_von`)'
                        . ' VALUES (?, ?, ?, ?, 0, NOW(6), ?, NOW(6), ?)'
                    );
                    if (!$insert) {
                        throw new RuntimeException(
                            'Die Zugangsschicht konnte nicht vorbereitet werden.'
                        );
                    }
                    try {
                        $insert->bind_param(
                            'isssss',
                            $incidentId,
                            $label,
                            $begin,
                            $end,
                            $actor,
                            $actor
                        );
                        if (!$insert->execute()) {
                            throw new RuntimeException(
                                'Die Zugangsschicht konnte nicht angelegt werden.'
                            );
                        }
                        $shiftId = (int) $connection->insert_id;
                    } finally {
                        $insert->close();
                    }
                    estab_shift_access_audit(
                        $connection,
                        $protocolTable,
                        $incidentId,
                        (int) $incident['revision'],
                        'zugangsschicht_angelegt',
                        $actor,
                        [
                            'shift_id' => $shiftId,
                            'label' => $label,
                            'begin' => $begin,
                            'end' => $end,
                            'access_enabled' => false,
                        ]
                    );
                    return [
                        'zugangsschicht_id' => $shiftId,
                        'bezeichnung' => $label,
                        'zugang_aktiv' => false,
                    ];
                }
            );
        }
    );
}

/** Append one current account-membership interval. */
function estab_shift_access_add_member(
    mysqli $connection,
    int $incidentId,
    int $shiftId,
    mixed $userCodeValue,
    string $actor,
    string $protocolTable = 'nv_protokoll'
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $shiftId = estab_shift_access_positive_id(
        $shiftId,
        'Zugangsschicht'
    );
    $userCode = estab_shift_access_code($userCodeValue);
    $actor = estab_shift_access_actor($actor);

    return estab_shift_access_with_mutation_locks(
        $connection,
        static fn (): array => [$userCode],
        static function () use (
            $connection,
            $incidentId,
            $shiftId,
            $userCode,
            $actor,
            $protocolTable
        ): array {
            return estab_incident_with_active_write(
                $connection,
                static function (array $incident) use (
                    $connection,
                    $incidentId,
                    $shiftId,
                    $userCode,
                    $actor,
                    $protocolTable
                ): array {
                    if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                        throw new EstabShiftAccessConflictException(
                            'Der ausgewählte Einsatz ist nicht mehr aktiv.'
                        );
                    }
                    $shift = estab_shift_access_shift_for_update(
                        $connection,
                        $incidentId,
                        $shiftId
                    );
                    $accountStatement = $connection->prepare(
                        'SELECT `benutzer`, `kuerzel`, `funktion`, `rolle`,'
                        . ' `estab_gesperrt` FROM `nv_benutzer`'
                        . ' WHERE BINARY `kuerzel` = BINARY ?'
                        . ' LIMIT 1 FOR UPDATE'
                    );
                    if (!$accountStatement) {
                        throw new RuntimeException(
                            'Das Benutzerkonto konnte nicht vorbereitet werden.'
                        );
                    }
                    try {
                        $accountStatement->bind_param('s', $userCode);
                        $accountStatement->execute();
                        $account = $accountStatement
                            ->get_result()
                            ->fetch_assoc();
                    } finally {
                        $accountStatement->close();
                    }
                    if (!is_array($account)) {
                        throw new EstabShiftAccessConflictException(
                            'Das ausgewählte Benutzerkonto wurde nicht gefunden.'
                        );
                    }
                    $select = $connection->prepare(
                        'SELECT `zugangsschicht_mitglied_id`'
                        . ' FROM `nv_zugangsschicht_mitglieder`'
                        . ' WHERE `zugangsschicht_id` = ?'
                        . ' AND BINARY `benutzer_kuerzel` = BINARY ?'
                        . ' AND `entfernt_am` IS NULL LIMIT 1 FOR UPDATE'
                    );
                    if (!$select) {
                        throw new RuntimeException(
                            'Die Schichtzuordnung konnte nicht vorbereitet werden.'
                        );
                    }
                    try {
                        $select->bind_param('is', $shiftId, $userCode);
                        $select->execute();
                        $membership = $select->get_result()->fetch_assoc();
                    } finally {
                        $select->close();
                    }
                    if (is_array($membership)) {
                        throw new EstabShiftAccessConflictException(
                            'Das Konto gehört bereits zu dieser Schicht.'
                        );
                    }
                    $write = $connection->prepare(
                        'INSERT INTO `nv_zugangsschicht_mitglieder`'
                        . ' (`zugangsschicht_id`, `benutzer_kuerzel`,'
                        . ' `zugeordnet_am`, `zugeordnet_von`)'
                        . ' VALUES (?, ?, NOW(6), ?)'
                    );
                    if (!$write) {
                        throw new RuntimeException(
                            'Die Zuordnung konnte nicht vorbereitet werden.'
                        );
                    }
                    try {
                        $write->bind_param(
                            'iss',
                            $shiftId,
                            $userCode,
                            $actor
                        );
                        if (!$write->execute()) {
                            throw new RuntimeException(
                                'Die Zuordnung konnte nicht gespeichert werden.'
                            );
                        }
                        $membershipId = (int) $connection->insert_id;
                    } finally {
                        $write->close();
                    }
                    estab_shift_access_touch_shift(
                        $connection,
                        $incidentId,
                        $shiftId,
                        $actor
                    );
                    $sessionRevoked = estab_shift_access_revoke_if_denied(
                        $connection,
                        $incidentId,
                        $userCode
                    );
                    estab_shift_access_audit(
                        $connection,
                        $protocolTable,
                        $incidentId,
                        (int) $incident['revision'],
                        'schicht_mitglied_zugeordnet',
                        $actor,
                        [
                            'shift_id' => $shiftId,
                            'shift_label' => (string) $shift['bezeichnung'],
                            'membership_id' => $membershipId,
                            'target' => $userCode,
                            'target_function' =>
                                (string) $account['funktion'],
                            'target_role' => (string) $account['rolle'],
                            'account_blocked' =>
                                (int) $account['estab_gesperrt'] === 1,
                            'session_revoked' => $sessionRevoked,
                        ]
                    );
                    return [
                        'zugangsschicht_mitglied_id' => $membershipId,
                        'benutzer_kuerzel' => $userCode,
                        'session_revoked' => $sessionRevoked,
                    ];
                }
            );
        }
    );
}

/** Soft-remove one current membership while retaining its history. */
function estab_shift_access_remove_member(
    mysqli $connection,
    int $incidentId,
    int $shiftId,
    mixed $userCodeValue,
    mixed $expectedMembershipIdValue,
    mixed $expectedConfirmationVersionValue,
    string $actor,
    string $protocolTable = 'nv_protokoll'
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $shiftId = estab_shift_access_positive_id(
        $shiftId,
        'Zugangsschicht'
    );
    $userCode = estab_shift_access_code($userCodeValue);
    $expectedMembershipId = estab_shift_access_positive_id(
        $expectedMembershipIdValue,
        'Bestätigte Schichtzuordnung'
    );
    $expectedConfirmationVersion =
        estab_shift_access_confirmation_version_value(
            $expectedConfirmationVersionValue
        );
    $actor = estab_shift_access_actor($actor);

    return estab_shift_access_with_mutation_locks(
        $connection,
        static fn (): array => [$userCode],
        static function () use (
            $connection,
            $incidentId,
            $shiftId,
            $userCode,
            $expectedMembershipId,
            $expectedConfirmationVersion,
            $actor,
            $protocolTable
        ): array {
            return estab_incident_with_active_write(
                $connection,
                static function (array $incident) use (
                    $connection,
                    $incidentId,
                    $shiftId,
                    $userCode,
                    $expectedMembershipId,
                    $expectedConfirmationVersion,
                    $actor,
                    $protocolTable
                ): array {
                    if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                        throw new EstabShiftAccessConflictException(
                            'Der ausgewählte Einsatz ist nicht mehr aktiv.'
                        );
                    }
                    $shift = estab_shift_access_shift_for_update(
                        $connection,
                        $incidentId,
                        $shiftId
                    );
                    $currentConfirmationVersion =
                        estab_shift_access_confirmation_version(
                            estab_shift_access_list($connection, $incidentId),
                            $shiftId
                        );
                    if (!hash_equals(
                        $expectedConfirmationVersion,
                        $currentConfirmationVersion
                    )) {
                        throw new EstabShiftAccessConflictException(
                            'Schichtzuordnungen, Zugangsstatus oder das '
                            . 'betroffene Konto wurden zwischenzeitlich '
                            . 'geändert. Bitte prüfen Sie die aktuelle Wirkung '
                            . 'erneut.'
                        );
                    }
                    $select = $connection->prepare(
                        'SELECT `zugangsschicht_mitglied_id`'
                        . ' FROM `nv_zugangsschicht_mitglieder`'
                        . ' WHERE `zugangsschicht_id` = ?'
                        . ' AND `zugangsschicht_mitglied_id` = ?'
                        . ' AND BINARY `benutzer_kuerzel` = BINARY ?'
                        . ' AND `entfernt_am` IS NULL LIMIT 1 FOR UPDATE'
                    );
                    if (!$select) {
                        throw new RuntimeException(
                            'Die Schichtzuordnung konnte nicht vorbereitet werden.'
                        );
                    }
                    try {
                        $select->bind_param(
                            'iis',
                            $shiftId,
                            $expectedMembershipId,
                            $userCode
                        );
                        $select->execute();
                        $membership = $select->get_result()->fetch_assoc();
                    } finally {
                        $select->close();
                    }
                    if (!is_array($membership)) {
                        throw new EstabShiftAccessConflictException(
                            'Das Konto gehört nicht mehr zu dieser Schicht.'
                        );
                    }
                    $membershipId = (int) $membership[
                        'zugangsschicht_mitglied_id'
                    ];
                    $update = $connection->prepare(
                        'UPDATE `nv_zugangsschicht_mitglieder`'
                        . ' SET `entfernt_am` = NOW(6), `entfernt_von` = ?'
                        . ' WHERE `zugangsschicht_mitglied_id` = ?'
                        . ' AND `entfernt_am` IS NULL'
                    );
                    if (!$update) {
                        throw new RuntimeException(
                            'Das Entfernen konnte nicht vorbereitet werden.'
                        );
                    }
                    try {
                        $update->bind_param('si', $actor, $membershipId);
                        if (!$update->execute() || $update->affected_rows !== 1) {
                            throw new EstabShiftAccessConflictException(
                                'Die Zuordnung wurde zwischenzeitlich geändert.'
                            );
                        }
                    } finally {
                        $update->close();
                    }
                    estab_shift_access_touch_shift(
                        $connection,
                        $incidentId,
                        $shiftId,
                        $actor
                    );
                    $sessionRevoked = estab_shift_access_revoke_if_denied(
                        $connection,
                        $incidentId,
                        $userCode
                    );
                    estab_shift_access_audit(
                        $connection,
                        $protocolTable,
                        $incidentId,
                        (int) $incident['revision'],
                        'schicht_mitglied_entfernt',
                        $actor,
                        [
                            'shift_id' => $shiftId,
                            'shift_label' => (string) $shift['bezeichnung'],
                            'membership_id' => $membershipId,
                            'target' => $userCode,
                            'session_revoked' => $sessionRevoked,
                        ]
                    );
                    return [
                        'benutzer_kuerzel' => $userCode,
                        'session_revoked' => $sessionRevoked,
                    ];
                }
            );
        }
    );
}

/** Enable or disable all access through one optional shift. */
function estab_shift_access_set_enabled(
    mysqli $connection,
    int $incidentId,
    int $shiftId,
    bool $enabled,
    ?bool $expectedEnabled,
    mixed $expectedConfirmationVersionValue,
    string $actor,
    string $protocolTable = 'nv_protokoll'
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $shiftId = estab_shift_access_positive_id(
        $shiftId,
        'Zugangsschicht'
    );
    $actor = estab_shift_access_actor($actor);
    $expectedConfirmationVersion =
        estab_shift_access_confirmation_version_value(
            $expectedConfirmationVersionValue
        );

    return estab_shift_access_with_mutation_locks(
        $connection,
        static fn (): array => estab_shift_access_current_member_codes(
            $connection,
            $shiftId
        ),
        static function () use (
            $connection,
            $incidentId,
            $shiftId,
            $enabled,
            $expectedEnabled,
            $expectedConfirmationVersion,
            $actor,
            $protocolTable
        ): array {
            return estab_incident_with_active_write(
                $connection,
                static function (array $incident) use (
                    $connection,
                    $incidentId,
                    $shiftId,
                    $enabled,
                    $expectedEnabled,
                    $expectedConfirmationVersion,
                    $actor,
                    $protocolTable
                ): array {
                    if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                        throw new EstabShiftAccessConflictException(
                            'Der ausgewählte Einsatz ist nicht mehr aktiv.'
                        );
                    }
                    $shift = estab_shift_access_shift_for_update(
                        $connection,
                        $incidentId,
                        $shiftId
                    );
                    $currentConfirmationVersion =
                        estab_shift_access_confirmation_version(
                            estab_shift_access_list($connection, $incidentId),
                            $shiftId
                        );
                    if (!hash_equals(
                        $expectedConfirmationVersion,
                        $currentConfirmationVersion
                    )) {
                        throw new EstabShiftAccessConflictException(
                            'Schichtzuordnungen, Zugangsstatus oder betroffene '
                            . 'Konten wurden zwischenzeitlich geändert. Bitte '
                            . 'prüfen Sie die aktuelle Wirkung erneut.'
                        );
                    }
                    $currentEnabled = (int) $shift['zugang_aktiv'] === 1;
                    if (
                        $expectedEnabled !== null
                        && $currentEnabled !== $expectedEnabled
                    ) {
                        throw new EstabShiftAccessConflictException(
                            'Der Schichtstatus wurde zwischenzeitlich geändert.'
                        );
                    }
                    if ($currentEnabled === $enabled) {
                        return [
                            'changed' => false,
                            'zugang_aktiv' => $enabled,
                            'revoked_accounts' => [],
                        ];
                    }
                    $next = $enabled ? 1 : 0;
                    $update = $connection->prepare(
                        'UPDATE `nv_zugangsschichten`'
                        . ' SET `zugang_aktiv` = ?, `geaendert_am` = NOW(6),'
                        . ' `geaendert_von` = ?'
                        . ' WHERE `zugangsschicht_id` = ?'
                        . ' AND `einsatz_id` = ? AND `zugang_aktiv` = ?'
                    );
                    if (!$update) {
                        throw new RuntimeException(
                            'Der Schichtstatus konnte nicht vorbereitet werden.'
                        );
                    }
                    try {
                        $old = $currentEnabled ? 1 : 0;
                        $update->bind_param(
                            'isiii',
                            $next,
                            $actor,
                            $shiftId,
                            $incidentId,
                            $old
                        );
                        if (!$update->execute() || $update->affected_rows !== 1) {
                            throw new EstabShiftAccessConflictException(
                                'Der Schichtstatus wurde zwischenzeitlich geändert.'
                            );
                        }
                    } finally {
                        $update->close();
                    }
                    $memberCodes = estab_shift_access_current_member_codes(
                        $connection,
                        $shiftId
                    );
                    $revoked = [];
                    if (!$enabled) {
                        foreach ($memberCodes as $memberCode) {
                            if (estab_shift_access_revoke_if_denied(
                                $connection,
                                $incidentId,
                                $memberCode
                            )) {
                                $revoked[] = $memberCode;
                            }
                        }
                    }
                    estab_shift_access_audit(
                        $connection,
                        $protocolTable,
                        $incidentId,
                        (int) $incident['revision'],
                        $enabled
                            ? 'schicht_zugang_aktiviert'
                            : 'schicht_zugang_deaktiviert',
                        $actor,
                        [
                            'shift_id' => $shiftId,
                            'shift_label' => (string) $shift['bezeichnung'],
                            'access_enabled' => $enabled,
                            'members' => $memberCodes,
                            'revoked_accounts' => $revoked,
                        ]
                    );
                    return [
                        'changed' => true,
                        'zugang_aktiv' => $enabled,
                        'revoked_accounts' => $revoked,
                    ];
                }
            );
        }
    );
}
