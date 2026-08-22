<?php

declare(strict_types=1);

/**
 * A list that refreshes itself must not fight the person reading it.
 *
 * The Sichter, LdF and Fernmelder lists carried a <meta http-equiv="refresh">
 * every ten seconds, the staff reading list every two minutes. That reload is
 * unconditional and cannot be stopped: it discarded the search term and the
 * scroll position, and whoever was typing typed into a page that vanished.
 * The replacement postpones while somebody is working in the page and puts the
 * scroll position back afterwards.
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

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// No list may carry an unconditional meta refresh any more.
$toolsSource = file_get_contents($root . '/4fach/tools.php');
if (!is_string($toolsSource)) {
    throw new RuntimeException('Could not read 4fach/tools.php');
}
foreach (['fmdliste', 'stabliste', 'siliste', 'si2liste'] as $list) {
    $marker = '$cfg ["itv"] ["' . $list . '"]';
    $offset = strpos($toolsSource, $marker);
    $assert(
        $offset !== false,
        'The refresh interval of ' . $list . ' is no longer used'
    );
    $line = substr(
        $toolsSource,
        (int) strrpos(substr($toolsSource, 0, (int) $offset), "\n"),
        200
    );
    $assert(
        str_contains($line, 'estab_list_refresh_script'),
        'List ' . $list . ' still reloads through an unconditional meta refresh'
    );
}

// The interval is validated, not echoed.
$assert(
    estab_list_refresh_script('abc') === '',
    'A non-numeric interval reaches the page'
);
$assert(
    estab_list_refresh_script(2) === '',
    'An interval below the lower bound is accepted and hammers the server'
);
$assert(
    estab_list_refresh_script(99999) === '',
    'An interval above the upper bound is accepted'
);
$assert(
    estab_list_refresh_script('10') !== '',
    'A numeric string interval is rejected although it is valid'
);

$script = estab_list_refresh_script(10);
$assert(
    str_starts_with($script, '<script nonce="')
        && str_contains($script, ' data-estab-list-refresh="10">')
        && str_ends_with(rtrim($script), '</script>'),
    'The refresh script is not a closed script element'
);
$assert(
    !str_contains($script, 'http-equiv'),
    'The refresh still runs through a meta element'
);

// It has to wait while somebody works in the page.
foreach ([
    "tag==='input'" => 'a text field',
    "tag==='textarea'" => 'a multi-line field',
    "tag==='select'" => 'a selection',
    'isContentEditable' => 'an editable area',
    'getSelection' => 'a marked text',
] as $needle => $what) {
    $assert(
        str_contains($script, $needle),
        'The refresh does not wait for ' . $what
    );
}
$assert(
    str_contains($script, 'schedule(5000)'),
    'The refresh gives up instead of trying again shortly after'
);

// And it has to restore where the reader was.
$assert(
    str_contains($script, 'sessionStorage')
        && str_contains($script, 'window.scrollTo'),
    'The refresh does not restore the scroll position'
);
$assert(
    str_contains($script, 'estab-list-scroll:')
        && str_contains($script, 'window.location.pathname'),
    'The stored scroll position is not bound to the list it belongs to'
);
$assert(
    str_contains($script, "schedule(10000)"),
    'The configured interval does not reach the scheduler'
);

// The scheduler must run once, not stack up timers on every tick.
$assert(
    substr_count($script, 'window.setTimeout') === 1,
    'The refresh schedules more than one timer and drifts'
);

printf("list refresh: OK (%d assertions)\n", $assertions);
