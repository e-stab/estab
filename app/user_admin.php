<?php

declare(strict_types=1);

/**
 * Transactional account-management boundary for the Basic-Auth protected
 * administration area.
 *
 * Account writes are serialised with the legacy login controller by using the
 * same per-account MariaDB advisory-lock name. Account creation, assignment,
 * blocking and password resets share one atomic audit/session boundary.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/assignment.php';
require_once __DIR__ . '/password_policy.php';

if (!defined('ESTAB_USER_ADMIN_ACCOUNT_LOCK_TIMEOUT')) {
    define('ESTAB_USER_ADMIN_ACCOUNT_LOCK_TIMEOUT', 10);
}

final class EstabUserAdminNotFoundException extends RuntimeException
{
}

final class EstabUserAdminBusyException extends RuntimeException
{
}

final class EstabUserAdminConflictException extends RuntimeException
{
}

/** Accept only the canonical lower-case account primary key. */
function estab_user_admin_validate_code(mixed $value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Ungültiges Benutzerkürzel.');
    }
    if (preg_match('/[\x00-\x1F\x7F]/D', $value) === 1) {
        throw new InvalidArgumentException('Ungültiges Benutzerkürzel.');
    }
    $code = strtolower(trim($value));
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1) {
        throw new InvalidArgumentException('Ungültiges Benutzerkürzel.');
    }
    return $code;
}

/**
 * Validate a new password without normalising it.
 *
 * The cleartext value is returned only to the immediate hashing boundary. It
 * must never be included in URLs, flash state, audit records or log messages.
 */
function estab_user_admin_validate_password(
    mixed $passwordValue,
    mixed $confirmationValue,
    ?array $policy = null
): string {
    return estab_password_policy_validate_password(
        $passwordValue,
        $confirmationValue,
        $policy ?? estab_password_policy_defaults()
    );
}

/** Validate an account display name with the same boundary as public login. */
function estab_user_admin_validate_name(mixed $value): string
{
    if (
        !is_string($value)
        || preg_match('//u', $value) !== 1
        || preg_match('/[\p{C}<>]/u', $value) === 1
    ) {
        throw new InvalidArgumentException('Ungültiger Benutzername.');
    }
    $name = trim($value);
    if (
        estab_auth_text_length($name) < 1
        || estab_auth_text_length($name) > 50
    ) {
        throw new InvalidArgumentException('Ungültiger Benutzername.');
    }
    return $name;
}

/**
 * Read the only functions that an administrator may assign.
 *
 * Si and A/W are fixed application functions. Stab/FB functions come from the
 * current recipient matrix; conflicting duplicates fail closed.
 *
 * @return array<string,string> function => role
 */
function estab_user_admin_function_roles(
    mysqli $connection,
    string $matrixTable
): array {
    return estab_assignment_function_roles($connection, $matrixTable);
}

/**
 * Resolve a request-selected function only through the authoritative map.
 *
 * @param array<string,string> $functionRoles
 * @return array{funktion:string,rolle:string}
 */
function estab_user_admin_validate_assignment(
    mixed $functionValue,
    array $functionRoles
): array {
    if (
        !is_string($functionValue)
        || preg_match('//u', $functionValue) !== 1
        || preg_match('/[\p{C}]/u', $functionValue) === 1
    ) {
        throw new InvalidArgumentException('Ungültige Funktionszuordnung.');
    }
    $function = trim($functionValue);
    $role = $functionRoles[$function] ?? null;
    if (
        !is_string($role)
        || !in_array($role, ['Stab', 'FB', 'Fernmelder'], true)
        || (
            $function !== 'A/W'
            && preg_match('/\A[A-Za-z0-9_]+\z/D', $function) !== 1
        )
    ) {
        throw new InvalidArgumentException('Ungültige Funktionszuordnung.');
    }
    return ['funktion' => $function, 'rolle' => $role];
}

