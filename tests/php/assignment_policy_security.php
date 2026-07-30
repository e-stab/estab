<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/assignment.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$lockName = estab_assignment_policy_lock_name('estab', 'nv_empfmtx');
$assert(
    $lockName === 'estab:assignment:' . substr(
        hash('sha256', "estab\0nv_empfmtx"),
        0,
        47
    ) && strlen($lockName) <= 64,
    'assignment-policy lock name is unstable or too long'
);

$cells = [];
for ($row = 1; $row <= 5; $row++) {
    for ($column = 1; $column <= 4; $column++) {
        $position = (string) $row . (string) $column;
        $cells[$position] = [
            'row' => $row,
            'column' => $column,
            'function' => '',
            'role' => '',
            'auto' => false,
            'redcopy' => false,
        ];
    }
}
$cells['11']['function'] = 'S2';
$cells['11']['role'] = 'Stab';
$cells['11']['redcopy'] = true;
$cells['12']['function'] = 'POL';
$cells['12']['role'] = 'FB';
$cells['13']['function'] = 'S1';
$cells['13']['role'] = 'Stab';
$roles = estab_assignment_roles_from_matrix([
    'cells' => $cells,
    'redcopy' => '11',
]);
$assert(
    $roles === [
        'A/W' => 'Fernmelder',
        'LdF' => 'Fernmelder',
        'POL' => 'FB',
        'S1' => 'Stab',
        'S2' => 'Stab',
        'Si' => 'Stab',
    ],
    'canonical matrix did not produce the complete authoritative role map'
);
$assert(
    estab_assignment_is_current($roles, 'S1', 'Stab')
        && !estab_assignment_is_current($roles, 'S1', 'FB')
        && estab_assignment_is_current($roles, 'S2', 'Stab')
        && !estab_assignment_is_current($roles, 'S3', 'Stab'),
    'current and orphaned assignments are not distinguished exactly'
);
$legacyShape = estab_assignment_roles_as_conf_empf($roles);
$assert(
    count($legacyShape) === 6
        && ($legacyShape[1]['fkt'] ?? null) === 'A/W'
        && ($legacyShape[2]['fkt'] ?? null) === 'LdF'
        && ($legacyShape[6]['fkt'] ?? null) === 'Si',
    'role map cannot be adapted to the legacy login configuration'
);

$accountAudit = estab_assignment_account_audit(
    'matrix_orphan',
    'admin@example.test',
    [
        'kuerzel' => 'hd',
        'funktion' => 'S1',
        'rolle' => 'Stab',
        'sid' => 'must-not-be-logged',
        'password' => 'must-not-be-logged',
    ],
    true,
    '192.0.2.8',
    'Stab'
);
$decodedAccountAudit = json_decode(
    $accountAudit,
    true,
    8,
    JSON_THROW_ON_ERROR
);
$assert(
    ($decodedAccountAudit['action'] ?? null) === 'matrix_orphan'
        && ($decodedAccountAudit['target'] ?? null) === 'hd'
        && ($decodedAccountAudit['old_function'] ?? null) === 'S1'
        && ($decodedAccountAudit['new_function'] ?? null) === 'S1'
        && ($decodedAccountAudit['active_session_revoked'] ?? null) === true
        && !str_contains($accountAudit, 'must-not-be-logged'),
    'matrix-driven account audit leaks credentials or omits the preserved assignment'
);
$matrixAudit = json_decode(
    estab_assignment_matrix_audit(
        'replace_active',
        ['role_synced' => 2, 'orphaned' => 1, 'sessions_revoked' => 3]
    ),
    true,
    8,
    JSON_THROW_ON_ERROR
);
$assert(
    $matrixAudit === [
        'version' => 1,
        'action' => 'replace_active',
        'positions' => 20,
        'role_synced' => 2,
        'orphaned' => 1,
        'sessions_revoked' => 3,
    ],
    'matrix transaction audit summary is incomplete or unstable'
);

$root = dirname(__DIR__, 2);
$policySource = file_get_contents($root . '/app/assignment.php');
$adminSource = file_get_contents($root . '/app/admin_operations.php');
$userSource = file_get_contents($root . '/app/user_admin.php');
$loginSource = file_get_contents($root . '/4fach/data_hndl.php');
$userPage = file_get_contents($root . '/4fadm/users.php');
$matrixPage = file_get_contents($root . '/4fadm/make_fkt.php');
$runtimeVerifier = file_get_contents(
    $root . '/docker/app/verify-runtime-surface.sh'
);
$legacyMatrixConfig = file_get_contents($root . '/4fcfg/fkt_rolle.inc.php');
foreach ([
    $policySource,
    $adminSource,
    $userSource,
    $loginSource,
    $userPage,
    $matrixPage,
    $runtimeVerifier,
    $legacyMatrixConfig,
] as $source) {
    $assert(is_string($source), 'assignment-policy source is unreadable');
}

