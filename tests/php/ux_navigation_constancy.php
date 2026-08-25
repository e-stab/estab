<?php

declare(strict_types=1);

/**
 * Das Menü steht still.
 *
 * Wer im Einsatz eine Anwendung bedient, merkt sich Wege, nicht Beschriftungen:
 * dritter Eintrag von oben, zweiter von unten. Verschwindet ein Eintrag,
 * sobald die eigene Funktion ihn nicht ansteuern darf, rutscht alles darunter
 * nach -- und der gemerkte Weg führt woandershin. Schlimmer noch: Wer den
 * Eintrag gestern gesehen hat und heute nicht mehr, sucht ihn, statt zu
 * arbeiten.
 *
 * Deshalb sind alle Einträge immer da, in derselben Zahl und derselben
 * Reihenfolge. Was gerade nicht ansteuerbar ist, steht sichtbar und inaktiv
 * mit einem Grund: nicht "weg", sondern "nicht für Sie, und hier ist warum".
 *
 * Das Menü ist ausdrücklich keine Sicherheitsgrenze. Es war nie eine -- jeder
 * Endpunkt prüft seine Berechtigung selbst, und daran ändert die Sichtbarkeit
 * nichts. Der Test hält beides fest, damit ein sichtbarer Eintrag nicht als
 * Freigabe missverstanden wird.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once $root . '/app/navigation.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expected = array_merge(
    array_column(estab_navigation_areas(), 'key'),
    array_column(estab_navigation_services(), 'key')
);

/** Die Einträge einer gerenderten Navigation, in der Reihenfolge der Ausgabe. */
$entries = static function (string $markup): array {
    preg_match_all(
        '~data-estab-navigation-key="([a-z0-9-]+)"~',
        $markup,
        $keys
    );
    return $keys[1];
};

/*
 * Alle Kombinationen, die im Betrieb vorkommen: Betriebsart, angetretener
 * Dienst und getragene Funktion.
 */
$situations = [];
foreach (['STRICT', 'LOOSE'] as $mode) {
    foreach ([null, 73] as $assignment) {
        foreach (
            [
                ['S1', 'Stab'], ['S2', 'Stab'], ['S6', 'Stab'],
                ['Si', 'Stab'], ['LdF', 'Fernmelder'], ['A/W', 'Fernmelder'],
                ['ETB', 'Stab'],
            ] as [$function, $role]
        ) {
            $identity = [
                'funktion' => $function,
                'rolle' => $role,
                'kuerzel' => 'mm',
                'estab_permission_mode' => $mode,
            ];
            if ($assignment !== null) {
                $identity['duty_assignment_id'] = $assignment;
            }
            $situations[
                $mode . '/' . ($assignment === null ? 'ohne Dienst' : 'im Dienst')
                    . '/' . $function . ' · ' . $role
            ] = $identity;
        }
    }
}

/* --- Menge und Reihenfolge bleiben gleich --- */

foreach ($situations as $situation => $identity) {
    foreach (
        [
            'voll' => [false, false],
            'kompakt' => [true, false],
            'Seitenleiste' => [true, true],
        ] as $mode => [$compact, $sidebar]
    ) {
        $markup = estab_navigation_markup(
            true,
            ['SCRIPT_NAME' => '/4fach/vordrucke.php'],
            $compact,
            $sidebar,
            $identity
        );
        $found = $entries($markup);
        $assert(
            $found === $expected,
            estab_ux_requirement(
                'UX-MENUE-ORTSKONSTANZ',
                'In der Lage ' . $situation . ' (' . $mode . ') zeigt das '
                    . 'Menü ' . count($found) . ' Einträge ('
                    . implode(', ', $found) . ') statt aller '
                    . count($expected) . ' in fester Reihenfolge.'
            )
        );
    }
}

/* --- Jeder gesperrte Eintrag trägt einen Grund --- */

foreach ($situations as $situation => $identity) {
    $markup = estab_navigation_markup(
        true,
        ['SCRIPT_NAME' => '/4fach/vordrucke.php'],
        false,
        false,
        $identity
    );
    preg_match_all(
        '~<li[^>]*data-estab-navigation-key="([a-z0-9-]+)"[^>]*>(.*?)</li>~s',
        $markup,
        $items,
        PREG_SET_ORDER
    );
    $assert(
        count($items) === count($expected),
        estab_ux_requirement(
            'UX-MENUE-ORTSKONSTANZ',
            'In der Lage ' . $situation . ' lassen sich die Einträge nicht '
                . 'einzeln lesen.'
        )
    );
    foreach ($items as [$whole, $key, $body]) {
        $blocked = str_contains($whole, 'data-estab-navigation-blocked');
        if (!$blocked) {
            $assert(
                str_contains($body, '<a '),
                estab_ux_requirement(
                    'UX-MENUE-ORTSKONSTANZ',
                    'Der Eintrag ' . $key . ' ist in der Lage ' . $situation
                        . ' weder ansteuerbar noch als gesperrt gekennzeichnet.'
                )
            );
            continue;
        }
        $assert(
            !str_contains($body, '<a ') && !str_contains($body, 'href='),
            estab_ux_requirement(
                'UX-MENUE-ORTSKONSTANZ',
                'Der gesperrte Eintrag ' . $key . ' ist in der Lage '
                    . $situation . ' trotzdem anklickbar.'
            )
        );
        $assert(
            str_contains($body, 'estab-navigation-blocked-reason'),
            estab_ux_requirement(
                'UX-MENUE-ORTSKONSTANZ',
                'Der gesperrte Eintrag ' . $key . ' sagt in der Lage '
                    . $situation . ' nicht, warum er gesperrt ist.'
            )
        );
        if (
            preg_match(
                '~estab-navigation-blocked-reason[^>]*>([^<]+)<~',
                $body,
                $reason
            ) === 1
        ) {
            $assert(
                mb_strlen(trim($reason[1])) >= 20,
                estab_ux_requirement(
                    'UX-MENUE-ORTSKONSTANZ',
                    'Der Grund am Eintrag ' . $key . ' lautet „'
                        . trim($reason[1]) . '“ und erklärt nichts.'
                )
            );
        }
        $assert(
            str_contains($whole, 'aria-disabled="true"'),
            estab_ux_requirement(
                'UX-MENUE-ORTSKONSTANZ',
                'Der gesperrte Eintrag ' . $key . ' ist für '
                    . 'Vorleseprogramme nicht als gesperrt erkennbar.'
            )
        );
    }
}

