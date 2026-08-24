<?php

declare(strict_types=1);

/**
 * Der Einstieg muss die Lage zeigen, nicht nur ein Linkmenü.
 *
 * Nach der Anmeldung stand dort "Bereich auswählen". Wer die Führungsstelle
 * betrat, sah weder die eigene Warteschlange noch offenen Vorrangverkehr,
 * weder eine unbesetzte Station des Nachrichtenlaufs noch die Liegezeit der
 * ältesten offenen Nachricht - er musste raten, wo Arbeit liegt. Dieser Test
 * hält fest, dass der Einstieg diese Fragen beantwortet, dass er dafür einen
 * einzigen Datenbankumlauf braucht, dass er niemandem den Weg verlängert und
 * dass er die bisherige Seite behält, wo er nichts zu sagen hat.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/root_menu.php';
require_once $root . '/app/situation_overview.php';

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
    } catch (InvalidArgumentException | RuntimeException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $relative);
    }
    return $source;
};

/*
 * 1. Eine Kraft trägt in der kleinen Führungsstelle mehrere Funktionen. Jede
 *    von ihnen bekommt ihren Zähler, und alle Zähler entstehen zusammen mit
 *    den beiden Lagewerten in einer einzigen vorbereiteten Anweisung.
 */
$identity = [
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
$profiles = estab_sidebar_queue_profiles($identity);
$queues = estab_sidebar_queue_batch_query(
    $profiles,
    'nv_nachrichten',
    'usr_',
    false,
    42
);
$query = estab_situation_batch_query(
    $profiles,
    'nv_nachrichten',
    'usr_',
    false,
    42
);
$assert(
    str_starts_with($query['sql'], $queues['sql'] . ',')
        && substr_count($query['sql'], ') AS `queue_') === 3
        && substr_count($query['sql'], ';') === 0
        && str_contains($query['sql'], ') AS `vorrang_offen`')
        && str_contains($query['sql'], ') AS `aelteste_offene_minuten`')
        && $query['keys'] === $queues['keys']
        && $query['parameters']
            === array_merge($queues['parameters'], [42, 42]),
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Das Lagebild misst die Warteschlangen der getragenen Funktionen '
            . 'nicht in derselben Abfrage wie die Lagewerte oder bindet die '
            . 'Parameter nicht in der Reihenfolge der Spalten'
    )
);

/*
 * Eine Funktion ohne eigene Warteschlange - etwa die reine ETB-Führung -
 * darf die Lagewerte nicht verlieren.
 */
$withoutQueues = estab_situation_batch_query(
    [],
    'nv_nachrichten',
    'usr_',
    false,
    42
);
$assert(
    $withoutQueues['keys'] === []
        && $withoutQueues['parameters'] === [42, 42]
        && str_starts_with($withoutQueues['sql'], 'SELECT (SELECT COUNT(*)')
        && substr_count($withoutQueues['sql'], ') AS `queue_') === 0
        && str_contains($withoutQueues['sql'], ') AS `aelteste_offene_minuten`'),
    'Ohne getragene Warteschlange entfallen auch Vorrang und Liegezeit'
);

/*
 * 2. Beide Lagewerte fragen genau die offenen Nachrichten des Einsatzes und
 *    benutzen die kanonische Vorrangordnung statt einer eigenen Liste.
 */
$openPredicate = "`x01_abschluss` = 'f' AND `x00_status` <> 8";
$assert(
    substr_count($query['sql'], $openPredicate) === 2
        && str_contains(
            $query['sql'],
            estab_message_priority_order_sql('`09_vorrangstufe`') . ' > 0'
        )
        && str_contains(
            $query['sql'],
            'GREATEST(0, TIMESTAMPDIFF(MINUTE,'
                . ' MIN(COALESCE(`01_datum`, `12_abfzeit`)), NOW()))'
        ),
    'Vorrangverkehr und Liegezeit werden nicht über die offenen Nachrichten '
        . 'des Einsatzes und die kanonische Vorrangordnung gemessen'
);
$assertThrows(
    static fn (): array => estab_situation_batch_query(
        $profiles,
        'nv nachrichten',
        'usr_',
        false,
        42
    ),
    'Ein unmöglicher Tabellenname erreicht die Messung'
);
$assertThrows(
    static fn (): array => estab_situation_batch_query(
        $profiles,
        'nv_nachrichten',
        'usr_',
        false,
        0
    ),
    'Eine unmögliche Einsatzkennung erreicht die Messung'
);

