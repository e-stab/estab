<?php

declare(strict_types=1);

define('ESTAB_USER_ADMIN_ACCOUNT_LOCK_TIMEOUT', 0);
define('ESTAB_LOGIN_LOCK_TIMEOUT_SECONDS', 0);
define('ESTAB_ASSIGNMENT_POLICY_LOCK_TIMEOUT', 0);

require_once dirname(__DIR__, 2) . '/app/user_admin.php';

$assertions = 0;
function user_admin_test_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function user_admin_test_connect(array $config): mysqli
{
    return estab_auth_connect($config);
}

function user_admin_test_scalar(
    mysqli $connection,
    string $sql,
    string $types = '',
    mixed ...$parameters
): ?string {
    $statement = $connection->prepare($sql);
    user_admin_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare test scalar query'
    );
    try {
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        user_admin_test_assert(
            $statement->execute(),
            'Could not execute test scalar query'
        );
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        return is_array($row) ? (string) ($row[0] ?? '') : null;
    } finally {
        $statement->close();
    }
}

function user_admin_test_cleanup(
    array $config,
    string $code
): void {
    try {
        $connection = user_admin_test_connect($config);
        try {
            $statement = $connection->prepare(
                'DELETE FROM `nv_protokoll`'
                . ' WHERE `p_ereignis` LIKE ?'
            );
            if ($statement instanceof mysqli_stmt) {
                $pattern = '%"target":"' . $code . '"%';
                $statement->bind_param('s', $pattern);
                $statement->execute();
                $statement->close();
            }
            $statement = $connection->prepare(
                'DELETE FROM `nv_benutzer` WHERE `kuerzel` = ?'
            );
            if ($statement instanceof mysqli_stmt) {
                $statement->bind_param('s', $code);
                $statement->execute();
                $statement->close();
            }
            if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) === 1) {
                $dynamicBase = 'usr_s1_' . $code;
                $connection->query(
                    'DROP TABLE IF EXISTS'
                    . ' `' . $dynamicBase . '_read`,'
                    . ' `' . $dynamicBase . '_katego`,'
                    . ' `' . $dynamicBase . '_kategolink`'
                );
            }
        } finally {
            estab_auth_close($connection);
        }
    } catch (Throwable) {
        // Best-effort fixture cleanup must not hide the original assertion.
    }
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
user_admin_test_assert(
    preg_match('/\A[A-Za-z0-9_]+\z/D', $database) === 1,
    'Unsafe integration database name'
);

$code = 'ua' . substr(bin2hex(random_bytes(4)), 0, 4);
$name = 'Benutzerverwaltung Integration';
$function = 'A/W';
$role = 'Fernmelder';
$oldPassword = 'Altes sicheres Kennwort 2026!';
$newPassword = 'Neues sicheres Kennwort 2026!';
$oldHash = password_hash($oldPassword, PASSWORD_DEFAULT);
user_admin_test_assert(is_string($oldHash), 'Could not hash fixture password');
$createdCode = 'uc' . substr(bin2hex(random_bytes(4)), 0, 4);
$createdName = 'Administrativ angelegtes Konto';
$createdPassword = 'Sicheres Startkennwort 2026!';
$selfRegistrationCode = 'us' . substr(bin2hex(random_bytes(4)), 0, 4);
$selfRegistrationName = 'Kompatible Selbstregistrierung';
$selfRegistrationPassword = 'Selbstregistrierung sicher 2026!';

register_shutdown_function(
    static fn () => user_admin_test_cleanup($config, $code)
);
register_shutdown_function(
    static fn () => user_admin_test_cleanup($config, $createdCode)
);
register_shutdown_function(
    static fn () => user_admin_test_cleanup($config, $selfRegistrationCode)
);

