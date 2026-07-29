<?php

declare(strict_types=1);

/**
 * Authoritative assignment-policy boundary.
 *
 * The active recipient matrix controls which Stab/FB functions exist and
 * which role belongs to each function. A connection-scoped MariaDB advisory
 * lock serialises every active-matrix replacement with login, account
 * creation and account reassignment:
 *
 *     assignment-policy lock -> account lock -> transaction/row locks
 *
 * Standard-matrix reads do not change policy and deliberately need no lock.
 */

require_once __DIR__ . '/auth.php';

if (!defined('ESTAB_ASSIGNMENT_POLICY_LOCK_TIMEOUT')) {
    define('ESTAB_ASSIGNMENT_POLICY_LOCK_TIMEOUT', 10);
}

final class EstabAssignmentBusyException extends RuntimeException
{
}

/** Generate a stable MariaDB advisory-lock name within the 64-byte limit. */
function estab_assignment_policy_lock_name(
    string $database,
    string $matrixTable
): string {
    estab_auth_table($matrixTable);
    return 'estab:assignment:' . substr(
        hash('sha256', $database . "\0" . $matrixTable),
        0,
        47
    );
}

/** Acquire the global policy lock and return its connection-scoped name. */
function estab_assignment_acquire_policy_lock(
    mysqli $connection,
    string $database,
    string $matrixTable
): string {
    $timeout = ESTAB_ASSIGNMENT_POLICY_LOCK_TIMEOUT;
    if (!is_int($timeout) || $timeout < 0 || $timeout > 30) {
        throw new RuntimeException('Invalid assignment-policy lock timeout');
    }
    $lockName = estab_assignment_policy_lock_name($database, $matrixTable);
    $statement = $connection->prepare('SELECT GET_LOCK(?, ?)');
    if (!$statement) {
        throw new RuntimeException('Could not prepare assignment-policy lock');
    }
    try {
        $statement->bind_param('si', $lockName, $timeout);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not acquire assignment-policy lock');
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
            throw new EstabAssignmentBusyException(
                'Die Funktionszuordnungen werden gerade geändert. '
                . 'Bitte versuchen Sie es erneut.'
            );
        }
    } finally {
        $statement->close();
    }
    return $lockName;
}

/** Release a policy lock held by the current MariaDB connection. */
function estab_assignment_release_policy_lock(
    mysqli $connection,
    string $lockName
): void {
    $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
    if (!$statement) {
        throw new RuntimeException('Could not prepare assignment-policy unlock');
    }
    try {
        $statement->bind_param('s', $lockName);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not release assignment-policy lock');
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
            throw new RuntimeException('Assignment-policy lock was lost');
        }
    } finally {
        $statement->close();
    }
}

/**
 * Read the authoritative function-to-role map while the caller holds the
 * assignment-policy lock.
 *
 * @return array<string,string>
 */
function estab_assignment_function_roles(
    mysqli $connection,
    string $matrixTable
): array {
    $roles = [
        'Si' => 'Stab',
        'A/W' => 'Fernmelder',
    ];
    $statement = $connection->prepare(
        'SELECT `mtx_fkt`, `mtx_rolle` FROM '
        . estab_auth_table($matrixTable)
        . " WHERE `mtx_typ` = 'cb' AND `mtx_fkt` <> ''"
        . " AND `mtx_rolle` IN ('Stab', 'FB')"
        . ' ORDER BY `mtx_fkt`, `mtx_rolle`'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare assignment-policy lookup');
    }
    try {
        if (!$statement->execute()) {
            throw new RuntimeException('Could not read assignment policy');
        }
        $result = $statement->get_result();
        while ($row = $result->fetch_assoc()) {
            $function = trim((string) ($row['mtx_fkt'] ?? ''));
            $role = trim((string) ($row['mtx_rolle'] ?? ''));
            if (
                preg_match('/\A[A-Za-z0-9_]{1,6}\z/D', $function) !== 1
                || !in_array($role, ['Stab', 'FB'], true)
                || (
                    isset($roles[$function])
                    && !hash_equals($roles[$function], $role)
                )
            ) {
                throw new RuntimeException(
                    'Die Empfängermatrix enthält keine eindeutige '
                    . 'Funktionszuordnung.'
                );
            }
            $roles[$function] = $role;
        }
        $result->free();
    } finally {
        $statement->close();
    }
    uksort($roles, 'strnatcasecmp');
    return $roles;
}

/**
 * Derive the assignment policy from a canonical validated matrix payload.
 *
 * @return array<string,string>
 */