/*
 * 3. Eine nicht messbare Liegezeit bleibt leer; alles andere ist eine Zahl,
 *    und Unsinn aus der Datenbank wird nicht durchgereicht.
 */
$assert(
    estab_situation_minutes_value(null) === null
        && estab_situation_minutes_value('75') === 75
        && estab_situation_minutes_value(0) === 0,
    'Die Liegezeit übernimmt keine gültigen Messwerte'
);
foreach (['-1', 'x', '1.5', 1.5, true] as $invalid) {
    $assertThrows(
        static fn (): ?int => estab_situation_minutes_value($invalid),
        'Eine unsaubere Liegezeit wird angenommen'
    );
}
$assert(
    estab_situation_duration_label(null) === ''
        && estab_situation_duration_label(7) === '7 min'
        && estab_situation_duration_label(80) === '1 h 20 min'
        && estab_situation_duration_label(1500) === '25 h 00 min',
    'Die Liegezeit wird nicht knapp und eindeutig geschrieben'
);
$now = new DateTimeImmutable('2026-08-19T07:30:00+02:00');
$assert(
    estab_situation_elapsed_minutes('2026-08-19 01:25:00', $now) === 365
        && estab_situation_elapsed_minutes(null, $now) === null
        && estab_situation_elapsed_minutes('0000-00-00 00:00:00', $now) === null
        && estab_situation_elapsed_minutes('2026-08-19 09:00:00', $now) === null,
    'Die Laufzeit des Einsatzes rechnet aus einem unbrauchbaren Beginn eine '
        . 'Zahl'
);

/*
 * 4. Der eine Satz, der führt: Vorrang schlägt die eigene Warteschlange, die
 *    eigene Warteschlange schlägt die Ruhe, und eine gescheiterte Messung
 *    wird benannt statt als Ruhe ausgegeben.
 */
$snapshotOf = static function (
    array $overrides = []
) use ($now): array {
    return array_replace([
        'incident' => [
            'command_post' => 'Führungsstelle Ortsverband Nord',
            'kennung' => 'EL-2026-004',
            'name' => 'Hochwasser Innenstadt',
            'beginn' => '2026-08-19 01:25:00',
            'ort' => 'Feuerwache 1',
            'mode' => 'STRICT',
        ],
        'identity' => ['funktion' => 'LdF', 'rolle' => 'Fernmelder'],
        'queues' => [
            [
                'key' => 'old_que_ldf',
                'label' => 'Bei LdF',
                'short_label' => 'LdF',
                'count' => 3,
            ],
            [
                'key' => 'old_que_stab_s2',
                'label' => 'Offene Meldungen',
                'short_label' => 'S2',
                'count' => 0,
            ],
        ],
        'urgent_open' => 0,
        'oldest_open_minutes' => 80,
        'staffing' => [
            'modus' => 'STRICT',
            'stationen' => [
                [
                    'funktion' => 'Si',
                    'rolle' => 'Stab',
                    'station' => 'Sichtung eingehender Nachrichten',
                    'bezeichnung' => 'Sichtung eingehender Nachrichten (Si)',
                    'besetzt' => true,
                ],
                [
                    'funktion' => 'A/W',
                    'rolle' => 'Fernmelder',
                    'station' => 'Annahme und Weitergabe',
                    'bezeichnung' => 'Annahme und Weitergabe (Fernmelder)',
                    'besetzt' => false,
                ],
            ],
            'unbesetzt' => ['Annahme und Weitergabe (Fernmelder)'],
        ],
        'measured' => true,
        'workspace_url' => '/4fach/index.php',
        'now' => $now,
    ], $overrides);
};
$assert(
    estab_situation_next_step($snapshotOf())['state'] === 'arbeit'
        && estab_situation_next_step(
            $snapshotOf(['urgent_open' => 2])
        )['state'] === 'vorrang'
        && str_contains(
            estab_situation_next_step($snapshotOf(['urgent_open' => 2]))['text'],
            '2 Nachrichten mit Vorrang'
        )
        && estab_situation_next_step($snapshotOf([
            'queues' => [],
            'urgent_open' => 0,
        ]))['state'] === 'ruhig'
        && estab_situation_next_step($snapshotOf([
            'measured' => false,
            'urgent_open' => null,
            'oldest_open_minutes' => null,
        ]))['state'] === 'unbekannt',
    'Der Einstieg nennt nicht den nächsten Schritt oder gibt eine '
        . 'gescheiterte Messung als Ruhe aus'
);

