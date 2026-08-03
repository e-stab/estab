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
require_once __DIR__ . '/dynamic_schema.php';
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
 * Read every function eligible as a personal LOOSE-mode addition.
 *
 * The recipient matrix remains authoritative for its functions. The
 * capability catalogue may add specialised functions, but it may never
 * contradict a role already fixed by the matrix.
 *
 * @return array<string,string> function => role
 */
function estab_user_admin_extra_function_roles(
    mysqli $connection,
    string $matrixTable
): array {
    $roles = estab_auth_merge_function_role_catalog(
        $connection,
        estab_assignment_function_roles($connection, $matrixTable)
    );
    return array_filter(
        $roles,
        static fn (string $role, string $function): bool =>
            estab_auth_extra_function_is_eligible($function, $role),
        ARRAY_FILTER_USE_BOTH
    );
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

/**
 * Validate an opaque stored extra-function key, including orphaned values.
 *
 * Revocation must remain possible after a catalogue entry disappears. It
 * therefore validates only the storage boundary, not current authorisation.
 */
function estab_user_admin_validate_extra_function_key(mixed $value): string
{
    if (
        !is_string($value)
        || preg_match('//u', $value) !== 1
        || preg_match('/[\p{C}]/u', $value) === 1
        || estab_auth_text_length($value) < 1
        || estab_auth_text_length($value) > 10
    ) {
        throw new InvalidArgumentException('Ungültige Zusatzfunktion.');
    }
    return $value;
}

/** Validate one exact, potentially historical primary-assignment value. */
function estab_user_admin_validate_expected_assignment_value(
    mixed $value,
    string $label,
    int $maximumLength
): string {
    if (
        !is_string($value)
        || preg_match('//u', $value) !== 1
        || preg_match('/[\p{C}]/u', $value) === 1
        || estab_auth_text_length($value) > $maximumLength
    ) {
        throw new InvalidArgumentException($label . ' ist ungültig.');
    }
    return $value;
}

/** Validate the exact DATETIME(6) token used as an optimistic row version. */
function estab_user_admin_validate_extra_function_time(mixed $value): string
{
    if (
        !is_string($value)
        || preg_match(
            '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:'
                . '[0-9]{2}:[0-9]{2}\.[0-9]{6}\z/D',
            $value
        ) !== 1
    ) {
        throw new InvalidArgumentException(
            'Die Version der Zusatzfunktion ist ungültig.'
        );
    }
    return $value;
}

/** Validate the browser-supplied revision of one account's complete set. */
function estab_user_admin_validate_extra_functions_revision(mixed $value): string
{
    if (
        !is_string($value)
        || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1
    ) {
        throw new InvalidArgumentException(
            'Die Revision der Zusatzfunktionen ist ungültig.'
        );
    }
    return $value;
}

/**
 * Hash the complete non-secret assignment state in deterministic order.
 *
 * @param list<array<string,mixed>> $assignments
 */
function estab_user_admin_extra_functions_revision(array $assignments): string
{
    $canonical = [];
    foreach ($assignments as $assignment) {
        if (!is_array($assignment)) {
            throw new InvalidArgumentException(
                'Ungültiger Stand der Zusatzfunktionen.'
            );
        }
        $canonical[] = [
            'funktion' => (string) ($assignment['funktion'] ?? ''),
            'rolle' => (string) ($assignment['rolle'] ?? ''),
            'vergeben_am' => (string) ($assignment['vergeben_am'] ?? ''),
            'vergeben_von' => (string) ($assignment['vergeben_von'] ?? ''),
        ];
    }
    usort(
        $canonical,
        static fn (array $left, array $right): int =>
            strcmp($left['funktion'], $right['funktion'])
                ?: strcmp($left['rolle'], $right['rolle'])
                ?: strcmp($left['vergeben_am'], $right['vergeben_am'])
                ?: strcmp($left['vergeben_von'], $right['vergeben_von'])
    );
    return hash(
        'sha256',
        json_encode(
            $canonical,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
        )
    );
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
 * Build the before/after audit payload for one personal extra function.
 *
 * @param null|array{funktion:string,rolle:string,vergeben_am:string,vergeben_von:string} $before
 * @param null|array{funktion:string,rolle:string,vergeben_am:string,vergeben_von:string} $after
 */
function estab_user_admin_extra_function_audit_details(
    string $action,
    string $actor,
    string $targetCode,
    bool $activeSessionRevoked,
    string $remoteAddress,
    ?array $before,
    ?array $after
): string {
    if (
        !in_array(
            $action,
            ['grant_extra_function', 'revoke_extra_function'],
            true
        )
        || ($action === 'grant_extra_function'
            && ($before !== null || $after === null))
        || ($action === 'revoke_extra_function'
            && ($before === null || $after !== null))
    ) {
        throw new InvalidArgumentException(
            'Invalid extra-function audit transition'
        );
    }
    $normalizeSnapshot = static function (array $snapshot): array {
        if (
            array_keys($snapshot) !== [
                'funktion',
                'rolle',
                'vergeben_am',
                'vergeben_von',
            ]
        ) {
            throw new InvalidArgumentException(
                'Invalid extra-function audit snapshot'
            );
        }
        $function = estab_user_admin_validate_extra_function_key(
            $snapshot['funktion']
        );
        $role = estab_user_admin_validate_expected_assignment_value(
            $snapshot['rolle'],
            'Rolle der Zusatzfunktion',
            16
        );
        if (!in_array($role, ['Stab', 'FB', 'Fernmelder'], true)) {
            throw new InvalidArgumentException(
                'Invalid extra-function audit snapshot'
            );
        }
        return [
            'funktion' => $function,
            'rolle' => $role,
            'vergeben_am' => estab_user_admin_validate_extra_function_time(
                $snapshot['vergeben_am']
            ),
            'vergeben_von' =>
                estab_user_admin_validate_expected_assignment_value(
                    $snapshot['vergeben_von'],
                    'Vergabeidentität',
                    128
                ),
        ];
    };
    if (
        estab_auth_text_length($actor) < 1
        || estab_auth_text_length($actor) > 128
        || preg_match('//u', $actor) !== 1
        || preg_match('/[\p{C}]/u', $actor) === 1
    ) {
        $actor = 'unknown';
    }
    $payload = json_encode(
        [
            'version' => 1,
            'action' => $action,
            'admin' => $actor,
            'target' => estab_user_admin_validate_code($targetCode),
            'active_session_revoked' => $activeSessionRevoked,
            'remote_address' => filter_var(
                $remoteAddress,
                FILTER_VALIDATE_IP
            ) === false ? '' : $remoteAddress,
            'before' => $before === null ? null : $normalizeSnapshot($before),
            'after' => $after === null ? null : $normalizeSnapshot($after),
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($payload)) {
        throw new RuntimeException(
            'Could not encode extra-function administration audit'
        );
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
 *   estab_gesperrt:int|string,
 *   zusatzfunktionen_revision:string,
 *   zusatzfunktionen:list<array{
 *     funktion:string,
 *     rolle:string,
 *     vergeben_am:string,
 *     vergeben_von:string,
 *     ist_gueltig:bool
 *   }>
 * }>
 */
function estab_user_admin_list(
    mysqli $connection,
    string $userTable,
    array $extraFunctionRoles = []
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
    } finally {
        $statement->close();
    }
    if (!is_array($rows)) {
        return [];
    }
    $extraStatement = $connection->prepare(
        'SELECT `benutzer_kuerzel`, `funktion`, `rolle`,'
        . ' `vergeben_am`, `vergeben_von`'
        . ' FROM `nv_benutzer_zusatzfunktionen`'
        . ' ORDER BY `benutzer_kuerzel`, `funktion`'
    );
    if (!$extraStatement) {
        throw new RuntimeException(
            'Could not prepare personal extra-function list'
        );
    }
    try {
        if (!$extraStatement->execute()) {
            throw new RuntimeException(
                'Could not read personal extra-function list'
            );
        }
        $extraResult = $extraStatement->get_result();
        $extrasByAccount = [];
        while (($extra = $extraResult->fetch_assoc()) !== null) {
            $code = (string) ($extra['benutzer_kuerzel'] ?? '');
            $function = (string) ($extra['funktion'] ?? '');
            $role = (string) ($extra['rolle'] ?? '');
            $extrasByAccount[$code][] = [
                'funktion' => $function,
                'rolle' => $role,
                'vergeben_am' => (string) ($extra['vergeben_am'] ?? ''),
                'vergeben_von' => (string) ($extra['vergeben_von'] ?? ''),
                'ist_gueltig' => isset($extraFunctionRoles[$function])
                    && hash_equals(
                        (string) $extraFunctionRoles[$function],
                        $role
                    ),
            ];
        }
        $extraResult->free();
    } finally {
        $extraStatement->close();
    }
    foreach ($rows as &$row) {
        $code = (string) ($row['kuerzel'] ?? '');
        $primaryFunction = (string) ($row['funktion'] ?? '');
        $row['zusatzfunktionen'] = $extrasByAccount[$code] ?? [];
        $row['zusatzfunktionen_revision'] =
            estab_user_admin_extra_functions_revision(
                $row['zusatzfunktionen']
            );
        foreach ($row['zusatzfunktionen'] as &$extra) {
            // A duplicate of the primary function never expands authority.
            $extra['ist_gueltig'] = (bool) $extra['ist_gueltig']
                && !hash_equals($primaryFunction, (string) $extra['funktion']);
        }
        unset($extra);
    }
    unset($row);
    return array_values($rows);
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

/** Reject a stale browser form after the account assignment changed. */
function estab_user_admin_require_expected_primary_assignment(
    array $account,
    mixed $expectedFunctionValue,
    mixed $expectedRoleValue
): void {
    $expectedFunction = estab_user_admin_validate_expected_assignment_value(
        $expectedFunctionValue,
        'Erwartete Primärfunktion',
        10
    );
    $expectedRole = estab_user_admin_validate_expected_assignment_value(
        $expectedRoleValue,
        'Erwartete Primärrolle',
        16
    );
    if (
        !hash_equals((string) ($account['funktion'] ?? ''), $expectedFunction)
        || !hash_equals((string) ($account['rolle'] ?? ''), $expectedRole)
    ) {
        throw new EstabUserAdminConflictException(
            'Die Primärfunktion des Kontos wurde zwischenzeitlich geändert. '
            . 'Bitte laden Sie die Benutzerverwaltung neu.'
        );
    }
}

/**
 * Lock and read one exact extra-function row.
 *
 * The result can change within one transaction after a write; PHPStan must not
 * treat repeated calls with the same arguments as a pure expression.
 *
 * @phpstan-impure
 */
function estab_user_admin_fetch_extra_function_for_update(
    mysqli $connection,
    string $code,
    string $function
): ?array {
    $statement = $connection->prepare(
        'SELECT `funktion`, `rolle`, `vergeben_am`, `vergeben_von`'
        . ' FROM `nv_benutzer_zusatzfunktionen`'
        . ' WHERE `benutzer_kuerzel` = ?'
        . ' AND `funktion` = ? LIMIT 1 FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Could not prepare personal extra-function lookup'
        );
    }
    try {
        $statement->bind_param('ss', $code, $function);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Could not read personal extra-function assignment'
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

/** Lock and return the complete extra-function set for revision checks. */
function estab_user_admin_fetch_extra_functions_for_update(
    mysqli $connection,
    string $code
): array {
    $statement = $connection->prepare(
        'SELECT `funktion`, `rolle`, `vergeben_am`, `vergeben_von`'
        . ' FROM `nv_benutzer_zusatzfunktionen`'
        . ' WHERE `benutzer_kuerzel` = ?'
        . ' ORDER BY `funktion` FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Could not prepare personal extra-function revision lookup'
        );
    }
    try {
        $statement->bind_param('s', $code);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Could not read personal extra-function revision'
            );
        }
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return is_array($rows) ? array_values($rows) : [];
    } finally {
        $statement->close();
    }
}

/** Revoke the current legacy session while the account row is locked. */
function estab_user_admin_revoke_locked_session(
    mysqli $connection,
    string $userTable,
    string $code,
    array $account
): bool {
    $wasActive = (int) ($account['aktiv'] ?? 0) === 1
        || (string) ($account['sid'] ?? '') !== '';
    $statement = $connection->prepare(
        'UPDATE ' . estab_auth_table($userTable)
        . " SET `aktiv` = 0, `sid` = '', `ip` = '', `fwdip` = ''"
        . ' WHERE `kuerzel` = ?'
        . ' AND BINARY `funktion` = BINARY ?'
        . ' AND BINARY `rolle` = BINARY ?'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare immediate session revocation');
    }
    try {
        $function = (string) ($account['funktion'] ?? '');
        $role = (string) ($account['rolle'] ?? '');
        $statement->bind_param('sss', $code, $function, $role);
        if (!$statement->execute()) {
            throw new EstabUserAdminConflictException(
                'Das Konto wurde zwischenzeitlich geändert.'
            );
        }
    } finally {
        $statement->close();
    }
    return $wasActive;
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
        if (estab_user_admin_fetch_extra_function_for_update(
            $connection,
            $code,
            $assignment['funktion']
        ) !== null) {
            throw new EstabUserAdminConflictException(
                'Die gewählte Primärfunktion ist noch als Zusatzfunktion '
                . 'vergeben. Entziehen Sie zuerst die Zusatzfunktion.'
            );
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
 * Grant one personal function for LOOSE-mode capability checks.
 *
 * @return array{funktion:string,rolle:string,active_session_revoked:bool}
 */
function estab_user_admin_grant_extra_function(
    mysqli $connection,
    string $database,
    string $userTable,
    string $protocolTable,
    string $targetCode,
    mixed $functionValue,
    mixed $expectedPrimaryFunction,
    mixed $expectedPrimaryRole,
    mixed $expectedExtraFunctionsRevisionValue,
    mixed $expectedAbsent,
    string $matrixTable,
    string $userTablePrefix,
    string $actor,
    string $remoteAddress,
    bool $confirmed
): array {
    $code = estab_user_admin_validate_code($targetCode);
    $actor = estab_user_admin_actor(['REMOTE_USER' => $actor]);
    $expectedExtraFunctionsRevision =
        estab_user_admin_validate_extra_functions_revision(
            $expectedExtraFunctionsRevisionValue
        );
    if ($expectedAbsent !== '1') {
        throw new InvalidArgumentException(
            'Der erwartete Stand der Zusatzfunktion ist ungültig.'
        );
    }
    if (!$confirmed) {
        throw new InvalidArgumentException(
            'Bestätigen Sie die Vergabe der persönlichen Zusatzfunktion.'
        );
    }
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
            estab_user_admin_extra_function_roles($connection, $matrixTable)
        );
        estab_user_admin_acquire_account_lock($connection, $lockName);
        $lockAcquired = true;
        if (!$connection->begin_transaction()) {
            throw new RuntimeException(
                'Could not start extra-function grant preflight transaction'
            );
        }
        $transactionActive = true;
        $account = estab_user_admin_fetch_for_update(
            $connection,
            $userTable,
            $code
        );
        estab_user_admin_require_expected_primary_assignment(
            $account,
            $expectedPrimaryFunction,
            $expectedPrimaryRole
        );
        $currentExtraFunctions =
            estab_user_admin_fetch_extra_functions_for_update(
                $connection,
                $code
            );
        if (!hash_equals(
            $expectedExtraFunctionsRevision,
            estab_user_admin_extra_functions_revision($currentExtraFunctions)
        )) {
            throw new EstabUserAdminConflictException(
                'Die Zusatzfunktionen wurden zwischenzeitlich geändert. '
                . 'Bitte laden Sie die Benutzerverwaltung neu.'
            );
        }
        if (hash_equals(
            (string) ($account['funktion'] ?? ''),
            $assignment['funktion']
        )) {
            throw new InvalidArgumentException(
                'Die Primärfunktion kann nicht zusätzlich vergeben werden.'
            );
        }
        if (estab_user_admin_fetch_extra_function_for_update(
            $connection,
            $code,
            $assignment['funktion']
        ) !== null) {
            throw new EstabUserAdminConflictException(
                'Diese Zusatzfunktion wurde zwischenzeitlich bereits vergeben. '
                . 'Bitte laden Sie die Benutzerverwaltung neu.'
            );
        }
        if (!$connection->rollback()) {
            throw new RuntimeException(
                'Could not finish extra-function grant preflight'
            );
        }
        $transactionActive = false;

        // MariaDB DDL commits implicitly. Reconcile the idempotent legacy
        // workspace while both advisory locks are held, before any grant,
        // audit row or session revocation exists. A failed/partial DDL pass
        // therefore grants no authority and a retry safely completes it.
        estab_dynamic_schema_reconcile_hat(
            $connection,
            $userTablePrefix,
            $assignment['funktion'],
            $code,
            $assignment['rolle']
        );

        if (!$connection->begin_transaction()) {
            throw new RuntimeException(
                'Could not start extra-function grant transaction'
            );
        }
        $transactionActive = true;
        $revalidatedAssignment = estab_user_admin_validate_assignment(
            $assignment['funktion'],
            estab_user_admin_extra_function_roles($connection, $matrixTable)
        );
        if (!hash_equals(
            $assignment['rolle'],
            $revalidatedAssignment['rolle']
        )) {
            throw new EstabUserAdminConflictException(
                'Die Zusatzfunktion wurde zwischenzeitlich geändert. '
                . 'Bitte laden Sie die Benutzerverwaltung neu.'
            );
        }
        $account = estab_user_admin_fetch_for_update(
            $connection,
            $userTable,
            $code
        );
        estab_user_admin_require_expected_primary_assignment(
            $account,
            $expectedPrimaryFunction,
            $expectedPrimaryRole
        );
        $currentExtraFunctions =
            estab_user_admin_fetch_extra_functions_for_update(
                $connection,
                $code
            );
        if (!hash_equals(
            $expectedExtraFunctionsRevision,
            estab_user_admin_extra_functions_revision($currentExtraFunctions)
        )) {
            throw new EstabUserAdminConflictException(
                'Die Zusatzfunktionen wurden zwischenzeitlich geändert. '
                . 'Bitte laden Sie die Benutzerverwaltung neu.'
            );
        }
        if (hash_equals(
            (string) ($account['funktion'] ?? ''),
            $assignment['funktion']
        )) {
            throw new InvalidArgumentException(
                'Die Primärfunktion kann nicht zusätzlich vergeben werden.'
            );
        }
        if (estab_user_admin_fetch_extra_function_for_update(
            $connection,
            $code,
            $assignment['funktion']
        ) !== null) {
            throw new EstabUserAdminConflictException(
                'Diese Zusatzfunktion wurde zwischenzeitlich bereits vergeben. '
                . 'Bitte laden Sie die Benutzerverwaltung neu.'
            );
        }
        $insert = $connection->prepare(
            'INSERT INTO `nv_benutzer_zusatzfunktionen`'
            . ' (`benutzer_kuerzel`, `funktion`, `rolle`,'
            . ' `vergeben_am`, `vergeben_von`)'
            . ' VALUES (?, ?, ?, NOW(6), ?)'
        );
        if (!$insert) {
            throw new RuntimeException(
                'Could not prepare personal extra-function grant'
            );
        }
        try {
            $insert->bind_param(
                'ssss',
                $code,
                $assignment['funktion'],
                $assignment['rolle'],
                $actor
            );
            if (!$insert->execute() || $insert->affected_rows !== 1) {
                throw new EstabUserAdminConflictException(
                    'Die Zusatzfunktion konnte wegen einer gleichzeitigen '
                    . 'Änderung nicht vergeben werden.'
                );
            }
        } finally {
            $insert->close();
        }
        $after = estab_user_admin_fetch_extra_function_for_update(
            $connection,
            $code,
            $assignment['funktion']
        );
        if (!is_array($after)) {
            throw new RuntimeException(
                'The granted extra function could not be read back'
            );
        }
        $activeSessionRevoked = estab_user_admin_revoke_locked_session(
            $connection,
            $userTable,
            $code,
            $account
        );
        estab_auth_log_event(
            $connection,
            $protocolTable,
            'Benutzerverwaltung',
            estab_user_admin_extra_function_audit_details(
                'grant_extra_function',
                $actor,
                $code,
                $activeSessionRevoked,
                $remoteAddress,
                null,
                $after
            )
        );
        if (!$connection->commit()) {
            throw new RuntimeException(
                'Could not commit personal extra-function grant'
            );
        }
        $transactionActive = false;
        return [
            'funktion' => $assignment['funktion'],
            'rolle' => $assignment['rolle'],
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
                estab_user_admin_release_account_lock($connection, $lockName);
            } catch (Throwable $exception) {
                error_log(
                    'eStab extra-function grant account-lock cleanup failed: '
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
                    'eStab extra-function grant policy-lock cleanup failed: '
                    . $exception->getMessage()
                );
            }
        }
    }
}

/**
 * Revoke one personal function, including an orphaned catalogue assignment.
 *
 * @return array{funktion:string,rolle:string,active_session_revoked:bool}
 */
function estab_user_admin_revoke_extra_function(
    mysqli $connection,
    string $database,
    string $userTable,
    string $protocolTable,
    string $targetCode,
    mixed $functionValue,
    mixed $expectedRoleValue,
    mixed $expectedGrantedAtValue,
    mixed $expectedGrantedByValue,
    mixed $expectedPrimaryFunction,
    mixed $expectedPrimaryRole,
    mixed $expectedExtraFunctionsRevisionValue,
    string $matrixTable,
    string $actor,
    string $remoteAddress,
    bool $confirmed
): array {
    $code = estab_user_admin_validate_code($targetCode);
    $actor = estab_user_admin_actor(['REMOTE_USER' => $actor]);
    $expectedExtraFunctionsRevision =
        estab_user_admin_validate_extra_functions_revision(
            $expectedExtraFunctionsRevisionValue
        );
    $function = estab_user_admin_validate_extra_function_key($functionValue);
    $expectedRole = estab_user_admin_validate_expected_assignment_value(
        $expectedRoleValue,
        'Erwartete Rolle der Zusatzfunktion',
        16
    );
    if (!in_array($expectedRole, ['Stab', 'FB', 'Fernmelder'], true)) {
        throw new InvalidArgumentException(
            'Die erwartete Rolle der Zusatzfunktion ist ungültig.'
        );
    }
    $expectedGrantedAt = estab_user_admin_validate_extra_function_time(
        $expectedGrantedAtValue
    );
    $expectedGrantedBy = estab_user_admin_validate_expected_assignment_value(
        $expectedGrantedByValue,
        'Erwartete Vergabeidentität',
        128
    );
    if (!$confirmed) {
        throw new InvalidArgumentException(
            'Bestätigen Sie den Entzug der persönlichen Zusatzfunktion.'
        );
    }
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
        estab_user_admin_acquire_account_lock($connection, $lockName);
        $lockAcquired = true;
        if (!$connection->begin_transaction()) {
            throw new RuntimeException(
                'Could not start extra-function revocation transaction'
            );
        }
        $transactionActive = true;
        $account = estab_user_admin_fetch_for_update(
            $connection,
            $userTable,
            $code
        );
        estab_user_admin_require_expected_primary_assignment(
            $account,
            $expectedPrimaryFunction,
            $expectedPrimaryRole
        );
        $currentExtraFunctions =
            estab_user_admin_fetch_extra_functions_for_update(
                $connection,
                $code
            );
        if (!hash_equals(
            $expectedExtraFunctionsRevision,
            estab_user_admin_extra_functions_revision($currentExtraFunctions)
        )) {
            throw new EstabUserAdminConflictException(
                'Die Zusatzfunktionen wurden zwischenzeitlich geändert. '
                . 'Bitte laden Sie die Benutzerverwaltung neu.'
            );
        }
        $before = estab_user_admin_fetch_extra_function_for_update(
            $connection,
            $code,
            $function
        );
        if (!is_array($before)) {
            throw new EstabUserAdminConflictException(
                'Diese Zusatzfunktion wurde zwischenzeitlich bereits '
                . 'entzogen. Bitte laden Sie die Benutzerverwaltung neu.'
            );
        }
        if (
            !hash_equals((string) ($before['rolle'] ?? ''), $expectedRole)
            || !hash_equals(
                (string) ($before['vergeben_am'] ?? ''),
                $expectedGrantedAt
            )
            || !hash_equals(
                (string) ($before['vergeben_von'] ?? ''),
                $expectedGrantedBy
            )
        ) {
            throw new EstabUserAdminConflictException(
                'Die Zusatzfunktion wurde zwischenzeitlich geändert. '
                . 'Bitte laden Sie die Benutzerverwaltung neu.'
            );
        }
        $delete = $connection->prepare(
            'DELETE FROM `nv_benutzer_zusatzfunktionen`'
            . ' WHERE `benutzer_kuerzel` = ?'
            . ' AND BINARY `funktion` = BINARY ?'
            . ' AND BINARY `rolle` = BINARY ?'
            . ' AND `vergeben_am` = ?'
            . ' AND BINARY `vergeben_von` = BINARY ?'
        );
        if (!$delete) {
            throw new RuntimeException(
                'Could not prepare personal extra-function revocation'
            );
        }
        try {
            $delete->bind_param(
                'sssss',
                $code,
                $function,
                $expectedRole,
                $expectedGrantedAt,
                $expectedGrantedBy
            );
            if (!$delete->execute() || $delete->affected_rows !== 1) {
                throw new EstabUserAdminConflictException(
                    'Die Zusatzfunktion wurde gleichzeitig geändert.'
                );
            }
        } finally {
            $delete->close();
        }
        $activeSessionRevoked = estab_user_admin_revoke_locked_session(
            $connection,
            $userTable,
            $code,
            $account
        );
        estab_auth_log_event(
            $connection,
            $protocolTable,
            'Benutzerverwaltung',
            estab_user_admin_extra_function_audit_details(
                'revoke_extra_function',
                $actor,
                $code,
                $activeSessionRevoked,
                $remoteAddress,
                $before,
                null
            )
        );
        if (!$connection->commit()) {
            throw new RuntimeException(
                'Could not commit personal extra-function revocation'
            );
        }
        $transactionActive = false;
        return [
            'funktion' => $function,
            'rolle' => $expectedRole,
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
                estab_user_admin_release_account_lock($connection, $lockName);
            } catch (Throwable $exception) {
                error_log(
                    'eStab extra-function revoke account-lock cleanup failed: '
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
                    'eStab extra-function revoke policy-lock cleanup failed: '
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
