<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/permission_mode.php';
require_once dirname(__DIR__, 2) . '/app/workflow.php';

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$fails = static function (callable $operation): ?Throwable {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    return null;
};

$identities = [
    'S1/Stab' => [
        'benutzer' => 'Single Dispatch S1',
        'kuerzel' => 'sd_s1',
        'funktion' => 'S1',
        'rolle' => 'Stab',
        'default' => 'staff-list',
        'allowed_actions' => [
            'stab_schreiben_x',
            'stab_korrekturen_x',
        ],
    ],
    'Si/Stab' => [
        'benutzer' => 'Single Dispatch Si',
        'kuerzel' => 'sd_si',
        'funktion' => 'Si',
        'rolle' => 'Stab',
        'default' => 'viewer-list',
        'allowed_actions' => [
            'stab_sichten_x',
        ],
    ],
    'A/W/Fernmelder' => [
        'benutzer' => 'Single Dispatch A-W',
        'kuerzel' => 'sd_aw',
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
        'default' => 'telecommunications-outgoing-list',
        'allowed_actions' => [
            'fm_eingang_x',
            'fm_ausgang_x',
        ],
    ],
    'LdF/Fernmelder' => [
        'benutzer' => 'Single Dispatch LdF',
        'kuerzel' => 'sd_ldf',
        'funktion' => 'LdF',
        'rolle' => 'Fernmelder',
        'default' => 'telecommunications-lead-list',
        'allowed_actions' => [
            'ldf_nachrichten_x',
        ],
    ],
    'POL/FB' => [
        'benutzer' => 'Single Dispatch POL',
        'kuerzel' => 'sd_pol',
        'funktion' => 'POL',
        'rolle' => 'FB',
        'default' => 'staff-list',
        'allowed_actions' => [
            'stab_schreiben_x',
            'stab_korrekturen_x',
        ],
    ],
];
$actions = [
    'stab_schreiben_x' => 'staff-write',
    'stab_korrekturen_x' => 'staff-corrections',
    'stab_sichten_x' => 'viewer-list',
    'ldf_nachrichten_x' => 'telecommunications-lead-list',
    'fm_eingang_x' => 'telecommunications-incoming-form',
    'fm_ausgang_x' => 'telecommunications-outgoing-list',
];
$candidateViews = array_values(array_unique(array_merge(
    ['staff-list'],
    array_values($actions)
)));

estab_permission_context_set_from_incident([
    'active_einsatz_id' => 901,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_LOOSE,
    'revision' => 41,
]);

foreach ($identities as $identityLabel => $identityWithDefault) {
    $defaultView = $identityWithDefault['default'];
    $allowedActions = $identityWithDefault['allowed_actions'];
    $identity = $identityWithDefault;
    unset($identity['default'], $identity['allowed_actions']);

    $assert(
        estab_workflow_primary_view_selector([]) === null,
        $identityLabel . ': neutral request unexpectedly selected a view'
    );
    $neutralRendered = array_values(array_filter(
        $candidateViews,
        static fn (string $view): bool =>
            estab_workflow_should_render_primary_view(
                null,
                $view,
                $view === $defaultView
            )
    ));
    $assert(
        $neutralRendered === [$defaultView],
        $identityLabel . ': neutral request did not retain exactly its default view'
    );

    foreach ($actions as $button => $expectedView) {
        $request = [$button => '1'];
        $selector = estab_workflow_primary_view_selector($request);
        $routeExpected = in_array($button, $allowedActions, true);
        $assert(
            estab_workflow_route_allowed($identity, 'POST', $request)
                === $routeExpected,
            $identityLabel . ': LOOSE base function admitted or rejected the wrong action: '
                . $button
        );
        $assert(
            $selector === $expectedView,
            $identityLabel . ': action selected the wrong view: ' . $button
        );
        $rendered = array_values(array_filter(
            $candidateViews,
            static fn (string $view): bool =>
                estab_workflow_should_render_primary_view(
                    $selector,
                    $view,
                    $view === $defaultView
                )
        ));
        $assert(
            $rendered === [$expectedView],
            $identityLabel . ': explicit selector also rendered the account default: '
                . $button
        );
    }

    $buttonNames = array_keys($actions);
    for ($left = 0; $left < count($buttonNames); $left++) {
        for ($right = $left + 1; $right < count($buttonNames); $right++) {
            $ambiguous = [
                $buttonNames[$left] => '1',
                $buttonNames[$right] => '1',
            ];
            $assert(
                !estab_workflow_route_allowed($identity, 'POST', $ambiguous),
                $identityLabel . ': multiple primary LOOSE actions were admitted'
            );
        }
    }
}

