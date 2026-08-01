<?php

declare(strict_types=1);

/**
 * Central password-policy boundary for application accounts.
 *
 * The policy applies only when a new password is created. Existing hashes and
 * legacy cleartext credentials remain verifiable so tightening the policy can
 * never lock every existing account out at once.
 */

require_once __DIR__ . '/auth.php';

if (!defined('ESTAB_PASSWORD_POLICY_MINIMUM_ALLOWED')) {
    define('ESTAB_PASSWORD_POLICY_MINIMUM_ALLOWED', 8);
}
if (!defined('ESTAB_PASSWORD_POLICY_MAXIMUM_ALLOWED')) {
    define('ESTAB_PASSWORD_POLICY_MAXIMUM_ALLOWED', 128);
}
if (!defined('ESTAB_PASSWORD_POLICY_MAXIMUM_BYTES')) {
    define(
        'ESTAB_PASSWORD_POLICY_MAXIMUM_BYTES',
        ESTAB_AUTH_PASSWORD_MAXIMUM_BYTES
    );
}
if (!defined('ESTAB_PASSWORD_POLICY_LOCK_TIMEOUT')) {
    define('ESTAB_PASSWORD_POLICY_LOCK_TIMEOUT', 10);
}

final class EstabPasswordPolicyInputException extends InvalidArgumentException
{
}

final class EstabPasswordPolicyConflictException extends RuntimeException
{
}

final class EstabPasswordPolicyBusyException extends RuntimeException
{
}

/** @return array<string,int|bool|string> */
function estab_password_policy_defaults(): array
{
    return [
        'singleton_id' => 1,
        'minimum_length' => 12,
        'require_uppercase' => false,
        'require_lowercase' => false,
        'require_digit' => false,
        'require_symbol' => false,
        'revision' => 0,
        'updated_at' => '',
        'updated_by' => 'migration',
    ];
}

/** Parse one database boolean without accepting ambiguous values. */
function estab_password_policy_database_bool(mixed $value): bool
{
    if ($value === true || $value === 1 || $value === '1') {
        return true;
    }
    if ($value === false || $value === 0 || $value === '0') {
        return false;
    }
    throw new RuntimeException('Die gespeicherte Kennwortrichtlinie ist ungültig.');
}

/** @return array<string,int|bool|string> */
function estab_password_policy_normalize_row(array $row): array
{
    $minimumLength = filter_var(
        $row['minimum_length'] ?? null,
        FILTER_VALIDATE_INT
    );
    $revision = filter_var($row['revision'] ?? null, FILTER_VALIDATE_INT);
    $updatedAt = $row['updated_at'] ?? '';
    $updatedBy = $row['updated_by'] ?? '';
    if (
        (int) ($row['singleton_id'] ?? 0) !== 1
        || !is_int($minimumLength)
        || $minimumLength < ESTAB_PASSWORD_POLICY_MINIMUM_ALLOWED
        || $minimumLength > ESTAB_PASSWORD_POLICY_MAXIMUM_ALLOWED
        || !is_int($revision)
        || $revision < 0
        || !is_string($updatedAt)
        || !is_string($updatedBy)
        || estab_auth_text_length($updatedBy) < 1
        || estab_auth_text_length($updatedBy) > 128
        || preg_match('//u', $updatedBy) !== 1
        || preg_match('/[\p{C}]/u', $updatedBy) === 1
    ) {
        throw new RuntimeException('Die gespeicherte Kennwortrichtlinie ist ungültig.');
    }

    return [
        'singleton_id' => 1,
        'minimum_length' => $minimumLength,
        'require_uppercase' => estab_password_policy_database_bool(
            $row['require_uppercase'] ?? null
        ),
        'require_lowercase' => estab_password_policy_database_bool(
            $row['require_lowercase'] ?? null
        ),
        'require_digit' => estab_password_policy_database_bool(
            $row['require_digit'] ?? null
        ),
        'require_symbol' => estab_password_policy_database_bool(
            $row['require_symbol'] ?? null
        ),
        'revision' => $revision,
        'updated_at' => $updatedAt,
        'updated_by' => $updatedBy,
    ];
}

/** @return array<string,int|bool|string> */
function estab_password_policy_load(
    mysqli $connection,
    bool $forUpdate = false
): array {
    $sql = 'SELECT `singleton_id`, `minimum_length`, `require_uppercase`,'
        . ' `require_lowercase`, `require_digit`, `require_symbol`, `revision`,'
        . ' `updated_at`, `updated_by` FROM `nv_kennwortrichtlinie`'
        . ' WHERE `singleton_id` = 1'
        . ($forUpdate ? ' FOR UPDATE' : '');
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Die Kennwortrichtlinie konnte nicht gelesen werden.');
    }
    try {
        if (!$statement->execute()) {
            throw new RuntimeException('Die Kennwortrichtlinie konnte nicht gelesen werden.');
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $secondRow = $result->fetch_assoc();
        $result->free();
        if (!is_array($row) || $secondRow !== null) {
            throw new RuntimeException(
                'Die Kennwortrichtlinie ist nicht eindeutig konfiguriert.'
            );
        }
        return estab_password_policy_normalize_row($row);
    } finally {
        $statement->close();
    }
}

