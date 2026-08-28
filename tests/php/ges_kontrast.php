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

/*
 * Und dazu jede Paarung, die eine Regel selbst aufmacht.
 *
 * Die Liste oben ist eine Feststellung dessen, was die Oberflaeche tut -- und
 * sie kennt nur, was jemand eingetragen hat. Eine neue Regel darf beliebige
 * zwei Marken zusammenbringen, ohne dass die Liste davon erfaehrt.
 *
 * Genau so entstand die Filtermarke des Tabellenbauteils: Sie setzte
 * `background: var(--grund-kachel)` -- die dunkle Kachelfarbe der
 * Menuespalte -- und `color: var(--tinte)`. Dunkel auf dunkel, 1.34:1, und
 * die Liste oben blieb still, weil diese Paarung in ihr nicht vorkam.
 *
 * Deshalb wird zusaetzlich gemessen, was im Stylesheet steht: Jede Regel, die
 * Tinte und Grund gemeinsam aus Marken setzt, sagt selbst, was auf was
 * gehoert. Was eine Regel ausspricht, muss sie auch aushalten.
 */
$regelPaarungen = [];
foreach (estab_test_css_regeln($stylesheet) as $regel) {
    if (str_contains($regel['auswaehler'], ':root')) {
        continue;
    }
    if (estab_test_ist_vordruck($regel['auswaehler'])) {
        continue;
    }
    $tinte = null;
    $grund = null;
    foreach ($regel['deklarationen'] as $erklaerung) {
        if (preg_match(
            '~\Avar\(\s*(--[a-z0-9-]+)\s*\)\z~',
            trim($erklaerung['wert']),
            $treffer
        ) !== 1) {
            continue;
        }
        if ($erklaerung['eigenschaft'] === 'color') {
            $tinte = $treffer[1];
        }
        if (in_array($erklaerung['eigenschaft'], ['background', 'background-color'], true)) {
            $grund = $treffer[1];
        }
    }
    if ($tinte === null || $grund === null) {
        continue;
    }
    if (!isset($marken[$tinte]) || !isset($marken[$grund])) {
        continue;
    }
    // Nur echte Farbmarken: --schatten-tafel etwa ist keine.
    if (preg_match('~\A#[0-9a-fA-F]{3,8}\z~', $marken[$tinte]) !== 1
        || preg_match('~\A#[0-9a-fA-F]{3,8}\z~', $marken[$grund]) !== 1) {
        continue;
    }
    $regelPaarungen[] = [
        $tinte, $grund, ESTAB_GES_SOLL,
        'Regel ' . $regel['auswaehler'] . ' Zeile ' . $regel['zeile'],
    ];
}
$assert(
    count($regelPaarungen) >= 10,
    estab_ux_requirement(
        'GES-KONTRAST-TEXT',
        'Es werden nur ' . count($regelPaarungen) . ' selbst aufgemachte '
            . 'Paarungen gefunden. Die Ableitung aus dem Stylesheet greift '
            . 'nicht mehr, und ihre Ruhe waere kein Beweis.'
    )
);
$paarungen = array_merge($paarungen, $regelPaarungen);

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
 * Raender, die ein Bedienelement begrenzen, erreichen 3:1.
 *
 * WCAG 1.4.11 verlangt das fuer alles, was noetig ist, um ein Bedienelement
 * zu erkennen -- nicht fuer Zierrat. Ein Tafelrahmen darf deshalb zart sein,
 * der Rand eines Aufklapps, einer Feldgruppe oder eines Eingabefeldes nicht.
 *
 * Welche Auswaehler ein Bedienelement meinen, steht im Stylesheet nicht: dass
 * `.estab-message-list-more` ein `details` ist, weiss nur das Markup. Die
 * Muster unten benennen es deshalb hier -- und greifen zugleich auf die
 * Elementnamen, damit ein neues Bedienelement nicht durchrutscht.
 */
$bedienmuster = '~(\bdetails\b|\bsummary\b|\bfieldset\b|\binput\b|\bselect\b'
    . '|\btextarea\b|\bbutton\b|-chip\b|-tab\b|-toggle\b|-more\b'
    . '|-action\b|estab-button)~i';