/*
 * 5. Das Lagebild beantwortet die Fragen des Einstiegs an einer Stelle.
 */
$markup = estab_situation_markup($snapshotOf(['urgent_open' => 2]));
$assert(
    str_contains($markup, 'Einsatz EL-2026-004 · Hochwasser Innenstadt')
        && str_contains($markup, 'Modus Streng')
        && str_contains($markup, 'seit 19.08.2026 01:25 · läuft 6 h 05 min')
        && str_contains($markup, 'data-estab-permission-mode="STRICT"'),
    'Der Einstieg sagt nicht, welcher Einsatz in welchem '
        . 'Berechtigungsmodus seit wann läuft'
);
$assert(
    str_contains(
        $markup,
        'data-estab-situation-queue="old_que_ldf"'
    )
        && str_contains(
            $markup,
            'data-estab-situation-queue="old_que_stab_s2"'
        )
        && substr_count($markup, 'class="estab-situation-queue-count"') === 2
        && str_contains($markup, 'aria-label="Bei LdF LdF: 3 wartend"')
        && str_contains(
            $markup,
            'aria-label="Offene Meldungen S2: 0 wartend"'
        ),
    estab_dv_requirement(
        'FUEST-DOPPELFUNKTION',
        'Der Einstieg weist nicht je getragener Funktion einen eigenen '
            . 'Zähler aus'
    )
);
$assert(
    str_contains($markup, 'data-estab-message-run-staffing="incomplete"')
        && str_contains($markup, 'data-estab-station="A/W"')
        && str_contains(
            $markup,
            'aria-label="Annahme und Weitergabe: unbesetzt"'
        )
        && str_contains($markup, 'estab-situation-station-open')
        && str_contains($markup, 'estab-situation-station-staffed')
        && str_contains(
            $markup,
            'Eine Nachricht, deren nächste Station unbesetzt ist'
        ),
    estab_dv_requirement(
        'FUEST-BESETZUNG-VOLLSTAENDIG',
        'Der Einstieg benennt die unbesetzten Stationen des '
            . 'Nachrichtenlaufs nicht'
    )
);
$assert(
    str_contains($markup, 'data-estab-situation-urgent="2"')
        && str_contains($markup, '<dd data-estab-situation-oldest>1 h 20 min')
        && str_contains($markup, 'data-estab-situation-state="vorrang"'),
    'Offener Vorrangverkehr und die älteste offene Nachricht fehlen im '
        . 'Lagebild'
);
$assert(
    str_contains(
        $markup,
        '<a id="estab-open" class="estab-button estab-button-primary'
        . ' estab-situation-enter" href="/4fach/index.php" autofocus'
    )
        && substr_count($markup, 'autofocus') === 1
        && str_contains($markup, 'Eingabetaste öffnet den'),
    'Der geübte Nutzer erreicht seine Arbeit nicht mit einem Tastendruck'
);
$complete = estab_situation_markup($snapshotOf([
    'staffing' => [
        'modus' => 'LOOSE',
        'stationen' => [[
            'funktion' => 'LdF',
            'rolle' => 'Fernmelder',
            'station' => 'Leitung des Fernmeldebetriebs',
            'bezeichnung' => 'Leitung des Fernmeldebetriebs (LdF)',
            'besetzt' => true,
        ]],
        'unbesetzt' => [],
    ],
    'queues' => [],
    'urgent_open' => 0,
    'oldest_open_minutes' => null,
]));
$assert(
    str_contains($complete, 'data-estab-message-run-staffing="complete"')
        && str_contains($complete, 'Alle Stationen sind besetzt.')
        && str_contains($complete, '<dd data-estab-situation-oldest>keine offene')
        && str_contains($complete, 'keine eigene Warteschlange'),
    'Die ruhige Lage wird nicht als ruhige Lage ausgewiesen'
);
$degraded = estab_situation_markup($snapshotOf([
    'measured' => false,
    'urgent_open' => null,
    'oldest_open_minutes' => null,
    'staffing' => null,
    'queues' => [[
        'key' => 'old_que_ldf',
        'label' => 'Bei LdF',
        'short_label' => 'LdF',
        'count' => null,
    ]],
]));
$assert(
    str_contains($degraded, 'data-estab-queue-state="unavailable"')
        && str_contains($degraded, 'data-estab-situation-urgent="–"')
        && str_contains(
            $degraded,
            'Die Besetzung des Nachrichtenlaufs konnte nicht ermittelt werden.'
        ),
    'Eine fehlende Messung wird als Null ausgegeben statt benannt'
);

