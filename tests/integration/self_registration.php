<?php

declare(strict_types=1);

define('ESTAB_SELF_REGISTRATION_LOCK_TIMEOUT', 0);

require_once dirname(__DIR__, 2) . '/app/self_registration.php';

$assertions = 0;
function self_registration_test_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function self_registration_test_scalar(
    mysqli $connection,
    string $sql,
    string $types = '',
    mixed ...$parameters
): ?string {
    $statement = $connection->prepare($sql);
    self_registration_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare self-registration scalar query'
    );
    try {
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        self_registration_test_assert(
            $statement->execute(),
            'Could not execute self-registration scalar query'
        );
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        return is_array($row) ? (string) ($row[0] ?? '') : null;
    } finally {
        $statement->close();
    }
}

/** @return array{mode:string,enabled_until_utc:?string,revision:int,updated_at:string,updated_by:string} */
function self_registration_test_persisted(array $policy): array
{
    return [
        'mode' => (string) ($policy['mode'] ?? ''),
        'enabled_until_utc' => is_string($policy['enabled_until_utc'] ?? null)
            ? $policy['enabled_until_utc']
            : null,
        'revision' => (int) ($policy['revision'] ?? -1),
        'updated_at' => (string) ($policy['updated_at'] ?? ''),
        'updated_by' => (string) ($policy['updated_by'] ?? ''),
    ];
}

function self_registration_test_restore_policy(
    mysqli $connection,
    array $policy
): void {
    $mode = (string) $policy['mode'];
    $deadline = is_string($policy['enabled_until_utc'] ?? null)
        ? $policy['enabled_until_utc']
        : null;
    $revision = (int) $policy['revision'];
    $updatedAt = (string) $policy['updated_at'];
    $updatedBy = (string) $policy['updated_by'];
    $statement = $connection->prepare(
        'UPDATE `nv_selbstregistrierung` SET `mode` = ?,'
        . ' `enabled_until_utc` = ?, `revision` = ?, `updated_at` = ?,'
        . ' `updated_by` = ? WHERE `singleton_id` = 1'
    );
    if (!$statement instanceof mysqli_stmt) {
        throw new RuntimeException('Could not prepare self-registration restore');
    }
    try {
        $statement->bind_param(
            'ssiss',
            $mode,
            $deadline,
            $revision,
            $updatedAt,
            $updatedBy
        );
        if (!$statement->execute() || $statement->affected_rows > 1) {
            throw new RuntimeException('Could not restore self-registration policy');
        }
    } finally {
        $statement->close();
    }
}

function self_registration_test_set_fixture_mode(
    mysqli $connection,
    string $mode,
    string $deadlineExpression,
    string $actor
): void {
    if (!in_array($mode, [
        ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT,
        ESTAB_SELF_REGISTRATION_MODE_DISABLED,
        ESTAB_SELF_REGISTRATION_MODE_PERMANENT,
        ESTAB_SELF_REGISTRATION_MODE_UNTIL,
    ], true)) {
        throw new InvalidArgumentException('Unsafe fixture mode');
    }
    if (!in_array($deadlineExpression, [
        'NULL',
        'UTC_TIMESTAMP(6)',
        'TIMESTAMPADD(MICROSECOND, -1, UTC_TIMESTAMP(6))',
    ], true)) {
        throw new InvalidArgumentException('Unsafe fixture deadline');
    }
    $statement = $connection->prepare(
        'UPDATE `nv_selbstregistrierung` SET `mode` = ?,'
        . ' `enabled_until_utc` = ' . $deadlineExpression . ','
        . ' `updated_at` = UTC_TIMESTAMP(6), `updated_by` = ?'
        . ' WHERE `singleton_id` = 1'
    );
    if (!$statement instanceof mysqli_stmt) {
        throw new RuntimeException('Could not prepare self-registration fixture');
    }
    try {
        $statement->bind_param('ss', $mode, $actor);
        if (!$statement->execute() || $statement->affected_rows > 1) {
            throw new RuntimeException('Could not set self-registration fixture');
        }
    } finally {
        $statement->close();
    }
}

