<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/sidebar.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertThrows = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (InvalidArgumentException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$session = [
    'vStab_benutzer' => 'Müller, Ada',
    'vStab_kuerzel' => 'ada001',
    'vStab_funktion' => 'S1',
    'vStab_rolle' => 'Stab',
];
$identity = [
    'benutzer' => 'Müller, Ada',
    'kuerzel' => 'ada001',
    'funktion' => 'S1',
    'rolle' => 'Stab',
];
$invalidUtf8 = "\xC3\x28";
$now = new DateTimeImmutable('2026-07-28T19:03:00+02:00');
$activityAt = static function (string $modifier) use ($now): string {
    return $now->setTimezone(new DateTimeZone('UTC'))
        ->modify($modifier)
        ->format('Y-m-d H:i:s.u');
};
$configuredPositions = [
    1 => ['rolle' => 'Stab', 'fkt' => 'LS'],
    2 => ['rolle' => ' Stab ', 'fkt' => ' S1 '],
    3 => ['rolle' => 'Fernmelder', 'fkt' => 'A/W'],
    4 => ['rolle' => 'FB', 'fkt' => 'THW'],
    5 => ['rolle' => 'Stab', 'fkt' => 'S1'],
    6 => 'not-an-array',
    7 => ['rolle' => $invalidUtf8, 'fkt' => 'broken'],
    8 => ['rolle' => str_repeat('r', 81), 'fkt' => 'too-long'],
    9 => ['rolle' => 'Stab', 'fkt' => ''],
];
$users = [
    [
        'kuerzel' => 'ada001',
        'rolle' => 'Stab',
        'funktion' => 'S1',
        'sid' => 'sidebar-current-1',
        'aktiv' => 1,
        'estab_gesperrt' => 0,
        'estab_letzte_aktivitaet' => $activityAt('-1 minute'),
    ],
    [
        'kuerzel' => 'aw001',
        'rolle' => 'Fernmelder',
        'funktion' => 'A/W',
        'sid' => 'sidebar-aw-1',
        'aktiv' => 1,
        'estab_gesperrt' => 0,
        'estab_letzte_aktivitaet' => $activityAt('-2 minutes'),
    ],
    [
        'kuerzel' => 'aw002',
        'rolle' => 'Fernmelder',
        'funktion' => 'A/W',
        'sid' => 'sidebar-aw-2',
        'aktiv' => 1,
        'estab_gesperrt' => 0,
        'estab_letzte_aktivitaet' => $activityAt('-16 minutes'),
    ],
    [
        'kuerzel' => 'xss001',
        'rolle' => '<script>',
        'funktion' => 'X&"',
        'sid' => 'sidebar-xss-1',
        'aktiv' => 1,
        'estab_gesperrt' => 0,
        'estab_letzte_aktivitaet' => $activityAt('-3 minutes'),
    ],
    [
        'kuerzel' => 'thw001',
        'rolle' => 'FB',
        'funktion' => 'THW',
        'sid' => 'sidebar-thw-1',
        'aktiv' => 1,
        'estab_gesperrt' => 0,
        'estab_letzte_aktivitaet' => $activityAt('-16 minutes'),
    ],
    [
        'rolle' => 'Inactive',
        'funktion' => 'Never',
        'aktiv' => 0,
    ],
    [
        'rolle' => ['invalid'],
        'funktion' => 'Array',
        'sid' => 'sidebar-invalid-1',
        'aktiv' => 1,
        'estab_gesperrt' => 0,
        'estab_letzte_aktivitaet' => $activityAt('-1 minute'),
    ],
];

$positions = estab_sidebar_positions(
    $configuredPositions,
    $users,
    $now
);
$assert(
    $positions === [
        ['rolle' => 'Stab', 'funktion' => 'LS'],
        ['rolle' => 'Stab', 'funktion' => 'S1'],
        ['rolle' => 'Fernmelder', 'funktion' => 'A/W'],
        ['rolle' => 'FB', 'funktion' => 'THW'],
        ['rolle' => '<script>', 'funktion' => 'X&"'],
    ],
    'sidebar positions are not ordered, deduplicated, trimmed, or validated'
);

$identityOnly = estab_sidebar_positions([], [], $now);
$assert(
    $identityOnly === [],
    'sidebar invented a presence function without a database account row'
);

$queueProfiles = [
    'ldf' => estab_sidebar_queue_profile([
        'rolle' => 'Fernmelder',
        'funktion' => 'LdF',
    ]),
    'aw' => estab_sidebar_queue_profile([
        'rolle' => 'Fernmelder',
        'funktion' => 'A/W',
    ]),
    'si' => estab_sidebar_queue_profile([
        'rolle' => 'Stab',
        'funktion' => 'Si',
    ]),
    'stab' => estab_sidebar_queue_profile([
        'rolle' => 'Stab',
        'funktion' => 'S1',
    ]),
    'fb' => estab_sidebar_queue_profile([
        'rolle' => 'FB',
        'funktion' => 'THW',
    ]),
    'etb' => estab_sidebar_queue_profile([
        'rolle' => 'Stab',
        'funktion' => 'ETB',
    ]),
    'anonymous' => estab_sidebar_queue_profile(null),
];
$assert(
    $queueProfiles === [
        'ldf' => [
            'session_key' => 'old_que_ldf',
            'sound_file' => 'notify_aw.wav',
            'label' => 'Bei LdF',
            'funktion' => 'LdF',
        ],
        'aw' => [
            'session_key' => 'old_que_aw',
            'sound_file' => 'notify_aw.wav',
            'label' => 'Im Ausgang',
            'funktion' => 'A/W',
        ],
        'si' => [
            'session_key' => 'old_que_si',
            'sound_file' => 'notify_si.wav',
            'label' => 'Zu sichten',
            'funktion' => 'Si',
        ],
        'stab' => [
            'session_key' => 'old_que_stab',
            'sound_file' => 'notify_stab.wav',
            'label' => 'Offene Meldungen',
            'funktion' => 'S1',
        ],
        'fb' => [
            'session_key' => 'old_que_stab',
            'sound_file' => 'notify_stab.wav',
            'label' => 'Offene Meldungen',
            'funktion' => 'THW',
        ],
        'etb' => null,
        'anonymous' => null,
    ],
    'role-specific queue, label, or notification sound mapping changed'
);
$assertThrows(
    static fn (): ?array => estab_sidebar_queue_profile([
        'rolle' => 'Administrator',
        'funktion' => 'Admin',
    ]),
    'non-operational administrator identity accepted for an operational queue'
);
$assertThrows(
    static fn (): ?array => estab_sidebar_queue_profile([
        'rolle' => ['invalid'],
        'funktion' => 'S1',
    ]),
    'malformed queue identity accepted'
);

$ldfQueueQuery = estab_sidebar_queue_query(
    'old_que_ldf',
    'nv_nachrichten',
    'usr_',
    'LdF',
    false,
    42
);
$awQueueQuery = estab_sidebar_queue_query(
    'old_que_aw',
    'nv_nachrichten',
    'usr_',
    'A/W',
    false,
    42
);
$siIncomingQuery = estab_sidebar_queue_query(
    'old_que_si',
    'nv_nachrichten',
    'usr_',
    'Si',
    false,
    42
);
$siCombinedQuery = estab_sidebar_queue_query(
    'old_que_si',
    'nv_nachrichten',
    'usr_',
    'Si',
    true,
    42
);
$staffQueueQuery = estab_sidebar_queue_query(
    'old_que_stab',
    'nv_nachrichten',
    'usr_',
    'S1',
    false,
    42
);
$assert(
    $ldfQueueQuery['parameters'] === [42]
        && str_contains($ldfQueueQuery['sql'], '`einsatz_id` = ?')
        && str_contains($ldfQueueQuery['sql'], '`x00_status` = 1')
        && str_contains($ldfQueueQuery['sql'], "`04_richtung` IN ('E','A')")
        && str_contains($ldfQueueQuery['sql'], "`04_richtung` = 'E'")
        && str_contains($ldfQueueQuery['sql'], "`04_richtung` = 'A'")
        && str_contains($ldfQueueQuery['sql'], '`15_quitdatum` IS NOT NULL')
        && str_contains($ldfQueueQuery['sql'], "`15_quitzeichen` != ''")
        && str_contains($ldfQueueQuery['sql'], '`02_zeit` IS NULL')
        && str_contains($ldfQueueQuery['sql'], "`02_zeichen` = ''"),
    'LdF queue allows an outgoing message without Sichter approval'
);
$assert(
    $awQueueQuery['parameters'] === [42]
        && str_contains($awQueueQuery['sql'], '`nv_nachrichten`')
        && str_contains($awQueueQuery['sql'], '`einsatz_id` = ?')
        && str_contains($awQueueQuery['sql'], "`04_richtung` = 'A'")
        && str_contains($awQueueQuery['sql'], '`03_datum` IS NULL')
        && str_contains($awQueueQuery['sql'], "`03_zeichen` = ''")
        && str_contains($awQueueQuery['sql'], '`15_quitdatum` IS NOT NULL')
        && str_contains($awQueueQuery['sql'], "`15_quitzeichen` != ''"),
    'outgoing queue allows a message without Sichter approval'
);
$assert(
    $siIncomingQuery['parameters'] === [42]
        && str_contains($siIncomingQuery['sql'], '`einsatz_id` = ?')
        && str_contains($siIncomingQuery['sql'], '`x00_status` = 4')
        && str_contains(
            $siIncomingQuery['sql'],
            "`04_richtung` IN ('E','A')"
        )
        && !str_contains($siIncomingQuery['sql'], '`03_datum` IS NOT NULL')
        && str_contains($siCombinedQuery['sql'], '`x00_status` = 4')
        && str_contains(
            $siCombinedQuery['sql'],
            "`04_richtung` IN ('E','A')"
        )
        && $siIncomingQuery === $siCombinedQuery,
    'mandatory review queue can still exclude outgoing messages'
);
$assert(
    $staffQueueQuery['parameters'] === [
        42,
        '(^|,)[[:space:]]*(alle|S1)(_[^,[:space:]]+)?[[:space:]]*(,|$)',
        'S1',
        42,
        '(^|,)[[:space:]]*(alle|S1)(_[^,[:space:]]+)?[[:space:]]*(,|$)',
        'S1',
    ]
        && substr_count($staffQueueQuery['sql'], '`nv_nachrichten`') === 2
        && substr_count($staffQueueQuery['sql'], '`einsatz_id` = ?') === 2
        && str_contains(
            $staffQueueQuery['sql'],
            '(all_messages.`x00_status` = 8'
                . ' AND all_messages.`16_empf` REGEXP ?)'
        )
        && str_contains(
            $staffQueueQuery['sql'],
            "(all_messages.`04_richtung` = 'A'"
                . ' AND BINARY all_messages.`14_funktion` = BINARY ?)'
        )
        && str_contains(
            $staffQueueQuery['sql'],
            '(done_messages.`x00_status` = 8'
                . ' AND done_messages.`16_empf` REGEXP ?)'
        )
        && str_contains($staffQueueQuery['sql'], '`usr__fkt_s1_erl`')
        && str_contains(
            $staffQueueQuery['sql'],
            '`done_messages`.`00_lfd` = `done_state`.`nachnum`'
        ),
    'staff queue is not aligned with exact terminal-recipient/own-output access'
);
foreach (
    [
        ['unknown', 'nv_nachrichten', 'usr_', 'S1'],
        ['old_que_aw', 'bad`;', 'usr_', 'A/W'],
        ['old_que_stab', 'nv_nachrichten', 'bad`', 'S1'],
        ['old_que_stab', 'nv_nachrichten', 'usr_', 'S/1'],
    ] as $invalidQueueQuery
) {
    $assertThrows(
        static fn (): array => estab_sidebar_queue_query(
            $invalidQueueQuery[0],
            $invalidQueueQuery[1],
            $invalidQueueQuery[2],
            $invalidQueueQuery[3],
            false,
            42
        ),
        'unsafe queue profile or table identifier accepted'
    );
}
$assertThrows(
    static fn (): array => estab_sidebar_queue_query(
        'old_que_aw',
        'nv_nachrichten',
        'usr_',
        'A/W',
        false,
        0
    ),
    'invalid incident identifier accepted for the queue scope'
);

$audioUrls = [
    'old_que_aw' => '/4fach/audio/notify_aw.wav',
    'old_que_si' => '/4fach/audio/notify_si.wav',
    'old_que_stab' => '/4fach/audio/notify_stab.wav',
];
$notificationSession = [];
$assert(
    estab_sidebar_queue_notification(
        $notificationSession,
        'old_que_aw',
        2,
        true,
        $audioUrls['old_que_aw']
    ) === null
        && ($notificationSession['old_que_aw'] ?? null) === 2,
    'first queue measurement emitted a notification or lost its baseline'
);
$assert(
    estab_sidebar_queue_notification(
        $notificationSession,
        'old_que_aw',
        3,
        true,
        $audioUrls['old_que_aw']
    ) === $audioUrls['old_que_aw']
        && estab_sidebar_queue_notification(
            $notificationSession,
            'old_que_aw',
            3,
            true,
            $audioUrls['old_que_aw']
        ) === null,
    'one queue increase did not emit its sound exactly once'
);
$assert(
    estab_sidebar_queue_notification(
        $notificationSession,
        'old_que_aw',
        1,
        true,
        $audioUrls['old_que_aw']
    ) === null
        && estab_sidebar_queue_notification(
            $notificationSession,
            'old_que_aw',
            1,
            true,
            $audioUrls['old_que_aw']
        ) === null
        && ($notificationSession['old_que_aw'] ?? null) === 1,
    'equal or decreasing queue measurements emitted a notification'
);
$assert(
    estab_sidebar_queue_notification(
        $notificationSession,
        'old_que_aw',
        5,
        false,
        $audioUrls['old_que_aw']
    ) === null
        && ($notificationSession['old_que_aw'] ?? null) === 5
        && estab_sidebar_queue_notification(
            $notificationSession,
            'old_que_aw',
            5,
            true,
            $audioUrls['old_que_aw']
        ) === null,
    'disabled sounds failed to advance the queue baseline safely'
);
$baselineBeforeUnavailableQueue = $notificationSession['old_que_aw'];
$assert(
    estab_sidebar_queue_notification(
        $notificationSession,
        'old_que_aw',
        null,
        true,
        $audioUrls['old_que_aw']
    ) === null
        && $notificationSession['old_que_aw']
            === $baselineBeforeUnavailableQueue,
    'unavailable queue measurement changed the last successful baseline'
);

$allowedQueueSignals = [];
foreach ($audioUrls as $queueKey => $audioUrl) {
    $queueSession = [];
    $first = estab_sidebar_queue_notification(
        $queueSession,
        $queueKey,
        0,
        true,
        $audioUrl
    );
    $allowedQueueSignals[$queueKey] = [
        $first,
        estab_sidebar_queue_notification(
            $queueSession,
            $queueKey,
            1,
            true,
            $audioUrl
        ),
        $queueSession[$queueKey] ?? null,
    ];
}
$assert(
    $allowedQueueSignals === [
        'old_que_aw' => [null, $audioUrls['old_que_aw'], 1],
        'old_que_si' => [null, $audioUrls['old_que_si'], 1],
        'old_que_stab' => [null, $audioUrls['old_que_stab'], 1],
    ],
    'legacy A/W, Si, or Stab queue baseline is not supported consistently'
);
$assertThrows(
    static function (): void {
        $invalidSession = [];
        estab_sidebar_queue_notification(
            $invalidSession,
            'old_que_admin',
            1,
            true,
            '/4fach/audio/notify_aw.wav'
        );
    },
    'unrecognised queue session key accepted'
);
$assertThrows(
    static function (): void {
        $invalidSession = [];
        estab_sidebar_queue_notification(
            $invalidSession,
            null,
            1,
            true,
            '/4fach/audio/notify_aw.wav'
        );
    },
    'queue measurement without a baseline key accepted'
);
foreach (
    [
        '',
        '4fach/audio/notify_aw.wav',
        '//example.invalid/notify_aw.wav',
        'https://attacker.invalid/notify_aw.wav',
        'javascript:notify_aw.wav',
        '/4fach/audio/notify_aw.mp3',
        "/4fach/audio/notify_aw.wav\n",
        "/4fach/audio/\xC3\x28.wav",
        '/4fach/audio/notify_aw.wav#fragment',
    ] as $invalidAudioUrl
) {
    $assertThrows(
        static function () use ($invalidAudioUrl): void {
            $invalidSession = ['old_que_aw' => 0];
            estab_sidebar_queue_notification(
                $invalidSession,
                'old_que_aw',
                1,
                false,
                $invalidAudioUrl
            );
        },
        'invalid sidebar notification URL accepted'
    );
}
$originalPublicUrl = getenv('ESTAB_PUBLIC_URL');
$originalBasePath = getenv('ESTAB_BASE_PATH');
putenv('ESTAB_PUBLIC_URL=https://example.invalid/gateway');
putenv('ESTAB_BASE_PATH=dispatch');
$scopedAudioUrl =
    'https://example.invalid/gateway/dispatch/4fach/audio/notify_aw.wav';
$assert(
    estab_sidebar_valid_audio_url($scopedAudioUrl) === $scopedAudioUrl,
    'configured absolute application URL rejected for a bundled sound'
);
if ($originalPublicUrl === false) {
    putenv('ESTAB_PUBLIC_URL');
} else {
    putenv('ESTAB_PUBLIC_URL=' . $originalPublicUrl);
}
if ($originalBasePath === false) {
    putenv('ESTAB_BASE_PATH');
} else {
    putenv('ESTAB_BASE_PATH=' . $originalBasePath);
}

$markup = estab_sidebar_status_markup(
    $session,
    $configuredPositions,
    $users,
    'Offen & <b>"',
    7,
    $now
);
$assert(
    substr_count($markup, 'data-estab-sidebar-status') === 1
        && substr_count($markup, 'data-estab-queue-count') === 1
        && str_contains($markup, 'data-estab-queue-state="has-work"')
        && str_contains(
            $markup,
            'class="estab-sidebar-queue has-work"'
        )
        && str_contains($markup, '>7</strong>')
        && str_contains(
            $markup,
            'datetime="2026-07-28T19:03:00+02:00"'
        )
        && str_contains($markup, '<strong>19:03</strong>')
        && str_contains($markup, '<span>28.07.2026</span>')
        && str_contains($markup, 'data-estab-sidebar-freshness')
        && str_contains(
            $markup,
            'data-estab-status-freshness="current"'
        )
        && str_contains($markup, '>Status aktuell</span>')
        && str_contains($markup, '<h2>Aktivität nach Primärfunktion</h2>')
        && str_contains(
            $markup,
            'aria-label="Anmeldeaktivität nach Primärfunktion"'
        )
        && str_contains($markup, 'data-estab-notify="0"')
        && !str_contains($markup, 'data-estab-sound-toggle'),
    'sidebar status omitted queue, deterministic server time, date, or heading'
);
$assert(
    str_contains($markup, 'data-estab-online-count="3"')
        && str_contains($markup, '3 Personen aktiv')
        && str_contains($markup, '>2 Fernmelder</span>')
        && str_contains(
            $markup,
            'aria-label="Fernmelder: 1 aktiv, 1 inaktiv"'
        )
        && str_contains($markup, 'data-estab-presence-function="A/W"')
        && str_contains($markup, 'Aktiv</span>')
        && str_contains($markup, 'Inaktiv (15 Min.)</span>')
        && str_contains($markup, 'Ihre Primärfunktion</span>')
        && str_contains($markup, 'Abgemeldet</span>')
        && str_contains($markup, 'estab-sidebar-presence-mixed')
        && str_contains($markup, '>1 aktiv · 1 inaktiv</small>'),
    'sidebar presence summary lost counts, state text, or its visible legend'
);

$primaryFunctionPositions = $configuredPositions + [
    10 => ['rolle' => 'Stab', 'fkt' => 'S6'],
    11 => ['rolle' => 'Stab', 'fkt' => 'ETB'],
];
$strictHatSession = $session;
$strictHatSession['vStab_funktion'] = 'S6';
$strictHatSession['estab_permission_mode'] = 'STRICT';
$strictHatSession['estab_duty_assignment_id'] = 701;
$strictPresenceMarkup = estab_sidebar_status_markup(
    $strictHatSession,
    $primaryFunctionPositions,
    $users,
    'Offen',
    0,
    $now
);
$assert(
    str_contains(
        $strictPresenceMarkup,
        'data-estab-presence-state="current"'
            . ' data-estab-presence-role="Stab"'
            . ' data-estab-presence-function="S6"'
    )
        && str_contains(
            $strictPresenceMarkup,
            'data-estab-presence-state="offline"'
                . ' data-estab-presence-role="Stab"'
                . ' data-estab-presence-function="S1"'
        )
        && str_contains($strictPresenceMarkup, 'data-estab-online-count="3"')
        && str_contains(
            $strictPresenceMarkup,
            'Stab, Funktion S6: Ihre aktive Dienstfunktion, aktiv'
        ),
    'STRICT presence does not replace the account primary function with the '
        . 'selected duty hat exactly once'
);

$looseAdditionalSession = $session;
$looseAdditionalSession['estab_permission_mode'] = 'LOOSE';
$looseAdditionalSession['estab_additional_functions'] = [
    ['rolle' => 'Stab', 'funktion' => 'ETB'],
];
$loosePresenceMarkup = estab_sidebar_status_markup(
    $looseAdditionalSession,
    $primaryFunctionPositions,
    $users,
    'Offen',
    0,
    $now
);
$assert(
    str_contains(
        $loosePresenceMarkup,
        'data-estab-presence-state="current"'
            . ' data-estab-presence-role="Stab"'
            . ' data-estab-presence-function="S1"'
    )
        && str_contains(
            $loosePresenceMarkup,
            'data-estab-presence-state="offline"'
                . ' data-estab-presence-role="Stab"'
                . ' data-estab-presence-function="ETB"'
        )
        && str_contains($loosePresenceMarkup, 'data-estab-online-count="3"')
        && str_contains($loosePresenceMarkup, '>1 aktiv · 1 inaktiv</small>'),
    'LOOSE additional function was falsely counted as account presence or '
        . 'changed online/inactive totals'
);

$inactiveCurrentUsers = $users;
$inactiveCurrentUsers[0]['estab_letzte_aktivitaet'] = $activityAt('-16 minutes');
$inactiveCurrentMarkup = estab_sidebar_status_markup(
    $session,
    $configuredPositions,
    $inactiveCurrentUsers,
    'Offen',
    0,
    $now
);
$assert(
    str_contains(
        $inactiveCurrentMarkup,
        'estab-sidebar-presence-current-inactive'
    )
        && str_contains($inactiveCurrentMarkup, '>Sie: inaktiv</small>')
        && str_contains(
            $inactiveCurrentMarkup,
            'data-estab-current-activity="inactive"'
        ),
    'current user inactivity is not visibly distinguishable in the sidebar'
);

$stateMatches = [];
$stateResult = preg_match_all(
    '/data-estab-presence-state="([^"]+)"/',
    $markup,
    $stateMatches
);
$assert(
    $stateResult === 5
        && ($stateMatches[1] ?? []) === [
            'offline',
            'current',
            'online',
            'inactive',
            'online',
        ]
        && substr_count(
            $markup,
            'data-estab-presence-state="current"'
        ) === 1,
    'sidebar presence states are ambiguous or the current function is not unique'
);
$assert(
    str_contains($markup, 'Offen &amp; &lt;b&gt;&quot;')
        && str_contains(
            $markup,
            '&lt;script&gt;, Funktion X&amp;&quot;: aktiv'
        )
        && !str_contains($markup, 'Offen & <b>"')
        && !str_contains($markup, '<script>'),
    'queue label or database-backed presence text reached HTML unescaped'
);

$notificationMarkup = estab_sidebar_status_markup(
    $session,
    $configuredPositions,
    $users,
    'Offene Meldungen',
    8,
    $now,
    $audioUrls['old_que_aw'],
    $audioUrls['old_que_aw']
);
$assert(
    str_contains($notificationMarkup, 'data-estab-notify="1"')
        && str_contains(
            $notificationMarkup,
            'class="estab-sidebar-status estab-sidebar-status-notification"'
        )
        && str_contains($notificationMarkup, 'data-estab-sound-toggle')
        && str_contains(
            $notificationMarkup,
            'data-estab-sound-url="/4fach/audio/notify_aw.wav"'
        )
        && str_contains($notificationMarkup, 'aria-pressed="false"')
        && str_contains(
            $notificationMarkup,
            '<span data-estab-sound-label>Hinweistöne aktivieren</span>'
        ),
    'notification status omitted its signal or accessible sound toggle'
);
$assert(
    str_contains(
        $notificationMarkup,
        'data-estab-sound-feedback role="status" aria-live="polite"'
    )
        && str_contains(
            $notificationMarkup,
            'data-estab-queue-notification role="status"'
        )
        && str_contains(
            $notificationMarkup,
            'Neue Meldung in der Arbeitswarteschlange.'
        )
        && !str_contains($notificationMarkup, '<audio'),
    'notification feedback is inaccessible or recreates audio in the fragment'
);
$audioMarkup = estab_sidebar_audio_markup($audioUrls['old_que_aw']);
$assert(
    str_contains($audioMarkup, '<audio data-estab-sidebar-audio')
        && str_contains($audioMarkup, 'preload="auto"')
        && str_contains(
            $audioMarkup,
            'src="/4fach/audio/notify_aw.wav"'
        )
        && str_contains($audioMarkup, 'aria-hidden="true"')
        && estab_sidebar_audio_markup(null) === '',
    'long-lived sidebar audio player is missing or rendered without a source'
);
$assertThrows(
    static fn (): string => estab_sidebar_status_markup(
        $session,
        [],
        [],
        'Offene Meldungen',
        1,
        $now,
        $audioUrls['old_que_aw'],
        $audioUrls['old_que_si']
    ),
    'notification URL that differs from the persistent audio source accepted'
);

$unavailableMarkup = estab_sidebar_status_markup(
    $session,
    [['rolle' => 'Stab', 'fkt' => 'S1']],
    [],
    'Offene Meldungen',
    null,
    $now
);
$assert(
    str_contains(
        $unavailableMarkup,
        'class="estab-sidebar-queue unavailable"'
    )
        && str_contains(
            $unavailableMarkup,
            'data-estab-queue-state="unavailable"'
        )
        && str_contains($unavailableMarkup, '>–</strong>')
        && str_contains($unavailableMarkup, 'data-estab-online-count="0"')
        && str_contains(
            $unavailableMarkup,
            'data-estab-presence-state="offline"'
        )
        && !str_contains(
            $unavailableMarkup,
            'data-estab-presence-state="current"'
        ),
    'unavailable queue or absent current database row is falsely counted active'
);
$emptyQueueMarkup = estab_sidebar_status_markup(
    $session,
    [['rolle' => 'Stab', 'fkt' => 'S1']],
    [],
    'Offene Meldungen',
    0,
    $now
);
$assert(
    str_contains($emptyQueueMarkup, 'data-estab-queue-state="empty"')
        && str_contains(
            $emptyQueueMarkup,
            'class="estab-sidebar-queue empty"'
        )
        && !str_contains($emptyQueueMarkup, 'has-work'),
    'empty queue is presented as unresolved work'
);
$partialMarkup = estab_sidebar_status_markup(
    $session,
    [['rolle' => 'Stab', 'fkt' => 'S1']],
    [],
    'Offene Meldungen',
    null,
    $now,
    null,
    null,
    'partial'
);
$unavailableStatusMarkup = estab_sidebar_status_markup(
    $session,
    [['rolle' => 'Stab', 'fkt' => 'S1']],
    [],
    'Offene Meldungen',
    null,
    $now,
    null,
    null,
    'unavailable'
);
$assert(
    str_contains($partialMarkup, 'data-estab-status-data="partial"')
        && str_contains(
            $partialMarkup,
            'data-estab-status-freshness="partial"'
        )
        && str_contains($partialMarkup, 'Statusdaten unvollständig')
        && str_contains(
            $unavailableStatusMarkup,
            'data-estab-status-data="unavailable"'
        )
        && str_contains(
            $unavailableStatusMarkup,
            'Statusdaten nicht verfügbar'
        ),
    'server-side status failure is falsely rendered as current'
);
$assertThrows(
    static fn (): string => estab_sidebar_status_markup(
        $session,
        [],
        [],
        'Offene Meldungen',
        null,
        $now,
        null,
        null,
        'stale'
    ),
    'unrecognised server-side freshness state accepted'
);
$assert(
    estab_sidebar_status_markup(
        [],
        $configuredPositions,
        $users,
        'Offene Meldungen',
        1,
        $now
    ) === '',
    'anonymous session received live sidebar status'
);
$originalTimezone = date_default_timezone_get();
date_default_timezone_set('UTC');
$utcMarkup = estab_sidebar_status_markup(
    $session,
    [['rolle' => 'Stab', 'fkt' => 'S1']],
    [],
    'Offene Meldungen',
    0
);
date_default_timezone_set($originalTimezone);
$assert(
    preg_match(
        '/datetime="[0-9]{4}-[0-9]{2}-[0-9]{2}T'
            . '[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00"/',
        $utcMarkup
    ) === 1,
    'sidebar server time ignores the configured application timezone'
);
$assertThrows(
    static fn (): string => estab_sidebar_status_markup(
        $session,
        [],
        [],
        '',
        0,
        $now
    ),
    'empty queue label accepted'
);
$assertThrows(
    static fn (): string => estab_sidebar_status_markup(
        $session,
        [],
        [],
        str_repeat('x', 81),
        0,
        $now
    ),
    'overlong queue label accepted'
);
$assertThrows(
    static fn (): string => estab_sidebar_status_markup(
        $session,
        [],
        [],
        $invalidUtf8,
        0,
        $now
    ),
    'invalid UTF-8 queue label accepted'
);
$assertThrows(
    static fn (): string => estab_sidebar_status_markup(
        $session,
        [],
        [],
        'Offene Meldungen',
        -1,
        $now
    ),
    'negative queue count accepted'
);

$refreshUrl = '/gateway/4fach/vorgaben.php'
    . '?fragment=status&probe=</script>"';
$refresh = estab_sidebar_status_refresh_script($refreshUrl, 2);
$assert(
    str_contains($refresh, 'data-estab-sidebar-refresh')
        && str_contains($refresh, 'data-refresh-seconds="5"')
        && str_contains($refresh, 'data-timeout-ms="4500"')
        && str_contains(
            $refresh,
            '?fragment=status\u0026probe=\u003C/script\u003E\u0022'
        )
        && str_contains($refresh, 'response.status===401')
        && str_contains($refresh, 'window.top.location.assign(loginUrl)')
        && !str_contains($refresh, 'probe=</script>"')
        && substr_count($refresh, '</script>') === 1,
    'sidebar refresh URL is executable, unescaped, or ignores its minimum interval'
);
$assert(
    str_contains($refresh, 'credentials:"same-origin"')
        && str_contains($refresh, 'cache:"no-store"')
        && str_contains($refresh, 'var controller=new AbortController()')
        && str_contains($refresh, 'signal:controller.signal')
        && str_contains(
            $refresh,
            'window.setTimeout(function(){controller.abort();},4500)'
        )
        && str_contains($refresh, 'window.clearTimeout(timeout)')
        && str_contains(
            $refresh,
            'headers:{"X-Requested-With":"eStab-Sidebar"}'
        )
        && str_contains($refresh, 'new DOMParser()')
        && str_contains($refresh, 'markStatusStale();return false;')
        && str_contains(
            $refresh,
            'Status nicht aktuell · letzter Abruf '
        )
        && str_contains(
            $refresh,
            '"data-estab-status-freshness")==="stale"){return;}'
        )
        && str_contains(
            $refresh,
            'window.estabMarkSidebarStatusStale=markStatusStale'
        )
        && str_contains($refresh, 'lastSuccessfulRefresh=new Date();')
        && str_contains(
            $refresh,
            'querySelector("[data-estab-sidebar-status]")'
        )
        && str_contains($refresh, 'current.replaceWith(fresh)')
        && str_contains($refresh, 'window.estabRefreshSidebarStatus=refresh')
        && str_contains($refresh, 'if(!document.hidden){refresh();}')
        && str_contains($refresh, '},5000);'),
    'sidebar refresh does not preserve the page or enforce same-origin live updates'
);
$assert(
    str_contains(
        $refresh,
        'function reloadWorkspace(){try{if(window.parent'
            . '&&window.parent!==window'
            . '&&window.parent.location.origin===window.location.origin){'
            . 'window.parent.location.reload();return;}}catch(ignore){}'
            . 'window.location.reload();}'
    )
        && str_contains(
            $refresh,
            'if(response.status===403||response.status===409){'
                . 'reloadWorkspace();return false;}'
        )
        && str_contains($refresh, 'function incidentSignature(status)')
        && str_contains(
            $refresh,
            'incident.getAttribute("data-estab-incident-state")'
        )
        && str_contains(
            $refresh,
            'incident.getAttribute("data-estab-incident-id")'
        )
        && str_contains(
            $refresh,
            '"[data-estab-incident-permission-mode]"'
        )
        && str_contains(
            $refresh,
            'if(incidentSignature(current)!==incidentSignature(fresh)){'
                . 'reloadWorkspace();return false;}'
        )
        && substr_count($refresh, 'reloadWorkspace();return false;') === 2,
    'sidebar keeps a stale write surface after incident/mode changes or rejected refreshes'
);
$assert(
    str_contains($refresh, 'var storageKey="estab.sidebar.sounds"')
        && str_contains(
            $refresh,
            'audio[data-estab-sidebar-audio]'
        )
        && str_contains(
            $refresh,
            'document.addEventListener("click",function(event)'
        )
        && str_contains(
            $refresh,
            'target.closest("[data-estab-sound-toggle]")'
        )
        && str_contains($refresh, 'await player.play()')
        && str_contains($refresh, 'player.pause();player.currentTime=0;')
        && !str_contains($refresh, 'new Audio'),
    'refresh script does not reuse one long-lived, user-controlled audio player'
);
$assert(
    str_contains(
        $refresh,
        'fresh.getAttribute("data-estab-notify")==="1"'
    )
        && str_contains(
            $refresh,
            'var soundPreserved=preserveRefreshState(current,fresh);'
        )
        && str_contains(
            $refresh,
            'if(!soundPreserved){syncSoundControl();}'
        )
        && str_contains(
            $refresh,
            'if(notify&&soundsEnabled){playSound(false);}'
        )
        && str_contains(
            $refresh,
            'initialStatus.getAttribute("data-estab-notify")==="1"'
        )
        && str_contains(
            $refresh,
            'window.estabPlaySidebarNotification=function()'
        )
        && str_contains(
            $refresh,
            'window.estabSidebarSoundState=function()'
        ),
    'queue notification is lost during status replacement or cannot be monitored'
);
$assert(
    str_contains(
        $refresh,
        'freshSound.replaceWith(currentSound);soundPreserved=true;'
    )
        && str_contains(
            $refresh,
            'freshFreshness.replaceWith(currentFreshness);'
        )
        && str_contains(
            $refresh,
            'freshQueue.replaceWith(currentQueue);'
        )
        && str_contains($refresh, 'Status wieder aktuell'),
    'unchanged live regions are recreated on every status poll'
);
$assert(
    str_contains(
        $refresh,
        'document.activeElement===current.querySelector('
    )
        && str_contains(
            $refresh,
            'restoredButton.focus({preventScroll:true})'
        ),
    'status refresh does not restore focus from its replaced sound control'
);
$assert(
    str_contains($refresh, 'soundState="blocked"')
        && str_contains($refresh, 'soundState="unsupported"')
        && str_contains($refresh, 'error.name==="NotSupportedError"')
        && str_contains(
            $refresh,
            'Der Browser unterstützt die Audiodatei nicht.'
        )
        && str_contains(
            $refresh,
            'Der Browser blockiert den Ton. Zum erneuten Freigeben aus- und wieder einschalten.'
        )
        && str_contains(
            $refresh,
            'if(feedback){feedback.textContent=soundMessage;}'
        ),
    'audio playback failures do not reach the visible feedback state'
);
$assert(
    str_contains(
        $refresh,
        'soundState=soundsEnabled?"blocked":"inactive"'
    )
        && str_contains(
            $refresh,
            'Hinweistöne in diesem Tab erneut freigeben: aus- und wieder einschalten.'
        )
        && str_contains(
            $refresh,
            'window.addEventListener("storage",function(event)'
        )
        && str_contains($refresh, 'soundsEnabled=event.newValue==="on"')
        && str_contains(
            $refresh,
            'if(player){player.pause();player.currentTime=0;}'
        )
        && str_contains($refresh, 'var soundGeneration=0')
        && str_contains($refresh, 'var generation=++soundGeneration')
        && str_contains(
            $refresh,
            'if(generation!==soundGeneration||!soundsEnabled)'
        )
        && str_contains(
            $refresh,
            'if(soundsEnabled){soundGeneration+=1;'
        )
        && str_contains(
            $refresh,
            'if(event.key!==storageKey){return;}soundGeneration+=1;'
        ),
    'persisted, concurrent, or cross-tab sound intent is handled unsafely'
);
$assert(
    str_contains(
        estab_sidebar_status_refresh_script('/status', 999),
        'data-refresh-seconds="300"'
    )
        && str_contains(
            estab_sidebar_status_refresh_script('/status', 999),
            'data-timeout-ms="15000"'
        )
        && str_contains(
            estab_sidebar_status_refresh_script('/status', 999),
            '},300000);'
        ),
    'sidebar refresh maximum interval is not bounded'
);
$assertThrows(
    static fn (): string => estab_sidebar_status_refresh_script('', 10),
    'empty sidebar status URL accepted'
);
$assertThrows(
    static fn (): string =>
        estab_sidebar_status_refresh_script("\xC3\x28", 10),
    'invalid UTF-8 sidebar status URL accepted'
);

$workflowKeys = static fn (array $actions): array =>
    array_column($actions, 'key');
$workflowNames = static fn (array $actions): array =>
    array_column($actions, 'name');
$staffActions = estab_sidebar_workflow_actions([
    'rolle' => 'Stab',
    'funktion' => 'S1',
], 'ROLLE');
$assert(
    $workflowKeys($staffActions) === [
        'stab_schreiben',
        'stab_lesen',
        'm2_benutzer',
    ]
        && $workflowNames($staffActions) === [
            'stab_schreiben_x',
            'stab_lesen_x',
            'm2_benutzer_x',
        ],
    'staff sidebar lost a legacy writing, reading, or user action'
);
$multiStaffActions = estab_sidebar_workflow_actions([
    'rolle' => 'Stab',
    'funktion' => 'S1',
    'estab_permission_mode' => 'LOOSE',
    'estab_additional_functions' => [
        ['rolle' => 'Stab', 'funktion' => 'S2'],
    ],
], 'ROLLE');
$assert(
    $workflowKeys($multiStaffActions) === [
        'stab_schreiben',
        'stab_lesen',
        'stab_schreiben',
        'stab_lesen',
        'm2_benutzer',
    ]
        && array_map(
            static fn (array $action): ?string =>
                $action['acting_function'] ?? null,
            $multiStaffActions
        ) === [
            'S1',
            'S1',
            'S2',
            'S2',
            null,
        ]
        && array_column($multiStaffActions, 'label') === [
            'Schreiben als S1',
            'Lesen als S1',
            'Schreiben als S2',
            'Lesen als S2',
            'Benutzer',
        ],
    'LOOSE staff actions were deduplicated across S1/S2 or are not clearly '
        . 'function-labelled'
);
$sidebarControllerSource = file_get_contents(
    dirname(__DIR__, 2) . '/4fach/vorgaben.php'
);
$messageFormSource = file_get_contents(
    dirname(__DIR__, 2) . '/4fach/official_message_form.php'
);
$messageListSource = file_get_contents(
    dirname(__DIR__, 2) . '/4fach/liste.php'
);
$assert(
    is_string($sidebarControllerSource)
        && is_string($messageFormSource)
        && is_string($messageListSource)
        && str_contains(
            $sidebarControllerSource,
            "name=\"acting_function\""
        )
        && str_contains(
            $sidebarControllerSource,
            "\$action['acting_function']"
        )
        && str_contains(
            $messageFormSource,
            'official_message_acting_function()'
        )
        && str_contains(
            $messageFormSource,
            'name="acting_function"'
        )
        && substr_count(
            $messageListSource,
            'estab_list_acting_function_field ()'
        ) >= 4,
    'selected staff function is not carried through sidebar, form and list '
        . 'continuations'
);
$assert(
    $workflowKeys(estab_sidebar_workflow_actions([
        'rolle' => 'Stab',
        'funktion' => 'Si',
    ], 'ROLLE')) === [
        'stab_sichten',
        'si_admin',
        'm2_benutzer',
    ],
    'viewer sidebar action set changed'
);
$assert(
    $workflowKeys(estab_sidebar_workflow_actions([
        'rolle' => 'Fernmelder',
        'funktion' => 'A/W',
    ], 'ROLLE')) === [
        'fm_eingang',
        'fm_ausgang',
        'fm_admin',
        'fm_anhang',
        'm2_benutzer',
    ],
    'radio operator sidebar action set changed'
);
$assert(
    $workflowKeys(estab_sidebar_workflow_actions([
        'rolle' => 'FB',
        'funktion' => 'THW',
    ], 'ROLLE')) === [
        'stab_schreiben',
        'stab_lesen',
        'm2_benutzer',
    ],
    'subject-area sidebar action set changed'
);
$assert(
    $workflowKeys(estab_sidebar_workflow_actions([
        'rolle' => 'Administrator',
        'funktion' => 'Admin',
    ], 'ROLLE')) === ['m2_benutzer'],
    'administrator sidebar lost the legacy user action'
);
$assert(
    estab_sidebar_workflow_actions(null, 'ROLLE') === []
        && estab_sidebar_workflow_actions([
            'rolle' => 'Stab',
            'funktion' => 'S1',
        ], 'LOGIN') === [],
    'sidebar exposes role actions outside the authenticated role menu'
);
$assert(
    estab_sidebar_workflow_actions([
        'rolle' => 'Stab',
        'funktion' => 'S1',
        'estab_permission_mode' => 'STRICT',
    ], 'ROLLE') === []
        && estab_sidebar_workflow_actions([
            'rolle' => 'Stab',
            'funktion' => 'S1',
            'estab_permission_mode' => 'INVALID',
            'duty_assignment_id' => 701,
        ], 'ROLLE') === []
        && $workflowKeys(estab_sidebar_workflow_actions([
            'rolle' => 'Stab',
            'funktion' => 'S1',
            'estab_permission_mode' => 'STRICT',
            'duty_assignment_id' => 701,
        ], 'ROLLE')) === [
            'stab_schreiben',
            'stab_lesen',
            'm2_benutzer',
        ],
    'STRICT sidebar actions are not bound to a selected duty assignment'
);
$assert(
    $workflowKeys(estab_sidebar_workflow_actions([
        'rolle' => 'Stab',
        'funktion' => 'ETB',
        'estab_permission_mode' => 'STRICT',
        'duty_assignment_id' => 702,
    ], 'ROLLE')) === ['m2_benutzer']
        && $workflowKeys(estab_sidebar_workflow_actions([
            'rolle' => 'Fernmelder',
            'funktion' => 'LdF',
            'estab_permission_mode' => 'LOOSE',
            'estab_additional_functions' => [
                ['rolle' => 'Stab', 'funktion' => 'ETB'],
            ],
        ], 'ROLLE')) === ['ldf_nachrichten', 'm2_benutzer'],
    'ETB exposed normal Stab/FB message actions in STRICT or LOOSE'
);

$permissionContextKey = ESTAB_PERMISSION_CONTEXT_KEY;
estab_permission_context_set_from_incident([
    'active_einsatz_id' => 42,
    'estab_permission_mode' => 'LOOSE',
    'revision' => 8,
]);
$looseStaffKeys = $workflowKeys(estab_sidebar_workflow_actions([
    'rolle' => 'Stab',
    'funktion' => 'S1',
    'estab_permission_mode' => 'LOOSE',
], 'ROLLE'));
$looseRadioKeys = $workflowKeys(estab_sidebar_workflow_actions([
    'rolle' => 'Fernmelder',
    'funktion' => 'A/W',
    'estab_permission_mode' => 'LOOSE',
], 'ROLLE'));
$looseViewerKeys = $workflowKeys(estab_sidebar_workflow_actions([
    'rolle' => 'Stab',
    'funktion' => 'Si',
    'estab_permission_mode' => 'LOOSE',
], 'ROLLE'));
$looseAdminKeys = $workflowKeys(estab_sidebar_workflow_actions([
    'rolle' => 'Administrator',
    'funktion' => 'Admin',
], 'ROLLE'));
$looseLdfWithS6Keys = $workflowKeys(estab_sidebar_workflow_actions([
    'rolle' => 'Fernmelder',
    'funktion' => 'LdF',
    'estab_permission_mode' => 'LOOSE',
    'estab_additional_functions' => [
        ['rolle' => 'Stab', 'funktion' => 'S6'],
    ],
], 'ROLLE'));
$assert(
    estab_permission_role_checks_enforced()
        && $looseStaffKeys === [
            'stab_schreiben',
            'stab_lesen',
            'm2_benutzer',
        ]
        && $looseRadioKeys === [
            'fm_eingang',
            'fm_ausgang',
            'fm_admin',
            'fm_anhang',
            'm2_benutzer',
        ]
        && $looseViewerKeys === [
            'stab_sichten',
            'si_admin',
            'm2_benutzer',
        ]
        && $looseAdminKeys === ['m2_benutzer']
        && $looseLdfWithS6Keys === [
            'ldf_nachrichten',
            'stab_schreiben',
            'stab_lesen',
            'm2_benutzer',
        ],
    'LOOSE sidebar is not the exact union of the base and explicit additional functions'
);
unset($GLOBALS[$permissionContextKey]);
$assert(
    $workflowKeys(estab_sidebar_workflow_actions([
        'rolle' => ['invalid'],
        'funktion' => 'S1',
    ], 'ROLLE')) === ['m2_benutzer'],
    'malformed fachliche identity exposed operational sidebar actions'
);
$fixedFunctionMarkup = estab_sidebar_account_function_markup([], [
    'rolle' => 'Stab',
    'funktion' => 'S1',
]);
$assert(
    str_contains($fixedFunctionMarkup, 'data-estab-account-function')
        && str_contains($fixedFunctionMarkup, 'Primärfunktion')
        && str_contains($fixedFunctionMarkup, 'S1 · Stab')
        && !str_contains($fixedFunctionMarkup, 'duty-assignment')
        && !str_contains($fixedFunctionMarkup, 'fuehrungsstelle.php')
        && !str_contains($fixedFunctionMarkup, 'wechseln'),
    'sidebar presents the fixed account function as a selectable shift role'
);
$additionalFunctionMarkup = estab_sidebar_account_function_markup([], [
    'rolle' => 'Fernmelder',
    'funktion' => 'LdF',
    'estab_permission_mode' => 'LOOSE',
    'estab_additional_functions' => [
        ['rolle' => 'Stab', 'funktion' => 'S6'],
        ['rolle' => 'Stab', 'funktion' => 'S6'],
    ],
]);
$assert(
    str_contains($additionalFunctionMarkup, '<strong>LdF · Fernmelder</strong>')
        && str_contains($additionalFunctionMarkup, 'Zusatzfunktionen: S6 · Stab')
        && substr_count($additionalFunctionMarkup, 'S6') === 1,
    'sidebar does not disclose deduplicated effective LOOSE additional functions'
);
$radioFunctionMarkup = estab_sidebar_account_function_markup([], [
    'rolle' => 'Fernmelder',
    'funktion' => 'A/W',
]);
$assert(
    str_contains($radioFunctionMarkup, '<strong>Fernmelder</strong>')
        && !str_contains($radioFunctionMarkup, '>A/W<')
        && !str_contains($radioFunctionMarkup, 'Fernmelder · Fernmelder'),
    'sidebar account summary leaks the internal telecommunications key'
);
$assert(
    estab_sidebar_account_function_markup([], null) === '',
    'sidebar renders an account function without an authenticated identity'
);
$strictFunctionMarkup = estab_sidebar_account_function_markup(
    ['estab_duty_assignment_id' => 701],
    [
        'rolle' => 'Stab',
        'funktion' => 'S6',
        'estab_permission_mode' => 'STRICT',
        'duty_assignment_id' => 701,
    ]
);
$assert(
    str_contains($strictFunctionMarkup, 'data-estab-active-duty-hat')
        && str_contains(
            $strictFunctionMarkup,
            'data-estab-duty-assignment="701"'
        )
        && str_contains($strictFunctionMarkup, 'Aktive Dienstfunktion')
        && str_contains($strictFunctionMarkup, 'S6 · Stab')
        && str_contains($strictFunctionMarkup, 'Dienstfunktion wechseln')
        && str_contains($strictFunctionMarkup, 'fuehrungsstelle.php')
        && !str_contains($strictFunctionMarkup, 'Primärfunktion'),
    'STRICT sidebar lost the selected duty function or safe switch control'
);
$assert(
    estab_sidebar_account_function_markup(
        ['estab_duty_assignment_id' => 702],
        [
            'rolle' => 'Stab',
            'funktion' => 'S6',
            'estab_permission_mode' => 'STRICT',
            'duty_assignment_id' => 701,
        ]
    ) === '',
    'STRICT sidebar displayed a duty function from mismatched session state'
);

$wavMetadata = static function (string $path): array {
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) {
        throw new RuntimeException('Unable to read WAV asset: ' . $path);
    }

    $codec = null;
    $dataSize = null;
    $offset = 12;
    $length = strlen($bytes);
    while ($offset + 8 <= $length) {
        $chunkId = substr($bytes, $offset, 4);
        $sizeValues = unpack('Vsize', substr($bytes, $offset + 4, 4));
        if (!is_array($sizeValues)) {
            break;
        }
        $chunkSize = (int) $sizeValues['size'];
        $payloadOffset = $offset + 8;
        if ($payloadOffset + $chunkSize > $length) {
            break;
        }
        if ($chunkId === 'fmt ' && $chunkSize >= 16) {
            $codecValues = unpack(
                'vcodec',
                substr($bytes, $payloadOffset, 2)
            );
            if (is_array($codecValues)) {
                $codec = (int) $codecValues['codec'];
            }
        } elseif ($chunkId === 'data') {
            $dataSize = $chunkSize;
        }
        $offset = $payloadOffset + $chunkSize + ($chunkSize % 2);
    }

    return [
        'bytes' => $bytes,
        'codec' => $codec,
        'data_size' => $dataSize,
    ];
};
foreach (['aw', 'si', 'stab'] as $soundRole) {
    $assetPath = __DIR__
        . '/../../4fach/audio/notify_' . $soundRole . '.wav';
    $assert(
        is_file($assetPath),
        'sidebar notification WAV asset is missing: ' . $soundRole
    );
    $wav = $wavMetadata($assetPath);
    $assert(
        strlen($wav['bytes']) > 12
            && substr($wav['bytes'], 0, 4) === 'RIFF'
            && substr($wav['bytes'], 8, 4) === 'WAVE',
        'sidebar notification asset is not a RIFF/WAVE file: ' . $soundRole
    );
    $assert(
        $wav['codec'] === 1 && ($wav['data_size'] ?? 0) > 0,
        'sidebar notification asset is empty or not PCM: ' . $soundRole
    );
}

$stylesheet = file_get_contents(__DIR__ . '/../../estab-ui.css');
$assert(
    is_string($stylesheet)
        && str_contains($stylesheet, '.estab-sidebar-queue.has-work')
        && str_contains(
            $stylesheet,
            '.estab-sidebar-queue.has-work strong'
        )
        && str_contains($stylesheet, 'border-color: #e5b247')
        && str_contains($stylesheet, 'color: #ffd36b'),
    'non-empty queue has no persistent high-contrast warning style'
);
$assert(
    str_contains($stylesheet, '.estab-sidebar-status-stale')
        && str_contains(
            $stylesheet,
            '.estab-sidebar-freshness[data-estab-status-freshness="stale"]'
        ),
    'stale sidebar status has no persistent visible warning style'
);
$assert(
    str_contains($stylesheet, '.estab-sidebar-account-function')
        && str_contains($stylesheet, '.estab-sidebar-account-function span')
        && str_contains($stylesheet, '.estab-sidebar-duty-hat')
        && str_contains($stylesheet, '.estab-sidebar-duty-hat span'),
    'mode-specific function cards have no consistent sidebar styling'
);

echo "sidebar UI security: OK ({$assertions} assertions)\n";
