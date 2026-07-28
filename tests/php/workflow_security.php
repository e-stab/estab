<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/workflow.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(estab_workflow_public_login_request(['REQUEST_METHOD' => 'GET'], [], []), 'empty login page rejected');
foreach (['existing', 'new'] as $loginFlow) {
    $assert(
        estab_workflow_public_login_request(
            ['REQUEST_METHOD' => 'GET'],
            ['login_flow' => $loginFlow],
            []
        ),
        'safe GET account-flow selection rejected'
    );
}
$assert(
    estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'GET'],
        ['next' => 'incident-log'],
        []
    )
        && estab_workflow_public_login_request(
            ['REQUEST_METHOD' => 'GET'],
            ['login_flow' => 'existing', 'next' => 'tracking'],
            []
        ),
    'validated post-login destination was rejected'
);
$assert(
    !estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'GET'],
        ['next' => 'administration'],
        []
    )
        && !estab_workflow_public_login_request(
            ['REQUEST_METHOD' => 'GET'],
            ['next' => 'https://attacker.invalid'],
            []
        )
        && !estab_workflow_public_login_request(
            ['REQUEST_METHOD' => 'GET'],
            ['next' => ['incident-log']],
            []
        ),
    'untrusted post-login destination was accepted'
);
$assert(
    estab_workflow_public_login_request(['REQUEST_METHOD' => 'POST'], [], ['login' => 'Anmelden']),
    'login transition rejected'
);
foreach (['existing', 'new'] as $loginFlow) {
    $assert(
        estab_workflow_public_login_request(
            ['REQUEST_METHOD' => 'POST'],
            [],
            ['login_flow' => $loginFlow]
        ),
        'valid account-flow selection rejected'
    );
}
$assert(
    estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'POST'],
        [],
        ['login_flow' => 'new', 'next' => 'incident-log']
    ),
    'form-bound protected login destination was rejected'
);
$assert(
    estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'POST'],
        [],
        ['login_x' => '12', 'login_y' => '4']
    ),
    'real image-button login transition rejected'
);
foreach ([
    ['login_x' => '1'],
    ['login_x' => '-1', 'login_y' => '2'],
    ['login_x' => '10000', 'login_y' => '2'],
    ['login_x' => '1', 'login_y' => '2', 'task' => 'Stab_schreiben'],
] as $unsafeLoginCoordinates) {
    $assert(
        !estab_workflow_public_login_request(
            ['REQUEST_METHOD' => 'POST'],
            [],
            $unsafeLoginCoordinates
        ),
        'unsafe image-button login coordinates accepted'
    );
}
$credentials = [
    'login_flow' => 'new',
    'benutzer' => 'Ada Müller',
    'kuerzel' => 'ada001',
    'funktion' => 'S1',
    'kennwort1' => 'test-secret',
    'kennwort2' => 'test-secret',
    '2teskennwort' => 'Yes',
    'absenden_x' => '1',
];
$assert(
    estab_workflow_public_login_request(['REQUEST_METHOD' => 'POST'], [], $credentials),
    'credential POST rejected'
);
$assert(
    estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'POST'],
        [],
        $credentials + ['next' => 'incident-log']
    ),
    'credential POST lost its form-bound protected destination'
);
$loginCsrf = str_repeat('a', 64);
$assert(
    estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'POST'],
        [],
        $credentials + ['csrf_token' => $loginCsrf]
    ),
    'credential POST with a syntactically valid CSRF token rejected'
);
$assert(
    !estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'POST'],
        [],
        $credentials + ['csrf_token' => 'short']
    ),
    'credential POST with malformed CSRF token accepted'
);
$existingCredentials = [
    'login_flow' => 'existing',
    'benutzer' => 'Ada Müller',
    'kuerzel' => 'ada001',
    'funktion' => 'S1',
    'kennwort1' => 'test-secret',
    '2teskennwort' => 'No',
];
$assert(
    estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'POST'],
        [],
        $existingCredentials
    ),
    'existing-account credential POST rejected'
);
$assert(
    estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'POST'],
        [],
        array_diff_key($existingCredentials, ['login_flow' => true, '2teskennwort' => true])
    ),
    'historical one-password existing-account POST rejected'
);
$assert(
    estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'POST'],
        [],
        array_diff_key($credentials, ['login_flow' => true])
    ),
    'historical two-password credential POST rejected'
);
$assert(
    estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'POST'],
        [],
        array_diff_key($existingCredentials, ['2teskennwort' => true])
    ),
    'explicit existing flow was coupled to the compatibility marker'
);
$assert(
    estab_workflow_public_login_request(
        ['REQUEST_METHOD' => 'POST'],
        [],
        array_diff_key($credentials, ['2teskennwort' => true])
    ),
    'explicit new flow was coupled to the compatibility marker'
);
$legacyCredentials = array_diff_key(
    $existingCredentials,
    ['login_flow' => true, '2teskennwort' => true]
);
$assert(
    estab_workflow_login_credentials_present($credentials)
        && !estab_workflow_login_credentials_present(['login_flow' => 'existing']),
    'credential-bearing request detection is incorrect'
);
$legacyModeBefore = getenv('ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF');
putenv('ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=false');
$assert(
    !estab_workflow_legacy_login_without_csrf_allowed(
        ['HTTP_HOST' => 'estab.example', 'HTTP_SEC_FETCH_SITE' => 'same-origin'],
        $legacyCredentials
    ),
    'tokenless legacy login is enabled by default'
);
putenv('ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=true');
$assert(
    estab_workflow_legacy_login_without_csrf_allowed(
        ['HTTP_HOST' => 'estab.example', 'HTTP_SEC_FETCH_SITE' => 'same-origin'],
        $legacyCredentials
    ),
    'same-origin tokenless legacy login rejected after explicit opt-in'
);
$assert(
    !estab_workflow_legacy_login_without_csrf_allowed(
        ['HTTP_HOST' => 'estab.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site'],
        $legacyCredentials
    ),
    'cross-site tokenless legacy login accepted'
);
$assert(
    !estab_workflow_legacy_login_without_csrf_allowed(
        ['HTTP_HOST' => 'estab.example', 'HTTP_ORIGIN' => 'https://evil.example'],
        $legacyCredentials
    ),
    'foreign-origin tokenless legacy login accepted'
);
$assert(
    !estab_workflow_legacy_login_without_csrf_allowed(
        ['HTTP_HOST' => 'estab.example', 'HTTP_SEC_FETCH_SITE' => 'same-origin'],
        $existingCredentials
    ),
    'explicit browser login was misclassified as a tokenless legacy request'
);
if ($legacyModeBefore === false) {
    putenv('ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF');
} else {
    putenv('ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=' . $legacyModeBefore);
}
foreach ([
    ['REQUEST_METHOD' => 'GET', 'get' => ['reset_record' => '1'], 'post' => []],
    ['REQUEST_METHOD' => 'GET', 'get' => ['login_flow' => 'unknown'], 'post' => []],
    ['REQUEST_METHOD' => 'GET', 'get' => ['login_flow' => ['new']], 'post' => []],
    [
        'REQUEST_METHOD' => 'GET',
        'get' => ['login_flow' => 'new', 'task' => 'Stab_schreiben'],
        'post' => [],
    ],
    [
        'REQUEST_METHOD' => 'GET',
        'get' => ['login_flow' => 'new'],
        'post' => ['login_flow' => 'new'],
    ],
    ['REQUEST_METHOD' => 'POST', 'get' => [], 'post' => ['login' => 'Anmelden', 'reset_record' => '1']],
    ['REQUEST_METHOD' => 'POST', 'get' => [], 'post' => ['login_flow' => 'unknown']],
    ['REQUEST_METHOD' => 'POST', 'get' => [], 'post' => ['login_flow' => ['existing']]],
    ['REQUEST_METHOD' => 'POST', 'get' => [], 'post' => ['login_flow' => 'new', 'task' => 'Stab_schreiben']],
    ['REQUEST_METHOD' => 'POST', 'get' => [], 'post' => ['login_flow' => 'new', 'next' => 'administration']],
    ['REQUEST_METHOD' => 'POST', 'get' => [], 'post' => ['login_flow' => 'new', 'next' => ['incident-log']]],
    [
        'REQUEST_METHOD' => 'POST',
        'get' => [],
        'post' => $existingCredentials + ['kennwort2' => 'unexpected'],
    ],
    [
        'REQUEST_METHOD' => 'POST',
        'get' => [],
        'post' => array_replace($credentials, ['2teskennwort' => 'No']),
    ],
    ['REQUEST_METHOD' => 'POST', 'get' => [], 'post' => $credentials + ['task' => 'Stab_schreiben']],
    ['REQUEST_METHOD' => 'POST', 'get' => ['00_lfd' => '1'], 'post' => $credentials],
] as $anonymousRequest) {
    $assert(
        !estab_workflow_public_login_request(
            ['REQUEST_METHOD' => $anonymousRequest['REQUEST_METHOD']],
            $anonymousRequest['get'],
            $anonymousRequest['post']
        ),
        'unsafe anonymous request accepted'
    );
}

