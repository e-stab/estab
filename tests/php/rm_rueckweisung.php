<?php

declare(strict_types=1);

/**
 * Eine abgewiesene Handlung sagt es dort, wo hingesehen wird.
 *
 * "Für die Rückgabe an LdF ist ein Grund erforderlich." Der Satz stimmt, und
 * er stand am oberen Rand eines Dokuments, das erheblich laenger ist als der
 * Bildschirm. Wer unten am Vordruck arbeitet und absendet, landet wieder
 * unten -- die Meldung steht ausserhalb des Bildes.
 *
 * Die Folge ist nicht Unbequemlichkeit, sondern eine falsche Annahme: Die
 * Seite sieht aus wie vorher, also glaubt der Bediener, die Rueckgabe sei
 * durchgelaufen. Sie ist es nicht. Im Einsatz bleibt eine Nachricht liegen,
 * von der jemand annimmt, sie sei weitergegeben.
 *
 * Der Kasten liegt deshalb fest im Blickfeld, mittig, ueber dem Inhalt --
 * und er tut das ohne JavaScript. Das Wegnehmen ebenso: Der Schliessen-Weg
 * zeigt auf den Kasten selbst, und `:target` blendet ihn aus. Ein Fragment
 * ueberlebt keine Formularsendung, also erscheint eine neue Abweisung
 * verlaesslich wieder.
 *
 * Die volle Flaeche laesst Klicks durch (`pointer-events: none`); nur das
 * Blatt selbst faengt sie. Sonst waere der Kasten ohne Skript eine Sperre
 * statt einer Meldung.
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

$vordruck = file_get_contents($root . '/4fach/official_message_form.php');
$assert(is_string($vordruck), 'Der Vordruck ist nicht lesbar.');
$vordruck = (string) $vordruck;

// Der Kasten selbst.
if (preg_match(
    '~if \(\(string\)\$this->formdata\[\'estab_route_error\'\] !== \'\'\) \{(.*?)\n        \}~s',
    $vordruck,
    $treffer
) !== 1) {
    throw new RuntimeException(
        'Die Ausgabe der abgewiesenen Handlung ist nicht mehr auffindbar.'
    );
}
$kasten = $treffer[1];

foreach ([
    'role="alert"' => 'Der Kasten meldet sich nicht als Warnung; ein '
        . 'Vorleseprogramm nennt ihn nicht von selbst.',
    'estab-rueckweisung' => 'Der Kasten traegt nicht die Marke, an der das '
        . 'Stylesheet ihn ins Blickfeld hebt.',
    'autofocus' => 'Der Fokus springt beim Laden nicht in den Kasten. Ohne '
        . 'JavaScript ist das die einzige Weise, ihn dorthin zu bringen.',
    'tabindex="-1"' => 'Der Kasten ist nicht anfokussierbar; autofocus '
        . 'liefe damit ins Leere.',
] as $teil => $grund) {
    $assert(
        str_contains($kasten, $teil),
        estab_ux_requirement('UX-RUECKWEISUNG-SICHTBAR', $grund)
    );
}

// Der Weg zum Wegnehmen zeigt auf den Kasten selbst -- das ist es, was
// :target ohne Skript moeglich macht.
if (preg_match('~id="([a-z-]+)"~', $kasten, $kennung) !== 1) {
    throw new RuntimeException('Der Kasten hat keine Kennung.');
}
$assert(
    str_contains($kasten, 'href="#' . $kennung[1] . '"'),
    estab_ux_requirement(
        'UX-RUECKWEISUNG-SICHTBAR',
        'Der Schliessen-Weg zeigt nicht auf den Kasten selbst. Ohne das '
            . 'greift :target nicht, und ohne JavaScript liesse sich die '
            . 'Meldung nicht wegnehmen.'
    )
);

$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(is_string($stylesheet), 'Das Stylesheet ist nicht lesbar.');
$regeln = estab_test_css_regeln((string) $stylesheet);

$erklaerungen = static function (array $regeln, string $auswaehler): array {
    $gefunden = [];
    foreach ($regeln as $regel) {
        if ($regel['kontext'] !== '' || $regel['auswaehler'] !== $auswaehler) {
            continue;
        }
        foreach ($regel['deklarationen'] as $e) {
            $gefunden[$e['eigenschaft']] = $e['wert'];
        }
    }
    return $gefunden;
};

$flaeche = $erklaerungen($regeln, '.estab-rueckweisung');
$assert(
    ($flaeche['position'] ?? '') === 'fixed',
    estab_ux_requirement(
        'UX-RUECKWEISUNG-SICHTBAR',
        'Der Kasten steht nicht fest im Blickfeld, sondern: '
            . ($flaeche['position'] ?? 'gar nicht gesetzt') . '. Am oberen '
            . 'Dokumentrand steht er ausserhalb des Bildes, sobald jemand '
            . 'unten am Vordruck arbeitet.'
    )
);
$assert(
    ($flaeche['place-items'] ?? '') === 'center'
        && ($flaeche['display'] ?? '') === 'grid',
    estab_ux_requirement(
        'UX-RUECKWEISUNG-SICHTBAR',
        'Der Kasten steht nicht mittig: '
            . ($flaeche['display'] ?? '?') . ' / '
            . ($flaeche['place-items'] ?? '?')
    )
);
$assert(
    ($flaeche['pointer-events'] ?? '') === 'none',
    estab_ux_requirement(
        'UX-RUECKWEISUNG-SICHTBAR',
        'Die aufgespannte Flaeche faengt Klicks ab. Ohne Skript liesse sich '
            . 'der Kasten dann nicht wegnehmen, und er waere eine Sperre '
            . 'statt einer Meldung.'
    )
);
$hoehe = (int) ($flaeche['z-index'] ?? 0);
$assert(
    $hoehe >= 2000,
    estab_ux_requirement(
        'UX-RUECKWEISUNG-SICHTBAR',
        'Der Kasten liegt auf Ebene ' . $hoehe . '. Die Kopfleiste steht auf '
            . '1000; darunter waere er verdeckt.'
    )
);

$blatt = $erklaerungen($regeln, '.estab-rueckweisung-blatt');
$assert(
    ($blatt['pointer-events'] ?? '') === 'auto',
    estab_ux_requirement(
        'UX-RUECKWEISUNG-SICHTBAR',
        'Das Blatt nimmt keine Klicks an; der Schliessen-Weg waere nicht '
            . 'anklickbar.'
    )
);

$weg = $erklaerungen($regeln, '.estab-rueckweisung:target');
$assert(
    ($weg['display'] ?? '') === 'none',
    estab_ux_requirement(
        'UX-RUECKWEISUNG-SICHTBAR',
        'Der Kasten laesst sich ohne JavaScript nicht wegnehmen.'
    )
);

/*
 * Und im Ausdruck hat er nichts zu suchen: Ein Nachweis traegt keine
 * Bildschirmmeldung.
 */
$druck = '';
$stelle = 0;
$css = (string) $stylesheet;
while (($start = strpos($css, '@media print', $stelle)) !== false) {
    $tiefe = 0;
    for ($i = (int) strpos($css, '{', $start); $i < strlen($css); $i++) {
        $tiefe += $css[$i] === '{' ? 1 : 0;
        $tiefe -= $css[$i] === '}' ? 1 : 0;
        if ($tiefe === 0) {
            $druck .= substr($css, $start, $i - $start + 1);
            $stelle = $i + 1;
            break;
        }
    }
    if ($tiefe !== 0) {
        break;
    }
}
$assert(
    str_contains($druck, '.estab-rueckweisung'),
    estab_ux_requirement(
        'UX-RUECKWEISUNG-SICHTBAR',
        'Der Ausdruck traegt die Bildschirmmeldung mit. Ein Nachweis ist '
            . 'kein Bildschirm.'
    )
);

printf("Rueckweisung: OK (%d assertions)\n", $assertions);
