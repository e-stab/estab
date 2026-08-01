<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/self_registration.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throwsInput = static function (callable $operation): bool {
    try {
        $operation();
    } catch (EstabSelfRegistrationInputException) {
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
        $start = strpos($source, 'function ' . $function . ' (');
    }
    if ($start === false) {
        return '';
    }
    $next = strpos($source, "\nfunction ", $start + 10);
    return $next === false
        ? substr($source, $start)
        : substr($source, $start, $next - $start);
};
$policy = static function (
    string $mode,
    ?string $deadline = null,
    string $current = '2026-08-01 12:00:00.000000',
    int $revision = 3
): array {
    return [
        'singleton_id' => 1,
        'mode' => $mode,
        'enabled_until_utc' => $deadline,
        'revision' => $revision,
        'updated_at' => '2026-08-01 11:00:00.000000',
        'updated_by' => 'estab-admin',
        'current_utc' => $current,
    ];
};

$assert(
    estab_self_registration_defaults() === [
        'singleton_id' => 1,
        'mode' => ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT,
        'enabled_until_utc' => null,
        'revision' => 0,
        'updated_at' => '',
        'updated_by' => 'migration-114',
        'current_utc' => '',
    ],
    'self-registration defaults no longer preserve the upgrade-compatible state'
);
$assert(
    ESTAB_SELF_REGISTRATION_ALLOWED_DURATIONS
        === [15, 30, 60, 120, 240, 480, 720, 1440],
    'operator-facing self-registration durations changed without a contract update'
);

foreach ([
    ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT,
    ESTAB_SELF_REGISTRATION_MODE_DISABLED,
    ESTAB_SELF_REGISTRATION_MODE_PERMANENT,
    ESTAB_SELF_REGISTRATION_MODE_UNTIL,
] as $mode) {
    $assert(
        estab_self_registration_mode($mode) === $mode,
        'canonical self-registration mode was rejected: ' . $mode
    );
}
$assert(
    $throwsInput(
        static fn (): string => estab_self_registration_mode(
            ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT,
            false
        )
    ),
    'administrative configuration can restore the environment fallback'
);
foreach (['', 'disabled', 'ENABLED', 'UNTIL ', 1, ['DISABLED'], null] as $mode) {
    $assert(
        $throwsInput(static fn (): string => estab_self_registration_mode($mode)),
        'ambiguous self-registration mode was accepted'
    );
}

$microsecond = estab_self_registration_datetime(
    '2026-08-01 12:34:56.123456',
    'Testzeit'
);
$second = estab_self_registration_datetime(
    '2026-08-01 12:34:56',
    'Testzeit'
);
$assert(
    $microsecond->getTimezone()->getName() === 'UTC'
        && $microsecond->format('Y-m-d H:i:s.u')
            === '2026-08-01 12:34:56.123456'
        && $second->format('Y-m-d H:i:s.u')
            === '2026-08-01 12:34:56.000000',
    'canonical database timestamps are not parsed as UTC with microseconds'
);
foreach ([
    '',
    '2026-02-30 12:00:00.000000',
    '2026-08-01T12:00:00Z',
    '2026-08-01 12:00:00.1',
    '2026-08-01 12:00:00.000000+00:00',
    ['2026-08-01 12:00:00.000000'],
    null,
] as $timestamp) {
    $assert(
        $throwsRuntime(
            static fn (): DateTimeImmutable =>
                estab_self_registration_datetime($timestamp, 'Testzeit')
        ),
        'non-canonical self-registration timestamp was accepted'
    );
}