/** Parse the revision emitted by the administration form. */
function estab_password_policy_revision(mixed $value): int
{
    if (is_int($value) && $value >= 0) {
        return $value;
    }
    if (
        !is_string($value)
        || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $value) !== 1
    ) {
        throw new EstabPasswordPolicyInputException(
            'Der Versionsstand der Kennwortrichtlinie ist ungültig.'
        );
    }
    $revision = (int) $value;
    if ((string) $revision !== $value || $revision < 0) {
        throw new EstabPasswordPolicyInputException(
            'Der Versionsstand der Kennwortrichtlinie ist ungültig.'
        );
    }
    return $revision;
}

/** Parse one explicit HTML checkbox value. Missing means disabled. */
function estab_password_policy_request_flag(array $input, string $key): bool
{
    if (!array_key_exists($key, $input)) {
        return false;
    }
    $value = $input[$key];
    if ($value === '1' || $value === 1 || $value === true) {
        return true;
    }
    if ($value === '0' || $value === 0 || $value === false) {
        return false;
    }
    throw new EstabPasswordPolicyInputException(
        'Die ausgewählten Kennwortanforderungen sind ungültig.'
    );
}

/** @return array<string,int|bool> */
function estab_password_policy_configuration_from_request(array $input): array
{
    $minimumValue = $input['minimum_length'] ?? null;
    if (
        !is_string($minimumValue)
        || preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $minimumValue) !== 1
    ) {
        throw new EstabPasswordPolicyInputException(
            'Die Mindestlänge muss als ganze Zahl angegeben werden.'
        );
    }
    $minimumLength = (int) $minimumValue;
    if (
        $minimumLength < ESTAB_PASSWORD_POLICY_MINIMUM_ALLOWED
        || $minimumLength > ESTAB_PASSWORD_POLICY_MAXIMUM_ALLOWED
    ) {
        throw new EstabPasswordPolicyInputException(
            'Die Mindestlänge muss zwischen '
            . ESTAB_PASSWORD_POLICY_MINIMUM_ALLOWED . ' und '
            . ESTAB_PASSWORD_POLICY_MAXIMUM_ALLOWED . ' Zeichen liegen.'
        );
    }

    return [
        'minimum_length' => $minimumLength,
        'require_uppercase' => estab_password_policy_request_flag(
            $input,
            'require_uppercase'
        ),
        'require_lowercase' => estab_password_policy_request_flag(
            $input,
            'require_lowercase'
        ),
        'require_digit' => estab_password_policy_request_flag(
            $input,
            'require_digit'
        ),
        'require_symbol' => estab_password_policy_request_flag(
            $input,
            'require_symbol'
        ),
    ];
}

/** @return array<string,int|bool> */
function estab_password_policy_configuration(array $policy): array
{
    $minimumLength = $policy['minimum_length'] ?? null;
    if (
        !is_int($minimumLength)
        || $minimumLength < ESTAB_PASSWORD_POLICY_MINIMUM_ALLOWED
        || $minimumLength > ESTAB_PASSWORD_POLICY_MAXIMUM_ALLOWED
    ) {
        throw new EstabPasswordPolicyInputException(
            'Die Mindestlänge der Kennwortrichtlinie ist ungültig.'
        );
    }
    foreach ([
        'require_uppercase',
        'require_lowercase',
        'require_digit',
        'require_symbol',
    ] as $key) {
        if (!array_key_exists($key, $policy) || !is_bool($policy[$key])) {
            throw new EstabPasswordPolicyInputException(
                'Die Kennwortanforderungen sind unvollständig oder ungültig.'
            );
        }
    }
    return [
        'minimum_length' => $minimumLength,
        'require_uppercase' => $policy['require_uppercase'],
        'require_lowercase' => $policy['require_lowercase'],
        'require_digit' => $policy['require_digit'],
        'require_symbol' => $policy['require_symbol'],
    ];
}

