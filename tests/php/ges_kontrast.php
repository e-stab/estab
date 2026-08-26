<?php

declare(strict_types=1);

/**
 * Jeder Text erreicht 7:1 gegen den Grund, auf dem er tatsaechlich steht.
 *
 * `UX-KONTRAST` verlangte urspruenglich den AA-Wert 4.5:1. Der Betreiber hat
 * die Regel auf zwei Stufen gehoben: 4.5:1 ist das absolute Minimum, 7:1 der
 * Sollwert, und gearbeitet wird am Sollwert.
 *
 * Der Grund ist nicht Perfektionismus. Ein Laptopbildschirm im Einsatzraum
 * steht unter Deckenbeleuchtung oder im Tageslicht, oft schraeg im Blick, oft
 * mit Fingerabdruecken. Der gemessene Kontrast ist der beste Fall, nicht der
 * tatsaechliche -- 4.5:1 im Labor ist auf einem verspiegelten Schirm um
 * 14 Uhr weniger. Eine Angabe, die untergeht, kann im Meldewesen grosse
 * Folgen haben: eine ueberlesene Vorrangstufe, ein falsch gelesener Rufname.
 *
 * Welche Farbe auf welchem Grund steht, ist im Stylesheet nicht ablesbar --
 * ein Feld ist durchsichtig und liegt auf der Tafel, eine Marke liegt auf der
 * Zeigezeile. Diese Zuordnung steht deshalb hier im Test, als Feststellung
 * dessen, was die Oberflaeche tut. Die Farbwerte selbst kommen aus dem
 * :root-Block, damit Test und Stylesheet nicht auseinanderlaufen koennen.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once __DIR__ . '/lib/farbe.php';
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

$farbe = static function (string $name) use ($marken): string {
    if (!isset($marken[$name])) {
        throw new RuntimeException('Marke fehlt im :root-Block: ' . $name);
    }
    return $marken[$name];
};

const ESTAB_GES_SOLL = 7.0;
const ESTAB_GES_MINIMUM = 4.5;
const ESTAB_GES_NICHTTEXT = 3.0;

/*
 * Jeder helle Grund, auf dem in dieser Anwendung Text stehen kann. Die
 * Goldflaeche fehlt hier mit Absicht: Auf ihr ist nur die Haupttinte
 * zulaessig, und das wird weiter unten eigens geprueft.
 */
$helleGruende = [
    '--grund-tafel', '--grund-seite', '--grund-gedaempft', '--handlung-sanft',
    '--achtung-flaeche', '--blitz-flaeche', '--fehler-flaeche',
    '--erledigt-flaeche',
];
$dunkleGruende = [
    '--grund-spalte', '--grund-kachel', '--grund-kachel-zeigen',
];

/** @var list<array{0:string,1:string,2:float,3:string}> */
$paarungen = [];

// Fliesstext und zweite Tinte auf jedem hellen Grund.
foreach (['--tinte', '--tinte-neben'] as $tinte) {
    foreach ($helleGruende as $grund) {
        $paarungen[] = [$tinte, $grund, ESTAB_GES_SOLL, 'Text'];
    }
}
// Auf der Goldflaeche traegt nur die Haupttinte.
$paarungen[] = ['--tinte', '--marke-standort', ESTAB_GES_SOLL, 'Text auf Gold'];

// Verweise und die Tinte des Zweitknopfs. Nicht --handlung: das ist die
// Flaechenfarbe und verfehlt als Schrift den Sollwert.
foreach ($helleGruende as $grund) {
    $paarungen[] = ['--handlung-dunkel', $grund, ESTAB_GES_SOLL, 'Verweis'];
}

// Die dunklen Spalten.
foreach (['--tinte-spalte', '--tinte-spalte-neben'] as $tinte) {
    foreach ($dunkleGruende as $grund) {
        $paarungen[] = [$tinte, $grund, ESTAB_GES_SOLL, 'Text in der Spalte'];
    }
}

// Weiss auf gefuellten Knoepfen.
foreach (['--handlung', '--handlung-dunkel', '--fehler-kante'] as $flaeche) {
    $paarungen[] = ['--tinte-spalte', $flaeche, ESTAB_GES_SOLL, 'Knopfschrift'];
}

// Meldungskaesten: jede Zustandstinte auf ihrer eigenen Flaeche.
foreach (['hinweis', 'erledigt', 'achtung', 'fehler'] as $zustand) {
    $paarungen[] = [
        '--' . $zustand . '-tinte', '--' . $zustand . '-flaeche',
        ESTAB_GES_SOLL, 'Meldungskasten',
    ];
}
// Vorrang „Blitz" hat eigene Marken; Sofort und Staatsnot teilen sich
// achtung und fehler und sind oben schon geprueft.
$paarungen[] = ['--blitz-tinte', '--blitz-flaeche', ESTAB_GES_SOLL, 'Vorrang'];

// Nichttext: Raender von Bedienelementen auf jedem Grund, auf dem ein
// Bedienelement stehen kann.
foreach ($helleGruende as $grund) {
    $paarungen[] = [
        '--rand-bedienelement', $grund, ESTAB_GES_NICHTTEXT, 'Bedienrand',
    ];
}
$paarungen[] = ['--linie-spalte', '--grund-spalte', ESTAB_GES_NICHTTEXT, 'Kachelrand'];
$paarungen[] = ['--linie-spalte', '--grund-kachel', ESTAB_GES_NICHTTEXT, 'Kachelrand'];
$paarungen[] = [
    '--tinte-spalte-neben', '--grund-kachel-zeigen',
    ESTAB_GES_NICHTTEXT, 'Kachelrand beim Zeigen',
];