/*
 * Feindlicher Text aus Einsatzstammdaten erreicht die Seite nicht.
 */
$hostile = estab_situation_markup($snapshotOf([
    'incident' => [
        'command_post' => '"><script>alert(1)</script>',
        'kennung' => '<img src=x onerror=alert(2)>',
        'name' => "Lage & Ordnung",
        'beginn' => '2026-08-19 01:25:00',
        'ort' => '" onmouseover="alert(3)',
        'mode' => 'LOOSE',
    ],
]));
$assert(
    !str_contains($hostile, '<script>')
        && !str_contains($hostile, '<img src=x')
        && !str_contains($hostile, '" onmouseover="')
        && str_contains($hostile, '&quot; onmouseover=&quot;')
        && str_contains($hostile, '&lt;script&gt;')
        && str_contains($hostile, 'Lage &amp; Ordnung')
        && str_contains($hostile, 'Modus Locker'),
    'Einsatzstammdaten erreichen die Einstiegsseite unmaskiert'
);
$assertThrows(
    static fn (): string => estab_situation_markup($snapshotOf([
        'incident' => [
            'command_post' => '',
            'kennung' => 'EL-1',
            'name' => 'Lage',
            'beginn' => null,
            'ort' => '',
            'mode' => 'STRICT',
        ],
    ])),
    'Ein Einsatz ohne Führungsstellenname wird trotzdem dargestellt'
);
$assertThrows(
    static fn (): string => estab_situation_markup($snapshotOf([
        'queues' => [[
            'key' => 'old_que_ldf',
            'label' => 'Bei LdF',
            'short_label' => 'LdF',
            'count' => -1,
        ]],
    ])),
    'Eine unmögliche Warteschlangenangabe erreicht die Anzeige'
);

/*
 * 6. Jede Klasse, die das Lagebild erzeugt, hat eine Regel im Stylesheet.
 *    Ein Name ohne Regel ist eine Anzeige ohne Bedeutung.
 */
$stylesheet = $read('estab-ui.css');
if (preg_match_all('~\.([A-Za-z][\w-]*)~', $stylesheet, $ruleMatches) === false) {
    throw new RuntimeException('Could not read the stylesheet selectors');
}
$styled = array_flip($ruleMatches[1]);
$missing = [];
foreach ([$markup, $complete, $degraded, $hostile] as $rendered) {
    if (preg_match_all('~class="([^"]+)"~', $rendered, $classMatches) === false) {
        throw new RuntimeException('Could not read the rendered classes');
    }
    foreach ($classMatches[1] as $classList) {
        foreach (preg_split('~\s+~', trim($classList)) ?: [] as $class) {
            if ($class !== '' && !isset($styled[$class])) {
                $missing[$class] = true;
            }
        }
    }
}
$assert(
    $missing === [],
    'Das Lagebild erzeugt Klassen ohne Regel im Stylesheet: '
        . implode(', ', array_keys($missing))
);

/*
 * 7. Der Einstieg passt ohne Scrollen auf einen flachen Bildschirm, und die
 *    verdrängte Einsatzanzeige verschwindet nur, solange sie sagt, dass ein
 *    Einsatz läuft. Jede Meldung eines gesperrten Betriebs bleibt stehen.
 */
