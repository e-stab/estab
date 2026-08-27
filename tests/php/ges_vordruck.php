<?php

declare(strict_types=1);

/**
 * Das Blatt wird skaliert, nicht abgeschnitten und nicht gestaucht.
 *
 * Wenn ein Blatt nicht in die Spalte passt, gibt es drei Moeglichkeiten, und
 * nur eine erhaelt das Papierbild. Abschneiden heisst seitwaerts schieben,
 * um die rechte Haelfte zu sehen. Stauchen heisst: Die Feldfolge bleibt, das
 * Bild geht -- wer den Papiervordruck kennt, erkennt ihn nicht wieder.
 * Skalieren heisst, das ganze Blatt gleichmaessig kleiner zu ziehen, so wie
 * man ein Blatt Papier weiter weghaelt: Alle Verhaeltnisse bleiben, kein Feld
 * wandert, nichts wird abgeschnitten.
 *
 * Der Maszstab hat zwei Grenzen, und beide sind Absicht. Nach oben 1: Auf
 * einem breiten Schirm wird das Blatt nicht aufgeblasen. Nach unten 0.75:
 * Wer das Blatt skaliert, skaliert seine Schrift mit, und bei 0.75 steht eine
 * tragende Angabe noch bei 10.5 Bildpunkten.
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
$regeln = estab_test_css_regeln((string) $stylesheet);

$erklaerungen = static function (array $regeln, string $muster, string $kontext = ''): array {
    $gefunden = [];
    foreach ($regeln as $regel) {
        if ($regel['kontext'] !== $kontext) {
            continue;
        }
        if (preg_match($muster, $regel['auswaehler']) !== 1) {
            continue;
        }
        foreach ($regel['deklarationen'] as $e) {
            $gefunden[$e['eigenschaft']] = $e['wert'];
        }
    }
    return $gefunden;
};

// Der Rahmen misst sich selbst -- sonst haette das Blatt nichts, woran es
// seinen Maszstab bemessen koennte. Am Fenster darf er nicht haengen: Der
// Maszstab muss auch stimmen, wenn daneben der Bearbeitungsweg steht.
$rahmen = $erklaerungen($regeln, '~\A\.estab-message-form-scroll\z~');
$assert(
    ($rahmen['container-type'] ?? '') === 'inline-size',
    estab_ux_requirement(
        'GES-VORDRUCK-MASSSTAB',
        'Der Rahmen um das Blatt misst sich nicht selbst; der Maszstab haenge '
            . 'dann am Fenster statt am verfuegbaren Platz.'
    )
);

$blatt = $erklaerungen($regeln, '~\A\.estab-official-message-form\z~');
$assert(
    ($blatt['width'] ?? '') === '56rem' && ($blatt['min-width'] ?? '') === '56rem',
    estab_ux_requirement(
        'GES-VORDRUCK-MASSSTAB',
        'Das Raster des Blattes ist nicht 56rem breit. Es ist ein Raster, '
            . 'kein fliessender Satz -- was sich aendert, ist der Maszstab.'
    )
);
$zoom = str_replace(' ', '', $blatt['zoom'] ?? '');
$assert(
    $zoom === 'max(0.75,min(1,calc(100cqw/56rem)))',
    estab_ux_requirement(
        'GES-VORDRUCK-MASSSTAB',
        'Der Maszstab ist nicht auf [0.75, 1] begrenzt, sondern: '
            . ($blatt['zoom'] ?? 'gar nicht gesetzt')
    )
);
$assert(
    str_contains($zoom, 'min(1,'),
    estab_ux_requirement(
        'GES-VORDRUCK-MASSSTAB',
        'Das Blatt kann groesser als sein Raster werden. Ein Vordruck in '
            . 'Uebergroesse sieht falsch aus und gewinnt nichts.'
    )
);
$assert(
    str_contains($zoom, 'max(0.75,'),
    estab_ux_requirement(
        'GES-VORDRUCK-LESBAR',
        'Der Maszstab hat keine Untergrenze. Wer das Blatt skaliert, '
            . 'skaliert seine Schrift mit -- ohne Grenze wird sie unlesbar.'
    )
);

// Auf Papier gilt ein eigener, fester Maszstab. Ein Ausdruck, dessen Groesse
// davon abhinge, wie breit gerade das Fenster stand, waere kein Vordruck.
$druck = $erklaerungen($regeln, '~\A\.estab-official-message-form\z~', '@media print');
$assert(
    isset($druck['zoom']) && !str_contains($druck['zoom'], 'cqw'),
    estab_ux_requirement(
        'GES-VORDRUCK-MASSSTAB',
        'Der Druck hat keinen eigenen festen Maszstab: '
            . ($druck['zoom'] ?? 'gar keinen')
    )
);

/*
 * Tragende Angaben sind mindestens 0.875rem.
 *
 * Drei Ausnahmen stehen namentlich hier, und alle sind begruendet: Sie
 * stehen im festen Raster des amtlichen Blattes und sprengen es in
 * Arbeitsgroesse -- der Hinweis unter "Anschrift:" ueberlaeuft die
 * Feldnummer, der Satz der Gespraechsnotiz laeuft unten aus seinem Kasten,
 * und "noch kein TBB-Nachweis" braucht drei Zeilen in einer Rasterzeile, die
 * 2,2rem hoch ist, weil sie auf dem Papier so hoch ist.
 * Da schlaegt die Dienstvorschrift die Gestaltungsspec (Abschnitt 1.4).
 * Beide Texte stehen lesbar in der Ausfuellhilfe ihres Feldes.
 *
 * Der Kleinstdruck des Papierbildes -- die Durchschriftenzuordnung am linken
 * Rand, die Vordrucknummer, die Legende -- ist ebenfalls ausgenommen: Er ist
 * auf dem Papier genauso klein und traegt keine Angabe, die es nicht
 * anderswo lesbar gaebe.
 */
