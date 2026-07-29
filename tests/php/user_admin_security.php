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
    [str_repeat('a', 256), str_repeat('a', 256)],
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
$assert(
    $listSource !== ''
        && str_contains($listSource, '`estab_gesperrt`')
        && !str_contains($listSource, '`password`')
        && !str_contains($listSource, '`sid`')
        && !str_contains($listSource, '`ip`'),
    'account list reads credential or session material'
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
        && str_contains($resetSource, 'password_hash(')
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
    $createSource !== ''
        && str_contains($createSource, 'estab_user_admin_validate_assignment')
        && str_contains($createSource, 'password_hash(')
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
    str_contains($pageSource, 'estab_admin_require_http_auth($_SERVER)')
        && str_contains(
            $pageSource,
            'estab_csrf_require_post($_SERVER, $_POST)'
        )
        && substr_count($pageSource, 'method="post" action="users.php"') >= 5
        && str_contains($pageSource, "['create', 'reassign', 'block', 'unblock', 'reset_password']")
        && str_contains($pageSource, 'estab_user_admin_function_roles(')
        && str_contains($pageSource, 'estab_user_admin_create_account(')
        && str_contains($pageSource, 'estab_user_admin_reassign(')
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
        && substr_count(
            $pageSource,
            '<?php if ($manageable): ?>'
        ) >= 1
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
            '|| estab_auth_account_is_blocked($storedUser)'
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