/** Return a log-safe Basic-Auth operator identity. */
function estab_user_admin_actor(array $server): string
{
    $actor = $server['REMOTE_USER'] ?? null;
    if (
        !is_string($actor)
        || estab_auth_text_length($actor) < 1
        || estab_auth_text_length($actor) > 128
        || preg_match('//u', $actor) !== 1
        || preg_match('/[\p{C}]/u', $actor) === 1
    ) {
        return 'unknown';
    }
    return $actor;
}

/**
 * Build a structured audit payload containing no password, hash or SID.
 */
function estab_user_admin_audit_details(
    string $action,
    string $actor,
    string $targetCode,
    bool $activeSessionRevoked,
    string $remoteAddress,
    array $assignment = []
): string {
    if (!in_array(
        $action,
        ['create', 'reassign', 'block', 'unblock', 'reset_password'],
        true
    )) {
        throw new InvalidArgumentException('Unknown user-administration audit action');
    }
    $expectedAssignmentKeys = match ($action) {
        'create' => ['new_function', 'new_role'],
        'reassign' => [
            'old_function',
            'old_role',
            'new_function',
            'new_role',
        ],
        default => [],
    };
    if (array_keys($assignment) !== $expectedAssignmentKeys) {
        throw new InvalidArgumentException(
            'Invalid user-administration audit assignment'
        );
    }
    foreach ($assignment as $key => $value) {
        // Historical imports may contain an empty function or role. Those
        // values must remain repairable through a reassignment, while every
        // newly assigned value is still required to be non-empty.
        $minimumLength = str_starts_with($key, 'old_') ? 0 : 1;
        if (
            !is_string($key)
            || !is_string($value)
            || estab_auth_text_length($value) < $minimumLength
            || estab_auth_text_length($value) > 64
            || preg_match('//u', $value) !== 1
            || preg_match('/[\p{C}]/u', $value) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid user-administration audit assignment'
            );
        }
    }
    if (
        estab_auth_text_length($actor) < 1
        || estab_auth_text_length($actor) > 128
        || preg_match('//u', $actor) !== 1
        || preg_match('/[\p{C}]/u', $actor) === 1
    ) {
        $actor = 'unknown';
    }
    $payloadData = [
        'version' => 1,
        'action' => $action,
        'admin' => $actor,
        'target' => estab_user_admin_validate_code($targetCode),
        'active_session_revoked' => $activeSessionRevoked,
        'remote_address' => filter_var(
            $remoteAddress,
            FILTER_VALIDATE_IP
        ) === false ? '' : $remoteAddress,
    ];
    foreach ($assignment as $key => $value) {
        $payloadData[$key] = $value;
    }
    $payload = json_encode(
        $payloadData,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($payload)) {
        throw new RuntimeException('Could not encode user-administration audit');
    }
    return $payload;
}

/**
 * Generate exactly the advisory-lock name used by check_save_user().
 */
function estab_user_admin_account_lock_name(
    string $database,
    string $table,
    string $code
): string {
    estab_auth_table($table);
    return 'estab:login:' . substr(
        hash('sha256', $database . "\0" . $table . "\0"
            . estab_user_admin_validate_code($code)),
        0,
        52
    );
}

function estab_user_admin_acquire_account_lock(
    mysqli $connection,
    string $lockName
): void {
    $statement = $connection->prepare('SELECT GET_LOCK(?, ?)');
    if (!$statement) {
        throw new RuntimeException('Could not prepare account-management lock');
    }
    try {
        $timeout = ESTAB_USER_ADMIN_ACCOUNT_LOCK_TIMEOUT;
        $statement->bind_param('si', $lockName, $timeout);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not acquire account-management lock');
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
            throw new EstabUserAdminBusyException(
                'Das Konto wird gerade verwendet. Bitte versuchen Sie es erneut.'
            );
        }
    } finally {
        $statement->close();
    }
}

function estab_user_admin_release_account_lock(
    mysqli $connection,
    string $lockName
): void {
    $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
    if (!$statement) {
        throw new RuntimeException('Could not prepare account-management unlock');
    }
    try {
        $statement->bind_param('s', $lockName);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not release account-management lock');
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
            throw new RuntimeException('Account-management lock was lost');
        }
    } finally {
        $statement->close();
    }
}