$ausnahmen = [
    '.estab-official-ttb > .estab-official-readonly',
    '.estab-official-designation-hint',
    '.estab-official-conversation-medium-status',
    '.estab-official-copy-distribution',
    '.estab-official-copy-legend',
    '.estab-official-print-number',
];
$zuKlein = [];
foreach ($regeln as $regel) {
    if (!str_contains($regel['auswaehler'], 'estab-official')) {
        continue;
    }
    if ($regel['kontext'] !== '') {
        continue;
    }
    foreach ($ausnahmen as $a) {
        if (str_contains($regel['auswaehler'], $a)) {
            continue 2;
        }
    }
    foreach ($regel['deklarationen'] as $e) {
        if ($e['eigenschaft'] !== 'font-size') {
            continue;
        }
        if (preg_match('~\A([0-9.]+)rem\z~', $e['wert'], $t) !== 1) {
            continue;
        }
        if ((float) $t[1] < 0.875) {
            $zuKlein[] = $regel['auswaehler'] . ' = ' . $e['wert']
                . ' (Zeile ' . $regel['zeile'] . ')';
        }
    }
}
$assert(
    $zuKlein === [],
    estab_ux_requirement(
        'GES-VORDRUCK-LESBAR',
        'Diese tragenden Angaben im Blatt unterschreiten 0.875rem und waeren '
            . 'im kleinsten Maszstab unter 10.5 Bildpunkten: '
            . implode(' | ', array_slice($zuKlein, 0, 6))
    )
);

// Jede Ausnahme muss im Stylesheet begruendet sein. Eine stillschweigende
// Ausnahme ist keine.
foreach ($ausnahmen as $a) {
    // Ein Auswaehler kann mehrfach vorkommen. Begruendet werden muss der
    // Block, der die kleine Schrift setzt -- nicht irgendeiner.
    $begruendet = false;
    $gefunden = false;
    $offset = 0;
    while (($stelle = strpos((string) $stylesheet, $a . ' {', $offset)) !== false) {
        $offset = $stelle + 1;
        $ende = strpos((string) $stylesheet, '}', $stelle);
        $block = substr((string) $stylesheet, $stelle, ($ende ?: $stelle) - $stelle);
        if (!str_contains($block, 'font-size')) {
            continue;
        }
        $gefunden = true;
        $davor = substr((string) $stylesheet, max(0, $stelle - 800), min(800, $stelle));
        if (str_contains($davor, 'Ausnahme') || str_contains($davor, 'Papierbild')
            || str_contains($davor, 'Kleinstdruck')) {
            $begruendet = true;
        }
    }
    $assert(
        !$gefunden || $begruendet,
        estab_ux_requirement(
            'GES-VORDRUCK-LESBAR',
            'Die Ausnahme ' . $a . ' ist im Stylesheet nicht begruendet. '
                . 'Eine stillschweigende Ausnahme ist keine.'
        )
    );
}

// Der Nachrichtentext ist der Gegenstand der Anwendung. Er schrumpft nicht.
$text = $erklaerungen($regeln, '~estab-official-message-text .*textarea~');
$assert(
    ($text['font-size'] ?? '') === 'var(--schrift-4)',
    estab_ux_requirement(
        'GES-INHALT-BLEIBT-GROSS',
        'Der Nachrichtentext traegt nicht die Inhaltsstufe: '
            . ($text['font-size'] ?? 'keine Angabe')
    )
);

printf("Gestaltung Vordruck: OK (%d assertions)\n", $assertions);