foreach ([
    $policy(ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT),
    $policy(ESTAB_SELF_REGISTRATION_MODE_DISABLED),
    $policy(ESTAB_SELF_REGISTRATION_MODE_PERMANENT),
    $policy(
        ESTAB_SELF_REGISTRATION_MODE_UNTIL,
        '2026-08-01 12:15:00.000000'
    ),
] as $row) {
    $normalised = estab_self_registration_normalize_row($row);
    $assert(
        $normalised['mode'] === $row['mode']
            && $normalised['enabled_until_utc']
                === $row['enabled_until_utc']
            && $normalised['updated_at']
                === '2026-08-01 11:00:00.000000'
            && $normalised['current_utc']
                === '2026-08-01 12:00:00.000000',
        'canonical self-registration row was not normalised deterministically'
    );
}
foreach ([
    array_replace($policy(ESTAB_SELF_REGISTRATION_MODE_DISABLED), [
        'singleton_id' => 2,
    ]),
    array_replace($policy(ESTAB_SELF_REGISTRATION_MODE_DISABLED), [
        'revision' => '-1',
    ]),
    array_replace($policy(ESTAB_SELF_REGISTRATION_MODE_DISABLED), [
        'updated_by' => "admin\nforged",
    ]),
    array_replace($policy(ESTAB_SELF_REGISTRATION_MODE_DISABLED), [
        'mode' => 'disabled',
    ]),
    array_replace($policy(ESTAB_SELF_REGISTRATION_MODE_DISABLED), [
        'enabled_until_utc' => '2026-08-01 13:00:00.000000',
    ]),
    $policy(ESTAB_SELF_REGISTRATION_MODE_UNTIL),
    $policy(
        ESTAB_SELF_REGISTRATION_MODE_UNTIL,
        '2026-08-01T13:00:00Z'
    ),
    array_replace($policy(ESTAB_SELF_REGISTRATION_MODE_DISABLED), [
        'current_utc' => 'not-a-date',
    ]),
] as $row) {
    $assert(
        $throwsRuntime(
            static fn (): array => estab_self_registration_normalize_row($row)
        ),
        'malformed self-registration database row did not fail closed'
    );
}

$environmentBefore = getenv('ESTAB_ALLOW_SELF_REGISTRATION');
try {
    putenv('ESTAB_ALLOW_SELF_REGISTRATION=false');
    $assert(
        !estab_self_registration_is_allowed(
            $policy(ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT)
        ),
        'disabled environment compatibility state was ignored'
    );
    putenv('ESTAB_ALLOW_SELF_REGISTRATION=true');
    $assert(
        estab_self_registration_is_allowed(
            $policy(ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT)
        ),
        'enabled environment compatibility state was ignored'
    );
    $assert(
        !estab_self_registration_is_allowed(
            $policy(ESTAB_SELF_REGISTRATION_MODE_DISABLED)
        ),
        'authoritative disabled mode was overridden by the environment'
    );
    putenv('ESTAB_ALLOW_SELF_REGISTRATION=false');
    $assert(
        estab_self_registration_is_allowed(
            $policy(ESTAB_SELF_REGISTRATION_MODE_PERMANENT)
        ),
        'authoritative permanent mode was overridden by the environment'
    );
} finally {
    if ($environmentBefore === false) {
        putenv('ESTAB_ALLOW_SELF_REGISTRATION');
    } else {
        putenv('ESTAB_ALLOW_SELF_REGISTRATION=' . $environmentBefore);
    }
}

