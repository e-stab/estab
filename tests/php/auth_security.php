<?php

require_once __DIR__ . '/../../app/auth.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$confEmpf = [
    1 => ['fkt' => 'S1', 'rolle' => 'Stab'],
    2 => ['fkt' => 'Si', 'rolle' => 'Stab'],
    3 => ['fkt' => 'A/W', 'rolle' => 'Fernmelder'],
    4 => ['fkt' => 'THW', 'rolle' => 'FB'],
];

$valid = estab_auth_validate_login([
    'benutzer' => '  Müller, Ada  ',
    'kuerzel' => ' ADA ',
    'funktion' => 'S1',
    'kennwort1' => 'legacy-compatible',
], $confEmpf);
$assert($valid['valid'] === true, 'valid identity accepted');
$assert($valid['data']['benutzer'] === 'Müller, Ada', 'name trimmed');
$assert($valid['data']['kuerzel'] === 'ada', 'code normalised');
$assert($valid['data']['rolle'] === 'Stab', 'role derived from configuration');
$freshRoleValidation = estab_auth_validate_login_with_roles([
    'benutzer' => 'Müller, Ada',
    'kuerzel' => 'ada',
    'funktion' => 'S1',
    'kennwort1' => 'legacy-compatible',
], ['S1' => 'FB']);
$assert(
    $freshRoleValidation['valid'] === true
        && $freshRoleValidation['data']['rolle'] === 'FB',
    'database-authoritative role map is not accepted by the shared validator'
);

$unknownFunction = estab_auth_validate_login([
    'benutzer' => 'Ada Müller',
    'kuerzel' => 'ada',
    'funktion' => 'ROOT',
    'kennwort1' => 'secret',
], $confEmpf);
$assert($unknownFunction['valid'] === false, 'unknown function rejected');
$assert(in_array('funktion', $unknownFunction['errors'], true), 'function error identified');

$invalidIdentity = estab_auth_validate_login([
    'benutzer' => "Ada\nAdmin",
    'kuerzel' => '../ada',
    'funktion' => 'S1',
    'kennwort1' => "bad\0password",
], $confEmpf);
$assert($invalidIdentity['valid'] === false, 'control characters and unsafe code rejected');
$assert(in_array('benutzer', $invalidIdentity['errors'], true), 'name error identified');
$assert(in_array('kuerzel', $invalidIdentity['errors'], true), 'code error identified');
$assert(in_array('kennwort1', $invalidIdentity['errors'], true), 'password NUL rejected');
foreach ([
    ['field' => 'benutzer', 'value' => "Ada\n"],
    ['field' => 'benutzer', 'value' => "Ada\0"],
    ['field' => 'kuerzel', 'value' => "ada\r"],
    ['field' => 'kuerzel', 'value' => "ada\0"],
    ['field' => 'funktion', 'value' => "S1\n"],
    ['field' => 'funktion', 'value' => "S1\0"],
] as $rawControl) {
    $request = [
        'benutzer' => 'Ada',
        'kuerzel' => 'ada',
        'funktion' => 'S1',
        'kennwort1' => 'secret',
    ];
    $request[$rawControl['field']] = $rawControl['value'];
    $rawControlResult = estab_auth_validate_login($request, $confEmpf);
    $assert(
        $rawControlResult['valid'] === false
            && in_array(
                $rawControl['field'],
                $rawControlResult['errors'],
                true
            ),
        'raw control character disappeared during login normalisation'
    );
}

$legacy = estab_auth_verify_password('old-secret', 'old-secret');
$assert($legacy['valid'] === true, 'legacy plaintext accepted once');
$assert($legacy['migrated'] === true, 'legacy plaintext requests migration');
$assert(is_string($legacy['replacement']), 'migration produces a hash');
$assert(password_verify('old-secret', $legacy['replacement']), 'migration hash verifies');
$assert($legacy['replacement'] !== 'old-secret', 'plaintext is not retained');

$legacyWrong = estab_auth_verify_password('wrong', 'old-secret');
$assert($legacyWrong['valid'] === false, 'wrong legacy password rejected');
$assert($legacyWrong['replacement'] === null, 'failed password has no migration hash');

