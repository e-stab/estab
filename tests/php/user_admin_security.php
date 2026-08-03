<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/user_admin.php';
require_once dirname(__DIR__, 2) . '/app/csrf.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throwsInvalidArgument = static function (callable $operation): bool {
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
};
$throwsRuntime = static function (callable $operation): bool {
    try {
        $operation();
    } catch (RuntimeException) {
        return true;
    }
    return false;
};
$functionSource = static function (string $source, string $function): string {
    $start = strpos($source, 'function ' . $function . '(');
    if ($start === false) {
        $start = strpos($source, "function {$function} (");
    }
    if ($start === false) {
        return '';
    }
    $next = strpos($source, "\nfunction ", $start + 10);
    return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
};

$assert(
    estab_user_admin_validate_code('  HD  ') === 'hd',
    'account code was not canonicalised'
);
foreach (['', 'toolong', 'a-b', "ab\0", 'ä'] as $invalidCode) {
    $assert(
        $throwsInvalidArgument(
            static fn (): string => estab_user_admin_validate_code($invalidCode)
        ),
        'invalid account code was accepted'
    );
}
$assert(
    $throwsInvalidArgument(
        static fn (): string => estab_user_admin_validate_code(['hd'])
    ),
    'non-string account code was accepted'
);
$assert(
    estab_auth_extra_function_is_eligible('ETB', 'Stab')
        && estab_auth_extra_function_is_eligible('S6', 'Stab')
        && estab_auth_has_staff_message_workspace('S6', 'Stab')
        && !estab_auth_has_staff_message_workspace('ETB', 'Stab'),
    'ETB cannot be granted as a logbook-only extra or owns a message workspace'
);

$assert(
    estab_user_admin_validate_name('  Müller, Ada  ') === 'Müller, Ada',
    'valid account name was not canonicalised'
);
foreach ([
    '',
    '<script>',
    "Admin\nName",
    "Ada\n",
    "Ada\0",
    str_repeat('a', 51),
    "\xFF",
] as $invalidName) {
    $assert(
        $throwsInvalidArgument(
            static fn (): string => estab_user_admin_validate_name($invalidName)
        ),
        'invalid account name was accepted'
    );
}
$functionRoles = [
    'A/W' => 'Fernmelder',
    'LdF' => 'Fernmelder',
    'S1' => 'Stab',
    'S2' => 'FB',
];
$assert(
    estab_user_admin_validate_assignment(' S1 ', $functionRoles) === [
        'funktion' => 'S1',
        'rolle' => 'Stab',
    ],
    'administrative assignment did not derive the role server-side'
);
$assert(
    estab_user_admin_validate_assignment('LdF', $functionRoles) === [
        'funktion' => 'LdF',
        'rolle' => 'Fernmelder',
    ],
    'reserved LdF assignment was not provisioned with the Fernmelder role'
);
foreach (['', 'S3', 'S 1', "S1\0"] as $invalidFunction) {
    $assert(
        $throwsInvalidArgument(
            static fn (): array => estab_user_admin_validate_assignment(
                $invalidFunction,
                $functionRoles
            )
        ),
        'unknown or malformed administrative function was accepted'
    );
}
$assert(
    estab_user_admin_validate_extra_function_key('ALT/S6') === 'ALT/S6',
    'orphaned extra-function key cannot be retained for safe revocation'
);
foreach (['', str_repeat('x', 11), "S6\0", "S6\n"] as $invalidExtraKey) {
    $assert(
        $throwsInvalidArgument(
            static fn (): string =>
                estab_user_admin_validate_extra_function_key($invalidExtraKey)
        ),
        'unsafe extra-function storage key was accepted'
    );
}
$assert(
    estab_user_admin_validate_extra_function_time(
        '2026-08-02 13:14:15.123456'
    ) === '2026-08-02 13:14:15.123456',
    'valid extra-function revision timestamp was rejected'
);
foreach ([
    '2026-08-02 13:14:15',
    '2026-08-02T13:14:15.123456',
    '2026-08-02 13:14:15.12345',
] as $invalidExtraTime) {
    $assert(
        $throwsInvalidArgument(
            static fn (): string =>
                estab_user_admin_validate_extra_function_time($invalidExtraTime)
        ),
        'ambiguous extra-function revision timestamp was accepted'
    );
}
$revisionRows = [
    [
        'funktion' => 'S6',
        'rolle' => 'Stab',
        'vergeben_am' => '2026-08-02 13:14:15.123456',
        'vergeben_von' => 'admin',
    ],
    [
        'funktion' => 'LdF',
        'rolle' => 'Fernmelder',
        'vergeben_am' => '2026-08-02 13:14:16.123456',
        'vergeben_von' => 'admin',
    ],
];
$extraRevision = estab_user_admin_extra_functions_revision($revisionRows);
$assert(
    preg_match('/\A[a-f0-9]{64}\z/D', $extraRevision) === 1
        && hash_equals(
            $extraRevision,
            estab_user_admin_extra_functions_revision(array_reverse($revisionRows))
        )
        && !hash_equals(
            $extraRevision,
            estab_user_admin_extra_functions_revision([$revisionRows[0]])
        )
        && estab_user_admin_validate_extra_functions_revision($extraRevision)
            === $extraRevision,
    'extra-function set revision is not stable, complete or canonical'
);
$assert(
    $throwsInvalidArgument(
        static fn (): string =>
            estab_user_admin_validate_extra_functions_revision(
                strtoupper($extraRevision)
            )
    ),
    'non-canonical extra-function set revision was accepted'
);
$assert(
    $throwsRuntime(
        static function (): void {
            estab_user_admin_require_expected_primary_assignment(
                ['funktion' => 'S6', 'rolle' => 'Stab'],
                'LdF',
                'Fernmelder'
            );
        }
    ),
    'stale primary assignment did not conflict'
);
estab_user_admin_require_expected_primary_assignment(
    ['funktion' => 'S6', 'rolle' => 'Stab'],
    'S6',
    'Stab'
);
$assert(true, 'current primary assignment was rejected');