$looseMultiFunctionIdentity = [
    'benutzer' => 'Single Dispatch S1 mit Zusatzfunktionen',
    'kuerzel' => 'sd_multi',
    'funktion' => 'S1',
    'rolle' => 'Stab',
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_LOOSE,
    'estab_additional_functions' => [
        ['funktion' => 'Si', 'rolle' => 'Stab'],
        ['funktion' => 'A/W', 'rolle' => 'Fernmelder'],
        ['funktion' => 'LdF', 'rolle' => 'Fernmelder'],
    ],
];
$assert(
    estab_workflow_selected_identity_is_staff_writer(
        $looseMultiFunctionIdentity
    )
        && !estab_workflow_selected_identity_is(
            $looseMultiFunctionIdentity,
            'Si',
            'Stab'
        )
        && !estab_workflow_selected_identity_is(
            $looseMultiFunctionIdentity,
            'A/W',
            'Fernmelder'
        )
        && !estab_workflow_selected_identity_is(
            $looseMultiFunctionIdentity,
            'LdF',
            'Fernmelder'
        ),
    'LOOSE additions replaced the fixed account function as neutral view'
);
foreach ($actions as $button => $expectedView) {
    $request = [$button => '1'];
    $assert(
        estab_workflow_route_allowed(
            $looseMultiFunctionIdentity,
            'POST',
            $request
        ),
        'LOOSE explicit additional function did not admit its action: ' . $button
    );
    $actingIdentity = estab_workflow_identity_for_request(
        $looseMultiFunctionIdentity,
        $request
    );
    $expectedTuple = match ($button) {
        'stab_schreiben_x', 'stab_korrekturen_x' => ['S1', 'Stab'],
        'stab_sichten_x' => ['Si', 'Stab'],
        'ldf_nachrichten_x' => ['LdF', 'Fernmelder'],
        'fm_eingang_x', 'fm_ausgang_x' => ['A/W', 'Fernmelder'],
    };
    $assert(
        ($actingIdentity['funktion'] ?? null) === $expectedTuple[0]
            && ($actingIdentity['rolle'] ?? null) === $expectedTuple[1]
            && ($actingIdentity['authorization_account_function'] ?? null)
                === 'S1'
            && ($actingIdentity['authorization_account_role'] ?? null)
                === 'Stab'
            && ($actingIdentity['authorization_route_function'] ?? null)
                === $expectedTuple[0]
            && ($actingIdentity['authorization_route_role'] ?? null)
                === $expectedTuple[1],
        'LOOSE route did not retain account provenance for ' . $expectedView
    );
    $assert(
        estab_workflow_selected_identity_is(
            $actingIdentity,
            $expectedTuple[0],
            $expectedTuple[1]
        ),
        'LOOSE route did not expose exactly one selected function for '
            . $expectedView
    );
}