$modernHash = password_hash('modern-secret', PASSWORD_DEFAULT);
$modern = estab_auth_verify_password('modern-secret', $modernHash);
$assert($modern['valid'] === true, 'modern hash verifies');
$assert($modern['replacement'] === null, 'current modern hash is retained');
$assert(estab_auth_verify_password('wrong', $modernHash)['valid'] === false, 'wrong modern password rejected');

$assert(estab_parse_bool('YES', false) === true, 'safe true boolean parsed');
$assert(estab_parse_bool('off', true) === false, 'safe false boolean parsed');
$invalidBooleanRejected = false;
try {
    estab_parse_bool('truthy typo', true);
} catch (InvalidArgumentException) {
    $invalidBooleanRejected = true;
}
$assert($invalidBooleanRejected, 'unknown boolean rejected');

$assert(estab_auth_remote_ip(['REMOTE_ADDR' => '2001:db8::1']) === '2001:db8::1', 'IPv6 peer accepted');
$assert(estab_auth_remote_ip(['REMOTE_ADDR' => 'not-an-ip']) === '', 'invalid peer rejected');
$forwarded = ['HTTP_X_FORWARDED_FOR' => '198.51.100.7, 2001:db8::2'];
$assert(estab_auth_forwarded_ip($forwarded, false) === '', 'proxy header ignored by default');
$assert(estab_auth_forwarded_ip($forwarded, true) === '198.51.100.7', 'validated trusted proxy chain accepted');
$assert(
    estab_auth_forwarded_ip(['HTTP_X_FORWARDED_FOR' => '198.51.100.7, injected'], true) === '',
    'invalid proxy chain rejected in full'
);

$token = estab_auth_identity_token([
    'benutzer' => 'Müller, Ada',
    'kuerzel' => 'ada',
    'funktion' => 'S1',
]);
$decoded = estab_auth_decode_identity_token($token, $confEmpf);
$assert($decoded === ['benutzer' => 'Müller, Ada', 'kuerzel' => 'ada', 'funktion' => 'S1'], 'POST prefill token round trip');
$assert(estab_auth_decode_identity_token('not+base64', $confEmpf) === null, 'malformed prefill token rejected');

$assert(
    estab_auth_login_flow(['login_flow' => 'existing']) === 'existing',
    'explicit existing-account flow rejected'
);
$assert(
    estab_auth_login_flow(['login_flow' => 'new']) === 'new',
    'explicit new-account flow rejected'
);
$assert(
    estab_auth_login_flow(['2teskennwort' => 'Yes']) === 'new'
        && estab_auth_login_flow(['2teskennwort' => 'No']) === 'existing',
    'legacy account-flow compatibility lost'
);
$assert(
    estab_auth_login_flow([
        'benutzer' => 'Ada Müller',
        'kuerzel' => 'ada001',
        'funktion' => 'S1',
        'kennwort1' => 'test-secret',
    ]) === 'existing',
    'historical one-password existing-account flow rejected'
);
foreach ([
    [],
    ['login_flow' => 'unknown'],
    ['login_flow' => ['existing']],
] as $invalidFlow) {
    $assert(estab_auth_login_flow($invalidFlow) === null, 'invalid account flow accepted');
}

$assert(
    estab_auth_assignment_allowed(['aktiv' => 1, 'funktion' => 'S1'], 'S1'),
    'active account rejected for its stored function'
);
$assert(
    !estab_auth_assignment_allowed(['aktiv' => 1, 'funktion' => 'S1'], 'A/W'),
    'active account accepted a request-selected role switch'
);
$assert(
    estab_auth_assignment_allowed(['aktiv' => 0, 'funktion' => 'S1'], 'S1'),
    'inactive account rejected for its stored function'
);
$assert(
    !estab_auth_assignment_allowed(['aktiv' => 0, 'funktion' => 'S1'], 'A/W'),
    'inactive account accepted a self-selected function'
);

