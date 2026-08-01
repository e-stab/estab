<?php

declare(strict_types=1);

/**
 * Persistent policy boundary for public account creation.
 *
 * ENVIRONMENT preserves the deployment setting used before migration 114.
 * Every administrative write replaces that compatibility state with an
 * authoritative database mode. UNTIL deadlines and all policy decisions use
 * database UTC so an application/server clock difference cannot extend a
 * registration window.
 */

require_once __DIR__ . '/auth.php';

const ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT = 'ENVIRONMENT';
const ESTAB_SELF_REGISTRATION_MODE_DISABLED = 'DISABLED';
const ESTAB_SELF_REGISTRATION_MODE_PERMANENT = 'PERMANENT';
const ESTAB_SELF_REGISTRATION_MODE_UNTIL = 'UNTIL';
const ESTAB_SELF_REGISTRATION_ALLOWED_DURATIONS = [
    15,
    30,
    60,
    120,
    240,
    480,
    720,
    1440,
];
if (!defined('ESTAB_SELF_REGISTRATION_LOCK_TIMEOUT')) {
    define('ESTAB_SELF_REGISTRATION_LOCK_TIMEOUT', 10);
}

final class EstabSelfRegistrationInputException extends InvalidArgumentException
{
}

final class EstabSelfRegistrationConflictException extends RuntimeException
{
}

final class EstabSelfRegistrationBusyException extends RuntimeException
{
}

/** @return array<string,int|string|null> */
function estab_self_registration_defaults(): array
{
    return [
        'singleton_id' => 1,
        'mode' => ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT,
        'enabled_until_utc' => null,
        'revision' => 0,
        'updated_at' => '',
        'updated_by' => 'migration-114',
        'current_utc' => '',
    ];
}

/** Parse one canonical database UTC timestamp. */
function estab_self_registration_datetime(
    mixed $value,
    string $label
): DateTimeImmutable {
    if (!is_string($value) || $value === '') {
        throw new RuntimeException($label . ' ist ungültig.');
    }
    $timezone = new DateTimeZone('UTC');
    foreach (['Y-m-d H:i:s.u', 'Y-m-d H:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat(
            '!' . $format,
            $value,
            $timezone
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date instanceof DateTimeImmutable
            && ($errors === false || (
                (int) ($errors['warning_count'] ?? 0) === 0
                && (int) ($errors['error_count'] ?? 0) === 0
            ))
            && $date->format($format) === $value
        ) {
            return $date;
        }
    }
    throw new RuntimeException($label . ' ist ungültig.');
}

/** Parse one stored policy mode without case folding. */
function estab_self_registration_mode(
    mixed $value,
    bool $allowEnvironment = true
): string {
    $allowed = [
        ESTAB_SELF_REGISTRATION_MODE_DISABLED,
        ESTAB_SELF_REGISTRATION_MODE_PERMANENT,
        ESTAB_SELF_REGISTRATION_MODE_UNTIL,
    ];
    if ($allowEnvironment) {
        array_unshift($allowed, ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT);
    }
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        throw new EstabSelfRegistrationInputException(
            'Der Modus der Selbstregistrierung ist ungültig.'
        );
    }
    return $value;
}

