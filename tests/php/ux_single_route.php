<?php

declare(strict_types=1);

/**
 * Ein Weg je Ziel.
 *
 * Die Anwendung bietet zwei Einstiege in dieselben Bereiche: die
 * Bereichsnavigation, die auf jeder Seite steht, und die Kachelübersicht auf
 * der Startseite. Zwei Einstiege sind nicht das Problem -- zwei Einstiege,
 * die sich unterschiedlich verhalten, sind es. Wer über die Kachel
 * hineinkommt und über das Menü nicht, lernt zwei Regeln statt einer und
 * traut am Ende keiner von beiden.
 *
 * Geprüft wird deshalb, dass beide Einstiege dieselben Ziele kennen, dass sie
 * dieselben Adressen führen, dass ein Ziel unter genau einer Adresse steht --
 * und dass eine Kachel, die das Menü gerade sperrt, ebenfalls sperrt und
 * denselben Grund nennt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once $root . '/app/navigation.php';
require_once $root . '/app/root_menu.php';

putenv('ESTAB_PUBLIC_URL=/');
putenv('ESTAB_BASE_PATH=');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$items = array_merge(
    estab_navigation_areas(),
    estab_navigation_services()
);

/* --- Ein Ziel, eine Adresse --- */

$paths = [];
foreach ($items as $item) {
    $key = (string) $item['key'];
    $path = (string) $item['path'];
    $assert(
        !isset($paths[$path]),
        estab_ux_requirement(
            'UX-MENUE-EIN-WEG',
            'Die Adresse ' . $path . ' wird von zwei Einträgen geführt: '
                . ($paths[$path] ?? '?') . ' und ' . $key . '.'
        )
    );
    $paths[$path] = $key;
}

/*
 * Und der Weg führt zurück: Wer dort ist, sieht denselben Eintrag leuchten,
 * über den er hingekommen ist. Ein Ziel, das sich nicht wiedererkennt, macht
 * die Ortsangabe im Menü zur Behauptung.
 */
foreach ($items as $item) {
    $key = (string) $item['key'];
    // Die Auflösung arbeitet zur Laufzeit auf dem Pfad ohne Abfrageteil,
    // genau wie estab_navigation_active_key() ihn übergibt.
    $path = (string) parse_url((string) $item['path'], PHP_URL_PATH);
    $resolved = estab_navigation_key_for_path($path);
    $assert(
        $resolved === $key,
        estab_ux_requirement(
            'UX-MENUE-EIN-WEG',
            'Der Eintrag ' . $key . ' führt nach ' . $path
                . ', und dort leuchtet ' . var_export($resolved, true) . '.'
        )
    );
}

/* --- Beide Einstiege kennen dieselben Ziele --- */

$menuFile = $root . '/menue.inc.php';
$menuSource = file_get_contents($menuFile);
$assert(
    is_string($menuSource),
    'Die Kachelübersicht ist nicht lesbar.'
);
preg_match_all(
    '~\["navigation_key"\]\s*=\s*"([a-z0-9-]+)"~',
    is_string($menuSource) ? $menuSource : '',
    $tiles
);
$tileKeys = $tiles[1];
sort($tileKeys, SORT_STRING);
/*
 * Bis auf einen: Die Startseite hat keine Kachel zu sich selbst. Sie ist die
 * Übersicht, und eine Kachel „hierhin“ wäre kein zweiter Weg, sondern eine
 * Schleife.
 */
$navigationKeys = array_values(array_filter(
    array_column($items, 'key'),
    static fn (string $key): bool => $key !== 'overview'
));
sort($navigationKeys, SORT_STRING);
$assert(
    $tileKeys === $navigationKeys,
    estab_ux_requirement(
        'UX-MENUE-EIN-WEG',
        'Die Kachelübersicht kennt ' . implode(', ', $tileKeys)
            . ', das Menü ' . implode(', ', $navigationKeys) . '.'
    )
);

// Und jede Kachel nennt ihren Eintrag; eine Kachel ohne Eintrag wäre ein
// Weg, den das Menü nicht kennt.
$tileCount = preg_match_all('~\["link"\]\s*=\s*"~', (string) $menuSource);
$assert(
    $tileCount === count($tileKeys),
    estab_ux_requirement(
        'UX-MENUE-EIN-WEG',
        'Die Kachelübersicht führt ' . $tileCount . ' Ziele, aber nur '
            . count($tileKeys) . ' davon nennen ihren Menüeintrag.'
    )
);