$assert(
    str_contains($policySource, 'SELECT GET_LOCK(?, ?)')
        && substr_count($policySource, "'LdF' => 'Fernmelder'") === 2
        && str_contains($policySource, 'SELECT RELEASE_LOCK(?)')
        && str_contains($policySource, 'ORDER BY `kuerzel` FOR UPDATE')
        && str_contains($policySource, '`rolle` = ?')
        && str_contains($policySource, '`aktiv` = 0')
        && str_contains($policySource, "`sid` = ''")
        && str_contains($policySource, "'matrix_role_sync'")
        && str_contains($policySource, "'matrix_orphan'")
        && !str_contains($policySource, 'SET `funktion` =')
        && !str_contains($policySource, 'DROP TABLE'),
    'policy reconciliation remaps assignments or omits atomic revocation/audit'
);
$assert(
    substr_count($adminSource, 'estab_assignment_acquire_policy_lock(') === 2
        && substr_count(
            $adminSource,
            'estab_assignment_reconcile_accounts('
        ) === 2
        && substr_count(
            $adminSource,
            'estab_assignment_matrix_audit('
        ) === 2
        && str_contains($adminSource, '$oldRoles =')
        && str_contains($adminSource, '$newRoles =')
        && str_contains($adminSource, '$connection->commit()')
        && str_contains($adminSource, '$connection->rollback()'),
    'active matrix saves do not share one policy/account/audit transaction'
);

$loginStart = strpos($loginSource, 'function check_save_user ');
$loginEnd = strpos(
    $loginSource,
    '} // function save_user',
    $loginStart === false ? 0 : $loginStart
);
$loginBody = $loginStart !== false && $loginEnd !== false
    ? substr($loginSource, $loginStart, $loginEnd - $loginStart)
    : '';
$loginPolicy = strpos($loginBody, 'estab_assignment_acquire_policy_lock');
$loginMap = strpos($loginBody, 'estab_assignment_function_roles');
$loginAccount = strpos($loginBody, 'estab_login_acquire_account_lock');
$loginTransaction = strpos($loginBody, '$connection->begin_transaction ()');
$assert(
    $loginPolicy !== false
        && $loginMap !== false
        && $loginAccount !== false
        && $loginTransaction !== false
        && $loginPolicy < $loginMap
        && $loginMap < $loginAccount
        && $loginAccount < $loginTransaction
        && str_contains($loginBody, 'estab_auth_validate_login_with_roles')
        && str_contains($loginBody, 'estab_assignment_release_policy_lock'),
    'login reads a stale role map or violates the global lock hierarchy'
);

foreach (
    ['estab_user_admin_create_account', 'estab_user_admin_reassign']
    as $function
) {
    $start = strpos($userSource, 'function ' . $function . '(');
    $assert($start !== false, $function . ' is missing');
    $end = strpos($userSource, "\nfunction ", ($start ?: 0) + 10);
    $body = $start === false
        ? ''
        : substr(
            $userSource,
            $start,
            $end === false ? null : $end - $start
        );
    $policy = strpos($body, 'estab_assignment_acquire_policy_lock');
    $map = strpos($body, 'estab_assignment_function_roles');
    $account = strpos($body, 'estab_user_admin_acquire_account_lock');
    $transaction = strpos($body, '$connection->begin_transaction()');
    $assert(
        $policy !== false
            && $map !== false
            && $account !== false
            && $transaction !== false
            && $policy < $map
            && $map < $account
            && $account < $transaction
            && str_contains($body, 'estab_assignment_release_policy_lock'),
        $function . ' reads stale policy or violates the lock hierarchy'
    );
}

$assert(
    str_contains($userPage, 'data-estab-assignment-orphaned')
        && str_contains($userPage, 'Zuordnung nicht mehr gültig')
        && str_contains($matrixPage, 'Betroffene Sitzungen')
        && str_contains($matrixPage, '<code>LdF</code> sind reserviert')
        && str_contains($matrixPage, "\$conf_4f_tbl['benutzer']")
        && str_contains($runtimeVerifier, 'app/assignment.php')
        && str_contains(
            $legacyMatrixConfig,
            'array ("fkt" => "LdF", "rolle" => "Fernmelder" )'
        ),
    'administration does not expose policy impact or ship the policy boundary'
);
$assert(
    substr_count($userPage, 'catch (EstabAssignmentBusyException') >= 2
        && substr_count($userPage, 'http_response_code(409)') >= 3
        && str_contains($matrixPage, 'catch (EstabAssignmentBusyException')
        && str_contains($matrixPage, 'http_response_code(409)')
        && str_contains($matrixPage, 'anderen administrativen Aktion'),
    'policy-lock contention is exposed as an unsafe or misleading HTTP 500'
);
$assert(
    str_contains(
        $userPage,
        '$displayPolicyLockName = estab_assignment_acquire_policy_lock('
    )
        && str_contains(
            $userPage,
            'estab_assignment_release_policy_lock('
        )
        && strpos(
            $userPage,
            'estab_user_admin_function_roles('
        ) < strpos(
            $userPage,
            'estab_user_admin_list('
        )
        && substr_count(
            $legacyMatrixConfig,
            'FROM ".$conf_4f_tbl   ["empfmtx"]." WHERE 1'
        ) === 1
        && substr_count($legacyMatrixConfig, 'mysql_query ($query, $db)') === 1,
    'display readers can combine matrix state from different commits'
);

printf(
    "Assignment policy security: OK (%d assertions)\n",
    $assertions
);