/**
 * List accounts without reading password hashes, session IDs or IP addresses.
 *
 * @return list<array{
 *   benutzer:string,
 *   kuerzel:string,
 *   funktion:string,
 *   rolle:string,
 *   aktiv:int|string,
 *   estab_sitzung_vorhanden:int|string,
 *   estab_letzte_aktivitaet:?string,
 *   estab_gesperrt:int|string
 * }>
 */
function estab_user_admin_list(
    mysqli $connection,
    string $userTable
): array {
    estab_auth_expire_stale_sessions($connection, $userTable);
    $statement = $connection->prepare(
        'SELECT `benutzer`, `kuerzel`, `funktion`, `rolle`, `aktiv`,'
        . ' (`sid` REGEXP BINARY \'^[A-Za-z0-9,-]{1,50}$\')'
        . ' AS `estab_sitzung_vorhanden`,'
        . ' `estab_gesperrt`, `estab_letzte_aktivitaet` FROM '
        . estab_auth_table($userTable)
        . ' ORDER BY `estab_gesperrt` DESC, `benutzer`, `kuerzel`'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare account-management list');
    }
    try {
        if (!$statement->execute()) {
            throw new RuntimeException('Could not read account-management list');
        }
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return is_array($rows) ? array_values($rows) : [];
    } finally {
        $statement->close();
    }
}

/** Lock and return the administrative state for one account. */
function estab_user_admin_fetch_for_update(
    mysqli $connection,
    string $userTable,
    string $code
): array {
    $statement = $connection->prepare(
        'SELECT `benutzer`, `kuerzel`, `funktion`, `rolle`, `aktiv`, `sid`,'
        . ' `estab_gesperrt` FROM '
        . estab_auth_table($userTable)
        . ' WHERE `kuerzel` = ? LIMIT 1 FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare account-management lookup');
    }
    try {
        $statement->bind_param('s', $code);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not read account-management target');
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        if (!is_array($row)) {
            throw new EstabUserAdminNotFoundException(
                'Das ausgewählte Konto wurde nicht gefunden.'
            );
        }
        return $row;
    } finally {
        $statement->close();
    }
}

/**
 * Create one inactive account with an immutable initial assignment.
 *
 * @return array{
 *   benutzer:string,
 *   kuerzel:string,
 *   funktion:string,
 *   rolle:string,
 *   active_session_revoked:bool
 * }
 */