$validPassword = 'lange Einsatz-Passphrase 2026!';
$assert(
    estab_user_admin_validate_password(
        $validPassword,
        $validPassword
    ) === $validPassword,
    'valid password was changed or rejected'
);
foreach ([
    ['zu-kurz', 'zu-kurz'],
    ['lange-passphrase-a', 'lange-passphrase-b'],
    ["lange-pass\0phrase", "lange-pass\0phrase"],
    ["lange-pass\nphrase", "lange-pass\nphrase"],
    [
        str_repeat('a', ESTAB_AUTH_PASSWORD_MAXIMUM_BYTES + 1),
        str_repeat('a', ESTAB_AUTH_PASSWORD_MAXIMUM_BYTES + 1),
    ],
    ["ungueltig-\xFF-passphrase", "ungueltig-\xFF-passphrase"],
] as [$password, $confirmation]) {
    $assert(
        $throwsInvalidArgument(
            static fn (): string => estab_user_admin_validate_password(
                $password,
                $confirmation
            )
        ),
        'invalid reset password was accepted'
    );
}
$assert(
    $throwsInvalidArgument(
        static fn (): string => estab_user_admin_validate_password(
            ['not-a-password'],
            ['not-a-password']
        )
    ),
    'non-string password was accepted'
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_id('estab-user-admin-csrf-test');
    $assert(session_start(), 'could not start CSRF test session');
}
$_SESSION = [];
$csrfToken = estab_csrf_token();
$assert(
    $throwsRuntime(
        static function (): void {
            estab_csrf_require_post(
                ['REQUEST_METHOD' => 'POST'],
                []
            );
        }
    ),
    'missing user-administration CSRF token was accepted'
);
$assert(
    $throwsRuntime(
        static function (): void {
            estab_csrf_require_post(
                ['REQUEST_METHOD' => 'POST'],
                ['csrf_token' => str_repeat('0', 64)]
            );
        }
    ),
    'incorrect user-administration CSRF token was accepted'
);
$assert(
    $throwsRuntime(
        static function () use ($csrfToken): void {
            estab_csrf_require_post(
                ['REQUEST_METHOD' => 'GET'],
                ['csrf_token' => $csrfToken]
            );
        }
    ),
    'GET user-administration write was accepted'
);
estab_csrf_require_post(
    ['REQUEST_METHOD' => 'POST'],
    ['csrf_token' => $csrfToken]
);
$assert(true, 'valid CSRF token was rejected');

