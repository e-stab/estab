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
        'rolle' => 'Stab',
        'funktion' => 'S1',
        'aktiv' => 1,
    ],
    [
        'rolle' => 'Fernmelder',
        'funktion' => 'A/W',
        'aktiv' => 1,
    ],
    [
        'rolle' => 'Fernmelder',
        'funktion' => 'A/W',
        'aktiv' => 1,
    ],
    [
        'rolle' => '<script>',
        'funktion' => 'X&"',
        'aktiv' => 1,
    ],
    [
        'rolle' => 'Inactive',
        'funktion' => 'Never',
        'aktiv' => 0,
    ],
    [
        'rolle' => ['invalid'],
        'funktion' => 'Array',
        'aktiv' => 1,
    ],
];

$positions = estab_sidebar_positions(
    $configuredPositions,
    $users,
    $identity
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

$identityOnly = estab_sidebar_positions([], [], [
    'rolle' => 'Stab',
    'funktion' => 'S6',
]);
$assert(
    $identityOnly === [['rolle' => 'Stab', 'funktion' => 'S6']],
    'current identity disappeared when absent from the configured matrix'
);

$queueProfiles = [
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
    'admin' => estab_sidebar_queue_profile([
        'rolle' => 'Administrator',
        'funktion' => 'Admin',
    ]),
    'anonymous' => estab_sidebar_queue_profile(null),
];
$assert(
    $queueProfiles === [
        'aw' => [
            'session_key' => 'old_que_aw',
            'sound_file' => 'notify_aw.wav',
            'label' => 'Im Ausgang',
        ],
        'si' => [
            'session_key' => 'old_que_si',
            'sound_file' => 'notify_si.wav',
            'label' => 'Zu sichten',
        ],
        'stab' => [
            'session_key' => 'old_que_stab',
            'sound_file' => 'notify_stab.wav',
            'label' => 'Offene Meldungen',
        ],
        'fb' => [
            'session_key' => 'old_que_stab',
            'sound_file' => 'notify_stab.wav',
            'label' => 'Offene Meldungen',
        ],
        'admin' => null,
        'anonymous' => null,
    ],
    'role-specific queue, label, or notification sound mapping changed'
);
$assertThrows(
    static fn (): ?array => estab_sidebar_queue_profile([
        'rolle' => ['invalid'],
        'funktion' => 'S1',
    ]),
    'malformed queue identity accepted'
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
    $awQueueQuery['parameters'] === [42]
        && str_contains($awQueueQuery['sql'], '`nv_nachrichten`')
        && str_contains($awQueueQuery['sql'], '`einsatz_id` = ?')
        && str_contains($awQueueQuery['sql'], "`04_richtung` = 'A'")
        && str_contains($awQueueQuery['sql'], '`03_datum` IS NULL')
        && str_contains($awQueueQuery['sql'], "`03_zeichen` = ''"),
    'outgoing queue query changed its legacy selection'
);
$assert(
    $siIncomingQuery['parameters'] === [42]
        && str_contains($siIncomingQuery['sql'], '`einsatz_id` = ?')
        && str_contains($siIncomingQuery['sql'], '`x00_status` = 4')
        && str_contains($siIncomingQuery['sql'], "`04_richtung` = 'E'")
        && !str_contains($siIncomingQuery['sql'], '`03_datum` IS NOT NULL')
        && str_contains($siCombinedQuery['sql'], '`x00_status` = 4')
        && str_contains($siCombinedQuery['sql'], '`03_datum` IS NOT NULL')
        && str_contains($siCombinedQuery['sql'], "`03_zeichen` != ''"),
    'review queue query counts messages outside the visible status-four scope'
);
$assert(
    $staffQueueQuery['parameters'] === [42, '%S1%', 42, '%S1%']
        && substr_count($staffQueueQuery['sql'], '`nv_nachrichten`') === 2
        && substr_count($staffQueueQuery['sql'], '`einsatz_id` = ?') === 2
        && str_contains(
            $staffQueueQuery['sql'],
            "(`all_messages`.`04_richtung` <> 'E'"
                . ' OR `all_messages`.`x00_status` <> 1)'
        )
        && str_contains(
            $staffQueueQuery['sql'],
            "(`done_messages`.`04_richtung` <> 'E'"
                . ' OR `done_messages`.`x00_status` <> 1)'
        )
        && str_contains($staffQueueQuery['sql'], '`usr__fkt_s1_erl`')
        && str_contains(
            $staffQueueQuery['sql'],
            '`done_messages`.`00_lfd` = `done_state`.`nachnum`'
        ),
    'staff queue counts incoming status-one messages or changed recipient state'
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

$now = new DateTimeImmutable('2026-07-28T19:03:00+02:00');
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
        && str_contains($markup, '<h2>Online-Übersicht</h2>')
        && str_contains($markup, 'data-estab-notify="0"')
        && !str_contains($markup, 'data-estab-sound-toggle'),
    'sidebar status omitted queue, deterministic server time, date, or heading'
);
$assert(
    str_contains($markup, 'data-estab-online-count="4"')
        && str_contains($markup, '4 Personen online')
        && str_contains($markup, '>2 A/W</span>')
        && str_contains(
            $markup,
            'aria-label="Fernmelder, Funktion A/W: online"'
        )
        && str_contains($markup, 'Online</span>')
        && str_contains($markup, 'Ihre Funktion</span>')
        && str_contains($markup, 'Unbesetzt</span>'),
    'sidebar presence summary lost counts, state text, or its visible legend'
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
            'offline',
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
            '&lt;script&gt;, Funktion X&amp;&quot;: online'
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
        && str_contains($unavailableMarkup, 'data-estab-online-count="1"')
        && str_contains(
            $unavailableMarkup,
            'data-estab-presence-state="current"'
        ),
    'unavailable queue or absent current database row is not represented safely'
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
$assertThrows(
    static fn (): array => estab_sidebar_workflow_actions([
        'rolle' => ['invalid'],
        'funktion' => 'S1',
    ], 'ROLLE'),
    'invalid sidebar workflow identity accepted'
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

echo "sidebar UI security: OK ({$assertions} assertions)\n";