function estab_user_admin_create_account(
    mysqli $connection,
    string $database,
    string $userTable,
    string $protocolTable,
    mixed $nameValue,
    mixed $codeValue,
    mixed $functionValue,
    mixed $passwordValue,
    mixed $confirmationValue,
    string $matrixTable,
    string $actor,
    string $remoteAddress
): array {
    $name = estab_user_admin_validate_name($nameValue);
    $code = estab_user_admin_validate_code($codeValue);

    $lockName = estab_user_admin_account_lock_name(
        $database,
        $userTable,
        $code
    );
    $lockAcquired = false;
    $policyLockName = null;
    $policyLockAcquired = false;
    $passwordPolicyLockName = null;
    $passwordPolicyLockAcquired = false;
    $transactionActive = false;
    $passwordHash = null;
    try {
        $policyLockName = estab_assignment_acquire_policy_lock(
            $connection,
            $database,
            $matrixTable
        );
        $policyLockAcquired = true;
        $assignment = estab_user_admin_validate_assignment(
            $functionValue,
            estab_assignment_function_roles($connection, $matrixTable)
        );
        $passwordPolicyLockName = estab_password_policy_acquire_lock(
            $connection,
            $database
        );
        $passwordPolicyLockAcquired = true;
        $password = estab_user_admin_validate_password(
            $passwordValue,
            $confirmationValue,
            estab_password_policy_load($connection)
        );
        $passwordHash = estab_auth_hash_password($password);
        unset($password);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException(
                'Das Kennwort konnte nicht sicher gehasht werden.'
            );
        }
        estab_user_admin_acquire_account_lock($connection, $lockName);
        $lockAcquired = true;
        if (!$connection->begin_transaction()) {
            throw new RuntimeException('Could not start account-creation transaction');
        }
        $transactionActive = true;

        $existing = $connection->prepare(
            'SELECT `kuerzel` FROM ' . estab_auth_table($userTable)
            . ' WHERE `kuerzel` = ? LIMIT 1 FOR UPDATE'
        );
        if (!$existing) {
            throw new RuntimeException('Could not prepare account conflict check');
        }
        try {
            $existing->bind_param('s', $code);
            if (!$existing->execute()) {
                throw new RuntimeException('Could not check account conflict');
            }
            $result = $existing->get_result();
            $row = $result->fetch_assoc();
            $result->free();
        } finally {
            $existing->close();
        }
        if (is_array($row ?? null)) {
            throw new EstabUserAdminConflictException(
                'Dieses Benutzerkürzel ist bereits vergeben.'
            );
        }

        $insert = $connection->prepare(
            'INSERT INTO ' . estab_auth_table($userTable)
            . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `ip`,'
            . ' `fwdip`, `aktiv`, `estab_gesperrt`, `password`)'
            . " VALUES (?, ?, ?, ?, '', '', '', 0, 0, ?)"
        );
        if (!$insert) {
            throw new RuntimeException('Could not prepare account creation');
        }
        try {
            $insert->bind_param(
                'sssss',
                $name,
                $code,
                $assignment['funktion'],
                $assignment['rolle'],
                $passwordHash
            );
            if (!$insert->execute() || $insert->affected_rows !== 1) {
                throw new RuntimeException('Could not create account');
            }
        } finally {
            $insert->close();
        }

        estab_auth_log_event(
            $connection,
            $protocolTable,
            'Benutzerverwaltung',
            estab_user_admin_audit_details(
                'create',
                $actor,
                $code,
                false,
                $remoteAddress,
                [
                    'new_function' => $assignment['funktion'],
                    'new_role' => $assignment['rolle'],
                ]
            )
        );
        if (!$connection->commit()) {
            throw new RuntimeException('Could not commit account creation');
        }
        $transactionActive = false;
        return [
            'benutzer' => $name,
            'kuerzel' => $code,
            'funktion' => $assignment['funktion'],
            'rolle' => $assignment['rolle'],
            'active_session_revoked' => false,
        ];
    } catch (Throwable $exception) {
        if ($transactionActive) {
            $connection->rollback();
            $transactionActive = false;
        }
        throw $exception;
    } finally {
        unset($passwordHash);
        if ($transactionActive) {
            $connection->rollback();
        }
        if ($lockAcquired) {
            try {
                estab_user_admin_release_account_lock($connection, $lockName);
            } catch (Throwable $exception) {
                error_log(
                    'eStab account-creation lock cleanup failed: '
                    . $exception->getMessage()
                );
            }
        }
        if (
            $passwordPolicyLockAcquired
            && is_string($passwordPolicyLockName)
            && $passwordPolicyLockName !== ''
        ) {
            try {
                estab_password_policy_release_lock(
                    $connection,
                    $passwordPolicyLockName
                );
            } catch (Throwable $exception) {
                error_log(
                    'eStab account-creation password-policy lock cleanup failed: '
                    . $exception->getMessage()
                );
            }
        }
        if (
            $policyLockAcquired
            && is_string($policyLockName)
            && $policyLockName !== ''
        ) {
            try {
                estab_assignment_release_policy_lock(
                    $connection,
                    $policyLockName
                );
            } catch (Throwable $exception) {
                error_log(
                    'eStab account-creation policy-lock cleanup failed: '
                    . $exception->getMessage()
                );
            }
        }
    }
}

/**
 * Reassign an account only through the administration boundary.
 *
 * @return array{
 *   changed:bool,
 *   active_session_revoked:bool,
 *   funktion:string,
 *   rolle:string
 * }
 */