foreach (['1', '42', 9] as $validId) {
    $assert(estab_workflow_record_id($validId) !== null, 'valid record ID rejected');
}
foreach (['', '0', '-1', '+1', '01', '1 OR 1=1', '1.0', [], null] as $invalidId) {
    $assert(estab_workflow_record_id($invalidId) === null, 'unsafe record ID accepted');
}

$staff = ['benutzer' => 'Staff', 'kuerzel' => 'staff1', 'funktion' => 'S1', 'rolle' => 'Stab'];
$advisor = ['benutzer' => 'Advisor', 'kuerzel' => 'fb0001', 'funktion' => 'POL', 'rolle' => 'FB'];
$viewer = ['benutzer' => 'Viewer', 'kuerzel' => 'si0001', 'funktion' => 'Si', 'rolle' => 'Stab'];
$telecommunications = ['benutzer' => 'Radio', 'kuerzel' => 'aw0001', 'funktion' => 'A/W', 'rolle' => 'Fernmelder'];

$assert(
    !estab_workflow_route_allowed($staff, 'POST', $existingCredentials),
    'authenticated session accepted a second login request'
);
$assert(
    !estab_workflow_route_allowed(
        $staff,
        'POST',
        ['next' => 'incident-log']
    ),
    'authenticated session accepted a login destination selector'
);
$assert(
    !estab_workflow_route_allowed($staff, 'POST', [
        'login_flow' => 'new',
        'benutzer' => 'Other',
        'kuerzel' => 'other1',
        'funktion' => 'A/W',
        'kennwort1' => 'new-secret',
        'kennwort2' => 'new-secret',
    ]),
    'authenticated session accepted account creation or role switching'
);