$multiStaffIdentity = $looseMultiFunctionIdentity;
$multiStaffIdentity['estab_additional_functions'][] = [
    'funktion' => 'S2',
    'rolle' => 'Stab',
];
$selectedS2Identity = estab_workflow_identity_as_tuple(
    $multiStaffIdentity,
    ['funktion' => 'S2', 'rolle' => 'Stab']
);
$selectedCorrectionActor = estab_workflow_identity_for_selected_route(
    $multiStaffIdentity,
    $selectedS2Identity,
    ['task' => 'Stab_korrigieren', 'absenden_x' => '1']
);
$assert(
    ($selectedCorrectionActor['funktion'] ?? null) === 'S2'
        && ($selectedCorrectionActor['rolle'] ?? null) === 'Stab'
        && ($selectedCorrectionActor['authorization_account_function'] ?? null)
            === 'S1'
        && ($selectedCorrectionActor['authorization_route_function'] ?? null)
            === 'S2',
    'selected additional S2 correction route fell back to the first staff function'
);
$foreignSelectedIdentity = $selectedS2Identity;
$foreignSelectedIdentity['kuerzel'] = 'foreign';
$assert(
    $fails(
        static fn (): array => estab_workflow_identity_for_selected_route(
            $multiStaffIdentity,
            $foreignSelectedIdentity,
            ['task' => 'Stab_korrigieren', 'absenden_x' => '1']
        )
    ) instanceof RuntimeException,
    'selected route actor from another account was accepted'
);
$revokedMultiStaffIdentity = $multiStaffIdentity;
$revokedMultiStaffIdentity['estab_additional_functions'] = array_values(
    array_filter(
        $revokedMultiStaffIdentity['estab_additional_functions'],
        static fn (array $tuple): bool => $tuple['funktion'] !== 'S2'
    )
);
$assert(
    $fails(
        static fn (): array => estab_workflow_identity_for_selected_route(
            $revokedMultiStaffIdentity,
            $selectedS2Identity,
            ['task' => 'Stab_korrigieren', 'absenden_x' => '1']
        )
    ) instanceof RuntimeException,
    'revoked selected S2 route remained effective'
);
$assert(
    $fails(
        static fn (): array => estab_workflow_identity_for_selected_route(
            $multiStaffIdentity,
            $selectedS2Identity,
            ['fm_eingang_x' => '1']
        )
    ) instanceof RuntimeException,
    'selected S2 actor was reused for an A/W route'
);

$looseUngradedIdentity = $looseMultiFunctionIdentity;
unset($looseUngradedIdentity['estab_additional_functions']);
foreach (['stab_sichten_x', 'ldf_nachrichten_x', 'fm_eingang_x'] as $button) {
    $assert(
        !estab_workflow_route_allowed(
            $looseUngradedIdentity,
            'POST',
            [$button => '1']
        ),
        'LOOSE route remained available after its additional function was removed: '
            . $button
    );
}

$assert(
    estab_workflow_primary_view_selector([
        'fm_eingang_x' => '17',
        'fm_eingang_y' => '8',
    ]) === 'telecommunications-incoming-form',
    'a complete legacy image-button coordinate pair was not one selector'
);
foreach ([
    ['fm_eingang_y' => '8'],
    ['task' => 'FM-Eingang', 'stab_schreiben_x' => '1'],
    ['stab' => 'meldung', 'fm' => 'meldung'],
    [
        'action' => 'gelesen',
        'todo' => 'set',
        'stab' => 'meldung',
        '00_lfd' => '17',
    ],
    [
        'reset_record' => '17',
        'fm' => 'meldung',
        '00_lfd' => '17',
    ],
    [
        'reset_record' => '17',
        'task' => 'FM-Ausgang',
        '00_lfd' => '17',
    ],
    [
        'action' => 'erledigt',
        'todo' => 'set',
        'task' => 'Stab_lesen',
        '00_lfd' => '17',
    ],
    ['stab' => 'invented'],
    ['sichter' => 'invented'],
] as $ambiguousOrInvalid) {
    $assert(
        !estab_workflow_route_allowed(
            [
                'benutzer' => 'Single Dispatch S1',
                'kuerzel' => 'sd_s1',
                'funktion' => 'S1',
                'rolle' => 'Stab',
            ],
            'POST',
            $ambiguousOrInvalid
        ),
        'ambiguous, incomplete, or unknown primary selector was admitted'
    );
    $assert(
        estab_workflow_message_operation($ambiguousOrInvalid) === null,
        'ambiguous object selectors still resolved by priority to one operation'
    );
}