$oneMicrosecondFuture = $policy(
    ESTAB_SELF_REGISTRATION_MODE_UNTIL,
    '2026-08-01 12:00:00.000001'
);
$exactBoundary = $policy(
    ESTAB_SELF_REGISTRATION_MODE_UNTIL,
    '2026-08-01 12:00:00.000000'
);
$oneMicrosecondPast = $policy(
    ESTAB_SELF_REGISTRATION_MODE_UNTIL,
    '2026-08-01 11:59:59.999999'
);
$assert(
    estab_self_registration_is_allowed($oneMicrosecondFuture)
        && estab_self_registration_effective($oneMicrosecondFuture),
    'timed self-registration closed before its exact UTC deadline'
);
$assert(
    !estab_self_registration_is_allowed($exactBoundary)
        && !estab_self_registration_effective($exactBoundary)
        && !estab_self_registration_is_allowed($oneMicrosecondPast),
    'timed self-registration remained open at or after its UTC deadline'
);
$futureStatus = estab_self_registration_status(
    $policy(
        ESTAB_SELF_REGISTRATION_MODE_UNTIL,
        '2026-08-01 12:15:00.000000'
    )
);
$expiredStatus = estab_self_registration_status($exactBoundary);
$assert(
    ($futureStatus['effective'] ?? null) === true
        && ($futureStatus['state'] ?? null)
            === ESTAB_SELF_REGISTRATION_MODE_UNTIL
        && ($futureStatus['remaining_seconds'] ?? null) === 900
        && ($expiredStatus['effective'] ?? null) === false
        && ($expiredStatus['state'] ?? null) === 'EXPIRED'
        && ($expiredStatus['remaining_seconds'] ?? null) === 0,
    'timed self-registration status does not expose an exact effective state'
);

foreach (ESTAB_SELF_REGISTRATION_ALLOWED_DURATIONS as $minutes) {
    $assert(
        estab_self_registration_duration_minutes($minutes) === $minutes
            && estab_self_registration_duration_minutes((string) $minutes)
                === $minutes,
        'allowlisted self-registration duration was rejected'
    );
}
foreach ([0, -1, 1, 14, 16, 1441, 9999, '0', '-1', '015', '15.0', ' 15', '', ['15'], null] as $minutes) {
    $assert(
        $throwsInput(
            static fn (): int =>
                estab_self_registration_duration_minutes($minutes)
        ),
        'non-allowlisted self-registration duration was accepted'
    );
}
$assert(
    estab_self_registration_configuration([
        'mode' => ESTAB_SELF_REGISTRATION_MODE_DISABLED,
    ]) === ['mode' => ESTAB_SELF_REGISTRATION_MODE_DISABLED, 'duration_minutes' => null]
        && estab_self_registration_configuration([
            'mode' => ESTAB_SELF_REGISTRATION_MODE_PERMANENT,
            'duration_minutes' => '',
        ]) === ['mode' => ESTAB_SELF_REGISTRATION_MODE_PERMANENT, 'duration_minutes' => null]
        && estab_self_registration_configuration([
            'mode' => ESTAB_SELF_REGISTRATION_MODE_UNTIL,
            'duration_minutes' => '60',
        ]) === ['mode' => ESTAB_SELF_REGISTRATION_MODE_UNTIL, 'duration_minutes' => 60],
    'administrative self-registration configuration is not canonical'
);
foreach ([
    [],
    ['mode' => ESTAB_SELF_REGISTRATION_MODE_ENVIRONMENT],
    ['mode' => ESTAB_SELF_REGISTRATION_MODE_UNTIL],
    [
        'mode' => ESTAB_SELF_REGISTRATION_MODE_DISABLED,
        'duration_minutes' => '60',
    ],
    [
        'mode' => ESTAB_SELF_REGISTRATION_MODE_PERMANENT,
        'duration_minutes' => [''],
    ],
] as $configuration) {
    $assert(
        $throwsInput(
            static fn (): array =>
                estab_self_registration_configuration($configuration)
        ),
        'ambiguous administrative self-registration configuration was accepted'
    );
}

$assert(
    estab_self_registration_revision(0) === 0
        && estab_self_registration_revision('0') === 0
        && estab_self_registration_revision('42') === 42,
    'valid self-registration revision was rejected'
);
foreach (['00', '01', '-1', '+1', '1.0', '', '9223372036854775808', ['1'], null] as $revision) {
    $assert(
        $throwsInput(
            static fn (): int => estab_self_registration_revision($revision)
        ),
        'ambiguous or overflowing self-registration revision was accepted'
    );
}
$assert(
    estab_self_registration_actor('estab-admin') === 'estab-admin'
        && estab_self_registration_actor(null) === 'unknown'
        && estab_self_registration_actor('') === 'unknown'
        && estab_self_registration_actor("admin\nforged") === 'unknown'
        && estab_self_registration_actor(str_repeat('a', 129)) === 'unknown'
        && estab_self_registration_actor("\xFF") === 'unknown',
    'self-registration audit actor validation is unsafe'
);