function estab_user_admin_reassign(
    mysqli $connection,
    string $database,
    string $userTable,
    string $protocolTable,
    string $targetCode,
    mixed $functionValue,
    string $matrixTable,
    string $actor,
    string $remoteAddress
): array {
    $code = estab_user_admin_validate_code($targetCode);
    $lockName = estab_user_admin_account_lock_name(
        $database,
        $userTable,
        $code
    );
    $lockAcquired = false;
    $policyLockName = null;
    $policyLockAcquired = false;
    $transactionActive = false;
    try {
        $policyLockName = estab_assignment_acquire_policy_lock(
            $connection,
            $database,
            $matrixTable
        );
        $policyLockAcquired = true;
        $assignment = estab_user_admin_validate_assignment(
            $functionValue,
            estab_assignment_function_roles($connection, $matrixTable)
        );
        estab_user_admin_acquire_account_lock($connection, $lockName);
        $lockAcquired = true;
        if (!$connection->begin_transaction()) {
            throw new RuntimeException('Could not start account-reassignment transaction');
        }
        $transactionActive = true;

        $account = estab_user_admin_fetch_for_update(
            $connection,
            $userTable,
            $code
        );
        $activeSessionRevoked = (int) ($account['aktiv'] ?? 0) === 1
            || (string) ($account['sid'] ?? '') !== '';
        $oldFunction = (string) ($account['funktion'] ?? '');
        $oldRole = (string) ($account['rolle'] ?? '');
        if (
            hash_equals($oldFunction, $assignment['funktion'])
            && hash_equals($oldRole, $assignment['rolle'])
        ) {
            if (!$connection->commit()) {
                throw new RuntimeException('Could not finish account reassignment');
            }
            $transactionActive = false;
            return [
                'changed' => false,
                'active_session_revoked' => false,
                'funktion' => $assignment['funktion'],
                'rolle' => $assignment['rolle'],
            ];
        }

        $update = $connection->prepare(
            'UPDATE ' . estab_auth_table($userTable)
            . ' SET `funktion` = ?, `rolle` = ?, `aktiv` = 0,'
            . " `sid` = '', `ip` = '', `fwdip` = ''"
            . ' WHERE `kuerzel` = ?'
        );
        if (!$update) {
            throw new RuntimeException('Could not prepare account reassignment');
        }
        try {
            $update->bind_param(
                'sss',
                $assignment['funktion'],
                $assignment['rolle'],
                $code
            );
            if (!$update->execute() || $update->affected_rows !== 1) {
                throw new RuntimeException('Account assignment changed concurrently');
            }
        } finally {
            $update->close();
        }

        estab_auth_log_event(
            $connection,
            $protocolTable,
            'Benutzerverwaltung',
            estab_user_admin_audit_details(
                'reassign',
                $actor,
                $code,
                $activeSessionRevoked,
                $remoteAddress,
                [
                    'old_function' => $oldFunction,
                    'old_role' => $oldRole,
                    'new_function' => $assignment['funktion'],
                    'new_role' => $assignment['rolle'],
                ]
            )
        );
        if (!$connection->commit()) {
            throw new RuntimeException('Could not commit account reassignment');
        }
        $transactionActive = false;
        return [
            'changed' => true,
            'active_session_revoked' => $activeSessionRevoked,
            'funktion' => $assignment['funktion'],
            'rolle' => $assignment['rolle'],
        ];
    } catch (Throwable $exception) {
        if ($transactionActive) {
            $connection->rollback();
            $transactionActive = false;
        }
        throw $exception;
    } finally {
        if ($transactionActive) {
            $connection->rollback();
        }
        if ($lockAcquired) {
            try {
                estab_user_admin_release_account_lock($connection, $lockName);
            } catch (Throwable $exception) {
                error_log(
                    'eStab account-reassignment lock cleanup failed: '
                    . $exception->getMessage()
                );
            }
        }
        if (
            $policyLockAcquired
            && is_string($policyLockName)
            && $policyLockName !== ''
        ) {
            try {
                estab_assignment_release_policy_lock(
                    $connection,
                    $policyLockName
                );
            } catch (Throwable $exception) {
                error_log(
                    'eStab account-reassignment policy-lock cleanup failed: '
                    . $exception->getMessage()
                );
            }
        }
    }
}