$sessionIdentity = [
    'benutzer' => 'Müller, Ada',
    'kuerzel' => 'ada001',
    'funktion' => 'S1',
    'rolle' => 'Stab',
];
$storedSessionUser = $sessionIdentity + [
    'sid' => 'current-session-123',
    'aktiv' => '1',
];
$assert(
    estab_auth_account_matches_session(
        $storedSessionUser,
        $sessionIdentity,
        'current-session-123'
    ),
    'current authoritative account session rejected'
);
foreach ([
    ['sid' => 'superseding-session-456'],
    ['aktiv' => '0'],
    ['benutzer' => 'Andere Person'],
    ['kuerzel' => 'other1'],
    ['funktion' => 'S2'],
    ['rolle' => 'FB'],
] as $accountChange) {
    $assert(
        !estab_auth_account_matches_session(
            array_replace($storedSessionUser, $accountChange),
            $sessionIdentity,
            'current-session-123'
        ),
        'revoked or changed authoritative account session accepted'
    );
}
$assert(
    !estab_auth_account_matches_session(
        $storedSessionUser,
        $sessionIdentity,
        'older-session-000'
    ),
    'older browser SID accepted after a newer login'
);
foreach (['', 'contains space', str_repeat('a', 51), 'slash/not-allowed'] as $invalidSessionId) {
    $assert(
        !estab_auth_session_id_is_valid($invalidSessionId),
        'unsafe session ID accepted'
    );
}
$auditSessionId = 'audit-session-20260729';
$auditPassword = 'must-never-reach-the-audit';
$auditIdentity = [
    'benutzer' => 'Müller, Ada',
    'kuerzel' => 'ada001',
    'funktion' => 'S1',
    'rolle' => 'Stab',
    'password' => $auditPassword,
];
foreach (
    ['existing_login', 'session_refresh', 'self_registration'] as $auditAction
) {
    $auditJson = estab_auth_login_audit_details(
        $auditAction,
        $auditIdentity,
        $auditSessionId,
        '192.0.2.44'
    );
    $audit = json_decode($auditJson, true, 8, JSON_THROW_ON_ERROR);
    $assert(
        ($audit['version'] ?? null) === 1
            && ($audit['action'] ?? null) === $auditAction
            && ($audit['target'] ?? null) === 'ada001'
            && ($audit['session_reference'] ?? null)
                === 'sha256:' . hash('sha256', $auditSessionId)
            && ($audit['remote_address'] ?? null) === '192.0.2.44'
            && !str_contains($auditJson, $auditSessionId)
            && !str_contains($auditJson, $auditPassword),
        'login audit leaked a reusable session or credential'
    );
}
$invalidAuditRejected = false;
try {
    estab_auth_login_audit_details(
        'invented',
        $auditIdentity,
        $auditSessionId,
        '192.0.2.44'
    );
} catch (InvalidArgumentException) {
    $invalidAuditRejected = true;
}
$assert($invalidAuditRejected, 'unknown login audit action was accepted');
$revokedLocalSession = [
    'vStab_benutzer' => 'Müller, Ada',
    'vStab_kuerzel' => 'ada001',
    'vStab_funktion' => 'S1',
    'vStab_rolle' => 'Stab',
    'ROLLE' => 'Stab',
    'estab_csrf_token' => str_repeat('a', 64),
    'sw_data' => ['sensitive workflow state'],
];
estab_auth_invalidate_local_session($revokedLocalSession);
$assert(
    $revokedLocalSession === ['menue' => 'LOGIN'],
    'revocation retained authenticated or workflow-local state'
);

$registrationSetting = getenv('ESTAB_ALLOW_SELF_REGISTRATION');
putenv('ESTAB_ALLOW_SELF_REGISTRATION');
$assert(
    estab_auth_self_registration_allowed() === false,
    'self-registration is not disabled by default'
);
putenv('ESTAB_ALLOW_SELF_REGISTRATION=true');
$assert(estab_auth_self_registration_allowed() === true, 'enabled self-registration rejected');
putenv('ESTAB_ALLOW_SELF_REGISTRATION=false');
$assert(estab_auth_self_registration_allowed() === false, 'disabled self-registration ignored');
if ($registrationSetting === false) {
    putenv('ESTAB_ALLOW_SELF_REGISTRATION');
} else {
    putenv('ESTAB_ALLOW_SELF_REGISTRATION=' . $registrationSetting);
}

