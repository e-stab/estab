<?php

declare(strict_types=1);

/**
 * Wer zwei Funktionen traegt, darf keine Warteschlange verlieren.
 *
 * In einer kleinen Fuehrungsstelle ist die Doppelfunktion der Regelfall: eine
 * Kraft fuehrt den Fernmeldebetrieb und bearbeitet zugleich ein Sachgebiet.
 * Die Seitenleiste zaehlte und meldete jedoch genau eine Warteschlange in
 * fester Rangfolge, so dass jede weitere getragene Funktion weder Zaehler noch
 * Signalton bekam und wartende Nachrichten unbemerkt liegen blieben. Dieser
 * Test haelt fest, dass jede getragene Funktion ihre eigene Warteschlange
 * ausweist, dass der Ton auf jede von ihnen anspringt, dass alle Zaehlstaende
 * in einer einzigen Datenbankabfrage entstehen und dass die
 * Besetzungsuebersicht die angenommene Dienstfunktion statt der Kontofunktion
 * zeigt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/sidebar.php';

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

/*
 * Ohne eine Warteschlange je getragener Funktion laesst sich die
 * Doppelfunktion nicht abbilden. Der Test benennt dann sofort die Regel statt
 * an einem unbekannten Funktionsnamen abzubrechen.
 */
$assert(
    function_exists('estab_sidebar_queue_profiles')
        && function_exists('estab_sidebar_queue_batch_query')
        && function_exists('estab_sidebar_queue_notifications'),
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Die Seitenleiste kennt je Sitzung nur eine einzige Warteschlange'
    )
);

/*
 * Eine Kraft fuehrt den Fernmeldebetrieb und bearbeitet zwei Sachgebiete.
 */
$doubleHatIdentity = [
    'benutzer' => 'Müller, Ada',
    'kuerzel' => 'ada001',
    'funktion' => 'LdF',
    'rolle' => 'Fernmelder',
    'estab_permission_mode' => 'LOOSE',
    'estab_additional_functions' => [
        ['funktion' => 'S1', 'rolle' => 'Stab'],
        ['funktion' => 'S2', 'rolle' => 'Stab'],
    ],
];
$profiles = estab_sidebar_queue_profiles($doubleHatIdentity);
$assert(
    array_column($profiles, 'funktion') === ['LdF', 'S1', 'S2'],
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Fuer eine Kraft mit LdF, S1 und S2 entsteht nicht je Funktion eine '
            . 'Warteschlange'
    )
);
$assert(
    array_column($profiles, 'baseline_key') === [
        'old_que_ldf',
        'old_que_stab_s1',
        'old_que_stab_s2',
    ],
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Zwei Stabsfunktionen teilen sich einen Zaehlerstand, so dass die '
            . 'zweite Messung die erste ueberschreibt'
    )
);
$assert(
    estab_sidebar_queue_profile($doubleHatIdentity) === [
        'session_key' => 'old_que_ldf',
        'sound_file' => 'notify_aw.wav',
        'label' => 'Bei LdF',
        'funktion' => 'LdF',
    ]
    && count(estab_sidebar_queue_profiles([
        'funktion' => 'S1',
        'rolle' => 'Stab',
    ])) === 1
    && estab_sidebar_queue_profiles([
        'funktion' => 'ETB',
        'rolle' => 'Stab',
    ]) === []
    && estab_sidebar_queue_profiles(null) === [],
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Die ranghoechste Funktion bleibt nicht die fuehrende Anzeige oder '
            . 'eine Funktion ohne Meldungseingang erhaelt eine Warteschlange'
    )
);

/*
 * Die Anzeige traegt nur eine begrenzte Zahl Zaehler. Ohne Deckel wirft die
 * Statusanzeige bei sehr vielen Zusatzfunktionen - und genau das ist der
 * Kleinbetrieb, in dem eine Kraft alles traegt - und reisst die ganze
 * Seitenleiste mit.
 */
