<?php

declare(strict_types=1);

/**
 * Sieben Schriftgroessen, drei Staerken -- und keine achte.
 *
 * Der Bestand trug 61 verschiedene Schriftgroessen, die kleinste 0.52rem und
 * damit gut 8 Pixel. Auf einem Laptopbildschirm im Tageslicht ist das nicht
 * lesbar, und eine Angabe, die untergeht, kann im Meldewesen Folgen haben.
 *
 * Er trug ausserdem 56-mal `font-weight: 800` und 8-mal `900`. Arial hat
 * keinen solchen Schnitt; der Browser rundet auf 700 ab. Die Angabe
 * suggeriert damit einen Unterschied, den niemand sehen kann.
 *
 * Geprueft wird alles, was nicht zum Papierfaksimile gehoert. Die
 * Selbstprobe am Ende stellt sicher, dass die Pruefung beisst, solange der
 * Bestand noch weitgehend in der Grenze steht.
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

$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(is_string($stylesheet), 'Das Stylesheet ist nicht lesbar.');
$stylesheet = (string) $stylesheet;
$marken = estab_test_css_marken($stylesheet);

// Die Skala steht im :root-Block und wird von dort gelesen, nicht hier noch
// einmal aufgeschrieben: Zwei Listen derselben Werte laufen auseinander.
$stufen = [];
foreach ($marken as $name => $wert) {
    if (preg_match('~\A--schrift-[1-7]\z~', $name) === 1) {
        $stufen[$name] = $wert;
    }
}
$assert(
    count($stufen) === 7,
    estab_ux_requirement(
        'GES-SCHRIFTSKALA',
        'Die Skala hat nicht genau sieben Stufen, sondern ' . count($stufen) . '.'
    )
);

// Keine Stufe unterschreitet die Lesbarkeitsgrenze von 0.75rem.
$zuKlein = [];
foreach ($stufen as $name => $wert) {
    if (preg_match('~\A([0-9.]+)rem\z~', $wert, $t) !== 1) {
        $zuKlein[] = $name . ' ist keine rem-Angabe: ' . $wert;
        continue;
    }
    if ((float) $t[1] < 0.75) {
        $zuKlein[] = $name . ' = ' . $wert;
    }
}
$assert(
    $zuKlein === [],
    estab_ux_requirement(
        'GES-SCHRIFTSKALA',
        'Diese Stufen unterschreiten 0.75rem und sind im Tageslicht nicht '
            . 'mehr lesbar: ' . implode(', ', $zuKlein)
    )
);

/** Eine Angabe, die aus der Skala kommt oder gar keine Groesse waehlt. */
$groesseErlaubt = static function (string $wert): bool {
    $wert = strtolower(trim(str_ireplace('!important', '', $wert)));
    if (in_array($wert, ['inherit', 'initial', 'unset', 'revert', '1em'], true)) {
        return true;
    }
    return preg_match('~\Avar\(\s*--schrift-[1-7]\s*\)\z~', $wert) === 1;
};

/** 400, 600, 700 -- als Marke oder als Zahl. */
$staerkeErlaubt = static function (string $wert): bool {
    $wert = strtolower(trim(str_ireplace('!important', '', $wert)));
    if (in_array($wert, ['inherit', 'initial', 'unset', 'revert', 'normal', 'bold'], true)) {
        return true;
    }
    if (preg_match('~\Avar\(\s*--stark-(normal|halb|voll)\s*\)\z~', $wert) === 1) {
        return true;
    }
    return in_array($wert, ['400', '600', '700'], true);
};

$regeln = estab_test_css_regeln($stylesheet);
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
        $eigenschaft = $erklaerung['eigenschaft'];
        $wert = $erklaerung['wert'];
        if ($eigenschaft === 'font-size' && !$groesseErlaubt($wert)) {
            $offen[] = $regel['auswaehler'] . ' { font-size: ' . $wert
                . ' } Zeile ' . $regel['zeile'];
        }
        if ($eigenschaft === 'font-weight' && !$staerkeErlaubt($wert)) {
            $offen[] = $regel['auswaehler'] . ' { font-weight: ' . $wert
                . ' } Zeile ' . $regel['zeile'];
        }
    }
}
$assert(
    $offen === [],
    estab_ux_requirement(
        'GES-SCHRIFTSKALA',
        'Diese umgestellten Regeln waehlen ihre Schrift selbst: '
            . implode(' | ', array_slice($offen, 0, 6))
    )
);
$assert(
    $offen === [],
    estab_ux_requirement(
        'GES-SCHRIFTSTAERKE',
        'Es gibt genau die Staerken 400, 600 und 700. Verstoesse: '
            . implode(' | ', array_slice($offen, 0, 6))
    )
);

// Beisst die Pruefung? Ohne diese Probe waere ihre Ruhe kein Beweis, solange
// der Bestand im Vordruck steht.
$probe = '.estab-probe-nicht-in-der-grenze { font-size: 0.62rem; '
    . 'font-weight: 800; }';
$probeOffen = 0;
foreach (estab_test_css_regeln($probe) as $regel) {
    foreach ($regel['deklarationen'] as $erklaerung) {
        if ($erklaerung['eigenschaft'] === 'font-size'
            && !$groesseErlaubt($erklaerung['wert'])) {
            $probeOffen++;
        }
        if ($erklaerung['eigenschaft'] === 'font-weight'
            && !$staerkeErlaubt($erklaerung['wert'])) {
            $probeOffen++;
        }
    }
}
$assert(
    $probeOffen === 2,
    estab_ux_requirement(
        'GES-SCHRIFTSKALA',
        'Die Pruefung findet eine eingebaute Groesse von 0.62rem und eine '
            . 'Staerke von 800 nicht wieder; ihre Ruhe waere kein Beweis.'
    )
);

printf(
    "Gestaltung Schriftskala: OK (%d assertions, %d Stufen, %d Regeln "
        . "geprueft, %d im Vordruck)\n",
    $assertions,
    count($stufen),
    $geprueft,
    count($regeln) - $geprueft
);
