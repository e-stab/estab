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
            [
                'login_flow' => 'existing',
                'next' => 'tracking',
                'interrupted' => '1',
            ],
            []
        ),
    'validated post-login destination or interruption notice was rejected'
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
        )
        && !estab_workflow_public_login_request(
            ['REQUEST_METHOD' => 'GET'],
            ['interrupted' => '0'],
            []
        )
        && !estab_workflow_public_login_request(
            ['REQUEST_METHOD' => 'GET'],
            ['interrupted' => ['1']],
            []
        ),
    'untrusted post-login destination or interruption notice was accepted'
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
$assert(
    estab_workflow_anonymous_operational_post(
        [
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'estab.example',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ],
        [],
        ['task' => 'Stab_schreiben', '12_inhalt' => 'discarded']
    )
        && !estab_workflow_anonymous_operational_post(
            [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'estab.example',
                'HTTP_SEC_FETCH_SITE' => 'cross-site',
            ],
            [],
            ['task' => 'Stab_schreiben']
        )
        && !estab_workflow_anonymous_operational_post(
            [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'estab.example',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
            [],
            ['login_flow' => 'existing']
        )
        && !estab_workflow_anonymous_operational_post(
            ['REQUEST_METHOD' => 'POST'],
            ['task' => 'Stab_schreiben'],
            ['task' => 'Stab_schreiben']
        )
        && !estab_workflow_anonymous_operational_post(
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        ),
    'expired same-site operational form classification is unsafe'
);
$assert(
    estab_workflow_anonymous_operational_get(
        ['REQUEST_METHOD' => 'GET'],
        ['filter_anzahl_x' => '1', 'filter_anzahl' => '10'],
        []
    )
        && !estab_workflow_anonymous_operational_get(
            ['REQUEST_METHOD' => 'GET'],
            ['login_flow' => 'unknown'],
            []
        )
        && !estab_workflow_anonymous_operational_get(
            ['REQUEST_METHOD' => 'GET'],
            ['next' => 'incident-log'],
            []
        )
        && !estab_workflow_anonymous_operational_get(
            ['REQUEST_METHOD' => 'GET'],
            [
                'benutzer' => 'Mustermann, Max',
                'kuerzel' => 'mm',
                'funktion' => 'S1',
                'anmelden' => 'Anmelden',
            ],
            []
        )
        && !estab_workflow_anonymous_operational_get(
            ['REQUEST_METHOD' => 'GET'],
            ['csrf_token' => str_repeat('a', 64)],
            []
        )
        && !estab_workflow_anonymous_operational_get(
            ['REQUEST_METHOD' => 'GET'],
            [],
            []
        )
        && !estab_workflow_anonymous_operational_get(
            ['REQUEST_METHOD' => 'POST'],
            ['filter_anzahl_x' => '1'],
            []
        ),
    'expired operational page-link classification is unsafe'
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
$telecommunicationsLead = ['benutzer' => 'Lead', 'kuerzel' => 'ldf001', 'funktion' => 'LdF', 'rolle' => 'Fernmelder'];

$assert(
    estab_workflow_is_telecommunications($telecommunications)
        && !estab_workflow_is_telecommunications($telecommunicationsLead)
        && estab_workflow_is_telecommunications_lead($telecommunicationsLead)
        && !estab_workflow_is_telecommunications_lead($telecommunications)
        && !estab_workflow_is_telecommunications(
            array_replace($telecommunications, ['rolle' => 'Stab'])
        )
        && !estab_workflow_is_telecommunications_lead(
            array_replace($telecommunicationsLead, ['rolle' => 'Stab'])
        ),
    'A/W and LdF telecommunications identities are not strictly separated'
);

$incomingTelecommunicationsTasks = [
    'FM-Eingang',
    'FM-Eingang_Anhang',
];
foreach ($incomingTelecommunicationsTasks as $incomingTask) {
    $assert(
        estab_workflow_route_allowed(
            $telecommunications,
            'POST',
            ['task' => $incomingTask]
        )
            && estab_workflow_route_allowed(
                $telecommunications,
                'POST',
                ['task' => $incomingTask, '13_abseinheit' => '']
            )
            && estab_workflow_route_allowed(
                $telecommunications,
                'POST',
                ['task' => $incomingTask, '13_abseinheit' => " \t"]
            ),
        $incomingTask . ' rejects an A/W request without a supplied sender'
    );
    foreach (['Leitstelle Nord', ' Leitstelle Nord ', ['Leitstelle Nord'], 42] as $forgedSender) {
        $assert(
            !estab_workflow_route_allowed(
                $telecommunications,
                'POST',
                ['task' => $incomingTask, '13_abseinheit' => $forgedSender]
            ),
            $incomingTask . ' accepts an A/W-supplied sender'
        );
    }
    foreach ([
        ['16_empf' => 'S1_bl,'],
        ['16_empf' => ''],
        ['16_gncopy' => '16_21_gn'],
        ['16_21' => '16_21_bl'],
        ['16_empf_sonst_21' => 'S1'],
    ] as $forgedDistribution) {
        $assert(
            !estab_workflow_route_allowed(
                $telecommunications,
                'POST',
                ['task' => $incomingTask] + $forgedDistribution
            ),
            $incomingTask . ' accepts browser-controlled distribution data'
        );
    }
    $assert(
        !estab_workflow_route_allowed(
            $telecommunicationsLead,
            'POST',
            ['task' => $incomingTask]
        ),
        $incomingTask . ' is reachable through the LdF role'
    );
}
$removedSelfReviewTasks = [
    'FM-Eingang_Sichter',
    'FM-Eingang_Anhang_Sichter',
    'FM-Ausgang_Sichter',
];
foreach ($removedSelfReviewTasks as $removedTask) {
    $assert(
        !estab_workflow_route_allowed(
            $telecommunications,
            'POST',
            ['task' => $removedTask]
        ),
        $removedTask . ' still permits A/W to replace the Sichter'
    );
}

foreach (['LdF-Eingang', 'LdF-Ausgang'] as $leadTask) {
    $assert(
        estab_workflow_route_allowed(
            $telecommunicationsLead,
            'POST',
            ['task' => $leadTask, '00_lfd' => '1']
        ),
        $leadTask . ' is denied to LdF'
    );
    foreach ([$telecommunications, $staff, $viewer] as $nonLeadIdentity) {
        $assert(
            !estab_workflow_route_allowed(
                $nonLeadIdentity,
                'POST',
                ['task' => $leadTask, '00_lfd' => '1']
            ),
            $leadTask . ' is reachable without the LdF role'
        );
    }
}
$assert(
    estab_workflow_route_allowed(
        $telecommunicationsLead,
        'POST',
        ['ldf' => 'meldung', '00_lfd' => '1']
    )
        && !estab_workflow_route_allowed(
            $telecommunications,
            'POST',
            ['ldf' => 'meldung', '00_lfd' => '1']
        )
        && estab_workflow_route_allowed(
            $telecommunicationsLead,
            'POST',
            ['ldf_nachrichten_x' => '1']
        )
        && !estab_workflow_route_allowed(
            $telecommunications,
            'POST',
            ['ldf_nachrichten_x' => '1']
        ),
    'LdF message selection or navigation is not isolated from A/W'
);

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
$assert(estab_workflow_route_allowed(
    $staff,
    'POST',
    [
        'task' => 'Stab_gesprnoti',
        '01_zeichen' => 'staff1',
        '14_zeichen' => 'staff1',
        '14_funktion' => 'S1',
        '15_quitdatum' => '',
        '15_quitzeichen' => '',
    ]
), 'self-recorded staff conversation note denied');
foreach ([
    ['01_zeichen' => 'forged'],
    ['14_zeichen' => 'forged'],
    ['14_funktion' => 'S6'],
    ['15_quitdatum' => '30122000'],
    ['15_quitzeichen' => 'si0001'],
] as $forgedConversationMark) {
    $assert(!estab_workflow_route_allowed(
        $staff,
        'POST',
        ['task' => 'Stab_gesprnoti'] + $forgedConversationMark
    ), 'forged conversation-note identity or review mark accepted');
}
$assert(estab_workflow_route_allowed(
    $staff,
    'POST',
    [
        'task' => 'Stab_korrigieren',
        '14_zeichen' => 'staff1',
        '14_funktion' => 'S1',
    ]
), 'returned staff correction denied');
$assert(!estab_workflow_route_allowed(
    $staff,
    'POST',
    [
        'task' => 'Stab_korrigieren',
        '14_zeichen' => 'forged',
        '14_funktion' => 'S1',
    ]
), 'forged correction author mark accepted');
$assert(estab_workflow_route_allowed($advisor, 'POST', ['stab' => 'meldung', '00_lfd' => '1']), 'advisor read denied');
$assert(estab_workflow_route_allowed($viewer, 'POST', ['task' => 'Stab_sichten', '00_lfd' => '1']), 'viewer route denied');
$assert(estab_workflow_route_allowed(
    $viewer,
    'POST',
    [
        'task' => 'Stab_sichten',
        '00_lfd' => '1',
        '15_quitzeichen' => 'si0001',
        'zurueckweisen_x' => '1',
    ]
), 'viewer formal return denied');
$assert(!estab_workflow_route_allowed(
    $viewer,
    'POST',
    [
        'task' => 'Stab_sichten',
        '00_lfd' => '1',
        '15_quitzeichen' => 'forged',
    ]
), 'forged Sichter mark accepted');

$distributionMatrix = [
    2 => [1 => ['fkt' => 'S1']],
    3 => [2 => ['fkt' => 'POL']],
];
$assert(
    estab_workflow_distribution_tokens(
        [
            '16_21' => '16_21_bl',
            '16_32' => '16_32_bl',
            '16_gncopy' => '16_21_gn',
        ],
        $distributionMatrix,
        ['S2_rt', 'S1_bl']
    ) === 'S2_rt,S1_bl,S1_gn,POL_bl,',
    'recipient distribution is not matrix-derived, ordered and deduplicated'
);
foreach ([
    ['16_21' => '16_21_bl,alle'],
    ['16_21' => '16_21'],
    ['16_25' => '16_25_bl'],
    ['16_gncopy' => '16_21_gn,alle'],
    ['16_empf' => 'alle,'],
    ['16_empf_sonst_21' => 'alle'],
    ['16_21' => ['16_21_bl']],
] as $forgedDistribution) {
    foreach ([
        [$staff, 'Stab_gesprnoti'],
        [$viewer, 'Stab_sichten'],
    ] as [$distributionIdentity, $distributionTask]) {
        $assert(
            !estab_workflow_route_allowed(
                $distributionIdentity,
                'POST',
                ['task' => $distributionTask] + $forgedDistribution
            ),
            $distributionTask . ' accepts forged recipient distribution'
        );
    }
}
$assert(
    estab_workflow_route_allowed(
        $staff,
        'POST',
        [
            'task' => 'Stab_gesprnoti',
            '16_21' => '16_21_bl',
            '16_gncopy' => '16_32_gn',
            '16_empf' => '',
            '16_empf_sonst_21' => '',
        ]
    )
        && estab_workflow_route_allowed(
            $viewer,
            'POST',
            [
                'task' => 'Stab_sichten',
                '16_21' => '16_21_bl',
                '16_gncopy' => '16_32_gn',
            ]
        ),
    'exact matrix distribution controls were rejected'
);
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
        && estab_workflow_message_operation([
            'task' => 'Stab_korrigieren',
            '00_lfd' => '1',
        ]) === 'staff-correction'
        && estab_workflow_message_operation(['fm' => 'meldung', '00_lfd' => '1']) === 'telecommunications-edit'
        && estab_workflow_message_operation(['ldf' => 'meldung', '00_lfd' => '1']) === 'telecommunications-lead-edit'
        && estab_workflow_message_operation(['task' => 'LdF-Eingang', '00_lfd' => '1'])
            === 'telecommunications-lead-incoming-save'
        && estab_workflow_message_operation(['task' => 'LdF-Ausgang', '00_lfd' => '1'])
            === 'telecommunications-lead-outgoing-save'
        && estab_workflow_message_operation(['reset_record' => '1'])
            === 'message-operator-reset'
        && estab_workflow_message_operation(['action' => 'gelesen', 'todo' => 'set']) === 'staff-state',
    'message object operation mapping is incomplete'
);
$assert(
    !estab_workflow_route_allowed($staff, 'POST', ['action' => 'drop', 'todo' => 'set', '00_lfd' => '9']),
    'unknown state action accepted'
);

$mainController = file_get_contents(dirname(__DIR__, 2) . '/4fach/mainindex.php');
$messageTools = file_get_contents(dirname(__DIR__, 2) . '/4fach/tools.php');
$assert(is_string($mainController), 'main controller unreadable');
$assert(is_string($messageTools), 'message tools unreadable');
$assert(
    str_contains($mainController, 'estab_workflow_public_login_request')
        && str_contains($mainController, 'estab_workflow_route_allowed')
        && str_contains($mainController, 'estab_workflow_record_id')
        && str_contains(
            $mainController,
            'estab_workflow_anonymous_operational_post'
        )
        && str_contains(
            $mainController,
            'estab_workflow_anonymous_operational_get'
        )
        && str_contains(
            $mainController,
            'estab_navigation_login_content_url'
        )
        && str_contains(
            $mainController,
            'data-estab-submission-discarded'
        )
        && substr_count($mainController, 'target=\\"_self\\"') >= 5
        && !str_contains($mainController, 'target=\\"mainframe\\"')
        && str_contains($messageTools, 'target=\\"_self\\"')
        && !str_contains($messageTools, 'target=\\"mainframe\\"')
        && str_contains(
            $mainController,
            'estab_message_fetch_for_incident_by_id ('
        )
        && str_contains(
            $mainController,
            '$workflowIncidentId = (int) ('
        )
        && str_contains(
            $mainController,
            '$messageOperation === "message-operator-reset"'
        )
        && str_contains($mainController, 'estab_csrf_is_valid')
        && str_contains($mainController, 'estab_workflow_legacy_login_without_csrf_allowed')
        && str_contains(
            $mainController,
            'estab_navigation_login_destination_field'
        )
        && !str_contains($mainController, 'estab_login_destination'),
    'main controller does not enforce the central workflow gate'
);
$commandPostLoadPosition = strpos(
    $mainController,
    '$activeCommandPostName = estab_incident_command_post_name ('
);
$conversationCommandPostPosition = strpos(
    $mainController,
    '$formdata ["13_abseinheit"] = $activeCommandPostName;'
);
$assert(
    is_int($commandPostLoadPosition)
        && is_int($conversationCommandPostPosition)
        && $commandPostLoadPosition < $conversationCommandPostPosition
        && str_contains(
            $mainController,
            'check_and_save ($returndata, $activeCommandPostName);'
        )
        && str_contains(
            $mainController,
            '$formdata ["14_zeichen"]      = $_SESSION ["vStab_kuerzel"];'
        )
        && str_contains(
            $mainController,
            '$formdata ["14_funktion"]     = $_SESSION ["vStab_funktion"];'
        )
        && str_contains(
            $mainController,
            '$formdata ["15_quitdatum"]    = "";'
        )
        && str_contains(
            $mainController,
            '$formdata ["15_quitzeichen"]  = "";'
        )
        && !str_contains($mainController, 'ESTAB_ORGANISATION'),
    'conversation-note staging is not bound to incident and session authority'
);

$ciIntegration = file_get_contents(dirname(__DIR__) . '/integration/ci.sh');
$defaultHttpSmoke = file_get_contents(dirname(__DIR__) . '/integration/http_smoke.sh');
$legacyHttpSmoke = file_get_contents(dirname(__DIR__) . '/integration/legacy_login_http.sh');
$assert(
    is_string($ciIntegration)
        && str_contains(
            $ciIntegration,
            'export ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=false'
        )
        && str_contains(
            $ciIntegration,
            'export ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=true'
        )
        && str_contains($ciIntegration, 'tests/integration/legacy_login_http.sh'),
    'CI does not keep the full stack default-off and isolate the legacy opt-in'
);
$assert(
    is_string($defaultHttpSmoke)
        && substr_count(
            $defaultHttpSmoke,
            "--header 'Sec-Fetch-Site: same-origin'"
        ) >= 3
        && str_contains(
            $defaultHttpSmoke,
            'The production-default stack rejects every tokenless credential request'
        ),
    'default HTTP acceptance no longer proves tokenless login rejection'
);
$assert(
    is_string($legacyHttpSmoke)
        && str_contains($legacyHttpSmoke, "Sec-Fetch-Site: cross-site")
        && str_contains($legacyHttpSmoke, "Sec-Fetch-Site: same-origin")
        && str_contains($legacyHttpSmoke, 'data-estab-session-bar')
        && str_contains(
            $legacyHttpSmoke,
            '$base_url/4fach/fuehrungsstelle.php'
        )
        && str_contains(
            $legacyHttpSmoke,
            'assert_status 303 --cookie "$cookie_jar" --cookie-jar '
                . '"$cookie_jar" \\' . "\n"
                . '    "$base_url/4fach/vordrucke.php"'
        )
        && str_contains(
            $legacyHttpSmoke,
            'missing selected-duty redirect'
        )
        && str_contains($legacyHttpSmoke, 'logout_action=logout'),
    'isolated legacy HTTP acceptance omits origin isolation, selected-hat '
        . 'fail-closed behavior or session cleanup'
);

printf("Workflow security tests: OK (%d assertions)\n", $assertions);
