<?php

declare(strict_types=1);

/**
 * Ein Fokusring fuer die ganze Anwendung -- und der traegt auf jedem Grund.
 *
 * Der Bestand trug acht verschiedene Ringfarben. Vier davon sind Goldtoene
 * und erreichen auf hellem Grund 1.86 bis 2.48 -- ein Verstoss gegen WCAG
 * 1.4.11, und zwar an der Stelle, an der er am meisten kostet: Der Fokusring
 * ist die einzige Rueckmeldung, die ein Tastaturbediener bekommt, und im Stab
 * wird getippt, nicht gezeigt.
 *
 * Die Anwendung hat helle und dunkle Flaechen nebeneinander -- die
 * Inhaltsspalte weiss, Menue und Cockpit dunkelblau. Ein einfarbiger Ring
 * kann das nicht bedienen: Gold erreicht auf Weiss 1.67, Dunkelblau auf der
 * Menuespalte 1.00. Deshalb zwei Ringe, und auf jedem Grund traegt
 * mindestens einer.
 *
 * Diese Pruefung gilt fuer das ganze Stylesheet, nicht nur ausserhalb der
 * Migrationsgrenze: Ein Fokusring, der in der halben Anwendung anders
 * aussieht, ist schlechter als jeder der beiden Zustaende.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once __DIR__ . '/lib/stylesheet.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(is_string($stylesheet), 'Das Stylesheet ist nicht lesbar.');
$stylesheet = (string) $stylesheet;
$regeln = estab_test_css_regeln($stylesheet);

// Genau eine Regel setzt den Ring, und sie tut es fuer alles.
$global = [];
foreach ($regeln as $regel) {
    if ($regel['auswaehler'] !== ':focus-visible' || $regel['kontext'] !== '') {
        continue;
    }
    $global[] = $regel;
}
$assert(
    count($global) === 1,
    estab_ux_requirement(
        'GES-FOKUS-DOPPELRING',
        'Es gibt nicht genau eine allgemeine :focus-visible-Regel, sondern '
            . count($global) . '.'
    )
);

$werte = [];
foreach ($global[0]['deklarationen'] as $erklaerung) {
    $werte[$erklaerung['eigenschaft']] = $erklaerung['wert'];
}
$assert(
    ($werte['outline'] ?? '') === '2px solid var(--fokus-aussen)',
    estab_ux_requirement(
        'GES-FOKUS-DOPPELRING',
        'Der aeussere Ring ist nicht 2px in --fokus-aussen, sondern: '
            . ($werte['outline'] ?? 'gar nicht gesetzt')
    )
);
$assert(
    ($werte['outline-offset'] ?? '') === '1px',
    estab_ux_requirement(
        'GES-FOKUS-DOPPELRING',
        'Der Versatz ist nicht 1px, sondern: '
            . ($werte['outline-offset'] ?? 'gar nicht gesetzt')
    )
);
$assert(
    str_contains($werte['box-shadow'] ?? '', 'var(--fokus-innen)'),
    estab_ux_requirement(
        'GES-FOKUS-DOPPELRING',
        'Der innere Ring liegt nicht als box-shadow in --fokus-innen am '
            . 'Element: ' . ($werte['box-shadow'] ?? 'gar nicht gesetzt')
    )
);

// Keine Regel bringt einen eigenen Fokusring mit.
$eigene = [];
foreach ($regeln as $regel) {
    if ($regel === $global[0]) {
        continue;
    }
    if (!str_contains($regel['auswaehler'], 'focus')) {
        continue;
    }
    // Der Ersatzring fuer erzwungene Farben ist gewollt und wird oben
    // eigens verlangt; er ist kein eigener Ring, sondern derselbe.
    if (str_contains($regel['kontext'], 'forced-colors')) {
        continue;
    }
    foreach ($regel['deklarationen'] as $erklaerung) {
        if (in_array($erklaerung['eigenschaft'], ['outline', 'outline-offset'], true)) {
            $eigene[] = $regel['auswaehler'] . ' { ' . $erklaerung['eigenschaft']
                . ': ' . $erklaerung['wert'] . ' } Zeile ' . $regel['zeile'];
        }
    }
}
$assert(
    $eigene === [],
    estab_ux_requirement(
        'GES-FOKUS-DOPPELRING',
        'Diese Regeln bringen einen eigenen Fokusring mit: '
            . implode(' | ', array_slice($eigene, 0, 6))
    )
);

// Bei erzwungenen Farben tritt der Systemring an die Stelle.
$erzwungen = false;
foreach ($regeln as $regel) {
    if (!str_contains($regel['kontext'], 'forced-colors')) {
        continue;
    }
    if (!str_contains($regel['auswaehler'], 'focus-visible')) {
        continue;
    }
    foreach ($regel['deklarationen'] as $erklaerung) {
        if ($erklaerung['eigenschaft'] === 'outline'
            && str_contains($erklaerung['wert'], 'CanvasText')) {
            $erzwungen = true;
        }
    }
}
$assert(
    $erzwungen,
    estab_ux_requirement(
        'GES-FOKUS-DOPPELRING',
        'Bei erzwungenen Farben tritt kein Systemring an die Stelle des '
            . 'Doppelrings. Der box-shadow faellt dort weg, und ohne Ersatz '
            . 'bliebe der Fokus unsichtbar.'
    )
);

// `outline: none` ohne Ersatz ist verboten. Ausnahmslos.
$entfernt = [];
foreach ($regeln as $regel) {
    foreach ($regel['deklarationen'] as $erklaerung) {
        if ($erklaerung['eigenschaft'] !== 'outline') {
            continue;
        }
        $wert = strtolower(trim($erklaerung['wert']));
        if ($wert === 'none' || $wert === '0') {
            $entfernt[] = $regel['auswaehler'] . ' Zeile ' . $regel['zeile'];
        }
    }
}
$assert(
    $entfernt === [],
    estab_ux_requirement(
        'GES-FOKUS-DOPPELRING',
        'Diese Regeln entfernen den Fokusring: ' . implode(', ', $entfernt)
    )
);

printf(
    "Gestaltung Fokus: OK (%d assertions, ein Ring fuer %d Regeln)\n",
    $assertions,
    count($regeln)
);
