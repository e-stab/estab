<?php

declare(strict_types=1);

define('ESTAB_PASSWORD_POLICY_LOCK_TIMEOUT', 0);
define('ESTAB_USER_ADMIN_ACCOUNT_LOCK_TIMEOUT', 0);
define('ESTAB_LOGIN_LOCK_TIMEOUT_SECONDS', 0);
define('ESTAB_ASSIGNMENT_POLICY_LOCK_TIMEOUT', 0);

require_once dirname(__DIR__, 2) . '/app/user_admin.php';

$assertions = 0;
function password_policy_test_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function password_policy_test_scalar(
    mysqli $connection,
    string $sql,
    string $types = '',
    mixed ...$parameters
): ?string {
    $statement = $connection->prepare($sql);
    password_policy_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare password-policy scalar query'
    );
    try {
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        password_policy_test_assert(
            $statement->execute(),
            'Could not execute password-policy scalar query'
        );
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        return is_array($row) ? (string) ($row[0] ?? '') : null;
    } finally {
        $statement->close();
    }
}

/** @return list<string> */
function password_policy_test_column(
    mysqli $connection,
    string $sql,
    string $types = '',
    mixed ...$parameters
): array {
    $statement = $connection->prepare($sql);
    password_policy_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare password-policy column query'
    );
    try {
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        password_policy_test_assert(
            $statement->execute(),
            'Could not execute password-policy column query'
        );
        $result = $statement->get_result();
        $values = [];
        while (is_array($row = $result->fetch_row())) {
            $values[] = (string) ($row[0] ?? '');
        }
        $result->free();
        return $values;
    } finally {
        $statement->close();
    }
}

/** Restore all nine singleton fields without creating a cleanup audit entry. */
function password_policy_test_restore_policy(
    mysqli $connection,
    array $policy
): void {
    $minimumLength = (int) $policy['minimum_length'];
    $requireUppercase = !empty($policy['require_uppercase']) ? 1 : 0;
    $requireLowercase = !empty($policy['require_lowercase']) ? 1 : 0;
    $requireDigit = !empty($policy['require_digit']) ? 1 : 0;
    $requireSymbol = !empty($policy['require_symbol']) ? 1 : 0;
    $revision = (int) $policy['revision'];
    $updatedAt = (string) $policy['updated_at'];
    $updatedBy = (string) $policy['updated_by'];
    $statement = $connection->prepare(
        'UPDATE `nv_kennwortrichtlinie` SET `minimum_length` = ?,'
        . ' `require_uppercase` = ?, `require_lowercase` = ?,'
        . ' `require_digit` = ?, `require_symbol` = ?, `revision` = ?,'
        . ' `updated_at` = ?, `updated_by` = ? WHERE `singleton_id` = 1'
    );
    if (!$statement instanceof mysqli_stmt) {
        throw new RuntimeException('Could not prepare password-policy restore');
    }
    try {
        $statement->bind_param(
            'iiiiiiss',
            $minimumLength,
            $requireUppercase,
            $requireLowercase,
            $requireDigit,
            $requireSymbol,
            $revision,
            $updatedAt,
            $updatedBy
        );
        if (!$statement->execute()) {
            throw new RuntimeException('Could not restore password policy');
        }
    } finally {
        $statement->close();
    }
}