$assert(
    estab_user_admin_actor(['REMOTE_USER' => 'admin@example.test'])
        === 'admin@example.test',
    'valid Basic-Auth identity was rejected'
);
$assert(
    estab_user_admin_actor(['REMOTE_USER' => "admin\nforged"]) === 'unknown'
        && estab_user_admin_actor(['REMOTE_USER' => "admin\xFF"]) === 'unknown'
        && estab_user_admin_actor([]) === 'unknown',
    'unsafe Basic-Auth identity reached the audit boundary'
);

$audit = estab_user_admin_audit_details(
    'reset_password',
    'admin@example.test',
    'hd',
    true,
    '192.0.2.10'
);
$decodedAudit = json_decode($audit, true, 8, JSON_THROW_ON_ERROR);
$assert(
    $decodedAudit === [
        'version' => 1,
        'action' => 'reset_password',
        'admin' => 'admin@example.test',
        'target' => 'hd',
        'active_session_revoked' => true,
        'remote_address' => '192.0.2.10',
    ],
    'password-reset audit is incomplete or unstable'
);
$assert(
    !str_contains($audit, $validPassword)
        && !str_contains($audit, 'password_hash')
        && !str_contains($audit, 'session-secret'),
    'audit payload contains credential material'
);
$assert(
    $throwsInvalidArgument(
        static fn (): string => estab_user_admin_audit_details(
            'delete',
            'admin',
            'hd',
            false,
            ''
        )
    ),
    'unknown audit action was accepted'
);
$createAudit = json_decode(
    estab_user_admin_audit_details(
        'create',
        'admin@example.test',
        'neu001',
        false,
        '192.0.2.10',
        [
            'new_function' => 'S1',
            'new_role' => 'Stab',
        ]
    ),
    true,
    8,
    JSON_THROW_ON_ERROR
);
$assert(
    ($createAudit['action'] ?? null) === 'create'
        && ($createAudit['new_function'] ?? null) === 'S1'
        && ($createAudit['new_role'] ?? null) === 'Stab',
    'account creation audit omitted the immutable assignment'
);
$legacyReassignAudit = json_decode(
    estab_user_admin_audit_details(
        'reassign',
        'admin@example.test',
        'hd',
        true,
        '192.0.2.10',
        [
            'old_function' => '',
            'old_role' => '',
            'new_function' => 'A/W',
            'new_role' => 'Fernmelder',
        ]
    ),
    true,
    8,
    JSON_THROW_ON_ERROR
);
$assert(
    ($legacyReassignAudit['old_function'] ?? null) === ''
        && ($legacyReassignAudit['old_role'] ?? null) === ''
        && ($legacyReassignAudit['new_function'] ?? null) === 'A/W',
    'empty legacy assignment cannot be repaired and audited'
);
$assert(
    $throwsInvalidArgument(
        static fn (): string => estab_user_admin_audit_details(
            'create',
            'admin',
            'hd',
            false,
            '',
            [
                'new_function' => '',
                'new_role' => 'Stab',
            ]
        )
    ),
    'empty new assignment reached the audit record'
);
$extraSnapshot = [
    'funktion' => 'LdF',
    'rolle' => 'Fernmelder',
    'vergeben_am' => '2026-08-02 13:14:15.123456',
    'vergeben_von' => 'admin@example.test',
];
$grantExtraAudit = json_decode(
    estab_user_admin_extra_function_audit_details(
        'grant_extra_function',
        'admin@example.test',
        'hd',
        true,
        '192.0.2.10',
        null,
        $extraSnapshot
    ),
    true,
    8,
    JSON_THROW_ON_ERROR
);
$assert(
    ($grantExtraAudit['action'] ?? null) === 'grant_extra_function'
        && array_key_exists('before', $grantExtraAudit)
        && $grantExtraAudit['before'] === null
        && ($grantExtraAudit['after'] ?? null) === $extraSnapshot
        && ($grantExtraAudit['active_session_revoked'] ?? null) === true
        && !array_key_exists('sid', $grantExtraAudit)
        && !array_key_exists('password', $grantExtraAudit),
    'extra-function grant audit is incomplete or contains session secrets'
);
$revokeExtraAudit = json_decode(
    estab_user_admin_extra_function_audit_details(
        'revoke_extra_function',
        'admin@example.test',
        'hd',
        false,
        '192.0.2.10',
        $extraSnapshot,
        null
    ),
    true,
    8,
    JSON_THROW_ON_ERROR
);
$assert(
    ($revokeExtraAudit['before'] ?? null) === $extraSnapshot
        && array_key_exists('after', $revokeExtraAudit)
        && $revokeExtraAudit['after'] === null,
    'extra-function revocation audit omits its exact before/after state'
);
$assert(
    $throwsInvalidArgument(
        static fn (): string =>
            estab_user_admin_extra_function_audit_details(
                'grant_extra_function',
                'admin',
                'hd',
                false,
                '',
                $extraSnapshot,
                null
            )
    ),
    'invalid extra-function audit transition was accepted'
);