$assert(
    preg_match(
        '~\.estab-root-page-situation\s*\{[^}]*min-height:\s*100vh~',
        $stylesheet
    ) === 1
    && preg_match(
        '~\.estab-root-main-situation\s*\{[^}]*min-height:\s*0~',
        $stylesheet
    ) === 1
    && preg_match(
        '~\.estab-situation-work,\s*\.estab-situation-run\s*\{'
            . '[^}]*overflow-y:\s*auto~',
        $stylesheet
    ) === 1
    && preg_match(
        '~@media \(max-height: 46rem\)\s*\{.*'
            . '\.estab-root-page-situation \.estab-menu-description\s*\{'
            . '\s*display:\s*none~s',
        $stylesheet
    ) === 1,
    'Das Lagebild erzwingt auf einem flachen Bildschirm eine scrollende Seite'
);
$assert(
    preg_match(
        '~\.estab-root-page-situation \[data-estab-incident-state="active"\]'
            . '\s*\{\s*display:\s*none;\s*\}~',
        $stylesheet
    ) === 1
    && !str_contains(
        $stylesheet,
        '.estab-root-page-situation [data-estab-incident-state="none"]'
    )
    && !str_contains(
        $stylesheet,
        '.estab-root-page-situation [data-estab-incident-state="unavailable"]'
    ),
    'Die Einstiegsseite verbirgt auch die Meldung eines gesperrten Betriebs'
);

/*
 * 8. Die Zifferntasten nehmen nichts weg: sie folgen nur den Kacheln, die
 *    wirklich entstehen, und lassen jede Eingabe in Ruhe.
 */
$card = [
    'text' => 'Nachrichtenvordruck',
    'info' => 'Vordrucke bearbeiten',
    'pic' => './icon.png',
    'link' => './4fach/index.php',
    'navigation_key' => 'messages',
    'visible' => true,
    'access' => 'application',
];
$grid = estab_root_menu_markup([
    1 => $card,
    2 => array_replace($card, ['visible' => false]),
    3 => array_replace($card, ['text' => 'Vordrucke']),
], true, true);
$assert(
    substr_count($grid, 'data-estab-shortcut=') === 2
        && str_contains($grid, 'data-estab-shortcut="1"')
        && str_contains($grid, 'data-estab-shortcut="2"')
        && !str_contains($grid, 'data-estab-shortcut="3"')
        && substr_count($grid, '<kbd class="estab-menu-shortcut">') === 2
        && str_contains(
            $grid,
            '<kbd class="estab-menu-shortcut">2</kbd>Vordrucke'
        ),
    'Die Zifferntasten überspringen eine ausgeblendete Kachel nicht oder '
        . 'stehen nicht sichtbar an der Kachel'
);
$assert(
    !str_contains(
        estab_root_menu_markup([1 => $card], true),
        'data-estab-shortcut'
    ),
    'Eine Kachel ohne Lagebild trägt trotzdem eine Ziffer'
);
$tenCards = [];
for ($index = 1; $index <= 12; $index++) {
    $tenCards[$index] = $card;
}
$assert(
    substr_count(
        estab_root_menu_markup($tenCards, true, true),
        'data-estab-shortcut='
    ) === 9,
    'Mehr als neun Kacheln erzwingen eine zweistellige Taste'
);
$assertThrows(
    static fn (): string => estab_root_menu_item_markup($card, true, 10),
    'Eine Kachel nimmt eine unmögliche Ziffer an'
);

$script = estab_situation_shortcut_script();
$assert(
    str_contains($script, '/^[1-9]$/.test(event.key)')
        && str_contains($script, 'event.altKey')
        && str_contains($script, 'event.ctrlKey')
        && str_contains($script, 'event.metaKey')
        && str_contains($script, 'event.shiftKey')
        && str_contains($script, 'INPUT|TEXTAREA|SELECT|BUTTON')
        && str_contains($script, 'target.isContentEditable')
        && !str_contains($script, 'eval(')
        && !str_contains($script, 'innerHTML')
        && !str_contains($script, '\\"'),
    'Die Zifferntasten greifen in eine Eingabe ein, werten fremden Text aus '
        . 'oder tragen kaputte Maskierung in die Seite'
);

/*
 * 9. Der Einstieg bleibt für nicht angemeldete Besucher und für Konten ohne
 *    gültigen operativen Zugriff, was er war, und das Lagebild entsteht nur
 *    hinter der bestehenden Prüfung.
 */