$loginController = file_get_contents(dirname(__DIR__, 2) . '/4fach/data_hndl.php');
$assert(is_string($loginController), 'login controller source readable');
$assert(
    substr_count($loginController, 'if ($login ["funktion"] != "A/W")') >= 2
        && !str_contains($loginController, '$wasInactive && $login ["funktion"] != "A/W"'),
    'active imported users do not reconcile their legacy dynamic tables on login'
);
$assert(
    str_contains($loginController, '$loginFlow === "new"')
        && str_contains($loginController, '$loginFlow === "existing"')
        && str_contains($loginController, 'estab_auth_assignment_allowed')
        && str_contains(
            $loginController,
            'estab_request_trusts_proxy_headers ($_SERVER)'
        )
        && !str_contains($loginController, 'errorwindow ("Benutzeranmeldung"'),
    'login controller does not preserve flow, assignment, proxy, or inline-error boundaries'
);
$assert(
    substr_count($loginController, 'session_regenerate_id (true)') === 2
        && substr_count($loginController, 'unset ($_SESSION ["estab_csrf_token"])') === 2
        && substr_count(
            $loginController,
            'estab_auth_session_id_is_valid (session_id ())'
        ) === 2,
    'both successful login paths must rotate and validate the session ID and clear the pre-authentication CSRF token'
);

$authSource = file_get_contents(dirname(__DIR__, 2) . '/app/auth.php');
$assert(is_string($authSource), 'authentication boundary source readable');
$assert(
    str_contains($authSource, 'estab_auth_fetch_session_user')
        && str_contains($authSource, 'estab_auth_account_matches_session')
        && str_contains($authSource, 'estab_auth_current_session_identity')
        && str_contains($authSource, 'estab_auth_invalidate_local_session')
        && str_contains($authSource, 'PHP_SAPI !== \'cli\'')
        && str_contains($authSource, '`sid`, `aktiv`'),
    'web session authentication is not bound to authoritative account state'
);
$updateUserSourceStart = strpos(
    $authSource,
    'function estab_auth_update_user('
);
$updateUserSourceEnd = $updateUserSourceStart === false
    ? false
    : strpos(
        $authSource,
        '/** Insert a self-registered account',
        $updateUserSourceStart
    );
$updateUserSource = $updateUserSourceStart !== false
    && $updateUserSourceEnd !== false
        ? substr(
            $authSource,
            $updateUserSourceStart,
            $updateUserSourceEnd - $updateUserSourceStart
        )
        : '';
$assert(
    $updateUserSource !== ''
        && !str_contains($updateUserSource, 'SET `funktion` = ?')
        && str_contains(
            $updateUserSource,
            'WHERE `kuerzel` = ? AND `funktion` = ?'
        )
        && str_contains($updateUserSource, '`estab_gesperrt` = 0'),
    'login can mutate or bypass the administrative function assignment'
);

$loginFunctionStart = strpos($loginController, 'function check_save_user ');
$loginFunctionEnd = strpos($loginController, '} // function save_user', $loginFunctionStart ?: 0);
$assert(
    $loginFunctionStart !== false && $loginFunctionEnd !== false,
    'atomic login function boundary not found'
);
$loginFunction = $loginFunctionStart !== false && $loginFunctionEnd !== false
    ? substr($loginController, $loginFunctionStart, $loginFunctionEnd - $loginFunctionStart)
    : '';