$manyHats = [];
for ($index = 1; $index <= 25; $index++) {
    $manyHats[] = ['funktion' => 'S' . $index, 'rolle' => 'Stab'];
}
$manyHatProfiles = estab_sidebar_queue_profiles([
    'funktion' => 'LdF',
    'rolle' => 'Fernmelder',
    'estab_permission_mode' => 'LOOSE',
    'estab_additional_functions' => $manyHats,
]);
$assert(
    count($manyHatProfiles) === ESTAB_SIDEBAR_MAX_QUEUES
        && count($manyHatProfiles) - 1 <= ESTAB_SIDEBAR_MAX_QUEUES - 1
        && count(estab_sidebar_queue_batch_query(
            $manyHatProfiles,
            'nv_nachrichten',
            'usr_',
            false,
            42
        )['keys']) === ESTAB_SIDEBAR_MAX_QUEUES,
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Sehr viele getragene Funktionen sprengen die Anzeige, statt die '
            . 'Messung geordnet zu begrenzen'
    )
);

/*
 * Die Zaehlung aller getragenen Funktionen bleibt ein Datenbankumlauf: eine
 * vorbereitete Anweisung mit je einer Spalte und den Parametern in
 * Spaltenreihenfolge.
 */
$batch = estab_sidebar_queue_batch_query(
    $profiles,
    'nv_nachrichten',
    'usr_',
    false,
    42
);
$expectedParameters = [];
foreach ($profiles as $profile) {
    $single = estab_sidebar_queue_query(
        $profile['session_key'],
        'nv_nachrichten',
        'usr_',
        $profile['funktion'],
        false,
        42
    );
    foreach ($single['parameters'] as $parameter) {
        $expectedParameters[] = $parameter;
    }
}
$assert(
    str_starts_with($batch['sql'], 'SELECT (')
        && substr_count($batch['sql'], ') AS `queue_') === 3
        && substr_count($batch['sql'], ';') === 0
        && $batch['keys'] === [
            'old_que_ldf',
            'old_que_stab_s1',
            'old_que_stab_s2',
        ]
        && $batch['parameters'] === $expectedParameters
        && $batch['parameters'][0] === 42,
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Die Warteschlangen der getragenen Funktionen werden nicht in einer '
            . 'einzigen Abfrage gemessen'
    )
);
$sidebarSource = file_get_contents($root . '/app/sidebar.php');
$navigationSource = file_get_contents($root . '/4fach/vorgaben.php');
$assert(
    is_string($sidebarSource)
        && is_string($navigationSource)
        && substr_count($sidebarSource, 'function estab_sidebar_queue_count(')
            === 0
        && substr_count($navigationSource, 'estab_sidebar_queue_counts(') === 1
        && substr_count($navigationSource, 'estab_sidebar_queue_profiles(')
            === 1,
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Die Seitenleiste liest die Warteschlangen weiterhin einzeln je '
            . 'Funktion'
    )
);
$assertThrows(
    static fn (): array => estab_sidebar_queue_batch_query(
        [$profiles[1], $profiles[1]],
        'nv_nachrichten',
        'usr_',
        false,
        42
    ),
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Zwei gleiche Warteschlangen werden gemeinsam gemessen und '
            . 'ueberschreiben denselben Zaehlerstand'
    )
);

/*
 * Der Signalton folgt jeder getragenen Funktion, und jede Messung fuehrt
 * ihren eigenen Ausgangswert nach - auch die, die nach einem bereits
 * ausgeloesten Ton an der Reihe ist.
 */
