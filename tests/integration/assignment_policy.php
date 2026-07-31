<?php

declare(strict_types=1);

define('ESTAB_ASSIGNMENT_POLICY_LOCK_TIMEOUT', 0);
define('ESTAB_USER_ADMIN_ACCOUNT_LOCK_TIMEOUT', 0);

require_once dirname(__DIR__, 2) . '/app/admin_operations.php';
require_once dirname(__DIR__, 2) . '/app/readiness.php';
require_once dirname(__DIR__, 2) . '/app/user_admin.php';

$assertions = 0;
function assignment_test_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assignment_test_scalar(
    mysqli $connection,
    string $sql,
    string $types = '',
    mixed ...$parameters
): ?string {
    $statement = $connection->prepare($sql);
    assignment_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare assignment-policy scalar'
    );
    try {
        if ($types !== '') {
            $statement->bind_param($types, ...$parameters);
        }
        assignment_test_assert(
            $statement->execute(),
            'Could not execute assignment-policy scalar'
        );
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        return is_array($row) ? (string) ($row[0] ?? '') : null;
    } finally {
        $statement->close();
    }
}

function assignment_test_readiness(mysqli $connection): string
{
    $result = $connection->query(estab_readiness_schema_query());
    assignment_test_assert(
        $result instanceof mysqli_result,
        'Could not execute canonical readiness query'
    );
    try {
        $row = $result->fetch_row();
        return is_array($row) ? (string) ($row[0] ?? '') : '';
    } finally {
        $result->free();
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
assignment_test_assert(
    preg_match('/\A[A-Za-z0-9_]+\z/D', $database) === 1,
    'Unsafe assignment-policy integration database'
);

$connection = estab_auth_connect($config);
$secondConnection = estab_auth_connect($config);
$thirdConnection = estab_auth_connect($config);
$originalMatrix = null;
$matrixChanged = false;
$code = 'q' . substr(bin2hex(random_bytes(4)), 0, 5);
$busyCreateCode = 'r' . substr(bin2hex(random_bytes(4)), 0, 5);
$function = 'Q' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
$name = 'Matrix Policy Integration';
$password = 'Matrix-Policy Kennwort 2026!';
$orphanResetPassword = 'Waisenkonto neues Kennwort 2026!';
$auditFloor = (int) (
    assignment_test_scalar(
        $connection,
        'SELECT COALESCE(MAX(`p_lfd`), 0) FROM `nv_protokoll`'
    ) ?? 0
);
$rollbackTrigger = 'estab_assignment_fail_' . substr(
    hash('sha256', $code),
    0,
    16
);

try {
    $originalMatrix = estab_admin_fetch_matrix($connection, 'nv_empfmtx');
    $blankPosition = null;
    foreach ($originalMatrix['cells'] as $position => $cell) {
        if (
            is_array($cell)
            && (string) ($cell['function'] ?? '') === ''
            && empty($cell['redcopy'])
        ) {
            $blankPosition = (string) $position;
            break;
        }
    }
    assignment_test_assert(
        is_string($blankPosition),
        'Disposable recipient matrix has no blank policy-test position'
    );
    assignment_test_assert(
        !array_key_exists(
            $function,
            estab_assignment_function_roles($connection, 'nv_empfmtx')
        ),
        'Random policy-test function collided with the active matrix'
    );

    $matrixWithFunction = $originalMatrix;
    $matrixWithFunction['cells'][$blankPosition]['function'] = $function;
    $matrixWithFunction['cells'][$blankPosition]['role'] = 'Stab';
    $matrixWithFunction['cells'][$blankPosition]['auto'] = false;
    $matrixWithFunction['cells'][$blankPosition]['redcopy'] = false;
    estab_admin_replace_matrix(
        $connection,
        $database,
        'nv_empfmtx',
        'nv_benutzer',
        'nv_protokoll',
        $matrixWithFunction,
        'assignment-integration',
        '127.0.0.1'
    );
    $matrixChanged = true;

    estab_user_admin_create_account(
        $secondConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $name,
        $code,
        $function,
        $password,
        $password,
        'nv_empfmtx',
        'assignment-integration',
        '127.0.0.1'
    );
    $sidOne = 'mxsid' . bin2hex(random_bytes(8));
    $statement = $secondConnection->prepare(
        'UPDATE `nv_benutzer` SET `aktiv` = 1, `sid` = ?,'
        . " `ip` = '192.0.2.30', `fwdip` = '198.51.100.31',"
        . ' `estab_letzte_aktivitaet` = UTC_TIMESTAMP(6)'
        . ' WHERE `kuerzel` = ?'
    );
    assignment_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare active policy fixture'
    );
    try {
        $statement->bind_param('ss', $sidOne, $code);
        assignment_test_assert(
            $statement->execute() && $statement->affected_rows === 1,
            'Could not activate policy fixture'
        );
    } finally {
        $statement->close();
    }
    $sessionOne = [
        'vStab_benutzer' => $name,
        'vStab_kuerzel' => $code,
        'vStab_funktion' => $function,
        'vStab_rolle' => 'Stab',
        'ROLLE' => 'Stab',
    ];
    assignment_test_assert(
        estab_auth_current_session_identity(
            $sessionOne,
            $config,
            'nv_benutzer',
            $sidOne
        ) !== null,
        'Valid pre-change account session was rejected'
    );
    assignment_test_assert(
        assignment_test_readiness($connection) === '1',
        'Valid active assignment failed canonical readiness'
    );

    // Two independent MariaDB connections contend for the real global lock.
    // Neither account reassignment nor matrix replacement may read/commit a
    // stale map while the first connection owns policy.
    $heldPolicyLock = estab_assignment_acquire_policy_lock(
        $connection,
        $database,
        'nv_empfmtx'
    );
    $reassignBusy = false;
    $createBusy = false;
    $matrixBusy = false;
    try {
        try {
            estab_user_admin_reassign(
                $secondConnection,
                $database,
                'nv_benutzer',
                'nv_protokoll',
                $code,
                'Si',
                'nv_empfmtx',
                'assignment-integration',
                '127.0.0.1'
            );
        } catch (EstabAssignmentBusyException) {
            $reassignBusy = true;
        }
        try {
            estab_user_admin_create_account(
                $secondConnection,
                $database,
                'nv_benutzer',
                'nv_protokoll',
                'Blocked Policy Creation',
                $busyCreateCode,
                $function,
                $password,
                $password,
                'nv_empfmtx',
                'assignment-integration',
                '127.0.0.1'
            );
        } catch (EstabAssignmentBusyException) {
            $createBusy = true;
        }
        try {
            estab_admin_replace_matrix(
                $thirdConnection,
                $database,
                'nv_empfmtx',
                'nv_benutzer',
                'nv_protokoll',
                $matrixWithFunction,
                'assignment-integration',
                '127.0.0.1'
            );
        } catch (EstabAssignmentBusyException) {
            $matrixBusy = true;
        }
    } finally {
        estab_assignment_release_policy_lock($connection, $heldPolicyLock);
    }
    assignment_test_assert(
        $reassignBusy && $createBusy && $matrixBusy,
        'Parallel create/reassign/matrix writes bypassed the policy lock'
    );
    assignment_test_assert(
        assignment_test_scalar(
            $connection,
            'SELECT COUNT(*) FROM `nv_benutzer` WHERE `kuerzel` = ?',
            's',
            $busyCreateCode
        ) === '0',
        'Policy-blocked account creation persisted a partial row'
    );
    assignment_test_assert(
        assignment_test_scalar(
            $connection,
            'SELECT CONCAT(`funktion`, ?, `rolle`, ?, `aktiv`, ?, `sid`)'
            . ' FROM `nv_benutzer` WHERE `kuerzel` = ?',
            'ssss',
            '|',
            '|',
            '|',
            $code
        ) === $function . '|Stab|1|' . $sidOne,
        'Rejected policy-lock race changed account state'
    );

    $roleChangedMatrix = $matrixWithFunction;
    $roleChangedMatrix['cells'][$blankPosition]['role'] = 'FB';

    // Fail the first audit insert after the matrix and account-role writes.
    // The shared InnoDB transaction must restore matrix, account session and
    // ledger exactly.
    $auditCountBeforeRollback = assignment_test_scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_protokoll` WHERE `p_lfd` > ?',
        'i',
        $auditFloor
    );
    assignment_test_assert(
        $connection->query(
            'CREATE TRIGGER `' . $rollbackTrigger . '`'
            . ' BEFORE INSERT ON `nv_protokoll` FOR EACH ROW'
            . " SIGNAL SQLSTATE '45000'"
            . " SET MESSAGE_TEXT = 'intentional assignment audit rollback'"
        ) === true,
        'Could not install assignment-audit rollback trigger'
    );
    $auditRollbackRejected = false;
    try {
        estab_admin_replace_matrix(
            $secondConnection,
            $database,
            'nv_empfmtx',
            'nv_benutzer',
            'nv_protokoll',
            $roleChangedMatrix,
            'assignment-integration',
            '127.0.0.1'
        );
    } catch (Throwable) {
        $auditRollbackRejected = true;
    } finally {
        assignment_test_assert(
            $connection->query(
                'DROP TRIGGER IF EXISTS `' . $rollbackTrigger . '`'
            ) === true,
            'Could not remove assignment-audit rollback trigger'
        );
    }
    assignment_test_assert(
        $auditRollbackRejected,
        'Audit failure did not reject the matrix/account transaction'
    );
    assignment_test_assert(
        estab_admin_fetch_matrix($connection, 'nv_empfmtx')
            === $matrixWithFunction
            && assignment_test_scalar(
                $connection,
                'SELECT CONCAT(`funktion`, ?, `rolle`, ?, `aktiv`, ?, `sid`)'
                . ' FROM `nv_benutzer` WHERE `kuerzel` = ?',
                'ssss',
                '|',
                '|',
                '|',
                $code
            ) === $function . '|Stab|1|' . $sidOne
            && assignment_test_scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_protokoll` WHERE `p_lfd` > ?',
                'i',
                $auditFloor
            ) === $auditCountBeforeRollback,
        'Audit failure retained matrix, role, SID, active state or ledger rows'
    );

    estab_admin_replace_matrix(
        $secondConnection,
        $database,
        'nv_empfmtx',
        'nv_benutzer',
        'nv_protokoll',
        $roleChangedMatrix,
        'assignment-integration',
        '127.0.0.1'
    );
    assignment_test_assert(
        assignment_test_scalar(
            $connection,
            'SELECT CONCAT(`funktion`, ?, `rolle`, ?, `aktiv`, ?, `sid`, ?,'
            . ' `ip`, ?, `fwdip`) FROM `nv_benutzer` WHERE `kuerzel` = ?',
            'ssssss',
            '|',
            '|',
            '|',
            '|',
            '|',
            $code
        ) === $function . '|FB|0|||',
        'Matrix role change was not synchronised with session revocation'
    );
    assignment_test_assert(
        estab_auth_current_session_identity(
            $sessionOne,
            $config,
            'nv_benutzer',
            $sidOne
        ) === null
            && $sessionOne === ['menue' => 'LOGIN'],
        'Role-changed account retained its old application session'
    );

    $sidTwo = 'mxsid' . bin2hex(random_bytes(8));
    $statement = $secondConnection->prepare(
        'UPDATE `nv_benutzer` SET `aktiv` = 1, `sid` = ?,'
        . " `ip` = '192.0.2.32', `fwdip` = '198.51.100.33',"
        . ' `estab_letzte_aktivitaet` = UTC_TIMESTAMP(6)'
        . ' WHERE `kuerzel` = ?'
    );
    assignment_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare second active policy fixture'
    );
    try {
        $statement->bind_param('ss', $sidTwo, $code);
        assignment_test_assert(
            $statement->execute() && $statement->affected_rows === 1,
            'Could not reactivate role-synchronised fixture'
        );
    } finally {
        $statement->close();
    }
    $sessionTwo = [
        'vStab_benutzer' => $name,
        'vStab_kuerzel' => $code,
        'vStab_funktion' => $function,
        'vStab_rolle' => 'FB',
        'ROLLE' => 'FB',
    ];

    $removedMatrix = $roleChangedMatrix;
    $removedMatrix['cells'][$blankPosition]['function'] = '';
    $removedMatrix['cells'][$blankPosition]['role'] = '';
    $removedMatrix['cells'][$blankPosition]['auto'] = false;
    $removedMatrix['cells'][$blankPosition]['redcopy'] = false;
    estab_admin_replace_matrix(
        $secondConnection,
        $database,
        'nv_empfmtx',
        'nv_benutzer',
        'nv_protokoll',
        $removedMatrix,
        'assignment-integration',
        '127.0.0.1'
    );
    assignment_test_assert(
        assignment_test_scalar(
            $connection,
            'SELECT CONCAT(`funktion`, ?, `rolle`, ?, `aktiv`, ?, `sid`, ?,'
            . ' `ip`, ?, `fwdip`) FROM `nv_benutzer` WHERE `kuerzel` = ?',
            'ssssss',
            '|',
            '|',
            '|',
            '|',
            '|',
            $code
        ) === $function . '|FB|0|||',
        'Removed function was remapped or retained an active session'
    );
    assignment_test_assert(
        estab_auth_current_session_identity(
            $sessionTwo,
            $config,
            'nv_benutzer',
            $sidTwo
        ) === null,
        'Orphaned account retained its application session'
    );
    $removedRoles = estab_assignment_function_roles(
        $connection,
        'nv_empfmtx'
    );
    $removedLogin = estab_auth_validate_login_with_roles(
        [
            'benutzer' => $name,
            'kuerzel' => $code,
            'funktion' => $function,
            'kennwort1' => $password,
        ],
        $removedRoles
    );
    assignment_test_assert(
        $removedLogin['valid'] === false
            && in_array('funktion', $removedLogin['errors'], true),
        'Removed function remained a valid login assignment'
    );
    assignment_test_assert(
        assignment_test_readiness($connection) === '1',
        'Inactive orphan incorrectly made the service unready'
    );
    $orphanReset = estab_user_admin_reset_password(
        $secondConnection,
        $database,
        'nv_benutzer',
        'nv_protokoll',
        $code,
        $orphanResetPassword,
        'assignment-integration',
        '127.0.0.1'
    );
    $orphanHash = assignment_test_scalar(
        $connection,
        'SELECT `password` FROM `nv_benutzer` WHERE `kuerzel` = ?'
        . ' AND `funktion` = ? AND `rolle` = ?'
        . " AND `aktiv` = 0 AND `sid` = ''",
        'sss',
        $code,
        $function,
        'FB'
    );
    assignment_test_assert(
        $orphanReset === ['active_session_revoked' => false]
            && is_string($orphanHash)
            && password_verify($orphanResetPassword, $orphanHash)
            && !password_verify($password, $orphanHash),
        'Orphan password reset failed or changed its preserved assignment'
    );
    $removedLoginAfterReset = estab_auth_validate_login_with_roles(
        [
            'benutzer' => $name,
            'kuerzel' => $code,
            'funktion' => $function,
            'kennwort1' => $orphanResetPassword,
        ],
        $removedRoles
    );
    assignment_test_assert(
        $removedLoginAfterReset['valid'] === false
            && in_array(
                'funktion',
                $removedLoginAfterReset['errors'],
                true
            )
            && assignment_test_scalar(
                $connection,
                'SELECT COUNT(*) FROM `nv_protokoll`'
                . " WHERE `p_lfd` > ? AND `p_was` = 'Benutzerverwaltung'"
                . " AND JSON_UNQUOTE(JSON_EXTRACT(`p_ereignis`, '$.target')) = ?"
                . " AND JSON_UNQUOTE(JSON_EXTRACT(`p_ereignis`, '$.action'))"
                . " = 'reset_password'",
                'is',
                $auditFloor,
                $code
            ) === '1',
        'Reset made an orphan login-capable or omitted its audit'
    );

    // A manually reactivated orphan proves the operational readiness guard.
    $statement = $secondConnection->prepare(
        'UPDATE `nv_benutzer` SET `aktiv` = 1, `sid` = ?,'
        . ' `estab_letzte_aktivitaet` = UTC_TIMESTAMP(6)'
        . ' WHERE `kuerzel` = ?'
    );
    assignment_test_assert(
        $statement instanceof mysqli_stmt,
        'Could not prepare stale active assignment fixture'
    );
    try {
        $statement->bind_param('ss', $sidTwo, $code);
        assignment_test_assert(
            $statement->execute() && $statement->affected_rows === 1,
            'Could not activate stale assignment fixture'
        );
    } finally {
        $statement->close();
    }
    assignment_test_assert(
        assignment_test_readiness($connection) === '0',
        'Readiness accepted an active orphaned assignment'
    );
    estab_admin_replace_matrix(
        $secondConnection,
        $database,
        'nv_empfmtx',
        'nv_benutzer',
        'nv_protokoll',
        $removedMatrix,
        'assignment-integration',
        '127.0.0.1'
    );
    assignment_test_assert(
        assignment_test_readiness($connection) === '1'
            && assignment_test_scalar(
                $connection,
                'SELECT CONCAT(`aktiv`, ?, `sid`) FROM `nv_benutzer`'
                . ' WHERE `kuerzel` = ?',
                'ss',
                '|',
                $code
            ) === '0|',
        'Repeated matrix save did not revoke a stale orphan session'
    );

    $roleSyncAudit = assignment_test_scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_protokoll`'
        . " WHERE `p_lfd` > ? AND `p_was` = 'Benutzerverwaltung'"
        . " AND JSON_UNQUOTE(JSON_EXTRACT(`p_ereignis`, '$.target')) = ?"
        . " AND JSON_UNQUOTE(JSON_EXTRACT(`p_ereignis`, '$.action'))"
        . " = 'matrix_role_sync'"
        . " AND JSON_EXTRACT(`p_ereignis`, '$.active_session_revoked') = true",
        'is',
        $auditFloor,
        $code
    );
    $orphanAudit = assignment_test_scalar(
        $connection,
        'SELECT COUNT(*) FROM `nv_protokoll`'
        . " WHERE `p_lfd` > ? AND `p_was` = 'Benutzerverwaltung'"
        . " AND JSON_UNQUOTE(JSON_EXTRACT(`p_ereignis`, '$.target')) = ?"
        . " AND JSON_UNQUOTE(JSON_EXTRACT(`p_ereignis`, '$.action'))"
        . " = 'matrix_orphan'"
        . " AND JSON_EXTRACT(`p_ereignis`, '$.active_session_revoked') = true",
        'is',
        $auditFloor,
        $code
    );
    assignment_test_assert(
        $roleSyncAudit === '1' && $orphanAudit === '2',
        'Matrix-driven role/orphan revocations were not audited exactly'
    );
} finally {
    try {
        $connection->query(
            'DROP TRIGGER IF EXISTS `' . $rollbackTrigger . '`'
        );
        $statement = $connection->prepare(
            'DELETE FROM `nv_benutzer` WHERE `kuerzel` IN (?, ?)'
        );
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param('ss', $code, $busyCreateCode);
            $statement->execute();
            $statement->close();
        }
        if ($matrixChanged && is_array($originalMatrix)) {
            estab_admin_replace_matrix(
                $connection,
                $database,
                'nv_empfmtx',
                'nv_benutzer',
                'nv_protokoll',
                $originalMatrix,
                'assignment-integration-cleanup',
                '127.0.0.1'
            );
        }
        $statement = $connection->prepare(
            'DELETE FROM `nv_protokoll` WHERE `p_lfd` > ?'
            . " AND `p_was` IN ('Benutzerverwaltung', 'Empfängermatrix')"
        );
        if ($statement instanceof mysqli_stmt) {
            $statement->bind_param('i', $auditFloor);
            $statement->execute();
            $statement->close();
        }
    } finally {
        estab_auth_close($thirdConnection);
        estab_auth_close($secondConnection);
        estab_auth_close($connection);
    }
}

printf(
    "Assignment policy MariaDB: OK (%d assertions)\n",
    $assertions
);
