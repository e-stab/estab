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
$assert(
    estab_workflow_public_login_request(['REQUEST_METHOD' => 'POST'], [], ['login' => 'Anmelden']),
    'login transition rejected'
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
foreach ([
    ['REQUEST_METHOD' => 'GET', 'get' => ['reset_record' => '1'], 'post' => []],
    ['REQUEST_METHOD' => 'POST', 'get' => [], 'post' => ['login' => 'Anmelden', 'reset_record' => '1']],
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
        && str_contains($mainController, 'estab_workflow_record_id'),
    'main controller does not enforce the central workflow gate'
);

printf("Workflow security tests: OK (%d assertions)\n", $assertions);