$lockName = estab_user_admin_account_lock_name('estab', 'nv_benutzer', 'hd');
$expectedLock = 'estab:login:' . substr(
    hash('sha256', "estab\0nv_benutzer\0hd"),
    0,
    52
);
$assert(
    $lockName === $expectedLock && strlen($lockName) <= 64,
    'administration does not share the login advisory-lock namespace'
);

$account = [
    'benutzer' => 'Hans, Dieter',
    'kuerzel' => 'hd',
    'funktion' => 'A/W',
    'rolle' => 'Fernmelder',
    'sid' => 'valid-session-123',
    'aktiv' => 1,
    'estab_gesperrt' => 0,
    'estab_letzte_aktivitaet' => gmdate('Y-m-d H:i:s.000000'),
];
$identity = [
    'benutzer' => 'Hans, Dieter',
    'kuerzel' => 'hd',
    'funktion' => 'A/W',
    'rolle' => 'Fernmelder',
];
$assert(
    estab_auth_account_matches_session(
        $account,
        $identity,
        'valid-session-123'
    ),
    'unblocked authoritative session was rejected'
);
$account['estab_gesperrt'] = 1;
$assert(
    estab_auth_account_is_blocked($account)
        && !estab_auth_account_matches_session(
            $account,
            $identity,
            'valid-session-123'
        ),
    'blocked account retained an authoritative session'
);
$assert(
    !estab_auth_account_is_blocked([]),
    'pre-migration account shape was interpreted as blocked'
);

$root = dirname(__DIR__, 2);
$librarySource = file_get_contents($root . '/app/user_admin.php');
$assignmentSource = file_get_contents($root . '/app/assignment.php');
$pageSource = file_get_contents($root . '/4fadm/users.php');
$dashboardSource = file_get_contents($root . '/4fadm/admin.php');
$authSource = file_get_contents($root . '/app/auth.php');
$loginSource = file_get_contents($root . '/4fach/data_hndl.php');
$migrationSource = file_get_contents(
    $root . '/docker/db/migrations/70-user-account-blocking.sql'
);
$dockerfile = file_get_contents($root . '/Dockerfile');
$runtimeVerifier = file_get_contents(
    $root . '/docker/app/verify-runtime-surface.sh'
);
$provisionSource = file_get_contents(
    $root . '/tests/integration/provision_user.php'
);
$provisionShell = file_get_contents(
    $root . '/tests/integration/provision_user.sh'
);
$integrationCi = file_get_contents($root . '/tests/integration/ci.sh');
foreach ([
    $librarySource,
    $assignmentSource,
    $pageSource,
    $dashboardSource,
    $authSource,
    $loginSource,
    $migrationSource,
    $dockerfile,
    $runtimeVerifier,
    $provisionSource,
    $provisionShell,
    $integrationCi,
] as $source) {
    $assert(is_string($source), 'user-administration source is unreadable');
}

