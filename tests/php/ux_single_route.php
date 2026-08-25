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

/* --- Und die Kachel sperrt, was das Menü sperrt --- */

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

$s1 = ['funktion' => 'S1', 'rolle' => 'Stab', 'estab_permission_mode' => 'LOOSE'];
$s2 = ['funktion' => 'S2', 'rolle' => 'Stab', 'estab_permission_mode' => 'LOOSE'];
$ldf = ['funktion' => 'LdF', 'rolle' => 'Fernmelder', 'estab_permission_mode' => 'LOOSE'];
$strict = ['funktion' => 'S2', 'rolle' => 'Stab', 'estab_permission_mode' => 'STRICT'];

foreach (
    [
        'S1 · Stab' => [$s1, ['message-overview', 'tracking']],
        'S2 · Stab' => [$s2, ['tracking']],
        'LdF · Fernmelder' => [$ldf, ['message-overview']],
        'S2 ohne Dienst' => [
            $strict,
            ['messages', 'message-overview', 'forms', 'incident-log',
                'technical-log', 'tracking'],
        ],
    ] as $situation => [$identity, $blockedKeys]
) {
    foreach ($items as $item) {
        $key = (string) $item['key'];
        $markup = estab_root_menu_item_markup($tile($key), true, null, $identity);
        $shouldBlock = in_array($key, $blockedKeys, true);
        $blocked = str_contains($markup, 'estab-menu-card-blocked');
        $assert(
            $blocked === $shouldBlock,
            estab_ux_requirement(
                'UX-MENUE-EIN-WEG',
                'Die Kachel ' . $key . ' ist für ' . $situation
                    . ($blocked ? ' gesperrt' : ' offen')
                    . ', das Menü sagt das Gegenteil.'
            )
        );
        if (!$shouldBlock) {
            $assert(
                str_contains($markup, 'href="./' . $item['path'] . '"'),
                estab_ux_requirement(
                    'UX-MENUE-EIN-WEG',
                    'Die offene Kachel ' . $key . ' führt für ' . $situation
                        . ' nicht an ihr Ziel.'
                )
            );
            continue;
        }
        $assert(
            !str_contains($markup, 'href='),
            estab_ux_requirement(
                'UX-MENUE-EIN-WEG',
                'Die gesperrte Kachel ' . $key . ' ist für ' . $situation
                    . ' trotzdem anklickbar; über das Menü ginge es nicht.'
            )
        );
        $reason = estab_navigation_duty_access_reason($item, $identity);
        $assert(
            $reason !== '' && str_contains($markup, estab_auth_html($reason)),
            estab_ux_requirement(
                'UX-MENUE-EIN-WEG',
                'Die gesperrte Kachel ' . $key . ' nennt für ' . $situation
                    . ' nicht denselben Grund wie das Menü.'
            )
        );
    }
}

/*
 * Ohne Anmeldung bleibt es beim bisherigen Weg: Die Kachel führt in die
 * Anmeldung und sagt das. Eine Sperre mit fachlichem Grund wäre dort
 * irreführend -- es fehlt nicht die Zuständigkeit, sondern die Anmeldung.
 */
$anonymous = estab_root_menu_item_markup($tile('messages'), false);
$assert(
    str_contains($anonymous, 'Anmeldung erforderlich')
        && str_contains($anonymous, 'login_flow=existing')
        && !str_contains($anonymous, 'estab-menu-card-blocked'),
    estab_ux_requirement(
        'UX-MENUE-EIN-WEG',
        'Ohne Anmeldung führt die Kachel nicht mehr in die Anmeldung.'
    )
);

printf("Ein Weg je Ziel: OK (%d assertions)\n", $assertions);