/* --- Und es wird wirklich gesperrt, wo es zu sperren gibt --- */

/*
 * Ein Menü, das nie sperrt, bestünde diesen Test ebenfalls. Deshalb wird
 * geprüft, dass die geschützten Bereiche für eine fremde Funktion tatsächlich
 * gesperrt und für die zuständige offen sind.
 */
$blockedKeysFor = static function (array $identity) use ($entries): array {
    $markup = estab_navigation_markup(
        true,
        ['SCRIPT_NAME' => '/4fach/vordrucke.php'],
        false,
        false,
        $identity
    );
    preg_match_all(
        '~<li[^>]*data-estab-navigation-key="([a-z0-9-]+)"[^>]*'
            . 'data-estab-navigation-blocked~s',
        $markup,
        $keys
    );
    return $keys[1];
};

$s2 = ['funktion' => 'S2', 'rolle' => 'Stab', 'estab_permission_mode' => 'LOOSE'];
$ldf = ['funktion' => 'LdF', 'rolle' => 'Fernmelder', 'estab_permission_mode' => 'LOOSE'];

$assert(
    in_array('tracking', $blockedKeysFor($s2), true)
        && !in_array('message-overview', $blockedKeysFor($s2), true),
    estab_ux_requirement(
        'UX-MENUE-ORTSKONSTANZ',
        'S 2 findet die Nachweisung offen oder die Meldungsübersicht '
            . 'gesperrt.'
    )
);
$assert(
    in_array('message-overview', $blockedKeysFor($ldf), true)
        && !in_array('tracking', $blockedKeysFor($ldf), true),
    estab_ux_requirement(
        'UX-MENUE-ORTSKONSTANZ',
        'Der Leiter des Fernmeldebetriebes findet die Meldungsübersicht '
            . 'offen oder die Nachweisung gesperrt.'
    )
);

// Und im Modus STRENG ohne angenommenen Dienst führt nur ein Weg weiter.
$strict = [
    'funktion' => 'S2', 'rolle' => 'Stab', 'estab_permission_mode' => 'STRICT',
];
$strictBlocked = $blockedKeysFor($strict);
$assert(
    !in_array('command-post', $strictBlocked, true)
        && in_array('messages', $strictBlocked, true),
    estab_ux_requirement(
        'UX-MENUE-ORTSKONSTANZ',
        'Ohne angetretenen Dienst führt entweder kein Weg zum '
            . 'Führungsstellenbetrieb oder der Nachrichtenvordruck steht '
            . 'offen.'
    )
);

/* --- Das Menü ist keine Sicherheitsgrenze --- */

/*
 * Es war nie eine, und die Sichtbarkeit macht es zu keiner. Jeder Endpunkt
 * prüft seine Berechtigung selbst; stünde die Prüfung nur im Menü, wäre eine
 * eingetippte Adresse eine offene Tür.
 */
$guards = [
    'estab_navigation_require_session' => 'die Anmeldung',
    'estab_navigation_require_selected_duty' => 'den angetretenen Dienst',
    'estab_read_require_area' => 'die Berechtigung für den Bereich',
    'EstabReadPermissionException' => 'die Abweisung ohne Berechtigung',
];
foreach (
    ['4fueltg/ue_ltg.php' => 'message-overview',
        '4fach/nachwea.php' => 'tracking'] as $endpoint => $area
) {
    $source = file_get_contents($root . '/' . $endpoint);
    $assert(
        is_string($source),
        'Der Endpunkt ' . $endpoint . ' ist nicht lesbar.'
    );
    if (!is_string($source)) {
        continue;
    }
    foreach ($guards as $guard => $what) {
        $assert(
            str_contains($source, $guard),
            estab_ux_requirement(
                'UX-MENUE-ORTSKONSTANZ',
                'Der Endpunkt ' . $endpoint . ' prüft ' . $what
                    . ' nicht selbst; die Sichtbarkeit im Menü würde damit '
                    . 'zur Freigabe.'
            )
        );
    }
    $assert(
        str_contains($source, '"' . $area . '"')
            || str_contains($source, "'" . $area . "'"),
        estab_ux_requirement(
            'UX-MENUE-ORTSKONSTANZ',
            'Der Endpunkt ' . $endpoint . ' prüft die Berechtigung für '
                . 'einen anderen Bereich als ' . $area . '.'
        )
    );
}

printf("Ortskonstanz der Navigation: OK (%d assertions)\n", $assertions);