$connection = user_admin_test_connect($config);
$secondConnection = user_admin_test_connect($config);
try {
    user_admin_test_assert(
        user_admin_test_scalar(
            $connection,
            "SELECT COUNT(*) FROM information_schema.columns"
            . " WHERE table_schema = DATABASE()"
            . " AND table_name = 'nv_benutzer'"
            . " AND column_name = 'estab_gesperrt'"
            . " AND data_type = 'tinyint'"
            . " AND is_nullable = 'NO'"
        ) === '1',
        'User-blocking migration is not applied'
    );

    $functionRoles = estab_user_admin_function_roles(
        $connection,
        'nv_empfmtx'
    );
    user_admin_test_assert(
        ($functionRoles['A/W'] ?? null) === 'Fernmelder'
            && ($functionRoles['S1'] ?? null) === 'Stab',
        'Authoritative function map is incomplete'
    );
    $created = estab_user_admin_create_account(
        $secondConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $createdName,
        $createdCode,
        'A/W',
        $createdPassword,
        $createdPassword,
        'nv_empfmtx',
        'integration-admin',
        '127.0.0.1'
    );
    user_admin_test_assert(
        $created === [
            'benutzer' => $createdName,
            'kuerzel' => $createdCode,
            'funktion' => 'A/W',
            'rolle' => 'Fernmelder',
            'active_session_revoked' => false,
        ],
        'Administrator did not create the expected inactive account'
    );
    user_admin_test_assert(
        user_admin_test_scalar(
            $connection,
            'SELECT CONCAT(`funktion`, ?, `rolle`, ?, `aktiv`, ?,'
            . ' `estab_gesperrt`, ?, `sid`, ?, `ip`, ?, `fwdip`)'
            . ' FROM `nv_benutzer` WHERE `kuerzel` = ?',
            'sssssss',
            '|',
            '|',
            '|',
            '|',
            '|',
            '|',
            $createdCode
        ) === 'A/W|Fernmelder|0|0|||',
        'Created account is active, blocked or carries session metadata'
    );
    $createdHash = user_admin_test_scalar(
        $connection,
        'SELECT `password` FROM `nv_benutzer` WHERE `kuerzel` = ?',
        's',
        $createdCode
    );
    user_admin_test_assert(
        is_string($createdHash)
            && !hash_equals($createdHash, $createdPassword)
            && password_verify($createdPassword, $createdHash),
        'Created account did not persist only a password hash'
    );
    $duplicateRejected = false;
    try {
        estab_user_admin_create_account(
            $secondConnection,
            $database,
            'nv_benutzer',
            'nv_protokoll',
            $createdName,
            $createdCode,
            'S1',
            $createdPassword,
            $createdPassword,
            'nv_empfmtx',
            'integration-admin',
            '127.0.0.1'
        );
    } catch (EstabUserAdminConflictException) {
        $duplicateRejected = true;
    }
    user_admin_test_assert(
        $duplicateRejected
            && user_admin_test_scalar(
                $connection,
                'SELECT CONCAT(`funktion`, ?, `rolle`, ?, `aktiv`)'
                . ' FROM `nv_benutzer` WHERE `kuerzel` = ?',
                'sss',
                '|',
                '|',
                $createdCode
            ) === 'A/W|Fernmelder|0',
        'Duplicate account creation changed the existing assignment'
    );

    // Imported legacy rows may have an empty assignment. The administration
    // must be able to repair and audit them without creating a session.
    $statement = $connection->prepare(
        "UPDATE `nv_benutzer` SET `funktion` = '', `rolle` = ''"
        . ' WHERE `kuerzel` = ?'
    );
    user_admin_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare empty legacy assignment fixture'
    );
    try {
        $statement->bind_param('s', $createdCode);
        user_admin_test_assert(
            $statement->execute() && $statement->affected_rows === 1,
            'Could not create empty legacy assignment fixture'
        );
    } finally {
        $statement->close();
    }
    $legacyRepair = estab_user_admin_reassign(
        $secondConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $createdCode,
        'A/W',
        'nv_empfmtx',
        'integration-admin',
        '127.0.0.1'
    );
    user_admin_test_assert(
        $legacyRepair === [
            'changed' => true,
            'active_session_revoked' => false,
            'funktion' => 'A/W',
            'rolle' => 'Fernmelder',
        ],
        'Empty legacy assignment could not be repaired'
    );
    $legacyRepairAgain = estab_user_admin_reassign(
        $secondConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $createdCode,
        'A/W',
        'nv_empfmtx',
        'integration-admin',
        '127.0.0.1'
    );
    user_admin_test_assert(
        $legacyRepairAgain === [
            'changed' => false,
            'active_session_revoked' => false,
            'funktion' => 'A/W',
            'rolle' => 'Fernmelder',
        ],
        'Repeated administrative reassignment was not idempotent'
    );

    $statement = $connection->prepare(
        'INSERT INTO `nv_benutzer`'
        . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `ip`,'
        . ' `fwdip`, `aktiv`, `estab_gesperrt`, `password`)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, ?)'
    );
    user_admin_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare account fixture'
    );
    $fixtureSid = 'uasid' . bin2hex(random_bytes(8));
    $fixtureIp = '192.0.2.21';
    $fixtureForwardedIp = '198.51.100.22';
    try {
        $statement->bind_param(
            'ssssssss',
            $name,
            $code,
            $function,
            $role,
            $fixtureSid,
            $fixtureIp,
            $fixtureForwardedIp,
            $oldHash
        );
        user_admin_test_assert(
            $statement->execute(),
            'Could not insert account fixture'
        );
    } finally {
        $statement->close();
    }

    // Hold the same lock used by login and prove the administrator cannot
    // race through it or partially change the account.
    $lockName = estab_user_admin_account_lock_name(
        $database,
        'nv_benutzer',
        $code
    );
    estab_user_admin_acquire_account_lock($connection, $lockName);
    $busyRejected = false;
    try {
        estab_user_admin_set_blocked(
            $secondConnection,
            $database,
            'nv_benutzer',
            'nv_protokoll',
            $code,
            true,
            'integration-admin',
            '127.0.0.1'
        );
    } catch (EstabUserAdminBusyException) {
        $busyRejected = true;
    } finally {
        estab_user_admin_release_account_lock($connection, $lockName);
    }
    user_admin_test_assert(
        $busyRejected,
        'Parallel account administration bypassed the login lock'
    );
    user_admin_test_assert(
        user_admin_test_scalar(
            $connection,
            'SELECT CONCAT(`estab_gesperrt`, ?, `aktiv`, ?, `sid`)'
            . ' FROM `nv_benutzer` WHERE `kuerzel` = ?',
            'sss',
            '|',
            '|',
            $code
        ) === '0|1|' . $fixtureSid,
        'Rejected lock race changed account state'
    );

    $block = estab_user_admin_set_blocked(
        $secondConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $code,
        true,
        'integration-admin',
        '127.0.0.1'
    );
    user_admin_test_assert(
        $block === [
            'changed' => true,
            'active_session_revoked' => true,
        ],
        'Blocking did not report the revoked active session'
    );
    user_admin_test_assert(
        user_admin_test_scalar(
            $connection,
            'SELECT CONCAT(`estab_gesperrt`, ?, `aktiv`, ?, `sid`, ?,'
            . ' `ip`, ?, `fwdip`) FROM `nv_benutzer` WHERE `kuerzel` = ?',
            'sssss',
            '|',
            '|',
            '|',
            '|',
            $code
        ) === '1|0|||',
        'Blocking did not clear authoritative session state'
    );
    user_admin_test_assert(
        user_admin_test_scalar(
            $connection,
            'SELECT `password` FROM `nv_benutzer` WHERE `kuerzel` = ?',
            's',
            $code
        ) === $oldHash,
        'Blocking changed the password hash'
    );
    $secondBlock = estab_user_admin_set_blocked(
        $secondConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $code,
        true,
        'integration-admin',
        '127.0.0.1'
    );
    user_admin_test_assert(
        $secondBlock === [
            'changed' => false,
            'active_session_revoked' => false,
        ],
        'Repeated blocking was not idempotent'
    );

    // Exercise the real legacy login controller. Correct credentials must not
    // reopen a blocked account.
    $originalDirectory = getcwd();
    user_admin_test_assert(
        is_string($originalDirectory)
            && chdir(dirname(__DIR__, 2) . '/4fach'),
        'Could not enter legacy controller directory'
    );
    if (!defined('debug')) {
        define('debug', false);
    }
    require_once dirname(__DIR__, 2) . '/4fach/db_operation.php';
    require_once dirname(__DIR__, 2) . '/4fach/data_hndl.php';
    $conf_empf = [
        1 => ['fkt' => 'A/W', 'rolle' => 'Fernmelder'],
        2 => ['fkt' => 'S1', 'rolle' => 'Stab'],
    ];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    user_admin_test_assert(
        session_status() === PHP_SESSION_ACTIVE || session_start(),
        'Could not start login integration session'
    );
    $_SESSION = ['menue' => 'LOGIN'];
    $loginRequest = [
        'login_flow' => 'existing',
        'benutzer' => $name,
        'kuerzel' => $code,
        'funktion' => $function,
        'kennwort1' => $oldPassword,
    ];
    $heldLoginPolicy = estab_assignment_acquire_policy_lock(
        $connection,
        $database,
        'nv_empfmtx'
    );
    try {
        $_SESSION = ['menue' => 'LOGIN'];
        $loginError = '';
        user_admin_test_assert(
            check_save_user($loginRequest, $loginError) === true
                && str_contains($loginError, 'technisch nicht abgeschlossen')
                && estab_auth_session_identity_shape($_SESSION) === null,
            'Login bypassed a concurrent matrix-policy writer'
        );
    } finally {
        estab_assignment_release_policy_lock(
            $connection,
            $heldLoginPolicy
        );
    }
    $loginError = '';
    user_admin_test_assert(
        check_save_user($loginRequest, $loginError) === true
            && str_contains($loginError, 'gesperrt')
            && estab_auth_session_identity_shape($_SESSION) === null,
        'Blocked account authenticated with correct credentials'
    );

    // Even a manually inconsistent legacy row must not resurrect a stale SID
    // when the durable block is removed.
    $statement = $connection->prepare(
        "UPDATE `nv_benutzer` SET `aktiv` = 1, `sid` = ?,"
        . " `ip` = '192.0.2.31', `fwdip` = '198.51.100.32'"
        . ' WHERE `kuerzel` = ? AND `estab_gesperrt` = 1'
    );
    user_admin_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare inconsistent blocked-session fixture'
    );
    $staleBlockedSid = 'uastale' . bin2hex(random_bytes(8));
    try {
        $statement->bind_param('ss', $staleBlockedSid, $code);
        user_admin_test_assert(
            $statement->execute() && $statement->affected_rows === 1,
            'Could not create inconsistent blocked-session fixture'
        );
    } finally {
        $statement->close();
    }

    $unblock = estab_user_admin_set_blocked(
        $secondConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $code,
        false,
        'integration-admin',
        '127.0.0.1'
    );
    user_admin_test_assert(
        $unblock === [
            'changed' => true,
            'active_session_revoked' => true,
        ],
        'Unblocking did not revoke inconsistent stale session state'
    );
    user_admin_test_assert(
        user_admin_test_scalar(
            $connection,
            'SELECT CONCAT(`estab_gesperrt`, ?, `aktiv`, ?, `sid`, ?,'
            . ' `ip`, ?, `fwdip`) FROM `nv_benutzer` WHERE `kuerzel` = ?',
            'sssss',
            '|',
            '|',
            '|',
            '|',
            $code
        ) === '0|0|||',
        'Unblocking resurrected stale authoritative session state'
    );

    $_SESSION = ['menue' => 'LOGIN'];
    $loginError = '';
    user_admin_test_assert(
        check_save_user($loginRequest, $loginError) === false,
        'Unblocked account could not authenticate: ' . $loginError
    );
    $activeSession = $_SESSION;
    $activeSessionId = session_id();
    user_admin_test_assert(
        estab_auth_session_id_is_valid($activeSessionId)
            && estab_auth_session_identity_shape($activeSession) !== null,
        'Successful post-unblock login did not establish a valid session'
    );
    $existingLoginAuditJson = user_admin_test_scalar(
        $connection,
        "SELECT `p_ereignis` FROM `nv_protokoll`"
        . " WHERE `p_was` = 'Anmelden'"
        . ' AND `p_ereignis` LIKE ?'
        . ' ORDER BY `p_lfd` DESC LIMIT 1',
        's',
        '%"target":"' . $code . '"%'
    );
    $existingLoginAudit = is_string($existingLoginAuditJson)
        ? json_decode(
            $existingLoginAuditJson,
            true,
            8,
            JSON_THROW_ON_ERROR
        )
        : null;
    user_admin_test_assert(
        is_array($existingLoginAudit)
            && ($existingLoginAudit['action'] ?? null) === 'existing_login'
            && ($existingLoginAudit['session_reference'] ?? null)
                === 'sha256:' . hash('sha256', $activeSessionId)
            && !str_contains($existingLoginAuditJson, $activeSessionId),
        'Real existing-account audit leaked its reusable session ID'
    );

    $reset = estab_user_admin_reset_password(
        $secondConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $code,
        $newPassword,
        'integration-admin',
        '127.0.0.1'
    );
    user_admin_test_assert(
        $reset === ['active_session_revoked' => true],
        'Password reset did not report the revoked session'
    );
    $storedHash = user_admin_test_scalar(
        $connection,
        'SELECT `password` FROM `nv_benutzer` WHERE `kuerzel` = ?',
        's',
        $code
    );
    user_admin_test_assert(
        is_string($storedHash)
            && !hash_equals($storedHash, $oldHash)
            && password_verify($newPassword, $storedHash)
            && !password_verify($oldPassword, $storedHash),
        'Password reset did not persist only a modern replacement hash'
    );
    $revokedSession = $activeSession;
    user_admin_test_assert(
        estab_auth_current_session_identity(
            $revokedSession,
            $config,
            'nv_benutzer',
            $activeSessionId
        ) === null
            && $revokedSession === ['menue' => 'LOGIN'],
        'Password reset did not immediately revoke the old application session'
    );

    $_SESSION = ['menue' => 'LOGIN'];
    $loginError = '';
    user_admin_test_assert(
        check_save_user($loginRequest, $loginError) === true,
        'Old password remained usable after reset'
    );
    $loginRequest['kennwort1'] = $newPassword;
    $_SESSION = ['menue' => 'LOGIN'];
    $loginError = '';
    user_admin_test_assert(
        check_save_user($loginRequest, $loginError) === false,
        'New password did not authenticate: ' . $loginError
    );

    // Logging out changes online state only. It must never make the stored
    // function request-selectable again.
    $postResetSessionId = session_id();
    user_admin_test_assert(
        estab_auth_session_id_is_valid($postResetSessionId)
            && estab_auth_mark_logged_out(
                $connection,
                'nv_benutzer',
                $code,
                $postResetSessionId
            ),
        'Could not create inactive-account assignment fixture'
    );
    $wrongFunctionRequest = $loginRequest;
    $wrongFunctionRequest['funktion'] = 'S1';
    $_SESSION = ['menue' => 'LOGIN'];
    $loginError = '';
    user_admin_test_assert(
        check_save_user($wrongFunctionRequest, $loginError) === true
            && str_contains(
                $loginError,
                'administrativ zugewiesene Funktion'
            )
            && estab_auth_session_identity_shape($_SESSION) === null
            && user_admin_test_scalar(
                $connection,
                'SELECT CONCAT(`funktion`, ?, `rolle`, ?, `aktiv`)'
                . ' FROM `nv_benutzer` WHERE `kuerzel` = ?',
                'sss',
                '|',
                '|',
                $code
            ) === 'A/W|Fernmelder|0',
        'Inactive account self-selected a different function or role'
    );

    $_SESSION = ['menue' => 'LOGIN'];
    $loginError = '';
    user_admin_test_assert(
        check_save_user($loginRequest, $loginError) === false,
        'Account could not return to its stored function: ' . $loginError
    );
    $preReassignSession = $_SESSION;
    $preReassignSessionId = session_id();
    $reassign = estab_user_admin_reassign(
        $secondConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $code,
        'S1',
        'nv_empfmtx',
        'integration-admin',
        '127.0.0.1'
    );
    user_admin_test_assert(
        $reassign === [
            'changed' => true,
            'active_session_revoked' => true,
            'funktion' => 'S1',
            'rolle' => 'Stab',
        ]
            && user_admin_test_scalar(
                $connection,
                'SELECT CONCAT(`funktion`, ?, `rolle`, ?, `aktiv`, ?,'
                . ' `sid`, ?, `ip`, ?, `fwdip`)'
                . ' FROM `nv_benutzer` WHERE `kuerzel` = ?',
                'ssssss',
                '|',
                '|',
                '|',
                '|',
                '|',
                $code
            ) === 'S1|Stab|0|||',
        'Administrative reassignment was not authoritative and session-revoking'
    );
    user_admin_test_assert(
        estab_auth_current_session_identity(
            $preReassignSession,
            $config,
            'nv_benutzer',
            $preReassignSessionId
        ) === null
            && $preReassignSession === ['menue' => 'LOGIN'],
        'Administrative reassignment did not revoke the old application session'
    );

    $_SESSION = ['menue' => 'LOGIN'];
    $loginError = '';
    user_admin_test_assert(
        check_save_user($loginRequest, $loginError) === true
            && str_contains(
                $loginError,
                'administrativ zugewiesene Funktion'
            ),
        'Old function remained usable after administrative reassignment'
    );
    $loginRequest['funktion'] = 'S1';
    $_SESSION = ['menue' => 'LOGIN'];
    $loginError = '';
    user_admin_test_assert(
        check_save_user($loginRequest, $loginError) === false
            && ($_SESSION['vStab_funktion'] ?? null) === 'S1'
            && ($_SESSION['vStab_rolle'] ?? null) === 'Stab',
        'New administrative function assignment could not authenticate: '
            . $loginError
    );

    // Exercise the explicit compatibility-only self-registration branch. It
    // must write the same credential-free JSON shape as an existing login.
    $selfRegistrationSetting = getenv('ESTAB_ALLOW_SELF_REGISTRATION');
    try {
        putenv('ESTAB_ALLOW_SELF_REGISTRATION=true');
        $_SESSION = ['menue' => 'LOGIN'];
        $loginError = '';
        $selfRegistrationRequest = [
            'login_flow' => 'new',
            'benutzer' => $selfRegistrationName,
            'kuerzel' => $selfRegistrationCode,
            'funktion' => 'A/W',
            'kennwort1' => $selfRegistrationPassword,
            'kennwort2' => $selfRegistrationPassword,
            '2teskennwort' => 'Yes',
        ];
        user_admin_test_assert(
            check_save_user($selfRegistrationRequest, $loginError) === false,
            'Explicit self-registration path failed: ' . $loginError
        );
    } finally {
        if ($selfRegistrationSetting === false) {
            putenv('ESTAB_ALLOW_SELF_REGISTRATION');
        } else {
            putenv(
                'ESTAB_ALLOW_SELF_REGISTRATION=' . $selfRegistrationSetting
            );
        }
    }
    $selfRegistrationSessionId = session_id();
    $selfRegistrationAuditJson = user_admin_test_scalar(
        $connection,
        "SELECT `p_ereignis` FROM `nv_protokoll`"
        . " WHERE `p_was` = 'Anmelden'"
        . ' AND `p_ereignis` LIKE ?'
        . ' ORDER BY `p_lfd` DESC LIMIT 1',
        's',
        '%"target":"' . $selfRegistrationCode . '"%'
    );
    $selfRegistrationAudit = is_string($selfRegistrationAuditJson)
        ? json_decode(
            $selfRegistrationAuditJson,
            true,
            8,
            JSON_THROW_ON_ERROR
        )
        : null;
    $selfRegistrationHash = user_admin_test_scalar(
        $connection,
        'SELECT `password` FROM `nv_benutzer` WHERE `kuerzel` = ?'
        . " AND `funktion` = 'A/W' AND `rolle` = 'Fernmelder'"
        . ' AND `aktiv` = 1 AND `sid` = ?',
        'ss',
        $selfRegistrationCode,
        $selfRegistrationSessionId
    );
    user_admin_test_assert(
        is_array($selfRegistrationAudit)
            && ($selfRegistrationAudit['action'] ?? null)
                === 'self_registration'
            && ($selfRegistrationAudit['target'] ?? null)
                === $selfRegistrationCode
            && ($selfRegistrationAudit['session_reference'] ?? null)
                === 'sha256:' . hash(
                    'sha256',
                    $selfRegistrationSessionId
                )
            && !str_contains(
                $selfRegistrationAuditJson,
                $selfRegistrationSessionId
            )
            && !str_contains(
                $selfRegistrationAuditJson,
                $selfRegistrationPassword
            )
            && is_string($selfRegistrationHash)
            && password_verify(
                $selfRegistrationPassword,
                $selfRegistrationHash
            ),
        'Real self-registration audit leaked SID/password or account did not commit'
    );
    user_admin_test_assert(
        estab_auth_mark_logged_out(
            $connection,
            'nv_benutzer',
            $selfRegistrationCode,
            $selfRegistrationSessionId
        ),
        'Could not deactivate the self-registration audit fixture'
    );

    // An audit failure must roll the state change back.
    $auditFailureRejected = false;
    try {
        estab_user_admin_set_blocked(
            $secondConnection,
            $database,
            'nv_benutzer',
            'nv_missing_user_admin_audit',
            $code,
            true,
            'integration-admin',
            '127.0.0.1'
        );
    } catch (Throwable) {
        $auditFailureRejected = true;
    }
    user_admin_test_assert(
        $auditFailureRejected
            && user_admin_test_scalar(
                $connection,
                'SELECT CONCAT(`estab_gesperrt`, ?, `aktiv`)'
                . ' FROM `nv_benutzer` WHERE `kuerzel` = ?',
                'ss',
                '|',
                $code
            ) === '0|1',
        'Audit failure left a partial account block'
    );

    $auditCountBeforeUnknown = user_admin_test_scalar(
        $connection,
        "SELECT COUNT(*) FROM `nv_protokoll`"
        . " WHERE `p_was` = 'Benutzerverwaltung'"
        . ' AND `p_ereignis` LIKE ?',
        's',
        '%"target":"' . $code . '"%'
    );
    do {
        $unknownCode = 'uz' . substr(bin2hex(random_bytes(4)), 0, 4);
    } while (
        $unknownCode === $code
        || user_admin_test_scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_benutzer` WHERE `kuerzel` = ?',
            's',
            $unknownCode
        ) !== '0'
    );
    $unknownRejected = false;
    try {
        estab_user_admin_set_blocked(
            $secondConnection,
            $database,
            'nv_benutzer',
            'nv_protokoll',
            $unknownCode,
            true,
            'integration-admin',
            '127.0.0.1'
        );
    } catch (EstabUserAdminNotFoundException) {
        $unknownRejected = true;
    }
    user_admin_test_assert(
        $unknownRejected
            && user_admin_test_scalar(
                $connection,
                "SELECT COUNT(*) FROM `nv_protokoll`"
                . " WHERE `p_was` = 'Benutzerverwaltung'"
                . ' AND `p_ereignis` LIKE ?',
                's',
                '%"target":"' . $code . '"%'
            ) === $auditCountBeforeUnknown,
        'Unknown account changed state or wrote an audit record'
    );

    $statement = $connection->prepare(
        "SELECT `p_ereignis` FROM `nv_protokoll`"
        . " WHERE `p_was` = 'Benutzerverwaltung'"
        . ' AND `p_ereignis` LIKE ? ORDER BY `p_lfd`'
    );
    user_admin_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare user-administration audit lookup'
    );
    try {
        $pattern = '%"target":"' . $code . '"%';
        $statement->bind_param('s', $pattern);
        user_admin_test_assert(
            $statement->execute(),
            'Could not read user-administration audit'
        );
        $result = $statement->get_result();
        $auditRows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } finally {
        $statement->close();
    }
    $actions = [];
    foreach ($auditRows as $auditRow) {
        $details = (string) ($auditRow['p_ereignis'] ?? '');
        $decoded = json_decode($details, true, 8, JSON_THROW_ON_ERROR);
        $actions[] = $decoded['action'] ?? null;
        user_admin_test_assert(
            !str_contains($details, $oldPassword)
                && !str_contains($details, $newPassword)
                && !str_contains($details, $oldHash)
                && !str_contains($details, $activeSessionId)
                && !str_contains($details, $preReassignSessionId),
            'Administrative audit leaked credential material'
        );
    }
    user_admin_test_assert(
        $actions === ['block', 'unblock', 'reset_password', 'reassign'],
        'Administrative actions were not audited exactly once and in order'
    );

    $createdAuditRows = user_admin_test_scalar(
        $connection,
        "SELECT GROUP_CONCAT("
        . "JSON_UNQUOTE(JSON_EXTRACT(`p_ereignis`, '$.action'))"
        . " ORDER BY `p_lfd` SEPARATOR ',') FROM `nv_protokoll`"
        . " WHERE `p_was` = 'Benutzerverwaltung'"
        . ' AND `p_ereignis` LIKE ?',
        's',
        '%"target":"' . $createdCode . '"%'
    );
    user_admin_test_assert(
        $createdAuditRows === 'create,reassign',
        'Account creation and legacy repair were not audited exactly once'
    );

    user_admin_test_assert(
        chdir($originalDirectory),
        'Could not restore integration working directory'
    );
} finally {
    estab_auth_close($secondConnection);
    estab_auth_close($connection);
    user_admin_test_cleanup($config, $code);
    user_admin_test_cleanup($config, $createdCode);
    user_admin_test_cleanup($config, $selfRegistrationCode);
}

printf(
    "User administration integration: OK (%d assertions)\n",
    $assertions
);