$before = $policy(ESTAB_SELF_REGISTRATION_MODE_DISABLED, null, '2026-08-01 12:00:00.000000', 7);
$after = $policy(
    ESTAB_SELF_REGISTRATION_MODE_UNTIL,
    '2026-08-01 13:00:00.000000',
    '2026-08-01 12:00:00.000000',
    8
);
$auditJson = estab_self_registration_audit_details(
    $before,
    $after,
    'estab-admin',
    '192.0.2.45'
);
$audit = json_decode($auditJson, true, 8, JSON_THROW_ON_ERROR);
$assert(
    ($audit['version'] ?? null) === 1
        && ($audit['action'] ?? null)
            === 'self_registration_policy_updated'
        && ($audit['admin'] ?? null) === 'estab-admin'
        && ($audit['remote_address'] ?? null) === '192.0.2.45'
        && ($audit['before']['mode'] ?? null)
            === ESTAB_SELF_REGISTRATION_MODE_DISABLED
        && ($audit['before']['revision'] ?? null) === 7
        && ($audit['before']['effective'] ?? null) === false
        && ($audit['after']['mode'] ?? null)
            === ESTAB_SELF_REGISTRATION_MODE_UNTIL
        && ($audit['after']['enabled_until_utc'] ?? null)
            === '2026-08-01 13:00:00.000000'
        && ($audit['after']['revision'] ?? null) === 8
        && ($audit['after']['effective'] ?? null) === true,
    'self-registration policy audit omits its non-secret before/after state'
);
$assert(
    !preg_match(
        '/password|kennwort|credential|csrf|session|cookie|authorization/i',
        $auditJson
    )
        && !str_contains($auditJson, 'must-never-be-audited')
        && json_decode(
            estab_self_registration_audit_details(
                $before,
                $after,
                'estab-admin',
                'not-an-ip'
            ),
            true,
            8,
            JSON_THROW_ON_ERROR
        )['remote_address'] === '',
    'self-registration policy audit leaks secrets or accepts a forged address'
);

$lockName = estab_self_registration_lock_name('estab');
$assert(
    $lockName === estab_self_registration_lock_name('estab')
        && $lockName !== estab_self_registration_lock_name('estab_other')
        && strlen($lockName) <= 64
        && str_starts_with($lockName, 'estab:self-registration:'),
    'self-registration advisory-lock name is unstable or exceeds MariaDB limits'
);

$root = dirname(__DIR__, 2);
$domainSource = file_get_contents($root . '/app/self_registration.php');
$loginSource = file_get_contents($root . '/4fach/data_hndl.php');
$rootSource = file_get_contents($root . '/index.php');
$mainSource = file_get_contents($root . '/4fach/mainindex.php');
$toolsSource = file_get_contents($root . '/4fach/tools.php');
$controllerSource = @file_get_contents($root . '/4fadm/self_registration.php');
$dockerfile = file_get_contents($root . '/Dockerfile');
$runtimeVerifier = file_get_contents(
    $root . '/docker/app/verify-runtime-surface.sh'
);
$handlerIntegration = @file_get_contents(
    $root . '/tests/integration/self_registration_handler.php'
);
$integrationCi = @file_get_contents($root . '/tests/integration/ci.sh');
foreach ([
    $domainSource,
    $loginSource,
    $rootSource,
    $mainSource,
    $toolsSource,
    $dockerfile,
    $runtimeVerifier,
    $handlerIntegration,
    $integrationCi,
] as $source) {
    $assert(is_string($source), 'self-registration runtime source is unreadable');
}