function estab_assignment_roles_from_matrix(array $matrix): array
{
    $cells = $matrix['cells'] ?? null;
    if (!is_array($cells) || count($cells) !== 20) {
        throw new InvalidArgumentException(
            'A complete validated recipient matrix is required'
        );
    }
    $roles = [
        'Si' => 'Stab',
        'A/W' => 'Fernmelder',
    ];
    foreach ($cells as $cell) {
        if (!is_array($cell)) {
            throw new InvalidArgumentException('Invalid recipient matrix cell');
        }
        $function = (string) ($cell['function'] ?? '');
        $role = (string) ($cell['role'] ?? '');
        if ($function === '' && $role === '') {
            continue;
        }
        if (
            preg_match('/\A[A-Za-z0-9_]{1,6}\z/D', $function) !== 1
            || !in_array($role, ['Stab', 'FB'], true)
            || isset($roles[$function])
        ) {
            throw new InvalidArgumentException(
                'Invalid or duplicate assignment-policy function'
            );
        }
        $roles[$function] = $role;
    }
    uksort($roles, 'strnatcasecmp');
    return $roles;
}

/** Convert a role map to the legacy configuration shape used by login forms. */
function estab_assignment_roles_as_conf_empf(array $roles): array
{
    $configuration = [];
    $index = 1;
    foreach ($roles as $function => $role) {
        if (!is_string($function) || !is_string($role)) {
            throw new InvalidArgumentException('Invalid assignment role map');
        }
        $configuration[$index++] = ['fkt' => $function, 'rolle' => $role];
    }
    return $configuration;
}

/** Return whether one stored function/role pair is still authoritative. */
function estab_assignment_is_current(
    array $roles,
    string $function,
    string $role
): bool {
    $currentRole = $roles[$function] ?? null;
    return is_string($currentRole) && hash_equals($currentRole, $role);
}

/** Make imported legacy values safe and loss-aware in structured audit JSON. */
function estab_assignment_audit_value(string $value): string
{
    if (
        strlen($value) <= 128
        && preg_match('//u', $value) === 1
        && preg_match('/[\p{C}]/u', $value) !== 1
    ) {
        return $value;
    }
    return 'hex:' . substr(bin2hex($value), 0, 256);
}

