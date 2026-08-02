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
$operationalCsrf = str_repeat('a', 64);
$sameOriginPost = [
    'REQUEST_METHOD' => 'POST',
    'HTTP_HOST' => 'estab.example',
    'HTTP_SEC_FETCH_SITE' => 'same-origin',
];
$realOperationalPosts = [
    'new message form' => [
        'csrf_token' => $operationalCsrf,
        'task' => 'Stab_schreiben',
        '12_inhalt' => 'discarded',
    ],
    'existing message form' => [
        'csrf_token' => $operationalCsrf,
        'task' => 'Stab_sichten',
        '00_lfd' => '7',
        'absenden_x' => '1',
    ],
    'sidebar action' => [
        'csrf_token' => $operationalCsrf,
        'fm_eingang_x' => '1',
        'next' => 'messages',
    ],
    'detail action' => [
        'csrf_token' => $operationalCsrf,
        'fm' => 'meldung',
        '00_lfd' => '9',
    ],
    'staff state action' => [
        'csrf_token' => $operationalCsrf,
        'action' => 'gelesen',
        'todo' => 'set',
        '00_lfd' => '11',
    ],
    'operator reset' => [
        'csrf_token' => $operationalCsrf,
        'reset_record' => '13',
    ],
    'second-sighting search' => [
        'csrf_token' => $operationalCsrf,
        'fm_admin_x' => '1',
        'ml_q' => 'search',
        'ml_apply' => '1',
    ],
    'legacy image filter' => [
        'filter_unerledigt_ein_x' => '4',
        'filter_unerledigt_ein_y' => '8',
    ],
    'legacy text filter' => [
        'flt_search' => 'search',
        'filter_suche' => 'suchen',
    ],
];
foreach ($realOperationalPosts as $form => $post) {
    $assert(
        estab_workflow_anonymous_operational_post(
            $sameOriginPost,
            [],
            $post
        ),
        'expired same-site ' . $form . ' was not recognized'
    );
}
$assert(
    estab_workflow_anonymous_operational_post(
        [
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'estab.example',
            'HTTP_ORIGIN' => 'https://estab.example',
        ],
        [],
        $realOperationalPosts['new message form']
    )
        && estab_workflow_anonymous_operational_post(
            [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'estab.example',
                'HTTP_REFERER' => 'https://estab.example/4fach/mainindex.php',
            ],
            [],
            $realOperationalPosts['sidebar action']
        ),
    'equal-authority Origin or Referer was not positive same-site evidence'
);

$unsafeOperationalPosts = [
    'unknown field only' => [
        'csrf_token' => $operationalCsrf,
        'totally_unknown' => '1',
    ],
    'CSRF only' => ['csrf_token' => $operationalCsrf],
    'unknown task' => [
        'csrf_token' => $operationalCsrf,
        'task' => 'Unknown',
    ],
    'missing CSRF' => [
        'task' => 'Stab_schreiben',
        '12_inhalt' => 'discarded',
    ],
    'two primary actions' => [
        'csrf_token' => $operationalCsrf,
        'stab_schreiben_x' => '1',
        'fm_eingang_x' => '1',
    ],
    'task and detail route' => [
        'csrf_token' => $operationalCsrf,
        'task' => 'Stab_schreiben',
        'fm' => 'meldung',
        '00_lfd' => '1',
    ],
    'two message submit actions' => [
        'csrf_token' => $operationalCsrf,
        'task' => 'Stab_schreiben',
        'absenden_x' => '1',
        'abbrechen_x' => '1',
    ],
    'unknown coordinate action' => [
        'csrf_token' => $operationalCsrf,
        'task' => 'Stab_schreiben',
        'unknown_x' => '1',
    ],
    'foreign legacy coordinate action' => [
        'csrf_token' => $operationalCsrf,
        'task' => 'Stab_schreiben',
        'ah_upload_x' => '1',
    ],
    'incomplete image action' => [
        'filter_erledigt_ein_y' => '1',
    ],
    'foreign form action' => [
        'csrf_token' => $operationalCsrf,
        'task' => 'Stab_lesen',
        '00_lfd' => '2',
        'category_action' => 'assign',
    ],
    'bare message-list action' => [
        'csrf_token' => $operationalCsrf,
        'ml_apply' => '1',
    ],
    'unknown message-list action' => [
        'csrf_token' => $operationalCsrf,
        'fm_admin_x' => '1',
        'ml_unknown' => '1',
    ],
    'combined message-list actions' => [
        'csrf_token' => $operationalCsrf,
        'fm_admin_x' => '1',
        'ml_apply' => '1',
        'ml_reset' => '1',
    ],
    'foreign sidebar destination' => [
        'csrf_token' => $operationalCsrf,
        'fm_eingang_x' => '1',
        'next' => 'incident-log',
    ],
];
foreach ($unsafeOperationalPosts as $form => $post) {
    $assert(
        !estab_workflow_anonymous_operational_post(
            $sameOriginPost,
            [],
            $post
        ),
        'unsafe expired operational POST accepted: ' . $form
    );
}