/** @return array<string,int|string|null> */
function estab_self_registration_normalize_row(array $row): array
{
    $revision = filter_var($row['revision'] ?? null, FILTER_VALIDATE_INT);
    $updatedBy = $row['updated_by'] ?? null;
    $updatedAt = estab_self_registration_datetime(
        $row['updated_at'] ?? null,
        'Der Änderungszeitpunkt der Selbstregistrierung'
    );
    $currentUtc = estab_self_registration_datetime(
        $row['current_utc'] ?? null,
        'Die Datenbankzeit der Selbstregistrierung'
    );
    try {
        $mode = estab_self_registration_mode($row['mode'] ?? null);
    } catch (EstabSelfRegistrationInputException $exception) {
        throw new RuntimeException(
            'Die gespeicherte Selbstregistrierung ist ungültig.',
            0,
            $exception
        );
    }
    if (
        (int) ($row['singleton_id'] ?? 0) !== 1
        || !is_int($revision)
        || $revision < 0
        || !is_string($updatedBy)
        || estab_auth_text_length($updatedBy) < 1
        || estab_auth_text_length($updatedBy) > 128
        || preg_match('//u', $updatedBy) !== 1
        || preg_match('/[\p{C}]/u', $updatedBy) === 1
    ) {
        throw new RuntimeException(
            'Die gespeicherte Selbstregistrierung ist ungültig.'
        );
    }

    $deadlineValue = $row['enabled_until_utc'] ?? null;
    $deadline = null;
    if ($mode === ESTAB_SELF_REGISTRATION_MODE_UNTIL) {
        $deadline = estab_self_registration_datetime(
            $deadlineValue,
            'Die Befristung der Selbstregistrierung'
        );
    } elseif ($deadlineValue !== null) {
        throw new RuntimeException(
            'Die gespeicherte Selbstregistrierung ist ungültig.'
        );
    }

    return [
        'singleton_id' => 1,
        'mode' => $mode,
        'enabled_until_utc' => $deadline?->format('Y-m-d H:i:s.u'),
        'revision' => $revision,
        'updated_at' => $updatedAt->format('Y-m-d H:i:s.u'),
        'updated_by' => $updatedBy,
        'current_utc' => $currentUtc->format('Y-m-d H:i:s.u'),
    ];
}

/**
 * Load the singleton together with database UTC used for this decision.
 *
 * @return array<string,int|string|null>
 */
function estab_self_registration_load(
    mysqli $connection,
    bool $forUpdate = false
): array {
    $sql = 'SELECT `singleton_id`, `mode`, `enabled_until_utc`, `revision`,'
        . ' `updated_at`, `updated_by`, UTC_TIMESTAMP(6) AS `current_utc`'
        . ' FROM `nv_selbstregistrierung` WHERE `singleton_id` = 1'
        . ($forUpdate ? ' FOR UPDATE' : '');
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException(
            'Die Einstellung der Selbstregistrierung konnte nicht gelesen werden.'
        );
    }
    try {
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Die Einstellung der Selbstregistrierung konnte nicht gelesen werden.'
            );
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $secondRow = $result->fetch_assoc();
        $result->free();
        if (!is_array($row) || $secondRow !== null) {
            throw new RuntimeException(
                'Die Einstellung der Selbstregistrierung ist nicht eindeutig.'
            );
        }
        return estab_self_registration_normalize_row($row);
    } finally {
        $statement->close();
    }
}

/** Decide against the database time carried by a normalized policy row. */
function estab_self_registration_is_allowed(array $policy): bool
{
    $policy = estab_self_registration_normalize_row($policy);
    $mode = $policy['mode'];
    if ($mode === ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT) {
        return estab_auth_self_registration_allowed();
    }
    if ($mode === ESTAB_SELF_REGISTRATION_MODE_DISABLED) {
        return false;
    }
    if ($mode === ESTAB_SELF_REGISTRATION_MODE_PERMANENT) {
        return true;
    }
    $deadline = estab_self_registration_datetime(
        $policy['enabled_until_utc'],
        'Die Befristung der Selbstregistrierung'
    );
    $current = estab_self_registration_datetime(
        $policy['current_utc'],
        'Die Datenbankzeit der Selbstregistrierung'
    );
    return $deadline > $current;
}

/** Alias describing the policy decision in domain terminology. */
function estab_self_registration_effective(array $policy): bool
{
    return estab_self_registration_is_allowed($policy);
}

/** @return array<string,int|string|bool|null> */
function estab_self_registration_status(array $policy): array
{
    $policy = estab_self_registration_normalize_row($policy);
    $effective = estab_self_registration_is_allowed($policy);
    $mode = (string) $policy['mode'];
    $state = $mode;
    $remainingSeconds = null;
    if ($mode === ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT) {
        $state = $effective ? 'ENVIRONMENT_ENABLED' : 'ENVIRONMENT_DISABLED';
    } elseif ($mode === ESTAB_SELF_REGISTRATION_MODE_UNTIL) {
        $deadline = estab_self_registration_datetime(
            $policy['enabled_until_utc'],
            'Die Befristung der Selbstregistrierung'
        );
        $current = estab_self_registration_datetime(
            $policy['current_utc'],
            'Die Datenbankzeit der Selbstregistrierung'
        );
        $remainingSeconds = max(0, $deadline->getTimestamp() - $current->getTimestamp());
        $state = $effective ? ESTAB_SELF_REGISTRATION_MODE_UNTIL : 'EXPIRED';
    }
    return $policy + [
        'effective' => $effective,
        'state' => $state,
        'remaining_seconds' => $remainingSeconds,
    ];
}