$audioUrl = '/4fach/audio/notify_aw.wav';
$measurement = static fn (?int $ldf, ?int $s1, ?int $s2): array => [
    ['baseline_key' => 'old_que_ldf', 'count' => $ldf],
    ['baseline_key' => 'old_que_stab_s1', 'count' => $s1],
    ['baseline_key' => 'old_que_stab_s2', 'count' => $s2],
];
$baselines = [];
$assert(
    estab_sidebar_queue_notifications(
        $baselines,
        $measurement(0, 0, 0),
        true,
        $audioUrl
    ) === null
        && $baselines === [
            'old_que_ldf' => 0,
            'old_que_stab_s1' => 0,
            'old_que_stab_s2' => 0,
        ],
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Die erste Messung meldet einen Ton oder legt nicht fuer jede '
            . 'Funktion einen Ausgangswert an'
    )
);
$assert(
    estab_sidebar_queue_notifications(
        $baselines,
        $measurement(0, 0, 1),
        true,
        $audioUrl
    ) === $audioUrl
        && estab_sidebar_queue_notifications(
            $baselines,
            $measurement(0, 0, 1),
            true,
            $audioUrl
        ) === null,
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Eine neue Meldung in der zweiten getragenen Funktion loest keinen '
            . 'Signalton aus'
    )
);
$assert(
    estab_sidebar_queue_notifications(
        $baselines,
        $measurement(3, 2, 1),
        true,
        $audioUrl
    ) === $audioUrl
        && $baselines === [
            'old_que_ldf' => 3,
            'old_que_stab_s1' => 2,
            'old_que_stab_s2' => 1,
        ]
        && estab_sidebar_queue_notifications(
            $baselines,
            $measurement(3, 2, 1),
            true,
            $audioUrl
        ) === null,
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Nach einem ausgeloesten Ton bleiben die Ausgangswerte der uebrigen '
            . 'Funktionen stehen und melden dieselbe Meldung erneut'
    )
);
$assert(
    estab_sidebar_queue_notifications(
        $baselines,
        $measurement(3, null, 4),
        true,
        $audioUrl
    ) === $audioUrl
        && $baselines['old_que_stab_s1'] === 2,
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Eine nicht messbare Warteschlange verwirft den Ausgangswert oder '
            . 'unterdrueckt den Ton der uebrigen Funktionen'
    )
);
foreach (
    [
        [['baseline_key' => 'old_que_admin', 'count' => 1]],
        [
            ['baseline_key' => 'old_que_stab_s1', 'count' => 1],
            ['baseline_key' => 'old_que_stab_s1', 'count' => 2],
        ],
        [['baseline_key' => 'old_que_ldf', 'count' => '2']],
    ] as $invalidMeasurement
) {
    $assertThrows(
        static function () use ($invalidMeasurement, $audioUrl): void {
            $invalidBaselines = [];
            estab_sidebar_queue_notifications(
                $invalidBaselines,
                $invalidMeasurement,
                true,
                $audioUrl
            );
        },
        estab_dv_requirement(
            'FUEST-DOPPELFUNKTION',
            'Eine unbekannte, doppelte oder unsaubere Warteschlangenmessung '
                . 'wird angenommen'
        )
    );
}

/*
 * Die Anzeige weist jede weitere Funktion als eigene, flache Zeile aus: ein
 * zweiter grosser Zaehlerblock waere auf einem 768-Punkte-Bildschirm nicht
 * tragbar. Der Live-Bereich sitzt am einzelnen Zaehler, nicht an der Liste -
 * sonst liest ein Screenreader bei jedem Statusabruf den ganzen Streifen vor.
 */
