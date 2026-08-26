<?php

declare(strict_types=1);

/**
 * Sieben Abstandsstufen, vier Radien -- und keine Zwischenwerte.
 *
 * Der Bestand traegt 240 verschiedene Angaben fuer `padding`, `gap` und
 * `margin` und 15 verschiedene Eckradien. Die Folge ist nicht nur Unordnung:
 * Weil jede Stelle ihren Abstand selbst waehlt, laesst sich die Dichte nicht
 * an einer Stelle nachziehen, sondern nur an zweihundert.
 *
 * Und die Dichte ist hier keine Geschmacksfrage. Eine Fuehrungsstelle
 * arbeitet auf Laptopbildschirmen; jeder Bildpunkt, den ein Polster
 * verbraucht, ist eine Zeile Lage, die jemand nicht sieht. Die Flaeche steckt
 * im Beiwerk, nicht in der Schrift -- deshalb wird sie dort geholt.
 *
 * Geprueft wird alles, was die Migrationsgrenze nicht mehr deckt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once __DIR__ . '/lib/stylesheet.php';
require_once __DIR__ . '/lib/migrationsgrenze.php';

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
$marken = estab_test_css_marken($stylesheet);

$abstaende = [];
$radien = [];
foreach ($marken as $name => $wert) {
    if (preg_match('~\A--abstand-[1-7]\z~', $name) === 1) {
        $abstaende[$name] = $wert;
    }
    if (preg_match('~\A--radius-(?:[1-3]|pille)\z~', $name) === 1) {
        $radien[$name] = $wert;
    }
}
$assert(
    count($abstaende) === 7,
    estab_ux_requirement(
        'GES-ABSTANDSSKALA',
        'Die Abstandsskala hat nicht genau sieben Stufen, sondern '
            . count($abstaende) . '.'
    )
);
$assert(
    count($radien) === 4,
    estab_ux_requirement(
        'GES-ABSTANDSSKALA',
        'Es gibt nicht genau vier Radien, sondern ' . count($radien) . '.'
    )
);

/**
 * Jeder Bestandteil einer Kurzschreibweise muss aus der Skala kommen.
 *
 * `padding: var(--abstand-2) var(--abstand-4)` ist zulaessig, `padding:
 * var(--abstand-2) 3px` nicht -- eine Kurzschreibweise ist kein Freibrief
 * fuer den zweiten Wert.
 */
$ausSkala = static function (string $wert, string $praefix): bool {
    $wert = strtolower(trim($wert));
    if (in_array($wert, ['inherit', 'initial', 'unset', 'revert', 'auto'], true)) {
        return true;
    }
    // Klammerinhalte zusammenhalten, damit var(...) nicht zerfaellt.
    $teile = preg_split('~\s+(?![^(]*\))~', $wert) ?: [];
    foreach ($teile as $teil) {
        $teil = trim($teil);
        if ($teil === '' || $teil === '0' || $teil === 'auto') {
            continue;
        }
        if (preg_match('~\Avar\(\s*' . $praefix . '[a-z0-9-]+\s*\)\z~', $teil) !== 1) {
            return false;
        }
    }
    return true;
};

$eigenschaften = [
    'padding' => '--abstand-',
    'padding-top' => '--abstand-',
    'padding-right' => '--abstand-',
    'padding-bottom' => '--abstand-',
    'padding-left' => '--abstand-',
    'gap' => '--abstand-',
    'row-gap' => '--abstand-',
    'column-gap' => '--abstand-',
    'margin' => '--abstand-',
    'margin-top' => '--abstand-',
    'margin-right' => '--abstand-',
    'margin-bottom' => '--abstand-',
    'margin-left' => '--abstand-',
    'border-radius' => '--radius-',
];

$regeln = estab_test_css_regeln($stylesheet);
$offen = [];
$geprueft = 0;
foreach ($regeln as $regel) {
    if (str_contains($regel['auswaehler'], ':root')) {
        continue;
    }
    if (estab_test_in_migrationsgrenze($regel['auswaehler'])) {
        continue;
    }
    // Ein Keyframe-Schritt ist kein Bereich, sondern ein Zwischenstand einer
    // Bewegung; er gehoert zu der Regel, die ihn auslöst.
    if (estab_test_css_ist_keyframe($regel)) {
        continue;
    }
    $geprueft++;
    foreach ($regel['deklarationen'] as $erklaerung) {
        $praefix = $eigenschaften[$erklaerung['eigenschaft']] ?? null;
        if ($praefix === null) {
            continue;
        }
        if (!$ausSkala($erklaerung['wert'], $praefix)) {
            $offen[] = $regel['auswaehler'] . ' { ' . $erklaerung['eigenschaft']
                . ': ' . $erklaerung['wert'] . ' } Zeile ' . $regel['zeile'];
        }
    }
}
$assert(
    $offen === [],
    estab_ux_requirement(
        'GES-ABSTANDSSKALA',
        'Diese umgestellten Regeln waehlen ihre Abstaende selbst: '
            . implode(' | ', array_slice($offen, 0, 6))
    )
);

// Beisst die Pruefung? Der zweite Fall ist der wichtigere: eine
// Kurzschreibweise, deren erster Wert stimmt und deren zweiter nicht.
$probe = '.estab-probe-nicht-in-der-grenze { padding: 0.62rem; '
    . 'gap: var(--abstand-2) 3px; border-radius: 10px; margin: 0; }';
$probeOffen = 0;
foreach (estab_test_css_regeln($probe) as $regel) {
    foreach ($regel['deklarationen'] as $erklaerung) {
        $praefix = $eigenschaften[$erklaerung['eigenschaft']] ?? null;
        if ($praefix !== null && !$ausSkala($erklaerung['wert'], $praefix)) {
            $probeOffen++;
        }
    }
}
$assert(
    $probeOffen === 3,
    estab_ux_requirement(
        'GES-ABSTANDSSKALA',
        'Die Pruefung findet drei eingebaute Abweichungen nicht wieder, '
            . 'darunter den zweiten Wert einer Kurzschreibweise. Gefunden: '
            . $probeOffen
    )
);

printf(
    "Gestaltung Abstandsskala: OK (%d assertions, %d Stufen, %d Radien, "
        . "%d Regeln geprueft, %d noch in der Grenze)\n",
    $assertions,
    count($abstaende),
    count($radien),
    $geprueft,
    count($regeln) - $geprueft
);
