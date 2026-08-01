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

$identities = [
    'S1/Stab' => [
        'benutzer' => 'Single Dispatch S1',
        'kuerzel' => 'sd_s1',
        'funktion' => 'S1',
        'rolle' => 'Stab',
        'default' => 'staff-list',
    ],
    'Si/Stab' => [
        'benutzer' => 'Single Dispatch Si',
        'kuerzel' => 'sd_si',
        'funktion' => 'Si',
        'rolle' => 'Stab',
        'default' => 'viewer-list',
    ],
    'A/W/Fernmelder' => [
        'benutzer' => 'Single Dispatch A-W',
        'kuerzel' => 'sd_aw',
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
        'default' => 'telecommunications-outgoing-list',
    ],
    'LdF/Fernmelder' => [
        'benutzer' => 'Single Dispatch LdF',
        'kuerzel' => 'sd_ldf',
        'funktion' => 'LdF',
        'rolle' => 'Fernmelder',
        'default' => 'telecommunications-lead-list',
    ],
    'POL/FB' => [
        'benutzer' => 'Single Dispatch POL',
        'kuerzel' => 'sd_pol',
        'funktion' => 'POL',
        'rolle' => 'FB',
        'default' => 'staff-list',
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
    $identity = $identityWithDefault;
    unset($identity['default']);

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
        $assert(
            estab_workflow_route_allowed($identity, 'POST', $request),
            $identityLabel . ': LOOSE action was rejected: ' . $button
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
            $identityLabel . ': explicit LOOSE action also rendered the account default: '
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
unset($strictStaff['default']);
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