/** Parse the optimistic policy revision emitted by the admin form. */
function estab_self_registration_revision(mixed $value): int
{
    if (is_int($value) && $value >= 0) {
        return $value;
    }
    if (
        !is_string($value)
        || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $value) !== 1
    ) {
        throw new EstabSelfRegistrationInputException(
            'Der Versionsstand der Selbstregistrierung ist ungültig.'
        );
    }
    $revision = (int) $value;
    if ((string) $revision !== $value || $revision < 0) {
        throw new EstabSelfRegistrationInputException(
            'Der Versionsstand der Selbstregistrierung ist ungültig.'
        );
    }
    return $revision;
}

/** Accept only the deliberately small set of operator-facing time windows. */
function estab_self_registration_duration_minutes(mixed $value): int
{
    if (is_int($value)) {
        $minutes = $value;
    } elseif (
        is_string($value)
        && preg_match('/\A[1-9][0-9]{0,3}\z/D', $value) === 1
    ) {
        $minutes = (int) $value;
    } else {
        throw new EstabSelfRegistrationInputException(
            'Der Zeitraum der Selbstregistrierung ist ungültig.'
        );
    }
    if (!in_array($minutes, ESTAB_SELF_REGISTRATION_ALLOWED_DURATIONS, true)) {
        throw new EstabSelfRegistrationInputException(
            'Der Zeitraum der Selbstregistrierung ist nicht freigegeben.'
        );
    }
    return $minutes;
}

/** @return array{mode:string,duration_minutes:?int} */
function estab_self_registration_configuration(array $input): array
{
    $mode = estab_self_registration_mode($input['mode'] ?? null, false);
    $durationValue = $input['duration_minutes'] ?? null;
    if ($mode === ESTAB_SELF_REGISTRATION_MODE_UNTIL) {
        $duration = estab_self_registration_duration_minutes($durationValue);
    } else {
        if ($durationValue !== null && $durationValue !== '') {
            throw new EstabSelfRegistrationInputException(
                'Dieser Modus darf keinen Zeitraum enthalten.'
            );
        }
        $duration = null;
    }
    return ['mode' => $mode, 'duration_minutes' => $duration];
}

/** Stable global advisory-lock name shared by admin and registration flow. */
function estab_self_registration_lock_name(string $database): string
{
    return 'estab:self-registration:'
        . substr(hash('sha256', $database), 0, 38);
}

function estab_self_registration_acquire_lock(
    mysqli $connection,
    string $database
): string {
    $timeout = ESTAB_SELF_REGISTRATION_LOCK_TIMEOUT;
    if (!is_int($timeout) || $timeout < 0 || $timeout > 30) {
        throw new RuntimeException(
            'Ungültiges Zeitlimit der Selbstregistrierung.'
        );
    }
    $lockName = estab_self_registration_lock_name($database);
    $statement = $connection->prepare('SELECT GET_LOCK(?, ?)');
    if (!$statement) {
        throw new RuntimeException(
            'Die Selbstregistrierung konnte nicht gesperrt werden.'
        );
    }
    try {
        $statement->bind_param('si', $lockName, $timeout);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Die Selbstregistrierung konnte nicht gesperrt werden.'
            );
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
            throw new EstabSelfRegistrationBusyException(
                'Die Selbstregistrierung wird gerade geändert. Bitte versuchen Sie es erneut.'
            );
        }
    } finally {
        $statement->close();
    }
    return $lockName;
}