//
// Die dunklen Spalten bleiben aussen vor: Dort steht ein Bedienelement auf
// Dunkelblau, und die Goldmarke, die auf Weiss 1.95 erreicht, kommt dort auf
// 10.36. Gegen die Tafel gemessen waere sie ein Falschbefund -- und ein
// Falschbefund, den man wegdrueckt, macht den ganzen Waechter unglaubwuerdig.
$dunkleSpalte = '~estab-(sidebar|cockpit|shell-menu|navigation|actions-page)~i';
$schwacheRaender = [];
foreach (estab_test_css_regeln($stylesheet) as $regel) {
    if (preg_match($bedienmuster, $regel['auswaehler']) !== 1) {
        continue;
    }
    if (preg_match($dunkleSpalte, $regel['auswaehler']) === 1) {
        continue;
    }
    foreach ($regel['deklarationen'] as $erklaerung) {
        if (preg_match('~\Aborder(?:-(?:top|right|bottom|left))?\z~',
            $erklaerung['eigenschaft']) !== 1) {
            continue;
        }
        if (preg_match('~#[0-9a-fA-F]{6}~', $erklaerung['wert'], $t) !== 1) {
            continue;
        }
        $verhaeltnis = estab_test_kontrast($t[0], $farbe('--grund-tafel'));
        if ($verhaeltnis < ESTAB_GES_NICHTTEXT) {
            $schwacheRaender[] = sprintf(
                '%s { %s: %s } = %.2f, Zeile %d',
                $regel['auswaehler'],
                $erklaerung['eigenschaft'],
                $t[0],
                $verhaeltnis,
                $regel['zeile']
            );
        }
    }
}
$assert(
    $schwacheRaender === [],
    estab_ux_requirement(
        'GES-KONTRAST-RAND',
        'Diese Raender begrenzen ein Bedienelement und erreichen gegen die '
            . 'Tafel keine 3:1: '
            . implode(' | ', array_slice($schwacheRaender, 0, 8))
    )
);

/*
 * Eine Tinte braucht ihren Grund.
 *
 * Die Farben der dunklen Spalte -- Weiss und das helle Blaugrau daneben --
 * tragen nur, solange etwas Dunkles darunter liegt. Steht eine von ihnen auf
 * hellem Grund, ist der Text unlesbar, und keine Paarungsrechnung findet das:
 * Diese Pruefung kennt die Paarungen, die ihr aufgezaehlt sind, und ein
 * verschwundener Grund steht in keiner Liste.
 *
 * Genau das ist passiert. Die Gestaltungsumstellung entfernte den dunklen
 * Verlaufsgrund des Dokumentkopfs der Infosammlung -- richtig, denn ein
 * Seitenkopf ist eine Zeile und kein Banner -- und liess die weisse Tinte
 * stehen. Weisse Ueberschrift auf hellem Grund, und der Waechter schwieg.
 *
 * Ableitbar ist es trotzdem: Wer eine Spaltentinte benutzt, muss auf einem
 * dunklen Grund stehen. Entweder setzt dieselbe Regel ihn, oder ihr
 * Auswaehler gehoert zu einem Bereich, der als dunkel benannt ist.
 */
$dunkleGrundfarben = [
    'var(--grund-spalte)', 'var(--grund-kachel)', 'var(--grund-kachel-zeigen)',
    'var(--handlung)', 'var(--handlung-dunkel)', 'var(--fehler-kante)',
    'var(--marke-standort-flaeche)', 'var(--erledigt-spalte-flaeche)',
    'var(--fehler-spalte-flaeche)', 'var(--tinte)',
];
/* Bereiche, die als Ganzes auf dunklem Grund stehen. Sie stehen namentlich
   hier, damit die Ausnahme eine Liste ist und keine Gewohnheit. */
$dunkleBereiche = '~estab-(shell|navigation|sidebar|session|cockpit'
    . '|menu-card|actions-page|bos-list-page|root-header'
    . '|incident-indicator-alert)~';