$loadSource = $functionSource((string) $domainSource, 'estab_self_registration_load');
$decisionSource = $functionSource(
    (string) $domainSource,
    'estab_self_registration_is_allowed'
);
$updateSource = $functionSource(
    (string) $domainSource,
    'estab_self_registration_update'
);
$insertSource = $functionSource(
    (string) $domainSource,
    'estab_self_registration_insert_user_if_allowed'
);
$loginFunction = $functionSource((string) $loginSource, 'check_save_user');
$assert(
    $loadSource !== ''
        && str_contains($loadSource, 'UTC_TIMESTAMP(6) AS `current_utc`')
        && str_contains($loadSource, "\$forUpdate ? ' FOR UPDATE' : ''")
        && str_contains($decisionSource, '$deadline > $current')
        && !str_contains($decisionSource, '$deadline >= $current'),
    'self-registration decision is not bound to exact database UTC'
);
$assert(
    $updateSource !== ''
        && str_contains($updateSource, 'estab_self_registration_acquire_lock')
        && str_contains($updateSource, '$connection->begin_transaction()')
        && str_contains($updateSource, 'estab_self_registration_load($connection, true)')
        && str_contains($updateSource, 'TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP(6))')
        && str_contains($updateSource, '`revision` = `revision` + 1')
        && str_contains($updateSource, 'estab_self_registration_audit_details(')
        && strpos($updateSource, 'estab_auth_log_event(')
            < strrpos($updateSource, '$connection->commit()')
        && str_contains($updateSource, '$connection->rollback()')
        && str_contains($updateSource, 'estab_self_registration_release_lock('),
    'self-registration admin update is not revisioned, audited and atomic'
);
$assert(
    $insertSource !== ''
        && str_contains($insertSource, "'INSERT INTO '")
        && str_contains($insertSource, "' SELECT ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(6)'")
        && str_contains($insertSource, "`mode` = \\'ENVIRONMENT\\'")
        && str_contains($insertSource, "`mode` = \\'PERMANENT\\'")
        && str_contains($insertSource, "`mode` = \\'UNTIL\\'")
        && str_contains(
            $insertSource,
            '`enabled_until_utc` > UTC_TIMESTAMP(6)'
        )
        && !str_contains(
            $insertSource,
            '`enabled_until_utc` >= UTC_TIMESTAMP(6)'
        )
        && str_contains($insertSource, '$statement->affected_rows === 0')
        && str_contains($insertSource, '$statement->affected_rows !== 1')
        && !str_contains($insertSource, '->commit(')
        && !str_contains($insertSource, '->commit ('),
    'account insert lacks an atomic policy and exact-expiry SQL guard'
);

$assignmentLockPosition = strpos(
    $loginFunction,
    'estab_assignment_acquire_policy_lock ('
);
$registrationLockPosition = strpos(
    $loginFunction,
    'estab_self_registration_acquire_lock ('
);
$passwordLockPosition = strpos(
    $loginFunction,
    'estab_password_policy_acquire_lock ('
);
$accountLockPosition = strpos(
    $loginFunction,
    'estab_login_acquire_account_lock ('
);
$guardedInsertPosition = strpos(
    $loginFunction,
    'estab_self_registration_insert_user_if_allowed ('
);
$registrationSchemaPosition = $guardedInsertPosition === false
    ? false
    : strpos(
        $loginFunction,
        '$dbaccess->create_user_table',
        $guardedInsertPosition
    );
$registrationAuditPosition = strpos(
    $loginFunction,
    '"self_registration"'
);
$registrationCommitPosition = $registrationAuditPosition === false
    ? false
    : strpos(
        $loginFunction,
        '$connection->commit ()',
        $registrationAuditPosition
    );