/* --- Und die Kachel verhält sich wie der Menüeintrag --- */

/*
 * Beide sind anklickbar, auch dorthin, wo die eigene Funktion nichts zu
 * suchen hat. Der erste Anlauf hatte beide gesperrt und den Grund
 * hingeschrieben; im Betrieb sprengte der Satz die schmale Menuespalte, und
 * ein Menue ist ohnehin zum Hingehen da. Wer wissen will, warum, klickt und
 * liest es am Ziel.
 *
 * Entscheidend bleibt, dass sich beide Einstiege gleich verhalten -- zwei
 * Einstiege, die sich unterschiedlich verhalten, lehren zwei Regeln.
 */

/** Eine Kachel, so wie menue.inc.php sie beschreibt. */
$tile = static function (string $key): array {
    $item = estab_navigation_item_for_key($key);
    if ($item === null) {
        throw new RuntimeException('Unbekannter Eintrag: ' . $key);
    }
    return [
        'text' => $item['label'],
        'info' => 'Beschreibung',
        'pic' => './4fsym/icon.png',
        'link' => './' . $item['path'],
        'visible' => true,
        'access' => $item['access'] === 'public' ? 'public' : 'application',
        'navigation_key' => $key,
    ];
};

$identities = [
    'S1 · Stab' => ['funktion' => 'S1', 'rolle' => 'Stab',
        'estab_permission_mode' => 'LOOSE'],
    'S2 · Stab' => ['funktion' => 'S2', 'rolle' => 'Stab',
        'estab_permission_mode' => 'LOOSE'],
    'LdF · Fernmelder' => ['funktion' => 'LdF', 'rolle' => 'Fernmelder',
        'estab_permission_mode' => 'LOOSE'],
    'S2 ohne Dienst' => ['funktion' => 'S2', 'rolle' => 'Stab',
        'estab_permission_mode' => 'STRICT'],
];

foreach ($identities as $situation => $identity) {
    $menu = estab_navigation_markup(
        true,
        ['SCRIPT_NAME' => '/4fach/vordrucke.php'],
        false,
        false,
        $identity
    );
    foreach ($items as $item) {
        $key = (string) $item['key'];
        $markup = estab_root_menu_item_markup($tile($key), true, null, $identity);

        $assert(
            !str_contains($markup, 'estab-menu-card-blocked')
                && !str_contains($markup, 'aria-disabled'),
            estab_ux_requirement(
                'UX-MENUE-EIN-WEG',
                'Die Kachel ' . $key . ' ist für ' . $situation
                    . ' gesperrt. Beide Einstiege sind anklickbar; ob dort '
                    . 'jemand hineindarf, sagt das Ziel.'
            )
        );
        $assert(
            str_contains($markup, 'href="./' . $item['path'] . '"'),
            estab_ux_requirement(
                'UX-MENUE-EIN-WEG',
                'Die Kachel ' . $key . ' führt für ' . $situation
                    . ' nicht an ihr Ziel.'
            )
        );

        // Und der Menüeintrag führt an dieselbe Adresse.
        $assert(
            str_contains(
                $menu,
                'data-estab-nav-key="' . $key . '"'
            ),
            estab_ux_requirement(
                'UX-MENUE-EIN-WEG',
                'Das Menü führt den Eintrag ' . $key . ' für ' . $situation
                    . ' nicht; die Kachel täte es. Zwei Einstiege, zwei '
                    . 'Regeln.'
            )
        );
    }
}

/*
 * Ohne Anmeldung führen beide in die Anmeldung und sagen das. Das ist kein
 * Widerspruch zur Anklickbarkeit: Es fehlt nicht die Zuständigkeit, sondern
 * die Anmeldung, und der Weg dorthin ist der Sinn des Verweises.
 */
$anonymous = estab_root_menu_item_markup($tile('messages'), false);
$assert(
    str_contains($anonymous, 'Anmeldung erforderlich')
        && str_contains($anonymous, 'login_flow=existing'),
    estab_ux_requirement(
        'UX-MENUE-EIN-WEG',
        'Ohne Anmeldung führt die Kachel nicht mehr in die Anmeldung.'
    )
);

printf("Ein Weg je Ziel: OK (%d assertions)\n", $assertions);
