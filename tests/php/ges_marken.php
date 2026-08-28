<?php

declare(strict_types=1);

/**
 * Jede Farbe kommt aus einer Marke.
 *
 * Der Bestand trug 359 verschiedene Hexfarben. Das ist kein Gestaltungsfehler
 * einzelner Stellen, sondern das erwartbare Ergebnis, wenn jede Stelle ihre
 * Werte selbst waehlt: „Gleiche Bedeutung heisst gleiches Aussehen" laesst
 * sich dann nicht mehr nachpruefen, nur noch hoffen.
 *
 * Diese Pruefung verlangt, dass jede Farbangabe aus dem :root-Block kommt.
 * Sie ist scharf fuer alles, was nicht zum Papierfaksimile gehoert -- und
 * die schrumpft mit jeder Aufgabe des Umsetzungsplans.
 *
 * Solange der Bestand noch weitgehend in der Grenze steht, faende diese
 * Pruefung von allein wenig. Deshalb prueft sie sich am Ende selbst: Ein
 * eingebautes Literal muss auffallen. Ein Waechter, der nicht beisst, ist
 * schlimmer als keiner -- er beruhigt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once __DIR__ . '/lib/stylesheet.php';
require_once __DIR__ . '/lib/vordruck_ausnahme.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/**
 * Farbangaben in einem Erklaerungswert finden.
 *
 * Erlaubt bleiben Angaben, die keine Farbe *waehlen*: `transparent` und
 * `currentColor` uebernehmen, was ohnehin gilt; die Systemfarben des
 * Kontrastmodus werden vom Betriebssystem gesetzt und duerfen gar nicht
 * uebersteuert werden.
 *
 * @return list<string>
 */
$literale = static function (string $wert): array {
    $treffer = [];
    if (preg_match_all('~#[0-9a-fA-F]{3,8}\b~', $wert, $hex) === false) {
        throw new RuntimeException('Farbsuche fehlgeschlagen: ' . $wert);
    }
    foreach ($hex[0] as $einzeln) {
        $treffer[] = $einzeln;
    }
    if (preg_match_all('~\b(?:rgba?|hsla?)\s*\(~i', $wert, $funktion)) {
        foreach ($funktion[0] as $einzeln) {
            $treffer[] = rtrim($einzeln, '( ');
        }
    }
    return $treffer;
};

$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(is_string($stylesheet), 'Das Stylesheet ist nicht lesbar.');
$stylesheet = (string) $stylesheet;

$marken = estab_test_css_marken($stylesheet);
$assert(
    $marken !== [],
    estab_ux_requirement(
        'GES-MARKEN',
        'Der :root-Block fuehrt keine einzige Marke.'
    )
);

// Jede Marke, die eine Regel benutzt, muss es auch geben. Ein Tippfehler in
// einem var()-Aufruf faellt sonst nirgends auf: Der Browser laesst die
// Erklaerung still fallen, und die Flaeche bleibt farblos.
//
// Bekannt sind dabei nicht nur die Marken aus :root: Die Zeitleiste und das
// Vordruckblatt setzen eigene auf ihrem Wurzelelement, und die gelten dort.
//
// Auch diese Pruefung gilt nur ausserhalb des Papierfaksimiles. Der Bestand
// kennt einen solchen Fall bereits: `var(--estab-menu-background, #f0f0f0)`
// ruft eine Marke auf, die nirgends definiert ist -- der Rueckfallwert ist
// der eigentliche Wert, also ein Farbliteral in Verkleidung. Er faellt auf,
// sobald die Menuekacheln umgestellt werden, und nicht vorher.
$bekannt = estab_test_css_definierte_marken($stylesheet);
$regeln = estab_test_css_regeln($stylesheet);
$unbekannt = [];
foreach ($regeln as $regel) {
    if (estab_test_ist_vordruck($regel['auswaehler'])) {
        continue;
    }
    foreach ($regel['deklarationen'] as $erklaerung) {
        if (preg_match_all('~var\(\s*(--[a-z0-9-]+)~i', $erklaerung['wert'], $t)) {
            foreach ($t[1] as $name) {
                if (!array_key_exists($name, $bekannt)) {
                    $unbekannt[] = $name . ' (Zeile ' . $regel['zeile'] . ')';
                }
            }
        }
    }
}
$assert(
    $unbekannt === [],
    estab_ux_requirement(
        'GES-MARKEN',
        'Diese Regeln rufen Marken auf, die es nicht gibt: '
            . implode(', ', array_slice($unbekannt, 0, 8))
    )
);

// Die eigentliche Pruefung: kein Farbliteral ausserhalb des :root-Blocks --
// fuer alles, was nicht zum Papierfaksimile gehoert.
$offen = [];
$geprueft = 0;
foreach ($regeln as $regel) {
    if (str_contains($regel['auswaehler'], ':root')) {
        continue;
    }
    if (estab_test_ist_vordruck($regel['auswaehler'])) {
        continue;
    }
    // Ein Keyframe-Schritt ist kein Bereich, sondern ein Zwischenstand einer
    // Bewegung; er gehoert zu der Regel, die ihn auslöst.
    if (estab_test_css_ist_keyframe($regel)) {
        continue;
    }
    $geprueft++;
    foreach ($regel['deklarationen'] as $erklaerung) {
        if (str_starts_with($erklaerung['eigenschaft'], '--')) {
            continue;
        }
        foreach ($literale($erklaerung['wert']) as $literal) {
            $offen[] = $regel['auswaehler'] . ' { ' . $erklaerung['eigenschaft']
                . ': ' . $literal . ' } Zeile ' . $regel['zeile'];
        }
    }
}
$assert(
    $offen === [],
    estab_ux_requirement(
        'GES-MARKEN',
        'Diese umgestellten Regeln tragen noch eigene Farben statt Marken: '
            . implode(' | ', array_slice($offen, 0, 6))
    )
);

// Beisst der Waechter? Solange der Bestand in der Grenze steht, findet er von
// allein wenig; ohne diese Probe waere seine Ruhe kein Beweis.
$probe = ":root { --x: #123456; }\n"
    . ".estab-probe-nicht-in-der-grenze { color: #ff0000; background: rgba(0,0,0,.5); }";
$probeOffen = [];
foreach (estab_test_css_regeln($probe) as $regel) {
    if (str_contains($regel['auswaehler'], ':root')) {
        continue;
    }
    if (estab_test_ist_vordruck($regel['auswaehler'])) {
        continue;
    }
    foreach ($regel['deklarationen'] as $erklaerung) {
        foreach ($literale($erklaerung['wert']) as $literal) {
            $probeOffen[] = $literal;
        }
    }
}
$assert(
    count($probeOffen) === 2
        && in_array('#ff0000', $probeOffen, true)
        && in_array('rgba', $probeOffen, true),
    estab_ux_requirement(
        'GES-MARKEN',
        'Die Pruefung findet ein eingebautes Farbliteral nicht wieder; '
            . 'ihre Ruhe waere damit kein Beweis. Gefunden: '
            . implode(', ', $probeOffen)
    )
);

printf(
    "Gestaltung Marken: OK (%d assertions, %d Marken, %d Regeln geprueft, "
        . "%d im Vordruck)\n",
    $assertions,
    count($marken),
    $geprueft,
    count($regeln) - $geprueft
);