$assert(
    $loginFunction !== ''
        && $assignmentLockPosition !== false
        && $registrationLockPosition !== false
        && $passwordLockPosition !== false
        && $accountLockPosition !== false
        && $assignmentLockPosition < $registrationLockPosition
        && $registrationLockPosition < $passwordLockPosition
        && $passwordLockPosition < $accountLockPosition
        && str_contains(
            $loginFunction,
            'estab_self_registration_load ($connection)'
        )
        && str_contains(
            $loginFunction,
            'estab_self_registration_is_allowed ($selfRegistrationPolicy)'
        )
        && str_contains(
            $loginFunction,
            'estab_self_registration_release_lock ('
        ),
    'registration does not hold assignment, gate, password and account locks in the fixed order'
);
$assert(
    $guardedInsertPosition !== false
        && $registrationSchemaPosition !== false
        && $registrationAuditPosition !== false
        && $registrationCommitPosition !== false
        && $guardedInsertPosition < $registrationSchemaPosition
        && $registrationSchemaPosition < $registrationAuditPosition
        && $registrationAuditPosition < $registrationCommitPosition
        && !str_contains($loginFunction, 'estab_auth_insert_user (')
        && str_contains($loginFunction, 'if (!$registrationInserted)')
        && str_contains(
            $loginFunction,
            'Es wurde kein Konto angelegt.'
        ),
    'registration can create dynamic tables, audit or commit before the atomic policy-guarded insert'
);
$assert(
    str_contains((string) $handlerIntegration, 'check_save_user($request, $loginError)')
        && str_contains(
            (string) $handlerIntegration,
            'ESTAB_SELF_REGISTRATION_MODE_PERMANENT'
        )
        && str_contains(
            (string) $handlerIntegration,
            'ESTAB_SELF_REGISTRATION_MODE_UNTIL'
        )
        && str_contains((string) $handlerIntegration, 'proc_open(')
        && str_contains(
            (string) $handlerIntegration,
            'estab_login_acquire_account_lock($connection, $accountLock)'
        )
        && str_contains((string) $handlerIntegration, 'SELECT IS_USED_LOCK(?)')
        && str_contains(
            (string) $handlerIntegration,
            'selfreg_handler_test_assert_rejected('
        )
        && str_contains(
            (string) $integrationCi,
            'estab_self_registration_handler_ci_test'
        )
        && str_contains(
            (string) $integrationCi,
            'tests/integration/self_registration_handler.php'
        ),
    'CI lacks a real persistent-policy registration-handler and expiry-race contract'
);

foreach ([
    'public overview' => (string) $rootSource,
    'login UI' => (string) $mainSource,
    'public account chooser' => (string) $toolsSource,
] as $consumerName => $consumerSource) {
    $assert(
        str_contains($consumerSource, 'estab_self_registration_load')
            && str_contains(
                $consumerSource,
                'estab_self_registration_is_allowed'
            )
            && !str_contains(
                $consumerSource,
                'estab_auth_self_registration_allowed'
            ),
        $consumerName
            . ' bypasses the persistent self-registration policy'
    );
}
$assert(
    !str_contains(
        $loginFunction,
        'estab_auth_self_registration_allowed'
    ),
    'account controller still treats the environment as authoritative outside the compatibility policy'
);

$adminAuthPosition = is_string($controllerSource)
    ? strpos($controllerSource, 'estab_admin_require_http_auth($_SERVER)')
    : false;
$contentHeaderPosition = is_string($controllerSource)
    ? strpos($controllerSource, "header('Content-Type:")
    : false;
$csrfPosition = is_string($controllerSource)
    ? strpos($controllerSource, 'estab_csrf_require_post($_SERVER, $_POST)')
    : false;
$actionPosition = is_string($controllerSource)
    ? strpos($controllerSource, "\$action = \$_POST['admin_action']")
    : false;
$updatePosition = is_string($controllerSource)
    ? strpos($controllerSource, 'estab_self_registration_update(')
    : false;
$redirectPosition = is_string($controllerSource)
    ? strpos(
        $controllerSource,
        "header('Location: self_registration.php', true, 303)"
    )
    : false;
