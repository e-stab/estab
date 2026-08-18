<?php

declare(strict_types=1);

/**
 * Every class the message list renders must have a rule in the stylesheet.
 *
 * The list encodes the workflow state and the priority of a message in class
 * names. Six of those names had no rule at all, so "Sofort" and "Blitz" looked
 * exactly like routine traffic and the workflow state carried no colour. This
 * test renders the real table for every state and priority the domain knows
 * and holds each emitted class against the stylesheet, so a name can no longer
 * be introduced without the rule that gives it meaning.
 */

$root = dirname(__DIR__, 2);
$originalWorkingDirectory = getcwd();
if (!is_string($originalWorkingDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
} finally {
    chdir($originalWorkingDirectory);
}
require_once $root . '/app/message_list.php';
require_once $root . '/app/message_list_ui.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$stylesheet = file_get_contents($root . '/estab-ui.css');
if (!is_string($stylesheet)) {
    throw new RuntimeException('Could not read estab-ui.css');
}
preg_match_all('~\.([A-Za-z][\w-]*)~', $stylesheet, $ruleMatches);
$styled = array_flip($ruleMatches[1]);

// Cover every workflow state and every priority the domain defines.
$states = [0, 1, 2, 4, 8, 10, 99];
$priorities = ['', 'eee', 'sss', 'bbb', 'aaa'];
$rows = [];
$recordId = 0;
foreach ($states as $state) {
    foreach ($priorities as $priority) {
        $recordId++;
        $rows[] = [
            '00_lfd' => $recordId,
            '04_richtung' => $recordId % 2 === 0 ? 'E' : 'A',
            '04_nummer' => $recordId,
            '05_gegenstelle' => 'Abschnitt Nord',
            '09_vorrangstufe' => $priority,
            '10_anschrift' => 'S3',
            '12_betreff' => $recordId % 3 === 0 ? '' : 'Lagemeldung',
            '12_inhalt' => 'Inhalt der Nachricht',
            '12_abfzeit' => '2026-07-31 12:36:00',
            '13_abseinheit' => 'Abschnitt Süd',
            '14_funktion' => 'S4',
            '16_empf' => 'S2_rt,S3_bl,',
            'x00_status' => $state,
        ];
    }
}
$assert($rows !== [], 'No rows were built for the rendering check');

ob_start();
try {
    estab_message_list_render_table($rows, static function (array $row): void {
        echo '<a class="estab-button estab-message-list-open" href="/detail">'
            . 'Vordruck öffnen</a>';
    });
    $markup = (string) ob_get_contents();
} finally {
    ob_end_clean();
}
$assert($markup !== '', 'The message list rendered nothing');

// Only real class attributes count, so data-* hooks cannot mask a gap.
preg_match_all('~\sclass="([^"]*)"~', $markup, $classMatches);
$rendered = [];
foreach ($classMatches[1] as $attribute) {
    foreach (preg_split('~\s+~', trim($attribute)) ?: [] as $class) {
        if ($class !== '') {
            $rendered[$class] = true;
        }
    }
}
$assert(
    count($rendered) >= 10,
    'The rendered markup carries almost no classes; the check would be empty'
);

$missing = [];
foreach (array_keys($rendered) as $class) {
    if (!isset($styled[$class])) {
        $missing[] = $class;
    }
}
sort($missing, SORT_STRING);
$assert(
    $missing === [],
    'The message list renders classes without a stylesheet rule: '
        . implode(', ', $missing)
);

// The state and priority names must be present, otherwise the check above
// passes on a table that no longer distinguishes anything.
foreach ([
    'estab-message-list-status--active',
    'estab-message-list-status--done',
    'estab-message-list-status--returned',
    'estab-message-list-status--neutral',
    'estab-message-list-row--priority',
    'estab-message-list-priority--urgent',
] as $expected) {
    $assert(
        isset($rendered[$expected]),
        'The list no longer renders ' . $expected
            . ', so its meaning is not covered'
    );
}

// A state must be visually distinguishable, not merely named.
$declarations = static function (string $selector) use ($stylesheet): string {
    $pattern = '~(?:^|[},])\s*' . preg_quote($selector, '~')
        . '\s*(?:,[^{]*)?\{([^}]*)\}~m';
    if (preg_match($pattern, $stylesheet, $match) !== 1) {
        return '';
    }
    return $match[1];
};
$statePaint = [];
foreach ([
    '.estab-message-list-status--active',
    '.estab-message-list-status--done',
    '.estab-message-list-status--returned',
    '.estab-message-list-status--neutral',
] as $selector) {
    $body = $declarations($selector);
    $assert(
        $body !== '' && str_contains($body, 'background')
            && str_contains($body, 'color'),
        'Selector ' . $selector . ' does not paint a background and a colour'
    );
    $statePaint[$selector] = preg_replace('~\s+~', ' ', trim($body));
}
$assert(
    count(array_unique($statePaint)) === count($statePaint),
    'Two workflow states are painted identically and cannot be told apart'
);

$priorityRow = $declarations('.estab-message-list-row.estab-message-list-row--priority');
$assert(
    $priorityRow !== '' && str_contains($priorityRow, 'box-shadow'),
    'A priority row carries no edge marker'
);
$urgentBadge = $declarations('.estab-message-list-priority--urgent::before');
$assert(
    $urgentBadge !== '' && str_contains($urgentBadge, 'content'),
    'The priority badge has no marker that works without colour'
);

printf(
    "list style coverage: OK (%d assertions, %d classes rendered)\n",
    $assertions,
    count($rendered)
);