/** Build a credential-free audit record for a matrix-driven account change. */
function estab_assignment_account_audit(
    string $action,
    string $actor,
    array $account,
    bool $activeSessionRevoked,
    string $remoteAddress,
    string $newRole
): string {
    if (!in_array($action, ['matrix_role_sync', 'matrix_orphan'], true)) {
        throw new InvalidArgumentException('Unknown assignment audit action');
    }
    $payload = json_encode(
        [
            'version' => 1,
            'action' => $action,
            'admin' => estab_assignment_audit_value($actor),
            'target' => estab_assignment_audit_value(
                (string) ($account['kuerzel'] ?? '')
            ),
            'old_function' => estab_assignment_audit_value(
                (string) ($account['funktion'] ?? '')
            ),
            'old_role' => estab_assignment_audit_value(
                (string) ($account['rolle'] ?? '')
            ),
            'new_function' => estab_assignment_audit_value(
                (string) ($account['funktion'] ?? '')
            ),
            'new_role' => estab_assignment_audit_value($newRole),
            'active_session_revoked' => $activeSessionRevoked,
            'remote_address' => filter_var(
                $remoteAddress,
                FILTER_VALIDATE_IP
            ) === false ? '' : $remoteAddress,
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($payload)) {
        throw new RuntimeException('Could not encode assignment audit');
    }
    return $payload;
}

/**
 * Reconcile all accounts after an active-matrix replacement.
 *
 * The caller owns the assignment-policy lock and an open transaction. A role
 * change updates only the server-derived role and revokes the session. A
 * removed function keeps its last explicit function/role assignment for
 * operator review, but every session is revoked and future login fails because
 * the function no longer exists in the authoritative map.
 *
 * @return array{role_synced:int,orphaned:int,sessions_revoked:int}
 */
function estab_assignment_reconcile_accounts(
    mysqli $connection,
    string $userTable,
    string $protocolTable,
    array $oldRoles,
    array $newRoles,
    string $actor,
    string $remoteAddress
): array {
    $statement = $connection->prepare(
        'SELECT `benutzer`, `kuerzel`, `funktion`, `rolle`, `aktiv`, `sid`,'
        . ' `ip`, `fwdip` FROM ' . estab_auth_table($userTable)
        . ' ORDER BY `kuerzel` FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare account reconciliation');
    }
    try {
        if (!$statement->execute()) {
            throw new RuntimeException('Could not lock accounts for reconciliation');
        }
        $result = $statement->get_result();
        $accounts = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } finally {
        $statement->close();
    }

    $roleUpdate = $connection->prepare(
        'UPDATE ' . estab_auth_table($userTable)
        . " SET `rolle` = ?, `aktiv` = 0, `sid` = '', `ip` = '', `fwdip` = ''"
        . ' WHERE `kuerzel` = ? AND `funktion` = ?'
    );
    $sessionUpdate = $connection->prepare(
        'UPDATE ' . estab_auth_table($userTable)
        . " SET `aktiv` = 0, `sid` = '', `ip` = '', `fwdip` = ''"
        . ' WHERE `kuerzel` = ? AND `funktion` = ?'
    );
    if (!$roleUpdate || !$sessionUpdate) {
        if ($roleUpdate instanceof mysqli_stmt) {
            $roleUpdate->close();
        }
        if ($sessionUpdate instanceof mysqli_stmt) {
            $sessionUpdate->close();
        }
        throw new RuntimeException('Could not prepare account-policy reconciliation');
    }

    $summary = ['role_synced' => 0, 'orphaned' => 0, 'sessions_revoked' => 0];
    try {
        foreach ($accounts as $account) {
            $code = (string) ($account['kuerzel'] ?? '');
            $function = (string) ($account['funktion'] ?? '');
            $oldRole = (string) ($account['rolle'] ?? '');
            $newRole = $newRoles[$function] ?? null;
            $hadSession = (int) ($account['aktiv'] ?? 0) === 1
                || (string) ($account['sid'] ?? '') !== '';
            $hadSessionMetadata = $hadSession
                || (string) ($account['ip'] ?? '') !== ''
                || (string) ($account['fwdip'] ?? '') !== '';

            if (is_string($newRole)) {
                if (hash_equals($oldRole, $newRole)) {
                    continue;
                }
                $roleUpdate->bind_param('sss', $newRole, $code, $function);
                if (!$roleUpdate->execute() || $roleUpdate->affected_rows !== 1) {
                    throw new RuntimeException(
                        'Account role changed during matrix reconciliation'
                    );
                }
                estab_auth_log_event(
                    $connection,
                    $protocolTable,
                    'Benutzerverwaltung',
                    estab_assignment_account_audit(
                        'matrix_role_sync',
                        $actor,
                        $account,
                        $hadSession,
                        $remoteAddress,
                        $newRole
                    )
                );
                $summary['role_synced']++;
                if ($hadSession) {
                    $summary['sessions_revoked']++;
                }
                continue;
            }

            $newlyOrphaned = array_key_exists($function, $oldRoles);
            if (!$newlyOrphaned && !$hadSessionMetadata) {
                continue;
            }
            if ($hadSessionMetadata) {
                $sessionUpdate->bind_param('ss', $code, $function);
                if (
                    !$sessionUpdate->execute()
                    || $sessionUpdate->affected_rows !== 1
                ) {
                    throw new RuntimeException(
                        'Account session changed during orphan reconciliation'
                    );
                }
            }
            estab_auth_log_event(
                $connection,
                $protocolTable,
                'Benutzerverwaltung',
                estab_assignment_account_audit(
                    'matrix_orphan',
                    $actor,
                    $account,
                    $hadSession,
                    $remoteAddress,
                    $oldRole
                )
            );
            $summary['orphaned']++;
            if ($hadSession) {
                $summary['sessions_revoked']++;
            }
        }
    } finally {
        $roleUpdate->close();
        $sessionUpdate->close();
    }
    return $summary;
}

/** Encode the matrix/account transaction summary for nv_protokoll. */
function estab_assignment_matrix_audit(
    string $action,
    array $summary
): string {
    if (!in_array($action, ['replace_active', 'replace_active_and_standard'], true)) {
        throw new InvalidArgumentException('Unknown matrix audit action');
    }
    $payload = json_encode(
        [
            'version' => 1,
            'action' => $action,
            'positions' => 20,
            'role_synced' => (int) ($summary['role_synced'] ?? 0),
            'orphaned' => (int) ($summary['orphaned'] ?? 0),
            'sessions_revoked' => (int) ($summary['sessions_revoked'] ?? 0),
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
    if (!is_string($payload)) {
        throw new RuntimeException('Could not encode matrix audit');
    }
    return $payload;
}