$assert(estab_workflow_route_allowed($staff, 'POST', ['task' => 'Stab_schreiben']), 'staff write denied');
$assert(estab_workflow_route_allowed($advisor, 'POST', ['stab' => 'meldung', '00_lfd' => '1']), 'advisor read denied');
$assert(estab_workflow_route_allowed($viewer, 'POST', ['task' => 'Stab_sichten', '00_lfd' => '1']), 'viewer route denied');
$assert(estab_workflow_route_allowed($telecommunications, 'POST', ['fm' => 'meldung', '00_lfd' => '1']), 'A/W route denied');
$assert(estab_workflow_route_allowed($telecommunications, 'POST', ['reset_record' => '1']), 'A/W reset denied');

$crossRoleCases = [
    [$telecommunications, 'POST', ['task' => 'Stab_schreiben']],
    [$staff, 'POST', ['task' => 'FM-Eingang']],
    [$staff, 'GET', ['sichter' => 'meldung', '00_lfd' => '1']],
    [$viewer, 'GET', ['fm' => 'meldung', '00_lfd' => '1']],
    [$advisor, 'GET', ['stab' => 'meldung', '00_lfd' => '1']],
    [$telecommunications, 'GET', ['fm' => 'meldung', '00_lfd' => '1']],
    [$viewer, 'POST', ['reset_record' => '1']],
    [$staff, 'GET', ['reset_record' => '1']],
    [$staff, 'POST', ['task' => 'unknown']],
    [$staff, 'POST', ['m2_parameter_x' => '1']],
    [$staff, 'POST', ['invented_action_x' => '1']],
    [$telecommunications, 'GET', ['fm' => 'meldung', '00_lfd' => '1 OR 1=1']],
];
foreach ($crossRoleCases as [$identity, $method, $request]) {
    $assert(!estab_workflow_route_allowed($identity, $method, $request), 'cross-role or unsafe route accepted');
}

$assert(
    estab_workflow_route_allowed($staff, 'POST', ['action' => 'gelesen', 'todo' => 'set', '00_lfd' => '9']),
    'valid state toggle denied'
);

$assert(estab_workflow_category_filter('alle') === 'alle', 'all-category filter rejected');
$assert(estab_workflow_category_filter('42') === 42, 'category ID rejected');
foreach (["x' OR 1=1", '-1', '01', [], null] as $unsafeCategory) {
    $assert(estab_workflow_category_filter($unsafeCategory) === null, 'unsafe category filter accepted');
}

$assert(
    estab_workflow_message_operation(['stab' => 'meldung', '00_lfd' => '1']) === 'staff-read'
        && estab_workflow_message_operation(['fm' => 'meldung', '00_lfd' => '1']) === 'telecommunications-edit'
        && estab_workflow_message_operation(['action' => 'gelesen', 'todo' => 'set']) === 'staff-state',
    'message object operation mapping is incomplete'
);
$assert(
    !estab_workflow_route_allowed($staff, 'POST', ['action' => 'drop', 'todo' => 'set', '00_lfd' => '9']),
    'unknown state action accepted'
);

$mainController = file_get_contents(dirname(__DIR__, 2) . '/4fach/mainindex.php');
$assert(is_string($mainController), 'main controller unreadable');
$assert(
    str_contains($mainController, 'estab_workflow_public_login_request')
        && str_contains($mainController, 'estab_workflow_route_allowed')
        && str_contains($mainController, 'estab_workflow_record_id')
        && str_contains($mainController, 'estab_csrf_is_valid')
        && str_contains($mainController, 'estab_workflow_legacy_login_without_csrf_allowed')
        && str_contains(
            $mainController,
            'estab_navigation_login_destination_field'
        )
        && !str_contains($mainController, 'estab_login_destination'),
    'main controller does not enforce the central workflow gate'
);

printf("Workflow security tests: OK (%d assertions)\n", $assertions);