foreach ([
    'Stab_schreiben',
    'Stab_gesprnoti',
    'FM-Eingang',
    'FM-Eingang_Anhang',
] as $cancelledTask) {
    $cancelRequest = [
        'task' => $cancelledTask,
        'abbrechen_x' => '1',
    ];
    $assert(
        estab_workflow_cancelled_new_form($cancelRequest),
        'new lock-free form does not return to the default on cancel: '
            . $cancelledTask
    );
    $cancelRequest['task'] = '';
    $assert(
        estab_workflow_primary_view_selector($cancelRequest) === null,
        'cancelled new form still suppresses the account default: '
            . $cancelledTask
    );
}
$assert(
    !estab_workflow_cancelled_new_form([
        'task' => 'LdF-Ausgang',
        'abbrechen_x' => '1',
    ])
        && !estab_workflow_cancelled_new_form([
            'task' => 'Stab_schreiben',
            'absenden_x' => '1',
        ]),
    'lock-owning or submitted task was mistaken for a new-form cancel'
);
$acknowledgedRead = [
    'task' => 'Stab_lesen',
    'gelesen_x' => '1',
    '00_lfd' => '17',
];
$assert(
    estab_workflow_acknowledged_read_form($acknowledgedRead),
    'Gelesen/OK does not return an opened staff message to its queue'
);
$acknowledgedRead['task'] = '';
$assert(
    estab_workflow_primary_view_selector($acknowledgedRead) === null,
    'acknowledged staff read still suppresses the account queue'
);
$assert(
    !estab_workflow_acknowledged_read_form([
        'task' => 'Stab_lesen',
        'antwort_x' => '1',
    ])
        && !estab_workflow_acknowledged_read_form([
            'task' => 'FM-Eingang',
            'gelesen_x' => '1',
        ]),
    'nonterminal follow-up or foreign task was mistaken for read acknowledgement'
);
$controller = file_get_contents($root . '/4fach/mainindex.php');
$assert(is_string($controller), 'main controller cannot be read');
$assert(
    preg_match(
        '~"telecommunications-outgoing-list",\s+'
            . 'is_array \(\$workflowSelectedIdentity\)\s+'
            . '&& estab_workflow_selected_identity_is \(\s*'
            . '\$workflowSelectedIdentity,\s*"A/W",\s*'
            . '"Fernmelder"\s*\)~',
        $controller
    ) === 1,
    'anonymous or multi-function message overview lacks one exact Fernmelder default'
);
$lockCancelSourceStart = strpos(
    $controller,
    'if (!estab_message_release_operator_stage_lock ('
);
$lockCancelSourceEnd = is_int($lockCancelSourceStart)
    ? strpos(
        $controller,
        '} elseif (estab_workflow_cancelled_new_form ($returnValue))',
        $lockCancelSourceStart
    )
    : false;
$lockCancelSource = (
    is_int($lockCancelSourceStart)
    && is_int($lockCancelSourceEnd)
    && $lockCancelSourceEnd > $lockCancelSourceStart
) ? substr(
    $controller,
    $lockCancelSourceStart,
    $lockCancelSourceEnd - $lockCancelSourceStart
) : '';
$assert(
    $lockCancelSource !== ''
        && strpos($lockCancelSource, '$returnValue ["task"] = "";')
            < strpos($lockCancelSource, 'if ($cancelIsLead)')
        && str_contains($lockCancelSource, '$returnValue ["ldf"] = "";'),
    'FM-Ausgang or LdF cancel does not neutralize task after releasing its lock'
);

$assert(
    substr_count($controller, 'estab_workflow_should_render_primary_view (') >= 10
        && str_contains(
            $controller,
            '$workflowPrimaryView = estab_workflow_primary_view_selector ($returnValue);'
        )
        && str_contains(
            $controller,
            'estab_workflow_cancelled_new_form ($returnValue)'
        )
        && str_contains(
            $controller,
            'estab_workflow_acknowledged_read_form ($returnValue)'
        ),
    'main controller does not consistently dispatch through the central selector'
);
foreach (array_unique(array_merge($candidateViews, [
    'telecommunications-second-sighting',
    'viewer-second-sighting',
    'account-list',
    'logout',
])) as $view) {
    $assert(
        str_contains($controller, '"' . $view . '"'),
        'main controller lacks guarded renderer: ' . $view
    );
}
$assert(
    !str_contains($controller, '$workflowRolesEnforced'),
    'legacy role-alternative render dispatch remains in the controller'
);

estab_permission_context_set_from_incident([
    'active_einsatz_id' => 901,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
    'revision' => 42,
]);
$strictStaff = $identities['S1/Stab'];
unset($strictStaff['default'], $strictStaff['allowed_actions']);
$assert(
    estab_workflow_route_allowed(
        $strictStaff,
        'POST',
        ['stab_schreiben_x' => '1']
    )
        && !estab_workflow_route_allowed(
            $strictStaff,
            'POST',
            ['fm_eingang_x' => '1']
        ),
    'STRICT restore no longer enforces the fixed account function'
);

printf(
    "Workflow single-dispatch security tests: OK (%d assertions)\n",
    $assertions
);