$listSource = $functionSource($librarySource, 'estab_user_admin_list');
$blockSource = $functionSource(
    $librarySource,
    'estab_user_admin_set_blocked'
);
$resetSource = $functionSource(
    $librarySource,
    'estab_user_admin_reset_password'
);
$createSource = $functionSource(
    $librarySource,
    'estab_user_admin_create_account'
);
$reassignSource = $functionSource(
    $librarySource,
    'estab_user_admin_reassign'
);
$functionRoleSource = $functionSource(
    $librarySource,
    'estab_user_admin_function_roles'
);
$extraFunctionRoleSource = $functionSource(
    $librarySource,
    'estab_user_admin_extra_function_roles'
);
$catalogMergeSource = $functionSource(
    $authSource,
    'estab_auth_merge_function_role_catalog'
);
$currentCatalogSource = $functionSource(
    $authSource,
    'estab_auth_current_function_role_catalog'
);
$additionalFunctionSource = $functionSource(
    $authSource,
    'estab_auth_fetch_additional_functions'
);
$grantExtraFunctionSource = $functionSource(
    $librarySource,
    'estab_user_admin_grant_extra_function'
);
$revokeExtraFunctionSource = $functionSource(
    $librarySource,
    'estab_user_admin_revoke_extra_function'
);
$assert(
    $listSource !== ''
        && str_contains($listSource, '`estab_gesperrt`')
        && !str_contains($listSource, '`password`')
        && str_contains(
            $listSource,
            '`sid` REGEXP BINARY'
        )
        && str_contains($listSource, '^[A-Za-z0-9,-]{1,50}$')
        && str_contains($listSource, 'AS `estab_sitzung_vorhanden`')
        && !str_contains($listSource, '`ip`'),
    'account list reads reusable credential or network-session material'
);
$assert(
    $listSource !== ''
        && str_contains($listSource, '`nv_benutzer_zusatzfunktionen`')
        && str_contains($listSource, '`vergeben_am`')
        && str_contains($listSource, '`vergeben_von`')
        && str_contains($listSource, "'ist_gueltig'")
        && str_contains($listSource, '$extraFunctionRoles[$function]')
        && str_contains($listSource, '!hash_equals($primaryFunction')
        && !str_contains($listSource, '`password`')
        && !str_contains($listSource, '`sid` FROM'),
    'extra-function list is not fail-closed or exposes reusable credentials'
);
$assert(
    $blockSource !== ''
        && str_contains($blockSource, 'estab_user_admin_acquire_account_lock')
        && str_contains($blockSource, '$connection->begin_transaction()')
        && str_contains($blockSource, '`estab_gesperrt` = 1')
        && str_contains($blockSource, '`aktiv` = 0')
        && str_contains($blockSource, "`sid` = ''")
        && str_contains($blockSource, 'estab_auth_log_event')
        && str_contains($blockSource, '$connection->commit()')
        && str_contains($blockSource, '$connection->rollback()'),
    'blocking is not atomic, audited and session-revoking'
);
$assert(
    $resetSource !== ''
        && str_contains($resetSource, 'estab_auth_hash_password(')
        && !str_contains($resetSource, 'PASSWORD_DEFAULT')
        && str_contains($resetSource, 'estab_user_admin_acquire_account_lock')
        && str_contains($resetSource, '$connection->begin_transaction()')
        && str_contains($resetSource, '`password` = ?')
        && str_contains($resetSource, '`aktiv` = 0')
        && str_contains($resetSource, "`sid` = ''")
        && str_contains($resetSource, 'estab_auth_log_event')
        && str_contains($resetSource, '$connection->commit()')
        && !str_contains($resetSource, 'error_log($newPassword')
        && !str_contains($resetSource, 'error_log($password')
        && !str_contains($resetSource, 'error_log($passwordHash'),
    'password reset is not hashed, atomic, audited and session-revoking'
);
$assert(
    $functionRoleSource !== ''
        && str_contains(
            $functionRoleSource,
            'estab_assignment_function_roles($connection, $matrixTable)'
        )
        && is_string($assignmentSource)
        && str_contains($assignmentSource, 'estab_auth_table($matrixTable)')
        && str_contains($assignmentSource, '$connection->prepare(')
        && str_contains($assignmentSource, "'Si' => 'Stab'")
        && str_contains($assignmentSource, "'A/W' => 'Fernmelder'")
        && str_contains($assignmentSource, "'LdF' => 'Fernmelder'")
        && str_contains($assignmentSource, "['Stab', 'FB']"),
    'assignable functions are not derived from the server-controlled matrix'
);
$assert(
    $extraFunctionRoleSource !== ''
        && str_contains(
            $extraFunctionRoleSource,
            'estab_assignment_function_roles($connection, $matrixTable)'
        )
        && str_contains(
            $extraFunctionRoleSource,
            'estab_auth_merge_function_role_catalog('
        )
        && str_contains(
            $extraFunctionRoleSource,
            'estab_auth_extra_function_is_eligible('
        )
        && $catalogMergeSource !== ''
        && str_contains($catalogMergeSource, '`nv_funktionsfaehigkeiten`')
        && str_contains($catalogMergeSource, "' FOR UPDATE'")
        && str_contains($catalogMergeSource, 'widersprechen')
        && str_contains($catalogMergeSource, 'uksort(')
        && $currentCatalogSource !== ''
        && str_contains($currentCatalogSource, 'estab_auth_table($matrixTable)')
        && str_contains(
            $currentCatalogSource,
            'estab_auth_merge_function_role_catalog($connection, $roles)'
        )
        && $additionalFunctionSource !== ''
        && str_contains(
            $additionalFunctionSource,
            'estab_auth_current_function_role_catalog($connection)'
        )
        && str_contains($additionalFunctionSource, '$canonicalRoles[$function]')
        && str_contains(
            $additionalFunctionSource,
            'estab_auth_extra_function_is_eligible($function, $role)'
        )
        && str_contains($additionalFunctionSource, 'continue;')
        && !str_contains($additionalFunctionSource, ' OR EXISTS ('),
    'extra-function catalogue does not merge both authoritative sources safely'
);
$assert(
    $createSource !== ''
        && str_contains($createSource, 'estab_user_admin_validate_assignment')
        && str_contains($createSource, 'estab_auth_hash_password(')
        && !str_contains($createSource, 'PASSWORD_DEFAULT')
        && str_contains($createSource, 'estab_assignment_acquire_policy_lock')
        && str_contains($createSource, 'estab_assignment_function_roles')
        && str_contains($createSource, 'estab_user_admin_acquire_account_lock')
        && str_contains($createSource, '$connection->begin_transaction()')
        && str_contains($createSource, 'FOR UPDATE')
        && str_contains($createSource, '`aktiv`, `estab_gesperrt`, `password`')
        && str_contains($createSource, "VALUES (?, ?, ?, ?, '', '', '', 0, 0, ?)")
        && str_contains($createSource, "'create'")
        && str_contains($createSource, 'estab_auth_log_event')
        && str_contains($createSource, '$connection->commit()')
        && str_contains($createSource, '$connection->rollback()'),
    'account creation is not conflict-safe, hashed, inactive and atomically audited'
);
$assert(
    $reassignSource !== ''
        && str_contains($reassignSource, 'estab_user_admin_validate_assignment')
        && str_contains($reassignSource, 'estab_assignment_acquire_policy_lock')
        && str_contains($reassignSource, 'estab_assignment_function_roles')
        && str_contains($reassignSource, 'estab_user_admin_acquire_account_lock')
        && str_contains($reassignSource, '$connection->begin_transaction()')
        && str_contains($reassignSource, 'estab_user_admin_fetch_for_update')
        && str_contains($reassignSource, '`funktion` = ?, `rolle` = ?')
        && str_contains($reassignSource, '`aktiv` = 0')
        && str_contains($reassignSource, "`sid` = ''")
        && str_contains($reassignSource, "'reassign'")
        && str_contains($reassignSource, 'estab_auth_log_event')
        && str_contains($reassignSource, '$connection->commit()')
        && str_contains($reassignSource, '$connection->rollback()'),
    'function reassignment is not server-derived, atomic, audited and session-revoking'
);
$assert(
    $grantExtraFunctionSource !== ''
        && str_contains(
            $grantExtraFunctionSource,
            'estab_assignment_acquire_policy_lock('
        )
        && str_contains(
            $grantExtraFunctionSource,
            'estab_user_admin_acquire_account_lock('
        )
        && str_contains($grantExtraFunctionSource, '$connection->begin_transaction()')
        && substr_count(
            $grantExtraFunctionSource,
            '$connection->begin_transaction()'
        ) === 2
        && str_contains(
            $grantExtraFunctionSource,
            'estab_dynamic_schema_reconcile_hat('
        )
        && strpos(
            $grantExtraFunctionSource,
            'estab_dynamic_schema_reconcile_hat('
        ) < strpos(
            $grantExtraFunctionSource,
            'INSERT INTO `nv_benutzer_zusatzfunktionen`'
        )
        && str_contains(
            $grantExtraFunctionSource,
            'estab_user_admin_require_expected_primary_assignment('
        )
        && str_contains($grantExtraFunctionSource, '$expectedAbsent')
        && str_contains(
            $grantExtraFunctionSource,
            '$expectedExtraFunctionsRevision'
        )
        && str_contains(
            $grantExtraFunctionSource,
            'estab_user_admin_fetch_extra_functions_for_update('
        )
        && str_contains($grantExtraFunctionSource, 'kann nicht zusätzlich')
        && str_contains(
            $grantExtraFunctionSource,
            'INSERT INTO `nv_benutzer_zusatzfunktionen`'
        )
        && str_contains(
            $grantExtraFunctionSource,
            'estab_user_admin_revoke_locked_session('
        )
        && str_contains($grantExtraFunctionSource, "'grant_extra_function'")
        && str_contains($grantExtraFunctionSource, 'estab_auth_log_event(')
        && str_contains($grantExtraFunctionSource, '$connection->commit()')
        && str_contains($grantExtraFunctionSource, '$connection->rollback()'),
    'extra-function grant is not authoritative, conflict-safe, audited and session-revoking'
);
$assert(
    $revokeExtraFunctionSource !== ''
        && str_contains(
            $revokeExtraFunctionSource,
            'estab_assignment_acquire_policy_lock('
        )
        && str_contains(
            $revokeExtraFunctionSource,
            'estab_user_admin_acquire_account_lock('
        )
        && str_contains($revokeExtraFunctionSource, '$connection->begin_transaction()')
        && str_contains($revokeExtraFunctionSource, '$expectedGrantedAt')
        && str_contains($revokeExtraFunctionSource, '$expectedGrantedBy')
        && str_contains(
            $revokeExtraFunctionSource,
            '$expectedExtraFunctionsRevision'
        )
        && str_contains(
            $revokeExtraFunctionSource,
            'estab_user_admin_fetch_extra_functions_for_update('
        )
        && str_contains(
            $revokeExtraFunctionSource,
            'DELETE FROM `nv_benutzer_zusatzfunktionen`'
        )
        && str_contains(
            $revokeExtraFunctionSource,
            'estab_user_admin_revoke_locked_session('
        )
        && str_contains($revokeExtraFunctionSource, "'revoke_extra_function'")
        && str_contains($revokeExtraFunctionSource, 'estab_auth_log_event(')
        && str_contains($revokeExtraFunctionSource, '$connection->commit()')
        && str_contains($revokeExtraFunctionSource, '$connection->rollback()'),
    'extra-function revocation is not revision-safe, audited and session-revoking'
);