$knownMessagePost = $realOperationalPosts['new message form'];
$unsafeOperationalMetadata = [
    'missing evidence' => [
        'REQUEST_METHOD' => 'POST',
        'HTTP_HOST' => 'estab.example',
    ],
    'cross-site fetch metadata' => [
        'REQUEST_METHOD' => 'POST',
        'HTTP_HOST' => 'estab.example',
        'HTTP_SEC_FETCH_SITE' => 'cross-site',
    ],
    'foreign Origin' => [
        'REQUEST_METHOD' => 'POST',
        'HTTP_HOST' => 'estab.example',
        'HTTP_ORIGIN' => 'https://evil.example',
    ],
    'different authority port' => [
        'REQUEST_METHOD' => 'POST',
        'HTTP_HOST' => 'estab.example:8443',
        'HTTP_ORIGIN' => 'https://estab.example:9443',
    ],
    'Origin userinfo without password' => [
        'REQUEST_METHOD' => 'POST',
        'HTTP_HOST' => 'estab.example',
        'HTTP_ORIGIN' => 'https://operator@estab.example',
    ],
    'Origin userinfo with password' => [
        'REQUEST_METHOD' => 'POST',
        'HTTP_HOST' => 'estab.example',
        'HTTP_ORIGIN' => 'https://operator:secret@estab.example',
    ],
];
foreach ($unsafeOperationalMetadata as $case => $server) {
    $assert(
        !estab_workflow_anonymous_operational_post(
            $server,
            [],
            $knownMessagePost
        ),
        'unsafe operational request metadata accepted: ' . $case
    );
}
$assert(
    !estab_workflow_anonymous_operational_post(
        array_replace($sameOriginPost, ['REQUEST_METHOD' => 'GET']),
        [],
        $knownMessagePost
    )
        && !estab_workflow_anonymous_operational_post(
            $sameOriginPost,
            ['task' => 'Stab_schreiben'],
            $knownMessagePost
        )
        && !estab_workflow_anonymous_operational_post(
            $sameOriginPost,
            [],
            array_replace($knownMessagePost, ['login_flow' => 'existing'])
        )
        && !estab_workflow_anonymous_operational_post(
            $sameOriginPost,
            [],
            []
        ),
    'non-POST, query, login hybrid, or empty stale request was accepted'
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

$attachmentEditRoutes = [
    [$telecommunications, 'FM-Eingang'],
    [$telecommunications, 'FM-Eingang_Anhang'],
    [$staff, 'Stab_schreiben'],
    [$staff, 'Stab_korrigieren'],
    [$staff, 'Stab_gesprnoti'],
];
$assert(
    estab_workflow_attachment_edit_tasks() === array_column(
        $attachmentEditRoutes,
        1
    ),
    'attachment edit-task policy differs from the tested workflow stages'
);
$assert(
    estab_workflow_action_keys_allowed([
        'message_attachment_upload_x' => '12',
        'message_attachment_upload_y' => '4',
        'message_attachment_remove_x' => 'EL0001.pdf',
        'message_attachment_remove_y' => 'EL0002.jpeg',
    ]),
    'integrated attachment action coordinates are absent from the central allowlist'
);
foreach ($attachmentEditRoutes as [$attachmentIdentity, $attachmentTask]) {
    $assert(
        estab_workflow_route_allowed(
            $attachmentIdentity,
            'POST',
            [
                'task' => $attachmentTask,
                'message_attachment_upload_x' => '12',
            ]
        )
            && estab_workflow_route_allowed(
                $attachmentIdentity,
                'POST',
                [
                    'task' => $attachmentTask,
                    'message_attachment_upload_y' => '4',
                ]
            )
            && estab_workflow_route_allowed(
                $attachmentIdentity,
                'POST',
                [
                    'task' => $attachmentTask,
                    'message_attachment_remove_x' => 'EL0001.pdf',
                ]
            )
            && estab_workflow_route_allowed(
                $attachmentIdentity,
                'POST',
                [
                    'task' => $attachmentTask,
                    'message_attachment_remove_y' => 'EL0002.jpeg',
                ]
            ),
        $attachmentTask . ' rejects its integrated attachment actions'
    );
    $wrongAttachmentIdentity = $attachmentIdentity === $staff
        ? $telecommunications
        : $staff;
    $assert(
        !estab_workflow_route_allowed(
            $wrongAttachmentIdentity,
            'POST',
            [
                'task' => $attachmentTask,
                'message_attachment_upload_x' => '1',
            ]
        )
            && !estab_workflow_route_allowed(
                $wrongAttachmentIdentity,
                'POST',
                [
                    'task' => $attachmentTask,
                    'message_attachment_remove_x' => 'EL0001.pdf',
                ]
            ),
        $attachmentTask . ' attachment actions are reachable through another role'
    );
}
foreach ([
    [$telecommunications, 'FM-Ausgang'],
    [$staff, 'Stab_lesen'],
    [$viewer, 'Stab_sichten'],
    [$telecommunicationsLead, 'LdF-Eingang'],
    [$telecommunicationsLead, 'LdF-Ausgang'],
] as [$readOnlyIdentity, $readOnlyTask]) {
    $assert(
        !estab_workflow_route_allowed(
            $readOnlyIdentity,
            'POST',
            [
                'task' => $readOnlyTask,
                'message_attachment_upload_x' => '1',
            ]
        )
            && !estab_workflow_route_allowed(
                $readOnlyIdentity,
                'POST',
                [
                    'task' => $readOnlyTask,
                    'message_attachment_remove_x' => 'EL0001.pdf',
                ]
            ),
        $readOnlyTask . ' unexpectedly permits attachment changes'
    );
}
$assert(
    !estab_workflow_route_allowed(
        $staff,
        'POST',
        ['message_attachment_upload_x' => '1']
    )
        && !estab_workflow_route_allowed(
            $staff,
            'POST',
            ['message_attachment_remove_x' => 'EL0001.pdf']
        ),
    'attachment action without an editable workflow task was accepted'
);
$assert(
    !estab_workflow_route_allowed(
        $staff,
        'GET',
        [
            'task' => 'Stab_schreiben',
            'message_attachment_upload_x' => '1',
        ]
    )
        && !estab_workflow_route_allowed(
            $staff,
            'POST',
            [
                'task' => 'Stab_schreiben',
                'message_attachment_upload_x' => '1',
                'message_attachment_remove_x' => 'EL0001.pdf',
            ]
        ),
    'attachment actions permit a GET or conflicting upload/remove transition'
);
foreach ([
    '',
    'A.pdf',
    'EL0001',
    'EL0001.pdf/../EL0002.pdf',
    "EL0001.pdf\n",
    str_repeat('A', 239) . '.pdf',
    ['EL0001.pdf'],
] as $unsafeAttachmentReference) {
    $assert(
        !estab_workflow_route_allowed(
            $staff,
            'POST',
            [
                'task' => 'Stab_schreiben',
                'message_attachment_remove_x' => $unsafeAttachmentReference,
            ]
        ),
        'malformed attachment removal reference passed the workflow gate'
    );
}

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
        'task' => 'Stab_schreiben',
        '11_gesprnotiz' => 'on',
    ]
), 'new staff message can no longer start a conversation note');
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
    ['02_zeit' => '1015'],
    ['02_zeichen' => 'ldf001'],
    ['03_datum' => '1016'],
    ['03_zeichen' => 'aw0001'],
    ['05_gegenstelle' => 'Gefälschter Rufname'],
    ['06_befweg' => 'Gefälschter Beförderungsweg'],
    ['06_befwegausw' => 'Fu'],
    ['fernmeldeplan_eintrag_id' => '17'],
    ['transportweg_bestaetigt' => '1'],
] as $forgedConversationMark) {
    $assert(!estab_workflow_route_allowed(
        $staff,
        'POST',
        ['task' => 'Stab_gesprnoti'] + $forgedConversationMark
    ), 'forged conversation-note identity, review or disposition mark accepted');
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
$assert(!estab_workflow_route_allowed(
    $viewer,
    'POST',
    [
        'task' => 'Stab_sichten',
        '00_lfd' => '1',
        '15_quitdatum' => '0101',
    ]
), 'browser-selected Sichter completion time accepted');
$assert(!estab_workflow_route_allowed(
    $staff,
    'POST',
    [
        'task' => 'Stab_korrigieren',
        '00_lfd' => '1',
        '17_vermerke' => 'Browserseitig erfundener Korrekturvermerk',
    ]
), 'author correction accepted a forged Sichter return reason');
foreach (['on', 'f'] as $forgedConversationState) {
    $assert(!estab_workflow_route_allowed(
        $staff,
        'POST',
        [
            'task' => 'Stab_korrigieren',
            '00_lfd' => '1',
            '11_gesprnotiz' => $forgedConversationState,
        ]
    ), 'author correction accepted an overposted conversation-note state');
}

$distributionMatrix = [
    2 => [1 => ['fkt' => 'S1']],
    3 => [2 => ['fkt' => 'POL']],
];
$matrixRevision = estab_workflow_recipient_matrix_revision(
    $distributionMatrix,
    'S2'
);
$assert(
    preg_match('/\A[a-f0-9]{64}\z/D', $matrixRevision) === 1,
    'recipient matrix revision is not a deterministic SHA-256 value'
);
estab_workflow_require_recipient_matrix_revision(
    ['recipient_matrix_revision' => $matrixRevision],
    $distributionMatrix,
    'S2'
);
foreach ([
    [],
    ['recipient_matrix_revision' => str_repeat('0', 64)],
    ['recipient_matrix_revision' => $matrixRevision],
] as $revisionRequest) {
    $mutatedMatrix = $distributionMatrix;
    $mutatedMatrix[2][1]['fkt'] = 'AB_C';
    try {
        estab_workflow_require_recipient_matrix_revision(
            $revisionRequest,
            $mutatedMatrix,
            'S2'
        );
        $assert(false, 'missing, forged or stale recipient matrix accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'missing, forged or stale recipient matrix rejected');
    }
}
$assert(
    estab_workflow_distribution_tokens(
        [
            '16_21' => '16_21_bl',
            '16_32' => '16_32_bl',
        ],
        $distributionMatrix,
        ['S2_rt', 'S1_gn']
    ) === 'S2_rt,S1_gn,S1_bl,POL_bl,',
    'recipient distribution is not matrix-derived while preserving server-required copies'
);
foreach ([
    ['16_21' => '16_21_bl,alle'],
    ['16_21' => '16_21'],
    ['16_25' => '16_25_bl'],
    ['16_gncopy' => ''],
    ['16_gncopy' => '16_21_gn'],
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
            ]
        ),
    'in-form matrix recipient controls were rejected'
);
foreach (['', '16_32_gn'] as $retiredGreenValue) {
    $assert(
        !estab_workflow_route_allowed(
            $staff,
            'POST',
            ['16_gncopy' => $retiredGreenValue]
        ),
        'neutral route accepted the retired green-copy field'
    );
    foreach ([
        [$staff, 'Stab_schreiben'],
        [$staff, 'Stab_gesprnoti'],
        [$viewer, 'Stab_sichten'],
    ] as [$distributionIdentity, $distributionTask]) {
        $assert(
            !estab_workflow_route_allowed(
                $distributionIdentity,
                'POST',
                [
                    'task' => $distributionTask,
                    '16_gncopy' => $retiredGreenValue,
                ]
            ),
            $distributionTask . ' accepted the retired green-copy field'
        );
    }
}
foreach ([
    ['16_gncopy' => ''],
    ['16_gncopy' => '16_21_gn'],
] as $retiredDistributionField) {
    try {
        estab_workflow_distribution_tokens(
            $retiredDistributionField,
            $distributionMatrix
        );
        $assert(false, 'distribution parser accepted a retired presentation field');
    } catch (InvalidArgumentException) {
        $assert(true, 'distribution parser rejected a retired presentation field');
    }
}
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
            'check_and_save ('
        )
        && str_contains(
            $mainController,
            '$workflowIncidentId'
        )
        && !str_contains(
            $mainController,
            '$_SESSION ["gesprnoti"]'
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
$logbookHttp = file_get_contents(dirname(__DIR__) . '/integration/logbooks_http.sh');
$categoryHttp = file_get_contents(dirname(__DIR__) . '/integration/categories_http.sh');
$messageWorkflowHttp = file_get_contents(
    dirname(__DIR__) . '/integration/message_workflow_http.sh'
);
$assert(
    is_string($logbookHttp)
        && is_string($categoryHttp)
        && is_string($messageWorkflowHttp),
    'fachliche HTTP integration contracts are unreadable'
);
$assert(
    !str_contains($logbookHttp, 'operation_action=select_hat')
        && !str_contains($logbookHttp, 'dienstbesetzung_id=')
        && str_contains($logbookHttp, 'fixed account function')
        && str_contains($logbookHttp, "'logbook_action=save_entry'")
        && !str_contains($categoryHttp, 'operation_action=select_hat')
        && !str_contains($categoryHttp, 'dienstbesetzung_id=')
        && str_contains($categoryHttp, 'fixed account function')
        && str_contains($categoryHttp, "'category_action=create'")
        && !str_contains($messageWorkflowHttp, 'operation_action=select_hat')
        && !str_contains($messageWorkflowHttp, 'activate_http_shift.php')
        && str_contains($messageWorkflowHttp, 'fixed account function')
        && str_contains(
            $messageWorkflowHttp,
            'workflow accounts have no legacy duty assignments'
        )
        && str_contains(
            $messageWorkflowHttp,
            "'operation_action=create_plan'"
        )
        && str_contains(
            $messageWorkflowHttp,
            'active incident for Führungsstellen HTTP workflow'
        ),
    'fachliche HTTP integration still depends on a selected legacy duty '
        . 'assignment or lost its fixed-account and active-incident evidence'
);
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
            'assert_status 200 --cookie "$cookie_jar" --cookie-jar '
                . '"$cookie_jar" \\' . "\n"
                . '    "$base_url/4fach/vordrucke.php"'
        )
        && str_contains($legacyHttpSmoke, 'Zugewiesene Funktion')
        && str_contains(
            $legacyHttpSmoke,
            'operation_action[^>]*select_hat'
        )
        && str_contains($legacyHttpSmoke, 'name="dienstbesetzung_id"')
        && str_contains($legacyHttpSmoke, 'Generierte Vordrucke')
        && !str_contains($legacyHttpSmoke, 'missing selected-duty redirect')
        && str_contains($legacyHttpSmoke, 'logout_action=logout'),
    'isolated legacy HTTP acceptance omits origin isolation, fixed-account '
        . 'access without a selected duty assignment or session cleanup'
);

printf("Workflow security tests: OK (%d assertions)\n", $assertions);