function password_policy_test_restore_environment(
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
password_policy_test_assert(
    preg_match('/\A[A-Za-z0-9_]+\z/D', $database) === 1,
    'Unsafe password-policy integration database name'
);

$token = substr(bin2hex(random_bytes(4)), 0, 4);
$marker = 'password-policy-it-' . bin2hex(random_bytes(5));
$createCode = 'pc' . $token;
$legacyCode = 'pl' . $token;
$registrationCode = 'ps' . $token;
$createName = 'Kennwortrichtlinie Konto ' . $token;
$legacyName = 'Bestandskonto Richtlinie ' . $token;
$registrationName = 'Selbstregistrierung Richtlinie ' . $token;
$weakPassword = 'kurz';
$longPasswordPrefix = 'GültigÄ١!' . str_repeat('x', 80);
$createPassword = $longPasswordPrefix . 'create';
$createPasswordCollision = $longPasswordPrefix . 'collision';
$resetPassword = $longPasswordPrefix . 'reset';
$registrationPassword = $longPasswordPrefix . 'registration';
$selfRegistrationSetting = getenv('ESTAB_ALLOW_SELF_REGISTRATION');
$originalDirectory = getcwd();
$controllerDirectoryChanged = false;
$connection = estab_auth_connect($config);
$secondConnection = estab_auth_connect($config);
$originalPolicy = null;
$auditFloor = 0;

try {
    password_policy_test_assert(
        password_policy_test_scalar(
            $connection,
            "SELECT COUNT(*) FROM information_schema.tables"
            . " WHERE table_schema = DATABASE()"
            . " AND table_name = 'nv_kennwortrichtlinie'"
            . " AND table_type = 'BASE TABLE' AND engine = 'InnoDB'"
        ) === '1',
        'Password-policy migration is not applied'
    );
    $originalPolicy = estab_password_policy_load($connection);
    $auditFloor = (int) (
        password_policy_test_scalar(
            $connection,
            'SELECT COALESCE(MAX(`p_lfd`), 0) FROM `nv_protokoll`'
        ) ?? 0
    );

    $strictMinimum = (int) $originalPolicy['minimum_length'] === 14 ? 15 : 14;
    $strictConfiguration = [
        'minimum_length' => $strictMinimum,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_digit' => true,
        'require_symbol' => true,
    ];
    $update = estab_password_policy_update(
        $connection,
        $database,
        'nv_protokoll',
        $strictConfiguration,
        (int) $originalPolicy['revision'],
        $marker,
        '192.0.2.40'
    );
    $activePolicy = $update['policy'] ?? null;
    password_policy_test_assert(
        ($update['changed'] ?? null) === true
            && is_array($activePolicy)
            && estab_password_policy_configuration($activePolicy)
                === $strictConfiguration
            && (int) $activePolicy['revision']
                === (int) $originalPolicy['revision'] + 1
            && ($activePolicy['updated_by'] ?? null) === $marker,
        'Policy update did not atomically persist configuration and revision'
    );

    $policyAuditJson = password_policy_test_scalar(
        $connection,
        "SELECT `p_ereignis` FROM `nv_protokoll`"
        . " WHERE `p_lfd` > ? AND `p_was` = 'Kennwortrichtlinie'"
        . ' ORDER BY `p_lfd` DESC LIMIT 1',
        'i',
        $auditFloor
    );
    $policyAudit = is_string($policyAuditJson)
        ? json_decode($policyAuditJson, true, 8, JSON_THROW_ON_ERROR)
        : null;
    password_policy_test_assert(
        is_array($policyAudit)
            && ($policyAudit['action'] ?? null) === 'password_policy_updated'
            && ($policyAudit['admin'] ?? null) === $marker
            && ($policyAudit['remote_address'] ?? null) === '192.0.2.40'
            && ($policyAudit['before_revision'] ?? null)
                === (int) $originalPolicy['revision']
            && ($policyAudit['after_revision'] ?? null)
                === (int) $activePolicy['revision']
            && ($policyAudit['before']['minimum_length'] ?? null)
                === (int) $originalPolicy['minimum_length']
            && ($policyAudit['after'] ?? null) === $strictConfiguration,
        'Policy audit omitted actor, revision or before/after configuration'
    );

    $policyAuditCount = password_policy_test_scalar(
        $connection,
        "SELECT COUNT(*) FROM `nv_protokoll`"
        . " WHERE `p_lfd` > ? AND `p_was` = 'Kennwortrichtlinie'",
        'i',
        $auditFloor
    );
    $unchanged = estab_password_policy_update(
        $connection,
        $database,
        'nv_protokoll',
        $strictConfiguration,
        (int) $activePolicy['revision'],
        $marker,
        '192.0.2.40'
    );
    password_policy_test_assert(
        ($unchanged['changed'] ?? null) === false
            && (int) ($unchanged['policy']['revision'] ?? -1)
                === (int) $activePolicy['revision']
            && password_policy_test_scalar(
                $connection,
                "SELECT COUNT(*) FROM `nv_protokoll`"
                . " WHERE `p_lfd` > ? AND `p_was` = 'Kennwortrichtlinie'",
                'i',
                $auditFloor
            ) === $policyAuditCount,
        'Unchanged policy created a revision or misleading audit event'
    );

    $staleRejected = false;
    try {
        estab_password_policy_update(
            $connection,
            $database,
            'nv_protokoll',
            array_replace($strictConfiguration, [
                'require_symbol' => false,
            ]),
            (int) $activePolicy['revision'] - 1,
            $marker,
            '192.0.2.40'
        );
    } catch (EstabPasswordPolicyConflictException) {
        $staleRejected = true;
    }
    password_policy_test_assert(
        $staleRejected
            && estab_password_policy_load($connection) === $activePolicy,
        'Stale policy revision overwrote or changed authoritative state'
    );

    $auditFailureRejected = false;
    try {
        estab_password_policy_update(
            $connection,
            $database,
            'nv_missing_password_policy_audit',
            array_replace($strictConfiguration, [
                'minimum_length' => $strictMinimum === 14 ? 15 : 14,
            ]),
            (int) $activePolicy['revision'],
            $marker,
            '192.0.2.40'
        );
    } catch (Throwable) {
        $auditFailureRejected = true;
    }
    password_policy_test_assert(
        $auditFailureRejected
            && estab_password_policy_load($connection) === $activePolicy,
        'Policy audit failure left a partial configuration or revision change'
    );

    $heldPolicyLock = estab_password_policy_acquire_lock(
        $secondConnection,
        $database
    );
    $busyRejected = false;
    try {
        estab_password_policy_update(
            $connection,
            $database,
            'nv_protokoll',
            array_replace($strictConfiguration, [
                'require_symbol' => false,
            ]),
            (int) $activePolicy['revision'],
            $marker,
            '192.0.2.40'
        );
    } catch (EstabPasswordPolicyBusyException) {
        $busyRejected = true;
    } finally {
        estab_password_policy_release_lock($secondConnection, $heldPolicyLock);
    }
    password_policy_test_assert(
        $busyRejected
            && estab_password_policy_load($connection) === $activePolicy,
        'Concurrent policy writer bypassed the advisory lock'
    );

    $weakCreateRejected = false;
    try {
        estab_user_admin_create_account(
            $connection,
            $database,
            'nv_benutzer',
            'nv_protokoll',
            $createName,
            $createCode,
            'A/W',
            $weakPassword,
            $weakPassword,
            'nv_empfmtx',
            $marker,
            '192.0.2.40'
        );
    } catch (EstabPasswordPolicyInputException) {
        $weakCreateRejected = true;
    }
    password_policy_test_assert(
        $weakCreateRejected
            && password_policy_test_scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_benutzer` WHERE `kuerzel` = ?',
                's',
                $createCode
            ) === '0',
        'Weak administrative account creation persisted an account'
    );
    $probeLock = estab_password_policy_acquire_lock(
        $secondConnection,
        $database
    );
    estab_password_policy_release_lock($secondConnection, $probeLock);
    password_policy_test_assert(true, 'Rejected account creation leaked policy lock');

    $createAuditCountBeforeBusy = password_policy_test_scalar(
        $connection,
        "SELECT COUNT(*) FROM `nv_protokoll`"
        . " WHERE `p_was` = 'Benutzerverwaltung'"
        . ' AND `p_ereignis` LIKE ?',
        's',
        '%"target":"' . $createCode . '"%'
    );
    $heldCreatePolicyLock = estab_password_policy_acquire_lock(
        $secondConnection,
        $database
    );
    $busyCreateRejected = false;
    try {
        estab_user_admin_create_account(
            $connection,
            $database,
            'nv_benutzer',
            'nv_protokoll',
            $createName,
            $createCode,
            'A/W',
            $createPassword,
            $createPassword,
            'nv_empfmtx',
            $marker,
            '192.0.2.40'
        );
    } catch (EstabPasswordPolicyBusyException) {
        $busyCreateRejected = true;
    } finally {
        estab_password_policy_release_lock(
            $secondConnection,
            $heldCreatePolicyLock
        );
    }
    password_policy_test_assert(
        $busyCreateRejected
            && password_policy_test_scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_benutzer` WHERE `kuerzel` = ?',
                's',
                $createCode
            ) === '0'
            && password_policy_test_scalar(
                $connection,
                "SELECT COUNT(*) FROM `nv_protokoll`"
                . " WHERE `p_was` = 'Benutzerverwaltung'"
                . ' AND `p_ereignis` LIKE ?',
                's',
                '%"target":"' . $createCode . '"%'
            ) === $createAuditCountBeforeBusy,
        'Policy-lock contention created or audited a partial account'
    );

    $created = estab_user_admin_create_account(
        $connection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $createName,
        $createCode,
        'A/W',
        $createPassword,
        $createPassword,
        'nv_empfmtx',
        $marker,
        '192.0.2.40'
    );
    $createdHash = password_policy_test_scalar(
        $connection,
        'SELECT `password` FROM `nv_benutzer` WHERE `kuerzel` = ?',
        's',
        $createCode
    );
    password_policy_test_assert(
        ($created['kuerzel'] ?? null) === $createCode
            && is_string($createdHash)
            && !hash_equals($createPassword, $createdHash)
            && password_verify($createPassword, $createdHash)
            && !password_verify($createPasswordCollision, $createdHash)
            && (password_get_info($createdHash)['algoName'] ?? '')
                === 'argon2id',
        'Valid policy-compliant account creation did not store only a hash'
    );

    $weakResetRejected = false;
    try {
        estab_user_admin_reset_password(
            $connection,
            $database,
            'nv_benutzer',
            'nv_protokoll',
            $createCode,
            $weakPassword,
            $marker,
            '192.0.2.40'
        );
    } catch (EstabPasswordPolicyInputException) {
        $weakResetRejected = true;
    }
    password_policy_test_assert(
        $weakResetRejected
            && password_policy_test_scalar(
                $connection,
                'SELECT `password` FROM `nv_benutzer` WHERE `kuerzel` = ?',
                's',
                $createCode
            ) === $createdHash,
        'Weak password reset changed the stored credential'
    );

    $resetAuditCountBeforeBusy = password_policy_test_scalar(
        $connection,
        "SELECT COUNT(*) FROM `nv_protokoll`"
        . " WHERE `p_was` = 'Benutzerverwaltung'"
        . ' AND `p_ereignis` LIKE ?',
        's',
        '%"target":"' . $createCode . '"%'
    );
    $heldResetPolicyLock = estab_password_policy_acquire_lock(
        $secondConnection,
        $database
    );
    $busyResetRejected = false;
    try {
        estab_user_admin_reset_password(
            $connection,
            $database,
            'nv_benutzer',
            'nv_protokoll',
            $createCode,
            $resetPassword,
            $marker,
            '192.0.2.40'
        );
    } catch (EstabPasswordPolicyBusyException) {
        $busyResetRejected = true;
    } finally {
        estab_password_policy_release_lock(
            $secondConnection,
            $heldResetPolicyLock
        );
    }
    password_policy_test_assert(
        $busyResetRejected
            && password_policy_test_scalar(
                $connection,
                'SELECT `password` FROM `nv_benutzer` WHERE `kuerzel` = ?',
                's',
                $createCode
            ) === $createdHash
            && password_policy_test_scalar(
                $connection,
                "SELECT COUNT(*) FROM `nv_protokoll`"
                . " WHERE `p_was` = 'Benutzerverwaltung'"
                . ' AND `p_ereignis` LIKE ?',
                's',
                '%"target":"' . $createCode . '"%'
            ) === $resetAuditCountBeforeBusy,
        'Policy-lock contention changed or audited the password reset'
    );

    $reset = estab_user_admin_reset_password(
        $connection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $createCode,
        $resetPassword,
        $marker,
        '192.0.2.40'
    );
    $resetHash = password_policy_test_scalar(
        $connection,
        'SELECT `password` FROM `nv_benutzer` WHERE `kuerzel` = ?',
        's',
        $createCode
    );
    password_policy_test_assert(
        ($reset['active_session_revoked'] ?? null) === false
            && is_string($resetHash)
            && password_verify($resetPassword, $resetHash)
            && !password_verify($createPassword, $resetHash)
            && !password_verify($createPasswordCollision, $resetHash)
            && (password_get_info($resetHash)['algoName'] ?? '')
                === 'argon2id',
        'Valid password reset did not replace the credential atomically'
    );

    $legacyHash = password_hash($weakPassword, PASSWORD_BCRYPT);
    password_policy_test_assert(
        is_string($legacyHash),
        'Could not hash existing-account fixture password'
    );
    $statement = $connection->prepare(
        'INSERT INTO `nv_benutzer`'
        . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `ip`,'
        . ' `fwdip`, `aktiv`, `estab_gesperrt`, `password`)'
        . " VALUES (?, ?, 'A/W', 'Fernmelder', '', '', '', 0, 0, ?)"
    );
    password_policy_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare existing-account fixture'
    );
    try {
        $statement->bind_param('sss', $legacyName, $legacyCode, $legacyHash);
        password_policy_test_assert(
            $statement->execute(),
            'Could not insert existing-account fixture'
        );
    } finally {
        $statement->close();
    }

    password_policy_test_assert(
        is_string($originalDirectory)
            && chdir(dirname(__DIR__, 2) . '/4fach'),
        'Could not enter legacy login-controller directory'
    );
    $controllerDirectoryChanged = true;
    if (!defined('debug')) {
        define('debug', false);
    }
    require_once dirname(__DIR__, 2) . '/4fach/db_operation.php';
    require_once dirname(__DIR__, 2) . '/4fach/data_hndl.php';
    $_SERVER['REMOTE_ADDR'] = '192.0.2.40';
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    password_policy_test_assert(
        session_status() === PHP_SESSION_ACTIVE || session_start(),
        'Could not start password-policy login session'
    );

    $_SESSION = ['menue' => 'LOGIN'];
    $loginError = '';
    $legacyRequest = [
        'login_flow' => 'existing',
        'benutzer' => $legacyName,
        'kuerzel' => $legacyCode,
        'funktion' => 'A/W',
        'kennwort1' => $weakPassword,
    ];
    password_policy_test_assert(
        check_save_user($legacyRequest, $loginError) === false
            && ($_SESSION['vStab_kuerzel'] ?? null) === $legacyCode
            && ($_SESSION['vStab_funktion'] ?? null) === 'A/W',
        'Tightened policy blocked a valid existing credential: ' . $loginError
    );
    $legacySessionId = session_id();
    password_policy_test_assert(
        estab_auth_mark_logged_out(
            $connection,
            'nv_benutzer',
            $legacyCode,
            $legacySessionId
        ),
        'Could not deactivate existing-login fixture'
    );

    putenv('ESTAB_ALLOW_SELF_REGISTRATION=true');
    $_SESSION = ['menue' => 'LOGIN'];
    $loginError = '';
    $weakRegistration = [
        'login_flow' => 'new',
        'benutzer' => $registrationName,
        'kuerzel' => $registrationCode,
        'funktion' => 'A/W',
        'kennwort1' => $weakPassword,
        'kennwort2' => $weakPassword,
        '2teskennwort' => 'Yes',
    ];
    password_policy_test_assert(
        check_save_user($weakRegistration, $loginError) === true
            && str_contains($loginError, 'Richtlinie')
            && password_policy_test_scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_benutzer` WHERE `kuerzel` = ?',
                's',
                $registrationCode
            ) === '0',
        'Weak self-registration was accepted or returned no policy guidance'
    );
    $probeLock = estab_password_policy_acquire_lock(
        $secondConnection,
        $database
    );
    estab_password_policy_release_lock($secondConnection, $probeLock);
    password_policy_test_assert(true, 'Rejected self-registration leaked policy lock');

    $validRegistration = array_replace($weakRegistration, [
        'kennwort1' => $registrationPassword,
        'kennwort2' => $registrationPassword,
    ]);
    $_SESSION = ['menue' => 'LOGIN'];
    $loginError = '';
    password_policy_test_assert(
        check_save_user($validRegistration, $loginError) === false
            && ($_SESSION['vStab_kuerzel'] ?? null) === $registrationCode,
        'Policy-compliant self-registration failed: ' . $loginError
    );
    $registrationHash = password_policy_test_scalar(
        $connection,
        'SELECT `password` FROM `nv_benutzer` WHERE `kuerzel` = ?',
        's',
        $registrationCode
    );
    password_policy_test_assert(
        is_string($registrationHash)
            && password_verify($registrationPassword, $registrationHash)
            && !password_verify($createPasswordCollision, $registrationHash)
            && (password_get_info($registrationHash)['algoName'] ?? '')
                === 'argon2id',
        'Self-registration did not persist the policy-compliant hash'
    );

    $fixtureAuditPattern = '%' . $marker . '%';
    $createCodePattern = '%"target":"' . $createCode . '"%';
    $legacyCodePattern = '%"target":"' . $legacyCode . '"%';
    $registrationCodePattern = '%"target":"' . $registrationCode . '"%';
    $fixtureAudits = implode(
        "\n",
        password_policy_test_column(
            $connection,
            'SELECT `p_ereignis` FROM `nv_protokoll` WHERE `p_lfd` > ?'
            . ' AND (`p_ereignis` LIKE ? OR `p_ereignis` LIKE ?'
            . ' OR `p_ereignis` LIKE ? OR `p_ereignis` LIKE ?)'
            . ' ORDER BY `p_lfd`',
            'issss',
            $auditFloor,
            $fixtureAuditPattern,
            $createCodePattern,
            $legacyCodePattern,
            $registrationCodePattern
        )
    );
    password_policy_test_assert(
        $fixtureAudits !== ''
            && !str_contains($fixtureAudits, $weakPassword)
            && !str_contains($fixtureAudits, $createPassword)
            && !str_contains($fixtureAudits, $createPasswordCollision)
            && !str_contains($fixtureAudits, $resetPassword)
            && !str_contains($fixtureAudits, $registrationPassword)
            && !str_contains($fixtureAudits, (string) $createdHash)
            && !str_contains($fixtureAudits, (string) $resetHash)
            && !str_contains($fixtureAudits, (string) $registrationHash),
        'Policy, account or login audit leaked cleartext/hash material'
    );
} finally {
    password_policy_test_restore_environment(
        'ESTAB_ALLOW_SELF_REGISTRATION',
        $selfRegistrationSetting
    );
    if ($controllerDirectoryChanged && is_string($originalDirectory)) {
        chdir($originalDirectory);
    }
    try {
        if (is_array($originalPolicy)) {
            password_policy_test_restore_policy($connection, $originalPolicy);
        }
        $statement = $connection->prepare(
            'DELETE FROM `nv_benutzer` WHERE `kuerzel` IN (?, ?, ?)'
        );
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param(
                'sss',
                $createCode,
                $legacyCode,
                $registrationCode
            );
            $statement->execute();
            $statement->close();
        }
        $fixtureAuditPattern = '%' . $marker . '%';
        $createCodePattern = '%"target":"' . $createCode . '"%';
        $legacyCodePattern = '%"target":"' . $legacyCode . '"%';
        $registrationCodePattern = '%"target":"'
            . $registrationCode . '"%';
        $statement = $connection->prepare(
            'DELETE FROM `nv_protokoll` WHERE `p_lfd` > ?'
            . ' AND (`p_ereignis` LIKE ? OR `p_ereignis` LIKE ?'
            . ' OR `p_ereignis` LIKE ? OR `p_ereignis` LIKE ?)'
        );
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param(
                'issss',
                $auditFloor,
                $fixtureAuditPattern,
                $createCodePattern,
                $legacyCodePattern,
                $registrationCodePattern
            );
            $statement->execute();
            $statement->close();
        }
    } finally {
        estab_auth_close($secondConnection);
        estab_auth_close($connection);
    }
}

printf(
    "Password policy MariaDB: OK (%d assertions)\n",
    $assertions
);