$assert(
    str_contains($pageSource, 'estab_admin_require_http_auth($_SERVER)')
        && str_contains(
            $pageSource,
            'estab_csrf_require_post($_SERVER, $_POST)'
        )
        && substr_count($pageSource, 'method="post" action="users.php"') >= 7
        && str_contains($pageSource, "'grant_extra_function'")
        && str_contains($pageSource, "'revoke_extra_function'")
        && substr_count(
            $pageSource,
            'estab_password_policy_load($connection)'
        ) >= 3
        && substr_count(
            $pageSource,
            'estab_user_admin_validate_password('
        ) >= 2
        && str_contains($pageSource, 'estab_user_admin_function_roles(')
        && substr_count(
            $pageSource,
            'estab_function_identity_display_name('
        ) >= 2
        && str_contains($pageSource, 'estab_function_display_name(')
        && str_contains($pageSource, 'estab_user_admin_create_account(')
        && str_contains($pageSource, 'estab_user_admin_reassign(')
        && str_contains($pageSource, 'estab_user_admin_grant_extra_function(')
        && str_contains($pageSource, 'estab_user_admin_revoke_extra_function(')
        && str_contains($pageSource, 'name="expected_primary_function"')
        && str_contains($pageSource, 'name="expected_primary_role"')
        && str_contains($pageSource, 'name="expected_extra_absent"')
        && str_contains(
            $pageSource,
            'name="expected_extra_functions_revision"'
        )
        && str_contains($pageSource, 'name="expected_granted_at"')
        && str_contains($pageSource, 'name="expected_granted_by"')
        && str_contains($pageSource, 'name="confirm_extra_function"')
        && str_contains($pageSource, 'data-estab-extra-function-invalid')
        && str_contains($pageSource, 'Nicht mehr gültig · keine Berechtigung')
        && str_contains($pageSource, 'nur im lockeren')
        && str_contains($pageSource, 'Im strengen Modus')
        && str_contains($pageSource, 'data-estab-assignment-orphaned')
        && str_contains($pageSource, 'Zuordnung nicht mehr gültig')
        && str_contains(
            $pageSource,
            'die Anmeldung bleibt aber'
        )
        && str_contains(
            $pageSource,
            'bis zur Zuweisung einer gültigen Funktion gesperrt'
        )
        && str_contains($pageSource, '<?php if (!$manageable): ?>')
        && substr_count($pageSource, '<?php if ($manageable &&') >= 2
        && str_contains(
            $pageSource,
            '$manageable && is_array($passwordPolicy)'
        )
        && !preg_match(
            '/method="get"[^>]*admin_action|admin_action[^>]*method="get"/i',
            $pageSource
        )
        && str_contains($pageSource, "header('Location: users.php', true, 303)")
        && !str_contains($pageSource, 'Location: users.php?'),
    'administrative writes are not Basic-Auth, POST, CSRF and PRG bound'
);
$passwordInputs = preg_match_all(
    '/<input[^>]+type="password"[^>]*>/i',
    $pageSource,
    $passwordInputMatches
);
$assert(
    $passwordInputs === 4
        && substr_count($pageSource, 'autocomplete="new-password"') === 4
        && !preg_match(
            '/<input[^>]+type="password"[^>]+value=/i',
            $pageSource
        )
        && str_contains($pageSource, "unset(\n                    \$_POST['new_password']")
        && !str_contains($pageSource, '$_GET'),
    'password cleartext can be reflected, retained or placed in a URL'
);