/**
 * Block or unblock an account transactionally.
 *
 * Blocking also clears the active SID and network metadata. Unblocking never
 * creates a session; the user must authenticate again.
 *
 * @return array{changed:bool, active_session_revoked:bool}
 */
function estab_user_admin_set_blocked(
    mysqli $connection,
    string $database,
    string $userTable,
    string $protocolTable,
    string $targetCode,
    bool $blocked,
    string $actor,
    string $remoteAddress
): array {
    $code = estab_user_admin_validate_code($targetCode);
    $lockName = estab_user_admin_account_lock_name(
        $database,
        $userTable,
        $code
    );
    $lockAcquired = false;
    $transactionActive = false;
    try {
        estab_user_admin_acquire_account_lock($connection, $lockName);
        $lockAcquired = true;
        if (!$connection->begin_transaction()) {
            throw new RuntimeException('Could not start account-management transaction');
        }
        $transactionActive = true;

        $account = estab_user_admin_fetch_for_update(
            $connection,
            $userTable,
            $code
        );
        $currentlyBlocked = (int) ($account['estab_gesperrt'] ?? 0) === 1;
        $activeSessionRevoked = (int) ($account['aktiv'] ?? 0) === 1
            || (string) ($account['sid'] ?? '') !== '';

        if (
            $currentlyBlocked === $blocked
            && !($blocked && $activeSessionRevoked)
        ) {
            if (!$connection->commit()) {
                throw new RuntimeException('Could not finish account-management read');
            }
            $transactionActive = false;
            return [
                'changed' => false,
                'active_session_revoked' => false,
            ];
        }

        $sql = $blocked
            ? 'UPDATE ' . estab_auth_table($userTable)
                . " SET `estab_gesperrt` = 1, `aktiv` = 0, `sid` = '',"
                . " `ip` = '', `fwdip` = ''"
                . ' WHERE `kuerzel` = ?'
            : 'UPDATE ' . estab_auth_table($userTable)
                . " SET `estab_gesperrt` = 0, `aktiv` = 0, `sid` = '',"
                . " `ip` = '', `fwdip` = ''"
                . ' WHERE `kuerzel` = ? AND `estab_gesperrt` = 1';
        $statement = $connection->prepare($sql);
        if (!$statement) {
            throw new RuntimeException('Could not prepare account block-state update');
        }
        try {
            $statement->bind_param('s', $code);
            if (!$statement->execute() || $statement->affected_rows !== 1) {
                throw new RuntimeException('Account block state changed concurrently');
            }
        } finally {
            $statement->close();
        }

        estab_auth_log_event(
            $connection,
            $protocolTable,
            'Benutzerverwaltung',
            estab_user_admin_audit_details(
                $blocked ? 'block' : 'unblock',
                $actor,
                $code,
                $activeSessionRevoked,
                $remoteAddress
            )
        );
        if (!$connection->commit()) {
            throw new RuntimeException('Could not commit account block-state update');
        }
        $transactionActive = false;
        return [
            'changed' => true,
            'active_session_revoked' => $activeSessionRevoked,
        ];
    } catch (Throwable $exception) {
        if ($transactionActive) {
            $connection->rollback();
            $transactionActive = false;
        }
        throw $exception;
    } finally {
        if ($transactionActive) {
            $connection->rollback();
        }
        if ($lockAcquired) {
            try {
                estab_user_admin_release_account_lock(
                    $connection,
                    $lockName
                );
            } catch (Throwable $exception) {
                // Closing the owning connection remains the fail-safe release.
                error_log(
                    'eStab user-administration lock cleanup failed: '
                    . $exception->getMessage()
                );
            }
        }
    }
}

