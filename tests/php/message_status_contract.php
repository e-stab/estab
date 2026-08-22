<?php

declare(strict_types=1);

/**
 * The workflow states have names, and the names mean what the schema stores.
 *
 * The numbers 0, 1, 2, 4, 8 and 10 appeared as bare literals across ten files.
 * A constant is only worth something if it cannot drift from the value the
 * database actually holds, so this test pins every constant to the schema and
 * proves that the label and the class of a state agree with it.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/message_status.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// The numbers come from the imported schema and may not move.
$expected = [
    'ESTAB_MESSAGE_STATUS_DRAFT' => [ESTAB_MESSAGE_STATUS_DRAFT, 0],
    'ESTAB_MESSAGE_STATUS_LDF' => [ESTAB_MESSAGE_STATUS_LDF, 1],
    'ESTAB_MESSAGE_STATUS_TRANSPORT' => [ESTAB_MESSAGE_STATUS_TRANSPORT, 2],
    'ESTAB_MESSAGE_STATUS_REVIEW' => [ESTAB_MESSAGE_STATUS_REVIEW, 4],
    'ESTAB_MESSAGE_STATUS_CLOSED' => [ESTAB_MESSAGE_STATUS_CLOSED, 8],
    'ESTAB_MESSAGE_STATUS_RETURNED' => [ESTAB_MESSAGE_STATUS_RETURNED, 10],
];
foreach ($expected as $name => [$actual, $value]) {
    $assert(
        $actual === $value,
        'Constant ' . $name . ' no longer holds the stored value ' . $value
    );
}
$assert(
    estab_message_status_values()
        === array_map(static fn (array $pair): int => $pair[1], array_values($expected)),
    'The list of states does not match the constants'
);

// Parsing is strict: no coercion, no unknown value.
$assert(
    estab_message_status('8') === ESTAB_MESSAGE_STATUS_CLOSED
        && estab_message_status(8) === ESTAB_MESSAGE_STATUS_CLOSED,
    'A stored state is not parsed'
);
foreach ([3, 9, 11, -1, '8abc', 'x', null, true, [], 1.5] as $rejected) {
    $assert(
        estab_message_status($rejected) === null,
        'An impossible state was accepted: ' . var_export($rejected, true)
    );
}

// Every state has a name, and no two states share one.
$names = [];
foreach (estab_message_status_values() as $value) {
    $name = estab_message_status_name($value);
    $assert(
        $name !== '' && $name !== 'Unbekannter Stand',
        'State ' . $value . ' has no name'
    );
    $names[] = $name;
}
$assert(
    count(array_unique($names)) === count($names),
    'Two states share the same name: ' . implode(', ', $names)
);
$assert(
    estab_message_status_name(3) === 'Unbekannter Stand'
        && estab_message_status_name('x') === 'Unbekannter Stand',
    'An impossible state is given a name'
);

// The routes are closed and reach the end from every start.
$transitions = estab_message_status_transitions();
$assert(
    array_keys($transitions) === ['incoming', 'outgoing', 'conversation-note'],
    'The catalogue of routes changed shape'
);
foreach ($transitions as $kind => $steps) {
    $assert($steps !== [], 'Route ' . $kind . ' has no steps');
    $reachable = [ESTAB_MESSAGE_STATUS_DRAFT];
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($steps as $step) {
            $assert(
                estab_message_status($step['from']) !== null
                    && estab_message_status($step['to']) !== null,
                'Route ' . $kind . ' uses a state that does not exist'
            );
            $assert(
                is_string($step['station']) && $step['station'] !== '',
                'A step of route ' . $kind . ' names no station'
            );
            if (
                in_array($step['from'], $reachable, true)
                && !in_array($step['to'], $reachable, true)
            ) {
                $reachable[] = $step['to'];
                $changed = true;
            }
        }
    }
    $assert(
        in_array(ESTAB_MESSAGE_STATUS_CLOSED, $reachable, true),
        'Route ' . $kind . ' never reaches the closed state'
    );
}

// The conversation note ends with the Sichter: it knows neither disposition
// nor transport.
$conversation = $transitions['conversation-note'];
foreach ($conversation as $step) {
    $assert(
        $step['from'] !== ESTAB_MESSAGE_STATUS_TRANSPORT
            && $step['to'] !== ESTAB_MESSAGE_STATUS_TRANSPORT
            && $step['to'] !== ESTAB_MESSAGE_STATUS_LDF,
        'The conversation note route passes disposition or transport'
    );
}

// The literals are gone from the places that used to carry them.
foreach ([
    'app/message_repository.php',
    'app/message_list_ui.php',
] as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    $assert(is_string($source), 'Could not read ' . $relative);
    $assert(
        preg_match('~\$status === (?:0|1|2|4|8|10)\b~', (string) $source) !== 1,
        $relative . ' still compares the workflow state against a bare number'
    );
}

printf("message status contract: OK (%d assertions)\n", $assertions);