$blockedCheckPosition = strpos(
    $loginSource,
    'estab_auth_account_is_blocked ($dbUser)'
);
$passwordCheckPosition = strpos(
    $loginSource,
    'if (!$nameMatches || !$passwordCheck ["valid"])'
);
$assert(
    $blockedCheckPosition !== false
        && $passwordCheckPosition !== false
        && $blockedCheckPosition > $passwordCheckPosition,
    'blocked login is not rejected after credential verification'
);
$assert(
    substr_count($authSource, '`estab_gesperrt`') >= 2
        && str_contains(
            $authSource,
            '|| !estab_auth_presence_has_session($storedUser, $now)'
        ),
    'authoritative session validation ignores administrative blocking'
);

$assert(
    str_contains($migrationSource, '`estab_gesperrt`')
        && str_contains($migrationSource, 'TINYINT UNSIGNED NOT NULL DEFAULT 0')
        && str_contains($migrationSource, 'information_schema.columns')
        && str_contains($migrationSource, 'incompatible pre-existing')
        && str_contains($migrationSource, 'NOT IN (0, 1)')
        && !str_contains(
            (string) file_get_contents($root . '/docker/db/init/10-schema.sql'),
            '`estab_gesperrt`'
        ),
    'blocking migration is not resumable, collision-aware or baseline-safe'
);
$assert(
    str_contains($dockerfile, '4fadm/users.php')
        && str_contains($dockerfile, 'COPY app/*.php ./app/')
        && str_contains($runtimeVerifier, '4fadm/users.php')
        && str_contains($runtimeVerifier, 'app/user_admin.php')
        && str_contains($runtimeVerifier, 'app/assignment.php'),
    'container runtime omits the user administration'
);
$assert(
    str_contains($dashboardSource, "'key' => 'users'")
        && str_contains($dashboardSource, "'href' => 'users.php'"),
    'administration dashboard does not expose the user administration'
);
$assert(
    str_contains($provisionSource, 'estab_user_admin_create_account(')
        && str_contains($provisionSource, 'estab_user_admin_reassign(')
        && str_contains($provisionSource, 'estab_user_admin_reset_password(')
        && str_contains($provisionSource, "'ci-provisioner'")
        && str_contains(
            $provisionSource,
            "getenv('ESTAB_TEST_PROVISION_ALLOW_MUTATION') !== 'true'"
        )
        && str_contains($provisionSource, "str_starts_with(\$project, 'estab_ci_')")
        && str_contains($provisionShell, 'estab_ci | estab_ci_*')
        && str_contains(
            $integrationCi,
            'export ESTAB_ALLOW_SELF_REGISTRATION=false'
        ),
    'integration accounts bypass the guarded administrative provisioning boundary'
);
foreach ([
    'tests/integration/dynamic_tables.php',
    'tests/integration/logbooks_http.sh',
    'tests/integration/categories_http.sh',
    'tests/integration/message_workflow_http.sh',
    'tests/browser/headless_ui.py',
] as $existingAccountFixture) {
    $fixtureSource = file_get_contents($root . '/' . $existingAccountFixture);
    $assert(
        is_string($fixtureSource)
            && !str_contains($fixtureSource, 'login_flow=new')
            && !str_contains($fixtureSource, "['login_flow' => 'new']"),
        $existingAccountFixture
            . ' still depends on positive public self-registration'
    );
}

printf(
    "User administration security: OK (%d assertions)\n",
    $assertions
);