/** Human-readable requirements shared by every password form. */
function estab_password_policy_requirements_text(array $policy): string
{
    $configuration = estab_password_policy_configuration($policy);
    $requirements = [];
    if ($configuration['require_uppercase']) {
        $requirements[] = 'einen Großbuchstaben';
    }
    if ($configuration['require_lowercase']) {
        $requirements[] = 'einen Kleinbuchstaben';
    }
    if ($configuration['require_digit']) {
        $requirements[] = 'eine Ziffer';
    }
    if ($configuration['require_symbol']) {
        $requirements[] = 'ein Sonderzeichen';
    }

    $text = 'Mindestens ' . $configuration['minimum_length'] . ' Zeichen';
    if ($requirements !== []) {
        $last = array_pop($requirements);
        $text .= '; zusätzlich mindestens '
            . ($requirements === []
                ? $last
                : implode(', ', $requirements) . ' und ' . $last);
    }
    return $text . '. Steuerzeichen sind nicht erlaubt.';
}

/**
 * Validate a new password without trimming or otherwise normalising it.
 *
 * @return string The original cleartext only for the immediate hash boundary.
 */
function estab_password_policy_validate_password(
    mixed $passwordValue,
    mixed $confirmationValue,
    array $policy
): string {
    if (!is_string($passwordValue) || !is_string($confirmationValue)) {
        throw new EstabPasswordPolicyInputException(
            'Das neue Kennwort ist ungültig.'
        );
    }
    if (!hash_equals($passwordValue, $confirmationValue)) {
        throw new EstabPasswordPolicyInputException(
            'Die beiden Kennwörter stimmen nicht überein.'
        );
    }
    if (
        $passwordValue === ''
        || strlen($passwordValue) > ESTAB_PASSWORD_POLICY_MAXIMUM_BYTES
        || str_contains($passwordValue, "\0")
        || preg_match('//u', $passwordValue) !== 1
        || preg_match('/\p{Cc}/u', $passwordValue) === 1
    ) {
        throw new EstabPasswordPolicyInputException(
            'Das neue Kennwort enthält ungültige Zeichen oder ist zu lang.'
        );
    }

    $configuration = estab_password_policy_configuration($policy);
    $missing = [];
    if (
        estab_auth_text_length($passwordValue)
        < $configuration['minimum_length']
    ) {
        $missing[] = 'mindestens ' . $configuration['minimum_length']
            . ' Zeichen';
    }
    if (
        $configuration['require_uppercase']
        && preg_match('/[\p{Lu}\p{Lt}]/u', $passwordValue) !== 1
    ) {
        $missing[] = 'einen Großbuchstaben';
    }
    if (
        $configuration['require_lowercase']
        && preg_match('/\p{Ll}/u', $passwordValue) !== 1
    ) {
        $missing[] = 'einen Kleinbuchstaben';
    }
    if (
        $configuration['require_digit']
        && preg_match('/\p{Nd}/u', $passwordValue) !== 1
    ) {
        $missing[] = 'eine Ziffer';
    }
    if (
        $configuration['require_symbol']
        && preg_match('/[\p{P}\p{S}]/u', $passwordValue) !== 1
    ) {
        $missing[] = 'ein Sonderzeichen';
    }
    if ($missing !== []) {
        throw new EstabPasswordPolicyInputException(
            'Das neue Kennwort erfüllt die Richtlinie nicht. Erforderlich: '
            . implode(', ', $missing) . '.'
        );
    }
    return $passwordValue;
}

/** Stable global advisory-lock name for policy reads followed by writes. */
function estab_password_policy_lock_name(string $database): string
{
    return 'estab:password-policy:'
        . substr(hash('sha256', $database), 0, 40);
}

function estab_password_policy_acquire_lock(
    mysqli $connection,
    string $database
): string {
    $timeout = ESTAB_PASSWORD_POLICY_LOCK_TIMEOUT;
    if (!is_int($timeout) || $timeout < 0 || $timeout > 30) {
        throw new RuntimeException('Ungültiges Zeitlimit der Kennwortrichtlinie.');
    }
    $lockName = estab_password_policy_lock_name($database);
    $statement = $connection->prepare('SELECT GET_LOCK(?, ?)');
    if (!$statement) {
        throw new RuntimeException('Die Kennwortrichtlinie konnte nicht gesperrt werden.');
    }
    try {
        $statement->bind_param('si', $lockName, $timeout);
        if (!$statement->execute()) {
            throw new RuntimeException('Die Kennwortrichtlinie konnte nicht gesperrt werden.');
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
            throw new EstabPasswordPolicyBusyException(
                'Die Kennwortrichtlinie wird gerade geändert. Bitte versuchen Sie es erneut.'
            );
        }
    } finally {
        $statement->close();
    }
    return $lockName;
}

function estab_password_policy_release_lock(
    mysqli $connection,
    string $lockName
): void {
    $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
    if (!$statement) {
        throw new RuntimeException('Die Kennwortrichtlinie konnte nicht entsperrt werden.');
    }
    try {
        $statement->bind_param('s', $lockName);
        if (!$statement->execute()) {
            throw new RuntimeException('Die Kennwortrichtlinie konnte nicht entsperrt werden.');
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
            throw new RuntimeException('Die Sperre der Kennwortrichtlinie ging verloren.');
        }
    } finally {
        $statement->close();
    }
}