function estab_self_registration_release_lock(
    mysqli $connection,
    string $lockName
): void {
    $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
    if (!$statement) {
        throw new RuntimeException(
            'Die Selbstregistrierung konnte nicht entsperrt werden.'
        );
    }
    try {
        $statement->bind_param('s', $lockName);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Die Selbstregistrierung konnte nicht entsperrt werden.'
            );
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
            throw new RuntimeException(
                'Die Sperre der Selbstregistrierung ging verloren.'
            );
        }
    } finally {
        $statement->close();
    }
}

/** Return a bounded Basic-Auth actor without propagating control characters. */
function estab_self_registration_actor(mixed $value): string
{
    if (
        !is_string($value)
        || estab_auth_text_length($value) < 1
        || estab_auth_text_length($value) > 128
        || preg_match('//u', $value) !== 1
        || preg_match('/[\p{C}]/u', $value) === 1
    ) {
        return 'unknown';
    }
    return $value;
}

/** @return array{mode:string,enabled_until_utc:?string,revision:int,effective:bool} */
function estab_self_registration_audit_configuration(array $policy): array
{
    $policy = estab_self_registration_normalize_row($policy);
    return [
        'mode' => (string) $policy['mode'],
        'enabled_until_utc' => is_string($policy['enabled_until_utc'])
            ? $policy['enabled_until_utc']
            : null,
        'revision' => (int) $policy['revision'],
        'effective' => estab_self_registration_is_allowed($policy),
    ];
}