/** @return array<string,string> */
function self_registration_test_user(string $code, string $label): array
{
    $hash = password_hash(
        'Self registration integration 2026! ' . $code,
        PASSWORD_ARGON2ID
    );
    if (!is_string($hash)) {
        throw new RuntimeException('Could not hash self-registration fixture password');
    }
    return [
        'benutzer' => 'Selbstregistrierung ' . $label,
        'kuerzel' => $code,
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
        'sid' => substr(hash('sha256', 'self-registration-' . $code), 0, 48),
        'ip' => '192.0.2.46',
        'fwdip' => '',
        'password' => $hash,
    ];
}

function self_registration_test_restore_environment(
    string $name,
    string|false $original
): void {
    if ($original === false) {
        putenv($name);
        return;
    }
    putenv($name . '=' . $original);
}

$host = getenv('ESTAB_TEST_DB_HOST')
    ?: (getenv('ESTAB_DB_HOST') ?: '127.0.0.1');
$port = (int) (
    getenv('ESTAB_TEST_DB_PORT')
    ?: (getenv('ESTAB_DB_PORT') ?: 3306)
);
$database = getenv('ESTAB_TEST_DB_NAME')
    ?: (getenv('ESTAB_DB_NAME') ?: 'estab');
$username = getenv('ESTAB_TEST_DB_USER')
    ?: (getenv('ESTAB_DB_USER') ?: 'estab');
$databasePassword = getenv('ESTAB_TEST_DB_PASSWORD');
if (!is_string($databasePassword) || $databasePassword === '') {
    $databasePassword = getenv('ESTAB_DB_PASSWORD') ?: '';
}
$config = [
    'server' => $host . ':' . $port,
    'user' => $username,
    'password' => $databasePassword,
    'datenbank' => $database,
];
self_registration_test_assert(
    preg_match('/\A[A-Za-z0-9_]+\z/D', $database) === 1,
    'Unsafe self-registration integration database name'
);

$token = substr(bin2hex(random_bytes(4)), 0, 4);
$marker = 'self-registration-it-' . bin2hex(random_bytes(6));
$codes = [
    'environment_closed' => 'sc' . $token,
    'environment_open' => 'se' . $token,
    'disabled' => 'sd' . $token,
    'permanent' => 'sp' . $token,
    'future' => 'sf' . $token,
    'boundary' => 'sb' . $token,
    'expired' => 'sx' . $token,
];
$environmentBefore = getenv('ESTAB_ALLOW_SELF_REGISTRATION');
$connection = estab_auth_connect($config);
$secondConnection = estab_auth_connect($config);
$originalPolicy = null;
$auditFloor = 0;