/**
 * Replace a password hash and revoke every current session atomically.
 *
 * The cleartext password is neither returned nor placed in the audit payload.
 *
 * @return array{active_session_revoked:bool}
 */
function estab_user_admin_reset_password(
    mysqli $connection,
    string $database,
    string $userTable,
    string $protocolTable,
    string $targetCode,
    string $newPassword,
    string $actor,
    string $remoteAddress
): array {
    $code = estab_user_admin_validate_code($targetCode);

    $lockName = estab_user_admin_account_lock_name(
        $database,
        $userTable,
        $code
    );
    $lockAcquired = false;
    $passwordPolicyLockName = null;
    $passwordPolicyLockAcquired = false;
    $transactionActive = false;
    $passwordHash = null;
    try {
        $passwordPolicyLockName = estab_password_policy_acquire_lock(
            $connection,
            $database
        );
        $passwordPolicyLockAcquired = true;
        // Revalidate the single value at the hashing boundary. The HTTP page
        // validates confirmation separately and never persists either field.
        $password = estab_user_admin_validate_password(
            $newPassword,
            $newPassword,
            estab_password_policy_load($connection)
        );
        $passwordHash = estab_auth_hash_password($password);
        unset($password);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException(
                'Das neue Kennwort konnte nicht sicher gehasht werden.'
            );
        }
        estab_user_admin_acquire_account_lock($connection, $lockName);
        $lockAcquired = true;
        if (!$connection->begin_transaction()) {
            throw new RuntimeException('Could not start password-reset transaction');
        }
        $transactionActive = true;

        $account = estab_user_admin_fetch_for_update(
            $connection,
            $userTable,
            $code
        );
        $activeSessionRevoked = (int) ($account['aktiv'] ?? 0) === 1
            || (string) ($account['sid'] ?? '') !== '';

        $statement = $connection->prepare(
            'UPDATE ' . estab_auth_table($userTable)
            . " SET `password` = ?, `aktiv` = 0, `sid` = '',"
            . " `ip` = '', `fwdip` = '' WHERE `kuerzel` = ?"
        );
        if (!$statement) {
            throw new RuntimeException('Could not prepare password reset');
        }
        try {
            $statement->bind_param('ss', $passwordHash, $code);
            if (!$statement->execute() || $statement->affected_rows !== 1) {
                throw new RuntimeException('Password reset target changed concurrently');
            }
        } finally {
            $statement->close();
        }
        unset($passwordHash);

        estab_auth_log_event(
            $connection,
            $protocolTable,
            'Benutzerverwaltung',
            estab_user_admin_audit_details(
                'reset_password',
                $actor,
                $code,
                $activeSessionRevoked,
                $remoteAddress
            )
        );
        if (!$connection->commit()) {
            throw new RuntimeException('Could not commit password reset');
        }
        $transactionActive = false;
        return ['active_session_revoked' => $activeSessionRevoked];
    } catch (Throwable $exception) {
        if ($transactionActive) {
            $connection->rollback();
            $transactionActive = false;
        }
        throw $exception;
    } finally {
        unset($passwordHash);
        if ($transactionActive) {
            $connection->rollback();
        }
        if ($lockAcquired) {
            try {
                estab_user_admin_release_account_lock(
                    $connection,
                    $lockName
                );
            } catch (Throwable $exception) {
                // Closing the owning connection remains the fail-safe release.
                error_log(
                    'eStab user-administration lock cleanup failed: '
                    . $exception->getMessage()
                );
            }
        }
        if (
            $passwordPolicyLockAcquired
            && is_string($passwordPolicyLockName)
            && $passwordPolicyLockName !== ''
        ) {
            try {
                estab_password_policy_release_lock(
                    $connection,
                    $passwordPolicyLockName
                );
            } catch (Throwable $exception) {
                error_log(
                    'eStab password-reset policy-lock cleanup failed: '
                    . $exception->getMessage()
                );
            }
        }
    }
}