$spaltentinten = ['var(--tinte-spalte)', 'var(--tinte-spalte-neben)'];

$ohneGrund = [];
foreach (estab_test_css_regeln($stylesheet) as $regel) {
    if (estab_test_ist_vordruck($regel['auswaehler'])) {
        continue;
    }
    $tinte = null;
    $grund = null;
    foreach ($regel['deklarationen'] as $erklaerung) {
        if ($erklaerung['eigenschaft'] === 'color') {
            $tinte = trim($erklaerung['wert']);
        }
        if (str_starts_with($erklaerung['eigenschaft'], 'background')) {
            $grund = trim($erklaerung['wert']);
        }
    }
    if ($tinte === null || !in_array($tinte, $spaltentinten, true)) {
        continue;
    }
    $stehtDunkel = false;
    if ($grund !== null) {
        foreach ($dunkleGrundfarben as $dunkel) {
            if (str_contains($grund, $dunkel)) {
                $stehtDunkel = true;
                break;
            }
        }
    }
    if ($stehtDunkel || preg_match($dunkleBereiche, $regel['auswaehler']) === 1) {
        continue;
    }
    $ohneGrund[] = $regel['auswaehler'] . ' { color: ' . $tinte . ' } Zeile '
        . $regel['zeile'];
}
$assert(
    $ohneGrund === [],
    estab_ux_requirement(
        'GES-TINTE-BRAUCHT-GRUND',
        'Diese Regeln setzen eine Tinte der dunklen Spalte, ohne dass ein '
            . 'dunkler Grund darunter liegt: '
            . implode(' | ', array_slice($ohneGrund, 0, 6))
    )
);

// Beisst die Pruefung? Genau der Fall, der sie noetig gemacht hat.
$probe = '.estab-probe-heller-kopf { color: var(--tinte-spalte); '
    . 'border-bottom: 1px solid var(--linie); }';
$probeOffen = 0;
foreach (estab_test_css_regeln($probe) as $regel) {
    foreach ($regel['deklarationen'] as $erklaerung) {
        if ($erklaerung['eigenschaft'] === 'color'
            && in_array(trim($erklaerung['wert']), $spaltentinten, true)) {
            $probeOffen++;
        }
    }
}
$assert(
    $probeOffen === 1,
    estab_ux_requirement(
        'GES-TINTE-BRAUCHT-GRUND',
        'Die Pruefung findet eine Spaltentinte ohne dunklen Grund nicht '
            . 'wieder -- genau den Fall, fuer den es sie gibt.'
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

/*
 * Beisst die Untergrenze?
 *
 * `UX-KONTRAST` traegt seit der Anhebung zwei Stufen: 7:1 als Sollwert und
 * 4.5:1 als absolutes Minimum. Waere die Untergrenze nur ein Satz im
 * Regeltext, hielte sie nichts. Die Probe rechnet ein Paar, das knapp
 * darunter liegt, und verlangt, dass es auffaellt.
 *
 * #767676 auf Weiss ergibt 4.54 -- gerade noch AA. #7a7a7a ergibt 4.37 und
 * faellt durch. Der Abstand ist absichtlich klein: Eine Probe mit Grau auf
 * Grau wuerde auch dann bestehen, wenn die Grenze bei 2 laege.
 */
$knappDarueber = estab_test_kontrast('#767676', '#ffffff');
$knappDarunter = estab_test_kontrast('#7a7a7a', '#ffffff');
$assert(
    $knappDarueber >= ESTAB_GES_MINIMUM && $knappDarunter < ESTAB_GES_MINIMUM,
    estab_ux_requirement(
        'GES-KONTRAST-TEXT',
        sprintf(
            'Die Untergrenze von 4.5:1 trennt nicht: %.2f gilt als bestanden, '
                . '%.2f als durchgefallen.',
            $knappDarueber,
            $knappDarunter
        )
    )
);
$assert(
    $knappDarunter < ESTAB_GES_SOLL,
    estab_ux_requirement(
        'GES-KONTRAST-TEXT',
        'Der Sollwert von 7:1 liegt nicht ueber der Untergrenze.'
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