$session = [
    'vStab_benutzer' => 'Müller, Ada',
    'vStab_kuerzel' => 'ada001',
    'vStab_funktion' => 'LdF',
    'vStab_rolle' => 'Fernmelder',
];
$now = new DateTimeImmutable('2026-07-28T19:03:00+02:00');
$activityAt = static function (string $modifier) use ($now): string {
    return $now->setTimezone(new DateTimeZone('UTC'))
        ->modify($modifier)
        ->format('Y-m-d H:i:s.u');
};
$configuredPositions = [
    ['rolle' => 'Fernmelder', 'fkt' => 'LdF'],
    ['rolle' => 'Fernmelder', 'fkt' => 'A/W'],
    ['rolle' => 'Stab', 'fkt' => 'S1'],
    ['rolle' => 'Stab', 'fkt' => 'S3'],
];
$users = [
    [
        'kuerzel' => 'ada001',
        'rolle' => 'Stab',
        'funktion' => 'S1',
        'sid' => 'double-hat-current-1',
        'aktiv' => 1,
        'estab_gesperrt' => 0,
        'estab_letzte_aktivitaet' => $activityAt('-1 minute'),
    ],
    [
        'kuerzel' => 'bea002',
        'rolle' => 'Stab',
        'funktion' => 'S3',
        'sid' => 'double-hat-other-1',
        'aktiv' => 1,
        'estab_gesperrt' => 0,
        'estab_letzte_aktivitaet' => $activityAt('-2 minutes'),
    ],
];
$secondaryQueues = [
    [
        'baseline_key' => 'old_que_stab_s1',
        'label' => 'Offene Meldungen',
        'short_label' => 'S1',
        'count' => 4,
    ],
    [
        'baseline_key' => 'old_que_stab_s2',
        'label' => 'Offene Meldungen',
        'short_label' => 'S2',
        'count' => 0,
    ],
];
$markup = estab_sidebar_status_markup(
    $session,
    $configuredPositions,
    $users,
    'Bei LdF',
    2,
    $now,
    null,
    null,
    'current',
    '',
    null,
    $secondaryQueues
);
$assert(
    substr_count($markup, 'data-estab-queue-count') === 3
        && substr_count($markup, '<li class="estab-sidebar-queue-item') === 2
        && substr_count($markup, 'class="estab-sidebar-queue ') === 1
        && str_contains(
            $markup,
            '<li class="estab-sidebar-queue-item has-work"'
        )
        && str_contains(
            $markup,
            '<strong data-estab-queue-count="old_que_stab_s1"'
                . ' aria-live="polite">4</strong>'
        )
        && str_contains(
            $markup,
            '<strong data-estab-queue-count="old_que_stab_s2"'
                . ' aria-live="polite">0</strong>'
        )
        && str_contains(
            $markup,
            '<ul class="estab-sidebar-queue-strip" data-estab-queue-strip'
                . ' aria-label="Warteschlangen Ihrer weiteren Funktionen">'
        )
        && str_contains($markup, 'aria-label="Offene Meldungen S1: 4 wartend"'),
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Die Anzeige fuehrt nicht je getragener Funktion einen eigenen, '
            . 'lesbaren Zaehler in einer flachen Zeile'
    )
);
$assertThrows(
    static fn (): string => estab_sidebar_status_markup(
        $session,
        $configuredPositions,
        $users,
        'Bei LdF',
        2,
        $now,
        null,
        null,
        'current',
        '',
        null,
        [[
            'baseline_key' => 'old_que_stab_s1',
            'label' => 'Offene Meldungen',
            'short_label' => 'S1',
            'count' => -1,
        ]]
    ),
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Eine unmoegliche Warteschlangenangabe erreicht die Anzeige'
    )
);
$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(
    is_string($stylesheet)
        && preg_match(
            '~\.estab-sidebar-queue-strip\s*\{[^}]*grid-template-columns:'
                . '\s*repeat\(auto-fit, minmax\(4\.5rem, 1fr\)\)~',
            $stylesheet
        ) === 1
        && preg_match(
            '~\.estab-sidebar-queue-item\s*\{[^}]*display:\s*flex~',
            $stylesheet
        ) === 1
        && preg_match(
            '~@media \(max-height: 46rem\)\s*\{.*'
                . '\.estab-sidebar-queue strong\s*\{\s*font-size:\s*1\.5rem~s',
            $stylesheet
        ) === 1,
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Die weiteren Warteschlangen kosten auf einem flachen Bildschirm '
            . 'einen zweiten Zaehlerblock statt einer Zeile'
    )
);

/*
 * Die Besetzungsuebersicht folgt der angenommenen Dienstfunktion. Ohne diese
 * Quelle zeigte sie fuer alle anderen Personen die Kontofunktion.
 */