$schlecht = [];
$niedrigsterText = 99.0;
foreach ($paarungen as [$vorn, $hinten, $soll, $zweck]) {
    $verhaeltnis = estab_test_kontrast($farbe($vorn), $farbe($hinten));
    if ($verhaeltnis < $soll) {
        $schlecht[] = sprintf(
            '%s auf %s (%s) = %.2f, verlangt %.1f',
            $vorn,
            $hinten,
            $zweck,
            $verhaeltnis,
            $soll
        );
    }
    if ($soll === ESTAB_GES_SOLL && $verhaeltnis < $niedrigsterText) {
        $niedrigsterText = $verhaeltnis;
    }
}
$assert(
    $schlecht === [],
    estab_ux_requirement(
        'GES-KONTRAST-TEXT',
        'Diese Paarungen erreichen ihren Sollwert nicht: '
            . implode(' | ', array_slice($schlecht, 0, 6))
    )
);
$assert(
    $schlecht === [],
    estab_ux_requirement(
        'GES-KONTRAST-RAND',
        'Raender und Ringe muessen 3:1 gegen jeden Grund erreichen, auf dem '
            . 'sie vorkommen koennen. Verstoesse: '
            . implode(' | ', array_slice($schlecht, 0, 6))
    )
);
$assert(
    $niedrigsterText >= ESTAB_GES_MINIMUM,
    estab_ux_requirement(
        'GES-KONTRAST-TEXT',
        'Eine Textpaarung unterschreitet das absolute Minimum von 4.5:1.'
    )
);

/*
 * Der Fokus muss auf hellem und auf dunklem Grund sichtbar sein, und die
 * Anwendung hat beides nebeneinander. Ein einfarbiger Ring kann das nicht:
 * Gold erreicht auf Weiss 1.67, Dunkelblau auf der Menuespalte 1.00. Deshalb
 * zwei Ringe -- und geprueft wird, dass auf jedem Grund mindestens einer
 * traegt.
 */
$ringSchwach = [];
$alleGruende = array_merge($helleGruende, $dunkleGruende, [
    '--handlung', '--fehler-kante',
]);
foreach ($alleGruende as $grund) {
    $innen = estab_test_kontrast($farbe('--fokus-innen'), $farbe($grund));
    $aussen = estab_test_kontrast($farbe('--fokus-aussen'), $farbe($grund));
    if (max($innen, $aussen) < ESTAB_GES_NICHTTEXT) {
        $ringSchwach[] = sprintf(
            '%s: innen %.2f, aussen %.2f',
            $grund,
            $innen,
            $aussen
        );
    }
}
$assert(
    $ringSchwach === [],
    estab_ux_requirement(
        'GES-KONTRAST-RAND',
        'Auf diesen Gruenden traegt keiner der beiden Fokusringe: '
            . implode(' | ', $ringSchwach)
    )
);

/*
 * Keine gedaempfte Schrift.
 *
 * `opacity` senkt den Kontrast unkontrolliert und entzieht ihn der Messung:
 * Was unter einem gedaempften Kasten liegt, laesst sich aus dem Stylesheet
 * nicht mehr nachrechnen. Rangfolge entsteht ueber Groesse, Staerke und Ort
 * -- nie ueber Blaesse. Was zu unwichtig ist, um lesbar zu sein, ist zu
 * unwichtig, um zu stehen.
 */
$regeln = estab_test_css_regeln($stylesheet);
$blass = [];
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
        if ($erklaerung['eigenschaft'] !== 'opacity') {
            continue;
        }
        $wert = trim($erklaerung['wert']);
        if (is_numeric($wert) && (float) $wert >= 1.0) {
            continue;
        }
        $blass[] = $regel['auswaehler'] . ' { opacity: ' . $wert
            . ' } Zeile ' . $regel['zeile'];
    }
}
$assert(
    $blass === [],
    estab_ux_requirement(
        'GES-KEINE-BLASSE-SCHRIFT',
        'Diese umgestellten Regeln daempfen ihren Inhalt: '
            . implode(' | ', array_slice($blass, 0, 6))
    )
);

// Beisst die Pruefung? Ohne diese Probe waere ihre Ruhe kein Beweis.
$probe = '.estab-probe-nicht-in-der-grenze { opacity: 0.78; }';
$probeBlass = 0;
foreach (estab_test_css_regeln($probe) as $regel) {
    foreach ($regel['deklarationen'] as $erklaerung) {
        if ($erklaerung['eigenschaft'] === 'opacity'
            && (float) $erklaerung['wert'] < 1.0) {
            $probeBlass++;
        }
    }
}
$assert(
    $probeBlass === 1,
    estab_ux_requirement(
        'GES-KEINE-BLASSE-SCHRIFT',
        'Die Pruefung findet ein eingebautes opacity von 0.78 nicht wieder.'
    )
);
$assert(
    estab_test_kontrast('#000000', '#ffffff') > 20.9
        && estab_test_kontrast('#ffffff', '#ffffff') < 1.1,
    estab_ux_requirement(
        'GES-KONTRAST-TEXT',
        'Die Kontrastrechnung selbst stimmt nicht.'
    )
);

printf(
    "Gestaltung Kontrast: OK (%d assertions, %d Paarungen, niedrigster "
        . "Textwert %.2f, %d Regeln auf Blaesse geprueft)\n",
    $assertions,
    count($paarungen),
    $niedrigsterText,
    $geprueft
);