/** Return a bounded, log-safe Basic-Auth actor identity. */
function estab_password_policy_actor(mixed $value): string
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

/** Build a credential-free policy-change audit payload. */
function estab_password_policy_audit_details(
    array $before,
    array $after,
    string $actor,
    string $remoteAddress
): string {
    $payload = json_encode(
        [
            'version' => 1,
            'action' => 'password_policy_updated',
            'admin' => estab_password_policy_actor($actor),
            'remote_address' => filter_var(
                $remoteAddress,
                FILTER_VALIDATE_IP
            ) === false ? '' : $remoteAddress,
            'before_revision' => (int) ($before['revision'] ?? 0),
            'after_revision' => (int) ($after['revision'] ?? 0),
            'before' => estab_password_policy_configuration($before),
            'after' => estab_password_policy_configuration($after),
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($payload)) {
        throw new RuntimeException('Das Audit der Kennwortrichtlinie ist ungültig.');
    }
    return $payload;
}

/**
 * Atomically replace the policy and its audit record using optimistic locking.
 *
 * @return array{changed:bool,policy:array<string,int|bool|string>}
 */
function estab_password_policy_update(
    mysqli $connection,
    string $database,
    string $protocolTable,
    array $configuration,
    int $expectedRevision,
    string $actor,
    string $remoteAddress
): array {
    $proposed = estab_password_policy_configuration($configuration);
    $lockName = estab_password_policy_acquire_lock($connection, $database);
    $transactionActive = false;
    try {
        if (!$connection->begin_transaction()) {
            throw new RuntimeException(
                'Die Änderung der Kennwortrichtlinie konnte nicht gestartet werden.'
            );
        }
        $transactionActive = true;
        $current = estab_password_policy_load($connection, true);
        if ((int) $current['revision'] !== $expectedRevision) {
            throw new EstabPasswordPolicyConflictException(
                'Die Kennwortrichtlinie wurde zwischenzeitlich geändert.'
            );
        }
        if (estab_password_policy_configuration($current) === $proposed) {
            if (!$connection->commit()) {
                throw new RuntimeException(
                    'Die unveränderte Kennwortrichtlinie konnte nicht bestätigt werden.'
                );
            }
            $transactionActive = false;
            return ['changed' => false, 'policy' => $current];
        }

        $actor = estab_password_policy_actor($actor);
        $minimumLength = $proposed['minimum_length'];
        $requireUppercase = $proposed['require_uppercase'] ? 1 : 0;
        $requireLowercase = $proposed['require_lowercase'] ? 1 : 0;
        $requireDigit = $proposed['require_digit'] ? 1 : 0;
        $requireSymbol = $proposed['require_symbol'] ? 1 : 0;
        $statement = $connection->prepare(
            'UPDATE `nv_kennwortrichtlinie` SET `minimum_length` = ?,'
            . ' `require_uppercase` = ?, `require_lowercase` = ?,'
            . ' `require_digit` = ?, `require_symbol` = ?,'
            . ' `revision` = `revision` + 1, `updated_at` = UTC_TIMESTAMP(6),'
            . ' `updated_by` = ? WHERE `singleton_id` = 1 AND `revision` = ?'
        );
        if (!$statement) {
            throw new RuntimeException(
                'Die Kennwortrichtlinie konnte nicht vorbereitet werden.'
            );
        }
        try {
            $statement->bind_param(
                'iiiiisi',
                $minimumLength,
                $requireUppercase,
                $requireLowercase,
                $requireDigit,
                $requireSymbol,
                $actor,
                $expectedRevision
            );
            if (!$statement->execute() || $statement->affected_rows !== 1) {
                throw new EstabPasswordPolicyConflictException(
                    'Die Kennwortrichtlinie wurde zwischenzeitlich geändert.'
                );
            }
        } finally {
            $statement->close();
        }

        $updated = estab_password_policy_load($connection, true);
        estab_auth_log_event(
            $connection,
            $protocolTable,
            'Kennwortrichtlinie',
            estab_password_policy_audit_details(
                $current,
                $updated,
                $actor,
                $remoteAddress
            )
        );
        if (!$connection->commit()) {
            throw new RuntimeException(
                'Die Kennwortrichtlinie konnte nicht vollständig gespeichert werden.'
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
            estab_password_policy_release_lock($connection, $lockName);
        } catch (Throwable $exception) {
            error_log(
                'eStab password-policy lock cleanup failed: '
                . $exception->getMessage()
            );
        }
    }
}