$accountLockPosition = strpos($loginFunction, 'estab_login_acquire_account_lock');
$policyLockPosition = strpos(
    $loginFunction,
    'estab_assignment_acquire_policy_lock'
);
$freshMapPosition = strpos(
    $loginFunction,
    'estab_assignment_function_roles'
);
$transactionPosition = strpos($loginFunction, '$connection->begin_transaction ()');
$accountLookupPosition = strpos($loginFunction, 'estab_auth_fetch_user');
$assert(
    $policyLockPosition !== false
        && $freshMapPosition !== false
        && $accountLockPosition !== false
        && $transactionPosition !== false
        && $accountLookupPosition !== false
        && $policyLockPosition < $freshMapPosition
        && $freshMapPosition < $accountLockPosition
        && $accountLockPosition < $transactionPosition
        && $transactionPosition < $accountLookupPosition
        && str_contains(
            $loginFunction,
            'estab_assignment_release_policy_lock'
        )
        && str_contains($loginFunction, 'estab_login_release_account_lock')
        && substr_count($loginFunction, '$connection->rollback ()') >= 2,
    'login does not hold fresh matrix policy before account/transaction locks'
);
$assert(
    substr_count($loginFunction, '$dbaccess->create_user_table') === 2
        && substr_count($loginFunction, '$connection->commit ()') === 2
        && substr_count($loginFunction, 'estab_login_write_audit') === 2
        && substr_count(
            $loginFunction,
            'estab_auth_login_audit_details ('
        ) === 2
        && str_contains($loginFunction, '"existing_login"')
        && str_contains($loginFunction, '"session_refresh"')
        && str_contains($loginFunction, '"self_registration"')
        && !str_contains($loginFunction, '.session_id ().";"')
        && substr_count($loginFunction, '$_SESSION ["vStab_benutzer"] =') === 2
        && !str_contains($loginFunction, 'protokolleintrag (')
        && !preg_match('/\\bor\\s+die\\s*\\(/', $loginFunction),
    'login paths do not commit schema, account, audit, and session in the required boundary'
);
$firstSchemaPosition = strpos($loginFunction, '$dbaccess->create_user_table');
$firstAccountWritePosition = strpos($loginFunction, 'estab_auth_update_user');
$firstCommitPosition = strpos($loginFunction, '$connection->commit ()');
$firstSessionPosition = strpos($loginFunction, '$_SESSION ["vStab_benutzer"] =');
$secondSchemaPosition = $firstSchemaPosition === false
    ? false
    : strpos($loginFunction, '$dbaccess->create_user_table', $firstSchemaPosition + 1);
$secondAccountWritePosition = strpos($loginFunction, 'estab_auth_insert_user');
$secondCommitPosition = $firstCommitPosition === false
    ? false
    : strpos($loginFunction, '$connection->commit ()', $firstCommitPosition + 1);
$secondSessionPosition = $firstSessionPosition === false
    ? false
    : strpos($loginFunction, '$_SESSION ["vStab_benutzer"] =', $firstSessionPosition + 1);
$assert(
    $firstSchemaPosition !== false
        && $firstAccountWritePosition !== false
        && $firstCommitPosition !== false
        && $firstSessionPosition !== false
        && $firstSchemaPosition < $firstAccountWritePosition
        && $firstAccountWritePosition < $firstCommitPosition
        && $firstCommitPosition < $firstSessionPosition
        && $secondSchemaPosition !== false
        && $secondAccountWritePosition !== false
        && $secondCommitPosition !== false
        && $secondSessionPosition !== false
        && $secondSchemaPosition < $secondAccountWritePosition
        && $secondAccountWritePosition < $secondCommitPosition
        && $secondCommitPosition < $secondSessionPosition,
    'an account or session can become active before dynamic schema readiness and commit'
);
$assert(
    str_contains($loginFunction, 'estab_login_clear_session_identity ();')
        && str_contains($loginFunction, 'technisch nicht abgeschlossen'),
    'technical login failures do not clear partial session identity'
);

$databaseOperations = file_get_contents(dirname(__DIR__, 2) . '/4fach/db_operation.php');
$assert(is_string($databaseOperations), 'dynamic database operations source readable');
$dynamicCreateStart = is_string($databaseOperations)
    ? strpos($databaseOperations, 'function create_user_table ')
    : false;
$dynamicCreateEnd = $dynamicCreateStart === false
    ? false
    : strpos($databaseOperations, 'function read_table ', $dynamicCreateStart);
$dynamicCreate = $dynamicCreateStart !== false && $dynamicCreateEnd !== false
    ? substr($databaseOperations, $dynamicCreateStart, $dynamicCreateEnd - $dynamicCreateStart)
    : '';
$assert(
    str_contains($databaseOperations, 'SELECT GET_LOCK(?, ?)')
        && str_contains($databaseOperations, 'SELECT RELEASE_LOCK(?)')
        && str_contains($dynamicCreate, 'acquire_dynamic_schema_lock')
        && str_contains($dynamicCreate, 'release_dynamic_schema_lock')
        && !preg_match('/\\bor\\s+die\\s*\\(/', $dynamicCreate),
    'dynamic schema DDL is not serialised or still terminates the login request with die()'
);

echo "authentication security: OK ({$assertions} assertions)\n";
