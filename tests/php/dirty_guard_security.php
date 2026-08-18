<?php

declare(strict_types=1);

/**
 * Half-filled entries must not disappear on a side click.
 *
 * The guard against unsaved input only ran when a form carried the logout
 * class. Every button of the sidebar action bar -- the usual way out of a
 * half-filled message form -- submitted straight past it and discarded the
 * entries without asking. The guard now covers the area buttons as well, while
 * the window handling of the logout stays reserved for the logout.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/session_ui.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$script = estab_session_ui_dirty_guard_script();
$assert(
    $script !== '' && str_starts_with($script, '<script data-estab-dirty-guard>'),
    'The guard against unsaved input is no longer emitted'
);

// The guard has to know both ways out of a form.
foreach ([
    '.estab-session-logout' => 'the logout',
    '.estab-sidebar-action-form' => 'the area buttons of the sidebar',
] as $selector => $what) {
    $assert(
        str_contains($script, $selector),
        'The guard against unsaved input does not cover ' . $what
    );
}

// Both ways must reach the confirmation before the submit goes through.
$assert(
    preg_match(
        '~isLogout\s*=\s*form\.matches\("\.estab-session-logout"\)~',
        $script
    ) === 1
    && preg_match(
        '~isAreaSwitch\s*=\s*form\.matches\("\.estab-sidebar-action-form"\)~',
        $script
    ) === 1,
    'The guard no longer distinguishes the logout from an area switch'
);
$guardOffset = strpos($script, 'if(!isLogout&&!isAreaSwitch){return;}');
$approveOffset = strpos($script, 'if(!approve()){event.preventDefault();return;}', (int) $guardOffset);
$assert(
    $guardOffset !== false && $approveOffset !== false
        && $approveOffset > $guardOffset,
    'An area switch reaches the submit without asking about unsaved input'
);

// The window handling belongs to the logout alone.
$logoutOnlyOffset = strpos($script, 'if(!isLogout){return;}');
$windowOffset = strpos($script, 'window.setTimeout(function(){window.close();}');
$assert(
    $logoutOnlyOffset !== false && $windowOffset !== false
        && $logoutOnlyOffset > $approveOffset
        && $windowOffset > $logoutOnlyOffset,
    'An area switch now also closes the application window'
);

// The confirmation must name what is at stake.
$assert(
    str_contains($script, 'Ungespeicherte Eingaben gehen beim Bereichswechsel verloren.'),
    'The confirmation no longer says what is lost'
);

// A form the guard does not know must pass through untouched, otherwise every
// save of the message form would ask.
$assert(
    str_contains($script, 'form[data-estab-dirty-guard]'),
    'The guard no longer looks at the forms that actually carry entries'
);
$assert(
    !str_contains($script, 'form.matches("form")'),
    'The guard intercepts every form and therefore also every save'
);

// The sidebar must actually mark its action forms.
$sidebarSource = file_get_contents($root . '/4fach/vorgaben.php');
if (!is_string($sidebarSource)) {
    throw new RuntimeException('Could not read 4fach/vorgaben.php');
}
$assert(
    str_contains($sidebarSource, 'class="estab-sidebar-action-form"'),
    'The sidebar action forms no longer carry the class the guard looks for'
);

printf("dirty guard: OK (%d assertions)\n", $assertions);
