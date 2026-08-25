<?php

declare(strict_types=1);

/**
 * Wo bin ich, in welcher Funktion, für welchen Einsatz?
 *
 * In einer Führungsstelle wechseln Menschen den Platz, übernehmen eine
 * Funktion und arbeiten an einem Rechner weiter, den vor zehn Minuten jemand
 * anderes bedient hat. Wer dann nicht auf einen Blick sieht, als wer er
 * gerade handelt, quittiert unter dem falschen Zeichen oder verteilt eine
 * Nachricht an die Warteschlange eines anderen. Der Bildschirm muss das
 * beantworten, ohne dass jemand danach sucht.
 *
 * Drei Angaben gehören dazu und stehen auf jeder Seite: der Einsatz und die
 * Führungsstelle, die eigene Funktion samt Rolle, und der Bereich, in dem
 * man sich befindet.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once $root . '/app/navigation.php';
require_once $root . '/app/session_ui.php';
require_once $root . '/app/incident_ui.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/*
 * Geprüft wird der sichtbare Text, nicht die Auszeichnung. Ein Wert, der nur
 * in einem data-Attribut steht, beantwortet keine Frage: Er ist für die
 * Anwendung da, nicht für den Menschen davor.
 */
$visibleText = static function (string $markup): string {
    // Skript- und Stilbloecke sind kein Text auf dem Bildschirm. Wer sie
    // mitliest, findet einen Wert auch dann, wenn ihn niemand sehen kann.
    $markup = preg_replace(
        '~<(script|style)\b[^>]*>.*?</\1>~is',
        ' ',
        $markup
    ) ?? $markup;
    $text = preg_replace('~<[^>]*>~', ' ', $markup);
    $text = html_entity_decode(
        is_string($text) ? $text : '',
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
    $text = preg_replace('~\s+~u', ' ', $text);
    return is_string($text) ? $text : '';
};

/* --- Der Bereich, in dem man gerade ist --- */

$identity = [
    'funktion' => 'S1',
    'rolle' => 'Stab',
    'estab_permission_mode' => 'LOOSE',
];

foreach (
    [
        '/4fach/vordrucke.php' => 'forms',
        '/4fach/liste.php' => 'messages',
        '/4fach/fuehrungsstelle.php' => 'command-post',
    ] as $script => $expectedKey
) {
    $markup = estab_navigation_markup(
        true,
        ['SCRIPT_NAME' => $script],
        false,
        false,
        $identity
    );
    $assert(
        substr_count($markup, 'aria-current="page"') === 1,
        estab_ux_requirement(
            'UX-STANDORT',
            'Auf ' . $script . ' ist '
                . substr_count($markup, 'aria-current="page"')
                . '-mal ausgewiesen, wo man sich befindet; genau einmal '
                . 'wäre richtig.'
        )
    );
    $assert(
        estab_navigation_active_key(['SCRIPT_NAME' => $script]) === $expectedKey,
        estab_ux_requirement(
            'UX-STANDORT',
            'Der Bereich zu ' . $script . ' heisst '
                . var_export(estab_navigation_active_key(
                    ['SCRIPT_NAME' => $script]
                ), true) . ' statt ' . $expectedKey . '.'
        )
    );
}

/*
 * Und auf einer Seite ausserhalb der bekannten Bereiche wird kein Bereich
 * behauptet. Eine falsche Ortsangabe ist schlechter als keine.
 */
foreach (['/stabetb-old/etb.php', '/other/index.php'] as $foreign) {
    $unknown = estab_navigation_markup(
        true,
        ['SCRIPT_NAME' => $foreign],
        false,
        false,
        $identity
    );
    $assert(
        substr_count($unknown, 'aria-current="page"') === 0,
        estab_ux_requirement(
            'UX-STANDORT',
            'Auf ' . $foreign . ' behauptet die Navigation trotzdem einen '
                . 'Standort.'
        )
    );
}

// Die Verwaltung ist ein eigener Bereich und wird auch so benannt.
$assert(
    estab_navigation_active_key(['SCRIPT_NAME' => '/4fadm/incidents.php'])
        === 'administration',
    estab_ux_requirement(
        'UX-STANDORT',
        'Die Einsatzverwaltung gehört zu keinem benannten Bereich.'
    )
);

/* --- Die Funktion, in der man handelt --- */

$session = [
    'vStab_benutzer' => 'Musterfrau',
    'vStab_kuerzel' => 'mm',
    'vStab_funktion' => 'Si',
    'vStab_rolle' => 'Stab',
];
$token = str_repeat('a', 64);

$bar = estab_session_ui_markup($session, $token, false, ['SCRIPT_NAME' => '/4fach/liste.php']);
$assert(
    $bar !== '',
    estab_ux_requirement(
        'UX-STANDORT',
        'Eine angemeldete Sitzung erzeugt keine Kopfzeile.'
    )
);
foreach (
    [
        'data-estab-user-function="Si"' => 'die Funktion',
        'data-estab-user-role="Stab"' => 'die Rolle',
        'data-estab-user-code="mm"' => 'das Namenszeichen',
        'data-estab-user-name="Musterfrau"' => 'den Namen',
    ] as $marker => $what
) {
    $assert(
        str_contains($bar, $marker),
        estab_ux_requirement(
            'UX-STANDORT',
            'Die Kopfzeile nennt ' . $what . ' nicht; wer den Platz '
                . 'übernimmt, weiss nicht, als wer er handelt.'
        )
    );
}
$assert(
    str_contains($bar, 'aria-label="Aktuelle Anmeldung"'),
    estab_ux_requirement(
        'UX-STANDORT',
        'Die Kopfzeile ist nicht als Angabe zur Anmeldung ausgezeichnet.'
    )
);

// Auch hier zählt der sichtbare Text: Funktion und Namenszeichen müssen
// gelesen werden können, nicht nur im Markup stehen.
$barText = $visibleText($bar);
foreach (['Si', 'mm', 'Musterfrau'] as $spoken) {
    $assert(
        str_contains($barText, $spoken),
        estab_ux_requirement(
            'UX-STANDORT',
            'Die Kopfzeile zeigt „' . $spoken . '“ nicht sichtbar an.'
        )
    );
}

/* --- Der Einsatz und die Führungsstelle --- */

$active = estab_incident_ui_markup([
    'availability' => 'available',
    'active' => true,
    'incident' => [
        'einsatz_id' => 12,
        'kennung' => 'HS-2026-04',
        'name' => 'Übung Rheinhochwasser',
        'fuehrungsstellenname' => 'Führungsstelle Heinsberg',
        'beginn' => '2026-08-25 06:00:00',
        'ort' => 'Heinsberg',
        'estab_permission_mode' => 'STRICT',
    ],
]);
$activeText = $visibleText($active);
foreach (
    ['HS-2026-04' => 'die Einsatzkennung',
        'Übung Rheinhochwasser' => 'den Einsatznamen',
        'Führungsstelle Heinsberg' => 'die Führungsstelle'] as $value => $what
) {
    $assert(
        str_contains($activeText, $value),
        estab_ux_requirement(
            'UX-STANDORT',
            'Die Einsatzanzeige nennt ' . $what . ' nicht sichtbar; im '
                . 'Markup zu stehen genügt nicht.'
        )
    );
}
$assert(
    str_contains($active, 'data-estab-incident-state="active"'),
    estab_ux_requirement(
        'UX-STANDORT',
        'Die Einsatzanzeige sagt nicht, dass ein Einsatz läuft.'
    )
);

/*
 * Ohne aktiven Einsatz wird das gesagt statt verschwiegen -- sonst hielte
 * jemand einen leeren Bildschirm für einen ruhigen Einsatz.
 */
foreach (
    [
        'kein Einsatz' => [['availability' => 'available', 'active' => false], 'none'],
        'Einsatzstatus unbekannt' => [['availability' => 'unavailable'], 'unavailable'],
    ] as $situation => [$state, $expectedState]
) {
    $markup = estab_incident_ui_markup($state);
    $assert(
        str_contains($markup, 'data-estab-incident-state="' . $expectedState . '"'),
        estab_ux_requirement(
            'UX-STANDORT',
            'Die Lage „' . $situation . '“ wird nicht als solche '
                . 'ausgewiesen.'
        )
    );
    $assert(
        str_contains($markup, 'Einsatz') || str_contains($markup, 'einsatz'),
        estab_ux_requirement(
            'UX-STANDORT',
            'Die Lage „' . $situation . '“ bleibt für den Anwender stumm.'
        )
    );
}

printf("Standort auf dem Bildschirm: OK (%d assertions)\n", $assertions);