try {
    self_registration_test_assert(
        self_registration_test_scalar(
            $connection,
            "SELECT COUNT(*) FROM information_schema.tables"
            . " WHERE table_schema = DATABASE()"
            . " AND table_name = 'nv_selbstregistrierung'"
            . " AND table_type = 'BASE TABLE' AND engine = 'InnoDB'"
            . " AND table_comment = "
            . "'estab:migration:114:self-registration-policy:v1'"
        ) === '1'
            && self_registration_test_scalar(
                $connection,
                'SELECT COUNT(*) FROM information_schema.columns'
                . ' WHERE table_schema = DATABASE()'
                . " AND table_name = 'nv_selbstregistrierung'"
                . " AND column_name = 'mode'"
                . " AND column_default = '''ENVIRONMENT'''"
            ) === '1',
        'Self-registration migration or ENVIRONMENT default is not applied'
    );
    $originalPolicy = estab_self_registration_load($connection);
    $auditFloor = (int) (
        self_registration_test_scalar(
            $connection,
            'SELECT COALESCE(MAX(`p_lfd`), 0) FROM `nv_protokoll`'
        ) ?? 0
    );

    self_registration_test_set_fixture_mode(
        $connection,
        ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT,
        'NULL',
        $marker
    );
    putenv('ESTAB_ALLOW_SELF_REGISTRATION=false');
    $environmentPolicy = estab_self_registration_load($connection);
    self_registration_test_assert(
        !estab_self_registration_is_allowed($environmentPolicy)
            && !estab_self_registration_insert_user_if_allowed(
                $connection,
                'nv_benutzer',
                self_registration_test_user(
                    $codes['environment_closed'],
                    'ENV geschlossen'
                )
            )
            && self_registration_test_scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_benutzer` WHERE `kuerzel` = ?',
                's',
                $codes['environment_closed']
            ) === '0',
        'ENVIRONMENT mode ignored the disabled deployment fallback'
    );
    putenv('ESTAB_ALLOW_SELF_REGISTRATION=true');
    $environmentPolicy = estab_self_registration_load($connection);
    self_registration_test_assert(
        estab_self_registration_is_allowed($environmentPolicy)
            && estab_self_registration_insert_user_if_allowed(
                $connection,
                'nv_benutzer',
                self_registration_test_user(
                    $codes['environment_open'],
                    'ENV offen'
                )
            ),
        'ENVIRONMENT mode ignored the enabled deployment fallback'
    );

    $environmentRevision = (int) $environmentPolicy['revision'];
    $disabled = estab_self_registration_update(
        $connection,
        $database,
        'nv_protokoll',
        ESTAB_SELF_REGISTRATION_MODE_DISABLED,
        null,
        $environmentRevision,
        $marker,
        '192.0.2.46'
    );
    $disabledPolicy = $disabled['policy'] ?? null;
    self_registration_test_assert(
        ($disabled['changed'] ?? null) === true
            && is_array($disabledPolicy)
            && ($disabledPolicy['mode'] ?? null)
                === ESTAB_SELF_REGISTRATION_MODE_DISABLED
            && (int) ($disabledPolicy['revision'] ?? -1)
                === $environmentRevision + 1
            && !estab_self_registration_is_allowed($disabledPolicy),
        'DISABLED mode was not persisted with one revision'
    );

    $auditJson = self_registration_test_scalar(
        $connection,
        "SELECT `p_ereignis` FROM `nv_protokoll`"
        . " WHERE `p_lfd` > ? AND `p_was` = 'Selbstregistrierung'"
        . ' ORDER BY `p_lfd` DESC LIMIT 1',
        'i',
        $auditFloor
    );
    $audit = is_string($auditJson)
        ? json_decode($auditJson, true, 8, JSON_THROW_ON_ERROR)
        : null;
    self_registration_test_assert(
        is_array($audit)
            && ($audit['action'] ?? null)
                === 'self_registration_policy_updated'
            && ($audit['admin'] ?? null) === $marker
            && ($audit['remote_address'] ?? null) === '192.0.2.46'
            && ($audit['before']['mode'] ?? null)
                === ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT
            && ($audit['before']['effective'] ?? null) === true
            && ($audit['after']['mode'] ?? null)
                === ESTAB_SELF_REGISTRATION_MODE_DISABLED
            && ($audit['after']['revision'] ?? null)
                === $environmentRevision + 1
            && ($audit['after']['effective'] ?? null) === false,
        'Self-registration audit omitted actor, revision or before/after state'
    );
    $auditCount = self_registration_test_scalar(
        $connection,
        "SELECT COUNT(*) FROM `nv_protokoll`"
        . " WHERE `p_lfd` > ? AND `p_was` = 'Selbstregistrierung'"
        . ' AND `p_ereignis` LIKE ?',
        'is',
        $auditFloor,
        '%' . $marker . '%'
    );
    $unchanged = estab_self_registration_update(
        $connection,
        $database,
        'nv_protokoll',
        ESTAB_SELF_REGISTRATION_MODE_DISABLED,
        null,
        (int) $disabledPolicy['revision'],
        $marker,
        '192.0.2.46'
    );
    self_registration_test_assert(
        ($unchanged['changed'] ?? null) === false
            && (int) ($unchanged['policy']['revision'] ?? -1)
                === (int) $disabledPolicy['revision']
            && self_registration_test_scalar(
                $connection,
                "SELECT COUNT(*) FROM `nv_protokoll`"
                . " WHERE `p_lfd` > ? AND `p_was` = 'Selbstregistrierung'"
                . ' AND `p_ereignis` LIKE ?',
                'is',
                $auditFloor,
                '%' . $marker . '%'
            ) === $auditCount,
        'No-op policy update created a revision or audit event'
    );

    $staleRejected = false;
    try {
        estab_self_registration_update(
            $connection,
            $database,
            'nv_protokoll',
            ESTAB_SELF_REGISTRATION_MODE_PERMANENT,
            null,
            (int) $disabledPolicy['revision'] - 1,
            $marker,
            '192.0.2.46'
        );
    } catch (EstabSelfRegistrationConflictException) {
        $staleRejected = true;
    }
    self_registration_test_assert(
        $staleRejected
            && self_registration_test_persisted(
                estab_self_registration_load($connection)
            ) === self_registration_test_persisted($disabledPolicy),
        'Stale self-registration revision overwrote persistent state'
    );
    self_registration_test_assert(
        !estab_self_registration_insert_user_if_allowed(
            $connection,
            'nv_benutzer',
            self_registration_test_user($codes['disabled'], 'deaktiviert')
        ),
        'Atomic account insert bypassed DISABLED mode'
    );

    $permanent = estab_self_registration_update(
        $connection,
        $database,
        'nv_protokoll',
        ESTAB_SELF_REGISTRATION_MODE_PERMANENT,
        null,
        (int) $disabledPolicy['revision'],
        $marker,
        '192.0.2.46'
    );
    $permanentPolicy = $permanent['policy'] ?? null;
    self_registration_test_assert(
        is_array($permanentPolicy)
            && ($permanentPolicy['mode'] ?? null)
                === ESTAB_SELF_REGISTRATION_MODE_PERMANENT
            && estab_self_registration_is_allowed($permanentPolicy)
            && estab_self_registration_insert_user_if_allowed(
                $connection,
                'nv_benutzer',
                self_registration_test_user($codes['permanent'], 'dauerhaft')
            ),
        'PERMANENT mode did not authorize the atomic account insert'
    );

    $beforeAuditFailure = self_registration_test_persisted($permanentPolicy);
    $auditFailureRejected = false;
    try {
        estab_self_registration_update(
            $connection,
            $database,
            'nv_missing_self_registration_audit',
            ESTAB_SELF_REGISTRATION_MODE_DISABLED,
            null,
            (int) $permanentPolicy['revision'],
            $marker,
            '192.0.2.46'
        );
    } catch (Throwable) {
        $auditFailureRejected = true;
    }
    self_registration_test_assert(
        $auditFailureRejected
            && self_registration_test_persisted(
                estab_self_registration_load($connection)
            ) === $beforeAuditFailure,
        'Audit failure left a partial self-registration update'
    );

    $heldLock = estab_self_registration_acquire_lock(
        $secondConnection,
        $database
    );
    $busyRejected = false;
    try {
        estab_self_registration_update(
            $connection,
            $database,
            'nv_protokoll',
            ESTAB_SELF_REGISTRATION_MODE_DISABLED,
            null,
            (int) $permanentPolicy['revision'],
            $marker,
            '192.0.2.46'
        );
    } catch (EstabSelfRegistrationBusyException) {
        $busyRejected = true;
    } finally {
        estab_self_registration_release_lock($secondConnection, $heldLock);
    }
    self_registration_test_assert(
        $busyRejected
            && self_registration_test_persisted(
                estab_self_registration_load($connection)
            ) === $beforeAuditFailure,
        'Concurrent self-registration writer bypassed the advisory lock'
    );

    $timed = estab_self_registration_update(
        $connection,
        $database,
        'nv_protokoll',
        ESTAB_SELF_REGISTRATION_MODE_UNTIL,
        15,
        (int) $permanentPolicy['revision'],
        $marker,
        '192.0.2.46'
    );
    $timedPolicy = $timed['policy'] ?? null;
    $timedStatus = is_array($timedPolicy)
        ? estab_self_registration_status($timedPolicy)
        : [];
    self_registration_test_assert(
        is_array($timedPolicy)
            && ($timedPolicy['mode'] ?? null)
                === ESTAB_SELF_REGISTRATION_MODE_UNTIL
            && ($timedStatus['effective'] ?? null) === true
            && (int) ($timedStatus['remaining_seconds'] ?? 0) >= 899
            && (int) ($timedStatus['remaining_seconds'] ?? 0) <= 900
            && estab_self_registration_insert_user_if_allowed(
                $connection,
                'nv_benutzer',
                self_registration_test_user($codes['future'], 'befristet')
            ),
        'Future UNTIL deadline was not stored/evaluated with database UTC'
    );

    self_registration_test_set_fixture_mode(
        $connection,
        ESTAB_SELF_REGISTRATION_MODE_UNTIL,
        'UTC_TIMESTAMP(6)',
        $marker
    );
    $boundaryPolicy = estab_self_registration_load($connection);
    self_registration_test_assert(
        !estab_self_registration_is_allowed($boundaryPolicy)
            && !estab_self_registration_insert_user_if_allowed(
                $connection,
                'nv_benutzer',
                self_registration_test_user($codes['boundary'], 'Grenze')
            )
            && self_registration_test_scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_benutzer` WHERE `kuerzel` = ?',
                's',
                $codes['boundary']
            ) === '0',
        'UNTIL mode remained active at its exact UTC boundary'
    );

    self_registration_test_set_fixture_mode(
        $connection,
        ESTAB_SELF_REGISTRATION_MODE_UNTIL,
        'TIMESTAMPADD(MICROSECOND, -1, UTC_TIMESTAMP(6))',
        $marker
    );
    $expiredPolicy = estab_self_registration_load($connection);
    self_registration_test_assert(
        !estab_self_registration_is_allowed($expiredPolicy)
            && (estab_self_registration_status($expiredPolicy)['state'] ?? null)
                === 'EXPIRED'
            && !estab_self_registration_insert_user_if_allowed(
                $connection,
                'nv_benutzer',
                self_registration_test_user($codes['expired'], 'abgelaufen')
            ),
        'Expired UNTIL policy authorized an atomic account insert'
    );
} finally {
    self_registration_test_restore_environment(
        'ESTAB_ALLOW_SELF_REGISTRATION',
        $environmentBefore
    );
    try {
        if (is_array($originalPolicy)) {
            self_registration_test_restore_policy($connection, $originalPolicy);
        }
        $codeValues = array_values($codes);
        $placeholders = implode(',', array_fill(0, count($codeValues), '?'));
        $statement = $connection->prepare(
            'DELETE FROM `nv_benutzer` WHERE `kuerzel` IN ('
            . $placeholders . ')'
        );
        if ($statement instanceof mysqli_stmt) {
            $types = str_repeat('s', count($codeValues));
            $statement->bind_param($types, ...$codeValues);
            $statement->execute();
            $statement->close();
        }
        $auditPattern = '%' . $marker . '%';
        $statement = $connection->prepare(
            'DELETE FROM `nv_protokoll` WHERE `p_lfd` > ?'
            . ' AND `p_ereignis` LIKE ?'
        );
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param('is', $auditFloor, $auditPattern);
            $statement->execute();
            $statement->close();
        }
    } finally {
        estab_auth_close($secondConnection);
        estab_auth_close($connection);
    }
}

printf(
    "Self-registration MariaDB: OK (%d assertions)\n",
    $assertions
);