$index = $read('index.php');
$module = $read('app/situation_overview.php');
$assert(
    substr_count($index, "require_once __DIR__ . '/app/situation_overview.php';") === 1
        && substr_count($index, 'estab_situation_entry_snapshot(') === 1
        && str_contains($index, "\$rootIdentity = estab_auth_session_identity(\$_SESSION);")
        && str_contains($index, "(\$incidentState['active'] ?? false) === true")
        && str_contains($index, "['GET', 'HEAD'],")
        && str_contains($index, '<?php if ($situation !== null): ?>')
        && str_contains($index, '<?php else: ?>')
        && str_contains($index, 'id="estab-login"')
        && str_contains($index, '>Mit bestehendem Konto anmelden</a>')
        && str_contains($module, '<a id="estab-open"'),
    'Der Einstieg lädt das Lagebild ausserhalb der bestehenden Prüfung, '
        . 'verliert die bisherige Anmeldeseite oder benennt den Weg in die '
        . 'Arbeit anders als bisher'
);
$assert(
    str_contains(
        $index,
        'estab_root_menu_markup($menue, $authenticated, $situation !== null)'
    )
        && str_contains(
            $index,
            'estab_root_menu_markup($zusatz_menue, $authenticated)'
        ),
    'Die Ziffern hängen nicht am Lagebild oder greifen auf die '
        . 'Servicekacheln über'
);
$assert(
    substr_count($module, 'estab_read_require_operational_scope(') === 1
        && str_contains($module, 'return null;')
        && !str_contains($module, '$_GET')
        && !str_contains($module, '$_POST')
        && !str_contains($module, '$_SESSION')
        && !str_contains($module, 'INSERT ')
        && !str_contains($module, 'UPDATE ')
        && !str_contains($module, 'DELETE '),
    'Das Lagebild umgeht die Leseberechtigung, liest aus der Anfrage oder '
        . 'schreibt'
);
$assert(
    substr_count($module, '$connection->prepare(') === 1
        && substr_count($module, 'estab_situation_batch_query(') === 2
        && str_contains($module, 'estab_sidebar_queue_batch_query('),
    'Das Lagebild fragt die Datenbank mehr als einmal für seine Zählstände'
);

/*
 * Bei Doppelfunktionen steht dieselbe Nachricht in mehreren
 * Funktionswarteschlangen -- eine an "alle" gerichtete Nachricht passt auf
 * jede wahrgenommene Funktion. Die einzelnen Zahlen stimmen, ihre Summe ist
 * aber nicht die Zahl der Nachrichten. Der Satz darf deshalb keine Summe
 * behaupten.
 */
$doubleHat = $snapshotOf([
    'queues' => [
        [
            'key' => 'old_que_s2',
            'label' => 'Bei S2',
            'short_label' => 'S2',
            'count' => 1,
        ],
        [
            'key' => 'old_que_s3',
            'label' => 'Bei S3',
            'short_label' => 'S3',
            'count' => 1,
        ],
    ],
    'urgent_open' => 0,
]);
$doubleHatStep = estab_situation_next_step($doubleHat);
$assert(
    $doubleHatStep['state'] === 'arbeit'
        && !str_contains($doubleHatStep['text'], '2 Nachrichten warten'),
    'Das Lagebild summiert Warteschlangen und behauptet zu viele Nachrichten.'
);
$assert(
    str_contains($doubleHatStep['text'], '2 Ihrer Funktionen'),
    'Das Lagebild benennt bei Doppelfunktionen nicht die betroffenen Stationen.'
);

/*
 * Wartet nur eine Funktion, ist die Zahl eindeutig und wird genannt --
 * samt der Station, damit klar ist, wo zu arbeiten ist.
 */
$singleStep = estab_situation_next_step($snapshotOf([
    'queues' => [
        [
            'key' => 'old_que_ldf',
            'label' => 'Bei LdF',
            'short_label' => 'LdF',
            'count' => 3,
        ],
    ],
    'urgent_open' => 0,
]));
$assert(
    str_contains($singleStep['text'], '3 Nachrichten warten')
        && str_contains($singleStep['text'], 'LdF'),
    'Der Satz nennt bei einer einzelnen Warteschlange nicht Zahl und Station.'
);

printf("Situation overview: OK (%d assertions)\n", $assertions);