/** Build a credential-free policy-change audit payload. */
function estab_self_registration_audit_details(
    array $before,
    array $after,
    string $actor,
    string $remoteAddress
): string {
    $payload = json_encode(
        [
            'version' => 1,
            'action' => 'self_registration_policy_updated',
            'admin' => estab_self_registration_actor($actor),
            'remote_address' => filter_var(
                $remoteAddress,
                FILTER_VALIDATE_IP
            ) === false ? '' : $remoteAddress,
            'before' => estab_self_registration_audit_configuration($before),
            'after' => estab_self_registration_audit_configuration($after),
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($payload)) {
        throw new RuntimeException(
            'Das Audit der Selbstregistrierung ist ungültig.'
        );
    }
    return $payload;
}

/**
 * Atomically replace the persistent mode and its audit record.
 *
 * ENVIRONMENT is deliberately not accepted here: the first administrative
 * write makes the database authoritative. A timed write always starts a new
 * window at database UTC, even if the same duration was selected before.
 *
 * @return array{changed:bool,policy:array<string,int|string|null>}
 */
function estab_self_registration_update(
    mysqli $connection,
    string $database,
    string $protocolTable,
    string $mode,
    ?int $durationMinutes,
    int $expectedRevision,
    string $actor,
    string $remoteAddress
): array {
    $proposed = estab_self_registration_configuration([
        'mode' => $mode,
        'duration_minutes' => $durationMinutes,
    ]);
    $expectedRevision = estab_self_registration_revision($expectedRevision);
    $lockName = estab_self_registration_acquire_lock($connection, $database);
    $transactionActive = false;
    try {
        if (!$connection->begin_transaction()) {
            throw new RuntimeException(
                'Die Änderung der Selbstregistrierung konnte nicht gestartet werden.'
            );
        }
        $transactionActive = true;
        $current = estab_self_registration_load($connection, true);
        if ((int) $current['revision'] !== $expectedRevision) {
            throw new EstabSelfRegistrationConflictException(
                'Die Selbstregistrierung wurde zwischenzeitlich geändert.'
            );
        }

        $mode = $proposed['mode'];
        $duration = $proposed['duration_minutes'] ?? 0;
        if (
            $mode !== ESTAB_SELF_REGISTRATION_MODE_UNTIL
            && $mode === $current['mode']
            && $current['enabled_until_utc'] === null
        ) {
            if (!$connection->commit()) {
                throw new RuntimeException(
                    'Die unveränderte Selbstregistrierung konnte nicht bestätigt werden.'
                );
            }
            $transactionActive = false;
            return ['changed' => false, 'policy' => $current];
        }

        $actor = estab_self_registration_actor($actor);
        $deadlineExpression = 'TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP(6))';
        $statement = $connection->prepare(
            'UPDATE `nv_selbstregistrierung` SET `mode` = ?,'
            . ' `enabled_until_utc` = CASE WHEN ? = \'UNTIL\''
            . ' THEN ' . $deadlineExpression . ' ELSE NULL END,'
            . ' `revision` = `revision` + 1,'
            . ' `updated_at` = UTC_TIMESTAMP(6), `updated_by` = ?'
            . ' WHERE `singleton_id` = 1 AND `revision` = ?'
        );
        if (!$statement) {
            throw new RuntimeException(
                'Die Selbstregistrierung konnte nicht vorbereitet werden.'
            );
        }
        try {
            $statement->bind_param(
                'ssisi',
                $mode,
                $mode,
                $duration,
                $actor,
                $expectedRevision
            );
            if (!$statement->execute() || $statement->affected_rows !== 1) {
                throw new EstabSelfRegistrationConflictException(
                    'Die Selbstregistrierung wurde zwischenzeitlich geändert.'
                );
            }
        } finally {
            $statement->close();
        }

        $updated = estab_self_registration_load($connection, true);
        estab_auth_log_event(
            $connection,
            $protocolTable,
            'Selbstregistrierung',
            estab_self_registration_audit_details(
                $current,
                $updated,
                $actor,
                $remoteAddress
            )
        );
        if (!$connection->commit()) {
            throw new RuntimeException(
                'Die Selbstregistrierung konnte nicht vollständig gespeichert werden.'
            );
        }
        $transactionActive = false;
        return ['changed' => true, 'policy' => $updated];
    } catch (Throwable $exception) {
        if ($transactionActive) {
            $connection->rollback();
        }
        throw $exception;
    } finally {
        try {
            estab_self_registration_release_lock($connection, $lockName);
        } catch (Throwable $exception) {
            error_log(
                'eStab self-registration lock cleanup failed: '
                . $exception->getMessage()
            );
        }
    }
}

/**
 * Insert an account only if the policy still permits it in this SQL statement.
 *
 * The caller owns the surrounding transaction and shared advisory lock. The
 * INSERT ... SELECT is nevertheless the final expiry boundary: an UNTIL
 * deadline cannot pass between a preceding PHP check and account creation.
 */
function estab_self_registration_insert_user_if_allowed(
    mysqli $connection,
    string $userTable,
    array $user
): bool {
    $values = [];
    foreach ([
        'benutzer',
        'kuerzel',
        'funktion',
        'rolle',
        'sid',
        'ip',
        'fwdip',
        'password',
    ] as $key) {
        if (!isset($user[$key]) || !is_string($user[$key])) {
            throw new InvalidArgumentException(
                'Die Daten des neuen Funktionskontos sind unvollständig.'
            );
        }
        $values[$key] = $user[$key];
    }
    $environmentAllowed = estab_auth_self_registration_allowed() ? 1 : 0;
    $sql = 'INSERT INTO ' . estab_auth_table($userTable)
        . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `ip`, `fwdip`,'
        . ' `password`, `aktiv`, `estab_letzte_aktivitaet`)'
        . ' SELECT ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(6)'
        . ' FROM `nv_selbstregistrierung` WHERE `singleton_id` = 1 AND ('
        . ' (`mode` = \'ENVIRONMENT\' AND `enabled_until_utc` IS NULL AND ? = 1)'
        . ' OR (`mode` = \'PERMANENT\' AND `enabled_until_utc` IS NULL)'
        . ' OR (`mode` = \'UNTIL\' AND `enabled_until_utc` IS NOT NULL'
        . ' AND `enabled_until_utc` > UTC_TIMESTAMP(6)))';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException(
            'Das Funktionskonto konnte nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param(
            'ssssssssi',
            $values['benutzer'],
            $values['kuerzel'],
            $values['funktion'],
            $values['rolle'],
            $values['sid'],
            $values['ip'],
            $values['fwdip'],
            $values['password'],
            $environmentAllowed
        );
        if (!$statement->execute()) {
            throw new RuntimeException('Das Funktionskonto konnte nicht angelegt werden.');
        }
        if ($statement->affected_rows === 0) {
            return false;
        }
        if ($statement->affected_rows !== 1) {
            throw new RuntimeException(
                'Die Kontoanlage hatte ein unerwartetes Ergebnis.'
            );
        }
        return true;
    } finally {
        $statement->close();
    }
}