$dutyFunctions = [
    'ada001' => [
        ['funktion' => 'LdF', 'rolle' => 'Fernmelder'],
        ['funktion' => 'S1', 'rolle' => 'Stab'],
    ],
    'bea002' => [
        ['funktion' => 'A/W', 'rolle' => 'Fernmelder'],
    ],
];
$dutyMarkup = estab_sidebar_status_markup(
    $session,
    $configuredPositions,
    $users,
    'Bei LdF',
    2,
    $now,
    null,
    null,
    'current',
    '',
    null,
    $secondaryQueues,
    $dutyFunctions
);
$assert(
    str_contains(
        $dutyMarkup,
        'data-estab-presence-state="online"'
            . ' data-estab-presence-role="Fernmelder"'
            . ' data-estab-presence-function="A/W"'
    )
        && str_contains(
            $dutyMarkup,
            'data-estab-presence-state="offline"'
                . ' data-estab-presence-role="Stab"'
                . ' data-estab-presence-function="S3"'
        ),
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Die Uebersicht zeigt fuer eine andere Person die Kontofunktion '
            . 'statt der angenommenen Dienstfunktion'
    )
);
$assert(
    str_contains(
        $dutyMarkup,
        'data-estab-presence-state="current"'
            . ' data-estab-presence-role="Fernmelder"'
            . ' data-estab-presence-function="LdF"'
    )
        && str_contains(
            $dutyMarkup,
            'data-estab-presence-state="online"'
                . ' data-estab-presence-role="Stab"'
                . ' data-estab-presence-function="S1"'
        )
        && str_contains($dutyMarkup, 'data-estab-online-count="2"')
        && str_contains($dutyMarkup, '2 Personen aktiv')
        && str_contains($dutyMarkup, '<h2>Aktivität nach Dienstfunktion</h2>'),
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Eine Kraft mit zwei angenommenen Dienstfunktionen erscheint nicht an '
            . 'beiden Stationen oder wird doppelt als Person gezaehlt'
    )
);
$staleDutyMarkup = estab_sidebar_status_markup(
    $session,
    $configuredPositions,
    $users,
    'Bei LdF',
    2,
    $now,
    null,
    null,
    'current',
    '',
    null,
    [],
    ['bea002' => [['funktion' => 'A/W', 'rolle' => 'Fernmelder']]]
);
$assert(
    str_contains(
        $staleDutyMarkup,
        'data-estab-presence-state="current"'
            . ' data-estab-presence-role="Fernmelder"'
            . ' data-estab-presence-function="LdF"'
    ),
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Fuehrt die Besetzungsliste das eigene Konto nicht mehr, verschwindet '
            . 'die Kachel, auf die die Legende zeigt'
    )
);
$accountMarkup = estab_sidebar_status_markup(
    $session,
    $configuredPositions,
    $users,
    'Bei LdF',
    2,
    $now
);
$assert(
    str_contains($accountMarkup, '<h2>Aktivität nach Primärfunktion</h2>')
        && str_contains(
            $accountMarkup,
            'data-estab-presence-state="online"'
                . ' data-estab-presence-role="Stab"'
                . ' data-estab-presence-function="S3"'
        )
        && substr_count($accountMarkup, 'data-estab-queue-count') === 1,
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Ohne Dienstschicht verlaesst die Uebersicht die Kontofunktion oder '
            . 'die Anzeige erfindet weitere Warteschlangen'
    )
);
$domainSource = file_get_contents($root . '/app/dv_operations.php');
$assert(
    is_string($navigationSource)
        && str_contains($navigationSource, 'estab_dv_active_duty_functions(')
        && str_contains(
            $navigationSource,
            'estab_incident_duty_shift_required($scope[\'incident\'])'
        )
        && is_string($domainSource)
        && str_contains(
            $domainSource,
            'function estab_dv_active_duty_functions('
        )
        && str_contains($domainSource, '`nv_dienstbesetzungen`')
        && str_contains($domainSource, "b.`status` = 'ANGENOMMEN'"),
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Die Uebersicht bezieht die angenommene Dienstfunktion nicht aus den '
            . 'Dienstbesetzungen der aktiven Schicht'
    )
);

printf("DV double-hat queues: OK (%d assertions)\n", $assertions);