$assert(
    is_string($controllerSource)
        && $adminAuthPosition !== false
        && $contentHeaderPosition !== false
        && $adminAuthPosition < $contentHeaderPosition
        && $csrfPosition !== false
        && $actionPosition !== false
        && $csrfPosition < $actionPosition
        && $updatePosition !== false
        && $redirectPosition !== false
        && $updatePosition < $redirectPosition
        && str_contains($controllerSource, "\$requestMethod === 'POST'")
        && str_contains($controllerSource, "\$requestMethod !== 'GET'")
        && str_contains($controllerSource, "header('Allow: GET, POST')")
        && str_contains(
            $controllerSource,
            "['disable', 'enable_permanent', 'enable_temporary']"
        )
        && str_contains($controllerSource, 'estab_self_registration_revision(')
        && str_contains($controllerSource, 'estab_self_registration_update('),
    'self-registration controller lacks Basic Auth, CSRF, action allowlist, method or PRG boundaries'
);
$assert(
    substr_count($controllerSource, 'name="expected_revision"') === 3
        && substr_count($controllerSource, '<?= estab_csrf_field() ?>') === 3
        && substr_count($controllerSource, 'name="confirm_activation"') === 2
        && str_contains(
            $controllerSource,
            "(\$_POST['confirm_activation'] ?? null) !== '1'"
        )
        && str_contains(
            $controllerSource,
            'auch für Funktionen mit weitreichenden Fachrechten'
        )
        && str_contains(
            $controllerSource,
            'foreach (ESTAB_SELF_REGISTRATION_ALLOWED_DURATIONS as $minutes)'
        )
        && str_contains(
            $controllerSource,
            "estab_self_registration_actor(\$_SERVER['REMOTE_USER'] ?? null)"
        )
        && !str_contains($controllerSource, "\$_POST['updated_by']")
        && !str_contains($controllerSource, "\$_POST['enabled_until_utc']")
        && !str_contains($controllerSource, '$_GET['),
    'self-registration controller accepts forged revision, actor, deadline or GET mutation state'
);
$assert(
    str_contains(
        $controllerSource,
        '$state === ESTAB_SELF_REGISTRATION_MODE_UNTIL'
    )
        && str_contains($controllerSource, "\$policy['enabled_until_utc']")
        && str_contains($controllerSource, "\$policy['current_utc']")
        && str_contains(
            $controllerSource,
            'data-estab-self-registration-refresh-ms='
        )
        && str_contains(
            $controllerSource,
            'data-estab-self-registration-expiry-refresh'
        )
        && str_contains($controllerSource, 'Number.isSafeInteger(delay)')
        && str_contains($controllerSource, 'delay > maximumDelay')
        && str_contains(
            $controllerSource,
            "performance.getEntriesByType('navigation')"
        )
        && str_contains($controllerSource, 'navigationEntry.responseStart')
        && str_contains($controllerSource, 'delay - Math.ceil(responseElapsed)')
        && str_contains(
            $controllerSource,
            'window.setTimeout(reload, remainingDelay)'
        )
        && str_contains(
            $controllerSource,
            "window.location.replace('self_registration.php')"
        )
        && !str_contains($controllerSource, 'window.location.reload()')
        && str_contains(
            $controllerSource,
            "window.addEventListener('focus', reloadWhenExpired)"
        )
        && str_contains(
            $controllerSource,
            "window.addEventListener('pageshow', reloadWhenExpired)"
        )
        && str_contains(
            $controllerSource,
            "document.addEventListener('visibilitychange'"
        ),
    'timed self-registration status does not refresh safely at database expiry'
);
$assert(
    str_contains((string) $dockerfile, '4fadm/self_registration.php')
        && str_contains(
            (string) $runtimeVerifier,
            '4fadm/self_registration.php'
        )
        && str_contains(
            (string) $runtimeVerifier,
            'app/self_registration.php'
        ),
    'runtime image omits the self-registration policy boundary'
);

printf(
    "Self-registration security: OK (%d assertions)\n",
    $assertions
);
